<?php
/**
 * includes/impersonation.php — Admin Impersonation Helpers
 *
 * Allows admins to temporarily "view as" a student or parent,
 * seeing exactly what that user sees in their portal. The admin's
 * session is backed up server-side and restored when impersonation ends.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/parent_auth.php';

/**
 * Check if the current session is an impersonation session.
 */
function is_impersonating(): bool
{
    return !empty($_SESSION['_impersonating']);
}

/**
 * Start impersonating a student.
 *
 * Mirrors login_student() but intentionally skips:
 *  - session_regenerate_id() (preserves backup data)
 *  - refresh_payment_lockout_status() (admin bypasses lockout)
 *  - "Student login" audit log (misleading during impersonation)
 *
 * @param array $student Full student row from the DB
 * @throws \RuntimeException if the current user is not an admin
 */
function start_impersonation_student(array $student): void
{
    auth_start_session();

    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        throw new \RuntimeException('Only admins can impersonate students.');
    }

    // Capture admin identity for audit before overwriting session
    $adminName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
    $adminId   = $_SESSION['user_id'] ?? null;

    // Back up the entire admin session
    $backup = $_SESSION;
    unset($backup['_admin_backup']); // Prevent recursive nesting

    // Clear session and set student vars
    $_SESSION = [];
    $_SESSION['_admin_backup'] = $backup;

    $_SESSION['user_type']  = 'student';
    $_SESSION['is_student'] = true;
    $_SESSION['user_id']    = $student['id'];
    $_SESSION['student_id'] = $student['id'];
    $_SESSION['username']   = $student['username'] ?? $student['email'] ?? '';
    $_SESSION['first_name'] = $student['first_name'];
    $_SESSION['last_name']  = $student['last_name'];
    $_SESSION['belt_rank']  = $student['belt_rank'] ?? '';
    $_SESSION['school_id']  = $student['school_id'] ?? 1;
    $_SESSION['is_parent']  = !empty($student['is_parent']) && (int)$student['is_parent'] === 1;

    // Clear flags that would redirect during impersonation
    $_SESSION['must_change_password']    = false;
    $_SESSION['registration_incomplete'] = false;
    $_SESSION['payment_locked_out']      = false;

    // Set impersonation markers
    $_SESSION['_impersonating']      = true;
    $_SESSION['_impersonating_type'] = 'student';
    $_SESSION['_impersonating_name'] = trim($student['first_name'] . ' ' . $student['last_name']);

    // Preserve CSRF token from admin session so forms continue working
    if (!empty($backup['csrf_token'])) {
        $_SESSION['csrf_token'] = $backup['csrf_token'];
    }

    // Audit log
    if (function_exists('audit_log')) {
        audit_log('impersonate_start', [
            'description' => "Admin \"{$adminName}\" started viewing as student: " . $_SESSION['_impersonating_name'],
            'entity_type' => 'student',
            'entity_id'   => $student['id'],
            'user_id'     => $adminId,
            'user_type'   => 'admin',
        ]);
    }
}

/**
 * Start impersonating a legacy parent account.
 *
 * @param array $parent Full parent row from the DB
 * @throws \RuntimeException if the current user is not an admin
 */
function start_impersonation_parent(array $parent): void
{
    auth_start_session();

    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        throw new \RuntimeException('Only admins can impersonate parents.');
    }

    $adminName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
    $adminId   = $_SESSION['user_id'] ?? null;

    $backup = $_SESSION;
    unset($backup['_admin_backup']);

    $_SESSION = [];
    $_SESSION['_admin_backup'] = $backup;

    $_SESSION['user_type']  = 'parent';
    $_SESSION['parent_id']  = $parent['id'];
    $_SESSION['user_id']    = $parent['id'];
    $_SESSION['username']   = $parent['username'];
    $_SESSION['first_name'] = $parent['first_name'];
    $_SESSION['last_name']  = $parent['last_name'];
    $_SESSION['school_id']  = $parent['school_id'] ?? 1;

    $_SESSION['_impersonating']      = true;
    $_SESSION['_impersonating_type'] = 'parent';
    $_SESSION['_impersonating_name'] = trim($parent['first_name'] . ' ' . $parent['last_name']);

    if (!empty($backup['csrf_token'])) {
        $_SESSION['csrf_token'] = $backup['csrf_token'];
    }

    if (function_exists('audit_log')) {
        audit_log('impersonate_start', [
            'description' => "Admin \"{$adminName}\" started viewing as parent: " . $_SESSION['_impersonating_name'],
            'entity_type' => 'parent',
            'entity_id'   => $parent['id'],
            'user_id'     => $adminId,
            'user_type'   => 'admin',
        ]);
    }
}

/**
 * End impersonation and restore the admin session.
 */
function stop_impersonation(): void
{
    auth_start_session();

    if (empty($_SESSION['_admin_backup'])) {
        return;
    }

    $impersonatedName = $_SESSION['_impersonating_name'] ?? 'Unknown';
    $impersonatedType = $_SESSION['_impersonating_type'] ?? 'student';
    $backup = $_SESSION['_admin_backup'];

    // Restore admin session
    $_SESSION = $backup;

    // Audit log
    if (function_exists('audit_log')) {
        $adminName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
        audit_log('impersonate_end', [
            'description' => "Admin \"{$adminName}\" stopped viewing as {$impersonatedType}: {$impersonatedName}",
        ]);
    }
}
