<?php
require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';
requireLogin();

// Migrations have been moved to migrate.php

$message = '';

// Flash message from cron.php
if (isset($_SESSION['flash_message'])) {
    $message = showAlert($_SESSION['flash_message'], 'info');
    unset($_SESSION['flash_message']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['action'])) {
        // ── Financial operations require admin/super_admin role ──
        $financialActions = ['add_plan', 'edit_plan', 'delete_plan', 'add_membership', 'apply_discount_to_membership'];
        if (in_array($_POST['action'], $financialActions, true)) {
            requireFinancialAccess();
        }

        switch ($_POST['action']) {
            case 'add_plan':
                $billing_freq = (isset($_POST['billing_frequency']) && $_POST['billing_frequency'] === 'monthly') ? 'monthly' : 'upfront';
                $reg_fee = max(0, (float) ($_POST['registration_fee'] ?? 0));
                $tax_ded = isset($_POST['tax_deductible']) ? 1 : 0;
                $is_afterschool = isset($_POST['is_afterschool']) ? 1 : 0;
                $is_camp = isset($_POST['is_camp']) ? 1 : 0;
                $is_grandfathered = isset($_POST['is_grandfathered']) ? 1 : 0;
                $is_fixed_term = ($is_afterschool || $is_camp);
                $program_start = $is_fixed_term ? (trim($_POST['program_start_date'] ?? '') ?: null) : null;
                $program_end   = $is_fixed_term ? (trim($_POST['program_end_date']   ?? '') ?: null) : null;

                if ($is_fixed_term && (!$program_start || !$program_end || $program_end <= $program_start)) {
                    $label = $is_camp ? 'Camp' : 'Afterschool';
                    $message = showAlert($label . ' plans require a valid start date before the end date.', 'error');
                    break;
                }

                // Auto-compute duration_months from program dates for fixed-term plans
                $duration_months = $is_fixed_term
                    ? max(1, (int) round((strtotime($program_end) - strtotime($program_start)) / (30.44 * 86400)))
                    : (int) $_POST['duration_months'];

                $stmt = $pdo->prepare("INSERT INTO membership_plans (school_id, name, description, duration_months, price, classes_per_week, status, billing_frequency, registration_fee, tax_deductible, is_afterschool, is_camp, program_start_date, program_end_date, is_grandfathered) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([current_school_id(), sanitizeInput($_POST['name']), sanitizeInput($_POST['description']), $duration_months, $_POST['price'], $_POST['classes_per_week'], $_POST['status'], $billing_freq, $reg_fee, $tax_ded, $is_afterschool, $is_camp, $program_start, $program_end, $is_grandfathered]);

                // Copy to other schools if requested
                $copyResults = '';
                if (!empty($_POST['copy_to_schools']) && is_super_admin()) {
                    require_once __DIR__ . '/includes/program_copy_helpers.php';
                    $newPlanId = (int)$pdo->lastInsertId();
                    $copyCount = 0;
                    foreach ($_POST['copy_to_schools'] as $targetSchoolId) {
                        if (copy_membership_plan($newPlanId, (int)$targetSchoolId)) {
                            $copyCount++;
                        }
                    }
                    if ($copyCount > 0) {
                        $copyResults = " Also copied to $copyCount other school(s).";
                    }
                }

                $message = showAlert('Membership plan created successfully!' . $copyResults, 'success');
                break;

            case 'edit_plan':
                $billing_freq = (isset($_POST['billing_frequency']) && $_POST['billing_frequency'] === 'monthly') ? 'monthly' : 'upfront';
                $reg_fee = max(0, (float) ($_POST['registration_fee'] ?? 0));
                $tax_ded = isset($_POST['tax_deductible']) ? 1 : 0;
                $is_afterschool = isset($_POST['is_afterschool']) ? 1 : 0;
                $is_camp = isset($_POST['is_camp']) ? 1 : 0;
                $is_grandfathered = isset($_POST['is_grandfathered']) ? 1 : 0;
                $is_fixed_term = ($is_afterschool || $is_camp);
                $program_start = $is_fixed_term ? (trim($_POST['program_start_date'] ?? '') ?: null) : null;
                $program_end   = $is_fixed_term ? (trim($_POST['program_end_date']   ?? '') ?: null) : null;

                if ($is_fixed_term && (!$program_start || !$program_end || $program_end <= $program_start)) {
                    $label = $is_camp ? 'Camp' : 'Afterschool';
                    $message = showAlert($label . ' plans require a valid start date before the end date.', 'error');
                    break;
                }

                // Auto-compute duration_months from program dates for fixed-term plans
                $duration_months = $is_fixed_term
                    ? max(1, (int) round((strtotime($program_end) - strtotime($program_start)) / (30.44 * 86400)))
                    : (int) $_POST['duration_months'];

                $params = [sanitizeInput($_POST['name']), sanitizeInput($_POST['description']), $duration_months, $_POST['price'], $_POST['classes_per_week'], $_POST['status'], $billing_freq, $reg_fee, $tax_ded, $is_afterschool, $is_camp, $program_start, $program_end, $is_grandfathered, $_POST['plan_id']];
                school_param($params);
                $stmt = $pdo->prepare("UPDATE membership_plans SET name = ?, description = ?, duration_months = ?, price = ?, classes_per_week = ?, status = ?, billing_frequency = ?, registration_fee = ?, tax_deductible = ?, is_afterschool = ?, is_camp = ?, program_start_date = ?, program_end_date = ?, is_grandfathered = ? WHERE id = ?" . school_where());
                $stmt->execute($params);
                $message = showAlert('Membership plan updated successfully!', 'success');
                break;

            case 'delete_plan':
                $check_params = [$_POST['plan_id']];
                school_param($check_params);
                $check = $pdo->prepare("SELECT COUNT(*) as count FROM memberships WHERE plan_id = ? AND status = 'active'" . school_where());
                $check->execute($check_params);
                if ($check->fetch()['count'] > 0) {
                    $message = showAlert('Cannot delete plan with active memberships! Deactivate it instead.', 'error');
                } else {
                    $del_params = [$_POST['plan_id']];
                    school_param($del_params);
                    $pdo->prepare("DELETE FROM membership_plans WHERE id = ?" . school_where())->execute($del_params);
                    $message = showAlert('Membership plan deleted successfully!', 'success');
                }
                break;

            case 'add_membership':
                $start_date = $_POST['start_date'];
                $plan_id = $_POST['plan_id'];
                $auto_renew = isset($_POST['auto_renew']) ? 1 : 0;

                $plan = $pdo->prepare("SELECT name, duration_months, price, registration_fee, billing_frequency, is_afterschool, is_camp, program_start_date, program_end_date FROM membership_plans WHERE id = ?");
                $plan->execute([$plan_id]);
                $plan_data = $plan->fetch();

                // Fixed-term plans (afterschool/camp): use fixed program end date, force no auto-renew
                $is_fixed_term_plan = (!empty($plan_data['is_afterschool']) || !empty($plan_data['is_camp']));
                if ($is_fixed_term_plan) {
                    $program_label = !empty($plan_data['is_camp']) ? 'camp' : 'afterschool';
                    if ($start_date > $plan_data['program_end_date']) {
                        $message = showAlert('Cannot enroll — this ' . $program_label . ' program has already ended.', 'error');
                        break;
                    }
                    $end_date = $plan_data['program_end_date'];
                    $auto_renew = 0;
                } else {
                    $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $plan_data['duration_months'] . ' months'));
                }

                $paymentStatus = $_POST['payment_status'];
                $amountPaid    = (float) $_POST['amount_paid'];
                $planPrice     = (float) ($plan_data['price'] ?? 0);
                $regFee        = (float) ($plan_data['registration_fee'] ?? 0);

                // ── Pre-validate discount code(s) (needed before auto-correct check) ──
                $discountCode       = trim($_POST['discount_code'] ?? '');
                $discountCoversAll  = false;
                $discountNote       = '';
                $discountBreakdown  = null;

                if ($discountCode !== '') {
                    $discountBreakdown = calculateTotalWithFees([
                        'base_amount'      => $planPrice,
                        'registration_fee' => $regFee,
                        'discount_code'    => $discountCode,
                        'plan_id'          => (int)$plan_id,
                    ]);
                    if ($discountBreakdown['discount_amount'] > 0) {
                        $effectiveTotal = ($planPrice + $regFee) - $discountBreakdown['discount_amount'];
                        if ($effectiveTotal <= 0) {
                            $discountCoversAll = true;
                        }
                    }
                }

                // Auto-correct: if admin marks "paid" but amount_paid is $0 and plan
                // actually costs money, set status to "pending" so it appears in
                // Pending Payments and can be properly charged later.
                // Skip auto-correct when a valid discount code fully covers the cost.
                if ($paymentStatus === 'paid' && $amountPaid <= 0 && $planPrice > 0 && !$discountCoversAll) {
                    $paymentStatus = 'pending';
                }

                // For monthly plans, set billing_day (capped at 28) and monthly_charges_made
                $billing_day = null;
                $monthly_charges = 0;
                if (($plan_data['billing_frequency'] ?? 'upfront') === 'monthly') {
                    $billing_day = min((int) date('j', strtotime($start_date)), 28);
                    // If the admin marked it as paid, count the first installment
                    if ($paymentStatus === 'paid' && $amountPaid > 0) {
                        $monthly_charges = 1;
                    }
                }

                $stmt = $pdo->prepare("INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date, status, payment_status, amount_paid, auto_renew, billing_day, monthly_charges_made) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([current_school_id(), $_POST['student_id'], $plan_id, $start_date, $end_date, 'active', $paymentStatus, $amountPaid, $auto_renew, $billing_day, $monthly_charges]);
                $membership_id = $pdo->lastInsertId();

                // ── Record discount code usage (reuse pre-validation result) ──
                if ($discountCode !== '' && $discountBreakdown && $discountBreakdown['discount_amount'] > 0) {
                    recordAllDiscountCodeUses($discountBreakdown, (int)$_POST['student_id'], 'membership', (int)$membership_id);
                    $codeNames = strtoupper(implode(', ', $discountBreakdown['discount_codes'] ?? [$discountCode]));
                    $discountNote = ' | Discount: -$' . number_format($discountBreakdown['discount_amount'], 2) . ' (' . $codeNames . ')';
                }

                if ($paymentStatus === 'paid' && $amountPaid > 0) {
                    $paymentNotes = 'Membership payment' . $discountNote;
                    $manualReceiptNum = generateReceiptNumber();
                    $pdo->prepare("INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount, payment_method, payment_date, receipt_number, notes) VALUES (?, ?, 'membership', ?, ?, ?, ?, ?, ?)")
                        ->execute([current_school_id(), $_POST['student_id'], $membership_id, $amountPaid, $_POST['payment_method'], date('Y-m-d'), $manualReceiptNum, $paymentNotes]);

                    // Send payment receipt email
                    send_payment_receipt_email([
                        'student_id'     => (int) $_POST['student_id'],
                        'amount'         => $amountPaid,
                        'payment_type'   => 'membership',
                        'description'    => 'Membership payment — ' . ($plan_data['name'] ?? 'Membership Plan'),
                        'receipt_number' => $manualReceiptNum,
                        'transaction_id' => null,
                        'payment_method' => $_POST['payment_method'] ?? 'other',
                    ]);
                }

                // Notify admin if status was auto-corrected
                if ($paymentStatus !== $_POST['payment_status']) {
                    $message = showAlert('Membership added — payment status set to <strong>Pending</strong> because no payment amount was entered. Process payment from the <a href="pending_payments.php?tab=memberships" class="underline font-semibold">Pending Payments</a> page.', 'warning');
                } else {
                    $discountMsg = $discountNote ? ' (discount applied)' : '';
                    $message = showAlert('Membership added successfully!' . $discountMsg, 'success');
                }
                break;

            case 'cancel_membership':
                $cancel_reason = sanitizeInput($_POST['cancel_reason'] ?? '');
                $cancel_params = [date('Y-m-d H:i:s'), $cancel_reason ?: null, $_POST['membership_id']];
                school_param($cancel_params);
                $pdo->prepare("UPDATE memberships SET status = 'cancelled', auto_renew = 0, cancelled_at = ?, cancel_reason = ? WHERE id = ?" . school_where())->execute($cancel_params);
                $message = showAlert('Membership cancelled successfully.', 'success');
                break;

            case 'hold_membership':
                $hold_reason = sanitizeInput($_POST['hold_reason'] ?? '');
                $hold_end = !empty($_POST['hold_end_date']) ? $_POST['hold_end_date'] : null;
                $hold_params = [date('Y-m-d'), $hold_end, $hold_reason ?: null, $_POST['membership_id']];
                school_param($hold_params);
                $pdo->prepare("UPDATE memberships SET status = 'on_hold', hold_start_date = ?, hold_end_date = ?, hold_reason = ? WHERE id = ?" . school_where())->execute($hold_params);
                $msg = 'Membership placed on hold.';
                if ($hold_end) $msg .= ' Will resume on ' . date('M j, Y', strtotime($hold_end)) . '.';
                $message = showAlert($msg, 'success');
                break;

            case 'resume_membership':
                $resume_params = [$_POST['membership_id']];
                school_param($resume_params);
                $pdo->prepare("UPDATE memberships SET status = 'active', hold_start_date = NULL, hold_end_date = NULL, hold_reason = NULL WHERE id = ?" . school_where())->execute($resume_params);
                $message = showAlert('Membership resumed successfully!', 'success');
                break;

            case 'reactivate_membership':
                $new_end = $_POST['new_end_date'] ?? '';
                if (empty($new_end)) {
                    $message = showAlert('Please provide a new end date to reactivate.', 'error');
                    break;
                }
                $react_params = [$new_end, $_POST['membership_id']];
                school_param($react_params);
                $pdo->prepare("UPDATE memberships SET status = 'active', end_date = ?, cancelled_at = NULL, cancel_reason = NULL WHERE id = ?" . school_where())->execute($react_params);
                $message = showAlert('Membership reactivated until ' . date('M j, Y', strtotime($new_end)) . '.', 'success');
                break;

            case 'toggle_auto_renew':
                $new_value = $_POST['auto_renew_value'] == '1' ? 1 : 0;
                $toggle_params = [$new_value, $_POST['membership_id']];
                school_param($toggle_params);
                $pdo->prepare("UPDATE memberships SET auto_renew = ? WHERE id = ?" . school_where())->execute($toggle_params);
                $message = showAlert('Auto-renewal ' . ($new_value ? 'enabled' : 'disabled') . '!', 'success');
                break;

            case 'apply_discount_to_membership':
                $membershipId = (int) $_POST['membership_id'];
                $discountCode = trim($_POST['discount_code'] ?? '');
                $planId       = (int) $_POST['plan_id'];
                $studentId    = (int) $_POST['student_id'];

                if ($discountCode === '') {
                    $message = showAlert('No discount code entered.', 'error');
                    break;
                }

                // Verify the membership exists and belongs to this school
                $memCheck = $pdo->prepare("SELECT m.id, m.payment_status, mp.price, mp.registration_fee FROM memberships m JOIN membership_plans mp ON m.plan_id = mp.id WHERE m.id = ? AND m.school_id = ?");
                $memCheck->execute([$membershipId, current_school_id()]);
                $memData = $memCheck->fetch();

                if (!$memData) {
                    $message = showAlert('Membership not found.', 'error');
                    break;
                }

                // Check if discounts are already applied to this membership
                $existingDiscountCount = $pdo->prepare("SELECT COUNT(*) FROM discount_code_uses WHERE reference_id = ? AND context = 'membership'");
                $existingDiscountCount->execute([$membershipId]);
                if ((int)$existingDiscountCount->fetchColumn() >= 2) {
                    $message = showAlert('This membership already has the maximum number of discount codes applied.', 'error');
                    break;
                }

                // Validate all discount codes via calculateTotalWithFees
                $memPlanPrice = (float) $memData['price'];
                $memRegFee    = (float) ($memData['registration_fee'] ?? 0);

                $breakdown = calculateTotalWithFees([
                    'base_amount'      => $memPlanPrice,
                    'registration_fee' => $memRegFee,
                    'discount_code'    => $discountCode,
                    'plan_id'          => $planId,
                ]);

                if ($breakdown['discount_amount'] <= 0) {
                    $errMsg = $breakdown['discount_error'] ?: 'The discount code(s) could not be applied.';
                    $message = showAlert($errMsg, 'error');
                    break;
                }

                // Record all discount code usages
                recordAllDiscountCodeUses($breakdown, $studentId, 'membership', $membershipId);

                $codeNames = strtoupper(implode(', ', $breakdown['discount_codes'] ?? []));
                $newTotal = $memPlanPrice + $memRegFee - $breakdown['discount_amount'];
                $message = showAlert(
                    'Discount code(s) <strong>' . htmlspecialchars($codeNames) . '</strong> applied! Saves ' .
                    formatMoney($breakdown['discount_amount']) . '. Discounted total: ' .
                    formatMoney(max(0, $newTotal)),
                    'success'
                );
                break;
        }
    }
}

