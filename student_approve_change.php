<?php
/**
 * student_approve_change.php — Payment confirmation for admin-proposed plan upgrades
 *
 * When a student approves an admin-proposed plan change that is an upgrade,
 * they are redirected here to review the live proration and confirm payment
 * before the change takes effect.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';

// Check if student is logged in
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php?type=student');
    exit;
}
require_student_payment_clear();

if (!isset($_SESSION['approve_change_id']) || !isset($_SESSION['approve_proration'])) {
    header('Location: student_portal.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$changeId = (int) $_SESSION['approve_change_id'];
$proration = $_SESSION['approve_proration'];

// Ensure all proration keys exist with defaults
$proration = array_merge([
    'amount' => 0,
    'type' => 'full',
    'credit' => 0,
    'unused_value' => 0,
    'new_cost' => 0,
    'days_remaining' => 0,
    'is_monthly' => false
], $proration);

// Fetch the pending change (verify it's still valid)
$params = [$changeId, $student_id];
$sql = "SELECT pc.*, mp_new.name as new_plan_name, mp_new.price as new_plan_price,
           mp_new.duration_months as new_duration, mp_new.billing_frequency as new_billing_frequency,
           mp_old.name as old_plan_name,
           u.full_name as requested_by_name
    FROM pending_plan_changes pc
    JOIN membership_plans mp_new ON pc.new_plan_id = mp_new.id
    LEFT JOIN membership_plans mp_old ON pc.old_plan_id = mp_old.id
    LEFT JOIN users u ON pc.requested_by = u.id
    WHERE pc.id = ? AND pc.student_id = ? AND pc.status = 'pending' AND pc.expires_at > NOW()" . school_where('pc');
school_param($params);
$pcStmt = $pdo->prepare($sql);
$pcStmt->execute($params);
$pc = $pcStmt->fetch();

if (!$pc) {
    unset($_SESSION['approve_change_id']);
    unset($_SESSION['approve_proration']);
    header('Location: student_portal.php');
    exit;
}

// Get current membership
$params = [$student_id];
$sql = "SELECT m.*, mp.name as plan_name, mp.price as plan_price, mp.duration_months, mp.billing_frequency
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.student_id = ? AND m.status = 'active' AND m.end_date >= CURDATE()" . school_where('m') . "
    ORDER BY m.end_date DESC LIMIT 1";
school_param($params);
$current_membership = $pdo->prepare($sql);
$current_membership->execute($params);
$current_membership = $current_membership->fetch() ?: null;

// Recalculate proration live for the most up-to-date numbers
$newPlan = [
    'price' => $pc['new_plan_price'],
    'duration_months' => $pc['new_duration'],
    'billing_frequency' => $pc['new_billing_frequency'] ?? 'upfront',
];
$proration = calculateProration($current_membership, $newPlan);

// Calculate fee breakdown (no registration fee, no discount for admin-initiated changes)
$feeBreakdown = calculateTotalWithFees([
    'base_amount'      => (float) $proration['amount'],
    'registration_fee' => 0,
    'discount_code'    => '',
    'plan_id'          => (int) $pc['new_plan_id'],
]);
$totalWithFees = $feeBreakdown['total'];

// Get student's default payment method
$defaultPayment = get_student_default_payment($student_id);

// Get student's credit balance
$studentCredit = get_student_credit($student_id);
$creditToApply = min($studentCredit, $totalWithFees);
$cardChargeAmount = round($totalWithFees - $creditToApply, 2);

$message = '';

// Handle payment confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    verify_csrf();
    $gateway = get_active_gateway();

    // Recalculate fees
    $feeBreakdown = calculateTotalWithFees([
        'base_amount'      => (float) $proration['amount'],
        'registration_fee' => 0,
        'discount_code'    => '',
        'plan_id'          => (int) $pc['new_plan_id'],
    ]);
    $totalWithFees = $feeBreakdown['total'];

    if ($proration['amount'] <= 0) {
        // Edge case: proration recalculated to zero or downgrade — process as downgrade
        if ($current_membership) {
            $params = [$current_membership['id']];
            $sql = "UPDATE memberships SET status = 'cancelled', end_date = CURDATE() WHERE id = ?" . school_where();
            school_param($params);
            $pdo->prepare($sql)->execute($params);
        }

        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $pc['new_duration'] . ' months'));
        $isMonthly = (($pc['new_billing_frequency'] ?? 'upfront') === 'monthly' && $pc['new_duration'] > 1);
        $billing_day = $isMonthly ? min((int) date('j'), 28) : null;

        $pdo->prepare("
            INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date, status, payment_status, amount_paid, billing_day, monthly_charges_made)
            VALUES (?, ?, ?, ?, ?, 'active', 'paid', 0, ?, 0)
        ")->execute([current_school_id(), $student_id, $pc['new_plan_id'], $start_date, $end_date, $billing_day]);

        if ($proration['credit'] > 0) {
            add_student_credit($student_id, $proration['credit'], 'Plan change credit: ' . ($current_membership['plan_name'] ?? 'None') . ' to ' . $pc['new_plan_name'], 'downgrade');
        }

        $params = [$changeId]; $sql = "UPDATE pending_plan_changes SET status = 'approved', resolved_at = NOW() WHERE id = ?" . school_where(); school_param($params);
        $pdo->prepare($sql)->execute($params);
        $params = [$student_id]; $sql = "UPDATE students SET last_plan_change = CURDATE() WHERE id = ?" . school_where(); school_param($params);
        $pdo->prepare($sql)->execute($params);

        unset($_SESSION['approve_change_id']);
        unset($_SESSION['approve_proration']);
        header('Location: student_portal.php?success=plan_change');
        exit;
    }

    if ($gateway === 'none' || !is_gateway_ready()) {
        $message = showAlert('Payment gateway not configured. Please contact the studio.', 'error');
    } elseif ($cardChargeAmount > 0 && !$defaultPayment) {
        $message = showAlert('No payment method on file. Please add a card in Payment Methods first.', 'error');
    } else {
        $chargeDesc = 'Plan change (admin proposed): ' . ($current_membership['plan_name'] ?? 'None') . ' to ' . $pc['new_plan_name'];
        if ($feeBreakdown['service_fee'] > 0) {
            $chargeDesc .= ' (incl. service fee)';
        }

        $chargeResult = charge_student(
            $student_id,
            $totalWithFees,
            $chargeDesc
        );

        if ($chargeResult['success']) {
            $creditUsed = $chargeResult['credit_used'] ?? 0;
            $amountCharged = $chargeResult['amount_charged'] ?? $totalWithFees;

            // Cancel current membership
            if ($current_membership) {
                $params = [$current_membership['id']];
                $sql = "UPDATE memberships SET status = 'cancelled', end_date = CURDATE() WHERE id = ?" . school_where();
                school_param($params);
                $pdo->prepare($sql)->execute($params);
            }

            // Create new membership
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $pc['new_duration'] . ' months'));
            $isMonthly = (($pc['new_billing_frequency'] ?? 'upfront') === 'monthly' && $pc['new_duration'] > 1);
            $billing_day = $isMonthly ? min((int) date('j'), 28) : null;
            $monthly_charges = $isMonthly ? 1 : 0;

            $pdo->prepare("
                INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date, status, payment_status, amount_paid, billing_day, monthly_charges_made)
                VALUES (?, ?, ?, ?, ?, 'active', 'paid', ?, ?, ?)
            ")->execute([current_school_id(), $student_id, $pc['new_plan_id'], $start_date, $end_date, $totalWithFees, $billing_day, $monthly_charges]);
            $newMembershipId = $pdo->lastInsertId();

            // Build payment notes
            $payNotes = $chargeDesc;
            if (!empty($chargeResult['transaction_id'])) $payNotes .= ' | Txn: ' . $chargeResult['transaction_id'];
            if ($creditUsed > 0) $payNotes .= ' | Credit applied: $' . number_format($creditUsed, 2);
            if ($feeBreakdown['service_fee'] > 0) $payNotes .= ' | Service fee: $' . number_format($feeBreakdown['service_fee'], 2);

            // Record card payment
            if ($amountCharged > 0) {
                $pdo->prepare("
                    INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount, payment_method, payment_date, receipt_number, notes)
                    VALUES (?, ?, 'membership', ?, ?, 'credit_card', CURDATE(), ?, ?)
                ")->execute([current_school_id(), $student_id, $newMembershipId, $amountCharged, generateReceiptNumber(), $payNotes]);
            }

            // Record credit payment
            if ($creditUsed > 0) {
                $pdo->prepare("
                    INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount, payment_method, payment_date, receipt_number, notes)
                    VALUES (?, ?, 'membership', ?, ?, 'account_credit', CURDATE(), ?, ?)
                ")->execute([
                    current_school_id(), $student_id, $newMembershipId, $creditUsed, generateReceiptNumber(),
                    'Account credit applied to plan change: ' . ($current_membership['plan_name'] ?? 'None') . ' to ' . $pc['new_plan_name']
                ]);
            }

            // Mark pending change as approved
            $params = [$changeId]; $sql = "UPDATE pending_plan_changes SET status = 'approved', resolved_at = NOW() WHERE id = ?" . school_where(); school_param($params);
            $pdo->prepare($sql)->execute($params);

            // Record plan change date for lockout
            $params = [$student_id]; $sql = "UPDATE students SET last_plan_change = CURDATE() WHERE id = ?" . school_where(); school_param($params);
            $pdo->prepare($sql)->execute($params);

            // Clear session
            unset($_SESSION['approve_change_id']);
            unset($_SESSION['approve_proration']);

            header('Location: student_portal.php?success=plan_change');
            exit;
        } else {
            $message = showAlert('Payment failed: ' . ($chargeResult['error'] ?? 'Unknown error') . '. Please try again or update your card.', 'error');
        }
    }
}

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <?php echo $message; ?>

        <div class="bg-white rounded-lg shadow-lg p-8">
            <h1 class="text-2xl font-bold text-gray-800 mb-2">Confirm Plan Change</h1>
            <p class="text-sm text-gray-500 mb-6">Proposed by <?php echo htmlspecialchars($pc['requested_by_name'] ?? 'Studio Admin'); ?></p>

            <!-- Plan comparison -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <p class="text-sm text-gray-600">Current Plan</p>
                        <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($current_membership['plan_name'] ?? 'No membership'); ?></p>
                    </div>
                    <span class="text-2xl">&#8594;</span>
                    <div>
                        <p class="text-sm text-gray-600">New Plan</p>
                        <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($pc['new_plan_name']); ?></p>
                        <?php if (($pc['new_billing_frequency'] ?? 'upfront') === 'monthly' && $pc['new_duration'] > 1): ?>
                            <p class="text-xs text-blue-600"><?php echo formatMoney($pc['new_plan_price'] / $pc['new_duration']); ?>/mo for <?php echo $pc['new_duration']; ?> months</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pt-4 border-t border-blue-200 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Days Remaining on Current Plan:</span>
                        <span class="font-semibold"><?php echo $proration['days_remaining']; ?> days</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Amount Paid on Current Plan:</span>
                        <span class="font-semibold"><?php echo formatMoney($proration['amount_actually_paid'] ?? $proration['unused_value']); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Unused Credit from Current Plan:</span>
                        <span class="font-semibold text-green-700">-<?php echo formatMoney($proration['unused_value']); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600"><?php echo ($proration['is_monthly'] ?? false) ? 'First Monthly Installment:' : 'New Plan Cost (pro-rated):'; ?></span>
                        <span class="font-semibold"><?php echo formatMoney($proration['new_cost']); ?></span>
                    </div>
                    <?php if ($proration['amount'] > 0): ?>
                    <!-- Fee breakdown -->
                    <?php echo renderFeeBreakdownHtml($feeBreakdown, true, ($proration['is_monthly'] ?? false) ? 'First Monthly Installment' : 'Amount Due'); ?>
                    <?php else: ?>
                    <div class="flex justify-between pt-2 border-t border-blue-200">
                        <span class="text-lg font-semibold text-gray-800">Credit to Your Account:</span>
                        <span class="text-lg font-bold text-green-600"><?php echo formatMoney($proration['credit']); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($creditToApply > 0): ?>
                        <div class="flex justify-between text-green-700">
                            <span class="font-semibold">&#128176; Account Credit Applied:</span>
                            <span class="font-bold">-<?php echo formatMoney($creditToApply); ?></span>
                        </div>
                        <div class="flex justify-between pt-2 border-t border-blue-200">
                            <span class="text-lg font-semibold text-gray-800">Amount to Charge:</span>
                            <span class="text-2xl font-bold text-green-600"><?php echo formatMoney($cardChargeAmount); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($pc['notes'])): ?>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6 text-sm">
                    <p class="text-gray-500 text-xs mb-1">Admin Notes:</p>
                    <p class="text-gray-700 italic">"<?php echo htmlspecialchars($pc['notes']); ?>"</p>
                </div>
            <?php endif; ?>

            <?php
            $gateway = get_active_gateway();
            if ($gateway === 'none' || !is_gateway_ready()):
            ?>
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-6 mb-6">
                    <p class="text-gray-700">
                        Online payment is not available. Please contact the studio to complete this plan change.
                    </p>
                </div>
                <a href="student_portal.php" class="block w-full text-center bg-gray-600 hover:bg-gray-700 text-white font-bold py-3 px-4 rounded-lg">
                    Back to Dashboard
                </a>
            <?php elseif ($cardChargeAmount <= 0): ?>
                <!-- Fully covered by credit -->
                <div class="bg-green-50 border border-green-200 rounded-lg p-6 mb-6">
                    <div class="flex items-center gap-3">
                        <span class="text-3xl">&#128176;</span>
                        <div>
                            <p class="font-semibold text-green-800">Fully Covered by Account Credit!</p>
                            <p class="text-sm text-green-600">Your account credit of <?php echo formatMoney($studentCredit); ?> will cover this upgrade. No card charge is needed.</p>
                        </div>
                    </div>
                </div>

                <form method="POST" id="payment-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirm_payment" value="1">
                    <button type="submit"
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-lg text-lg mb-4">
                        Confirm Upgrade — Use <?php echo formatMoney($creditToApply); ?> Credit
                    </button>
                </form>

                <a href="student_portal.php" class="block text-center text-blue-600 hover:text-blue-800">
                    Cancel and Return
                </a>
            <?php elseif (!$defaultPayment): ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
                    <p class="text-gray-700 mb-3">
                        <strong>No payment method on file.</strong> Please add a credit or debit card before completing this upgrade.
                    </p>
                    <a href="student_payment.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">
                        Add Payment Method
                    </a>
                </div>
                <a href="student_portal.php" class="block text-center text-gray-600 hover:text-gray-800 mt-4">
                    Cancel and Return
                </a>
            <?php else: ?>
                <!-- Payment method summary -->
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-7 bg-gray-200 rounded flex items-center justify-center text-xs font-bold uppercase text-gray-600">
                                <?= htmlspecialchars($defaultPayment['brand']) ?>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-700">
                                    <span class="tracking-widest">&bull;&bull;&bull;&bull;</span>
                                    <span class="font-mono ml-1"><?= htmlspecialchars($defaultPayment['last_four']) ?></span>
                                    <?php if ($defaultPayment['exp']): ?>
                                        <span class="ml-2 text-gray-400">Exp <?= $defaultPayment['exp'] ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <a href="student_payment.php" class="text-sm text-blue-600 hover:underline">Change</a>
                    </div>
                </div>

                <?php if ($creditToApply > 0): ?>
                    <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-4 text-sm flex items-center gap-2">
                        <span>&#128176;</span>
                        <span class="text-green-700">
                            <strong><?php echo formatMoney($creditToApply); ?></strong> account credit will be applied.
                            Remaining <strong><?php echo formatMoney($cardChargeAmount); ?></strong> will be charged to your card.
                        </span>
                    </div>
                <?php endif; ?>

                <form method="POST" id="payment-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirm_payment" value="1">

                    <button type="submit"
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-lg text-lg mb-4">
                        Pay Now - <?php echo formatMoney($cardChargeAmount); ?>
                        <?php if ($creditToApply > 0): ?>
                            <span class="text-sm opacity-80">(+ <?php echo formatMoney($creditToApply); ?> credit)</span>
                        <?php endif; ?>
                    </button>
                </form>

                <a href="student_portal.php" class="block text-center text-blue-600 hover:text-blue-800">
                    Cancel and Return
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/student_footer.php'; ?>
