<?php
/**
 * parent_payment.php — Parent Payment Method Management
 *
 * Uses Stripe Elements (Stripe.js) for secure card collection — identical
 * to student_payment.php. Card numbers never touch this server; Stripe.js
 * tokenizes them in the browser and only the pm_xxx token is sent here.
 *
 * For student-as-parent accounts the payment_methods table is used (same
 * table as the student's own cards). For legacy parent accounts the
 * parent_payment_methods table is used.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/parent_auth.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/payment_gateway.php';

require_parent();

$parentId = get_effective_parent_id();
$pdo = get_db();
$message = '';
$errors  = [];

// Determine which table to use: student-as-parent uses payment_methods, legacy uses parent_payment_methods
$isStudentParent = (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'student' && !empty($_SESSION['is_parent']));
$pmTable    = $isStudentParent ? 'payment_methods' : 'parent_payment_methods';
$pmOwnerCol = $isStudentParent ? 'student_id' : 'parent_id';

// ---------- Delete a payment method ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_method'])) {
    verify_csrf();
    $methodId = (int)($_POST['method_id'] ?? 0);
    $params = [$methodId, $parentId];
    school_param($params);
    $pdo->prepare("DELETE FROM {$pmTable} WHERE id = ? AND {$pmOwnerCol} = ?" . school_where())->execute($params);
    $message = showAlert('Payment method removed.', 'success');
}

// ---------- Set default payment method ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_default'])) {
    verify_csrf();
    $methodId = (int)($_POST['method_id'] ?? 0);
    $params = [$parentId];
    school_param($params);
    $pdo->prepare("UPDATE {$pmTable} SET is_default = 0 WHERE {$pmOwnerCol} = ?" . school_where())->execute($params);
    $params = [$methodId, $parentId];
    school_param($params);
    $pdo->prepare("UPDATE {$pmTable} SET is_default = 1 WHERE id = ? AND {$pmOwnerCol} = ?" . school_where())->execute($params);
    $message = showAlert('Default payment method updated.', 'success');
}

// ---------- Add a new payment method via Stripe token ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_method'])) {
    verify_csrf();

    $stripePaymentMethodId = trim($_POST['stripe_pm_id'] ?? '');
    $label = trim($_POST['label'] ?? '');

    if (empty($stripePaymentMethodId)) {
        $errors[] = 'Card tokenization failed. Please try again.';
    }

    if (empty($errors)) {
        // For student-as-parent, use the standard save_card_from_token which writes to payment_methods
        if ($isStudentParent) {
            $result = save_card_from_token($parentId, $stripePaymentMethodId, $label);
        } else {
            // Legacy parent — save to parent_payment_methods table with gateway ID
            $result = save_parent_card_from_token($parentId, $stripePaymentMethodId, $label);
        }

        if ($result['success']) {
            $message = showAlert('Payment method added successfully.', 'success');
        } else {
            $errors[] = $result['error'] ?? 'Failed to save card. Please try again.';
        }
    }
}

// ---------- Load existing methods ----------
$params = [$parentId];
school_param($params);
$methods = $pdo->prepare("SELECT * FROM {$pmTable} WHERE {$pmOwnerCol} = ?" . school_where() . " ORDER BY is_default DESC, created_at DESC");
$methods->execute($params);
$methods = $methods->fetchAll();

// Gateway info for frontend
$gw       = get_active_gateway();
$gwReady  = is_gateway_ready();
$isTest   = ($gw === 'stripe' && is_stripe_test_mode()) || ($gw === 'square' && is_square_sandbox());
$stripePk = ($gw === 'stripe') ? getSetting('stripe_publishable_key') : '';

// Fetch recent payments by this parent's children
$children = get_parent_children($parentId);
$childIds = array_column($children, 'id');
$recentPayments = [];
if (!empty($childIds)) {
    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    try {
        $params = $childIds;
        school_param($params);
        $payStmt = $pdo->prepare("
            SELECT p.*, s.first_name, s.last_name
            FROM payments p
            JOIN students s ON s.id = p.student_id
            WHERE p.student_id IN ({$placeholders})" . school_where('p') . "
            ORDER BY p.payment_date DESC
            LIMIT 20
        ");
        $payStmt->execute($params);
        $recentPayments = $payStmt->fetchAll();
    } catch (\PDOException $e) {}
}

include 'includes/parent_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    <?php if ($errors): ?>
        <div class="bg-red-100 border-l-4 border-red-400 text-red-700 p-4 mb-4 rounded">
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Payment Methods</h1>
        <p class="text-gray-600">Manage your family's payment methods</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Saved Payment Methods -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800">Saved Cards</h2>
                </div>
                <div class="p-6">
                    <?php if (empty($methods)): ?>
                        <p class="text-gray-400 text-center py-6">You have no saved payment methods yet. Add one below.</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($methods as $m): ?>
                                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg <?= $m['is_default'] ? 'ring-2 ring-blue-300' : '' ?>">
                                    <div class="flex items-center gap-4">
                                        <div class="w-12 h-8 bg-gray-200 rounded flex items-center justify-center text-xs font-bold uppercase text-gray-600">
                                            <?= htmlspecialchars($m['card_brand'] ?: 'Card') ?>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-800"><?= htmlspecialchars($m['label'] ?? 'Card') ?></p>
                                            <p class="text-sm text-gray-500">
                                                <span class="tracking-widest">&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;</span>
                                                <span class="font-mono font-semibold ml-1"><?= htmlspecialchars($m['last_four']) ?></span>
                                                <?php if (!empty($m['exp_month']) && !empty($m['exp_year'])): ?>
                                                    <span class="ml-3 text-gray-400">Exp <?= str_pad($m['exp_month'], 2, '0', STR_PAD_LEFT) ?>/<?= $m['exp_year'] ?></span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <?php if ($m['is_default']): ?>
                                            <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-semibold">Default</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <?php if (!$m['is_default']): ?>
                                            <form method="POST" class="inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="set_default" value="1">
                                                <input type="hidden" name="method_id" value="<?= $m['id'] ?>">
                                                <button type="submit" class="text-blue-600 hover:text-blue-800 text-sm">Set Default</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Remove this payment method?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="delete_method" value="1">
                                            <input type="hidden" name="method_id" value="<?= $m['id'] ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-700 text-sm">Remove</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Add Payment Method -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800">Add Payment Method</h2>
                    <p class="text-sm text-gray-500 mt-1">Your card details are securely handled by <?= $gwReady ? ucfirst($gw) : 'the payment processor' ?>. We never see or store your full card number.</p>
                </div>
                <div class="p-6">
                    <?php if ($gw === 'stripe' && $gwReady && $stripePk): ?>
                        <!-- Stripe Elements form -->
                        <form id="stripe-form" method="POST" class="space-y-4">
                            <?= csrf_field() ?>
                            <input type="hidden" name="add_method" value="1">
                            <input type="hidden" name="stripe_pm_id" id="stripe_pm_id" value="">

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Card Details</label>
                                <div id="card-element" class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-white" style="min-height: 44px;">
                                    <!-- Stripe Elements injects the card input here -->
                                </div>
                                <div id="card-errors" class="text-red-600 text-sm mt-2"></div>
                            </div>

                            <div>
                                <label for="label" class="block text-sm font-medium text-gray-700 mb-1">Card Nickname (optional)</label>
                                <input type="text" id="label" name="label"
                                       placeholder="e.g. Family Visa" autocomplete="off"
                                       class="w-full max-w-xs px-3 py-2 border border-gray-300 rounded-lg">
                            </div>

                            <button type="submit" id="submit-btn"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                                <span id="btn-text">Add Card</span>
                                <span id="btn-spinner" class="hidden">
                                    <svg class="animate-spin inline-block w-5 h-5 ml-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    Processing...
                                </span>
                            </button>
                        </form>
                    <?php elseif ($gw === 'square' && $gwReady): ?>
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                            <p class="text-sm text-yellow-800">
                                <strong>Square Web Payments:</strong> Square card input will be implemented here.
                                For now, please contact the studio to add a card to your account.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                            <p class="text-sm text-yellow-800">
                                Online card management is not available at this time. Please contact the studio to manage your payment methods.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Payment Summary & Info sidebar -->
        <div>
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Summary</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Saved Cards</span>
                        <span class="font-semibold text-gray-800"><?= count($methods) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Children</span>
                        <span class="font-semibold text-gray-800"><?= count($children) ?></span>
                    </div>
                </div>
            </div>

            <!-- Gateway Status & Security Notice -->
            <?php if ($gwReady): ?>
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="w-2.5 h-2.5 rounded-full <?= $isTest ? 'bg-yellow-400' : 'bg-green-500' ?>"></span>
                        <h3 class="font-semibold text-green-800 text-sm">
                            <?= ucfirst($gw) ?> <?= $isTest ? '(Test / Sandbox Mode)' : '(Live)' ?>
                        </h3>
                    </div>
                    <p class="text-sm text-green-700">
                        Cards are securely tokenized by <?= ucfirst($gw) ?> in your browser.
                        Your card number never reaches our server.
                        <?php if ($isTest): ?>
                            <br><strong>Test mode is active.</strong> Use test card number 4242 4242 4242 4242, any future date, any CVC.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                    <h3 class="font-semibold text-yellow-800 text-sm mb-1">No Payment Gateway Configured</h3>
                    <p class="text-sm text-yellow-700">
                        An admin needs to configure Stripe or Square in Settings before cards can be saved.
                    </p>
                </div>
            <?php endif; ?>

            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h3 class="font-semibold text-blue-800 text-sm mb-1">Security Notice</h3>
                <p class="text-sm text-blue-700">
                    Card details are entered into a secure iframe hosted by <?= $gwReady ? ucfirst($gw) : 'the payment processor' ?> and never pass through our server.
                    Only an encrypted reference token is stored. All data at rest is encrypted with AES-256-GCM.
                </p>
            </div>
        </div>
    </div>

    <!-- Recent Payment History -->
    <?php if (!empty($recentPayments)): ?>
        <div class="bg-white rounded-lg shadow mt-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800">Recent Payment History</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Child</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($recentPayments as $pay): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= formatDate($pay['payment_date']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($pay['first_name'] . ' ' . $pay['last_name']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 capitalize">
                                    <?= str_replace('_', ' ', $pay['payment_type'] ?? 'other') ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600">
                                    <?= formatMoney($pay['amount']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 capitalize">
                                    <?= str_replace('_', ' ', $pay['payment_method'] ?? '-') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($gw === 'stripe' && $gwReady && $stripePk): ?>
<!-- Stripe.js -->
<script src="https://js.stripe.com/v3/"></script>
<script>
(function() {
    const stripe = Stripe('<?= htmlspecialchars($stripePk) ?>');
    const elements = stripe.elements();

    // Create a Card Element with styling
    const cardElement = elements.create('card', {
        style: {
            base: {
                fontSize: '16px',
                color: '#1f2937',
                fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                '::placeholder': { color: '#9ca3af' }
            },
            invalid: {
                color: '#dc2626',
                iconColor: '#dc2626'
            }
        }
    });
    cardElement.mount('#card-element');

    // Show validation errors
    cardElement.on('change', function(event) {
        const errEl = document.getElementById('card-errors');
        errEl.textContent = event.error ? event.error.message : '';
    });

    // Handle form submit
    const form = document.getElementById('stripe-form');
    const submitBtn = document.getElementById('submit-btn');
    const btnText = document.getElementById('btn-text');
    const btnSpinner = document.getElementById('btn-spinner');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        submitBtn.disabled = true;
        btnText.classList.add('hidden');
        btnSpinner.classList.remove('hidden');

        const { paymentMethod, error } = await stripe.createPaymentMethod({
            type: 'card',
            card: cardElement,
        });

        if (error) {
            document.getElementById('card-errors').textContent = error.message;
            submitBtn.disabled = false;
            btnText.classList.remove('hidden');
            btnSpinner.classList.add('hidden');
            return;
        }

        // Set the token and submit the form
        document.getElementById('stripe_pm_id').value = paymentMethod.id;
        form.submit();
    });
})();
</script>
<?php endif; ?>

<?php include 'includes/student_footer.php'; ?>