// Get all membership plans
$plans_params = [];
school_param($plans_params);
$plans_stmt = $pdo->prepare("SELECT * FROM membership_plans " . school_where_clause() . " ORDER BY price ASC");
$plans_stmt->execute($plans_params);
$plans = $plans_stmt->fetchAll();

// Get memberships (filtered)
$status_filter = $_GET['status'] ?? 'active';
$query = "SELECT m.*, s.first_name, s.last_name, s.email, mp.name as plan_name, mp.price as plan_price, mp.registration_fee as plan_reg_fee, mp.billing_frequency, m.hold_start_date, m.hold_end_date, m.hold_reason, m.cancelled_at, m.cancel_reason,
    (SELECT dc.code FROM discount_code_uses dcu JOIN discount_codes dc ON dcu.discount_code_id = dc.id WHERE dcu.reference_id = m.id AND dcu.context = 'membership' LIMIT 1) as applied_discount_code,
    (SELECT dcu.applied_amount FROM discount_code_uses dcu WHERE dcu.reference_id = m.id AND dcu.context = 'membership' LIMIT 1) as applied_discount_amount
    FROM memberships m JOIN students s ON m.student_id = s.id JOIN membership_plans mp ON m.plan_id = mp.id WHERE 1=1";
if (!is_viewing_all_schools()) { $query .= " AND m.school_id = :school_id"; }
if ($status_filter) { $query .= " AND m.status = :status"; }
$query .= " ORDER BY m.created_at DESC LIMIT 500";
$stmt = $pdo->prepare($query);
if (!is_viewing_all_schools()) { $stmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
if ($status_filter) { $stmt->bindValue(':status', $status_filter); }
$stmt->execute();
$memberships = $stmt->fetchAll();

// Get expiring soon (within 7 days)
$expiring_params = [];
school_param($expiring_params);
$expiring_stmt = $pdo->prepare("
    SELECT m.*, s.first_name, s.last_name, mp.name as plan_name
    FROM memberships m JOIN students s ON m.student_id = s.id JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.status = 'active' AND m.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)" . school_where('m') . "
    ORDER BY m.end_date ASC
");
$expiring_stmt->execute($expiring_params);
$expiring_soon = $expiring_stmt->fetchAll();

// Get active students for dropdown
$students_params = [];
school_param($students_params);
$students_stmt = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE status = 'active'" . school_where() . " ORDER BY first_name, last_name");
$students_stmt->execute($students_params);
$students = $students_stmt->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Memberships</h1>
        <div class="space-x-3 flex items-center">
            <?php if (in_array(getCurrentUser()['role'], ['admin', 'super_admin'])): ?>
                <a href="test_renewals.php"
                   class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium text-sm">
                    &#128269; Test Renewals
                </a>
                <a href="cron.php" onclick="return confirm('Run membership renewal processing now?')"
                   class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg font-medium text-sm">
                    ⟳ Process Renewals
                </a>
            <?php endif; ?>
            <?php if (hasFinancialAccess()): ?>
            <button onclick="document.getElementById('addPlanModal').classList.remove('hidden')" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-medium">+ Add Plan</button>
            <button onclick="document.getElementById('addMembershipModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">+ Add Membership</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($expiring_soon)): ?>
    <div class="bg-orange-50 border-l-4 border-orange-500 p-4 mb-6 rounded-r-lg">
        <h3 class="text-orange-700 font-semibold mb-2">&#9888; Expiring Soon (<?php echo count($expiring_soon); ?>)</h3>
        <div class="space-y-2">
            <?php foreach ($expiring_soon as $exp): ?>
                <div class="flex items-center justify-between bg-white rounded p-3 border border-orange-200">
                    <span class="font-medium text-gray-800"><?php echo $exp['first_name'] . ' ' . $exp['last_name']; ?> <span class="text-sm text-gray-500">&mdash; <?php echo $exp['plan_name']; ?></span></span>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-orange-700 font-semibold">Expires: <?php echo formatDate($exp['end_date']); ?></span>
                        <span class="px-2 py-1 text-xs rounded-full <?php echo (isset($exp['auto_renew']) && $exp['auto_renew']) ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                            <?php echo (isset($exp['auto_renew']) && $exp['auto_renew']) ? 'Auto-renew ON' : 'Auto-renew OFF'; ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Membership Plans -->
    <div class="mb-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-4">Membership Plans</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($plans as $plan): ?>
                <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-6">
                        <h3 class="text-2xl font-bold mb-2"><?php echo $plan['name']; ?></h3>
                        <?php
                        $isMonthly = (isset($plan['billing_frequency']) && $plan['billing_frequency'] === 'monthly' && $plan['duration_months'] > 1);
                        $monthlyAmount = $isMonthly ? round($plan['price'] / $plan['duration_months'], 2) : 0;
                        ?>
                        <?php if ($isMonthly): ?>
                            <p class="text-3xl font-bold"><?php echo formatMoney($monthlyAmount); ?><span class="text-base font-normal">/mo</span></p>
                            <p class="text-sm opacity-90"><?php echo formatMoney($plan['price']); ?> total over <?php echo $plan['duration_months']; ?> months</p>
                        <?php else: ?>
                            <p class="text-3xl font-bold"><?php echo formatMoney($plan['price']); ?></p>
                            <p class="text-sm opacity-90">per <?php echo $plan['duration_months']; ?> month(s)</p>
                        <?php endif; ?>
                        <?php if (isset($plan['registration_fee']) && $plan['registration_fee'] > 0): ?>
                            <p class="text-sm opacity-90">+ <?php echo formatMoney($plan['registration_fee']); ?> registration fee</p>
                        <?php endif; ?>
                    </div>
                    <div class="p-6">
                        <p class="text-gray-700 mb-4"><?php echo $plan['description']; ?></p>
                        <ul class="space-y-2 text-gray-600">
                            <li>&#10003; <?php echo $plan['classes_per_week'] == 99 ? 'Unlimited' : $plan['classes_per_week']; ?> classes/week</li>
                            <li>&#10003; <?php echo $plan['duration_months']; ?> month duration</li>
                            <li>&#10003; <?php echo $isMonthly ? 'Billed monthly' : 'Billed upfront'; ?></li>
                            <?php if (!empty($plan['is_afterschool']) || !empty($plan['is_camp'])): ?>
                                <li>&#10003; Fixed-term (no auto-renewal)</li>
                                <li>&#10003; Mid-program proration available</li>
                            <?php else: ?>
                                <li>&#10003; Auto-renewable subscription</li>
                            <?php endif; ?>
                        </ul>
                        <div class="mt-4 flex items-center gap-2 flex-wrap">
                            <span class="px-3 py-1 text-sm rounded-full <?php echo $plan['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>"><?php echo ucfirst($plan['status']); ?></span>
                            <?php if (!empty($plan['is_afterschool'])): ?>
                                <span class="px-3 py-1 text-sm rounded-full bg-indigo-100 text-indigo-800 font-semibold">Afterschool</span>
                            <?php endif; ?>
                            <?php if (!empty($plan['is_camp'])): ?>
                                <span class="px-3 py-1 text-sm rounded-full bg-teal-100 text-teal-800 font-semibold">Camp</span>
                            <?php endif; ?>
                            <?php if (!empty($plan['tax_deductible'])): ?>
                                <span class="px-3 py-1 text-sm rounded-full bg-green-100 text-green-800 font-semibold">Tax-Deductible</span>
                            <?php endif; ?>
                            <?php if (!empty($plan['is_grandfathered'])): ?>
                                <span class="px-3 py-1 text-sm rounded-full bg-amber-100 text-amber-800 font-semibold">Grandfathered</span>
                            <?php endif; ?>
                        </div>
                        <?php if ((!empty($plan['is_afterschool']) || !empty($plan['is_camp'])) && $plan['program_start_date'] && $plan['program_end_date']): ?>
                            <p class="text-sm <?php echo !empty($plan['is_camp']) ? 'text-teal-700' : 'text-indigo-700'; ?> mt-2">&#128197; Program: <?php echo date('M j, Y', strtotime($plan['program_start_date'])); ?> &ndash; <?php echo date('M j, Y', strtotime($plan['program_end_date'])); ?></p>
                        <?php endif; ?>
                        <?php if (in_array(getCurrentUser()['role'], ['admin', 'super_admin'])): ?>
                        <div class="mt-4 pt-4 border-t border-gray-200 flex space-x-2">
                            <button onclick="editPlan(<?php echo htmlspecialchars(json_encode($plan)); ?>)" class="flex-1 text-blue-600 hover:text-blue-800 text-sm font-medium">Edit</button>
                            <form method="POST" class="flex-1" onsubmit="return confirmDelete('Delete this plan?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_plan">
                                <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                <button type="submit" class="w-full text-red-600 hover:text-red-800 text-sm font-medium">Delete</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Memberships Table -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-xl font-semibold text-gray-800">Memberships</h2>
            <div class="flex space-x-2">
                <a href="?status=active" class="px-4 py-2 rounded-lg text-sm <?php echo $status_filter === 'active' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">Active</a>
                <a href="?status=on_hold" class="px-4 py-2 rounded-lg text-sm <?php echo $status_filter === 'on_hold' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">On Hold</a>
                <a href="?status=expired" class="px-4 py-2 rounded-lg text-sm <?php echo $status_filter === 'expired' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">Expired</a>
                <a href="?status=cancelled" class="px-4 py-2 rounded-lg text-sm <?php echo $status_filter === 'cancelled' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">Cancelled</a>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50"><tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Period</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Auto-Renew</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr></thead>
                <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($memberships as $m): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="font-medium text-gray-900"><?php echo $m['first_name'] . ' ' . $m['last_name']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo $m['email']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900"><?php echo $m['plan_name']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo formatMoney($m['plan_price']); ?></div>
                            <?php if (isset($m['billing_day']) && $m['billing_day']): ?>
                                <div class="text-xs text-blue-600">Monthly &middot; Day <?php echo $m['billing_day']; ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php echo formatDate($m['start_date']); ?> &ndash; <?php echo formatDate($m['end_date']); ?>
                            <?php $days_left = (strtotime($m['end_date']) - time()) / 86400; if ($m['status'] === 'active' && $days_left > 0 && $days_left <= 30): ?>
                                <div class="text-xs text-orange-600 font-medium"><?php echo floor($days_left); ?> days left</div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php $sc = ['active'=>'bg-green-100 text-green-800','expired'=>'bg-red-100 text-red-800','cancelled'=>'bg-gray-100 text-gray-800','on_hold'=>'bg-yellow-100 text-yellow-800']; ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $sc[$m['status']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                <?php echo $m['status'] === 'on_hold' ? 'On Hold' : ucfirst($m['status']); ?>
                            </span>
                            <?php if ($m['status'] === 'on_hold' && !empty($m['hold_end_date'])): ?>
                                <div class="text-xs text-yellow-600 mt-0.5">Until <?= date('M j, Y', strtotime($m['hold_end_date'])) ?></div>
                            <?php endif; ?>
                            <?php if ($m['status'] === 'on_hold' && !empty($m['hold_reason'])): ?>
                                <div class="text-xs text-gray-500 mt-0.5" title="<?= htmlspecialchars($m['hold_reason']) ?>"><?= htmlspecialchars(mb_strimwidth($m['hold_reason'], 0, 30, '...')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php $pc = ['paid'=>'bg-green-100 text-green-800','pending'=>'bg-yellow-100 text-yellow-800','partial'=>'bg-orange-100 text-orange-800','declined'=>'bg-red-100 text-red-800','waived'=>'bg-blue-100 text-blue-800']; ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $pc[$m['payment_status']] ?? 'bg-gray-100 text-gray-800'; ?>"><?php echo ucfirst($m['payment_status']); ?></span>
                            <div class="text-xs text-gray-600 mt-1"><?php echo formatMoney($m['amount_paid']); ?></div>
                            <?php if (!empty($m['applied_discount_code'])): ?>
                                <div class="text-xs text-indigo-600 mt-0.5 font-medium"><?= strtoupper($m['applied_discount_code']) ?> (-<?= formatMoney($m['applied_discount_amount']) ?>)</div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if ($m['status'] === 'active' || $m['status'] === 'on_hold'): ?>
                                <form method="POST" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_auto_renew">
                                    <input type="hidden" name="membership_id" value="<?php echo $m['id']; ?>">
                                    <input type="hidden" name="auto_renew_value" value="<?php echo (isset($m['auto_renew']) && $m['auto_renew']) ? '0' : '1'; ?>">
                                    <button type="submit" class="px-2 py-1 text-xs font-semibold rounded-full <?php echo (isset($m['auto_renew']) && $m['auto_renew']) ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                        <?php echo (isset($m['auto_renew']) && $m['auto_renew']) ? '&#10003; ON' : '&#10007; OFF'; ?>
                                    </button>
                                </form>
                            <?php else: ?><span class="text-xs text-gray-400">N/A</span><?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <a href="student_detail.php?id=<?php echo $m['student_id']; ?>" class="text-blue-600 hover:text-blue-900 mr-2">View</a>
                            <?php if ($m['payment_status'] === 'pending' && empty($m['applied_discount_code']) && hasFinancialAccess()): ?>
                                <button type="button" onclick="openApplyDiscountModal(<?= $m['id'] ?>, <?= (int)$m['plan_id'] ?>, <?= htmlspecialchars(json_encode($m['plan_name']), ENT_QUOTES) ?>, <?= (float)$m['plan_price'] ?>, <?= (float)($m['plan_reg_fee'] ?? 0) ?>, <?= (int)$m['student_id'] ?>)" class="text-indigo-600 hover:text-indigo-800 mr-2">Apply Discount</button>
                            <?php endif; ?>
                            <?php if ($m['status'] === 'active'): ?>
                                <button type="button" onclick="openHoldModal(<?= $m['id'] ?>)" class="text-yellow-600 hover:text-yellow-800 mr-2">Hold</button>
                                <button type="button" onclick="openCancelModal(<?= $m['id'] ?>)" class="text-red-600 hover:text-red-900">Cancel</button>
                            <?php elseif ($m['status'] === 'on_hold'): ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Resume this membership?')">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="resume_membership">
                                    <input type="hidden" name="membership_id" value="<?php echo $m['id']; ?>">
                                    <button type="submit" class="text-green-600 hover:text-green-800 mr-2">Resume</button>
                                </form>
                                <button type="button" onclick="openCancelModal(<?= $m['id'] ?>)" class="text-red-600 hover:text-red-900">Cancel</button>
                            <?php elseif ($m['status'] === 'cancelled' || $m['status'] === 'expired'): ?>
                                <button type="button" onclick="openReactivateModal(<?= $m['id'] ?>)" class="text-green-600 hover:text-green-800">Reactivate</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($memberships)): ?><div class="text-center py-12 text-gray-500"><p class="text-lg">No <?php echo $status_filter; ?> memberships found</p></div><?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Plan Modal -->
<div id="addPlanModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4"><h3 class="text-xl font-bold text-gray-800">Create Membership Plan</h3><button onclick="document.getElementById('addPlanModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button></div>
        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_plan">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Plan Name *</label><input type="text" name="name" required placeholder="e.g., Premium Monthly" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Description</label><textarea name="description" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea></div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Duration (months) *</label><input type="number" name="duration_months" min="1" required value="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Price *</label><input type="number" name="price" step="0.01" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Classes/Week *</label><input type="number" name="classes_per_week" min="1" max="99" required placeholder="99=unlimited" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Registration Fee</label><input type="number" name="registration_fee" step="0.01" min="0" value="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg"><p class="text-xs text-gray-500 mt-1">One-time fee for new enrollees</p></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Status</label><select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Billing Frequency</label>
                    <select name="billing_frequency" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="upfront">Upfront (full price at once)</option>
                        <option value="monthly">Monthly Installments</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Monthly = total price &divide; duration charged each month</p>
                </div>
            </div>
            <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="tax_deductible" value="1" class="w-5 h-5 text-green-600 border-gray-300 rounded focus:ring-green-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Tax-Deductible (Afterschool/Camp)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Mark if this plan qualifies as dependent care for tax purposes</p>
                    </div>
                </label>
            </div>
            <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_afterschool" value="1" id="add_is_afterschool" onchange="toggleAfterschoolFields('add')" class="w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Afterschool Program (Fixed Term)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Set a fixed start &amp; end date — students enrolling mid-program pay a prorated amount</p>
                    </div>
                </label>
                <div id="add_afterschool_fields" class="hidden mt-3 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Program Start Date *</label>
                        <input type="date" name="program_start_date" id="add_program_start_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Program End Date *</label>
                        <input type="date" name="program_end_date" id="add_program_end_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div class="col-span-2">
                        <p class="text-xs text-indigo-600">&#128161; Duration will be auto-calculated from the program dates. Auto-renewal is disabled for afterschool plans.</p>
                    </div>
                </div>
            </div>
            <div class="bg-teal-50 border border-teal-200 rounded-lg p-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_camp" value="1" id="add_is_camp" onchange="toggleCampFields('add')" class="w-5 h-5 text-teal-600 border-gray-300 rounded focus:ring-teal-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Camp Program (Fixed Term)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Set a fixed start &amp; end date — functions like afterschool with tax reporting &amp; no 30-day lock</p>
                    </div>
                </label>
                <div id="add_camp_fields" class="hidden mt-3 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Camp Start Date *</label>
                        <input type="date" id="add_camp_start_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-teal-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Camp End Date *</label>
                        <input type="date" id="add_camp_end_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-teal-500">
                    </div>
                    <div class="col-span-2">
                        <p class="text-xs text-teal-600">&#128161; Duration will be auto-calculated from camp dates. Auto-renewal is disabled for camp plans.</p>
                    </div>
                </div>
            </div>
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_grandfathered" value="1" class="w-5 h-5 text-amber-600 border-gray-300 rounded focus:ring-amber-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Grandfathered Plan (Legacy Pricing)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Hidden from student self-signup. Can only be assigned to students by an admin.</p>
                    </div>
                </label>
            </div>
            <?php if (is_super_admin() && count(get_all_schools()) > 1): ?>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="enableCopyPlan" onchange="document.getElementById('copyPlanSchools').classList.toggle('hidden', !this.checked)" class="w-4 h-4 text-blue-600 rounded">
                    <span class="text-sm font-medium text-gray-700">Also create in other schools</span>
                </label>
                <div id="copyPlanSchools" class="hidden mt-2 ml-6 space-y-1">
                    <?php foreach (get_all_schools() as $_cs): ?>
                        <?php if ((int)$_cs['id'] !== (int)current_school_id()): ?>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" name="copy_to_schools[]" value="<?= $_cs['id'] ?>" class="rounded">
                            <?= htmlspecialchars($_cs['name']) ?>
                        </label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addPlanModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">Create Plan</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Membership Modal -->
<div id="addMembershipModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4"><h3 class="text-xl font-bold text-gray-800">Add Membership</h3><button onclick="document.getElementById('addMembershipModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button></div>
        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_membership">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Student *</label><div id="membership-student-picker"></div></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Plan *</label><select name="plan_id" id="membership-plan-select" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"><option value="">Choose a plan...</option><?php foreach ($plans as $p): ?><?php if ($p['status'] === 'active'): ?><option value="<?php echo $p['id']; ?>" data-price="<?php echo (float)$p['price']; ?>" data-regfee="<?php echo (float)($p['registration_fee'] ?? 0); ?>"><?php echo $p['name']; ?><?php if (!empty($p['is_grandfathered'])): ?> (Grandfathered)<?php endif; ?> - <?php echo formatMoney($p['price']); ?> (<?php echo $p['duration_months']; ?>mo)</option><?php endif; ?><?php endforeach; ?></select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Start Date *</label><input type="date" name="start_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Payment Status *</label><select name="payment_status" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"><option value="paid">Paid</option><option value="pending">Pending</option><option value="partial">Partial</option></select></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Amount Paid *</label><input type="number" name="amount_paid" id="membership-amount-paid" step="0.01" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
            </div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label><select name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg"><option value="cash">Cash</option><option value="credit_card">Credit Card</option><option value="debit_card">Debit Card</option><option value="bank_transfer">Bank Transfer</option><option value="other">Other</option></select></div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Discount Code(s)</label>
                <div class="flex gap-2">
                    <input type="text" id="admin-discount-code" placeholder="Enter code and click Apply"
                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <button type="button" id="admin-apply-discount"
                            class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium">
                        Apply
                    </button>
                </div>
                <input type="hidden" name="discount_code" id="admin-discount-hidden" value="">
                <div id="admin-discount-message" class="text-sm mt-1"></div>
                <div id="admin-applied-codes" class="flex flex-wrap gap-2 mt-2"></div>
                <p class="text-xs text-gray-400 mt-1">You can apply up to 2 codes: one for plan price and one for registration fee.</p>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                <label class="flex items-center space-x-2 cursor-pointer"><input type="checkbox" name="auto_renew" value="1" checked class="rounded border-gray-300 text-blue-600"><span class="text-sm font-medium text-blue-800">Enable auto-renewal (subscription)</span></label>
                <p class="text-xs text-blue-600 mt-1">Membership auto-renews if payment gateway is configured; otherwise expires for manual renewal.</p>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addMembershipModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Add Membership</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Plan Modal -->
<div id="editPlanModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4"><h3 class="text-xl font-bold text-gray-800">Edit Plan</h3><button onclick="document.getElementById('editPlanModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button></div>
        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_plan"><input type="hidden" name="plan_id" id="edit_plan_id">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Plan Name *</label><input type="text" name="name" id="edit_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Description</label><textarea name="description" id="edit_description" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea></div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Duration (months)</label><input type="number" name="duration_months" id="edit_duration_months" min="1" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Price</label><input type="number" name="price" id="edit_price" step="0.01" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Classes/Week</label><input type="number" name="classes_per_week" id="edit_classes_per_week" min="1" max="99" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Registration Fee</label><input type="number" name="registration_fee" id="edit_registration_fee" step="0.01" min="0" value="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg"><p class="text-xs text-gray-500 mt-1">One-time fee for new enrollees</p></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Status</label><select name="status" id="edit_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Billing Frequency</label>
                    <select name="billing_frequency" id="edit_billing_frequency" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="upfront">Upfront (full price at once)</option>
                        <option value="monthly">Monthly Installments</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Monthly = total price &divide; duration charged each month</p>
                </div>
            </div>
            <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="tax_deductible" value="1" id="edit_tax_deductible" class="w-5 h-5 text-green-600 border-gray-300 rounded focus:ring-green-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Tax-Deductible (Afterschool/Camp)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Mark if this plan qualifies as dependent care for tax purposes</p>
                    </div>
                </label>
            </div>
            <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_afterschool" value="1" id="edit_is_afterschool" onchange="toggleAfterschoolFields('edit')" class="w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Afterschool Program (Fixed Term)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Set a fixed start &amp; end date — students enrolling mid-program pay a prorated amount</p>
                    </div>
                </label>
                <div id="edit_afterschool_fields" class="hidden mt-3 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Program Start Date *</label>
                        <input type="date" name="program_start_date" id="edit_program_start_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Program End Date *</label>
                        <input type="date" name="program_end_date" id="edit_program_end_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div class="col-span-2">
                        <p class="text-xs text-indigo-600">&#128161; Duration will be auto-calculated from the program dates. Auto-renewal is disabled for afterschool plans.</p>
                    </div>
                </div>
            </div>
            <div class="bg-teal-50 border border-teal-200 rounded-lg p-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_camp" value="1" id="edit_is_camp" onchange="toggleCampFields('edit')" class="w-5 h-5 text-teal-600 border-gray-300 rounded focus:ring-teal-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Camp Program (Fixed Term)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Set a fixed start &amp; end date — functions like afterschool with tax reporting &amp; no 30-day lock</p>
                    </div>
                </label>
                <div id="edit_camp_fields" class="hidden mt-3 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Camp Start Date *</label>
                        <input type="date" id="edit_camp_start_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-teal-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Camp End Date *</label>
                        <input type="date" id="edit_camp_end_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-teal-500">
                    </div>
                    <div class="col-span-2">
                        <p class="text-xs text-teal-600">&#128161; Duration will be auto-calculated from camp dates. Auto-renewal is disabled for camp plans.</p>
                    </div>
                </div>
            </div>
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_grandfathered" value="1" id="edit_is_grandfathered" class="w-5 h-5 text-amber-600 border-gray-300 rounded focus:ring-amber-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Grandfathered Plan (Legacy Pricing)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Hidden from student self-signup. Can only be assigned to students by an admin.</p>
                    </div>
                </label>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editPlanModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Update Plan</button>
            </div>
        </form>
    </div>
</div>

<!-- Apply Discount to Existing Membership Modal -->
<div id="applyDiscountModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-800">Apply Discount Code</h3>
            <button onclick="document.getElementById('applyDiscountModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" id="applyDiscountForm" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="apply_discount_to_membership">
            <input type="hidden" name="membership_id" id="discount_membership_id">
            <input type="hidden" name="plan_id" id="discount_plan_id">
            <input type="hidden" name="student_id" id="discount_student_id">
            <input type="hidden" name="discount_code" id="discount_code_hidden">
            <div class="bg-gray-50 rounded-lg p-3 text-sm">
                <p class="text-gray-600">Plan: <strong id="discount_plan_name_display"></strong></p>
                <p class="text-gray-600">Price: <strong id="discount_plan_price_display"></strong></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Discount Code(s)</label>
                <div class="flex gap-2">
                    <input type="text" id="existing-discount-code" placeholder="Enter code and click Apply"
                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-indigo-500">
                    <button type="button" id="existing-apply-discount-btn"
                            class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium">Apply</button>
                </div>
                <div id="existing-discount-message" class="text-sm mt-1"></div>
                <div id="existing-applied-codes" class="flex flex-wrap gap-2 mt-2"></div>
                <p class="text-xs text-gray-400 mt-1">You can apply up to 2 codes: one for plan price and one for registration fee.</p>
            </div>
            <div id="discount-summary" class="hidden bg-green-50 border border-green-200 rounded-lg p-3 text-sm text-green-700">
                <p>Discounted total: <strong id="discount-new-total"></strong></p>
                <p class="text-xs text-green-600 mt-1">The discount will be recorded and applied when payment is processed.</p>
            </div>
            <div class="flex justify-end space-x-3 pt-2">
                <button type="button" onclick="document.getElementById('applyDiscountModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" id="discount-submit-btn" disabled class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium disabled:opacity-50 disabled:cursor-not-allowed">Save Discount</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleFixedTermDuration(prefix) {
    // Check if either afterschool or camp is checked to dim duration field
    var afterschoolChecked = document.getElementById(prefix + '_is_afterschool').checked;
    var campChecked = document.getElementById(prefix + '_is_camp').checked;
    var isFixedTerm = afterschoolChecked || campChecked;

    var modalId = prefix === 'add' ? '#addPlanModal' : '#editPlanModal';
    var form = document.querySelector(modalId + ' form');
    var durField = form ? form.querySelector('input[name="duration_months"]') : document.getElementById(prefix + '_duration_months');

    if (durField) {
        if (isFixedTerm) {
            durField.closest('div').style.opacity = '0.4';
            durField.removeAttribute('required');
            if (prefix === 'add' || !durField.value) { durField.value = ''; }
            durField.placeholder = 'Auto';
        } else {
            durField.closest('div').style.opacity = '1';
            durField.setAttribute('required', 'required');
            durField.placeholder = '';
        }
    }
}

