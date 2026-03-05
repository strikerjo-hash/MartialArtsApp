<?php
/**
 * migrate.php — Database Migration Script
 *
 * Run this script once after deployment to ensure all database tables and
 * columns are up to date. It is safe to run repeatedly (all statements are
 * idempotent).
 *
 * Usage:
 *   php migrate.php          (CLI)
 *   Browse to /migrate.php   (web — requires super_admin login)
 *
 * Previously, these DDL statements were scattered across config.php,
 * includes/auth.php, includes/parent_auth.php, includes/payment_gateway.php,
 * includes/messaging.php, includes/audit.php, and many page files.
 * They have been consolidated here for performance (no DDL on every page load).
 */

$isCli = php_sapi_name() === 'cli';

// In web mode, require super admin auth
if (!$isCli) {
    require_once __DIR__ . '/config.php';
    requireLogin();
    require_super_admin();
}

// Get database connection
if (!isset($pdo)) {
    if (!$isCli) {
        $pdo = get_db();
    } else {
        // CLI: load config manually
        if (file_exists(__DIR__ . '/config.local.php')) {
            require_once __DIR__ . '/config.local.php';
        }
        if (!defined('DB_HOST'))    define('DB_HOST', 'localhost');
        if (!defined('DB_NAME'))    define('DB_NAME', 'martial_arts_app');
        if (!defined('DB_USER'))    define('DB_USER', 'root');
        if (!defined('DB_PASS'))    define('DB_PASS', '');
        if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}

$results = [];
$errors  = [];

function run_migration(PDO $pdo, string $description, string $sql, array &$results, array &$errors): void
{
    try {
        $pdo->exec($sql);
        $results[] = "[OK] $description";
    } catch (PDOException $e) {
        // Most errors are "column already exists" / "table already exists" — expected
        $msg = $e->getMessage();
        if (str_contains($msg, 'Duplicate column') || str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate key name') || str_contains($msg, 'check that column/key exists')) {
            $results[] = "[SKIP] $description (already done)";
        } else {
            $errors[] = "[ERROR] $description: $msg";
        }
    }
}

// ============================================================================
// 1. Core tables
// ============================================================================

run_migration($pdo, 'Create schools table', "CREATE TABLE IF NOT EXISTS schools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    address TEXT DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    timezone VARCHAR(50) DEFAULT 'America/New_York',
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)", $results, $errors);

run_migration($pdo, 'Insert default school', "INSERT IGNORE INTO schools (id, name, slug) VALUES (1, 'Default School', 'default')", $results, $errors);

// Add banner_color column to schools table for per-school banner customisation
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM schools LIKE 'banner_color'");
    if ($colCheck->rowCount() === 0) {
        $pdo->exec("ALTER TABLE schools ADD COLUMN banner_color VARCHAR(7) DEFAULT '#3b82f6'");
        $results[] = '[OK] Added banner_color column to schools table';
    }
} catch (\PDOException $e) {
    $errors[] = '[ERROR] Adding banner_color column: ' . $e->getMessage();
}

run_migration($pdo, 'Create settings table', "CREATE TABLE IF NOT EXISTS settings (
    school_id INT NOT NULL DEFAULT 1,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (school_id, setting_key)
)", $results, $errors);

run_migration($pdo, 'Create studio_config table', "CREATE TABLE IF NOT EXISTS studio_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) UNIQUE NOT NULL,
    config_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)", $results, $errors);

run_migration($pdo, 'Create login_attempts table', "CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_username (username),
    INDEX idx_ip (ip_address),
    INDEX idx_time (attempted_at)
)", $results, $errors);

// ============================================================================
// 2. Multi-tenancy: school_id on users table
// ============================================================================

run_migration($pdo, 'Extend users.role ENUM', "ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','admin','instructor','staff') DEFAULT 'staff'", $results, $errors);
run_migration($pdo, 'Add users.school_id', "ALTER TABLE users ADD COLUMN school_id INT NOT NULL DEFAULT 1 AFTER role", $results, $errors);
run_migration($pdo, 'Index users.school_id', "ALTER TABLE users ADD INDEX idx_users_school (school_id)", $results, $errors);

// ============================================================================
// 3. Multi-tenancy: school_id on all data tables
// ============================================================================

$tenantTables = [
    'students', 'parents', 'parent_students', 'parent_payment_methods', 'classes', 'rooms',
    'class_enrollments', 'attendance', 'membership_plans', 'memberships', 'payments', 'events',
    'event_registrations', 'student_belts', 'training_logs', 'messages', 'message_recipients',
    'discount_codes', 'makeup_classes', 'absence_warnings', 'renewal_log',
];

foreach ($tenantTables as $tbl) {
    run_migration($pdo, "Add {$tbl}.school_id", "ALTER TABLE `{$tbl}` ADD COLUMN school_id INT NOT NULL DEFAULT 1 AFTER id", $results, $errors);
    run_migration($pdo, "Index {$tbl}.school_id", "ALTER TABLE `{$tbl}` ADD INDEX idx_{$tbl}_school (school_id)", $results, $errors);
}

// ============================================================================
// 4. Settings & studio_config: school_id support
// ============================================================================

