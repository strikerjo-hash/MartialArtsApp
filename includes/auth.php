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

// Migrations have been moved to migrate.php — run it once after deployment.

/**
 * Start or resume a session with hardened cookie settings.
 */
function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
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
function authenticate_student(string $username, string $password, string $ip = '', ?string &$inactive_status = null)
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

    // Query by both email and username (schema has both columns after migrations).
    $stmt = $pdo->prepare('SELECT * FROM students WHERE (email = :u1 OR username = :u2) LIMIT 1');
    $stmt->execute([':u1' => $username, ':u2' => $username]);

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
        // Pass back the specific status so the caller can show an appropriate message
        $inactive_status = $student['status'] ?? 'inactive';
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
 * Cascade side-effects when a student account is deactivated.
 * - Drops active class enrollments
 * - Cancels future event registrations
 * - Records the deactivation reason
 * - If the student is a parent, deactivates all linked child accounts
 *
 * @param int    $studentId  The student being deactivated
 * @param string $reason     'manual' (admin-initiated) or 'payment' (auto/bulk delinquency)
 * @param array  $visited    Internal: tracks already-processed IDs to prevent infinite recursion
 */
function deactivate_student_cascade(int $studentId, string $reason = 'manual', array $visited = []): void
{
    // Guard against infinite recursion (child could theoretically also be a parent)
    if (in_array($studentId, $visited, true)) {
        return;
    }
    $visited[] = $studentId;

    $pdo = get_db();

    // 1. Drop all active class enrollments
    try {
        $params = [$studentId];
        school_param($params);
        $pdo->prepare("UPDATE class_enrollments SET status = 'dropped' WHERE student_id = ? AND status = 'active'" . school_where())
            ->execute($params);
    } catch (\Throwable $e) { /* enrollment table may not exist in all setups */ }

    // 2. Cancel future event registrations (preserve past records)
    try {
        $params = [$studentId];
        school_param($params);
        $pdo->prepare("
            UPDATE event_registrations er
            JOIN events e ON er.event_id = e.id
            SET er.attendance_status = 'cancelled'
            WHERE er.student_id = ?
              AND e.event_date >= CURDATE()
              AND er.attendance_status = 'registered'" . school_where('er'))
            ->execute($params);
    } catch (\Throwable $e) { /* event tables may not exist in all setups */ }

    // 3. Record deactivation reason
    try {
        $validReasons = ['manual', 'payment'];
        $reason = in_array($reason, $validReasons) ? $reason : 'manual';
        $params = [$reason, $studentId];
        school_param($params);
        $pdo->prepare("UPDATE students SET deactivation_reason = ? WHERE id = ?" . school_where())
            ->execute($params);
    } catch (\Throwable $e) { /* deactivation_reason column may not exist pre-migration */ }

    // 4. If this student is a parent, cascade deactivation to all linked children
    try {
        $params = [$studentId];
        school_param($params);
        $parentCheck = $pdo->prepare("SELECT is_parent FROM students WHERE id = ?" . school_where() . " LIMIT 1");
        $parentCheck->execute($params);
        $row = $parentCheck->fetch();

        if ($row && (int)($row['is_parent'] ?? 0) === 1) {
            // Get all linked children
            $childParams = [$studentId];
            school_param($childParams);
            $childStmt = $pdo->prepare(
                "SELECT ps.student_id FROM parent_students ps
                 JOIN students s ON s.id = ps.student_id
                 WHERE ps.parent_id = ?" . school_where('s')
            );
            $childStmt->execute($childParams);
            $children = $childStmt->fetchAll();

            foreach ($children as $child) {
                $childId = (int)$child['student_id'];

                // Set child status to inactive
                $statusParams = ['inactive', date('Y-m-d'), $childId];
                school_param($statusParams);
                $pdo->prepare("UPDATE students SET status = ?, inactive_since = ? WHERE id = ?" . school_where())
                    ->execute($statusParams);

                // Cascade to the child (drops their enrollments, events, etc.)
                deactivate_student_cascade($childId, $reason, $visited);

                // Audit log the child deactivation
                if (function_exists('audit_log')) {
                    audit_log('status_change', [
                        'description' => 'Child account auto-deactivated (parent account deactivated)',
                        'entity_type' => 'student',
                        'entity_id'   => $childId,
                    ]);
                }
            }
        }
    } catch (\Throwable $e) { /* parent_students table may not exist in all setups */ }
}

/**
 * Clean up when a student account is reactivated.
 * Clears deactivation_reason. Caller handles setting status='active' and inactive_since=NULL.
 * If the student is a parent, also reactivates all linked child accounts.
 *
 * @param int   $studentId  The student being reactivated
 * @param array $visited    Internal: tracks already-processed IDs to prevent infinite recursion
 */
function reactivate_student(int $studentId, array $visited = []): void
{
    // Guard against infinite recursion
    if (in_array($studentId, $visited, true)) {
        return;
    }
    $visited[] = $studentId;

    $pdo = get_db();

    // 1. Clear deactivation reason
    try {
        $params = [$studentId];
        school_param($params);
        $pdo->prepare("UPDATE students SET deactivation_reason = NULL WHERE id = ?" . school_where())
            ->execute($params);
    } catch (\Throwable $e) { /* deactivation_reason column may not exist pre-migration */ }

    // 2. If this student is a parent, cascade reactivation to all linked children
    try {
        $params = [$studentId];
        school_param($params);
        $parentCheck = $pdo->prepare("SELECT is_parent FROM students WHERE id = ?" . school_where() . " LIMIT 1");
        $parentCheck->execute($params);
        $row = $parentCheck->fetch();

        if ($row && (int)($row['is_parent'] ?? 0) === 1) {
            // Get all linked children
            $childParams = [$studentId];
            school_param($childParams);
            $childStmt = $pdo->prepare(
                "SELECT ps.student_id FROM parent_students ps
                 JOIN students s ON s.id = ps.student_id
                 WHERE ps.parent_id = ?" . school_where('s')
            );
            $childStmt->execute($childParams);
            $children = $childStmt->fetchAll();

            foreach ($children as $child) {
                $childId = (int)$child['student_id'];

                // Set child status to active and clear inactive_since
                $statusParams = [$childId];
                school_param($statusParams);
                $pdo->prepare("UPDATE students SET status = 'active', inactive_since = NULL WHERE id = ?" . school_where())
                    ->execute($statusParams);

                // Cascade to the child (clears deactivation_reason, etc.)
                reactivate_student($childId, $visited);

                // Audit log the child reactivation
                if (function_exists('audit_log')) {
                    audit_log('status_change', [
                        'description' => 'Child account auto-reactivated (parent account reactivated)',
                        'entity_type' => 'student',
                        'entity_id'   => $childId,
                    ]);
                }
            }
        }
    } catch (\Throwable $e) { /* parent_students table may not exist in all setups */ }
}

/**
 * Look up why a student was deactivated (for login error messages).
 * Returns 'payment', 'manual', or null.
 */
function get_deactivation_reason(string $username): ?string
{
    $pdo = get_db();
    try {
        $stmt = $pdo->prepare("SELECT deactivation_reason FROM students WHERE (email = :u1 OR username = :u2) LIMIT 1");
        $stmt->execute([':u1' => $username, ':u2' => $username]);
        $row = $stmt->fetch();
        return $row ? ($row['deactivation_reason'] ?? null) : null;
    } catch (\Throwable $e) {
        return null;
    }
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
    $_SESSION['school_id']  = $student['school_id'] ?? 1;

    // Set parent capability flag if student has been promoted
    $_SESSION['is_parent']  = !empty($student['is_parent']) && (int)$student['is_parent'] === 1;

    // Import flags: forced password change & incomplete registration
    $_SESSION['must_change_password']    = !empty($student['must_change_password']) && (int)$student['must_change_password'] === 1;
    $_SESSION['registration_incomplete'] = !empty($student['registration_incomplete']) && (int)$student['registration_incomplete'] === 1;

    // Cache payment lockout status immediately on login
    refresh_payment_lockout_status();

    // Audit log: student login
    if (function_exists('audit_log')) {
        audit_log('login', [
            'description' => 'Student login: ' . trim($student['first_name'] . ' ' . $student['last_name']),
            'entity_type' => 'student',
            'entity_id'   => $student['id'],
        ]);
    }
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
    // Admin impersonation bypasses registration check
    if (!empty($_SESSION['_impersonating'])) {
        return;
    }
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
    // Admin impersonation is never payment-locked
    if (!empty($_SESSION['_impersonating'])) {
        return false;
    }
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
    $_SESSION['school_id'] = $admin['school_id'] ?? 1;

    // Super admins start switched into their home school
    if (($admin['role'] ?? 'admin') === 'super_admin') {
        $_SESSION['active_school_id'] = $admin['school_id'] ?? 1;
    }

    // Audit log: admin login
    if (function_exists('audit_log')) {
        audit_log('login', [
            'description' => 'Admin login: ' . ($admin['full_name'] ?? $admin['username']) . ' (role: ' . ($admin['role'] ?? 'admin') . ')',
            'entity_type' => 'user',
            'entity_id'   => $admin['id'],
        ]);
    }
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

    // Audit log: capture user info BEFORE clearing session
    if (function_exists('audit_log')) {
        $userType = $_SESSION['user_type'] ?? 'unknown';
        $username = $_SESSION['username'] ?? ($_SESSION['first_name'] ?? 'unknown');
        audit_log('logout', [
            'description' => ucfirst($userType) . ' logout: ' . $username,
        ]);
    }

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
