<?php
/**
 * Authentication helpers.
 *
 * Provides login verification, session guards, and logout for both
 * students and admins.  Includes rate limiting via the security module.
 *
 * Compatible with both database schemas:
 *   - Original (database.sql):  `users` table (password col), `students` with status/email
 *   - New (sql/schema.sql):     `admins` table (password_hash col), `students` with username/is_active
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

// ─── Idempotent migrations for payment lockout ──────────────────────
try {
    $pdo = get_db();
    // Add 'declined' to memberships.payment_status ENUM
    $pdo->exec("ALTER TABLE memberships MODIFY COLUMN payment_status ENUM('paid','pending','partial','declined') DEFAULT 'pending'");
} catch (\PDOException $e) {}
try {
    $pdo = get_db();
    $cols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll();
    $colNames = array_column($cols, 'Field');
    if (!in_array('payment_lockout_override', $colNames, true)) {
        $pdo->exec("ALTER TABLE students ADD COLUMN payment_lockout_override TINYINT(1) DEFAULT 0");
    }
    if (!in_array('lockout_override_at', $colNames, true)) {
        $pdo->exec("ALTER TABLE students ADD COLUMN lockout_override_at DATETIME DEFAULT NULL");
    }
    if (!in_array('lockout_override_by', $colNames, true)) {
        $pdo->exec("ALTER TABLE students ADD COLUMN lockout_override_by INT DEFAULT NULL");
    }
} catch (\PDOException $e) {}

// ─── Idempotent migrations for import: forced password change & registration ──
try {
    $pdo = get_db();
    $cols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll();
    $colNames = array_column($cols, 'Field');
    if (!in_array('must_change_password', $colNames, true)) {
        $pdo->exec("ALTER TABLE students ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!in_array('registration_incomplete', $colNames, true)) {
        $pdo->exec("ALTER TABLE students ADD COLUMN registration_incomplete TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (\PDOException $e) {}

// ─── Idempotent migration for calendar-only events ───────────────────
try {
    $pdo = get_db();
    $cols = $pdo->query("SHOW COLUMNS FROM events")->fetchAll();
    $colNames = array_column($cols, 'Field');
    if (!in_array('requires_registration', $colNames, true)) {
        $pdo->exec("ALTER TABLE events ADD COLUMN requires_registration TINYINT(1) NOT NULL DEFAULT 1");
    }
} catch (\PDOException $e) {}

/**
 * Start or resume a session with hardened cookie settings.
 */
function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'httponly'  => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

// ---------- Student authentication ----------

/**
 * Authenticate a student by username & password.
 * Enforces rate limiting.  Returns the student row on success, or false.
 */
