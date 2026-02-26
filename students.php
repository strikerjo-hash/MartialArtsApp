<?php
require_once 'config.php';
require_once __DIR__ . '/includes/parent_auth.php';
requireLogin();

$message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Promote student to parent account (sets is_parent=1)
    if (isset($_POST['promote_to_parent'])) {
        $promoteStudentId = (int)($_POST['student_id'] ?? 0);
        if ($promoteStudentId) {
            $existingCheck = student_has_parent_account($promoteStudentId);
            if ($existingCheck) {
                $message = showAlert('This student already has parent capabilities enabled.', 'error');
            } else {
                $promoted = promote_student_to_parent($promoteStudentId);
                if ($promoted) {
                    $studentName = htmlspecialchars($promoted['first_name'] . ' ' . $promoted['last_name']);
                    $message = showAlert(
                        'Parent capabilities enabled for ' . $studentName . '. ' .
                        'Go to <a href="student_detail.php?id=' . $promoteStudentId . '" class="underline font-semibold">their student detail page</a> to link children.',
                        'success'
                    );
                } else {
                    $message = showAlert('Failed to enable parent capabilities. Please try again.', 'error');
                }
            }
        }
    }

    // Toggle activity status (active <-> inactive)
    if (isset($_POST['toggle_activity'])) {
        $toggleId = (int)($_POST['student_id'] ?? 0);
        if ($toggleId) {
            $toggleParams = [$toggleId];
            school_param($toggleParams);
            $toggleStmt = $pdo->prepare("SELECT activity_status FROM students WHERE id = ?" . school_where());
            $toggleStmt->execute($toggleParams);
            $currentActivity = $toggleStmt->fetchColumn();

            $newActivity = ($currentActivity === 'inactive') ? 'active' : 'inactive';
            $newInactiveSince = ($newActivity === 'inactive') ? date('Y-m-d') : null;

            $updParams = [$newActivity, $newInactiveSince, $toggleId];
            school_param($updParams);
            $pdo->prepare("UPDATE students SET activity_status = ?, inactive_since = ? WHERE id = ?" . school_where())
                ->execute($updParams);

            $label = ($newActivity === 'inactive') ? 'marked as inactive' : 'marked as active';
            $message = showAlert("Student {$label} successfully!", 'success');
        }
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $stmt = $pdo->prepare("
                    INSERT INTO students (school_id, first_name, last_name, email, phone, date_of_birth,
                                        address, emergency_contact_name, emergency_contact_phone,
                                        join_date, status, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    current_school_id(),
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
                $deleteParams = [$_POST['student_id']];
                school_param($deleteParams);
                $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?" . school_where());
                $stmt->execute($deleteParams);
                $message = showAlert('Student deleted successfully!', 'success');
                break;
        }
    }
}

// Get all students with their current belt
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$membership_filter = $_GET['membership'] ?? '';
$payment_filter = $_GET['payment'] ?? '';
$activity_filter = $_GET['activity'] ?? '';

$query = "
    SELECT s.*,
           COALESCE(belt_info.belt_name, 'No Belt') as current_belt,
           COALESCE(belt_info.style_name, '-') as belt_style,
           COALESCE(m.membership_status, 'No Membership') as membership_status,
           m.plan_name,
           m.payment_status,
           m.end_date as membership_end_date,
           last_att.last_attendance_date
    FROM students s
    LEFT JOIN (
        SELECT student_id, MAX(attendance_date) as last_attendance_date
        FROM attendance
        WHERE status IN ('present', 'late')
        GROUP BY student_id
    ) last_att ON s.id = last_att.student_id
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
        SELECT m1.student_id, m1.status as membership_status,
               mp.name as plan_name, m1.payment_status, m1.end_date
        FROM memberships m1
        JOIN membership_plans mp ON m1.plan_id = mp.id
        WHERE m1.id = (
            SELECT m2.id FROM memberships m2
            WHERE m2.student_id = m1.student_id
            ORDER BY m2.end_date DESC LIMIT 1
        )
    ) m ON s.id = m.student_id
    WHERE 1=1 AND s.school_id = :school_id
";

