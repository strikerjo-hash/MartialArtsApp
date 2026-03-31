<?php
/**
 * report_export_excel.php — Excel export endpoint for report tabs.
 *
 * Receives GET parameters: ?tab=revenue|students|attendance|events|compliance|schools|instructors|membership_lifecycle|belt_progression|attendance_patterns
 * Plus date range: &range=this_month&date_from=2026-02-01&date_to=2026-02-28
 *
 * Runs the same queries as reports.php and generates a multi-sheet Excel workbook.
 */

require_once 'config.php';
requireLogin();

require_once 'includes/report_helpers.php';
require_once 'includes/export_excel.php';

$pdo = get_db();
$tab = $_GET['tab'] ?? 'revenue';

[$report_range, $date_from, $date_to, $range_label] = parseReportDateRange();

ini_set('memory_limit', '256M');

$sheets = [];

switch ($tab) {

    // ══════════════════════════════════════════════════
    // REVENUE
    // ══════════════════════════════════════════════════
    case 'revenue':
        // Monthly Trend
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(payment_date, '%b %Y') as month,
                       SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as revenue,
                       SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as refunds,
                       COUNT(*) as transactions
                FROM payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . school_where() . "
                GROUP BY DATE_FORMAT(payment_date, '%Y-%m') ORDER BY MIN(payment_date) ASC
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['month'], $r['revenue'], $r['refunds'], $r['revenue'] - $r['refunds'], $r['transactions']];
            }
            $sheets[] = ['title' => 'Monthly Trend', 'headers' => ['Month', 'Revenue', 'Refunds', 'Net', 'Transactions'], 'rows' => $rows, 'formats' => [1 => 'currency', 2 => 'currency', 3 => 'currency', 4 => 'number']];
        } catch (\PDOException $e) {}

        // By Category
        try {
            $params = [$date_from, $date_to];
            $stmt = $pdo->prepare("SELECT payment_type, SUM(amount) as total, COUNT(*) as cnt FROM payments WHERE payment_date BETWEEN ? AND ? AND amount > 0" . school_where() . " GROUP BY payment_type ORDER BY total DESC");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            $total = 0;
            foreach ($stmt->fetchAll() as $r) { $total += $r['total']; $rows[] = [ucfirst($r['payment_type']), $r['total'], 0, $r['cnt']]; }
            foreach ($rows as &$row) { $row[2] = $total > 0 ? round($row[1] / $total * 100, 1) . '%' : '0%'; }
            $sheets[] = ['title' => 'By Category', 'headers' => ['Category', 'Amount', '% of Total', 'Transactions'], 'rows' => $rows, 'formats' => [1 => 'currency']];
        } catch (\PDOException $e) {}

        // By Payment Method
        try {
            $params = [$date_from, $date_to];
            $stmt = $pdo->prepare("SELECT payment_method, SUM(amount) as total, COUNT(*) as cnt FROM payments WHERE payment_date BETWEEN ? AND ? AND amount > 0" . school_where() . " GROUP BY payment_method ORDER BY total DESC");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) { $rows[] = [ucwords(str_replace('_', ' ', $r['payment_method'])), $r['total'], $r['cnt']]; }
            $sheets[] = ['title' => 'By Payment Method', 'headers' => ['Method', 'Amount', 'Transactions'], 'rows' => $rows, 'formats' => [1 => 'currency']];
        } catch (\PDOException $e) {}

        // Top Spenders
        try {
            $stmt = $pdo->prepare("SELECT s.first_name, s.last_name, SUM(p.amount) as total_spent, COUNT(p.id) as payments FROM students s JOIN payments p ON s.id = p.student_id WHERE p.payment_date BETWEEN ? AND ? AND p.amount > 0 GROUP BY s.id ORDER BY total_spent DESC LIMIT 20");
            $stmt->execute([$date_from, $date_to]);
            $rows = [];
            foreach ($stmt->fetchAll() as $i => $r) { $rows[] = [$i + 1, $r['first_name'] . ' ' . $r['last_name'], $r['total_spent'], $r['payments']]; }
            $sheets[] = ['title' => 'Top Spenders', 'headers' => ['Rank', 'Student', 'Total Spent', 'Payments'], 'rows' => $rows, 'formats' => [2 => 'currency']];
        } catch (\PDOException $e) {}

        // Year-over-Year
        try {
            $params = [];
            $stmt = $pdo->prepare("SELECT YEAR(payment_date) as yr, SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as revenue, SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as refunds, COUNT(*) as txn FROM payments WHERE 1=1" . school_where() . " GROUP BY yr ORDER BY yr ASC");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) { $rows[] = [$r['yr'], $r['revenue'], $r['refunds'], $r['revenue'] - $r['refunds'], $r['txn']]; }
            if (count($rows) > 1) {
                $sheets[] = ['title' => 'Year-over-Year', 'headers' => ['Year', 'Revenue', 'Refunds', 'Net', 'Transactions'], 'rows' => $rows, 'formats' => [1 => 'currency', 2 => 'currency', 3 => 'currency']];
            }
        } catch (\PDOException $e) {}

        generateExcel('revenue_report_' . date('Y-m-d'), $sheets);
        break;

    // ══════════════════════════════════════════════════
    // STUDENTS
    // ══════════════════════════════════════════════════
    case 'students':
        // Retention Cohorts
        try {
            $data = $pdo->query("
                SELECT CASE
                    WHEN DATEDIFF(CURDATE(), join_date) < 90 THEN 'Under 3 months'
                    WHEN DATEDIFF(CURDATE(), join_date) < 180 THEN '3-6 months'
                    WHEN DATEDIFF(CURDATE(), join_date) < 365 THEN '6-12 months'
                    WHEN DATEDIFF(CURDATE(), join_date) < 730 THEN '1-2 years'
                    ELSE '2+ years' END as cohort, COUNT(*) as count
                FROM students WHERE status = 'active'
                GROUP BY cohort ORDER BY MIN(DATEDIFF(CURDATE(), join_date))
            ")->fetchAll();
            $total = max(1, array_sum(array_column($data, 'count')));
            $rows = [];
            foreach ($data as $d) { $rows[] = [$d['cohort'], $d['count'], round($d['count'] / $total * 100, 1) . '%']; }
            $sheets[] = ['title' => 'Retention Cohorts', 'headers' => ['Cohort', 'Students', '% of Active'], 'rows' => $rows, 'formats' => [1 => 'number']];
        } catch (\PDOException $e) {}

        // LTV by Year
        try {
            $data = $pdo->query("
                SELECT YEAR(s.join_date) as join_year, COUNT(DISTINCT s.id) as student_count,
                       ROUND(AVG(COALESCE(rev.total, 0)), 2) as avg_ltv
                FROM students s LEFT JOIN (SELECT student_id, SUM(amount) as total FROM payments WHERE amount > 0 GROUP BY student_id) rev ON s.id = rev.student_id
                WHERE s.is_parent = 0 GROUP BY join_year ORDER BY join_year DESC
            ")->fetchAll();
            $rows = [];
            foreach ($data as $d) { $rows[] = [$d['join_year'], $d['student_count'], $d['avg_ltv']]; }
            $sheets[] = ['title' => 'LTV by Join Year', 'headers' => ['Join Year', 'Students', 'Avg LTV'], 'rows' => $rows, 'formats' => [2 => 'currency']];
        } catch (\PDOException $e) {}

        // Top LTV Students
        try {
            $data = $pdo->query("
                SELECT s.first_name, s.last_name, s.join_date, s.status, COALESCE(SUM(p.amount), 0) as lifetime_revenue, COUNT(p.id) as total_payments
                FROM students s LEFT JOIN payments p ON s.id = p.student_id AND p.amount > 0
                WHERE s.is_parent = 0 GROUP BY s.id ORDER BY lifetime_revenue DESC LIMIT 20
            ")->fetchAll();
            $rows = [];
            foreach ($data as $i => $d) { $rows[] = [$i + 1, $d['first_name'] . ' ' . $d['last_name'], $d['join_date'], ucfirst($d['status']), $d['lifetime_revenue'], $d['total_payments']]; }
            $sheets[] = ['title' => 'Top LTV Students', 'headers' => ['Rank', 'Student', 'Join Date', 'Status', 'Lifetime Revenue', 'Payments'], 'rows' => $rows, 'formats' => [4 => 'currency']];
        } catch (\PDOException $e) {}

        // At-Risk Students
        try {
            $data = $pdo->query("
                SELECT s.first_name, s.last_name, s.email, DATEDIFF(m.end_date, CURDATE()) as days_left,
                       mp.name as plan_name, m.auto_renew,
                       (SELECT COUNT(*) FROM attendance a WHERE a.student_id = s.id AND a.status = 'present' AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as recent_attendance,
                       (SELECT COUNT(*) FROM renewal_log rl WHERE rl.student_id = s.id AND rl.action = 'payment_failed') as payment_failures
                FROM students s JOIN memberships m ON m.student_id = s.id AND m.status = 'active'
                JOIN membership_plans mp ON m.plan_id = mp.id
                WHERE s.status = 'active' AND (
                    (m.end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND m.auto_renew = 0)
                    OR EXISTS (SELECT 1 FROM renewal_log rl WHERE rl.student_id = s.id AND rl.action = 'payment_failed')
                ) ORDER BY days_left ASC
            ")->fetchAll();
            $rows = [];
            foreach ($data as $d) {
                $risk = 'LOW';
                if ($d['days_left'] <= 7 || $d['payment_failures'] >= 2 || $d['recent_attendance'] == 0) $risk = 'HIGH';
                elseif ($d['days_left'] <= 14 || $d['payment_failures'] >= 1) $risk = 'MEDIUM';
                $rows[] = [$d['first_name'] . ' ' . $d['last_name'], $d['email'], $d['plan_name'], $d['days_left'], $d['auto_renew'] ? 'Yes' : 'No', $d['recent_attendance'], $d['payment_failures'], $risk];
            }
            $sheets[] = ['title' => 'At-Risk Students', 'headers' => ['Student', 'Email', 'Plan', 'Days Left', 'Auto-Renew', 'Attendance (30d)', 'Pay Failures', 'Risk'], 'rows' => $rows];
        } catch (\PDOException $e) {}

        // Belt Pipeline
        try {
            $data = $pdo->query("
                SELECT belt_rank, COUNT(*) as cnt FROM students
                WHERE status = 'active' AND is_parent = 0 AND belt_rank IS NOT NULL AND belt_rank != ''
                GROUP BY belt_rank ORDER BY cnt DESC
            ")->fetchAll();
            $total = max(1, array_sum(array_column($data, 'cnt')));
            $rows = [];
            foreach ($data as $d) { $rows[] = [ucfirst($d['belt_rank']), $d['cnt'], round($d['cnt'] / $total * 100, 1) . '%']; }
            $sheets[] = ['title' => 'Belt Pipeline', 'headers' => ['Belt', 'Students', '% of Total'], 'rows' => $rows, 'formats' => [1 => 'number']];
        } catch (\PDOException $e) {}

        generateExcel('students_report_' . date('Y-m-d'), $sheets);
        break;

    // ══════════════════════════════════════════════════
    // ATTENDANCE
    // ══════════════════════════════════════════════════
    case 'attendance':
        // Class Attendance
        try {
            $stmt = $pdo->prepare("
                SELECT c.name, c.day_of_week,
                       COUNT(DISTINCT a.attendance_date) as sessions,
                       COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                       COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent,
                       COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late,
                       ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0), 1) as rate
                FROM classes c LEFT JOIN attendance a ON c.id = a.class_id AND a.attendance_date BETWEEN ? AND ?
                WHERE c.status = 'active' GROUP BY c.id ORDER BY c.name
            ");
            $stmt->execute([$date_from, $date_to]);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) { $rows[] = [$r['name'], $r['day_of_week'], $r['sessions'], $r['present'], $r['absent'], $r['late'], ($r['rate'] ?? 0) . '%']; }
            $sheets[] = ['title' => 'Class Attendance', 'headers' => ['Class', 'Day', 'Sessions', 'Present', 'Absent', 'Late', 'Rate'], 'rows' => $rows, 'formats' => [2 => 'number', 3 => 'number', 4 => 'number', 5 => 'number']];
        } catch (\PDOException $e) {}

        // Capacity Utilization
        try {
            $data = $pdo->query("
                SELECT c.name, c.day_of_week, c.max_students, COUNT(ce.id) as enrolled,
                       ROUND(COUNT(ce.id) * 100.0 / NULLIF(c.max_students, 0), 1) as pct
                FROM classes c LEFT JOIN class_enrollments ce ON c.id = ce.class_id AND ce.status = 'active' AND ce.school_id = c.school_id
                WHERE c.status = 'active' AND c.school_id = " . intval(current_school_id()) . " GROUP BY c.id ORDER BY pct DESC
            ")->fetchAll();
            $rows = [];
            foreach ($data as $d) { $rows[] = [$d['name'], $d['day_of_week'], $d['enrolled'], $d['max_students'], ($d['pct'] ?? 0) . '%']; }
            $sheets[] = ['title' => 'Capacity Utilization', 'headers' => ['Class', 'Day', 'Enrolled', 'Max', 'Utilization'], 'rows' => $rows, 'formats' => [2 => 'number', 3 => 'number']];
        } catch (\PDOException $e) {}

        // Top Students
        try {
            $params = [$date_from, $date_to];
            $stmt = $pdo->prepare("SELECT s.first_name, s.last_name, COUNT(a.id) as cnt FROM students s JOIN attendance a ON s.id = a.student_id WHERE a.status = 'present' AND a.attendance_date BETWEEN ? AND ?" . school_where('s') . " GROUP BY s.id ORDER BY cnt DESC LIMIT 30");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $i => $r) { $rows[] = [$i + 1, $r['first_name'] . ' ' . $r['last_name'], $r['cnt']]; }
            $sheets[] = ['title' => 'Top Students', 'headers' => ['Rank', 'Student', 'Attendance Count'], 'rows' => $rows, 'formats' => [2 => 'number']];
        } catch (\PDOException $e) {}

        generateExcel('attendance_report_' . date('Y-m-d'), $sheets);
        break;

    // ══════════════════════════════════════════════════
    // EVENTS
    // ══════════════════════════════════════════════════
    case 'events':
        // Revenue by Type
        try {
            $stmt = $pdo->prepare("SELECT e.event_type, COUNT(DISTINCT e.id) as events, COUNT(er.id) as regs, COALESCE(SUM(er.amount_paid), 0) as revenue FROM events e LEFT JOIN event_registrations er ON e.id = er.event_id WHERE e.event_date BETWEEN ? AND ? GROUP BY e.event_type ORDER BY revenue DESC");
            $stmt->execute([$date_from, $date_to]);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) { $rows[] = [ucwords(str_replace('_', ' ', $r['event_type'])), $r['events'], $r['regs'], $r['revenue']]; }
            $sheets[] = ['title' => 'Revenue by Type', 'headers' => ['Type', 'Events', 'Registrations', 'Revenue'], 'rows' => $rows, 'formats' => [3 => 'currency']];
        } catch (\PDOException $e) {}

        // ROI Detail
        try {
            $stmt = $pdo->prepare("
                SELECT e.name, e.event_type, e.event_date, e.registration_fee, e.max_participants,
                       COUNT(er.id) as regs, COUNT(CASE WHEN er.attendance_status = 'attended' THEN 1 END) as attended,
                       COUNT(CASE WHEN er.attendance_status = 'no_show' THEN 1 END) as no_shows,
                       COALESCE(SUM(er.amount_paid), 0) as revenue,
                       ROUND(COUNT(er.id) * 100.0 / NULLIF(e.max_participants, 0), 1) as fill_rate
                FROM events e LEFT JOIN event_registrations er ON e.id = er.event_id
                WHERE e.event_date BETWEEN ? AND ? GROUP BY e.id ORDER BY e.event_date DESC
            ");
            $stmt->execute([$date_from, $date_to]);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) { $rows[] = [$r['name'], ucwords(str_replace('_', ' ', $r['event_type'])), $r['event_date'], $r['registration_fee'], $r['regs'], $r['attended'], $r['no_shows'], $r['revenue'], ($r['fill_rate'] ?? 0) . '%']; }
            $sheets[] = ['title' => 'Event ROI Detail', 'headers' => ['Event', 'Type', 'Date', 'Fee', 'Registered', 'Attended', 'No-Shows', 'Revenue', 'Fill Rate'], 'rows' => $rows, 'formats' => [3 => 'currency', 7 => 'currency']];
        } catch (\PDOException $e) {}

        generateExcel('events_report_' . date('Y-m-d'), $sheets);
        break;

    // ══════════════════════════════════════════════════
    // COMPLIANCE
    // ══════════════════════════════════════════════════
    case 'compliance':
        // No Membership
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT s.first_name, s.last_name, s.email, COUNT(ce.id) as enrolled
                FROM students s JOIN class_enrollments ce ON ce.student_id = s.id AND ce.status = 'active' AND ce.school_id = s.school_id
                LEFT JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
                WHERE s.status = 'active' AND m.id IS NULL" . school_where('s') . "
                GROUP BY s.id ORDER BY enrolled DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) { $rows[] = [$r['first_name'] . ' ' . $r['last_name'], $r['email'], $r['enrolled']]; }
            $sheets[] = ['title' => 'No Membership', 'headers' => ['Student', 'Email', 'Enrolled Classes'], 'rows' => $rows, 'formats' => [2 => 'number']];
        } catch (\PDOException $e) {}

        // Over Limit
        try {
            $data = $pdo->query("
                SELECT s.first_name, s.last_name, mp.name as plan, mp.classes_per_week as lim, COUNT(ce.id) as enrolled
                FROM students s JOIN class_enrollments ce ON ce.student_id = s.id AND ce.status = 'active' AND ce.school_id = s.school_id
                JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
                JOIN membership_plans mp ON m.plan_id = mp.id
                WHERE s.status = 'active' AND s.school_id = " . intval(current_school_id()) . " AND mp.classes_per_week < 99
                GROUP BY s.id, mp.name, mp.classes_per_week HAVING COUNT(ce.id) > mp.classes_per_week
                ORDER BY (COUNT(ce.id) - mp.classes_per_week) DESC
            ")->fetchAll();
            $rows = [];
            foreach ($data as $d) { $rows[] = [$d['first_name'] . ' ' . $d['last_name'], $d['plan'], $d['lim'], $d['enrolled'], $d['enrolled'] - $d['lim']]; }
            $sheets[] = ['title' => 'Over Limit', 'headers' => ['Student', 'Plan', 'Limit', 'Enrolled', 'Over By'], 'rows' => $rows];
        } catch (\PDOException $e) {}

        // Payment Defaults
        try {
            $data = $pdo->query("
                SELECT s.first_name, s.last_name, s.email, mp.name as plan, m.status as mem_status, rl.created_at
                FROM renewal_log rl JOIN students s ON rl.student_id = s.id
                JOIN memberships m ON rl.membership_id = m.id JOIN membership_plans mp ON m.plan_id = mp.id
                WHERE rl.action = 'payment_failed' ORDER BY rl.created_at DESC LIMIT 50
            ")->fetchAll();
            $rows = [];
            foreach ($data as $d) { $rows[] = [$d['first_name'] . ' ' . $d['last_name'], $d['email'], $d['plan'], ucfirst($d['mem_status']), $d['created_at']]; }
            $sheets[] = ['title' => 'Payment Defaults', 'headers' => ['Student', 'Email', 'Plan', 'Membership Status', 'Failed Date'], 'rows' => $rows];
        } catch (\PDOException $e) {}

        generateExcel('compliance_report_' . date('Y-m-d'), $sheets);
        break;

    // ══════════════════════════════════════════════════
    // SCHOOLS (School Comparison — super admin only)
    // ══════════════════════════════════════════════════
    case 'schools':
        if (!is_super_admin()) { accessDenied('School Comparison reports are only available to Super Admins.', 'reports.php'); }

        // Revenue by School
        try {
            $stmt = $pdo->prepare("
                SELECT sc.name as school_name,
                       COALESCE(SUM(p.amount), 0) as revenue,
                       COUNT(p.id) as payments,
                       ROUND(COALESCE(SUM(p.amount), 0) / NULLIF(COUNT(p.id), 0), 2) as avg_per_payment
                FROM schools sc
                LEFT JOIN payments p ON p.school_id = sc.id AND p.amount > 0 AND p.payment_date BETWEEN ? AND ?
                WHERE sc.status = 'active'
                GROUP BY sc.id ORDER BY revenue DESC
            ");
            $stmt->execute([$date_from, $date_to]);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['school_name'], $r['revenue'], $r['payments'], $r['avg_per_payment'] ?? 0];
            }
            $sheets[] = ['title' => 'Revenue by School', 'headers' => ['School', 'Revenue', 'Payments', 'Avg per Payment'], 'rows' => $rows, 'formats' => [1 => 'currency', 3 => 'currency']];
        } catch (\PDOException $e) {}

        // Students by School
        try {
            $stmt = $pdo->query("
                SELECT sc.name as school_name,
                       COUNT(DISTINCT s.id) as total_students,
                       COUNT(DISTINCT CASE WHEN s.status = 'active' THEN s.id END) as active_students,
                       COUNT(DISTINCT CASE WHEN m.id IS NOT NULL THEN s.id END) as active_memberships,
                       ROUND(COUNT(DISTINCT CASE WHEN m.id IS NOT NULL THEN s.id END) * 100.0 / NULLIF(COUNT(DISTINCT CASE WHEN s.status = 'active' THEN s.id END), 0), 1) as membership_rate
                FROM schools sc
                LEFT JOIN students s ON s.school_id = sc.id AND s.is_parent = 0
                LEFT JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
                WHERE sc.status = 'active'
                GROUP BY sc.id ORDER BY sc.name
            ");
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['school_name'], $r['total_students'], $r['active_students'], $r['active_memberships'], ($r['membership_rate'] ?? 0)];
            }
            $sheets[] = ['title' => 'Students by School', 'headers' => ['School', 'Total Students', 'Active Students', 'Active Memberships', 'Membership Rate %'], 'rows' => $rows, 'formats' => [4 => 'percent']];
        } catch (\PDOException $e) {}

        // Attendance by School
        try {
            $stmt = $pdo->prepare("
                SELECT sc.name as school_name,
                       COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                       COUNT(a.id) as total_records,
                       ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0), 1) as rate
                FROM schools sc
                LEFT JOIN attendance a ON a.school_id = sc.id AND a.attendance_date BETWEEN ? AND ?
                WHERE sc.status = 'active'
                GROUP BY sc.id ORDER BY sc.name
            ");
            $stmt->execute([$date_from, $date_to]);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['school_name'], $r['present'], $r['total_records'], ($r['rate'] ?? 0)];
            }
            $sheets[] = ['title' => 'Attendance by School', 'headers' => ['School', 'Present', 'Total Records', 'Rate %'], 'rows' => $rows, 'formats' => [3 => 'percent']];
        } catch (\PDOException $e) {}

        generateExcel('school_comparison_report_' . date('Y-m-d'), $sheets);
        break;

    // ══════════════════════════════════════════════════
    // INSTRUCTORS (Instructor Performance)
    // ══════════════════════════════════════════════════
    case 'instructors':
        // Summary
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT u.full_name as instructor,
                       COUNT(DISTINCT c.id) as classes,
                       COUNT(DISTINCT ce.student_id) as students,
                       COUNT(DISTINCT a.attendance_date) as sessions,
                       ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0), 1) as attendance_rate
                FROM users u
                LEFT JOIN classes c ON c.instructor_id = u.id AND c.status = 'active'
                LEFT JOIN class_enrollments ce ON ce.class_id = c.id AND ce.status = 'active' AND ce.school_id = c.school_id
                LEFT JOIN attendance a ON a.class_id = c.id AND a.attendance_date BETWEEN ? AND ?
                WHERE u.role = 'instructor'" . school_where('u') . "
                GROUP BY u.id ORDER BY u.full_name
            ");
            $params = [$date_from, $date_to];
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['instructor'], $r['classes'], $r['students'], $r['sessions'], ($r['attendance_rate'] ?? 0)];
            }
            $sheets[] = ['title' => 'Summary', 'headers' => ['Instructor', 'Classes', 'Students', 'Sessions', 'Attendance Rate %'], 'rows' => $rows, 'formats' => [4 => 'percent']];
        } catch (\PDOException $e) {}

        // Promotions
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT u.full_name as instructor,
                       COUNT(sb.id) as promotions
                FROM users u
                LEFT JOIN student_belts sb ON sb.instructor_id = u.id
                WHERE u.role = 'instructor'" . school_where('u') . "
                GROUP BY u.id ORDER BY promotions DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['instructor'], $r['promotions']];
            }
            $sheets[] = ['title' => 'Promotions', 'headers' => ['Instructor', 'Promotions Awarded'], 'rows' => $rows, 'formats' => [1 => 'number']];
        } catch (\PDOException $e) {}

        // Capacity
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT u.full_name as instructor,
                       COALESCE(SUM(c.max_students), 0) as total_max,
                       COUNT(ce.id) as total_enrolled,
                       ROUND(COUNT(ce.id) * 100.0 / NULLIF(SUM(c.max_students), 0), 1) as utilization
                FROM users u
                LEFT JOIN classes c ON c.instructor_id = u.id AND c.status = 'active'
                LEFT JOIN class_enrollments ce ON ce.class_id = c.id AND ce.status = 'active' AND ce.school_id = c.school_id
                WHERE u.role = 'instructor'" . school_where('u') . "
                GROUP BY u.id ORDER BY u.full_name
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['instructor'], $r['total_max'], $r['total_enrolled'], ($r['utilization'] ?? 0)];
            }
            $sheets[] = ['title' => 'Capacity', 'headers' => ['Instructor', 'Total Max', 'Total Enrolled', 'Utilization %'], 'rows' => $rows, 'formats' => [3 => 'percent']];
        } catch (\PDOException $e) {}

        generateExcel('instructor_performance_' . date('Y-m-d'), $sheets);
        break;

    // ══════════════════════════════════════════════════
    // MEMBERSHIP LIFECYCLE
    // ══════════════════════════════════════════════════
    case 'membership_lifecycle':
        // New Memberships Trend
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(m.start_date, '%b %Y') as month,
                       COUNT(*) as new_memberships
                FROM memberships m
                WHERE m.start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . school_where('m') . "
                GROUP BY DATE_FORMAT(m.start_date, '%Y-%m')
                ORDER BY MIN(m.start_date) ASC
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['month'], $r['new_memberships']];
            }
            $sheets[] = ['title' => 'New Memberships Trend', 'headers' => ['Month', 'New Memberships'], 'rows' => $rows, 'formats' => [1 => 'number']];
        } catch (\PDOException $e) {}

        // Plan Popularity
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT mp.name as plan,
                       COUNT(m.id) as active_members,
                       ROUND(SUM(mp.price / mp.duration_months), 2) as monthly_revenue_est
                FROM membership_plans mp
                LEFT JOIN memberships m ON m.plan_id = mp.id AND m.status = 'active' AND m.end_date >= CURDATE()
                WHERE mp.status = 'active'" . school_where('mp') . "
                GROUP BY mp.id ORDER BY active_members DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['plan'], $r['active_members'], $r['monthly_revenue_est']];
            }
            $sheets[] = ['title' => 'Plan Popularity', 'headers' => ['Plan', 'Active Members', 'Monthly Revenue Est'], 'rows' => $rows, 'formats' => [2 => 'currency']];
        } catch (\PDOException $e) {}

        // Key Metrics
        try {
            $params = [];
            // MRR
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(mp.price / mp.duration_months), 0) as mrr
                FROM memberships m
                JOIN membership_plans mp ON m.plan_id = mp.id
                WHERE m.status = 'active' AND m.end_date >= CURDATE()" . school_where('m') . "
            ");
            school_param($params);
            $stmt->execute($params);
            $mrr = round($stmt->fetchColumn(), 2);

            // Renewal Rate
            $params = [];
            $stmt = $pdo->prepare("
                SELECT COUNT(CASE WHEN m2.id IS NOT NULL THEN 1 END) as renewed,
                       COUNT(*) as total_expired
                FROM memberships m1
                LEFT JOIN memberships m2 ON m2.student_id = m1.student_id AND m2.start_date > m1.end_date
                WHERE m1.status = 'expired' AND m1.end_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . school_where('m1') . "
            ");
            school_param($params);
            $stmt->execute($params);
            $rr = $stmt->fetch();
            $renewal_rate = ($rr['total_expired'] > 0) ? round($rr['renewed'] / $rr['total_expired'] * 100, 1) : 0;

            // Cancel Rate
            $params = [];
            $stmt = $pdo->prepare("
                SELECT COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
                       COUNT(*) as total
                FROM memberships
                WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . school_where() . "
            ");
            school_param($params);
            $stmt->execute($params);
            $cr = $stmt->fetch();
            $cancel_rate = ($cr['total'] > 0) ? round($cr['cancelled'] / $cr['total'] * 100, 1) : 0;

            // Avg Duration
            $params = [];
            $stmt = $pdo->prepare("
                SELECT ROUND(AVG(DATEDIFF(end_date, start_date)), 0) as avg_days
                FROM memberships
                WHERE status IN ('expired', 'cancelled')" . school_where() . "
            ");
            school_param($params);
            $stmt->execute($params);
            $avg_duration = $stmt->fetchColumn() ?: 0;

            // Collection Rate
            $params = [];
            $stmt = $pdo->prepare("
                SELECT ROUND(SUM(m.amount_paid) * 100.0 / NULLIF(SUM(mp.price), 0), 1) as collection_rate
                FROM memberships m
                JOIN membership_plans mp ON m.plan_id = mp.id
                WHERE m.status = 'active'" . school_where('m') . "
            ");
            school_param($params);
            $stmt->execute($params);
            $collection_rate = $stmt->fetchColumn() ?: 0;

            $rows = [
                ['Monthly Recurring Revenue (MRR)', '$' . number_format($mrr, 2)],
                ['Renewal Rate', $renewal_rate . '%'],
                ['Cancellation Rate', $cancel_rate . '%'],
                ['Avg Membership Duration (days)', $avg_duration],
                ['Collection Rate', $collection_rate . '%'],
            ];
            $sheets[] = ['title' => 'Key Metrics', 'headers' => ['Metric', 'Value'], 'rows' => $rows];
        } catch (\PDOException $e) {}

        generateExcel('membership_lifecycle_' . date('Y-m-d'), $sheets);
        break;

    // ══════════════════════════════════════════════════
    // BELT PROGRESSION
    // ══════════════════════════════════════════════════
    case 'belt_progression':
        // Promotion Time
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT b.name as belt_rank,
                       ROUND(AVG(DATEDIFF(sb.awarded_date, COALESCE(prev.awarded_date, s.join_date))), 0) as avg_days,
                       ROUND(AVG(DATEDIFF(sb.awarded_date, COALESCE(prev.awarded_date, s.join_date))) / 30.0, 1) as avg_months
                FROM student_belts sb
                JOIN belts b ON sb.belt_id = b.id
                JOIN students s ON sb.student_id = s.id
                LEFT JOIN student_belts prev ON prev.student_id = sb.student_id
                    AND prev.style_id = sb.style_id
                    AND prev.awarded_date < sb.awarded_date
                    AND prev.id = (
                        SELECT MAX(p2.id) FROM student_belts p2
                        WHERE p2.student_id = sb.student_id
                          AND p2.style_id = sb.style_id
                          AND p2.awarded_date < sb.awarded_date
                    )
                WHERE 1=1" . school_where('sb') . "
                GROUP BY b.id, b.name, b.rank_order
                ORDER BY b.rank_order ASC
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['belt_rank'], $r['avg_days'], $r['avg_months']];
            }
            $sheets[] = ['title' => 'Promotion Time', 'headers' => ['Belt Rank', 'Avg Days', 'Avg Months'], 'rows' => $rows, 'formats' => [1 => 'number', 2 => 'number']];
        } catch (\PDOException $e) {}

        // Cohort Velocity
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT YEAR(s.join_date) as join_year,
                       COUNT(DISTINCT s.id) as students,
                       COUNT(sb.id) as promotions,
                       ROUND(COUNT(sb.id) * 1.0 / NULLIF(COUNT(DISTINCT s.id), 0), 2) as avg_promotions
                FROM students s
                LEFT JOIN student_belts sb ON sb.student_id = s.id
                WHERE s.is_parent = 0" . school_where('s') . "
                GROUP BY join_year ORDER BY join_year DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['join_year'], $r['students'], $r['promotions'], $r['avg_promotions']];
            }
            $sheets[] = ['title' => 'Cohort Velocity', 'headers' => ['Join Year', 'Students', 'Promotions', 'Avg Promotions per Student'], 'rows' => $rows, 'formats' => [3 => 'number']];
        } catch (\PDOException $e) {}

        // Instructor Promotions
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT u.full_name as instructor,
                       COUNT(sb.id) as promotions
                FROM users u
                JOIN student_belts sb ON sb.instructor_id = u.id
                WHERE u.role = 'instructor'" . school_where('u') . "
                GROUP BY u.id ORDER BY promotions DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['instructor'], $r['promotions']];
            }
            $sheets[] = ['title' => 'Instructor Promotions', 'headers' => ['Instructor', 'Promotions'], 'rows' => $rows, 'formats' => [1 => 'number']];
        } catch (\PDOException $e) {}

        // Belt Test Pass Rates
        try {
            $params = [$date_from, $date_to];
            $stmt = $pdo->prepare("
                SELECT e.name as event_name, e.event_date,
                       COUNT(er.id) as tested,
                       COUNT(CASE WHEN er.result = 'pass' THEN 1 END) as passed,
                       ROUND(COUNT(CASE WHEN er.result = 'pass' THEN 1 END) * 100.0 / NULLIF(COUNT(er.id), 0), 1) as pass_rate
                FROM events e
                JOIN event_registrations er ON er.event_id = e.id AND er.attendance_status = 'attended'
                WHERE e.event_type = 'belt_test' AND e.event_date BETWEEN ? AND ?" . school_where('e') . "
                GROUP BY e.id ORDER BY e.event_date DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['event_name'], $r['event_date'], $r['tested'], $r['passed'], ($r['pass_rate'] ?? 0)];
            }
            $sheets[] = ['title' => 'Belt Test Pass Rates', 'headers' => ['Event', 'Date', 'Tested', 'Passed', 'Pass Rate %'], 'rows' => $rows, 'formats' => [4 => 'percent']];
        } catch (\PDOException $e) {}

        generateExcel('belt_progression_' . date('Y-m-d'), $sheets);
        break;

    // ══════════════════════════════════════════════════
    // ATTENDANCE PATTERNS
    // ══════════════════════════════════════════════════
    case 'attendance_patterns':
        // Heatmap
        try {
            $params = [$date_from, $date_to];
            $stmt = $pdo->prepare("
                SELECT c.day_of_week as day_name,
                       HOUR(c.start_time) as hour,
                       COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                       COUNT(a.id) as total,
                       ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0), 1) as rate
                FROM attendance a
                JOIN classes c ON a.class_id = c.id
                WHERE a.attendance_date BETWEEN ? AND ?" . school_where('a') . "
                GROUP BY c.day_of_week, hour
                ORDER BY FIELD(c.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), hour
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['day_name'], $r['hour'], $r['present'], $r['total'], ($r['rate'] ?? 0)];
            }
            $sheets[] = ['title' => 'Heatmap', 'headers' => ['Day', 'Hour', 'Present', 'Total', 'Rate %'], 'rows' => $rows, 'formats' => [4 => 'percent']];
        } catch (\PDOException $e) {}

        // Consistency
        try {
            $params = [$date_from, $date_to];
            $weeks = max(1, round((strtotime($date_to) - strtotime($date_from)) / (7 * 86400), 1));
            $stmt = $pdo->prepare("
                SELECT CONCAT(s.first_name, ' ', s.last_name) as student,
                       COUNT(CASE WHEN a.status = 'present' THEN 1 END) as total_present
                FROM students s
                JOIN attendance a ON a.student_id = s.id AND a.attendance_date BETWEEN ? AND ?
                WHERE s.status = 'active' AND s.is_parent = 0" . school_where('s') . "
                GROUP BY s.id ORDER BY total_present DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $sessions_per_week = round($r['total_present'] / $weeks, 1);
                $rows[] = [$r['student'], $sessions_per_week, $r['total_present']];
            }
            $sheets[] = ['title' => 'Consistency', 'headers' => ['Student', 'Sessions/Week', 'Total Present'], 'rows' => $rows, 'formats' => [1 => 'number', 2 => 'number']];
        } catch (\PDOException $e) {}

        // Dropout Risk
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT CONCAT(s.first_name, ' ', s.last_name) as student,
                       COUNT(CASE WHEN a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND a.status = 'present' THEN 1 END) as last_30d,
                       COUNT(CASE WHEN a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND a.attendance_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND a.status = 'present' THEN 1 END) as prev_30d,
                       ROUND(
                           (COUNT(CASE WHEN a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND a.status = 'present' THEN 1 END)
                            - COUNT(CASE WHEN a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND a.attendance_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND a.status = 'present' THEN 1 END))
                           * 100.0
                           / NULLIF(COUNT(CASE WHEN a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND a.attendance_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND a.status = 'present' THEN 1 END), 0)
                       , 1) as change_pct
                FROM students s
                JOIN attendance a ON a.student_id = s.id AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                WHERE s.status = 'active' AND s.is_parent = 0" . school_where('s') . "
                GROUP BY s.id
                HAVING prev_30d > 0
                ORDER BY change_pct ASC
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['student'], $r['last_30d'], $r['prev_30d'], ($r['change_pct'] ?? 0)];
            }
            $sheets[] = ['title' => 'Dropout Risk', 'headers' => ['Student', 'Last 30d', 'Previous 30d', 'Change %'], 'rows' => $rows, 'formats' => [3 => 'percent']];
        } catch (\PDOException $e) {}

        // Seasonal Patterns
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(a.attendance_date, '%b') as month_name,
                       ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0), 1) as attendance_rate
                FROM attendance a
                WHERE a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . school_where('a') . "
                GROUP BY DATE_FORMAT(a.attendance_date, '%Y-%m')
                ORDER BY MIN(a.attendance_date) ASC
            ");
            school_param($params);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [$r['month_name'], ($r['attendance_rate'] ?? 0)];
            }
            $sheets[] = ['title' => 'Seasonal Patterns', 'headers' => ['Month', 'Attendance Rate %'], 'rows' => $rows, 'formats' => [1 => 'percent']];
        } catch (\PDOException $e) {}

        generateExcel('attendance_patterns_' . date('Y-m-d'), $sheets);
        break;

    default:
        header('Location: reports.php');
        exit;
}
