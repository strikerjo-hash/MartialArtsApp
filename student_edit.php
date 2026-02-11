<?php
/**
 * student_edit.php — Edit Student (Admin / Staff / Instructor)
 *
 * Allows staff to update all student fields: personal info, contact,
 * emergency contacts, status, notes, and optionally reset the password
 * or change the username.
 */

require_once 'config.php';
requireLogin();

$student_id = $_GET['id'] ?? 0;
$message = '';

// Load the student.
$stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: students.php');
    exit;
}

// Detect available columns so the form adapts to the active schema.
$cols     = $pdo->query('SHOW COLUMNS FROM students')->fetchAll();
$colNames = array_column($cols, 'Field');

$hasUsername    = in_array('username', $colNames, true);
$hasAddress     = in_array('address', $colNames, true);
$hasDob         = in_array('date_of_birth', $colNames, true);
$hasEmergency   = in_array('emergency_contact_name', $colNames, true);
$hasNotes       = in_array('notes', $colNames, true);
$hasStatus      = in_array('status', $colNames, true);
$hasIsActive    = in_array('is_active', $colNames, true);
$hasBeltRank    = in_array('belt_rank', $colNames, true);

// ---------- Handle form submission ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Collect fields.
    $first_name = sanitizeInput($_POST['first_name'] ?? '');
    $last_name  = sanitizeInput($_POST['last_name']  ?? '');
    $email      = sanitizeInput($_POST['email']      ?? '');
    $phone      = sanitizeInput($_POST['phone']      ?? '');

    if ($first_name === '' || $last_name === '') {
        $message = showAlert('First name and last name are required.', 'error');
    } else {
        // Build a dynamic UPDATE to handle both schemas.
        $fields  = ['first_name = ?', 'last_name = ?', 'email = ?', 'phone = ?'];
        $params  = [$first_name, $last_name, $email ?: null, $phone ?: null];

        if ($hasUsername) {
            $newUsername = sanitizeInput($_POST['username'] ?? '');
            if ($newUsername !== '') {
                // Check uniqueness if it changed.
                if ($newUsername !== ($student['username'] ?? '')) {
                    $chk = $pdo->prepare('SELECT id FROM students WHERE username = ? AND id != ?');
                    $chk->execute([$newUsername, $student_id]);
                    if ($chk->fetch()) {
                        $message = showAlert('That username is already taken by another student.', 'error');
                    }
                }
                if ($message === '') {
                    $fields[] = 'username = ?';
                    $params[] = $newUsername;
                }
            }
        }

        if ($hasDob) {
            $fields[] = 'date_of_birth = ?';
            $params[] = $_POST['date_of_birth'] ?: null;
        }

        if ($hasAddress) {
            $fields[] = 'address = ?';
            $params[] = sanitizeInput($_POST['address'] ?? '');
        }

        if ($hasEmergency) {
            $fields[] = 'emergency_contact_name = ?';
            $params[] = sanitizeInput($_POST['emergency_contact_name'] ?? '');
            $fields[] = 'emergency_contact_phone = ?';
            $params[] = sanitizeInput($_POST['emergency_contact_phone'] ?? '');
        }

        if ($hasNotes) {
            $fields[] = 'notes = ?';
            $params[] = sanitizeInput($_POST['notes'] ?? '');
        }

        if ($hasStatus) {
            $fields[] = 'status = ?';
            $params[] = $_POST['status'] ?? 'active';
        }

        if ($hasIsActive) {
            $fields[] = 'is_active = ?';
            $params[] = (int) ($_POST['is_active'] ?? 1);
        }

        if ($hasBeltRank) {
            $fields[] = 'belt_rank = ?';
            $params[] = sanitizeInput($_POST['belt_rank'] ?? 'White');
        }

        $fields[] = 'join_date = ?';
        $params[] = $_POST['join_date'] ?: $student['join_date'];

        // Password reset (optional — only if a value was provided).
        $newPassword = $_POST['new_password'] ?? '';
        if ($newPassword !== '') {
            $pwErr = validate_password($newPassword);
            if ($pwErr !== '') {
                $message = showAlert($pwErr, 'error');
            } else {
                $hashCol = in_array('password_hash', $colNames, true) ? 'password_hash' : 'password';
                $fields[] = "$hashCol = ?";
                $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
        }

        if ($message === '') {
            $params[] = $student_id;
            $sql = 'UPDATE students SET ' . implode(', ', $fields) . ' WHERE id = ?';
            $pdo->prepare($sql)->execute($params);

            $message = showAlert('Student updated successfully!', 'success');

            // Reload the student row.
            $stmt->execute([$student_id]);
            $student = $stmt->fetch();
        }
    }
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <a href="student_detail.php?id=<?php echo $student_id; ?>" class="text-blue-600 hover:text-blue-800">&larr; Back to Student Detail</a>
            <h1 class="text-3xl font-bold text-gray-800 mt-2">
                Edit Student: <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
            </h1>
        </div>
    </div>

    <?php echo $message; ?>

    <form method="POST" class="space-y-6">
        <?= csrf_field() ?>

        <!-- Personal Information -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Personal Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                    <input type="text" name="first_name" required
                           value="<?php echo htmlspecialchars($student['first_name']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                    <input type="text" name="last_name" required
                           value="<?php echo htmlspecialchars($student['last_name']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email"
                           value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="tel" name="phone"
                           value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <?php if ($hasDob): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                    <input type="date" name="date_of_birth"
                           value="<?php echo htmlspecialchars($student['date_of_birth'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <?php endif; ?>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Join Date</label>
                    <input type="date" name="join_date"
                           value="<?php echo htmlspecialchars($student['join_date'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <?php if ($hasAddress): ?>
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <textarea name="address" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
            </div>
            <?php endif; ?>
        </div>

        <!-- Emergency Contact -->
        <?php if ($hasEmergency): ?>
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Emergency Contact</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Name</label>
                    <input type="text" name="emergency_contact_name"
                           value="<?php echo htmlspecialchars($student['emergency_contact_name'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Phone</label>
                    <input type="tel" name="emergency_contact_phone"
                           value="<?php echo htmlspecialchars($student['emergency_contact_phone'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Account & Status -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Account &amp; Status</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <?php if ($hasUsername): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" name="username"
                           value="<?php echo htmlspecialchars($student['username'] ?? ''); ?>"
                           pattern="[a-zA-Z0-9_]{3,50}"
                           title="3–50 characters: letters, numbers, underscores"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <?php endif; ?>

                <?php if ($hasStatus): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="active"    <?php echo ($student['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive"  <?php echo ($student['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="suspended" <?php echo ($student['status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($hasIsActive): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Active</label>
                    <select name="is_active"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="1" <?php echo ($student['is_active'] ?? 1) ? 'selected' : ''; ?>>Yes</option>
                        <option value="0" <?php echo !($student['is_active'] ?? 1) ? 'selected' : ''; ?>>No</option>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($hasBeltRank): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Belt Rank</label>
                    <input type="text" name="belt_rank"
                           value="<?php echo htmlspecialchars($student['belt_rank'] ?? 'White'); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Password Reset -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Reset Password</h2>
            <p class="text-sm text-gray-500 mb-4">Leave blank to keep the current password. If set, the student will need to use the new password on their next login.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                    <input type="password" name="new_password" autocomplete="new-password"
                           placeholder="Min 8 chars, mixed case + number"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
        </div>

        <!-- Notes -->
        <?php if ($hasNotes): ?>
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Notes</h2>
            <textarea name="notes" rows="4"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"><?php echo htmlspecialchars($student['notes'] ?? ''); ?></textarea>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="flex justify-between items-center">
            <a href="student_detail.php?id=<?php echo $student_id; ?>"
               class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg font-medium">
                Save Changes
            </button>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>
