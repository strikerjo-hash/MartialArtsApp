<?php
/**
 * student_portal.php — Student Dashboard
 *
 * The main landing page after a student logs in.  Shows upcoming
 * classes, attendance history, events, membership, and profile info.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';
require_once __DIR__ . '/includes/parent_auth.php';

// Require student login
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}

// Locked-out students cannot view the dashboard — redirect to payment page
// (also enforces registration completion for imported students)
require_student_payment_clear();

$studentId = $_SESSION['student_id'];

// Fetch student profile
$stmtSql = 'SELECT * FROM students WHERE id = :id';
if (!is_viewing_all_schools()) { $stmtSql .= ' AND school_id = :school_id'; }
$stmtSql .= ' LIMIT 1';
$stmt = $pdo->prepare($stmtSql);
$stmt->bindValue(':id', $studentId, PDO::PARAM_INT);
if (!is_viewing_all_schools()) { $stmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
$stmt->execute();
$student = $stmt->fetch();

if (!$student) {
    header('Location: logout.php');
    exit;
}

$portal_message = '';

// Handle pending plan change actions (approve/decline)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['approve_plan_change'])) {
        $changeId = (int) ($_POST['change_id'] ?? 0);

        // Fetch the pending change
        $pcParams = [$changeId, $studentId];
        $pcStmt = $pdo->prepare("
            SELECT pc.*, mp_new.*, mp_new.name as new_plan_name, mp_new.price as new_plan_price,
                   mp_new.duration_months as new_duration, mp_new.billing_frequency as new_billing_frequency
            FROM pending_plan_changes pc
            JOIN membership_plans mp_new ON pc.new_plan_id = mp_new.id
            WHERE pc.id = ? AND pc.student_id = ? AND pc.status = 'pending' AND pc.expires_at > NOW()" . school_where('pc') . "
        ");
        school_param($pcParams);
        $pcStmt->execute($pcParams);
        $pc = $pcStmt->fetch();

        if (!$pc) {
            $portal_message = showAlert('This plan change is no longer available.', 'error');
        } else {
            // Get current active membership
            $curMemParams = [$studentId];
            $curMem = $pdo->prepare("
                SELECT m.*, mp.name as plan_name, mp.price as plan_price, mp.duration_months, mp.billing_frequency
                FROM memberships m
                JOIN membership_plans mp ON m.plan_id = mp.id
                WHERE m.student_id = ? AND m.status = 'active' AND m.end_date >= CURDATE()" . school_where('m') . "
                ORDER BY m.end_date DESC LIMIT 1
            ");
            school_param($curMemParams);
            $curMem->execute($curMemParams);
            $curMemRow = $curMem->fetch() ?: null;

            // Recalculate proration live (not stale stored data)
            $newPlan = [
                'price' => $pc['new_plan_price'],
                'duration_months' => $pc['new_duration'],
                'billing_frequency' => $pc['new_billing_frequency'] ?? 'upfront',
            ];
            $proration = calculateProration($curMemRow, $newPlan);

            if ($proration['amount'] > 0) {
                // Upgrade — redirect to payment confirmation page
                $_SESSION['approve_change_id'] = $changeId;
                $_SESSION['approve_proration'] = $proration;
                header('Location: student_approve_change.php');
                exit;
            } else {
                // Downgrade or free — process immediately (no payment needed)
                if ($curMemRow) {
                    $updMemParams = [$curMemRow['id']];
                    $updMemSql = "UPDATE memberships SET status = 'cancelled', end_date = CURDATE() WHERE id = ?" . school_where();
                    school_param($updMemParams);
                    $pdo->prepare($updMemSql)->execute($updMemParams);
                }

                $start_date = date('Y-m-d');
                $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $pc['new_duration'] . ' months'));
                $isMonthly = (($pc['new_billing_frequency'] ?? 'upfront') === 'monthly' && $pc['new_duration'] > 1);
                $billing_day = $isMonthly ? min((int) date('j'), 28) : null;

                $pdo->prepare("
                    INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date, status, payment_status, amount_paid, billing_day, monthly_charges_made)
                    VALUES (?, ?, ?, ?, ?, 'active', 'paid', 0, ?, 0)
                ")->execute([current_school_id(), $studentId, $pc['new_plan_id'], $start_date, $end_date, $billing_day]);
                $newMembershipId = $pdo->lastInsertId();

                // Add downgrade credit
                if ($proration['credit'] > 0) {
                    add_student_credit(
                        $studentId,
                        $proration['credit'],
                        'Plan change credit: ' . ($curMemRow['plan_name'] ?? 'None') . ' to ' . $pc['new_plan_name'],
                        'downgrade',
                        (int) $newMembershipId
                    );
                }

                // Mark pending change as approved
                $updPcParams = [$changeId];
                $updPcSql = "UPDATE pending_plan_changes SET status = 'approved', resolved_at = NOW() WHERE id = ?" . school_where();
                school_param($updPcParams);
                $pdo->prepare($updPcSql)->execute($updPcParams);

                // Record plan change date for lockout
                $updStParams = [$studentId];
                $updStSql = "UPDATE students SET last_plan_change = CURDATE() WHERE id = ?" . school_where();
                school_param($updStParams);
                $pdo->prepare($updStSql)->execute($updStParams);

                $creditMsg = ($proration['credit'] > 0) ? ' A credit of ' . formatMoney($proration['credit']) . ' has been applied to your account.' : '';
                $portal_message = showAlert('Plan change approved! Your membership has been updated.' . $creditMsg, 'success');
            }
        }
    }

    if (isset($_POST['decline_plan_change'])) {
        $changeId = (int) ($_POST['change_id'] ?? 0);
        $decParams = [$changeId, $studentId];
        $decSql = "UPDATE pending_plan_changes SET status = 'rejected', resolved_at = NOW() WHERE id = ? AND student_id = ? AND status = 'pending'" . school_where();
        school_param($decParams);
        $pdo->prepare($decSql)->execute($decParams);
        $portal_message = showAlert('Plan change declined.', 'info');
    }
}

// Fetch pending plan changes for this student
$studentPendingChanges = [];
try {
    $spcParams = [$studentId];
    $spcStmt = $pdo->prepare("
        SELECT pc.*, mp_new.name as new_plan_name, mp_new.price as new_plan_price,
               mp_new.duration_months as new_duration, mp_new.billing_frequency as new_billing_frequency,
               mp_old.name as old_plan_name, u.full_name as requested_by_name
        FROM pending_plan_changes pc
        JOIN membership_plans mp_new ON pc.new_plan_id = mp_new.id
        LEFT JOIN membership_plans mp_old ON pc.old_plan_id = mp_old.id
        LEFT JOIN users u ON pc.requested_by = u.id
        WHERE pc.student_id = ? AND pc.status = 'pending' AND pc.expires_at > NOW()" . school_where('pc') . "
        ORDER BY pc.created_at DESC
    ");
    school_param($spcParams);
    $spcStmt->execute($spcParams);
    $studentPendingChanges = $spcStmt->fetchAll();
} catch (PDOException $e) {}

// Fetch enrolled classes
$classes = [];
try {
    $classesSql = "SELECT c.* FROM classes c
         JOIN class_enrollments ce ON ce.class_id = c.id
         WHERE ce.student_id = :sid AND ce.status = 'active' AND c.status = 'active'";
    if (!is_viewing_all_schools()) { $classesSql .= ' AND c.school_id = :school_id'; }
    $classesSql .= " ORDER BY FIELD(c.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), c.start_time";
    $classesStmt = $pdo->prepare($classesSql);
    $classesStmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    if (!is_viewing_all_schools()) { $classesStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
    $classesStmt->execute();
    $classes = $classesStmt->fetchAll();
} catch (\PDOException $e) {}

// Fetch recent attendance (last 30 records)
$attendance = [];
try {
    $attendSql = 'SELECT a.attendance_date, a.status, c.name as class_name
         FROM attendance a
         JOIN classes c ON c.id = a.class_id
         WHERE a.student_id = :sid';
    if (!is_viewing_all_schools()) { $attendSql .= ' AND a.school_id = :school_id'; }
    $attendSql .= ' ORDER BY a.attendance_date DESC LIMIT 30';
    $attendStmt = $pdo->prepare($attendSql);
    $attendStmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    if (!is_viewing_all_schools()) { $attendStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
    $attendStmt->execute();
    $attendance = $attendStmt->fetchAll();
} catch (\PDOException $e) {}

// Attendance stats
$stats = ['present' => 0, 'absent' => 0, 'late' => 0];
try {
    $statsSql = 'SELECT status, COUNT(*) AS cnt FROM attendance WHERE student_id = :sid';
    if (!is_viewing_all_schools()) { $statsSql .= ' AND school_id = :school_id'; }
    $statsSql .= ' GROUP BY status';
    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    if (!is_viewing_all_schools()) { $statsStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
    $statsStmt->execute();
    foreach ($statsStmt->fetchAll() as $r) {
        $stats[$r['status']] = (int)$r['cnt'];
    }
} catch (\PDOException $e) {}
$totalClasses = array_sum($stats);
$attendanceRate = $totalClasses > 0 ? round(($stats['present'] / $totalClasses) * 100) : 0;

// Per-class attendance breakdown
$perClassAttendance = [];
try {
    $pcaSql = "SELECT c.id, c.name, c.day_of_week,
               COUNT(a.id) as total_records,
               SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
               SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
               SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
               ROUND(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(a.id), 0), 1) as rate
        FROM attendance a
        JOIN classes c ON c.id = a.class_id
        WHERE a.student_id = :sid";
    if (!is_viewing_all_schools()) { $pcaSql .= ' AND a.school_id = :school_id'; }
    $pcaSql .= ' GROUP BY c.id, c.name, c.day_of_week ORDER BY total_records DESC';
    $pcaStmt = $pdo->prepare($pcaSql);
    $pcaStmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    if (!is_viewing_all_schools()) { $pcaStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
    $pcaStmt->execute();
    $perClassAttendance = $pcaStmt->fetchAll();
} catch (\PDOException $e) {}

// Attendance streak (consecutive present/late records, counting backwards)
$currentStreak = 0;
try {
    $streakSql = "SELECT a.status FROM attendance a
        WHERE a.student_id = :sid AND a.status IN ('present','absent','late')";
    if (!is_viewing_all_schools()) { $streakSql .= ' AND a.school_id = :school_id'; }
    $streakSql .= ' ORDER BY a.attendance_date DESC, a.id DESC LIMIT 100';
    $streakStmt = $pdo->prepare($streakSql);
    $streakStmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    if (!is_viewing_all_schools()) { $streakStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
    $streakStmt->execute();
    foreach ($streakStmt->fetchAll() as $sr) {
        if ($sr['status'] === 'present' || $sr['status'] === 'late') {
            $currentStreak++;
        } else {
            break;
        }
    }
} catch (\PDOException $e) {}

// Belt testing cycle absence summary
$absenceSummary = null;
try {
    require_once __DIR__ . '/includes/belt_cycle.php';
    $absenceSummary = get_student_absence_summary($studentId);
} catch (\Exception $e) {}

// Upcoming events: registered events + calendar-only events visible to all students
$upcomingEvents = [];
try {
    $evSql = "SELECT e.id, e.name, e.event_date, e.start_time, e.event_type, e.location,
                e.requires_registration, er.payment_status
         FROM events e
         LEFT JOIN event_registrations er ON er.event_id = e.id AND er.student_id = :sid1
         WHERE e.event_date >= CURDATE() AND e.status = 'upcoming'
           AND (er.student_id IS NOT NULL OR e.requires_registration = 0)";
    if (!is_viewing_all_schools()) { $evSql .= ' AND e.school_id = :school_id'; }
    $evSql .= ' ORDER BY e.event_date ASC LIMIT 5';
    $evStmt = $pdo->prepare($evSql);
    $evStmt->bindValue(':sid1', $studentId, PDO::PARAM_INT);
    if (!is_viewing_all_schools()) { $evStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
    $evStmt->execute();
    $upcomingEvents = $evStmt->fetchAll();
} catch (\PDOException $e) {}

// Current membership info
$membership = null;
try {
    $memSql = "SELECT m.*, mp.name as plan_name, mp.price as plan_price, mp.duration_months, mp.billing_frequency
         FROM memberships m
         JOIN membership_plans mp ON mp.id = m.plan_id
         WHERE m.student_id = :sid AND m.status = 'active' AND m.end_date >= CURDATE()";
    if (!is_viewing_all_schools()) { $memSql .= ' AND m.school_id = :school_id'; }
    $memSql .= ' ORDER BY m.end_date DESC LIMIT 1';
    $memStmt = $pdo->prepare($memSql);
    $memStmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    if (!is_viewing_all_schools()) { $memStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
    $memStmt->execute();
    $membership = $memStmt->fetch();
} catch (\PDOException $e) {}

// Belt history — ordered by rank_order DESC so the highest achieved belt is first
$beltHistory = [];
try {
    $beltSql = "SELECT b.name as belt_name, b.color, b.rank_order, mas.name as style_name,
                sb.awarded_date, sb.black_belt_number
         FROM student_belts sb
         JOIN belts b ON b.id = sb.belt_id
         JOIN martial_arts_styles mas ON mas.id = sb.style_id
         WHERE sb.student_id = :sid";
    if (!is_viewing_all_schools()) { $beltSql .= ' AND sb.school_id = :school_id'; }
    $beltSql .= ' ORDER BY b.rank_order DESC, sb.awarded_date DESC LIMIT 10';
    $beltStmt = $pdo->prepare($beltSql);
    $beltStmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    if (!is_viewing_all_schools()) { $beltStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
    $beltStmt->execute();
    $beltHistory = $beltStmt->fetchAll();
} catch (\PDOException $e) {}

// Belt color map for visual rendering
$colorMap = [
    'White' => '#FFFFFF', 'Yellow' => '#FFD700', 'Orange' => '#FF8C00',
    'Green' => '#228B22', 'Blue' => '#0000CD', 'Purple' => '#800080',
    'Brown' => '#8B4513', 'Red' => '#DC143C', 'Black' => '#000000',
    'Brown-Red' => '#8B4513', 'Black-Red' => '#000000',
    'Black-White Stripe' => '#000000', 'Black-Blue Stripe' => '#000000',
    'Black-Red Stripe' => '#000000',
    'White-Yellow Stripe' => '#FFFFFF', 'White-Orange Stripe' => '#FFFFFF',
    'White-Green Stripe' => '#FFFFFF', 'White-Blue Stripe' => '#FFFFFF',
    'White-Purple Stripe' => '#FFFFFF', 'White-Brown Stripe' => '#FFFFFF',
    'White-Red Stripe' => '#FFFFFF',
    'Camouflage-Yellow Stripe' => '#4B5320', 'Camouflage-Orange Stripe' => '#4B5320',
    'Camouflage-Green Stripe' => '#4B5320', 'Camouflage-Blue Stripe' => '#4B5320',
    'Camouflage-Purple Stripe' => '#4B5320', 'Camouflage-Brown Stripe' => '#4B5320',
    'Camouflage-Red Stripe' => '#4B5320',
    'Camouflage' => '#4B5320'
];

/** Return CSS background style for a belt color (gradient for compound colors). */
if (!function_exists('beltBackground')) {
    function beltBackground(string $color, array $colorMap): string
    {
        $stripeColors = [
            'Yellow' => '#FFD700', 'Orange' => '#FF8C00', 'Green' => '#228B22',
            'Blue' => '#0000CD', 'Purple' => '#800080', 'Brown' => '#8B4513',
            'Red' => '#DC143C', 'White' => '#FFFFFF',
        ];
        $camoBg = 'linear-gradient(135deg, #4B5320 0%, #6B8E23 25%, #556B2F 50%, #4B5320 75%, #6B8E23 100%)';

        $splits = [
            'Brown-Red' => 'linear-gradient(135deg, #8B4513 50%, #DC143C 50%)',
            'Black-Red' => 'linear-gradient(135deg, #000000 50%, #DC143C 50%)',
        ];
        if (isset($splits[$color])) {
            return 'background: ' . $splits[$color] . ';';
        }

        if (preg_match('/^(Black|White|Camouflage)-(\w+) Stripe$/', $color, $m)) {
            $baseName = $m[1];
            $stripeName = $m[2];
            $stripeHex = $stripeColors[$stripeName] ?? '#999';

            if ($baseName === 'Camouflage') {
                return 'background: linear-gradient(180deg, transparent 40%, ' . $stripeHex . ' 40%, ' . $stripeHex . ' 60%, transparent 60%), ' . $camoBg . ';';
            }
            $baseHex = ($baseName === 'Black') ? '#000' : '#fff';
            return 'background: linear-gradient(180deg, ' . $baseHex . ' 40%, ' . $stripeHex . ' 40%, ' . $stripeHex . ' 60%, ' . $baseHex . ' 60%);';
        }

        if ($color === 'Camouflage') {
            return 'background: ' . $camoBg . ';';
        }

        return 'background-color: ' . ($colorMap[$color] ?? '#6B7280') . ';';
    }
}

