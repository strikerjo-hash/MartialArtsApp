<?php
require_once 'config.php';
requireLogin();

// Only admins can manage users
if (getCurrentUser()['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                // Check if username already exists
                $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $check->execute([sanitizeInput($_POST['username'])]);
                
                if ($check->fetch()) {
                    $message = showAlert('Username already exists!', 'error');
                } else {
                    $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (username, password, email, full_name, role)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        sanitizeInput($_POST['username']),
                        $password_hash,
                        sanitizeInput($_POST['email']),
                        sanitizeInput($_POST['full_name']),
                        $_POST['role']
                    ]);
                    $message = showAlert('User added successfully!', 'success');
                }
                break;
                
            case 'delete':
                // Prevent deleting yourself
                if ($_POST['user_id'] == $_SESSION['user_id']) {
                    $message = showAlert('You cannot delete your own account!', 'error');
                } else {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$_POST['user_id']]);
                    $message = showAlert('User deleted successfully!', 'success');
                }
                break;
                
            case 'reset_password':
                $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$new_password, $_POST['user_id']]);
                $message = showAlert('Password reset successfully!', 'success');
                break;
        }
    }
}

// Get all users
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">User Management</h1>
        <button onclick="document.getElementById('addModal').classList.remove('hidden')" 
                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
            + Add User
        </button>
    </div>
    
    <!-- Users Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Username</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Full Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($users as $user): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="font-medium text-gray-900"><?php echo $user['username']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php echo $user['full_name']; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php echo $user['email']; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $roleColors = [
                                'admin' => 'bg-red-100 text-red-800',
                                'instructor' => 'bg-blue-100 text-blue-800',
                                'staff' => 'bg-green-100 text-green-800'
                            ];
                            ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $roleColors[$user['role']]; ?>">
                                <?php echo ucfirst($user['role']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php echo formatDate($user['created_at']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <button onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo addslashes($user['username']); ?>')"
                                    class="text-blue-600 hover:text-blue-900 mr-3">
                                Reset Password
                            </button>
                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this user?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                                </form>
                            <?php else: ?>
                                <span class="text-gray-400">(Current User)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add User Modal -->
<div id="addModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add New User</h3>
            <button onclick="document.getElementById('addModal').classList.add('hidden')" 
                    class="text-gray-600 hover:text-gray-800">✕</button>
        </div>
        
        <form method="POST" class="space-y-4">
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
                </select>
                <p class="text-xs text-gray-500 mt-1">
                    Staff: Basic access • Instructor: Can teach classes • Admin: Full access
                </p>
            </div>
            
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

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Reset Password</h3>
            <button onclick="document.getElementById('resetPasswordModal').classList.add('hidden')" 
                    class="text-gray-600 hover:text-gray-800">✕</button>
        </div>
        
        <form method="POST" class="space-y-4">
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
function resetPassword(userId, username) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_username').textContent = username;
    document.getElementById('resetPasswordModal').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
