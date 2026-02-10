<?php
// DIAGNOSTIC SCRIPT - Delete this file after fixing the issue!

echo "<h1>Martial Arts Studio - Diagnostic Check</h1>";
echo "<style>body { font-family: Arial; padding: 20px; } .success { color: green; } .error { color: red; } .info { color: blue; } pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }</style>";

// Step 1: Check Database Configuration
echo "<h2>1. Database Configuration</h2>";
$config = [
    'DB_HOST' => 'localhost',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'martial_arts_studio'
];

echo "<pre>";
print_r($config);
echo "</pre>";

// Step 2: Test Database Connection
echo "<h2>2. Database Connection Test</h2>";
try {
    $pdo = new PDO(
        "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
        $config['DB_USER'],
        $config['DB_PASS'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    echo "<p class='success'>✓ Database connection successful!</p>";
} catch (PDOException $e) {
    echo "<p class='error'>✗ Database connection failed: " . $e->getMessage() . "</p>";
    echo "<p class='info'>Make sure MySQL is running in XAMPP and the database 'martial_arts_studio' exists.</p>";
    exit;
}

// Step 3: Check if users table exists
echo "<h2>3. Check Users Table</h2>";
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        echo "<p class='success'>✓ Users table exists</p>";
    } else {
        echo "<p class='error'>✗ Users table does NOT exist!</p>";
        echo "<p class='info'>Re-import database.sql in phpMyAdmin</p>";
        exit;
    }
} catch (PDOException $e) {
    echo "<p class='error'>Error checking table: " . $e->getMessage() . "</p>";
    exit;
}

// Step 4: Check admin user
echo "<h2>4. Check Admin User</h2>";
try {
    $stmt = $pdo->query("SELECT * FROM users WHERE username = 'admin'");
    $user = $stmt->fetch();
    
    if ($user) {
        echo "<p class='success'>✓ Admin user found!</p>";
        echo "<pre>";
        echo "ID: " . $user['id'] . "\n";
        echo "Username: " . $user['username'] . "\n";
        echo "Email: " . $user['email'] . "\n";
        echo "Role: " . $user['role'] . "\n";
        echo "Password Hash: " . substr($user['password'], 0, 20) . "...\n";
        echo "</pre>";
    } else {
        echo "<p class='error'>✗ Admin user NOT found!</p>";
        echo "<p class='info'>Run this SQL in phpMyAdmin:</p>";
        echo "<pre>INSERT INTO users (username, password, email, full_name, role) 
VALUES ('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@studio.com', 'System Administrator', 'admin');</pre>";
        exit;
    }
} catch (PDOException $e) {
    echo "<p class='error'>Error checking user: " . $e->getMessage() . "</p>";
    exit;
}

// Step 5: Test password verification
echo "<h2>5. Password Verification Test</h2>";
$test_password = 'admin123';
$stored_hash = $user['password'];

echo "<p class='info'>Testing password: <strong>admin123</strong></p>";

if (password_verify($test_password, $stored_hash)) {
    echo "<p class='success'>✓ Password verification WORKS!</p>";
    echo "<p class='info'>The login should work. There might be an issue with the login.php file.</p>";
} else {
    echo "<p class='error'>✗ Password verification FAILED!</p>";
    echo "<p class='info'>The password hash in database is incorrect. Let's create a new one:</p>";
    
    // Generate new hash
    $new_hash = password_hash('admin123', PASSWORD_DEFAULT);
    echo "<p class='info'>Run this SQL in phpMyAdmin to fix it:</p>";
    echo "<pre>UPDATE users SET password = '{$new_hash}' WHERE username = 'admin';</pre>";
}

// Step 6: Test complete login process
echo "<h2>6. Complete Login Simulation</h2>";
$username = 'admin';
$password = 'admin123';

$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$login_user = $stmt->fetch();

if ($login_user && password_verify($password, $login_user['password'])) {
    echo "<p class='success'>✓✓✓ LOGIN WOULD SUCCEED! ✓✓✓</p>";
    echo "<p class='info'>If login.php still doesn't work, check:</p>";
    echo "<ul>";
    echo "<li>Make sure you're accessing the correct URL</li>";
    echo "<li>Clear your browser cache/cookies</li>";
    echo "<li>Check config.php exists and is correct</li>";
    echo "</ul>";
} else {
    echo "<p class='error'>✗ LOGIN WOULD FAIL</p>";
    echo "<p class='info'>Problem identified. Use the SQL command above to fix the password.</p>";
}

echo "<hr>";
echo "<h2>Summary</h2>";
echo "<p>If all checks passed, try these:</p>";
echo "<ol>";
echo "<li>Clear your browser cache and cookies</li>";
echo "<li>Try a different browser</li>";
echo "<li>Make sure you're using: <strong>http://localhost/martial-arts-studio/login.php</strong></li>";
echo "<li>Check that config.php has session_start() at the top</li>";
echo "</ol>";

echo "<hr>";
echo "<p><strong>DELETE THIS FILE (check.php) after fixing the issue!</strong></p>";
?>
