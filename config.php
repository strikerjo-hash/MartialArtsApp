<?php
/**
 * MartialArtsApp - Main Configuration
 *
 * Database credentials, application settings, and shared helper functions
 * used by all admin-facing pages (students, classes, events, etc.).
 */

// Record request start time for performance monitoring
if (!defined('REQUEST_START_TIME')) {
    define('REQUEST_START_TIME', $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
}

// Load local configuration (secrets) — not committed to version control.
// Copy config.local.example.php → config.local.php and fill in your values.
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Database configuration — defaults used only if config.local.php is absent
if (!defined('DB_HOST'))    define('DB_HOST', 'localhost');
if (!defined('DB_NAME'))    define('DB_NAME', 'martial_arts_app');
if (!defined('DB_USER'))    define('DB_USER', 'root');
if (!defined('DB_PASS'))    define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Session configuration
define('SESSION_LIFETIME', 3600); // 1 hour

// Security — rate limiting
define('MAX_LOGIN_ATTEMPTS', 5);        // per 15-minute window
define('LOGIN_LOCKOUT_SECONDS', 900);   // 15 minutes

// Security — encryption key for payment data (64-char hex = 256-bit key).
// IMPORTANT: Move this to config.local.php for production deployments.
if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2');
}

// Application paths
define('BASE_URL', '/MartialArtsApp');
define('APP_ROOT', __DIR__);

// Application name (fallback when no studio name is configured)
define('APP_NAME', 'Martial Arts Academy');

// ---------- Database connection ----------

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tenant.php';

// Auto-start session for all pages (skipped in API mode — API is stateless)
if (!defined('API_MODE')) {
    auth_start_session();

    // Security headers — prevent clickjacking, MIME sniffing, and XSS
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}

// Expose $pdo globally for pages that use it directly
$pdo = get_db();

// Audit logging & global error handling (must come after DB + auth + session)
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/error_handler.php';

// Log slow requests (> 1 second) for performance monitoring
if (!defined('API_MODE')) {
    register_shutdown_function(function () {
        $duration = (microtime(true) - REQUEST_START_TIME) * 1000;
        if ($duration > 1000 && function_exists('app_log')) {
            app_log('warning', 'Slow request: ' . round($duration) . 'ms', [
                'category'            => 'performance',
                'request_duration_ms' => round($duration),
            ]);
        }
    });
}

// ---------- School Switch Handler ----------
// Migrations have been moved to migrate.php — run it once after deployment.
if (!defined('API_MODE')) {
    // School switch handler (super admin only)
    if (isset($_GET['switch_school']) && function_exists('is_super_admin') && is_super_admin()) {
        $switchTo = (int) $_GET['switch_school'];
        switch_school($switchTo === 0 ? null : $switchTo);
        $url = strtok($_SERVER['REQUEST_URI'], '?');
        header('Location: ' . $url);
        exit;
    }
}

// ---------- Session & Auth ----------

function requireLogin(): void
{
    auth_start_session();
    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        header('Location: login.php');
        exit;
    }
}

function getCurrentUser(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    auth_start_session();
    $pdo = get_db();

    $id = $_SESSION['user_id'] ?? 0;
    if (!$id) {
        $cached = [
            'id' => 0, 'username' => 'Guest', 'full_name' => 'Guest',
            'email' => '', 'role' => 'staff',
        ];
        return $cached;
    }

    try {
        $stmt = $pdo->prepare('SELECT id, username, full_name, email, role, school_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        if ($user) {
            // Normalise keys expected by various pages
            if (!isset($user['role']))      $user['role'] = $_SESSION['role'] ?? 'admin';
            if (!isset($user['full_name'])) $user['full_name'] = $_SESSION['full_name'] ?? $user['username'];
            if (!isset($user['email']))     $user['email'] = '';
            $cached = $user;
            return $cached;
        }
    } catch (\PDOException $e) {
        // users table may not exist — fall back to session data
    }

    $cached = [
        'id'        => $id,
        'username'  => $_SESSION['username'] ?? 'Admin',
        'full_name' => $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin'),
        'email'     => '',
        'role'      => $_SESSION['role'] ?? 'admin',
    ];
    return $cached;
}

// ---------- Permissions ----------

/**
 * Show a styled "Access Denied" page and stop execution.
 *
 * @param string $message  Optional custom message (defaults to generic)
 * @param string $backUrl  URL for the "Go Back" link (defaults to index.php)
 */
