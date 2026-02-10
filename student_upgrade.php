<?php
require_once 'config.php';

// Check if student is logged in
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php?type=student');
    exit;
}

$student_id = $_SESSION['student_id'];
$message = '';

// Get current membership
$current_membership = $pdo->prepare("
    SELECT m.*, mp.name as plan_name, mp.price as plan_price, mp.duration_months
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.student_id = ? AND m.status = 'active' AND m.end_date >= CURDATE()
    ORDER BY m.end_date DESC
    LIMIT 1
");
$current_membership->execute([$student_id]);
$current_membership = $current_membership->fetch();

// Get available plans
$available_plans = $pdo->query("SELECT * FROM membership_plans WHERE status = 'active' ORDER BY price ASC")->fetchAll();

// Calculate proration
function calculateProration($current_membership, $new_plan) {
    global $pdo;
    
    if (!$current_membership) {
        return [
            'amount' => $new_plan['price'], 
            'type' => 'full', 
            'credit' => 0,
            'unused_value' => 0,
            'new_cost' => $new_plan['price'],
            'days_remaining' => ($new_plan['duration_months'] * 30)
        ];
    }
    
    // Calculate days remaining in current membership
    $today = new DateTime();
    $end_date = new DateTime($current_membership['end_date']);
    $days_remaining = max(0, $today->diff($end_date)->days);
    
    $start_date = new DateTime($current_membership['start_date']);
    $total_days = max(1, $start_date->diff($end_date)->days); // Prevent division by zero
    
    // Calculate unused portion of current membership
    $unused_value = ($days_remaining / $total_days) * $current_membership['plan_price'];
    
    // Calculate pro-rated amount for new membership
    $daily_rate_new = $new_plan['price'] / ($new_plan['duration_months'] * 30);
    $new_membership_cost = $daily_rate_new * $days_remaining;
    
    // Calculate difference
    $difference = $new_membership_cost - $unused_value;
    
    if ($difference > 0) {
        // Upgrade - student pays difference
        return [
            'amount' => round($difference, 2),
            'type' => 'upgrade',
            'credit' => 0,
            'unused_value' => round($unused_value, 2),
            'new_cost' => round($new_membership_cost, 2),
            'days_remaining' => $days_remaining
        ];
    } else {
        // Downgrade - student gets credit
        return [
            'amount' => 0,
            'type' => 'downgrade',
            'credit' => round(abs($difference), 2),
            'unused_value' => round($unused_value, 2),
            'new_cost' => round($new_membership_cost, 2),
            'days_remaining' => $days_remaining
        ];
    }
}

// Handle upgrade request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upgrade'])) {
    $new_plan_id = $_POST['new_plan_id'];
    
    // Get new plan details
    $new_plan = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?");
    $new_plan->execute([$new_plan_id]);
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
                $stmt = $pdo->prepare("UPDATE memberships SET status = 'cancelled', end_date = CURDATE() WHERE id = ?");
                $stmt->execute([$current_membership['id']]);
            }
            
            // Create new membership starting today
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $new_plan['duration_months'] . ' months'));
            
            $stmt = $pdo->prepare("
                INSERT INTO memberships (student_id, plan_id, start_date, end_date, status, payment_status, amount_paid)
                VALUES (?, ?, ?, ?, 'active', 'paid', ?)
            ");
            $stmt->execute([$student_id, $new_plan_id, $start_date, $end_date, 0]);
            
            // Record credit to account
            if ($proration['credit'] > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (student_id, payment_type, reference_id, amount, 
                                        payment_method, payment_date, receipt_number, notes)
                    VALUES (?, 'membership', ?, ?, 'credit', CURDATE(), ?, ?)
                ");
                $stmt->execute([
                    $student_id,
                    $pdo->lastInsertId(),
                    -$proration['credit'], // Negative for credit
                    generateReceiptNumber(),
                    'Membership downgrade credit - ' . formatMoney($proration['credit']) . ' applied to account'
                ]);
            }
            
            header('Location: student_upgrade.php?success=downgrade');
            exit;
        }
    }
}

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php if (isset($_GET['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
            Membership successfully updated!
        </div>
    <?php endif; ?>
    
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Manage Membership</h1>
        <p class="text-gray-600">Upgrade or change your membership plan</p>
    </div>
    
    <!-- Current Membership -->
    <?php if ($current_membership): ?>
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg shadow-lg p-6 mb-8">
            <h2 class="text-xl font-bold mb-2">Current Membership</h2>
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-2xl font-bold"><?php echo $current_membership['plan_name']; ?></p>
                    <p class="opacity-90">Valid until <?php echo formatDate($current_membership['end_date']); ?></p>
                </div>
                <div class="text-right">
                    <p class="text-3xl font-bold"><?php echo formatMoney($current_membership['plan_price']); ?></p>
                    <p class="text-sm opacity-90">per <?php echo $current_membership['duration_months']; ?> month(s)</p>
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
                        <p class="text-3xl font-bold text-blue-600 mb-2"><?php echo formatMoney($plan['price']); ?></p>
                        <p class="text-sm text-gray-600 mb-4">per <?php echo $plan['duration_months']; ?> month(s)</p>
                        
                        <p class="text-sm text-gray-700 mb-4"><?php echo $plan['description']; ?></p>
                        
                        <ul class="space-y-2 text-sm text-gray-600 mb-6">
                            <li>✓ <?php echo $plan['classes_per_week'] == 99 ? 'Unlimited' : $plan['classes_per_week']; ?> classes/week</li>
                            <li>✓ All martial arts styles</li>
                            <li>✓ <?php echo $plan['duration_months']; ?> month commitment</li>
                        </ul>
                        
                        <?php if (!$is_current): ?>
                            <form method="POST" class="mb-4">
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