function toggleAfterschoolFields(prefix) {
    var checked = document.getElementById(prefix + '_is_afterschool').checked;
    var fields = document.getElementById(prefix + '_afterschool_fields');

    if (checked) {
        fields.classList.remove('hidden');
        // Uncheck camp — they're mutually exclusive
        document.getElementById(prefix + '_is_camp').checked = false;
        document.getElementById(prefix + '_camp_fields').classList.add('hidden');
    } else {
        fields.classList.add('hidden');
    }
    toggleFixedTermDuration(prefix);
}

function toggleCampFields(prefix) {
    var checked = document.getElementById(prefix + '_is_camp').checked;
    var fields = document.getElementById(prefix + '_camp_fields');

    if (checked) {
        fields.classList.remove('hidden');
        // Uncheck afterschool — they're mutually exclusive
        document.getElementById(prefix + '_is_afterschool').checked = false;
        document.getElementById(prefix + '_afterschool_fields').classList.add('hidden');
    } else {
        fields.classList.add('hidden');
    }
    toggleFixedTermDuration(prefix);
}

// Sync camp dates to the program_start_date/program_end_date named inputs before submit
function syncCampDates(prefix) {
    if (document.getElementById(prefix + '_is_camp').checked) {
        document.getElementById(prefix + '_program_start_date').value = document.getElementById(prefix + '_camp_start_date').value;
        document.getElementById(prefix + '_program_end_date').value = document.getElementById(prefix + '_camp_end_date').value;
    }
}
// Attach to both forms
document.addEventListener('DOMContentLoaded', function() {
    var addForm = document.querySelector('#addPlanModal form');
    if (addForm) addForm.addEventListener('submit', function() { syncCampDates('add'); });
    var editForm = document.querySelector('#editPlanModal form');
    if (editForm) editForm.addEventListener('submit', function() { syncCampDates('edit'); });
});

