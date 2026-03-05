<?php
/**
 * cron.php — Membership Renewal & Monthly Billing Processing
 *
 * Processes these tasks:
 *   1. Expire stale pending plan changes (> 7 days old)
 *   2. Monthly installment billing for plans with billing_frequency = 'monthly'
 *   3. Auto-renewing memberships that have reached their end date
 *   4. Absence warning emails
 *   5. Log retention cleanup
 *   6. Auto-deactivate students with no payment in 30+ days (active membership + past due)
 *
 * Can be triggered two ways:
 *   1. Via cron job:   php /path/to/cron.php
 *   2. Via browser:    Admin clicks "Process Renewals" on memberships page
 *
 * Logic for each expired/expiring membership:
 *   - If auto_renew is ON and a payment gateway is configured => attempt charge, renew on success
 *   - If auto_renew is ON but no gateway => expire the membership, log as "no_gateway"
 *   - If auto_renew is OFF => expire the membership
 *
 * Multi-tenancy: this file processes ALL active schools in a loop.
 */

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    require_once 'config.php';
    require_once __DIR__ . '/includes/payment_gateway.php';
    require_once __DIR__ . '/includes/messaging.php';
    require_once __DIR__ . '/includes/belt_cycle.php';
    requireLogin();

    // Only admins and super admins can trigger from browser
    if (!in_array(getCurrentUser()['role'], ['admin', 'super_admin'])) {
        accessDenied('Renewal processing requires Admin or Super Admin privileges.', 'memberships.php');
    }
}

