<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'martial_arts_studio');

// Application Settings
define('APP_NAME', 'Martial Arts Studio Manager');
define('APP_URL', 'http://localhost/procomp');
define('UPLOAD_PATH', 'uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB

// Session Settings
session_start();

// Database Connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper Functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function getCurrentUser() {
    if (isLoggedIn()) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    }
    return null;
}

// Permission checking functions
function canView($page) {
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin') return true; // Admins can view everything
    
    global $pdo;
    $stmt = $pdo->prepare("SELECT can_view FROM role_permissions WHERE role = ? AND page = ?");
    $stmt->execute([$role, $page]);
    $result = $stmt->fetch();
    
    return $result ? (bool)$result['can_view'] : false;
}

function canCreate($page) {
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin') return true;
    
    global $pdo;
    $stmt = $pdo->prepare("SELECT can_create FROM role_permissions WHERE role = ? AND page = ?");
    $stmt->execute([$role, $page]);
    $result = $stmt->fetch();
    
    return $result ? (bool)$result['can_create'] : false;
}

function canEdit($page) {
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin') return true;
    
    global $pdo;
    $stmt = $pdo->prepare("SELECT can_edit FROM role_permissions WHERE role = ? AND page = ?");
    $stmt->execute([$role, $page]);
    $result = $stmt->fetch();
    
    return $result ? (bool)$result['can_edit'] : false;
}

function canDelete($page) {
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin') return true;
    
    global $pdo;
    $stmt = $pdo->prepare("SELECT can_delete FROM role_permissions WHERE role = ? AND page = ?");
    $stmt->execute([$role, $page]);
    $result = $stmt->fetch();
    
    return $result ? (bool)$result['can_delete'] : false;
}

function requirePermission($page, $type = 'view') {
    $allowed = false;
    
    switch ($type) {
        case 'view':
            $allowed = canView($page);
            break;
        case 'create':
            $allowed = canCreate($page);
            break;
        case 'edit':
            $allowed = canEdit($page);
            break;
        case 'delete':
            $allowed = canDelete($page);
            break;
    }
    
    if (!$allowed) {
        header('Location: index.php');
        exit;
    }
}

function getSetting($key, $default = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function saveSetting($key, $value) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $value, $value]);
}

function getActivePaymentGateway() {
    return getSetting('active_payment_gateway', 'none');
}

function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

function formatDateTime($datetime) {
    return date('M d, Y g:i A', strtotime($datetime));
}

function formatMoney($amount) {
    return '$' . number_format($amount, 2);
}

function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function generateReceiptNumber() {
    return 'RCP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

function uploadFile($file, $targetDir = 'uploads/photos/') {
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $newFileName = uniqid() . '.' . $fileExtension;
    $targetFile = $targetDir . $newFileName;
    
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($fileExtension, $allowedTypes)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => 'File too large'];
    }
    
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => true, 'filename' => $newFileName, 'path' => $targetFile];
    }
    
    return ['success' => false, 'message' => 'Upload failed'];
}

function showAlert($message, $type = 'info') {
    $colors = [
        'success' => 'green',
        'error' => 'red',
        'warning' => 'yellow',
        'info' => 'blue'
    ];
    $color = $colors[$type] ?? 'blue';
    return "<div class='bg-{$color}-100 border border-{$color}-400 text-{$color}-700 px-4 py-3 rounded mb-4' role='alert'>
                <span class='block sm:inline'>{$message}</span>
            </div>";
}

// Theme & Branding Functions
function getThemeColorSchemes() {
    return [
        'blue' => [
            'name' => 'Ocean Blue',
            'primary' => '#2563EB',
            'primary_hover' => '#1D4ED8',
            'primary_light' => '#DBEAFE',
            'sidebar_bg' => '#1E3A5F',
            'sidebar_text' => '#CBD5E1',
            'sidebar_active' => '#2563EB',
            'accent' => '#3B82F6',
            'gradient_from' => '#1E3A5F',
            'gradient_to' => '#2563EB',
        ],
        'emerald' => [
            'name' => 'Emerald Green',
            'primary' => '#059669',
            'primary_hover' => '#047857',
            'primary_light' => '#D1FAE5',
            'sidebar_bg' => '#1B3B36',
            'sidebar_text' => '#A7F3D0',
            'sidebar_active' => '#059669',
            'accent' => '#10B981',
            'gradient_from' => '#1B3B36',
            'gradient_to' => '#059669',
        ],
        'crimson' => [
            'name' => 'Crimson Red',
            'primary' => '#DC2626',
            'primary_hover' => '#B91C1C',
            'primary_light' => '#FEE2E2',
            'sidebar_bg' => '#450A0A',
            'sidebar_text' => '#FECACA',
            'sidebar_active' => '#DC2626',
            'accent' => '#EF4444',
            'gradient_from' => '#450A0A',
            'gradient_to' => '#DC2626',
        ],
        'purple' => [
            'name' => 'Royal Purple',
            'primary' => '#7C3AED',
            'primary_hover' => '#6D28D9',
            'primary_light' => '#EDE9FE',
            'sidebar_bg' => '#2E1065',
            'sidebar_text' => '#C4B5FD',
            'sidebar_active' => '#7C3AED',
            'accent' => '#8B5CF6',
            'gradient_from' => '#2E1065',
            'gradient_to' => '#7C3AED',
        ],
        'slate' => [
            'name' => 'Modern Slate',
            'primary' => '#475569',
            'primary_hover' => '#334155',
            'primary_light' => '#F1F5F9',
            'sidebar_bg' => '#0F172A',
            'sidebar_text' => '#94A3B8',
            'sidebar_active' => '#475569',
            'accent' => '#64748B',
            'gradient_from' => '#0F172A',
            'gradient_to' => '#475569',
        ],
        'amber' => [
            'name' => 'Golden Amber',
            'primary' => '#D97706',
            'primary_hover' => '#B45309',
            'primary_light' => '#FEF3C7',
            'sidebar_bg' => '#451A03',
            'sidebar_text' => '#FDE68A',
            'sidebar_active' => '#D97706',
            'accent' => '#F59E0B',
            'gradient_from' => '#451A03',
            'gradient_to' => '#D97706',
        ],
    ];
}

function getActiveTheme() {
    $scheme_key = getSetting('theme_color_scheme', 'blue');
    $schemes = getThemeColorSchemes();
    return $schemes[$scheme_key] ?? $schemes['blue'];
}

function getActiveThemeKey() {
    return getSetting('theme_color_scheme', 'blue');
}

function getLogoPath() {
    $logo = getSetting('site_logo', '');
    if ($logo && file_exists('uploads/logo/' . $logo)) {
        return 'uploads/logo/' . $logo;
    }
    return '';
}

function getSiteName() {
    return getSetting('site_name', APP_NAME);
}
?>
