<?php
/**
 * pending_payments.php — Admin Pending Payments Report & Processing
 *
 * Lists all event registrations AND memberships with a pending/declined/partial
 * payment status.  Admins can:
 *   • View the full pending-payment report (tabbed: Events / Memberships)
 *   • Select individual or all items
 *   • Batch-process selected payments against the student's (or parent's)
 *     card on file, OR mark them as paid (cash / manual), OR waive them.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';
require_once __DIR__ . '/includes/parent_auth.php';
requireLogin();
requireFinancialAccess();

$message = '';

$gw      = get_active_gateway();
$gwReady = is_gateway_ready();

// ---------------------------------------------------------------------------
// Auto-correct memberships marked "paid" that never actually had a payment
// processed.  This catches admin mistakes (e.g. $0 amount_paid with plan
// price > 0) and memberships from before the auto-pending guard was added.
// ---------------------------------------------------------------------------
$fixParams = [];
school_param($fixParams);
$fixSql = "
    UPDATE memberships m
    JOIN   membership_plans mp ON mp.id = m.plan_id
    SET    m.payment_status = 'pending'
    WHERE  m.status = 'active'
      AND  m.payment_status = 'paid'
      AND  (m.amount_paid IS NULL OR m.amount_paid <= 0)
      AND  mp.price > 0
      AND  NOT EXISTS (
               SELECT 1 FROM payments p
               WHERE  p.payment_type = 'membership'
                 AND  p.reference_id  = m.id
                 AND  p.amount > 0
           )
      AND  NOT EXISTS (
               SELECT 1 FROM credit_ledger cl
               WHERE  cl.reference_type = 'downgrade'
                 AND  cl.reference_id   = m.id
           )
      AND  NOT EXISTS (
               SELECT 1 FROM discount_code_uses dcu
               WHERE  dcu.context      = 'membership'
                 AND  dcu.reference_id  = m.id
                 AND  dcu.applied_amount >= (mp.price + IFNULL(mp.registration_fee, 0))
           )
" . school_where('m');
$fixStmt = $pdo->prepare($fixSql);
$fixStmt->execute($fixParams);
$fixedCount = $fixStmt->rowCount();
if ($fixedCount > 0) {
    $message = showAlert("Auto-corrected {$fixedCount} membership(s) from &ldquo;paid&rdquo; to &ldquo;pending&rdquo; &mdash; no payment was ever recorded for them.", 'info');
}

// ---------------------------------------------------------------------------
// Handle POST actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();

    $action = $_POST['action'];

    // ======================================================================
    // EVENT REGISTRATION ACTIONS (existing)
    // ======================================================================
    if (in_array($action, ['charge_card', 'mark_paid', 'waive'])) {
        $selectedIds = $_POST['registration_ids'] ?? [];
        $selectedIds = array_map('intval', $selectedIds);
        $selectedIds = array_filter($selectedIds);

        if (empty($selectedIds)) {
            $message = showAlert('No registrations selected.', 'error');
        } else {
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));

            switch ($action) {

                // ------------------------------------------------------------------
                // CHARGE CARD ON FILE — Process via Stripe/Square
                // ------------------------------------------------------------------
                case 'charge_card':
                    if (!$gwReady) {
                        $message = showAlert('Payment gateway is not configured. Cannot process card charges.', 'error');
                        break;
                    }

                    $regQuery = "
                        SELECT er.id, er.student_id, er.event_id, er.parent_id,
                               e.name AS event_name, e.registration_fee,
                               s.first_name, s.last_name
                        FROM event_registrations er
                        JOIN events e ON er.event_id = e.id
                        JOIN students s ON s.id = er.student_id
                        WHERE er.id IN ({$placeholders}) AND er.payment_status = 'pending'"
                        . school_where('s');
                    $regParams = $selectedIds;
                    school_param($regParams);
                    $regStmt = $pdo->prepare($regQuery);
                    $regStmt->execute($regParams);
                    $regs = $regStmt->fetchAll();

                    $successCount = 0;
                    $failCount    = 0;
                    $failMessages = [];

                    foreach ($regs as $reg) {
                        $baseFee = (float)$reg['registration_fee'];
                        if ($baseFee <= 0) {
                            $wParams = [$reg['id']];
                            school_param($wParams);
                            $pdo->prepare("UPDATE event_registrations SET payment_status = 'waived', amount_paid = 0 WHERE id = ?" . school_where())->execute($wParams);
                            $successCount++;
                            continue;
                        }

                        $regFeeBreakdown = calculateTotalWithFees([
                            'base_amount'      => $baseFee,
                            'registration_fee' => 0,
                            'discount_code'    => '',
                            'event_id'         => $reg['event_id'],
                        ]);
                        $totalToCharge = $regFeeBreakdown['total'];
                        $serviceFee    = $regFeeBreakdown['service_fee'];

                        $chargeStudentId = $reg['parent_id'] ?: $reg['student_id'];

                        $pmCheck = $pdo->prepare("SELECT id FROM payment_methods WHERE student_id = ? AND is_default = 1 LIMIT 1");
                        $pmCheck->execute([$chargeStudentId]);
                        if (!$pmCheck->fetch()) {
                            $failCount++;
                            $failMessages[] = $reg['first_name'] . ' ' . $reg['last_name'] . ' — no card on file' . ($reg['parent_id'] ? ' (parent)' : '');
                            continue;
                        }

                        $desc = 'Event: ' . $reg['event_name'] . ' — ' . $reg['first_name'] . ' ' . $reg['last_name'] . ' (admin processed)';
                        if ($serviceFee > 0) {
                            $desc .= ' (incl. $' . number_format($serviceFee, 2) . ' processing fee)';
                        }
                        $result = charge_student($chargeStudentId, $totalToCharge, $desc);

                        if ($result['success']) {
                            $upParams = [$totalToCharge, $reg['id']];
                            school_param($upParams);
                            $pdo->prepare("UPDATE event_registrations SET payment_status = 'paid', amount_paid = ? WHERE id = ?" . school_where())->execute($upParams);

                            $payNotes = $desc . ' | Txn: ' . ($result['transaction_id'] ?? 'N/A');
                            if ($serviceFee > 0) {
                                $payNotes .= ' | Service fee: $' . number_format($serviceFee, 2);
                            }
                            $pdo->prepare("
                                INSERT INTO payments (student_id, payment_type, reference_id, amount,
                                                    payment_method, payment_date, receipt_number, notes, school_id)
                                VALUES (?, 'event', ?, ?, 'credit_card', CURDATE(), ?, ?, ?)
                            ")->execute([
                                $reg['student_id'],
                                $reg['event_id'],
                                $result['amount_charged'] ?? $totalToCharge,
                                generateReceiptNumber(),
                                $payNotes,
                                current_school_id()
                            ]);

                            if (($result['credit_used'] ?? 0) > 0) {
                                $pdo->prepare("
                                    INSERT INTO payments (student_id, payment_type, reference_id, amount,
                                                        payment_method, payment_date, receipt_number, notes, school_id)
                                    VALUES (?, 'event', ?, ?, 'account_credit', CURDATE(), ?, ?, ?)
                                ")->execute([
                                    $chargeStudentId,
                                    $reg['event_id'],
                                    $result['credit_used'],
                                    generateReceiptNumber(),
                                    'Account credit applied (admin processed) — ' . $reg['event_name'],
                                    current_school_id()
                                ]);
                            }

                            send_payment_receipt_email([
                                'student_id'     => (int) $reg['student_id'],
                                'parent_id'      => $reg['parent_id'] ?? null,
                                'amount'         => $totalToCharge,
                                'payment_type'   => 'event',
                                'description'    => $desc,
                                'receipt_number' => '',
                                'transaction_id' => $result['transaction_id'] ?? null,
                                'payment_method' => 'credit_card',
                            ]);

                            $successCount++;
                        } else {
                            $failCount++;
                            $failMessages[] = $reg['first_name'] . ' ' . $reg['last_name'] . ' — ' . ($result['error'] ?? 'Unknown error');
                        }
                    }

                    $msgs = [];
                    if ($successCount > 0) $msgs[] = "Successfully charged {$successCount} registration(s).";
                    if ($failCount > 0)    $msgs[] = "Failed to charge {$failCount} registration(s).";
                    $alertType = $failCount === 0 ? 'success' : ($successCount > 0 ? 'warning' : 'error');
                    $message = showAlert(implode(' ', $msgs), $alertType);
                    if (!empty($failMessages)) {
                        $message .= '<div class="bg-red-50 border border-red-200 rounded-lg p-4 mt-3"><ul class="list-disc list-inside text-sm text-red-700">';
                        foreach ($failMessages as $fm) {
                            $message .= '<li>' . htmlspecialchars($fm) . '</li>';
                        }
                        $message .= '</ul></div>';
                    }
                    break;

                // ------------------------------------------------------------------
                // MARK AS PAID (manual / cash / in-person)
                // ------------------------------------------------------------------
                case 'mark_paid':
                    $method = sanitizeInput($_POST['manual_method'] ?? 'cash');
                    $notes  = sanitizeInput($_POST['manual_notes'] ?? '');

                    $regQuery2 = "
                        SELECT er.id, er.student_id, er.event_id,
                               e.name AS event_name, e.registration_fee,
                               s.first_name, s.last_name
                        FROM event_registrations er
                        JOIN events e ON er.event_id = e.id
                        JOIN students s ON s.id = er.student_id
                        WHERE er.id IN ({$placeholders}) AND er.payment_status = 'pending'"
                        . school_where('s');
                    $regParams2 = $selectedIds;
                    school_param($regParams2);
                    $regStmt = $pdo->prepare($regQuery2);
                    $regStmt->execute($regParams2);
                    $regs = $regStmt->fetchAll();

                    $count = 0;
                    foreach ($regs as $reg) {
                        $fee = (float)$reg['registration_fee'];
                        $upParams2 = [$fee, $reg['id']];
                        school_param($upParams2);
                        $pdo->prepare("UPDATE event_registrations SET payment_status = 'paid', amount_paid = ? WHERE id = ?" . school_where())->execute($upParams2);

                        $pdo->prepare("
                            INSERT INTO payments (student_id, payment_type, reference_id, amount,
                                                payment_method, payment_date, receipt_number, notes, school_id)
                            VALUES (?, 'event', ?, ?, ?, CURDATE(), ?, ?, ?)
                        ")->execute([
                            $reg['student_id'],
                            $reg['event_id'],
                            $fee,
                            $method,
                            generateReceiptNumber(),
                            'Event: ' . $reg['event_name'] . ' — ' . $reg['first_name'] . ' ' . $reg['last_name'] . ($notes ? " | {$notes}" : '') . ' (admin marked paid)',
                            current_school_id()
                        ]);

                        send_payment_receipt_email([
                            'student_id'     => (int) $reg['student_id'],
                            'amount'         => $fee,
                            'payment_type'   => 'event',
                            'description'    => 'Event: ' . $reg['event_name'] . ' — ' . $reg['first_name'] . ' ' . $reg['last_name'] . ' (marked paid by admin)',
                            'receipt_number' => '',
                            'transaction_id' => null,
                            'payment_method' => $method,
                        ]);

                        $count++;
                    }
                    $message = showAlert("Marked {$count} registration(s) as paid ({$method}).", 'success');
                    break;

                // ------------------------------------------------------------------
                // WAIVE PAYMENT
                // ------------------------------------------------------------------
                case 'waive':
                    $pdo->prepare("UPDATE event_registrations SET payment_status = 'waived', amount_paid = 0 WHERE id IN ({$placeholders}) AND payment_status = 'pending'")->execute($selectedIds);
                    $message = showAlert('Selected registrations have been waived.', 'success');
                    break;
            }
        }
    }

    // ======================================================================
    // MEMBERSHIP PAYMENT ACTIONS (new)
    // ======================================================================
    if (in_array($action, ['charge_membership', 'mark_membership_paid', 'waive_membership'])) {
        $selectedIds = $_POST['membership_ids'] ?? [];
        $selectedIds = array_map('intval', $selectedIds);
        $selectedIds = array_filter($selectedIds);

        if (empty($selectedIds)) {
            $message = showAlert('No memberships selected.', 'error');
        } else {
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));

            switch ($action) {

                // ------------------------------------------------------------------
                // CHARGE MEMBERSHIP CARD ON FILE
                // ------------------------------------------------------------------
                case 'charge_membership':
                    if (!$gwReady) {
                        $message = showAlert('Payment gateway is not configured. Cannot process card charges.', 'error');
                        break;
                    }

                    $memQuery = "
                        SELECT m.id, m.student_id, m.plan_id, m.payment_status,
                               m.monthly_charges_made,
                               mp.name AS plan_name, mp.price AS plan_price,
                               mp.billing_frequency, mp.duration_months,
                               s.first_name, s.last_name
                        FROM memberships m
                        JOIN membership_plans mp ON mp.id = m.plan_id
                        JOIN students s ON s.id = m.student_id
                        WHERE m.id IN ({$placeholders})
                          AND m.status = 'active'
                          AND m.payment_status IN ('pending', 'declined', 'partial')"
                        . school_where('s');
                    $memParams = $selectedIds;
                    school_param($memParams);
                    $memStmt = $pdo->prepare($memQuery);
                    $memStmt->execute($memParams);
                    $mems = $memStmt->fetchAll();

                    $successCount = 0;
                    $failCount    = 0;
                    $failMessages = [];

                    foreach ($mems as $mem) {
                        $fullPrice = (float)$mem['plan_price'];
                        // For monthly billing, charge the installment amount, not the full price
                        if ($mem['billing_frequency'] === 'monthly' && (int)$mem['duration_months'] > 1) {
                            $chargeAmount = round($fullPrice / (int)$mem['duration_months'], 2);
                        } else {
                            $chargeAmount = $fullPrice;
                        }

                        if ($chargeAmount <= 0) {
                            // Free plan — just mark as paid
                            $pdo->prepare("UPDATE memberships SET payment_status = 'paid', amount_paid = 0 WHERE id = ? AND school_id = ?")
                                ->execute([$mem['id'], current_school_id()]);
                            $successCount++;
                            continue;
                        }

                        // Calculate total with service fee
                        $serviceFeeRate = getServiceFeePercentage();
                        $serviceFee = ($serviceFeeRate > 0) ? round($chargeAmount * ($serviceFeeRate / 100), 2) : 0;
                        $totalToCharge = round($chargeAmount + $serviceFee, 2);

                        // Find who to charge: student → parent-as-student → legacy parent
                        $chargeId = _findChargeableId($pdo, (int)$mem['student_id']);

                        if (!$chargeId) {
                            $failCount++;
                            $failMessages[] = $mem['first_name'] . ' ' . $mem['last_name'] . ' — no card on file';
                            continue;
                        }

                        $desc = 'Membership: ' . $mem['plan_name'] . ' — ' . $mem['first_name'] . ' ' . $mem['last_name'] . ' (admin processed)';
                        if ($serviceFee > 0) {
                            $desc .= ' (incl. $' . number_format($serviceFee, 2) . ' processing fee)';
                        }
                        $result = charge_student($chargeId, $totalToCharge, $desc);

                        // If the student's charge failed, try the parent's card
                        if (!$result['success'] && $chargeId === (int)$mem['student_id']) {
                            $parentChargeId = _findParentChargeableId($pdo, (int)$mem['student_id']);
                            if ($parentChargeId) {
                                $result = charge_student($parentChargeId, $totalToCharge, $desc);
                                if ($result['success']) {
                                    $chargeId = $parentChargeId; // update for payment record
                                }
                            }
                        }

                        if ($result['success']) {
                            // For monthly billing, increment monthly_charges_made
                            if ($mem['billing_frequency'] === 'monthly') {
                                $newChargesMade = ((int)$mem['monthly_charges_made']) + 1;
                                $pdo->prepare("UPDATE memberships SET payment_status = 'paid', amount_paid = amount_paid + ?, monthly_charges_made = ? WHERE id = ? AND school_id = ?")
                                    ->execute([$chargeAmount, $newChargesMade, $mem['id'], current_school_id()]);
                            } else {
                                $pdo->prepare("UPDATE memberships SET payment_status = 'paid', amount_paid = ? WHERE id = ? AND school_id = ?")
                                    ->execute([$chargeAmount, $mem['id'], current_school_id()]);
                            }

                            // Also clear lockout override if it was set
                            $pdo->prepare("UPDATE students SET payment_lockout_override = 0 WHERE id = ? AND school_id = ?")
                                ->execute([$mem['student_id'], current_school_id()]);

                            $payNotes = $desc . ' | Txn: ' . ($result['transaction_id'] ?? 'N/A');
                            if ($serviceFee > 0) {
                                $payNotes .= ' | Service fee: $' . number_format($serviceFee, 2);
                            }
                            $pdo->prepare("
                                INSERT INTO payments (student_id, payment_type, reference_id, amount,
                                                    payment_method, payment_date, receipt_number, notes, school_id)
                                VALUES (?, 'membership', ?, ?, 'credit_card', CURDATE(), ?, ?, ?)
                            ")->execute([
                                $mem['student_id'],
                                $mem['id'],
                                $result['amount_charged'] ?? $totalToCharge,
                                generateReceiptNumber(),
                                $payNotes,
                                current_school_id()
                            ]);

                            if (($result['credit_used'] ?? 0) > 0) {
                                $pdo->prepare("
                                    INSERT INTO payments (student_id, payment_type, reference_id, amount,
                                                        payment_method, payment_date, receipt_number, notes, school_id)
                                    VALUES (?, 'membership', ?, ?, 'account_credit', CURDATE(), ?, ?, ?)
                                ")->execute([
                                    $chargeId,
                                    $mem['id'],
                                    $result['credit_used'],
                                    generateReceiptNumber(),
                                    'Account credit applied (admin processed) — ' . $mem['plan_name'],
                                    current_school_id()
                                ]);
                            }

                            try {
                                send_payment_receipt_email([
                                    'student_id'     => (int) $mem['student_id'],
                                    'amount'         => $totalToCharge,
                                    'payment_type'   => 'membership',
                                    'description'    => $desc,
                                    'receipt_number' => '',
                                    'transaction_id' => $result['transaction_id'] ?? null,
                                    'payment_method' => 'credit_card',
                                ]);
                            } catch (\Throwable $e) {}

                            $successCount++;
                        } else {
                            $failCount++;
                            $failMessages[] = $mem['first_name'] . ' ' . $mem['last_name'] . ' — ' . ($result['error'] ?? 'Unknown error');
                        }
                    }

                    $msgs = [];
                    if ($successCount > 0) $msgs[] = "Successfully charged {$successCount} membership(s).";
                    if ($failCount > 0)    $msgs[] = "Failed to charge {$failCount} membership(s).";
                    $alertType = $failCount === 0 ? 'success' : ($successCount > 0 ? 'warning' : 'error');
                    $message = showAlert(implode(' ', $msgs), $alertType);
                    if (!empty($failMessages)) {
                        $message .= '<div class="bg-red-50 border border-red-200 rounded-lg p-4 mt-3"><ul class="list-disc list-inside text-sm text-red-700">';
                        foreach ($failMessages as $fm) {
                            $message .= '<li>' . htmlspecialchars($fm) . '</li>';
                        }
                        $message .= '</ul></div>';
                    }
                    break;

                // ------------------------------------------------------------------
                // MARK MEMBERSHIP AS PAID (manual / cash / in-person)
                // ------------------------------------------------------------------
                case 'mark_membership_paid':
                    $method = sanitizeInput($_POST['manual_method'] ?? 'cash');
                    $notes  = sanitizeInput($_POST['manual_notes'] ?? '');

                    $memQuery2 = "
                        SELECT m.id, m.student_id, m.plan_id, m.monthly_charges_made,
                               mp.name AS plan_name, mp.price AS plan_price,
                               mp.billing_frequency, mp.duration_months,
                               s.first_name, s.last_name
                        FROM memberships m
                        JOIN membership_plans mp ON mp.id = m.plan_id
                        JOIN students s ON s.id = m.student_id
                        WHERE m.id IN ({$placeholders})
                          AND m.status = 'active'
                          AND m.payment_status IN ('pending', 'declined', 'partial')"
                        . school_where('s');
                    $memParams2 = $selectedIds;
                    school_param($memParams2);
                    $memStmt2 = $pdo->prepare($memQuery2);
                    $memStmt2->execute($memParams2);
                    $mems = $memStmt2->fetchAll();

                    $count = 0;
                    foreach ($mems as $mem) {
                        $fullPrice = (float)$mem['plan_price'];
                        if ($mem['billing_frequency'] === 'monthly' && (int)$mem['duration_months'] > 1) {
                            $price = round($fullPrice / (int)$mem['duration_months'], 2);
                        } else {
                            $price = $fullPrice;
                        }

                        if ($mem['billing_frequency'] === 'monthly') {
                            $newChargesMade = ((int)$mem['monthly_charges_made']) + 1;
                            $pdo->prepare("UPDATE memberships SET payment_status = 'paid', amount_paid = amount_paid + ?, monthly_charges_made = ? WHERE id = ? AND school_id = ?")
                                ->execute([$price, $newChargesMade, $mem['id'], current_school_id()]);
                        } else {
                            $pdo->prepare("UPDATE memberships SET payment_status = 'paid', amount_paid = ? WHERE id = ? AND school_id = ?")
                                ->execute([$price, $mem['id'], current_school_id()]);
                        }

                        $pdo->prepare("
                            INSERT INTO payments (student_id, payment_type, reference_id, amount,
                                                payment_method, payment_date, receipt_number, notes, school_id)
                            VALUES (?, 'membership', ?, ?, ?, CURDATE(), ?, ?, ?)
                        ")->execute([
                            $mem['student_id'],
                            $mem['id'],
                            $price,
                            $method,
                            generateReceiptNumber(),
                            'Membership: ' . $mem['plan_name'] . ' — ' . $mem['first_name'] . ' ' . $mem['last_name'] . ($notes ? " | {$notes}" : '') . ' (admin marked paid)',
                            current_school_id()
                        ]);

                        try {
                            send_payment_receipt_email([
                                'student_id'     => (int) $mem['student_id'],
                                'amount'         => $price,
                                'payment_type'   => 'membership',
                                'description'    => 'Membership: ' . $mem['plan_name'] . ' — ' . $mem['first_name'] . ' ' . $mem['last_name'] . ' (marked paid by admin)',
                                'receipt_number' => '',
                                'transaction_id' => null,
                                'payment_method' => $method,
                            ]);
                        } catch (\Throwable $e) {}

                        $count++;
                    }
                    $message = showAlert("Marked {$count} membership(s) as paid ({$method}).", 'success');
                    break;

                // ------------------------------------------------------------------
                // WAIVE MEMBERSHIP PAYMENT
                // ------------------------------------------------------------------
                case 'waive_membership':
                    $memParams3 = $selectedIds;
                    $memParams3[] = current_school_id();
                    $pdo->prepare("UPDATE memberships SET payment_status = 'waived', amount_paid = 0, notes = CONCAT(IFNULL(notes,''), ' | Payment waived by admin on " . date('Y-m-d') . "') WHERE id IN ({$placeholders}) AND school_id = ?")->execute($memParams3);
                    $message = showAlert('Selected membership payments have been waived.', 'success');
                    break;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Fetch all pending event registrations
// ---------------------------------------------------------------------------
$filterEvent  = (int)($_GET['event_id'] ?? 0);
$filterSearch = trim($_GET['search'] ?? '');

$query = "
    SELECT er.id, er.student_id, er.event_id, er.parent_id, er.registration_date, er.payment_status,
           e.name AS event_name, e.event_date, e.event_type, e.registration_fee,
           s.first_name, s.last_name, s.email,
           ps.first_name AS parent_first, ps.last_name AS parent_last
    FROM event_registrations er
    JOIN events e ON er.event_id = e.id
    JOIN students s ON s.id = er.student_id
    LEFT JOIN students ps ON ps.id = er.parent_id AND ps.is_parent = 1
    WHERE er.payment_status = 'pending'
";
$params = [];

if ($filterEvent) {
    $query .= " AND er.event_id = ?";
    $params[] = $filterEvent;
}
if ($filterSearch !== '') {
    $query .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR e.name LIKE ?)";
    $searchWild = "%{$filterSearch}%";
    $params[] = $searchWild;
    $params[] = $searchWild;
    $params[] = $searchWild;
}

$query .= " ORDER BY e.event_date ASC, s.last_name ASC, s.first_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$pending = $stmt->fetchAll();

// Summary stats (events)
$totalPending    = count($pending);
$totalOwedBase   = array_sum(array_column($pending, 'registration_fee'));
$uniqueEvents    = count(array_unique(array_column($pending, 'event_id')));
$uniqueStudents  = count(array_unique(array_column($pending, 'student_id')));

$serviceFeeRate  = getServiceFeePercentage();
$totalServiceFee = ($serviceFeeRate > 0) ? round($totalOwedBase * ($serviceFeeRate / 100), 2) : 0;
$totalOwedWithFees = round($totalOwedBase + $totalServiceFee, 2);

// Events list for filter dropdown
$events = $pdo->query("
    SELECT DISTINCT e.id, e.name, e.event_date
    FROM events e
    JOIN event_registrations er ON er.event_id = e.id
    WHERE er.payment_status = 'pending'
    ORDER BY e.event_date ASC
")->fetchAll();

// Check which students / parents have a card on file (events)
$cardOnFile = [];
foreach ($pending as $reg) {
    $ownerId = $reg['parent_id'] ?: $reg['student_id'];
    if (!isset($cardOnFile[$ownerId])) {
        $pmStmt = $pdo->prepare("SELECT id FROM payment_methods WHERE student_id = ? AND is_default = 1 AND gateway_payment_method_id IS NOT NULL AND gateway_payment_method_id != '' LIMIT 1");
        $pmStmt->execute([$ownerId]);
        $cardOnFile[$ownerId] = (bool)$pmStmt->fetch();
    }
}

// ---------------------------------------------------------------------------
// Fetch all pending membership payments
// ---------------------------------------------------------------------------
$memSearchFilter = trim($_GET['mem_search'] ?? '');

$memQuery = "
    SELECT m.id, m.student_id, m.plan_id, m.start_date, m.end_date,
           m.payment_status, m.amount_paid, m.created_at, m.monthly_charges_made,
           mp.name AS plan_name, mp.price AS plan_price,
           mp.billing_frequency, mp.duration_months,
           s.first_name, s.last_name, s.email
    FROM memberships m
    JOIN membership_plans mp ON mp.id = m.plan_id
    JOIN students s ON s.id = m.student_id
    WHERE m.status = 'active'
      AND m.payment_status IN ('pending', 'declined', 'partial')"
    . school_where('s');
$memParams = [];
school_param($memParams);

if ($memSearchFilter !== '') {
    $memQuery .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR mp.name LIKE ?)";
    $memWild = "%{$memSearchFilter}%";
    $memParams[] = $memWild;
    $memParams[] = $memWild;
    $memParams[] = $memWild;
}

$memQuery .= " ORDER BY m.created_at DESC, s.last_name ASC, s.first_name ASC";
$memStmt = $pdo->prepare($memQuery);
$memStmt->execute($memParams);
$pendingMemberships = $memStmt->fetchAll();

// Summary stats (memberships) — use installment amount for monthly plans
$totalPendingMem     = count($pendingMemberships);
$totalOwedMem        = 0;
foreach ($pendingMemberships as &$memRow) {
    $fullPrice = (float)$memRow['plan_price'];
    if ($memRow['billing_frequency'] === 'monthly' && (int)$memRow['duration_months'] > 1) {
        $memRow['_installment_amount'] = round($fullPrice / (int)$memRow['duration_months'], 2);
    } else {
        $memRow['_installment_amount'] = $fullPrice;
    }
    $totalOwedMem += $memRow['_installment_amount'];
}
unset($memRow);
$uniqueMemStudents   = count(array_unique(array_column($pendingMemberships, 'student_id')));
$memServiceFee       = ($serviceFeeRate > 0) ? round($totalOwedMem * ($serviceFeeRate / 100), 2) : 0;
$totalOwedMemWithFee = round($totalOwedMem + $memServiceFee, 2);

// Card on file for membership students — check student, then parent-as-student,
// then legacy parent_payment_methods.
// Returns: 'student' | 'parent:FirstName LastName' | false
$memCardOnFile = [];
foreach ($pendingMemberships as $mem) {
    $sid = $mem['student_id'];
    if (!isset($memCardOnFile[$sid])) {
        $memCardOnFile[$sid] = _whoHasCard($pdo, $sid);
    }
}

/**
 * Determine who has a chargeable card on file for this student.
 * Returns: 'student' if the student has their own card,
 *          'parent:Name' if a linked parent has a card,
 *          false if nobody has a card.
 */
