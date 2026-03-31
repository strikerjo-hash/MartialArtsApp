<?php
/**
 * report_export_pdf.php — PDF export endpoint for report tabs.
 *
 * Receives GET parameters: ?tab=revenue|students|attendance|events|compliance|schools|instructors|membership_lifecycle|belt_progression|attendance_patterns
 * Plus date range: &range=this_month&date_from=2026-02-01&date_to=2026-02-28
 *
 * Runs the same queries as reports.php, builds print-friendly HTML tables,
 * and streams a PDF download via DomPDF.
 */

require_once 'config.php';
requireLogin();

require_once 'includes/report_helpers.php';
require_once 'includes/export_pdf.php';

$pdo = get_db();
$tab = $_GET['tab'] ?? 'revenue';

// Parse date range (shared logic)
[$report_range, $date_from, $date_to, $range_label] = parseReportDateRange();

// Get school name for header
$schoolName = 'All Schools';
try {
    $school = get_current_school();
    $schoolName = $school['name'] ?? 'All Schools';
} catch (\Throwable $e) {}

// Increase memory limit for large reports
ini_set('memory_limit', '256M');

// ─────────────────────────────────────────────────────
// Build PDF content for the requested tab
// ─────────────────────────────────────────────────────

$bodyHtml = '';
$title    = 'Report';