// Settings table restructure (check first)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM settings LIKE 'school_id'")->fetchAll();
    if (empty($cols)) {
        run_migration($pdo, 'Add settings.school_id', "ALTER TABLE settings DROP PRIMARY KEY", $results, $errors);
        run_migration($pdo, 'Add settings.school_id col', "ALTER TABLE settings ADD COLUMN school_id INT NOT NULL DEFAULT 1 FIRST", $results, $errors);
        run_migration($pdo, 'Rekey settings', "ALTER TABLE settings ADD PRIMARY KEY (school_id, setting_key)", $results, $errors);
    }
} catch (PDOException $e) {
    $results[] = '[SKIP] Settings school_id (already done or table missing)';
}

run_migration($pdo, 'Add studio_config.school_id', "ALTER TABLE studio_config ADD COLUMN school_id INT NOT NULL DEFAULT 1 AFTER id", $results, $errors);

// ============================================================================
// 5. Compound unique constraints (per-school)
// ============================================================================

run_migration($pdo, 'Per-school students.email', "ALTER TABLE students DROP INDEX email, ADD UNIQUE INDEX idx_students_email_school (email, school_id)", $results, $errors);
run_migration($pdo, 'Per-school students.username', "ALTER TABLE students DROP INDEX idx_students_username, ADD UNIQUE INDEX idx_students_username_school (username, school_id)", $results, $errors);
run_migration($pdo, 'Per-school users.username', "ALTER TABLE users DROP INDEX username, ADD UNIQUE INDEX idx_users_username_school (username, school_id)", $results, $errors);
run_migration($pdo, 'Per-school users.email', "ALTER TABLE users DROP INDEX email, ADD UNIQUE INDEX idx_users_email_school (email, school_id)", $results, $errors);
run_migration($pdo, 'Per-school parents.username', "ALTER TABLE parents DROP INDEX username, ADD UNIQUE INDEX idx_parents_username_school (username, school_id)", $results, $errors);
run_migration($pdo, 'Per-school class_enrollments', "ALTER TABLE class_enrollments DROP INDEX unique_enrollment, ADD UNIQUE INDEX unique_enrollment_school (student_id, class_id, school_id)", $results, $errors);
run_migration($pdo, 'Per-school event_registrations', "ALTER TABLE event_registrations DROP INDEX unique_registration, ADD UNIQUE INDEX unique_registration_school (event_id, student_id, school_id)", $results, $errors);

// ============================================================================
// 6. Auth.php migrations: payment lockout, import flags, calendar events
// ============================================================================

