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

// Check for pending plan changes
try {
    $pendingParams = [$childId];
    school_param($pendingParams);
    $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM pending_plan_changes WHERE student_id = ? AND status = 'pending' AND expires_at > NOW()" . school_where());
    $pendingStmt->execute($pendingParams);
    $has_pending_change = ($pendingStmt->fetchColumn() > 0);
} catch (PDOException $e) {}

// Handle upgrade/downgrade request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upgrade'])) {
    verify_csrf();

    if ($is_locked_out) {
        $message = showAlert('Plan changes are limited to once every 30 days. Next change available on ' . $lockout_until->format('M j, Y') . '.', 'error');
    } elseif ($has_pending_change) {
        $message = showAlert('There is a pending plan change for this student. Please wait for it to be resolved before requesting another change.', 'error');
    } else {
        $new_plan_id = (int)$_POST['new_plan_id'];

        $npParams = [$new_plan_id];
        school_param($npParams);
        $new_plan = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?" . school_where());
        $new_plan->execute($npParams);
        $new_plan = $new_plan->fetch();

        if ($new_plan) {
            $proration = calculateProration($current_membership, $new_plan);

            if ($proration['amount'] > 0) {
                // Store upgrade info in session for payment
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

                // Afterschool plans: use fixed program end date, no auto-renewal
                if (!empty($new_plan['is_afterschool'])) {
                    $end_date   = $new_plan['program_end_date'];
                    $auto_renew = 0;
                } else {
                    $end_date   = date('Y-m-d', strtotime($start_date . ' + ' . $new_plan['duration_months'] . ' months'));
                    $auto_renew = 1;
                }

                $isMonthlyNewPlan = (isset($new_plan['billing_frequency']) && $new_plan['billing_frequency'] === 'monthly' && $new_plan['duration_months'] > 1);
                $billing_day = $isMonthlyNewPlan ? min((int)date('j'), 28) : null;

                $stmt = $pdo->prepare("
                    INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date, status, payment_status, amount_paid, auto_renew, billing_day, monthly_charges_made)
                    VALUES (?, ?, ?, ?, ?, 'active', 'paid', ?, ?, ?, 0)
                ");
                $stmt->execute([current_school_id(), $childId, $new_plan_id, $start_date, $end_date, 0, $auto_renew, $billing_day]);

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
                $lpcParams = [$childId];
                school_param($lpcParams);
                $pdo->prepare("UPDATE students SET last_plan_change = CURDATE() WHERE id = ?" . school_where())->execute($lpcParams);

                header('Location: parent_child_membership.php?id=' . $childId . '&success=downgrade');
                exit;
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

include 'includes/student_header.php';
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
            Membership successfully updated for <?= $childName ?>!
            <?php if ($_GET['success'] === 'downgrade' && $studentCredit > 0): ?>
                <br><span class="text-sm">A credit of <?= formatMoney($studentCredit) ?> has been applied to their account.</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?= $message ?>

    <?php if ($is_locked_out): ?>
        <div class="bg-yellow-50 border border-yellow-300 text-yellow-800 px-4 py-3 rounded mb-6">
            <p class="font-semibold">&#128274; Plan Change Locked</p>
            <p class="text-sm">Plan changes are limited to once every 30 days. The next change for <?= $childName ?> will be available on <strong><?= $lockout_until->format('M j, Y') ?></strong>.</p>
        </div>
    <?php endif; ?>

    <?php if ($has_pending_change): ?>
        <div class="bg-orange-50 border border-orange-300 text-orange-800 px-4 py-3 rounded mb-6">
            <p class="font-semibold">&#9203; Pending Plan Change</p>
            <p class="text-sm"><?= $childName ?> has a pending plan change. Please wait for it to be resolved before requesting another change.</p>
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
                            <?php if (!empty($plan['is_afterschool'])): ?>
                                <li>&#10003; Fixed-term (no auto-renewal)</li>
                            <?php endif; ?>
                        </ul>
                        <?php if (!empty($plan['is_afterschool']) && $plan['program_start_date'] && $plan['program_end_date']): ?>
                            <div class="mb-4">
                                <span class="inline-block bg-indigo-100 text-indigo-800 text-xs font-semibold px-2 py-1 rounded-full">Afterschool Program</span>
                                <p class="text-xs text-indigo-600 mt-1">&#128197; <?= date('M j, Y', strtotime($plan['program_start_date'])) ?> &ndash; <?= date('M j, Y', strtotime($plan['program_end_date'])) ?></p>
                                <?php if (date('Y-m-d') > $plan['program_end_date']): ?>
                                    <p class="text-xs text-red-600 font-semibold mt-1">&#9888; This program has ended</p>
                                <?php elseif (date('Y-m-d') > $plan['program_start_date']): ?>
                                    <p class="text-xs text-indigo-600 mt-1">Prorated enrollment available</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php
                        $afterschool_ended = (!empty($plan['is_afterschool']) && !empty($plan['program_end_date']) && date('Y-m-d') > $plan['program_end_date']);
                        ?>
                        <?php if (!$is_current): ?>
                            <?php if ($afterschool_ended): ?>
                                <button disabled class="w-full bg-gray-300 text-gray-500 font-bold py-3 px-4 rounded-lg cursor-not-allowed text-sm">
                                    Program Ended
                                </button>
                            <?php elseif ($is_locked_out || $has_pending_change): ?>
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
                                <form method="POST" class="mb-4" onsubmit="return confirm('Change <?= $childName ?>\'s membership to <?= htmlspecialchars($plan['name']) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="upgrade" value="1">
                                    <input type="hidden" name="new_plan_id" value="<?= $plan['id'] ?>">

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

                                    <button type="submit"
                                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition">
                                        <?= $current_membership ? 'Switch to This Plan' : 'Select Plan' ?>
                                    </button>
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