function accessDenied(string $message = '', string $backUrl = 'index.php'): void
{
    if (empty($message)) {
        $message = 'You do not have permission to access this page. '
                 . 'If you believe this is an error, please contact your administrator.';
    }
    $role = $_SESSION['role'] ?? 'unknown';

    // If headers already sent, just output minimal HTML
    if (!headers_sent()) {
        http_response_code(403);
    }

    // Use the full layout if header.php is available, otherwise standalone page
    $headerFile = __DIR__ . '/includes/header.php';
    $footerFile = __DIR__ . '/includes/footer.php';
    $useLayout  = file_exists($headerFile) && function_exists('getActiveTheme');

    if ($useLayout) {
        include $headerFile;
        echo '<div class="container mx-auto px-4 py-16 max-w-lg">';
        echo '  <div class="bg-white rounded-xl shadow-lg overflow-hidden">';
        echo '    <div class="bg-red-600 px-6 py-8 text-center">';
        echo '      <div class="text-5xl mb-3">&#128683;</div>';
        echo '      <h1 class="text-2xl font-bold text-white">Access Denied</h1>';
        echo '    </div>';
        echo '    <div class="px-6 py-8 text-center">';
        echo '      <p class="text-gray-600 mb-6">' . htmlspecialchars($message) . '</p>';
        echo '      <p class="text-xs text-gray-400 mb-6">Your role: <span class="font-semibold">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $role))) . '</span></p>';
        echo '      <div class="flex justify-center gap-3">';
        echo '        <a href="javascript:history.back()" class="inline-flex items-center px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-sm font-medium transition">';
        echo '          &larr; Go Back';
        echo '        </a>';
        echo '        <a href="' . htmlspecialchars($backUrl) . '" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">';
        echo '          Dashboard';
        echo '        </a>';
        echo '      </div>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';
        include $footerFile;
    } else {
        // Standalone fallback (no layout available)
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Access Denied</title>';
        echo '<style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f3f4f6;}';
        echo '.card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.1);max-width:420px;overflow:hidden;text-align:center;}';
        echo '.header{background:#dc2626;color:#fff;padding:2rem;}.header h1{margin:.5rem 0 0;font-size:1.5rem;}';
        echo '.body{padding:2rem;}.body p{color:#666;margin-bottom:1.5rem;}';
        echo 'a.btn{display:inline-block;padding:.5rem 1.2rem;border-radius:8px;text-decoration:none;font-size:.9rem;margin:0 .3rem;}';
        echo '.btn-back{background:#e5e7eb;color:#374151;}.btn-home{background:#2563eb;color:#fff;}</style></head>';
        echo '<body><div class="card"><div class="header"><div style="font-size:3rem;">&#128683;</div><h1>Access Denied</h1></div>';
        echo '<div class="body"><p>' . htmlspecialchars($message) . '</p>';
        echo '<a href="javascript:history.back()" class="btn btn-back">&larr; Go Back</a>';
        echo '<a href="' . htmlspecialchars($backUrl) . '" class="btn btn-home">Dashboard</a>';
        echo '</div></div></body></html>';
    }
    exit;
}

function require_super_admin(): void
{
    auth_start_session();
    if (empty($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
        accessDenied('This page requires Super Admin privileges.');
    }
}

function canView(string $page): bool
{
    static $permCache = null;

    auth_start_session();
    $role = $_SESSION['role'] ?? 'staff';

    // Super admins and admins can see everything
    if ($role === 'admin' || $role === 'super_admin') {
        return true;
    }

    // Load all permissions for this role in one query, cache for the request
    if ($permCache === null) {
        $permCache = [];
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare('SELECT page, can_view FROM role_permissions WHERE role = :role');
            $stmt->execute([':role' => $role]);
            foreach ($stmt->fetchAll() as $row) {
                $permCache[$row['page']] = (bool) $row['can_view'];
            }
        } catch (\PDOException $e) {
            // Table doesn't exist yet — default to allowing access
        }
    }

    return $permCache[$page] ?? true;
}

// ---------- Settings (key-value store) ----------

function getSetting(string $key, string $default = '', ?int $schoolId = null): string
{
    try {
        if ($schoolId === null) {
            $schoolId = function_exists('current_school_id') ? current_school_id() : 1;
        }
        $pdo  = get_db();
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE school_id = :sid AND setting_key = :k LIMIT 1');
        $stmt->execute([':sid' => $schoolId, ':k' => $key]);
        $row = $stmt->fetch();
        return $row ? (string) $row['setting_value'] : $default;
    } catch (\PDOException $e) {
        return $default;
    }
}

