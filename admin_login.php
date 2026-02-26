<?php
/**
 * admin_login.php — Staff / Instructor Login
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';

auth_start_session();

// Migrations have been moved to migrate.php

if (current_user_type() === 'admin') {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $wait = check_rate_limit($username, $ip);

        if ($wait > 0) {
            $error = "Too many failed attempts. Please wait {$wait} seconds before trying again.";
        } else {
            $admin = authenticate_admin($username, $password, $ip);

            if ($admin) {
                login_admin($admin);
                header('Location: index.php');
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
    <title>Staff Login — <?= htmlspecialchars($theme['studio_name']) ?></title>
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
            <p class="login-tagline">Staff &amp; Instructor Portal</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="admin_login.php" class="login-form">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           value="<?= htmlspecialchars($username ?? '') ?>"
                           placeholder="Staff username" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password"
                           placeholder="Password" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Staff Sign In</button>
            </form>

            <div class="login-footer">
                <a href="login.php" class="admin-link">&larr; Student Login</a>
            </div>
        </div>
    </div>
</body>
</html>
