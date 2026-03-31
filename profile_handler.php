<?php
/**
 * Profile Handler — manages profile editing and password changes for ALL users.
 * Accessible by clicking the user name in the header dropdown.
 * No settings permissions required — every logged-in user can edit their own profile.
 */
require_once 'config.php';
requireLogin();

// ── POST: Save Profile (name + email) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    verify_csrf();

    $newEmail = trim($_POST['profile_email'] ?? '');
    $newName  = trim($_POST['profile_full_name'] ?? '');

    if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['profile_message'] = 'Please enter a valid email address.';
        $_SESSION['profile_message_type'] = 'error';
    } else {
        $stmt = $pdo->prepare("UPDATE users SET email = ?, full_name = ? WHERE id = ?");
        $stmt->execute([$newEmail, $newName, $_SESSION['user_id']]);

        // Update session cache so the header shows the new name immediately
        if (isset($_SESSION['user_cache'])) {
            $_SESSION['user_cache']['full_name'] = $newName;
            $_SESSION['user_cache']['email'] = $newEmail;
        }

        $_SESSION['profile_message'] = 'Profile updated successfully!';
        $_SESSION['profile_message_type'] = 'success';
    }

    $redirect = $_POST['redirect_to'] ?? $_SERVER['HTTP_REFERER'] ?? 'index.php';
    header('Location: ' . $redirect);
    exit;
}

// ── POST: Upload Profile Photo ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_admin_photo'])) {
    verify_csrf();

    if (!empty($_FILES['profile_photo']['name'])) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 2 * 1024 * 1024;
        $file = $_FILES['profile_photo'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['profile_message'] = 'File upload failed. Please try again.';
            $_SESSION['profile_message_type'] = 'error';
        } elseif ($file['size'] > $maxSize) {
            $_SESSION['profile_message'] = 'Photo must be under 2MB.';
            $_SESSION['profile_message_type'] = 'error';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (!in_array($mime, $allowedTypes)) {
                $_SESSION['profile_message'] = 'Only JPG, PNG, GIF, and WebP images are allowed.';
                $_SESSION['profile_message_type'] = 'error';
            } else {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $photoDir = __DIR__ . '/uploads/photos';
                if (!is_dir($photoDir)) { mkdir($photoDir, 0750, true); }

                // Delete old photo
                $oldPhoto = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
                $oldPhoto->execute([$_SESSION['user_id']]);
                $oldPath = $oldPhoto->fetchColumn();
                if ($oldPath && file_exists(__DIR__ . '/' . $oldPath)) {
                    unlink(__DIR__ . '/' . $oldPath);
                }

                $filename = 'admin_' . $_SESSION['user_id'] . '_' . time() . '.' . strtolower($ext);
                $path = $photoDir . '/' . $filename;
                if (move_uploaded_file($file['tmp_name'], $path)) {
                    $dbPath = 'uploads/photos/' . $filename;
                    $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?")->execute([$dbPath, $_SESSION['user_id']]);
                    if (isset($_SESSION['user_cache'])) {
                        $_SESSION['user_cache']['profile_photo'] = $dbPath;
                    }
                    $_SESSION['profile_message'] = 'Profile photo updated!';
                    $_SESSION['profile_message_type'] = 'success';
                } else {
                    $_SESSION['profile_message'] = 'Failed to save photo. Please try again.';
                    $_SESSION['profile_message_type'] = 'error';
                }
            }
        }
    }

    $redirect = $_POST['redirect_to'] ?? $_SERVER['HTTP_REFERER'] ?? 'index.php';
    header('Location: ' . $redirect);
    exit;
}

// ── POST: Remove Profile Photo ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_admin_photo'])) {
    verify_csrf();
    $oldPhoto = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
    $oldPhoto->execute([$_SESSION['user_id']]);
    $oldPath = $oldPhoto->fetchColumn();
    if ($oldPath && file_exists(__DIR__ . '/' . $oldPath)) {
        unlink(__DIR__ . '/' . $oldPath);
    }
    $pdo->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?")->execute([$_SESSION['user_id']]);
    if (isset($_SESSION['user_cache'])) {
        $_SESSION['user_cache']['profile_photo'] = null;
    }
    $_SESSION['profile_message'] = 'Profile photo removed.';
    $_SESSION['profile_message_type'] = 'success';

    $redirect = $_POST['redirect_to'] ?? $_SERVER['HTTP_REFERER'] ?? 'index.php';
    header('Location: ' . $redirect);
    exit;
}