function editPlan(plan) {
    document.getElementById('edit_plan_id').value = plan.id;
    document.getElementById('edit_name').value = plan.name;
    document.getElementById('edit_description').value = plan.description || '';
    document.getElementById('edit_duration_months').value = plan.duration_months;
    document.getElementById('edit_price').value = plan.price;
    document.getElementById('edit_classes_per_week').value = plan.classes_per_week;
    document.getElementById('edit_status').value = plan.status;
    document.getElementById('edit_billing_frequency').value = plan.billing_frequency || 'upfront';
    document.getElementById('edit_registration_fee').value = plan.registration_fee || 0;
    document.getElementById('edit_tax_deductible').checked = (plan.tax_deductible == 1);

    // Grandfathered flag
    document.getElementById('edit_is_grandfathered').checked = (plan.is_grandfathered == 1);

    // Afterschool fields
    var isAfterschool = (plan.is_afterschool == 1);
    document.getElementById('edit_is_afterschool').checked = isAfterschool;
    document.getElementById('edit_program_start_date').value = plan.program_start_date || '';
    document.getElementById('edit_program_end_date').value = plan.program_end_date || '';

    // Camp fields
    var isCamp = (plan.is_camp == 1);
    document.getElementById('edit_is_camp').checked = isCamp;
    document.getElementById('edit_camp_start_date').value = plan.program_start_date || '';
    document.getElementById('edit_camp_end_date').value = plan.program_end_date || '';

    toggleAfterschoolFields('edit');
    toggleCampFields('edit');

    document.getElementById('editPlanModal').classList.remove('hidden');
}

