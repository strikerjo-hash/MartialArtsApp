<?php
require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';

// Check if student is logged in
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php?type=student');
    exit;
}
require_student_payment_clear();

if (!isset($_SESSION['upgrade_plan_id']) || !isset($_SESSION['upgrade_proration'])) {
    header('Location: student_upgrade.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$new_plan_id = $_SESSION['upgrade_plan_id'];

// Get new plan details
$new_plan = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?");
$new_plan->execute([$new_plan_id]);
$new_plan = $new_plan->fetch();

if (!$new_plan) {
    unset($_SESSION['upgrade_plan_id'], $_SESSION['upgrade_proration']);
    header('Location: student_upgrade.php');
    exit;
}

// Get current membership (may be null for first-time enrollment)
$current_membership = $pdo->prepare("
    SELECT m.*, mp.name as plan_name, mp.price as plan_price, mp.duration_months, mp.billing_frequency
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.student_id = ? AND m.status = 'active' AND m.end_date >= CURDATE()
    ORDER BY m.end_date DESC
    LIMIT 1
");
$current_membership->execute([$student_id]);
$current_membership = $current_membership->fetch() ?: null;

// ALWAYS recalculate proration fresh — never rely solely on stale session data
$proration = calculateProration($current_membership, $new_plan);

// Ensure all proration keys exist with defaults
$proration = array_merge([
    'amount' => 0,
    'type' => 'full',
    'credit' => 0,
    'unused_value' => 0,
    'new_cost' => 0,
    'days_remaining' => 0,
    'is_monthly' => false,
], $proration);

// Calculate fee breakdown (no registration fee for upgrades)
$discountCodeFromPost = trim($_POST['discount_code'] ?? '');
$feeBreakdown = calculateTotalWithFees([
    'base_amount'      => (float) $proration['amount'],
    'registration_fee' => 0,
    'discount_code'    => $discountCodeFromPost,
    'plan_id'          => $new_plan_id,
]);

// Get student's default payment method
$defaultPayment = get_student_default_payment($student_id);

// Get student's credit balance
$studentCredit = get_student_credit($student_id);
$totalWithFees = $feeBreakdown['total'];
$creditToApply = min($studentCredit, $totalWithFees);
$cardChargeAmount = round($totalWithFees - $creditToApply, 2);

$message = '';

// Handle payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $gateway = get_active_gateway();

    // Recalculate with submitted discount code
    $submittedDiscount = trim($_POST['discount_code'] ?? '');
    $feeBreakdown = calculateTotalWithFees([
        'base_amount'      => (float) $proration['amount'],
        'registration_fee' => 0,
        'discount_code'    => $submittedDiscount,
        'plan_id'          => $new_plan_id,
    ]);
    $totalWithFees = $feeBreakdown['total'];

    if ($gateway === 'none' || !is_gateway_ready()) {
        $message = showAlert('Payment gateway not configured. Please contact the studio.', 'error');
    } elseif (!$defaultPayment) {
        $message = showAlert('No payment method on file. Please add a card in Payment Methods first.', 'error');
    } else {
        $chargeDesc = $current_membership
            ? 'Membership upgrade: ' . $current_membership['plan_name'] . ' to ' . $new_plan['name']
            : 'New membership enrollment: ' . $new_plan['name'];
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
                $stmt = $pdo->prepare("UPDATE memberships SET status = 'cancelled', end_date = CURDATE() WHERE id = ?");
                $stmt->execute([$current_membership['id']]);
            }

            // Create new membership
            $start_date = date('Y-m-d');

            // Afterschool plans: use fixed program end date, no auto-renewal
            if (!empty($new_plan['is_afterschool'])) {
                $end_date   = $new_plan['program_end_date'];
                $auto_renew = 0;
            } else {
                $end_date   = date('Y-m-d', strtotime($start_date . ' + ' . $new_plan['duration_months'] . ' months'));
                $auto_renew = 1;
            }

            // For monthly plans, set billing_day and monthly_charges_made
            $isMonthlyPlan = (isset($new_plan['billing_frequency']) && $new_plan['billing_frequency'] === 'monthly' && $new_plan['duration_months'] > 1);
            $billing_day = $isMonthlyPlan ? min((int) date('j'), 28) : null;
            $monthly_charges = $isMonthlyPlan ? 1 : 0;

            $stmt = $pdo->prepare("
                INSERT INTO memberships (student_id, plan_id, start_date, end_date, status, payment_status, amount_paid, auto_renew, billing_day, monthly_charges_made)
                VALUES (?, ?, ?, ?, 'active', 'paid', ?, ?, ?, ?)
            ");
            $stmt->execute([$student_id, $new_plan_id, $start_date, $end_date, $totalWithFees, $auto_renew, $billing_day, $monthly_charges]);
            $membership_id = $pdo->lastInsertId();

            // Build payment notes
            $notes = $chargeDesc;
            if ($chargeResult['transaction_id']) $notes .= ' | Txn: ' . $chargeResult['transaction_id'];
            if ($creditUsed > 0) $notes .= ' | Credit applied: $' . number_format($creditUsed, 2);
            if ($feeBreakdown['discount_amount'] > 0) $notes .= ' | Discount: -$' . number_format($feeBreakdown['discount_amount'], 2) . ' (' . $feeBreakdown['discount_code'] . ')';
            if ($feeBreakdown['service_fee'] > 0) $notes .= ' | Service fee: $' . number_format($feeBreakdown['service_fee'], 2);

            // Record card payment (if any amount was charged to card)
            if ($amountCharged > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (student_id, payment_type, reference_id, amount,
                                        payment_method, payment_date, receipt_number, notes)
                    VALUES (?, 'membership', ?, ?, 'credit_card', CURDATE(), ?, ?)
                ");
                $stmt->execute([$student_id, $membership_id, $amountCharged, generateReceiptNumber(), $notes]);
            }

            // Record credit payment portion (if credit was used)
            if ($creditUsed > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (student_id, payment_type, reference_id, amount,
                                        payment_method, payment_date, receipt_number, notes)
                    VALUES (?, 'membership', ?, ?, 'account_credit', CURDATE(), ?, ?)
                ");
                $stmt->execute([$student_id, $membership_id, $creditUsed, generateReceiptNumber(), 'Account credit applied: ' . $chargeDesc]);
            }

            // Record discount code usage
            if ($feeBreakdown['discount_code_id']) {
                recordDiscountCodeUse(
                    $feeBreakdown['discount_code_id'],
                    $student_id,
                    $feeBreakdown['discount_amount'],
                    'upgrade',
                    $membership_id
                );
            }

            // Record plan change date for lockout
            $pdo->prepare("UPDATE students SET last_plan_change = CURDATE() WHERE id = ?")->execute([$student_id]);

            // Clear session
            unset($_SESSION['upgrade_plan_id']);
            unset($_SESSION['upgrade_proration']);

            header('Location: student_upgrade.php?success=upgrade');
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
            <h1 class="text-2xl font-bold text-gray-800 mb-6">
                <?php echo $current_membership ? 'Complete Membership Upgrade' : 'Complete Membership Enrollment'; ?>
            </h1>

            <?php
            $isMonthlyPlan = (isset($new_plan['billing_frequency']) && $new_plan['billing_frequency'] === 'monthly' && $new_plan['duration_months'] > 1);
            $isFirstEnrollment = !$current_membership;
            ?>

            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
                <?php if (!$isFirstEnrollment): ?>
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <p class="text-sm text-gray-600">Current Plan</p>
                        <p class="font-semibold text-gray-800"><?php echo $current_membership['plan_name']; ?></p>
                    </div>
                    <span class="text-2xl">&#8594;</span>
                    <div>
                        <p class="text-sm text-gray-600">New Plan</p>
                        <p class="font-semibold text-gray-800"><?php echo $new_plan['name']; ?></p>
                        <?php if ($isMonthlyPlan): ?>
                            <p class="text-xs text-blue-600"><?php echo formatMoney($new_plan['price'] / $new_plan['duration_months']); ?>/mo for <?php echo $new_plan['duration_months']; ?> months</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="mb-4">
                    <p class="font-semibold text-gray-800 text-lg"><?php echo $new_plan['name']; ?></p>
                    <?php if ($isMonthlyPlan): ?>
                        <p class="text-sm text-blue-600"><?php echo formatMoney($new_plan['price'] / $new_plan['duration_months']); ?>/mo for <?php echo $new_plan['duration_months']; ?> months (<?php echo formatMoney($new_plan['price']); ?> total)</p>
                    <?php else: ?>
                        <p class="text-sm text-gray-600"><?php echo formatMoney($new_plan['price']); ?> &middot; <?php echo $new_plan['duration_months']; ?> month<?php echo $new_plan['duration_months'] > 1 ? 's' : ''; ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($new_plan['is_afterschool']) && $new_plan['program_start_date'] && $new_plan['program_end_date']): ?>
                <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3 mb-4 text-sm">
                    <span class="inline-block bg-indigo-100 text-indigo-800 text-xs font-semibold px-2 py-0.5 rounded-full mb-1">Afterschool Program</span>
                    <p class="text-indigo-700">&#128197; <?php echo date('M j, Y', strtotime($new_plan['program_start_date'])); ?> &ndash; <?php echo date('M j, Y', strtotime($new_plan['program_end_date'])); ?></p>
                    <p class="text-xs text-indigo-600 mt-1">Prorated from today. No auto-renewal.</p>
                </div>
                <?php endif; ?>

                <div class="pt-4 border-t border-blue-200 space-y-2 text-sm">
                    <?php if (!$isFirstEnrollment): ?>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Days Remaining on Current Plan:</span>
                        <span class="font-semibold"><?php echo $proration['days_remaining']; ?> days</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Unused Credit from Current Plan:</span>
                        <span class="font-semibold"><?php echo formatMoney($proration['unused_value']); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600"><?php echo $isMonthlyPlan ? 'First Monthly Installment:' : 'New Plan Cost (pro-rated):'; ?></span>
                        <span class="font-semibold"><?php echo formatMoney($proration['new_cost']); ?></span>
                    </div>
                    <?php endif; ?>

                    <!-- Fee breakdown -->
                    <div id="fee-breakdown-area">
                        <?php echo renderFeeBreakdownHtml($feeBreakdown, true, $isFirstEnrollment ? ($isMonthlyPlan ? 'First Monthly Payment' : 'Amount Due') : 'Subtotal'); ?>
                    </div>

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

                <!-- Discount Code -->
                <div class="mt-4 pt-4 border-t border-blue-200">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Discount Code</label>
                    <div class="flex gap-2">
                        <input type="text" id="discount-input" placeholder="Enter code" value="<?php echo htmlspecialchars($discountCodeFromPost); ?>"
                               class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-blue-500">
                        <button type="button" id="apply-discount-btn"
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm">Apply</button>
                    </div>
                    <div id="discount-message" class="text-xs mt-1"></div>
                </div>
            </div>

            <?php
            $gateway = get_active_gateway();
            if ($gateway === 'none' || !is_gateway_ready()):
            ?>
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-6 mb-6">
                    <p class="text-gray-700">
                        Online payment is not available. Please contact the studio to complete your membership upgrade.
                    </p>
                </div>
                <a href="student_upgrade.php" class="block w-full text-center bg-gray-600 hover:bg-gray-700 text-white font-bold py-3 px-4 rounded-lg">
                    Back to Plans
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
                    <input type="hidden" name="process_payment" value="1">
                    <input type="hidden" name="discount_code" id="discount_code_hidden_credit" value="<?php echo htmlspecialchars($discountCodeFromPost); ?>">
                    <button type="submit"
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-lg text-lg mb-4">
                        Confirm Upgrade — Use <?php echo formatMoney($creditToApply); ?> Credit
                    </button>
                </form>

                <a href="student_upgrade.php" class="block text-center text-blue-600 hover:text-blue-800">
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
                    <input type="hidden" name="process_payment" value="1">
                    <input type="hidden" name="discount_code" id="discount_code_hidden" value="<?php echo htmlspecialchars($discountCodeFromPost); ?>">

                    <button type="submit" id="pay-btn"
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-lg text-lg mb-4">
                        <span id="btn-text">Pay Now - <?php echo formatMoney($cardChargeAmount); ?></span>
                        <?php if ($creditToApply > 0): ?>
                            <span class="text-sm opacity-80">(+ <?php echo formatMoney($creditToApply); ?> credit)</span>
                        <?php endif; ?>
                    </button>
                </form>

                <a href="student_upgrade.php" class="block text-center text-blue-600 hover:text-blue-800">
                    Cancel and Return
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    var applyBtn = document.getElementById('apply-discount-btn');
    if (!applyBtn) return;

    var discountInput = document.getElementById('discount-input');
    var discountMsg   = document.getElementById('discount-message');
    var breakdownArea = document.getElementById('fee-breakdown-area');
    var btnText       = document.getElementById('btn-text');

    // Find any discount_code hidden fields
    var hiddenFields = document.querySelectorAll('input[name="discount_code"]');

    applyBtn.addEventListener('click', async function() {
        var code = (discountInput.value || '').trim();
        if (!code) {
            discountMsg.innerHTML = '<span class="text-red-600">Please enter a code.</span>';
            return;
        }
        applyBtn.disabled = true;
        applyBtn.textContent = '...';

        var fd = new FormData();
        fd.append('code', code);
        fd.append('plan_id', '<?php echo $new_plan_id; ?>');
        fd.append('base_amount', '<?php echo $proration["amount"]; ?>');
        fd.append('registration_fee', '0');

        try {
            var resp = await fetch('ajax_validate_discount.php', { method: 'POST', body: fd });
            var data = await resp.json();

            if (data.valid) {
                discountMsg.innerHTML = '<span class="text-green-600">' + data.message + '</span>';
                hiddenFields.forEach(function(f) { f.value = code; });
                if (btnText) btnText.textContent = 'Pay Now - $' + parseFloat(data.total).toFixed(2);

                // Rebuild breakdown
                var html = '<div class="space-y-2 text-sm">';
                html += '<div class="flex justify-between"><span class="text-gray-600">Base Amount:</span><span class="font-semibold">$' + parseFloat(<?php echo json_encode($proration['amount']); ?>).toFixed(2) + '</span></div>';
                if (data.total_discount > 0) {
                    html += '<div class="flex justify-between"><span class="text-green-600 font-semibold">Discount (' + code + '):</span><span class="text-green-600 font-semibold">-$' + data.total_discount.toFixed(2) + '</span></div>';
                }
                if (data.service_fee > 0) {
                    html += '<div class="flex justify-between"><span class="text-gray-600">Service Fee (<?php echo number_format($feeBreakdown["service_fee_percentage"], 2); ?>%):</span><span class="font-semibold">$' + data.service_fee.toFixed(2) + '</span></div>';
                }
                html += '<div class="flex justify-between pt-2 border-t border-blue-200"><span class="text-lg font-semibold text-gray-800">Total Due:</span><span class="text-xl font-bold text-green-600">$' + data.total.toFixed(2) + '</span></div>';
                html += '</div>';
                if (breakdownArea) breakdownArea.innerHTML = html;
            } else {
                discountMsg.innerHTML = '<span class="text-red-600">' + data.error + '</span>';
                hiddenFields.forEach(function(f) { f.value = ''; });
            }
        } catch (err) {
            discountMsg.innerHTML = '<span class="text-red-600">Error validating code.</span>';
        }
        applyBtn.disabled = false;
        applyBtn.textContent = 'Apply';
    });
})();
</script>

<?php include 'includes/student_footer.php'; ?>
