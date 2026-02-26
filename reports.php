<?php
require_once 'config.php';
requireLogin();
require_once 'includes/report_helpers.php';

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
$params = [];
$stmt = $pdo->prepare("SELECT COUNT(*) as c FROM students WHERE 1=1" . school_where());
school_param($params);
$stmt->execute($params);
$stats['total_students'] = $stmt->fetch()['c'];

$params = [];
$stmt = $pdo->prepare("SELECT COUNT(*) as c FROM students WHERE status = 'active'" . school_where());
school_param($params);
$stmt->execute($params);
$stats['active_students'] = $stmt->fetch()['c'];

// Membership stats
$params = [];
$stmt = $pdo->prepare("SELECT COUNT(*) as c FROM memberships WHERE status = 'active' AND end_date >= CURDATE()" . school_where());
school_param($params);
$stmt->execute($params);
$stats['active_memberships'] = $stmt->fetch()['c'];

$params = [];
$stmt = $pdo->prepare("SELECT COUNT(*) as c FROM memberships WHERE status = 'active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)" . school_where());
school_param($params);
$stmt->execute($params);
$stats['expiring_soon'] = $stmt->fetch()['c'];

// Financial stats — filtered by date range
try {
    $params = [$date_from, $date_to];
    $rev_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_date BETWEEN ? AND ? AND status = 'completed'" . school_where());
    school_param($params);
    $rev_stmt->execute($params);
    $stats['range_revenue'] = (float) $rev_stmt->fetch()['total'];
} catch (\PDOException $e) {
    $params = [$date_from, $date_to];
    $rev_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_date BETWEEN ? AND ?" . school_where());
    school_param($params);
    $rev_stmt->execute($params);
    $stats['range_revenue'] = (float) $rev_stmt->fetch()['total'];
}

// Also keep monthly/yearly for projected income section
try {
    $params = [];
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) AND status = 'completed'" . school_where());
    school_param($params);
    $stmt->execute($params);
    $stats['monthly_revenue'] = (float) $stmt->fetch()['total'];
} catch (\PDOException $e) {
    $params = [];
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())" . school_where());
    school_param($params);
    $stmt->execute($params);
    $stats['monthly_revenue'] = (float) $stmt->fetch()['total'];
}
try {
    $params = [];
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE()) AND status = 'completed'" . school_where());
    school_param($params);
    $stmt->execute($params);
    $stats['yearly_revenue'] = (float) $stmt->fetch()['total'];
} catch (\PDOException $e) {
    $params = [];
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE())" . school_where());
    school_param($params);
    $stmt->execute($params);
    $stats['yearly_revenue'] = (float) $stmt->fetch()['total'];
}

// Event stats
$params = [];
$stmt = $pdo->prepare("SELECT COUNT(*) as c FROM events WHERE 1=1" . school_where());
school_param($params);
$stmt->execute($params);
$stats['total_events'] = $stmt->fetch()['c'];

$params = [];
$stmt = $pdo->prepare("SELECT COUNT(*) as c FROM events WHERE status = 'upcoming' AND event_date >= CURDATE()" . school_where());
school_param($params);
$stmt->execute($params);
$stats['upcoming_events'] = $stmt->fetch()['c'];

// Class stats
$params = [];
$stmt = $pdo->prepare("SELECT COUNT(*) as c FROM classes WHERE status = 'active'" . school_where());
school_param($params);
$stmt->execute($params);
$stats['total_classes'] = $stmt->fetch()['c'];

// Revenue by month — enhanced with count and refund totals (always last 12 months for trend chart)
try {
    $params = [];
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
               SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total,
               SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as refund_total,
               COUNT(*) as txn_count
        FROM payments
        WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
          AND (status = 'completed' OR status = 'refunded')" . school_where() . "
        GROUP BY month
        ORDER BY month ASC
    ");
    school_param($params);
    $stmt->execute($params);
    $monthly_revenue = $stmt->fetchAll();
} catch (\PDOException $e) {
    $params = [];
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
               SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total,
               SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as refund_total,
               COUNT(*) as txn_count
        FROM payments
        WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . school_where() . "
        GROUP BY month
        ORDER BY month ASC
    ");
    school_param($params);
    $stmt->execute($params);
    $monthly_revenue = $stmt->fetchAll();
}

// Range-filtered payment count
try {
    $params = [$date_from, $date_to];
    $range_stmt = $pdo->prepare("SELECT COUNT(*) as c FROM payments WHERE payment_date BETWEEN ? AND ? AND status = 'completed'" . school_where());
    school_param($params);
    $range_stmt->execute($params);
    $stats['range_payment_count'] = (int) $range_stmt->fetch()['c'];
} catch (\PDOException $e) {
    $stats['range_payment_count'] = 0;
}

// Range-filtered refunds
$stats['range_refund_count'] = 0;
$stats['range_refund_total'] = 0.0;
try {
    $params = [$date_from, $date_to];
    $ref_stmt = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(ABS(amount)), 0) as total FROM payments WHERE payment_date BETWEEN ? AND ? AND (amount < 0 OR status = 'refunded')" . school_where());
    school_param($params);
    $ref_stmt->execute($params);
    $refRow = $ref_stmt->fetch();
    $stats['range_refund_count'] = (int) $refRow['cnt'];
    $stats['range_refund_total'] = (float) $refRow['total'];
} catch (\PDOException $e) {}