if ($search) {
    $query .= " AND (s.first_name LIKE :search1 OR s.last_name LIKE :search2 OR s.email LIKE :search3)";
}
if ($status_filter) {
    $query .= " AND s.status = :status";
}
if ($membership_filter === 'active') {
    $query .= " AND m.membership_status = 'active' AND m.end_date >= CURDATE()";
} elseif ($membership_filter === 'expired') {
    $query .= " AND (m.membership_status = 'expired' OR (m.membership_status IS NOT NULL AND m.end_date < CURDATE()))";
} elseif ($membership_filter === 'none') {
    $query .= " AND m.membership_status IS NULL";
}
if ($payment_filter === 'paid') {
    $query .= " AND m.payment_status = 'paid'";
} elseif ($payment_filter === 'pending') {
    $query .= " AND m.payment_status = 'pending'";
} elseif ($payment_filter === 'partial') {
    $query .= " AND m.payment_status = 'partial'";
} elseif ($payment_filter === 'overdue') {
    $query .= " AND m.payment_status IN ('pending', 'partial') AND m.end_date < CURDATE()";
}
if ($activity_filter) {
    $query .= " AND s.activity_status = :activity";
}

$query .= " ORDER BY s.created_at DESC LIMIT 500";

$stmt = $pdo->prepare($query);
if ($search) {
    $searchVal = "%$search%";
    $stmt->bindValue(':search1', $searchVal);
    $stmt->bindValue(':search2', $searchVal);
    $stmt->bindValue(':search3', $searchVal);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
if ($activity_filter) {
    $stmt->bindValue(':activity', $activity_filter);
}
$stmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT);
$stmt->execute();
$students = $stmt->fetchAll();

