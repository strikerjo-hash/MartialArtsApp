<?php
/**
 * login.php — Unified Student Login
 *
 * Authenticates students and redirects them straight to the student
 * portal on success.  No intermediate student_login.php page.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';

auth_start_session();

// If the student is already logged in, send them to the portal.
if (current_user_type() === 'student') {
    header('Location: student_portal.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $student = authenticate_student($username, $password);

        if ($student) {
            // Log the student in and go straight to the portal.
            login_student($student);
            header('Location: student_portal.php');
            exit;
        } else {
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
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           value="<?= htmlspecialchars($username ?? '') ?>"
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
                <a href="admin_login.php" class="admin-link">Staff / Instructor Login &rarr;</a>
            </div>
        </div>
    </div>
</body>
</html>
