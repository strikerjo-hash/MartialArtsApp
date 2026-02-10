<?php
/**
 * Authentication helpers.
 *
 * Provides login verification, session guards, and logout for both
 * students and admins.
 */

require_once __DIR__ . '/db.php';

// Start or resume session (called once per request).
function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// ---------- Student authentication ----------

/**
 * Authenticate a student by username & password.
 * Returns the student row (assoc array) on success, or false on failure.
 */
function authenticate_student(string $username, string $password)
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM students WHERE username = :u AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
    $student = $stmt->fetch();

    if ($student && password_verify($password, $student['password_hash'])) {
        return $student;
    }

    return false;
}

/**
 * Persist student identity into the session after successful login.
 */
function login_student(array $student): void
{
    auth_start_session();
    session_regenerate_id(true);

    $_SESSION['user_type']  = 'student';
    $_SESSION['user_id']    = $student['id'];
    $_SESSION['username']   = $student['username'];
    $_SESSION['first_name'] = $student['first_name'];
    $_SESSION['last_name']  = $student['last_name'];
    $_SESSION['belt_rank']  = $student['belt_rank'];
}

/**
 * Require an authenticated student session.  Redirects to login.php if
 * the visitor is not logged in as a student.
 */
function require_student(): void
{
    auth_start_session();

    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
        header('Location: login.php');
        exit;
    }
}

// ---------- Admin authentication ----------

function authenticate_admin(string $username, string $password)
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM admins WHERE username = :u AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        return $admin;
    }

    return false;
}

function login_admin(array $admin): void
{
    auth_start_session();
    session_regenerate_id(true);

    $_SESSION['user_type'] = 'admin';
    $_SESSION['user_id']   = $admin['id'];
    $_SESSION['username']  = $admin['username'];
    $_SESSION['full_name'] = $admin['full_name'];
    $_SESSION['role']      = $admin['role'];
}

function require_admin(): void
{
    auth_start_session();

    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        header('Location: admin_login.php');
        exit;
    }
}

// ---------- General ----------

function logout(): void
{
    auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
}

function is_logged_in(): bool
{
    auth_start_session();
    return !empty($_SESSION['user_type']);
}

function current_user_type(): ?string
{
    auth_start_session();
    return $_SESSION['user_type'] ?? null;
}
