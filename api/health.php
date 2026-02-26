<?php
/**
 * api/health.php — System Health Check Endpoint
 *
 * Returns basic status (database up/down) without authentication.
 * Detailed system info requires authentication via:
 *   - ?key=<api_health_key setting value>
 *   - or an active admin session
 *
 * Useful for uptime monitoring tools (UptimeRobot, Pingdom, etc.).
 */

// Allow direct access (not just via API router)
if (!function_exists('get_db')) {
    define('API_MODE', true);
    require_once __DIR__ . '/../config.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$checks  = [];
$overall = 'healthy';

// 1. Database connectivity (always public)
try {
    $pdo = get_db();
    $pdo->query('SELECT 1');
    $checks['database'] = ['status' => 'ok'];
} catch (Throwable $e) {
    $checks['database'] = ['status' => 'error', 'message' => 'Connection failed'];
    $overall = 'unhealthy';
}

// Detailed checks require authentication
$authenticated = false;
try {
    $healthKey = '';
    if (function_exists('getSetting')) {
        $healthKey = getSetting('api_health_key', '');
    }
    $providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_HEALTH_KEY'] ?? '';
    if ($healthKey !== '' && $providedKey !== '' && hash_equals($healthKey, $providedKey)) {
        $authenticated = true;
    }
    if (!$authenticated && function_exists('auth_start_session')) {
        auth_start_session();
        if (($_SESSION['user_type'] ?? '') === 'admin') {
            $authenticated = true;
        }
    }
} catch (Throwable $e) {}

if ($authenticated) {
    // 2. PHP version & memory
    $checks['php'] = [
        'status'       => version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'warning',
        'version'      => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'memory_used'  => round(memory_get_peak_usage(true) / 1048576, 2) . ' MB',
    ];

    // 3. Disk space (uploads directory)
    $uploadsDir = __DIR__ . '/../uploads';
    if (is_dir($uploadsDir)) {
        $freeBytes  = @disk_free_space($uploadsDir);
        $totalBytes = @disk_total_space($uploadsDir);
        if ($freeBytes !== false && $totalBytes !== false) {
            $freeGB = round($freeBytes / 1073741824, 2);
            $checks['disk'] = [
                'status'   => $freeGB < 1 ? 'warning' : 'ok',
                'free_gb'  => $freeGB,
                'total_gb' => round($totalBytes / 1073741824, 2),
            ];
            if ($freeGB < 1) $overall = 'degraded';
        }
    }

    // 4. Last cron run
    try {
        $stmt    = $pdo->query("SELECT MAX(created_at) as last_run FROM renewal_log");
        $row     = $stmt->fetch();
        $lastRun = $row['last_run'] ?? null;
        $cronAge = $lastRun ? (time() - strtotime($lastRun)) : null;
        $checks['cron'] = [
            'status'     => ($cronAge !== null && $cronAge < 86400 * 2) ? 'ok' : 'warning',
            'last_run'   => $lastRun,
            'age_hours'  => $cronAge !== null ? round($cronAge / 3600, 1) : null,
        ];
        if ($cronAge !== null && $cronAge > 86400 * 2) $overall = 'degraded';
    } catch (Throwable $e) {
        $checks['cron'] = ['status' => 'unknown'];
    }

    // 5. Error count in last hour
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM app_log WHERE level = 'error' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $errorCount = (int) $stmt->fetchColumn();
        $checks['errors_last_hour'] = [
            'status' => $errorCount > 10 ? 'warning' : 'ok',
            'count'  => $errorCount,
        ];
        if ($errorCount > 10) $overall = 'degraded';
    } catch (Throwable $e) {}

    // 6. Active schools
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM schools WHERE status = 'active'");
        $checks['schools'] = ['status' => 'ok', 'active' => (int) $stmt->fetchColumn()];
    } catch (Throwable $e) {}

    // 7. API tokens
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM api_tokens WHERE expires_at > NOW()");
        $checks['api_sessions'] = ['status' => 'ok', 'active' => (int) $stmt->fetchColumn()];
    } catch (Throwable $e) {}
}

echo json_encode([
    'status'    => $overall,
    'timestamp' => date('c'),
    'checks'    => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