// --- Database connection ---
if ($is_cli) {
    // When running from CLI, set up our own PDO
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/payment_gateway.php';
    require_once __DIR__ . '/includes/messaging.php';
    require_once __DIR__ . '/includes/belt_cycle.php';
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false,
            ]
        );
    } catch (PDOException $e) {
        echo "Database connection failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Multi-tenancy: process each school separately
$_schools = $pdo->query("SELECT id FROM schools WHERE status = 'active'")->fetchAll();

// Accumulate results across all schools
$totalExpiredChanges = 0;
$totalMonthlyResults = [
    'billed'         => 0,
    'failed'         => 0,
    'completed'      => 0,
    'total_monthly'  => 0,
];
$totalResults = [
    'renewed'        => 0,
    'expired'        => 0,
    'payment_failed' => 0,
    'no_gateway'     => 0,
    'total_processed' => 0,
];
$totalAbsenceResults = ['checked' => 0, 'warned' => 0, 'emails_sent' => 0, 'emails_failed' => 0];
$totalDeactivated = 0;

foreach ($_schools as $_school) {
    $_SESSION['active_school_id'] = $_school['id'];

    // --- Check for payment gateway (per-school) ---
    $gateway_configured = (get_active_gateway() !== 'none' && is_gateway_ready());

// =====================================================================
// STEP 1: Expire stale pending plan changes
// =====================================================================
try {
    $params = [];
    $sql = "UPDATE pending_plan_changes
            SET status = 'expired', resolved_at = NOW()
            WHERE status = 'pending' AND expires_at <= NOW()" . school_where();
    school_param($params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $expiredChanges = $stmt->rowCount();
} catch (PDOException $e) {
    $expiredChanges = 0;
}
$totalExpiredChanges += $expiredChanges;

// =====================================================================
// STEP 1b: Auto-resume memberships whose hold period has ended
// =====================================================================
try {
    $holdParams = [];
    $holdSql = "UPDATE memberships SET status = 'active', hold_start_date = NULL, hold_end_date = NULL, hold_reason = NULL
                WHERE status = 'on_hold' AND hold_end_date IS NOT NULL AND hold_end_date <= CURDATE()" . school_where();
    school_param($holdParams);
    $holdResumeStmt = $pdo->prepare($holdSql);
    $holdResumeStmt->execute($holdParams);
    $holdResumed = $holdResumeStmt->rowCount();
} catch (PDOException $e) {
    $holdResumed = 0;
}

// =====================================================================
// STEP 2: Monthly installment billing
// =====================================================================
$monthlyResults = [
    'billed'         => 0,
    'failed'         => 0,
    'completed'      => 0,
    'total_monthly'  => 0,
];

// Find active monthly memberships due for billing today
$today_day = (int) date('j');
$last_day_of_month = (int) date('t');

try {
    $params = [];
    $monthlyDueSql = "
        SELECT m.*, mp.name AS plan_name, mp.duration_months, mp.price,
               mp.billing_frequency, mp.is_afterschool, mp.is_camp, mp.program_start_date, mp.program_end_date,
               s.first_name, s.last_name, s.email
        FROM memberships m
        JOIN membership_plans mp ON m.plan_id = mp.id
        JOIN students s ON m.student_id = s.id
        WHERE m.status = 'active'
          AND mp.billing_frequency = 'monthly'
          AND m.end_date > CURDATE()
          AND m.monthly_charges_made < mp.duration_months
          AND (
              m.billing_day = {$today_day}
              OR (m.billing_day > {$last_day_of_month} AND {$today_day} = {$last_day_of_month})
          )" . school_where('m') . "
        ORDER BY m.id ASC";
    school_param($params);
    $monthlyDueStmt = $pdo->prepare($monthlyDueSql);
    $monthlyDueStmt->execute($params);
    $monthlyDue = $monthlyDueStmt->fetchAll();
} catch (PDOException $e) {
    $monthlyDue = [];
}

$monthlyResults['total_monthly'] = count($monthlyDue);

$monthlyLogStmt = $pdo->prepare("
    INSERT INTO renewal_log (school_id, membership_id, student_id, action, old_end_date, new_end_date, amount, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

foreach ($monthlyDue as $mm) {
    // Skip afterschool/camp plans that have passed their program end date
    $isFixedTermPlan = (!empty($mm['is_afterschool']) || !empty($mm['is_camp']));
    if ($isFixedTermPlan && !empty($mm['program_end_date']) && date('Y-m-d') > $mm['program_end_date']) {
        continue;
    }

    // Fixed-term plans (afterschool/camp): compute monthly rate from program dates
    if ($isFixedTermPlan && $mm['program_start_date'] && $mm['program_end_date']) {
        $programDays = max(1, (strtotime($mm['program_end_date']) - strtotime($mm['program_start_date'])) / 86400);
        $programMonths = max(1, round($programDays / 30.44, 4));
        $monthly_amount = round($mm['price'] / $programMonths, 2);
    } else {
        $monthly_amount = round($mm['price'] / $mm['duration_months'], 2);
    }
    $installment_num = ($mm['monthly_charges_made'] ?? 0) + 1;

    // Calculate service fee on the monthly installment
    $cronFeeBreakdown = calculateTotalWithFees([
        'base_amount'      => $monthly_amount,
        'registration_fee' => 0,
        'discount_code'    => '',
        'plan_id'          => (int) $mm['plan_id'],
    ]);
    $chargeTotal = $cronFeeBreakdown['total'];
    $serviceFeeAmount = $cronFeeBreakdown['service_fee'];

    if ($gateway_configured) {
        $chargeDesc = 'Monthly installment ' . $installment_num . '/' . $mm['duration_months'] . ': ' . $mm['plan_name'];
        if ($serviceFeeAmount > 0) $chargeDesc .= ' (incl. service fee)';

        $chargeResult = charge_student(
            (int) $mm['student_id'],
            $chargeTotal,
            $chargeDesc
        );

        if ($chargeResult['success']) {
            $creditUsed = $chargeResult['credit_used'] ?? 0;
            $amountCharged = $chargeResult['amount_charged'] ?? $chargeTotal;
            $newChargesMade = $installment_num;

            // Update charges count and mark payment as paid (clears lockout)
            $pdo->prepare("UPDATE memberships SET monthly_charges_made = ?, payment_status = 'paid' WHERE id = ?" . school_where())
                ->execute([$newChargesMade, $mm['id'], current_school_id()]);
            // Clear any lingering lockout override (no longer needed)
            $pdo->prepare("UPDATE students SET payment_lockout_override = 0 WHERE id = ?" . school_where())
                ->execute([$mm['student_id'], current_school_id()]);

            // Auto-reactivate if student was deactivated for payment reasons
            try {
                $reactivateCheck = $pdo->prepare("SELECT status, deactivation_reason FROM students WHERE id = ?" . school_where());
                $reactivateCheck->execute([$mm['student_id'], current_school_id()]);
                $stuRow = $reactivateCheck->fetch();
                if ($stuRow && $stuRow['status'] === 'inactive' && $stuRow['deactivation_reason'] === 'payment') {
                    $pdo->prepare("UPDATE students SET status = 'active', inactive_since = NULL, deactivation_reason = NULL WHERE id = ?" . school_where())
                        ->execute([$mm['student_id'], current_school_id()]);
                    if (function_exists('app_log')) {
                        app_log('info', "Auto-reactivated student {$mm['student_id']} ({$mm['first_name']} {$mm['last_name']}) after successful payment", ['category' => 'reactivation']);
                    }
                }
            } catch (\Throwable $e) { /* don't crash billing on reactivation error */ }

            // Record payment(s)
            try {
                if ($amountCharged > 0) {
                    $receiptNum = generateReceiptNumber();
                    $txnNote = 'Monthly installment ' . $installment_num . '/' . $mm['duration_months'] . ' for ' . $mm['plan_name'];
                    if (!empty($chargeResult['transaction_id'])) {
                        $txnNote .= ' | Txn: ' . $chargeResult['transaction_id'];
                    }
                    if ($creditUsed > 0) {
                        $txnNote .= ' | Credit applied: $' . number_format($creditUsed, 2);
                    }
                    if ($serviceFeeAmount > 0) {
                        $txnNote .= ' | Service fee: $' . number_format($serviceFeeAmount, 2);
                    }
                    $pdo->prepare("
                        INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount, payment_method, payment_date, receipt_number, notes)
                        VALUES (?, ?, 'membership', ?, ?, 'credit_card', CURDATE(), ?, ?)
                    ")->execute([
                        current_school_id(), $mm['student_id'], $mm['id'], $amountCharged, $receiptNum, $txnNote
                    ]);
                }
                if ($creditUsed > 0) {
                    $pdo->prepare("
                        INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount, payment_method, payment_date, receipt_number, notes)
                        VALUES (?, ?, 'membership', ?, ?, 'account_credit', CURDATE(), ?, ?)
                    ")->execute([
                        current_school_id(), $mm['student_id'], $mm['id'], $creditUsed, generateReceiptNumber(),
                        'Account credit applied to monthly installment: ' . $mm['plan_name']
                    ]);
                }
            } catch (PDOException $e) {}

            // Send payment receipt email
            send_payment_receipt_email([
                'student_id'     => (int) $mm['student_id'],
                'amount'         => $chargeTotal,
                'payment_type'   => 'membership',
                'description'    => 'Monthly installment ' . $installment_num . '/' . $mm['duration_months'] . ' for ' . $mm['plan_name'],
                'receipt_number' => $receiptNum ?? '',
                'transaction_id' => $chargeResult['transaction_id'] ?? null,
                'payment_method' => $amountCharged > 0 ? 'credit_card' : 'account_credit',
            ]);

            $monthlyLogStmt->execute([
                current_school_id(), $mm['id'], $mm['student_id'], 'renewed',
                $mm['end_date'], $mm['end_date'], $chargeTotal,
                'Monthly installment ' . $installment_num . '/' . $mm['duration_months'] . ' charged successfully ($' . number_format($monthly_amount, 2) . ' + $' . number_format($serviceFeeAmount, 2) . ' fee).'
                . ($creditUsed > 0 ? ' Credit used: $' . number_format($creditUsed, 2) : '')
            ]);
            $monthlyResults['billed']++;

            // If all installments are now paid and auto_renew is ON, reset for next cycle
            if ($newChargesMade >= $mm['duration_months'] && $mm['auto_renew']) {
                $new_end = date('Y-m-d', strtotime($mm['end_date'] . ' + ' . $mm['duration_months'] . ' months'));
                $pdo->prepare("UPDATE memberships SET monthly_charges_made = 0, end_date = ? WHERE id = ?" . school_where())
                    ->execute([$new_end, $mm['id'], current_school_id()]);
                $monthlyLogStmt->execute([
                    current_school_id(), $mm['id'], $mm['student_id'], 'renewed',
                    $mm['end_date'], $new_end, 0,
                    'All monthly installments complete. Auto-renewed for next cycle.'
                ]);
                $monthlyResults['completed']++;
            }
        } else {
            // Monthly installment payment failed — log but don't expire immediately
            $monthlyLogStmt->execute([
                current_school_id(), $mm['id'], $mm['student_id'], 'payment_failed',
                $mm['end_date'], null, $monthly_amount,
                'Monthly installment failed: ' . ($chargeResult['error'] ?? 'Unknown error')
            ]);
            $monthlyResults['failed']++;

            // Mark membership as declined and reset any admin override (triggers student lockout)
            $pdo->prepare("UPDATE memberships SET payment_status = 'declined' WHERE id = ?" . school_where())
                ->execute([$mm['id'], current_school_id()]);
            $pdo->prepare("UPDATE students SET payment_lockout_override = 0 WHERE id = ?" . school_where())
                ->execute([$mm['student_id'], current_school_id()]);

            // Send payment failure email notification (requires email consent)
            if (is_email_configured() && getSetting('payment_failure_email_enabled', '1') === '1' && !empty($mm['email']) && has_comm_consent((int)$mm['student_id'], 'email')) {
                $tpl = get_notification_template('payment_failed_monthly', [
                    '{student_name}' => htmlspecialchars(trim($mm['first_name'] . ' ' . $mm['last_name'])),
                    '{amount}'       => '$' . number_format($monthly_amount, 2),
                    '{plan_name}'    => htmlspecialchars($mm['plan_name']),
                    '{school_name}'  => htmlspecialchars(getSiteName()),
                ]);
                send_email($mm['email'], $tpl['subject'], $tpl['body'], ['type' => 'student', 'id' => $mm['student_id']]);
            }
        }
    } else {
        // No gateway — log the missed billing
        $monthlyLogStmt->execute([
            current_school_id(), $mm['id'], $mm['student_id'], 'no_gateway',
            $mm['end_date'], null, $monthly_amount,
            'No payment gateway configured for monthly installment ' . $installment_num . '/' . $mm['duration_months']
        ]);
        $monthlyResults['failed']++;
    }
}

// =====================================================================
// STEP 3: Find memberships due for renewal (skip monthly-billed plans)
// =====================================================================
$params = [];
$dueSql = "
    SELECT m.*, mp.name AS plan_name, mp.duration_months, mp.price,
           mp.billing_frequency, s.first_name, s.last_name, s.email
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    JOIN students s ON m.student_id = s.id
    WHERE m.status = 'active'
      AND m.end_date <= CURDATE()
      AND (mp.billing_frequency = 'upfront' OR mp.billing_frequency IS NULL)
      AND (mp.is_afterschool = 0 OR mp.is_afterschool IS NULL)
      AND (mp.is_camp = 0 OR mp.is_camp IS NULL)" . school_where('m') . "
    ORDER BY m.end_date ASC";
school_param($params);
$dueStmt = $pdo->prepare($dueSql);
$dueStmt->execute($params);
$due = $dueStmt->fetchAll();

$results = [
    'renewed'        => 0,
    'expired'        => 0,
    'payment_failed' => 0,
    'no_gateway'     => 0,
    'total_processed' => count($due),
];

$logStmt = $pdo->prepare("
    INSERT INTO renewal_log (school_id, membership_id, student_id, action, old_end_date, new_end_date, amount, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$updateMembership = $pdo->prepare("UPDATE memberships SET end_date = ?, status = ? WHERE id = ?" . school_where());

foreach ($due as $m) {
    $old_end = $m['end_date'];

    if (!$m['auto_renew']) {
        // Auto-renew is OFF — expire
        $updateMembership->execute([$old_end, 'expired', $m['id'], current_school_id()]);
        $logStmt->execute([
            current_school_id(), $m['id'], $m['student_id'], 'expired',
            $old_end, null, null,
            'Auto-renew was disabled. Membership expired.'
        ]);
        $results['expired']++;
        continue;
    }

    // Auto-renew is ON
    // Calculate service fee on renewal amount
    $renewalFeeBreakdown = calculateTotalWithFees([
        'base_amount'      => (float) $m['price'],
        'registration_fee' => 0,
        'discount_code'    => '',
        'plan_id'          => (int) $m['plan_id'],
    ]);
    $renewalChargeTotal = $renewalFeeBreakdown['total'];
    $renewalServiceFee  = $renewalFeeBreakdown['service_fee'];

    if ($gateway_configured) {
        // Attempt payment via the unified gateway abstraction
        $chargeDesc = 'Auto-renewal: ' . $m['plan_name'];
        if ($renewalServiceFee > 0) $chargeDesc .= ' (incl. service fee)';

        $chargeResult = charge_student(
            (int) $m['student_id'],
            $renewalChargeTotal,
            $chargeDesc
        );

        if ($chargeResult['success']) {
            $creditUsed = $chargeResult['credit_used'] ?? 0;
            $amountCharged = $chargeResult['amount_charged'] ?? $renewalChargeTotal;

            // Renew: extend from end_date by duration_months, mark paid (clears lockout)
            $new_end = date('Y-m-d', strtotime($old_end . ' + ' . $m['duration_months'] . ' months'));
            $updateMembership->execute([$new_end, 'active', $m['id'], current_school_id()]);
            $pdo->prepare("UPDATE memberships SET payment_status = 'paid' WHERE id = ?" . school_where())
                ->execute([$m['id'], current_school_id()]);
            $pdo->prepare("UPDATE students SET payment_lockout_override = 0 WHERE id = ?" . school_where())
                ->execute([$m['student_id'], current_school_id()]);

            // Auto-reactivate if student was deactivated for payment reasons
            try {
                $reactivateCheck2 = $pdo->prepare("SELECT status, deactivation_reason FROM students WHERE id = ?" . school_where());
                $reactivateCheck2->execute([$m['student_id'], current_school_id()]);
                $stuRow2 = $reactivateCheck2->fetch();
                if ($stuRow2 && $stuRow2['status'] === 'inactive' && $stuRow2['deactivation_reason'] === 'payment') {
                    $pdo->prepare("UPDATE students SET status = 'active', inactive_since = NULL, deactivation_reason = NULL WHERE id = ?" . school_where())
                        ->execute([$m['student_id'], current_school_id()]);
                    if (function_exists('app_log')) {
                        app_log('info', "Auto-reactivated student {$m['student_id']} after successful renewal payment", ['category' => 'reactivation']);
                    }
                }
            } catch (\Throwable $e) { /* don't crash billing on reactivation error */ }

            // Record the card payment (if any)
            try {
                if ($amountCharged > 0) {
                    $receiptNum = generateReceiptNumber();
                    $txnNote = 'Auto-renewal payment for ' . $m['plan_name'];
                    if (!empty($chargeResult['transaction_id'])) {
                        $txnNote .= ' | Txn: ' . $chargeResult['transaction_id'];
                    }
                    if ($creditUsed > 0) {
                        $txnNote .= ' | Credit applied: $' . number_format($creditUsed, 2);
                    }
                    if ($renewalServiceFee > 0) {
                        $txnNote .= ' | Service fee: $' . number_format($renewalServiceFee, 2);
                    }
                    $pdo->prepare("
                        INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount, payment_method, payment_date, receipt_number, notes)
                        VALUES (?, ?, 'membership', ?, ?, 'credit_card', CURDATE(), ?, ?)
                    ")->execute([
                        current_school_id(), $m['student_id'], $m['id'], $amountCharged, $receiptNum, $txnNote
                    ]);
                }

                // Record credit portion (if any)
                if ($creditUsed > 0) {
                    $pdo->prepare("
                        INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount, payment_method, payment_date, receipt_number, notes)
                        VALUES (?, ?, 'membership', ?, ?, 'account_credit', CURDATE(), ?, ?)
                    ")->execute([
                        current_school_id(), $m['student_id'], $m['id'], $creditUsed, generateReceiptNumber(),
                        'Account credit applied to auto-renewal: ' . $m['plan_name']
                    ]);
                }
            } catch (PDOException $e) {
                // Payment record failed but membership was renewed
            }

            // Send payment receipt email
            send_payment_receipt_email([
                'student_id'     => (int) $m['student_id'],
                'amount'         => $renewalChargeTotal,
                'payment_type'   => 'membership',
                'description'    => 'Auto-renewal for ' . $m['plan_name'],
                'receipt_number' => $receiptNum ?? '',
                'transaction_id' => $chargeResult['transaction_id'] ?? null,
                'payment_method' => $amountCharged > 0 ? 'credit_card' : 'account_credit',
            ]);

            $renewNote = 'Auto-renewed via payment gateway ($' . number_format($m['price'], 2) . ' + $' . number_format($renewalServiceFee, 2) . ' fee).';
            if ($creditUsed > 0) {
                $renewNote .= ' Credit used: $' . number_format($creditUsed, 2) . '.';
            }
            if (!empty($chargeResult['transaction_id'])) {
                $renewNote .= ' Txn: ' . $chargeResult['transaction_id'];
            }
            $logStmt->execute([
                current_school_id(), $m['id'], $m['student_id'], 'renewed',
                $old_end, $new_end, $renewalChargeTotal,
                $renewNote
            ]);
            $results['renewed']++;
        } else {
            // Payment failed — expire and trigger lockout
            $updateMembership->execute([$old_end, 'expired', $m['id'], current_school_id()]);
            $pdo->prepare("UPDATE memberships SET payment_status = 'declined' WHERE id = ?" . school_where())
                ->execute([$m['id'], current_school_id()]);
            $pdo->prepare("UPDATE students SET payment_lockout_override = 0 WHERE id = ?" . school_where())
                ->execute([$m['student_id'], current_school_id()]);
            $logStmt->execute([
                current_school_id(), $m['id'], $m['student_id'], 'payment_failed',
                $old_end, null, $renewalChargeTotal,
                'Gateway payment failed: ' . ($chargeResult['error'] ?? 'Unknown error') . '. Membership expired.'
            ]);
            $results['payment_failed']++;

            // Send renewal failure email notification (requires email consent)
            if (is_email_configured() && getSetting('payment_failure_email_enabled', '1') === '1' && !empty($m['email']) && has_comm_consent((int)$m['student_id'], 'email')) {
                $tpl = get_notification_template('membership_expired', [
                    '{student_name}' => htmlspecialchars(trim($m['first_name'] . ' ' . $m['last_name'])),
                    '{amount}'       => '$' . number_format((float)$renewalChargeTotal, 2),
                    '{plan_name}'    => htmlspecialchars($m['plan_name']),
                    '{school_name}'  => htmlspecialchars(getSiteName()),
                ]);
                send_email($m['email'], $tpl['subject'], $tpl['body'], ['type' => 'student', 'id' => $m['student_id']]);
            }
        }
    } else {
        // No gateway configured — expire with specific note
        $updateMembership->execute([$old_end, 'expired', $m['id'], current_school_id()]);
        $logStmt->execute([
            current_school_id(), $m['id'], $m['student_id'], 'no_gateway',
            $old_end, null, $m['price'],
            'No payment gateway configured. Membership expired for manual renewal.'
        ]);
        $results['no_gateway']++;
    }
}

