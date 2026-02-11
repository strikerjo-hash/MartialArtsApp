<?php
/**
 * login.php — Unified Student Login
 *
 * Authenticates students via username + password and redirects them
 * straight to the student portal on success.
 * Staff/Admins use admin_login.php instead.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/db.php';

auth_start_session();

// If already logged in, redirect to the right place.
if (current_user_type() === 'admin') {
    header('Location: index.php');
    exit;
}
if (current_user_type() === 'student') {
    header('Location: student_portal.php');
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
            $student = authenticate_student($username, $password, $ip);

            if ($student) {
                login_student($student);
                header('Location: student_portal.php');
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
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
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           value="<?= htmlspecialchars($username_value) ?>"
                           placeholder="Enter your username" required autofocus>
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