function _whoHasCard(PDO $pdo, int $studentId)
{
    // 1. Student's own card
    $stmt = $pdo->prepare("SELECT id FROM payment_methods WHERE student_id = ? AND is_default = 1 AND gateway_payment_method_id IS NOT NULL AND gateway_payment_method_id != '' LIMIT 1");
    $stmt->execute([$studentId]);
    if ($stmt->fetch()) return 'student';

    // 2. Parent-as-student (via parent_students junction)
    $parents = get_student_parents($studentId);
    foreach ($parents as $parent) {
        $stmt2 = $pdo->prepare("SELECT id FROM payment_methods WHERE student_id = ? AND is_default = 1 AND gateway_payment_method_id IS NOT NULL AND gateway_payment_method_id != '' LIMIT 1");
        $stmt2->execute([$parent['id']]);
        if ($stmt2->fetch()) {
            return 'parent:' . trim($parent['first_name'] . ' ' . $parent['last_name']);
        }
    }

    // 3. Legacy parent model — check if any event registration links a legacy
    //    parent to this student, then check parent_payment_methods
    $legStmt = $pdo->prepare("SELECT DISTINCT er.parent_id, p.first_name, p.last_name FROM event_registrations er JOIN parents p ON p.id = er.parent_id WHERE er.student_id = ? AND er.parent_id IS NOT NULL LIMIT 1");
    $legStmt->execute([$studentId]);
    $legRow = $legStmt->fetch();
    if ($legRow) {
        $lpmStmt = $pdo->prepare("SELECT id FROM parent_payment_methods WHERE parent_id = ? AND is_default = 1 AND gateway_payment_method_id IS NOT NULL AND gateway_payment_method_id != '' LIMIT 1");
        $lpmStmt->execute([$legRow['parent_id']]);
        if ($lpmStmt->fetch()) {
            return 'parent:' . trim($legRow['first_name'] . ' ' . $legRow['last_name']);
        }
    }

    return false;
}

