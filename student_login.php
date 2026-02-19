<?php
require_once 'config.php';
require_once __DIR__ . '/includes/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email']);
    $dob = $_POST['dob'];

    $stmt = $pdo->prepare("SELECT * FROM students WHERE email = ? AND date_of_birth = ? AND status = 'active'");
    $stmt->execute([$email, $dob]);
    $student = $stmt->fetch();

    if ($student) {
        $_SESSION['student_id'] = $student['id'];
        $_SESSION['student_name'] = $student['first_name'] . ' ' . $student['last_name'];
        $_SESSION['is_student'] = true;
        $_SESSION['user_type'] = 'student';
        // Cache payment lockout status on login
        refresh_payment_lockout_status();
        header('Location: ' . (is_student_payment_locked() ? 'student_payment.php?lockout=1' : 'student_portal.php'));
        exit;
    } else {
        $error = 'Invalid credentials or inactive account';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center">
        <div class="max-w-md w-full">
            <!-- Back to Staff Login -->
            <div class="text-center mb-4">
                <a href="login.php" class="text-blue-600 hover:text-blue-800 text-sm">
                    ← Staff Login
                </a>
            </div>
            
            <div class="bg-white rounded-lg shadow-lg p-8">
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold text-gray-800">🥋</h1>
                    <h2 class="text-2xl font-bold text-gray-800 mt-2">Student Portal</h2>
                    <p class="text-gray-600 mt-2">View your progress and schedule</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="email">
                            Email Address
                        </label>
                        <input type="email" name="email" id="email" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="dob">
                            Date of Birth
                        </label>
                        <input type="date" name="dob" id="dob" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <button type="submit" 
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition duration-200">
                        Sign In
                    </button>
                </form>
                
                <div class="mt-6 text-center text-sm text-gray-600">
                    <p>Use your registered email and date of birth to login</p>
                    <p class="mt-2">Need help? Contact your instructor</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