// =====================================================================
// STEP 4: Check and send absence warning emails
// =====================================================================
$absenceResults = ['checked' => 0, 'warned' => 0, 'emails_sent' => 0, 'emails_failed' => 0];
try {
    $absenceResults = check_and_send_absence_warnings();
} catch (\Exception $e) {
    // Don't let absence check errors crash the cron
}

// =====================================================================
// STEP 4b: Auto-deactivate students with no payment in 30+ days
// =====================================================================
// Finds active students who have a membership but whose last completed
// payment is older than 30 days (or who have never made a payment).
// Sets status = 'inactive' and records inactive_since date.
$deactivated = 0;
try {
    // First, capture which students will be deactivated (for cascade)
    $selectParams = [];
    $delinqSelectSql = "
        SELECT s.id FROM students s
        WHERE s.status = 'active'
          AND s.id IN (
              SELECT m.student_id
              FROM memberships m
              WHERE m.status = 'active'
                AND m.payment_status IN ('declined', 'pending')
                AND m.end_date >= CURDATE()
                " . school_where('m') . "
          )
          AND s.id NOT IN (
              SELECT p.student_id
              FROM payments p
              WHERE p.status = 'completed'
                AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          )
          AND s.payment_lockout_override = 0" . school_where('s');
    school_param($selectParams);
    school_param($selectParams);
    $selectStmt = $pdo->prepare($delinqSelectSql);
    $selectStmt->execute($selectParams);
    $delinquentIds = $selectStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($delinquentIds)) {
        $params = [];
        $delinqSql = "
            UPDATE students s
            SET s.status = 'inactive', s.inactive_since = CURDATE()
            WHERE s.status = 'active'
              AND s.id IN (
                  SELECT m.student_id
                  FROM memberships m
                  WHERE m.status = 'active'
                    AND m.payment_status IN ('declined', 'pending')
                    AND m.end_date >= CURDATE()
                    " . school_where('m') . "
              )
              AND s.id NOT IN (
                  SELECT p.student_id
                  FROM payments p
                  WHERE p.status = 'completed'
                    AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              )
              AND s.payment_lockout_override = 0" . school_where('s');
        school_param($params);
        school_param($params); // second school_where for s alias
        $stmt = $pdo->prepare($delinqSql);
        $stmt->execute($params);
        $deactivated = $stmt->rowCount();

        // Run deactivation cascade for each affected student
        foreach ($delinquentIds as $delinqId) {
            deactivate_student_cascade((int)$delinqId, 'payment');
        }
    }

    // Log each deactivated student for audit trail
    if ($deactivated > 0 && function_exists('app_log')) {
        app_log('info', "Auto-deactivated {$deactivated} student(s) with no payment in 30+ days", [
            'category' => 'delinquency',
            'school_id' => current_school_id(),
            'count' => $deactivated,
        ]);
    }
} catch (\Throwable $e) {
    // Don't crash cron on delinquency check errors
    if (function_exists('app_log')) {
        app_log('error', 'Auto-deactivation failed: ' . $e->getMessage(), ['category' => 'delinquency']);
    }
}
$totalDeactivated += $deactivated;

    // Accumulate per-school results into totals
    $totalMonthlyResults['billed']        += $monthlyResults['billed'];
    $totalMonthlyResults['failed']        += $monthlyResults['failed'];
    $totalMonthlyResults['completed']     += $monthlyResults['completed'];
    $totalMonthlyResults['total_monthly'] += $monthlyResults['total_monthly'];
    $totalResults['renewed']        += $results['renewed'];
    $totalResults['expired']        += $results['expired'];
    $totalResults['payment_failed'] += $results['payment_failed'];
    $totalResults['no_gateway']     += $results['no_gateway'];
    $totalResults['total_processed'] += $results['total_processed'];
    $totalAbsenceResults['checked']      += $absenceResults['checked'];
    $totalAbsenceResults['warned']       += $absenceResults['warned'];
    $totalAbsenceResults['emails_sent']  += $absenceResults['emails_sent'];
    $totalAbsenceResults['emails_failed'] += $absenceResults['emails_failed'];

} // end foreach school
unset($_SESSION['active_school_id']);

