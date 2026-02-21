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

// Revenue by month — enhanced with count and refund totals (always last 12 months for trend chart)
try {
    $monthly_revenue = $pdo->query("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
               SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total,
               SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as refund_total,
               COUNT(*) as txn_count
        FROM payments
        WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
          AND (status = 'completed' OR status = 'refunded')
        GROUP BY month
        ORDER BY month ASC
    ")->fetchAll();
} catch (\PDOException $e) {
    $monthly_revenue = $pdo->query("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
               SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total,
               SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as refund_total,
               COUNT(*) as txn_count
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

// Range-filtered refunds
$stats['range_refund_count'] = 0;
$stats['range_refund_total'] = 0.0;
try {
    $ref_stmt = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(ABS(amount)), 0) as total FROM payments WHERE payment_date BETWEEN ? AND ? AND (amount < 0 OR status = 'refunded')");
    $ref_stmt->execute([$date_from, $date_to]);
    $refRow = $ref_stmt->fetch();
    $stats['range_refund_count'] = (int) $refRow['cnt'];
    $stats['range_refund_total'] = (float) $refRow['total'];
} catch (\PDOException $e) {}

// Revenue by Category (date-range filtered)
$revenue_by_category = [];
try {
    $rbc_stmt = $pdo->prepare("
        SELECT payment_type, SUM(amount) as total, COUNT(*) as cnt
        FROM payments
        WHERE payment_date BETWEEN ? AND ? AND amount > 0
        GROUP BY payment_type
        ORDER BY total DESC
    ");
    $rbc_stmt->execute([$date_from, $date_to]);
    $revenue_by_category = $rbc_stmt->fetchAll();
} catch (\PDOException $e) {}

// Revenue by Payment Method (date-range filtered)
$revenue_by_method = [];
try {
    $rbm_stmt = $pdo->prepare("
        SELECT payment_method, SUM(amount) as total, COUNT(*) as cnt
        FROM payments
        WHERE payment_date BETWEEN ? AND ? AND amount > 0
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $rbm_stmt->execute([$date_from, $date_to]);
    $revenue_by_method = $rbm_stmt->fetchAll();
} catch (\PDOException $e) {}

// Year-over-Year Revenue (all years)
$yearly_comparison = [];
try {
    $yearly_comparison = $pdo->query("
        SELECT YEAR(payment_date) as yr,
               SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as revenue,
               SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as refunds,
               COUNT(*) as txn_count
        FROM payments
        GROUP BY yr
        ORDER BY yr ASC
    ")->fetchAll();
} catch (\PDOException $e) {}

// Calculate totals for category/method percentage calculations
$total_category_revenue = array_sum(array_column($revenue_by_category, 'total'));
$total_method_revenue = array_sum(array_column($revenue_by_method, 'total'));

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

// =============================================
// NEW QUERIES FOR TABBED REPORTS (Phase 2)
// =============================================

// --- Revenue Tab: Revenue Per Student ---
$avg_revenue_per_student = 0;
$paying_student_count = 0;
$top_spenders = [];
try {
    $rps_stmt = $pdo->prepare("
        SELECT ROUND(SUM(p.amount) / NULLIF(COUNT(DISTINCT p.student_id), 0), 2) as avg_per_student,
               COUNT(DISTINCT p.student_id) as paying_students
        FROM payments p
        WHERE p.payment_date BETWEEN ? AND ? AND p.amount > 0
    ");
    $rps_stmt->execute([$date_from, $date_to]);
    $rpsRow = $rps_stmt->fetch();
    $avg_revenue_per_student = (float) ($rpsRow['avg_per_student'] ?? 0);
    $paying_student_count = (int) ($rpsRow['paying_students'] ?? 0);
} catch (\PDOException $e) {}

try {
    $ts_stmt = $pdo->prepare("
        SELECT s.first_name, s.last_name, SUM(p.amount) as total_spent, COUNT(p.id) as payment_count
        FROM students s
        JOIN payments p ON s.id = p.student_id
        WHERE p.payment_date BETWEEN ? AND ? AND p.amount > 0
        GROUP BY s.id ORDER BY total_spent DESC LIMIT 10
    ");
    $ts_stmt->execute([$date_from, $date_to]);
    $top_spenders = $ts_stmt->fetchAll();
} catch (\PDOException $e) {}

// --- Revenue Tab: Best/Worst Performing Months (last 24 months) ---
$best_worst_months = [];
try {
    $best_worst_months = $pdo->query("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, DATE_FORMAT(payment_date, '%M %Y') as label,
               SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as revenue, COUNT(*) as txn_count
        FROM payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH) AND amount > 0
        GROUP BY month ORDER BY revenue DESC
    ")->fetchAll();
} catch (\PDOException $e) {}

// --- Students Tab: Student Lifetime Value ---
$ltv_cohorts = [];
try {
    $ltv_cohorts = $pdo->query("
        SELECT YEAR(s.join_date) as join_year, COUNT(DISTINCT s.id) as student_count,
               ROUND(AVG(COALESCE(rev.total, 0)), 2) as avg_ltv
        FROM students s
        LEFT JOIN (SELECT student_id, SUM(amount) as total FROM payments WHERE amount > 0 GROUP BY student_id) rev ON s.id = rev.student_id
        WHERE s.is_parent = 0
        GROUP BY join_year ORDER BY join_year DESC
    ")->fetchAll();
} catch (\PDOException $e) {}

$top_ltv_students = [];
try {
    $top_ltv_students = $pdo->query("
        SELECT s.first_name, s.last_name, s.join_date, s.status, COALESCE(SUM(p.amount), 0) as lifetime_revenue,
               COUNT(p.id) as total_payments
        FROM students s
        LEFT JOIN payments p ON s.id = p.student_id AND p.amount > 0
        WHERE s.is_parent = 0
        GROUP BY s.id ORDER BY lifetime_revenue DESC LIMIT 20
    ")->fetchAll();
} catch (\PDOException $e) {}

// --- Students Tab: Churn Risk / At-Risk Students ---
$churn_risk_students = [];
try {
    $churn_risk_students = $pdo->query("
        SELECT s.id, s.first_name, s.last_name, s.email, m.end_date, DATEDIFF(m.end_date, CURDATE()) as days_left,
               mp.name as plan_name, m.auto_renew,
               (SELECT COUNT(*) FROM attendance a WHERE a.student_id = s.id AND a.status = 'present'
                AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as recent_attendance,
               (SELECT COUNT(*) FROM renewal_log rl WHERE rl.student_id = s.id AND rl.action = 'payment_failed') as payment_failures
        FROM students s
        JOIN memberships m ON m.student_id = s.id AND m.status = 'active'
        JOIN membership_plans mp ON m.plan_id = mp.id
        WHERE s.status = 'active' AND (
            (m.end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND m.auto_renew = 0)
            OR EXISTS (SELECT 1 FROM renewal_log rl WHERE rl.student_id = s.id AND rl.action = 'payment_failed')
        )
        ORDER BY days_left ASC
    ")->fetchAll();
} catch (\PDOException $e) {}

// --- Students Tab: Belt Progression Pipeline ---
$belt_pipeline = [];
try {
    $belt_pipeline = $pdo->query("
        SELECT belt_rank, COUNT(*) as student_count
        FROM students
        WHERE status = 'active' AND is_parent = 0 AND belt_rank IS NOT NULL AND belt_rank != ''
        GROUP BY belt_rank ORDER BY student_count DESC
    ")->fetchAll();
} catch (\PDOException $e) {}

// --- Attendance Tab: Class Capacity Utilization ---
$class_capacity = [];
try {
    $class_capacity = $pdo->query("
        SELECT c.name, c.day_of_week, c.start_time, c.max_students,
               COUNT(ce.id) as enrolled, ROUND(COUNT(ce.id) * 100.0 / NULLIF(c.max_students, 0), 1) as utilization_pct
        FROM classes c
        LEFT JOIN class_enrollments ce ON c.id = ce.class_id AND ce.status = 'active'
        WHERE c.status = 'active' GROUP BY c.id ORDER BY utilization_pct DESC
    ")->fetchAll();
} catch (\PDOException $e) {}

// --- Attendance Tab: Attendance Trends (12 months for Chart.js line chart) ---
$attendance_trends = [];
try {
    $attendance_trends = $pdo->query("
        SELECT DATE_FORMAT(a.attendance_date, '%Y-%m') as month,
               COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
               COUNT(*) as total_records,
               ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0), 1) as rate
        FROM attendance a
        WHERE a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY month ORDER BY month ASC
    ")->fetchAll();
} catch (\PDOException $e) {}

// --- Events Tab: Event Revenue Summary ---
$event_revenue_summary = [];
try {
    $ers_stmt = $pdo->prepare("
        SELECT e.event_type, COUNT(DISTINCT e.id) as event_count, COUNT(er.id) as registrations,
               COALESCE(SUM(er.amount_paid), 0) as total_revenue
        FROM events e
        LEFT JOIN event_registrations er ON e.id = er.event_id
        WHERE e.event_date BETWEEN ? AND ?
        GROUP BY e.event_type ORDER BY total_revenue DESC
    ");
    $ers_stmt->execute([$date_from, $date_to]);
    $event_revenue_summary = $ers_stmt->fetchAll();
} catch (\PDOException $e) {}

// --- Events Tab: Event ROI / Attendance Analysis ---
$event_roi = [];
try {
    $eroi_stmt = $pdo->prepare("
        SELECT e.name, e.event_type, e.event_date, e.registration_fee, e.max_participants,
               COUNT(er.id) as registrations,
               COUNT(CASE WHEN er.attendance_status = 'attended' THEN 1 END) as attended,
               COUNT(CASE WHEN er.attendance_status = 'no_show' THEN 1 END) as no_shows,
               COALESCE(SUM(er.amount_paid), 0) as actual_revenue,
               ROUND(COUNT(er.id) * 100.0 / NULLIF(e.max_participants, 0), 1) as fill_rate
        FROM events e
        LEFT JOIN event_registrations er ON e.id = er.event_id
        WHERE e.event_date BETWEEN ? AND ?
        GROUP BY e.id ORDER BY e.event_date DESC
    ");
    $eroi_stmt->execute([$date_from, $date_to]);
    $event_roi = $eroi_stmt->fetchAll();
} catch (\PDOException $e) {}

// --- Compliance Tab: Discount Code Performance ---
$discount_performance = [];
try {
    $discount_performance = $pdo->query("
        SELECT dc.code, dc.description, dc.discount_type, dc.discount_value,
               dc.uses_count, dc.max_uses, COALESCE(SUM(dcu.applied_amount), 0) as total_discounted, dc.is_active
        FROM discount_codes dc
        LEFT JOIN discount_code_uses dcu ON dc.id = dcu.discount_code_id
        GROUP BY dc.id ORDER BY total_discounted DESC
    ")->fetchAll();
} catch (\PDOException $e) {}

// --- Compliance Tab: Credit Ledger Summary ---
$credit_summary = ['students_with_credit' => 0, 'total_outstanding' => 0];
$recent_credits = [];
try {
    $cs = $pdo->query("
        SELECT COUNT(DISTINCT id) as students_with_credit, COALESCE(SUM(account_credit), 0) as total_outstanding
        FROM students WHERE account_credit > 0
    ")->fetch();
    $credit_summary = $cs ?: $credit_summary;
} catch (\PDOException $e) {}
try {
    $recent_credits = $pdo->query("
        SELECT cl.created_at, s.first_name, s.last_name, cl.amount, cl.balance_after, cl.reference_type, cl.description
        FROM credit_ledger cl JOIN students s ON cl.student_id = s.id ORDER BY cl.created_at DESC LIMIT 25
    ")->fetchAll();
} catch (\PDOException $e) {}

// --- Tab Badge Counts ---
$tab_badges = [
    'revenue' => $stats['range_payment_count'],
    'students' => count($churn_risk_students),
    'attendance' => $stats['total_classes'],
    'events' => count($event_roi),
    'compliance' => $total_compliance_issues + count($payment_defaults),
];

include 'includes/header.php';
?>