// ---- Hold / Cancel / Reactivate Modals ----
function openHoldModal(membershipId) {
    document.getElementById('hold_membership_id').value = membershipId;
    document.getElementById('hold_reason').value = '';
    document.getElementById('hold_end_date').value = '';
    document.getElementById('holdModal').classList.remove('hidden');
}

function openCancelModal(membershipId) {
    document.getElementById('cancel_membership_id').value = membershipId;
    document.getElementById('cancel_reason').value = '';
    document.getElementById('cancelModal').classList.remove('hidden');
}

function openReactivateModal(membershipId) {
    document.getElementById('reactivate_membership_id').value = membershipId;
    // Default to 30 days from now
    var d = new Date();
    d.setDate(d.getDate() + 30);
    document.getElementById('new_end_date').value = d.toISOString().split('T')[0];
    document.getElementById('reactivateModal').classList.remove('hidden');
}

function openApplyDiscountModal(membershipId, planId, planName, planPrice, planRegFee, studentId) {
    document.getElementById('discount_membership_id').value = membershipId;
    document.getElementById('discount_plan_id').value = planId;
    document.getElementById('discount_student_id').value = studentId;
    document.getElementById('discount_code_hidden').value = '';
    document.getElementById('discount_plan_name_display').textContent = planName;
    document.getElementById('discount_plan_price_display').textContent = '$' + (planPrice + planRegFee).toFixed(2);
    document.getElementById('existing-discount-code').value = '';
    document.getElementById('existing-discount-message').innerHTML = '';
    document.getElementById('existing-applied-codes').innerHTML = '';
    document.getElementById('discount-summary').classList.add('hidden');
    document.getElementById('discount-submit-btn').disabled = true;

    // Store plan data for AJAX call
    var modal = document.getElementById('applyDiscountModal');
    modal.dataset.planPrice = planPrice;
    modal.dataset.planRegfee = planRegFee;

    modal.classList.remove('hidden');
}
</script>