function authenticate_student(string $username, string $password, string $ip = '')
{
    if ($ip === '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    // Rate-limit check.
    $wait = check_rate_limit($username, $ip);
    if ($wait > 0) {
        return false;
    }

    $pdo = get_db();

    // Detect which columns exist in the students table.
    $cols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll();
    $colNames = array_column($cols, 'Field');
    $hasUsername = in_array('username', $colNames, true);
    $hasEmail    = in_array('email', $colNames, true);

    // Build query based on available columns.
    if ($hasUsername && $hasEmail) {
        $stmt = $pdo->prepare('SELECT * FROM students WHERE (email = :u1 OR username = :u2) LIMIT 1');
        $stmt->execute([':u1' => $username, ':u2' => $username]);
    } elseif ($hasUsername) {
        $stmt = $pdo->prepare('SELECT * FROM students WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM students WHERE email = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
    }

    $student = $stmt->fetch();

    if (!$student) {
        record_failed_login($username, $ip);
        return false;
    }

    // Check if student is active (supports both `status='active'` and `is_active=1`).
    $is_active = true;
    if (isset($student['status']) && $student['status'] !== 'active') {
        $is_active = false;
    }
    if (isset($student['is_active']) && !$student['is_active']) {
        $is_active = false;
    }
    if (!$is_active) {
        record_failed_login($username, $ip);
        return false;
    }

    // Verify password (supports both `password` and `password_hash` columns).
    $hash = $student['password_hash'] ?? $student['password'] ?? '';
    if ($hash && password_verify($password, $hash)) {
        clear_login_attempts($username, $ip);
        return $student;
    }

    record_failed_login($username, $ip);
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
    $_SESSION['is_student'] = true;
    $_SESSION['user_id']    = $student['id'];
    $_SESSION['student_id'] = $student['id'];
    $_SESSION['username']   = $student['username'] ?? $student['email'] ?? '';
    $_SESSION['first_name'] = $student['first_name'];
    $_SESSION['last_name']  = $student['last_name'];
    $_SESSION['belt_rank']  = $student['belt_rank'] ?? '';

    // Set parent capability flag if student has been promoted
    $_SESSION['is_parent']  = !empty($student['is_parent']) && (int)$student['is_parent'] === 1;

    // Import flags: forced password change & incomplete registration
    $_SESSION['must_change_password']    = !empty($student['must_change_password']) && (int)$student['must_change_password'] === 1;
    $_SESSION['registration_incomplete'] = !empty($student['registration_incomplete']) && (int)$student['registration_incomplete'] === 1;

    // Cache payment lockout status immediately on login
    refresh_payment_lockout_status();
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

/**
 * Guard: redirect students who must change their password or complete registration.
 * Call at the top of any student page EXCEPT complete_registration.php and logout.php.
 */
function require_registration_complete(): void
{
    auth_start_session();
    if (!empty($_SESSION['must_change_password']) || !empty($_SESSION['registration_incomplete'])) {
        header('Location: complete_registration.php');
        exit;
    }
}

// ---------- Student payment lockout ----------

/**
 * Query DB for the student's current payment lockout state and cache in session.
 * A student is locked out if their active membership has a non-paid payment_status
 * AND no admin override is active.
 */
function refresh_payment_lockout_status(): void
{
    auth_start_session();
    $studentId = $_SESSION['student_id'] ?? 0;
    if (!$studentId) return;

    $pdo = get_db();

    // Check if the student has an admin override active
    try {
        $overrideStmt = $pdo->prepare(
            'SELECT payment_lockout_override FROM students WHERE id = :id LIMIT 1'
        );
        $overrideStmt->execute([':id' => $studentId]);
        $overrideRow = $overrideStmt->fetch();

        if ($overrideRow && (int) $overrideRow['payment_lockout_override'] === 1) {
            $_SESSION['payment_locked_out'] = false;
            $_SESSION['payment_lockout_checked_at'] = time();
            return;
        }
    } catch (\PDOException $e) {
        // Column may not exist yet on older installs
        $_SESSION['payment_locked_out'] = false;
        $_SESSION['payment_lockout_checked_at'] = time();
        return;
    }

    // Check active membership for problematic payment_status
    $isLocked = false;
    try {
        $stmt = $pdo->prepare("
            SELECT m.payment_status
            FROM memberships m
            WHERE m.student_id = :sid AND m.status = 'active'
            ORDER BY m.end_date DESC LIMIT 1
        ");
        $stmt->execute([':sid' => $studentId]);
        $membership = $stmt->fetch();

        if ($membership && in_array($membership['payment_status'], ['declined', 'pending', 'partial'], true)) {
            $isLocked = true;
        }
    } catch (\PDOException $e) {}

    // Also check for recent payment_failed entries in renewal_log
    if (!$isLocked) {
        try {
            $failStmt = $pdo->prepare("
                SELECT COUNT(*) as cnt FROM renewal_log
                WHERE student_id = :sid
                  AND action = 'payment_failed'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $failStmt->execute([':sid' => $studentId]);
            $failRow = $failStmt->fetch();
            if ($failRow && (int) $failRow['cnt'] > 0) {
                $isLocked = true;
            }
        } catch (\PDOException $e) {
            // renewal_log may not exist on older installs
        }
    }

    $_SESSION['payment_locked_out'] = $isLocked;
    $_SESSION['payment_lockout_checked_at'] = time();
}

/**
 * Check if the current student is payment-locked.
 * Uses session cache, refreshes from DB if stale (>5 minutes).
 * Also respects the global test toggle (test_lockout_all_students setting).
 */
function is_student_payment_locked(): bool
{
    auth_start_session();
    if (empty($_SESSION['student_id'])) {
        return false;
    }
    if (($_SESSION['user_type'] ?? '') !== 'student') {
        return false;
    }

    // Global test toggle — forces ALL students into lockout mode
    // Cached in session for 60 seconds to avoid constant DB reads
    $testCacheTime = $_SESSION['test_lockout_checked_at'] ?? 0;
    if ((time() - $testCacheTime) > 60) {
        try {
            $pdo = get_db();
            $testStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'test_lockout_all_students' LIMIT 1");
            $testStmt->execute();
            $testRow = $testStmt->fetch();
            $_SESSION['test_lockout_all_students'] = ($testRow && $testRow['setting_value'] === '1');
            $_SESSION['test_lockout_checked_at'] = time();
        } catch (\PDOException $e) {
            $_SESSION['test_lockout_all_students'] = false;
            $_SESSION['test_lockout_checked_at'] = time();
        }
    }
    if (!empty($_SESSION['test_lockout_all_students'])) {
        return true;
    }

    // Refresh cache if stale (older than 5 minutes)
    $cacheTime = $_SESSION['payment_lockout_checked_at'] ?? 0;
    if ((time() - $cacheTime) > 300) {
        refresh_payment_lockout_status();
    }

    return !empty($_SESSION['payment_locked_out']);
}

/**
 * Guard: redirect locked-out students to the payment page.
 * Call at the top of any student page that should be LOCKED during payment issues.
 *
 * ALLOWED pages (do NOT call this on):
 *   - student_payment.php  (must update card info — primary lockout landing page)
 *   - student_profile.php  (basic profile access)
 *   - student_logout.php   (must be able to log out)
 */
function require_student_payment_clear(): void
{
    // Imported students must complete registration before anything else
    require_registration_complete();

    if (is_student_payment_locked()) {
        header('Location: student_payment.php?lockout=1');
        exit;
    }
}

// ---------- Admin / Staff authentication ----------

/**
 * Authenticate an admin/staff user by username & password.
 * Queries the `users` table (original schema) or `admins` table (new schema).
 * Enforces rate limiting.
 */
function authenticate_admin(string $username, string $password, string $ip = '')
{
    if ($ip === '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    $wait = check_rate_limit($username, $ip);
    if ($wait > 0) {
        return false;
    }

    $pdo = get_db();

    // Try the `users` table first (original database.sql schema).
    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $admin = $stmt->fetch();

        if ($admin) {
            $hash = $admin['password_hash'] ?? $admin['password'] ?? '';
            if ($hash && password_verify($password, $hash)) {
                if (!isset($admin['role'])) {
                    $admin['role'] = 'admin';
                }
                clear_login_attempts($username, $ip);
                return $admin;
            }
            record_failed_login($username, $ip);
            return false;
        }
    } catch (\PDOException $e) {
        // `users` table doesn't exist — try `admins` table.
    }

    // Fall back to `admins` table (new sql/schema.sql).
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM admins WHERE username = :u AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':u' => $username]);
        $admin = $stmt->fetch();

        if ($admin) {
            $hash = $admin['password_hash'] ?? $admin['password'] ?? '';
            if ($hash && password_verify($password, $hash)) {
                clear_login_attempts($username, $ip);
                return $admin;
            }
        }
    } catch (\PDOException $e) {
        // Neither table exists.
    }

    record_failed_login($username, $ip);
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
