<?php
require_once 'config.php';
requireLogin();

// Get summary stats
$stats = [];

// Student stats
$stats['total_students'] = $pdo->query("SELECT COUNT(*) as c FROM students")->fetch()['c'];
$stats['active_students'] = $pdo->query("SELECT COUNT(*) as c FROM students WHERE status = 'active'")->fetch()['c'];

// Membership stats
$stats['active_memberships'] = $pdo->query("SELECT COUNT(*) as c FROM memberships WHERE status = 'active' AND end_date >= CURDATE()")->fetch()['c'];
$stats['expiring_soon'] = $pdo->query("SELECT COUNT(*) as c FROM memberships WHERE status = 'active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetch()['c'];

// Financial stats
$stats['monthly_revenue'] = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())")->fetch()['total'];
$stats['yearly_revenue'] = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE())")->fetch()['total'];

// Event stats
$stats['total_events'] = $pdo->query("SELECT COUNT(*) as c FROM events")->fetch()['c'];
$stats['upcoming_events'] = $pdo->query("SELECT COUNT(*) as c FROM events WHERE status = 'upcoming' AND event_date >= CURDATE()")->fetch()['c'];

// Class stats
$stats['total_classes'] = $pdo->query("SELECT COUNT(*) as c FROM classes WHERE status = 'active'")->fetch()['c'];