<!-- Hold Membership Modal -->
<div id="holdModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-800">Put Membership On Hold</h3>
            <button onclick="document.getElementById('holdModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="hold_membership">
            <input type="hidden" name="membership_id" id="hold_membership_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Reason (optional)</label>
                <input type="text" name="hold_reason" id="hold_reason" maxlength="255" placeholder="e.g., Injury, Travel, Personal reasons"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-yellow-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Resume Date (optional)</label>
                <input type="date" name="hold_end_date" id="hold_end_date"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-yellow-500">
                <p class="text-xs text-gray-500 mt-1">Leave blank for indefinite hold. Manual resume required.</p>
            </div>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-sm text-yellow-700">
                <p>Billing will be paused while the membership is on hold. The student will not be charged until the membership is resumed.</p>
            </div>
            <div class="flex justify-end space-x-3 pt-2">
                <button type="button" onclick="document.getElementById('holdModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg font-medium">Put On Hold</button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Membership Modal -->
<div id="cancelModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-800">Cancel Membership</h3>
            <button onclick="document.getElementById('cancelModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4" onsubmit="return confirm('Are you sure you want to cancel this membership? This will disable auto-renewal.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel_membership">
            <input type="hidden" name="membership_id" id="cancel_membership_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Reason (optional)</label>
                <input type="text" name="cancel_reason" id="cancel_reason" maxlength="255" placeholder="e.g., Student request, Non-payment, Moving"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
            </div>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700">
                <p>This will cancel the membership and disable auto-renewal. The student will lose access to membership benefits.</p>
            </div>
            <div class="flex justify-end space-x-3 pt-2">
                <button type="button" onclick="document.getElementById('cancelModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Go Back</button>
                <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium">Cancel Membership</button>
            </div>
        </form>
    </div>
