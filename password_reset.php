<?php
/**
 * password_reset.php — Self-Service Password Reset
 *
 * Three-step flow accessible from the login screen (no auth required):
 *   Step 1 (request):  Enter username/email → receive verification code
 *   Step 2 (verify):   Enter the 6-digit code
 *   Step 3 (reset):    Set a new password
 *
 * Verification codes are delivered via:
 *   - In-app message (audit trail)
 *   - Email (if SMTP configured)
 *   - SMS  (if Twilio configured)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/parent_auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/messaging.php';

if (function_exists('auth_start_session')) {
    auth_start_session();
}

$pdo   = get_db();
$theme = get_theme();
$step  = $_GET['step'] ?? 'request';
$error = '';
$success = '';

// ─── Step 1: Request a reset code ─────────────────────────────────────
if ($step === 'request') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();

        $username = trim($_POST['username'] ?? '');
        if ($username === '') {
            $error = 'Please enter your username or email.';
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            // Rate-limit using existing mechanism (prefix to avoid collision with login)
            $wait = check_rate_limit('reset_' . $username, $ip);
            if ($wait > 0) {
                $error = "Too many attempts. Please wait {$wait} seconds before trying again.";
            } else {
                // Look up user in students table first, then parents
                $user = null;
                $userType = null;

                $stmt = $pdo->prepare('SELECT id, school_id, first_name, last_name, email, phone, username, status, deactivation_reason FROM students WHERE (email = :u1 OR username = :u2) LIMIT 1');
                $stmt->execute([':u1' => $username, ':u2' => $username]);
                $student = $stmt->fetch();

                if ($student) {
                    // Block password reset for deactivated/suspended accounts
                    $stuStatus = $student['status'] ?? 'active';
                    if ($stuStatus === 'suspended') {
                        $error = 'Your account has been suspended. Please contact administration for assistance.';
                        $student = null; // prevent further processing
                    } elseif ($stuStatus === 'inactive') {
                        $stuReason = $student['deactivation_reason'] ?? null;
                        if ($stuReason === 'payment') {
                            $error = 'Your account has been deactivated due to an outstanding balance. Please contact us to resolve your payment before resetting your password.';
                        } else {
                            $error = 'Your account has been deactivated. Please contact administration for assistance.';
                        }
                        $student = null; // prevent further processing
                    }
                }

                if ($student) {
                    $user = $student;
                    $userType = 'student';
                } else if (empty($error)) {
                    // Try parents table
                    try {
                        $pStmt = $pdo->prepare("SELECT id, school_id, first_name, last_name, email, phone, username FROM parents WHERE (email = :u1 OR username = :u2) AND status = 'active' LIMIT 1");
                        $pStmt->execute([':u1' => $username, ':u2' => $username]);
                        $parent = $pStmt->fetch();
                        if ($parent) {
                            $user = $parent;
                            $userType = 'parent';
                        }
                    } catch (\PDOException $e) {
                        // parents table may not exist
                    }
                }

                if ($user) {
                    // Check max 3 unexpired codes per user per 15 minutes
                    $countStmt = $pdo->prepare(
                        'SELECT COUNT(*) FROM password_resets
                         WHERE user_type = :ut AND user_id = :uid
                           AND expires_at > NOW() AND used_at IS NULL'
                    );
                    $countStmt->execute([':ut' => $userType, ':uid' => $user['id']]);
                    $activeCount = (int) $countStmt->fetchColumn();

                    if ($activeCount >= 3) {
                        // Silently show success (don't reveal rate limit details)
                    } else {
                        // Generate 6-digit code
                        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $codeHash = password_hash($code, PASSWORD_DEFAULT);
                        $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 minutes
                        $schoolId = $user['school_id'] ?? 1;

                        // Store the reset code
                        $insertStmt = $pdo->prepare(
                            'INSERT INTO password_resets (school_id, user_type, user_id, username, code_hash, ip_address, expires_at)
                             VALUES (:sid, :ut, :uid, :uname, :hash, :ip, :exp)'
                        );
                        $insertStmt->execute([
                            ':sid'   => $schoolId,
                            ':ut'    => $userType,
                            ':uid'   => $user['id'],
                            ':uname' => $username,
                            ':hash'  => $codeHash,
                            ':ip'    => $ip,
                            ':exp'   => $expiresAt,
                        ]);

                        $resetId = $pdo->lastInsertId();

                        // Build the message body from notification templates
                        $displayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                        $siteName = $theme['studio_name'] ?? 'Martial Arts Academy';
                        $emailTpl = get_notification_template('password_reset_email', [
                            '{student_name}' => $displayName,
                            '{code}'         => $code,
                            '{school_name}'  => $siteName,
                        ]);
                        $msgSubject = $emailTpl['subject'];
                        $msgBody    = $emailTpl['body'];

                        $smsTpl = get_notification_template('password_reset_sms', [
                            '{code}'         => $code,
                            '{school_name}'  => $siteName,
                        ]);
                        $plainText = $smsTpl['body'];

                        // 1. In-app message (audit trail)
                        try {
                            $msgStmt = $pdo->prepare(
                                "INSERT INTO messages (school_id, sender_type, sender_id, subject, body, channel_inapp, channel_email, channel_sms, status, sent_at, total_recipients)
                                 VALUES (:sid, 'system', 0, :subj, :body, 1, 0, 0, 'sent', NOW(), 1)"
                            );
                            $msgStmt->execute([
                                ':sid'  => $schoolId,
                                ':subj' => $msgSubject,
                                ':body' => $msgBody,
                            ]);
                            $messageId = $pdo->lastInsertId();

                            $recipType = $userType === 'student' ? 'student' : 'user';
                            $recipStmt = $pdo->prepare(
                                "INSERT INTO message_recipients (school_id, message_id, recipient_type, recipient_id, inapp_status)
                                 VALUES (:sid, :mid, :rtype, :rid, 'delivered')"
                            );
                            $recipStmt->execute([
                                ':sid'   => $schoolId,
                                ':mid'   => $messageId,
                                ':rtype' => $recipType,
                                ':rid'   => $user['id'],
                            ]);
                        } catch (\PDOException $e) {
                            // Non-fatal: messages table may not have all columns
                            if (function_exists('app_log')) {
                                app_log('warning', 'Password reset in-app message failed: ' . $e->getMessage());
                            }
                        }

                        // 2. Email (if configured)
                        if (is_email_configured() && !empty($user['email'])) {
                            $emailResult = send_email($user['email'], $msgSubject . ' - ' . $siteName, $msgBody);
                            if (!$emailResult['success'] && function_exists('app_log')) {
                                app_log('warning', 'Password reset email failed: ' . ($emailResult['error'] ?? 'unknown'));
                            }
                        }

                        // 3. SMS (if configured)
                        if (is_sms_configured() && !empty($user['phone'])) {
                            $smsResult = send_sms($user['phone'], $plainText);
                            if (!$smsResult['success'] && function_exists('app_log')) {
                                app_log('warning', 'Password reset SMS failed: ' . ($smsResult['error'] ?? 'unknown'));
                            }
                        }

                        // Audit log
                        if (function_exists('audit_log')) {
                            audit_log('password_reset_requested', [
                                'description' => "Password reset requested for {$userType}: {$displayName} ({$username})",
                                'entity_type' => $userType,
                                'entity_id'   => $user['id'],
                                'user_type'   => 'system',
                                'username'    => 'system',
                            ]);
                        }
                    }

                    // Store reset context in session for step 2
                    $_SESSION['reset_username'] = $username;
                    $_SESSION['reset_user_type'] = $userType;
                    $_SESSION['reset_user_id'] = $user['id'];
                } else {
                    // Record failed attempt so rate limiting kicks in
                    record_failed_login('reset_' . $username, $ip);
                }

                // Always show generic success (prevents username enumeration)
                $success = 'If an account exists with that username or email, a verification code has been sent.';
                $_SESSION['reset_step_allowed'] = 'verify';
            }
        }
    }
}

// ─── Step 2: Verify the code ──────────────────────────────────────────
if ($step === 'verify') {
    // Must have come from step 1
    if (empty($_SESSION['reset_step_allowed']) || $_SESSION['reset_step_allowed'] !== 'verify') {
        header('Location: password_reset.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();

        $code = trim($_POST['code'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $username = $_SESSION['reset_username'] ?? '';
        $userType = $_SESSION['reset_user_type'] ?? '';
        $userId = $_SESSION['reset_user_id'] ?? 0;

        if ($code === '' || strlen($code) !== 6) {
            $error = 'Please enter the 6-digit verification code.';
        } else {
            // Rate-limit code verification attempts
            $wait = check_rate_limit('verify_' . $username, $ip);
            if ($wait > 0) {
                $error = "Too many attempts. Please wait {$wait} seconds.";
            } else {
                // Find unexpired, unused codes for this user
                $codesStmt = $pdo->prepare(
                    'SELECT id, code_hash FROM password_resets
                     WHERE user_type = :ut AND user_id = :uid
                       AND expires_at > NOW() AND used_at IS NULL
                     ORDER BY created_at DESC LIMIT 5'
                );
                $codesStmt->execute([':ut' => $userType, ':uid' => $userId]);
                $codes = $codesStmt->fetchAll();

                $matched = false;
                $matchedResetId = null;

                foreach ($codes as $row) {
                    if (password_verify($code, $row['code_hash'])) {
                        $matched = true;
                        $matchedResetId = $row['id'];
                        break;
                    }
                }

                if ($matched) {
                    // Code is valid — allow step 3
                    $_SESSION['reset_step_allowed'] = 'reset';
                    $_SESSION['reset_code_id'] = $matchedResetId;
                    clear_login_attempts('verify_' . $username, $ip);
                    header('Location: password_reset.php?step=reset');
                    exit;
                } else {
                    record_failed_login('verify_' . $username, $ip);
                    $error = 'Invalid or expired verification code. Please try again.';
                }
            }
        }
    }
}

// ─── Step 3: Set new password ─────────────────────────────────────────
if ($step === 'reset') {
    // Must have verified the code
    if (empty($_SESSION['reset_step_allowed']) || $_SESSION['reset_step_allowed'] !== 'reset') {
        header('Location: password_reset.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();

        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        $userType = $_SESSION['reset_user_type'] ?? '';
        $userId = $_SESSION['reset_user_id'] ?? 0;
        $resetId = $_SESSION['reset_code_id'] ?? 0;
        $username = $_SESSION['reset_username'] ?? '';

        if ($password === '' || $confirm === '') {
            $error = 'Please fill in both password fields.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $pwError = validate_password($password);
            if ($pwError !== '') {
                $error = $pwError;
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                // Update the password in the correct table
                if ($userType === 'student') {
                    // Students may have password_hash or password column
                    try {
                        $updStmt = $pdo->prepare('UPDATE students SET password_hash = :h WHERE id = :id');
                        $updStmt->execute([':h' => $hash, ':id' => $userId]);
                    } catch (\PDOException $e) {
                        $updStmt = $pdo->prepare('UPDATE students SET password = :h WHERE id = :id');
                        $updStmt->execute([':h' => $hash, ':id' => $userId]);
                    }

                    // Clear import flags if set
                    try {
                        $pdo->prepare('UPDATE students SET must_change_password = 0, registration_incomplete = 0 WHERE id = :id')
                             ->execute([':id' => $userId]);
                    } catch (\PDOException $e) {
                        // Columns may not exist
                    }
                } elseif ($userType === 'parent') {
                    try {
                        $updStmt = $pdo->prepare('UPDATE parents SET password_hash = :h WHERE id = :id');
                        $updStmt->execute([':h' => $hash, ':id' => $userId]);
                    } catch (\PDOException $e) {
                        // parents table structure issue
                        $error = 'Unable to update password. Please contact an administrator.';
                    }
                }

                if ($error === '') {
                    // Mark the reset code as used
                    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')
                         ->execute([':id' => $resetId]);

                    // Expire all other unused codes for this user
                    $pdo->prepare(
                        'UPDATE password_resets SET used_at = NOW()
                         WHERE user_type = :ut AND user_id = :uid AND used_at IS NULL'
                    )->execute([':ut' => $userType, ':uid' => $userId]);

                    // Audit log
                    if (function_exists('audit_log')) {
                        audit_log('password_reset_completed', [
                            'description' => "Password reset completed for {$userType} ID {$userId} ({$username})",
                            'entity_type' => $userType,
                            'entity_id'   => $userId,
                            'user_type'   => 'system',
                            'username'    => 'system',
                        ]);
                    }

                    // Clean up session reset data
                    unset(
                        $_SESSION['reset_username'],
                        $_SESSION['reset_user_type'],
                        $_SESSION['reset_user_id'],
                        $_SESSION['reset_code_id'],
                        $_SESSION['reset_step_allowed']
                    );

                    $success = 'Your password has been reset successfully! You can now sign in.';
                }
            }
        }
    }
}

// Current step number for the progress indicator
$stepNumber = match ($step) {
    'verify' => 2,
    'reset'  => 3,
    default  => 1,
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password &mdash; <?= htmlspecialchars($theme['studio_name']) ?></title>
    <?php if (!empty($theme['favicon_url'])): ?>
        <link rel="icon" href="<?= htmlspecialchars($theme['favicon_url']) ?>">
    <?php endif; ?>
    <style><?= theme_css_vars() ?></style>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <?php if (!empty($theme['logo_url'])): ?>
                <img src="<?= htmlspecialchars($theme['logo_url']) ?>"
                     alt="<?= htmlspecialchars($theme['studio_name']) ?>"
                     class="login-logo">
            <?php endif; ?>

            <h1 class="login-title">Reset Password</h1>

            <!-- Step indicator -->
            <div class="step-indicator">
                <div class="step-item">
                    <span class="step-circle <?= $stepNumber >= 1 ? 'active' : '' ?> <?= $stepNumber > 1 ? 'completed' : '' ?>"><?= $stepNumber > 1 ? '&#10003;' : '1' ?></span>
                    <span class="step-label <?= $stepNumber >= 1 ? 'active' : '' ?>">Request</span>
                </div>
                <div class="step-connector <?= $stepNumber >= 2 ? 'completed' : '' ?>"></div>
                <div class="step-item">
                    <span class="step-circle <?= $stepNumber >= 2 ? 'active' : '' ?> <?= $stepNumber > 2 ? 'completed' : '' ?>"><?= $stepNumber > 2 ? '&#10003;' : '2' ?></span>
                    <span class="step-label <?= $stepNumber >= 2 ? 'active' : '' ?>">Verify</span>
                </div>
                <div class="step-connector <?= $stepNumber >= 3 ? 'completed' : '' ?>"></div>
                <div class="step-item">
                    <span class="step-circle <?= $stepNumber >= 3 ? 'active' : '' ?>">3</span>
                    <span class="step-label <?= $stepNumber >= 3 ? 'active' : '' ?>">Reset</span>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if ($step === 'request' && !$success): ?>
                <!-- Step 1: Request Code -->
                <form method="POST" action="password_reset.php?step=request" class="login-form" autocomplete="on">
                    <?= csrf_field() ?>
                    <p class="form-hint">Enter the username or email associated with your account. We'll send you a verification code.</p>
                    <div class="form-group">
                        <label for="username">Username or Email</label>
                        <input type="text" id="username" name="username"
                               placeholder="Enter your username or email" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Send Verification Code</button>
                </form>
            <?php elseif ($step === 'request' && $success): ?>
                <!-- Step 1 success: proceed to step 2 -->
                <div class="form-actions" style="margin-top: 1.5rem;">
                    <a href="password_reset.php?step=verify" class="btn btn-primary btn-block">Enter Verification Code</a>
                </div>

            <?php elseif ($step === 'verify'): ?>
                <!-- Step 2: Enter Code -->
                <form method="POST" action="password_reset.php?step=verify" class="login-form">
                    <?= csrf_field() ?>
                    <p class="form-hint">Enter the 6-digit verification code sent to your email, phone, or messaging inbox.</p>
                    <div class="form-group">
                        <label for="code">Verification Code</label>
                        <input type="text" id="code" name="code"
                               class="verification-code-input"
                               inputmode="numeric" pattern="[0-9]{6}"
                               maxlength="6" placeholder="000000"
                               autocomplete="one-time-code" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Verify Code</button>
                </form>

            <?php elseif ($step === 'reset' && !$success): ?>
                <!-- Step 3: New Password -->
                <form method="POST" action="password_reset.php?step=reset" class="login-form">
                    <?= csrf_field() ?>
                    <p class="form-hint">Choose a new password. It must be at least 8 characters with uppercase, lowercase, and a number.</p>
                    <div class="form-group">
                        <label for="password">New Password</label>
                        <input type="password" id="password" name="password"
                               placeholder="Enter new password" required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm">Confirm Password</label>
                        <input type="password" id="password_confirm" name="password_confirm"
                               placeholder="Confirm new password" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
                </form>

            <?php elseif ($step === 'reset' && $success): ?>
                <!-- Step 3 success -->
                <div class="form-actions" style="margin-top: 1.5rem;">
                    <a href="login.php" class="btn btn-primary btn-block">Sign In</a>
                </div>
            <?php endif; ?>

            <div class="login-footer">
                <a href="login.php">&larr; Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>
