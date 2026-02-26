<?php
/**
 * includes/audit.php — Audit Trail & Application Logging
 *
 * Provides three core functions:
 *   audit_log()      — Record a user action (login, CRUD, settings change, etc.)
 *   app_log()        — Record an application event (error, warning, info, debug)
 *   audit_log_post() — Auto-log every POST action (called from verify_csrf())
 *
 * Tables are auto-created on first use (idempotent).
 */

require_once __DIR__ . '/db.php';

// Migrations have been moved to migrate.php

// ─── Audit Log ───────────────────────────────────────────────────────

/**
 * Record a user action in the audit log.
 *
 * @param string $action      Action name: login, logout, create, update, delete, view, export, etc.
 * @param array  $opts        Optional details:
 *   'entity_type'  => string   What was affected (student, event, class, settings, etc.)
 *   'entity_id'    => int      ID of the affected record
 *   'description'  => string   Human-readable description
 *   'old_values'   => array    Previous values (for updates)
 *   'new_values'   => array    New values (for creates/updates)
 *   'user_id'      => int      Override auto-detected user ID
 *   'user_type'    => string   Override auto-detected user type
 *   'username'     => string   Override auto-detected username
 */
function audit_log(string $action, array $opts = []): void
{
    try {

        $pdo = get_db();

        // Auto-detect user from session
        $userId   = $opts['user_id']   ?? ($_SESSION['user_id'] ?? null);
        $userType = $opts['user_type'] ?? ($_SESSION['user_type'] ?? null);
        $username = $opts['username']  ?? ($_SESSION['username'] ?? null);

        // If username not in session, try first_name + last_name
        if (!$username && !empty($_SESSION['first_name'])) {
            $username = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        }

        $schoolId = function_exists('current_school_id') ? current_school_id() : 1;
        $ip       = $_SERVER['REMOTE_ADDR'] ?? null;
        $uri      = $_SERVER['REQUEST_URI'] ?? null;

        $oldValues = isset($opts['old_values']) ? json_encode($opts['old_values'], JSON_UNESCAPED_UNICODE) : null;
        $newValues = isset($opts['new_values']) ? json_encode($opts['new_values'], JSON_UNESCAPED_UNICODE) : null;

        $stmt = $pdo->prepare(
            "INSERT INTO audit_log
                (school_id, user_id, user_type, username, ip_address, action,
                 entity_type, entity_id, description, old_values, new_values, request_uri)
             VALUES
                (:school_id, :user_id, :user_type, :username, :ip, :action,
                 :entity_type, :entity_id, :description, :old_values, :new_values, :uri)"
        );
        $stmt->execute([
            ':school_id'   => $schoolId,
            ':user_id'     => $userId,
            ':user_type'   => $userType,
            ':username'    => $username,
            ':ip'          => $ip,
            ':action'      => $action,
            ':entity_type' => $opts['entity_type'] ?? null,
            ':entity_id'   => $opts['entity_id'] ?? null,
            ':description' => $opts['description'] ?? null,
            ':old_values'  => $oldValues,
            ':new_values'  => $newValues,
            ':uri'         => $uri,
        ]);
    } catch (\Throwable $e) {
        // Never let audit logging break the application
    }
}

// ─── Application Log ─────────────────────────────────────────────────

/**
 * Record an application-level log entry.
 *
 * @param string $level    One of: error, warning, info, debug
 * @param string $message  Log message
 * @param array  $context  Optional details:
 *   'category'     => string   Category: auth, payment, cron, system, etc.
 *   'file'         => string   Source file
 *   'line'         => int      Source line
 *   'trace'        => string   Stack trace
 *   'reference_id' => string   Error reference ID (ERR-date-random)
 *   'context'      => array    Additional context data (merged with $context minus reserved keys)
 */
