<?php
/**
 * student_payment.php — Manage Stored Payment Methods
 *
 * Uses Stripe Elements (Stripe.js) for secure card collection.
 * Card numbers never touch this server — Stripe.js tokenizes them
 * directly in the browser. Only the resulting pm_xxx token is sent here.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/payment_gateway.php';

// Require student login
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}

// Imported students must complete registration first
require_registration_complete();

// Always refresh lockout status on payment page (student may have just updated payment)
refresh_payment_lockout_status();

$studentId = $_SESSION['student_id'];

$success = '';
$errors  = [];

// ---------- Delete a payment method ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_method'])) {
    verify_csrf();
    $methodId = (int) ($_POST['method_id'] ?? 0);
    $delSql = 'DELETE FROM payment_methods WHERE id = :id AND student_id = :sid';
    $delParams = [':id' => $methodId, ':sid' => $studentId];
    $del = $pdo->prepare($delSql);
    $del->execute($delParams);
    $success = 'Payment method removed.';
}

// ---------- Set default payment method ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_default'])) {
    verify_csrf();
    $methodId = (int) ($_POST['method_id'] ?? 0);
    $resetSql = 'UPDATE payment_methods SET is_default = 0 WHERE student_id = :sid';
    $resetParams = [':sid' => $studentId];
    $pdo->prepare($resetSql)->execute($resetParams);
    $setSql = 'UPDATE payment_methods SET is_default = 1 WHERE id = :id AND student_id = :sid';
    $setParams = [':id' => $methodId, ':sid' => $studentId];
    $pdo->prepare($setSql)->execute($setParams);
    $success = 'Default payment method updated.';
}

// ---------- Add a new payment method (receives Stripe pm_xxx token) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_method'])) {
    verify_csrf();

    $stripePaymentMethodId = trim($_POST['stripe_pm_id'] ?? '');
    $label = trim($_POST['label'] ?? '');

    if (empty($stripePaymentMethodId)) {
        $errors[] = 'Card tokenization failed. Please try again.';
    }

    if (empty($errors)) {
        $result = save_card_from_token($studentId, $stripePaymentMethodId, $label);

        if ($result['success']) {
            $success = 'Payment method added successfully.';
        } else {
            $errors[] = $result['error'] ?? 'Failed to save card. Please try again.';
        }
    }
}

// ---------- Load existing methods ----------
// payment_methods has no school_id column — scoped by student_id only
$methodsSql = 'SELECT id, label, card_brand, last_four, exp_month, exp_year, is_default, created_at
     FROM payment_methods WHERE student_id = :sid';
$methodsParams = [':sid' => $studentId];
$methodsSql .= ' ORDER BY is_default DESC, created_at DESC';
$methods = $pdo->prepare($methodsSql);
$methods->execute($methodsParams);
$paymentMethods = $methods->fetchAll();

// Gateway info for frontend
$gw       = get_active_gateway();
$gwReady  = is_gateway_ready();
$isTest   = ($gw === 'stripe' && is_stripe_test_mode()) || ($gw === 'square' && is_square_sandbox());
$stripePk = ($gw === 'stripe') ? getSetting('stripe_publishable_key') : '';

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-3xl mx-auto">

        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-400 text-green-700 p-4 mb-4 rounded"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="bg-red-100 border-l-4 border-red-400 text-red-700 p-4 mb-4 rounded">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (is_student_payment_locked()): ?>
            <div class="bg-red-50 border-2 border-red-300 rounded-xl p-6 mb-6 shadow-sm">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0">
                        <svg class="w-10 h-10 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-red-800">Account Access Restricted</h2>
                        <p class="text-sm text-red-700 mt-1">Your portal access has been limited due to an outstanding payment issue. All features — including your dashboard, events, training, and messages — are currently unavailable.</p>
                        <p class="text-sm text-red-700 mt-2 font-medium">Please update or add a valid payment method below to restore full access. If you've made a payment in person, please contact your instructor to have them clear this hold.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <h1 class="text-2xl font-bold text-gray-800 mb-6">Payment Methods</h1>

        <!-- Saved Methods -->
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800">Saved Cards</h2>
            </div>
            <div class="p-6">
                <?php if (empty($paymentMethods)): ?>
                    <p class="text-gray-400 text-center py-6">You have no saved payment methods yet. Add one below.</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($paymentMethods as $pm): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg <?= $pm['is_default'] ? 'ring-2 ring-blue-300' : '' ?>">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-8 bg-gray-200 rounded flex items-center justify-center text-xs font-bold uppercase text-gray-600">
                                        <?= htmlspecialchars($pm['card_brand'] ?: 'Card') ?>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-800"><?= htmlspecialchars($pm['label']) ?></p>
                                        <p class="text-sm text-gray-500">
                                            <span class="tracking-widest">&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;</span>
                                            <span class="font-mono font-semibold ml-1"><?= htmlspecialchars($pm['last_four']) ?></span>
                                            <?php if ($pm['exp_month'] && $pm['exp_year']): ?>
                                                <span class="ml-3 text-gray-400">Exp <?= str_pad($pm['exp_month'], 2, '0', STR_PAD_LEFT) ?>/<?= $pm['exp_year'] ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <?php if ($pm['is_default']): ?>
                                        <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-semibold">Default</span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center gap-2">
                                    <?php if (!$pm['is_default']): ?>
                                        <form method="POST" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="set_default" value="1">
                                            <input type="hidden" name="method_id" value="<?= $pm['id'] ?>">
                                            <button type="submit" class="text-blue-600 hover:text-blue-800 text-sm">Set Default</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Remove this payment method?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_method" value="1">
                                        <input type="hidden" name="method_id" value="<?= $pm['id'] ?>">
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
                                   placeholder="e.g. Mom's Visa" autocomplete="off"
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
