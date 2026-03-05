<?php
/**
 * login.php — Unified Student Login
 *
 * Authenticates students via username + password and redirects them
 * straight to the student portal on success.
 * Staff/Admins use admin_login.php instead.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/parent_auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/db.php';

auth_start_session();

// ─── Idempotent migrations for login dependencies ──────────────────
$pdo = get_db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attempts_user (username, attempted_at),
        INDEX idx_attempts_ip (ip_address, attempted_at)
    )");
} catch (\PDOException $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS studio_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        config_key VARCHAR(100) NOT NULL UNIQUE,
        config_value TEXT NOT NULL
    )");
} catch (\PDOException $e) {}

// If already logged in, redirect to the right place.
if (current_user_type() === 'admin') {
    header('Location: index.php');
    exit;
}
if (current_user_type() === 'student') {
    if (!empty($_SESSION['must_change_password']) || !empty($_SESSION['registration_incomplete'])) {
        header('Location: complete_registration.php');
        exit;
    }
    header('Location: ' . (is_student_payment_locked() ? 'student_payment.php?lockout=1' : 'student_portal.php'));
    exit;
}
if (current_user_type() === 'parent') {
    // Legacy parent accounts — redirect to parent portal
    header('Location: parent_portal.php');
    exit;
}

$error = '';
$username_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $username_value = $username;

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $wait = check_rate_limit($username, $ip);

        if ($wait > 0) {
            $error = "Too many failed attempts. Please wait {$wait} seconds before trying again.";
        } else {
            // Try student login first
            $student = authenticate_student($username, $password, $ip);

            if ($student) {
                login_student($student);

                // Imported students must complete registration first
                if (!empty($_SESSION['must_change_password']) || !empty($_SESSION['registration_incomplete'])) {
                    header('Location: complete_registration.php');
                    exit;
                }

                header('Location: ' . (is_student_payment_locked() ? 'student_payment.php?lockout=1' : 'student_portal.php'));
                exit;
            }

            // Try legacy parent login
            $parent = authenticate_parent($username, $password, $ip);
            if ($parent) {
                // If the legacy parent was matched to a promoted student,
                // log them in as that student (student-as-parent model)
                if (!empty($parent['_legacy_parent'])) {
                    login_student($parent);
                    header('Location: parent_portal.php');
                    exit;
                }
                login_parent($parent);
                header('Location: parent_portal.php');
                exit;
            }

            $error = 'Invalid username or password.';
        }
    }
}

$theme = get_theme();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login — <?= htmlspecialchars($theme['studio_name']) ?></title>
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

            <h1 class="login-title"><?= htmlspecialchars($theme['studio_name']) ?></h1>
            <p class="login-tagline"><?= htmlspecialchars($theme['studio_tagline']) ?></p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="login-form" autocomplete="on">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <input type="text" id="username" name="username"
                           value="<?= htmlspecialchars($username_value) ?>"
                           placeholder="Enter your username or email" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password"
                           placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Sign In</button>
            </form>

            <div class="login-footer">
                <p><a href="register.php">New student? Create an account</a></p>
                <a href="admin_login.php" class="admin-link">Staff / Instructor Login &rarr;</a>
            </div>
        </div>
    </div>
</body>
</html>
