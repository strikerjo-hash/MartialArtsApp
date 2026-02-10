<?php
require_once 'config.php';
requireLogin();

// Get dashboard statistics
$stats = [];

// Total active students
$stmt = $pdo->query("SELECT COUNT(*) as count FROM students WHERE status = 'active'");
$stats['active_students'] = $stmt->fetch()['count'];

// Total active memberships
$stmt = $pdo->query("SELECT COUNT(*) as count FROM memberships WHERE status = 'active' AND end_date >= CURDATE()");
$stats['active_memberships'] = $stmt->fetch()['count'];

// Upcoming events
$stmt = $pdo->query("SELECT COUNT(*) as count FROM events WHERE status = 'upcoming' AND event_date >= CURDATE()");
$stats['upcoming_events'] = $stmt->fetch()['count'];

// Today's classes
$stmt = $pdo->query("SELECT COUNT(*) as count FROM classes WHERE status = 'active' AND day_of_week = DAYNAME(CURDATE())");
$stats['todays_classes'] = $stmt->fetch()['count'];

// Recent payments (last 7 days)
$stmt = $pdo->query("SELECT SUM(amount) as total FROM payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$stats['recent_revenue'] = $stmt->fetch()['total'] ?? 0;

// Get recent students
$recent_students = $pdo->query("
    SELECT s.*, COALESCE(sb.belt_name, 'No Belt') as current_belt
    FROM students s
    LEFT JOIN (
        SELECT sb.student_id, b.name as belt_name
        FROM student_belts sb
        JOIN belts b ON sb.belt_id = b.id
        WHERE sb.id IN (
            SELECT MAX(id) FROM student_belts GROUP BY student_id
        )
    ) sb ON s.id = sb.student_id
    ORDER BY s.created_at DESC
    LIMIT 5
")->fetchAll();

// Get upcoming events
$upcoming_events = $pdo->query("
    SELECT e.*, COUNT(er.id) as registrations
    FROM events e
    LEFT JOIN event_registrations er ON e.id = er.event_id
    WHERE e.event_date >= CURDATE() AND e.status = 'upcoming'
    GROUP BY e.id
    ORDER BY e.event_date ASC
    LIMIT 5
")->fetchAll();

// Get today's classes
$todays_classes = $pdo->query("
    SELECT c.*, u.full_name as instructor_name, mas.name as style_name,
           COUNT(ce.id) as enrolled_students
    FROM classes c
    LEFT JOIN users u ON c.instructor_id = u.id
    LEFT JOIN martial_arts_styles mas ON c.style_id = mas.id
    LEFT JOIN class_enrollments ce ON c.id = ce.class_id AND ce.status = 'active'
    WHERE c.status = 'active' AND c.day_of_week = DAYNAME(CURDATE())
    GROUP BY c.id
    ORDER BY c.start_time ASC
")->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Dashboard</h1>
        <p class="text-gray-600 mt-2">Welcome back, <?php echo getCurrentUser()['full_name']; ?>!</p>
    </div>
    
    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-600 text-sm">Active Students</p>
                    <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $stats['active_students']; ?></p>
                </div>
                <div class="text-blue-500 text-4xl">👥</div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-600 text-sm">Active Memberships</p>
                    <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $stats['active_memberships']; ?></p>
                </div>
                <div class="text-green-500 text-4xl">📋</div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-600 text-sm">Upcoming Events</p>
                    <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $stats['upcoming_events']; ?></p>
                </div>
                <div class="text-purple-500 text-4xl">🎯</div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-600 text-sm">Today's Classes</p>
                    <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $stats['todays_classes']; ?></p>
                </div>
                <div class="text-orange-500 text-4xl">🥋</div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-600 text-sm">7-Day Revenue</p>
                    <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo formatMoney($stats['recent_revenue']); ?></p>
                </div>
                <div class="text-yellow-500 text-4xl">💰</div>
            </div>
        </div>
    </div>
    
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Recent Students -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">Recent Students</h2>
            </div>
            <div class="p-6">
                <?php if (empty($recent_students)): ?>
                    <p class="text-gray-500 text-center py-4">No students yet</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($recent_students as $student): ?>
                            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                                <div>
                                    <p class="font-semibold text-gray-800">
                                        <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                                    </p>
                                    <p class="text-sm text-gray-600"><?php echo $student['current_belt']; ?></p>
                                </div>
                                <div class="text-sm text-gray-500">
                                    Joined: <?php echo formatDate($student['join_date']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <a href="students.php" class="block text-center text-blue-600 hover:text-blue-800 mt-4 font-medium">
                        View All Students →
                    </a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Upcoming Events -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">Upcoming Events</h2>
            </div>
            <div class="p-6">
                <?php if (empty($upcoming_events)): ?>
                    <p class="text-gray-500 text-center py-4">No upcoming events</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($upcoming_events as $event): ?>
                            <div class="border-l-4 border-blue-500 pl-4 pb-3">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="font-semibold text-gray-800"><?php echo $event['name']; ?></p>
                                        <p class="text-sm text-gray-600 capitalize"><?php echo str_replace('_', ' ', $event['event_type']); ?></p>
                                    </div>
                                    <span class="bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded">
                                        <?php echo $event['registrations']; ?> registered
                                    </span>
                                </div>
                                <p class="text-sm text-gray-500 mt-1">
                                    📅 <?php echo formatDate($event['event_date']); ?>
                                    <?php if ($event['start_time']): ?>
                                        at <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <a href="events.php" class="block text-center text-blue-600 hover:text-blue-800 mt-4 font-medium">
                        View All Events →
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Today's Classes -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Today's Classes (<?php echo date('l'); ?>)</h2>
        </div>
        <div class="p-6">
            <?php if (empty($todays_classes)): ?>
                <p class="text-gray-500 text-center py-4">No classes scheduled for today</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="text-left py-3 px-4 font-semibold text-gray-700">Time</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-700">Class</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-700">Style</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-700">Instructor</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-700">Students</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-700">Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todays_classes as $class): ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50">
                                    <td class="py-3 px-4">
                                        <?php echo date('g:i A', strtotime($class['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($class['end_time'])); ?>
                                    </td>
                                    <td class="py-3 px-4 font-medium"><?php echo $class['name']; ?></td>
                                    <td class="py-3 px-4"><?php echo $class['style_name']; ?></td>
                                    <td class="py-3 px-4"><?php echo $class['instructor_name'] ?? 'TBD'; ?></td>
                                    <td class="py-3 px-4"><?php echo $class['enrolled_students']; ?>/<?php echo $class['max_students']; ?></td>
                                    <td class="py-3 px-4">
                                        <span class="text-xs px-2 py-1 rounded bg-gray-100 capitalize">
                                            <?php echo $class['skill_level']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
