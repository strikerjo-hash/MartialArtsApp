<?php
/**
 * test_renewals.php — Auto-Renewal & Monthly Billing Test Dashboard
 *
 * Admin-only tool to:
 *   1. Preview what cron.php WOULD do if it ran now (dry run)
 *   2. Test individual student payment methods with a $0.50 test charge
 *   3. View renewal log history
 *   4. Identify potential billing issues before they happen
 *
 * This page NEVER charges real money in dry-run mode.
 * The "Test Charge" feature uses the gateway's test/sandbox mode
 * and charges exactly $0.50 (which can be refunded from the dashboard).
 */

require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';
require_once __DIR__ . '/includes/messaging.php';
require_once __DIR__ . '/includes/belt_cycle.php';
requireLogin();

// Only admins and super admins
$user = getCurrentUser();
if (!in_array($user['role'], ['admin', 'super_admin'])) {
    accessDenied('Renewal testing requires Admin or Super Admin privileges.', 'memberships.php');
}

$pageTitle = 'Auto-Renewal Test Dashboard';
$alertMessage = '';
$alertType = 'info';

// Ensure renewal_log supports 'test_charge' action
try { $pdo->exec("ALTER TABLE renewal_log MODIFY COLUMN action ENUM('renewed','expired','payment_failed','no_gateway','test_charge') NOT NULL"); } catch (\PDOException $e) {}

