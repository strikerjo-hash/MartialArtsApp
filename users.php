<?php
require_once 'config.php';
requireLogin();
if (!canView('users.php')) { accessDenied(); }

$currentRole = getCurrentUser()['role'];
$isSuperAdmin = is_super_admin();
$allSchools = get_all_schools();

$message = '';

// Handle form submissions — super admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$isSuperAdmin) {
        $message = showAlert('Only Super Admins can manage users.', 'error');
    } elseif (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                // Check if username already exists at this school
                $params = [sanitizeInput($_POST['username'])];
                $check = $pdo->prepare("SELECT id FROM users WHERE username = ?" . school_where());
                school_param($params);
                $check->execute($params);

                if ($check->fetch()) {
                    $message = showAlert('Username already exists!', 'error');
                } else {
                    $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $role = $_POST['role'];
                    if ($role === 'super_admin' && !$isSuperAdmin) {
                        $role = 'admin';
                    }
                    $stmt = $pdo->prepare("
                        INSERT INTO users (school_id, username, password, email, full_name, role)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        current_school_id(),
                        sanitizeInput($_POST['username']),
                        $password_hash,
                        sanitizeInput($_POST['email']),
                        sanitizeInput($_POST['full_name']),
                        $role
                    ]);
                    $newUserId = (int) $pdo->lastInsertId();

                    // Insert into user_schools junction table
                    $assignedSchools = !empty($_POST['schools']) ? $_POST['schools'] : [current_school_id()];
                    $insUs = $pdo->prepare("INSERT IGNORE INTO user_schools (user_id, school_id) VALUES (?, ?)");
                    foreach ($assignedSchools as $sid) {
                        $insUs->execute([$newUserId, (int) $sid]);
                    }

                    $message = showAlert('User added successfully!', 'success');
                }
                break;

            case 'edit':
                $editId = (int) ($_POST['user_id'] ?? 0);
                if (!$editId) {
                    $message = showAlert('Invalid user.', 'error');
                    break;
                }

                $newUsername = trim(sanitizeInput($_POST['username'] ?? ''));
                $newFullName = trim(sanitizeInput($_POST['full_name'] ?? ''));
                $newEmail = trim(sanitizeInput($_POST['email'] ?? ''));
                $newRole = $_POST['role'] ?? 'staff';

                // Only super_admin can assign super_admin role
                if ($newRole === 'super_admin' && !$isSuperAdmin) {
                    $newRole = 'admin';
                }

                if (empty($newUsername)) {
                    $message = showAlert('Username cannot be empty.', 'error');
                    break;
                }

                if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $message = showAlert('Please enter a valid email address.', 'error');
                    break;
                }

                // Check username uniqueness (exclude current user)
                $params = [$newUsername, $editId];
                $check = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?" . school_where());
                school_param($params);
                $check->execute($params);
                if ($check->fetch()) {
                    $message = showAlert('Username already taken by another user.', 'error');
                    break;
                }

                $params = [$newUsername, $newFullName, $newEmail, $newRole, $editId];
                $stmt = $pdo->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, role = ? WHERE id = ?" . school_where());
                school_param($params);
                $stmt->execute($params);
                $message = showAlert('User updated successfully!', 'success');
                break;

            case 'assign_schools':
                $userId = (int) ($_POST['user_id'] ?? 0);
                if (!$userId) {
                    $message = showAlert('Invalid user.', 'error');
                    break;
                }

                $selectedSchools = $_POST['schools'] ?? [];
                if (empty($selectedSchools)) {
                    $message = showAlert('A user must be assigned to at least one school.', 'error');
                    break;
                }

                // Replace all school assignments
                $pdo->prepare("DELETE FROM user_schools WHERE user_id = ?")->execute([$userId]);
                $insUs = $pdo->prepare("INSERT INTO user_schools (user_id, school_id) VALUES (?, ?)");
                foreach ($selectedSchools as $sid) {
                    $insUs->execute([$userId, (int) $sid]);
                }

                // Update users.school_id to the first selected school (home school)
                $homeSchool = (int) $selectedSchools[0];
                $pdo->prepare("UPDATE users SET school_id = ? WHERE id = ?")->execute([$homeSchool, $userId]);

                $message = showAlert('School assignments updated successfully!', 'success');
                break;

            case 'delete':
                // Prevent deleting yourself
                if ($_POST['user_id'] == $_SESSION['user_id']) {
                    $message = showAlert('You cannot delete your own account!', 'error');
                } else {
                    $delId = (int) $_POST['user_id'];
                    // Delete from user_schools first
                    $pdo->prepare("DELETE FROM user_schools WHERE user_id = ?")->execute([$delId]);
                    $params = [$delId];
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?" . school_where());
                    school_param($params);
                    $stmt->execute($params);
                    $message = showAlert('User deleted successfully!', 'success');
                }
                break;

            case 'reset_password':
                $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $params = [$new_password, $_POST['user_id']];
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?" . school_where());
                school_param($params);
                $stmt->execute($params);
                $message = showAlert('Password reset successfully!', 'success');
                break;
        }
    }
}