function app_log(string $level, string $message, array $context = []): void
{
    // Validate level
    $validLevels = ['error', 'warning', 'info', 'debug'];
    if (!in_array($level, $validLevels, true)) {
        $level = 'info';
    }

    try {

        $pdo = get_db();

        $schoolId = function_exists('current_school_id') ? current_school_id() : 1;
        $userId   = $_SESSION['user_id'] ?? null;
        $userType = $_SESSION['user_type'] ?? null;
        $ip       = $_SERVER['REMOTE_ADDR'] ?? null;
        $uri      = $_SERVER['REQUEST_URI'] ?? null;

        // Extract reserved keys, rest goes into context JSON
        $reserved = ['category', 'file', 'line', 'trace', 'reference_id'];
        $ctxData  = array_diff_key($context, array_flip($reserved));
        $ctxJson  = !empty($ctxData) ? json_encode($ctxData, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $pdo->prepare(
            "INSERT INTO app_log
                (school_id, level, category, message, context, file, line, trace,
                 reference_id, user_id, user_type, ip_address, request_uri)
             VALUES
                (:school_id, :level, :category, :message, :context, :file, :line, :trace,
                 :ref_id, :user_id, :user_type, :ip, :uri)"
        );
        $stmt->execute([
            ':school_id' => $schoolId,
            ':level'     => $level,
            ':category'  => $context['category'] ?? null,
            ':message'   => mb_substr($message, 0, 65000),
            ':context'   => $ctxJson,
            ':file'      => $context['file'] ?? null,
            ':line'      => $context['line'] ?? null,
            ':trace'     => $context['trace'] ?? null,
            ':ref_id'    => $context['reference_id'] ?? null,
            ':user_id'   => $userId,
            ':user_type' => $userType,
            ':ip'        => $ip,
            ':uri'       => $uri,
        ]);
    } catch (\Throwable $e) {
        // Never let app logging break the application.
        // Last resort: write to PHP error_log.
        error_log('[app_log FALLBACK] ' . $level . ': ' . $message);
    }
}

// ─── Auto POST Logging ──────────────────────────────────────────────

/**
 * Automatically log a POST action. Called from verify_csrf().
 *
 * Captures the action name from $_POST['action'] (or falls back to the
 * script filename), the request URI, and a sanitized copy of POST data
 * (with sensitive fields stripped).
 */
function audit_log_post(): void
{
    // Determine the action name
    $action = $_POST['action'] ?? '';
    if (!$action) {
        // Fall back to the page name as the action
        $action = basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php');
    }

    // Build sanitized POST snapshot (strip sensitive fields)
    $sensitiveKeys = [
        'password', 'password_hash', 'new_password', 'confirm_password',
        'current_password', 'old_password',
        'csrf_token',
        'card_number', 'cvv', 'cvc', 'card_cvc',
        'ssn', 'social_security',
        'encryption_key', 'api_key', 'secret_key',
        'stripe_secret_key', 'square_access_token',
    ];

    $postData = $_POST;
    foreach ($sensitiveKeys as $key) {
        if (isset($postData[$key])) {
            $postData[$key] = '[REDACTED]';
        }
    }

    // Truncate very long values (e.g., base64 file uploads in POST)
    foreach ($postData as $key => $value) {
        if (is_string($value) && strlen($value) > 500) {
            $postData[$key] = mb_substr($value, 0, 500) . '...[truncated]';
        }
    }

    // Determine entity_type from POST data or page name
    $entityType = null;
    $entityId   = null;

    // Common POST field patterns for entity detection
    if (!empty($_POST['student_id'])) {
        $entityType = 'student';
        $entityId   = (int) $_POST['student_id'];
    } elseif (!empty($_POST['event_id'])) {
        $entityType = 'event';
        $entityId   = (int) $_POST['event_id'];
    } elseif (!empty($_POST['class_id'])) {
        $entityType = 'class';
        $entityId   = (int) $_POST['class_id'];
    } elseif (!empty($_POST['membership_id'])) {
        $entityType = 'membership';
        $entityId   = (int) $_POST['membership_id'];
    } elseif (!empty($_POST['plan_id'])) {
        $entityType = 'plan';
        $entityId   = (int) $_POST['plan_id'];
    } elseif (!empty($_POST['user_id'])) {
        $entityType = 'user';
        $entityId   = (int) $_POST['user_id'];
    }

    $description = 'POST ' . ($_SERVER['REQUEST_URI'] ?? '');
    if ($action !== basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php')) {
        $description .= ' [action=' . $action . ']';
    }

    audit_log('post_action', [
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'description' => $description,
        'new_values'  => $postData,
    ]);
}
