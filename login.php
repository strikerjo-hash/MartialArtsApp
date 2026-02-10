<?php
require_once 'config.php';

$error = '';
$login_type = $_GET['type'] ?? 'staff'; // staff or student

$theme = getActiveTheme();
$logoPath = getLogoPath();
$siteName = getSiteName();

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
            $_SESSION['is_student'] = true;
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
    <title>Login - <?php echo htmlspecialchars($siteName); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .login-bg {
            background: linear-gradient(135deg, <?php echo $theme['gradient_from']; ?> 0%, <?php echo $theme['primary']; ?> 50%, <?php echo $theme['accent']; ?> 100%);
        }
        .tab-active {
            background-color: white;
            color: <?php echo $theme['primary']; ?>;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .btn-primary {
            background-color: <?php echo $theme['primary']; ?>;
        }
        .btn-primary:hover {
            background-color: <?php echo $theme['primary_hover']; ?>;
        }
        .link-primary {
            color: <?php echo $theme['primary']; ?>;
        }
        .link-primary:hover {
            color: <?php echo $theme['primary_hover']; ?>;
        }
        .input-focus:focus {
            border-color: <?php echo $theme['primary']; ?>;
            box-shadow: 0 0 0 3px <?php echo $theme['primary']; ?>22;
        }
    </style>
</head>
<body class="login-bg">
    <div class="min-h-screen flex items-center justify-center py-12 px-4">
        <div class="max-w-md w-full">
            <div class="bg-white rounded-2xl shadow-2xl p-8">
                <div class="text-center mb-8">
                    <?php if ($logoPath): ?>
                        <div class="flex justify-center mb-3">
                            <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo" class="max-h-16 object-contain">
                        </div>
                    <?php else: ?>
                        <h1 class="text-4xl mb-2">🥋</h1>
                    <?php endif; ?>
                    <h2 class="text-2xl font-bold text-gray-800 mt-2"><?php echo htmlspecialchars($siteName); ?></h2>
                    <p class="text-gray-500 mt-1 text-sm">Sign in to your account</p>
                </div>

                <!-- Login Type Toggle -->
                <div class="flex mb-6 bg-gray-100 rounded-lg p-1">
                    <button type="button" onclick="switchLoginType('staff')"
                            id="staff-tab"
                            class="flex-1 py-2 px-4 rounded-md text-sm font-medium transition-all <?php echo $login_type === 'staff' ? 'tab-active' : 'text-gray-600'; ?>">
                        Staff / Instructor / Admin
                    </button>
                    <button type="button" onclick="switchLoginType('student')"
                            id="student-tab"
                            class="flex-1 py-2 px-4 rounded-md text-sm font-medium transition-all <?php echo $login_type === 'student' ? 'tab-active' : 'text-gray-600'; ?>">
                        Student
                    </button>
                </div>

                <?php if ($error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <!-- Staff Login Form -->
                <form method="POST" action="" id="staff-form" style="display: <?php echo $login_type === 'staff' ? 'block' : 'none'; ?>;">
                    <input type="hidden" name="login_type" value="staff">

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-medium mb-2" for="username">
                            Username
                        </label>
                        <input type="text" name="username" id="username" required
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all">
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-medium mb-2" for="staff_password">
                            Password
                        </label>
                        <input type="password" name="password" id="staff_password" required
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all">
                    </div>

                    <button type="submit"
                            class="w-full btn-primary text-white font-bold py-3 px-4 rounded-lg transition duration-200">
                        Sign In as Staff
                    </button>
                </form>

                <!-- Student Login Form -->
                <form method="POST" action="" id="student-form" style="display: <?php echo $login_type === 'student' ? 'block' : 'none'; ?>;">
                    <input type="hidden" name="login_type" value="student">

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-medium mb-2" for="email">
                            Email Address
                        </label>
                        <input type="email" name="email" id="email" required
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all">
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-medium mb-2" for="student_password">
                            Password
                        </label>
                        <input type="password" name="password" id="student_password" required
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all">
                    </div>

                    <button type="submit"
                            class="w-full btn-primary text-white font-bold py-3 px-4 rounded-lg transition duration-200">
                        Sign In as Student
                    </button>

                    <div class="mt-4 text-center">
                        <a href="student_register.php" class="link-primary text-sm">
                            Don't have an account? Register here &rarr;
                        </a>
                    </div>
                </form>

                <div class="mt-6 text-center text-sm text-gray-400">
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
                staffTab.classList.add('tab-active');
                staffTab.classList.remove('text-gray-600');
                studentTab.classList.remove('tab-active');
                studentTab.classList.add('text-gray-600');
            } else {
                staffForm.style.display = 'none';
                studentForm.style.display = 'block';
                studentTab.classList.add('tab-active');
                studentTab.classList.remove('text-gray-600');
                staffTab.classList.remove('tab-active');
                staffTab.classList.add('text-gray-600');
            }
        }
    </script>
</body>
</html>
