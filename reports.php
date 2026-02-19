<?php
require_once 'config.php';
requireLogin();

// === Date Range Filter ===
$report_range = $_GET['range'] ?? 'this_month';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

switch ($report_range) {
    case 'today':
        $date_from = date('Y-m-d');
        $date_to = date('Y-m-d');
        break;
    case 'this_week':
        $date_from = date('Y-m-d', strtotime('monday this week'));
        $date_to = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'this_month':
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-t');
        break;
    case 'last_month':
        $date_from = date('Y-m-01', strtotime('-1 month'));
        $date_to = date('Y-m-t', strtotime('-1 month'));
        break;
    case 'this_quarter':
        $quarter = ceil(date('n') / 3);
        $date_from = date('Y-' . str_pad(($quarter - 1) * 3 + 1, 2, '0', STR_PAD_LEFT) . '-01');
        $date_to = date('Y-m-t', strtotime($date_from . ' +2 months'));
        break;
    case 'this_year':
        $date_from = date('Y-01-01');
        $date_to = date('Y-12-31');
        break;
    case 'last_year':
        $date_from = date('Y-01-01', strtotime('-1 year'));
        $date_to = date('Y-12-31', strtotime('-1 year'));
        break;
    case 'custom':
        if (empty($date_from)) $date_from = date('Y-m-01');
        if (empty($date_to)) $date_to = date('Y-m-d');
        break;
    default:
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-t');
        break;
}

$range_label = '';
switch ($report_range) {
    case 'today': $range_label = 'Today'; break;
    case 'this_week': $range_label = 'This Week'; break;
    case 'this_month': $range_label = 'This Month'; break;
    case 'last_month': $range_label = 'Last Month'; break;
    case 'this_quarter': $range_label = 'This Quarter'; break;
    case 'this_year': $range_label = 'This Year'; break;
    case 'last_year': $range_label = 'Last Year'; break;
    case 'custom': $range_label = formatDate($date_from) . ' - ' . formatDate($date_to); break;
    default: $range_label = 'This Month'; break;
}

// Get summary stats
$stats = [];

// Student stats
$stats['total_students'] = $pdo->query("SELECT COUNT(*) as c FROM students")->fetch()['c'];
$stats['active_students'] = $pdo->query("SELECT COUNT(*) as c FROM students WHERE status = 'active'")->fetch()['c'];

// Membership stats
$stats['active_memberships'] = $pdo->query("SELECT COUNT(*) as c FROM memberships WHERE status = 'active' AND end_date >= CURDATE()")->fetch()['c'];
$stats['expiring_soon'] = $pdo->query("SELECT COUNT(*) as c FROM memberships WHERE status = 'active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetch()['c'];

// Financial stats — filtered by date range
try {
    $rev_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_date BETWEEN ? AND ? AND status = 'completed'");
    $rev_stmt->execute([$date_from, $date_to]);
    $stats['range_revenue'] = (float) $rev_stmt->fetch()['total'];
} catch (\PDOException $e) {
    $rev_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_date BETWEEN ? AND ?");
    $rev_stmt->execute([$date_from, $date_to]);
    $stats['range_revenue'] = (float) $rev_stmt->fetch()['total'];
}

// Also keep monthly/yearly for projected income section
try {
    $stats['monthly_revenue'] = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) AND status = 'completed'")->fetch()['total'];
} catch (\PDOException $e) {
    $stats['monthly_revenue'] = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())")->fetch()['total'];
}
try {
    $stats['yearly_revenue'] = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE()) AND status = 'completed'")->fetch()['total'];
} catch (\PDOException $e) {
    $stats['yearly_revenue'] = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE())")->fetch()['total'];
}

// Event stats
$stats['total_events'] = $pdo->query("SELECT COUNT(*) as c FROM events")->fetch()['c'];
$stats['upcoming_events'] = $pdo->query("SELECT COUNT(*) as c FROM events WHERE status = 'upcoming' AND event_date >= CURDATE()")->fetch()['c'];

// Class stats
$stats['total_classes'] = $pdo->query("SELECT COUNT(*) as c FROM classes WHERE status = 'active'")->fetch()['c'];