function saveSetting(string $key, string $value, ?int $schoolId = null): void
{
    if ($schoolId === null) {
        $schoolId = function_exists('current_school_id') ? current_school_id() : 1;
    }
    $pdo = get_db();

    $stmt = $pdo->prepare(
        'INSERT INTO settings (school_id, setting_key, setting_value)
         VALUES (:sid, :k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([':sid' => $schoolId, ':k' => $key, ':v' => $value]);
}

// ---------- Theme / Branding ----------

function getThemeColorSchemes(): array
{
    return [
        'blue' => [
            'name' => 'Ocean Blue',
            'sidebar_bg' => '#1e293b', 'gradient_from' => '#0f172a',
            'sidebar_text' => '#cbd5e1', 'sidebar_active' => '#3b82f6',
            'primary' => '#3b82f6', 'primary_light' => '#dbeafe',
            'accent' => '#f59e0b',
        ],
        'red' => [
            'name' => 'Crimson Dojo',
            'sidebar_bg' => '#1a1a2e', 'gradient_from' => '#16213e',
            'sidebar_text' => '#e0e0e0', 'sidebar_active' => '#e53e3e',
            'primary' => '#e53e3e', 'primary_light' => '#fed7d7',
            'accent' => '#f5c518',
        ],
        'green' => [
            'name' => 'Forest',
            'sidebar_bg' => '#1a2e1a', 'gradient_from' => '#0f1f0f',
            'sidebar_text' => '#c6dcc6', 'sidebar_active' => '#38a169',
            'primary' => '#38a169', 'primary_light' => '#c6f6d5',
            'accent' => '#d69e2e',
        ],
        'purple' => [
            'name' => 'Royal Purple',
            'sidebar_bg' => '#2d1b4e', 'gradient_from' => '#1a0f2e',
            'sidebar_text' => '#d6bcfa', 'sidebar_active' => '#805ad5',
            'primary' => '#805ad5', 'primary_light' => '#e9d8fd',
            'accent' => '#ed8936',
        ],
        'dark' => [
            'name' => 'Midnight',
            'sidebar_bg' => '#111827', 'gradient_from' => '#030712',
            'sidebar_text' => '#9ca3af', 'sidebar_active' => '#6366f1',
            'primary' => '#6366f1', 'primary_light' => '#e0e7ff',
            'accent' => '#f59e0b',
        ],
        'teal' => [
            'name' => 'Teal Spirit',
            'sidebar_bg' => '#134e4a', 'gradient_from' => '#0f3d3a',
            'sidebar_text' => '#99f6e4', 'sidebar_active' => '#14b8a6',
            'primary' => '#14b8a6', 'primary_light' => '#ccfbf1',
            'accent' => '#f97316',
        ],
    ];
}

function getActiveThemeKey(): string
{
    return getSetting('theme_color_scheme', 'blue');
}

/**
 * Load all studio_config rows into an associative array, cached per request.
 */
function getStudioConfig(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    try {
        $pdo = get_db();
        $stmt = $pdo->query('SELECT config_key, config_value FROM studio_config');
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['config_key']] = $row['config_value'];
        }
    } catch (\Exception $e) {
        // studio_config table may not exist yet
    }
    return $cache;
}

function getActiveTheme(): array
{
    $schemes = getThemeColorSchemes();
    $key = getActiveThemeKey();
    $theme = $schemes[$key] ?? $schemes['blue'];

    // Merge with studio_config custom colours if set
    $config = getStudioConfig();

    // Map studio_config colours → theme array keys used by header/student_header
    if (!empty($config['primary_color']))    $theme['primary']        = $config['primary_color'];
    if (!empty($config['secondary_color']))  $theme['sidebar_bg']     = $config['secondary_color'];
    if (!empty($config['accent_color']))     $theme['accent']         = $config['accent_color'];
    if (!empty($config['background_color'])) $theme['gradient_from']  = $config['background_color'];
    if (!empty($config['text_color']))        $theme['sidebar_text']   = $config['text_color'];
    // Derive sidebar_active from primary
    if (!empty($config['primary_color']))    $theme['sidebar_active'] = $config['primary_color'];
    // Derive primary_light (lighten primary)
    if (!empty($config['primary_color'])) {
        $hex = ltrim($config['primary_color'], '#');
        if (strlen($hex) === 6) {
            $r = min(255, hexdec(substr($hex, 0, 2)) + 180);
            $g = min(255, hexdec(substr($hex, 2, 2)) + 180);
            $b = min(255, hexdec(substr($hex, 4, 2)) + 180);
            $theme['primary_light'] = sprintf('#%02x%02x%02x', $r, $g, $b);
        }
    }

    return $theme;
}

function getSiteName(): string
{
    // Check studio_config first (cached)
    $config = getStudioConfig();
    if (!empty($config['studio_name'])) {
        return $config['studio_name'];
    }

    // Fall back to settings table
    $name = getSetting('site_name', '');
    return $name !== '' ? $name : APP_NAME;
}

function getLogoPath(): string
{
    // Check studio_config first (cached)
    $config = getStudioConfig();
    if (!empty($config['logo_url'])) {
        $path = $config['logo_url'];
        if (file_exists($path)) {
            return $path;
        }
    }

    // Fall back to settings table
    $logo = getSetting('site_logo', '');
    if ($logo && file_exists('uploads/logo/' . $logo)) {
        return 'uploads/logo/' . $logo;
    }
    return '';
}