</div>

<!-- Reactivate Membership Modal -->
<div id="reactivateModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-800">Reactivate Membership</h3>
            <button onclick="document.getElementById('reactivateModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reactivate_membership">
            <input type="hidden" name="membership_id" id="reactivate_membership_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">New End Date *</label>
                <input type="date" name="new_end_date" id="new_end_date" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                <p class="text-xs text-gray-500 mt-1">Set the new membership end date.</p>
            </div>
            <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-sm text-green-700">
                <p>This will reactivate the membership and set it back to active status with the specified end date.</p>
            </div>
            <div class="flex justify-end space-x-3 pt-2">
                <button type="button" onclick="document.getElementById('reactivateModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium">Reactivate</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/student-picker.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    StudentPicker.init({
        container: '#membership-student-picker',
        inputName: 'student_id',
        placeholder: 'Type student name to search\u2026',
        data: <?= json_encode(array_map(function($s) { return ['id' => $s['id'], 'name' => trim($s['first_name'] . ' ' . $s['last_name'])]; }, $students)) ?>
    });
});

// ── Admin Discount Code for Add Membership ──
(function() {
    var planSelect   = document.getElementById('membership-plan-select');
    var amountInput  = document.getElementById('membership-amount-paid');
    var codeInput    = document.getElementById('admin-discount-code');
    var applyBtn     = document.getElementById('admin-apply-discount');
    var msgDiv       = document.getElementById('admin-discount-message');
    var hiddenInput  = document.getElementById('admin-discount-hidden');
    var appliedList  = document.getElementById('admin-applied-codes');

    if (!planSelect || !amountInput || !codeInput || !applyBtn) return;

    var appliedCodes = [];

    function renderCodeBadges(perCodeDetails) {
        appliedList.innerHTML = '';
        (perCodeDetails || []).forEach(function(d) {
            var badge = document.createElement('span');
            badge.className = 'inline-flex items-center gap-1 px-2 py-1 bg-green-100 text-green-800 text-xs font-medium rounded-full';
            badge.innerHTML = d.code + ' (-$' + (d.total_discount || 0).toFixed(2) + ') <button type="button" data-code="' + d.code + '" class="ml-1 text-green-600 hover:text-red-600 font-bold">&times;</button>';
            badge.querySelector('button').addEventListener('click', function() { removeCode(this.dataset.code); });
            appliedList.appendChild(badge);
        });
    }

    function removeCode(code) {
        appliedCodes = appliedCodes.filter(function(c) { return c.toUpperCase() !== code.toUpperCase(); });
        hiddenInput.value = appliedCodes.join(',');
        msgDiv.innerHTML = '';
        if (appliedCodes.length === 0) {
            appliedList.innerHTML = '';
            var opt = planSelect.options[planSelect.selectedIndex];
            if (opt && opt.value) {
                var price = parseFloat(opt.getAttribute('data-price')) || 0;
                var regfee = parseFloat(opt.getAttribute('data-regfee')) || 0;
                amountInput.value = (price + regfee).toFixed(2);
            }
            return;
        }
        revalidateAll();
    }

    function revalidateAll() {
        var opt = planSelect.options[planSelect.selectedIndex];
        if (!opt || !opt.value || appliedCodes.length === 0) return;
        var fd = new FormData();
        fd.append('code', appliedCodes[appliedCodes.length - 1]);
        fd.append('existing_codes', appliedCodes.slice(0, -1).join(','));
        fd.append('plan_id', opt.value);
        fd.append('base_amount', (parseFloat(opt.getAttribute('data-price')) || 0).toString());
        fd.append('registration_fee', (parseFloat(opt.getAttribute('data-regfee')) || 0).toString());
        fetch('ajax_validate_discount.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.valid) {
                    amountInput.value = data.total.toFixed(2);
                    renderCodeBadges(data.per_code_details || []);
                }
            }).catch(function() {});
    }

    // Auto-fill amount when plan changes
    planSelect.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        if (opt && opt.value) {
            var price = parseFloat(opt.getAttribute('data-price')) || 0;
            var regfee = parseFloat(opt.getAttribute('data-regfee')) || 0;
            amountInput.value = (price + regfee).toFixed(2);
        } else {
            amountInput.value = '';
        }
        appliedCodes = [];
        hiddenInput.value = '';
        codeInput.value = '';
        msgDiv.innerHTML = '';
        appliedList.innerHTML = '';
    });

    // Apply discount code via AJAX
    applyBtn.addEventListener('click', function() {
        var code = (codeInput.value || '').trim();
        if (!code) {
            msgDiv.innerHTML = '<span class="text-red-600">Please enter a discount code.</span>';
            return;
        }

        var opt = planSelect.options[planSelect.selectedIndex];
        if (!opt || !opt.value) {
            msgDiv.innerHTML = '<span class="text-red-600">Please select a plan first.</span>';
            return;
        }

        applyBtn.disabled = true;
        applyBtn.textContent = '...';

        var fd = new FormData();
        fd.append('code', code);
        fd.append('existing_codes', appliedCodes.join(','));
        fd.append('plan_id', opt.value);
        fd.append('base_amount', (parseFloat(opt.getAttribute('data-price')) || 0).toString());
        fd.append('registration_fee', (parseFloat(opt.getAttribute('data-regfee')) || 0).toString());

        fetch('ajax_validate_discount.php', { method: 'POST', body: fd })
            .then(function(resp) { return resp.json(); })
            .then(function(data) {
                if (data.valid) {
                    msgDiv.innerHTML = '<span class="text-green-600">' + data.message + '</span>';
                    appliedCodes = (data.all_codes || code).split(',').filter(Boolean);
                    hiddenInput.value = appliedCodes.join(',');
                    codeInput.value = '';
                    amountInput.value = data.total.toFixed(2);
                    renderCodeBadges(data.per_code_details || []);
                } else {
                    msgDiv.innerHTML = '<span class="text-red-600">' + data.error + '</span>';
                }
            })
            .catch(function() {
                msgDiv.innerHTML = '<span class="text-red-600">Error validating code.</span>';
            })
            .finally(function() {
                applyBtn.disabled = false;
                applyBtn.textContent = 'Apply';
            });
    });
})();

