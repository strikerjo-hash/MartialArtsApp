<?php
/**
 * Authentication helpers.
 *
 * Provides login verification, session guards, and logout for both
 * students and admins/staff.
 *
 * Compatible with both database schemas:
 *   - Original (database.sql):  `users` table (password col), `students` with status/email
 *   - New (sql/schema.sql):     `admins` table (password_hash col), `students` with username/is_active
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
 * Authenticate a student by username/email & password.
 * Returns the student row on success, or false on failure.
 */
function authenticate_student(string $username, string $password)
{
    $pdo = get_db();
    $student = null;

    // Detect which columns exist in the students table
    $cols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll();
    $colNames = array_column($cols, 'Field');
    $hasUsername = in_array('username', $colNames, true);
    $hasEmail    = in_array('email', $colNames, true);

    // Build query based on available columns
    if ($hasUsername && $hasEmail) {
        $stmt = $pdo->prepare('SELECT * FROM students WHERE (email = :u1 OR username = :u2) LIMIT 1');
        $stmt->execute([':u1' => $username, ':u2' => $username]);
    } elseif ($hasUsername) {
        $stmt = $pdo->prepare('SELECT * FROM students WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
    } else {
        // Original schema — email only
        $stmt = $pdo->prepare('SELECT * FROM students WHERE email = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
    }

    $student = $stmt->fetch();

    if (!$student) {
        return false;
    }

    // Check if student is active (supports both `status='active'` and `is_active=1`)
    $is_active = true;
    if (isset($student['status']) && $student['status'] !== 'active') {
        $is_active = false;
    }
    if (isset($student['is_active']) && !$student['is_active']) {
        $is_active = false;
    }
    if (!$is_active) {
        return false;
    }

    // Verify password (supports both `password` and `password_hash` columns)
    $hash = $student['password_hash'] ?? $student['password'] ?? '';
    if ($hash && password_verify($password, $hash)) {
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
    $_SESSION['is_student'] = true; // backward-compat with legacy student pages
    $_SESSION['user_id']    = $student['id'];
    $_SESSION['student_id'] = $student['id'];
    $_SESSION['username']   = $student['username'] ?? $student['email'] ?? '';
    $_SESSION['first_name'] = $student['first_name'];
    $_SESSION['last_name']  = $student['last_name'];
    $_SESSION['belt_rank']  = $student['belt_rank'] ?? '';
}

/**
 * Require an authenticated student session.
 */
function require_student(): void
{
    auth_start_session();

    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
        header('Location: login.php');
        exit;
    }
}

// ---------- Admin / Staff authentication ----------

/**
 * Authenticate an admin/staff user by username & password.
 * Queries the `users` table (original schema) or `admins` table (new schema).
 * Returns the user row on success, or false on failure.
 */
function authenticate_admin(string $username, string $password)
{
    $pdo = get_db();

    // Try the `users` table first (original database.sql schema)
    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $admin = $stmt->fetch();

        if ($admin) {
            $hash = $admin['password_hash'] ?? $admin['password'] ?? '';
            if ($hash && password_verify($password, $hash)) {
                // Normalize: ensure 'role' key exists
                if (!isset($admin['role'])) {
                    $admin['role'] = 'admin';
                }
                return $admin;
            }
            return false; // user found but password wrong
        }
    } catch (\PDOException $e) {
        // `users` table doesn't exist — try `admins` table
    }

    // Fall back to `admins` table (new sql/schema.sql)
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM admins WHERE username = :u AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':u' => $username]);
        $admin = $stmt->fetch();

        if ($admin) {
            $hash = $admin['password_hash'] ?? $admin['password'] ?? '';
            if ($hash && password_verify($password, $hash)) {
                return $admin;
            }
        }
    } catch (\PDOException $e) {
        // Neither table exists
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
    $_SESSION['full_name'] = $admin['full_name'] ?? ($admin['username'] ?? 'Admin');
    $_SESSION['role']      = $admin['role'] ?? 'admin';
}

function require_admin(): void
{
    auth_start_session();

    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        header('Location: login.php');
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