/**
 * Find the student_id (or parent's student_id) that can be charged.
 * Returns the ID to pass to charge_student(), or null if nobody has a card.
 */
function _findChargeableId(PDO $pdo, int $studentId): ?int
{
    // 1. Student's own card
    $stmt = $pdo->prepare("SELECT id FROM payment_methods WHERE student_id = ? AND is_default = 1 LIMIT 1");
    $stmt->execute([$studentId]);
    if ($stmt->fetch()) return $studentId;

    // 2. Parent-as-student card
    return _findParentChargeableId($pdo, $studentId);
}

/**
 * Find a parent's student_id that can be charged for this child.
 * Only checks parent cards, NOT the student's own card.
 */
function _findParentChargeableId(PDO $pdo, int $studentId): ?int
{
    $parents = get_student_parents($studentId);
    foreach ($parents as $parent) {
        $stmt = $pdo->prepare("SELECT id FROM payment_methods WHERE student_id = ? AND is_default = 1 LIMIT 1");
        $stmt->execute([$parent['id']]);
        if ($stmt->fetch()) return (int)$parent['id'];
    }
    return null;
}

// Determine active tab
$activeTab = $_GET['tab'] ?? '';
if ($activeTab === '') {
    // Auto-select: if POST just processed memberships, stay on memberships tab
    if (isset($_POST['action']) && in_array($_POST['action'] ?? '', ['charge_membership', 'mark_membership_paid', 'waive_membership'])) {
        $activeTab = 'memberships';
    } else if (isset($_POST['action']) && in_array($_POST['action'] ?? '', ['charge_card', 'mark_paid', 'waive'])) {
        $activeTab = 'events';
    } else {
        // Default: show whichever has pending items (memberships first if events is empty)
        $activeTab = ($totalPending > 0) ? 'events' : (($totalPendingMem > 0) ? 'memberships' : 'events');
    }
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Pending Payments</h1>
            <p class="text-gray-600 mt-1">Review and process outstanding payments</p>
        </div>
        <a href="payments.php" class="text-blue-600 hover:text-blue-800 text-sm">View All Payments &rarr;</a>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 mb-6">
        <nav class="-mb-px flex space-x-8">
            <button onclick="switchTab('events')" id="tab-events"
                    class="tab-btn whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm <?= $activeTab === 'events' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                Event Payments
                <?php if ($totalPending > 0): ?>
                    <span class="ml-2 bg-yellow-100 text-yellow-800 text-xs font-semibold px-2 py-0.5 rounded-full"><?= $totalPending ?></span>
                <?php endif; ?>
            </button>
            <button onclick="switchTab('memberships')" id="tab-memberships"
                    class="tab-btn whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm <?= $activeTab === 'memberships' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                Membership Payments
                <?php if ($totalPendingMem > 0): ?>
                    <span class="ml-2 bg-red-100 text-red-800 text-xs font-semibold px-2 py-0.5 rounded-full"><?= $totalPendingMem ?></span>
                <?php endif; ?>
            </button>
        </nav>
    </div>

    <!-- ================================================================== -->
    <!-- EVENT PAYMENTS TAB -->
    <!-- ================================================================== -->
    <div id="panel-events" class="tab-panel <?= $activeTab !== 'events' ? 'hidden' : '' ?>">

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <p class="text-sm text-yellow-700 font-medium">Pending Registrations</p>
                <p class="text-3xl font-bold text-yellow-800"><?= $totalPending ?></p>
            </div>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                <p class="text-sm text-red-700 font-medium">Total Outstanding</p>
                <p class="text-3xl font-bold text-red-800"><?= formatMoney($totalOwedBase) ?></p>
                <?php if ($totalServiceFee > 0): ?>
                    <p class="text-xs text-red-600 mt-1">
                        + <?= formatMoney($totalServiceFee) ?> processing fee (<?= number_format($serviceFeeRate, 2) ?>%)
                        = <strong><?= formatMoney($totalOwedWithFees) ?></strong> total
                    </p>
                <?php endif; ?>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p class="text-sm text-blue-700 font-medium">Events Affected</p>
                <p class="text-3xl font-bold text-blue-800"><?= $uniqueEvents ?></p>
            </div>
            <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                <p class="text-sm text-purple-700 font-medium">Students Owing</p>
                <p class="text-3xl font-bold text-purple-800"><?= $uniqueStudents ?></p>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <input type="hidden" name="tab" value="events">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search Student / Event</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($filterSearch) ?>" placeholder="Name or event..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div class="min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Event</label>
                    <select name="event_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">All Events</option>
                        <?php foreach ($events as $ev): ?>
                            <option value="<?= $ev['id'] ?>" <?= $filterEvent == $ev['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ev['name']) ?> (<?= formatDate($ev['event_date']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Filter</button>
                    <a href="pending_payments.php?tab=events" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">Reset</a>
                </div>
            </form>
        </div>

        <?php if (empty($pending)): ?>
            <div class="bg-white rounded-lg shadow p-12 text-center">
                <div class="text-6xl mb-4">&#10004;&#65039;</div>
                <h2 class="text-2xl font-bold text-gray-800 mb-2">No Pending Event Payments</h2>
                <p class="text-gray-600">All event registration payments are up to date!</p>
            </div>
        <?php else: ?>

        <!-- Action Bar -->
        <form method="POST" id="pendingForm">
            <?= csrf_field() ?>
            <div class="bg-white rounded-lg shadow mb-4">
                <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700 cursor-pointer">
                            <input type="checkbox" id="selectAll" class="w-4 h-4 text-blue-600 border-gray-300 rounded">
                            Select All
                        </label>
                        <span id="selectedCount" class="text-sm text-gray-500">0 selected</span>
                        <span id="selectedTotal" class="text-sm font-semibold text-gray-700"></span>
                    </div>

                    <div class="flex items-center gap-2">
                        <?php if ($gwReady): ?>
                            <button type="submit" name="action" value="charge_card"
                                    onclick="return confirmAction('Charge the selected registrations to the card on file? Processing fees will be included in the charge amount.')"
                                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
                                    id="chargeBtn" disabled
                                    title="Includes <?= number_format($serviceFeeRate, 2) ?>% processing fee">
                                &#128179; Charge Card on File
                            </button>
                        <?php endif; ?>
                        <button type="button" onclick="showMarkPaidModal('pendingForm', 'mark_paid')"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
                                id="markPaidBtn" disabled>
                            &#9989; Mark as Paid
                        </button>
                        <button type="submit" name="action" value="waive"
                                onclick="return confirmAction('Waive the fees for the selected registrations?')"
                                class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
                                id="waiveBtn" disabled>
                            Waive
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-10"></th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Event Date</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fee</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Card Charge</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Registered</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Parent</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Card on File</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($pending as $row):
                                $ownerId  = $row['parent_id'] ?: $row['student_id'];
                                $hasCard  = $cardOnFile[$ownerId] ?? false;
                                $fee      = (float)$row['registration_fee'];
                                $rowServiceFee = ($serviceFeeRate > 0) ? round($fee * ($serviceFeeRate / 100), 2) : 0;
                                $rowTotal = round($fee + $rowServiceFee, 2);
                            ?>
                                <tr class="hover:bg-gray-50 reg-row" data-fee="<?= $rowTotal ?>">
                                    <td class="px-4 py-4">
                                        <input type="checkbox" name="registration_ids[]" value="<?= $row['id'] ?>"
                                               class="row-checkbox w-4 h-4 text-blue-600 border-gray-300 rounded">
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <a href="student_detail.php?id=<?= $row['student_id'] ?>" class="text-blue-600 hover:underline font-medium">
                                            <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                                        </a>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($row['email'] ?: '') ?></div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <a href="event_detail.php?id=<?= $row['event_id'] ?>" class="text-blue-600 hover:underline">
                                            <?= htmlspecialchars($row['event_name']) ?>
                                        </a>
                                        <div class="text-xs text-gray-500 capitalize"><?= str_replace('_', ' ', $row['event_type']) ?></div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?= formatDate($row['event_date']) ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm font-semibold text-red-600">
                                        <?= formatMoney($fee) ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm">
                                        <span class="font-semibold text-gray-900"><?= formatMoney($rowTotal) ?></span>
                                        <?php if ($rowServiceFee > 0): ?>
                                            <div class="text-xs text-gray-500">incl. <?= formatMoney($rowServiceFee) ?> fee</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?= formatDate($row['registration_date']) ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?php if ($row['parent_first']): ?>
                                            <?= htmlspecialchars($row['parent_first'] . ' ' . $row['parent_last']) ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-center">
                                        <?php if ($hasCard): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">&#128179; Yes</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-700">&#10060; No</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Hidden fields for mark-paid modal -->
            <input type="hidden" name="manual_method" id="hidden_method" value="cash">
            <input type="hidden" name="manual_notes"  id="hidden_notes"  value="">
        </form>

        <?php endif; ?>
    </div>

    <!-- ================================================================== -->
    <!-- MEMBERSHIP PAYMENTS TAB -->
    <!-- ================================================================== -->
    <div id="panel-memberships" class="tab-panel <?= $activeTab !== 'memberships' ? 'hidden' : '' ?>">

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <p class="text-sm text-yellow-700 font-medium">Pending Memberships</p>
                <p class="text-3xl font-bold text-yellow-800"><?= $totalPendingMem ?></p>
            </div>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                <p class="text-sm text-red-700 font-medium">Total Outstanding</p>
                <p class="text-3xl font-bold text-red-800"><?= formatMoney($totalOwedMem) ?></p>
                <?php if ($memServiceFee > 0): ?>
                    <p class="text-xs text-red-600 mt-1">
                        + <?= formatMoney($memServiceFee) ?> processing fee (<?= number_format($serviceFeeRate, 2) ?>%)
                        = <strong><?= formatMoney($totalOwedMemWithFee) ?></strong> total
                    </p>
                <?php endif; ?>
            </div>
            <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                <p class="text-sm text-purple-700 font-medium">Students Owing</p>
                <p class="text-3xl font-bold text-purple-800"><?= $uniqueMemStudents ?></p>
            </div>
        </div>

        <!-- Search -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <input type="hidden" name="tab" value="memberships">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search Student / Plan</label>
                    <input type="text" name="mem_search" value="<?= htmlspecialchars($memSearchFilter) ?>" placeholder="Name or plan..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Filter</button>
                    <a href="pending_payments.php?tab=memberships" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">Reset</a>
                </div>
            </form>
        </div>

        <?php if (empty($pendingMemberships)): ?>
            <div class="bg-white rounded-lg shadow p-12 text-center">
                <div class="text-6xl mb-4">&#10004;&#65039;</div>
                <h2 class="text-2xl font-bold text-gray-800 mb-2">No Pending Membership Payments</h2>
                <p class="text-gray-600">All membership payments are up to date!</p>
            </div>
        <?php else: ?>

        <!-- Action Bar -->
        <form method="POST" id="membershipForm">
            <?= csrf_field() ?>
            <div class="bg-white rounded-lg shadow mb-4">
                <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700 cursor-pointer">
                            <input type="checkbox" id="selectAllMem" class="w-4 h-4 text-blue-600 border-gray-300 rounded">
                            Select All
                        </label>
                        <span id="memSelectedCount" class="text-sm text-gray-500">0 selected</span>
                        <span id="memSelectedTotal" class="text-sm font-semibold text-gray-700"></span>
                    </div>

                    <div class="flex items-center gap-2">
                        <?php if ($gwReady): ?>
                            <button type="submit" name="action" value="charge_membership"
                                    onclick="return confirmAction('Charge the selected memberships to the card on file? Processing fees will be included.')"
                                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
                                    id="memChargeBtn" disabled>
                                &#128179; Charge Card on File
                            </button>
                        <?php endif; ?>
                        <button type="button" onclick="showMarkPaidModal('membershipForm', 'mark_membership_paid')"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
                                id="memMarkPaidBtn" disabled>
                            &#9989; Mark as Paid
                        </button>
                        <button type="submit" name="action" value="waive_membership"
                                onclick="return confirmAction('Waive the fees for the selected memberships?')"
                                class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
                                id="memWaiveBtn" disabled>
                            Waive
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-10"></th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Billing</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount Due</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Card Charge</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Start Date</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Card on File</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($pendingMemberships as $mem):
                                $cardStatus = $memCardOnFile[$mem['student_id']] ?? false;
                                $hasCard    = ($cardStatus !== false);
                                $price    = (float)$mem['_installment_amount'];
                                $rowSvcFee = ($serviceFeeRate > 0) ? round($price * ($serviceFeeRate / 100), 2) : 0;
                                $rowTotal = round($price + $rowSvcFee, 2);

                                $statusColors = [
                                    'pending'  => 'bg-yellow-100 text-yellow-800',
                                    'declined' => 'bg-red-100 text-red-800',
                                    'partial'  => 'bg-orange-100 text-orange-800',
                                ];
                                $billingColors = [
                                    'monthly'   => 'bg-blue-100 text-blue-800',
                                    'quarterly' => 'bg-indigo-100 text-indigo-800',
                                    'upfront'   => 'bg-green-100 text-green-800',
                                ];
                            ?>
                                <tr class="hover:bg-gray-50 mem-row" data-fee="<?= $rowTotal ?>">
                                    <td class="px-4 py-4">
                                        <input type="checkbox" name="membership_ids[]" value="<?= $mem['id'] ?>"
                                               class="mem-checkbox w-4 h-4 text-blue-600 border-gray-300 rounded">
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <a href="student_detail.php?id=<?= $mem['student_id'] ?>" class="text-blue-600 hover:underline font-medium">
                                            <?= htmlspecialchars($mem['first_name'] . ' ' . $mem['last_name']) ?>
                                        </a>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($mem['email'] ?: '') ?></div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($mem['plan_name']) ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $billingColors[$mem['billing_frequency']] ?? 'bg-gray-100 text-gray-800' ?>">
                                            <?= ucfirst($mem['billing_frequency']) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm font-semibold text-red-600">
                                        <?= formatMoney($price) ?>
                                        <?php if ($mem['billing_frequency'] === 'monthly' && (int)$mem['duration_months'] > 1): ?>
                                            <div class="text-xs text-gray-500 font-normal"><?= formatMoney($mem['plan_price']) ?> / <?= $mem['duration_months'] ?> mo</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm">
                                        <span class="font-semibold text-gray-900"><?= formatMoney($rowTotal) ?></span>
                                        <?php if ($rowSvcFee > 0): ?>
                                            <div class="text-xs text-gray-500">incl. <?= formatMoney($rowSvcFee) ?> fee</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $statusColors[$mem['payment_status']] ?? 'bg-gray-100 text-gray-800' ?>">
                                            <?= ucfirst($mem['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?= formatDate($mem['start_date']) ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-center">
                                        <?php if ($hasCard): ?>
                                            <?php if (is_string($cardStatus) && strpos($cardStatus, 'parent:') === 0): ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800" title="<?= htmlspecialchars(substr($cardStatus, 7)) ?>">&#128179; Via Parent</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">&#128179; Yes</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-700">&#10060; No</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Hidden fields for mark-paid modal -->
            <input type="hidden" name="manual_method" id="mem_hidden_method" value="cash">
            <input type="hidden" name="manual_notes"  id="mem_hidden_notes"  value="">
        </form>

        <?php endif; ?>
    </div>
</div>

<!-- Mark as Paid Modal (shared between both tabs) -->
<div id="markPaidModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Mark as Paid</h3>
            <button onclick="document.getElementById('markPaidModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>

        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                <select id="modal_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="cash">Cash</option>
                    <option value="credit_card">Credit Card (Manual)</option>
                    <option value="debit_card">Debit Card</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="check">Check</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                <textarea id="modal_notes" rows="2" placeholder="e.g., Check #1234"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('markPaidModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="button" onclick="submitMarkPaid()"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Track which form/action the modal should submit to
var _markPaidFormId = 'pendingForm';
var _markPaidAction = 'mark_paid';

// ==========================================================================
// Tab switching
// ==========================================================================
function switchTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.add('hidden'); });
    document.querySelectorAll('.tab-btn').forEach(function(b) {
        b.classList.remove('border-blue-500', 'text-blue-600');
        b.classList.add('border-transparent', 'text-gray-500');
    });

    document.getElementById('panel-' + tab).classList.remove('hidden');
    var btn = document.getElementById('tab-' + tab);
    btn.classList.add('border-blue-500', 'text-blue-600');
    btn.classList.remove('border-transparent', 'text-gray-500');

    // Update URL without reload
    var url = new URL(window.location);
    url.searchParams.set('tab', tab);
    history.replaceState(null, '', url.toString());
}

// ==========================================================================
// Event Payments — checkbox logic
// ==========================================================================
(function() {
    var selectAll    = document.getElementById('selectAll');
    var checkboxes   = document.querySelectorAll('.row-checkbox');
    var countEl      = document.getElementById('selectedCount');
    var totalEl      = document.getElementById('selectedTotal');
    var chargeBtn    = document.getElementById('chargeBtn');
    var markPaidBtn  = document.getElementById('markPaidBtn');
    var waiveBtn     = document.getElementById('waiveBtn');

    function updateState() {
        var checked = document.querySelectorAll('.row-checkbox:checked');
        var count = checked.length;
        var total = 0;
        checked.forEach(function(cb) {
            var row = cb.closest('tr');
            total += parseFloat(row.getAttribute('data-fee') || 0);
        });

        if (countEl) countEl.textContent = count + ' selected';
        if (totalEl) totalEl.textContent = count > 0 ? '(' + '$' + total.toFixed(2) + ')' : '';

        if (chargeBtn)  chargeBtn.disabled  = count === 0;
        if (markPaidBtn) markPaidBtn.disabled = count === 0;
        if (waiveBtn)   waiveBtn.disabled   = count === 0;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
            updateState();
        });
    }

    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', function() {
            if (!this.checked && selectAll) selectAll.checked = false;
            updateState();
        });
    });

    updateState();
})();

