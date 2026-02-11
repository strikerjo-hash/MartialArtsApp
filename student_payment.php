<?php
/**
 * student_payment.php — Manage Stored Payment Methods
 *
 * Students can add, view, and remove payment methods.  Card numbers are
 * NEVER stored in full — only the last four digits and an encrypted
 * processor token/reference are persisted.
 *
 * IMPORTANT:  In production you should integrate with Stripe, Braintree,
 * or another PCI-compliant processor and store only their opaque token.
 * The encryption layer here adds defence-in-depth for that token, but it
 * is NOT a substitute for full PCI-DSS compliance.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/db.php';

require_student();

$theme     = get_theme();
$pdo       = get_db();
$studentId = $_SESSION['user_id'];

$success = '';
$errors  = [];

// ---------- Delete a payment method ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_method'])) {
    verify_csrf();

    $methodId = (int) ($_POST['method_id'] ?? 0);
    $del = $pdo->prepare('DELETE FROM payment_methods WHERE id = :id AND student_id = :sid');
    $del->execute([':id' => $methodId, ':sid' => $studentId]);

    $success = 'Payment method removed.';
}

// ---------- Set default payment method ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_default'])) {
    verify_csrf();

    $methodId = (int) ($_POST['method_id'] ?? 0);

    // Clear existing default.
    $pdo->prepare('UPDATE payment_methods SET is_default = 0 WHERE student_id = :sid')
        ->execute([':sid' => $studentId]);

    // Set new default.
    $pdo->prepare('UPDATE payment_methods SET is_default = 1 WHERE id = :id AND student_id = :sid')
        ->execute([':id' => $methodId, ':sid' => $studentId]);

    $success = 'Default payment method updated.';
}

// ---------- Add a new payment method ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_method'])) {
    verify_csrf();

    $cardNumber = preg_replace('/\s+/', '', $_POST['card_number'] ?? '');
    $cardBrand  = trim($_POST['card_brand'] ?? '');
    $label      = trim($_POST['label'] ?? '');

    // Basic validation — we only need to collect enough to create a processor token.
    if (!preg_match('/^\d{13,19}$/', $cardNumber)) {
        $errors[] = 'Please enter a valid card number (digits only).';
    }
    if ($label === '') {
        $label = ucfirst($cardBrand ?: 'Card') . ' ending ' . substr($cardNumber, -4);
    }

    if (empty($errors)) {
        $lastFour = substr($cardNumber, -4);

        // In production this is where you would call Stripe::createToken()
        // or equivalent and receive an opaque token.  For now we simulate
        // a processor reference.
        $processorToken = 'tok_' . bin2hex(random_bytes(16));

        // Encrypt the token before storing.
        $encryptedToken = encrypt_payment_data($processorToken);

        $ins = $pdo->prepare(
            'INSERT INTO payment_methods (student_id, label, card_brand, last_four, encrypted_token, is_default)
             VALUES (:sid, :lbl, :brand, :l4, :tok, :def)'
        );

        // Make the first card the default automatically.
        $existingCount = (int) $pdo->prepare('SELECT COUNT(*) FROM payment_methods WHERE student_id = :sid')
            ->execute([':sid' => $studentId]) ? $pdo->query("SELECT COUNT(*) FROM payment_methods WHERE student_id = $studentId")->fetchColumn() : 0;
        $isDefault = $existingCount === 0 ? 1 : 0;

        $ins->execute([
            ':sid'   => $studentId,
            ':lbl'   => $label,
            ':brand' => $cardBrand ?: null,
            ':l4'    => $lastFour,
            ':tok'   => $encryptedToken,
            ':def'   => $isDefault,
        ]);

        $success = 'Payment method added.';
    }
}

// ---------- Load existing methods ----------
$methods = $pdo->prepare(
    'SELECT id, label, card_brand, last_four, is_default, created_at
     FROM payment_methods WHERE student_id = :sid ORDER BY is_default DESC, created_at DESC'
);
$methods->execute([':sid' => $studentId]);
$paymentMethods = $methods->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Methods — <?= htmlspecialchars($theme['studio_name']) ?></title>
    <?php if (!empty($theme['favicon_url'])): ?>
        <link rel="icon" href="<?= htmlspecialchars($theme['favicon_url']) ?>">
    <?php endif; ?>
    <style><?= theme_css_vars() ?></style>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="portal-page">

    <nav class="top-nav">
        <div class="nav-brand">
            <?php if (!empty($theme['logo_url'])): ?>
                <img src="<?= htmlspecialchars($theme['logo_url']) ?>" alt="" class="nav-logo">
            <?php endif; ?>
            <span><?= htmlspecialchars($theme['studio_name']) ?></span>
        </div>
        <div class="nav-user">
            <a href="student_portal.php" class="btn btn-sm btn-outline">Dashboard</a>
            <a href="student_profile.php" class="btn btn-sm btn-outline">My Profile</a>
            <a href="logout.php" class="btn btn-sm btn-outline">Sign Out</a>
        </div>
    </nav>

    <main class="portal-main">

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Saved Methods -->
        <section class="card">
            <h2>Saved Payment Methods</h2>

            <?php if (empty($paymentMethods)): ?>
                <p class="empty-state">You have no saved payment methods yet.</p>
            <?php else: ?>
                <div class="payment-list">
                    <?php foreach ($paymentMethods as $pm): ?>
                        <div class="payment-item">
                            <div class="payment-info">
                                <span class="payment-label">
                                    <?= htmlspecialchars($pm['label']) ?>
                                </span>
                                <span class="payment-last4">
                                    &bull;&bull;&bull;&bull; <?= htmlspecialchars($pm['last_four']) ?>
                                </span>
                                <?php if ($pm['is_default']): ?>
                                    <span class="status-badge status-present">Default</span>
                                <?php endif; ?>
                            </div>
                            <div class="payment-actions">
                                <?php if (!$pm['is_default']): ?>
                                    <form method="POST" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="set_default" value="1">
                                        <input type="hidden" name="method_id" value="<?= $pm['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline">Set Default</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" style="display:inline"
                                      onsubmit="return confirm('Remove this payment method?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_method" value="1">
                                    <input type="hidden" name="method_id" value="<?= $pm['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Add Payment Method -->
        <section class="card">
            <h2>Add Payment Method</h2>
            <p class="section-description">
                Your full card number is never stored. We keep only the last four digits
                and a secure, encrypted reference for future charges.
            </p>

            <form method="POST" action="student_payment.php" class="branding-form" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="add_method" value="1">

                <div class="form-group">
                    <label for="card_number">Card Number</label>
                    <input type="text" id="card_number" name="card_number"
                           placeholder="1234 5678 9012 3456" required
                           maxlength="19" inputmode="numeric" autocomplete="cc-number">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="card_brand">Card Type</label>
                        <select id="card_brand" name="card_brand" class="form-select">
                            <option value="">Select...</option>
                            <option value="visa">Visa</option>
                            <option value="mastercard">Mastercard</option>
                            <option value="amex">American Express</option>
                            <option value="discover">Discover</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="label">Label (optional)</label>
                        <input type="text" id="label" name="label"
                               placeholder="e.g. Mom's Visa">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Add Card</button>
            </form>
        </section>

        <section class="card">
            <h2>Security Notice</h2>
            <p class="section-description">
                All payment data is encrypted with AES-256-GCM at rest. Full card numbers
                are never written to the database — only a tokenised reference is stored.
                For additional protection, this application is designed to integrate with a
                PCI-compliant payment processor (e.g. Stripe) so that raw card details are
                handled exclusively by the processor's secure infrastructure.
            </p>
        </section>
    </main>

    <footer class="portal-footer">
        <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($theme['studio_name']) ?>. All rights reserved.</p>
    </footer>
</body>
</html>
