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
$stmtSql = 'SELECT * FROM students WHERE id = :id';
if (!is_viewing_all_schools()) { $stmtSql .= ' AND school_id = :school_id'; }
$stmtSql .= ' LIMIT 1';
$stmt = $pdo->prepare($stmtSql);
$stmt->bindValue(':id', $studentId, PDO::PARAM_INT);
if (!is_viewing_all_schools()) { $stmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
$stmt->execute();
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
        $updSql = 'UPDATE students SET first_name = :fn, last_name = :ln, email = :em, phone = :ph WHERE id = :id';
        if (!is_viewing_all_schools()) { $updSql .= ' AND school_id = :school_id'; }
        $upd = $pdo->prepare($updSql);
        $upd->bindValue(':fn', $first_name);
        $upd->bindValue(':ln', $last_name);
        $upd->bindValue(':em', $email ?: null);
        $upd->bindValue(':ph', $phone ?: null);
        $upd->bindValue(':id', $studentId, PDO::PARAM_INT);
        if (!is_viewing_all_schools()) { $upd->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
        $upd->execute();

        // Refresh session values.
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name']  = $last_name;

        // Reload student row.
        $stmt->bindValue(':id', $studentId, PDO::PARAM_INT);
        if (!is_viewing_all_schools()) { $stmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
        $stmt->execute();
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
        $updPwSql = 'UPDATE students SET password_hash = :h WHERE id = :id';
        if (!is_viewing_all_schools()) { $updPwSql .= ' AND school_id = :school_id'; }
        $upd = $pdo->prepare($updPwSql);
        $upd->bindValue(':h', $hash);
        $upd->bindValue(':id', $studentId, PDO::PARAM_INT);
        if (!is_viewing_all_schools()) { $upd->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
        $upd->execute();

        $success = 'Password changed successfully.';

        // Reload student row.
        $stmt->bindValue(':id', $studentId, PDO::PARAM_INT);
        if (!is_viewing_all_schools()) { $stmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
        $stmt->execute();
        $student = $stmt->fetch();
    }
}

// ---------- Handle profile photo upload ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    verify_csrf();

    if (!empty($_FILES['profile_photo']['name'])) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        $file = $_FILES['profile_photo'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed. Please try again.';
        } elseif ($file['size'] > $maxSize) {
            $errors[] = 'Photo must be under 2MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (!in_array($mime, $allowedTypes)) {
                $errors[] = 'Only JPG, PNG, GIF, and WebP images are allowed.';
            } else {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $photoDir = __DIR__ . '/uploads/photos';
                if (!is_dir($photoDir)) { mkdir($photoDir, 0750, true); }

                // Delete old photo if exists
                if (!empty($student['photo']) && file_exists(__DIR__ . '/' . $student['photo'])) {
                    unlink(__DIR__ . '/' . $student['photo']);
                }

                $filename = 'student_' . $studentId . '_' . time() . '.' . strtolower($ext);
                $path = $photoDir . '/' . $filename;
                if (move_uploaded_file($file['tmp_name'], $path)) {
                    $dbPath = 'uploads/photos/' . $filename;
                    $updSql = 'UPDATE students SET photo = :photo WHERE id = :id';
                    if (!is_viewing_all_schools()) { $updSql .= ' AND school_id = :school_id'; }
                    $upd = $pdo->prepare($updSql);
                    $upd->bindValue(':photo', $dbPath);
                    $upd->bindValue(':id', $studentId, PDO::PARAM_INT);
                    if (!is_viewing_all_schools()) { $upd->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
                    $upd->execute();
                    $student['photo'] = $dbPath;
                    $success = 'Profile photo updated!';
                } else {
                    $errors[] = 'Failed to save photo. Please try again.';
                }
            }
        }
    } else {
        $errors[] = 'Please select a photo to upload.';
    }
}

// ---------- Handle profile photo removal ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_photo'])) {
    verify_csrf();
    if (!empty($student['photo']) && file_exists(__DIR__ . '/' . $student['photo'])) {
        unlink(__DIR__ . '/' . $student['photo']);
    }
    $updSql = 'UPDATE students SET photo = NULL WHERE id = :id';
    if (!is_viewing_all_schools()) { $updSql .= ' AND school_id = :school_id'; }
    $upd = $pdo->prepare($updSql);
    $upd->bindValue(':id', $studentId, PDO::PARAM_INT);
    if (!is_viewing_all_schools()) { $upd->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
    $upd->execute();
    $student['photo'] = null;
    $success = 'Profile photo removed.';
}

// ---------- Handle communication consent update ----------
require_once __DIR__ . '/includes/messaging.php';
$comm_consent_text    = get_comm_consent_text();
$comm_consent_version = get_comm_consent_version();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_comm_consent'])) {
    verify_csrf();

    $wantEmail = !empty($_POST['comm_consent_email']) ? 1 : 0;
    $wantSms   = !empty($_POST['comm_consent_sms'])   ? 1 : 0;
    $consentNow = date('Y-m-d H:i:s');
    $consentIp  = $_SERVER['REMOTE_ADDR'] ?? '';

    try {
        $updSql = 'UPDATE students SET
            comm_consent_email = :ce,
            comm_consent_email_at = :cea,
            comm_consent_sms = :cs,
            comm_consent_sms_at = :csa,
            comm_consent_version = :cv,
            comm_consent_ip = :cip
            WHERE id = :id';
        if (!is_viewing_all_schools()) { $updSql .= ' AND school_id = :school_id'; }
        $upd = $pdo->prepare($updSql);
        $upd->bindValue(':ce',  $wantEmail, PDO::PARAM_INT);
        $upd->bindValue(':cea', $wantEmail ? $consentNow : null);
        $upd->bindValue(':cs',  $wantSms,   PDO::PARAM_INT);
        $upd->bindValue(':csa', $wantSms   ? $consentNow : null);
        $upd->bindValue(':cv',  ($wantEmail || $wantSms) ? $comm_consent_version : null);
        $upd->bindValue(':cip', ($wantEmail || $wantSms) ? $consentIp : null);
        $upd->bindValue(':id',  $studentId, PDO::PARAM_INT);
        if (!is_viewing_all_schools()) { $upd->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
        $upd->execute();

        // Reload student row
        $stmt->bindValue(':id', $studentId, PDO::PARAM_INT);
        if (!is_viewing_all_schools()) { $stmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
        $stmt->execute();
        $student = $stmt->fetch();

        $success = 'Communication preferences updated.';
    } catch (\PDOException $e) {
        $errors[] = 'Unable to update communication preferences. Please try again.';
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

        <!-- Profile Photo -->
        <section class="card">
            <h2>Profile Photo</h2>
            <div style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 1rem;">
                <?php if (!empty($student['photo'])): ?>
                    <img src="<?= htmlspecialchars($student['photo']) ?>" alt="Profile"
                         style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid var(--primary-color, #3b82f6);">
                <?php else: ?>
                    <div style="width: 80px; height: 80px; border-radius: 50%; background: var(--primary-color, #3b82f6); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 2rem; font-weight: bold;">
                        <?= strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div>
                    <form method="POST" action="student_profile.php" enctype="multipart/form-data" style="display: flex; gap: 0.5rem; align-items: center;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="upload_photo" value="1">
                        <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp"
                               style="font-size: 0.85rem;">
                        <button type="submit" class="btn btn-sm btn-primary">Upload</button>
                    </form>
                    <?php if (!empty($student['photo'])): ?>
                        <form method="POST" action="student_profile.php" style="margin-top: 0.5rem;"
                              onsubmit="return confirm('Remove your profile photo?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="remove_photo" value="1">
                            <button type="submit" class="btn btn-sm btn-outline" style="color: #dc2626; border-color: #dc2626;">Remove Photo</button>
                        </form>
                    <?php endif; ?>
                    <p style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">JPG, PNG, GIF, or WebP. Max 2MB.</p>
                </div>
            </div>
        </section>

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

        <!-- Communication Preferences -->
        <section class="card">
            <h2>Communication Preferences</h2>
            <p style="color:#6b7280; font-size:0.9em; margin-bottom:1rem;">
                Manage your consent for receiving email and SMS/text message communications from <?= htmlspecialchars($theme['studio_name']) ?>.
            </p>
            <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:1rem; margin-bottom:1rem; max-height:180px; overflow-y:auto; font-size:0.85rem; color:#374151; line-height:1.5;">
                <?= nl2br(htmlspecialchars($comm_consent_text)) ?>
            </div>
            <form method="POST" action="student_profile.php" class="branding-form">
                <?= csrf_field() ?>
                <input type="hidden" name="update_comm_consent" value="1">
                <div style="margin-bottom:1rem;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin-bottom:0.5rem;">
                        <input type="checkbox" name="comm_consent_email" value="1"
                               <?= !empty($student['comm_consent_email']) ? 'checked' : '' ?>>
                        <span>I consent to receive <strong>email</strong> communications</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="comm_consent_sms" value="1"
                               <?= !empty($student['comm_consent_sms']) ? 'checked' : '' ?>>
                        <span>I consent to receive <strong>SMS/text message</strong> communications</span>
                    </label>
                </div>
                <?php if (!empty($student['comm_consent_email_at']) || !empty($student['comm_consent_sms_at'])): ?>
                <p style="font-size:0.8rem; color:#9ca3af; margin-bottom:0.75rem;">
                    Last updated:
                    <?php if (!empty($student['comm_consent_email_at'])): ?>
                        Email consent <?= date('M j, Y g:i A', strtotime($student['comm_consent_email_at'])) ?>
                    <?php endif; ?>
                    <?php if (!empty($student['comm_consent_sms_at'])): ?>
                        <?= !empty($student['comm_consent_email_at']) ? ' · ' : '' ?>SMS consent <?= date('M j, Y g:i A', strtotime($student['comm_consent_sms_at'])) ?>
                    <?php endif; ?>
                    <?php if (!empty($student['comm_consent_version'])): ?>
                        · Version <?= htmlspecialchars($student['comm_consent_version']) ?>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">Save Preferences</button>
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