// Revenue by month
$monthly_revenue = $pdo->query("
    SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total
    FROM payments
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month ASC
")->fetchAll();

// Top students by attendance
$top_attendance = $pdo->query("
    SELECT s.first_name, s.last_name, COUNT(a.id) as attendance_count
    FROM students s
    JOIN attendance a ON s.id = a.student_id
    WHERE a.status = 'present' AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY s.id
    ORDER BY attendance_count DESC
    LIMIT 10
")->fetchAll();

// === COMPLIANCE REPORTS ===

// Students enrolled in classes but with NO active membership
$no_membership_students = $pdo->query("
    SELECT s.id, s.first_name, s.last_name, s.email,
           COUNT(ce.id) as enrolled_classes
    FROM students s
    JOIN class_enrollments ce ON ce.student_id = s.id AND ce.status = 'active'
    LEFT JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
    WHERE s.status = 'active'
      AND m.id IS NULL
    GROUP BY s.id
    ORDER BY enrolled_classes DESC
")->fetchAll();

// Students enrolled in MORE classes than their plan allows
$over_limit_students = $pdo->query("
    SELECT s.id, s.first_name, s.last_name, s.email,
           mp.name as plan_name, mp.classes_per_week,
           COUNT(ce.id) as enrolled_classes
    FROM students s
    JOIN class_enrollments ce ON ce.student_id = s.id AND ce.status = 'active'
    JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE s.status = 'active'
      AND mp.classes_per_week < 99
    GROUP BY s.id, s.first_name, s.last_name, s.email, mp.name, mp.classes_per_week
    HAVING COUNT(ce.id) > mp.classes_per_week
    ORDER BY (COUNT(ce.id) - mp.classes_per_week) DESC
")->fetchAll();

$total_compliance_issues = count($no_membership_students) + count($over_limit_students);

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Reports & Analytics</h1>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Total Students</h3>
            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['total_students']; ?></p>
            <p class="text-sm text-green-600 mt-1"><?php echo $stats['active_students']; ?> active</p>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Active Memberships</h3>
            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['active_memberships']; ?></p>
            <p class="text-sm text-orange-600 mt-1"><?php echo $stats['expiring_soon']; ?> expiring soon</p>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Monthly Revenue</h3>
            <p class="text-3xl font-bold text-green-600"><?php echo formatMoney($stats['monthly_revenue']); ?></p>
            <p class="text-sm text-gray-600 mt-1">This month</p>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Compliance Issues</h3>
            <p class="text-3xl font-bold <?php echo $total_compliance_issues > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                <?php echo $total_compliance_issues; ?>
            </p>
            <p class="text-sm <?php echo $total_compliance_issues > 0 ? 'text-red-600' : 'text-green-600'; ?> mt-1">
                <?php echo $total_compliance_issues > 0 ? 'Needs attention' : 'All clear'; ?>
            </p>
        </div>
    </div>

    <!-- Enrollment Compliance Report -->
    <?php if ($total_compliance_issues > 0): ?>
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="px-6 py-4 border-b border-gray-200 bg-red-50">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <span class="text-2xl">&#128680;</span>
                    <div>
                        <h2 class="text-xl font-semibold text-red-800">Enrollment Compliance Report</h2>
                        <p class="text-sm text-red-600"><?php echo $total_compliance_issues; ?> student(s) require attention</p>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <?php if (count($no_membership_students) > 0): ?>
                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                            <?php echo count($no_membership_students); ?> No Membership
                        </span>
                    <?php endif; ?>
                    <?php if (count($over_limit_students) > 0): ?>
                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-orange-100 text-orange-800">
                            <?php echo count($over_limit_students); ?> Over Limit
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($no_membership_students)): ?>
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-800 mb-3">
                <span class="text-red-600">&#9888;</span> Enrolled Without Active Membership
            </h3>
            <p class="text-sm text-gray-600 mb-4">These students are enrolled in classes but do not have an active membership.</p>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-red-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-red-700 uppercase">Student</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-red-700 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-red-700 uppercase">Enrolled Classes</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-red-700 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($no_membership_students as $s): ?>
                            <tr class="hover:bg-red-25">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo $s['first_name'] . ' ' . $s['last_name']; ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?php echo $s['email'] ?: 'N/A'; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                        <?php echo $s['enrolled_classes']; ?> class(es)
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <a href="student_detail.php?id=<?php echo $s['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">View Student</a>
                                    <a href="memberships.php" class="text-green-600 hover:text-green-900">Add Membership</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($over_limit_students)): ?>
        <div class="p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-3">
                <span class="text-orange-600">&#128680;</span> Over Enrollment Limit
            </h3>
            <p class="text-sm text-gray-600 mb-4">These students are enrolled in more classes than their membership plan allows.</p>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-orange-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase">Student</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase">Plan</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase">Allowed</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase">Enrolled</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase">Over By</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($over_limit_students as $s): ?>
                            <tr class="hover:bg-orange-25">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo $s['first_name'] . ' ' . $s['last_name']; ?></div>
                                    <div class="text-xs text-gray-500"><?php echo $s['email'] ?: ''; ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?php echo $s['plan_name']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?php echo $s['classes_per_week']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-orange-100 text-orange-800">
                                        <?php echo $s['enrolled_classes']; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-bold rounded-full bg-red-100 text-red-800">
                                        +<?php echo $s['enrolled_classes'] - $s['classes_per_week']; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <a href="student_detail.php?id=<?php echo $s['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">View Student</a>
                                    <a href="memberships.php" class="text-green-600 hover:text-green-900">Upgrade Plan</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-6 mb-8">
        <div class="flex items-center space-x-3">
            <span class="text-2xl">&#10003;</span>
            <div>
                <h2 class="text-lg font-semibold text-green-800">Enrollment Compliance: All Clear</h2>
                <p class="text-sm text-green-600">All students are within their enrollment limits and have active memberships.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Revenue Chart -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Revenue Trend (Last 12 Months)</h2>
        <div class="overflow-x-auto">
            <div class="flex items-end space-x-2 h-64">
                <?php foreach ($monthly_revenue as $data): ?>
                    <?php
                    $height = $stats['yearly_revenue'] > 0 ? ($data['total'] / $stats['yearly_revenue']) * 100 * 2 : 0;
                    ?>
                    <div class="flex-1 bg-blue-500 hover:bg-blue-600 rounded-t transition-all"
                         style="height: <?php echo max($height, 5); ?>%;"
                         title="<?php echo date('M Y', strtotime($data['month'] . '-01')); ?>: <?php echo formatMoney($data['total']); ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="flex space-x-2 mt-2">
                <?php foreach ($monthly_revenue as $data): ?>
                    <div class="flex-1 text-xs text-center text-gray-600">
                        <?php echo date('M', strtotime($data['month'] . '-01')); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Top Attendance -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Top Students by Attendance (Last 30 Days)</h2>
        <div class="space-y-3">
            <?php foreach ($top_attendance as $index => $student): ?>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold">
                            <?php echo $index + 1; ?>
                        </div>
                        <span class="font-medium text-gray-900">
                            <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                        </span>
                    </div>
                    <span class="text-blue-600 font-semibold">
                        <?php echo $student['attendance_count']; ?> classes
                    </span>
                </div>
            <?php endforeach; ?>

            <?php if (empty($top_attendance)): ?>
                <p class="text-center text-gray-500 py-8">No attendance data for the last 30 days</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