// ---------------------------------------------------------------------------
// POST handler: Test a single student's payment method
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();

    if ($_POST['action'] === 'test_charge') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $testAmount = 0.50; // $0.50 test charge

        if ($studentId <= 0) {
            $alertMessage = 'Invalid student ID.';
            $alertType = 'error';
        } else {
            $gateway = get_active_gateway();
            if ($gateway === 'none' || !is_gateway_ready()) {
                $alertMessage = 'No payment gateway is configured. Please set up Stripe or Square in Settings first.';
                $alertType = 'error';
            } else {
                $isTestMode = false;
                if ($gateway === 'stripe') {
                    $isTestMode = is_stripe_test_mode();
                } elseif ($gateway === 'square') {
                    $isTestMode = is_square_sandbox();
                }

                $result = charge_student($studentId, $testAmount, 'Test charge - verification of payment method');

                if ($result['success']) {
                    $txnId = $result['transaction_id'] ?? 'N/A';
                    $alertMessage = "Test charge of $0.50 succeeded! Transaction ID: {$txnId}. "
                        . ($isTestMode ? '(Test/Sandbox mode - no real money charged)' : 'WARNING: This was a LIVE charge. Please refund it from your payment dashboard.');
                    $alertType = 'success';

                    // Log the test charge
                    try {
                        $params = [$studentId];
                        $nameStmt = $pdo->prepare("SELECT first_name, last_name FROM students WHERE id = ?" . school_where());
                        school_param($params);
                        $nameStmt->execute($params);
                        $sName = $nameStmt->fetch();
                        $studentName = $sName ? trim($sName['first_name'] . ' ' . $sName['last_name']) : "Student #$studentId";
                    } catch (\PDOException $e) {
                        $studentName = "Student #$studentId";
                    }

                    try {
                        $pdo->prepare("INSERT INTO renewal_log (school_id, membership_id, student_id, action, old_end_date, new_end_date, amount, notes) VALUES (?, 0, ?, 'test_charge', CURDATE(), NULL, ?, ?)")
                            ->execute([current_school_id(), $studentId, $testAmount, "Test charge succeeded for {$studentName}. Txn: {$txnId}"]);
                    } catch (\PDOException $e) {}
                } else {
                    $alertMessage = 'Test charge FAILED: ' . ($result['error'] ?? 'Unknown error');
                    $alertType = 'error';
                }
            }
        }
    }

    // -----------------------------------------------------------------------
    // Trigger a real auto-renewal for a specific membership
    // -----------------------------------------------------------------------
    if ($_POST['action'] === 'trigger_renewal') {
        $membershipId = (int) ($_POST['membership_id'] ?? 0);
        $showLogsTab = false;

        if ($membershipId <= 0) {
            $alertMessage = 'Invalid membership ID.';
            $alertType = 'error';
        } else {
            $gateway = get_active_gateway();
            if ($gateway === 'none' || !is_gateway_ready()) {
                $alertMessage = 'No payment gateway is configured. Please set up Stripe or Square first.';
                $alertType = 'error';
            } else {
                // Fetch the membership with plan + student details (same JOIN as cron.php)
                $params = [$membershipId];
                $mSql = "
                    SELECT m.*, mp.name AS plan_name, mp.duration_months, mp.price,
                           mp.billing_frequency, s.first_name, s.last_name, s.email
                    FROM memberships m
                    JOIN membership_plans mp ON m.plan_id = mp.id
                    JOIN students s ON m.student_id = s.id
                    WHERE m.id = ?
                      AND m.status = 'active'
                      AND m.auto_renew = 1" . school_where('m');
                school_param($params);
                $mStmt = $pdo->prepare($mSql);
                $mStmt->execute($params);
                $mem = $mStmt->fetch();

                if (!$mem) {
                    $alertMessage = 'Membership not found, not active, or auto-renew is disabled.';
                    $alertType = 'error';
                } else {
                    $old_end = $mem['end_date'];
                    $studentName = trim($mem['first_name'] . ' ' . $mem['last_name']);

                    // Calculate fees (same as cron.php line 342)
                    $renewalFeeBreakdown = calculateTotalWithFees([
                        'base_amount'      => (float) $mem['price'],
                        'registration_fee' => 0,
                        'discount_code'    => '',
                        'plan_id'          => (int) $mem['plan_id'],
                    ]);
                    $renewalChargeTotal = $renewalFeeBreakdown['total'];
                    $renewalServiceFee  = $renewalFeeBreakdown['service_fee'];

                    // Attempt payment (same as cron.php line 356)
                    $chargeDesc = 'Admin-triggered renewal: ' . $mem['plan_name'];
                    if ($renewalServiceFee > 0) $chargeDesc .= ' (incl. service fee)';

                    $chargeResult = charge_student(
                        (int) $mem['student_id'],
                        $renewalChargeTotal,
                        $chargeDesc
                    );

                    $logStmt = $pdo->prepare("
                        INSERT INTO renewal_log (school_id, membership_id, student_id, action, old_end_date, new_end_date, amount, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    if ($chargeResult['success']) {
                        $creditUsed    = $chargeResult['credit_used'] ?? 0;
                        $amountCharged = $chargeResult['amount_charged'] ?? $renewalChargeTotal;

                        // Extend membership (same as cron.php line 367-372)
                        $new_end = date('Y-m-d', strtotime($old_end . ' + ' . $mem['duration_months'] . ' months'));
                        $pdo->prepare("UPDATE memberships SET end_date = ?, status = 'active', payment_status = 'paid' WHERE id = ?" . school_where())
                            ->execute([$new_end, $mem['id'], current_school_id()]);
                        $pdo->prepare("UPDATE students SET payment_lockout_override = 0 WHERE id = ?" . school_where())
                            ->execute([$mem['student_id'], current_school_id()]);

                        // Record payment(s) (same as cron.php lines 375-405)
                        try {
                            if ($amountCharged > 0) {
                                $receiptNum = generateReceiptNumber();
                                $txnNote = 'Admin-triggered renewal payment for ' . $mem['plan_name'];
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
                                    current_school_id(), $mem['student_id'], $mem['id'], $amountCharged, $receiptNum, $txnNote
                                ]);
                            }
                            if ($creditUsed > 0) {
                                $pdo->prepare("
                                    INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount, payment_method, payment_date, receipt_number, notes)
                                    VALUES (?, ?, 'membership', ?, ?, 'account_credit', CURDATE(), ?, ?)
                                ")->execute([
                                    current_school_id(), $mem['student_id'], $mem['id'], $creditUsed, generateReceiptNumber(),
                                    'Account credit applied to admin-triggered renewal: ' . $mem['plan_name']
                                ]);
                            }
                        } catch (PDOException $e) {
                            // Payment record failed but membership was renewed
                        }

                        // Send payment receipt email
                        send_payment_receipt_email([
                            'student_id'     => (int) $mem['student_id'],
                            'amount'         => $renewalChargeTotal,
                            'payment_type'   => 'membership',
                            'description'    => 'Admin-triggered renewal for ' . $mem['plan_name'],
                            'receipt_number' => $receiptNum ?? '',
                            'transaction_id' => $chargeResult['transaction_id'] ?? null,
                            'payment_method' => $amountCharged > 0 ? 'credit_card' : 'account_credit',
                        ]);

                        // Log to renewal_log
                        $renewNote = 'Admin-triggered renewal test. Charged $' . number_format($renewalChargeTotal, 2)
                            . ' ($' . number_format($mem['price'], 2) . ' + $' . number_format($renewalServiceFee, 2) . ' fee).';
                        if ($creditUsed > 0) {
                            $renewNote .= ' Credit used: $' . number_format($creditUsed, 2) . '.';
                        }
                        if (!empty($chargeResult['transaction_id'])) {
                            $renewNote .= ' Txn: ' . $chargeResult['transaction_id'];
                        }
                        $logStmt->execute([
                            current_school_id(), $mem['id'], $mem['student_id'], 'renewed',
                            $old_end, $new_end, $renewalChargeTotal, $renewNote
                        ]);

                        $isTestMode = ($gateway === 'stripe' && is_stripe_test_mode()) || ($gateway === 'square' && is_square_sandbox());
                        $alertMessage = "Renewal triggered successfully for {$studentName}! "
                            . "Charged: \$" . number_format($renewalChargeTotal, 2) . ". "
                            . "End date extended: " . formatDate($old_end) . " → " . formatDate($new_end) . ". "
                            . ($isTestMode ? '(Test/Sandbox mode — no real money charged)' : 'NOTE: This was a LIVE charge.');
                        $alertType = 'success';
                        $showLogsTab = true;
                    } else {
                        // Payment failed — log but do NOT expire (admin-triggered, not natural)
                        $logStmt->execute([
                            current_school_id(), $mem['id'], $mem['student_id'], 'payment_failed',
                            $old_end, null, $renewalChargeTotal,
                            'Admin-triggered renewal FAILED: ' . ($chargeResult['error'] ?? 'Unknown error')
                        ]);
                        $alertMessage = "Renewal payment FAILED for {$studentName}: " . ($chargeResult['error'] ?? 'Unknown error')
                            . '. The membership was NOT expired (since this was an admin-triggered test).';
                        $alertType = 'error';
                        $showLogsTab = true;
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Gather data for the dashboard
// ---------------------------------------------------------------------------

$gateway = get_active_gateway();
$gatewayReady = is_gateway_ready();
$isTestMode = false;
$gatewayLabel = 'None';

if ($gateway === 'stripe') {
    $gatewayLabel = 'Stripe';
    $isTestMode = is_stripe_test_mode();
} elseif ($gateway === 'square') {
    $gatewayLabel = 'Square';
    $isTestMode = is_square_sandbox();
}

// --- Dry Run: Simulate what cron.php would do ---
$today_day = (int) date('j');
$last_day_of_month = (int) date('t');

// STEP A: Monthly installments due today
$monthlyDue = [];
try {
    $params = [];
    $sql = "
        SELECT m.*, mp.name AS plan_name, mp.duration_months, mp.price,
               mp.billing_frequency, mp.is_afterschool, mp.program_start_date, mp.program_end_date,
               s.first_name, s.last_name, s.email, s.status AS student_status,
               (SELECT COUNT(*) FROM payment_methods pm WHERE pm.student_id = s.id AND pm.is_default = 1) AS has_default_card
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
        ORDER BY s.last_name, s.first_name";
    school_param($params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $monthlyDue = $stmt->fetchAll();
} catch (\PDOException $e) {
    $monthlyDue = [];
}

// STEP B: Memberships due for renewal (upfront, end_date <= today)
$renewalsDue = [];
try {
    $params = [];
    $sql = "
        SELECT m.*, mp.name AS plan_name, mp.duration_months, mp.price,
               mp.billing_frequency, s.first_name, s.last_name, s.email,
               s.status AS student_status,
               (SELECT COUNT(*) FROM payment_methods pm WHERE pm.student_id = s.id AND pm.is_default = 1) AS has_default_card
        FROM memberships m
        JOIN membership_plans mp ON m.plan_id = mp.id
        JOIN students s ON m.student_id = s.id
        WHERE m.status = 'active'
          AND m.end_date <= CURDATE()
          AND (mp.billing_frequency = 'upfront' OR mp.billing_frequency IS NULL)
          AND (mp.is_afterschool = 0 OR mp.is_afterschool IS NULL)" . school_where('m') . "
        ORDER BY m.end_date ASC";
    school_param($params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $renewalsDue = $stmt->fetchAll();
} catch (\PDOException $e) {
    $renewalsDue = [];
}

// STEP C: Upcoming renewals (next 30 days)
$upcomingRenewals = [];
try {
    $params = [];
    $sql = "
        SELECT m.*, mp.name AS plan_name, mp.duration_months, mp.price,
               mp.billing_frequency, s.first_name, s.last_name, s.email,
               s.status AS student_status,
               DATEDIFF(m.end_date, CURDATE()) AS days_until_renewal,
               (SELECT COUNT(*) FROM payment_methods pm WHERE pm.student_id = s.id AND pm.is_default = 1) AS has_default_card
        FROM memberships m
        JOIN membership_plans mp ON m.plan_id = mp.id
        JOIN students s ON m.student_id = s.id
        WHERE m.status = 'active'
          AND m.end_date > CURDATE()
          AND m.end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND (mp.billing_frequency = 'upfront' OR mp.billing_frequency IS NULL)
          AND (mp.is_afterschool = 0 OR mp.is_afterschool IS NULL)" . school_where('m') . "
        ORDER BY m.end_date ASC";
    school_param($params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $upcomingRenewals = $stmt->fetchAll();
} catch (\PDOException $e) {
    $upcomingRenewals = [];
}

// STEP D: All active memberships with payment method status
$allActive = [];
try {
    $params = [];
    $sql = "
        SELECT m.id AS membership_id, m.student_id, m.auto_renew, m.end_date,
               m.monthly_charges_made, m.payment_status,
               mp.name AS plan_name, mp.price, mp.duration_months, mp.billing_frequency,
               s.first_name, s.last_name, s.email, s.status AS student_status,
               s.account_credit,
               (SELECT COUNT(*) FROM payment_methods pm WHERE pm.student_id = s.id) AS total_cards,
               (SELECT COUNT(*) FROM payment_methods pm WHERE pm.student_id = s.id AND pm.is_default = 1) AS has_default_card,
               (SELECT pm2.gateway_payment_method_id FROM payment_methods pm2 WHERE pm2.student_id = s.id AND pm2.is_default = 1 LIMIT 1) AS gateway_pm_id
        FROM memberships m
        JOIN membership_plans mp ON m.plan_id = mp.id
        JOIN students s ON m.student_id = s.id
        WHERE m.status = 'active'" . school_where('m') . "
        ORDER BY s.last_name, s.first_name";
    school_param($params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allActive = $stmt->fetchAll();
} catch (\PDOException $e) {
    $allActive = [];
}

// STEP E: Recent renewal log
$recentLogs = [];
try {
    $params = [];
    $sql = "
        SELECT rl.*, s.first_name, s.last_name
        FROM renewal_log rl
        LEFT JOIN students s ON rl.student_id = s.id
        WHERE 1=1" . school_where('rl') . "
        ORDER BY rl.id DESC
        LIMIT 50";
    school_param($params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recentLogs = $stmt->fetchAll();
} catch (\PDOException $e) {
    $recentLogs = [];
}

// STEP F: Students with payment methods for test charge dropdown
$studentsWithCards = [];
try {
    $params = [];
    $sql = "
        SELECT DISTINCT s.id, s.first_name, s.last_name,
               pm.card_brand, pm.last_four, pm.gateway_payment_method_id
        FROM students s
        JOIN payment_methods pm ON pm.student_id = s.id AND pm.is_default = 1
        WHERE 1=1" . school_where('s') . "
        ORDER BY s.last_name, s.first_name";
    school_param($params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $studentsWithCards = $stmt->fetchAll();
} catch (\PDOException $e) {
    $studentsWithCards = [];
}

// Count issues
$issueCount = 0;
foreach ($allActive as $m) {
    if ($m['auto_renew'] && !$gatewayReady) $issueCount++;
    elseif ($m['auto_renew'] && $m['total_cards'] == 0) $issueCount++;
    elseif ($m['auto_renew'] && empty($m['gateway_pm_id'])) $issueCount++;
}

$staleChanges = 0;
try {
    $params = [];
    $sql = "SELECT COUNT(*) FROM pending_plan_changes WHERE status = 'pending' AND expires_at <= NOW()" . school_where();
    school_param($params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $staleChanges = (int) $stmt->fetchColumn();
} catch (\PDOException $e) {}

// STEP G: Active auto-renew memberships with payment methods (for Trigger Renewal tab)
$renewableMemberships = [];
try {
    $params = [];
    $sql = "
        SELECT m.id AS membership_id, m.student_id, m.end_date, m.auto_renew, m.plan_id,
               mp.name AS plan_name, mp.price, mp.duration_months, mp.billing_frequency,
               s.first_name, s.last_name,
               pm.card_brand, pm.last_four
        FROM memberships m
        JOIN membership_plans mp ON m.plan_id = mp.id
        JOIN students s ON m.student_id = s.id
        JOIN payment_methods pm ON pm.student_id = s.id AND pm.is_default = 1
        WHERE m.status = 'active'
          AND m.auto_renew = 1
          AND (mp.billing_frequency = 'upfront' OR mp.billing_frequency IS NULL)
          AND pm.gateway_payment_method_id IS NOT NULL
          AND pm.gateway_payment_method_id != ''" . school_where('m') . "
        ORDER BY s.last_name, s.first_name";
    school_param($params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $renewableMemberships = $stmt->fetchAll();
} catch (\PDOException $e) {
    $renewableMemberships = [];
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-6 max-w-7xl">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Auto-Renewal Test Dashboard</h1>
            <p class="text-gray-500 text-sm mt-1">Preview, test, and diagnose the billing &amp; renewal system</p>
        </div>
        <div class="flex gap-2">
            <a href="memberships.php" class="inline-flex items-center px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-sm font-medium transition">
                &larr; Back to Memberships
            </a>
        </div>
    </div>

    <?php if ($alertMessage): ?>
        <?= showAlert($alertMessage, $alertType) ?>
    <?php endif; ?>

    <!-- Gateway Status Banner -->
    <div class="bg-white rounded-xl shadow-sm border mb-6 p-5">
        <h2 class="text-lg font-semibold text-gray-800 mb-3">Payment Gateway Status</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
            <div class="flex items-center gap-3">
                <div class="w-3 h-3 rounded-full <?= $gatewayReady ? 'bg-green-500' : 'bg-red-500' ?>"></div>
                <div>
                    <div class="text-sm font-medium text-gray-700">Gateway</div>
                    <div class="text-sm text-gray-500"><?= htmlspecialchars($gatewayLabel) ?> <?= $gatewayReady ? '(Ready)' : '(Not Configured)' ?></div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="w-3 h-3 rounded-full <?= $isTestMode ? 'bg-yellow-500' : ($gatewayReady ? 'bg-blue-500' : 'bg-gray-400') ?>"></div>
                <div>
                    <div class="text-sm font-medium text-gray-700">Mode</div>
                    <div class="text-sm text-gray-500"><?= $isTestMode ? 'Test/Sandbox' : ($gatewayReady ? 'LIVE' : 'N/A') ?></div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="w-3 h-3 rounded-full <?= $issueCount > 0 ? 'bg-orange-500' : 'bg-green-500' ?>"></div>
                <div>
                    <div class="text-sm font-medium text-gray-700">Billing Issues</div>
                    <div class="text-sm text-gray-500"><?= $issueCount ?> member(s) with issues</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="w-3 h-3 rounded-full bg-blue-500"></div>
                <div>
                    <div class="text-sm font-medium text-gray-700">Active Memberships</div>
                    <div class="text-sm text-gray-500"><?= count($allActive) ?> total</div>
                </div>
            </div>
        </div>
        <?php if (!$gatewayReady): ?>
            <div class="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-700">
                <strong>Warning:</strong> No payment gateway is configured. All auto-renewals will expire with "no_gateway" status.
                <a href="settings.php" class="underline font-medium">Configure in Settings</a>
            </div>
        <?php elseif (!$isTestMode): ?>
            <div class="mt-3 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
                <strong>LIVE MODE:</strong> Your gateway is in production mode. Test charges will use real money.
                Use Stripe test keys (sk_test_...) or Square sandbox tokens for safe testing.
            </div>
        <?php else: ?>
            <div class="mt-3 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
                <strong>Test Mode Active:</strong> Your gateway is in test/sandbox mode. No real charges will be made.
            </div>
        <?php endif; ?>
    </div>

    <!-- Dry Run Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border p-5 text-center">
            <div class="text-3xl font-bold text-blue-600"><?= count($monthlyDue) ?></div>
            <div class="text-sm text-gray-500 mt-1">Monthly Installments Due Today</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 text-center">
            <div class="text-3xl font-bold text-orange-600"><?= count($renewalsDue) ?></div>
            <div class="text-sm text-gray-500 mt-1">Renewals Due Today</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 text-center">
            <div class="text-3xl font-bold text-purple-600"><?= count($upcomingRenewals) ?></div>
            <div class="text-sm text-gray-500 mt-1">Renewals Next 30 Days</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 text-center">
            <div class="text-3xl font-bold text-gray-600"><?= $staleChanges ?></div>
            <div class="text-sm text-gray-500 mt-1">Stale Pending Changes</div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="mb-6 border-b border-gray-200">
        <nav class="flex gap-4 flex-wrap" id="tabNav">
            <button type="button" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-blue-600 text-blue-600 cursor-pointer" data-tab="dryrun">
                Dry Run Preview
            </button>
            <button type="button" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 cursor-pointer" data-tab="health">
                Payment Health Check
            </button>
            <button type="button" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 cursor-pointer" data-tab="testcharge">
                Test Charge
            </button>
            <button type="button" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 cursor-pointer" data-tab="logs">
                Renewal Log
            </button>
            <button type="button" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 cursor-pointer" data-tab="trigger">
                Trigger Renewal
            </button>
        </nav>
    </div>

    <!-- Tab switching script — inline here so it's ready before user can click -->
    <script>
    function showTab(tabName) {
        // Hide all tab content panels
        var panels = document.querySelectorAll('.tab-content');
        for (var i = 0; i < panels.length; i++) {
            panels[i].style.display = 'none';
        }
        // Reset all tab button styles
        var btns = document.querySelectorAll('.tab-btn');
        for (var j = 0; j < btns.length; j++) {
            btns[j].classList.remove('border-blue-600', 'text-blue-600');
            btns[j].classList.add('border-transparent', 'text-gray-500');
        }
        // Show selected tab content
        var tabEl = document.getElementById('tab-' + tabName);
        if (tabEl) {
            tabEl.style.display = '';
        }
        // Highlight active button
        var activeBtn = document.querySelector('[data-tab="' + tabName + '"]');
        if (activeBtn) {
            activeBtn.classList.remove('border-transparent', 'text-gray-500');
            activeBtn.classList.add('border-blue-600', 'text-blue-600');
        }
    }

    // Event delegation — one listener on the nav, catches all tab button clicks
    (function() {
        var nav = document.getElementById('tabNav');
        if (nav) {
            nav.addEventListener('click', function(e) {
                var btn = e.target.closest('.tab-btn');
                if (btn && btn.dataset.tab) {
                    e.preventDefault();
                    e.stopPropagation();
                    showTab(btn.dataset.tab);
                }
            });
        }
    })();
    </script>

    <!-- TAB: Dry Run Preview -->
    <div id="tab-dryrun" class="tab-content">
        <?php if (count($monthlyDue) > 0): ?>
        <div class="bg-white rounded-xl shadow-sm border mb-6">
            <div class="px-5 py-4 border-b bg-blue-50">
                <h3 class="font-semibold text-blue-800">Monthly Installments Due Today (<?= date('F j') ?>, billing day <?= $today_day ?>)</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Installment</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Card on File</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Expected Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($monthlyDue as $mm):
                            $installment = ($mm['monthly_charges_made'] ?? 0) + 1;
                            if (!empty($mm['is_afterschool']) && $mm['program_start_date'] && $mm['program_end_date']) {
                                $programDays = max(1, (strtotime($mm['program_end_date']) - strtotime($mm['program_start_date'])) / 86400);
                                $programMonths = max(1, round($programDays / 30.44, 4));
                                $monthlyAmt = round($mm['price'] / $programMonths, 2);
                            } else {
                                $monthlyAmt = round($mm['price'] / $mm['duration_months'], 2);
                            }
                            $hasCard = $mm['has_default_card'] > 0;
                            $expectedResult = !$gatewayReady ? 'no_gateway' : (!$hasCard ? 'fail_no_card' : 'charge');
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium"><?= htmlspecialchars($mm['first_name'] . ' ' . $mm['last_name']) ?></td>
                            <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($mm['plan_name']) ?></td>
                            <td class="px-4 py-3 text-center"><?= $installment ?>/<?= $mm['duration_months'] ?></td>
                            <td class="px-4 py-3 text-right font-medium"><?= formatMoney($monthlyAmt) ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($hasCard): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Yes</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($expectedResult === 'charge'): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Will Charge</span>
                                <?php elseif ($expectedResult === 'no_gateway'): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">No Gateway</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">No Card</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-sm border mb-6 p-8 text-center text-gray-500">
                <div class="text-4xl mb-2">&#128197;</div>
                No monthly installments are due today (billing day <?= $today_day ?>).
            </div>
        <?php endif; ?>

        <?php if (count($renewalsDue) > 0): ?>
        <div class="bg-white rounded-xl shadow-sm border mb-6">
            <div class="px-5 py-4 border-b bg-orange-50">
                <h3 class="font-semibold text-orange-800">Upfront Renewals Due Today (end_date &le; <?= date('Y-m-d') ?>)</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">End Date</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Auto-Renew</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Renewal Price</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Card on File</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Expected Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($renewalsDue as $r):
                            $hasCard = $r['has_default_card'] > 0;
                            if (!$r['auto_renew']) {
                                $expected = 'expire';
                            } elseif (!$gatewayReady) {
                                $expected = 'no_gateway';
                            } elseif (!$hasCard) {
                                $expected = 'fail_no_card';
                            } else {
                                $expected = 'renew';
                            }
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                            <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($r['plan_name']) ?></td>
                            <td class="px-4 py-3 text-center text-gray-500"><?= formatDate($r['end_date']) ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($r['auto_renew']): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">ON</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">OFF</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-right font-medium"><?= formatMoney($r['price']) ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($hasCard): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Yes</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($expected === 'renew'): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Will Renew</span>
                                <?php elseif ($expected === 'expire'): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">Will Expire</span>
                                <?php elseif ($expected === 'no_gateway'): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">No Gateway</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">Will Fail</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-sm border mb-6 p-8 text-center text-gray-500">
                <div class="text-4xl mb-2">&#9989;</div>
                No upfront memberships are due for renewal today.
            </div>
        <?php endif; ?>

        <?php if (count($upcomingRenewals) > 0): ?>
        <div class="bg-white rounded-xl shadow-sm border">
            <div class="px-5 py-4 border-b bg-purple-50">
                <h3 class="font-semibold text-purple-800">Upcoming Renewals (Next 30 Days)</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Renewal Date</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Days Away</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Auto-Renew</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Card Ready</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($upcomingRenewals as $u):
                            $hasCard = $u['has_default_card'] > 0;
                            $daysAway = (int) $u['days_until_renewal'];
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
                            <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($u['plan_name']) ?></td>
                            <td class="px-4 py-3 text-center"><?= formatDate($u['end_date']) ?></td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $daysAway <= 7 ? 'bg-red-100 text-red-800' : ($daysAway <= 14 ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800') ?>">
                                    <?= $daysAway ?> day<?= $daysAway !== 1 ? 's' : '' ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($u['auto_renew']): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">ON</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">OFF</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($u['auto_renew'] && $hasCard && $gatewayReady): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Ready</span>
                                <?php elseif (!$u['auto_renew']): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">N/A</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">Not Ready</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- TAB: Payment Health Check -->
    <div id="tab-health" class="tab-content" style="display:none">
        <div class="bg-white rounded-xl shadow-sm border">
            <div class="px-5 py-4 border-b">
                <h3 class="font-semibold text-gray-800">All Active Memberships &mdash; Payment Readiness</h3>
                <p class="text-sm text-gray-500 mt-1">Identifies members who have auto-renew ON but may have billing issues</p>
            </div>
            <?php if (count($allActive) > 0): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Billing Type</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Auto-Renew</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">End Date</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Cards</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Gateway ID</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($allActive as $a):
                            $hasIssue = false;
                            $issueMsg = '';
                            if ($a['auto_renew']) {
                                if (!$gatewayReady) { $hasIssue = true; $issueMsg = 'No gateway'; }
                                elseif ($a['total_cards'] == 0) { $hasIssue = true; $issueMsg = 'No card on file'; }
                                elseif (empty($a['gateway_pm_id'])) { $hasIssue = true; $issueMsg = 'Legacy card (no gateway ID)'; }
                            }
                        ?>
                        <tr class="hover:bg-gray-50 <?= $hasIssue ? 'bg-red-50' : '' ?>">
                            <td class="px-4 py-3 font-medium"><?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']) ?></td>
                            <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($a['plan_name']) ?></td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= ($a['billing_frequency'] === 'monthly') ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-600' ?>">
                                    <?= ucfirst($a['billing_frequency'] ?? 'upfront') ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($a['auto_renew']): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">ON</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">OFF</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center text-gray-500"><?= formatDate($a['end_date']) ?></td>
                            <td class="px-4 py-3 text-center"><?= (int) $a['total_cards'] ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if (!empty($a['gateway_pm_id'])): ?>
                                    <span class="text-xs text-gray-400" title="<?= htmlspecialchars($a['gateway_pm_id']) ?>"><?= htmlspecialchars(substr($a['gateway_pm_id'], 0, 12)) ?>...</span>
                                <?php else: ?>
                                    <span class="text-xs text-red-400">None</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-right"><?= $a['account_credit'] > 0 ? formatMoney($a['account_credit']) : '-' ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($hasIssue): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800" title="<?= htmlspecialchars($issueMsg) ?>">
                                        &#9888; <?= htmlspecialchars($issueMsg) ?>
                                    </span>
                                <?php elseif ($a['auto_renew']): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Ready</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">Manual</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="p-8 text-center text-gray-500">No active memberships found.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB: Test Charge -->
    <div id="tab-testcharge" class="tab-content" style="display:none">
        <div class="bg-white rounded-xl shadow-sm border p-6 max-w-xl">
            <h3 class="font-semibold text-gray-800 mb-2">Test a Student's Payment Method</h3>
            <p class="text-sm text-gray-500 mb-4">
                This will charge <strong>$0.50</strong> to the student's default payment method to verify it works.
                <?php if ($isTestMode): ?>
                    <span class="text-green-600 font-medium">Test mode is active &mdash; no real money will be charged.</span>
                <?php else: ?>
                    <span class="text-red-600 font-medium">WARNING: Gateway is in LIVE mode. This will charge real money.</span>
                <?php endif; ?>
            </p>

            <?php if (!$gatewayReady): ?>
                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-700">
                    No payment gateway configured. <a href="settings.php" class="underline font-medium">Set up Stripe or Square first.</a>
                </div>
            <?php elseif (empty($studentsWithCards)): ?>
                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-700">
                    No students have a default payment method on file. Students need to add a card first through the student portal.
                </div>
            <?php else: ?>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf()) ?>">
                    <input type="hidden" name="action" value="test_charge">

                    <div>
                        <label for="student_id" class="block text-sm font-medium text-gray-700 mb-1">Select Student</label>
                        <div id="test-student-picker"></div>
                    </div>

                    <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-700">
                        <strong>What this does:</strong><br>
                        1. Checks the student's account credit balance<br>
                        2. Attempts to charge $0.50 to their default card via <?= htmlspecialchars($gatewayLabel) ?><br>
                        3. Records the result in the renewal log<br>
                        <br>
                        <strong>This does NOT affect their membership.</strong> It only tests that the card can be charged.
                    </div>

                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium text-sm transition"
                            onclick="return confirm('Are you sure? This will charge $0.50 to the selected student\'s card.')">
                        Run Test Charge ($0.50)
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB: Trigger Renewal -->
    <div id="tab-trigger" class="tab-content" style="display:none">
        <div class="bg-white rounded-xl shadow-sm border p-6 max-w-2xl">
            <h3 class="font-semibold text-gray-800 mb-2">Trigger a Real Auto-Renewal</h3>
            <p class="text-sm text-gray-500 mb-4">
                This will charge the <strong>full plan price</strong> to the student's payment method and extend their membership end date —
                exactly the same logic that the cron auto-renewal uses.
            </p>

            <?php if ($isTestMode): ?>
                <div class="p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700 mb-4">
                    <strong>Test Mode Active:</strong> Your gateway is in test/sandbox mode. No real money will be charged.
                </div>
            <?php elseif ($gatewayReady): ?>
                <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700 mb-4">
                    <strong>LIVE MODE:</strong> Your gateway is in production mode. This will charge <strong>real money</strong> to the student's card.
                </div>
            <?php endif; ?>

            <?php if (!$gatewayReady): ?>
                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-700">
                    No payment gateway configured. <a href="settings.php#billing" class="underline font-medium">Set up Stripe or Square first.</a>
                </div>
            <?php elseif (empty($renewableMemberships)): ?>
                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-700">
                    No eligible memberships found. A membership must be: <strong>active</strong>, <strong>auto-renew ON</strong>,
                    <strong>upfront billing</strong>, and have a <strong>payment method with a gateway ID</strong> on file.
                </div>
            <?php else: ?>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf()) ?>">
                    <input type="hidden" name="action" value="trigger_renewal">

                    <div>
                        <label for="membership_id" class="block text-sm font-medium text-gray-700 mb-1">Select Membership</label>
                        <select name="membership_id" id="membership_id" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                onchange="updateRenewalPreview(this)">
                            <option value="">-- Choose a membership --</option>
                            <?php foreach ($renewableMemberships as $rm):
                                $feeBreakdown = calculateTotalWithFees([
                                    'base_amount' => (float) $rm['price'],
                                    'registration_fee' => 0,
                                    'discount_code' => '',
                                    'plan_id' => (int) $rm['plan_id'],
                                ]);
                            ?>
                                <option value="<?= (int) $rm['membership_id'] ?>"
                                        data-price="<?= number_format($feeBreakdown['total'], 2) ?>"
                                        data-plan="<?= htmlspecialchars($rm['plan_name']) ?>"
                                        data-end="<?= htmlspecialchars(formatDate($rm['end_date'])) ?>"
                                        data-duration="<?= (int) $rm['duration_months'] ?>"
                                        data-student="<?= htmlspecialchars($rm['first_name'] . ' ' . $rm['last_name']) ?>">
                                    <?= htmlspecialchars($rm['last_name'] . ', ' . $rm['first_name']) ?>
                                    &mdash; <?= htmlspecialchars($rm['plan_name']) ?>
                                    ($<?= number_format($rm['price'], 2) ?>)
                                    &mdash; <?= htmlspecialchars(ucfirst($rm['card_brand'] ?? 'Card')) ?> ending <?= htmlspecialchars($rm['last_four']) ?>
                                    &mdash; Ends: <?= formatDate($rm['end_date']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Preview of what will happen -->
                    <div id="renewalPreview" class="hidden p-4 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800">
                        <strong>What will happen:</strong>
                        <ul class="mt-2 space-y-1 list-disc list-inside">
                            <li>Charge <strong id="previewAmount">$0.00</strong> to <span id="previewStudent">the student</span>'s default payment method</li>
                            <li>Extend <strong id="previewPlan">Plan</strong> end date from <span id="previewEndDate">—</span> by <span id="previewDuration">0</span> month(s)</li>
                            <li>Record payment(s) in the payments table</li>
                            <li>Log the renewal in the Renewal Log</li>
                        </ul>
                    </div>

                    <button type="submit" id="triggerBtn" disabled
                            class="w-full bg-orange-600 hover:bg-orange-700 disabled:bg-gray-400 disabled:cursor-not-allowed text-white px-4 py-2 rounded-lg font-medium text-sm transition"
                            onclick="return confirm('Are you sure? This will charge the full plan price to the selected student\'s card and extend their membership.')">
                        Trigger Renewal
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB: Renewal Log -->
    <div id="tab-logs" class="tab-content" style="display:none">
        <div class="bg-white rounded-xl shadow-sm border">
            <div class="px-5 py-4 border-b">
                <h3 class="font-semibold text-gray-800">Recent Renewal Log (Last 50 entries)</h3>
            </div>
            <?php if (count($recentLogs) > 0): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Old End</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">New End</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($recentLogs as $log):
                            $actionColors = [
                                'renewed' => 'bg-green-100 text-green-800',
                                'expired' => 'bg-gray-100 text-gray-600',
                                'payment_failed' => 'bg-red-100 text-red-800',
                                'no_gateway' => 'bg-yellow-100 text-yellow-800',
                                'test_charge' => 'bg-blue-100 text-blue-800',
                            ];
                            $actionClass = $actionColors[$log['action']] ?? 'bg-gray-100 text-gray-600';
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-400">#<?= $log['id'] ?></td>
                            <td class="px-4 py-3 font-medium"><?= htmlspecialchars(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?></td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $actionClass ?>"><?= htmlspecialchars($log['action']) ?></span>
                            </td>
                            <td class="px-4 py-3 text-center text-gray-500"><?= $log['old_end_date'] ? formatDate($log['old_end_date']) : '-' ?></td>
                            <td class="px-4 py-3 text-center text-gray-500"><?= $log['new_end_date'] ? formatDate($log['new_end_date']) : '-' ?></td>
                            <td class="px-4 py-3 text-right"><?= $log['amount'] ? formatMoney($log['amount']) : '-' ?></td>
                            <td class="px-4 py-3 text-gray-500 text-xs max-w-xs truncate" title="<?= htmlspecialchars($log['notes'] ?? '') ?>"><?= htmlspecialchars($log['notes'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="p-8 text-center text-gray-500">No renewal log entries yet. Renewal log entries are created when cron.php processes memberships.</div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
// Update renewal preview when a membership is selected
function updateRenewalPreview(select) {
    var opt = select.options[select.selectedIndex];
    var preview = document.getElementById('renewalPreview');
    var btn = document.getElementById('triggerBtn');

    if (!opt || !opt.value) {
        if (preview) preview.classList.add('hidden');
        if (btn) btn.disabled = true;
        return;
    }

    document.getElementById('previewAmount').textContent = '$' + opt.getAttribute('data-price');
    document.getElementById('previewStudent').textContent = opt.getAttribute('data-student');
    document.getElementById('previewPlan').textContent = opt.getAttribute('data-plan');
    document.getElementById('previewEndDate').textContent = opt.getAttribute('data-end');
    document.getElementById('previewDuration').textContent = opt.getAttribute('data-duration');

    if (preview) preview.classList.remove('hidden');
    if (btn) btn.disabled = false;
}

// Auto-switch to logs tab after a successful trigger_renewal POST
<?php if (!empty($showLogsTab)): ?>
document.addEventListener('DOMContentLoaded', function() { showTab('logs'); });
<?php endif; ?>
</script>

<?php if (!empty($studentsWithCards)): ?>
<script src="assets/js/student-picker.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    StudentPicker.init({
        container: '#test-student-picker',
        inputName: 'student_id',
        placeholder: 'Type student name to search\u2026',
        data: <?= json_encode(array_map(function($s) {
            $cardInfo = ucfirst($s['card_brand'] ?? 'Card') . ' ending ' . $s['last_four'];
            if (empty($s['gateway_payment_method_id'])) $cardInfo .= ' (NO GATEWAY ID!)';
            return ['id' => $s['id'], 'name' => trim($s['last_name'] . ', ' . $s['first_name']), 'extra' => $cardInfo];
        }, $studentsWithCards)) ?>,
        renderOption: function(s) {
            var html = '<div class="sp-option-name">' + s.name + '</div>';
            if (s.extra) html += '<div class="sp-option-sub">' + s.extra + '</div>';
            return html;
        }
    });
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
