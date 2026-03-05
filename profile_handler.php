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
