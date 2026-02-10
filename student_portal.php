<?php
/**
 * student_portal.php — Student Dashboard
 *
 * The main landing page after a student logs in.  Shows upcoming
 * classes, attendance history, and profile info.
 *
 * Uses the primary schema (database.sql):
 *   - class_enrollments (not enrollments)
 *   - classes.name (not class_name), classes.status (not is_active)
 */

require_once 'config.php';

// Require student login
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}

$studentId = $_SESSION['student_id'];

// Fetch student profile
$stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $studentId]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: logout.php');
    exit;
}

// Fetch enrolled classes
$classes = [];
try {
    $classesStmt = $pdo->prepare(
        "SELECT c.* FROM classes c
         JOIN class_enrollments ce ON ce.class_id = c.id
         WHERE ce.student_id = :sid AND ce.status = 'active' AND c.status = 'active'
         ORDER BY FIELD(c.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), c.start_time"
    );
    $classesStmt->execute([':sid' => $studentId]);
    $classes = $classesStmt->fetchAll();
} catch (\PDOException $e) {}

// Fetch recent attendance (last 30 records)
$attendance = [];
try {
    $attendStmt = $pdo->prepare(
        'SELECT a.attendance_date, a.status, c.name as class_name
         FROM attendance a
         JOIN classes c ON c.id = a.class_id
         WHERE a.student_id = :sid
         ORDER BY a.attendance_date DESC
         LIMIT 30'
    );
    $attendStmt->execute([':sid' => $studentId]);
    $attendance = $attendStmt->fetchAll();
} catch (\PDOException $e) {}

// Attendance stats
$stats = ['present' => 0, 'absent' => 0, 'late' => 0];
try {
    $statsStmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS cnt FROM attendance WHERE student_id = :sid GROUP BY status'
    );
    $statsStmt->execute([':sid' => $studentId]);
    foreach ($statsStmt->fetchAll() as $r) {
        $stats[$r['status']] = (int)$r['cnt'];
    }
} catch (\PDOException $e) {}
$totalClasses = array_sum($stats);
$attendanceRate = $totalClasses > 0 ? round(($stats['present'] / $totalClasses) * 100) : 0;

// Upcoming events the student is registered for
$upcomingEvents = [];
try {
    $evStmt = $pdo->prepare(
        "SELECT e.name, e.event_date, e.start_time, e.location, er.payment_status
         FROM events e
         JOIN event_registrations er ON er.event_id = e.id
         WHERE er.student_id = :sid AND e.event_date >= CURDATE()
         ORDER BY e.event_date ASC LIMIT 5"
    );
    $evStmt->execute([':sid' => $studentId]);
    $upcomingEvents = $evStmt->fetchAll();
} catch (\PDOException $e) {}

// Current membership info
$membership = null;
try {
    $memStmt = $pdo->prepare(
        "SELECT m.*, mp.name as plan_name
         FROM memberships m
         JOIN membership_plans mp ON mp.id = m.plan_id
         WHERE m.student_id = :sid AND m.status = 'active' AND m.end_date >= CURDATE()
         ORDER BY m.end_date DESC LIMIT 1"
    );
    $memStmt->execute([':sid' => $studentId]);
    $membership = $memStmt->fetch();
} catch (\PDOException $e) {}