// ── POST: Change Password ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    verify_csrf();

    $current_user = getCurrentUser();

    if (!password_verify($_POST['current_password'], $current_user['password'])) {
        $_SESSION['profile_message'] = 'Current password is incorrect!';
        $_SESSION['profile_message_type'] = 'error';
    } elseif ($_POST['new_password'] !== $_POST['confirm_password']) {
        $_SESSION['profile_message'] = 'New passwords do not match!';
        $_SESSION['profile_message_type'] = 'error';
    } elseif (strlen($_POST['new_password']) < 6) {
        $_SESSION['profile_message'] = 'New password must be at least 6 characters.';
        $_SESSION['profile_message_type'] = 'error';
    } else {
        $new_hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$new_hash, $_SESSION['user_id']]);

        $_SESSION['profile_message'] = 'Password changed successfully!';
        $_SESSION['profile_message_type'] = 'success';
    }

    $redirect = $_POST['redirect_to'] ?? $_SERVER['HTTP_REFERER'] ?? 'index.php';
    header('Location: ' . $redirect);
    exit;
}

// ── GET: Change Password Page ──
if (isset($_GET['action']) && $_GET['action'] === 'password') {
    $current_user = getCurrentUser();
    include 'includes/header.php';
    ?>
    <div class="container mx-auto px-4 py-8 max-w-lg">
        <?php
        if (!empty($_SESSION['profile_message'])) {
            echo showAlert($_SESSION['profile_message'], $_SESSION['profile_message_type'] ?? 'info');
            unset($_SESSION['profile_message'], $_SESSION['profile_message_type']);
        }
        ?>
        <!-- Profile Photo -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Profile Photo</h2>
            <div class="flex items-center gap-4">
                <?php if (!empty($current_user['profile_photo'])): ?>
                    <img src="<?= htmlspecialchars($current_user['profile_photo']) ?>" alt="Profile"
                         class="w-20 h-20 rounded-full object-cover border-4 border-blue-600">
                <?php else: ?>
                    <div class="w-20 h-20 rounded-full bg-blue-600 flex items-center justify-center text-white text-2xl font-bold">
                        <?= strtoupper(substr($current_user['full_name'] ?? 'A', 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div>
                    <form method="POST" action="profile_handler.php" enctype="multipart/form-data" class="flex items-center gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="upload_admin_photo" value="1">
                        <input type="hidden" name="redirect_to" value="profile_handler.php?action=password">
                        <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp" class="text-sm">
                        <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg">Upload</button>
                    </form>
                    <?php if (!empty($current_user['profile_photo'])): ?>
                        <form method="POST" action="profile_handler.php" class="mt-2"
                              onsubmit="return confirm('Remove your profile photo?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="remove_admin_photo" value="1">
                            <input type="hidden" name="redirect_to" value="profile_handler.php?action=password">
                            <button type="submit" class="text-sm text-red-600 hover:text-red-700 underline">Remove Photo</button>
                        </form>
                    <?php endif; ?>
                    <p class="text-xs text-gray-500 mt-1">JPG, PNG, GIF, or WebP. Max 2MB.</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Change Password</h1>
            <form method="POST" action="profile_handler.php" class="space-y-4">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="redirect_to" value="profile_handler.php?action=password">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                    <input type="password" name="current_password" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                    <input type="password" name="new_password" required minlength="6"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                    <input type="password" name="confirm_password" required minlength="6"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div class="flex items-center gap-3">
                    <button type="submit" name="change_password" value="1"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                        Update Password
                    </button>
                    <a href="javascript:history.back()" class="text-gray-500 hover:text-gray-700 text-sm">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php
    include 'includes/footer.php';
    exit;
}

// Default: redirect back
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
exit;
