<?php
/**
 * register.php — Student Self-Registration
 *
 * Allows prospective students to create their own account with a
 * username and password.  On success they are logged in and sent
 * straight to the student portal.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';

auth_start_session();

// Already logged in — go to portal.
if (current_user_type() === 'student') {
    header('Location: student_portal.php');
    exit;
}

$theme  = get_theme();
$errors = [];
$form   = [
    'username'   => '',
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'phone'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Collect & sanitise input.
    $form['username']   = trim($_POST['username']   ?? '');
    $form['first_name'] = trim($_POST['first_name'] ?? '');
    $form['last_name']  = trim($_POST['last_name']  ?? '');
    $form['email']      = trim($_POST['email']      ?? '');
    $form['phone']      = trim($_POST['phone']      ?? '');
    $password           = $_POST['password']         ?? '';
    $password_confirm   = $_POST['password_confirm'] ?? '';

    // Validate.
    if ($form['username'] === '') {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $form['username'])) {
        $errors[] = 'Username must be 3–50 characters and contain only letters, numbers, or underscores.';
    }

    if ($form['first_name'] === '') {
        $errors[] = 'First name is required.';
    }
    if ($form['last_name'] === '') {
        $errors[] = 'Last name is required.';
    }

    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    $pwError = validate_password($password);
    if ($pwError !== '') {
        $errors[] = $pwError;
    } elseif ($password !== $password_confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Check for duplicate username.
    if (empty($errors)) {
        $pdo  = get_db();
        $stmt = $pdo->prepare('SELECT id FROM students WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $form['username']]);
        if ($stmt->fetch()) {
            $errors[] = 'That username is already taken.';
        }
    }

    // Create the account.
    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $insert = $pdo->prepare(
            'INSERT INTO students (username, password_hash, first_name, last_name, email, phone, belt_rank, join_date)
             VALUES (:u, :h, :fn, :ln, :em, :ph, :br, CURDATE())'
        );
        $insert->execute([
            ':u'  => $form['username'],
            ':h'  => $hash,
            ':fn' => $form['first_name'],
            ':ln' => $form['last_name'],
            ':em' => $form['email'] ?: null,
            ':ph' => $form['phone'] ?: null,
            ':br' => 'White',
        ]);

        // Fetch the new student and log them in immediately.
        $newId = $pdo->lastInsertId();
        $stmt  = $pdo->prepare('SELECT * FROM students WHERE id = :id');
        $stmt->execute([':id' => $newId]);
        $student = $stmt->fetch();

        login_student($student);
        header('Location: student_portal.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — <?= htmlspecialchars($theme['studio_name']) ?></title>
    <?php if (!empty($theme['favicon_url'])): ?>
        <link rel="icon" href="<?= htmlspecialchars($theme['favicon_url']) ?>">
    <?php endif; ?>
    <style><?= theme_css_vars() ?></style>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container register-container">
        <div class="login-card">
            <?php if (!empty($theme['logo_url'])): ?>
                <img src="<?= htmlspecialchars($theme['logo_url']) ?>"
                     alt="<?= htmlspecialchars($theme['studio_name']) ?>"
                     class="login-logo">
            <?php endif; ?>

            <h1 class="login-title"><?= htmlspecialchars($theme['studio_name']) ?></h1>
            <p class="login-tagline">Create Your Account</p>

            <?php if ($errors): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $e): ?>
                        <div><?= htmlspecialchars($e) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php" class="login-form" autocomplete="on">
                <?= csrf_field() ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name"
                               value="<?= htmlspecialchars($form['first_name']) ?>"
                               placeholder="First name" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name"
                               value="<?= htmlspecialchars($form['last_name']) ?>"
                               placeholder="Last name" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username"
                           value="<?= htmlspecialchars($form['username']) ?>"
                           placeholder="Choose a username" required
                           pattern="[a-zA-Z0-9_]{3,50}"
                           title="3–50 characters: letters, numbers, underscores">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email"
                           value="<?= htmlspecialchars($form['email']) ?>"
                           placeholder="Optional">
                </div>

                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input type="text" id="phone" name="phone"
                           value="<?= htmlspecialchars($form['phone']) ?>"
                           placeholder="Optional">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password"
                               placeholder="Min 8 chars, mixed case + number" required>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm">Confirm Password *</label>
                        <input type="password" id="password_confirm" name="password_confirm"
                               placeholder="Re-enter password" required>
                    </div>
                </div>

                <p class="password-hint">
                    At least 8 characters with uppercase, lowercase, and a number.
                </p>

                <button type="submit" class="btn btn-primary btn-block">Create Account</button>
            </form>

            <div class="login-footer">
                <a href="login.php" class="admin-link">&larr; Already have an account? Sign In</a>
            </div>
        </div>
    </div>
</body>
</html>
