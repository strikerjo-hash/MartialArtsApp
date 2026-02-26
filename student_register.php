<?php
require_once 'config.php';

$message = '';
$step = $_GET['step'] ?? 1;

// Get available membership plans
$plans_stmt = $pdo->prepare("SELECT * FROM membership_plans WHERE status = 'active' AND (is_grandfathered = 0 OR is_grandfathered IS NULL)" . school_where() . " ORDER BY price ASC");
$params = [];
school_param($params);
$plans_stmt->execute($params);
$plans = $plans_stmt->fetchAll();

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    verify_csrf();
    // Validate email doesn't exist
    $check = $pdo->prepare("SELECT id FROM students WHERE email = ?" . school_where());
    $params = [sanitizeInput($_POST['email'])];
    school_param($params);
    $check->execute($params);
    
    if ($check->fetch()) {
        $message = showAlert('An account with this email already exists!', 'error');
    } else {
        // Create password hash
        $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        // Insert student
        $stmt = $pdo->prepare("
            INSERT INTO students (school_id, first_name, last_name, email, phone, date_of_birth, 
                                address, emergency_contact_name, emergency_contact_phone, 
                                join_date, status, password)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'active', ?)
        ");
        $stmt->execute([
            current_school_id(),
            sanitizeInput($_POST['first_name']),
            sanitizeInput($_POST['last_name']),
            sanitizeInput($_POST['email']),
            sanitizeInput($_POST['phone']),
            $_POST['date_of_birth'],
            sanitizeInput($_POST['address']),
            sanitizeInput($_POST['emergency_contact_name']),
            sanitizeInput($_POST['emergency_contact_phone']),
            $password_hash
        ]);
        
        $student_id = $pdo->lastInsertId();
        
        // Store student info in session for payment
        $_SESSION['registration_student_id'] = $student_id;
        $_SESSION['registration_plan_id'] = $_POST['plan_id'];
        
        // Redirect to payment
        header('Location: student_register.php?step=2');
        exit;
    }
}

// Handle payment processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    verify_csrf();
    $student_id = $_SESSION['registration_student_id'];
    $plan_id = $_SESSION['registration_plan_id'];
    
    // Get plan details
    $plan = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?" . school_where());
    $params = [$plan_id];
    school_param($params);
    $plan->execute($params);
    $plan_data = $plan->fetch();
    
    // Get active payment gateway
    $active_gateway = getSetting('active_payment_gateway');
    
    // CRITICAL: Require payment gateway to be configured
    if ($active_gateway === 'none' || empty($active_gateway)) {
        $message = showAlert('Payment processing is not configured. Please contact the studio to complete your registration.', 'error');
        
        // Mark student as inactive until payment is processed manually
        $stmt = $pdo->prepare("UPDATE students SET status = 'inactive' WHERE id = ?" . school_where());
        $params = [$student_id];
        school_param($params);
        $stmt->execute($params);
    } else {
        // Process payment based on active gateway
        $payment_success = false;
        $payment_error = '';
        
        if ($active_gateway === 'stripe') {
            // Stripe payment processing
            $stripe_secret = getSetting('stripe_secret_key');
            
            if (empty($stripe_secret)) {
                $payment_error = 'Stripe is not properly configured.';
            } else {
                // TODO: Implement actual Stripe API call here
                // Example:
                // require_once 'vendor/autoload.php';
                // \Stripe\Stripe::setApiKey($stripe_secret);
                // $charge = \Stripe\Charge::create([...]);
                
                // For now, require manual admin approval
                $payment_error = 'Stripe integration pending. Please contact studio to complete payment.';
            }
            
        } elseif ($active_gateway === 'square') {
            // Square payment processing
            $square_token = getSetting('square_access_token');
            
            if (empty($square_token)) {
                $payment_error = 'Square is not properly configured.';
            } else {
                // TODO: Implement actual Square API call here
                // Example:
                // $client = new \Square\SquareClient([...]);
                // $result = $client->getPaymentsApi()->createPayment(...);
                
                // For now, require manual admin approval
                $payment_error = 'Square integration pending. Please contact studio to complete payment.';
            }
        }
        
        if ($payment_success) {
            // Create membership
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $plan_data['duration_months'] . ' months'));
            
            $stmt = $pdo->prepare("
                INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date, status, payment_status, amount_paid)
                VALUES (?, ?, ?, ?, ?, 'active', 'paid', ?)
            ");
            $stmt->execute([current_school_id(), $student_id, $plan_id, $start_date, $end_date, $plan_data['price']]);
            $membership_id = $pdo->lastInsertId();
            
            // Record payment
            $stmt = $pdo->prepare("
                INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount, 
                                    payment_method, payment_date, receipt_number, notes)
                VALUES (?, ?, 'membership', ?, ?, 'credit_card', CURDATE(), ?, 'Initial membership payment - Self registration')
            ");
            $stmt->execute([
                current_school_id(),
                $student_id,
                $membership_id,
                $plan_data['price'],
                generateReceiptNumber()
            ]);
            
            // Clear session
            unset($_SESSION['registration_student_id']);
            unset($_SESSION['registration_plan_id']);
            
            // Redirect to success
            header('Location: student_register.php?step=3');
            exit;
        } else {
            // Payment failed or gateway not ready
            $message = showAlert($payment_error ?: 'Payment processing failed. Please try again.', 'error');
            
            // Mark student as inactive and create pending membership
            $stmt = $pdo->prepare("UPDATE students SET status = 'inactive' WHERE id = ?" . school_where());
            $params = [$student_id];
            school_param($params);
            $stmt->execute($params);
            
            // Create pending membership
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $plan_data['duration_months'] . ' months'));
            
            $stmt = $pdo->prepare("
                INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date, status, payment_status, amount_paid)
                VALUES (?, ?, ?, ?, ?, 'cancelled', 'pending', 0)
            ");
            $stmt->execute([current_school_id(), $student_id, $plan_id, $start_date, $end_date]);
            
            // Redirect to pending page
            header('Location: student_register.php?step=pending');
            exit;
        }
    }
}