run_migration($pdo, 'Extend memberships.payment_status', "ALTER TABLE memberships MODIFY COLUMN payment_status ENUM('paid','pending','partial','declined') DEFAULT 'pending'", $results, $errors);
run_migration($pdo, 'Add students.payment_lockout_override', "ALTER TABLE students ADD COLUMN payment_lockout_override TINYINT(1) DEFAULT 0", $results, $errors);
run_migration($pdo, 'Add students.lockout_override_at', "ALTER TABLE students ADD COLUMN lockout_override_at DATETIME DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add students.lockout_override_by', "ALTER TABLE students ADD COLUMN lockout_override_by INT DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add students.must_change_password', "ALTER TABLE students ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0", $results, $errors);
run_migration($pdo, 'Add students.registration_incomplete', "ALTER TABLE students ADD COLUMN registration_incomplete TINYINT(1) NOT NULL DEFAULT 0", $results, $errors);
run_migration($pdo, 'Add events.requires_registration', "ALTER TABLE events ADD COLUMN requires_registration TINYINT(1) NOT NULL DEFAULT 1", $results, $errors);

// ============================================================================
// 7. Parent auth migrations
// ============================================================================

run_migration($pdo, 'Add students.is_parent', "ALTER TABLE students ADD COLUMN is_parent TINYINT(1) NOT NULL DEFAULT 0", $results, $errors);

run_migration($pdo, 'Create parents table', "CREATE TABLE IF NOT EXISTS parents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    stripe_customer_id VARCHAR(255) DEFAULT NULL,
    square_customer_id VARCHAR(255) DEFAULT NULL,
    account_credit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('active','inactive','suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)", $results, $errors);

run_migration($pdo, 'Create parent_students table', "CREATE TABLE IF NOT EXISTS parent_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    student_id INT NOT NULL,
    relationship ENUM('parent','guardian','other') NOT NULL DEFAULT 'parent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_parent_student (parent_id, student_id)
)", $results, $errors);

run_migration($pdo, 'Create parent_payment_methods table', "CREATE TABLE IF NOT EXISTS parent_payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    label VARCHAR(100) NOT NULL,
    card_brand VARCHAR(20) DEFAULT NULL,
    last_four CHAR(4) NOT NULL,
    exp_month TINYINT DEFAULT NULL,
    exp_year SMALLINT DEFAULT NULL,
    encrypted_token TEXT NOT NULL,
    gateway_payment_method_id VARCHAR(255) DEFAULT NULL,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)", $results, $errors);

run_migration($pdo, 'Add event_registrations.parent_id', "ALTER TABLE event_registrations ADD COLUMN parent_id INT DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add payments.parent_id', "ALTER TABLE payments ADD COLUMN parent_id INT DEFAULT NULL", $results, $errors);

// ============================================================================
// 8. Payment gateway migrations
// ============================================================================

run_migration($pdo, 'Add students.stripe_customer_id', "ALTER TABLE students ADD COLUMN stripe_customer_id VARCHAR(255) DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add students.square_customer_id', "ALTER TABLE students ADD COLUMN square_customer_id VARCHAR(255) DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add students.account_credit', "ALTER TABLE students ADD COLUMN account_credit DECIMAL(10,2) NOT NULL DEFAULT 0.00", $results, $errors);
run_migration($pdo, 'Add students.last_plan_change', "ALTER TABLE students ADD COLUMN last_plan_change DATE DEFAULT NULL", $results, $errors);

run_migration($pdo, 'Create payment_methods table', "CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    label VARCHAR(100) NOT NULL,
    card_brand VARCHAR(20) DEFAULT NULL,
    last_four CHAR(4) NOT NULL,
    exp_month TINYINT DEFAULT NULL,
    exp_year SMALLINT DEFAULT NULL,
    encrypted_token TEXT NOT NULL,
    gateway_payment_method_id VARCHAR(255) DEFAULT NULL,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student (student_id)
)", $results, $errors);

run_migration($pdo, 'Add payment_methods.gateway_payment_method_id', "ALTER TABLE payment_methods ADD COLUMN gateway_payment_method_id VARCHAR(255) DEFAULT NULL AFTER encrypted_token", $results, $errors);

run_migration($pdo, 'Create credit_ledger table', "CREATE TABLE IF NOT EXISTS credit_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    balance_after DECIMAL(10,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    reference_type VARCHAR(50) DEFAULT NULL,
    reference_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student (student_id),
    INDEX idx_created (created_at)
)", $results, $errors);

run_migration($pdo, 'Extend payments.payment_method ENUM', "ALTER TABLE payments MODIFY COLUMN payment_method ENUM('cash','credit_card','debit_card','bank_transfer','account_credit','other') NOT NULL DEFAULT 'other'", $results, $errors);
run_migration($pdo, 'Add payments.status', "ALTER TABLE payments ADD COLUMN status ENUM('completed','failed','refunded') NOT NULL DEFAULT 'completed' AFTER payment_method", $results, $errors);
run_migration($pdo, 'Add membership_plans.registration_fee', "ALTER TABLE membership_plans ADD COLUMN registration_fee DECIMAL(10,2) NOT NULL DEFAULT 0", $results, $errors);

run_migration($pdo, 'Create pending_plan_changes table', "CREATE TABLE IF NOT EXISTS pending_plan_changes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    new_plan_id INT NOT NULL,
    old_plan_id INT DEFAULT NULL,
    old_membership_id INT DEFAULT NULL,
    requested_by INT NOT NULL,
    status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
    proration_amount DECIMAL(10,2) DEFAULT 0,
    proration_credit DECIMAL(10,2) DEFAULT 0,
    proration_type VARCHAR(20) DEFAULT NULL,
    proration_data TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    resolved_at DATETIME DEFAULT NULL,
    INDEX idx_student_status (student_id, status),
    INDEX idx_expires (expires_at, status)
)", $results, $errors);

run_migration($pdo, 'Create discount_codes table', "CREATE TABLE IF NOT EXISTS discount_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    discount_type ENUM('percentage','flat') NOT NULL DEFAULT 'percentage',
    discount_value DECIMAL(10,2) NOT NULL,
    applies_to ENUM('plan_price','registration_fee','both') NOT NULL DEFAULT 'both',
    plan_id INT DEFAULT NULL,
    event_id INT DEFAULT NULL,
    max_uses INT DEFAULT NULL,
    uses_count INT NOT NULL DEFAULT 0,
    valid_from DATE DEFAULT NULL,
    valid_until DATE DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_active (is_active, valid_from, valid_until)
)", $results, $errors);

// ============================================================================
// 9. Messaging migrations
// ============================================================================

run_migration($pdo, 'Create messages table', "CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_type ENUM('admin','student','parent','system') NOT NULL DEFAULT 'admin',
    sender_id INT DEFAULT NULL,
    subject VARCHAR(255) DEFAULT NULL,
    body TEXT NOT NULL,
    message_type ENUM('general','payment','attendance','event','system') DEFAULT 'general',
    priority ENUM('normal','high','urgent') DEFAULT 'normal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)", $results, $errors);

run_migration($pdo, 'Create message_recipients table', "CREATE TABLE IF NOT EXISTS message_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    recipient_type ENUM('student','parent','admin') NOT NULL DEFAULT 'student',
    recipient_id INT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME DEFAULT NULL,
    INDEX idx_message (message_id),
    INDEX idx_recipient (recipient_id, is_read)
)", $results, $errors);

// ============================================================================
// 10. Audit / error logging migrations
// ============================================================================

run_migration($pdo, 'Create audit_log table', "CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    user_type VARCHAR(20) DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) DEFAULT NULL,
    entity_id INT DEFAULT NULL,
    description TEXT DEFAULT NULL,
    old_values TEXT DEFAULT NULL,
    new_values TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
)", $results, $errors);

run_migration($pdo, 'Create app_log table', "CREATE TABLE IF NOT EXISTS app_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level ENUM('error','warning','info','debug') NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    category VARCHAR(50) DEFAULT 'system',
    context TEXT DEFAULT NULL,
    file VARCHAR(255) DEFAULT NULL,
    line INT DEFAULT NULL,
    reference_id VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_level (level),
    INDEX idx_category (category),
    INDEX idx_created (created_at)
)", $results, $errors);

// ============================================================================
// 11. Belt cycle / training migrations
// ============================================================================

run_migration($pdo, 'Create belt_resources table', "CREATE TABLE IF NOT EXISTS belt_resources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    belt_id INT DEFAULT NULL,
    style_id INT DEFAULT NULL,
    resource_type VARCHAR(50) DEFAULT 'document',
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    url VARCHAR(500) DEFAULT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)", $results, $errors);

// ============================================================================
// 12. API tables
// ============================================================================

run_migration($pdo, 'Create api_tokens table', "CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type VARCHAR(20) NOT NULL DEFAULT 'student',
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id, user_type),
    INDEX idx_expires (expires_at)
)", $results, $errors);

run_migration($pdo, 'Create device_tokens table', "CREATE TABLE IF NOT EXISTS device_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type VARCHAR(20) NOT NULL DEFAULT 'student',
    token TEXT NOT NULL,
    platform VARCHAR(20) DEFAULT 'android',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id, user_type)
)", $results, $errors);

// ============================================================================
// 13. Performance indexes (new)
// ============================================================================

run_migration($pdo, 'Index attendance lookup', "ALTER TABLE attendance ADD INDEX idx_attendance_lookup (student_id, class_id, attendance_date)", $results, $errors);
run_migration($pdo, 'Index attendance date', "ALTER TABLE attendance ADD INDEX idx_attendance_date (attendance_date)", $results, $errors);
run_migration($pdo, 'Index payments date', "ALTER TABLE payments ADD INDEX idx_payments_date (payment_date)", $results, $errors);
run_migration($pdo, 'Index payments student', "ALTER TABLE payments ADD INDEX idx_payments_student (student_id)", $results, $errors);
run_migration($pdo, 'Index payments status', "ALTER TABLE payments ADD INDEX idx_payments_status (status)", $results, $errors);
run_migration($pdo, 'Index memberships lookup', "ALTER TABLE memberships ADD INDEX idx_memberships_student_status (student_id, status, end_date)", $results, $errors);
run_migration($pdo, 'Index class_enrollments student', "ALTER TABLE class_enrollments ADD INDEX idx_enrollments_student (student_id, status)", $results, $errors);
run_migration($pdo, 'Index event_registrations student', "ALTER TABLE event_registrations ADD INDEX idx_event_reg_student (student_id)", $results, $errors);
run_migration($pdo, 'Index event_registrations payment', "ALTER TABLE event_registrations ADD INDEX idx_event_reg_payment (payment_status)", $results, $errors);
run_migration($pdo, 'Index message_recipients', "ALTER TABLE message_recipients ADD INDEX idx_msg_recipients (recipient_id, is_read)", $results, $errors);
run_migration($pdo, 'Index training_logs student', "ALTER TABLE training_logs ADD INDEX idx_training_student (student_id)", $results, $errors);

// ============================================================================
// 14. Password resets
// ============================================================================

run_migration($pdo, 'Create password_resets table', "CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL DEFAULT 1,
    user_type VARCHAR(20) NOT NULL DEFAULT 'student',
    user_id INT NOT NULL,
    username VARCHAR(255) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pr_user (user_type, user_id),
    INDEX idx_pr_username (username),
    INDEX idx_pr_expires (expires_at)
)", $results, $errors);

// ============================================================================
// 15. Conversation messaging system
// ============================================================================

run_migration($pdo, 'Create conversations table', "CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL DEFAULT 1,
    participant_one_type ENUM('admin','student') NOT NULL,
    participant_one_id INT NOT NULL,
    participant_two_type ENUM('admin','student') NOT NULL,
    participant_two_id INT NOT NULL,
    last_message_at DATETIME DEFAULT NULL,
    last_message_preview VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_conv_school (school_id),
    INDEX idx_conv_p1 (participant_one_type, participant_one_id, school_id),
    INDEX idx_conv_p2 (participant_two_type, participant_two_id, school_id),
    INDEX idx_conv_last_msg (last_message_at),
    UNIQUE KEY unique_conversation (school_id, participant_one_type, participant_one_id, participant_two_type, participant_two_id)
)", $results, $errors);

run_migration($pdo, 'Create conversation_participants table', "CREATE TABLE IF NOT EXISTS conversation_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL DEFAULT 1,
    conversation_id INT NOT NULL,
    participant_type ENUM('admin','student') NOT NULL,
    participant_id INT NOT NULL,
    last_read_at DATETIME DEFAULT NULL,
    hidden_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cp_school (school_id),
    INDEX idx_cp_conversation (conversation_id),
    INDEX idx_cp_participant (participant_type, participant_id, school_id),
    UNIQUE KEY unique_participant (conversation_id, participant_type, participant_id)
)", $results, $errors);

run_migration($pdo, 'Create direct_messages table', "CREATE TABLE IF NOT EXISTS direct_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL DEFAULT 1,
    conversation_id INT NOT NULL,
    sender_type ENUM('admin','student') NOT NULL,
    sender_id INT NOT NULL,
    body TEXT NOT NULL,
    is_flagged TINYINT(1) NOT NULL DEFAULT 0,
    flag_reason VARCHAR(255) DEFAULT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dm_school (school_id),
    INDEX idx_dm_conversation (conversation_id, created_at),
    INDEX idx_dm_sender (sender_type, sender_id),
    INDEX idx_dm_flagged (is_flagged, school_id)
)", $results, $errors);

run_migration($pdo, 'Create student_blocks table', "CREATE TABLE IF NOT EXISTS student_blocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL DEFAULT 1,
    blocker_student_id INT NOT NULL,
    blocked_student_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sb_school (school_id),
    INDEX idx_sb_blocker (blocker_student_id, school_id),
    INDEX idx_sb_blocked (blocked_student_id, school_id),
    UNIQUE KEY unique_block (school_id, blocker_student_id, blocked_student_id)
)", $results, $errors);

run_migration($pdo, 'Create moderation_words table', "CREATE TABLE IF NOT EXISTS moderation_words (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL DEFAULT 1,
    word VARCHAR(100) NOT NULL,
    severity ENUM('flag','block') NOT NULL DEFAULT 'flag',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mw_school (school_id)
)", $results, $errors);

run_migration($pdo, 'Create flagged_message_reviews table', "CREATE TABLE IF NOT EXISTS flagged_message_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL DEFAULT 1,
    direct_message_id INT NOT NULL,
    reviewed_by INT DEFAULT NULL,
    review_action ENUM('pending','dismissed','warned','suspended') NOT NULL DEFAULT 'pending',
    review_notes TEXT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fmr_school (school_id),
    INDEX idx_fmr_message (direct_message_id),
    INDEX idx_fmr_status (review_action, school_id)
)", $results, $errors);

// Seed default moderation words (only inserts if table is empty)
try {
    $checkWords = $pdo->query("SELECT COUNT(*) FROM moderation_words");
    if ((int) $checkWords->fetchColumn() === 0) {
        $defaultWords = [
            // Threats
            'kill you', 'beat you up', 'hurt you', 'fight you', 'punch you', 'stab you',
            'shoot you', 'murder', 'gonna die', 'death threat', 'come for you',
            // Severe profanity & slurs
            'fuck you', 'fucking', 'motherfucker', 'shit', 'bitch', 'asshole',
            'bastard', 'dick', 'pussy', 'whore', 'slut', 'retard', 'retarded',
            'faggot', 'nigger', 'nigga', 'spic', 'chink', 'kike',
            // Harassment
            'kill yourself', 'kys', 'go die', 'nobody likes you', 'worthless',
            // Bullying
            'ugly', 'fatass', 'loser', 'freak',
        ];
        $insStmt = $pdo->prepare("INSERT INTO moderation_words (school_id, word, severity) VALUES (1, ?, 'flag')");
        foreach ($defaultWords as $w) {
            $insStmt->execute([$w]);
        }
        $results[] = '[OK] Seeded default moderation words (' . count($defaultWords) . ' words)';
    } else {
        $results[] = '[SKIP] Moderation words already seeded';
    }
} catch (\PDOException $e) {
    $errors[] = '[ERROR] Seeding moderation words: ' . $e->getMessage();
}

// ============================================================================
// 16. Membership hold/freeze support
// ============================================================================

run_migration($pdo, 'Extend memberships.status for on_hold', "ALTER TABLE memberships MODIFY COLUMN status ENUM('active','expired','cancelled','on_hold') DEFAULT 'active'", $results, $errors);
run_migration($pdo, 'Add memberships.hold_start_date', "ALTER TABLE memberships ADD COLUMN hold_start_date DATE DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add memberships.hold_end_date', "ALTER TABLE memberships ADD COLUMN hold_end_date DATE DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add memberships.hold_reason', "ALTER TABLE memberships ADD COLUMN hold_reason VARCHAR(255) DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add memberships.cancelled_at', "ALTER TABLE memberships ADD COLUMN cancelled_at DATETIME DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add memberships.cancel_reason', "ALTER TABLE memberships ADD COLUMN cancel_reason VARCHAR(255) DEFAULT NULL", $results, $errors);

// ============================================================================
// 17. Deactivation cascade support
// ============================================================================

run_migration($pdo, 'Add students.deactivation_reason', "ALTER TABLE students ADD COLUMN deactivation_reason ENUM('manual','payment') DEFAULT NULL", $results, $errors);

// ============================================================================
// 18. Student profile extended fields (CSV import support)
// ============================================================================

run_migration($pdo, 'Add students.medical_info', "ALTER TABLE students ADD COLUMN medical_info TEXT DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add students.school_district', "ALTER TABLE students ADD COLUMN school_district VARCHAR(100) DEFAULT NULL", $results, $errors);

// ============================================================================
// 19. Camp program support on membership plans
// ============================================================================

run_migration($pdo, 'Add membership_plans.is_camp', "ALTER TABLE membership_plans ADD COLUMN is_camp TINYINT(1) NOT NULL DEFAULT 0", $results, $errors);

// ============================================================================
// 20. Multi-school user assignments (user_schools junction table)
// ============================================================================

run_migration($pdo, 'Create user_schools junction table', "
    CREATE TABLE IF NOT EXISTS user_schools (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        school_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_school (user_id, school_id),
        KEY idx_user_schools_school (school_id)
    )
", $results, $errors);

// Backfill: copy existing users.school_id into user_schools
try {
    $checkTable = $pdo->query("SELECT COUNT(*) FROM user_schools");
    $rowCount = (int) $checkTable->fetchColumn();
    if ($rowCount === 0) {
        $backfill = $pdo->exec("INSERT IGNORE INTO user_schools (user_id, school_id) SELECT id, school_id FROM users WHERE school_id IS NOT NULL");
        $results[] = "Backfilled user_schools from users.school_id ({$backfill} rows)";
    }
} catch (\PDOException $e) {
    // Table may not exist yet on first run — ignore
}

// ============================================================================
// 21. Multi-belt resource assignments (belt_resource_belts junction table)
// ============================================================================

run_migration($pdo, 'Create belt_resource_belts junction table', "
    CREATE TABLE IF NOT EXISTS belt_resource_belts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        resource_id INT NOT NULL,
        belt_id INT NOT NULL,
        style_id INT NOT NULL,
        UNIQUE KEY unique_resource_belt (resource_id, belt_id),
        KEY idx_brb_belt (belt_id),
        KEY idx_brb_resource (resource_id)
    )
", $results, $errors);

// Backfill: copy existing belt_resources belt assignments into junction table
try {
    $checkBrb = $pdo->query("SELECT COUNT(*) FROM belt_resource_belts");
    $brbCount = (int) $checkBrb->fetchColumn();
    if ($brbCount === 0) {
        $brbFill = $pdo->exec("INSERT IGNORE INTO belt_resource_belts (resource_id, belt_id, style_id) SELECT id, belt_id, style_id FROM belt_resources WHERE belt_id IS NOT NULL AND style_id IS NOT NULL");
        $results[] = "Backfilled belt_resource_belts from belt_resources ({$brbFill} rows)";
    }
} catch (\PDOException $e) {
    // Table may not exist yet on first run — ignore
}

// ============================================================================
// 22. Class curriculum scheduling
// ============================================================================

run_migration($pdo, 'Create class_curriculum table', "
    CREATE TABLE IF NOT EXISTS class_curriculum (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL DEFAULT 1,
        class_id INT NOT NULL,
        class_date DATE NOT NULL,
        line1 VARCHAR(500) DEFAULT NULL,
        line2 VARCHAR(500) DEFAULT NULL,
        line3 VARCHAR(500) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cc_school (school_id),
        INDEX idx_cc_class_date (class_id, class_date),
        UNIQUE KEY unique_cc_entry (school_id, class_id, class_date),
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
    )
", $results, $errors);

// ============================================================================
// 23. Communication consent (TCPA / CAN-SPAM)
// ============================================================================

// Students
run_migration($pdo, 'Add students.comm_consent_email',
    "ALTER TABLE students ADD COLUMN comm_consent_email TINYINT(1) NOT NULL DEFAULT 0", $results, $errors);
run_migration($pdo, 'Add students.comm_consent_email_at',
    "ALTER TABLE students ADD COLUMN comm_consent_email_at DATETIME DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add students.comm_consent_sms',
    "ALTER TABLE students ADD COLUMN comm_consent_sms TINYINT(1) NOT NULL DEFAULT 0", $results, $errors);
run_migration($pdo, 'Add students.comm_consent_sms_at',
    "ALTER TABLE students ADD COLUMN comm_consent_sms_at DATETIME DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add students.comm_consent_version',
    "ALTER TABLE students ADD COLUMN comm_consent_version VARCHAR(50) DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add students.comm_consent_ip',
    "ALTER TABLE students ADD COLUMN comm_consent_ip VARCHAR(45) DEFAULT NULL", $results, $errors);

// Parents
run_migration($pdo, 'Add parents.comm_consent_email',
    "ALTER TABLE parents ADD COLUMN comm_consent_email TINYINT(1) NOT NULL DEFAULT 0", $results, $errors);
run_migration($pdo, 'Add parents.comm_consent_email_at',
    "ALTER TABLE parents ADD COLUMN comm_consent_email_at DATETIME DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add parents.comm_consent_sms',
    "ALTER TABLE parents ADD COLUMN comm_consent_sms TINYINT(1) NOT NULL DEFAULT 0", $results, $errors);
run_migration($pdo, 'Add parents.comm_consent_sms_at',
    "ALTER TABLE parents ADD COLUMN comm_consent_sms_at DATETIME DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add parents.comm_consent_version',
    "ALTER TABLE parents ADD COLUMN comm_consent_version VARCHAR(50) DEFAULT NULL", $results, $errors);
run_migration($pdo, 'Add parents.comm_consent_ip',
    "ALTER TABLE parents ADD COLUMN comm_consent_ip VARCHAR(45) DEFAULT NULL", $results, $errors);

// ============================================================================
// 24. Account merge support
// ============================================================================

run_migration($pdo, 'Create account_merges table', "
    CREATE TABLE IF NOT EXISTS account_merges (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL,
        primary_student_id INT NOT NULL,
        merged_student_id INT NOT NULL,
        merged_by_user_id INT NOT NULL,
        merge_reason VARCHAR(255) DEFAULT NULL,
        records_moved TEXT DEFAULT NULL,
        profile_fields_filled TEXT DEFAULT NULL,
        conflicts_skipped TEXT DEFAULT NULL,
        stripe_customer_id_lost VARCHAR(255) DEFAULT NULL,
        source_account_snapshot TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_merges_school (school_id),
        INDEX idx_merges_primary (primary_student_id),
        INDEX idx_merges_merged (merged_student_id)
    )
", $results, $errors);

run_migration($pdo, 'Widen students.deactivation_reason to VARCHAR(50)',
    "ALTER TABLE students MODIFY COLUMN deactivation_reason VARCHAR(50) DEFAULT NULL", $results, $errors);

// ============================================================================
// 25. Role permissions (4-column schema + seed defaults)
// ============================================================================

run_migration($pdo, 'Create role_permissions table', "
    CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role VARCHAR(50) NOT NULL,
        page VARCHAR(100) NOT NULL,
        can_view TINYINT(1) DEFAULT 0,
        can_create TINYINT(1) DEFAULT 0,
        can_edit TINYINT(1) DEFAULT 0,
        can_delete TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_role_page (role, page)
    )
", $results, $errors);

// Upgrade from 2-column schema to 4-column schema (handles existing installs)
run_migration($pdo, 'Add role_permissions.can_create column',
    "ALTER TABLE role_permissions ADD COLUMN can_create TINYINT(1) DEFAULT 0 AFTER can_view", $results, $errors);
run_migration($pdo, 'Add role_permissions.can_delete column',
    "ALTER TABLE role_permissions ADD COLUMN can_delete TINYINT(1) DEFAULT 0 AFTER can_edit", $results, $errors);

// Seed default permissions for instructor and staff (idempotent — only if no rows exist)
try {
    $checkPerms = $pdo->query("SELECT COUNT(*) FROM role_permissions WHERE role = 'instructor'");
    if ((int)$checkPerms->fetchColumn() === 0) {
        $pdo->exec("INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete) VALUES
            ('instructor', 'index.php',               1, 0, 0, 0),
            ('instructor', 'students.php',             1, 0, 1, 0),
            ('instructor', 'parent_accounts.php',      1, 0, 0, 0),
            ('instructor', 'memberships.php',          1, 0, 0, 0),
            ('instructor', 'classes.php',              1, 0, 1, 0),
            ('instructor', 'events.php',               1, 0, 1, 0),
            ('instructor', 'calendar.php',             1, 0, 0, 0),
            ('instructor', 'attendance.php',           1, 1, 1, 0),
            ('instructor', 'curriculum.php',           0, 0, 0, 0),
            ('instructor', 'makeup_classes.php',       1, 1, 1, 0),
            ('instructor', 'payments.php',             1, 0, 0, 0),
            ('instructor', 'belts.php',                1, 1, 1, 0),
            ('instructor', 'reports_financial.php',     0, 0, 0, 0),
            ('instructor', 'reports_student.php',       1, 0, 0, 0),
            ('instructor', 'messages.php',             1, 1, 0, 0),
            ('instructor', 'users.php',                0, 0, 0, 0),
            ('instructor', 'settings_school.php',       1, 0, 0, 0),
            ('instructor', 'settings_belt.php',         1, 0, 0, 0),
            ('instructor', 'settings_certs.php',        0, 0, 0, 0),
            ('instructor', 'settings_registration.php', 0, 0, 0, 0),
            ('instructor', 'settings_billing.php',      0, 0, 0, 0),
            ('instructor', 'settings_communications.php', 0, 0, 0, 0),
            ('instructor', 'settings_system.php',       0, 0, 0, 0),
            ('instructor', 'admin_dashboard.php',      0, 0, 0, 0),
            ('instructor', 'import_data.php',          0, 0, 0, 0),
            ('instructor', 'merge_accounts.php',       0, 0, 0, 0),
            ('instructor', 'pending_registrations.php',0, 0, 0, 0),
            ('staff', 'index.php',                     1, 0, 0, 0),
            ('staff', 'students.php',                  1, 1, 1, 0),
            ('staff', 'parent_accounts.php',           1, 0, 0, 0),
            ('staff', 'memberships.php',               1, 1, 1, 0),
            ('staff', 'classes.php',                   1, 0, 0, 0),
            ('staff', 'events.php',                    1, 1, 1, 0),
            ('staff', 'calendar.php',                  1, 0, 0, 0),
            ('staff', 'attendance.php',                1, 1, 1, 0),
            ('staff', 'curriculum.php',                0, 0, 0, 0),
            ('staff', 'makeup_classes.php',            1, 1, 0, 0),
            ('staff', 'payments.php',                  1, 1, 0, 0),
            ('staff', 'belts.php',                     1, 0, 0, 0),
            ('staff', 'reports_financial.php',           0, 0, 0, 0),
            ('staff', 'reports_student.php',             1, 0, 0, 0),
            ('staff', 'messages.php',                  1, 1, 0, 0),
            ('staff', 'users.php',                     0, 0, 0, 0),
            ('staff', 'settings_school.php',            0, 0, 0, 0),
            ('staff', 'settings_belt.php',              0, 0, 0, 0),
            ('staff', 'settings_certs.php',             0, 0, 0, 0),
            ('staff', 'settings_registration.php',      0, 0, 0, 0),
            ('staff', 'settings_billing.php',           0, 0, 0, 0),
            ('staff', 'settings_communications.php',    0, 0, 0, 0),
            ('staff', 'settings_system.php',            0, 0, 0, 0),
            ('staff', 'admin_dashboard.php',           0, 0, 0, 0),
            ('staff', 'import_data.php',               0, 0, 0, 0),
            ('staff', 'merge_accounts.php',            0, 0, 0, 0),
            ('staff', 'pending_registrations.php',     1, 0, 1, 0)
        ");
        $results[] = '[OK] Seeded default role_permissions for instructor and staff roles';
    } else {
        $results[] = '[SKIP] role_permissions already seeded for instructor';
    }
} catch (\PDOException $e) {
    $errors[] = '[ERROR] Seeding role_permissions: ' . $e->getMessage();
}

// Migrate old settings.php permission to new per-tab permissions
try {
    $oldSettings = $pdo->query("SELECT COUNT(*) FROM role_permissions WHERE page = 'settings.php'");
    if ((int)$oldSettings->fetchColumn() > 0) {
        // School & Schedule and Belt Testing inherit old view access
        $pdo->exec("INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete)
            SELECT role, 'settings_school.php', can_view, can_create, can_edit, can_delete
            FROM role_permissions WHERE page = 'settings.php'");
        $pdo->exec("INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete)
            SELECT role, 'settings_belt.php', can_view, can_create, can_edit, can_delete
            FROM role_permissions WHERE page = 'settings.php'");
        // Other 5 tabs default to deny-by-default
        foreach (['settings_certs.php', 'settings_registration.php', 'settings_billing.php', 'settings_communications.php', 'settings_system.php'] as $newPage) {
            $pdo->exec("INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete)
                SELECT role, '$newPage', 0, 0, 0, 0
                FROM role_permissions WHERE page = 'settings.php'");
        }
        // Remove old settings.php rows
        $pdo->exec("DELETE FROM role_permissions WHERE page = 'settings.php'");
        $results[] = '[OK] Migrated settings.php permission to 7 per-tab settings permissions';
    }
} catch (\PDOException $e) {
    $errors[] = '[ERROR] Migrating settings permissions: ' . $e->getMessage();
}

// Migrate old reports.php permission to new split permissions (reports_financial.php + reports_student.php)
try {
    $oldReports = $pdo->query("SELECT COUNT(*) FROM role_permissions WHERE page = 'reports.php'");
    if ((int)$oldReports->fetchColumn() > 0) {
        // Copy the old reports.php can_view to reports_student.php (student data inherits old access)
        $pdo->exec("INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete)
            SELECT role, 'reports_student.php', can_view, can_create, can_edit, can_delete
            FROM role_permissions WHERE page = 'reports.php'");
        // Financial reports defaults to no access for non-admin roles (deny-by-default)
        $pdo->exec("INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete)
            SELECT role, 'reports_financial.php', 0, 0, 0, 0
            FROM role_permissions WHERE page = 'reports.php'");
        // Remove old reports.php rows
        $pdo->exec("DELETE FROM role_permissions WHERE page = 'reports.php'");
        $results[] = '[OK] Migrated reports.php permission to reports_financial.php + reports_student.php';
    }
} catch (\PDOException $e) {
    $errors[] = '[ERROR] Migrating reports permissions: ' . $e->getMessage();
}

// ============================================================================
// Output results
// ============================================================================

if ($isCli) {
    echo "\n=== MartialArtsApp Database Migration ===\n\n";
    foreach ($results as $r) echo "  $r\n";
    if (!empty($errors)) {
        echo "\n--- ERRORS ---\n";
        foreach ($errors as $e) echo "  $e\n";
    }
    echo "\nDone. " . count($results) . " migrations checked, " . count($errors) . " errors.\n";
} else {
    include 'includes/header.php';
    echo '<div class="container mx-auto px-4 py-8">';
    echo '<h1 class="text-3xl font-bold text-gray-800 mb-6">Database Migration</h1>';
    echo '<div class="bg-white rounded-lg shadow p-6">';
    echo '<pre class="text-sm font-mono whitespace-pre-wrap">';
    foreach ($results as $r) echo htmlspecialchars($r) . "\n";
    if (!empty($errors)) {
        echo "\n--- ERRORS ---\n";
        foreach ($errors as $e) echo htmlspecialchars($e) . "\n";
    }
    echo "\nDone. " . count($results) . " migrations checked, " . count($errors) . " errors.";
    echo '</pre></div></div>';
    include 'includes/footer.php';
}
