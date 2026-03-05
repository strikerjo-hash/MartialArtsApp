<?php
/**
 * parent_child_membership.php — Parent view/management of a child's membership
 *
 * Mirrors student_upgrade.php but with parent auth and parent header.
 * Shows current membership, available plans, proration info,
 * and allows the parent to change the child's plan.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/parent_auth.php';
require_once __DIR__ . '/includes/payment_gateway.php';

require_parent();

$parentId = get_effective_parent_id();
$childId  = (int)($_GET['id'] ?? 0);
$pdo      = get_db();

// Verify this child belongs to this parent
$child = parent_verify_child($parentId, $childId);

// Fetch all children for the child switcher
$children = get_parent_children($parentId);

$message = '';

// Get child's current membership
$params = [$childId];
school_param($params);
$current_membership = $pdo->prepare("
    SELECT m.*, mp.name as plan_name, mp.price as plan_price, mp.duration_months,
           mp.billing_frequency, mp.classes_per_week, mp.description as plan_description
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.student_id = ? AND m.status = 'active' AND m.end_date >= CURDATE()" . school_where('m') . "
    ORDER BY m.end_date DESC
    LIMIT 1
");
$current_membership->execute($params);
$current_membership = $current_membership->fetch() ?: null;

// Get available plans
$apParams = [];
school_param($apParams);
$apStmt = $pdo->prepare("SELECT * FROM membership_plans WHERE status = 'active' AND (is_grandfathered = 0 OR is_grandfathered IS NULL)" . school_where() . " ORDER BY price ASC");
$apStmt->execute($apParams);
$available_plans = $apStmt->fetchAll();

// --- Plan change lockout check ---
$is_locked_out = false;
$lockout_until = null;
$has_pending_change = false;

$lockoutParams = [$childId];
school_param($lockoutParams);
$lockoutStmt = $pdo->prepare("SELECT last_plan_change FROM students WHERE id = ?" . school_where());
$lockoutStmt->execute($lockoutParams);
$lockoutRow = $lockoutStmt->fetch();
if (!empty($lockoutRow['last_plan_change'])) {
    $lastChange = new DateTime($lockoutRow['last_plan_change']);
    $lockout_until = (clone $lastChange)->modify('+30 days');
    if (new DateTime() < $lockout_until) {
        $is_locked_out = true;
    }
}

// Check for pending plan changes (fetch full details)
$pending_change_detail = null;
$has_pending_change = false;
try {
    $pendingParams = [$childId];
    school_param($pendingParams);
    $pendingStmt = $pdo->prepare("
        SELECT pc.*, mp.name as new_plan_name, mp.price as new_plan_price, mp.duration_months as new_duration,
               mp.billing_frequency as new_billing_frequency, mp.classes_per_week as new_classes_per_week,
               mp_old.name as old_plan_name, u.full_name as requested_by_name
        FROM pending_plan_changes pc
        JOIN membership_plans mp ON pc.new_plan_id = mp.id
        LEFT JOIN membership_plans mp_old ON pc.old_plan_id = mp_old.id
        LEFT JOIN users u ON pc.requested_by = u.id
        WHERE pc.student_id = ? AND pc.status = 'pending' AND pc.expires_at > NOW()" . school_where('pc') . "
        ORDER BY pc.created_at DESC LIMIT 1
    ");
    $pendingStmt->execute($pendingParams);
    $pending_change_detail = $pendingStmt->fetch() ?: null;
    $has_pending_change = ($pending_change_detail !== null);
} catch (PDOException $e) {}

// Handle upgrade/downgrade request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upgrade'])) {
    verify_csrf();

    $new_plan_id = (int)$_POST['new_plan_id'];

    // Get new plan details first (needed to check if lockout bypass applies)
    $npParams = [$new_plan_id];
    school_param($npParams);
    $new_plan = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?" . school_where());
    $new_plan->execute($npParams);
    $new_plan = $new_plan->fetch();

    // Afterschool, camp, and tax-deductible plans bypass the 30-day lockout
    $bypass_lockout = $new_plan && (!empty($new_plan['is_afterschool']) || !empty($new_plan['is_camp']) || !empty($new_plan['tax_deductible']));

    if ($is_locked_out && !$bypass_lockout) {
        $message = showAlert('Plan changes are limited to once every 30 days. Next change available on ' . $lockout_until->format('M j, Y') . '.', 'error');
    } elseif ($has_pending_change) {
        $message = showAlert('There is a pending plan change for this student. Please wait for it to be resolved before requesting another change.', 'error');
    } else {

        if ($new_plan) {
            $is_program = (!empty($new_plan['is_afterschool']) || !empty($new_plan['is_camp']));

            if ($is_program) {
                // Camp/afterschool: charge full price, keep existing membership
                $programAmount = (float)$new_plan['price'];

                $_SESSION['upgrade_plan_id'] = $new_plan_id;
                $_SESSION['upgrade_proration'] = [
                    'amount' => $programAmount,
                    'credit' => 0,
                    'type' => 'program_signup',
                    'days_remaining' => 0,
                    'unused_value' => 0,
                    'new_cost' => $programAmount,
                    'is_monthly' => ($new_plan['billing_frequency'] ?? 'upfront') === 'monthly',
                    'is_program' => true,
                ];
                $_SESSION['upgrade_child_id'] = $childId;

                if ($programAmount > 0) {
                    header('Location: parent_child_membership_payment.php?id=' . $childId);
                    exit;
                } else {
                    // Free program - enroll immediately without cancelling existing membership
                    $start_date = date('Y-m-d');
                    $end_date = $new_plan['program_end_date'];
                    $isMonthlyNewPlan = (isset($new_plan['billing_frequency']) && $new_plan['billing_frequency'] === 'monthly' && $new_plan['duration_months'] > 1);
                    $billing_day = $isMonthlyNewPlan ? min((int)date('j'), 28) : null;

                    $stmt = $pdo->prepare("
                        INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date, status, payment_status, amount_paid, auto_renew, billing_day, monthly_charges_made)
                        VALUES (?, ?, ?, ?, ?, 'active', 'paid', 0, 0, ?, 0)
                    ");
                    $stmt->execute([current_school_id(), $childId, $new_plan_id, $start_date, $end_date, $billing_day]);

                    header('Location: parent_child_membership.php?id=' . $childId . '&success=program');
                    exit;
                }
            } else {
                // Regular plan: calculate proration, switch memberships
                $proration = calculateProration($current_membership, $new_plan);

                if ($proration['amount'] > 0) {
                    $_SESSION['upgrade_plan_id'] = $new_plan_id;
                    $_SESSION['upgrade_proration'] = $proration;
                    $_SESSION['upgrade_child_id'] = $childId;
                    header('Location: parent_child_membership_payment.php?id=' . $childId);
                    exit;
                } else {
                    // Downgrade - process immediately
                    if ($current_membership) {
                        $cancelParams = [$current_membership['id']];
                        school_param($cancelParams);
                        $stmt = $pdo->prepare("UPDATE memberships SET status = 'cancelled', end_date = CURDATE() WHERE id = ?" . school_where());
                        $stmt->execute($cancelParams);
                    }

                    // Create new membership
                    $start_date = date('Y-m-d');
                    $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $new_plan['duration_months'] . ' months'));

                    $isMonthlyNewPlan = (isset($new_plan['billing_frequency']) && $new_plan['billing_frequency'] === 'monthly' && $new_plan['duration_months'] > 1);
                    $billing_day = $isMonthlyNewPlan ? min((int)date('j'), 28) : null;

                    $stmt = $pdo->prepare("
                        INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date, status, payment_status, amount_paid, auto_renew, billing_day, monthly_charges_made)
                        VALUES (?, ?, ?, ?, ?, 'active', 'paid', ?, 1, ?, 0)
                    ");
                    $stmt->execute([current_school_id(), $childId, $new_plan_id, $start_date, $end_date, 0, $billing_day]);

                    // Add credit to student's account balance
                    if ($proration['credit'] > 0) {
                        $membershipId = $pdo->lastInsertId();
                        add_student_credit(
                            $childId,
                            $proration['credit'],
                            'Membership downgrade credit: ' . ($current_membership['plan_name'] ?? 'Previous') . ' to ' . $new_plan['name'],
                            'downgrade',
                            (int)$membershipId
                        );
                    }

                    // Record plan change date for lockout
                    if (empty($new_plan['tax_deductible'])) {
                        $lpcParams = [$childId];
                        school_param($lpcParams);
                        $pdo->prepare("UPDATE students SET last_plan_change = CURDATE() WHERE id = ?" . school_where())->execute($lpcParams);
                    }

                    header('Location: parent_child_membership.php?id=' . $childId . '&success=downgrade');
                    exit;
                }
            }
        }
    }
}

// Get student's credit balance
$studentCredit = get_student_credit($childId);

// Refresh current membership after possible changes
if (isset($_GET['success'])) {
    $refreshParams = [$childId];
    school_param($refreshParams);
    $current_membership = $pdo->prepare("
        SELECT m.*, mp.name as plan_name, mp.price as plan_price, mp.duration_months,
               mp.billing_frequency, mp.classes_per_week, mp.description as plan_description
        FROM memberships m
        JOIN membership_plans mp ON m.plan_id = mp.id
        WHERE m.student_id = ? AND m.status = 'active' AND m.end_date >= CURDATE()" . school_where('m') . "
        ORDER BY m.end_date DESC LIMIT 1
    ");
    $current_membership->execute($refreshParams);
    $current_membership = $current_membership->fetch() ?: null;
}

$childName = htmlspecialchars($child['first_name'] . ' ' . $child['last_name']);

include 'includes/parent_header.php';
?>

<div class="container mx-auto px-4 py-8">

    <!-- Child Switcher + Back -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="parent_child.php?id=<?= $childId ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">&larr; <?= $childName ?></a>
            <span class="text-gray-300">|</span>
            <h1 class="text-2xl font-bold text-gray-800">Manage Membership</h1>
        </div>
        <?php if (count($children) > 1): ?>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500">Switch child:</span>
                <select onchange="window.location.href='parent_child_membership.php?id='+this.value"
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

    <p class="text-gray-600 mb-6">Manage <?= $childName ?>'s membership plan.</p>

    <?php if (isset($_GET['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
            <?php if ($_GET['success'] === 'program'): ?>
                <?= $childName ?> has been successfully signed up for the program!
            <?php else: ?>
                Membership successfully updated for <?= $childName ?>!
            <?php endif; ?>
            <?php if ($_GET['success'] === 'downgrade' && $studentCredit > 0): ?>
                <br><span class="text-sm">A credit of <?= formatMoney($studentCredit) ?> has been applied to their account.</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?= $message ?>

    <?php if ($is_locked_out): ?>
        <div class="bg-yellow-50 border border-yellow-300 text-yellow-800 px-4 py-3 rounded mb-6">
            <p class="font-semibold">&#128274; Plan Change Locked</p>
            <p class="text-sm">Regular plan changes are limited to once every 30 days. The next change for <?= $childName ?> will be available on <strong><?= $lockout_until->format('M j, Y') ?></strong>.</p>
            <p class="text-xs mt-1">Afterschool, camp, and tax-deductible programs can still be enrolled at any time.</p>
        </div>
    <?php endif; ?>

    <?php if ($has_pending_change && $pending_change_detail): ?>
        <?php
        $pcIsMonthly = (isset($pending_change_detail['new_billing_frequency']) && $pending_change_detail['new_billing_frequency'] === 'monthly' && $pending_change_detail['new_duration'] > 1);
        $pcMonthlyAmt = $pcIsMonthly ? round($pending_change_detail['new_plan_price'] / $pending_change_detail['new_duration'], 2) : 0;
        $pcDisplayPrice = $pcIsMonthly
            ? formatMoney($pcMonthlyAmt) . '/mo (' . formatMoney($pending_change_detail['new_plan_price']) . ' total)'
            : formatMoney($pending_change_detail['new_plan_price']);
        ?>
        <div class="bg-orange-50 border-2 border-orange-300 rounded-lg p-6 mb-6">
            <div class="flex items-start gap-4">
                <span class="text-3xl flex-shrink-0">&#128232;</span>
                <div class="flex-1">
                    <h3 class="text-lg font-bold text-orange-800 mb-1">Membership Change Proposed for <?= $childName ?></h3>
                    <p class="text-sm text-gray-700 mb-3">
                        <?= htmlspecialchars($pending_change_detail['requested_by_name'] ?? 'Studio Admin') ?> has proposed a membership change:
                    </p>

                    <div class="bg-white rounded-lg p-4 mb-3 flex items-center justify-between gap-4">
                        <div class="text-center">
                            <p class="text-xs text-gray-500">Current Plan</p>
                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($pending_change_detail['old_plan_name'] ?? 'No Plan') ?></p>
                        </div>
                        <span class="text-2xl text-gray-400">&#8594;</span>
                        <div class="text-center">
                            <p class="text-xs text-gray-500">Proposed Plan</p>
                            <p class="font-semibold text-blue-700"><?= htmlspecialchars($pending_change_detail['new_plan_name']) ?></p>
                            <p class="text-xs text-gray-500"><?= $pcDisplayPrice ?></p>
                        </div>
                    </div>

                    <?php if ($pending_change_detail['proration_type'] === 'upgrade'): ?>
                        <p class="text-sm text-gray-700 mb-2">
                            Pro-rated upgrade cost: <strong class="text-green-700"><?= formatMoney($pending_change_detail['proration_amount']) ?></strong>
                        </p>
                    <?php elseif ($pending_change_detail['proration_type'] === 'downgrade'): ?>
                        <p class="text-sm text-gray-700 mb-2">
                            Downgrade credit: <strong class="text-green-700"><?= formatMoney($pending_change_detail['proration_credit']) ?></strong>
                        </p>
                    <?php endif; ?>

                    <?php if ($pending_change_detail['notes']): ?>
                        <p class="text-xs text-gray-500 mb-3 italic">"<?= htmlspecialchars($pending_change_detail['notes']) ?>"</p>
                    <?php endif; ?>

                    <p class="text-xs text-gray-500 mb-3">Expires: <?= date('M j, Y \a\t g:i A', strtotime($pending_change_detail['expires_at'])) ?></p>

                    <p class="text-sm text-gray-600 italic mb-0">
                        This plan change requires <?= $childName ?> to approve or decline from their student portal.
                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Account Credit Balance -->
    <?php if ($studentCredit > 0): ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-2xl">&#128176;</span>
                <div>
                    <p class="font-semibold text-green-800">Account Credit Available</p>
                    <p class="text-sm text-green-600">This credit will be automatically applied to <?= $childName ?>'s next payment.</p>
                </div>
            </div>
            <span class="text-2xl font-bold text-green-700"><?= formatMoney($studentCredit) ?></span>
        </div>
    <?php endif; ?>

    <!-- Current Membership -->
    <?php if ($current_membership): ?>
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg shadow-lg p-6 mb-8">
            <h2 class="text-xl font-bold mb-2">Current Membership</h2>
            <?php
            $curIsMonthly = (isset($current_membership['billing_frequency']) && $current_membership['billing_frequency'] === 'monthly' && $current_membership['duration_months'] > 1);
            $curMonthlyAmt = $curIsMonthly ? round($current_membership['plan_price'] / $current_membership['duration_months'], 2) : 0;
            ?>
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-2xl font-bold"><?= htmlspecialchars($current_membership['plan_name']) ?></p>
                    <p class="opacity-90">Valid until <?= formatDate($current_membership['end_date']) ?></p>
                    <?php if ($curIsMonthly && isset($current_membership['billing_day'])): ?>
                        <p class="text-sm opacity-80">Billed monthly on day <?= $current_membership['billing_day'] ?></p>
                    <?php endif; ?>
                    <?php if ($current_membership['classes_per_week']): ?>
                        <p class="text-sm opacity-80"><?= $current_membership['classes_per_week'] == 99 ? 'Unlimited' : $current_membership['classes_per_week'] ?> classes per week</p>
                    <?php endif; ?>
                </div>
                <div class="text-right">
                    <?php if ($curIsMonthly): ?>
                        <p class="text-3xl font-bold"><?= formatMoney($curMonthlyAmt) ?><span class="text-base font-normal">/mo</span></p>
                        <p class="text-sm opacity-90"><?= formatMoney($current_membership['plan_price']) ?> total / <?= $current_membership['duration_months'] ?> months</p>
                    <?php else: ?>
                        <p class="text-3xl font-bold"><?= formatMoney($current_membership['plan_price']) ?></p>
                        <p class="text-sm opacity-90">per <?= $current_membership['duration_months'] ?> month(s)</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php elseif ($has_pending_change && $pending_change_detail): ?>
        <?php
        $pcmIsMonthly = (isset($pending_change_detail['new_billing_frequency']) && $pending_change_detail['new_billing_frequency'] === 'monthly' && $pending_change_detail['new_duration'] > 1);
        $pcmMonthlyAmt = $pcmIsMonthly ? round($pending_change_detail['new_plan_price'] / $pending_change_detail['new_duration'], 2) : 0;
        ?>
        <div class="bg-gradient-to-r from-orange-400 to-orange-500 text-white rounded-lg shadow-lg p-6 mb-8">
            <div class="flex items-center gap-2 mb-2">
                <h2 class="text-xl font-bold">Proposed Membership</h2>
                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded-full bg-white/30 text-white">Pending Approval</span>
            </div>
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-2xl font-bold"><?= htmlspecialchars($pending_change_detail['new_plan_name']) ?></p>
                    <p class="opacity-90">Proposed by <?= htmlspecialchars($pending_change_detail['requested_by_name'] ?? 'Studio Admin') ?></p>
                    <?php if ($pending_change_detail['new_classes_per_week']): ?>
                        <p class="text-sm opacity-80"><?= $pending_change_detail['new_classes_per_week'] == 99 ? 'Unlimited' : $pending_change_detail['new_classes_per_week'] ?> classes per week</p>
                    <?php endif; ?>
                    <p class="text-sm opacity-80 mt-1">Awaiting student approval &bull; Expires <?= date('M j, Y', strtotime($pending_change_detail['expires_at'])) ?></p>
                </div>
                <div class="text-right">
                    <?php if ($pcmIsMonthly): ?>
                        <p class="text-3xl font-bold"><?= formatMoney($pcmMonthlyAmt) ?><span class="text-base font-normal">/mo</span></p>
                        <p class="text-sm opacity-90"><?= formatMoney($pending_change_detail['new_plan_price']) ?> total / <?= $pending_change_detail['new_duration'] ?> months</p>
                    <?php else: ?>
                        <p class="text-3xl font-bold"><?= formatMoney($pending_change_detail['new_plan_price']) ?></p>
                        <p class="text-sm opacity-90">per <?= $pending_change_detail['new_duration'] ?> month(s)</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="bg-orange-100 border border-orange-400 text-orange-700 px-6 py-4 rounded-lg mb-8">
            <p class="font-semibold">No Active Membership</p>
            <p class="text-sm"><?= $childName ?> does not have an active membership. Select a plan below to get started.</p>
        </div>
    <?php endif; ?>

    <!-- Available Plans -->
    <div class="mb-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6">Available Plans</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($available_plans as $plan): ?>
                <?php
                $is_current = $current_membership && $current_membership['plan_id'] == $plan['id'];
                $proration = calculateProration($current_membership, $plan);
                ?>
                <div class="bg-white rounded-lg shadow-lg overflow-hidden <?= $is_current ? 'ring-4 ring-blue-500' : '' ?>">
                    <?php if ($is_current): ?>
                        <div class="bg-blue-500 text-white text-center py-2 font-semibold text-sm">
                            CURRENT PLAN
                        </div>
                    <?php endif; ?>

                    <div class="p-6">
                        <h3 class="text-xl font-bold text-gray-800 mb-2"><?= htmlspecialchars($plan['name']) ?></h3>
                        <?php
                        $planIsMonthly = (isset($plan['billing_frequency']) && $plan['billing_frequency'] === 'monthly' && $plan['duration_months'] > 1);
                        $planMonthlyAmt = $planIsMonthly ? round($plan['price'] / $plan['duration_months'], 2) : 0;
                        ?>
                        <?php if ($planIsMonthly): ?>
                            <p class="text-3xl font-bold text-blue-600 mb-1"><?= formatMoney($planMonthlyAmt) ?><span class="text-base font-normal">/mo</span></p>
                            <p class="text-sm text-gray-500 mb-4"><?= formatMoney($plan['price']) ?> total over <?= $plan['duration_months'] ?> months</p>
                        <?php else: ?>
                            <p class="text-3xl font-bold text-blue-600 mb-2"><?= formatMoney($plan['price']) ?></p>
                            <p class="text-sm text-gray-600 mb-4">per <?= $plan['duration_months'] ?> month(s)</p>
                        <?php endif; ?>

                        <p class="text-sm text-gray-700 mb-4"><?= htmlspecialchars($plan['description'] ?? '') ?></p>

                        <ul class="space-y-2 text-sm text-gray-600 mb-6">
                            <li>&#10003; <?= $plan['classes_per_week'] == 99 ? 'Unlimited' : $plan['classes_per_week'] ?> classes/week</li>
                            <li>&#10003; All martial arts styles</li>
                            <li>&#10003; <?= $plan['duration_months'] ?> month commitment</li>
                            <?php if ($planIsMonthly): ?>
                                <li>&#10003; Billed monthly</li>
                            <?php endif; ?>
                            <?php if (!empty($plan['is_afterschool']) || !empty($plan['is_camp'])): ?>
                                <li>&#10003; Fixed-term (no auto-renewal)</li>
                            <?php endif; ?>
                        </ul>
                        <?php if ((!empty($plan['is_afterschool']) || !empty($plan['is_camp'])) && $plan['program_start_date'] && $plan['program_end_date']): ?>
                            <div class="mb-4">
                                <?php if (!empty($plan['is_camp'])): ?>
                                    <span class="inline-block bg-teal-100 text-teal-800 text-xs font-semibold px-2 py-1 rounded-full">Camp Program</span>
                                <?php else: ?>
                                    <span class="inline-block bg-indigo-100 text-indigo-800 text-xs font-semibold px-2 py-1 rounded-full">Afterschool Program</span>
                                <?php endif; ?>
                                <p class="text-xs <?= !empty($plan['is_camp']) ? 'text-teal-600' : 'text-indigo-600' ?> mt-1">&#128197; <?= date('M j, Y', strtotime($plan['program_start_date'])) ?> &ndash; <?= date('M j, Y', strtotime($plan['program_end_date'])) ?></p>
                                <?php if (date('Y-m-d') > $plan['program_end_date']): ?>
                                    <p class="text-xs text-red-600 font-semibold mt-1">&#9888; This program has ended</p>
                                <?php elseif (date('Y-m-d') > $plan['program_start_date']): ?>
                                    <p class="text-xs <?= !empty($plan['is_camp']) ? 'text-teal-600' : 'text-indigo-600' ?> mt-1">Enrollment available &mdash; program in progress</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php
                        $fixed_term_ended = ((!empty($plan['is_afterschool']) || !empty($plan['is_camp'])) && !empty($plan['program_end_date']) && date('Y-m-d') > $plan['program_end_date']);
                        $plan_bypasses_lockout = (!empty($plan['is_afterschool']) || !empty($plan['is_camp']) || !empty($plan['tax_deductible']));
                        $plan_is_locked = (($is_locked_out && !$plan_bypasses_lockout) || $has_pending_change);
                        ?>
                        <?php if (!$is_current): ?>
                            <?php if ($fixed_term_ended): ?>
                                <button disabled class="w-full bg-gray-300 text-gray-500 font-bold py-3 px-4 rounded-lg cursor-not-allowed text-sm">
                                    Program Ended
                                </button>
                            <?php elseif ($plan_is_locked): ?>
                                <?php if ($current_membership): ?>
                                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 mb-4 text-xs">
                                        <?php if ($proration['type'] === 'upgrade'): ?>
                                            <p class="font-semibold text-gray-800 mb-1">Pro-rated Upgrade Cost:</p>
                                            <p class="text-green-600 font-bold text-lg"><?= formatMoney($proration['amount']) ?></p>
                                            <p class="text-gray-600 mt-1">For remaining <?= $proration['days_remaining'] ?> days</p>
                                        <?php else: ?>
                                            <p class="font-semibold text-gray-800 mb-1">Downgrade Credit:</p>
                                            <p class="text-green-600 font-bold text-lg"><?= formatMoney($proration['credit']) ?></p>
                                            <p class="text-gray-600 mt-1">Applied to account</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <button disabled class="w-full bg-gray-300 text-gray-500 font-bold py-3 px-4 rounded-lg cursor-not-allowed text-sm">
                                    <?php if ($is_locked_out): ?>
                                        &#128274; Available <?= $lockout_until->format('M j') ?>
                                    <?php else: ?>
                                        &#9203; Pending Change
                                    <?php endif; ?>
                                </button>
                            <?php else: ?>
                                <?php $is_program = (!empty($plan['is_afterschool']) || !empty($plan['is_camp'])); ?>
                                <form method="POST" class="mb-4" onsubmit="return confirm('<?= $is_program ? 'Sign up ' . $childName . ' for ' . htmlspecialchars($plan['name']) . '?' : 'Change ' . $childName . '\\\'s membership to ' . htmlspecialchars($plan['name']) . '?' ?>')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="upgrade" value="1">
                                    <input type="hidden" name="new_plan_id" value="<?= $plan['id'] ?>">

                                    <?php if ($current_membership && !$is_program): ?>
                                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 mb-4 text-xs">
                                            <?php if ($proration['type'] === 'upgrade'): ?>
                                                <p class="font-semibold text-gray-800 mb-1">Pro-rated Upgrade Cost:</p>
                                                <p class="text-green-600 font-bold text-lg"><?= formatMoney($proration['amount']) ?></p>
                                                <p class="text-gray-600 mt-1">For remaining <?= $proration['days_remaining'] ?> days</p>
                                            <?php else: ?>
                                                <p class="font-semibold text-gray-800 mb-1">Downgrade Credit:</p>
                                                <p class="text-green-600 font-bold text-lg"><?= formatMoney($proration['credit']) ?></p>
                                                <p class="text-gray-600 mt-1">Applied to account</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($is_program): ?>
                                        <button type="submit"
                                                class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-3 px-4 rounded-lg transition">
                                            Sign Up for <?= !empty($plan['is_camp']) ? 'Camp' : 'Program' ?>
                                        </button>
                                    <?php else: ?>
                                        <button type="submit"
                                                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition">
                                            <?= $current_membership ? 'Switch to This Plan' : 'Select Plan' ?>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <button disabled class="w-full bg-gray-300 text-gray-600 font-bold py-3 px-4 rounded-lg cursor-not-allowed">
                                Current Plan
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Proration Explanation -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
        <h3 class="font-semibold text-gray-800 mb-3">How Plan Changes Work</h3>
        <ul class="space-y-2 text-sm text-gray-700">
            <li><strong>Upgrade:</strong> Pay only the pro-rated difference for the remaining days of the current membership period.</li>
            <li><strong>Downgrade:</strong> Receive credit for the unused portion of the current plan, applied to the student's account.</li>
            <li><strong>No Cancellation:</strong> Memberships cannot be cancelled online. Contact the studio if you need to cancel.</li>
            <li><strong>Immediate Effect:</strong> Plan changes take effect immediately upon payment.</li>
        </ul>
    </div>
</div>

<?php include 'includes/student_footer.php'; ?>
