<?php
require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';

// Check if student is logged in
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php?type=student');
    exit;
}
require_student_payment_clear();

$student_id = $_SESSION['student_id'];
$message = '';

// Get current membership
$params = [$student_id];
$sql = "SELECT m.*, mp.name as plan_name, mp.price as plan_price, mp.duration_months, mp.billing_frequency
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.student_id = ? AND m.status = 'active' AND m.end_date >= CURDATE()" . school_where('m') . "
    ORDER BY m.end_date DESC
    LIMIT 1";
school_param($params);
$current_membership = $pdo->prepare($sql);
$current_membership->execute($params);
$current_membership = $current_membership->fetch() ?: null;

// Get available plans
$params = [];
$sql = "SELECT * FROM membership_plans " . school_where_clause() . " AND status = 'active' AND (is_grandfathered = 0 OR is_grandfathered IS NULL) ORDER BY price ASC";
school_param($params);
$available_plans = $pdo->prepare($sql);
$available_plans->execute($params);
$available_plans = $available_plans->fetchAll();

// calculateProration() is now a shared function in includes/payment_gateway.php

// --- Plan change lockout check ---
$is_locked_out = false;
$lockout_until = null;
$has_pending_change = false;

// Check last_plan_change for lockout
$params = [$student_id];
$sql = "SELECT last_plan_change FROM students WHERE id = ?" . school_where();
school_param($params);
$lockoutStmt = $pdo->prepare($sql);
$lockoutStmt->execute($params);
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
    $params = [$student_id];
    $sql = "SELECT COUNT(*) FROM pending_plan_changes WHERE student_id = ? AND status = 'pending' AND expires_at > NOW()" . school_where();
    school_param($params);
    $pendingStmt = $pdo->prepare($sql);
    $pendingStmt->execute($params);
    $has_pending_change = ($pendingStmt->fetchColumn() > 0);
} catch (PDOException $e) {}

// Handle upgrade request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upgrade'])) {
    verify_csrf();
    // Server-side lockout enforcement
    if ($is_locked_out) {
        $message = showAlert('You can only change your membership plan once every 30 days. Your next change will be available on ' . $lockout_until->format('M j, Y') . '.', 'error');
    } elseif ($has_pending_change) {
        $message = showAlert('You have a pending plan change awaiting your confirmation. Please approve or decline it before requesting another change.', 'error');
    } else {

    $new_plan_id = $_POST['new_plan_id'];

    // Get new plan details
    $params = [$new_plan_id];
    $sql = "SELECT * FROM membership_plans WHERE id = ?" . school_where();
    school_param($params);
    $new_plan = $pdo->prepare($sql);
    $new_plan->execute($params);
    $new_plan = $new_plan->fetch();

    if ($new_plan) {
        // Calculate proration
        $proration = calculateProration($current_membership, $new_plan);
        
        // Store in session for payment
        $_SESSION['upgrade_plan_id'] = $new_plan_id;
        $_SESSION['upgrade_proration'] = $proration;
        
        // Redirect to payment if amount due
        if ($proration['amount'] > 0) {
            header('Location: student_upgrade_payment.php');
            exit;
        } else {
            // Downgrade - process immediately
            // Cancel current membership
            if ($current_membership) {
                $params = [$current_membership['id']];
                $sql = "UPDATE memberships SET status = 'cancelled', end_date = CURDATE() WHERE id = ?" . school_where();
                school_param($params);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }
            
            // Create new membership starting today
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $new_plan['duration_months'] . ' months'));

            // For monthly plans, set billing_day and monthly_charges_made
            $isMonthlyNewPlan = (isset($new_plan['billing_frequency']) && $new_plan['billing_frequency'] === 'monthly' && $new_plan['duration_months'] > 1);
            $billing_day = $isMonthlyNewPlan ? min((int) date('j'), 28) : null;

            $stmt = $pdo->prepare("
                INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date, status, payment_status, amount_paid, billing_day, monthly_charges_made)
                VALUES (?, ?, ?, ?, ?, 'active', 'paid', ?, ?, 0)
            ");
            $stmt->execute([current_school_id(), $student_id, $new_plan_id, $start_date, $end_date, 0, $billing_day]);
            
            // Add credit to student's account balance
            if ($proration['credit'] > 0) {
                $membershipId = $pdo->lastInsertId();
                add_student_credit(
                    $student_id,
                    $proration['credit'],
                    'Membership downgrade credit: ' . ($current_membership['plan_name'] ?? 'Previous') . ' to ' . $new_plan['name'],
                    'downgrade',
                    (int) $membershipId
                );
            }

            // Record plan change date for lockout
            $params = [$student_id]; $sql = "UPDATE students SET last_plan_change = CURDATE() WHERE id = ?" . school_where(); school_param($params);
            $pdo->prepare($sql)->execute($params);

            header('Location: student_upgrade.php?success=downgrade');
            exit;
        }
    }

    } // end lockout else
}