// Revenue by month — with status filter (always last 12 months for trend chart)
try {
    $monthly_revenue = $pdo->query("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total
        FROM payments
        WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND status = 'completed'
        GROUP BY month
        ORDER BY month ASC
    ")->fetchAll();
} catch (\PDOException $e) {
    $monthly_revenue = $pdo->query("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total
        FROM payments
        WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY month
        ORDER BY month ASC
    ")->fetchAll();
}

// Range-filtered payment count
try {
    $range_stmt = $pdo->prepare("SELECT COUNT(*) as c FROM payments WHERE payment_date BETWEEN ? AND ? AND status = 'completed'");
    $range_stmt->execute([$date_from, $date_to]);
    $stats['range_payment_count'] = (int) $range_stmt->fetch()['c'];
} catch (\PDOException $e) {
    $stats['range_payment_count'] = 0;
}

// Top students by attendance (uses date range)
$top_stmt = $pdo->prepare("
    SELECT s.first_name, s.last_name, COUNT(a.id) as attendance_count
    FROM students s
    JOIN attendance a ON s.id = a.student_id
    WHERE a.status = 'present' AND a.attendance_date BETWEEN ? AND ?
    GROUP BY s.id
    ORDER BY attendance_count DESC
    LIMIT 10
");
$top_stmt->execute([$date_from, $date_to]);
$top_attendance = $top_stmt->fetchAll();

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

// === NEW: Student Retention Cohorts ===
$retention_cohorts = [];
try {
    $retention_cohorts = $pdo->query("
        SELECT
            CASE
                WHEN DATEDIFF(CURDATE(), join_date) < 90 THEN 'Under 3 months'
                WHEN DATEDIFF(CURDATE(), join_date) < 180 THEN '3-6 months'
                WHEN DATEDIFF(CURDATE(), join_date) < 365 THEN '6-12 months'
                WHEN DATEDIFF(CURDATE(), join_date) < 730 THEN '1-2 years'
                ELSE '2+ years'
            END as cohort,
            COUNT(*) as count
        FROM students
        WHERE status = 'active'
        GROUP BY cohort
        ORDER BY MIN(DATEDIFF(CURDATE(), join_date))
    ")->fetchAll();
} catch (\PDOException $e) {}

$totalActiveForRetention = max(1, (int) ($stats['active_students'] ?: 1));

// === NEW: Projected vs Actual Income ===
$projected_monthly = 0;
try {
    $projected_monthly = (float) $pdo->query("
        SELECT COALESCE(SUM(
            ROUND(mp.price / GREATEST(mp.duration_months, 1), 2)
        ), 0) as projected
        FROM memberships m
        JOIN membership_plans mp ON m.plan_id = mp.id
        WHERE m.status = 'active' AND m.end_date >= CURDATE()
    ")->fetch()['projected'];
} catch (\PDOException $e) {}
$projected_yearly = $projected_monthly * 12;

// === NEW: Payment Defaults ===
$payment_defaults = [];
try {
    $payment_defaults = $pdo->query("
        SELECT rl.*, s.first_name, s.last_name, s.email, s.status as student_status,
               mp.name as plan_name, m.status as membership_status
        FROM renewal_log rl
        JOIN students s ON rl.student_id = s.id
        JOIN memberships m ON rl.membership_id = m.id
        JOIN membership_plans mp ON m.plan_id = mp.id
        WHERE rl.action = 'payment_failed'
        ORDER BY rl.created_at DESC
        LIMIT 50
    ")->fetchAll();
} catch (\PDOException $e) {}

// === Class Attendance Reports (date-range filtered) ===
$class_attendance = [];
try {
    $ca_stmt = $pdo->prepare("
        SELECT c.id, c.name, c.day_of_week, c.start_time,
               COUNT(DISTINCT a.attendance_date) as total_sessions,
               COUNT(CASE WHEN a.status = 'present' THEN 1 END) as total_present,
               COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as total_absent,
               COUNT(CASE WHEN a.status = 'late' THEN 1 END) as total_late,
               COUNT(a.id) as total_records,
               ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0), 1) as avg_rate
        FROM classes c
        LEFT JOIN attendance a ON c.id = a.class_id AND a.attendance_date BETWEEN ? AND ?
        WHERE c.status = 'active'
        GROUP BY c.id
        ORDER BY c.name
    ");
    $ca_stmt->execute([$date_from, $date_to]);
    $class_attendance = $ca_stmt->fetchAll();
} catch (\PDOException $e) {}

// === Payment Status Drill-Down Report ===
$payment_drilldown = [];
try {
    $payment_drilldown = $pdo->query("
        SELECT s.id, s.first_name, s.last_name, s.email, s.phone, s.status as student_status,
               mp.name as plan_name, mp.price as plan_price,
               m.status as membership_status, m.payment_status,
               m.start_date, m.end_date, m.amount_paid,
               s.emergency_contact_name as parent_name, s.emergency_contact_phone as parent_phone
        FROM students s
        JOIN memberships m ON m.student_id = s.id
        JOIN membership_plans mp ON m.plan_id = mp.id
        WHERE m.id = (
            SELECT m2.id FROM memberships m2
            WHERE m2.student_id = s.id
            ORDER BY m2.end_date DESC LIMIT 1
        )
        AND s.status = 'active'
        ORDER BY
            CASE m.payment_status
                WHEN 'pending' THEN 1
                WHEN 'partial' THEN 2
                WHEN 'paid' THEN 3
            END,
            s.last_name, s.first_name
    ")->fetchAll();
} catch (\PDOException $e) {}

$paid_count = 0;
$pending_count = 0;
$partial_count = 0;
$overdue_count = 0;
$today = date('Y-m-d');
foreach ($payment_drilldown as $pd) {
    if ($pd['payment_status'] === 'paid') $paid_count++;
    elseif ($pd['payment_status'] === 'pending') $pending_count++;
    elseif ($pd['payment_status'] === 'partial') $partial_count++;
    if (in_array($pd['payment_status'], ['pending', 'partial']) && $pd['end_date'] < $today) {
        $overdue_count++;
    }
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-wrap items-center justify-between mb-6 gap-4">
        <h1 class="text-3xl font-bold text-gray-800">Reports & Analytics</h1>
        <span class="text-sm text-gray-500 bg-blue-50 px-3 py-1 rounded-full font-medium"><?php echo $range_label; ?></span>
    </div>

    <!-- Date Range Filter -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Report Period</label>
                <select name="range" id="reportRange"
                        class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                        onchange="toggleCustomDates(this.value)">
                    <option value="today" <?php echo $report_range === 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="this_week" <?php echo $report_range === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                    <option value="this_month" <?php echo $report_range === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                    <option value="last_month" <?php echo $report_range === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                    <option value="this_quarter" <?php echo $report_range === 'this_quarter' ? 'selected' : ''; ?>>This Quarter</option>
                    <option value="this_year" <?php echo $report_range === 'this_year' ? 'selected' : ''; ?>>This Year</option>
                    <option value="last_year" <?php echo $report_range === 'last_year' ? 'selected' : ''; ?>>Last Year</option>
                    <option value="custom" <?php echo $report_range === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
            </div>

            <div id="customDateFields" class="<?php echo $report_range === 'custom' ? 'flex' : 'hidden'; ?> gap-3 items-end">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                           class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                           class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                Apply
            </button>
            <a href="reports.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg">
                Reset
            </a>
        </form>
    </div>

    <script>
    function toggleCustomDates(val) {
        document.getElementById('customDateFields').className = val === 'custom' ? 'flex gap-3 items-end' : 'hidden';
    }
    </script>

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
            <h3 class="text-gray-600 text-sm font-medium mb-2">Revenue (<?php echo $range_label; ?>)</h3>
            <p class="text-3xl font-bold text-green-600"><?php echo formatMoney($stats['range_revenue']); ?></p>
            <p class="text-sm text-gray-600 mt-1"><?php echo $stats['range_payment_count']; ?> payment(s)</p>
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

    <!-- Projected vs Actual Income -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Projected vs Actual Income</h2>
        <p class="text-sm text-gray-500 mb-4">Projected income is based on currently active memberships and their plan prices.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Monthly -->
            <div class="border rounded-lg p-5">
                <h3 class="text-sm font-medium text-gray-500 uppercase mb-3">Monthly</h3>
                <div class="flex items-end justify-between mb-2">
                    <div>
                        <p class="text-xs text-gray-500">Projected</p>
                        <p class="text-2xl font-bold text-blue-600"><?php echo formatMoney($projected_monthly); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-500">Actual</p>
                        <p class="text-2xl font-bold text-green-600"><?php echo formatMoney($stats['monthly_revenue']); ?></p>
                    </div>
                </div>
                <?php
                $monthlyVariance = $projected_monthly > 0 ? (($stats['monthly_revenue'] - $projected_monthly) / $projected_monthly) * 100 : 0;
                $mvColor = $monthlyVariance >= 0 ? 'text-green-600' : 'text-red-600';
                $mvSign = $monthlyVariance >= 0 ? '+' : '';
                ?>
                <div class="flex items-center justify-between pt-2 border-t border-gray-100">
                    <span class="text-xs text-gray-500">Variance</span>
                    <span class="text-sm font-semibold <?php echo $mvColor; ?>"><?php echo $mvSign . number_format($monthlyVariance, 1); ?>%</span>
                </div>
                <!-- Progress bar -->
                <?php $monthlyPct = $projected_monthly > 0 ? min(100, ($stats['monthly_revenue'] / $projected_monthly) * 100) : 0; ?>
                <div class="mt-2 w-full bg-gray-200 rounded-full h-2">
                    <div class="h-2 rounded-full <?php echo $monthlyPct >= 100 ? 'bg-green-500' : 'bg-blue-500'; ?>" style="width: <?php echo $monthlyPct; ?>%;"></div>
                </div>
            </div>

            <!-- Yearly -->
            <div class="border rounded-lg p-5">
                <h3 class="text-sm font-medium text-gray-500 uppercase mb-3">Yearly</h3>
                <div class="flex items-end justify-between mb-2">
                    <div>
                        <p class="text-xs text-gray-500">Projected</p>
                        <p class="text-2xl font-bold text-blue-600"><?php echo formatMoney($projected_yearly); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-500">Actual YTD</p>
                        <p class="text-2xl font-bold text-green-600"><?php echo formatMoney($stats['yearly_revenue']); ?></p>
                    </div>
                </div>
                <?php
                $yearlyVariance = $projected_yearly > 0 ? (($stats['yearly_revenue'] - $projected_yearly) / $projected_yearly) * 100 : 0;
                $yvColor = $yearlyVariance >= 0 ? 'text-green-600' : 'text-red-600';
                $yvSign = $yearlyVariance >= 0 ? '+' : '';
                // Calculate expected YTD progress (month/12)
                $monthOfYear = (int) date('n');
                $expectedYtd = $projected_yearly * ($monthOfYear / 12);
                ?>
                <div class="flex items-center justify-between pt-2 border-t border-gray-100">
                    <span class="text-xs text-gray-500">Expected YTD: <?php echo formatMoney($expectedYtd); ?></span>
                    <span class="text-sm font-semibold <?php echo $yvColor; ?>"><?php echo $yvSign . number_format($yearlyVariance, 1); ?>%</span>
                </div>
                <?php $yearlyPct = $projected_yearly > 0 ? min(100, ($stats['yearly_revenue'] / $projected_yearly) * 100) : 0; ?>
                <div class="mt-2 w-full bg-gray-200 rounded-full h-2">
                    <div class="h-2 rounded-full <?php echo $yearlyPct >= 80 ? 'bg-green-500' : 'bg-blue-500'; ?>" style="width: <?php echo $yearlyPct; ?>%;"></div>
                </div>
            </div>
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

    <!-- Payment Status Drill-Down -->
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Payment Status Report</h2>
                    <p class="text-sm text-gray-500">Drill-down view of membership payment status for all active students</p>
                </div>
                <div class="flex space-x-2">
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800"><?php echo $paid_count; ?> Paid</span>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800"><?php echo $pending_count; ?> Pending</span>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-orange-100 text-orange-800"><?php echo $partial_count; ?> Partial</span>
                    <?php if ($overdue_count > 0): ?>
                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800"><?php echo $overdue_count; ?> Overdue</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Filter tabs -->
        <div class="px-6 pt-4 flex space-x-2" id="paymentFilterTabs">
            <button onclick="filterPaymentRows('all')" class="payment-tab px-4 py-2 text-sm rounded-lg bg-blue-600 text-white font-medium" data-filter="all">All</button>
            <button onclick="filterPaymentRows('paid')" class="payment-tab px-4 py-2 text-sm rounded-lg bg-gray-200 text-gray-700 font-medium" data-filter="paid">Paid</button>
            <button onclick="filterPaymentRows('pending')" class="payment-tab px-4 py-2 text-sm rounded-lg bg-gray-200 text-gray-700 font-medium" data-filter="pending">Pending</button>
            <button onclick="filterPaymentRows('partial')" class="payment-tab px-4 py-2 text-sm rounded-lg bg-gray-200 text-gray-700 font-medium" data-filter="partial">Partial</button>
            <button onclick="filterPaymentRows('overdue')" class="payment-tab px-4 py-2 text-sm rounded-lg bg-gray-200 text-gray-700 font-medium" data-filter="overdue">Overdue</button>
        </div>

        <?php if (!empty($payment_drilldown)): ?>
        <div class="p-6 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="paymentDrilldownTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Payment</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Membership</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">End Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Parent/Contact</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($payment_drilldown as $pd):
                        $isOverdue = in_array($pd['payment_status'], ['pending', 'partial']) && $pd['end_date'] < $today;
                        $payClass = $pd['payment_status'];
                        $rowClasses = 'payment-row';
                        $rowClasses .= ' payment-' . $payClass;
                        if ($isOverdue) $rowClasses .= ' payment-overdue';
                    ?>
                    <tr class="<?php echo $rowClasses; ?> hover:bg-gray-50 <?php echo $isOverdue ? 'bg-red-50' : ''; ?>">
                        <td class="px-4 py-3">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($pd['first_name'] . ' ' . $pd['last_name']); ?></div>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($pd['email'] ?? ''); ?></div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($pd['plan_name']); ?></td>
                        <td class="px-4 py-3 text-sm text-right text-gray-800 font-medium"><?php echo formatMoney($pd['plan_price']); ?></td>
                        <td class="px-4 py-3 text-sm text-right font-medium <?php echo (float)$pd['amount_paid'] >= (float)$pd['plan_price'] ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo formatMoney($pd['amount_paid']); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php
                            $pColors = ['paid' => 'bg-green-100 text-green-800', 'pending' => 'bg-yellow-100 text-yellow-800', 'partial' => 'bg-orange-100 text-orange-800'];
                            $label = $isOverdue ? 'Overdue' : ucfirst($pd['payment_status']);
                            $color = $isOverdue ? 'bg-red-100 text-red-800' : ($pColors[$pd['payment_status']] ?? 'bg-gray-100 text-gray-600');
                            ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $color; ?>"><?php echo $label; ?></span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php
                            $mColors = ['active' => 'bg-green-100 text-green-800', 'expired' => 'bg-red-100 text-red-800', 'cancelled' => 'bg-gray-100 text-gray-600'];
                            ?>
                            <span class="px-2 py-1 text-xs rounded-full <?php echo $mColors[$pd['membership_status']] ?? 'bg-gray-100 text-gray-600'; ?>">
                                <?php echo ucfirst($pd['membership_status']); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <?php echo formatDate($pd['end_date']); ?>
                            <?php if ($pd['end_date'] < $today): ?>
                                <div class="text-xs text-red-600 font-semibold">Expired</div>
                            <?php elseif ($pd['end_date'] <= date('Y-m-d', strtotime('+7 days'))): ?>
                                <div class="text-xs text-orange-600">Expiring soon</div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <?php if (!empty($pd['parent_name'])): ?>
                                <div class="font-medium"><?php echo htmlspecialchars($pd['parent_name']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($pd['parent_phone'])): ?>
                                <div class="text-xs"><?php echo htmlspecialchars($pd['parent_phone']); ?></div>
                            <?php endif; ?>
                            <?php if (empty($pd['parent_name']) && empty($pd['parent_phone'])): ?>
                                <span class="text-gray-400">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="student_detail.php?id=<?php echo $pd['id']; ?>" class="text-sm text-blue-600 hover:text-blue-900">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500">
            <p>No active students with memberships to display.</p>
        </div>
        <?php endif; ?>
    </div>

    <script>
    function filterPaymentRows(filter) {
        const rows = document.querySelectorAll('.payment-row');
        rows.forEach(row => {
            if (filter === 'all') {
                row.style.display = '';
            } else if (filter === 'overdue') {
                row.style.display = row.classList.contains('payment-overdue') ? '' : 'none';
            } else {
                row.style.display = row.classList.contains('payment-' + filter) ? '' : 'none';
            }
        });
        // Update tab styles
        document.querySelectorAll('.payment-tab').forEach(tab => {
            if (tab.dataset.filter === filter) {
                tab.className = 'payment-tab px-4 py-2 text-sm rounded-lg bg-blue-600 text-white font-medium';
            } else {
                tab.className = 'payment-tab px-4 py-2 text-sm rounded-lg bg-gray-200 text-gray-700 font-medium';
            }
        });
    }
    </script>

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

    <!-- Student Retention -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-2">Student Retention</h2>
        <p class="text-sm text-gray-500 mb-4">Active students grouped by how long they have been members.</p>
        <?php if (!empty($retention_cohorts)): ?>
        <div class="space-y-3">
            <?php
            $cohortColors = [
                'Under 3 months' => 'bg-blue-400',
                '3-6 months'     => 'bg-blue-500',
                '6-12 months'    => 'bg-indigo-500',
                '1-2 years'      => 'bg-purple-500',
                '2+ years'       => 'bg-green-500',
            ];
            foreach ($retention_cohorts as $rc):
                $pct = round(($rc['count'] / $totalActiveForRetention) * 100, 1);
                $barColor = $cohortColors[$rc['cohort']] ?? 'bg-gray-400';
            ?>
            <div class="flex items-center gap-4">
                <div class="w-36 text-sm font-medium text-gray-700 text-right"><?php echo $rc['cohort']; ?></div>
                <div class="flex-1">
                    <div class="flex items-center gap-3">
                        <div class="flex-1 bg-gray-100 rounded-full h-6">
                            <div class="<?php echo $barColor; ?> rounded-full h-6 flex items-center justify-end pr-2 text-white text-xs font-bold" style="width: <?php echo max($pct, 8); ?>%;">
                                <?php echo $rc['count']; ?>
                            </div>
                        </div>
                        <span class="text-sm text-gray-500 w-14 text-right"><?php echo $pct; ?>%</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <p class="text-center text-gray-500 py-4">No active students to display.</p>
        <?php endif; ?>
    </div>

    <!-- Payment Defaults -->
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="px-6 py-4 border-b border-gray-200 bg-red-50">
            <div class="flex items-center space-x-3">
                <span class="text-xl">&#9888;</span>
                <div>
                    <h2 class="text-xl font-semibold text-red-800">Payment Defaults</h2>
                    <p class="text-sm text-red-600"><?php echo count($payment_defaults); ?> failed payment(s) recorded</p>
                </div>
            </div>
        </div>
        <?php if (!empty($payment_defaults)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Student Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Membership</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($payment_defaults as $pd): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm text-gray-600"><?php echo date('M j, Y', strtotime($pd['created_at'])); ?></td>
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($pd['first_name'] . ' ' . $pd['last_name']); ?></div>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($pd['email'] ?? ''); ?></div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($pd['plan_name']); ?></td>
                        <td class="px-6 py-4 text-sm text-right font-semibold text-red-600">
                            <?php echo $pd['amount'] ? '$' . number_format((float)$pd['amount'], 2) : 'N/A'; ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="px-2 py-1 text-xs rounded-full <?php echo $pd['student_status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'; ?>">
                                <?php echo ucfirst($pd['student_status']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="px-2 py-1 text-xs rounded-full <?php echo $pd['membership_status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo ucfirst($pd['membership_status']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="student_detail.php?id=<?php echo $pd['student_id']; ?>" class="text-sm text-blue-600 hover:text-blue-900">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500">
            <p>No payment failures recorded.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Class Attendance Reports -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-2">Class Attendance (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Attendance statistics per active class for the selected period.</p>
        <?php if (!empty($class_attendance)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Day</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Sessions</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Present</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Absent</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Late</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Attendance Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($class_attendance as $ca): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ca['name']); ?></div>
                            <div class="text-xs text-gray-500"><?php echo $ca['start_time'] ? date('g:i A', strtotime($ca['start_time'])) : ''; ?></div>
                        </td>
                        <td class="px-6 py-4 text-sm text-center text-gray-600"><?php echo $ca['day_of_week']; ?></td>
                        <td class="px-6 py-4 text-sm text-center text-gray-800 font-semibold"><?php echo $ca['total_sessions']; ?></td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-sm font-semibold text-green-700"><?php echo $ca['total_present']; ?></span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-sm font-semibold text-red-600"><?php echo $ca['total_absent']; ?></span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-sm font-semibold text-yellow-600"><?php echo $ca['total_late']; ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <?php $rate = (float) ($ca['avg_rate'] ?? 0); ?>
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-200 rounded-full h-3 max-w-[120px]">
                                    <div class="h-3 rounded-full <?php echo $rate >= 80 ? 'bg-green-500' : ($rate >= 60 ? 'bg-yellow-500' : 'bg-red-500'); ?>"
                                         style="width: <?php echo $rate; ?>%;"></div>
                                </div>
                                <span class="text-sm font-semibold <?php echo $rate >= 80 ? 'text-green-700' : ($rate >= 60 ? 'text-yellow-700' : 'text-red-700'); ?>">
                                    <?php echo number_format($rate, 1); ?>%
                                </span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No attendance data for active classes.</p>
        <?php endif; ?>
    </div>

    <!-- Top Attendance -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Top Students by Attendance (<?php echo $range_label; ?>)</h2>
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
