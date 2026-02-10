<?php
/**
 * login.php — Unified Login Page
 *
 * Single login page with two modes:
 *   1. Staff/Admin login — username + password (users table)
 *   2. Student login — email + date of birth (students table)
 *
 * Detects which type of user is logging in and routes accordingly.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/db.php';

auth_start_session();

// If already logged in, redirect to the right place.
if (current_user_type() === 'admin') {
    header('Location: index.php');
    exit;
}
if (current_user_type() === 'student') {
    header('Location: student_portal.php');
    exit;
}

$error = '';
$active_tab = $_POST['login_type'] ?? ($_GET['tab'] ?? 'staff');
$username_value = '';
$email_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_type = $_POST['login_type'] ?? 'staff';

    if ($login_type === 'staff') {
        // --- Staff / Admin login (username + password) ---
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $username_value = $username;

        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            $admin = authenticate_admin($username, $password);
            if ($admin) {
                login_admin($admin);
                header('Location: index.php');
                exit;
            }

            // Also try student password auth (for schemas that support it)
            $student = authenticate_student($username, $password);
            if ($student) {
                login_student($student);
                header('Location: student_portal.php');
                exit;
            }

            $error = 'Invalid username or password.';
        }
    } else {
        // --- Student login (email + date of birth) ---
        $email = trim($_POST['email'] ?? '');
        $dob   = $_POST['dob'] ?? '';
        $email_value = $email;

        if ($email === '' || $dob === '') {
            $error = 'Please enter both your email and date of birth.';
        } else {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                "SELECT * FROM students WHERE email = :email AND date_of_birth = :dob AND status = 'active' LIMIT 1"
            );
            $stmt->execute([':email' => $email, ':dob' => $dob]);
            $student = $stmt->fetch();

            if ($student) {
                // Use login_student if available, or set session manually
                auth_start_session();
                session_regenerate_id(true);
                $_SESSION['user_type']  = 'student';
                $_SESSION['is_student'] = true;
                $_SESSION['user_id']    = $student['id'];
                $_SESSION['student_id'] = $student['id'];
                $_SESSION['username']   = $student['email'];
                $_SESSION['first_name'] = $student['first_name'];
                $_SESSION['last_name']  = $student['last_name'];
                $_SESSION['belt_rank']  = $student['belt_rank'] ?? '';

                header('Location: student_portal.php');
                exit;
            } else {
                $error = 'Invalid email or date of birth, or account is inactive.';
            }
        }
    }
}

$theme = get_theme();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= htmlspecialchars($theme['studio_name']) ?></title>
    <?php if (!empty($theme['favicon_url'])): ?>
        <link rel="icon" href="<?= htmlspecialchars($theme['favicon_url']) ?>">
    <?php endif; ?>
    <style><?= theme_css_vars() ?></style>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .login-tabs {
            display: flex;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid rgba(255,255,255,0.15);
        }
        .login-tab {
            flex: 1;
            padding: 0.75rem 1rem;
            text-align: center;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            opacity: 0.6;
            background: none;
            border-top: none;
            border-left: none;
            border-right: none;
            color: inherit;
        }
        .login-tab:hover {
            opacity: 0.85;
        }
        .login-tab.active {
            opacity: 1;
            border-bottom-color: var(--accent-color, #f5c518);
        }
        .login-panel {
            display: none;
        }
        .login-panel.active {
            display: block;
        }
    </style>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <?php if (!empty($theme['logo_url'])): ?>
                <img src="<?= htmlspecialchars($theme['logo_url']) ?>"
                     alt="<?= htmlspecialchars($theme['studio_name']) ?>"
                     class="login-logo">
            <?php endif; ?>

            <h1 class="login-title"><?= htmlspecialchars($theme['studio_name']) ?></h1>
            <p class="login-tagline"><?= htmlspecialchars($theme['studio_tagline']) ?></p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Login Tabs -->
            <div class="login-tabs">
                <button type="button" class="login-tab <?= $active_tab === 'staff' ? 'active' : '' ?>" onclick="switchTab('staff')">
                    Staff / Instructor
                </button>
                <button type="button" class="login-tab <?= $active_tab === 'student' ? 'active' : '' ?>" onclick="switchTab('student')">
                    Student
                </button>
            </div>

            <!-- Staff Login Form -->
            <div id="panel-staff" class="login-panel <?= $active_tab === 'staff' ? 'active' : '' ?>">
                <form method="POST" action="login.php" class="login-form" autocomplete="on">
                    <input type="hidden" name="login_type" value="staff">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username"
                               value="<?= htmlspecialchars($username_value) ?>"
                               placeholder="Enter your username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password"
                               placeholder="Enter your password" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Sign In</button>
                </form>
            </div>

            <!-- Student Login Form -->
            <div id="panel-student" class="login-panel <?= $active_tab === 'student' ? 'active' : '' ?>">
                <form method="POST" action="login.php" class="login-form" autocomplete="on">
                    <input type="hidden" name="login_type" value="student">
                    <div class="form-group">
                        <label for="student_email">Email Address</label>
                        <input type="email" id="student_email" name="email"
                               value="<?= htmlspecialchars($email_value) ?>"
                               placeholder="Enter your registered email" required>
                    </div>
                    <div class="form-group">
                        <label for="student_dob">Date of Birth</label>
                        <input type="date" id="student_dob" name="dob"
                               placeholder="Your date of birth" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Sign In</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            document.querySelectorAll('.login-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.login-panel').forEach(p => p.classList.remove('active'));
            document.querySelector('.login-tab:nth-child(' + (tab === 'staff' ? '1' : '2') + ')').classList.add('active');
            document.getElementById('panel-' + tab).classList.add('active');
        }
    </script>
</body>
</html>