// Revenue by Category (date-range filtered)
$revenue_by_category = [];
try {
    $params = [$date_from, $date_to];
    $rbc_stmt = $pdo->prepare("
        SELECT payment_type, SUM(amount) as total, COUNT(*) as cnt
        FROM payments
        WHERE payment_date BETWEEN ? AND ? AND amount > 0" . school_where() . "
        GROUP BY payment_type
        ORDER BY total DESC
    ");
    school_param($params);
    $rbc_stmt->execute($params);
    $revenue_by_category = $rbc_stmt->fetchAll();
} catch (\PDOException $e) {}

// Revenue by Payment Method (date-range filtered)
$revenue_by_method = [];
try {
    $params = [$date_from, $date_to];
    $rbm_stmt = $pdo->prepare("
        SELECT payment_method, SUM(amount) as total, COUNT(*) as cnt
        FROM payments
        WHERE payment_date BETWEEN ? AND ? AND amount > 0" . school_where() . "
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    school_param($params);
    $rbm_stmt->execute($params);
    $revenue_by_method = $rbm_stmt->fetchAll();
} catch (\PDOException $e) {}

// Year-over-Year Revenue (all years)
$yearly_comparison = [];
try {
    $params = [];
    $stmt = $pdo->prepare("
        SELECT YEAR(payment_date) as yr,
               SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as revenue,
               SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as refunds,
               COUNT(*) as txn_count
        FROM payments
        WHERE 1=1" . school_where() . "
        GROUP BY yr
        ORDER BY yr ASC
    ");
    school_param($params);
    $stmt->execute($params);
    $yearly_comparison = $stmt->fetchAll();
} catch (\PDOException $e) {}

// Calculate totals for category/method percentage calculations
$total_category_revenue = array_sum(array_column($revenue_by_category, 'total'));
$total_method_revenue = array_sum(array_column($revenue_by_method, 'total'));

// Top students by attendance (uses date range)
$params = [$date_from, $date_to];
$top_stmt = $pdo->prepare("
    SELECT s.first_name, s.last_name, COUNT(a.id) as attendance_count
    FROM students s
    JOIN attendance a ON s.id = a.student_id
    WHERE a.status = 'present' AND a.attendance_date BETWEEN ? AND ?" . school_where('s') . "
    GROUP BY s.id
    ORDER BY attendance_count DESC
    LIMIT 10
");
school_param($params);
$top_stmt->execute($params);
$top_attendance = $top_stmt->fetchAll();

// === COMPLIANCE REPORTS ===

// Students enrolled in classes but with NO active membership
$params = [];
$stmt = $pdo->prepare("
    SELECT s.id, s.first_name, s.last_name, s.email,
           COUNT(ce.id) as enrolled_classes
    FROM students s
    JOIN class_enrollments ce ON ce.student_id = s.id AND ce.status = 'active'
    LEFT JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
    WHERE s.status = 'active'
      AND m.id IS NULL" . school_where('s') . "
    GROUP BY s.id
    ORDER BY enrolled_classes DESC
");
school_param($params);
$stmt->execute($params);
$no_membership_students = $stmt->fetchAll();

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

// =============================================
// NEW REPORT TAB QUERIES (Phases 4-8)
// =============================================

// === PHASE 4: School Comparison (super admin only) ===
$school_revenue = [];
$school_students = [];
$school_attendance = [];
$school_plan_mix = [];
if (is_super_admin()) {
    try {
        $school_revenue = $pdo->prepare("
            SELECT sc.name as school_name, COALESCE(SUM(p.amount), 0) as total_revenue, COUNT(p.id) as payment_count
            FROM schools sc
            LEFT JOIN payments p ON p.school_id = sc.id AND p.payment_date BETWEEN ? AND ? AND p.amount > 0
            WHERE sc.status = 'active'
            GROUP BY sc.id ORDER BY total_revenue DESC
        ");
        $school_revenue->execute([$date_from, $date_to]);
        $school_revenue = $school_revenue->fetchAll();
    } catch (\PDOException $e) { $school_revenue = []; }

    try {
        $school_students = $pdo->query("
            SELECT sc.name as school_name,
                   COUNT(DISTINCT s.id) as total_students,
                   COUNT(DISTINCT CASE WHEN s.status = 'active' THEN s.id END) as active_students,
                   COUNT(DISTINCT CASE WHEN m.status = 'active' AND m.end_date >= CURDATE() THEN m.id END) as active_memberships
            FROM schools sc
            LEFT JOIN students s ON s.school_id = sc.id AND s.is_parent = 0
            LEFT JOIN memberships m ON m.student_id = s.id
            WHERE sc.status = 'active'
            GROUP BY sc.id ORDER BY sc.name
        ")->fetchAll();
    } catch (\PDOException $e) { $school_students = []; }

    try {
        $sa_stmt = $pdo->prepare("
            SELECT sc.name as school_name,
                   COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
                   COUNT(a.id) as total_records,
                   ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0), 1) as attendance_rate
            FROM schools sc
            LEFT JOIN attendance a ON a.school_id = sc.id AND a.attendance_date BETWEEN ? AND ?
            WHERE sc.status = 'active'
            GROUP BY sc.id ORDER BY sc.name
        ");
        $sa_stmt->execute([$date_from, $date_to]);
        $school_attendance = $sa_stmt->fetchAll();
    } catch (\PDOException $e) { $school_attendance = []; }

    try {
        $school_plan_mix = $pdo->query("
            SELECT sc.name as school_name, mp.name as plan_name, COUNT(m.id) as member_count
            FROM schools sc
            JOIN memberships m ON m.school_id = sc.id AND m.status = 'active' AND m.end_date >= CURDATE()
            JOIN membership_plans mp ON m.plan_id = mp.id
            WHERE sc.status = 'active'
            GROUP BY sc.id, mp.id ORDER BY sc.name, member_count DESC
        ")->fetchAll();
    } catch (\PDOException $e) { $school_plan_mix = []; }
}

// === PHASE 5: Instructor Performance ===
$instructor_summary = [];
$instructor_promotions = [];
$instructor_capacity = [];
try {
    $is_stmt = $pdo->prepare("
        SELECT u.id, u.full_name,
               COUNT(DISTINCT c.id) as class_count,
               COUNT(DISTINCT ce.student_id) as student_count,
               (SELECT COUNT(DISTINCT a2.attendance_date) FROM attendance a2
                JOIN classes c2 ON a2.class_id = c2.id
                WHERE c2.instructor_id = u.id AND a2.attendance_date BETWEEN ? AND ?) as total_sessions,
               (SELECT ROUND(
                   COUNT(CASE WHEN a3.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(a3.id), 0), 1
               ) FROM attendance a3
                JOIN classes c3 ON a3.class_id = c3.id
                WHERE c3.instructor_id = u.id AND a3.attendance_date BETWEEN ? AND ?) as attendance_rate
        FROM users u
        JOIN classes c ON c.instructor_id = u.id AND c.status = 'active'
        LEFT JOIN class_enrollments ce ON ce.class_id = c.id AND ce.status = 'active'
        WHERE u.role = 'instructor'" . school_where('u') . "
        GROUP BY u.id ORDER BY student_count DESC
    ");
    $params = [$date_from, $date_to, $date_from, $date_to];
    school_param($params);
    $is_stmt->execute($params);
    $instructor_summary = $is_stmt->fetchAll();
} catch (\PDOException $e) {}

try {
    $ip_stmt = $pdo->prepare("
        SELECT u.full_name, COUNT(sb.id) as promotion_count
        FROM users u
        JOIN student_belts sb ON sb.instructor_id = u.id
        WHERE sb.awarded_date BETWEEN ? AND ?" . school_where('u') . "
        GROUP BY u.id ORDER BY promotion_count DESC
    ");
    $params = [$date_from, $date_to];
    school_param($params);
    $ip_stmt->execute($params);
    $instructor_promotions = $ip_stmt->fetchAll();
} catch (\PDOException $e) {}

try {
    $params = [];
    $ic_stmt = $pdo->prepare("
        SELECT u.full_name,
               SUM(c.max_students) as total_max,
               SUM(IFNULL(enr.cnt, 0)) as total_enrolled,
               ROUND(SUM(IFNULL(enr.cnt, 0)) * 100.0 / NULLIF(SUM(c.max_students), 0), 1) as utilization_pct
        FROM users u
        JOIN classes c ON c.instructor_id = u.id AND c.status = 'active' AND c.max_students > 0
        LEFT JOIN (SELECT class_id, COUNT(*) as cnt FROM class_enrollments WHERE status = 'active' GROUP BY class_id) enr ON enr.class_id = c.id
        WHERE u.role = 'instructor'" . school_where('u') . "
        GROUP BY u.id ORDER BY utilization_pct DESC
    ");
    school_param($params);
    $ic_stmt->execute($params);
    $instructor_capacity = $ic_stmt->fetchAll();
} catch (\PDOException $e) {}

// === PHASE 6: Membership Lifecycle ===
$new_memberships_trend = [];
$membership_renewal_rate = 0;
$membership_cancel_rate = 0;
$plan_popularity = [];
$avg_membership_duration = 0;
$payment_collection_rate = 0;
$mrr = 0;

try {
    $params = [];
    $nmt = $pdo->prepare("
        SELECT DATE_FORMAT(m.start_date, '%Y-%m') as month, COUNT(*) as count
        FROM memberships m
        WHERE m.start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . school_where('m') . "
        GROUP BY month ORDER BY month ASC
    ");
    school_param($params);
    $nmt->execute($params);
    $new_memberships_trend = $nmt->fetchAll();
} catch (\PDOException $e) {}

try {
    $params = [];
    $renewal_stmt = $pdo->prepare("
        SELECT
            COUNT(CASE WHEN m2.id IS NOT NULL THEN 1 END) as renewed,
            COUNT(*) as total_expired
        FROM memberships m1
        LEFT JOIN memberships m2 ON m2.student_id = m1.student_id
            AND m2.id != m1.id
            AND m2.start_date BETWEEN m1.end_date AND DATE_ADD(m1.end_date, INTERVAL 30 DAY)
        WHERE m1.status IN ('expired','cancelled')
          AND m1.end_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . school_where('m1') . "
    ");
    school_param($params);
    $renewal_stmt->execute($params);
    $rr = $renewal_stmt->fetch();
    $membership_renewal_rate = $rr['total_expired'] > 0 ? round(($rr['renewed'] / $rr['total_expired']) * 100, 1) : 0;
} catch (\PDOException $e) {}

try {
    $params = [];
    $cancel_stmt = $pdo->prepare("
        SELECT
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
            COUNT(*) as total
        FROM memberships
        WHERE end_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . school_where() . "
    ");
    school_param($params);
    $cancel_stmt->execute($params);
    $cr = $cancel_stmt->fetch();
    $membership_cancel_rate = $cr['total'] > 0 ? round(($cr['cancelled'] / $cr['total']) * 100, 1) : 0;
} catch (\PDOException $e) {}

try {
    $params = [];
    $pp_stmt = $pdo->prepare("
        SELECT mp.name as plan_name, COUNT(m.id) as active_count,
               ROUND(SUM(mp.price / GREATEST(mp.duration_months, 1)), 2) as monthly_revenue_estimate
        FROM memberships m
        JOIN membership_plans mp ON m.plan_id = mp.id
        WHERE m.status = 'active' AND m.end_date >= CURDATE()" . school_where('m') . "
        GROUP BY mp.id ORDER BY active_count DESC
    ");
    school_param($params);
    $pp_stmt->execute($params);
    $plan_popularity = $pp_stmt->fetchAll();
} catch (\PDOException $e) {}

try {
    $params = [];
    $dur_stmt = $pdo->prepare("
        SELECT ROUND(AVG(DATEDIFF(end_date, start_date)) / 30.44, 1) as avg_months
        FROM memberships WHERE start_date IS NOT NULL AND end_date IS NOT NULL
          AND end_date > start_date" . school_where() . "
    ");
    school_param($params);
    $dur_stmt->execute($params);
    $avg_membership_duration = (float) ($dur_stmt->fetch()['avg_months'] ?? 0);
} catch (\PDOException $e) {}

try {
    $params = [];
    $pcr_stmt = $pdo->prepare("
        SELECT
            COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid,
            COUNT(*) as total
        FROM memberships WHERE status = 'active' AND end_date >= CURDATE()" . school_where() . "
    ");
    school_param($params);
    $pcr_stmt->execute($params);
    $pcr = $pcr_stmt->fetch();
    $payment_collection_rate = $pcr['total'] > 0 ? round(($pcr['paid'] / $pcr['total']) * 100, 1) : 0;
} catch (\PDOException $e) {}

try {
    $params = [];
    $mrr_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(ROUND(mp.price / GREATEST(mp.duration_months, 1), 2)), 0) as mrr
        FROM memberships m
        JOIN membership_plans mp ON m.plan_id = mp.id
        WHERE m.status = 'active' AND m.end_date >= CURDATE()" . school_where('m') . "
    ");
    school_param($params);
    $mrr_stmt->execute($params);
    $mrr = (float) ($mrr_stmt->fetch()['mrr'] ?? 0);
} catch (\PDOException $e) {}

// === PHASE 7: Belt Progression ===
$avg_promotion_time = [];
$promotion_velocity = [];
$instructor_promotions_detail = [];
$belt_test_pass_rates = [];

try {
    $params = [];
    $apt_stmt = $pdo->prepare("
        SELECT b2.name as belt_rank,
               ROUND(AVG(DATEDIFF(sb2.awarded_date, sb1.awarded_date))) as avg_days
        FROM student_belts sb1
        JOIN student_belts sb2 ON sb2.student_id = sb1.student_id
            AND sb2.style_id = sb1.style_id
            AND sb2.id != sb1.id
            AND sb2.awarded_date > sb1.awarded_date
        JOIN belts b1 ON b1.id = sb1.belt_id
        JOIN belts b2 ON b2.id = sb2.belt_id AND b2.rank_order = b1.rank_order + 1
            AND b2.style_id = b1.style_id
        WHERE sb2.awarded_date IS NOT NULL AND sb1.awarded_date IS NOT NULL" . school_where('sb2') . "
        GROUP BY b2.name, b2.rank_order ORDER BY b2.rank_order ASC
    ");
    school_param($params);
    $apt_stmt->execute($params);
    $avg_promotion_time = $apt_stmt->fetchAll();
} catch (\PDOException $e) {}

try {
    $params = [];
    $pv_stmt = $pdo->prepare("
        SELECT YEAR(s.join_date) as cohort_year, COUNT(DISTINCT s.id) as student_count,
               COUNT(sb.id) as total_promotions,
               ROUND(COUNT(sb.id) / NULLIF(COUNT(DISTINCT s.id), 0), 2) as promotions_per_student
        FROM students s
        LEFT JOIN student_belts sb ON sb.student_id = s.id
        WHERE s.is_parent = 0 AND s.join_date IS NOT NULL" . school_where('s') . "
        GROUP BY cohort_year ORDER BY cohort_year DESC
    ");
    school_param($params);
    $pv_stmt->execute($params);
    $promotion_velocity = $pv_stmt->fetchAll();
} catch (\PDOException $e) {}

try {
    $ipd_stmt = $pdo->prepare("
        SELECT u.full_name, COUNT(sb.id) as promotion_count
        FROM users u
        JOIN student_belts sb ON sb.instructor_id = u.id
        WHERE sb.awarded_date BETWEEN ? AND ?" . school_where('u') . "
        GROUP BY u.id ORDER BY promotion_count DESC
    ");
    $params = [$date_from, $date_to];
    school_param($params);
    $ipd_stmt->execute($params);
    $instructor_promotions_detail = $ipd_stmt->fetchAll();
} catch (\PDOException $e) {}

try {
    $btpr_stmt = $pdo->prepare("
        SELECT e.name as event_name, e.event_date,
               COUNT(er.id) as total_tested,
               COUNT(CASE WHEN er.result LIKE '%pass%' OR er.attendance_status = 'attended' THEN 1 END) as total_passed,
               ROUND(COUNT(CASE WHEN er.result LIKE '%pass%' OR er.attendance_status = 'attended' THEN 1 END) * 100.0 / NULLIF(COUNT(er.id), 0), 1) as pass_rate
        FROM events e
        JOIN event_registrations er ON er.event_id = e.id
        WHERE e.event_type = 'belt_test' AND e.event_date BETWEEN ? AND ?" . school_where('e') . "
        GROUP BY e.id ORDER BY e.event_date DESC
    ");
    $params = [$date_from, $date_to];
    school_param($params);
    $btpr_stmt->execute($params);
    $belt_test_pass_rates = $btpr_stmt->fetchAll();
} catch (\PDOException $e) {}

// === PHASE 8: Attendance Patterns ===
$attendance_heatmap = [];
$student_consistency = [];
$dropout_risk = [];
$seasonal_patterns = [];

try {
    $ah_stmt = $pdo->prepare("
        SELECT c.day_of_week, HOUR(c.start_time) as hour_slot,
               COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
               COUNT(a.id) as total_records,
               ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0), 1) as rate
        FROM attendance a
        JOIN classes c ON a.class_id = c.id
        WHERE a.attendance_date BETWEEN ? AND ?" . school_where('a') . "
        GROUP BY c.day_of_week, hour_slot ORDER BY FIELD(c.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), hour_slot
    ");
    $params = [$date_from, $date_to];
    school_param($params);
    $ah_stmt->execute($params);
    $attendance_heatmap = $ah_stmt->fetchAll();
} catch (\PDOException $e) {}

try {
    $weeks_in_range = max(1, round((strtotime($date_to) - strtotime($date_from)) / (7 * 86400), 1));
    $sc_stmt = $pdo->prepare("
        SELECT s.first_name, s.last_name,
               COUNT(CASE WHEN a.status = 'present' THEN 1 END) as total_present,
               ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) / ?, 1) as sessions_per_week
        FROM students s
        JOIN attendance a ON a.student_id = s.id AND a.attendance_date BETWEEN ? AND ?
        WHERE s.status = 'active' AND s.is_parent = 0" . school_where('s') . "
        GROUP BY s.id ORDER BY sessions_per_week DESC LIMIT 50
    ");
    $params = [$weeks_in_range, $date_from, $date_to];
    school_param($params);
    $sc_stmt->execute($params);
    $student_consistency = $sc_stmt->fetchAll();
} catch (\PDOException $e) {}

try {
    $params = [];
    $dr_stmt = $pdo->prepare("
        SELECT s.id, s.first_name, s.last_name,
               (SELECT COUNT(*) FROM attendance a1 WHERE a1.student_id = s.id AND a1.status = 'present'
                AND a1.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as last_30d,
               (SELECT COUNT(*) FROM attendance a2 WHERE a2.student_id = s.id AND a2.status = 'present'
                AND a2.attendance_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as prev_30d
        FROM students s
        WHERE s.status = 'active' AND s.is_parent = 0" . school_where('s') . "
        HAVING prev_30d > 0 AND last_30d < prev_30d
        ORDER BY (last_30d - prev_30d) ASC
        LIMIT 30
    ");
    school_param($params);
    $dr_stmt->execute($params);
    $dropout_risk_raw = $dr_stmt->fetchAll();
    $dropout_risk = [];
    foreach ($dropout_risk_raw as $dr) {
        $dr['change_pct'] = $dr['prev_30d'] > 0 ? round((($dr['last_30d'] - $dr['prev_30d']) / $dr['prev_30d']) * 100, 1) : 0;
        $dropout_risk[] = $dr;
    }
} catch (\PDOException $e) {}

try {
    $params = [];
    $sp_stmt = $pdo->prepare("
        SELECT MONTH(a.attendance_date) as month_num,
               MONTHNAME(a.attendance_date) as month_name,
               ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0), 1) as attendance_rate
        FROM attendance a
        WHERE a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)" . school_where('a') . "
        GROUP BY month_num, month_name ORDER BY month_num
    ");
    school_param($params);
    $sp_stmt->execute($params);
    $seasonal_patterns = $sp_stmt->fetchAll();
} catch (\PDOException $e) {}

// --- Tab Badge Counts ---
$tab_badges = [
    'revenue' => $stats['range_payment_count'],
    'students' => count($churn_risk_students),
    'attendance' => $stats['total_classes'],
    'events' => count($event_roi),
    'compliance' => $total_compliance_issues + count($payment_defaults),
    'instructors' => count($instructor_summary),
    'membership_lifecycle' => count($plan_popularity),
    'belt_progression' => count($avg_promotion_time),
    'attendance_patterns' => count($dropout_risk),
];
if (is_super_admin()) {
    $tab_badges['schools'] = count($school_revenue);
}

include 'includes/header.php';
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-wrap items-center justify-between mb-6 gap-4">
        <h1 class="text-3xl font-bold text-gray-800">Reports &amp; Analytics</h1>
        <span class="text-sm text-gray-500 bg-blue-50 px-3 py-1 rounded-full font-medium"><?php echo $range_label; ?></span>
    </div>

    <!-- Date Range Filter -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Report Period</label>
                <select name="range" id="reportRange" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500" onchange="toggleCustomDates(this.value)">
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
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">Apply</button>
            <a href="reports.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg">Reset</a>
        </form>
    </div>
    <script>function toggleCustomDates(val){document.getElementById('customDateFields').className=val==='custom'?'flex gap-3 items-end':'hidden';}</script>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
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
            <?php if ($stats['range_refund_count'] > 0): ?>
                <p class="text-sm text-red-500 mt-1"><?php echo $stats['range_refund_count']; ?> refund(s): -<?php echo formatMoney($stats['range_refund_total']); ?></p>
            <?php endif; ?>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Compliance Issues</h3>
            <p class="text-3xl font-bold <?php echo $total_compliance_issues > 0 ? 'text-red-600' : 'text-green-600'; ?>"><?php echo $total_compliance_issues; ?></p>
            <p class="text-sm <?php echo $total_compliance_issues > 0 ? 'text-red-600' : 'text-green-600'; ?> mt-1"><?php echo $total_compliance_issues > 0 ? 'Needs attention' : 'All clear'; ?></p>
        </div>
    </div>

    <!-- Revenue Category Breakdown Badges -->
    <?php if (!empty($revenue_by_category)): ?>
    <?php
    $categoryLabels = ['membership' => 'Memberships', 'event' => 'Events', 'merchandise' => 'Merchandise', 'other' => 'Other'];
    $categoryColors = ['membership' => 'bg-blue-100 text-blue-800 border-blue-200', 'event' => 'bg-purple-100 text-purple-800 border-purple-200', 'merchandise' => 'bg-orange-100 text-orange-800 border-orange-200', 'other' => 'bg-gray-100 text-gray-700 border-gray-200'];
    ?>
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm font-medium text-gray-500">Revenue Breakdown:</span>
            <?php foreach ($revenue_by_category as $rc):
                $label = $categoryLabels[$rc['payment_type']] ?? ucfirst($rc['payment_type']);
                $color = $categoryColors[$rc['payment_type']] ?? 'bg-gray-100 text-gray-700 border-gray-200';
                $pct = $total_category_revenue > 0 ? round(($rc['total'] / $total_category_revenue) * 100, 1) : 0;
            ?>
                <span class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border text-sm font-medium <?php echo $color; ?>">
                    <?php echo $label; ?>: <strong><?php echo formatMoney($rc['total']); ?></strong>
                    <span class="text-xs opacity-75">(<?php echo $pct; ?>%)</span>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- TAB NAVIGATION -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="flex overflow-x-auto border-b border-gray-200" id="reportTabs">
            <button onclick="switchTab('revenue')" data-tab="revenue" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-blue-600 text-blue-600">
                Revenue <?php if ($tab_badges['revenue'] > 0): ?><span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-700"><?php echo $tab_badges['revenue']; ?></span><?php endif; ?>
            </button>
            <button onclick="switchTab('students')" data-tab="students" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                Students <?php if ($tab_badges['students'] > 0): ?><span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-orange-100 text-orange-700"><?php echo $tab_badges['students']; ?> at-risk</span><?php endif; ?>
            </button>
            <button onclick="switchTab('attendance')" data-tab="attendance" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                Attendance <?php if ($tab_badges['attendance'] > 0): ?><span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-gray-200 text-gray-600"><?php echo $tab_badges['attendance']; ?> classes</span><?php endif; ?>
            </button>
            <button onclick="switchTab('events')" data-tab="events" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                Events <?php if ($tab_badges['events'] > 0): ?><span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-700"><?php echo $tab_badges['events']; ?></span><?php endif; ?>
            </button>
            <button onclick="switchTab('compliance')" data-tab="compliance" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                Compliance <?php if ($tab_badges['compliance'] > 0): ?><span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-700"><?php echo $tab_badges['compliance']; ?></span><?php endif; ?>
            </button>
            <span class="self-center px-2 text-gray-300">|</span>
            <button onclick="switchTab('instructors')" data-tab="instructors" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                Instructors <?php if ($tab_badges['instructors'] > 0): ?><span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-teal-100 text-teal-700"><?php echo $tab_badges['instructors']; ?></span><?php endif; ?>
            </button>
            <button onclick="switchTab('membership-lifecycle')" data-tab="membership-lifecycle" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                Memberships
            </button>
            <button onclick="switchTab('belt-progression')" data-tab="belt-progression" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                Belt Progression
            </button>
            <button onclick="switchTab('attendance-patterns')" data-tab="attendance-patterns" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                Attendance Patterns <?php if ($tab_badges['attendance_patterns'] > 0): ?><span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-orange-100 text-orange-700"><?php echo $tab_badges['attendance_patterns']; ?> at-risk</span><?php endif; ?>
            </button>
            <?php if (is_super_admin()): ?>
            <span class="self-center px-2 text-gray-300">|</span>
            <button onclick="switchTab('schools')" data-tab="schools" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                School Comparison <?php if (!empty($tab_badges['schools'])): ?><span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-700"><?php echo $tab_badges['schools']; ?> schools</span><?php endif; ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================= -->
    <!-- REVENUE TAB -->
    <!-- ============================================= -->
    <div id="tab-revenue" class="report-panel">

    <!-- Export Buttons -->
    <div class="flex justify-end mb-4">
        <?php echo renderExportButtons('revenue', $report_range, $date_from, $date_to); ?>
    </div>

    <!-- Projected vs Actual Income -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Projected vs Actual Income</h2>
        <p class="text-sm text-gray-500 mb-4">Projected income is based on currently active memberships and their plan prices.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="border rounded-lg p-5">
                <h3 class="text-sm font-medium text-gray-500 uppercase mb-3">Monthly</h3>
                <div class="flex items-end justify-between mb-2">
                    <div><p class="text-xs text-gray-500">Projected</p><p class="text-2xl font-bold text-blue-600"><?php echo formatMoney($projected_monthly); ?></p></div>
                    <div class="text-right"><p class="text-xs text-gray-500">Actual</p><p class="text-2xl font-bold text-green-600"><?php echo formatMoney($stats['monthly_revenue']); ?></p></div>
                </div>
                <?php $monthlyVariance = $projected_monthly > 0 ? (($stats['monthly_revenue'] - $projected_monthly) / $projected_monthly) * 100 : 0; $mvColor = $monthlyVariance >= 0 ? 'text-green-600' : 'text-red-600'; $mvSign = $monthlyVariance >= 0 ? '+' : ''; ?>
                <div class="flex items-center justify-between pt-2 border-t border-gray-100">
                    <span class="text-xs text-gray-500">Variance</span>
                    <span class="text-sm font-semibold <?php echo $mvColor; ?>"><?php echo $mvSign . number_format($monthlyVariance, 1); ?>%</span>
                </div>
                <?php $monthlyPct = $projected_monthly > 0 ? min(100, ($stats['monthly_revenue'] / $projected_monthly) * 100) : 0; ?>
                <div class="mt-2 w-full bg-gray-200 rounded-full h-2">
                    <div class="h-2 rounded-full <?php echo $monthlyPct >= 100 ? 'bg-green-500' : 'bg-blue-500'; ?>" style="width: <?php echo $monthlyPct; ?>%;"></div>
                </div>
            </div>
            <div class="border rounded-lg p-5">
                <h3 class="text-sm font-medium text-gray-500 uppercase mb-3">Yearly</h3>
                <div class="flex items-end justify-between mb-2">
                    <div><p class="text-xs text-gray-500">Projected</p><p class="text-2xl font-bold text-blue-600"><?php echo formatMoney($projected_yearly); ?></p></div>
                    <div class="text-right"><p class="text-xs text-gray-500">Actual YTD</p><p class="text-2xl font-bold text-green-600"><?php echo formatMoney($stats['yearly_revenue']); ?></p></div>
                </div>
                <?php $yearlyVariance = $projected_yearly > 0 ? (($stats['yearly_revenue'] - $projected_yearly) / $projected_yearly) * 100 : 0; $yvColor = $yearlyVariance >= 0 ? 'text-green-600' : 'text-red-600'; $yvSign = $yearlyVariance >= 0 ? '+' : ''; $monthOfYear = (int) date('n'); $expectedYtd = $projected_yearly * ($monthOfYear / 12); ?>
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

    <!-- Revenue Trend Chart (Chart.js) -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Revenue Trend (Last 12 Months)</h2>
        <?php
        $totalChartRevenue = 0; $monthCount = count($monthly_revenue);
        foreach ($monthly_revenue as $d) { $totalChartRevenue += (float)$d['total']; }
        $avgMonthlyRevenue = $monthCount > 0 ? $totalChartRevenue / $monthCount : 0;
        ?>
        <p class="text-sm text-gray-500 mb-4">Total: <strong class="text-gray-800"><?php echo formatMoney($totalChartRevenue); ?></strong> &middot; Average: <strong class="text-gray-800"><?php echo formatMoney($avgMonthlyRevenue); ?>/mo</strong></p>
        <?php if (!empty($monthly_revenue)): ?>
        <div style="position:relative; height:350px;"><canvas id="revenueTrendChart"></canvas></div>
        <script>
        (function(){
            const ctx = document.getElementById('revenueTrendChart').getContext('2d');
            const labels = <?php echo json_encode(array_map(function($d){ return date('M \'y', strtotime($d['month'].'-01')); }, $monthly_revenue)); ?>;
            const data = <?php echo json_encode(array_map(function($d){ return round((float)$d['total'],2); }, $monthly_revenue)); ?>;
            const txns = <?php echo json_encode(array_map(function($d){ return (int)$d['txn_count']; }, $monthly_revenue)); ?>;
            const avg = <?php echo round($avgMonthlyRevenue,2); ?>;
            new Chart(ctx, {
                type:'bar', data:{labels:labels, datasets:[{label:'Revenue',data:data,
                    backgroundColor:data.map(v=>v>=avg?'rgba(16,185,129,0.8)':'rgba(96,165,250,0.8)'),
                    borderColor:data.map(v=>v>=avg?'rgba(16,185,129,1)':'rgba(96,165,250,1)'),borderWidth:1,borderRadius:4}]},
                plugins:[ChartDataLabels],
                options:{responsive:true,maintainAspectRatio:false,
                    plugins:{legend:{display:false},datalabels:{anchor:'end',align:'end',rotation:-45,
                        formatter:v=>'$'+v.toLocaleString('en-US',{maximumFractionDigits:0}),font:{weight:'bold',size:11},color:'#374151'},
                        tooltip:{callbacks:{label:function(c){return '$'+c.raw.toLocaleString('en-US',{minimumFractionDigits:2})+' ('+txns[c.dataIndex]+' txns)'}}}},
                    scales:{y:{beginAtZero:true,ticks:{callback:v=>'$'+v.toLocaleString()}},x:{grid:{display:false}}},
                    layout:{padding:{top:30}}}
            });
        })();
        </script>
        <?php else: ?><p class="text-center text-gray-500 py-8">No revenue data for the last 12 months.</p><?php endif; ?>
    </div>

    <!-- Revenue by Category -->
    <?php if (!empty($revenue_by_category)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Revenue by Category (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Breakdown of revenue by payment type for the selected period.</p>
        <?php
        $catBarColors = ['membership'=>'bg-blue-500','event'=>'bg-purple-500','merchandise'=>'bg-orange-500','other'=>'bg-gray-400'];
        $catTextColors = ['membership'=>'text-blue-700','event'=>'text-purple-700','merchandise'=>'text-orange-700','other'=>'text-gray-600'];
        $catLabels = ['membership'=>'Memberships','event'=>'Events','merchandise'=>'Merchandise','other'=>'Other'];
        $maxCatRevenue = max(1, max(array_column($revenue_by_category, 'total')));
        ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="space-y-4">
                <?php foreach ($revenue_by_category as $rc):
                    $catPct = ($rc['total']/$maxCatRevenue)*100; $barColor = $catBarColors[$rc['payment_type']] ?? 'bg-gray-400';
                    $textColor = $catTextColors[$rc['payment_type']] ?? 'text-gray-600'; $label = $catLabels[$rc['payment_type']] ?? ucfirst($rc['payment_type']); ?>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium <?php echo $textColor; ?>"><?php echo $label; ?></span>
                        <span class="text-sm font-bold text-gray-800"><?php echo formatMoney($rc['total']); ?></span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-5">
                        <div class="<?php echo $barColor; ?> rounded-full h-5 flex items-center justify-end pr-2 text-white text-xs font-semibold transition-all" style="width:<?php echo max($catPct,5); ?>%;"><?php echo $rc['cnt']; ?> txn<?php echo $rc['cnt']!=1?'s':''; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Category</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">% of Total</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Transactions</th></tr></thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($revenue_by_category as $rc): $pct=$total_category_revenue>0?round(($rc['total']/$total_category_revenue)*100,1):0; $label=$catLabels[$rc['payment_type']]??ucfirst($rc['payment_type']); ?>
                        <tr class="hover:bg-gray-50"><td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo $label; ?></td><td class="px-4 py-2 text-sm text-right font-semibold text-gray-800"><?php echo formatMoney($rc['total']); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo $pct; ?>%</td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($rc['cnt']); ?></td></tr>
                        <?php endforeach; ?>
                        <tr class="bg-gray-50 font-bold"><td class="px-4 py-2 text-sm text-gray-800">Total</td><td class="px-4 py-2 text-sm text-right text-gray-800"><?php echo formatMoney($total_category_revenue); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600">100%</td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format(array_sum(array_column($revenue_by_category,'cnt'))); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Revenue by Payment Method -->
    <?php if (!empty($revenue_by_method)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Revenue by Payment Method (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Breakdown of revenue by how payments were collected.</p>
        <?php
        $methodBarColors = ['credit_card'=>'bg-blue-500','bank_transfer'=>'bg-green-500','cash'=>'bg-yellow-500','debit_card'=>'bg-indigo-500','other'=>'bg-gray-400'];
        $methodTextColors = ['credit_card'=>'text-blue-700','bank_transfer'=>'text-green-700','cash'=>'text-yellow-700','debit_card'=>'text-indigo-700','other'=>'text-gray-600'];
        $methodLabels = ['credit_card'=>'Credit Card','bank_transfer'=>'Bank Transfer','cash'=>'Cash','debit_card'=>'Debit Card','other'=>'Other'];
        $maxMethodRevenue = max(1, max(array_column($revenue_by_method, 'total')));
        ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="space-y-4">
                <?php foreach ($revenue_by_method as $rm):
                    $methPct = ($rm['total']/$maxMethodRevenue)*100; $barColor = $methodBarColors[$rm['payment_method']] ?? 'bg-gray-400';
                    $textColor = $methodTextColors[$rm['payment_method']] ?? 'text-gray-600'; $label = $methodLabels[$rm['payment_method']] ?? ucfirst(str_replace('_',' ',$rm['payment_method'])); ?>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium <?php echo $textColor; ?>"><?php echo $label; ?></span>
                        <span class="text-sm font-bold text-gray-800"><?php echo formatMoney($rm['total']); ?></span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-5">
                        <div class="<?php echo $barColor; ?> rounded-full h-5 flex items-center justify-end pr-2 text-white text-xs font-semibold transition-all" style="width:<?php echo max($methPct,5); ?>%;"><?php echo $rm['cnt']; ?> txn<?php echo $rm['cnt']!=1?'s':''; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Method</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">% of Total</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Transactions</th></tr></thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($revenue_by_method as $rm): $pct=$total_method_revenue>0?round(($rm['total']/$total_method_revenue)*100,1):0; $label=$methodLabels[$rm['payment_method']]??ucfirst(str_replace('_',' ',$rm['payment_method'])); ?>
                        <tr class="hover:bg-gray-50"><td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo $label; ?></td><td class="px-4 py-2 text-sm text-right font-semibold text-gray-800"><?php echo formatMoney($rm['total']); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo $pct; ?>%</td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($rm['cnt']); ?></td></tr>
                        <?php endforeach; ?>
                        <tr class="bg-gray-50 font-bold"><td class="px-4 py-2 text-sm text-gray-800">Total</td><td class="px-4 py-2 text-sm text-right text-gray-800"><?php echo formatMoney($total_method_revenue); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600">100%</td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format(array_sum(array_column($revenue_by_method,'cnt'))); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Year-over-Year Revenue (Chart.js) -->
    <?php if (!empty($yearly_comparison) && count($yearly_comparison) > 1): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Year-over-Year Revenue</h2>
        <p class="text-sm text-gray-500 mb-4">Annual revenue and refund comparison across all years with payment data.</p>
        <div style="position:relative; height:350px;"><canvas id="yoyChart"></canvas></div>
        <script>
        (function(){
            const ctx = document.getElementById('yoyChart').getContext('2d');
            const years = <?php echo json_encode(array_map(function($y){return (string)$y['yr'];}, $yearly_comparison)); ?>;
            const revenue = <?php echo json_encode(array_map(function($y){return round((float)$y['revenue'],2);}, $yearly_comparison)); ?>;
            const refunds = <?php echo json_encode(array_map(function($y){return round((float)$y['refunds'],2);}, $yearly_comparison)); ?>;
            new Chart(ctx, {
                type:'bar', data:{labels:years, datasets:[
                    {label:'Revenue',data:revenue,backgroundColor:'rgba(16,185,129,0.8)',borderColor:'rgba(16,185,129,1)',borderWidth:1,borderRadius:4},
                    {label:'Refunds',data:refunds,backgroundColor:'rgba(248,113,113,0.8)',borderColor:'rgba(248,113,113,1)',borderWidth:1,borderRadius:4}
                ]}, plugins:[ChartDataLabels],
                options:{responsive:true,maintainAspectRatio:false,
                    plugins:{legend:{position:'top'},datalabels:{anchor:'end',align:'end',
                        formatter:v=>v>0?'$'+v.toLocaleString('en-US',{maximumFractionDigits:0}):'',font:{weight:'bold',size:11},color:'#374151'},
                        tooltip:{callbacks:{label:function(c){return c.dataset.label+': $'+c.raw.toLocaleString('en-US',{minimumFractionDigits:2})}}}},
                    scales:{y:{beginAtZero:true,ticks:{callback:v=>'$'+v.toLocaleString()}},x:{grid:{display:false}}},
                    layout:{padding:{top:30}}}
            });
        })();
        </script>
        <!-- YoY Summary Table -->
        <div class="overflow-x-auto mt-6">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Year</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Refunds</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Net Revenue</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Transactions</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Avg Transaction</th></tr></thead>
                <tbody class="divide-y divide-gray-200">
                    <?php $grandRevenue=0;$grandRefunds=0;$grandTxns=0; foreach ($yearly_comparison as $yc): $net=$yc['revenue']-$yc['refunds']; $avg=$yc['txn_count']>0?$yc['revenue']/$yc['txn_count']:0; $grandRevenue+=$yc['revenue'];$grandRefunds+=$yc['refunds'];$grandTxns+=$yc['txn_count']; ?>
                    <tr class="hover:bg-gray-50"><td class="px-4 py-2 text-sm font-bold text-gray-800"><?php echo $yc['yr']; ?></td><td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($yc['revenue']); ?></td><td class="px-4 py-2 text-sm text-right text-red-600"><?php echo $yc['refunds']>0?'-'.formatMoney($yc['refunds']):'$0.00'; ?></td><td class="px-4 py-2 text-sm text-right font-semibold text-gray-800"><?php echo formatMoney($net); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($yc['txn_count']); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo formatMoney($avg); ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="bg-gray-50 font-bold"><td class="px-4 py-2 text-sm text-gray-800">All Time</td><td class="px-4 py-2 text-sm text-right text-green-700"><?php echo formatMoney($grandRevenue); ?></td><td class="px-4 py-2 text-sm text-right text-red-600"><?php echo $grandRefunds>0?'-'.formatMoney($grandRefunds):'$0.00'; ?></td><td class="px-4 py-2 text-sm text-right text-gray-800"><?php echo formatMoney($grandRevenue-$grandRefunds); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($grandTxns); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo $grandTxns>0?formatMoney($grandRevenue/$grandTxns):'$0.00'; ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Revenue Per Student -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Revenue Per Student (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Average revenue generated per paying student in the selected period.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="border rounded-lg p-5 text-center"><p class="text-xs text-gray-500 uppercase mb-1">Avg Revenue / Student</p><p class="text-3xl font-bold text-blue-600"><?php echo formatMoney($avg_revenue_per_student); ?></p></div>
            <div class="border rounded-lg p-5 text-center"><p class="text-xs text-gray-500 uppercase mb-1">Paying Students</p><p class="text-3xl font-bold text-gray-800"><?php echo number_format($paying_student_count); ?></p></div>
        </div>
        <?php if (!empty($top_spenders)): ?>
        <h3 class="text-sm font-semibold text-gray-700 uppercase mb-3">Top 10 Spenders</h3>
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Student</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total Spent</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Payments</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($top_spenders as $i=>$ts): ?>
                <tr class="hover:bg-gray-50"><td class="px-4 py-2 text-sm text-gray-500"><?php echo $i+1; ?></td><td class="px-4 py-2 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ts['first_name'].' '.$ts['last_name']); ?></td><td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($ts['total_spent']); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo $ts['payment_count']; ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?><p class="text-center text-gray-500 py-4">No payment data for this period.</p><?php endif; ?>
    </div>

    <!-- Best/Worst Performing Months -->
    <?php if (!empty($best_worst_months)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Best &amp; Worst Performing Months</h2>
        <p class="text-sm text-gray-500 mb-4">Revenue ranking for the last 24 months. Top 3 highlighted green, bottom 3 highlighted red.</p>
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Rank</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Month</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Transactions</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
                <?php $bwCount=count($best_worst_months); foreach ($best_worst_months as $i=>$bw): $rowBg=''; if($i<3)$rowBg='bg-green-50'; elseif($i>=$bwCount-3)$rowBg='bg-red-50'; ?>
                <tr class="<?php echo $rowBg; ?> hover:bg-gray-50"><td class="px-4 py-2 text-sm font-bold <?php echo $i<3?'text-green-700':($i>=$bwCount-3?'text-red-700':'text-gray-500'); ?>"><?php echo $i+1; ?></td><td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($bw['label']); ?></td><td class="px-4 py-2 text-sm text-right font-semibold <?php echo $i<3?'text-green-700':($i>=$bwCount-3?'text-red-600':'text-gray-800'); ?>"><?php echo formatMoney($bw['revenue']); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($bw['txn_count']); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    </div><!-- END tab-revenue -->

    <!-- ============================================= -->
    <!-- STUDENTS TAB -->
    <!-- ============================================= -->
    <div id="tab-students" class="report-panel hidden">

    <!-- Export Buttons -->
    <div class="flex justify-end mb-4">
        <?php echo renderExportButtons('students', $report_range, $date_from, $date_to); ?>
    </div>

    <!-- Student Retention -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-2">Student Retention</h2>
        <p class="text-sm text-gray-500 mb-4">Active students grouped by how long they have been members.</p>
        <?php if (!empty($retention_cohorts)): ?>
        <div class="space-y-3">
            <?php $cohortColors = ['Under 3 months'=>'bg-blue-400','3-6 months'=>'bg-blue-500','6-12 months'=>'bg-indigo-500','1-2 years'=>'bg-purple-500','2+ years'=>'bg-green-500'];
            foreach ($retention_cohorts as $rc): $pct=round(($rc['count']/$totalActiveForRetention)*100,1); $barColor=$cohortColors[$rc['cohort']]??'bg-gray-400'; ?>
            <div class="flex items-center gap-4">
                <div class="w-36 text-sm font-medium text-gray-700 text-right"><?php echo $rc['cohort']; ?></div>
                <div class="flex-1"><div class="flex items-center gap-3"><div class="flex-1 bg-gray-100 rounded-full h-6"><div class="<?php echo $barColor; ?> rounded-full h-6 flex items-center justify-end pr-2 text-white text-xs font-bold" style="width:<?php echo max($pct,8); ?>%;"><?php echo $rc['count']; ?></div></div><span class="text-sm text-gray-500 w-14 text-right"><?php echo $pct; ?>%</span></div></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?><p class="text-center text-gray-500 py-4">No active students to display.</p><?php endif; ?>
    </div>

    <!-- Student Lifetime Value -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Student Lifetime Value (LTV)</h2>
        <p class="text-sm text-gray-500 mb-4">Average total revenue per student, grouped by join year.</p>
        <?php if (!empty($ltv_cohorts)): ?>
        <div class="overflow-x-auto mb-6"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Join Year</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Students</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Avg LTV</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($ltv_cohorts as $lc): ?>
                <tr class="hover:bg-gray-50"><td class="px-4 py-2 text-sm font-bold text-gray-800"><?php echo $lc['join_year']?:'Unknown'; ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($lc['student_count']); ?></td><td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($lc['avg_ltv']); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        <?php if (!empty($top_ltv_students)): ?>
        <h3 class="text-sm font-semibold text-gray-700 uppercase mb-3">Top 20 Students by Lifetime Revenue</h3>
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Student</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Joined</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Lifetime Revenue</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Payments</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($top_ltv_students as $i=>$ls): ?>
                <tr class="hover:bg-gray-50"><td class="px-4 py-2 text-sm text-gray-500"><?php echo $i+1; ?></td><td class="px-4 py-2 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ls['first_name'].' '.$ls['last_name']); ?></td><td class="px-4 py-2 text-sm text-gray-600"><?php echo $ls['join_date']?formatDate($ls['join_date']):'N/A'; ?></td><td class="px-4 py-2 text-center"><span class="px-2 py-1 text-xs rounded-full <?php echo $ls['status']==='active'?'bg-green-100 text-green-800':'bg-gray-100 text-gray-600'; ?>"><?php echo ucfirst($ls['status']); ?></span></td><td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($ls['lifetime_revenue']); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($ls['total_payments']); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <!-- Churn Risk -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Churn Risk / At-Risk Students</h2>
        <p class="text-sm text-gray-500 mb-4">Students whose memberships expire within 30 days (no auto-renew) or have payment failures.</p>
        <?php if (!empty($churn_risk_students)): ?>
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Student</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Plan</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Risk</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Days Left</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Auto-Renew</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">30d Attendance</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Failures</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Action</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($churn_risk_students as $cr):
                    $daysLeft=(int)$cr['days_left']; $failures=(int)$cr['payment_failures']; $attendance=(int)$cr['recent_attendance'];
                    if($daysLeft<=7||$failures>=2){$riskLevel='HIGH';$riskColor='bg-red-100 text-red-800';}
                    elseif($daysLeft<=14||$failures>=1||$attendance<=2){$riskLevel='MEDIUM';$riskColor='bg-orange-100 text-orange-800';}
                    else{$riskLevel='LOW';$riskColor='bg-yellow-100 text-yellow-800';} ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2"><div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($cr['first_name'].' '.$cr['last_name']); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars($cr['email']??''); ?></div></td>
                    <td class="px-4 py-2 text-sm text-gray-600"><?php echo htmlspecialchars($cr['plan_name']); ?></td>
                    <td class="px-4 py-2 text-center"><span class="px-2 py-1 text-xs font-bold rounded-full <?php echo $riskColor; ?>"><?php echo $riskLevel; ?></span></td>
                    <td class="px-4 py-2 text-sm text-center <?php echo $daysLeft<=7?'text-red-600 font-bold':'text-gray-600'; ?>"><?php echo $daysLeft; ?></td>
                    <td class="px-4 py-2 text-center text-xs <?php echo $cr['auto_renew']?'text-green-600':'text-red-600'; ?>"><?php echo $cr['auto_renew']?'Yes':'No'; ?></td>
                    <td class="px-4 py-2 text-sm text-center <?php echo $attendance<=2?'text-red-600 font-bold':'text-gray-600'; ?>"><?php echo $attendance; ?></td>
                    <td class="px-4 py-2 text-sm text-center <?php echo $failures>0?'text-red-600 font-bold':'text-gray-600'; ?>"><?php echo $failures; ?></td>
                    <td class="px-4 py-2 text-right"><a href="student_detail.php?id=<?php echo $cr['id']; ?>" class="text-sm text-blue-600 hover:text-blue-900">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4"><p class="text-sm text-green-700 font-medium">No at-risk students detected. All memberships are in good standing.</p></div>
        <?php endif; ?>
    </div>

    <!-- Belt Progression Pipeline -->
    <?php if (!empty($belt_pipeline)): $maxBeltCount=max(1,max(array_column($belt_pipeline,'student_count'))); ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Belt Progression Pipeline</h2>
        <p class="text-sm text-gray-500 mb-4">Active student distribution across belt ranks.</p>
        <div class="space-y-3">
            <?php $beltColors=['White'=>'bg-gray-400','Yellow'=>'bg-yellow-400','Orange'=>'bg-orange-400','Green'=>'bg-green-500','Blue'=>'bg-blue-500','Purple'=>'bg-purple-500','Brown'=>'bg-amber-700','Red'=>'bg-red-500','Black'=>'bg-gray-900'];
            foreach ($belt_pipeline as $bp): $beltPct=($bp['student_count']/$maxBeltCount)*100; $bc=$beltColors[$bp['belt_rank']]??'bg-gray-400'; ?>
            <div class="flex items-center gap-4">
                <div class="w-24 text-sm font-medium text-gray-700 text-right"><?php echo htmlspecialchars($bp['belt_rank']); ?></div>
                <div class="flex-1"><div class="flex-1 bg-gray-100 rounded-full h-6"><div class="<?php echo $bc; ?> rounded-full h-6 flex items-center justify-end pr-2 text-white text-xs font-bold" style="width:<?php echo max($beltPct,8); ?>%;"><?php echo $bp['student_count']; ?></div></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    </div><!-- END tab-students -->

    <!-- ============================================= -->
    <!-- ATTENDANCE TAB -->
    <!-- ============================================= -->
    <div id="tab-attendance" class="report-panel hidden">

    <!-- Export Buttons -->
    <div class="flex justify-end mb-4">
        <?php echo renderExportButtons('attendance', $report_range, $date_from, $date_to); ?>
    </div>

    <!-- Attendance Trends (Chart.js) -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Attendance Trends (Last 12 Months)</h2>
        <p class="text-sm text-gray-500 mb-4">Monthly attendance rate percentage over time.</p>
        <?php if (!empty($attendance_trends)): ?>
        <div style="position:relative; height:300px;"><canvas id="attendanceTrendChart"></canvas></div>
        <script>
        (function(){
            const ctx = document.getElementById('attendanceTrendChart').getContext('2d');
            const labels = <?php echo json_encode(array_map(function($d){return date('M \'y',strtotime($d['month'].'-01'));}, $attendance_trends)); ?>;
            const rates = <?php echo json_encode(array_map(function($d){return (float)$d['rate'];}, $attendance_trends)); ?>;
            const present = <?php echo json_encode(array_map(function($d){return (int)$d['present_count'];}, $attendance_trends)); ?>;
            const total = <?php echo json_encode(array_map(function($d){return (int)$d['total_records'];}, $attendance_trends)); ?>;
            new Chart(ctx, {
                type:'line', data:{labels:labels, datasets:[{label:'Attendance Rate %',data:rates,
                    borderColor:'rgba(16,185,129,1)',backgroundColor:'rgba(16,185,129,0.1)',fill:true,tension:0.3,pointRadius:5,pointBackgroundColor:'rgba(16,185,129,1)'}]},
                plugins:[ChartDataLabels],
                options:{responsive:true,maintainAspectRatio:false,
                    plugins:{legend:{display:false},datalabels:{anchor:'end',align:'end',formatter:v=>v.toFixed(1)+'%',font:{weight:'bold',size:11},color:'#374151'},
                        tooltip:{callbacks:{label:function(c){return c.raw.toFixed(1)+'% ('+present[c.dataIndex]+'/'+total[c.dataIndex]+')'}}}},
                    scales:{y:{beginAtZero:true,max:100,ticks:{callback:v=>v+'%'}},x:{grid:{display:false}}},layout:{padding:{top:20}}}
            });
        })();
        </script>
        <?php else: ?><p class="text-center text-gray-500 py-8">No attendance data for the last 12 months.</p><?php endif; ?>
    </div>

    <!-- Class Attendance -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-2">Class Attendance (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Attendance statistics per active class for the selected period.</p>
        <?php if (!empty($class_attendance)): ?>
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Class</th><th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Day</th><th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Sessions</th><th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Present</th><th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Absent</th><th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Late</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rate</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($class_attendance as $ca): $rate=(float)($ca['avg_rate']??0); ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4"><div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ca['name']); ?></div><div class="text-xs text-gray-500"><?php echo $ca['start_time']?date('g:i A',strtotime($ca['start_time'])):''; ?></div></td>
                    <td class="px-6 py-4 text-sm text-center text-gray-600"><?php echo $ca['day_of_week']; ?></td>
                    <td class="px-6 py-4 text-sm text-center font-semibold"><?php echo $ca['total_sessions']; ?></td>
                    <td class="px-6 py-4 text-center text-sm font-semibold text-green-700"><?php echo $ca['total_present']; ?></td>
                    <td class="px-6 py-4 text-center text-sm font-semibold text-red-600"><?php echo $ca['total_absent']; ?></td>
                    <td class="px-6 py-4 text-center text-sm font-semibold text-yellow-600"><?php echo $ca['total_late']; ?></td>
                    <td class="px-6 py-4"><div class="flex items-center gap-2"><div class="flex-1 bg-gray-200 rounded-full h-3 max-w-[120px]"><div class="h-3 rounded-full <?php echo $rate>=80?'bg-green-500':($rate>=60?'bg-yellow-500':'bg-red-500'); ?>" style="width:<?php echo $rate; ?>%;"></div></div><span class="text-sm font-semibold <?php echo $rate>=80?'text-green-700':($rate>=60?'text-yellow-700':'text-red-700'); ?>"><?php echo number_format($rate,1); ?>%</span></div></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?><p class="text-center text-gray-500 py-4">No attendance data for active classes.</p><?php endif; ?>
    </div>

    <!-- Class Capacity Utilization -->
    <?php if (!empty($class_capacity)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Class Capacity Utilization</h2>
        <p class="text-sm text-gray-500 mb-4">Current enrollment vs maximum capacity for each active class.</p>
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Class</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Day</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Enrolled</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Max</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Utilization</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($class_capacity as $cc): $util=(float)($cc['utilization_pct']??0); $utilColor=$util>=90?'bg-red-500':($util>=60?'bg-yellow-500':'bg-gray-400'); ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2"><div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($cc['name']); ?></div><div class="text-xs text-gray-500"><?php echo $cc['start_time']?date('g:i A',strtotime($cc['start_time'])):''; ?></div></td>
                    <td class="px-4 py-2 text-sm text-center text-gray-600"><?php echo $cc['day_of_week']; ?></td>
                    <td class="px-4 py-2 text-sm text-center font-semibold"><?php echo $cc['enrolled']; ?></td>
                    <td class="px-4 py-2 text-sm text-center text-gray-600"><?php echo $cc['max_students']?:'-'; ?></td>
                    <td class="px-4 py-2"><div class="flex items-center gap-2"><div class="flex-1 bg-gray-100 rounded-full h-4 max-w-[150px]"><div class="<?php echo $utilColor; ?> rounded-full h-4" style="width:<?php echo min($util,100); ?>%;"></div></div><span class="text-sm font-semibold <?php echo $util>=90?'text-red-700':($util>=60?'text-yellow-700':'text-gray-600'); ?>"><?php echo number_format($util,1); ?>%</span></div></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <!-- Top Students by Attendance -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Top Students by Attendance (<?php echo $range_label; ?>)</h2>
        <div class="space-y-3">
            <?php foreach ($top_attendance as $index=>$student): ?>
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div class="flex items-center space-x-3"><div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold"><?php echo $index+1; ?></div><span class="font-medium text-gray-900"><?php echo $student['first_name'].' '.$student['last_name']; ?></span></div>
                <span class="text-blue-600 font-semibold"><?php echo $student['attendance_count']; ?> classes</span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($top_attendance)): ?><p class="text-center text-gray-500 py-8">No attendance data for <?php echo htmlspecialchars($range_label); ?></p><?php endif; ?>
        </div>
    </div>

    </div><!-- END tab-attendance -->

    <!-- ============================================= -->
    <!-- EVENTS TAB -->
    <!-- ============================================= -->
    <div id="tab-events" class="report-panel hidden">

    <!-- Export Buttons -->
    <div class="flex justify-end mb-4">
        <?php echo renderExportButtons('events', $report_range, $date_from, $date_to); ?>
    </div>

    <!-- Event Revenue by Type -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Event Revenue by Type (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Revenue breakdown by event type for the selected period.</p>
        <?php if (!empty($event_revenue_summary)): ?>
        <?php $maxEventRev = max(1, max(array_column($event_revenue_summary, 'total_revenue'))); $eventTypeColors = ['tournament'=>'bg-red-500','seminar'=>'bg-blue-500','workshop'=>'bg-purple-500','belt_test'=>'bg-yellow-500','social'=>'bg-green-500']; ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="space-y-4">
                <?php foreach ($event_revenue_summary as $ers): $epct=($ers['total_revenue']/$maxEventRev)*100; $eColor=$eventTypeColors[$ers['event_type']]??'bg-gray-400'; ?>
                <div>
                    <div class="flex items-center justify-between mb-1"><span class="text-sm font-medium text-gray-700"><?php echo ucfirst(str_replace('_',' ',$ers['event_type'])); ?></span><span class="text-sm font-bold text-gray-800"><?php echo formatMoney($ers['total_revenue']); ?></span></div>
                    <div class="w-full bg-gray-100 rounded-full h-5"><div class="<?php echo $eColor; ?> rounded-full h-5 flex items-center justify-end pr-2 text-white text-xs font-semibold" style="width:<?php echo max($epct,5); ?>%;"><?php echo $ers['registrations']; ?> reg</div></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div><table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Events</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Registrations</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th></tr></thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($event_revenue_summary as $ers): ?>
                    <tr class="hover:bg-gray-50"><td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo ucfirst(str_replace('_',' ',$ers['event_type'])); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo $ers['event_count']; ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo $ers['registrations']; ?></td><td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($ers['total_revenue']); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php else: ?><p class="text-center text-gray-500 py-8">No events for the selected period.</p><?php endif; ?>
    </div>

    <!-- Event ROI / Attendance Analysis -->
    <?php if (!empty($event_roi)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Event ROI / Attendance Analysis</h2>
        <p class="text-sm text-gray-500 mb-4">Detailed event performance with fill rates and attendance tracking.</p>
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Event</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Date</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Registered</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Attended</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">No-Shows</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Fill Rate</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($event_roi as $er): $fr=(float)($er['fill_rate']??0); $frColor=$fr>=80?'bg-green-500':($fr>=50?'bg-yellow-500':'bg-gray-400'); ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2"><div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($er['name']); ?></div><div class="text-xs text-gray-500"><?php echo ucfirst(str_replace('_',' ',$er['event_type'])); ?></div></td>
                    <td class="px-4 py-2 text-sm text-center text-gray-600"><?php echo $er['event_date']?formatDate($er['event_date']):''; ?></td>
                    <td class="px-4 py-2 text-sm text-center font-semibold"><?php echo $er['registrations']; ?>/<?php echo $er['max_participants']?:'-'; ?></td>
                    <td class="px-4 py-2 text-sm text-center text-green-700 font-semibold"><?php echo $er['attended']; ?></td>
                    <td class="px-4 py-2 text-sm text-center <?php echo $er['no_shows']>0?'text-red-600 font-semibold':'text-gray-500'; ?>"><?php echo $er['no_shows']; ?></td>
                    <td class="px-4 py-2"><div class="flex items-center gap-2"><div class="flex-1 bg-gray-100 rounded-full h-3 max-w-[100px]"><div class="<?php echo $frColor; ?> rounded-full h-3" style="width:<?php echo min($fr,100); ?>%;"></div></div><span class="text-xs font-semibold"><?php echo number_format($fr,1); ?>%</span></div></td>
                    <td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($er['actual_revenue']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    </div><!-- END tab-events -->

    <!-- ============================================= -->
    <!-- COMPLIANCE TAB -->
    <!-- ============================================= -->
    <div id="tab-compliance" class="report-panel hidden">

    <!-- Export Buttons -->
    <div class="flex justify-end mb-4">
        <?php echo renderExportButtons('compliance', $report_range, $date_from, $date_to); ?>
    </div>

    <!-- Enrollment Compliance Report -->
    <?php if ($total_compliance_issues > 0): ?>
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="px-6 py-4 border-b border-gray-200 bg-red-50">
            <div class="flex items-center space-x-3">
                <span class="text-2xl">&#128680;</span>
                <div><h2 class="text-xl font-semibold text-red-800">Enrollment Compliance Report</h2><p class="text-sm text-red-600"><?php echo $total_compliance_issues; ?> student(s) require attention</p></div>
            </div>
        </div>
        <?php if (!empty($no_membership_students)): ?>
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800 mb-3"><span class="text-red-600">&#128680;</span> No Active Membership</h3>
            <p class="text-sm text-gray-600 mb-4">These students are enrolled in classes but do not have an active membership.</p>
            <div class="overflow-x-auto"><table class="min-w-full">
                <thead class="bg-red-50"><tr><th class="px-6 py-3 text-left text-xs font-medium text-red-700 uppercase">Student</th><th class="px-6 py-3 text-left text-xs font-medium text-red-700 uppercase">Enrolled Classes</th><th class="px-6 py-3 text-left text-xs font-medium text-red-700 uppercase">Action</th></tr></thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($no_membership_students as $s): ?>
                    <tr class="hover:bg-red-25"><td class="px-6 py-4 whitespace-nowrap"><div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($s['first_name'].' '.$s['last_name']); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars($s['email']?:''); ?></div></td><td class="px-6 py-4 whitespace-nowrap"><span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800"><?php echo $s['enrolled_classes']; ?> class(es)</span></td><td class="px-6 py-4 whitespace-nowrap text-sm"><a href="student_detail.php?id=<?php echo $s['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a> <a href="memberships.php" class="text-green-600 hover:text-green-900">Add Membership</a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($over_limit_students)): ?>
        <div class="p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-3"><span class="text-orange-600">&#128680;</span> Over Enrollment Limit</h3>
            <p class="text-sm text-gray-600 mb-4">These students are enrolled in more classes than their membership plan allows.</p>
            <div class="overflow-x-auto"><table class="min-w-full">
                <thead class="bg-orange-50"><tr><th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase">Student</th><th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase">Plan</th><th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase">Allowed</th><th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase">Enrolled</th><th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase">Over By</th><th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase">Action</th></tr></thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($over_limit_students as $s): ?>
                    <tr class="hover:bg-orange-25"><td class="px-6 py-4 whitespace-nowrap"><div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($s['first_name'].' '.$s['last_name']); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars($s['email']?:''); ?></div></td><td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($s['plan_name']); ?></td><td class="px-6 py-4 text-sm text-gray-600"><?php echo $s['classes_per_week']; ?></td><td class="px-6 py-4"><span class="px-2 py-1 text-xs font-semibold rounded-full bg-orange-100 text-orange-800"><?php echo $s['enrolled_classes']; ?></span></td><td class="px-6 py-4"><span class="px-2 py-1 text-xs font-bold rounded-full bg-red-100 text-red-800">+<?php echo $s['enrolled_classes']-$s['classes_per_week']; ?></span></td><td class="px-6 py-4 text-sm"><a href="student_detail.php?id=<?php echo $s['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a> <a href="memberships.php" class="text-green-600 hover:text-green-900">Upgrade</a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-6 mb-8">
        <div class="flex items-center space-x-3"><span class="text-2xl">&#10003;</span><div><h2 class="text-lg font-semibold text-green-800">Enrollment Compliance: All Clear</h2><p class="text-sm text-green-600">All students are within their enrollment limits and have active memberships.</p></div></div>
    </div>
    <?php endif; ?>

    <!-- Payment Status Drill-Down -->
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <div><h2 class="text-xl font-semibold text-gray-800">Payment Status Report</h2><p class="text-sm text-gray-500">Drill-down view of membership payment status for all active students</p></div>
                <div class="flex space-x-2">
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800"><?php echo $paid_count; ?> Paid</span>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800"><?php echo $pending_count; ?> Pending</span>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-orange-100 text-orange-800"><?php echo $partial_count; ?> Partial</span>
                    <?php if ($overdue_count > 0): ?><span class="px-3 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800"><?php echo $overdue_count; ?> Overdue</span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="px-6 pt-4 flex space-x-2" id="paymentFilterTabs">
            <button onclick="filterPaymentRows('all')" class="payment-tab px-4 py-2 text-sm rounded-lg bg-blue-600 text-white font-medium" data-filter="all">All</button>
            <button onclick="filterPaymentRows('paid')" class="payment-tab px-4 py-2 text-sm rounded-lg bg-gray-200 text-gray-700 font-medium" data-filter="paid">Paid</button>
            <button onclick="filterPaymentRows('pending')" class="payment-tab px-4 py-2 text-sm rounded-lg bg-gray-200 text-gray-700 font-medium" data-filter="pending">Pending</button>
            <button onclick="filterPaymentRows('partial')" class="payment-tab px-4 py-2 text-sm rounded-lg bg-gray-200 text-gray-700 font-medium" data-filter="partial">Partial</button>
            <button onclick="filterPaymentRows('overdue')" class="payment-tab px-4 py-2 text-sm rounded-lg bg-gray-200 text-gray-700 font-medium" data-filter="overdue">Overdue</button>
        </div>
        <?php if (!empty($payment_drilldown)): ?>
        <div class="p-6 overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th><th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th><th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Paid</th><th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Payment</th><th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Membership</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">End Date</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th><th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($payment_drilldown as $pd):
                    $isOverdue=in_array($pd['payment_status'],['pending','partial'])&&$pd['end_date']<$today;
                    $rowClasses='payment-row payment-'.$pd['payment_status'];
                    if($isOverdue)$rowClasses.=' payment-overdue'; ?>
                <tr class="<?php echo $rowClasses; ?> hover:bg-gray-50 <?php echo $isOverdue?'bg-red-50':''; ?>">
                    <td class="px-4 py-3"><div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($pd['first_name'].' '.$pd['last_name']); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars($pd['email']??''); ?></div></td>
                    <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($pd['plan_name']); ?></td>
                    <td class="px-4 py-3 text-sm text-right font-medium"><?php echo formatMoney($pd['plan_price']); ?></td>
                    <td class="px-4 py-3 text-sm text-right font-medium <?php echo (float)$pd['amount_paid']>=(float)$pd['plan_price']?'text-green-600':'text-red-600'; ?>"><?php echo formatMoney($pd['amount_paid']); ?></td>
                    <td class="px-4 py-3 text-center"><?php $pColors=['paid'=>'bg-green-100 text-green-800','pending'=>'bg-yellow-100 text-yellow-800','partial'=>'bg-orange-100 text-orange-800']; $label=$isOverdue?'Overdue':ucfirst($pd['payment_status']); $color=$isOverdue?'bg-red-100 text-red-800':($pColors[$pd['payment_status']]??'bg-gray-100 text-gray-600'); ?><span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $color; ?>"><?php echo $label; ?></span></td>
                    <td class="px-4 py-3 text-center"><?php $mColors=['active'=>'bg-green-100 text-green-800','expired'=>'bg-red-100 text-red-800','cancelled'=>'bg-gray-100 text-gray-600']; ?><span class="px-2 py-1 text-xs rounded-full <?php echo $mColors[$pd['membership_status']]??'bg-gray-100 text-gray-600'; ?>"><?php echo ucfirst($pd['membership_status']); ?></span></td>
                    <td class="px-4 py-3 text-sm text-gray-600"><?php echo formatDate($pd['end_date']); ?><?php if($pd['end_date']<$today): ?><div class="text-xs text-red-600 font-semibold">Expired</div><?php elseif($pd['end_date']<=date('Y-m-d',strtotime('+7 days'))): ?><div class="text-xs text-orange-600">Expiring soon</div><?php endif; ?></td>
                    <td class="px-4 py-3 text-sm text-gray-600"><?php if(!empty($pd['parent_name'])): ?><div class="font-medium"><?php echo htmlspecialchars($pd['parent_name']); ?></div><?php endif; ?><?php if(!empty($pd['parent_phone'])): ?><div class="text-xs"><?php echo htmlspecialchars($pd['parent_phone']); ?></div><?php endif; ?><?php if(empty($pd['parent_name'])&&empty($pd['parent_phone'])): ?><span class="text-gray-400">N/A</span><?php endif; ?></td>
                    <td class="px-4 py-3 text-right"><a href="student_detail.php?id=<?php echo $pd['id']; ?>" class="text-sm text-blue-600 hover:text-blue-900">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?><div class="p-8 text-center text-gray-500"><p>No active students with memberships to display.</p></div><?php endif; ?>
    </div>

    <!-- Payment Defaults -->
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="px-6 py-4 border-b border-gray-200 bg-red-50">
            <div class="flex items-center space-x-3"><span class="text-xl">&#9888;</span><div><h2 class="text-xl font-semibold text-red-800">Payment Defaults</h2><p class="text-sm text-red-600"><?php echo count($payment_defaults); ?> failed payment(s) recorded</p></div></div>
        </div>
        <?php if (!empty($payment_defaults)): ?>
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th><th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th><th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th><th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($payment_defaults as $pd): ?>
                <tr class="hover:bg-gray-50"><td class="px-6 py-4 text-sm text-gray-600"><?php echo date('M j, Y',strtotime($pd['created_at'])); ?></td><td class="px-6 py-4"><div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($pd['first_name'].' '.$pd['last_name']); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars($pd['email']??''); ?></div></td><td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($pd['plan_name']); ?></td><td class="px-6 py-4 text-sm text-right font-semibold text-red-600"><?php echo $pd['amount']?formatMoney((float)$pd['amount']):'N/A'; ?></td><td class="px-6 py-4 text-center"><span class="px-2 py-1 text-xs rounded-full <?php echo $pd['student_status']==='active'?'bg-green-100 text-green-800':'bg-gray-100 text-gray-600'; ?>"><?php echo ucfirst($pd['student_status']); ?></span></td><td class="px-6 py-4 text-right"><a href="student_detail.php?id=<?php echo $pd['student_id']; ?>" class="text-sm text-blue-600 hover:text-blue-900">View</a></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?><div class="p-8 text-center text-gray-500"><p>No payment failures recorded.</p></div><?php endif; ?>
    </div>

    <!-- Discount Code Performance -->
    <?php if (!empty($discount_performance)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Discount Code Performance</h2>
        <p class="text-sm text-gray-500 mb-4">Usage and revenue impact of all discount codes.</p>
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Code</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Type</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Uses</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total Discounted</th><th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($discount_performance as $dp): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 text-sm font-mono font-bold text-gray-800"><?php echo htmlspecialchars($dp['code']); ?></td>
                    <td class="px-4 py-2 text-sm text-gray-600"><?php echo htmlspecialchars($dp['description']?:''); ?></td>
                    <td class="px-4 py-2 text-sm text-center text-gray-600"><?php echo ucfirst($dp['discount_type']); ?> <?php echo $dp['discount_type']==='percentage'?$dp['discount_value'].'%':'$'.$dp['discount_value']; ?></td>
                    <td class="px-4 py-2 text-sm text-center"><?php echo $dp['uses_count']; ?><?php echo $dp['max_uses']?' / '.$dp['max_uses']:''; ?></td>
                    <td class="px-4 py-2 text-sm text-right font-semibold text-red-600"><?php echo formatMoney($dp['total_discounted']); ?></td>
                    <td class="px-4 py-2 text-center"><span class="px-2 py-1 text-xs rounded-full <?php echo $dp['is_active']?'bg-green-100 text-green-800':'bg-gray-100 text-gray-600'; ?>"><?php echo $dp['is_active']?'Active':'Inactive'; ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <!-- Credit Ledger Summary -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Credit Ledger Summary</h2>
        <p class="text-sm text-gray-500 mb-4">Outstanding student credit balances and recent activity.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="border rounded-lg p-5 text-center"><p class="text-xs text-gray-500 uppercase mb-1">Students with Credit</p><p class="text-3xl font-bold text-blue-600"><?php echo number_format($credit_summary['students_with_credit']); ?></p></div>
            <div class="border rounded-lg p-5 text-center"><p class="text-xs text-gray-500 uppercase mb-1">Total Outstanding Credit</p><p class="text-3xl font-bold text-green-600"><?php echo formatMoney($credit_summary['total_outstanding']); ?></p></div>
        </div>
        <?php if (!empty($recent_credits)): ?>
        <h3 class="text-sm font-semibold text-gray-700 uppercase mb-3">Recent Credit Activity</h3>
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Student</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Balance After</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($recent_credits as $cl): ?>
                <tr class="hover:bg-gray-50"><td class="px-4 py-2 text-sm text-gray-600"><?php echo date('M j, Y',strtotime($cl['created_at'])); ?></td><td class="px-4 py-2 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($cl['first_name'].' '.$cl['last_name']); ?></td><td class="px-4 py-2 text-sm text-right font-semibold <?php echo (float)$cl['amount']>=0?'text-green-700':'text-red-600'; ?>"><?php echo ((float)$cl['amount']>=0?'+':'').formatMoney($cl['amount']); ?></td><td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo formatMoney($cl['balance_after']); ?></td><td class="px-4 py-2 text-sm text-gray-600"><?php echo ucfirst(str_replace('_',' ',$cl['reference_type']??'')); ?></td><td class="px-4 py-2 text-sm text-gray-500 max-w-xs truncate"><?php echo htmlspecialchars($cl['description']??''); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?><p class="text-center text-gray-500 py-4">No credit ledger activity.</p><?php endif; ?>
    </div>

    </div><!-- END tab-compliance -->

    <!-- ============================================= -->
    <!-- INSTRUCTOR PERFORMANCE TAB -->
    <!-- ============================================= -->
    <?php include 'reports_html_part8.php'; ?>

    <!-- ============================================= -->
    <!-- MEMBERSHIP LIFECYCLE TAB -->
    <!-- ============================================= -->
    <?php include 'reports_html_part9.php'; ?>

    <!-- ============================================= -->
    <!-- BELT PROGRESSION TAB -->
    <!-- ============================================= -->
    <?php include 'reports_html_part10.php'; ?>

    <!-- ============================================= -->
    <!-- ATTENDANCE PATTERNS TAB -->
    <!-- ============================================= -->
    <?php include 'reports_html_part11.php'; ?>

    <!-- ============================================= -->
    <!-- SCHOOL COMPARISON TAB (Super Admin Only) -->
    <!-- ============================================= -->
    <?php if (is_super_admin()): ?>
    <?php include 'reports_html_part7.php'; ?>
    <?php endif; ?>

</div><!-- END container -->

<!-- Tab Switching JavaScript -->
<script>
function switchTab(tab) {
    document.querySelectorAll('.report-panel').forEach(p => p.classList.add('hidden'));
    document.getElementById('tab-' + tab).classList.remove('hidden');
    document.querySelectorAll('.report-tab').forEach(t => {
        const isActive = t.dataset.tab === tab;
        t.className = 'report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap ' +
            (isActive ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700');
    });
    history.replaceState(null, '', '#' + tab);
}
function filterPaymentRows(filter) {
    document.querySelectorAll('.payment-row').forEach(row => {
        if (filter === 'all') row.style.display = '';
        else if (filter === 'overdue') row.style.display = row.classList.contains('payment-overdue') ? '' : 'none';
        else row.style.display = row.classList.contains('payment-' + filter) ? '' : 'none';
    });
    document.querySelectorAll('.payment-tab').forEach(tab => {
        tab.className = 'payment-tab px-4 py-2 text-sm rounded-lg ' +
            (tab.dataset.filter === filter ? 'bg-blue-600 text-white font-medium' : 'bg-gray-200 text-gray-700 font-medium');
    });
}
document.addEventListener('DOMContentLoaded', function() {
    const hash = location.hash.replace('#', '');
    if (hash && document.getElementById('tab-' + hash)) switchTab(hash);
});
</script>

<?php include 'includes/footer.php'; ?>