// ==========================================================================
// Membership Payments — checkbox logic
// ==========================================================================
(function() {
    var selectAll    = document.getElementById('selectAllMem');
    var checkboxes   = document.querySelectorAll('.mem-checkbox');
    var countEl      = document.getElementById('memSelectedCount');
    var totalEl      = document.getElementById('memSelectedTotal');
    var chargeBtn    = document.getElementById('memChargeBtn');
    var markPaidBtn  = document.getElementById('memMarkPaidBtn');
    var waiveBtn     = document.getElementById('memWaiveBtn');

    function updateState() {
        var checked = document.querySelectorAll('.mem-checkbox:checked');
        var count = checked.length;
        var total = 0;
        checked.forEach(function(cb) {
            var row = cb.closest('tr');
            total += parseFloat(row.getAttribute('data-fee') || 0);
        });

        if (countEl) countEl.textContent = count + ' selected';
        if (totalEl) totalEl.textContent = count > 0 ? '(' + '$' + total.toFixed(2) + ')' : '';

        if (chargeBtn)  chargeBtn.disabled  = count === 0;
        if (markPaidBtn) markPaidBtn.disabled = count === 0;
        if (waiveBtn)   waiveBtn.disabled   = count === 0;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
            updateState();
        });
    }

    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', function() {
            if (!this.checked && selectAll) selectAll.checked = false;
            updateState();
        });
    });

    updateState();
})();

