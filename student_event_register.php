<?php
require_once 'config.php';

// Check if student is logged in
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php?type=student');
    exit;
}
require_student_payment_clear();

$student_id = $_SESSION['student_id'];
$event_id = $_GET['event_id'] ?? 0;
$message = '';

// Fetch student info (including DOB)
$studentStmt = $pdo->prepare("SELECT first_name, last_name, date_of_birth FROM students WHERE id = ?");
$studentStmt->execute([$student_id]);
$student_info = $studentStmt->fetch();

// Handle DOB save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_dob'])) {
    $dob = $_POST['date_of_birth'] ?? '';
    if ($dob) {
        $dobStmt = $pdo->prepare("UPDATE students SET date_of_birth = ? WHERE id = ?");
        $dobStmt->execute([$dob, $student_id]);
        $student_info['date_of_birth'] = $dob;
        $message = showAlert('Date of birth saved successfully!', 'success');
    } else {
        $message = showAlert('Please enter a valid date of birth.', 'error');
    }
}

$student_dob = $student_info['date_of_birth'] ?? null;

// Get event details
$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch();

if (!$event) {
    header('Location: student_events.php');
    exit;
}

// Calendar-only events don't allow registration
if (empty($event['requires_registration'])) {
    header('Location: student_events.php');
    exit;
}

// Check if already registered
$check_reg = $pdo->prepare("SELECT * FROM event_registrations WHERE student_id = ? AND event_id = ?");
$check_reg->execute([$student_id, $event_id]);
$existing_registration = $check_reg->fetch();