// ---------- Utilities ----------

function sanitizeInput(string $data): string
{
    return strip_tags(trim($data));
}

function showAlert(string $message, string $type = 'info'): string
{
    $colors = [
        'success' => 'bg-green-100 border-green-400 text-green-700',
        'error'   => 'bg-red-100 border-red-400 text-red-700',
        'warning' => 'bg-yellow-100 border-yellow-400 text-yellow-700',
        'info'    => 'bg-blue-100 border-blue-400 text-blue-700',
    ];
    $cls = $colors[$type] ?? $colors['info'];
    $escaped = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    return '<div role="alert" class="border-l-4 p-4 mb-4 rounded ' . $cls . '">' . $escaped . '</div>';
}

// ---------- Formatters ----------

function formatDate(?string $date): string
{
    if (!$date || $date === '0000-00-00') {
        return 'N/A';
    }
    $ts = strtotime($date);
    return $ts ? date('M j, Y', $ts) : $date;
}

function formatDateTime(?string $datetime): string
{
    if (!$datetime || $datetime === '0000-00-00 00:00:00') {
        return 'N/A';
    }
    $ts = strtotime($datetime);
    return $ts ? date('M j, Y g:i A', $ts) : $datetime;
}

function formatMoney($amount): string
{
    return '$' . number_format((float)$amount, 2);
}

// ---------- Skill Level Helpers ----------

function skillLevelLabel(string $level): string
{
    $labels = [
        'all'                  => 'All Levels',
        'beginner'             => 'Beginner',
        'intermediate'         => 'Intermediate',
        'advanced'             => 'Advanced',
        'black_belt'           => 'Black Belt',
        'ninja'                => 'Ninja',
        'beginner_warrior'     => 'Beginner Warrior',
        'intermediate_warrior' => 'Intermediate Warrior',
        'advanced_warrior'     => 'Advanced Warrior',
    ];
    return $labels[$level] ?? ucfirst(str_replace('_', ' ', $level));
}

function skillLevelOptions(): array
{
    return [
        'all'                  => 'All Levels',
        'beginner'             => 'Beginner',
        'intermediate'         => 'Intermediate',
        'advanced'             => 'Advanced',
        'black_belt'           => 'Black Belt',
        'ninja'                => 'Ninja',
        'beginner_warrior'     => 'Beginner Warrior',
        'intermediate_warrior' => 'Intermediate Warrior',
        'advanced_warrior'     => 'Advanced Warrior',
    ];
}

// ---------- Hours of Operation / Schedule Helpers ----------

function getHoursOfOperation(): array
{
    $json = getSetting('hours_of_operation', '');
    $hours = $json ? json_decode($json, true) : null;
    if (!is_array($hours) || empty($hours)) {
        return [['label' => 'Full Day', 'start' => '06:00', 'end' => '21:00']];
    }
    return $hours;
}

function getScheduleSlotInterval(): int
{
    $interval = (int) getSetting('schedule_slot_interval', '30');
    return max(15, min(120, $interval));
}

/**
 * Generate flat array of time slot strings (e.g. ['06:00', '06:30', '07:00', ...])
 * spanning all configured hours-of-operation frames.
 */
function generateTimeSlots(): array
{
    $hours = getHoursOfOperation();
    $interval = getScheduleSlotInterval();
    $slots = [];

    foreach ($hours as $frame) {
        $start = strtotime($frame['start']);
        $end   = strtotime($frame['end']);
        for ($t = $start; $t < $end; $t += $interval * 60) {
            $slots[] = date('H:i', $t);
        }
    }

    $slots = array_unique($slots);
    sort($slots);
    return $slots;
}

/**
 * Generate time slots for a SINGLE hours-of-operation frame,
 * including 1-hour padding before and after.
 *
 * @return array ['slots' => ['HH:MM', ...], 'frame_start' => 'HH:MM', 'frame_end' => 'HH:MM']
 */
function generateTimeSlotsForFrame(array $frame, int $interval): array
{
    $frameStart = strtotime($frame['start']);
    $frameEnd   = strtotime($frame['end']);

    // 1 hour padding, clamped to 00:00 and 23:59
    $paddedStart = max(strtotime('00:00'), $frameStart - 3600);
    $paddedEnd   = min(strtotime('23:59'), $frameEnd + 3600);

    $slots = [];
    for ($t = $paddedStart; $t < $paddedEnd; $t += $interval * 60) {
        $slots[] = date('H:i', $t);
    }

    return [
        'slots'       => $slots,
        'frame_start' => $frame['start'],
        'frame_end'   => $frame['end'],
    ];
}
