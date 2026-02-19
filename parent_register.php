<?php
/**
 * parent_register.php — Parent/Family Account Self-Registration
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/parent_auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/db.php';

auth_start_session();

// Redirect if already logged in
if (current_user_type() === 'parent') {
    header('Location: parent_portal.php');
    exit;
}
if (current_user_type() === 'student') {
    header('Location: student_portal.php');
    exit;
}

$pdo = get_db();
$errors = [];
$success = false;
$form = [
    'username'   => '',
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'phone'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form['username']   = trim($_POST['username'] ?? '');
    $form['first_name'] = trim($_POST['first_name'] ?? '');
    $form['last_name']  = trim($_POST['last_name'] ?? '');
    $form['email']      = trim($_POST['email'] ?? '');
    $form['phone']      = trim($_POST['phone'] ?? '');
    $password           = $_POST['password'] ?? '';
    $password_confirm   = $_POST['password_confirm'] ?? '';

    // Validate
    if ($form['username'] === '') {
        $errors[] = 'Username is required.';
    } elseif (strlen($form['username']) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }
    if ($form['first_name'] === '') {
        $errors[] = 'First name is required.';
    }
    if ($form['last_name'] === '') {
        $errors[] = 'Last name is required.';
    }
    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $password_confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Check username uniqueness
    if (empty($errors)) {
        $check = $pdo->prepare("SELECT id FROM parents WHERE username = ? LIMIT 1");
        $check->execute([$form['username']]);
        if ($check->fetch()) {
            $errors[] = 'Username is already taken.';
        }
    }

    // Check email uniqueness if provided
    if (empty($errors) && $form['email'] !== '') {
        $check = $pdo->prepare("SELECT id FROM parents WHERE email = ? LIMIT 1");
        $check->execute([$form['email']]);
        if ($check->fetch()) {
            $errors[] = 'Email is already associated with another parent account.';
        }
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO parents (username, password_hash, first_name, last_name, email, phone, status)
            VALUES (?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([
            $form['username'],
            $hash,
            $form['first_name'],
            $form['last_name'],
            $form['email'] ?: null,
            $form['phone'] ?: null,
        ]);

        // Auto-login
        $parent = $pdo->prepare("SELECT * FROM parents WHERE id = ?");
        $parent->execute([$pdo->lastInsertId()]);
        $parent = $parent->fetch();
        login_parent($parent);

        header('Location: parent_portal.php');
        exit;
    }
}

$theme = get_theme();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Registration — <?= htmlspecialchars($theme['studio_name']) ?></title>
    <?php if (!empty($theme['favicon_url'])): ?>
        <link rel="icon" href="<?= htmlspecialchars($theme['favicon_url']) ?>">
    <?php endif; ?>
    <style><?= theme_css_vars() ?></style>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container" style="max-width: 500px;">
        <div class="login-card">
            <?php if (!empty($theme['logo_url'])): ?>
                <img src="<?= htmlspecialchars($theme['logo_url']) ?>"
                     alt="<?= htmlspecialchars($theme['studio_name']) ?>"
                     class="login-logo">
            <?php endif; ?>

            <h1 class="login-title"><?= htmlspecialchars($theme['studio_name']) ?></h1>
            <p class="login-tagline">Create a Family / Parent Account</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $err): ?>
                        <p><?= htmlspecialchars($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="login-form" autocomplete="on">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username" required
                           value="<?= htmlspecialchars($form['username']) ?>"
                           placeholder="Choose a username" minlength="3">
                </div>

                <div style="display: flex; gap: 12px;">
                    <div class="form-group" style="flex:1;">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" required
                               value="<?= htmlspecialchars($form['first_name']) ?>"
                               placeholder="First name">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" required
                               value="<?= htmlspecialchars($form['last_name']) ?>"
                               placeholder="Last name">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email"
                           value="<?= htmlspecialchars($form['email']) ?>"
                           placeholder="your@email.com">
                </div>

                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone"
                           value="<?= htmlspecialchars($form['phone']) ?>"
                           placeholder="(555) 123-4567">
                </div>

                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" required
                           placeholder="At least 6 characters" minlength="6">
                </div>

                <div class="form-group">
                    <label for="password_confirm">Confirm Password *</label>
                    <input type="password" id="password_confirm" name="password_confirm" required
                           placeholder="Re-enter password">
                </div>

                <button type="submit" class="btn btn-primary btn-block">Create Account</button>
            </form>

            <div class="login-footer">
                <p><a href="login.php">Already have an account? Sign in</a></p>
                <p><a href="register.php">Register as a student instead</a></p>
            </div>
        </div>
    </div>
</body>
</html>
