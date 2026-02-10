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
?>
