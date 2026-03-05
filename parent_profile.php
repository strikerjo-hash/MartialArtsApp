<?php
/**
 * parent_profile.php — Parent Profile Management
 */

require_once 'config.php';
require_once __DIR__ . '/includes/parent_auth.php';

require_parent();

$parentId = get_effective_parent_id();
$pdo = get_db();
$message = '';

// Determine if this is a student-as-parent or legacy parent
$isStudentParent = (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'student' && !empty($_SESSION['is_parent']));

// Fetch parent profile from the appropriate table
if ($isStudentParent) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND is_parent = 1" . school_where() . " LIMIT 1");
} else {
    $stmt = $pdo->prepare("SELECT * FROM parents WHERE id = ?" . school_where() . " LIMIT 1");
}
$params = [$parentId];
school_param($params);
$stmt->execute($params);
$parent = $stmt->fetch();

if (!$parent) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['update_profile'])) {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $address   = trim($_POST['address'] ?? '');

        if ($firstName === '' || $lastName === '') {
            $message = showAlert('First and last name are required.', 'error');
        } else {
            // Check email uniqueness in the appropriate table
            $tbl = $isStudentParent ? 'students' : 'parents';
            if ($email !== '' && $email !== ($parent['email'] ?? '')) {
                $checkParams = [$email, $parentId];
                $check = $pdo->prepare("SELECT id FROM {$tbl} WHERE email = ? AND id != ?" . school_where() . " LIMIT 1");
                school_param($checkParams);
                $check->execute($checkParams);
                if ($check->fetch()) {
                    $message = showAlert('Email is already in use by another account.', 'error');
                }
            }

            if ($message === '') {
                if ($isStudentParent) {
                    $updParams = [$firstName, $lastName, $email ?: null, $phone ?: null, $parentId];
                    $upd = $pdo->prepare("
                        UPDATE students SET first_name = ?, last_name = ?, email = ?, phone = ?
                        WHERE id = ?" . school_where() . "
                    ");
                    school_param($updParams);
                    $upd->execute($updParams);
                } else {
                    $updParams = [$firstName, $lastName, $email ?: null, $phone ?: null, $address ?: null, $parentId];
                    $upd = $pdo->prepare("
                        UPDATE parents SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?
                        WHERE id = ?" . school_where() . "
                    ");
                    school_param($updParams);
                    $upd->execute($updParams);
                }

                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name']  = $lastName;

                // Re-fetch
                $params = [$parentId];
                school_param($params);
                $stmt->execute($params);
                $parent = $stmt->fetch();
                $message = showAlert('Profile updated successfully!', 'success');
            }
        }
    }

    if (isset($_POST['update_comm_consent'])) {
        require_once __DIR__ . '/includes/messaging.php';
        $ccText    = get_comm_consent_text();
        $ccVersion = get_comm_consent_version();
        $wantEmail = !empty($_POST['comm_consent_email']) ? 1 : 0;
        $wantSms   = !empty($_POST['comm_consent_sms'])   ? 1 : 0;
        $consentNow = date('Y-m-d H:i:s');
        $consentIp  = $_SERVER['REMOTE_ADDR'] ?? '';

        try {
            $tbl = $isStudentParent ? 'students' : 'parents';
            $ccParams = [
                $wantEmail,
                $wantEmail ? $consentNow : null,
                $wantSms,
                $wantSms ? $consentNow : null,
                ($wantEmail || $wantSms) ? $ccVersion : null,
                ($wantEmail || $wantSms) ? $consentIp : null,
                $parentId,
            ];
            $ccSql = "UPDATE {$tbl} SET
                comm_consent_email = ?, comm_consent_email_at = ?,
                comm_consent_sms = ?, comm_consent_sms_at = ?,
                comm_consent_version = ?, comm_consent_ip = ?
                WHERE id = ?" . school_where();
            school_param($ccParams);
            $pdo->prepare($ccSql)->execute($ccParams);

            // Re-fetch
            $params = [$parentId];
            school_param($params);
            $stmt->execute($params);
            $parent = $stmt->fetch();

            $message = showAlert('Communication preferences updated!', 'success');
        } catch (\PDOException $e) {
            $message = showAlert('Unable to update communication preferences.', 'error');
        }
    }

    if (isset($_POST['change_password'])) {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        if (!password_verify($currentPw, $parent['password_hash'])) {
            $message = showAlert('Current password is incorrect.', 'error');
        } elseif (strlen($newPw) < 6) {
            $message = showAlert('New password must be at least 6 characters.', 'error');
        } elseif ($newPw !== $confirmPw) {
            $message = showAlert('New passwords do not match.', 'error');
        } else {
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $tbl = $isStudentParent ? 'students' : 'parents';
            $pwParams = [$hash, $parentId];
            $pwStmt = $pdo->prepare("UPDATE {$tbl} SET password_hash = ? WHERE id = ?" . school_where());
            school_param($pwParams);
            $pwStmt->execute($pwParams);
            $message = showAlert('Password changed successfully!', 'success');
        }
    }
}

include 'includes/parent_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="mb-8">
        <div class="flex items-center gap-3 mb-2">
            <h1 class="text-3xl font-bold text-gray-800">My Profile</h1>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-blue-100 text-blue-800 text-sm font-semibold rounded-full">
                <span>👨‍👩‍👧‍👦</span> Parent Account
            </span>
        </div>
        <p class="text-gray-600">Manage your parent account information and family settings</p>
    </div>

    <!-- Account Type Banner -->
    <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4 flex items-center gap-3">
        <div class="flex-shrink-0 w-10 h-10 bg-blue-200 rounded-full flex items-center justify-center">
            <span class="text-lg">👨‍👩‍👧‍👦</span>
        </div>
        <div>
            <p class="font-semibold text-blue-900">Account Type: Parent / Family Account</p>
            <p class="text-sm text-blue-700">
                You can manage your children's memberships, event registrations, and payment methods from the
                <a href="parent_portal.php" class="underline hover:text-blue-900">Family Dashboard</a>.
                <?php
                $profileChildren = get_parent_children($parentId);
                if (!empty($profileChildren)):
                    $childNames = array_map(fn($c) => $c['first_name'], $profileChildren);
                ?>
                    Currently managing: <strong><?= htmlspecialchars(implode(', ', $childNames)) ?></strong>
                <?php else: ?>
                    No children linked yet.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Profile Info -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Account Information</h2>

            <form method="POST" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="update_profile" value="1">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" value="<?= htmlspecialchars($parent['username']) ?>" disabled
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-500">
                    <p class="text-xs text-gray-400 mt-1">Username cannot be changed</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                        <input type="text" name="first_name" required
                               value="<?= htmlspecialchars($parent['first_name']) ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                        <input type="text" name="last_name" required
                               value="<?= htmlspecialchars($parent['last_name']) ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email"
                           value="<?= htmlspecialchars($parent['email'] ?? '') ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="tel" name="phone"
                           value="<?= htmlspecialchars($parent['phone'] ?? '') ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <textarea name="address" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"><?= htmlspecialchars($parent['address'] ?? '') ?></textarea>
                </div>

                <div class="pt-4">
                    <button type="submit"
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>

        <!-- Change Password -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Change Password</h2>

            <form method="POST" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="change_password" value="1">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Current Password *</label>
                    <input type="password" name="current_password" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">New Password *</label>
                    <input type="password" name="new_password" required minlength="6"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <p class="text-xs text-gray-400 mt-1">Minimum 6 characters</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password *</label>
                    <input type="password" name="confirm_password" required minlength="6"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div class="pt-4">
                    <button type="submit"
                            class="w-full bg-gray-800 hover:bg-gray-900 text-white font-medium py-2 px-4 rounded-lg">
                        Change Password
                    </button>
                </div>
            </form>

            <!-- Account Summary -->
            <div class="mt-8 pt-6 border-t border-gray-200">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Account Summary</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Account Type</span>
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">Parent Account</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Member Since</span>
                        <span class="text-gray-800"><?= formatDate($parent['created_at']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Status</span>
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800"><?= ucfirst($parent['status']) ?></span>
                    </div>
                    <?php if (($parent['account_credit'] ?? 0) > 0): ?>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Account Credit</span>
                            <span class="font-semibold text-green-700"><?= formatMoney($parent['account_credit']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Communication Preferences -->
    <div class="mt-8 bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-2">Communication Preferences</h2>
        <p class="text-sm text-gray-600 mb-4">
            Manage your consent for receiving email and SMS/text message communications.
        </p>
        <?php
        if (!function_exists('get_comm_consent_text')) {
            require_once __DIR__ . '/includes/messaging.php';
        }
        $ccDisplayText    = get_comm_consent_text();
        $ccDisplayVersion = get_comm_consent_version();
        ?>
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 max-h-48 overflow-y-auto mb-4 text-sm text-gray-700 leading-relaxed">
            <?= nl2br(htmlspecialchars($ccDisplayText)) ?>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="update_comm_consent" value="1">
            <div class="space-y-2">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="comm_consent_email" value="1"
                           class="w-5 h-5 text-blue-600 rounded"
                           <?= !empty($parent['comm_consent_email']) ? 'checked' : '' ?>>
                    <span class="text-sm font-medium text-gray-700">I consent to receive <strong>email</strong> communications</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="comm_consent_sms" value="1"
                           class="w-5 h-5 text-blue-600 rounded"
                           <?= !empty($parent['comm_consent_sms']) ? 'checked' : '' ?>>
                    <span class="text-sm font-medium text-gray-700">I consent to receive <strong>SMS/text message</strong> communications</span>
                </label>
            </div>
            <?php if (!empty($parent['comm_consent_email_at']) || !empty($parent['comm_consent_sms_at'])): ?>
            <p class="text-xs text-gray-400">
                Last updated:
                <?php if (!empty($parent['comm_consent_email_at'])): ?>
                    Email consent <?= date('M j, Y g:i A', strtotime($parent['comm_consent_email_at'])) ?>
                <?php endif; ?>
                <?php if (!empty($parent['comm_consent_sms_at'])): ?>
                    <?= !empty($parent['comm_consent_email_at']) ? ' · ' : '' ?>SMS consent <?= date('M j, Y g:i A', strtotime($parent['comm_consent_sms_at'])) ?>
                <?php endif; ?>
                <?php if (!empty($parent['comm_consent_version'])): ?>
                    · Version <?= htmlspecialchars($parent['comm_consent_version']) ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>
            <button type="submit"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">
                Save Preferences
            </button>
        </form>
    </div>
</div>

<?php include 'includes/student_footer.php'; ?>
