<?php
require_once 'config.php';
requireLogin();

// Only admins, super admins, and staff can approve registrations
if (!in_array(getCurrentUser()['role'], ['admin', 'super_admin', 'staff'])) {
    accessDenied('Registration approval requires Admin or Staff privileges.');
}

$message = '';

// Handle approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve'])) {
    verify_csrf();
    $student_id = $_POST['student_id'];
    $membership_id = $_POST['membership_id'];
    
    // Activate student
    $params = [$student_id];
    $stmt = $pdo->prepare("UPDATE students SET status = 'active' WHERE id = ?" . school_where());
    school_param($params);
    $stmt->execute($params);
    
    // Activate membership and mark as paid
    $params = [$membership_id];
    $stmt = $pdo->prepare("UPDATE memberships SET status = 'active', payment_status = 'paid', amount_paid = (SELECT price FROM membership_plans WHERE id = memberships.plan_id) WHERE id = ?" . school_where());
    school_param($params);
    $stmt->execute($params);
    
    // Record payment
    $params = [$membership_id];
    $membership = $pdo->prepare("SELECT * FROM memberships WHERE id = ?" . school_where());
    school_param($params);
    $membership->execute($params);
    $membership_data = $membership->fetch();
    
    $stmt = $pdo->prepare("
        INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount,
                            payment_method, payment_date, receipt_number, notes)
        VALUES (?, ?, 'membership', ?, ?, 'cash', CURDATE(), ?, 'Manual approval - Offline payment received')
    ");
    $stmt->execute([
        current_school_id(),
        $student_id,
        $membership_id,
        $membership_data['amount_paid'],
        generateReceiptNumber()
    ]);
    
    $message = showAlert('Registration approved and student activated!', 'success');
}

// Handle rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject'])) {
    verify_csrf();
    $student_id = $_POST['student_id'];
    $membership_id = $_POST['membership_id'];
    
    // Delete membership
    $params = [$membership_id];
    $stmt = $pdo->prepare("DELETE FROM memberships WHERE id = ?" . school_where());
    school_param($params);
    $stmt->execute($params);

    // Delete student
    $params = [$student_id];
    $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?" . school_where());
    school_param($params);
    $stmt->execute($params);
    
    $message = showAlert('Registration rejected and removed.', 'success');
}

// Get pending registrations
$params = [];
$stmt = $pdo->prepare("
    SELECT s.*, m.id as membership_id, mp.name as plan_name, mp.price as plan_price, m.created_at as registration_date
    FROM students s
    JOIN memberships m ON s.id = m.student_id
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE s.status = 'inactive' AND m.status = 'cancelled' AND m.payment_status = 'pending'" . school_where('s') . "
    ORDER BY m.created_at DESC
");
school_param($params);
$stmt->execute($params);
$pending = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Pending Registrations</h1>
            <p class="text-gray-600 mt-2">Review and approve student self-registrations awaiting payment</p>
        </div>
        <div class="bg-orange-100 text-orange-800 px-4 py-2 rounded-lg font-semibold">
            <?php echo count($pending); ?> Pending
        </div>
    </div>
    
    <?php if (empty($pending)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <div class="text-6xl mb-4">✅</div>
            <h2 class="text-2xl font-bold text-gray-800 mb-2">All Caught Up!</h2>
            <p class="text-gray-600">No pending registrations to review</p>
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($pending as $registration): ?>
                <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                    <div class="bg-orange-50 border-l-4 border-orange-500 px-6 py-3">
                        <div class="flex justify-between items-center">
                            <div>
                                <h3 class="text-lg font-bold text-gray-800">
                                    <?php echo $registration['first_name'] . ' ' . $registration['last_name']; ?>
                                </h3>
                                <p class="text-sm text-gray-600">
                                    Registered: <?php echo formatDateTime($registration['registration_date']); ?>
                                </p>
                            </div>
                            <span class="px-3 py-1 bg-orange-200 text-orange-800 rounded-full text-sm font-semibold">
                                AWAITING PAYMENT
                            </span>
                        </div>
                    </div>
                    
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                            <div>
                                <p class="text-sm text-gray-600">Email</p>
                                <p class="font-medium text-gray-800"><?php echo $registration['email']; ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Phone</p>
                                <p class="font-medium text-gray-800"><?php echo $registration['phone'] ?: 'N/A'; ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Date of Birth</p>
                                <p class="font-medium text-gray-800"><?php echo formatDate($registration['date_of_birth']); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Emergency Contact</p>
                                <p class="font-medium text-gray-800"><?php echo $registration['emergency_contact_name'] ?: 'N/A'; ?></p>
                                <p class="text-sm text-gray-600"><?php echo $registration['emergency_contact_phone'] ?: ''; ?></p>
                            </div>
                        </div>
                        
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm text-gray-600">Selected Plan</p>
                                    <p class="text-lg font-bold text-gray-800"><?php echo $registration['plan_name']; ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm text-gray-600">Amount Due</p>
                                    <p class="text-2xl font-bold text-green-600"><?php echo formatMoney($registration['plan_price']); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($registration['notes']): ?>
                            <div class="mb-4">
                                <p class="text-sm text-gray-600">Notes</p>
                                <p class="text-gray-800"><?php echo nl2br($registration['notes']); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="flex space-x-3">
                            <form method="POST" class="flex-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="approve" value="1">
                                <input type="hidden" name="student_id" value="<?php echo $registration['id']; ?>">
                                <input type="hidden" name="membership_id" value="<?php echo $registration['membership_id']; ?>">
                                <button type="submit" 
                                        class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg transition duration-200">
                                    ✓ Approve & Activate (Payment Received)
                                </button>
                            </form>
                            
                            <form method="POST" class="flex-1" onsubmit="return confirmDelete('Are you sure you want to reject this registration? This will delete the student account.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="reject" value="1">
                                <input type="hidden" name="student_id" value="<?php echo $registration['id']; ?>">
                                <input type="hidden" name="membership_id" value="<?php echo $registration['membership_id']; ?>">
                                <button type="submit" 
                                        class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-lg transition duration-200">
                                    ✗ Reject Registration
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
