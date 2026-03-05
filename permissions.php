<?php
require_once 'config.php';
requireLogin();

// Only admins and super admins can manage permissions
if (!in_array(getCurrentUser()['role'], ['admin', 'super_admin'])) {
    accessDenied('Permission management requires Admin or Super Admin privileges.');
}

$message = '';

// Handle permission updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_permissions'])) {
    verify_csrf();
    $role = $_POST['role'];
    $page = $_POST['page'];
    
    $stmt = $pdo->prepare("
        INSERT INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            can_view = VALUES(can_view),
            can_create = VALUES(can_create),
            can_edit = VALUES(can_edit),
            can_delete = VALUES(can_delete)
    ");
    
    $stmt->execute([
        $role,
        $page,
        isset($_POST['can_view']) ? 1 : 0,
        isset($_POST['can_create']) ? 1 : 0,
        isset($_POST['can_edit']) ? 1 : 0,
        isset($_POST['can_delete']) ? 1 : 0
    ]);
    
    $message = showAlert('Permissions updated successfully!', 'success');
}

// Get all permissions
$permissions = $pdo->query("
    SELECT * FROM role_permissions 
    ORDER BY role, page
")->fetchAll();

// Group by role
$permissions_by_role = [];
foreach ($permissions as $perm) {
    $permissions_by_role[$perm['role']][] = $perm;
}

$roles = ['admin', 'instructor', 'staff', 'student'];
$pages = [
    'index.php' => 'Dashboard',
    'students.php' => 'Students',
    'parent_accounts.php' => 'Parent Accounts',
    'memberships.php' => 'Memberships',
    'classes.php' => 'Classes',
    'events.php' => 'Events',
    'calendar.php' => 'Calendar',
    'attendance.php' => 'Attendance',
    'curriculum.php' => 'Curriculum',
    'makeup_classes.php' => 'Make-Up Classes',
    'payments.php' => 'Payments',
    'belts.php' => 'Belt System',
    'reports_financial.php' => 'Reports — Financial',
    'reports_student.php' => 'Reports — Student Data',
    'messages.php' => 'Messages / Broadcasts',
    'users.php' => 'User Management',
    'settings_school.php' => 'Settings — School & Schedule',
    'settings_belt.php' => 'Settings — Belt Testing',
    'settings_certs.php' => 'Settings — Certificates',
    'settings_registration.php' => 'Settings — Registration',
    'settings_billing.php' => 'Settings — Billing',
    'settings_communications.php' => 'Settings — Communications',
    'settings_system.php' => 'Settings — System',
    'admin_dashboard.php' => 'Studio Branding',
    'import_data.php' => 'Import / Export',
    'merge_accounts.php' => 'Merge Accounts',
    'pending_registrations.php' => 'Pending Registrations',
    'student_portal.php' => 'Student Portal',
    'student_upgrade.php' => 'Upgrade Membership',
];

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Role Permissions</h1>
            <p class="text-gray-600 mt-2">Configure what each role can access and do</p>
        </div>
    </div>
    
    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
        <p class="text-sm text-yellow-700">
            <strong>Important:</strong> Admin role always has full access to everything. 
            Changes here affect Instructor, Staff, and Student roles.
        </p>
    </div>
    
    <!-- Permissions by Role -->
    <div class="space-y-6">
        <?php foreach ($roles as $role): ?>
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200 <?php 
                    $bgColors = [
                        'admin' => 'bg-red-50',
                        'instructor' => 'bg-blue-50',
                        'staff' => 'bg-green-50',
                        'student' => 'bg-purple-50'
                    ];
                    echo $bgColors[$role];
                ?>">
                    <h2 class="text-xl font-semibold text-gray-800 capitalize"><?php echo $role; ?> Permissions</h2>
                </div>
                
                <div class="p-6">
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Page</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">View</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Create</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Edit</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Delete</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php 
                                $role_perms = $permissions_by_role[$role] ?? [];
                                $perm_map = [];
                                foreach ($role_perms as $p) {
                                    $perm_map[$p['page']] = $p;
                                }
                                
                                foreach ($pages as $page_file => $page_name): 
                                    // Skip student-only pages for non-students and vice versa
                                    if ($role === 'student' && !in_array($page_file, ['student_portal.php', 'student_upgrade.php'])) continue;
                                    if ($role !== 'student' && in_array($page_file, ['student_portal.php', 'student_upgrade.php'])) continue;
                                    
                                    $perm = $perm_map[$page_file] ?? ['can_view' => 0, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0];
                                    $is_admin = $role === 'admin';
                                ?>
                                    <tr id="perm-<?php echo $role; ?>-<?php echo $page_file; ?>">
                                        <td class="px-4 py-3 font-medium text-gray-900"><?php echo $page_name; ?></td>
                                        <td class="px-4 py-3 text-center">
                                            <input type="checkbox" 
                                                   data-role="<?php echo $role; ?>" 
                                                   data-page="<?php echo $page_file; ?>"
                                                   data-permission="can_view"
                                                   <?php echo $perm['can_view'] ? 'checked' : ''; ?>
                                                   <?php echo $is_admin ? 'disabled' : ''; ?>
                                                   onchange="updatePermission(this)"
                                                   class="w-4 h-4">
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <input type="checkbox" 
                                                   data-role="<?php echo $role; ?>" 
                                                   data-page="<?php echo $page_file; ?>"
                                                   data-permission="can_create"
                                                   <?php echo $perm['can_create'] ? 'checked' : ''; ?>
                                                   <?php echo $is_admin ? 'disabled' : ''; ?>
                                                   onchange="updatePermission(this)"
                                                   class="w-4 h-4">
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <input type="checkbox" 
                                                   data-role="<?php echo $role; ?>" 
                                                   data-page="<?php echo $page_file; ?>"
                                                   data-permission="can_edit"
                                                   <?php echo $perm['can_edit'] ? 'checked' : ''; ?>
                                                   <?php echo $is_admin ? 'disabled' : ''; ?>
                                                   onchange="updatePermission(this)"
                                                   class="w-4 h-4">
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <input type="checkbox" 
                                                   data-role="<?php echo $role; ?>" 
                                                   data-page="<?php echo $page_file; ?>"
                                                   data-permission="can_delete"
                                                   <?php echo $perm['can_delete'] ? 'checked' : ''; ?>
                                                   <?php echo $is_admin ? 'disabled' : ''; ?>
                                                   onchange="updatePermission(this)"
                                                   class="w-4 h-4">
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span id="status-<?php echo $role; ?>-<?php echo $page_file; ?>" 
                                                  class="text-xs text-gray-500"></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<form id="permission-form" method="POST" style="display: none;">
    <?= csrf_field() ?>
    <input type="hidden" name="update_permissions" value="1">
    <input type="hidden" name="role" id="form_role">
    <input type="hidden" name="page" id="form_page">
    <input type="hidden" name="can_view" id="form_can_view">
    <input type="hidden" name="can_create" id="form_can_create">
    <input type="hidden" name="can_edit" id="form_can_edit">
    <input type="hidden" name="can_delete" id="form_can_delete">
</form>

<script>
function updatePermission(checkbox) {
    const role = checkbox.dataset.role;
    const page = checkbox.dataset.page;
    const permission = checkbox.dataset.permission;
    
    // Get all checkboxes for this role/page combination
    const row = document.getElementById(`perm-${role}-${page}`);
    const checkboxes = row.querySelectorAll('input[type="checkbox"]');
    
    const permissions = {
        can_view: 0,
        can_create: 0,
        can_edit: 0,
        can_delete: 0
    };
    
    checkboxes.forEach(cb => {
        permissions[cb.dataset.permission] = cb.checked ? 1 : 0;
    });
    
    // Update form
    document.getElementById('form_role').value = role;
    document.getElementById('form_page').value = page;
    document.getElementById('form_can_view').value = permissions.can_view;
    document.getElementById('form_can_create').value = permissions.can_create;
    document.getElementById('form_can_edit').value = permissions.can_edit;
    document.getElementById('form_can_delete').value = permissions.can_delete;
    
    // Show saving status
    const status = document.getElementById(`status-${role}-${page}`);
    status.textContent = 'Saving...';
    status.className = 'text-xs text-blue-500';
    
    // Submit form
    const form = document.getElementById('permission-form');
    const formData = new FormData(form);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(() => {
        status.textContent = 'Saved ✓';
        status.className = 'text-xs text-green-500';
        setTimeout(() => {
            status.textContent = '';
        }, 2000);
    })
    .catch(() => {
        status.textContent = 'Error ✗';
        status.className = 'text-xs text-red-500';
    });
}
</script>

<?php include 'includes/footer.php'; ?>
