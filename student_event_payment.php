<?php
require_once 'config.php';

// Check if student is logged in
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php?type=student');
    exit;
}

$student_id = $_SESSION['student_id'];
$registration_id = $_GET['registration_id'] ?? 0;
$message = '';

// Get registration and event details
$stmt = $pdo->prepare("
    SELECT er.*, e.name as event_name, e.registration_fee as fee, e.event_type, e.event_date
    FROM event_registrations er
    JOIN events e ON er.event_id = e.id
    WHERE er.id = ? AND er.student_id = ?
");
$stmt->execute([$registration_id, $student_id]);
$registration = $stmt->fetch();

if (!$registration) {
    header('Location: student_events.php');
    exit;
}

// Handle payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $active_gateway = getSetting('active_payment_gateway');
    
    if ($active_gateway === 'none' || empty($active_gateway)) {
        // No gateway - mark as pending and notify admin
        $stmt = $pdo->prepare("UPDATE event_registrations SET payment_status = 'pending' WHERE id = ?");
        $stmt->execute([$registration_id]);
        
        $message = showAlert('Payment gateway not configured. Your registration is pending. Please contact the studio to complete payment.', 'warning');
    } else {
        // TODO: Implement actual payment processing here
        // For now, simulate successful payment
        $payment_success = true;
        
        if ($payment_success) {
            // Update registration
            $stmt = $pdo->prepare("UPDATE event_registrations SET payment_status = 'paid' WHERE id = ?");
            $stmt->execute([$registration_id]);
            
            // Record payment
            $stmt = $pdo->prepare("
                INSERT INTO payments (student_id, payment_type, reference_id, amount, 
                                    payment_method, payment_date, receipt_number, notes)
                VALUES (?, 'event', ?, ?, 'credit_card', CURDATE(), ?, ?)
            ");
            $stmt->execute([
                $student_id,
                $registration['event_id'],
                $registration['fee'],
                generateReceiptNumber(),
                'Event registration: ' . $registration['event_name']
            ]);
            
            header('Location: student_events.php?success=payment');
            exit;
        }
    }
}

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <?php echo $message; ?>
        
        <div class="bg-white rounded-lg shadow-lg p-8">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Complete Event Payment</h1>
            
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
                <h3 class="font-semibold text-gray-800 mb-2"><?php echo $registration['event_name']; ?></h3>
                <p class="text-sm text-gray-600 mb-1">
                    <?php echo formatDate($registration['event_date']); ?>
                </p>
                <p class="text-sm text-gray-600 capitalize mb-3">
                    <?php echo str_replace('_', ' ', $registration['event_type']); ?>
                </p>
                <div class="pt-3 border-t border-blue-200">
                    <div class="flex justify-between items-center">
                        <span class="text-lg font-semibold text-gray-800">Amount Due:</span>
                        <span class="text-3xl font-bold text-green-600"><?php echo formatMoney($registration['fee']); ?></span>
                    </div>
                </div>
            </div>
            
            <?php 
            $active_gateway = getSetting('active_payment_gateway');
            if ($active_gateway === 'none' || empty($active_gateway)): 
            ?>
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-6 mb-6">
                    <p class="text-gray-700 mb-3">
                        <strong>Online payment is not available at this time.</strong>
                    </p>
                    <p class="text-gray-700">
                        Please contact the studio to complete your payment. Your registration will be confirmed once payment is received.
                    </p>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="process_payment" value="1">
                    <button type="submit" 
                            class="w-full bg-orange-600 hover:bg-orange-700 text-white font-bold py-3 px-4 rounded-lg">
                        Confirm Registration (Payment Required at Studio)
                    </button>
                </form>
            <?php else: ?>
                <form method="POST" id="payment-form">
                    <input type="hidden" name="process_payment" value="1">
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Card Information</label>
                        <div id="card-element" class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50">
                            <p class="text-gray-600 text-sm">Payment processing with <?php echo ucfirst($active_gateway); ?></p>
                        </div>
                        <div id="card-errors" class="text-red-600 text-sm mt-2"></div>
                    </div>
                    
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                        <p class="text-sm text-gray-700">
                            <strong>Demo Mode:</strong> Click "Pay Now" to simulate payment. 
                            Configure actual Stripe/Square API keys in admin settings for real payment processing.
                        </p>
                    </div>
                    
                    <button type="submit" 
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-lg text-lg">
                        Pay Now - <?php echo formatMoney($registration['fee']); ?>
                    </button>
                </form>
            <?php endif; ?>
            
            <div class="mt-6 text-center">
                <a href="student_events.php" class="text-blue-600 hover:text-blue-800">Cancel and Return to Events</a>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/student_footer.php'; ?>
