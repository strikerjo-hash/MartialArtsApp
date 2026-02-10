<?php
require_once 'config.php';
requireLogin();

$message = '';
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_class = $_GET['class_id'] ?? '';

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['attendance'])) {
    foreach ($_POST['attendance'] as $student_id => $status) {
        // Check if attendance record exists
        $check = $pdo->prepare("
            SELECT id FROM attendance 
            WHERE student_id = ? AND class_id = ? AND attendance_date = ?
        ");
        $check->execute([$student_id, $_POST['class_id'], $_POST['date']]);
        
        if ($check->fetch()) {
            // Update existing
            $stmt = $pdo->prepare("
                UPDATE attendance 
                SET status = ?, check_in_time = ?
                WHERE student_id = ? AND class_id = ? AND attendance_date = ?
            ");
            $stmt->execute([
                $status,
                $_POST['check_in_time'][$student_id] ?? null,
                $student_id,
                $_POST['class_id'],
                $_POST['date']
            ]);
        } else {
            // Insert new
            $stmt = $pdo->prepare("
                INSERT INTO attendance (student_id, class_id, attendance_date, status, check_in_time)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $student_id,
                $_POST['class_id'],
                $_POST['date'],
                $status,
                $_POST['check_in_time'][$student_id] ?? null
            ]);
        }
    }
    $message = showAlert('Attendance recorded successfully!', 'success');
}

// Get classes for the selected date
$day_of_week = date('l', strtotime($selected_date));
$classes_on_day = $pdo->prepare("
    SELECT c.*, mas.name as style_name, u.full_name as instructor_name
    FROM classes c
    LEFT JOIN martial_arts_styles mas ON c.style_id = mas.id
    LEFT JOIN users u ON c.instructor_id = u.id
    WHERE c.day_of_week = ? AND c.status = 'active'
    ORDER BY c.start_time
");
$classes_on_day->execute([$day_of_week]);
$classes_on_day = $classes_on_day->fetchAll();

// Get enrolled students for selected class
$enrolled_students = [];
if ($selected_class) {
    $enrolled_students = $pdo->prepare("
        SELECT s.*, 
               COALESCE(a.status, 'absent') as attendance_status,
               a.check_in_time
        FROM class_enrollments ce
        JOIN students s ON ce.student_id = s.id
        LEFT JOIN attendance a ON s.id = a.student_id 
            AND a.class_id = ce.class_id 
            AND a.attendance_date = ?
        WHERE ce.class_id = ? AND ce.status = 'active'
        ORDER BY s.first_name, s.last_name
    ");
    $enrolled_students->execute([$selected_date, $selected_class]);
    $enrolled_students = $enrolled_students->fetchAll();
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Attendance</h1>
    
    <!-- Date and Class Selection -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select Date</label>
                <input type="date" name="date" value="<?php echo $selected_date; ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                       onchange="this.form.submit()">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select Class</label>
                <select name="class_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                        onchange="this.form.submit()">
                    <option value="">Choose a class...</option>
                    <?php foreach ($classes_on_day as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>>
                            <?php echo date('g:i A', strtotime($class['start_time'])); ?> - 
                            <?php echo $class['name']; ?> (<?php echo $class['style_name']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="flex items-end">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                    Load Students
                </button>
            </div>
        </form>
        
        <div class="mt-4 text-sm text-gray-600">
            <strong>Selected Date:</strong> <?php echo formatDate($selected_date); ?> (<?php echo $day_of_week; ?>)
            <?php if (empty($classes_on_day)): ?>
                <span class="text-orange-600 ml-2">⚠️ No classes scheduled for this day</span>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Attendance Form -->
    <?php if ($selected_class && !empty($enrolled_students)): ?>
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">Mark Attendance</h2>
            </div>
            
            <form method="POST" class="p-6">
                <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Check-in Time</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($enrolled_students as $student): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="font-medium text-gray-900">
                                            <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex space-x-4">
                                            <label class="inline-flex items-center">
                                                <input type="radio" name="attendance[<?php echo $student['id']; ?>]" 
                                                       value="present" 
                                                       <?php echo $student['attendance_status'] === 'present' ? 'checked' : ''; ?>
                                                       class="text-blue-600">
                                                <span class="ml-2 text-sm text-green-700">Present</span>
                                            </label>
                                            <label class="inline-flex items-center">
                                                <input type="radio" name="attendance[<?php echo $student['id']; ?>]" 
                                                       value="absent"
                                                       <?php echo $student['attendance_status'] === 'absent' ? 'checked' : ''; ?>
                                                       class="text-blue-600">
                                                <span class="ml-2 text-sm text-red-700">Absent</span>
                                            </label>
                                            <label class="inline-flex items-center">
                                                <input type="radio" name="attendance[<?php echo $student['id']; ?>]" 
                                                       value="late"
                                                       <?php echo $student['attendance_status'] === 'late' ? 'checked' : ''; ?>
                                                       class="text-blue-600">
                                                <span class="ml-2 text-sm text-orange-700">Late</span>
                                            </label>
                                            <label class="inline-flex items-center">
                                                <input type="radio" name="attendance[<?php echo $student['id']; ?>]" 
                                                       value="excused"
                                                       <?php echo $student['attendance_status'] === 'excused' ? 'checked' : ''; ?>
                                                       class="text-blue-600">
                                                <span class="ml-2 text-sm text-blue-700">Excused</span>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <input type="time" 
                                               name="check_in_time[<?php echo $student['id']; ?>]"
                                               value="<?php echo $student['check_in_time'] ?? date('H:i'); ?>"
                                               class="px-3 py-1 border border-gray-300 rounded">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-6 flex justify-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg font-medium">
                        Save Attendance
                    </button>
                </div>
            </form>
        </div>
    <?php elseif ($selected_class && empty($enrolled_students)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
            <p class="text-lg">No students enrolled in this class</p>
            <a href="classes.php" class="text-blue-600 hover:text-blue-800 mt-2 inline-block">
                Manage Class Enrollments →
            </a>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
            <p class="text-lg">Select a date and class to mark attendance</p>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
