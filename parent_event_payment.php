<?php
/**
 * parent_event_payment.php — Process Event Registration Payments for Children
 *
 * Accepts one or more registration IDs (comma-separated) via ?registration_ids=
 * and charges the parent's card for the total event fees.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/parent_auth.php';
require_once __DIR__ . '/includes/payment_gateway.php';

require_parent();

$parentId = get_effective_parent_id();
$pdo = get_db();
$message = '';

// ---------------------------------------------------------------------------
// 1. Parse & validate registration IDs
// ---------------------------------------------------------------------------
$rawIds = $_GET['registration_ids'] ?? '';
$registrationIds = array_unique(array_filter(array_map('intval', explode(',', $rawIds))));

if (empty($registrationIds)) {
    header('Location: parent_events.php');
    exit;
}

// Fetch children linked to this parent
$children = get_parent_children($parentId);
$childIds = array_column($children, 'id');

if (empty($childIds)) {
    header('Location: parent_events.php');
    exit;
}

// Fetch the registrations — only those belonging to this parent's children and still pending
$placeholdersReg = implode(',', array_fill(0, count($registrationIds), '?'));
$placeholdersCh  = implode(',', array_fill(0, count($childIds), '?'));

$regParams = array_merge($registrationIds, $childIds);
$regStmt = $pdo->prepare("
    SELECT er.*, e.name AS event_name, e.registration_fee AS fee, e.event_type, e.event_date, e.id AS eid,
           s.first_name, s.last_name
    FROM event_registrations er
    JOIN events e ON er.event_id = e.id
    JOIN students s ON s.id = er.student_id
    WHERE er.id IN ({$placeholdersReg})
      AND er.student_id IN ({$placeholdersCh})
      AND er.payment_status = 'pending'" . school_where('er') . "
");
school_param($regParams);
$regStmt->execute($regParams);
$registrations = $regStmt->fetchAll();

if (empty($registrations)) {
    // Nothing to pay — maybe already paid
    header('Location: parent_events.php?success=payment');
    exit;
}

// ---------------------------------------------------------------------------
// 2. Calculate totals
// ---------------------------------------------------------------------------
$totalBaseFee = 0;
foreach ($registrations as $reg) {
    $totalBaseFee += (float)$reg['fee'];
}

// Use the first event's id for discount code validation (simple approach)
$firstEventId = (int)$registrations[0]['eid'];

$discountCodeFromPost = trim($_POST['discount_code'] ?? '');
$feeBreakdown = calculateTotalWithFees([
    'base_amount'      => $totalBaseFee,
    'registration_fee' => 0,
    'discount_code'    => $discountCodeFromPost,
    'event_id'         => $firstEventId,
]);
$totalWithFees = $feeBreakdown['total'];

// Parent's payment method and credit
$defaultPayment = get_parent_default_payment($parentId, $childIds);
$isStudentParent = (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'student' && !empty($_SESSION['is_parent']));
$parentCredit   = $isStudentParent ? get_student_credit($parentId) : 0;
$creditToApply  = min($parentCredit, $totalWithFees);
$cardChargeAmount = round($totalWithFees - $creditToApply, 2);
$stripePk = (get_active_gateway() === 'stripe') ? getSetting('stripe_publishable_key') : '';

// ---------------------------------------------------------------------------
// 3. Handle payment POST
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    verify_csrf();
    $gateway = get_active_gateway();

    // Recalculate with submitted discount
    $submittedDiscount = trim($_POST['discount_code'] ?? '');
    $feeBreakdown = calculateTotalWithFees([
        'base_amount'      => $totalBaseFee,
        'registration_fee' => 0,
        'discount_code'    => $submittedDiscount,
        'event_id'         => $firstEventId,
    ]);
    $totalWithFees    = $feeBreakdown['total'];
    $creditToApply    = min($parentCredit, $totalWithFees);
    $cardChargeAmount = round($totalWithFees - $creditToApply, 2);

    if ($gateway === 'none' || !is_gateway_ready()) {
        // No gateway — leave as pending with a message
        $message = showAlert('Payment gateway not configured. Registrations are pending — please contact the studio to complete payment.', 'warning');
    } else {
        // Build a human-readable description
        $childNames = [];
        foreach ($registrations as $reg) {
            $childNames[] = $reg['first_name'];
        }
        $eventName   = $registrations[0]['event_name'];
        $chargeDesc  = 'Event registration: ' . $eventName . ' — ' . implode(', ', $childNames);
        if ($feeBreakdown['service_fee'] > 0) {
            $chargeDesc .= ' (incl. service fee)';
        }

        $walletPmId = trim($_POST['wallet_pm_id'] ?? '');
        $chargeResult = null;

        if (!empty($walletPmId)) {
            $chargeResult = charge_wallet_token($parentId, $walletPmId, $totalWithFees, $chargeDesc);
        } elseif ($cardChargeAmount > 0 && !$defaultPayment) {
            $message = showAlert('No payment method on file. Please add a card in Payment Methods first.', 'error');
        } else {
            $chargeResult = charge_parent($parentId, $totalWithFees, $chargeDesc, $childIds);
        }

        if ($chargeResult && $chargeResult['success']) {
            $creditUsed    = $chargeResult['credit_used'] ?? 0;
            $amountCharged = $chargeResult['amount_charged'] ?? $totalWithFees;

            // Mark all registrations as paid
            $updateStmt = $pdo->prepare("UPDATE event_registrations SET payment_status = 'paid' WHERE id = ?" . school_where());
            foreach ($registrations as $reg) {
                $updateParams = [$reg['id']];
                school_param($updateParams);
                $updateStmt->execute($updateParams);
            }

            // Build payment notes
            $notes = $chargeDesc;
            if ($chargeResult['transaction_id']) {
                $notes .= ' | Txn: ' . $chargeResult['transaction_id'];
            }
            if ($creditUsed > 0) {
                $notes .= ' | Credit applied: $' . number_format($creditUsed, 2);
            }
            if ($feeBreakdown['discount_amount'] > 0) {
                $notes .= ' | Discount: -$' . number_format($feeBreakdown['discount_amount'], 2) . ' (' . $feeBreakdown['discount_code'] . ')';
            }
            if ($feeBreakdown['service_fee'] > 0) {
                $notes .= ' | Service fee: $' . number_format($feeBreakdown['service_fee'], 2);
            }

            // Record card payment in payments table (parent pays on behalf of children)
            if ($amountCharged > 0) {
                foreach ($registrations as $reg) {
                    $perChildAmount = round($amountCharged * ((float)$reg['fee'] / $totalBaseFee), 2);
                    $childNotes = 'Event: ' . $eventName . ' — ' . $reg['first_name'] . ' ' . $reg['last_name'] . ' (paid by parent)';
                    if ($chargeResult['transaction_id']) $childNotes .= ' | Txn: ' . $chargeResult['transaction_id'];

                    $pdo->prepare("
                        INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount,
                                            payment_method, payment_date, receipt_number, notes)
                        VALUES (?, ?, 'event', ?, ?, 'credit_card', CURDATE(), ?, ?)
                    ")->execute([
                        current_school_id(),
                        $reg['student_id'],
                        $reg['eid'],
                        $perChildAmount,
                        generateReceiptNumber(),
                        $childNotes
                    ]);
                }
            }

            // Record credit portion
            if ($creditUsed > 0) {
                $pdo->prepare("
                    INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount,
                                        payment_method, payment_date, receipt_number, notes)
                    VALUES (?, ?, 'event', ?, ?, 'account_credit', CURDATE(), ?, ?)
                ")->execute([
                    current_school_id(),
                    $parentId,
                    $firstEventId,
                    $creditUsed,
                    generateReceiptNumber(),
                    'Account credit applied to event: ' . $eventName . ' for ' . implode(', ', $childNames)
                ]);
            }

            // Send payment receipt email (one receipt for the parent's total payment)
            send_payment_receipt_email([
                'student_id'     => $parentId,
                'parent_id'      => $parentId,
                'amount'         => $totalWithFees,
                'payment_type'   => 'event',
                'description'    => 'Event registration: ' . $eventName . ' for ' . implode(', ', $childNames),
                'receipt_number' => '',
                'transaction_id' => $chargeResult['transaction_id'] ?? null,
                'payment_method' => $amountCharged > 0 ? 'credit_card' : 'account_credit',
            ]);

            // Record discount code usage (supports multiple codes)
            recordAllDiscountCodeUses($feeBreakdown, $parentId, 'event', $registrations[0]['id']);

            header('Location: parent_events.php?success=payment');
            exit;
        } elseif ($chargeResult) {
            $message = showAlert('Payment failed: ' . ($chargeResult['error'] ?? 'Unknown error') . '. Please try again or update your card.', 'error');
        }
    }
}

// ---------------------------------------------------------------------------
// 4. Show a success message if redirected with ?success=payment
// ---------------------------------------------------------------------------
if (isset($_GET['success']) && $_GET['success'] === 'payment') {
    $message = showAlert('Payment completed successfully! All registrations are confirmed.', 'success');
}

include 'includes/parent_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <div class="mb-6">
            <a href="parent_events.php" class="text-blue-600 hover:text-blue-800">&larr; Back to Events</a>
        </div>

        <?php echo $message; ?>

        <div class="bg-white rounded-lg shadow-lg p-8">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Complete Event Payment</h1>

            <!-- Registration summary -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
                <h3 class="font-semibold text-gray-800 mb-4"><?= htmlspecialchars($registrations[0]['event_name']) ?></h3>
                <p class="text-sm text-gray-600 mb-1"><?= formatDate($registrations[0]['event_date']) ?></p>
                <p class="text-sm text-gray-600 capitalize mb-4"><?= str_replace('_', ' ', $registrations[0]['event_type']) ?></p>

                <!-- Children being registered -->
                <div class="border-t border-blue-200 pt-3 mb-3">
                    <p class="text-sm font-medium text-gray-700 mb-2">Registering:</p>
                    <div class="space-y-1">
                        <?php foreach ($registrations as $reg): ?>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-700"><?= htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']) ?></span>
                                <span class="font-semibold text-gray-900"><?= formatMoney($reg['fee']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Fee breakdown -->
                <div class="border-t border-blue-200 pt-3" id="fee-breakdown-area">
                    <?= renderFeeBreakdownHtml($feeBreakdown, true, 'Total Event Fees') ?>
                </div>

                <?php if ($creditToApply > 0): ?>
                    <div class="flex justify-between items-center text-green-700 mt-2">
                        <span class="text-sm font-semibold">&#128176; Account Credit:</span>
                        <span class="font-bold">-<?= formatMoney($creditToApply) ?></span>
                    </div>
                    <div class="flex justify-between items-center pt-2 border-t border-blue-200 mt-2">
                        <span class="text-lg font-semibold text-gray-800">Amount to Charge:</span>
                        <span class="text-3xl font-bold text-green-600"><?= formatMoney($cardChargeAmount) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Discount Code -->
                <div class="mt-4 pt-3 border-t border-blue-200">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Discount Code</label>
                    <div id="applied-codes-list" class="mb-1"></div>
                    <div class="flex gap-2">
                        <input type="text" id="discount-input" placeholder="Enter code"
                               value="<?= htmlspecialchars($discountCodeFromPost) ?>"
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
                <!-- No gateway configured -->
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-6 mb-6">
                    <p class="text-gray-700 mb-3">
                        <strong>Online payment is not available at this time.</strong>
                    </p>
                    <p class="text-gray-700">
                        Please contact the studio to complete your payment. Registrations will be confirmed once payment is received.
                    </p>
                </div>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="process_payment" value="1">
                    <button type="submit"
                            class="w-full bg-orange-600 hover:bg-orange-700 text-white font-bold py-3 px-4 rounded-lg">
                        Confirm Registrations (Payment Required at Studio)
                    </button>
                </form>

            <?php elseif ($cardChargeAmount <= 0): ?>
                <!-- Fully covered by credit -->
                <div class="bg-green-50 border border-green-200 rounded-lg p-6 mb-6">
                    <div class="flex items-center gap-3">
                        <span class="text-3xl">&#128176;</span>
                        <div>
                            <p class="font-semibold text-green-800">Fully Covered by Account Credit!</p>
                            <p class="text-sm text-green-600">
                                Your account credit of <?= formatMoney($parentCredit) ?> will cover the event fees. No card charge is needed.
                            </p>
                        </div>
                    </div>
                </div>

                <form method="POST" id="payment-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="process_payment" value="1">
                    <input type="hidden" name="discount_code" class="discount-hidden" value="<?= htmlspecialchars($discountCodeFromPost) ?>">
                    <button type="submit"
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-lg text-lg mb-4">
                        Confirm &mdash; Use <?= formatMoney($creditToApply) ?> Credit
                    </button>
                </form>

                <a href="parent_events.php" class="block text-center text-blue-600 hover:text-blue-800">
                    Cancel and Return to Events
                </a>

            <?php elseif (!$defaultPayment && empty($stripePk)): ?>
                <!-- No payment method -->
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
                    <p class="text-gray-700 mb-3">
                        <strong>No payment method on file.</strong> Please add a credit or debit card before completing this payment.
                    </p>
                    <a href="parent_payment.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">
                        Add Payment Method
                    </a>
                </div>

            <?php else: ?>
                <?php if (!empty($stripePk)): ?>
                    <form method="POST" id="wallet-form" style="display:none;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="process_payment" value="1">
                        <input type="hidden" name="wallet_pm_id" id="wallet_pm_id" value="">
                        <input type="hidden" name="discount_code" class="discount-hidden" value="<?= htmlspecialchars($discountCodeFromPost) ?>">
                    </form>
                    <div id="wallet-pay-container" style="display:none;" class="mb-3"></div>
                    <div id="wallet-pay-divider" style="display:none;" class="flex items-center gap-3 mb-4">
                        <div class="flex-1 h-px bg-gray-200"></div>
                        <span class="text-sm text-gray-400">or pay with saved card</span>
                        <div class="flex-1 h-px bg-gray-200"></div>
                    </div>
                <?php endif; ?>

                <?php if (!$defaultPayment): ?>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
                        <p class="text-gray-700 mb-3">
                            <strong>No payment method on file.</strong> Please add a credit or debit card before completing this payment.
                        </p>
                        <a href="parent_payment.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">
                            Add Payment Method
                        </a>
                    </div>
                <?php else: ?>
                <!-- Show payment method and pay button -->
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
                        <a href="parent_payment.php" class="text-sm text-blue-600 hover:underline">Change</a>
                    </div>
                </div>

                <?php if ($creditToApply > 0): ?>
                    <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-4 text-sm flex items-center gap-2">
                        <span>&#128176;</span>
                        <span class="text-green-700">
                            <strong><?= formatMoney($creditToApply) ?></strong> account credit will be applied.
                            Remaining <strong><?= formatMoney($cardChargeAmount) ?></strong> will be charged to your card.
                        </span>
                    </div>
                <?php endif; ?>

                <form method="POST" id="payment-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="process_payment" value="1">
                    <input type="hidden" name="discount_code" class="discount-hidden" value="<?= htmlspecialchars($discountCodeFromPost) ?>">

                    <button type="submit" id="pay-btn"
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-lg text-lg mb-4">
                        <span id="btn-text">Pay Now &mdash; <?= formatMoney($cardChargeAmount) ?></span>
                        <?php if ($creditToApply > 0): ?>
                            <span class="text-sm opacity-80">(+ <?= formatMoney($creditToApply) ?> credit)</span>
                        <?php endif; ?>
                    </button>
                </form>

                <a href="parent_events.php" class="block text-center text-blue-600 hover:text-blue-800">
                    Cancel and Return to Events
                </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (get_active_gateway() === 'stripe' && is_gateway_ready() && $stripePk && $cardChargeAmount > 0): ?>
<script src="https://js.stripe.com/v3/"></script>
<script src="assets/js/wallet-pay.js"></script>
<script>
(function() {
    var stripe = Stripe('<?= htmlspecialchars($stripePk) ?>');
    var walletForm = document.getElementById('wallet-form');
    if (!walletForm) return;

    initWalletPay(stripe, {
        amount:      <?= (int) round($cardChargeAmount * 100) ?>,
        label:       <?= json_encode($registrations[0]['event_name'] ?? 'Event Registration') ?>,
        containerId: 'wallet-pay-container',
        dividerId:   'wallet-pay-divider',
        onToken: function(pm) {
            document.getElementById('wallet_pm_id').value = pm.id;
            walletForm.submit();
        }
    });
})();
</script>
<?php endif; ?>

<script>
(function() {
    var applyBtn = document.getElementById('apply-discount-btn');
    if (!applyBtn) return;

    var discountInput = document.getElementById('discount-input');
    var discountMsg   = document.getElementById('discount-message');
    var breakdownArea = document.getElementById('fee-breakdown-area');
    var btnText       = document.getElementById('btn-text');
    var hiddenFields  = document.querySelectorAll('.discount-hidden');
    var appliedList   = document.getElementById('applied-codes-list');

    var appliedCodes = [];
    var originalBreakdownHtml = '<?= addslashes(renderFeeBreakdownHtml($feeBreakdown, true, "Total Event Fees")) ?>';

    function renderCodeBadges(details) {
        if (!appliedList) return;
        if (appliedCodes.length === 0) { appliedList.innerHTML = ''; return; }
        var html = '<div class="flex flex-wrap gap-1">';
        appliedCodes.forEach(function(c) {
            var detail = (details || []).find(function(d) { return d.code === c; });
            var label = c;
            if (detail && detail.total_discount) label += ' (-$' + parseFloat(detail.total_discount).toFixed(2) + ')';
            html += '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">';
            html += label;
            html += ' <button type="button" onclick="removeCode(\'' + c.replace(/'/g, "\\'") + '\')" class="text-green-600 hover:text-red-600 font-bold">&times;</button>';
            html += '</span>';
        });
        html += '</div>';
        appliedList.innerHTML = html;
    }

    window.removeCode = function(code) {
        appliedCodes = appliedCodes.filter(function(c) { return c !== code; });
        hiddenFields.forEach(function(f) { f.value = appliedCodes.join(','); });
        discountMsg.innerHTML = '';
        if (appliedCodes.length === 0) {
            if (breakdownArea) breakdownArea.innerHTML = originalBreakdownHtml;
            if (btnText) btnText.textContent = 'Pay Now \u2014 $' + parseFloat(<?= json_encode($feeBreakdown['total']) ?>).toFixed(2);
            renderCodeBadges([]);
        } else {
            revalidateAll();
        }
    };

    async function revalidateAll() {
        var lastCode = appliedCodes[appliedCodes.length - 1];
        var fd = new FormData();
        fd.append('code', lastCode);
        fd.append('event_id', '<?= $firstEventId ?>');
        fd.append('base_amount', '<?= $totalBaseFee ?>');
        fd.append('registration_fee', '0');
        fd.append('existing_codes', appliedCodes.slice(0, -1).join(','));

        try {
            var resp = await fetch('ajax_validate_discount.php', { method: 'POST', body: fd });
            var data = await resp.json();
            if (data.valid) {
                appliedCodes = (data.all_codes || appliedCodes.join(',')).split(',').filter(Boolean);
                hiddenFields.forEach(function(f) { f.value = appliedCodes.join(','); });
                updateBreakdownDisplay(data);
                renderCodeBadges(data.per_code_details || []);
                if (btnText) btnText.textContent = 'Pay Now \u2014 $' + parseFloat(data.total).toFixed(2);
            }
        } catch (err) {
            discountMsg.innerHTML = '<span class="text-red-600">Unable to validate code. Please try again.</span>';
        }
    }

    function updateBreakdownDisplay(data) {
        var html = '<div class="space-y-2 text-sm">';
        html += '<div class="flex justify-between"><span class="text-gray-600">Total Event Fees:</span><span class="font-semibold">$' + parseFloat(<?= json_encode($totalBaseFee) ?>).toFixed(2) + '</span></div>';
        if (data.per_code_details && data.per_code_details.length > 0) {
            data.per_code_details.forEach(function(d) {
                html += '<div class="flex justify-between"><span class="text-green-600 font-semibold">Discount (' + d.code + '):</span><span class="text-green-600 font-semibold">-$' + parseFloat(d.total_discount).toFixed(2) + '</span></div>';
            });
        } else if (data.total_discount > 0) {
            html += '<div class="flex justify-between"><span class="text-green-600 font-semibold">Discount:</span><span class="text-green-600 font-semibold">-$' + data.total_discount.toFixed(2) + '</span></div>';
        }
        if (data.service_fee > 0) {
            html += '<div class="flex justify-between"><span class="text-gray-600">Service Fee (<?= number_format($feeBreakdown["service_fee_percentage"], 2) ?>%):</span><span class="font-semibold">$' + data.service_fee.toFixed(2) + '</span></div>';
        }
        html += '<div class="flex justify-between pt-2 border-t border-blue-200"><span class="text-lg font-semibold text-gray-800">Total Due:</span><span class="text-xl font-bold text-green-600">$' + data.total.toFixed(2) + '</span></div>';
        html += '</div>';
        if (breakdownArea) breakdownArea.innerHTML = html;
    }

    applyBtn.addEventListener('click', async function() {
        var code = (discountInput.value || '').trim();
        if (!code) {
            discountMsg.innerHTML = '<span class="text-red-600">Please enter a code.</span>';
            return;
        }
        if (appliedCodes.indexOf(code.toUpperCase()) !== -1 || appliedCodes.indexOf(code) !== -1) {
            discountMsg.innerHTML = '<span class="text-red-600">This code has already been applied.</span>';
            return;
        }
        applyBtn.disabled = true;
        applyBtn.textContent = '...';

        var fd = new FormData();
        fd.append('code', code);
        fd.append('event_id', '<?= $firstEventId ?>');
        fd.append('base_amount', '<?= $totalBaseFee ?>');
        fd.append('registration_fee', '0');
        fd.append('existing_codes', appliedCodes.join(','));

        try {
            var resp = await fetch('ajax_validate_discount.php', { method: 'POST', body: fd });
            var data = await resp.json();

            if (data.valid) {
                discountMsg.innerHTML = '<span class="text-green-600">' + data.message + '</span>';
                appliedCodes = (data.all_codes || code).split(',').filter(Boolean);
                hiddenFields.forEach(function(f) { f.value = appliedCodes.join(','); });
                discountInput.value = '';
                if (btnText) btnText.textContent = 'Pay Now \u2014 $' + parseFloat(data.total).toFixed(2);
                updateBreakdownDisplay(data);
                renderCodeBadges(data.per_code_details || []);
            } else {
                discountMsg.innerHTML = '<span class="text-red-600">' + (data.error || 'Unable to validate code. Please try again.') + '</span>';
            }
        } catch (err) {
            discountMsg.innerHTML = '<span class="text-red-600">Unable to validate code. Please try again.</span>';
        }
        applyBtn.disabled = false;
        applyBtn.textContent = 'Apply';
    });
})();
</script>

<?php include 'includes/student_footer.php'; ?>
