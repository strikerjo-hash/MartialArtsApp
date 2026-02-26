<?php
/**
 * api/index.php — REST API Front Controller
 *
 * Routes all API requests to the appropriate handler.
 * Handles CORS preflight, error catching, and audit logging.
 *
 * URL patterns:
 *   POST /api/auth/login         → routes/auth.php
 *   POST /api/auth/admin-login   → routes/auth.php
 *   POST /api/auth/refresh       → routes/auth.php
 *   POST /api/auth/logout        → routes/auth.php
 *   GET  /api/student/profile    → routes/student.php
 *   GET  /api/parent/children    → routes/parent.php
 *   GET  /api/admin/dashboard    → routes/admin.php
 *   GET  /api/theme              → routes/theme.php
 *   GET  /api/health             → health.php
 */

require_once __DIR__ . '/bootstrap.php';

// ---------- CORS headers ----------
// Restrict to known origins. In production, set the 'api_cors_origin' setting
// in the database to your app domain (e.g. https://myapp.example.com).
$allowedOrigin = '';
try {
    if (function_exists('getSetting')) {
        $allowedOrigin = getSetting('api_cors_origin', '');
    }
} catch (\Throwable $e) {}

if ($allowedOrigin === '') {
    // Default: same-origin only (no CORS header sent)
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $serverHost = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) === $serverHost) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
} else {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------- Parse route ----------
$pathInfo = $_SERVER['PATH_INFO']
    ?? str_replace(parse_url($_SERVER['SCRIPT_NAME'], PHP_URL_PATH), '', $_SERVER['REQUEST_URI'] ?? '')
    ?? '';

// Strip query string and clean up
$path   = strtok(trim($pathInfo, '/'), '?');
$path   = preg_replace('#^api/#', '', $path); // strip api/ prefix if present
$method = $_SERVER['REQUEST_METHOD'];

// ---------- Route dispatch ----------
try {
    if (preg_match('#^auth/(.+)$#', $path, $m)) {
        require_once __DIR__ . '/routes/auth.php';
        route_auth($method, $m[1]);

    } elseif (preg_match('#^student(?:/(.*))?$#', $path, $m)) {
        require_once __DIR__ . '/routes/student.php';
        route_student($method, $m[1] ?? '');

    } elseif (preg_match('#^parent(?:/(.*))?$#', $path, $m)) {
        require_once __DIR__ . '/routes/parent.php';
        route_parent($method, $m[1] ?? '');

    } elseif (preg_match('#^admin(?:/(.*))?$#', $path, $m)) {
        require_once __DIR__ . '/routes/admin.php';
        route_admin($method, $m[1] ?? '');

    } elseif ($path === 'theme') {
        require_once __DIR__ . '/routes/theme.php';
        route_theme($method);

    } elseif ($path === 'health') {
        require_once __DIR__ . '/health.php';

    } else {
        api_error('Endpoint not found', 404);
    }
} catch (\Throwable $e) {
    // Log the error for debugging
    if (function_exists('app_log')) {
        app_log('error', 'API exception: ' . $e->getMessage(), [
            'category' => 'api',
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
            'trace'    => $e->getTraceAsString(),
        ]);
    }

    api_error('Internal server error', 500);
}