switch ($tab) {

    // ══════════════════════════════════════════════════
    // REVENUE TAB
    // ══════════════════════════════════════════════════
    case 'revenue':
        $title = 'Revenue Report';

        // --- Projected vs Actual ---
        $projected_monthly = 0;
        try {
            $projected_monthly = (float) $pdo->query("
                SELECT COALESCE(SUM(ROUND(mp.price / GREATEST(mp.duration_months, 1), 2)), 0) as projected
                FROM memberships m JOIN membership_plans mp ON m.plan_id = mp.id
                WHERE m.status = 'active' AND m.end_date >= CURDATE()
            ")->fetch()['projected'];
        } catch (\PDOException $e) {}
        $projected_yearly = $projected_monthly * 12;

        try {
            $params = [$date_from, $date_to];
            $rev_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_date BETWEEN ? AND ? AND status = 'completed'" . school_where());
            school_param($params);
            $rev_stmt->execute($params);
            $actual_revenue = (float) $rev_stmt->fetch()['total'];
        } catch (\PDOException $e) {
            $params = [$date_from, $date_to];
            $rev_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_date BETWEEN ? AND ?" . school_where());
            school_param($params);
            $rev_stmt->execute($params);
            $actual_revenue = (float) $rev_stmt->fetch()['total'];
        }

        $params = [];
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())" . school_where());
        school_param($params);
        $stmt->execute($params);
        $monthly_revenue = (float) $stmt->fetch()['total'];

        $params = [];
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE())" . school_where());
        school_param($params);
        $stmt->execute($params);
        $yearly_revenue = (float) $stmt->fetch()['total'];

        $monthly_variance = $projected_monthly > 0 ? round(($monthly_revenue - $projected_monthly) / $projected_monthly * 100, 1) : 0;
        $yearly_variance  = $projected_yearly > 0  ? round(($yearly_revenue  - $projected_yearly)  / $projected_yearly  * 100, 1) : 0;

        $bodyHtml .= '<h2>Projected vs Actual Income</h2>';
        $bodyHtml .= '<table>';
        $bodyHtml .= '<tr><th>Period</th><th class="text-right">Projected</th><th class="text-right">Actual</th><th class="text-right">Variance</th></tr>';
        $bodyHtml .= '<tr><td>Monthly</td><td class="text-right">' . formatMoney($projected_monthly) . '</td>';
        $bodyHtml .= '<td class="text-right">' . formatMoney($monthly_revenue) . '</td>';
        $bodyHtml .= '<td class="text-right ' . ($monthly_variance >= 0 ? 'text-green' : 'text-red') . '">' . ($monthly_variance >= 0 ? '+' : '') . $monthly_variance . '%</td></tr>';
        $bodyHtml .= '<tr><td>Yearly</td><td class="text-right">' . formatMoney($projected_yearly) . '</td>';
        $bodyHtml .= '<td class="text-right">' . formatMoney($yearly_revenue) . '</td>';
        $bodyHtml .= '<td class="text-right ' . ($yearly_variance >= 0 ? 'text-green' : 'text-red') . '">' . ($yearly_variance >= 0 ? '+' : '') . $yearly_variance . '%</td></tr>';
        $bodyHtml .= '</table>';

        // --- Monthly Revenue Trend ---
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
                       DATE_FORMAT(payment_date, '%b %Y') as label,
                       SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as revenue,
                       SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as refunds,
                       COUNT(*) as txn_count
                FROM payments
                WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . school_where() . "
                GROUP BY month ORDER BY month ASC
            ");
            school_param($params);
            $stmt->execute($params);
            $monthly_data = $stmt->fetchAll();
        } catch (\PDOException $e) { $monthly_data = []; }

        if (!empty($monthly_data)) {
            $bodyHtml .= '<h2>Monthly Revenue Trend (Last 12 Months)</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Month</th><th class="text-right">Revenue</th><th class="text-right">Refunds</th><th class="text-right">Net</th><th class="text-right">Transactions</th></tr>';
            foreach ($monthly_data as $m) {
                $net = $m['revenue'] - $m['refunds'];
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($m['label']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . formatMoney($m['revenue']) . '</td>';
                $bodyHtml .= '<td class="text-right text-red">' . ($m['refunds'] > 0 ? '-' . formatMoney($m['refunds']) : '–') . '</td>';
                $bodyHtml .= '<td class="text-right font-bold">' . formatMoney($net) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $m['txn_count'] . '</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Revenue by Category ---
        $revenue_by_category = [];
        try {
            $params = [$date_from, $date_to];
            $stmt = $pdo->prepare("
                SELECT payment_type, SUM(amount) as total, COUNT(*) as cnt
                FROM payments WHERE payment_date BETWEEN ? AND ? AND amount > 0" . school_where() . "
                GROUP BY payment_type ORDER BY total DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $revenue_by_category = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        $total_cat = array_sum(array_column($revenue_by_category, 'total'));
        if (!empty($revenue_by_category)) {
            $bodyHtml .= '<h2>Revenue by Category</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Category</th><th class="text-right">Amount</th><th class="text-right">% of Total</th><th class="text-right">Transactions</th></tr>';
            foreach ($revenue_by_category as $rc) {
                $pct = $total_cat > 0 ? round($rc['total'] / $total_cat * 100, 1) : 0;
                $bodyHtml .= '<tr><td>' . ucfirst($rc['payment_type']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . formatMoney($rc['total']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $pct . '%</td>';
                $bodyHtml .= '<td class="text-right">' . $rc['cnt'] . '</td></tr>';
            }
            $bodyHtml .= '<tr class="font-bold"><td>Total</td><td class="text-right">' . formatMoney($total_cat) . '</td><td class="text-right">100%</td><td></td></tr>';
            $bodyHtml .= '</table>';
        }

        // --- Revenue by Payment Method ---
        $revenue_by_method = [];
        try {
            $params = [$date_from, $date_to];
            $stmt = $pdo->prepare("
                SELECT payment_method, SUM(amount) as total, COUNT(*) as cnt
                FROM payments WHERE payment_date BETWEEN ? AND ? AND amount > 0" . school_where() . "
                GROUP BY payment_method ORDER BY total DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $revenue_by_method = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        $total_method = array_sum(array_column($revenue_by_method, 'total'));
        if (!empty($revenue_by_method)) {
            $bodyHtml .= '<h2>Revenue by Payment Method</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Method</th><th class="text-right">Amount</th><th class="text-right">% of Total</th><th class="text-right">Transactions</th></tr>';
            foreach ($revenue_by_method as $rm) {
                $pct = $total_method > 0 ? round($rm['total'] / $total_method * 100, 1) : 0;
                $label = ucwords(str_replace('_', ' ', $rm['payment_method']));
                $bodyHtml .= '<tr><td>' . $label . '</td>';
                $bodyHtml .= '<td class="text-right">' . formatMoney($rm['total']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $pct . '%</td>';
                $bodyHtml .= '<td class="text-right">' . $rm['cnt'] . '</td></tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Top 10 Spenders ---
        $top_spenders = [];
        try {
            $stmt = $pdo->prepare("
                SELECT s.first_name, s.last_name, SUM(p.amount) as total_spent, COUNT(p.id) as payment_count
                FROM students s JOIN payments p ON s.id = p.student_id
                WHERE p.payment_date BETWEEN ? AND ? AND p.amount > 0
                GROUP BY s.id ORDER BY total_spent DESC LIMIT 10
            ");
            $stmt->execute([$date_from, $date_to]);
            $top_spenders = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($top_spenders)) {
            $bodyHtml .= '<h2>Top 10 Spenders</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>#</th><th>Student</th><th class="text-right">Total Spent</th><th class="text-right">Payments</th></tr>';
            foreach ($top_spenders as $i => $ts) {
                $bodyHtml .= '<tr><td>' . ($i + 1) . '</td>';
                $bodyHtml .= '<td>' . htmlspecialchars($ts['first_name'] . ' ' . $ts['last_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . formatMoney($ts['total_spent']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ts['payment_count'] . '</td></tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Best/Worst Months ---
        $best_worst = [];
        try {
            $best_worst = $pdo->query("
                SELECT DATE_FORMAT(payment_date, '%M %Y') as label,
                       SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as revenue,
                       COUNT(*) as txn_count
                FROM payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH) AND amount > 0
                GROUP BY DATE_FORMAT(payment_date, '%Y-%m') ORDER BY revenue DESC
            ")->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($best_worst)) {
            $bodyHtml .= '<h2>Best &amp; Worst Performing Months (Last 24 Months)</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Rank</th><th>Month</th><th class="text-right">Revenue</th><th class="text-right">Transactions</th></tr>';
            foreach ($best_worst as $i => $bw) {
                $rankColor = $i < 3 ? 'text-green' : ($i >= count($best_worst) - 3 ? 'text-red' : '');
                $bodyHtml .= '<tr><td class="' . $rankColor . ' font-bold">#' . ($i + 1) . '</td>';
                $bodyHtml .= '<td>' . htmlspecialchars($bw['label']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . formatMoney($bw['revenue']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $bw['txn_count'] . '</td></tr>';
            }
            $bodyHtml .= '</table>';
        }
        break;

    // ══════════════════════════════════════════════════
    // STUDENTS TAB
    // ══════════════════════════════════════════════════
    case 'students':
        $title = 'Students Report';

        // --- Retention Cohorts ---
        $retention = [];
        try {
            $retention = $pdo->query("
                SELECT CASE
                    WHEN DATEDIFF(CURDATE(), join_date) < 90 THEN 'Under 3 months'
                    WHEN DATEDIFF(CURDATE(), join_date) < 180 THEN '3-6 months'
                    WHEN DATEDIFF(CURDATE(), join_date) < 365 THEN '6-12 months'
                    WHEN DATEDIFF(CURDATE(), join_date) < 730 THEN '1-2 years'
                    ELSE '2+ years' END as cohort,
                    COUNT(*) as count
                FROM students WHERE status = 'active'
                GROUP BY cohort ORDER BY MIN(DATEDIFF(CURDATE(), join_date))
            ")->fetchAll();
        } catch (\PDOException $e) {}

        $totalActive = max(1, array_sum(array_column($retention, 'count')));
        if (!empty($retention)) {
            $bodyHtml .= '<h2>Student Retention Cohorts</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Cohort</th><th class="text-right">Students</th><th class="text-right">% of Active</th></tr>';
            foreach ($retention as $r) {
                $pct = round($r['count'] / $totalActive * 100, 1);
                $bodyHtml .= '<tr><td>' . $r['cohort'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $r['count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $pct . '%</td></tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Lifetime Value by Join Year ---
        $ltv = [];
        try {
            $ltv = $pdo->query("
                SELECT YEAR(s.join_date) as join_year, COUNT(DISTINCT s.id) as student_count,
                       ROUND(AVG(COALESCE(rev.total, 0)), 2) as avg_ltv
                FROM students s
                LEFT JOIN (SELECT student_id, SUM(amount) as total FROM payments WHERE amount > 0 GROUP BY student_id) rev ON s.id = rev.student_id
                WHERE s.is_parent = 0
                GROUP BY join_year ORDER BY join_year DESC
            ")->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($ltv)) {
            $bodyHtml .= '<h2>Student Lifetime Value by Join Year</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Join Year</th><th class="text-right">Students</th><th class="text-right">Avg Lifetime Value</th></tr>';
            foreach ($ltv as $l) {
                $bodyHtml .= '<tr><td>' . $l['join_year'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $l['student_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . formatMoney($l['avg_ltv']) . '</td></tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Top 20 LTV Students ---
        $top_ltv = [];
        try {
            $top_ltv = $pdo->query("
                SELECT s.first_name, s.last_name, s.join_date, s.status,
                       COALESCE(SUM(p.amount), 0) as lifetime_revenue, COUNT(p.id) as total_payments
                FROM students s LEFT JOIN payments p ON s.id = p.student_id AND p.amount > 0
                WHERE s.is_parent = 0
                GROUP BY s.id ORDER BY lifetime_revenue DESC LIMIT 20
            ")->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($top_ltv)) {
            $bodyHtml .= '<h2>Top 20 Students by Lifetime Revenue</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>#</th><th>Student</th><th>Join Date</th><th>Status</th><th class="text-right">Lifetime Revenue</th><th class="text-right">Payments</th></tr>';
            foreach ($top_ltv as $i => $s) {
                $statusBadge = $s['status'] === 'active' ? 'badge-green' : 'badge-gray';
                $bodyHtml .= '<tr><td>' . ($i + 1) . '</td>';
                $bodyHtml .= '<td>' . htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) . '</td>';
                $bodyHtml .= '<td>' . formatDate($s['join_date']) . '</td>';
                $bodyHtml .= '<td><span class="badge ' . $statusBadge . '">' . ucfirst($s['status']) . '</span></td>';
                $bodyHtml .= '<td class="text-right">' . formatMoney($s['lifetime_revenue']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $s['total_payments'] . '</td></tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- At-Risk Students ---
        $churn = [];
        try {
            $churn = $pdo->query("
                SELECT s.id, s.first_name, s.last_name, s.email, m.end_date,
                       DATEDIFF(m.end_date, CURDATE()) as days_left,
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

        if (!empty($churn)) {
            $bodyHtml .= '<h2>At-Risk Students (Churn Risk)</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Student</th><th>Plan</th><th class="text-right">Days Left</th><th class="text-center">Auto-Renew</th><th class="text-right">Recent Attendance (30d)</th><th class="text-right">Payment Failures</th><th>Risk</th></tr>';
            foreach ($churn as $c) {
                $risk = 'LOW';
                $riskClass = 'badge-blue';
                if ($c['days_left'] <= 7 || $c['payment_failures'] >= 2 || $c['recent_attendance'] == 0) {
                    $risk = 'HIGH'; $riskClass = 'badge-red';
                } elseif ($c['days_left'] <= 14 || $c['payment_failures'] >= 1) {
                    $risk = 'MEDIUM'; $riskClass = 'badge-yellow';
                }
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) . '</td>';
                $bodyHtml .= '<td>' . htmlspecialchars($c['plan_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $c['days_left'] . '</td>';
                $bodyHtml .= '<td class="text-center">' . ($c['auto_renew'] ? 'Yes' : 'No') . '</td>';
                $bodyHtml .= '<td class="text-right">' . $c['recent_attendance'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $c['payment_failures'] . '</td>';
                $bodyHtml .= '<td><span class="badge ' . $riskClass . '">' . $risk . '</span></td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Belt Pipeline ---
        $belts = [];
        try {
            $belts = $pdo->query("
                SELECT belt_rank, COUNT(*) as student_count
                FROM students WHERE status = 'active' AND is_parent = 0 AND belt_rank IS NOT NULL AND belt_rank != ''
                GROUP BY belt_rank ORDER BY student_count DESC
            ")->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($belts)) {
            $totalBelts = max(1, array_sum(array_column($belts, 'student_count')));
            $bodyHtml .= '<h2>Belt Progression Pipeline</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Belt</th><th class="text-right">Students</th><th class="text-right">% of Total</th></tr>';
            foreach ($belts as $b) {
                $pct = round($b['student_count'] / $totalBelts * 100, 1);
                $bodyHtml .= '<tr><td>' . ucfirst(htmlspecialchars($b['belt_rank'])) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $b['student_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $pct . '%</td></tr>';
            }
            $bodyHtml .= '</table>';
        }
        break;

    // ══════════════════════════════════════════════════
    // ATTENDANCE TAB
    // ══════════════════════════════════════════════════
    case 'attendance':
        $title = 'Attendance Report';

        // --- Class Attendance ---
        $class_att = [];
        try {
            $stmt = $pdo->prepare("
                SELECT c.name, c.day_of_week, c.start_time,
                       COUNT(DISTINCT a.attendance_date) as total_sessions,
                       COUNT(CASE WHEN a.status = 'present' THEN 1 END) as total_present,
                       COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as total_absent,
                       COUNT(CASE WHEN a.status = 'late' THEN 1 END) as total_late,
                       COUNT(a.id) as total_records,
                       ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0), 1) as avg_rate
                FROM classes c
                LEFT JOIN attendance a ON c.id = a.class_id AND a.attendance_date BETWEEN ? AND ?
                WHERE c.status = 'active'
                GROUP BY c.id ORDER BY c.name
            ");
            $stmt->execute([$date_from, $date_to]);
            $class_att = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($class_att)) {
            $bodyHtml .= '<h2>Class Attendance Rates</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Class</th><th>Day</th><th class="text-right">Sessions</th><th class="text-right">Present</th><th class="text-right">Absent</th><th class="text-right">Late</th><th class="text-right">Rate</th></tr>';
            foreach ($class_att as $ca) {
                $rateClass = ($ca['avg_rate'] >= 80 ? 'text-green' : ($ca['avg_rate'] >= 60 ? 'text-yellow' : 'text-red'));
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($ca['name']) . '</td>';
                $bodyHtml .= '<td>' . $ca['day_of_week'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ca['total_sessions'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ca['total_present'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ca['total_absent'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ca['total_late'] . '</td>';
                $bodyHtml .= '<td class="text-right font-bold ' . $rateClass . '">' . ($ca['avg_rate'] ?? '–') . '%</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Class Capacity Utilization ---
        $capacity = [];
        try {
            $capacity = $pdo->query("
                SELECT c.name, c.day_of_week, c.max_students,
                       COUNT(ce.id) as enrolled,
                       ROUND(COUNT(ce.id) * 100.0 / NULLIF(c.max_students, 0), 1) as utilization_pct
                FROM classes c
                LEFT JOIN class_enrollments ce ON c.id = ce.class_id AND ce.status = 'active' AND ce.school_id = c.school_id
                WHERE c.status = 'active' AND c.school_id = " . intval(current_school_id()) . " GROUP BY c.id ORDER BY utilization_pct DESC
            ")->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($capacity)) {
            $bodyHtml .= '<h2>Class Capacity Utilization</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Class</th><th>Day</th><th class="text-right">Enrolled</th><th class="text-right">Max</th><th class="text-right">Utilization</th></tr>';
            foreach ($capacity as $cap) {
                $uClass = ($cap['utilization_pct'] > 90 ? 'text-red' : ($cap['utilization_pct'] >= 60 ? 'text-yellow' : 'text-gray'));
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($cap['name']) . '</td>';
                $bodyHtml .= '<td>' . $cap['day_of_week'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $cap['enrolled'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $cap['max_students'] . '</td>';
                $bodyHtml .= '<td class="text-right font-bold ' . $uClass . '">' . ($cap['utilization_pct'] ?? '–') . '%</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Top Students by Attendance ---
        $top_att = [];
        try {
            $params = [$date_from, $date_to];
            $stmt = $pdo->prepare("
                SELECT s.first_name, s.last_name, COUNT(a.id) as attendance_count
                FROM students s JOIN attendance a ON s.id = a.student_id
                WHERE a.status = 'present' AND a.attendance_date BETWEEN ? AND ?" . school_where('s') . "
                GROUP BY s.id ORDER BY attendance_count DESC LIMIT 20
            ");
            school_param($params);
            $stmt->execute($params);
            $top_att = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($top_att)) {
            $bodyHtml .= '<h2>Top Students by Attendance</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>#</th><th>Student</th><th class="text-right">Attendance Count</th></tr>';
            foreach ($top_att as $i => $ta) {
                $bodyHtml .= '<tr><td>' . ($i + 1) . '</td>';
                $bodyHtml .= '<td>' . htmlspecialchars($ta['first_name'] . ' ' . $ta['last_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ta['attendance_count'] . '</td></tr>';
            }
            $bodyHtml .= '</table>';
        }
        break;

    // ══════════════════════════════════════════════════
    // EVENTS TAB
    // ══════════════════════════════════════════════════
    case 'events':
        $title = 'Events Report';

        // --- Event Revenue by Type ---
        $event_rev = [];
        try {
            $stmt = $pdo->prepare("
                SELECT e.event_type, COUNT(DISTINCT e.id) as event_count, COUNT(er.id) as registrations,
                       COALESCE(SUM(er.amount_paid), 0) as total_revenue
                FROM events e LEFT JOIN event_registrations er ON e.id = er.event_id
                WHERE e.event_date BETWEEN ? AND ?
                GROUP BY e.event_type ORDER BY total_revenue DESC
            ");
            $stmt->execute([$date_from, $date_to]);
            $event_rev = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($event_rev)) {
            $bodyHtml .= '<h2>Event Revenue by Type</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Type</th><th class="text-right">Events</th><th class="text-right">Registrations</th><th class="text-right">Revenue</th></tr>';
            foreach ($event_rev as $er) {
                $bodyHtml .= '<tr><td>' . ucwords(str_replace('_', ' ', $er['event_type'])) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $er['event_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $er['registrations'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . formatMoney($er['total_revenue']) . '</td></tr>';
            }
            $total_ev_rev = array_sum(array_column($event_rev, 'total_revenue'));
            $bodyHtml .= '<tr class="font-bold"><td>Total</td><td></td><td></td><td class="text-right">' . formatMoney($total_ev_rev) . '</td></tr>';
            $bodyHtml .= '</table>';
        }

        // --- Event ROI Detail ---
        $event_roi = [];
        try {
            $stmt = $pdo->prepare("
                SELECT e.name, e.event_type, e.event_date, e.registration_fee, e.max_participants,
                       COUNT(er.id) as registrations,
                       COUNT(CASE WHEN er.attendance_status = 'attended' THEN 1 END) as attended,
                       COUNT(CASE WHEN er.attendance_status = 'no_show' THEN 1 END) as no_shows,
                       COALESCE(SUM(er.amount_paid), 0) as actual_revenue,
                       ROUND(COUNT(er.id) * 100.0 / NULLIF(e.max_participants, 0), 1) as fill_rate
                FROM events e LEFT JOIN event_registrations er ON e.id = er.event_id
                WHERE e.event_date BETWEEN ? AND ?
                GROUP BY e.id ORDER BY e.event_date DESC
            ");
            $stmt->execute([$date_from, $date_to]);
            $event_roi = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($event_roi)) {
            $bodyHtml .= '<h2>Event ROI &amp; Attendance Analysis</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Event</th><th>Type</th><th>Date</th><th class="text-right">Fee</th><th class="text-right">Reg</th><th class="text-right">Attended</th><th class="text-right">No-Show</th><th class="text-right">Revenue</th><th class="text-right">Fill Rate</th></tr>';
            foreach ($event_roi as $ev) {
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($ev['name']) . '</td>';
                $bodyHtml .= '<td>' . ucwords(str_replace('_', ' ', $ev['event_type'])) . '</td>';
                $bodyHtml .= '<td>' . formatDate($ev['event_date']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . formatMoney($ev['registration_fee']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ev['registrations'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ev['attended'] . '</td>';
                $bodyHtml .= '<td class="text-right ' . ($ev['no_shows'] > 0 ? 'text-red' : '') . '">' . $ev['no_shows'] . '</td>';
                $bodyHtml .= '<td class="text-right font-bold">' . formatMoney($ev['actual_revenue']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . ($ev['fill_rate'] ?? '–') . '%</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }
        break;

    // ══════════════════════════════════════════════════
    // COMPLIANCE TAB
    // ══════════════════════════════════════════════════
    case 'compliance':
        $title = 'Compliance Report';

        // --- No-Membership Students ---
        $no_mem = [];
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT s.first_name, s.last_name, s.email, COUNT(ce.id) as enrolled_classes
                FROM students s
                JOIN class_enrollments ce ON ce.student_id = s.id AND ce.status = 'active' AND ce.school_id = s.school_id
                LEFT JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
                WHERE s.status = 'active' AND m.id IS NULL" . school_where('s') . "
                GROUP BY s.id ORDER BY enrolled_classes DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $no_mem = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($no_mem)) {
            $bodyHtml .= '<h2>Students Without Active Membership (Enrolled in Classes)</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Student</th><th>Email</th><th class="text-right">Enrolled Classes</th></tr>';
            foreach ($no_mem as $nm) {
                $bodyHtml .= '<tr><td>' . htmlspecialchars($nm['first_name'] . ' ' . $nm['last_name']) . '</td>';
                $bodyHtml .= '<td>' . htmlspecialchars($nm['email']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $nm['enrolled_classes'] . '</td></tr>';
            }
            $bodyHtml .= '</table>';
        } else {
            $bodyHtml .= '<h2>Students Without Active Membership</h2>';
            $bodyHtml .= '<p class="no-data">No compliance issues found.</p>';
        }

        // --- Over-Limit Students ---
        $over_limit = [];
        try {
            $over_limit = $pdo->query("
                SELECT s.first_name, s.last_name, s.email, mp.name as plan_name,
                       mp.classes_per_week, COUNT(ce.id) as enrolled_classes
                FROM students s
                JOIN class_enrollments ce ON ce.student_id = s.id AND ce.status = 'active' AND ce.school_id = s.school_id
                JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
                JOIN membership_plans mp ON m.plan_id = mp.id
                WHERE s.status = 'active' AND s.school_id = " . intval(current_school_id()) . " AND mp.classes_per_week < 99
                GROUP BY s.id, mp.name, mp.classes_per_week
                HAVING COUNT(ce.id) > mp.classes_per_week
                ORDER BY (COUNT(ce.id) - mp.classes_per_week) DESC
            ")->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($over_limit)) {
            $bodyHtml .= '<h2>Students Over Plan Class Limit</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Student</th><th>Plan</th><th class="text-right">Limit</th><th class="text-right">Enrolled</th><th class="text-right">Over By</th></tr>';
            foreach ($over_limit as $ol) {
                $overBy = $ol['enrolled_classes'] - $ol['classes_per_week'];
                $bodyHtml .= '<tr><td>' . htmlspecialchars($ol['first_name'] . ' ' . $ol['last_name']) . '</td>';
                $bodyHtml .= '<td>' . htmlspecialchars($ol['plan_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ol['classes_per_week'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ol['enrolled_classes'] . '</td>';
                $bodyHtml .= '<td class="text-right text-red font-bold">+' . $overBy . '</td></tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Payment Defaults ---
        $defaults = [];
        try {
            $defaults = $pdo->query("
                SELECT s.first_name, s.last_name, s.email, mp.name as plan_name,
                       m.status as membership_status, rl.created_at
                FROM renewal_log rl
                JOIN students s ON rl.student_id = s.id
                JOIN memberships m ON rl.membership_id = m.id
                JOIN membership_plans mp ON m.plan_id = mp.id
                WHERE rl.action = 'payment_failed'
                ORDER BY rl.created_at DESC LIMIT 50
            ")->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($defaults)) {
            $bodyHtml .= '<h2>Payment Defaults</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Student</th><th>Email</th><th>Plan</th><th>Membership Status</th><th>Failed Date</th></tr>';
            foreach ($defaults as $d) {
                $bodyHtml .= '<tr><td>' . htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) . '</td>';
                $bodyHtml .= '<td>' . htmlspecialchars($d['email']) . '</td>';
                $bodyHtml .= '<td>' . htmlspecialchars($d['plan_name']) . '</td>';
                $bodyHtml .= '<td><span class="badge badge-red">' . ucfirst($d['membership_status']) . '</span></td>';
                $bodyHtml .= '<td>' . formatDateTime($d['created_at']) . '</td></tr>';
            }
            $bodyHtml .= '</table>';
        }
        break;

    // ══════════════════════════════════════════════════
    // SCHOOLS TAB (Super Admin Only)
    // ══════════════════════════════════════════════════
    case 'schools':
        if (!is_super_admin()) {
            accessDenied('School Comparison reports are only available to Super Admins.', 'reports.php');
        }
        $title = 'School Comparison Report';

        // --- Revenue per School ---
        $school_revenue = [];
        try {
            $stmt = $pdo->prepare("
                SELECT sc.name as school_name,
                       COALESCE(SUM(p.amount), 0) as revenue,
                       COUNT(p.id) as payments,
                       ROUND(COALESCE(SUM(p.amount), 0) / NULLIF(COUNT(p.id), 0), 2) as avg_payment
                FROM schools sc
                LEFT JOIN payments p ON p.school_id = sc.id
                    AND p.payment_date BETWEEN ? AND ?
                    AND p.status = 'completed' AND p.amount > 0
                GROUP BY sc.id ORDER BY revenue DESC
            ");
            $stmt->execute([$date_from, $date_to]);
            $school_revenue = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($school_revenue)) {
            $bodyHtml .= '<h2>Revenue by School</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>School</th><th class="text-right">Revenue</th><th class="text-right">Payments</th><th class="text-right">Avg/Payment</th></tr>';
            foreach ($school_revenue as $sr) {
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($sr['school_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . formatMoney($sr['revenue']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $sr['payments'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . formatMoney($sr['avg_payment'] ?? 0) . '</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Student Counts per School ---
        $school_students = [];
        try {
            $stmt = $pdo->query("
                SELECT sc.name as school_name,
                       COUNT(s.id) as total_students,
                       COUNT(CASE WHEN s.status = 'active' THEN 1 END) as active_students,
                       COUNT(DISTINCT m.id) as active_memberships,
                       ROUND(COUNT(CASE WHEN s.status = 'active' THEN 1 END) * 100.0 / NULLIF(COUNT(s.id), 0), 1) as active_rate
                FROM schools sc
                LEFT JOIN students s ON s.school_id = sc.id AND s.is_parent = 0
                LEFT JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
                GROUP BY sc.id ORDER BY total_students DESC
            ");
            $school_students = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($school_students)) {
            $bodyHtml .= '<h2>Student Counts by School</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>School</th><th class="text-right">Total</th><th class="text-right">Active</th><th class="text-right">Memberships</th><th class="text-right">Rate</th></tr>';
            foreach ($school_students as $ss) {
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($ss['school_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ss['total_students'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ss['active_students'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ss['active_memberships'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . ($ss['active_rate'] ?? '0') . '%</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Attendance Rates per School ---
        $school_attendance = [];
        try {
            $stmt = $pdo->prepare("
                SELECT sc.name as school_name,
                       COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
                       COUNT(a.id) as total_records,
                       ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0), 1) as attendance_rate
                FROM schools sc
                LEFT JOIN students s ON s.school_id = sc.id
                LEFT JOIN attendance a ON a.student_id = s.id
                    AND a.attendance_date BETWEEN ? AND ?
                GROUP BY sc.id ORDER BY attendance_rate DESC
            ");
            $stmt->execute([$date_from, $date_to]);
            $school_attendance = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($school_attendance)) {
            $bodyHtml .= '<h2>Attendance Rates by School</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>School</th><th class="text-right">Present</th><th class="text-right">Total</th><th class="text-right">Rate</th></tr>';
            foreach ($school_attendance as $sa) {
                $rateClass = ($sa['attendance_rate'] >= 80 ? 'text-green' : ($sa['attendance_rate'] >= 60 ? 'text-yellow' : 'text-red'));
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($sa['school_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $sa['present_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $sa['total_records'] . '</td>';
                $bodyHtml .= '<td class="text-right font-bold ' . $rateClass . '">' . ($sa['attendance_rate'] ?? '0') . '%</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }
        break;

    // ══════════════════════════════════════════════════
    // INSTRUCTORS TAB
    // ══════════════════════════════════════════════════
    case 'instructors':
        $title = 'Instructor Performance Report';

        // --- Instructor Summary ---
        $instructor_summary = [];
        try {
            $params = [$date_from, $date_to, $date_from, $date_to];
            $stmt = $pdo->prepare("
                SELECT u.id, u.full_name,
                       COUNT(DISTINCT c.id) as class_count,
                       COUNT(DISTINCT ce.student_id) as student_count,
                       ROUND(
                           COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0
                           / NULLIF(COUNT(a.id), 0), 1
                       ) as attendance_rate
                FROM users u
                JOIN classes c ON c.instructor_id = u.id AND c.status = 'active'
                LEFT JOIN class_enrollments ce ON ce.class_id = c.id AND ce.status = 'active' AND ce.school_id = c.school_id
                LEFT JOIN attendance a ON a.class_id = c.id
                    AND a.attendance_date BETWEEN ? AND ?
                LEFT JOIN attendance a2 ON a2.class_id = c.id
                    AND a2.attendance_date BETWEEN ? AND ?
                WHERE u.role = 'instructor'" . school_where('u') . "
                GROUP BY u.id ORDER BY class_count DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $instructor_summary = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($instructor_summary)) {
            $bodyHtml .= '<h2>Instructor Summary</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Instructor</th><th class="text-right">Classes</th><th class="text-right">Students</th><th class="text-right">Attendance Rate</th></tr>';
            foreach ($instructor_summary as $is) {
                $rateClass = ($is['attendance_rate'] >= 80 ? 'text-green' : ($is['attendance_rate'] >= 60 ? 'text-yellow' : 'text-red'));
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($is['full_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $is['class_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $is['student_count'] . '</td>';
                $bodyHtml .= '<td class="text-right font-bold ' . $rateClass . '">' . ($is['attendance_rate'] ?? '0') . '%</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Promotions by Instructor ---
        $instructor_promotions = [];
        try {
            $params = [$date_from, $date_to];
            $stmt = $pdo->prepare("
                SELECT u.full_name,
                       COUNT(sb.id) as promotion_count,
                       COUNT(DISTINCT sb.student_id) as students_promoted,
                       COUNT(DISTINCT b.name) as belt_levels
                FROM users u
                JOIN student_belts sb ON sb.instructor_id = u.id
                JOIN belts b ON sb.belt_id = b.id
                WHERE sb.awarded_date BETWEEN ? AND ?" . school_where('u') . "
                GROUP BY u.id ORDER BY promotion_count DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $instructor_promotions = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($instructor_promotions)) {
            $bodyHtml .= '<h2>Promotions by Instructor</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Instructor</th><th class="text-right">Promotions</th><th class="text-right">Students Promoted</th><th class="text-right">Belt Levels</th></tr>';
            foreach ($instructor_promotions as $ip) {
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($ip['full_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ip['promotion_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ip['students_promoted'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ip['belt_levels'] . '</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Capacity by Instructor ---
        $instructor_capacity = [];
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT u.full_name,
                       COUNT(DISTINCT c.id) as class_count,
                       SUM(c.max_students) as total_capacity,
                       COUNT(DISTINCT ce.id) as total_enrolled,
                       ROUND(COUNT(DISTINCT ce.id) * 100.0 / NULLIF(SUM(c.max_students), 0), 1) as utilization_pct
                FROM users u
                JOIN classes c ON c.instructor_id = u.id AND c.status = 'active'
                LEFT JOIN class_enrollments ce ON ce.class_id = c.id AND ce.status = 'active' AND ce.school_id = c.school_id
                WHERE u.role = 'instructor'" . school_where('u') . "
                GROUP BY u.id ORDER BY utilization_pct DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $instructor_capacity = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($instructor_capacity)) {
            $bodyHtml .= '<h2>Capacity by Instructor</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Instructor</th><th class="text-right">Classes</th><th class="text-right">Total Capacity</th><th class="text-right">Enrolled</th><th class="text-right">Utilization</th></tr>';
            foreach ($instructor_capacity as $ic) {
                $uClass = ($ic['utilization_pct'] > 90 ? 'text-red' : ($ic['utilization_pct'] >= 60 ? 'text-yellow' : 'text-gray'));
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($ic['full_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ic['class_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ic['total_capacity'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $ic['total_enrolled'] . '</td>';
                $bodyHtml .= '<td class="text-right font-bold ' . $uClass . '">' . ($ic['utilization_pct'] ?? '0') . '%</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }
        break;

    // ══════════════════════════════════════════════════
    // MEMBERSHIP LIFECYCLE TAB
    // ══════════════════════════════════════════════════
    case 'membership_lifecycle':
        $title = 'Membership Lifecycle Report';

        // --- KPI Calculations ---
        $mrr = 0;
        $avg_duration = 0;
        $renewal_rate = 0;
        $collection_rate = 0;

        // MRR (Monthly Recurring Revenue)
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(ROUND(mp.price / GREATEST(mp.duration_months, 1), 2)), 0) as mrr
                FROM memberships m
                JOIN membership_plans mp ON m.plan_id = mp.id
                WHERE m.status = 'active' AND m.end_date >= CURDATE()" . school_where('m') . "
            ");
            school_param($params);
            $stmt->execute($params);
            $mrr = (float) $stmt->fetch()['mrr'];
        } catch (\PDOException $e) {}

        // Average Membership Duration
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT ROUND(AVG(DATEDIFF(
                    CASE WHEN m.status = 'active' THEN CURDATE() ELSE m.end_date END,
                    m.start_date
                )), 0) as avg_days
                FROM memberships m
                WHERE m.start_date IS NOT NULL" . school_where('m') . "
            ");
            school_param($params);
            $stmt->execute($params);
            $avg_duration = (int) ($stmt->fetch()['avg_days'] ?? 0);
        } catch (\PDOException $e) {}

        // Renewal Rate
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(CASE WHEN rl.action = 'renewed' THEN 1 END) as renewed,
                    COUNT(CASE WHEN rl.action IN ('renewed', 'expired', 'cancelled') THEN 1 END) as total_endings
                FROM renewal_log rl
                JOIN memberships m ON rl.membership_id = m.id" . school_where('m') . "
            ");
            school_param($params);
            $stmt->execute($params);
            $rr = $stmt->fetch();
            $renewal_rate = $rr['total_endings'] > 0 ? round($rr['renewed'] / $rr['total_endings'] * 100, 1) : 0;
        } catch (\PDOException $e) {}

        // Collection Rate
        try {
            $params = [$date_from, $date_to, $date_from, $date_to];
            $stmt = $pdo->prepare("
                SELECT
                    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed' AND payment_date BETWEEN ? AND ?" . school_where() . ") as collected,
                    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date BETWEEN ? AND ?" . school_where() . ") as total_billed
            ");
            school_param($params);
            school_param($params);
            $stmt->execute($params);
            $cr = $stmt->fetch();
            $collection_rate = $cr['total_billed'] > 0 ? round($cr['collected'] / $cr['total_billed'] * 100, 1) : 0;
        } catch (\PDOException $e) {}

        // KPI Section
        $avg_duration_label = $avg_duration > 0 ? round($avg_duration / 30, 1) . ' months' : 'N/A';
        $bodyHtml .= '<h2>Key Performance Indicators</h2>';
        $bodyHtml .= '<table>';
        $bodyHtml .= '<tr><th>MRR</th><th>Avg Duration</th><th>Renewal Rate</th><th>Collection Rate</th></tr>';
        $bodyHtml .= '<tr>';
        $bodyHtml .= '<td class="text-center font-bold">' . formatMoney($mrr) . '</td>';
        $bodyHtml .= '<td class="text-center font-bold">' . $avg_duration_label . '</td>';
        $bodyHtml .= '<td class="text-center font-bold">' . $renewal_rate . '%</td>';
        $bodyHtml .= '<td class="text-center font-bold">' . $collection_rate . '%</td>';
        $bodyHtml .= '</tr>';
        $bodyHtml .= '</table>';

        // --- New Memberships Trend (12 months) ---
        $new_memberships = [];
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(m.start_date, '%Y-%m') as month,
                       DATE_FORMAT(m.start_date, '%b %Y') as label,
                       COUNT(*) as new_count
                FROM memberships m
                WHERE m.start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . school_where('m') . "
                GROUP BY month ORDER BY month ASC
            ");
            school_param($params);
            $stmt->execute($params);
            $new_memberships = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($new_memberships)) {
            $bodyHtml .= '<h2>New Memberships Trend (Last 12 Months)</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Month</th><th class="text-right">New Memberships</th></tr>';
            foreach ($new_memberships as $nm) {
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($nm['label']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $nm['new_count'] . '</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Cancellations Trend ---
        $cancel_trend = [];
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(rl.created_at, '%Y-%m') as month,
                       DATE_FORMAT(rl.created_at, '%b %Y') as label,
                       COUNT(*) as cancel_count
                FROM renewal_log rl
                JOIN memberships m ON rl.membership_id = m.id
                WHERE rl.action = 'cancelled'
                    AND rl.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . school_where('m') . "
                GROUP BY month ORDER BY month ASC
            ");
            school_param($params);
            $stmt->execute($params);
            $cancel_trend = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($cancel_trend)) {
            $bodyHtml .= '<h2>Cancellations Trend (Last 12 Months)</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Month</th><th class="text-right">Cancellations</th></tr>';
            foreach ($cancel_trend as $ct) {
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($ct['label']) . '</td>';
                $bodyHtml .= '<td class="text-right text-red">' . $ct['cancel_count'] . '</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Plan Popularity ---
        $plan_popularity = [];
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT mp.name as plan_name,
                       COUNT(m.id) as active_members,
                       ROUND(mp.price / GREATEST(mp.duration_months, 1), 2) as monthly_rev_est,
                       COUNT(m.id) * ROUND(mp.price / GREATEST(mp.duration_months, 1), 2) as total_monthly_rev
                FROM membership_plans mp
                LEFT JOIN memberships m ON m.plan_id = mp.id AND m.status = 'active' AND m.end_date >= CURDATE()
                WHERE 1=1" . school_where('mp') . "
                GROUP BY mp.id ORDER BY active_members DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $plan_popularity = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        $total_plan_members = max(1, array_sum(array_column($plan_popularity, 'active_members')));
        if (!empty($plan_popularity)) {
            $bodyHtml .= '<h2>Plan Popularity</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Plan</th><th class="text-right">Active Members</th><th class="text-right">Monthly Rev Est</th><th class="text-right">% of Total</th></tr>';
            foreach ($plan_popularity as $pp) {
                $pct = round($pp['active_members'] / $total_plan_members * 100, 1);
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($pp['plan_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $pp['active_members'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . formatMoney($pp['total_monthly_rev']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $pct . '%</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Key Metrics Summary ---
        $bodyHtml .= '<h2>Key Metrics Summary</h2>';
        $bodyHtml .= '<table>';
        $bodyHtml .= '<tr><th>Metric</th><th class="text-right">Value</th></tr>';
        $bodyHtml .= '<tr><td>Monthly Recurring Revenue (MRR)</td><td class="text-right font-bold">' . formatMoney($mrr) . '</td></tr>';
        $bodyHtml .= '<tr><td>Average Membership Duration</td><td class="text-right font-bold">' . $avg_duration_label . '</td></tr>';
        $bodyHtml .= '<tr><td>Renewal Rate</td><td class="text-right font-bold">' . $renewal_rate . '%</td></tr>';
        $bodyHtml .= '<tr><td>Collection Rate</td><td class="text-right font-bold">' . $collection_rate . '%</td></tr>';
        $bodyHtml .= '</table>';
        break;

    // ══════════════════════════════════════════════════
    // BELT PROGRESSION TAB
    // ══════════════════════════════════════════════════
    case 'belt_progression':
        $title = 'Belt Progression Report';

        // --- Avg Time Between Promotions ---
        $promotion_times = [];
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT b.name as belt_name,
                       COUNT(sb.id) as promotion_count,
                       ROUND(AVG(DATEDIFF(sb.awarded_date, COALESCE(
                           (SELECT sb2.awarded_date FROM student_belts sb2
                            WHERE sb2.student_id = sb.student_id AND sb2.awarded_date < sb.awarded_date
                            ORDER BY sb2.awarded_date DESC LIMIT 1),
                           (SELECT s.join_date FROM students s WHERE s.id = sb.student_id)
                       ))), 0) as avg_days
                FROM student_belts sb
                JOIN belts b ON sb.belt_id = b.id" . school_where('sb') . "
                GROUP BY b.id, b.name ORDER BY avg_days ASC
            ");
            school_param($params);
            $stmt->execute($params);
            $promotion_times = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($promotion_times)) {
            $bodyHtml .= '<h2>Average Time Between Promotions</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Belt</th><th class="text-right">Promotions</th><th class="text-right">Avg Days</th><th class="text-right">Avg Months</th></tr>';
            foreach ($promotion_times as $pt) {
                $avgMonths = $pt['avg_days'] > 0 ? round($pt['avg_days'] / 30, 1) : 'N/A';
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($pt['belt_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $pt['promotion_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $pt['avg_days'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $avgMonths . '</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Promotion Velocity by Cohort ---
        $velocity = [];
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT YEAR(s.join_date) as join_year,
                       COUNT(DISTINCT s.id) as student_count,
                       COUNT(sb.id) as total_promotions,
                       ROUND(COUNT(sb.id) * 1.0 / NULLIF(COUNT(DISTINCT s.id), 0), 2) as avg_promotions_per_student
                FROM students s
                LEFT JOIN student_belts sb ON sb.student_id = s.id
                WHERE s.is_parent = 0 AND s.join_date IS NOT NULL" . school_where('s') . "
                GROUP BY join_year ORDER BY join_year DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $velocity = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($velocity)) {
            $bodyHtml .= '<h2>Promotion Velocity by Cohort</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Join Year</th><th class="text-right">Students</th><th class="text-right">Total Promotions</th><th class="text-right">Avg Promotions/Student</th></tr>';
            foreach ($velocity as $v) {
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . $v['join_year'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $v['student_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $v['total_promotions'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $v['avg_promotions_per_student'] . '</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Instructor Promotions ---
        $belt_instructor = [];
        try {
            $params = [$date_from, $date_to];
            $stmt = $pdo->prepare("
                SELECT u.full_name,
                       COUNT(sb.id) as promotion_count,
                       COUNT(DISTINCT sb.student_id) as students_promoted,
                       COUNT(DISTINCT b.style_id) as styles_covered
                FROM student_belts sb
                JOIN users u ON sb.instructor_id = u.id
                JOIN belts b ON sb.belt_id = b.id
                WHERE sb.awarded_date BETWEEN ? AND ?" . school_where('sb') . "
                GROUP BY u.id ORDER BY promotion_count DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $belt_instructor = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($belt_instructor)) {
            $bodyHtml .= '<h2>Instructor Promotions (' . htmlspecialchars($range_label) . ')</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Instructor</th><th class="text-right">Promotions</th><th class="text-right">Students</th><th class="text-right">Styles</th></tr>';
            foreach ($belt_instructor as $bi) {
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($bi['full_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $bi['promotion_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $bi['students_promoted'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $bi['styles_covered'] . '</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Belt Test Pass Rates ---
        $pass_rates = [];
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT e.name as event_name,
                       e.event_date,
                       COUNT(er.id) as total_tested,
                       COUNT(CASE WHEN er.result = 'pass' THEN 1 END) as passed,
                       COUNT(CASE WHEN er.result = 'fail' THEN 1 END) as failed,
                       ROUND(COUNT(CASE WHEN er.result = 'pass' THEN 1 END) * 100.0
                           / NULLIF(COUNT(er.id), 0), 1) as pass_rate
                FROM events e
                LEFT JOIN event_registrations er ON er.event_id = e.id
                WHERE e.event_type = 'belt_test'" . school_where('e') . "
                GROUP BY e.id ORDER BY e.event_date DESC
            ");
            school_param($params);
            $stmt->execute($params);
            $pass_rates = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($pass_rates)) {
            $bodyHtml .= '<h2>Belt Test Pass Rates</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Event</th><th>Date</th><th class="text-right">Tested</th><th class="text-right">Passed</th><th class="text-right">Failed</th><th class="text-right">Pass Rate</th></tr>';
            foreach ($pass_rates as $pr) {
                $prClass = ($pr['pass_rate'] >= 80 ? 'text-green' : ($pr['pass_rate'] >= 60 ? 'text-yellow' : 'text-red'));
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($pr['event_name']) . '</td>';
                $bodyHtml .= '<td>' . formatDate($pr['event_date']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $pr['total_tested'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $pr['passed'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $pr['failed'] . '</td>';
                $bodyHtml .= '<td class="text-right font-bold ' . $prClass . '">' . ($pr['pass_rate'] ?? '0') . '%</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }
        break;

    // ══════════════════════════════════════════════════
    // ATTENDANCE PATTERNS TAB
    // ══════════════════════════════════════════════════
    case 'attendance_patterns':
        $title = 'Attendance Patterns Report';

        // --- Heatmap: Day of Week x Hour ---
        $heatmap_data = [];
        try {
            $params = [$date_from, $date_to];
            $stmt = $pdo->prepare("
                SELECT DAYOFWEEK(a.attendance_date) as dow,
                       HOUR(c.start_time) as hour_of_day,
                       COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count
                FROM attendance a
                JOIN classes c ON a.class_id = c.id
                WHERE a.attendance_date BETWEEN ? AND ?" . school_where('a') . "
                GROUP BY dow, hour_of_day ORDER BY dow, hour_of_day
            ");
            school_param($params);
            $stmt->execute($params);
            $heatmap_raw = $stmt->fetchAll();
        } catch (\PDOException $e) { $heatmap_raw = []; }

        // Build heatmap grid
        $days = ['', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $heatmap = [];
        $max_val = 1;
        foreach ($heatmap_raw as $hr) {
            $heatmap[$hr['dow']][$hr['hour_of_day']] = (int) $hr['present_count'];
            if ($hr['present_count'] > $max_val) $max_val = (int) $hr['present_count'];
        }

        // Determine hour range from data
        $all_hours = array_unique(array_column($heatmap_raw, 'hour_of_day'));
        sort($all_hours);
        if (empty($all_hours)) $all_hours = range(6, 21);
        $min_hour = min($all_hours);
        $max_hour = max($all_hours);
        $hours = range($min_hour, $max_hour);

        $bodyHtml .= '<h2>Attendance Heatmap (Day x Hour)</h2>';
        $bodyHtml .= '<table>';
        $bodyHtml .= '<tr><th>Day</th>';
        foreach ($hours as $h) {
            $bodyHtml .= '<th class="text-center">' . sprintf('%02d:00', $h) . '</th>';
        }
        $bodyHtml .= '</tr>';
        for ($d = 1; $d <= 7; $d++) {
            $bodyHtml .= '<tr><td class="font-bold">' . $days[$d] . '</td>';
            foreach ($hours as $h) {
                $val = $heatmap[$d][$h] ?? 0;
                $intensity = $max_val > 0 ? round($val / $max_val * 100) : 0;
                $bgColor = $val === 0 ? '#f5f5f5' : 'rgba(34,139,34,' . round($intensity / 100, 2) . ')';
                $textColor = $intensity > 50 ? '#fff' : '#333';
                $bodyHtml .= '<td class="text-center" style="background-color:' . $bgColor . ';color:' . $textColor . ';">' . $val . '</td>';
            }
            $bodyHtml .= '</tr>';
        }
        $bodyHtml .= '</table>';

        // --- Student Consistency (Top 50) ---
        $consistency = [];
        try {
            $params = [$date_from, $date_to, $date_from, $date_to];
            $stmt = $pdo->prepare("
                SELECT CONCAT(s.first_name, ' ', s.last_name) as full_name,
                       COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
                       COUNT(a.id) as total_records,
                       ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0
                           / NULLIF(COUNT(a.id), 0), 1) as consistency_rate,
                       COUNT(DISTINCT YEARWEEK(a.attendance_date)) as weeks_attended
                FROM students s
                JOIN attendance a ON a.student_id = s.id
                WHERE a.attendance_date BETWEEN ? AND ?
                    AND s.status = 'active'" . school_where('s') . "
                GROUP BY s.id
                HAVING COUNT(a.id) > 0
                ORDER BY consistency_rate DESC, present_count DESC
                LIMIT 50
            ");
            school_param($params);
            $stmt->execute($params);
            $consistency = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($consistency)) {
            $bodyHtml .= '<h2>Top 50 Most Consistent Students</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>#</th><th>Student</th><th class="text-right">Present</th><th class="text-right">Total</th><th class="text-right">Consistency</th><th class="text-right">Weeks Attended</th></tr>';
            foreach ($consistency as $i => $cs) {
                $cClass = ($cs['consistency_rate'] >= 80 ? 'text-green' : ($cs['consistency_rate'] >= 60 ? 'text-yellow' : 'text-red'));
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . ($i + 1) . '</td>';
                $bodyHtml .= '<td>' . htmlspecialchars($cs['full_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $cs['present_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $cs['total_records'] . '</td>';
                $bodyHtml .= '<td class="text-right font-bold ' . $cClass . '">' . $cs['consistency_rate'] . '%</td>';
                $bodyHtml .= '<td class="text-right">' . $cs['weeks_attended'] . '</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Dropout Risk (Declining Attendance) ---
        $dropout_risk = [];
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT CONCAT(s.first_name, ' ', s.last_name) as full_name,
                       recent.recent_count,
                       prior.prior_count,
                       ROUND((recent.recent_count - prior.prior_count) * 100.0
                           / NULLIF(prior.prior_count, 0), 1) as change_pct
                FROM students s
                JOIN (
                    SELECT student_id, COUNT(*) as recent_count
                    FROM attendance
                    WHERE status = 'present'
                        AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    GROUP BY student_id
                ) recent ON recent.student_id = s.id
                JOIN (
                    SELECT student_id, COUNT(*) as prior_count
                    FROM attendance
                    WHERE status = 'present'
                        AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                        AND attendance_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    GROUP BY student_id
                ) prior ON prior.student_id = s.id
                WHERE s.status = 'active'
                    AND prior.prior_count > 0
                    AND recent.recent_count < prior.prior_count" . school_where('s') . "
                ORDER BY change_pct ASC
                LIMIT 30
            ");
            school_param($params);
            $stmt->execute($params);
            $dropout_risk = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($dropout_risk)) {
            $bodyHtml .= '<h2>Dropout Risk (Declining Attendance)</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Student</th><th class="text-right">Last 30 Days</th><th class="text-right">Prior 60 Days</th><th class="text-right">Change</th></tr>';
            foreach ($dropout_risk as $dr) {
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($dr['full_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $dr['recent_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $dr['prior_count'] . '</td>';
                $bodyHtml .= '<td class="text-right text-red font-bold">' . $dr['change_pct'] . '%</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- Seasonal Patterns ---
        $seasonal = [];
        try {
            $params = [];
            $stmt = $pdo->prepare("
                SELECT MONTH(a.attendance_date) as month_num,
                       DATE_FORMAT(a.attendance_date, '%M') as month_name,
                       COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
                       COUNT(a.id) as total_records,
                       ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0
                           / NULLIF(COUNT(a.id), 0), 1) as attendance_rate
                FROM attendance a
                WHERE a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)" . school_where('a') . "
                GROUP BY month_num, month_name
                ORDER BY month_num ASC
            ");
            school_param($params);
            $stmt->execute($params);
            $seasonal = $stmt->fetchAll();
        } catch (\PDOException $e) {}

        if (!empty($seasonal)) {
            $bodyHtml .= '<h2>Seasonal Attendance Patterns (Last 24 Months)</h2>';
            $bodyHtml .= '<table>';
            $bodyHtml .= '<tr><th>Month</th><th class="text-right">Present</th><th class="text-right">Total</th><th class="text-right">Rate</th></tr>';
            foreach ($seasonal as $sp) {
                $sClass = ($sp['attendance_rate'] >= 80 ? 'text-green' : ($sp['attendance_rate'] >= 60 ? 'text-yellow' : 'text-red'));
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td>' . htmlspecialchars($sp['month_name']) . '</td>';
                $bodyHtml .= '<td class="text-right">' . $sp['present_count'] . '</td>';
                $bodyHtml .= '<td class="text-right">' . $sp['total_records'] . '</td>';
                $bodyHtml .= '<td class="text-right font-bold ' . $sClass . '">' . $sp['attendance_rate'] . '%</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</table>';
        }
        break;

    default:
        $bodyHtml = '<p class="no-data">Unknown report tab: ' . htmlspecialchars($tab) . '</p>';
        break;
}

// ─────────────────────────────────────────────────────
// Generate and stream the PDF
// ─────────────────────────────────────────────────────

if (empty($bodyHtml)) {
    $bodyHtml = '<p class="no-data">No data available for this report.</p>';
}

$html     = buildPdfHtml($title, $schoolName, $range_label, $bodyHtml);
$filename = strtolower(str_replace(' ', '_', $title)) . '_' . date('Y-m-d') . '.pdf';

generatePdf($html, $filename, 'landscape');