// ── Apply Discount to Existing Membership ──
(function() {
    var applyBtn    = document.getElementById('existing-apply-discount-btn');
    if (!applyBtn) return;

    var codeInput   = document.getElementById('existing-discount-code');
    var msgDiv      = document.getElementById('existing-discount-message');
    var hiddenInput = document.getElementById('discount_code_hidden');
    var appliedList = document.getElementById('existing-applied-codes');
    var appliedCodes = [];

    function renderCodeBadges(perCodeDetails) {
        appliedList.innerHTML = '';
        (perCodeDetails || []).forEach(function(d) {
            var badge = document.createElement('span');
            badge.className = 'inline-flex items-center gap-1 px-2 py-1 bg-green-100 text-green-800 text-xs font-medium rounded-full';
            badge.innerHTML = d.code + ' (-$' + (d.total_discount || 0).toFixed(2) + ') <button type="button" data-code="' + d.code + '" class="ml-1 text-green-600 hover:text-red-600 font-bold">&times;</button>';
            badge.querySelector('button').addEventListener('click', function() { removeCode(this.dataset.code); });
            appliedList.appendChild(badge);
        });
    }

    function removeCode(code) {
        appliedCodes = appliedCodes.filter(function(c) { return c.toUpperCase() !== code.toUpperCase(); });
        hiddenInput.value = appliedCodes.join(',');
        msgDiv.innerHTML = '';
        if (appliedCodes.length === 0) {
            appliedList.innerHTML = '';
            document.getElementById('discount-summary').classList.add('hidden');
            document.getElementById('discount-submit-btn').disabled = true;
            return;
        }
        revalidateAll();
    }

    function revalidateAll() {
        var modal  = document.getElementById('applyDiscountModal');
        var planId = document.getElementById('discount_plan_id').value;
        var price  = parseFloat(modal.dataset.planPrice) || 0;
        var regfee = parseFloat(modal.dataset.planRegfee) || 0;
        var fd = new FormData();
        fd.append('code', appliedCodes[appliedCodes.length - 1]);
        fd.append('existing_codes', appliedCodes.slice(0, -1).join(','));
        fd.append('plan_id', planId);
        fd.append('base_amount', price.toString());
        fd.append('registration_fee', regfee.toString());
        fetch('ajax_validate_discount.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.valid) {
                    document.getElementById('discount-summary').classList.remove('hidden');
                    document.getElementById('discount-new-total').textContent = '$' + data.total.toFixed(2);
                    document.getElementById('discount-submit-btn').disabled = false;
                    renderCodeBadges(data.per_code_details || []);
                }
            }).catch(function() {});
    }

    applyBtn.addEventListener('click', function() {
        var code = (codeInput.value || '').trim();
        if (!code) {
            msgDiv.innerHTML = '<span class="text-red-600">Please enter a discount code.</span>';
            return;
        }

        var modal  = document.getElementById('applyDiscountModal');
        var planId = document.getElementById('discount_plan_id').value;
        var price  = parseFloat(modal.dataset.planPrice) || 0;
        var regfee = parseFloat(modal.dataset.planRegfee) || 0;

        applyBtn.disabled = true;
        applyBtn.textContent = '...';

        var fd = new FormData();
        fd.append('code', code);
        fd.append('existing_codes', appliedCodes.join(','));
        fd.append('plan_id', planId);
        fd.append('base_amount', price.toString());
        fd.append('registration_fee', regfee.toString());

        fetch('ajax_validate_discount.php', { method: 'POST', body: fd })
            .then(function(resp) { return resp.json(); })
            .then(function(data) {
                if (data.valid) {
                    msgDiv.innerHTML = '<span class="text-green-600">' + data.message + '</span>';
                    appliedCodes = (data.all_codes || code).split(',').filter(Boolean);
                    hiddenInput.value = appliedCodes.join(',');
                    codeInput.value = '';
                    document.getElementById('discount-summary').classList.remove('hidden');
                    document.getElementById('discount-new-total').textContent = '$' + data.total.toFixed(2);
                    document.getElementById('discount-submit-btn').disabled = false;
                    renderCodeBadges(data.per_code_details || []);
                } else {
                    msgDiv.innerHTML = '<span class="text-red-600">' + data.error + '</span>';
                }
            })
            .catch(function() {
                msgDiv.innerHTML = '<span class="text-red-600">Error validating code.</span>';
            })
            .finally(function() {
                applyBtn.disabled = false;
                applyBtn.textContent = 'Apply';
            });
    });
})();
</script>
<?php include 'includes/footer.php'; ?>