// ==========================================================================
// Shared helpers
// ==========================================================================
function confirmAction(msg) {
    return confirm(msg);
}

function showMarkPaidModal(formId, actionValue) {
    var form = document.getElementById(formId);
    var checkboxClass = formId === 'membershipForm' ? '.mem-checkbox' : '.row-checkbox';
    var checked = form.querySelectorAll(checkboxClass + ':checked');
    if (checked.length === 0) { alert('No items selected.'); return; }

    _markPaidFormId = formId;
    _markPaidAction = actionValue;

    document.getElementById('modal_method').value = 'cash';
    document.getElementById('modal_notes').value = '';
    document.getElementById('markPaidModal').classList.remove('hidden');
}

function submitMarkPaid() {
    var form = document.getElementById(_markPaidFormId);
    var method = document.getElementById('modal_method').value;
    var notes  = document.getElementById('modal_notes').value;

    // Set the hidden fields on the correct form
    if (_markPaidFormId === 'membershipForm') {
        document.getElementById('mem_hidden_method').value = method;
        document.getElementById('mem_hidden_notes').value  = notes;
    } else {
        document.getElementById('hidden_method').value = method;
        document.getElementById('hidden_notes').value  = notes;
    }

    document.getElementById('markPaidModal').classList.add('hidden');

    // Create hidden action input and submit
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'action'; inp.value = _markPaidAction;
    form.appendChild(inp);
    form.submit();
}
</script>

<?php include 'includes/footer.php'; ?>
