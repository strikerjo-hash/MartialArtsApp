<?php
require_once 'config.php';

$error = '';
$login_type = $_GET['type'] ?? 'staff'; // staff or student

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_type = $_POST['login_type'];
    
    if ($login_type === 'staff') {
        // Staff/Admin/Instructor Login
        $username = sanitizeInput($_POST['username']);
        $password = $_POST['password'];
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_type'] = 'staff';
            
            // Redirect based on role
            switch ($user['role']) {
                case 'admin':
                    header('Location: index.php');
                    break;
                case 'instructor':
                    header('Location: index.php');
                    break;
                case 'staff':
                    header('Location: index.php');
                    break;
                default:
                    header('Location: index.php');
            }
            exit;
        } else {
            $error = 'Invalid username or password';
        }
    } else {
        // Student Login
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        
        $stmt = $pdo->prepare("SELECT * FROM students WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $student = $stmt->fetch();
        
        if ($student && isset($student['password']) && password_verify($password, $student['password'])) {
            $_SESSION['student_id'] = $student['id'];
            $_SESSION['student_name'] = $student['first_name'] . ' ' . $student['last_name'];
            $_SESSION['role'] = 'student';
            $_SESSION['user_type'] = 'student';
            header('Location: student_portal.php');
            exit;
        } else {
            $error = 'Invalid email or password. Make sure you have registered an account.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center py-12 px-4">
        <div class="max-w-md w-full">
            <div class="bg-white rounded-lg shadow-lg p-8">
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold text-gray-800">🥋</h1>
                    <h2 class="text-2xl font-bold text-gray-800 mt-2"><?php echo APP_NAME; ?></h2>
                    <p class="text-gray-600 mt-2">Sign in to your account</p>
                </div>
                
                <!-- Login Type Toggle -->
                <div class="flex mb-6 bg-gray-100 rounded-lg p-1">
                    <button type="button" onclick="switchLoginType('staff')" 
                            id="staff-tab"
                            class="flex-1 py-2 px-4 rounded-md text-sm font-medium transition-all <?php echo $login_type === 'staff' ? 'bg-white text-blue-600 shadow' : 'text-gray-600'; ?>">
                        Staff / Instructor / Admin
                    </button>
                    <button type="button" onclick="switchLoginType('student')" 
                            id="student-tab"
                            class="flex-1 py-2 px-4 rounded-md text-sm font-medium transition-all <?php echo $login_type === 'student' ? 'bg-white text-blue-600 shadow' : 'text-gray-600'; ?>">
                        Student
                    </button>
                </div>
                
                <?php if ($error): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Staff Login Form -->
                <form method="POST" action="" id="staff-form" style="display: <?php echo $login_type === 'staff' ? 'block' : 'none'; ?>;">
                    <input type="hidden" name="login_type" value="staff">
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="username">
                            Username
                        </label>
                        <input type="text" name="username" id="username" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="staff_password">
                            Password
                        </label>
                        <input type="password" name="password" id="staff_password" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <button type="submit" 
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition duration-200">
                        Sign In as Staff
                    </button>
                </form>
                
                <!-- Student Login Form -->
                <form method="POST" action="" id="student-form" style="display: <?php echo $login_type === 'student' ? 'block' : 'none'; ?>;">
                    <input type="hidden" name="login_type" value="student">
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="email">
                            Email Address
                        </label>
                        <input type="email" name="email" id="email" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="student_password">
                            Password
                        </label>
                        <input type="password" name="password" id="student_password" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <button type="submit" 
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition duration-200">
                        Sign In as Student
                    </button>
                    
                    <div class="mt-4 text-center">
                        <a href="student_register.php" class="text-blue-600 hover:text-blue-800 text-sm">
                            Don't have an account? Register here →
                        </a>
                    </div>
                </form>
                
                <div class="mt-6 text-center text-sm text-gray-600">
                    <p>Having trouble logging in? Contact support</p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function switchLoginType(type) {
            const staffForm = document.getElementById('staff-form');
            const studentForm = document.getElementById('student-form');
            const staffTab = document.getElementById('staff-tab');
            const studentTab = document.getElementById('student-tab');
            
            if (type === 'staff') {
                staffForm.style.display = 'block';
                studentForm.style.display = 'none';
                staffTab.classList.add('bg-white', 'text-blue-600', 'shadow');
                staffTab.classList.remove('text-gray-600');
                studentTab.classList.remove('bg-white', 'text-blue-600', 'shadow');
                studentTab.classList.add('text-gray-600');
            } else {
                staffForm.style.display = 'none';
                studentForm.style.display = 'block';
                studentTab.classList.add('bg-white', 'text-blue-600', 'shadow');
                studentTab.classList.remove('text-gray-600');
                staffTab.classList.remove('bg-white', 'text-blue-600', 'shadow');
                staffTab.classList.add('text-gray-600');
            }
        }
    </script>
</body>
</html>