// Get selected plan for step 2
$selected_plan = null;
if ($step == 2 && isset($_SESSION['registration_plan_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?" . school_where());
    $params = [$_SESSION['registration_plan_id']];
    school_param($params);
    $stmt->execute($params);
    $selected_plan = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://js.stripe.com/v3/"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen py-12 px-4">
        <div class="max-w-4xl mx-auto">
            <div class="text-center mb-8">
                <a href="login.php" class="text-blue-600 hover:text-blue-800 text-sm">← Back to Login</a>
                <h1 class="text-3xl font-bold text-gray-800 mt-4">Student Registration</h1>
                <p class="text-gray-600 mt-2">Join our martial arts community today!</p>
            </div>
            
            <!-- Progress Steps -->
            <div class="mb-8">
                <div class="flex items-center justify-center">
                    <div class="flex items-center">
                        <div class="flex items-center text-sm">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $step >= 1 ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600'; ?>">
                                1
                            </div>
                            <span class="ml-2 font-medium">Account Info</span>
                        </div>
                        <div class="w-24 h-1 mx-4 <?php echo $step >= 2 ? 'bg-blue-600' : 'bg-gray-300'; ?>"></div>
                        <div class="flex items-center text-sm">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $step >= 2 ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600'; ?>">
                                2
                            </div>
                            <span class="ml-2 font-medium">Payment</span>
                        </div>
                        <div class="w-24 h-1 mx-4 <?php echo $step >= 3 ? 'bg-blue-600' : 'bg-gray-300'; ?>"></div>
                        <div class="flex items-center text-sm">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $step >= 3 ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600'; ?>">
                                3
                            </div>
                            <span class="ml-2 font-medium">Complete</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php echo $message; ?>
            
            <?php if ($step == 1): ?>
                <!-- Step 1: Registration Form -->
                <div class="bg-white rounded-lg shadow-lg p-8">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6">Create Your Account</h2>
                    
                    <form method="POST" class="space-y-6">
                        <?= csrf_field() ?>
                        <input type="hidden" name="register" value="1">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">First Name *</label>
                                <input type="text" name="first_name" required
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Last Name *</label>
                                <input type="text" name="last_name" required
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                                <input type="email" name="email" required
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Phone *</label>
                                <input type="tel" name="phone" required
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date of Birth *</label>
                            <input type="date" name="date_of_birth" required
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                            <textarea name="address" rows="2"
                                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Emergency Contact Name</label>
                                <input type="text" name="emergency_contact_name"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Emergency Contact Phone</label>
                                <input type="tel" name="emergency_contact_phone"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Password *</label>
                                <input type="password" name="password" required minlength="6"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                                <p class="text-xs text-gray-500 mt-1">Minimum 6 characters</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Confirm Password *</label>
                                <input type="password" name="confirm_password" required minlength="6"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            </div>
                        </div>
                        
                        <div class="border-t border-gray-200 pt-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Select Membership Plan *</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <?php foreach ($plans as $plan): ?>
                                    <label class="relative cursor-pointer">
                                        <input type="radio" name="plan_id" value="<?php echo $plan['id']; ?>" required
                                               class="peer sr-only">
                                        <div class="border-2 border-gray-300 rounded-lg p-4 peer-checked:border-blue-600 peer-checked:bg-blue-50 hover:border-blue-400">
                                            <p class="font-bold text-gray-800"><?php echo $plan['name']; ?></p>
                                            <p class="text-2xl font-bold text-blue-600 mt-2"><?php echo formatMoney($plan['price']); ?></p>
                                            <p class="text-sm text-gray-600"><?php echo $plan['duration_months']; ?> month(s)</p>
                                            <p class="text-sm text-gray-600 mt-2"><?php echo $plan['description']; ?></p>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="terms" required class="mr-2">
                            <label for="terms" class="text-sm text-gray-700">
                                I agree to the terms and conditions and waiver of liability
                            </label>
                        </div>
                        
                        <button type="submit" 
                                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition duration-200">
                            Continue to Payment
                        </button>
                    </form>
                </div>
                
            <?php elseif ($step == 2 && $selected_plan): ?>
                <!-- Step 2: Payment -->
                <div class="bg-white rounded-lg shadow-lg p-8">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6">Complete Your Payment</h2>
                    
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <p class="font-semibold text-gray-800">Selected Plan: <?php echo $selected_plan['name']; ?></p>
                        <p class="text-2xl font-bold text-blue-600 mt-2"><?php echo formatMoney($selected_plan['price']); ?></p>
                    </div>
                    
                    <?php 
                    $active_gateway = getSetting('active_payment_gateway');
                    if ($active_gateway === 'none' || empty($active_gateway)): 
                    ?>
                        <!-- No Payment Gateway Configured -->
                        <div class="bg-orange-50 border border-orange-200 rounded-lg p-6 mb-6">
                            <div class="flex items-start">
                                <div class="text-orange-500 text-3xl mr-4">⚠️</div>
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Payment Processing Unavailable</h3>
                                    <p class="text-gray-700 mb-3">
                                        Online payment processing is not currently available. Your account has been created 
                                        but is <strong>inactive</strong> until payment is received.
                                    </p>
                                    <p class="text-gray-700 mb-3">
                                        <strong>To complete your registration:</strong>
                                    </p>
                                    <ul class="list-disc list-inside text-gray-700 space-y-1 mb-4">
                                        <li>Visit the studio in person to make payment</li>
                                        <li>Call us to provide payment over the phone</li>
                                        <li>Contact us for alternative payment arrangements</li>
                                    </ul>
                                    <p class="text-sm text-gray-600">
                                        Amount Due: <span class="font-bold text-lg"><?php echo formatMoney($selected_plan['price']); ?></span>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="process_payment" value="1">
                            <button type="submit"
                                    class="w-full bg-orange-600 hover:bg-orange-700 text-white font-bold py-3 px-4 rounded-lg transition duration-200">
                                Continue (Payment Required at Studio)
                            </button>
                        </form>
                        
                    <?php elseif ($active_gateway === 'stripe'): ?>
                        <!-- Stripe Payment Form -->
                        <form method="POST" id="payment-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="process_payment" value="1">
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Card Information</label>
                                <div id="card-element" class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50">
                                    <!-- Stripe card element will be inserted here -->
                                    <p class="text-gray-600 text-sm">Stripe payment form will appear here when Stripe.js is loaded</p>
                                </div>
                                <div id="card-errors" class="text-red-600 text-sm mt-2"></div>
                            </div>
                            
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                                <p class="text-sm text-gray-700">
                                    <strong>Note:</strong> To enable actual Stripe payment processing, the studio admin needs to 
                                    configure Stripe API keys in the Settings page. Currently, registration will be marked as pending.
                                </p>
                            </div>
                            
                            <button type="submit" 
                                    class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg transition duration-200">
                                Process Payment - <?php echo formatMoney($selected_plan['price']); ?>
                            </button>
                        </form>
                        
                    <?php elseif ($active_gateway === 'square'): ?>
                        <!-- Square Payment Form -->
                        <form method="POST" id="payment-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="process_payment" value="1">
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Card Information</label>
                                <div id="card-container" class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50">
                                    <!-- Square card element will be inserted here -->
                                    <p class="text-gray-600 text-sm">Square payment form will appear here when Square Web SDK is loaded</p>
                                </div>
                                <div id="card-errors" class="text-red-600 text-sm mt-2"></div>
                            </div>
                            
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                                <p class="text-sm text-gray-700">
                                    <strong>Note:</strong> To enable actual Square payment processing, the studio admin needs to 
                                    configure Square API credentials in the Settings page. Currently, registration will be marked as pending.
                                </p>
                            </div>
                            
                            <button type="submit" 
                                    class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg transition duration-200">
                                Process Payment - <?php echo formatMoney($selected_plan['price']); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                
            <?php elseif ($step == 'pending'): ?>
                <!-- Pending Approval Page -->
                <div class="bg-white rounded-lg shadow-lg p-8 text-center">
                    <div class="text-6xl mb-4">⏳</div>
                    <h2 class="text-3xl font-bold text-gray-800 mb-4">Registration Pending</h2>
                    <p class="text-lg text-gray-600 mb-6">
                        Your account has been created but your membership is pending payment verification.
                    </p>
                    
                    <div class="bg-orange-50 border border-orange-200 rounded-lg p-6 mb-6">
                        <p class="text-gray-700 mb-2">
                            <strong>Your account status: INACTIVE</strong>
                        </p>
                        <p class="text-gray-700 mb-4">
                            You will not be able to access classes or the student portal until payment is processed.
                        </p>
                        <div class="text-left text-gray-700 space-y-2">
                            <p><strong>Next Steps:</strong></p>
                            <ol class="list-decimal list-inside space-y-1">
                                <li>Contact the studio to complete your payment</li>
                                <li>Payment can be made in person, by phone, or online once processing is configured</li>
                                <li>Once payment is verified, your account will be activated</li>
                                <li>You'll receive an email confirmation when activated</li>
                            </ol>
                        </div>
                    </div>
                    
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <p class="text-sm text-gray-700">
                            <strong>Need Help?</strong> Contact the studio for assistance with payment processing.
                        </p>
                    </div>
                    
                    <a href="login.php?type=student" 
                       class="inline-block bg-gray-600 hover:bg-gray-700 text-white font-bold py-3 px-8 rounded-lg transition duration-200">
                        Go to Login Page
                    </a>
                </div>
                
            <?php elseif ($step == 3): ?>
                <!-- Step 3: Success -->
                <div class="bg-white rounded-lg shadow-lg p-8 text-center">
                    <div class="text-6xl mb-4">✅</div>
                    <h2 class="text-3xl font-bold text-gray-800 mb-4">Registration Complete!</h2>
                    <p class="text-lg text-gray-600 mb-6">
                        Welcome to <?php echo APP_NAME; ?>! Your account has been created and your membership is now active.
                    </p>
                    
                    <div class="bg-green-50 border border-green-200 rounded-lg p-6 mb-6">
                        <p class="text-gray-700 mb-2">
                            <strong>Next Steps:</strong>
                        </p>
                        <ul class="text-left text-gray-700 space-y-2">
                            <li>✓ Check your email for confirmation</li>
                            <li>✓ Login to your student portal</li>
                            <li>✓ View your class schedule</li>
                            <li>✓ Track your progress</li>
                        </ul>
                    </div>
                    
                    <a href="login.php?type=student" 
                       class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-8 rounded-lg transition duration-200">
                        Go to Student Portal
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Validate password match
        document.querySelector('form')?.addEventListener('submit', function(e) {
            const password = document.querySelector('input[name="password"]');
            const confirm = document.querySelector('input[name="confirm_password"]');
            
            if (password && confirm && password.value !== confirm.value) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });
    </script>
</body>
</html>