// =====================================================================
// STEP 5: Log retention cleanup (runs once, not per-school)
// =====================================================================
$logRetentionResults = ['audit_deleted' => 0, 'app_deleted' => 0];
try {
    $retentionDays = (int) getSetting('log_retention_days', '90');
    $retentionDays = max(7, min(365, $retentionDays));

    // Clean old audit_log entries
    $stmt = $pdo->prepare("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$retentionDays]);
    $logRetentionResults['audit_deleted'] = $stmt->rowCount();

    // Clean old app_log entries
    $stmt = $pdo->prepare("DELETE FROM app_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$retentionDays]);
    $logRetentionResults['app_deleted'] = $stmt->rowCount();

    $totalCleaned = $logRetentionResults['audit_deleted'] + $logRetentionResults['app_deleted'];
    if ($totalCleaned > 0 && function_exists('app_log')) {
        app_log('info', "Log retention cleanup: removed {$logRetentionResults['audit_deleted']} audit log and {$logRetentionResults['app_deleted']} app log entries older than {$retentionDays} days", [
            'category' => 'maintenance',
            'audit_deleted' => $logRetentionResults['audit_deleted'],
            'app_deleted'   => $logRetentionResults['app_deleted'],
            'retention_days' => $retentionDays,
        ]);
    }
} catch (Throwable $e) {
    // Don't let log cleanup errors crash the cron
    if (function_exists('app_log')) {
        app_log('error', 'Log retention cleanup failed: ' . $e->getMessage(), ['category' => 'maintenance']);
    }
}

// --- Output results (aggregated across all schools) ---
$summary = "Renewal processing complete. ";
if ($totalExpiredChanges > 0) {
    $summary .= "{$totalExpiredChanges} pending plan change(s) expired. ";
}
if ($totalMonthlyResults['total_monthly'] > 0) {
    $summary .= "Monthly billing: {$totalMonthlyResults['billed']} billed, {$totalMonthlyResults['failed']} failed"
        . ($totalMonthlyResults['completed'] > 0 ? ", {$totalMonthlyResults['completed']} cycles completed" : '') . ". ";
}
$summary .= "{$totalResults['total_processed']} renewal(s) processed: "
    . "{$totalResults['renewed']} renewed, "
    . "{$totalResults['expired']} expired, "
    . "{$totalResults['payment_failed']} payment failed, "
    . "{$totalResults['no_gateway']} no gateway.";
if ($totalAbsenceResults['checked'] > 0 || $totalAbsenceResults['warned'] > 0) {
    $summary .= " Absence check: {$totalAbsenceResults['checked']} students checked, "
        . "{$totalAbsenceResults['warned']} warned, "
        . "{$totalAbsenceResults['emails_sent']} email(s) sent.";
}
if ($totalDeactivated > 0) {
    $summary .= " Delinquency: {$totalDeactivated} student(s) marked inactive (no payment in 30+ days).";
}
$totalLogsCleaned = $logRetentionResults['audit_deleted'] + $logRetentionResults['app_deleted'];
if ($totalLogsCleaned > 0) {
    $summary .= " Log cleanup: {$logRetentionResults['audit_deleted']} audit + {$logRetentionResults['app_deleted']} app log entries removed.";
}

if ($is_cli) {
    echo $summary . "\n";
    echo "Done.\n";
    exit(0);
} else {
    // Browser: flash message and redirect back
    $_SESSION['flash_message'] = $summary;
    header('Location: memberships.php');
    exit;
}
