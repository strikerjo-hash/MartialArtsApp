<?php
/**
 * unsubscribe.php — One-Click Email/SMS Unsubscribe
 *
 * Handles token-based unsubscribe requests from email links.
 * Tokens are HMAC-signed and contain user type, ID, and channel.
 * No login required — the token itself is the proof of identity.
 *
 * Supports:
 *   - GET with ?token=... → shows confirmation page
 *   - POST with token → processes the unsubscribe
 *   - POST with List-Unsubscribe header (RFC 8058 one-click)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/messaging.php';

$theme = getActiveTheme();
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$confirmed = false;
$error = '';
$success = '';
$tokenData = null;

if ($token !== '') {
    $tokenData = verify_unsubscribe_token($token);
    if (!$tokenData) {
        $error = 'This unsubscribe link is invalid or has expired. Please update your preferences from your profile page instead.';
    }
}

// Handle POST — actually unsubscribe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenData && $error === '') {
    $channel = $_POST['channel'] ?? $tokenData['channel'];
    if (!in_array($channel, ['email', 'sms', 'all'], true)) {
        $channel = $tokenData['channel'];
    }

    $result = revoke_comm_consent($tokenData['type'], $tokenData['id'], $channel);
    if ($result) {
        $channelLabel = $channel === 'all' ? 'all communications' : ($channel === 'email' ? 'email communications' : 'SMS/text messages');
        $success = "You have been successfully unsubscribed from {$channelLabel}. This change takes effect immediately.";
        $confirmed = true;
    } else {
        $error = 'Unable to process your unsubscribe request. Please try updating your preferences from your profile page.';
    }
}

// Fetch user info for display
$userName = '';
if ($tokenData && !$error) {
    try {
        $pdo = get_db();
        $tbl = ($tokenData['type'] === 'parent') ? 'parents' : 'students';
        $row = $pdo->prepare("SELECT first_name, comm_consent_email, comm_consent_sms FROM {$tbl} WHERE id = ? LIMIT 1");
        $row->execute([$tokenData['id']]);
        $row = $row->fetch();
        if ($row) {
            $userName = htmlspecialchars($row['first_name']);
            // If already unsubscribed from the requested channel
            if (!$confirmed) {
                $alreadyDone = false;
                if ($tokenData['channel'] === 'email' && empty($row['comm_consent_email'])) $alreadyDone = true;
                if ($tokenData['channel'] === 'sms' && empty($row['comm_consent_sms'])) $alreadyDone = true;
                if ($tokenData['channel'] === 'all' && empty($row['comm_consent_email']) && empty($row['comm_consent_sms'])) $alreadyDone = true;
                if ($alreadyDone) {
                    $success = 'You are already unsubscribed from these communications.';
                    $confirmed = true;
                }
            }
        }
    } catch (\PDOException $e) {
        // Ignore — non-critical
    }
}

$schoolName = getSiteName();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribe — <?= htmlspecialchars($schoolName) ?></title>
    <?php if (!empty($theme['favicon_url'])): ?>
        <link rel="icon" href="<?= htmlspecialchars($theme['favicon_url']) ?>">
    <?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6; color: #1f2937;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1rem;
        }
        .card {
            background: white; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            max-width: 480px; width: 100%; padding: 2.5rem; text-align: center;
        }
        .logo { max-height: 60px; margin-bottom: 1rem; }
        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; color: #111827; }
        .subtitle { color: #6b7280; font-size: 0.9rem; margin-bottom: 1.5rem; line-height: 1.5; }
        .alert-error {
            background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
            border-radius: 8px; padding: 1rem; margin-bottom: 1rem; font-size: 0.9rem;
        }
        .alert-success {
            background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;
            border-radius: 8px; padding: 1rem; margin-bottom: 1rem; font-size: 0.9rem;
        }
        .btn {
            display: inline-block; padding: 0.75rem 1.5rem; border-radius: 8px;
            font-size: 0.9rem; font-weight: 600; cursor: pointer; border: none;
            text-decoration: none; transition: all 0.15s;
        }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-secondary { background: #e5e7eb; color: #374151; margin-left: 0.5rem; }
        .btn-secondary:hover { background: #d1d5db; }
        .options { margin: 1rem 0; text-align: left; }
        .option-label {
            display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1rem;
            background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;
            margin-bottom: 0.5rem; cursor: pointer; font-size: 0.9rem;
        }
        .option-label:hover { background: #f3f4f6; }
        .option-label input { width: 1.1rem; height: 1.1rem; }
        .footer-text { margin-top: 1.5rem; font-size: 0.8rem; color: #9ca3af; }
        .footer-text a { color: #6366f1; text-decoration: none; }
        .footer-text a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <?php if (!empty($theme['logo_url'])): ?>
            <img src="<?= htmlspecialchars($theme['logo_url']) ?>" alt="" class="logo">
        <?php endif; ?>

        <?php if ($error): ?>
            <h1>Unsubscribe</h1>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <p class="footer-text"><a href="login.php">Sign in to manage preferences</a></p>

        <?php elseif ($confirmed): ?>
            <h1>Unsubscribed</h1>
            <div class="alert-success"><?= htmlspecialchars($success) ?></div>
            <p class="subtitle">
                You can re-subscribe at any time by updating your communication preferences in your profile.
            </p>
            <a href="login.php" class="btn btn-secondary">Sign In</a>

        <?php elseif ($tokenData): ?>
            <h1>Unsubscribe<?= $userName ? ", {$userName}" : '' ?>?</h1>
            <p class="subtitle">
                Choose which communications you'd like to stop receiving from <?= htmlspecialchars($schoolName) ?>.
            </p>
            <form method="POST">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="options">
                    <label class="option-label">
                        <input type="radio" name="channel" value="email" checked>
                        <span>Unsubscribe from <strong>emails</strong> only</span>
                    </label>
                    <label class="option-label">
                        <input type="radio" name="channel" value="sms">
                        <span>Unsubscribe from <strong>SMS/text messages</strong> only</span>
                    </label>
                    <label class="option-label">
                        <input type="radio" name="channel" value="all">
                        <span>Unsubscribe from <strong>all communications</strong></span>
                    </label>
                </div>
                <button type="submit" class="btn btn-danger">Confirm Unsubscribe</button>
            </form>
            <p class="footer-text">
                Or <a href="login.php">sign in</a> to manage your preferences in detail.
            </p>

        <?php else: ?>
            <h1>Unsubscribe</h1>
            <div class="alert-error">No unsubscribe token provided. Please use the link from your email.</div>
            <p class="footer-text"><a href="login.php">Sign in to manage preferences</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