// Get all users with their school assignments
$params = [];
$sql = "
    SELECT u.id, u.username, u.full_name, u.email, u.role, u.school_id, u.created_at,
           GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR '||') as school_names,
           GROUP_CONCAT(us.school_id ORDER BY s.name SEPARATOR ',') as school_ids
    FROM users u
    LEFT JOIN user_schools us ON u.id = us.user_id
    LEFT JOIN schools s ON us.school_id = s.id
    WHERE 1=1" . user_school_where('u') . "
    GROUP BY u.id
    ORDER BY u.created_at DESC
    LIMIT 500
";
user_school_param($params);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">User Management</h1>
            <?php if (!$isSuperAdmin): ?>
                <p class="text-sm text-gray-500 mt-1">View only — contact a Super Admin to make changes</p>
            <?php endif; ?>
        </div>
        <?php if ($isSuperAdmin): ?>
            <button onclick="document.getElementById('addModal').classList.remove('hidden')"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                + Add User
            </button>
        <?php endif; ?>
    </div>

    <!-- Users Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Username</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Full Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Schools</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                    <?php if ($isSuperAdmin): ?>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($users as $user): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($user['username']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php echo htmlspecialchars($user['full_name']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php echo htmlspecialchars($user['email']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $roleColors = [
                                'super_admin' => 'bg-yellow-100 text-yellow-800',
                                'admin' => 'bg-red-100 text-red-800',
                                'instructor' => 'bg-blue-100 text-blue-800',
                                'staff' => 'bg-green-100 text-green-800'
                            ];
                            $roleColor = $roleColors[$user['role']] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $roleColor; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <?php
                            $schoolNamesList = $user['school_names'] ? explode('||', $user['school_names']) : [];
                            if ($schoolNamesList):
                                foreach ($schoolNamesList as $sName): ?>
                                    <span class="inline-block px-2 py-0.5 text-xs font-medium rounded bg-purple-100 text-purple-800 mr-1 mb-1">
                                        <?php echo htmlspecialchars($sName); ?>
                                    </span>
                                <?php endforeach;
                            else: ?>
                                <span class="text-xs text-gray-400">None</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php echo formatDate($user['created_at']); ?>
                        </td>
                        <?php if ($isSuperAdmin): ?>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <button onclick="editUser(<?php echo htmlspecialchars(json_encode([
                                    'id' => $user['id'],
                                    'username' => $user['username'],
                                    'full_name' => $user['full_name'],
                                    'email' => $user['email'],
                                    'role' => $user['role'],
                                ])); ?>)"
                                        class="text-indigo-600 hover:text-indigo-900 mr-3">
                                    Edit
                                </button>
                                <button onclick="manageSchools(<?php echo $user['id']; ?>, '<?php echo addslashes($user['full_name'] ?: $user['username']); ?>', '<?php echo $user['school_ids'] ?? ''; ?>')"
                                        class="text-purple-600 hover:text-purple-900 mr-3">
                                    Schools
                                </button>
                                <button onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo addslashes($user['username']); ?>')"
                                        class="text-blue-600 hover:text-blue-900 mr-3">
                                    Reset PW
                                </button>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this user?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-gray-400">(You)</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div><!-- /overflow-x-auto -->
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<!-- Add User Modal -->
<div id="addModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add New User</h3>
            <button onclick="document.getElementById('addModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Username *</label>
                <input type="text" name="username" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                <input type="text" name="full_name" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                <input type="email" name="email" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Password *</label>
                <input type="password" name="password" required minlength="6"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                <p class="text-xs text-gray-500 mt-1">Minimum 6 characters</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                <select name="role" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="staff">Staff</option>
                    <option value="instructor">Instructor</option>
                    <option value="admin">Admin</option>
                    <option value="super_admin">Super Admin</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">
                    Staff: Basic access &bull; Instructor: Can teach classes &bull; Admin: Full access &bull; Super Admin: All schools
                </p>
            </div>

            <?php if (count($allSchools) > 1): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Assign to Schools</label>
                <div class="border border-gray-300 rounded-lg p-3 max-h-40 overflow-y-auto space-y-1">
                    <?php foreach ($allSchools as $school): ?>
                        <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                            <input type="checkbox" name="schools[]" value="<?php echo $school['id']; ?>"
                                   <?php echo ((int)$school['id'] === (int)current_school_id()) ? 'checked' : ''; ?>
                                   class="w-4 h-4 text-purple-600 rounded">
                            <?php echo htmlspecialchars($school['name']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-500 mt-1">Current school is pre-selected. Check all schools this user should have access to.</p>
            </div>
            <?php endif; ?>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Add User
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit User</h3>
            <button onclick="document.getElementById('editModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="edit_user_id">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Username *</label>
                <input type="text" name="username" id="edit_username" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                <input type="text" name="full_name" id="edit_full_name" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" id="edit_email"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                <select name="role" id="edit_role" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="staff">Staff</option>
                    <option value="instructor">Instructor</option>
                    <option value="admin">Admin</option>
                    <option value="super_admin">Super Admin</option>
                </select>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Manage Schools Modal -->
<div id="schoolsModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Manage School Assignments</h3>
            <button onclick="document.getElementById('schoolsModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>

        <p class="text-sm text-gray-600 mb-4">
            Assigning schools for: <span id="schools_user_name" class="font-semibold"></span>
        </p>

        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_schools">
            <input type="hidden" name="user_id" id="schools_user_id">

            <div class="border border-gray-300 rounded-lg p-3 max-h-60 overflow-y-auto space-y-2">
                <?php foreach ($allSchools as $school): ?>
                    <label class="flex items-center gap-3 p-2 rounded hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" name="schools[]" value="<?php echo $school['id']; ?>"
                               id="school_assign_<?php echo $school['id']; ?>"
                               class="w-4 h-4 text-purple-600 rounded school-assign-cb">
                        <span class="text-sm text-gray-700"><?php echo htmlspecialchars($school['name']); ?></span>
                        <?php if ($school['status'] !== 'active'): ?>
                            <span class="text-xs text-gray-400">(<?php echo htmlspecialchars($school['status']); ?>)</span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="text-xs text-gray-500">Select all schools this user should have access to. At least one school is required.</p>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('schoolsModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg">
                    Save Assignments
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Reset Password</h3>
            <button onclick="document.getElementById('resetPasswordModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset_user_id">

            <p class="text-sm text-gray-600">
                Reset password for: <span id="reset_username" class="font-semibold"></span>
            </p>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">New Password *</label>
                <input type="password" name="new_password" required minlength="6"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('resetPasswordModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Reset Password
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editUser(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_full_name').value = user.full_name;
    document.getElementById('edit_email').value = user.email || '';
    document.getElementById('edit_role').value = user.role;
    document.getElementById('editModal').classList.remove('hidden');
}

function manageSchools(userId, userName, schoolIds) {
    document.getElementById('schools_user_id').value = userId;
    document.getElementById('schools_user_name').textContent = userName;

    // Uncheck all first
    document.querySelectorAll('.school-assign-cb').forEach(function(cb) { cb.checked = false; });

    // Check the user's current schools
    if (schoolIds) {
        schoolIds.split(',').forEach(function(sid) {
            var cb = document.getElementById('school_assign_' + sid.trim());
            if (cb) cb.checked = true;
        });
    }

    document.getElementById('schoolsModal').classList.remove('hidden');
}

function resetPassword(userId, username) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_username').textContent = username;
    document.getElementById('resetPasswordModal').classList.remove('hidden');
}
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
