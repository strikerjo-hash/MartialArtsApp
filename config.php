<?php
/**
 * MartialArtsApp - Main Configuration
 *
 * Database credentials, application settings, and shared helper functions
 * used by all admin-facing pages (students, classes, events, etc.).
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'martial_arts_app');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Session configuration
define('SESSION_LIFETIME', 3600); // 1 hour

// Application paths
define('BASE_URL', '/MartialArtsApp');
define('APP_ROOT', __DIR__);

// Application name (fallback when no studio name is configured)
define('APP_NAME', 'Martial Arts Academy');

// ---------- Database connection ----------

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// Auto-start session for all pages
auth_start_session();

// Expose $pdo globally for pages that use it directly
$pdo = get_db();

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
    auth_start_session();
    $pdo = get_db();

    $id = $_SESSION['user_id'] ?? 0;
    if (!$id) {
        return [
            'id' => 0, 'username' => 'Guest', 'full_name' => 'Guest',
            'email' => '', 'role' => 'staff', 'password' => '',
        ];
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        if ($user) {
            // Normalise keys expected by various pages
            if (!isset($user['role']))      $user['role'] = $_SESSION['role'] ?? 'admin';
            if (!isset($user['full_name'])) $user['full_name'] = $_SESSION['full_name'] ?? $user['username'];
            if (!isset($user['email']))     $user['email'] = '';
            return $user;
        }
    } catch (\PDOException $e) {
        // users table may not exist — fall back to session data
    }

    return [
        'id'        => $id,
        'username'  => $_SESSION['username'] ?? 'Admin',
        'full_name' => $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin'),
        'email'     => '',
        'role'      => $_SESSION['role'] ?? 'admin',
        'password'  => '',
    ];
}

// ---------- Permissions ----------

function canView(string $page): bool
{
    auth_start_session();
    $role = $_SESSION['role'] ?? 'staff';

    // Admins can see everything
    if ($role === 'admin') {
        return true;
    }

    // Check the role_permissions table if it exists
    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT can_view FROM role_permissions WHERE role = :role AND page = :page LIMIT 1'
        );
        $stmt->execute([':role' => $role, ':page' => $page]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return (bool) $row['can_view'];
        }
    } catch (\PDOException $e) {
        // Table doesn't exist yet — default to allowing access
    }

    return true;
}

// ---------- Settings (key-value store) ----------

function getSetting(string $key, string $default = ''): string
{
    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        return $row ? (string) $row['setting_value'] : $default;
    } catch (\PDOException $e) {
        return $default;
    }
}

function saveSetting(string $key, string $value): void
{
    $pdo = get_db();

    // Ensure settings table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value)
         VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([':k' => $key, ':v' => $value]);
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

function getActiveTheme(): array
{
    $schemes = getThemeColorSchemes();
    $key = getActiveThemeKey();
    return $schemes[$key] ?? $schemes['blue'];
}

function getSiteName(): string
{
    $name = getSetting('site_name', '');
    return $name !== '' ? $name : APP_NAME;
}

function getLogoPath(): string
{
    $logo = getSetting('site_logo', '');
    if ($logo && file_exists('uploads/logo/' . $logo)) {
        return 'uploads/logo/' . $logo;
    }
    return '';
}

// ---------- Utilities ----------

function sanitizeInput(string $data): string
{
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
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
