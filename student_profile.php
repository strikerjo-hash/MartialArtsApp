<?php
/**
 * student_profile.php — Edit Student Profile
 *
 * Lets the student update their personal information and change their
 * password.  Belt rank and join date are read-only (managed by admins).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/parent_auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/db.php';

require_student();
require_registration_complete();

$theme     = get_theme();
$pdo       = get_db();
$studentId = $_SESSION['user_id'];

// Fetch linked parent accounts
$linkedParents = get_student_parents($studentId);

// Load current profile.
$stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $studentId]);
$student = $stmt->fetch();

$success = '';
$errors  = [];

// ---------- Handle profile update ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    verify_csrf();

    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $email      = trim($_POST['email']      ?? '');
    $phone      = trim($_POST['phone']      ?? '');

    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name === '')  $errors[] = 'Last name is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        $upd = $pdo->prepare(
            'UPDATE students SET first_name = :fn, last_name = :ln, email = :em, phone = :ph WHERE id = :id'
        );
        $upd->execute([
            ':fn' => $first_name,
            ':ln' => $last_name,
            ':em' => $email ?: null,
            ':ph' => $phone ?: null,
            ':id' => $studentId,
        ]);

        // Refresh session values.
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name']  = $last_name;

        // Reload student row.
        $stmt->execute([':id' => $studentId]);
        $student = $stmt->fetch();

        $success = 'Profile updated successfully.';
    }
}

// ---------- Handle password change ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    verify_csrf();

    $current  = $_POST['current_password']  ?? '';
    $newpw    = $_POST['new_password']       ?? '';
    $confirm  = $_POST['confirm_password']   ?? '';

    if (!password_verify($current, $student['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    }

    $pwErr = validate_password($newpw);
    if ($pwErr !== '') {
        $errors[] = $pwErr;
    } elseif ($newpw !== $confirm) {
        $errors[] = 'New passwords do not match.';
    }

    if (empty($errors)) {
        $hash = password_hash($newpw, PASSWORD_DEFAULT);
        $upd  = $pdo->prepare('UPDATE students SET password_hash = :h WHERE id = :id');
        $upd->execute([':h' => $hash, ':id' => $studentId]);

        $success = 'Password changed successfully.';

        // Reload student row.
        $stmt->execute([':id' => $studentId]);
        $student = $stmt->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — <?= htmlspecialchars($theme['studio_name']) ?></title>
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
            <a href="student_payment.php" class="btn btn-sm btn-outline">Payment Methods</a>
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

        <!-- Personal Information -->
        <section class="card">
            <h2>Personal Information</h2>
            <form method="POST" action="student_profile.php" class="branding-form">
                <?= csrf_field() ?>
                <input type="hidden" name="update_profile" value="1">

                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name"
                               value="<?= htmlspecialchars($student['first_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name"
                               value="<?= htmlspecialchars($student['last_name']) ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email"
                               value="<?= htmlspecialchars($student['email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="text" id="phone" name="phone"
                               value="<?= htmlspecialchars($student['phone'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" value="<?= htmlspecialchars($student['username']) ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>Belt Rank</label>
                        <input type="text" value="<?= htmlspecialchars($student['belt_rank']) ?>" disabled>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
        </section>

        <!-- Change Password -->
        <section class="card">
            <h2>Change Password</h2>
            <form method="POST" action="student_profile.php" class="branding-form">
                <?= csrf_field() ?>
                <input type="hidden" name="change_password" value="1">

                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password"
                               placeholder="Min 8 chars, mixed case + number" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                </div>

                <p class="password-hint">
                    At least 8 characters with uppercase, lowercase, and a number.
                </p>

                <button type="submit" class="btn btn-primary">Change Password</button>
            </form>
        </section>

        <!-- Family / Parent Account -->
        <section class="card">
            <h2>Family Account</h2>
            <?php if (!empty($linkedParents)): ?>
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <span style="font-size: 1.3em;">👨‍👩‍👧‍👦</span>
                        <strong style="color: #1e40af;">Linked to Parent Account</strong>
                    </div>
                    <p style="color: #374151; font-size: 0.9em; margin-bottom: 12px;">
                        Your account is managed under a family/parent account. Payment methods and event registrations may be handled by your parent.
                    </p>
                    <?php foreach ($linkedParents as $lp): ?>
                        <div style="background: white; border-radius: 6px; padding: 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 12px;">
                            <div style="width: 40px; height: 40px; background: #dbeafe; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #2563eb; font-size: 0.85em;">
                                <?= strtoupper(substr($lp['first_name'], 0, 1) . substr($lp['last_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600; color: #1f2937;">
                                    <?= htmlspecialchars($lp['first_name'] . ' ' . $lp['last_name']) ?>
                                    <span style="color: #6b7280; font-weight: normal; font-size: 0.85em; margin-left: 4px;">(<?= htmlspecialchars(ucfirst($lp['relationship'])) ?>)</span>
                                </div>
                                <?php if ($lp['email']): ?>
                                    <div style="color: #6b7280; font-size: 0.85em;"><?= htmlspecialchars($lp['email']) ?></div>
                                <?php endif; ?>
                                <?php if ($lp['phone']): ?>
                                    <div style="color: #6b7280; font-size: 0.85em;"><?= htmlspecialchars($lp['phone']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color: #6b7280; font-size: 0.9em;">
                    Your account is not linked to a parent/family account. If a parent or guardian manages your membership, ask them to link your account from their family portal.
                </p>
            <?php endif; ?>
        </section>
    </main>

    <footer class="portal-footer">
        <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($theme['studio_name']) ?>. All rights reserved.</p>
    </footer>
</body>
</html>
