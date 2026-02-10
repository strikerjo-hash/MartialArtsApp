<?php
require_once 'config.php';

// Check if student is logged in
if (!isset($_SESSION['is_student']) || !isset($_SESSION['student_id'])) {
    header('Location: student_login.php');
    exit;
}

$student_id = $_SESSION['student_id'];

// Get student details
$student = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$student->execute([$student_id]);
$student = $student->fetch();

// Get current belt
$current_belt = $pdo->prepare("
    SELECT sb.*, b.name as belt_name, b.color, mas.name as style_name
    FROM student_belts sb
    JOIN belts b ON sb.belt_id = b.id
    JOIN martial_arts_styles mas ON sb.style_id = mas.id
    WHERE sb.student_id = ?
    ORDER BY sb.awarded_date DESC
    LIMIT 1
");
$current_belt->execute([$student_id]);
$current_belt = $current_belt->fetch();

// Get active membership
$membership = $pdo->prepare("
    SELECT m.*, mp.name as plan_name
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.student_id = ? AND m.status = 'active' AND m.end_date >= CURDATE()
    ORDER BY m.end_date DESC
    LIMIT 1
");
$membership->execute([$student_id]);
$membership = $membership->fetch();

// Get enrolled classes
$classes = $pdo->prepare("
    SELECT c.*, mas.name as style_name, u.full_name as instructor_name
    FROM class_enrollments ce
    JOIN classes c ON ce.class_id = c.id
    JOIN martial_arts_styles mas ON c.style_id = mas.id
    LEFT JOIN users u ON c.instructor_id = u.id
    WHERE ce.student_id = ? AND ce.status = 'active'
    ORDER BY FIELD(c.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), c.start_time
");
$classes->execute([$student_id]);
$classes = $classes->fetchAll();

// Get upcoming events
$events = $pdo->prepare("
    SELECT e.*, er.payment_status, er.attendance_status
    FROM event_registrations er
    JOIN events e ON er.event_id = e.id
    WHERE er.student_id = ? AND e.event_date >= CURDATE()
    ORDER BY e.event_date ASC
");
$events->execute([$student_id]);
$events = $events->fetchAll();

// Get belt history
$belt_history = $pdo->prepare("
    SELECT sb.*, b.name as belt_name, b.color, mas.name as style_name
    FROM student_belts sb
    JOIN belts b ON sb.belt_id = b.id
    JOIN martial_arts_styles mas ON sb.style_id = mas.id
    WHERE sb.student_id = ?
    ORDER BY sb.awarded_date DESC
");
$belt_history->execute([$student_id]);
$belt_history = $belt_history->fetchAll();

// Get recent attendance (last 30 days)
$attendance = $pdo->prepare("
    SELECT a.*, c.name as class_name
    FROM attendance a
    JOIN classes c ON a.class_id = c.id
    WHERE a.student_id = ? AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY a.attendance_date DESC
    LIMIT 10
");
$attendance->execute([$student_id]);
$attendance = $attendance->fetchAll();

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
        <!-- Profile Summary -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="flex items-center space-x-6">
                <div class="w-20 h-20 bg-blue-600 rounded-full flex items-center justify-center text-white text-2xl font-bold">
                    <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                </div>
                <div class="flex-1">
                    <h2 class="text-2xl font-bold text-gray-800">
                        <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                    </h2>
                    <p class="text-gray-600">
                        <?php if ($current_belt): ?>
                            <span class="font-semibold"><?php echo $current_belt['belt_name']; ?></span> - 
                            <?php echo $current_belt['style_name']; ?>
                        <?php else: ?>
                            No belt yet - Keep training!
                        <?php endif; ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-1">Member since <?php echo formatDate($student['join_date']); ?></p>
                </div>
            </div>
            
            <!-- Membership Status -->
            <?php if ($membership): ?>
                <div class="mt-4 pt-4 border-t border-gray-200">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm text-gray-600">Membership</p>
                            <p class="font-semibold text-gray-800"><?php echo $membership['plan_name']; ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-600">Valid Until</p>
                            <p class="font-semibold text-gray-800"><?php echo formatDate($membership['end_date']); ?></p>
                            <?php 
                            $days_left = (strtotime($membership['end_date']) - time()) / (60 * 60 * 24);
                            if ($days_left <= 30):
                            ?>
                                <p class="text-xs text-orange-600"><?php echo floor($days_left); ?> days remaining</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="mt-4 pt-4 border-t border-gray-200">
                    <p class="text-orange-600 font-semibold">⚠️ No active membership</p>
                    <p class="text-sm text-gray-600 mt-1">Contact the studio to renew your membership</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- My Classes -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800">My Classes</h3>
                </div>
                <div class="p-6">
                    <?php if (empty($classes)): ?>
                        <p class="text-gray-500 text-center py-8">Not enrolled in any classes</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($classes as $class): ?>
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <p class="font-semibold text-gray-800"><?php echo $class['name']; ?></p>
                                    <p class="text-sm text-gray-600"><?php echo $class['style_name']; ?></p>
                                    <p class="text-sm text-gray-600">
                                        <?php echo $class['day_of_week']; ?> • 
                                        <?php echo date('g:i A', strtotime($class['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($class['end_time'])); ?>
                                    </p>
                                    <?php if ($class['instructor_name']): ?>
                                        <p class="text-sm text-gray-500 mt-1">👨‍🏫 <?php echo $class['instructor_name']; ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Upcoming Events -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800">Upcoming Events</h3>
                </div>
                <div class="p-6">
                    <?php if (empty($events)): ?>
                        <p class="text-gray-500 text-center py-8">No upcoming events</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($events as $event): ?>
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <p class="font-semibold text-gray-800"><?php echo $event['name']; ?></p>
                                            <p class="text-sm text-gray-600 capitalize"><?php echo str_replace('_', ' ', $event['event_type']); ?></p>
                                            <p class="text-sm text-gray-600">📅 <?php echo formatDate($event['event_date']); ?></p>
                                        </div>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?php 
                                            echo $event['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800';
                                        ?>">
                                            <?php echo ucfirst($event['payment_status']); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Belt Progression -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800">Belt Progression</h3>
                </div>
                <div class="p-6">
                    <?php if (empty($belt_history)): ?>
                        <p class="text-gray-500 text-center py-8">No belts earned yet</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($belt_history as $belt): ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold"
                                             style="background-color: <?php 
                                             $colors = [
                                                 'White' => '#FFFFFF', 'Yellow' => '#FFD700', 'Orange' => '#FF8C00',
                                                 'Green' => '#228B22', 'Blue' => '#0000CD', 'Purple' => '#800080',
                                                 'Brown' => '#8B4513', 'Red' => '#DC143C', 'Black' => '#000000'
                                             ];
                                             echo $colors[$belt['color']] ?? '#6B7280';
                                             ?>; <?php echo in_array($belt['color'], ['White', 'Yellow']) ? 'color: #000;' : ''; ?>">
                                            🥋
                                        </div>
                                        <div>
                                            <p class="font-semibold text-gray-800"><?php echo $belt['belt_name']; ?></p>
                                            <p class="text-sm text-gray-600"><?php echo $belt['style_name']; ?></p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm text-gray-600"><?php echo formatDate($belt['awarded_date']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Attendance -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800">Recent Attendance</h3>
                    <p class="text-sm text-gray-600">Last 30 days</p>
                </div>
                <div class="p-6">
                    <?php if (empty($attendance)): ?>
                        <p class="text-gray-500 text-center py-8">No attendance records</p>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($attendance as $att): ?>
                                <div class="flex items-center justify-between p-2 border-b border-gray-100">
                                    <div>
                                        <p class="text-sm font-medium text-gray-800"><?php echo $att['class_name']; ?></p>
                                        <p class="text-xs text-gray-600"><?php echo formatDate($att['attendance_date']); ?></p>
                                    </div>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php
                                        $statusColors = [
                                            'present' => 'bg-green-100 text-green-800',
                                            'late' => 'bg-yellow-100 text-yellow-800',
                                            'absent' => 'bg-red-100 text-red-800',
                                            'excused' => 'bg-blue-100 text-blue-800'
                                        ];
                                        echo $statusColors[$att['status']] ?? 'bg-gray-100 text-gray-800';
                                    ?>">
                                        <?php echo ucfirst($att['status']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