// Handle registration submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    if ($existing_registration) {
        $message = showAlert('You are already registered for this event!', 'error');
    } else {
        // Check if event is full
        $count = $pdo->prepare("SELECT COUNT(*) as count FROM event_registrations WHERE event_id = ?");
        $count->execute([$event_id]);
        $current_count = $count->fetch()['count'];
        
        if ($event['max_participants'] > 0 && $current_count >= $event['max_participants']) {
            $message = showAlert('Sorry, this event is full!', 'error');
        } else {
            // Create registration
            $stmt = $pdo->prepare("
                INSERT INTO event_registrations (student_id, event_id, registration_date, payment_status, attendance_status)
                VALUES (?, ?, CURDATE(), 'pending', 'registered')
            ");
            $stmt->execute([$student_id, $event_id]);
            $registration_id = $pdo->lastInsertId();
            
            // Redirect to payment if fee > 0
            if ($event['registration_fee'] > 0) {
                header('Location: student_event_payment.php?registration_id=' . $registration_id);
                exit;
            } else {
                // Free event - mark as paid
                $stmt = $pdo->prepare("UPDATE event_registrations SET payment_status = 'waived' WHERE id = ?");
                $stmt->execute([$registration_id]);
                
                header('Location: student_events.php?success=1');
                exit;
            }
        }
    }
}

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-3xl mx-auto">
        <div class="mb-6">
            <a href="student_events.php" class="text-blue-600 hover:text-blue-800">← Back to Events</a>
        </div>
        
        <?php echo $message; ?>
        
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-6">
                <h1 class="text-3xl font-bold mb-2"><?php echo $event['name']; ?></h1>
                <p class="text-lg opacity-90 capitalize"><?php echo str_replace('_', ' ', $event['event_type']); ?></p>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <p class="text-sm text-gray-600 mb-1">Date</p>
                        <p class="text-lg font-semibold text-gray-900"><?php echo formatDate($event['event_date']); ?></p>
                    </div>
                    
                    <?php if ($event['location']): ?>
                        <div>
                            <p class="text-sm text-gray-600 mb-1">Location</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $event['location']; ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <div>
                        <p class="text-sm text-gray-600 mb-1">Registration Fee</p>
                        <p class="text-2xl font-bold text-green-600"><?php echo formatMoney($event['registration_fee']); ?></p>
                    </div>
                    
                    <?php if ($event['max_participants'] > 0): ?>
                        <div>
                            <p class="text-sm text-gray-600 mb-1">Capacity</p>
                            <?php 
                            $count = $pdo->prepare("SELECT COUNT(*) as count FROM event_registrations WHERE event_id = ?");
                            $count->execute([$event_id]);
                            $current_count = $count->fetch()['count'];
                            $spots_left = $event['max_participants'] - $current_count;
                            ?>
                            <p class="text-lg font-semibold text-gray-900">
                                <?php echo $spots_left; ?> spots remaining
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($event['description']): ?>
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Event Details</h3>
                        <p class="text-gray-700 whitespace-pre-line"><?php echo $event['description']; ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if ($event['requirements']): ?>
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Requirements</h3>
                        <p class="text-gray-700 whitespace-pre-line"><?php echo $event['requirements']; ?></p>
                    </div>
                <?php endif; ?>
                
                <!-- Date of Birth Section -->
                <div class="mb-6">
                    <?php if ($student_dob): ?>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600 mb-0.5">Date of Birth</p>
                                    <p class="text-lg font-semibold text-gray-900"><?php echo formatDate($student_dob); ?></p>
                                </div>
                                <span class="text-green-600">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="bg-amber-50 border-2 border-amber-300 rounded-lg p-5">
                            <div class="flex items-start gap-3 mb-3">
                                <span class="text-2xl flex-shrink-0">&#9888;&#65039;</span>
                                <div>
                                    <h3 class="font-semibold text-amber-800 mb-1">Date of Birth Required</h3>
                                    <p class="text-sm text-amber-700">Please enter your date of birth before registering for this event. This will be saved to your profile.</p>
                                </div>
                            </div>
                            <form method="POST" class="flex items-end gap-3">
                                <div class="flex-1">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                                    <input type="date" name="date_of_birth" required max="<?php echo date('Y-m-d'); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                                </div>
                                <button type="submit" name="save_dob" value="1"
                                        class="bg-amber-600 hover:bg-amber-700 text-white font-medium py-2 px-6 rounded-lg transition">
                                    Save
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="pt-6 border-t border-gray-200">
                    <?php if ($existing_registration): ?>
                        <div class="bg-green-50 border border-green-200 rounded-lg p-6 text-center">
                            <div class="text-4xl mb-3">✓</div>
                            <h3 class="text-xl font-bold text-gray-800 mb-2">You're Registered!</h3>
                            <?php if ($existing_registration['payment_status'] === 'pending'): ?>
                                <p class="text-gray-700 mb-4">Complete your payment to confirm your registration</p>
                                <a href="student_event_payment.php?registration_id=<?php echo $existing_registration['id']; ?>" 
                                   class="inline-block bg-orange-600 hover:bg-orange-700 text-white font-bold py-3 px-8 rounded-lg transition">
                                    Complete Payment - <?php echo formatMoney($event['fee']); ?>
                                </a>
                            <?php else: ?>
                                <p class="text-gray-700">Your registration is confirmed. See you at the event!</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
                                <h3 class="font-semibold text-gray-800 mb-3">Registration Summary</h3>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700">Registration Fee:</span>
                                    <span class="text-2xl font-bold text-green-600"><?php echo formatMoney($event['registration_fee']); ?></span>
                                </div>
                                <?php if ($student_dob): ?>
                                    <div class="flex justify-between items-center mt-2">
                                        <span class="text-gray-700">Date of Birth:</span>
                                        <span class="font-medium text-gray-900"><?php echo formatDate($student_dob); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($event['registration_fee'] > 0): ?>
                                    <p class="text-sm text-gray-600 mt-3">
                                        You will be redirected to payment after registration
                                    </p>
                                <?php endif; ?>
                            </div>

                            <?php if ($student_dob): ?>
                                <button type="submit" name="register" value="1"
                                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 px-6 rounded-lg text-lg transition">
                                    <?php echo $event['registration_fee'] > 0 ? 'Register & Proceed to Payment' : 'Register Now (Free)'; ?>
                                </button>
                            <?php else: ?>
                                <button type="button" disabled
                                        class="w-full bg-gray-400 text-white font-bold py-4 px-6 rounded-lg text-lg cursor-not-allowed">
                                    Please Enter Date of Birth Above to Register
                                </button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/student_footer.php'; ?>
