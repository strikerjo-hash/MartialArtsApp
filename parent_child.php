<?php
/**
 * parent_child.php — Parent view of a child's student dashboard
 *
 * Shows belt rank, membership, class schedule, upcoming events,
 * attendance stats, and links to training/membership management.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/parent_auth.php';

require_parent();

$parentId  = get_effective_parent_id();
$childId   = (int)($_GET['id'] ?? 0);
$pdo       = get_db();

// Verify access
$child = parent_verify_child($parentId, $childId);

// Fetch all children for the child switcher
$children = get_parent_children($parentId);

// Current belt
$params = [$childId];
$beltStmt = $pdo->prepare("
    SELECT sb.*, b.name as belt_name, b.color, mas.name as style_name, b.rank_order
    FROM student_belts sb
    JOIN belts b ON sb.belt_id = b.id
    JOIN martial_arts_styles mas ON sb.style_id = mas.id
    WHERE sb.student_id = ?" . school_where('sb') . "
    ORDER BY sb.awarded_date DESC
    LIMIT 1
");
school_param($params);
$beltStmt->execute($params);
$currentBelt = $beltStmt->fetch();

// Active membership
$params = [$childId];
$memStmt = $pdo->prepare("
    SELECT m.*, mp.name as plan_name, mp.price, mp.classes_per_week, mp.duration_months, mp.billing_frequency
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.student_id = ? AND m.status = 'active' AND m.end_date >= CURDATE()" . school_where('m') . "
    ORDER BY m.end_date DESC LIMIT 1
");
school_param($params);
$memStmt->execute($params);
$membership = $memStmt->fetch();

// Pending plan change (proposed by admin)
$childPendingChange = null;
try {
    $pcParams = [$childId];
    school_param($pcParams);
    $pcStmt = $pdo->prepare("
        SELECT pc.*, mp.name as new_plan_name, mp.price as new_plan_price, mp.duration_months as new_duration,
               mp.billing_frequency as new_billing_frequency, u.full_name as requested_by_name
        FROM pending_plan_changes pc
        JOIN membership_plans mp ON pc.new_plan_id = mp.id
        LEFT JOIN users u ON pc.requested_by = u.id
        WHERE pc.student_id = ? AND pc.status = 'pending' AND pc.expires_at > NOW()" . school_where('pc') . "
        ORDER BY pc.created_at DESC LIMIT 1
    ");
    $pcStmt->execute($pcParams);
    $childPendingChange = $pcStmt->fetch() ?: null;
} catch (PDOException $e) {}

// Enrolled classes (schedule)
$params = [$childId];
$schedStmt = $pdo->prepare("
    SELECT c.name as class_name, c.day_of_week, c.start_time, c.end_time
    FROM class_enrollments ce
    JOIN classes c ON ce.class_id = c.id
    WHERE ce.student_id = ? AND ce.status = 'active'" . school_where('ce') . "
    ORDER BY FIELD(c.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), c.start_time
");
school_param($params);
$schedStmt->execute($params);
$schedule = $schedStmt->fetchAll();

// Attendance stats
$params = [$childId];
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
    FROM attendance WHERE student_id = ?" . school_where() . "
");
school_param($params);
$statsStmt->execute($params);
$stats = $statsStmt->fetch();
$attendanceRate = ($stats['total'] > 0) ? round(($stats['present'] / $stats['total']) * 100) : 0;

// Upcoming events (registered + calendar-only)
$params = [$childId];
$evStmt = $pdo->prepare("
    SELECT e.id, e.name, e.event_date, e.start_time, e.event_type, e.location, e.requires_registration,
           er.payment_status, er.attendance_status
    FROM events e
    LEFT JOIN event_registrations er ON er.event_id = e.id AND er.student_id = ?
    WHERE e.event_date >= CURDATE()
      AND (er.id IS NOT NULL OR e.requires_registration = 0)" . school_where('e') . "
    ORDER BY e.event_date ASC
    LIMIT 8
");
school_param($params);
$evStmt->execute($params);
$upcomingEvents = $evStmt->fetchAll();

// Recent attendance (last 15)
$params = [$childId];
$attStmt = $pdo->prepare("
    SELECT a.*, c.name as class_name
    FROM attendance a
    JOIN classes c ON a.class_id = c.id
    WHERE a.student_id = ?" . school_where('a') . "
    ORDER BY a.attendance_date DESC LIMIT 15
");
school_param($params);
$attStmt->execute($params);
$recentAttendance = $attStmt->fetchAll();

// Belt history
$params = [$childId];
$beltHistStmt = $pdo->prepare("
    SELECT sb.*, b.name as belt_name, b.color, mas.name as style_name
    FROM student_belts sb
    JOIN belts b ON sb.belt_id = b.id
    JOIN martial_arts_styles mas ON sb.style_id = mas.id
    WHERE sb.student_id = ?" . school_where('sb') . "
    ORDER BY sb.awarded_date DESC
");
school_param($params);
$beltHistStmt->execute($params);
$beltHistory = $beltHistStmt->fetchAll();

include 'includes/parent_header.php';
?>

<div class="container mx-auto px-4 py-8">

    <!-- Child Switcher + Back -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="parent_portal.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">&larr; Dashboard</a>
            <span class="text-gray-300">|</span>
            <h1 class="text-2xl font-bold text-gray-800">
                <?= htmlspecialchars($child['first_name'] . ' ' . $child['last_name']) ?>
            </h1>
            <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                <?= ucfirst($child['status'] ?? 'active') ?>
            </span>
        </div>
        <?php if (count($children) > 1): ?>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500">Switch child:</span>
                <select onchange="window.location.href='parent_child.php?id='+this.value"
                        class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-blue-500">
                    <?php foreach ($children as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $c['id'] == $childId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Action Buttons -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
        <a href="parent_events.php?child=<?= $childId ?>" class="flex items-center gap-2 p-3 bg-blue-50 rounded-lg hover:bg-blue-100 transition text-sm font-medium text-blue-700">
            <span>🏆</span> Register for Events
        </a>
        <a href="parent_child_training.php?id=<?= $childId ?>" class="flex items-center gap-2 p-3 bg-purple-50 rounded-lg hover:bg-purple-100 transition text-sm font-medium text-purple-700">
            <span>📚</span> Training Resources
        </a>
        <a href="parent_child_membership.php?id=<?= $childId ?>" class="flex items-center gap-2 p-3 bg-green-50 rounded-lg hover:bg-green-100 transition text-sm font-medium text-green-700">
            <span>📋</span> Membership
        </a>
        <a href="parent_payment.php" class="flex items-center gap-2 p-3 bg-orange-50 rounded-lg hover:bg-orange-100 transition text-sm font-medium text-orange-700">
            <span>💳</span> Payment Methods
        </a>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <p class="text-3xl font-bold text-blue-600"><?= $stats['total'] ?? 0 ?></p>
            <p class="text-sm text-gray-500">Total Classes</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <p class="text-3xl font-bold <?= $attendanceRate >= 80 ? 'text-green-600' : ($attendanceRate >= 60 ? 'text-yellow-600' : 'text-red-600') ?>"><?= $attendanceRate ?>%</p>
            <p class="text-sm text-gray-500">Attendance Rate</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <p class="text-3xl font-bold text-purple-600"><?= count($schedule) ?></p>
            <p class="text-sm text-gray-500">Enrolled Classes</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <p class="text-3xl font-bold text-orange-600"><?= count($upcomingEvents) ?></p>
            <p class="text-sm text-gray-500">Upcoming Events</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        <!-- Belt & Membership Column -->
        <div class="space-y-6">
            <!-- Current Belt -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">🥋 Belt Rank</h3>
                <?php if ($currentBelt): ?>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-16 h-8 rounded" style="background: <?= htmlspecialchars($currentBelt['color']) ?>;"></div>
                            <div>
                                <p class="font-bold text-gray-800"><?= htmlspecialchars($currentBelt['belt_name']) ?></p>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars($currentBelt['style_name']) ?></p>
                                <p class="text-xs text-gray-400">Awarded: <?= formatDate($currentBelt['awarded_date']) ?></p>
                            </div>
                        </div>
                        <a href="student_certificate.php?child_id=<?= $childId ?>&belt_index=0"
                           target="_blank"
                           class="text-xs text-amber-600 hover:text-amber-800 font-medium whitespace-nowrap">&#128220; Certificate</a>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500">No belt rank awarded yet.</p>
                <?php endif; ?>

                <?php if (count($beltHistory) > 1): ?>
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-sm font-medium text-gray-600 mb-2">Belt History</p>
                        <div class="space-y-2">
                            <?php foreach (array_slice($beltHistory, 1) as $bhIdx => $bh): ?>
                                <div class="flex items-center justify-between text-sm">
                                    <div class="flex items-center gap-3">
                                        <div class="w-6 h-3 rounded" style="background: <?= htmlspecialchars($bh['color']) ?>;"></div>
                                        <span class="text-gray-700"><?= htmlspecialchars($bh['belt_name']) ?></span>
                                        <span class="text-gray-400 text-xs"><?= formatDate($bh['awarded_date']) ?></span>
                                    </div>
                                    <a href="student_certificate.php?child_id=<?= $childId ?>&belt_index=<?= $bhIdx + 1 ?>"
                                       target="_blank"
                                       class="text-xs text-amber-600 hover:text-amber-800 font-medium">&#128220; Certificate</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Membership -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">📋 Membership</h3>
                    <a href="parent_child_membership.php?id=<?= $childId ?>" class="text-sm text-blue-600 hover:underline">Manage</a>
                </div>
                <?php if ($membership): ?>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Plan</span>
                            <span class="font-semibold text-gray-800"><?= htmlspecialchars($membership['plan_name']) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Valid Until</span>
                            <span class="text-gray-800"><?= formatDate($membership['end_date']) ?></span>
                        </div>
                        <?php if ($membership['billing_frequency']): ?>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Billing</span>
                                <span class="text-gray-800 capitalize"><?= htmlspecialchars($membership['billing_frequency']) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Classes/Week</span>
                            <span class="text-gray-800"><?= $membership['classes_per_week'] ?: 'Unlimited' ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Price</span>
                            <span class="font-semibold text-green-700"><?= formatMoney($membership['price']) ?></span>
                        </div>
                    </div>
                <?php elseif ($childPendingChange): ?>
                    <?php
                    $pcIsMonthly = (isset($childPendingChange['new_billing_frequency']) && $childPendingChange['new_billing_frequency'] === 'monthly' && $childPendingChange['new_duration'] > 1);
                    $pcMonthlyAmt = $pcIsMonthly ? round($childPendingChange['new_plan_price'] / $childPendingChange['new_duration'], 2) : 0;
                    ?>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-500">Proposed Plan</span>
                            <span class="font-semibold text-gray-800"><?= htmlspecialchars($childPendingChange['new_plan_name']) ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-500">Price</span>
                            <?php if ($pcIsMonthly): ?>
                                <span class="font-semibold text-green-700"><?= formatMoney($pcMonthlyAmt) ?>/mo</span>
                            <?php else: ?>
                                <span class="font-semibold text-green-700"><?= formatMoney($childPendingChange['new_plan_price']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-500">Status</span>
                            <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded-full bg-orange-100 text-orange-700">Pending Approval</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-500">Proposed By</span>
                            <span class="text-gray-800 text-sm"><?= htmlspecialchars($childPendingChange['requested_by_name'] ?? 'Studio Admin') ?></span>
                        </div>
                        <div class="text-xs text-gray-400 pt-1">
                            Expires <?= date('M j, Y', strtotime($childPendingChange['expires_at'])) ?> &bull; Awaiting student approval
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <p class="text-gray-500 mb-3">No active membership.</p>
                        <a href="parent_child_membership.php?id=<?= $childId ?>" class="text-blue-600 hover:underline text-sm font-medium">View available plans</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Schedule & Events Column -->
        <div class="space-y-6">
            <!-- Class Schedule -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">📅 Class Schedule</h3>
                <?php if (!empty($schedule)): ?>
                    <div class="space-y-3">
                        <?php foreach ($schedule as $cls): ?>
                            <div class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
                                <div>
                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($cls['class_name']) ?></p>
                                    <p class="text-sm text-gray-500"><?= htmlspecialchars($cls['day_of_week']) ?></p>
                                </div>
                                <span class="text-sm text-gray-600">
                                    <?= date('g:i A', strtotime($cls['start_time'])) ?> - <?= date('g:i A', strtotime($cls['end_time'])) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-4">Not enrolled in any classes.</p>
                <?php endif; ?>
            </div>

            <!-- Upcoming Events -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">🏆 Upcoming Events</h3>
                    <a href="parent_events.php?child=<?= $childId ?>" class="text-sm text-blue-600 hover:underline">Browse All</a>
                </div>
                <?php if (!empty($upcomingEvents)): ?>
                    <div class="space-y-3">
                        <?php foreach ($upcomingEvents as $ev): ?>
                            <div class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
                                <div>
                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($ev['name']) ?></p>
                                    <p class="text-sm text-gray-500">
                                        <?= formatDate($ev['event_date']) ?>
                                        <?php if ($ev['start_time']): ?> at <?= date('g:i A', strtotime($ev['start_time'])) ?><?php endif; ?>
                                    </p>
                                </div>
                                <?php if (empty($ev['requires_registration'])): ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">Info Only</span>
                                <?php elseif ($ev['payment_status']): ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Registered</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-4">No upcoming events.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Attendance -->
    <?php if (!empty($recentAttendance)): ?>
        <div class="bg-white rounded-lg shadow mb-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">📊 Recent Attendance</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($recentAttendance as $att): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-600"><?= formatDate($att['attendance_date']) ?></td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm font-medium text-gray-800"><?= htmlspecialchars($att['class_name']) ?></td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <?php
                                    $attColors = ['present' => 'bg-green-100 text-green-800', 'absent' => 'bg-red-100 text-red-800', 'late' => 'bg-yellow-100 text-yellow-800'];
                                    ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $attColors[$att['status']] ?? 'bg-gray-100 text-gray-800' ?>">
                                        <?= ucfirst($att['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php include 'includes/student_footer.php'; ?>
