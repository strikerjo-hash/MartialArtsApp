<?php
require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';

// Check if student is logged in
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php?type=student');
    exit;
}
require_student_payment_clear();

$student_id = $_SESSION['student_id'];
$registration_id = $_GET['registration_id'] ?? 0;
$message = '';

// Get registration and event details
$stmt = $pdo->prepare("
    SELECT er.*, e.name as event_name, e.registration_fee as fee, e.event_type, e.event_date
    FROM event_registrations er
    JOIN events e ON er.event_id = e.id
    WHERE er.id = ? AND er.student_id = ?" . school_where('er') . "
");
$params = [$registration_id, $student_id];
school_param($params);
$stmt->execute($params);
$registration = $stmt->fetch();

if (!$registration) {
    header('Location: student_events.php');
    exit;
}

// Get student's default payment method
$defaultPayment = get_student_default_payment($student_id);

// Get student's credit balance
$studentCredit = get_student_credit($student_id);
$eventFee = (float) $registration['fee'];
$eventId = (int) $registration['event_id'];

// Calculate fee breakdown with optional discount
$discountCodeFromPost = trim($_POST['discount_code'] ?? '');
$feeBreakdown = calculateTotalWithFees([
    'base_amount'      => $eventFee,
    'registration_fee' => 0,
    'discount_code'    => $discountCodeFromPost,
    'event_id'         => $eventId,
]);
$totalWithFees = $feeBreakdown['total'];

$creditToApply = min($studentCredit, $totalWithFees);
$cardChargeAmount = round($totalWithFees - $creditToApply, 2);

// Handle payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    verify_csrf();
    $gateway = get_active_gateway();

    // Recalculate with submitted discount
    $submittedDiscount = trim($_POST['discount_code'] ?? '');
    $feeBreakdown = calculateTotalWithFees([
        'base_amount'      => $eventFee,
        'registration_fee' => 0,
        'discount_code'    => $submittedDiscount,
        'event_id'         => $eventId,
    ]);
    $totalWithFees = $feeBreakdown['total'];

    if ($gateway === 'none' || !is_gateway_ready()) {
        // No gateway configured — mark as pending
        $params = [$registration_id];
        $stmt = $pdo->prepare("UPDATE event_registrations SET payment_status = 'pending' WHERE id = ?" . school_where());
        school_param($params);
        $stmt->execute($params);
        $message = showAlert('Payment gateway not configured. Your registration is pending. Please contact the studio to complete payment.', 'warning');
    } elseif (!$defaultPayment) {
        $message = showAlert('No payment method on file. Please add a card in Payment Methods first.', 'error');
    } else {
        $chargeDesc = 'Event registration: ' . $registration['event_name'];
        if ($feeBreakdown['service_fee'] > 0) $chargeDesc .= ' (incl. service fee)';

        $chargeResult = charge_student($student_id, $totalWithFees, $chargeDesc);

        if ($chargeResult['success']) {
            $creditUsed = $chargeResult['credit_used'] ?? 0;
            $amountCharged = $chargeResult['amount_charged'] ?? $totalWithFees;

            // Update registration
            $params = [$registration_id];
            $stmt = $pdo->prepare("UPDATE event_registrations SET payment_status = 'paid' WHERE id = ?" . school_where());
            school_param($params);
            $stmt->execute($params);

            // Build payment notes
            $notes = $chargeDesc;
            if ($chargeResult['transaction_id']) $notes .= ' | Txn: ' . $chargeResult['transaction_id'];
            if ($creditUsed > 0) $notes .= ' | Credit applied: $' . number_format($creditUsed, 2);
            if ($feeBreakdown['discount_amount'] > 0) $notes .= ' | Discount: -$' . number_format($feeBreakdown['discount_amount'], 2) . ' (' . $feeBreakdown['discount_code'] . ')';
            if ($feeBreakdown['service_fee'] > 0) $notes .= ' | Service fee: $' . number_format($feeBreakdown['service_fee'], 2);

            // Record card payment (if any)
            if ($amountCharged > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount,
                                        payment_method, payment_date, receipt_number, notes)
                    VALUES (?, ?, 'event', ?, ?, 'credit_card', CURDATE(), ?, ?)
                ");
                $stmt->execute([current_school_id(), $student_id, $eventId, $amountCharged, generateReceiptNumber(), $notes]);
            }

            // Record credit payment portion (if credit was used)
            if ($creditUsed > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount,
                                        payment_method, payment_date, receipt_number, notes)
                    VALUES (?, ?, 'event', ?, ?, 'account_credit', CURDATE(), ?, ?)
                ");
                $stmt->execute([current_school_id(), $student_id, $eventId, $creditUsed, generateReceiptNumber(), 'Account credit applied to event: ' . $registration['event_name']]);
            }

            // Record discount code usage
            if ($feeBreakdown['discount_code_id']) {
                recordDiscountCodeUse(
                    $feeBreakdown['discount_code_id'],
                    $student_id,
                    $feeBreakdown['discount_amount'],
                    'event',
                    $registration_id
                );
            }

            header('Location: student_events.php?success=payment');
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
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Complete Event Payment</h1>

            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
                <h3 class="font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($registration['event_name']); ?></h3>
                <p class="text-sm text-gray-600 mb-1">
                    <?php echo formatDate($registration['event_date']); ?>
                </p>
                <p class="text-sm text-gray-600 capitalize mb-3">
                    <?php echo str_replace('_', ' ', $registration['event_type']); ?>
                </p>
                <div class="pt-3 border-t border-blue-200 space-y-2">
                    <!-- Fee breakdown -->
                    <div id="fee-breakdown-area">
                        <?php echo renderFeeBreakdownHtml($feeBreakdown, true, 'Event Fee'); ?>
                    </div>

                    <?php if ($creditToApply > 0): ?>
                        <div class="flex justify-between items-center text-green-700">
                            <span class="text-sm font-semibold">&#128176; Account Credit:</span>
                            <span class="font-bold">-<?php echo formatMoney($creditToApply); ?></span>
                        </div>
                        <div class="flex justify-between items-center pt-2 border-t border-blue-200">
                            <span class="text-lg font-semibold text-gray-800">Amount to Charge:</span>
                            <span class="text-3xl font-bold text-green-600"><?php echo formatMoney($cardChargeAmount); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Discount Code -->
                <div class="mt-4 pt-3 border-t border-blue-200">
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
                    <p class="text-gray-700 mb-3">
                        <strong>Online payment is not available at this time.</strong>
                    </p>
                    <p class="text-gray-700">
                        Please contact the studio to complete your payment. Your registration will be confirmed once payment is received.
                    </p>
                </div>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="process_payment" value="1">
                    <button type="submit"
                            class="w-full bg-orange-600 hover:bg-orange-700 text-white font-bold py-3 px-4 rounded-lg">
                        Confirm Registration (Payment Required at Studio)
                    </button>
                </form>
            <?php elseif ($cardChargeAmount <= 0): ?>
                <!-- Fully covered by credit -->
                <div class="bg-green-50 border border-green-200 rounded-lg p-6 mb-6">
                    <div class="flex items-center gap-3">
                        <span class="text-3xl">&#128176;</span>
                        <div>
                            <p class="font-semibold text-green-800">Fully Covered by Account Credit!</p>
                            <p class="text-sm text-green-600">Your account credit of <?php echo formatMoney($studentCredit); ?> will cover this event fee. No card charge is needed.</p>
                        </div>
                    </div>
                </div>

                <form method="POST" id="payment-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="process_payment" value="1">
                    <input type="hidden" name="discount_code" class="discount-hidden" value="<?php echo htmlspecialchars($discountCodeFromPost); ?>">
                    <button type="submit"
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-lg text-lg mb-4">
                        Confirm — Use <?php echo formatMoney($creditToApply); ?> Credit
                    </button>
                </form>

                <a href="student_events.php" class="block text-center text-blue-600 hover:text-blue-800">
                    Cancel and Return to Events
                </a>
            <?php elseif (!$defaultPayment): ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
                    <p class="text-gray-700 mb-3">
                        <strong>No payment method on file.</strong> Please add a credit or debit card before completing this payment.
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
                    <?= csrf_field() ?>
                    <input type="hidden" name="process_payment" value="1">
                    <input type="hidden" name="discount_code" class="discount-hidden" value="<?php echo htmlspecialchars($discountCodeFromPost); ?>">

                    <button type="submit" id="pay-btn"
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-lg text-lg mb-4">
                        <span id="btn-text">Pay Now - <?php echo formatMoney($cardChargeAmount); ?></span>
                        <?php if ($creditToApply > 0): ?>
                            <span class="text-sm opacity-80">(+ <?php echo formatMoney($creditToApply); ?> credit)</span>
                        <?php endif; ?>
                    </button>
                </form>

                <a href="student_events.php" class="block text-center text-blue-600 hover:text-blue-800">
                    Cancel and Return to Events
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
    var hiddenFields  = document.querySelectorAll('.discount-hidden');

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
        fd.append('event_id', '<?php echo $eventId; ?>');
        fd.append('base_amount', '<?php echo $eventFee; ?>');
        fd.append('registration_fee', '0');

        try {
            var resp = await fetch('ajax_validate_discount.php', { method: 'POST', body: fd });
            var data = await resp.json();

            if (data.valid) {
                discountMsg.innerHTML = '<span class="text-green-600">' + data.message + '</span>';
                hiddenFields.forEach(function(f) { f.value = code; });
                if (btnText) btnText.textContent = 'Pay Now - $' + parseFloat(data.total).toFixed(2);

                var html = '<div class="space-y-2 text-sm">';
                html += '<div class="flex justify-between"><span class="text-gray-600">Event Fee:</span><span class="font-semibold">$' + parseFloat(<?php echo json_encode($eventFee); ?>).toFixed(2) + '</span></div>';
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