// Build lookup of students who have parent capabilities (is_parent=1)
$studentsWithParent = [];
try {
    $spParams = [];
    school_param($spParams);
    $spStmt = $pdo->prepare("SELECT id, username FROM students WHERE is_parent = 1" . school_where());
    $spStmt->execute($spParams);
    foreach ($spStmt->fetchAll() as $sp) {
        $studentsWithParent[$sp['id']] = $sp['username'];
    }
} catch (\PDOException $e) {}

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

            <select name="membership" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                <option value="">All Memberships</option>
                <option value="active" <?php echo $membership_filter === 'active' ? 'selected' : ''; ?>>Active Membership</option>
                <option value="expired" <?php echo $membership_filter === 'expired' ? 'selected' : ''; ?>>Expired Membership</option>
                <option value="none" <?php echo $membership_filter === 'none' ? 'selected' : ''; ?>>No Membership</option>
            </select>

            <select name="payment" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                <option value="">All Payments</option>
                <option value="paid" <?php echo $payment_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                <option value="pending" <?php echo $payment_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="partial" <?php echo $payment_filter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                <option value="overdue" <?php echo $payment_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
            </select>

            <select name="activity" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                <option value="">All Activity</option>
                <option value="active" <?php echo $activity_filter === 'active' ? 'selected' : ''; ?>>Actively Attending</option>
                <option value="inactive" <?php echo $activity_filter === 'inactive' ? 'selected' : ''; ?>>Inactive (Not Attending)</option>
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
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Attended</th>
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
                            <?php
                            $activityStatus = $student['activity_status'] ?? 'active';
                            $mStatusForBadge = $student['membership_status'];
                            $hasPaidMembership = ($mStatusForBadge === 'active' && !empty($student['membership_end_date']) && $student['membership_end_date'] >= date('Y-m-d'));
                            if ($activityStatus === 'inactive'): ?>
                                <span class="mt-1 px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $hasPaidMembership ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-600'; ?>">
                                    <?php echo $hasPaidMembership ? 'Inactive - Paying' : 'Not Attending'; ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $lastDate = $student['last_attendance_date'] ?? null;
                            if ($lastDate) {
                                $daysAgo = (int)((strtotime('today') - strtotime($lastDate)) / 86400);
                                if ($daysAgo <= 30) {
                                    $daysBadge = 'bg-green-100 text-green-700';
                                } elseif ($daysAgo <= 60) {
                                    $daysBadge = 'bg-yellow-100 text-yellow-700';
                                } elseif ($daysAgo <= 90) {
                                    $daysBadge = 'bg-orange-100 text-orange-700';
                                } else {
                                    $daysBadge = 'bg-red-100 text-red-700';
                                }
                                echo '<div class="text-sm text-gray-900">' . formatDate($lastDate) . '</div>';
                                echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ' . $daysBadge . '">' . $daysAgo . ' days ago</span>';
                            } else {
                                echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-500">Never</span>';
                            }
                            ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $mStatus = $student['membership_status'];
                            $isCurrentlyActive = ($mStatus === 'active' && !empty($student['membership_end_date']) && $student['membership_end_date'] >= date('Y-m-d'));
                            $isExpired = ($mStatus === 'expired' || ($mStatus === 'active' && !empty($student['membership_end_date']) && $student['membership_end_date'] < date('Y-m-d')));

                            if ($isCurrentlyActive) {
                                $membershipBadge = 'bg-green-100 text-green-800';
                                $membershipLabel = 'Active';
                            } elseif ($mStatus === 'cancelled') {
                                $membershipBadge = 'bg-red-100 text-red-800';
                                $membershipLabel = 'Cancelled';
                            } elseif ($isExpired) {
                                $membershipBadge = 'bg-red-100 text-red-800';
                                $membershipLabel = 'Expired';
                            } elseif ($mStatus === 'No Membership') {
                                $membershipBadge = 'bg-gray-100 text-gray-800';
                                $membershipLabel = 'No Membership';
                            } else {
                                $membershipBadge = 'bg-gray-100 text-gray-800';
                                $membershipLabel = ucfirst($mStatus);
                            }

                            $paymentColors = [
                                'paid' => 'bg-green-100 text-green-700',
                                'pending' => 'bg-yellow-100 text-yellow-700',
                                'partial' => 'bg-orange-100 text-orange-700',
                            ];
                            ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $membershipBadge; ?>">
                                <?php echo $membershipLabel; ?>
                            </span>
                            <?php if (!empty($student['plan_name'])): ?>
                                <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($student['plan_name']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($student['payment_status']) && $mStatus !== 'No Membership'): ?>
                                <span class="mt-1 px-2 inline-flex text-xs leading-4 rounded-full <?php echo $paymentColors[$student['payment_status']] ?? 'bg-gray-100 text-gray-600'; ?>">
                                    <?php echo ucfirst($student['payment_status']); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <a href="student_detail.php?id=<?php echo $student['id']; ?>"
                               class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                            <a href="student_edit.php?id=<?php echo $student['id']; ?>"
                               class="text-green-600 hover:text-green-900 mr-3">Edit</a>
                            <?php if (isset($studentsWithParent[$student['id']])): ?>
                                <span class="text-green-600 mr-3 cursor-default" title="Parent account enabled">✓ Parent</span>
                            <?php else: ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Enable parent capabilities for this student?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="promote_to_parent" value="1">
                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                    <button type="submit" class="text-purple-600 hover:text-purple-900 mr-3" title="Enable parent capabilities">Make Parent</button>
                                </form>
                            <?php endif; ?>
                            <?php
                            $isActivityInactive = ($student['activity_status'] ?? 'active') === 'inactive';
                            $toggleLabel = $isActivityInactive ? 'Mark Active' : 'Mark Inactive';
                            $toggleColor = $isActivityInactive ? 'text-green-600 hover:text-green-900' : 'text-yellow-600 hover:text-yellow-900';
                            $toggleConfirm = $isActivityInactive
                                ? 'Mark this student as actively attending?'
                                : 'Mark this student as inactive (not attending)?';
                            ?>
                            <form method="POST" class="inline" onsubmit="return confirm('<?php echo $toggleConfirm; ?>')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="toggle_activity" value="1">
                                <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                <button type="submit" class="<?php echo $toggleColor; ?> mr-3" title="<?php echo $toggleLabel; ?>"><?php echo $toggleLabel; ?></button>
                            </form>
                            <form method="POST" class="inline" onsubmit="return confirmDelete('Are you sure you want to delete this student?')">
                                <?= csrf_field() ?>
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
            <?= csrf_field() ?>
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
