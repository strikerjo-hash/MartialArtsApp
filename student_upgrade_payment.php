<?php
require_once 'config.php';

// Check if student is logged in
if (!isset($_SESSION['is_student']) || !isset($_SESSION['student_id'])) {
    header('Location: student_login.php');
    exit;
}

if (!isset($_SESSION['upgrade_plan_id']) || !isset($_SESSION['upgrade_proration'])) {
    header('Location: student_upgrade.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$new_plan_id = $_SESSION['upgrade_plan_id'];
$proration = $_SESSION['upgrade_proration'];

// Ensure all proration keys exist with defaults
$proration = array_merge([
    'amount' => 0,
    'type' => 'full',
    'credit' => 0,
    'unused_value' => 0,
    'new_cost' => 0,
    'days_remaining' => 0
], $proration);

// Get new plan details
$new_plan = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?");
$new_plan->execute([$new_plan_id]);
$new_plan = $new_plan->fetch();

// Get current membership
$current_membership = $pdo->prepare("
    SELECT m.*, mp.name as plan_name
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.student_id = ? AND m.status = 'active'
    ORDER BY m.end_date DESC
    LIMIT 1
");
$current_membership->execute([$student_id]);
$current_membership = $current_membership->fetch();

// Handle payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $active_gateway = getSetting('active_payment_gateway');
    
    if ($active_gateway === 'none' || empty($active_gateway)) {
        $message = showAlert('Payment gateway not configured. Please contact the studio.', 'error');
    } else {
        // TODO: Implement actual payment processing
        $payment_success = true;
        
        if ($payment_success) {
            // Cancel current membership
            if ($current_membership) {
                $stmt = $pdo->prepare("UPDATE memberships SET status = 'cancelled', end_date = CURDATE() WHERE id = ?");
                $stmt->execute([$current_membership['id']]);
            }
            
            // Create new membership
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $new_plan['duration_months'] . ' months'));
            
            $stmt = $pdo->prepare("
                INSERT INTO memberships (student_id, plan_id, start_date, end_date, status, payment_status, amount_paid)
                VALUES (?, ?, ?, ?, 'active', 'paid', ?)
            ");
            $stmt->execute([$student_id, $new_plan_id, $start_date, $end_date, $proration['amount']]);
            $membership_id = $pdo->lastInsertId();
            
            // Record payment
            $stmt = $pdo->prepare("
                INSERT INTO payments (student_id, payment_type, reference_id, amount, 
                                    payment_method, payment_date, receipt_number, notes)
                VALUES (?, 'membership', ?, ?, 'credit_card', CURDATE(), ?, ?)
            ");
            $stmt->execute([
                $student_id,
                $membership_id,
                $proration['amount'],
                generateReceiptNumber(),
                'Membership upgrade: ' . ($current_membership['plan_name'] ?? 'New') . ' → ' . $new_plan['name']
            ]);
            
            // Clear session
            unset($_SESSION['upgrade_plan_id']);
            unset($_SESSION['upgrade_proration']);
            
            header('Location: student_upgrade.php?success=upgrade');
            exit;
        }
    }
}

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Complete Membership Upgrade</h1>
            
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <p class="text-sm text-gray-600">Current Plan</p>
                        <p class="font-semibold text-gray-800"><?php echo $current_membership['plan_name'] ?? 'No membership'; ?></p>
                    </div>
                    <span class="text-2xl">→</span>
                    <div>
                        <p class="text-sm text-gray-600">New Plan</p>
                        <p class="font-semibold text-gray-800"><?php echo $new_plan['name']; ?></p>
                    </div>
                </div>
                
                <div class="pt-4 border-t border-blue-200 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Days Remaining:</span>
                        <span class="font-semibold"><?php echo $proration['days_remaining']; ?> days</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Unused Value:</span>
                        <span class="font-semibold"><?php echo formatMoney($proration['unused_value']); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">New Plan Cost (pro-rated):</span>
                        <span class="font-semibold"><?php echo formatMoney($proration['new_cost']); ?></span>
                    </div>
                    <div class="flex justify-between pt-2 border-t border-blue-200">
                        <span class="text-lg font-semibold text-gray-800">Amount Due:</span>
                        <span class="text-2xl font-bold text-green-600"><?php echo formatMoney($proration['amount']); ?></span>
                    </div>
                </div>
            </div>
            
            <?php 
            $active_gateway = getSetting('active_payment_gateway');
            if ($active_gateway === 'none' || empty($active_gateway)): 
            ?>
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-6 mb-6">
                    <p class="text-gray-700">
                        Online payment is not available. Please contact the studio to complete your membership upgrade.
                    </p>
                </div>
                <a href="student_upgrade.php" class="block w-full text-center bg-gray-600 hover:bg-gray-700 text-white font-bold py-3 px-4 rounded-lg">
                    Back to Plans
                </a>
            <?php else: ?>
                <form method="POST" id="payment-form">
                    <input type="hidden" name="process_payment" value="1">
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Card Information</label>
                        <div id="card-element" class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50">
                            <p class="text-gray-600 text-sm">Payment processing with <?php echo ucfirst($active_gateway); ?></p>
                        </div>
                    </div>
                    
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                        <p class="text-sm text-gray-700">
                            <strong>Demo Mode:</strong> Click "Pay Now" to simulate payment processing.
                        </p>
                    </div>
                    
                    <button type="submit" 
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-lg text-lg mb-4">
                        Pay Now - <?php echo formatMoney($proration['amount']); ?>
                    </button>
                </form>
                
                <a href="student_upgrade.php" class="block text-center text-blue-600 hover:text-blue-800">
                    Cancel and Return
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