// Get student's credit balance
$studentCredit = get_student_credit($student_id);

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php if (isset($_GET['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
            Membership successfully updated!
            <?php if (isset($_GET['success']) && $_GET['success'] === 'downgrade' && $studentCredit > 0): ?>
                <br><span class="text-sm">A credit of <?php echo formatMoney($studentCredit); ?> has been applied to your account.</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php echo $message; ?>

    <?php if ($is_locked_out): ?>
        <div class="bg-yellow-50 border border-yellow-300 text-yellow-800 px-4 py-3 rounded mb-6">
            <p class="font-semibold">&#128274; Plan Change Locked</p>
            <p class="text-sm">You can only change your membership plan once every 30 days. Your next change will be available on <strong><?php echo $lockout_until->format('M j, Y'); ?></strong>.</p>
        </div>
    <?php endif; ?>

    <?php if ($has_pending_change): ?>
        <div class="bg-orange-50 border border-orange-300 text-orange-800 px-4 py-3 rounded mb-6">
            <p class="font-semibold">&#9203; Pending Plan Change</p>
            <p class="text-sm">You have a pending plan change awaiting your confirmation. <a href="student_portal.php" class="underline font-medium">View on your dashboard</a> to approve or decline it before requesting another change.</p>
        </div>
    <?php endif; ?>

    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Manage Membership</h1>
        <p class="text-gray-600">Upgrade or change your membership plan</p>
    </div>

    <!-- Account Credit Balance -->
    <?php if ($studentCredit > 0): ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-2xl">&#128176;</span>
                <div>
                    <p class="font-semibold text-green-800">Account Credit Available</p>
                    <p class="text-sm text-green-600">This credit will be automatically applied to your next payment.</p>
                </div>
            </div>
            <span class="text-2xl font-bold text-green-700"><?php echo formatMoney($studentCredit); ?></span>
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
                    <p class="text-2xl font-bold"><?php echo $current_membership['plan_name']; ?></p>
                    <p class="opacity-90">Valid until <?php echo formatDate($current_membership['end_date']); ?></p>
                    <?php if ($curIsMonthly && isset($current_membership['billing_day'])): ?>
                        <p class="text-sm opacity-80">Billed monthly on day <?php echo $current_membership['billing_day']; ?></p>
                    <?php endif; ?>
                </div>
                <div class="text-right">
                    <?php if ($curIsMonthly): ?>
                        <p class="text-3xl font-bold"><?php echo formatMoney($curMonthlyAmt); ?><span class="text-base font-normal">/mo</span></p>
                        <p class="text-sm opacity-90"><?php echo formatMoney($current_membership['plan_price']); ?> total / <?php echo $current_membership['duration_months']; ?> months</p>
                    <?php else: ?>
                        <p class="text-3xl font-bold"><?php echo formatMoney($current_membership['plan_price']); ?></p>
                        <p class="text-sm opacity-90">per <?php echo $current_membership['duration_months']; ?> month(s)</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="bg-orange-100 border border-orange-400 text-orange-700 px-6 py-4 rounded-lg mb-8">
            <p class="font-semibold">No Active Membership</p>
            <p class="text-sm">Select a plan below to get started</p>
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
                <div class="bg-white rounded-lg shadow-lg overflow-hidden <?php echo $is_current ? 'ring-4 ring-blue-500' : ''; ?>">
                    <?php if ($is_current): ?>
                        <div class="bg-blue-500 text-white text-center py-2 font-semibold text-sm">
                            CURRENT PLAN
                        </div>
                    <?php endif; ?>
                    
                    <div class="p-6">
                        <h3 class="text-xl font-bold text-gray-800 mb-2"><?php echo $plan['name']; ?></h3>
                        <?php
                        $planIsMonthly = (isset($plan['billing_frequency']) && $plan['billing_frequency'] === 'monthly' && $plan['duration_months'] > 1);
                        $planMonthlyAmt = $planIsMonthly ? round($plan['price'] / $plan['duration_months'], 2) : 0;
                        ?>
                        <?php if ($planIsMonthly): ?>
                            <p class="text-3xl font-bold text-blue-600 mb-1"><?php echo formatMoney($planMonthlyAmt); ?><span class="text-base font-normal">/mo</span></p>
                            <p class="text-sm text-gray-500 mb-4"><?php echo formatMoney($plan['price']); ?> total over <?php echo $plan['duration_months']; ?> months</p>
                        <?php else: ?>
                            <p class="text-3xl font-bold text-blue-600 mb-2"><?php echo formatMoney($plan['price']); ?></p>
                            <p class="text-sm text-gray-600 mb-4">per <?php echo $plan['duration_months']; ?> month(s)</p>
                        <?php endif; ?>

                        <p class="text-sm text-gray-700 mb-4"><?php echo $plan['description']; ?></p>

                        <ul class="space-y-2 text-sm text-gray-600 mb-6">
                            <li>✓ <?php echo $plan['classes_per_week'] == 99 ? 'Unlimited' : $plan['classes_per_week']; ?> classes/week</li>
                            <li>✓ All martial arts styles</li>
                            <li>✓ <?php echo $plan['duration_months']; ?> month commitment</li>
                            <?php if ($planIsMonthly): ?>
                                <li>✓ Billed monthly on day <?php echo min((int) date('j'), 28); ?></li>
                            <?php endif; ?>
                            <?php if (!empty($plan['is_afterschool'])): ?>
                                <li>✓ Fixed-term (no auto-renewal)</li>
                            <?php endif; ?>
                        </ul>
                        <?php if (!empty($plan['is_afterschool']) && $plan['program_start_date'] && $plan['program_end_date']): ?>
                            <div class="mb-4">
                                <span class="inline-block bg-indigo-100 text-indigo-800 text-xs font-semibold px-2 py-1 rounded-full">Afterschool Program</span>
                                <p class="text-xs text-indigo-600 mt-1">&#128197; <?php echo date('M j, Y', strtotime($plan['program_start_date'])); ?> &ndash; <?php echo date('M j, Y', strtotime($plan['program_end_date'])); ?></p>
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
                                            <p class="text-green-600 font-bold text-lg"><?php echo formatMoney($proration['amount']); ?></p>
                                            <p class="text-gray-600 mt-1">For remaining <?php echo $proration['days_remaining']; ?> days</p>
                                        <?php else: ?>
                                            <p class="font-semibold text-gray-800 mb-1">Downgrade Credit:</p>
                                            <p class="text-green-600 font-bold text-lg"><?php echo formatMoney($proration['credit']); ?></p>
                                            <p class="text-gray-600 mt-1">Applied to your account</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <button disabled class="w-full bg-gray-300 text-gray-500 font-bold py-3 px-4 rounded-lg cursor-not-allowed text-sm">
                                    <?php if ($is_locked_out): ?>
                                        &#128274; Available <?php echo $lockout_until->format('M j'); ?>
                                    <?php else: ?>
                                        &#9203; Pending Change
                                    <?php endif; ?>
                                </button>
                            <?php else: ?>
                                <form method="POST" class="mb-4">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="upgrade" value="1">
                                    <input type="hidden" name="new_plan_id" value="<?php echo $plan['id']; ?>">

                                    <?php if ($current_membership): ?>
                                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 mb-4 text-xs">
                                            <?php if ($proration['type'] === 'upgrade'): ?>
                                                <p class="font-semibold text-gray-800 mb-1">Pro-rated Upgrade Cost:</p>
                                                <p class="text-green-600 font-bold text-lg"><?php echo formatMoney($proration['amount']); ?></p>
                                                <p class="text-gray-600 mt-1">For remaining <?php echo $proration['days_remaining']; ?> days</p>
                                            <?php else: ?>
                                                <p class="font-semibold text-gray-800 mb-1">Downgrade Credit:</p>
                                                <p class="text-green-600 font-bold text-lg"><?php echo formatMoney($proration['credit']); ?></p>
                                                <p class="text-gray-600 mt-1">Applied to your account</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <button type="submit"
                                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition">
                                        <?php echo $current_membership ? 'Switch to This Plan' : 'Select Plan'; ?>
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
            <li><strong>Upgrade:</strong> Pay only the pro-rated difference for the remaining days of your current membership period.</li>
            <li><strong>Downgrade:</strong> Receive credit for the unused portion of your current plan, applied to your account.</li>
            <li><strong>No Cancellation:</strong> You cannot cancel your membership online. Contact the studio if you need to cancel.</li>
            <li><strong>Immediate Effect:</strong> Plan changes take effect immediately upon payment.</li>
        </ul>
    </div>
</div>

<?php include 'includes/student_footer.php'; ?>
