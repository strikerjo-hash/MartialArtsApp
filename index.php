<?php
/**
 * index.php — Admin Dashboard (main landing page after admin login).
 *
 * Shows quick stats, recent activity, and serves as the home page
 * for the admin sidebar navigation. Redirects to login if not
 * authenticated.
 */

require_once 'config.php';
requireLogin();

// Dashboard statistics
$studentCount = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
$classCount   = (int)$pdo->query("SELECT COUNT(*) FROM classes WHERE status = 'active'")->fetchColumn();
$todayAttend  = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE()")->fetchColumn();

// Pending registrations count
$pendingCount = 0;
try {
    $pendingCount = (int)$pdo->query("
        SELECT COUNT(*) FROM students s
        JOIN memberships m ON s.id = m.student_id
        WHERE s.status = 'inactive' AND m.status = 'cancelled' AND m.payment_status = 'pending'
    ")->fetchColumn();
} catch (\PDOException $e) {}

// Recent students
$recentStudents = $pdo->query("
    SELECT first_name, last_name, join_date, status
    FROM students ORDER BY id DESC LIMIT 5
")->fetchAll();

// Upcoming events
$upcomingEvents = [];
try {
    $upcomingEvents = $pdo->query("
        SELECT id, name, event_type, event_date, location, start_time, status
        FROM events WHERE event_date >= CURDATE()
        ORDER BY event_date ASC LIMIT 5
    ")->fetchAll();
} catch (\PDOException $e) {}

// Monthly revenue
$monthlyRevenue = 0;
try {
    $monthlyRevenue = (float)$pdo->query("
        SELECT COALESCE(SUM(amount), 0) FROM payments
        WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())
        AND status = 'completed'
    ")->fetchColumn();
} catch (\PDOException $e) {}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Dashboard</h1>
        <p class="text-gray-600 mt-1">Welcome back, <?php echo htmlspecialchars(getCurrentUser()['full_name']); ?></p>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-2xl font-bold text-gray-800"><?php echo $studentCount; ?></p>
                    <p class="text-sm text-gray-600">Active Students</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100 text-green-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-2xl font-bold text-gray-800"><?php echo $classCount; ?></p>
                    <p class="text-sm text-gray-600">Active Classes</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-2xl font-bold text-gray-800"><?php echo $todayAttend; ?></p>
                    <p class="text-sm text-gray-600">Today's Attendance</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-2xl font-bold text-gray-800">$<?php echo number_format($monthlyRevenue, 2); ?></p>
                    <p class="text-sm text-gray-600">Monthly Revenue</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Registrations Alert -->
    <?php if ($pendingCount > 0): ?>
    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-8 rounded">
        <div class="flex items-center">
            <div class="flex-shrink-0">
                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-yellow-700">
                    You have <strong><?php echo $pendingCount; ?></strong> pending student registration(s) awaiting review.
                    <a href="pending_registrations.php" class="font-medium underline hover:text-yellow-800">Review now</a>
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Recent Students -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Recent Students</h2>
                <a href="students.php" class="text-sm text-blue-600 hover:underline">View All</a>
            </div>
            <?php if (empty($recentStudents)): ?>
                <p class="text-gray-500 text-sm">No students yet.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentStudents as $s): ?>
                    <div class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                        <div>
                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></p>
                            <p class="text-xs text-gray-500">Joined <?php echo $s['join_date']; ?></p>
                        </div>
                        <span class="text-xs px-2 py-1 rounded-full <?php echo $s['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
                            <?php echo ucfirst($s['status']); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Upcoming Events -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Upcoming Events</h2>
                <div class="flex items-center gap-3">
                    <a href="calendar.php" class="text-sm text-purple-600 hover:underline">Calendar</a>
                    <a href="events.php" class="text-sm text-blue-600 hover:underline">View All</a>
                </div>
            </div>
            <?php if (empty($upcomingEvents)): ?>
                <p class="text-gray-500 text-sm">No upcoming events.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php
                    $eventTypeColors = [
                        'belt_test' => 'bg-yellow-100 text-yellow-700',
                        'tournament' => 'bg-red-100 text-red-700',
                        'seminar' => 'bg-blue-100 text-blue-700',
                        'workshop' => 'bg-green-100 text-green-700',
                        'demonstration' => 'bg-purple-100 text-purple-700',
                        'other' => 'bg-gray-100 text-gray-700'
                    ];
                    ?>
                    <?php foreach ($upcomingEvents as $e): ?>
                    <a href="event_detail.php?id=<?php echo $e['id']; ?>"
                       class="flex items-center justify-between py-3 px-3 -mx-3 border-b border-gray-100 last:border-0 rounded-lg hover:bg-blue-50 transition group">
                        <div class="flex items-center gap-3">
                            <span class="px-2 py-1 text-xs font-semibold rounded <?php echo $eventTypeColors[$e['event_type']] ?? 'bg-gray-100 text-gray-700'; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $e['event_type'])); ?>
                            </span>
                            <div>
                                <p class="font-medium text-gray-800 group-hover:text-blue-700"><?php echo htmlspecialchars($e['name']); ?></p>
                                <p class="text-xs text-gray-500">
                                    <?php echo htmlspecialchars($e['location'] ?? ''); ?>
                                    <?php if (!empty($e['start_time'])): ?>
                                        <?php echo $e['location'] ? ' &middot; ' : ''; ?><?php echo date('g:i A', strtotime($e['start_time'])); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <span class="text-sm font-medium text-gray-700"><?php echo formatDate($e['event_date']); ?></span>
                            <span class="block text-xs text-blue-500 opacity-0 group-hover:opacity-100 transition">View Details &rarr;</span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="mt-8 bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Quick Actions</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <a href="students.php" class="flex flex-col items-center p-4 bg-blue-50 rounded-lg hover:bg-blue-100 transition">
                <span class="text-2xl mb-2">👥</span>
                <span class="text-sm font-medium text-gray-700">Add Student</span>
            </a>
            <a href="attendance.php" class="flex flex-col items-center p-4 bg-green-50 rounded-lg hover:bg-green-100 transition">
                <span class="text-2xl mb-2">✅</span>
                <span class="text-sm font-medium text-gray-700">Take Attendance</span>
            </a>
            <a href="events.php" class="flex flex-col items-center p-4 bg-purple-50 rounded-lg hover:bg-purple-100 transition">
                <span class="text-2xl mb-2">🎯</span>
                <span class="text-sm font-medium text-gray-700">Create Event</span>
            </a>
            <a href="calendar.php" class="flex flex-col items-center p-4 bg-yellow-50 rounded-lg hover:bg-yellow-100 transition">
                <span class="text-2xl mb-2">📅</span>
                <span class="text-sm font-medium text-gray-700">Calendar</span>
            </a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