// Belt history
$beltHistory = [];
try {
    $beltStmt = $pdo->prepare(
        "SELECT b.name as belt_name, b.color, mas.name as style_name, sb.awarded_date
         FROM student_belts sb
         JOIN belts b ON b.id = sb.belt_id
         JOIN martial_arts_styles mas ON mas.id = sb.style_id
         WHERE sb.student_id = :sid
         ORDER BY sb.awarded_date DESC LIMIT 5"
    );
    $beltStmt->execute([':sid' => $studentId]);
    $beltHistory = $beltStmt->fetchAll();
} catch (\PDOException $e) {}

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">

    <!-- Profile & Membership Row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Profile Summary -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">My Profile</h2>
            <div class="space-y-3">
                <div>
                    <span class="text-sm text-gray-500">Name</span>
                    <p class="font-medium text-gray-800"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></p>
                </div>
                <div>
                    <span class="text-sm text-gray-500">Email</span>
                    <p class="font-medium text-gray-800"><?= htmlspecialchars($student['email'] ?? '—') ?></p>
                </div>
                <div>
                    <span class="text-sm text-gray-500">Member Since</span>
                    <p class="font-medium text-gray-800"><?= formatDate($student['join_date']) ?></p>
                </div>
                <div>
                    <span class="text-sm text-gray-500">Status</span>
                    <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full <?= $student['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                        <?= ucfirst($student['status']) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Membership Info -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Membership</h2>
                <a href="student_upgrade.php" class="text-sm text-blue-600 hover:underline">Upgrade</a>
            </div>
            <?php if ($membership): ?>
                <div class="space-y-3">
                    <div>
                        <span class="text-sm text-gray-500">Plan</span>
                        <p class="font-medium text-gray-800"><?= htmlspecialchars($membership['plan_name']) ?></p>
                    </div>
                    <div>
                        <span class="text-sm text-gray-500">Valid Until</span>
                        <p class="font-medium text-gray-800"><?= formatDate($membership['end_date']) ?></p>
                    </div>
                    <div>
                        <span class="text-sm text-gray-500">Status</span>
                        <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <p class="text-gray-500 text-sm mb-3">No active membership found.</p>
                    <a href="student_upgrade.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-2 rounded-lg">
                        View Plans
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">My Stats</h2>
            <div class="grid grid-cols-3 gap-4 text-center">
                <div>
                    <p class="text-2xl font-bold text-gray-800"><?= $totalClasses ?></p>
                    <p class="text-xs text-gray-500">Total Classes</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-green-600"><?= $attendanceRate ?>%</p>
                    <p class="text-xs text-gray-500">Attendance</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-blue-600"><?= count($classes) ?></p>
                    <p class="text-xs text-gray-500">Enrolled</p>
                </div>
            </div>
            <?php if (!empty($beltHistory)): ?>
                <div class="mt-4 pt-4 border-t border-gray-200">
                    <span class="text-sm text-gray-500">Current Belt</span>
                    <p class="font-medium text-gray-800">
                        <?= htmlspecialchars($beltHistory[0]['belt_name']) ?>
                        <span class="text-xs text-gray-500">(<?= htmlspecialchars($beltHistory[0]['style_name']) ?>)</span>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- My Schedule -->
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-800">My Schedule</h2>
            <span class="text-sm text-gray-500"><?= count($classes) ?> class<?= count($classes) !== 1 ? 'es' : '' ?></span>
        </div>
        <?php if (empty($classes)): ?>
            <div class="p-8 text-center">
                <p class="text-gray-500">You are not enrolled in any classes yet.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Day</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($classes as $c): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 font-medium text-gray-800"><?= htmlspecialchars($c['name']) ?></td>
                                <td class="px-6 py-4 text-gray-600"><?= htmlspecialchars($c['day_of_week']) ?></td>
                                <td class="px-6 py-4 text-gray-600">
                                    <?= date('g:i A', strtotime($c['start_time'])) ?>
                                    &ndash;
                                    <?= date('g:i A', strtotime($c['end_time'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Upcoming Events -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-800">Upcoming Events</h2>
                <a href="student_events.php" class="text-sm text-blue-600 hover:underline">Browse All</a>
            </div>
            <?php if (empty($upcomingEvents)): ?>
                <div class="p-8 text-center">
                    <p class="text-gray-500">No upcoming events.</p>
                    <a href="student_events.php" class="text-sm text-blue-600 hover:underline mt-2 inline-block">Browse events</a>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($upcomingEvents as $ev): ?>
                        <div class="px-6 py-4 hover:bg-gray-50">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($ev['name']) ?></p>
                                    <p class="text-sm text-gray-500">
                                        <?= formatDate($ev['event_date']) ?>
                                        <?php if (!empty($ev['location'])): ?>
                                            &bull; <?= htmlspecialchars($ev['location']) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $ev['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                    <?= ucfirst($ev['payment_status']) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Attendance -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800">Recent Attendance</h2>
            </div>
            <?php if (empty($attendance)): ?>
                <div class="p-8 text-center">
                    <p class="text-gray-500">No attendance records yet.</p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach (array_slice($attendance, 0, 10) as $a): ?>
                        <div class="px-6 py-3 flex items-center justify-between hover:bg-gray-50">
                            <div>
                                <p class="text-sm font-medium text-gray-800"><?= htmlspecialchars($a['class_name']) ?></p>
                                <p class="text-xs text-gray-500"><?= formatDate($a['attendance_date']) ?></p>
                            </div>
                            <?php
                            $statusColors = [
                                'present' => 'bg-green-100 text-green-800',
                                'absent'  => 'bg-red-100 text-red-800',
                                'late'    => 'bg-yellow-100 text-yellow-800',
                            ];
                            $cls = $statusColors[$a['status']] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $cls ?>">
                                <?= ucfirst($a['status']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/student_footer.php'; ?>
