<?php
require_once 'config.php';
requireLogin();

$message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $stmt = $pdo->prepare("
                    INSERT INTO students (first_name, last_name, email, phone, date_of_birth, 
                                        address, emergency_contact_name, emergency_contact_phone, 
                                        join_date, status, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    sanitizeInput($_POST['first_name']),
                    sanitizeInput($_POST['last_name']),
                    sanitizeInput($_POST['email']),
                    sanitizeInput($_POST['phone']),
                    $_POST['date_of_birth'],
                    sanitizeInput($_POST['address']),
                    sanitizeInput($_POST['emergency_contact_name']),
                    sanitizeInput($_POST['emergency_contact_phone']),
                    $_POST['join_date'],
                    $_POST['status'],
                    sanitizeInput($_POST['notes'])
                ]);
                $message = showAlert('Student added successfully!', 'success');
                break;
                
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
                $stmt->execute([$_POST['student_id']]);
                $message = showAlert('Student deleted successfully!', 'success');
                break;
        }
    }
}

// Get all students with their current belt
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

$query = "
    SELECT s.*, 
           COALESCE(belt_info.belt_name, 'No Belt') as current_belt,
           COALESCE(belt_info.style_name, '-') as belt_style,
           COALESCE(m.status, 'No Membership') as membership_status
    FROM students s
    LEFT JOIN (
        SELECT sb.student_id, b.name as belt_name, mas.name as style_name
        FROM student_belts sb
        JOIN belts b ON sb.belt_id = b.id
        JOIN martial_arts_styles mas ON sb.style_id = mas.id
        WHERE sb.id IN (
            SELECT MAX(id) FROM student_belts GROUP BY student_id
        )
    ) belt_info ON s.id = belt_info.student_id
    LEFT JOIN (
        SELECT student_id, status
        FROM memberships
        WHERE end_date >= CURDATE()
        ORDER BY end_date DESC
        LIMIT 1
    ) m ON s.id = m.student_id
    WHERE 1=1
";

if ($search) {
    $query .= " AND (s.first_name LIKE :search OR s.last_name LIKE :search OR s.email LIKE :search)";
}
if ($status_filter) {
    $query .= " AND s.status = :status";
}

$query .= " ORDER BY s.created_at DESC";

$stmt = $pdo->prepare($query);
if ($search) {
    $stmt->bindValue(':search', "%$search%");
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$students = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Students</h1>
        <button onclick="document.getElementById('addModal').classList.remove('hidden')" 
                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
            + Add Student
        </button>
    </div>
    
    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4">
            <input type="text" name="search" placeholder="Search by name or email..." 
                   value="<?php echo htmlspecialchars($search); ?>"
                   class="flex-1 min-w-[200px] px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            
            <select name="status" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                <option value="">All Status</option>
                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="suspended" <?php echo $status_filter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
            </select>
            
            <button type="submit" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded-lg">
                Filter
            </button>
            <a href="students.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg">
                Reset
            </a>
        </form>
    </div>
    
    <!-- Students Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Belt</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Join Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Membership</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($students as $student): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                    <span class="text-blue-600 font-bold">
                                        <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                    </span>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900"><?php echo $student['email'] ?: 'N/A'; ?></div>
                            <div class="text-sm text-gray-500"><?php echo $student['phone'] ?: 'N/A'; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900"><?php echo $student['current_belt']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo $student['belt_style']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo formatDate($student['join_date']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $statusColors = [
                                'active' => 'bg-green-100 text-green-800',
                                'inactive' => 'bg-gray-100 text-gray-800',
                                'suspended' => 'bg-red-100 text-red-800'
                            ];
                            ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusColors[$student['status']]; ?>">
                                <?php echo ucfirst($student['status']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $membershipColors = [
                                'active' => 'bg-green-100 text-green-800',
                                'expired' => 'bg-red-100 text-red-800',
                                'No Membership' => 'bg-gray-100 text-gray-800'
                            ];
                            ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $membershipColors[$student['membership_status']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $student['membership_status'])); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <a href="student_detail.php?id=<?php echo $student['id']; ?>" 
                               class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                            <form method="POST" class="inline" onsubmit="return confirmDelete('Are you sure you want to delete this student?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (empty($students)): ?>
            <div class="text-center py-12 text-gray-500">
                <p class="text-lg">No students found</p>
                <p class="text-sm mt-2">Add your first student to get started</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Student Modal -->
<div id="addModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add New Student</h3>
            <button onclick="document.getElementById('addModal').classList.add('hidden')" 
                    class="text-gray-600 hover:text-gray-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add">
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                    <input type="text" name="first_name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                    <input type="text" name="last_name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="tel" name="phone"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                    <input type="date" name="date_of_birth"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Join Date *</label>
                    <input type="date" name="join_date" value="<?php echo date('Y-m-d'); ?>" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <textarea name="address" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Name</label>
                    <input type="text" name="emergency_contact_name"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Phone</label>
                    <input type="tel" name="emergency_contact_phone"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Add Student
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