// Training resources: include student's belt ranks AND all lower ranks per style
$trainingResources = [];
try {
    // Determine highest rank per style for this student
    $hpSql = "SELECT sb.style_id, MAX(b.rank_order) as max_rank
         FROM student_belts sb
         JOIN belts b ON b.id = sb.belt_id
         WHERE sb.student_id = :sid";
    if (!is_viewing_all_schools()) { $hpSql .= ' AND sb.school_id = :school_id'; }
    $hpSql .= ' GROUP BY sb.style_id';
    $hpStmt = $pdo->prepare($hpSql);
    $hpStmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    if (!is_viewing_all_schools()) { $hpStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
    $hpStmt->execute();
    $hpRows = $hpStmt->fetchAll();

    if (!empty($hpRows)) {
        $trConditions = [];
        $trParams     = [];
        $idx = 0;
        foreach ($hpRows as $hp) {
            $trConditions[] = "(b.style_id = :ts{$idx} AND b.rank_order <= :tr{$idx})";
            $trParams[":ts{$idx}"] = $hp['style_id'];
            $trParams[":tr{$idx}"] = $hp['max_rank'];
            $idx++;
        }
        $trWhere = implode(' OR ', $trConditions);

        $trSql = "SELECT br.*, b.name as belt_name, b.color as belt_color, mas.name as style_name
             FROM belt_resources br
             JOIN belts b ON b.id = br.belt_id
             JOIN martial_arts_styles mas ON mas.id = br.style_id
             WHERE ({$trWhere})";
        if (!is_viewing_all_schools()) {
            $trSql .= ' AND br.school_id = :school_id';
            $trParams[':school_id'] = current_school_id();
        }
        $trSql .= ' ORDER BY b.rank_order DESC, br.sort_order ASC, br.created_at DESC LIMIT 20';
        $trStmt = $pdo->prepare($trSql);
        $trStmt->execute($trParams);
        $trainingResources = $trStmt->fetchAll();
    }
} catch (\PDOException $e) {}

// Get student credit balance
$studentCredit = get_student_credit($studentId);

// Get recent credit ledger entries
$creditHistory = [];
try {
    $clSql = "SELECT * FROM credit_ledger WHERE student_id = :sid";
    if (!is_viewing_all_schools()) { $clSql .= ' AND school_id = :school_id'; }
    $clSql .= ' ORDER BY created_at DESC LIMIT 5';
    $clStmt = $pdo->prepare($clSql);
    $clStmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    if (!is_viewing_all_schools()) { $clStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT); }
    $clStmt->execute();
    $creditHistory = $clStmt->fetchAll();
} catch (\PDOException $e) {}

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">

    <?php echo $portal_message; ?>

    <?php if (is_student_payment_locked()): ?>
        <div class="bg-red-50 border-2 border-red-400 rounded-lg p-6 mb-6">
            <div class="flex items-start gap-4">
                <span class="text-4xl flex-shrink-0">&#9888;&#65039;</span>
                <div class="flex-1">
                    <h2 class="text-xl font-bold text-red-800 mb-2">Account Restricted &mdash; Payment Issue</h2>
                    <p class="text-red-700 mb-3">
                        Your account access has been limited due to a payment issue with your membership.
                        Please update your payment method to restore full access to the student portal.
                    </p>
                    <p class="text-sm text-red-600 mb-4">
                        While your account is restricted, you can only access your Dashboard, Payment Methods, and Profile.
                        All other features (events, training, messages, certificates, membership changes) are unavailable until payment is resolved.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <a href="student_payment.php"
                           class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg font-medium text-sm inline-block">
                            Update Payment Method
                        </a>
                        <a href="student_profile.php"
                           class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-2 rounded-lg font-medium text-sm inline-block">
                            View Profile
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success']) && $_GET['success'] === 'plan_change'): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
            Plan change approved and payment processed! Your membership has been updated.
        </div>
    <?php endif; ?>

    <!-- Pending Plan Changes from Admin -->
    <?php if (!empty($studentPendingChanges)): ?>
        <?php foreach ($studentPendingChanges as $spc): ?>
            <?php
            $spcIsMonthly = (isset($spc['new_billing_frequency']) && $spc['new_billing_frequency'] === 'monthly' && $spc['new_duration'] > 1);
            $spcMonthlyAmt = $spcIsMonthly ? round($spc['new_plan_price'] / $spc['new_duration'], 2) : 0;
            $spcDisplayPrice = $spcIsMonthly
                ? formatMoney($spcMonthlyAmt) . '/mo (' . formatMoney($spc['new_plan_price']) . ' total)'
                : formatMoney($spc['new_plan_price']);
            ?>
            <div class="bg-orange-50 border-2 border-orange-300 rounded-lg p-6 mb-6">
                <div class="flex items-start gap-4">
                    <span class="text-3xl flex-shrink-0">&#128232;</span>
                    <div class="flex-1">
                        <h3 class="text-lg font-bold text-orange-800 mb-1">Membership Change Proposed</h3>
                        <p class="text-sm text-gray-700 mb-3">
                            <?php echo htmlspecialchars($spc['requested_by_name'] ?? 'Studio Admin'); ?> has proposed changing your membership:
                        </p>

                        <div class="bg-white rounded-lg p-4 mb-3 flex items-center justify-between gap-4">
                            <div class="text-center">
                                <p class="text-xs text-gray-500">Current Plan</p>
                                <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($spc['old_plan_name'] ?? 'No Plan'); ?></p>
                            </div>
                            <span class="text-2xl text-gray-400">&#8594;</span>
                            <div class="text-center">
                                <p class="text-xs text-gray-500">New Plan</p>
                                <p class="font-semibold text-blue-700"><?php echo htmlspecialchars($spc['new_plan_name']); ?></p>
                                <p class="text-xs text-gray-500"><?php echo $spcDisplayPrice; ?></p>
                            </div>
                        </div>

                        <?php if ($spc['proration_type'] === 'upgrade'): ?>
                            <p class="text-sm text-gray-700 mb-2">
                                Pro-rated upgrade cost: <strong class="text-green-700"><?php echo formatMoney($spc['proration_amount']); ?></strong>
                                <span class="text-xs text-gray-500">(recalculated at approval)</span>
                            </p>
                        <?php elseif ($spc['proration_type'] === 'downgrade'): ?>
                            <p class="text-sm text-gray-700 mb-2">
                                Downgrade credit: <strong class="text-green-700"><?php echo formatMoney($spc['proration_credit']); ?></strong>
                                <span class="text-xs text-gray-500">(applied to your account)</span>
                            </p>
                        <?php endif; ?>

                        <?php if ($spc['notes']): ?>
                            <p class="text-xs text-gray-500 mb-3 italic">"<?php echo htmlspecialchars($spc['notes']); ?>"</p>
                        <?php endif; ?>

                        <p class="text-xs text-gray-500 mb-3">Expires: <?php echo date('M j, Y \a\t g:i A', strtotime($spc['expires_at'])); ?></p>

                        <div class="flex gap-3">
                            <form method="POST" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="approve_plan_change" value="1">
                                <input type="hidden" name="change_id" value="<?php echo $spc['id']; ?>">
                                <?php if ($spc['proration_type'] === 'upgrade'): ?>
                                    <button type="submit"
                                            class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-medium text-sm">
                                        &#10003; Review &amp; Pay
                                    </button>
                                <?php else: ?>
                                    <button type="submit" onclick="return confirm('Approve this plan change?')"
                                            class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-medium text-sm">
                                        &#10003; Approve
                                    </button>
                                <?php endif; ?>
                            </form>
                            <form method="POST" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="decline_plan_change" value="1">
                                <input type="hidden" name="change_id" value="<?php echo $spc['id']; ?>">
                                <button type="submit" onclick="return confirm('Decline this plan change?')"
                                        class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-2 rounded-lg font-medium text-sm">
                                    &#10007; Decline
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Parent Account: My Children Section -->
    <?php if (!empty($_SESSION['is_parent'])): ?>
        <?php
        $myChildren = get_parent_children((int)$_SESSION['student_id']);
        ?>
        <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-blue-50 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="text-xl">👨‍👩‍👧‍👦</span>
                    <h2 class="text-lg font-semibold text-blue-800">My Children</h2>
                </div>
                <div class="flex items-center gap-3">
                    <a href="parent_portal.php" class="text-sm text-blue-600 hover:underline font-medium">Family Dashboard</a>
                    <button onclick="document.getElementById('linkChildModal').classList.remove('hidden')"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium">
                        + Add Child
                    </button>
                </div>
            </div>

            <?php if (empty($myChildren)): ?>
                <div class="p-8 text-center">
                    <div class="text-5xl mb-3">👶</div>
                    <h3 class="text-lg font-bold text-gray-800 mb-2">No Children Linked Yet</h3>
                    <p class="text-sm text-gray-600 mb-4">Link your children's student accounts to manage their classes, memberships, and events from here.</p>
                    <button onclick="document.getElementById('linkChildModal').classList.remove('hidden')"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg text-sm font-medium">
                        Link a Student Account
                    </button>
                </div>
            <?php else: ?>
                <div class="p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($myChildren as $mc): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition">
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center font-bold text-blue-700 text-sm flex-shrink-0">
                                        <?= strtoupper(substr($mc['first_name'], 0, 1) . substr($mc['last_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <a href="parent_child.php?id=<?= $mc['id'] ?>" class="font-semibold text-gray-800 hover:text-blue-600">
                                            <?= htmlspecialchars($mc['first_name'] . ' ' . $mc['last_name']) ?>
                                        </a>
                                        <div class="flex items-center gap-2 text-xs text-gray-500">
                                            <span>🥋 <?= htmlspecialchars($mc['current_belt']) ?></span>
                                            <?php if ($mc['plan_name']): ?>
                                                <span>&bull; <?= htmlspecialchars($mc['plan_name']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="grid grid-cols-3 gap-1">
                                    <a href="parent_child.php?id=<?= $mc['id'] ?>" class="text-center bg-blue-50 hover:bg-blue-100 text-blue-700 py-1.5 rounded text-xs font-medium transition">
                                        Overview
                                    </a>
                                    <a href="parent_child_training.php?id=<?= $mc['id'] ?>" class="text-center bg-purple-50 hover:bg-purple-100 text-purple-700 py-1.5 rounded text-xs font-medium transition">
                                        Training
                                    </a>
                                    <a href="parent_child_membership.php?id=<?= $mc['id'] ?>" class="text-center bg-green-50 hover:bg-green-100 text-green-700 py-1.5 rounded text-xs font-medium transition">
                                        Plan
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Link Child Modal -->
        <div id="linkChildModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-bold text-gray-800">Add Child to Your Account</h3>
                    <button onclick="document.getElementById('linkChildModal').classList.add('hidden')"
                            class="text-gray-600 hover:text-gray-800">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <p class="text-sm text-gray-600 mb-4">
                    Enter your child's student username or email to link them to your account.
                    They must already have an account registered at the studio.
                </p>

                <form method="POST" action="parent_portal.php" class="space-y-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="link_student" value="1">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Student Username or Email *</label>
                        <input type="text" name="student_identifier" required
                               placeholder="e.g., john_doe or john@email.com"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Relationship</label>
                        <select name="relationship"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            <option value="parent">Parent</option>
                            <option value="guardian">Guardian</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="document.getElementById('linkChildModal').classList.add('hidden')"
                                class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                            Link Child
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Account Credit Balance -->
    <?php if ($studentCredit > 0): ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="text-3xl">&#128176;</span>
                    <div>
                        <p class="font-semibold text-green-800">Account Credit Balance</p>
                        <p class="text-sm text-green-600">This credit will be automatically applied to your next payment.</p>
                    </div>
                </div>
                <span class="text-3xl font-bold text-green-700"><?= formatMoney($studentCredit) ?></span>
            </div>
            <?php if (!empty($creditHistory)): ?>
                <div class="mt-3 pt-3 border-t border-green-200">
                    <p class="text-xs font-semibold text-green-700 mb-2">Recent Credit Activity:</p>
                    <div class="space-y-1">
                        <?php foreach (array_slice($creditHistory, 0, 3) as $entry): ?>
                            <div class="flex justify-between text-xs">
                                <span class="text-gray-600"><?= htmlspecialchars($entry['description']) ?></span>
                                <span class="font-semibold <?= $entry['amount'] > 0 ? 'text-green-700' : 'text-red-600' ?>">
                                    <?= $entry['amount'] > 0 ? '+' : '' ?><?= formatMoney($entry['amount']) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Profile & Membership Row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Profile Summary -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">My Profile</h2>
                <a href="student_profile.php" class="text-sm text-blue-600 hover:underline">Edit Profile</a>
            </div>
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
                    <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full <?= ($student['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                        <?= ucfirst($student['status'] ?? 'active') ?>
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
                <?php
                $memIsMonthly = (isset($membership['billing_frequency']) && $membership['billing_frequency'] === 'monthly' && $membership['duration_months'] > 1);
                $memMonthlyAmt = $memIsMonthly ? round($membership['plan_price'] / $membership['duration_months'], 2) : 0;
                ?>
                <div class="space-y-3">
                    <div>
                        <span class="text-sm text-gray-500">Plan</span>
                        <p class="font-medium text-gray-800"><?= htmlspecialchars($membership['plan_name']) ?></p>
                    </div>
                    <div>
                        <span class="text-sm text-gray-500">Valid Until</span>
                        <p class="font-medium text-gray-800"><?= formatDate($membership['end_date']) ?></p>
                    </div>
                    <?php if ($memIsMonthly): ?>
                        <div>
                            <span class="text-sm text-gray-500">Billing</span>
                            <p class="font-medium text-gray-800"><?= formatMoney($memMonthlyAmt) ?>/mo</p>
                            <?php if (isset($membership['billing_day'])): ?>
                                <p class="text-xs text-gray-500">Next billing: Day <?= $membership['billing_day'] ?> of each month</p>
                            <?php endif; ?>
                            <?php if (isset($membership['monthly_charges_made'])): ?>
                                <p class="text-xs text-gray-500">Installment <?= $membership['monthly_charges_made'] ?>/<?= $membership['duration_months'] ?> paid</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
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
            <div class="grid grid-cols-4 gap-4 text-center">
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
                <div>
                    <p class="text-2xl font-bold text-orange-500"><?= $currentStreak ?></p>
                    <p class="text-xs text-gray-500">Streak</p>
                </div>
            </div>
            <?php if ($absenceSummary): ?>
            <div class="mt-4 pt-4 border-t border-gray-200">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-600">Testing Cycle Absences</span>
                    <span class="text-sm font-semibold <?= $absenceSummary['over_threshold'] ? 'text-red-600' : 'text-gray-800' ?>">
                        <?= $absenceSummary['net_absences'] ?> / <?= $absenceSummary['threshold'] ?>
                    </span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <?php $absPct = $absenceSummary['threshold'] > 0 ? min(100, ($absenceSummary['net_absences'] / $absenceSummary['threshold']) * 100) : 0; ?>
                    <div class="h-2 rounded-full <?= $absPct >= 100 ? 'bg-red-500' : ($absPct >= 66 ? 'bg-yellow-500' : 'bg-green-500') ?>"
                         style="width: <?= $absPct ?>%;"></div>
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    Cycle: <?= date('M j', strtotime($absenceSummary['cycle_start'])) ?> &ndash; <?= date('M j, Y', strtotime($absenceSummary['cycle_end'])) ?>
                    <?php if ($absenceSummary['makeups'] > 0): ?>
                        &middot; <?= $absenceSummary['makeups'] ?> make-up(s) completed
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
            <?php if (!empty($beltHistory)):
                $highestBelt = $beltHistory[0]; // Highest by rank_order DESC
                $isBlackBelt = str_starts_with(strtolower($highestBelt['color'] ?? ''), 'black');
            ?>
                <div class="mt-4 pt-4 border-t border-gray-200">
                    <span class="text-sm text-gray-500">Highest Belt Rank</span>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="inline-block w-4 h-4 rounded-full border border-gray-300" style="<?= beltBackground($highestBelt['color'] ?? '', $colorMap) ?>"></span>
                        <p class="font-medium text-gray-800">
                            <?= htmlspecialchars($highestBelt['belt_name']) ?>
                            <span class="text-xs text-gray-500">(<?= htmlspecialchars($highestBelt['style_name']) ?>)</span>
                        </p>
                    </div>
                    <?php if ($isBlackBelt && !empty($highestBelt['black_belt_number'])): ?>
                        <div class="mt-2 inline-flex items-center gap-1 bg-gray-900 text-yellow-400 text-xs font-bold px-3 py-1 rounded-full">
                            <span>&#127941;</span> Black Belt #<?= htmlspecialchars($highestBelt['black_belt_number']) ?>
                        </div>
                    <?php endif; ?>
                    <div class="mt-2">
                        <a href="student_certificate.php" class="text-xs text-blue-600 hover:underline">View Certificate &rarr;</a>
                    </div>
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
                    <?php foreach ($upcomingEvents as $ev):
                        $isInfoOnly = empty($ev['requires_registration']);
                        $evLink = $isInfoOnly ? 'student_events.php' : 'student_event_register.php?event_id=' . $ev['id'];
                    ?>
                        <a href="<?= $evLink ?>" class="block px-6 py-4 hover:bg-blue-50 transition-colors group">
                            <div class="flex items-center justify-between">
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-gray-800 group-hover:text-blue-700 transition-colors"><?= htmlspecialchars($ev['name']) ?></p>
                                    <p class="text-sm text-gray-500">
                                        <?= formatDate($ev['event_date']) ?>
                                        <?php if (!empty($ev['start_time'])): ?>
                                            at <?= date('g:i A', strtotime($ev['start_time'])) ?>
                                        <?php endif; ?>
                                        <?php if (!empty($ev['location'])): ?>
                                            &bull; <?= htmlspecialchars($ev['location']) ?>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($ev['event_type'])): ?>
                                        <p class="text-xs text-gray-400 mt-1 capitalize"><?= str_replace('_', ' ', $ev['event_type']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center gap-2 ml-4 flex-shrink-0">
                                    <?php if ($isInfoOnly): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">
                                            Info Only
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $ev['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                            <?= ucfirst($ev['payment_status'] ?? 'registered') ?>
                                        </span>
                                    <?php endif; ?>
                                    <svg class="w-4 h-4 text-gray-400 group-hover:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Attendance by Class -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800">Attendance by Class</h2>
            </div>

            <?php if ($absenceSummary && $absenceSummary['over_threshold']): ?>
            <div class="mx-6 mt-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                <p class="text-sm text-red-800 font-medium">
                    You have <?= $absenceSummary['net_absences'] ?> unexcused absence(s) this testing cycle.
                    Please arrange make-up classes to maintain your testing eligibility.
                </p>
            </div>
            <?php endif; ?>

            <?php if (!empty($perClassAttendance)): ?>
            <div class="p-6 space-y-3">
                <?php foreach ($perClassAttendance as $pca):
                    $pcRate = (float) ($pca['rate'] ?? 0);
                    $rateColor = $pcRate >= 80 ? 'text-green-700' : ($pcRate >= 60 ? 'text-yellow-700' : 'text-red-700');
                    $barColor = $pcRate >= 80 ? 'bg-green-500' : ($pcRate >= 60 ? 'bg-yellow-500' : 'bg-red-500');
                ?>
                <div class="flex items-center gap-4 p-3 bg-gray-50 rounded-lg">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-800 truncate"><?= htmlspecialchars($pca['name']) ?></p>
                        <p class="text-xs text-gray-500"><?= $pca['day_of_week'] ?> &middot; <?= $pca['total_records'] ?> sessions</p>
                    </div>
                    <div class="flex items-center gap-1 text-xs">
                        <span class="text-green-700 font-semibold"><?= $pca['present_count'] ?>P</span>
                        <span class="text-gray-400">/</span>
                        <span class="text-red-600 font-semibold"><?= $pca['absent_count'] ?>A</span>
                        <span class="text-gray-400">/</span>
                        <span class="text-yellow-600 font-semibold"><?= $pca['late_count'] ?>L</span>
                    </div>
                    <div class="flex items-center gap-2 w-32">
                        <div class="flex-1 bg-gray-200 rounded-full h-2">
                            <div class="h-2 rounded-full <?= $barColor ?>" style="width: <?= $pcRate ?>%;"></div>
                        </div>
                        <span class="text-xs font-bold <?= $rateColor ?> w-10 text-right"><?= number_format($pcRate, 0) ?>%</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Recent Records -->
            <div class="px-6 py-3 border-t border-gray-200">
                <h3 class="text-sm font-semibold text-gray-700 mb-2">Recent Records</h3>
            </div>
            <?php if (empty($attendance)): ?>
                <div class="p-6 text-center">
                    <p class="text-gray-500 text-sm">No attendance records yet.</p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach (array_slice($attendance, 0, 20) as $a): ?>
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

    <!-- Training Resources Preview -->
    <?php if (!empty($trainingResources)): ?>
    <div class="mt-8 bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-800">Training Resources</h2>
            <a href="student_training.php" class="text-sm text-blue-600 hover:underline">View All</a>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach (array_slice($trainingResources, 0, 4) as $res): ?>
                    <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                        <?php if ($res['resource_type'] === 'document'): ?>
                            <span class="text-2xl">&#128196;</span>
                        <?php else: ?>
                            <span class="text-2xl">&#127909;</span>
                        <?php endif; ?>
                        <div>
                            <p class="font-medium text-gray-800 text-sm"><?= htmlspecialchars($res['title']) ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($res['belt_name']) ?> &bull; <?= htmlspecialchars($res['style_name']) ?></p>
                            <?php if ($res['resource_type'] === 'document' && $res['file_path']): ?>
                                <a href="<?= htmlspecialchars($res['file_path']) ?>" target="_blank" class="text-xs text-blue-600 hover:underline mt-1 inline-block">Download</a>
                            <?php elseif ($res['resource_type'] === 'video' && $res['video_url']): ?>
                                <a href="student_training.php" class="text-xs text-blue-600 hover:underline mt-1 inline-block">Watch Video</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($trainingResources) > 4): ?>
                <div class="mt-3 text-center">
                    <a href="student_training.php" class="text-sm text-blue-600 hover:underline">View all <?= count($trainingResources) ?> resources &rarr;</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Family Account Info (shown only for child-students, not parent-students) -->
    <?php if (empty($_SESSION['is_parent'])):
        $portalParents = get_student_parents($studentId);
        if (!empty($portalParents)):
    ?>
    <div class="mt-8 bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-blue-50">
            <div class="flex items-center gap-2">
                <span class="text-xl">👨‍👩‍👧‍👦</span>
                <h2 class="text-lg font-semibold text-blue-800">Family Account</h2>
            </div>
        </div>
        <div class="p-6">
            <p class="text-sm text-gray-600 mb-4">Your account is linked to a parent/family account. Payment methods and event registrations may be managed by your parent or guardian.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($portalParents as $pp): ?>
                    <div class="flex items-center gap-3 bg-blue-50 rounded-lg p-4">
                        <div class="w-10 h-10 bg-blue-200 rounded-full flex items-center justify-center font-bold text-blue-700 text-sm flex-shrink-0">
                            <?= strtoupper(substr($pp['first_name'], 0, 1) . substr($pp['last_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($pp['first_name'] . ' ' . $pp['last_name']) ?></p>
                            <p class="text-xs text-gray-500 capitalize"><?= htmlspecialchars($pp['relationship']) ?><?php if ($pp['email']): ?> &bull; <?= htmlspecialchars($pp['email']) ?><?php endif; ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; endif; ?>

    <!-- Quick Links -->
    <div class="mt-8 bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Quick Links</h2>
        <div class="grid grid-cols-2 md:grid-cols-<?= !empty($_SESSION['is_parent']) ? '5' : '4' ?> gap-4">
            <?php if (!empty($_SESSION['is_parent'])): ?>
                <a href="parent_portal.php" class="flex flex-col items-center p-4 bg-blue-50 rounded-lg hover:bg-blue-100 transition">
                    <span class="text-2xl mb-2">👨‍👩‍👧‍👦</span>
                    <span class="text-sm font-medium text-gray-700">My Children</span>
                </a>
            <?php endif; ?>
            <a href="student_profile.php" class="flex flex-col items-center p-4 bg-blue-50 rounded-lg hover:bg-blue-100 transition">
                <span class="text-2xl mb-2">&#9998;</span>
                <span class="text-sm font-medium text-gray-700">Edit Profile</span>
            </a>
            <a href="student_payment.php" class="flex flex-col items-center p-4 bg-green-50 rounded-lg hover:bg-green-100 transition">
                <span class="text-2xl mb-2">&#128179;</span>
                <span class="text-sm font-medium text-gray-700">Payment Methods</span>
            </a>
            <a href="student_events.php" class="flex flex-col items-center p-4 bg-purple-50 rounded-lg hover:bg-purple-100 transition">
                <span class="text-2xl mb-2">&#127942;</span>
                <span class="text-sm font-medium text-gray-700">Browse Events</span>
            </a>
            <a href="student_training.php" class="flex flex-col items-center p-4 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition">
                <span class="text-2xl mb-2">&#127909;</span>
                <span class="text-sm font-medium text-gray-700">Training</span>
            </a>
        </div>
    </div>
</div>

<?php include 'includes/student_footer.php'; ?>
