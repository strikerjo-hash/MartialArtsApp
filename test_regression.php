<?php
/**
 * test_regression.php — Full Regression Test Suite
 *
 * Admin-only tool that runs automated diagnostic checks against every
 * major subsystem of the application:
 *
 *   1. Database Schema — required tables and columns exist
 *   2. Configuration — gateway keys, encryption, email, theme
 *   3. Authentication — admin/student/parent login systems
 *   4. Students — CRUD, search, filtering, activity status
 *   5. Memberships — plans, active memberships, billing fields
 *   6. Payments — payment methods, payment records, gateway IDs
 *   7. Belt System — styles, belts, resources, training content
 *   8. Events — event records, registrations
 *   9. Classes & Attendance — class schedules, attendance records
 *  10. Multi-Tenancy — school_id present on tenant tables
 *  11. Permissions — role_permissions populated for all roles
 *  12. Data Integrity — FK relationships, orphan detection
 *  13. Email System — SMTP config, SMS, broadcast messages, delivery stats
 *  14. Password Reset — table, page, login link, reset history
 *  15. Conversation Messaging — tables, helpers, moderation, flagged reviews
 *  16. Parent System — accounts, links, payment methods, header correctness
 *  17. Page Accessibility — critical pages are reachable
 *  18. Curriculum System — curriculum table, entries, import page, cycle rollover
 *  19. Training Guides — admin, student, parent wizard pages exist
 *
 * Each check returns: PASS, WARN, or FAIL with a description.
 * No data is modified — all checks are read-only.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';
requireLogin();

$user = getCurrentUser();
if (!in_array($user['role'], ['admin', 'super_admin'])) {
    accessDenied('Regression testing requires Admin or Super Admin privileges.');
}

$pageTitle = 'Regression Test Suite';

// ---------------------------------------------------------------------------
// Test framework
// ---------------------------------------------------------------------------

$testResults = [];
$categoryTotals = [];

function addResult(string $category, string $name, string $status, string $detail = ''): void
{
    global $testResults, $categoryTotals;
    $testResults[] = [
        'category' => $category,
        'name'     => $name,
        'status'   => $status, // 'pass', 'warn', 'fail'
        'detail'   => $detail,
    ];
    if (!isset($categoryTotals[$category])) {
        $categoryTotals[$category] = ['pass' => 0, 'warn' => 0, 'fail' => 0];
    }
    $categoryTotals[$category][$status]++;
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return $stmt !== false;
    } catch (\PDOException $e) {
        return false;
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt->fetch() !== false;
    } catch (\PDOException $e) {
        return false;
    }
}

function countRows(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (\PDOException $e) {
        return -1;
    }
}

/**
 * Count rows in a table scoped to the current school.
 * Uses school_where_clause() (WHERE school_id = ?) and school_param() for correct SQL.
 */
function countSchoolRows(PDO $pdo, string $table): int
{
    $params = [];
    school_param($params);
    return countRows($pdo, "SELECT COUNT(*) FROM {$table} " . school_where_clause(), $params);
}

function fileAccessible(string $path): bool
{
    return file_exists(__DIR__ . '/' . $path);
}

// ===========================================================================
// RUN ALL TESTS
// ===========================================================================

// ---------------------------------------------------------------------------
// 1. DATABASE SCHEMA
// ---------------------------------------------------------------------------
$cat = 'Database Schema';

$requiredTables = [
    'users'                   => 'Admin/staff user accounts',
    'students'                => 'Student records',
    'parents'                 => 'Parent accounts',
    'parent_students'         => 'Parent-student relationships',
    'classes'                 => 'Class schedules',
    'attendance'              => 'Attendance records',
    'membership_plans'        => 'Membership plan definitions',
    'memberships'             => 'Student memberships',
    'payments'                => 'Payment records',
    'events'                  => 'Events and tournaments',
    'event_registrations'     => 'Event sign-ups',
    'martial_arts_styles'     => 'Martial arts styles (global)',
    'belts'                   => 'Belt ranks (global)',
    'student_belts'           => 'Student belt awards',
    'belt_resources'          => 'Belt training resources (global)',
    'settings'                => 'Application settings (per-school)',
    'studio_config'           => 'Studio branding/theme',
    'role_permissions'        => 'Role-based permissions',
    'login_attempts'          => 'Login rate limiting',
    'payment_methods'         => 'Stored payment cards (student)',
    'parent_payment_methods'  => 'Stored payment cards (parent)',
    'renewal_log'             => 'Renewal processing history',
    'schools'                 => 'Multi-tenant schools',
    'discount_codes'          => 'Discount/promo codes',
    'credit_ledger'           => 'Account credit audit trail',
    'pending_plan_changes'    => 'Plan upgrade/downgrade requests',
    'messages'                => 'Broadcast messaging system',
    'message_recipients'      => 'Broadcast delivery tracking',
    'password_resets'         => 'Password reset verification codes',
    'conversations'           => 'Direct message conversation threads',
    'conversation_participants' => 'Conversation participant metadata',
    'direct_messages'         => 'Direct messages within conversations',
    'student_blocks'          => 'Student-to-student blocking',
    'moderation_words'        => 'Content moderation word list',
    'flagged_message_reviews' => 'Flagged message admin review queue',
    'class_curriculum'        => 'Curriculum content per class/date',
    'class_enrollments'       => 'Student class enrollment',
    'makeup_classes'          => 'Make-up class tracking',
    'absence_warnings'        => 'Absence warning records',
    'rooms'                   => 'Training rooms/spaces',
    'user_schools'            => 'User-to-school assignment for multi-school',
    'audit_log'               => 'System audit trail',
];

foreach ($requiredTables as $tbl => $desc) {
    if (tableExists($pdo, $tbl)) {
        addResult($cat, "Table: $tbl", 'pass', $desc);
    } else {
        addResult($cat, "Table: $tbl", 'fail', "MISSING — $desc");
    }
}

// Key columns
$keyColumns = [
    ['students', 'username', 'Student login username'],
    ['students', 'password_hash', 'Student password hash'],
    ['students', 'activity_status', 'Activity tracking (active/inactive)'],
    ['students', 'inactive_since', 'Date student went inactive'],
    ['students', 'must_change_password', 'Force password change on import'],
    ['students', 'registration_incomplete', 'Force registration completion on import'],
    ['students', 'stripe_customer_id', 'Stripe customer reference'],
    ['students', 'square_customer_id', 'Square customer reference'],
    ['students', 'account_credit', 'Account credit balance'],
    ['students', 'school_id', 'Multi-tenancy school reference'],
    ['memberships', 'auto_renew', 'Auto-renewal flag'],
    ['memberships', 'billing_day', 'Monthly billing day'],
    ['memberships', 'monthly_charges_made', 'Monthly installment counter'],
    ['memberships', 'payment_status', 'Payment status (paid/declined)'],
    ['memberships', 'school_id', 'Multi-tenancy school reference'],
    ['membership_plans', 'billing_frequency', 'Upfront vs monthly billing'],
    ['membership_plans', 'is_afterschool', 'Afterschool program flag'],
    ['membership_plans', 'tax_deductible', 'Tax-deductible flag'],
    ['membership_plans', 'registration_fee', 'Registration fee amount'],
    ['events', 'tax_deductible', 'Tax-deductible event flag'],
    ['payment_methods', 'gateway_payment_method_id', 'Stripe/Square PM reference'],
    ['users', 'school_id', 'Multi-tenancy school reference'],
    // Parent payment methods
    ['parent_payment_methods', 'parent_id', 'Parent reference for payment card'],
    ['parent_payment_methods', 'gateway_payment_method_id', 'Stripe/Square PM reference (parent)'],
    ['parent_payment_methods', 'is_default', 'Default payment method flag'],
    // Password resets
    ['password_resets', 'user_type', 'Student or parent reset'],
    ['password_resets', 'code_hash', 'Bcrypt-hashed verification code'],
    ['password_resets', 'expires_at', 'Code expiration timestamp'],
    ['password_resets', 'used_at', 'Code consumption timestamp'],
    // Conversation messaging
    ['conversations', 'participant_one_type', 'First participant type (admin/student)'],
    ['conversations', 'participant_two_type', 'Second participant type (admin/student)'],
    ['conversations', 'last_message_at', 'Last message timestamp for ordering'],
    ['conversation_participants', 'last_read_at', 'Read cursor for unread count'],
    ['conversation_participants', 'hidden_at', 'Soft-hide timestamp'],
    ['direct_messages', 'conversation_id', 'Thread foreign key'],
    ['direct_messages', 'sender_type', 'Sender type (admin/student)'],
    ['direct_messages', 'body', 'Message content'],
    ['direct_messages', 'is_flagged', 'Content moderation flag'],
    ['direct_messages', 'flag_reason', 'Matched moderation words'],
    ['student_blocks', 'blocker_student_id', 'Student who initiated the block'],
    ['student_blocks', 'blocked_student_id', 'Student who was blocked'],
    ['moderation_words', 'word', 'Flagged word or phrase'],
    ['moderation_words', 'severity', 'Flag or block severity level'],
    ['flagged_message_reviews', 'direct_message_id', 'FK to flagged message'],
    ['flagged_message_reviews', 'review_action', 'Admin review action taken'],
    // Curriculum system
    ['class_curriculum', 'class_id', 'FK to classes table'],
    ['class_curriculum', 'class_date', 'Curriculum target date'],
    ['class_curriculum', 'line1', 'Focus/line1 curriculum content'],
    ['class_curriculum', 'line2', 'Rotation/line2 curriculum content'],
    ['class_curriculum', 'line3', 'Technique/line3 curriculum content'],
    ['class_curriculum', 'school_id', 'Multi-tenancy school reference'],
    // Class enrollments
    ['class_enrollments', 'student_id', 'FK to students table'],
    ['class_enrollments', 'class_id', 'FK to classes table'],
    ['class_enrollments', 'status', 'Enrollment status (active/dropped)'],
    ['class_enrollments', 'school_id', 'Multi-tenancy school reference'],
    // Make-up classes
    ['makeup_classes', 'student_id', 'FK to students table'],
    ['makeup_classes', 'makeup_date', 'Date of make-up class'],
    ['makeup_classes', 'school_id', 'Multi-tenancy school reference'],
    // Absence warnings
    ['absence_warnings', 'student_id', 'FK to students table'],
    ['absence_warnings', 'cycle_start', 'Belt testing cycle start date'],
    ['absence_warnings', 'email_sent', 'Whether warning email was sent'],
    ['absence_warnings', 'school_id', 'Multi-tenancy school reference'],
    // Rooms
    ['rooms', 'name', 'Room display name'],
    ['rooms', 'school_id', 'Multi-tenancy school reference'],
];

foreach ($keyColumns as [$tbl, $col, $desc]) {
    if (!tableExists($pdo, $tbl)) {
        addResult($cat, "Column: $tbl.$col", 'fail', "Table $tbl doesn't exist — $desc");
    } elseif (columnExists($pdo, $tbl, $col)) {
        addResult($cat, "Column: $tbl.$col", 'pass', $desc);
    } else {
        addResult($cat, "Column: $tbl.$col", 'warn', "MISSING — $desc. Run sql/migrate.sql");
    }
}

// ---------------------------------------------------------------------------
// 2. CONFIGURATION
// ---------------------------------------------------------------------------
$cat = 'Configuration';

// Encryption key
if (defined('ENCRYPTION_KEY') && strlen(ENCRYPTION_KEY) >= 64) {
    addResult($cat, 'Encryption key', 'pass', 'ENCRYPTION_KEY is set (' . strlen(ENCRYPTION_KEY) . ' chars)');
} elseif (defined('ENCRYPTION_KEY')) {
    addResult($cat, 'Encryption key', 'warn', 'ENCRYPTION_KEY is set but shorter than recommended (64 hex chars)');
} else {
    addResult($cat, 'Encryption key', 'fail', 'ENCRYPTION_KEY not defined in config.php');
}

// Database connection
try {
    $pdo->query("SELECT 1");
    addResult($cat, 'Database connection', 'pass', 'Connected to ' . DB_NAME . '@' . DB_HOST);
} catch (\PDOException $e) {
    addResult($cat, 'Database connection', 'fail', $e->getMessage());
}

// Payment gateway
$gw = get_active_gateway();
$gwReady = is_gateway_ready();
if ($gw !== 'none' && $gwReady) {
    $mode = '';
    if ($gw === 'stripe') $mode = is_stripe_test_mode() ? ' (Test mode)' : ' (LIVE)';
    if ($gw === 'square') $mode = is_square_sandbox() ? ' (Sandbox)' : ' (LIVE)';
    addResult($cat, 'Payment gateway', 'pass', ucfirst($gw) . ' configured and ready' . $mode);
} elseif ($gw !== 'none') {
    addResult($cat, 'Payment gateway', 'warn', ucfirst($gw) . ' selected but not fully configured (missing API keys)');
} else {
    addResult($cat, 'Payment gateway', 'warn', 'No payment gateway configured. Auto-renewals will not charge cards.');
}

// Theme/branding
try {
    $theme = getActiveTheme();
    addResult($cat, 'Theme system', 'pass', 'Active theme loaded (' . getActiveThemeKey() . ')');
} catch (\Exception $e) {
    addResult($cat, 'Theme system', 'warn', 'Theme system error: ' . $e->getMessage());
}

// Site name
$siteName = getSiteName();
if ($siteName && $siteName !== APP_NAME) {
    addResult($cat, 'Studio name', 'pass', 'Custom studio name: ' . $siteName);
} else {
    addResult($cat, 'Studio name', 'warn', 'Using default app name. Configure in Settings > Studio Branding.');
}

// Service fee
$feePct = getServiceFeePercentage();
addResult($cat, 'Service fee', 'pass', $feePct > 0 ? "Service fee: {$feePct}%" : 'No service fee configured');

// ---------------------------------------------------------------------------
// 3. AUTHENTICATION
// ---------------------------------------------------------------------------
$cat = 'Authentication';

// Admin users exist
$adminCount = countRows($pdo, "SELECT COUNT(*) FROM users WHERE role IN ('admin','super_admin')");
if ($adminCount > 0) {
    addResult($cat, 'Admin accounts', 'pass', "$adminCount admin/super_admin user(s) found");
} else {
    addResult($cat, 'Admin accounts', 'fail', 'No admin users found in users table');
}

// Student login capability
$studentsWithLogin = countRows($pdo, "SELECT COUNT(*) FROM students WHERE username IS NOT NULL AND username != '' AND password_hash IS NOT NULL AND password_hash != ''");
$totalStudents = countRows($pdo, "SELECT COUNT(*) FROM students");
if ($studentsWithLogin > 0) {
    addResult($cat, 'Student logins', 'pass', "$studentsWithLogin of $totalStudents students have login credentials");
} elseif ($totalStudents > 0) {
    addResult($cat, 'Student logins', 'warn', "0 of $totalStudents students have login credentials. Run migrate.sql to set defaults.");
} else {
    addResult($cat, 'Student logins', 'warn', 'No students in database yet');
}

// Parent accounts
$parentCount = countRows($pdo, "SELECT COUNT(*) FROM parents");
if ($parentCount >= 0) {
    addResult($cat, 'Parent accounts', 'pass', "$parentCount parent account(s) found");
}

// Login attempts table functional
if (tableExists($pdo, 'login_attempts')) {
    addResult($cat, 'Rate limiting', 'pass', 'login_attempts table exists for brute-force protection');
} else {
    addResult($cat, 'Rate limiting', 'warn', 'login_attempts table missing. Login rate limiting disabled.');
}

// ---------------------------------------------------------------------------
// 4. STUDENTS
// ---------------------------------------------------------------------------
$cat = 'Students';

if ($totalStudents > 0) {
    addResult($cat, 'Student records', 'pass', "$totalStudents student(s) in database");
} else {
    addResult($cat, 'Student records', 'warn', 'No students found. Import or add students.');
}

// Activity status distribution
if (columnExists($pdo, 'students', 'activity_status')) {
    $activeStudents = countRows($pdo, "SELECT COUNT(*) FROM students WHERE activity_status = 'active'" . school_where(), [current_school_id()]);
    $inactiveStudents = countRows($pdo, "SELECT COUNT(*) FROM students WHERE activity_status = 'inactive'" . school_where(), [current_school_id()]);
    addResult($cat, 'Activity tracking', 'pass', "$activeStudents active, $inactiveStudents inactive (this school)");
} else {
    addResult($cat, 'Activity tracking', 'warn', 'activity_status column missing. Run migrate.sql.');
}

// Students with email
$withEmail = countRows($pdo, "SELECT COUNT(*) FROM students WHERE email IS NOT NULL AND email != ''");
addResult($cat, 'Email coverage', $withEmail === $totalStudents ? 'pass' : 'warn',
    "$withEmail of $totalStudents students have email addresses");

// ---------------------------------------------------------------------------
// 5. MEMBERSHIPS
// ---------------------------------------------------------------------------
$cat = 'Memberships';

$planCount = countRows($pdo, "SELECT COUNT(*) FROM membership_plans");
if ($planCount > 0) {
    addResult($cat, 'Membership plans', 'pass', "$planCount plan(s) defined");
} else {
    addResult($cat, 'Membership plans', 'warn', 'No membership plans created yet');
}

// Billing frequency distribution
if (columnExists($pdo, 'membership_plans', 'billing_frequency')) {
    $upfrontPlans = countRows($pdo, "SELECT COUNT(*) FROM membership_plans WHERE billing_frequency = 'upfront' OR billing_frequency IS NULL");
    $monthlyPlans = countRows($pdo, "SELECT COUNT(*) FROM membership_plans WHERE billing_frequency = 'monthly'");
    addResult($cat, 'Plan types', 'pass', "$upfrontPlans upfront, $monthlyPlans monthly billing plan(s)");
}

// Active memberships
$params = [];
$activeMemberships = countRows($pdo, "SELECT COUNT(*) FROM memberships WHERE status = 'active'" . school_where(), [current_school_id()]);
addResult($cat, 'Active memberships', $activeMemberships > 0 ? 'pass' : 'warn',
    "$activeMemberships active membership(s) in current school");

// Auto-renew stats
$autoRenewOn = countRows($pdo, "SELECT COUNT(*) FROM memberships WHERE status = 'active' AND auto_renew = 1" . school_where(), [current_school_id()]);
$autoRenewOff = $activeMemberships - $autoRenewOn;
addResult($cat, 'Auto-renew', 'pass', "$autoRenewOn with auto-renew ON, $autoRenewOff with auto-renew OFF");

// Expired memberships
$expiredCount = countRows($pdo, "SELECT COUNT(*) FROM memberships WHERE status = 'expired'" . school_where(), [current_school_id()]);
addResult($cat, 'Expired memberships', 'pass', "$expiredCount expired membership(s)");

// On-hold memberships
if (columnExists($pdo, 'memberships', 'hold_start_date')) {
    $onHoldCount = countRows($pdo, "SELECT COUNT(*) FROM memberships WHERE status = 'on_hold'" . school_where(), [current_school_id()]);
    addResult($cat, 'On-hold memberships', 'pass', "$onHoldCount membership(s) currently on hold");

    // Cancelled with reason
    if (columnExists($pdo, 'memberships', 'cancel_reason')) {
        $cancelledCount = countRows($pdo, "SELECT COUNT(*) FROM memberships WHERE status = 'cancelled'" . school_where(), [current_school_id()]);
        addResult($cat, 'Cancelled memberships', 'pass', "$cancelledCount cancelled membership(s)");
    }
} else {
    addResult($cat, 'Hold fields', 'warn', 'hold_start_date column missing — run migrate.php to add membership hold support');
}

// Afterschool programs
if (columnExists($pdo, 'membership_plans', 'is_afterschool')) {
    $afterschoolCount = countRows($pdo, "SELECT COUNT(*) FROM membership_plans WHERE is_afterschool = 1");
    addResult($cat, 'Afterschool programs', 'pass', "$afterschoolCount afterschool plan(s)");
}

// ---------------------------------------------------------------------------
// 6. PAYMENTS
// ---------------------------------------------------------------------------
$cat = 'Payments';

// Payment records
$paymentCount = countSchoolRows($pdo, 'payments');
addResult($cat, 'Payment records', $paymentCount > 0 ? 'pass' : 'warn',
    "$paymentCount payment(s) recorded in current school");

// Payment methods
$cardCount = countRows($pdo, "SELECT COUNT(*) FROM payment_methods");
$withGatewayId = countRows($pdo, "SELECT COUNT(*) FROM payment_methods WHERE gateway_payment_method_id IS NOT NULL AND gateway_payment_method_id != ''");
if ($cardCount > 0) {
    addResult($cat, 'Payment methods', 'pass', "$cardCount stored card(s), $withGatewayId with gateway IDs");
    if ($cardCount > $withGatewayId) {
        $legacy = $cardCount - $withGatewayId;
        addResult($cat, 'Legacy cards', 'warn', "$legacy card(s) have no gateway_payment_method_id — these cannot be charged. Students should re-add their cards.");
    }
} else {
    addResult($cat, 'Payment methods', 'warn', 'No payment methods on file');
}

// Credit system
$studentsWithCredit = countRows($pdo, "SELECT COUNT(*) FROM students WHERE account_credit > 0");
$totalCredit = 0;
try {
    $stmt = $pdo->query("SELECT COALESCE(SUM(account_credit), 0) FROM students WHERE account_credit > 0");
    $totalCredit = (float) $stmt->fetchColumn();
} catch (\PDOException $e) {}
addResult($cat, 'Account credits', 'pass', "$studentsWithCredit student(s) have credit totaling " . formatMoney($totalCredit));

// Renewal log
$logCount = countSchoolRows($pdo, 'renewal_log');
addResult($cat, 'Renewal log', 'pass', "$logCount renewal log entries");

// Discount codes
$activeDiscounts = countRows($pdo, "SELECT COUNT(*) FROM discount_codes WHERE is_active = 1");
addResult($cat, 'Discount codes', 'pass', "$activeDiscounts active discount code(s)");

// ---------------------------------------------------------------------------
// 7. BELT SYSTEM
// ---------------------------------------------------------------------------
$cat = 'Belt System';

$styleCount = countRows($pdo, "SELECT COUNT(*) FROM martial_arts_styles");
$beltCount = countRows($pdo, "SELECT COUNT(*) FROM belts");
$resourceCount = countRows($pdo, "SELECT COUNT(*) FROM belt_resources");

if ($styleCount > 0) {
    addResult($cat, 'Martial arts styles', 'pass', "$styleCount style(s) defined");
} else {
    addResult($cat, 'Martial arts styles', 'warn', 'No martial arts styles defined. Add them in Belt Management.');
}

if ($beltCount > 0) {
    addResult($cat, 'Belt ranks', 'pass', "$beltCount belt(s) defined");
} else {
    addResult($cat, 'Belt ranks', 'warn', 'No belts defined');
}

addResult($cat, 'Training resources', $resourceCount > 0 ? 'pass' : 'warn',
    "$resourceCount belt resource(s) uploaded");

// Unassigned resources
$unassigned = countRows($pdo, "SELECT COUNT(*) FROM belt_resources WHERE belt_id IS NULL");
if ($unassigned > 0) {
    addResult($cat, 'Unassigned resources', 'warn', "$unassigned resource(s) not yet assigned to a belt");
}

// Belt awards
$awardCount = countRows($pdo, "SELECT COUNT(*) FROM student_belts");
addResult($cat, 'Belt awards', $awardCount > 0 ? 'pass' : 'warn', "$awardCount belt award(s) given to students");

// Training content accessibility (check that queries work on global tables without school_id)
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM belts b JOIN martial_arts_styles mas ON b.style_id = mas.id");
    $joinCount = (int) $stmt->fetchColumn();
    addResult($cat, 'Training query (global join)', 'pass', "Belt-Style join returns $joinCount rows (no school_id filter — correct)");
} catch (\PDOException $e) {
    addResult($cat, 'Training query (global join)', 'fail', 'Join query failed: ' . $e->getMessage());
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM belt_resources br LEFT JOIN belts b ON br.belt_id = b.id");
    $resJoinCount = (int) $stmt->fetchColumn();
    addResult($cat, 'Resources query (global join)', 'pass', "Resources-Belt join returns $resJoinCount rows (no school_id filter — correct)");
} catch (\PDOException $e) {
    addResult($cat, 'Resources query (global join)', 'fail', 'Join query failed: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 8. EVENTS
// ---------------------------------------------------------------------------
$cat = 'Events';

$eventCount = countSchoolRows($pdo, 'events');
addResult($cat, 'Events', $eventCount >= 0 ? 'pass' : 'fail', "$eventCount event(s) in current school");

$regCount = countSchoolRows($pdo, 'event_registrations');
addResult($cat, 'Event registrations', 'pass', "$regCount registration(s)");

// Tax deductible events
if (columnExists($pdo, 'events', 'tax_deductible')) {
    $taxEvents = countRows($pdo, "SELECT COUNT(*) FROM events WHERE tax_deductible = 1");
    addResult($cat, 'Tax-deductible events', 'pass', "$taxEvents tax-deductible event(s)");
}

// ---------------------------------------------------------------------------
// 9. CLASSES & ATTENDANCE
// ---------------------------------------------------------------------------
$cat = 'Classes & Attendance';

$classCount = countSchoolRows($pdo, 'classes');
addResult($cat, 'Classes', $classCount >= 0 ? 'pass' : 'warn', "$classCount class(es) scheduled");

$attendanceCount = countSchoolRows($pdo, 'attendance');
addResult($cat, 'Attendance records', 'pass', "$attendanceCount attendance record(s)");

// Enrollments
try {
    $enrollCount = countSchoolRows($pdo, 'class_enrollments');
    addResult($cat, 'Class enrollments', 'pass', "$enrollCount enrollment(s)");
} catch (\PDOException $e) {
    addResult($cat, 'Class enrollments', 'warn', 'Could not query enrollments');
}

// ---------------------------------------------------------------------------
// 10. MULTI-TENANCY
// ---------------------------------------------------------------------------
$cat = 'Multi-Tenancy';

$schoolCount = countRows($pdo, "SELECT COUNT(*) FROM schools WHERE status = 'active'");
addResult($cat, 'Active schools', 'pass', "$schoolCount active school(s)");

$currentSchool = current_school_id();
addResult($cat, 'Current school context', 'pass', "Operating as school_id = $currentSchool");

// Verify school_id on key tenant tables
$tenantTables = ['students', 'memberships', 'payments', 'attendance', 'events', 'classes',
    'class_curriculum', 'class_enrollments', 'makeup_classes', 'absence_warnings', 'rooms'];
foreach ($tenantTables as $tbl) {
    if (tableExists($pdo, $tbl) && columnExists($pdo, $tbl, 'school_id')) {
        addResult($cat, "Tenant: $tbl.school_id", 'pass', 'Multi-tenancy column present');
    } elseif (tableExists($pdo, $tbl)) {
        addResult($cat, "Tenant: $tbl.school_id", 'fail', 'Missing school_id column — multi-tenancy broken');
    }
}

// Global tables should NOT have school_id (or it's fine if they do, but queries shouldn't filter on it)
$globalTables = ['belts', 'belt_resources', 'martial_arts_styles', 'role_permissions'];
foreach ($globalTables as $gTbl) {
    if (tableExists($pdo, $gTbl)) {
        $hasSchoolId = columnExists($pdo, $gTbl, 'school_id');
        addResult($cat, "Global: $gTbl", 'pass',
            $hasSchoolId ? 'Has school_id (queries should NOT filter on it)' : 'No school_id (correct — global table)');
    }
}

// ---------------------------------------------------------------------------
// 11. PERMISSIONS
// ---------------------------------------------------------------------------
$cat = 'Permissions';

$roles = ['admin', 'instructor', 'staff', 'student'];
foreach ($roles as $role) {
    $permCount = countRows($pdo, "SELECT COUNT(*) FROM role_permissions WHERE role = ?", [$role]);
    if ($permCount > 0) {
        addResult($cat, "Permissions: $role", 'pass', "$permCount page permission(s) defined");
    } else {
        addResult($cat, "Permissions: $role", $role === 'student' ? 'pass' : 'warn',
            $role === 'student' ? 'Student permissions use portal pages' : "No permissions defined for $role role. Run migrate.sql.");
    }
}

// Verify key page permissions
$keyPages = ['students.php', 'memberships.php', 'payments.php', 'belts.php', 'settings.php'];
foreach ($keyPages as $page) {
    $adminPerm = countRows($pdo, "SELECT COUNT(*) FROM role_permissions WHERE role = 'admin' AND page = ? AND can_view = 1", [$page]);
    addResult($cat, "Admin access: $page", $adminPerm > 0 ? 'pass' : 'warn',
        $adminPerm > 0 ? 'Admin can view' : 'No explicit permission row (admin bypass may still work)');
}

// ---------------------------------------------------------------------------
// 12. DATA INTEGRITY
// ---------------------------------------------------------------------------
$cat = 'Data Integrity';

// Orphaned memberships (student_id not in students)
$orphanedMemberships = countRows($pdo, "SELECT COUNT(*) FROM memberships m LEFT JOIN students s ON m.student_id = s.id WHERE s.id IS NULL");
if ($orphanedMemberships === 0) {
    addResult($cat, 'Orphaned memberships', 'pass', 'No memberships reference missing students');
} else {
    addResult($cat, 'Orphaned memberships', 'warn', "$orphanedMemberships membership(s) reference non-existent students");
}

// Orphaned payments
$orphanedPayments = countRows($pdo, "SELECT COUNT(*) FROM payments p LEFT JOIN students s ON p.student_id = s.id WHERE s.id IS NULL");
if ($orphanedPayments === 0) {
    addResult($cat, 'Orphaned payments', 'pass', 'No payments reference missing students');
} else {
    addResult($cat, 'Orphaned payments', 'warn', "$orphanedPayments payment(s) reference non-existent students");
}

// Orphaned attendance
$orphanedAttendance = countRows($pdo, "SELECT COUNT(*) FROM attendance a LEFT JOIN students s ON a.student_id = s.id WHERE s.id IS NULL");
if ($orphanedAttendance === 0) {
    addResult($cat, 'Orphaned attendance', 'pass', 'No attendance records reference missing students');
} else {
    addResult($cat, 'Orphaned attendance', 'warn', "$orphanedAttendance attendance record(s) reference non-existent students");
}

// Memberships referencing invalid plans
$invalidPlanMemberships = countRows($pdo, "SELECT COUNT(*) FROM memberships m LEFT JOIN membership_plans mp ON m.plan_id = mp.id WHERE mp.id IS NULL");
if ($invalidPlanMemberships === 0) {
    addResult($cat, 'Plan references', 'pass', 'All memberships reference valid plans');
} else {
    addResult($cat, 'Plan references', 'warn', "$invalidPlanMemberships membership(s) reference deleted/missing plans");
}

// Student belts referencing valid belts
$invalidBeltAwards = countRows($pdo, "SELECT COUNT(*) FROM student_belts sb LEFT JOIN belts b ON sb.belt_id = b.id WHERE b.id IS NULL");
if ($invalidBeltAwards === 0) {
    addResult($cat, 'Belt award references', 'pass', 'All belt awards reference valid belts');
} else {
    addResult($cat, 'Belt award references', 'warn', "$invalidBeltAwards belt award(s) reference missing belts");
}

// Duplicate active memberships per student
$dupMemberships = countRows($pdo, "SELECT COUNT(*) FROM (SELECT student_id, COUNT(*) as cnt FROM memberships WHERE status = 'active' GROUP BY student_id HAVING cnt > 1) AS dupes");
if ($dupMemberships === 0) {
    addResult($cat, 'Duplicate memberships', 'pass', 'No students have multiple active memberships');
} else {
    addResult($cat, 'Duplicate memberships', 'warn', "$dupMemberships student(s) have multiple active memberships");
}

// Payment methods with missing students
$orphanedCards = countRows($pdo, "SELECT COUNT(*) FROM payment_methods pm LEFT JOIN students s ON pm.student_id = s.id WHERE s.id IS NULL");
if ($orphanedCards === 0) {
    addResult($cat, 'Orphaned payment methods', 'pass', 'All payment methods belong to existing students');
} else {
    addResult($cat, 'Orphaned payment methods', 'warn', "$orphanedCards payment method(s) belong to deleted students");
}

// Orphaned direct messages (conversation_id not in conversations)
if (tableExists($pdo, 'direct_messages') && tableExists($pdo, 'conversations')) {
    $orphanedDMs = countRows($pdo, "SELECT COUNT(*) FROM direct_messages dm LEFT JOIN conversations c ON dm.conversation_id = c.id WHERE c.id IS NULL");
    if ($orphanedDMs === 0) {
        addResult($cat, 'Orphaned direct messages', 'pass', 'All direct messages reference valid conversations');
    } else {
        addResult($cat, 'Orphaned direct messages', 'warn', "$orphanedDMs direct message(s) reference missing conversations");
    }
}

// Orphaned conversation participants
if (tableExists($pdo, 'conversation_participants') && tableExists($pdo, 'conversations')) {
    $orphanedParts = countRows($pdo, "SELECT COUNT(*) FROM conversation_participants cp LEFT JOIN conversations c ON cp.conversation_id = c.id WHERE c.id IS NULL");
    if ($orphanedParts === 0) {
        addResult($cat, 'Orphaned participants', 'pass', 'All participant rows reference valid conversations');
    } else {
        addResult($cat, 'Orphaned participants', 'warn', "$orphanedParts participant row(s) reference missing conversations");
    }
}

// Orphaned flagged message reviews
if (tableExists($pdo, 'flagged_message_reviews') && tableExists($pdo, 'direct_messages')) {
    $orphanedFlags = countRows($pdo, "SELECT COUNT(*) FROM flagged_message_reviews fmr LEFT JOIN direct_messages dm ON fmr.direct_message_id = dm.id WHERE dm.id IS NULL");
    if ($orphanedFlags === 0) {
        addResult($cat, 'Orphaned flag reviews', 'pass', 'All flag reviews reference valid messages');
    } else {
        addResult($cat, 'Orphaned flag reviews', 'warn', "$orphanedFlags flag review(s) reference missing messages");
    }
}

// ---------------------------------------------------------------------------
// 13. EMAIL SYSTEM
// ---------------------------------------------------------------------------
$cat = 'Email System';

// Email configuration check
$emailConfigured = false;
try {
    require_once __DIR__ . '/includes/messaging.php';
    $emailConfigured = function_exists('is_email_configured') && is_email_configured();
} catch (\Exception $e) {}

if ($emailConfigured) {
    addResult($cat, 'SMTP configured', 'pass', 'Email system is configured and ready to send');

    // Check SMTP settings are present
    try {
        $smtpParams = [];
        $smtpSql = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption')" . school_where();
        school_param($smtpParams);
        $smtpStmt = $pdo->prepare($smtpSql);
        $smtpStmt->execute($smtpParams);
        $smtpSettings = $smtpStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        if (!empty($smtpSettings['smtp_host'])) {
            addResult($cat, 'SMTP host', 'pass', 'Host: ' . $smtpSettings['smtp_host']);
        } else {
            addResult($cat, 'SMTP host', 'warn', 'SMTP host not set');
        }

        if (!empty($smtpSettings['smtp_port'])) {
            addResult($cat, 'SMTP port', 'pass', 'Port: ' . $smtpSettings['smtp_port']);
        } else {
            addResult($cat, 'SMTP port', 'warn', 'SMTP port not set');
        }

        $encryption = $smtpSettings['smtp_encryption'] ?? 'none';
        if ($encryption !== 'none') {
            addResult($cat, 'SMTP encryption', 'pass', 'Encryption: ' . strtoupper($encryption));
        } else {
            addResult($cat, 'SMTP encryption', 'warn', 'No SMTP encryption — emails sent in plain text');
        }
    } catch (\PDOException $e) {
        addResult($cat, 'SMTP settings', 'warn', 'Could not read SMTP settings');
    }
} else {
    addResult($cat, 'SMTP configured', 'warn', 'Email not configured — notification emails will not be sent. Configure in Settings > Communications.');
}

// SMS configuration check
$smsConfigured = false;
try {
    $smsConfigured = function_exists('is_sms_configured') && is_sms_configured();
} catch (\Exception $e) {}
addResult($cat, 'SMS configured', $smsConfigured ? 'pass' : 'warn',
    $smsConfigured ? 'Twilio SMS is configured' : 'SMS not configured — verification codes sent via email/in-app only');

// send_email() function exists
if (function_exists('send_email')) {
    addResult($cat, 'send_email() function', 'pass', 'Email sending function available');
} else {
    addResult($cat, 'send_email() function', 'fail', 'send_email() function not found in includes/messaging.php');
}

// Broadcast messages stats
$totalMessages = countSchoolRows($pdo, 'messages');
$sentMessages = countRows($pdo, "SELECT COUNT(*) FROM messages WHERE status = 'sent'" . school_where(), [current_school_id()]);
addResult($cat, 'Broadcast messages', 'pass', "$sentMessages sent of $totalMessages total broadcast message(s)");

// Message delivery stats
$totalRecipients = countSchoolRows($pdo, 'message_recipients');
$readRecipients = countRows($pdo, "SELECT COUNT(*) FROM message_recipients WHERE inapp_status = 'read'" . school_where(), [current_school_id()]);
addResult($cat, 'Message delivery', 'pass', "$readRecipients read of $totalRecipients recipient(s)");

// ---------------------------------------------------------------------------
// 14. PASSWORD RESET
// ---------------------------------------------------------------------------
$cat = 'Password Reset';

if (tableExists($pdo, 'password_resets')) {
    addResult($cat, 'password_resets table', 'pass', 'Table exists');

    $totalResets = countRows($pdo, "SELECT COUNT(*) FROM password_resets");
    $usedResets = countRows($pdo, "SELECT COUNT(*) FROM password_resets WHERE used_at IS NOT NULL");
    $expiredUnused = countRows($pdo, "SELECT COUNT(*) FROM password_resets WHERE used_at IS NULL AND expires_at < NOW()");
    addResult($cat, 'Reset history', 'pass', "$totalResets total reset(s), $usedResets completed, $expiredUnused expired unused");
} else {
    addResult($cat, 'password_resets table', 'fail', 'MISSING — Run migrate.php to create the table');
}

// Password reset page accessible
if (fileAccessible('password_reset.php')) {
    addResult($cat, 'Reset page file', 'pass', 'password_reset.php exists');
} else {
    addResult($cat, 'Reset page file', 'fail', 'password_reset.php is MISSING');
}

// Login page has reset link
try {
    $loginContent = file_get_contents(__DIR__ . '/login.php');
    if (str_contains($loginContent, 'password_reset.php')) {
        addResult($cat, 'Login reset link', 'pass', 'login.php contains link to password_reset.php');
    } else {
        addResult($cat, 'Login reset link', 'warn', 'login.php does not link to password_reset.php');
    }
} catch (\Throwable $e) {
    addResult($cat, 'Login reset link', 'warn', 'Could not read login.php');
}

// ---------------------------------------------------------------------------
// 15. CONVERSATION MESSAGING
// ---------------------------------------------------------------------------
$cat = 'Conversation Messaging';

if (tableExists($pdo, 'conversations')) {
    $convCount = countSchoolRows($pdo, 'conversations');
    addResult($cat, 'Conversations', 'pass', "$convCount conversation(s) in current school");
} else {
    addResult($cat, 'Conversations table', 'fail', 'MISSING — Run migrate.php to create conversation tables');
}

if (tableExists($pdo, 'direct_messages')) {
    $dmCount = countSchoolRows($pdo, 'direct_messages');
    $flaggedCount = countRows($pdo, "SELECT COUNT(*) FROM direct_messages WHERE is_flagged = 1" . school_where(), [current_school_id()]);
    addResult($cat, 'Direct messages', 'pass', "$dmCount message(s), $flaggedCount flagged");
} else {
    addResult($cat, 'Direct messages table', 'fail', 'MISSING — Run migrate.php');
}

if (tableExists($pdo, 'student_blocks')) {
    $blockCount = countSchoolRows($pdo, 'student_blocks');
    addResult($cat, 'Student blocks', 'pass', "$blockCount active block(s)");
} else {
    addResult($cat, 'Student blocks table', 'fail', 'MISSING — Run migrate.php');
}

if (tableExists($pdo, 'moderation_words')) {
    $wordCount = countRows($pdo, "SELECT COUNT(*) FROM moderation_words");
    if ($wordCount > 0) {
        addResult($cat, 'Moderation words', 'pass', "$wordCount moderation word(s) loaded");
    } else {
        addResult($cat, 'Moderation words', 'warn', 'No moderation words seeded — Run migrate.php to populate default word list');
    }
} else {
    addResult($cat, 'Moderation words table', 'fail', 'MISSING — Run migrate.php');
}

if (tableExists($pdo, 'flagged_message_reviews')) {
    $pendingReviews = countRows($pdo, "SELECT COUNT(*) FROM flagged_message_reviews WHERE review_action = 'pending'" . school_where(), [current_school_id()]);
    $totalReviews = countSchoolRows($pdo, 'flagged_message_reviews');
    if ($pendingReviews > 0) {
        addResult($cat, 'Flagged reviews', 'warn', "$pendingReviews pending review(s) of $totalReviews total — admin action needed");
    } else {
        addResult($cat, 'Flagged reviews', 'pass', "$totalReviews total review(s), none pending");
    }
} else {
    addResult($cat, 'Flagged reviews table', 'fail', 'MISSING — Run migrate.php');
}

// Conversation helpers file
if (fileAccessible('includes/conversation_helpers.php')) {
    addResult($cat, 'Conversation helpers', 'pass', 'includes/conversation_helpers.php exists');

    // Verify key functions are available
    require_once __DIR__ . '/includes/conversation_helpers.php';
    $requiredFunctions = [
        'find_or_create_conversation', 'send_direct_message', 'check_message_moderation',
        'get_conversations_for_user', 'get_conversation_messages', 'get_unread_conversation_count',
        'is_blocked', 'can_block_user', 'block_student', 'unblock_student',
        'get_messageable_contacts', 'get_messageable_contacts_admin', 'hide_conversation', 'mark_conversation_read',
    ];
    $missingFns = [];
    foreach ($requiredFunctions as $fn) {
        if (!function_exists($fn)) $missingFns[] = $fn;
    }
    if (empty($missingFns)) {
        addResult($cat, 'Helper functions', 'pass', count($requiredFunctions) . ' required functions all available');
    } else {
        addResult($cat, 'Helper functions', 'fail', 'Missing: ' . implode(', ', $missingFns));
    }
} else {
    addResult($cat, 'Conversation helpers', 'fail', 'includes/conversation_helpers.php is MISSING');
}

// Content moderation smoke test
if (function_exists('check_message_moderation') && tableExists($pdo, 'moderation_words')) {
    $modResult = check_message_moderation('This is a perfectly normal message', (int) current_school_id());
    if (!$modResult['flagged']) {
        addResult($cat, 'Moderation: clean message', 'pass', 'Clean message correctly not flagged');
    } else {
        addResult($cat, 'Moderation: clean message', 'warn', 'Clean message was flagged — check moderation word list for overly broad terms');
    }

    $modResult2 = check_message_moderation('I will kill you', (int) current_school_id());
    if ($modResult2['flagged']) {
        addResult($cat, 'Moderation: threat message', 'pass', 'Threat message correctly flagged (' . implode(', ', $modResult2['reasons']) . ')');
    } else {
        addResult($cat, 'Moderation: threat message', 'warn', 'Threat message was NOT flagged — moderation word list may be empty');
    }
}

// ---------------------------------------------------------------------------
// 15b. PROGRAM COPY HELPERS
// ---------------------------------------------------------------------------
$cat = 'Program Copy Helpers';

if (fileAccessible('includes/program_copy_helpers.php')) {
    require_once __DIR__ . '/includes/program_copy_helpers.php';
    $copyFunctions = [
        'copy_membership_plan', 'copy_class_to_school', 'copy_event_to_school',
        'copy_room_to_school', 'get_program_item_name',
    ];
    $missingCopyFns = [];
    foreach ($copyFunctions as $fn) {
        if (!function_exists($fn)) $missingCopyFns[] = $fn;
    }
    if (empty($missingCopyFns)) {
        addResult($cat, 'Copy helper functions', 'pass', count($copyFunctions) . ' program copy functions all available');
    } else {
        addResult($cat, 'Copy helper functions', 'fail', 'Missing: ' . implode(', ', $missingCopyFns));
    }
} else {
    addResult($cat, 'Copy helpers file', 'warn', 'includes/program_copy_helpers.php not found — program duplication feature unavailable');
}

if (fileAccessible('admin_copy_programs.php')) {
    addResult($cat, 'Copy programs page', 'pass', 'admin_copy_programs.php exists');
} else {
    addResult($cat, 'Copy programs page', 'warn', 'admin_copy_programs.php not found');
}

// ---------------------------------------------------------------------------
// 16. PARENT SYSTEM
// ---------------------------------------------------------------------------
$cat = 'Parent System';

// Parent accounts
if (tableExists($pdo, 'parents')) {
    $parentCount = countRows($pdo, "SELECT COUNT(*) FROM parents");
    addResult($cat, 'Legacy parent accounts', 'pass', "$parentCount parent account(s) in parents table");
}

// Student-as-parent
$studentParents = countRows($pdo, "SELECT COUNT(*) FROM students WHERE is_parent = 1");
addResult($cat, 'Student-as-parent accounts', 'pass', "$studentParents student(s) with is_parent=1");

// Parent-student links
if (tableExists($pdo, 'parent_students')) {
    $linkCount = countRows($pdo, "SELECT COUNT(*) FROM parent_students");
    addResult($cat, 'Parent-child links', 'pass', "$linkCount parent-student link(s)");

    // Orphaned links
    $orphanedLinks = countRows($pdo, "SELECT COUNT(*) FROM parent_students ps LEFT JOIN students s ON ps.student_id = s.id WHERE s.id IS NULL");
    if ($orphanedLinks === 0) {
        addResult($cat, 'Orphaned parent links', 'pass', 'All parent-child links reference valid students');
    } else {
        addResult($cat, 'Orphaned parent links', 'warn', "$orphanedLinks link(s) reference missing students");
    }
}

// Parent payment methods
if (tableExists($pdo, 'parent_payment_methods')) {
    $parentCards = countRows($pdo, "SELECT COUNT(*) FROM parent_payment_methods");
    addResult($cat, 'Parent payment methods', 'pass', "$parentCards parent payment card(s) on file");
} else {
    addResult($cat, 'Parent payment methods', 'warn', 'parent_payment_methods table missing — legacy parents cannot save cards');
}

// Parent header correct on parent pages
$parentPageHeaders = [
    'parent_child_membership.php'         => 'parent_header.php',
    'parent_child_membership_payment.php' => 'parent_header.php',
    'parent_child_training.php'           => 'parent_header.php',
    'parent_events.php'                   => 'parent_header.php',
    'parent_event_payment.php'            => 'parent_header.php',
];
foreach ($parentPageHeaders as $page => $expectedHeader) {
    if (fileAccessible($page)) {
        try {
            $content = file_get_contents(__DIR__ . '/' . $page);
            if (str_contains($content, $expectedHeader)) {
                addResult($cat, "Header: $page", 'pass', "Uses $expectedHeader");
            } else {
                addResult($cat, "Header: $page", 'fail', "Does NOT include $expectedHeader — may show wrong navigation");
            }
        } catch (\Throwable $e) {
            addResult($cat, "Header: $page", 'warn', "Could not read file");
        }
    }
}

// ---------------------------------------------------------------------------
// 17. PAGE ACCESSIBILITY (file existence)
// ---------------------------------------------------------------------------
$cat = 'Page Accessibility';

$criticalPages = [
    // Admin pages
    'index.php'                    => 'Admin dashboard (redirect)',
    'admin_dashboard.php'          => 'Admin dashboard',
    'admin_login.php'              => 'Admin login',
    'login.php'                    => 'Student/parent login',
    'students.php'                 => 'Student management',
    'student_detail.php'           => 'Student detail view',
    'student_edit.php'             => 'Student edit form',
    'memberships.php'              => 'Membership management',
    'payments.php'                 => 'Payment management',
    'classes.php'                  => 'Class management',
    'attendance.php'               => 'Attendance tracking',
    'events.php'                   => 'Event management',
    'event_detail.php'             => 'Event detail view',
    'belts.php'                    => 'Belt management',
    'belt_resources_upload.php'    => 'Bulk resource upload',
    'reports.php'                  => 'Reports',
    'settings.php'                 => 'Application settings',
    'permissions.php'              => 'Role permissions',
    'messages.php'                 => 'Admin broadcast messaging',
    'admin_flagged_messages.php'   => 'Flagged messages review',
    'admin_conversations.php'      => 'Admin conversation inbox',
    'admin_conversation_audit.php' => 'Conversation audit/search',
    'admin_delinquency.php'        => 'Payment status & projected income',
    'admin_copy_programs.php'      => 'Copy programs between schools',
    'admin_training.php'           => 'Admin training wizard',
    'curriculum.php'               => 'Curriculum management page',
    'import_curriculum.php'        => 'Curriculum import from XLSX',
    'makeup_classes.php'           => 'Make-up class management',
    'calendar.php'                 => 'Calendar view',
    'discount_codes.php'           => 'Discount code management',
    'schools.php'                  => 'Multi-school management',
    'users.php'                    => 'User management',
    'audit_log.php'                => 'Audit log viewer',
    'import_payments.php'          => 'Payment import',
    'export_data.php'              => 'Data export',
    'import_data.php'              => 'Student data import',
    'report_export_excel.php'      => 'Report export to Excel',
    'report_export_pdf.php'        => 'Report export to PDF',
    'pending_registrations.php'    => 'Pending student registrations',
    'pending_payments.php'         => 'Pending payments',
    'tax_statement.php'            => 'Tax statement generator',
    'parent_accounts.php'          => 'Parent account management (admin)',
    'cron.php'                     => 'Renewal processor',
    'migrate.php'                  => 'Database migrations',
    // Student portal
    'student_login.php'            => 'Student login',
    'student_portal.php'           => 'Student portal dashboard',
    'student_training.php'         => 'Student training content',
    'student_messages.php'         => 'Student announcements inbox',
    'student_conversations.php'    => 'Student conversation messaging',
    'student_events.php'           => 'Student event browsing',
    'student_payment.php'          => 'Student payment methods',
    'student_upgrade.php'          => 'Student membership upgrade',
    'student_profile.php'          => 'Student profile editing',
    'student_transactions.php'     => 'Student transaction history',
    'student_certificate.php'      => 'Student certificate view',
    'student_register.php'         => 'Student self-registration',
    'student_training_wizard.php'  => 'Student training wizard',
    'student_event_register.php'   => 'Student event registration',
    'student_event_payment.php'    => 'Student event payment',
    'student_upgrade_payment.php'  => 'Student upgrade payment',
    'student_approve_change.php'   => 'Student plan change approval',
    // Parent portal
    'parent_portal.php'            => 'Parent portal dashboard',
    'parent_child.php'             => 'Parent child overview',
    'parent_child_training.php'    => 'Parent training content view',
    'parent_child_membership.php'  => 'Parent child membership',
    'parent_child_membership_payment.php' => 'Parent child membership payment',
    'parent_events.php'            => 'Parent event browsing',
    'parent_event_payment.php'     => 'Parent event payment',
    'parent_payment.php'           => 'Parent payment methods',
    'parent_profile.php'           => 'Parent profile editing',
    'parent_transactions.php'      => 'Parent transaction history',
    'parent_tax_statement.php'     => 'Parent tax statement (IRS Form 2441)',
    'parent_register.php'          => 'Parent self-registration',
    'parent_training_wizard.php'   => 'Parent training wizard',
    // Registration & auth
    'register.php'                 => 'Public registration page',
    'complete_registration.php'    => 'Complete registration after import',
    'logout.php'                   => 'Admin logout handler',
    'student_logout.php'           => 'Student logout handler',
    'parent_logout.php'            => 'Parent logout handler',
    'error.php'                    => 'Error page handler',
    // Password reset
    'password_reset.php'           => 'Self-service password reset',
    // Core includes
    'config.php'                   => 'Main configuration',
    'includes/header.php'          => 'Admin header/nav',
    'includes/footer.php'          => 'Admin footer',
    'includes/student_header.php'  => 'Student portal header/nav',
    'includes/parent_header.php'   => 'Parent portal header/nav',
    'includes/payment_gateway.php' => 'Payment gateway abstraction',
    'includes/tenant.php'          => 'Multi-tenancy helpers',
    'includes/auth.php'            => 'Authentication functions',
    'includes/parent_auth.php'     => 'Parent authentication functions',
    'includes/messaging.php'       => 'Broadcast messaging system',
    'includes/conversation_helpers.php' => 'Conversation messaging helpers',
    'includes/belt_cycle.php'      => 'Belt testing cycle & absence tracking',
    'includes/program_copy_helpers.php' => 'Program copy helpers',
    'includes/security.php'        => 'Security functions',
    'includes/theme.php'           => 'Theme/branding system',
];

foreach ($criticalPages as $file => $desc) {
    if (fileAccessible($file)) {
        addResult($cat, $file, 'pass', $desc);
    } else {
        addResult($cat, $file, 'fail', "MISSING — $desc");
    }
}

// Upload directories — use actual file write test instead of is_writable()
// (is_writable() is unreliable on Windows/OneDrive paths)
$uploadDirs = ['uploads', 'uploads/belt_documents'];
foreach ($uploadDirs as $dir) {
    $fullPath = __DIR__ . '/' . $dir;
    if (is_dir($fullPath)) {
        $writable = false;
        $testFile = $fullPath . '/.write_test_' . bin2hex(random_bytes(4));
        try {
            if (@file_put_contents($testFile, 'test') !== false) {
                $writable = true;
                @unlink($testFile);
            }
        } catch (\Throwable $e) {}
        addResult($cat, "Dir: $dir", $writable ? 'pass' : 'warn',
            $writable ? 'Exists and writable' : 'Exists but NOT writable — uploads will fail');
    } else {
        addResult($cat, "Dir: $dir", 'warn', 'Directory missing — file uploads may fail');
    }
}

// ---------------------------------------------------------------------------
// 18. CURRICULUM SYSTEM
// ---------------------------------------------------------------------------
$cat = 'Curriculum System';

// class_curriculum table and data
if (tableExists($pdo, 'class_curriculum')) {
    $curriculumCount = countSchoolRows($pdo, 'class_curriculum');
    addResult($cat, 'Curriculum entries', $curriculumCount > 0 ? 'pass' : 'warn',
        "$curriculumCount curriculum entry(ies) in current school");

    // Orphaned curriculum (class_id not in classes)
    $orphanedCurr = countRows($pdo, "SELECT COUNT(*) FROM class_curriculum cc LEFT JOIN classes c ON cc.class_id = c.id WHERE c.id IS NULL");
    if ($orphanedCurr === 0) {
        addResult($cat, 'Orphaned curriculum', 'pass', 'All curriculum entries reference valid classes');
    } else {
        addResult($cat, 'Orphaned curriculum', 'warn', "$orphanedCurr curriculum entry(ies) reference missing classes");
    }

    // Unique key check (school_id, class_id, class_date)
    try {
        $stmt = $pdo->query("SHOW INDEX FROM class_curriculum WHERE Non_unique = 0 AND Key_name != 'PRIMARY'");
        $uniqueKeys = $stmt->fetchAll();
        if (count($uniqueKeys) > 0) {
            addResult($cat, 'Curriculum unique key', 'pass', 'Unique index present on class_curriculum');
        } else {
            addResult($cat, 'Curriculum unique key', 'warn', 'No unique index found — duplicate entries possible');
        }
    } catch (\PDOException $e) {
        addResult($cat, 'Curriculum unique key', 'warn', 'Could not check indexes');
    }
} else {
    addResult($cat, 'class_curriculum table', 'fail', 'MISSING — Run migrate.php to create the table');
}

// Belt testing cycle settings
$cycleStart = getSetting('belt_testing_cycle_start_date', '');
$cycleEnd = getSetting('belt_testing_cycle_end_date', '');
if (!empty($cycleStart) && !empty($cycleEnd)) {
    addResult($cat, 'Belt testing cycle', 'pass',
        "Cycle: $cycleStart to $cycleEnd");

    // Validate cycle dates make sense
    if (strtotime($cycleEnd) > strtotime($cycleStart)) {
        $cycleDays = (int) round((strtotime($cycleEnd) - strtotime($cycleStart)) / 86400);
        addResult($cat, 'Cycle date validity', 'pass', "Cycle spans $cycleDays days");
    } else {
        addResult($cat, 'Cycle date validity', 'warn', 'Cycle end date is before start date');
    }
} else {
    addResult($cat, 'Belt testing cycle', 'warn',
        'Belt testing cycle dates not configured. Set in Settings > Belt Testing.');
}

// Absence warning threshold
$absThreshold = getSetting('absence_warning_threshold', '');
if (!empty($absThreshold)) {
    addResult($cat, 'Absence threshold', 'pass', "Warning after $absThreshold net absences");
} else {
    addResult($cat, 'Absence threshold', 'warn', 'Absence warning threshold not set (defaults to 3)');
}

// Belt cycle helper functions
require_once __DIR__ . '/includes/belt_cycle.php';
$beltCycleFns = ['get_current_cycle', 'count_absences_in_cycle', 'count_makeups_in_cycle',
    'get_net_absences', 'get_student_absence_summary', 'check_and_send_absence_warnings'];
$missingBCFns = [];
foreach ($beltCycleFns as $fn) {
    if (!function_exists($fn)) $missingBCFns[] = $fn;
}
if (empty($missingBCFns)) {
    addResult($cat, 'Belt cycle functions', 'pass', count($beltCycleFns) . ' belt cycle functions all available');
} else {
    addResult($cat, 'Belt cycle functions', 'fail', 'Missing: ' . implode(', ', $missingBCFns));
}

// Curriculum management page exists and has AJAX endpoint
if (fileAccessible('curriculum.php')) {
    addResult($cat, 'Curriculum page', 'pass', 'curriculum.php exists');

    // Check for AJAX inline editing support
    try {
        $currPageContent = file_get_contents(__DIR__ . '/curriculum.php');
        if (str_contains($currPageContent, 'save_cell')) {
            addResult($cat, 'Inline editing', 'pass', 'curriculum.php supports AJAX inline cell editing');
        } else {
            addResult($cat, 'Inline editing', 'warn', 'curriculum.php may not support inline editing');
        }
        if (str_contains($currPageContent, 'apply_to_new_cycle')) {
            addResult($cat, 'Cycle rollover', 'pass', 'curriculum.php supports Apply to New Cycle');
        } else {
            addResult($cat, 'Cycle rollover', 'warn', 'curriculum.php may not support cycle rollover');
        }
    } catch (\Throwable $e) {
        addResult($cat, 'Curriculum features', 'warn', 'Could not read curriculum.php');
    }
} else {
    addResult($cat, 'Curriculum page', 'fail', 'curriculum.php is MISSING');
}

// Import curriculum page
if (fileAccessible('import_curriculum.php')) {
    addResult($cat, 'Import curriculum page', 'pass', 'import_curriculum.php exists');
} else {
    addResult($cat, 'Import curriculum page', 'fail', 'import_curriculum.php is MISSING');
}

// Curriculum entries have content
if (tableExists($pdo, 'class_curriculum')) {
    $emptyEntries = countRows($pdo,
        "SELECT COUNT(*) FROM class_curriculum WHERE (line1 IS NULL OR line1 = '') AND (line2 IS NULL OR line2 = '') AND (line3 IS NULL OR line3 = '')" . school_where(),
        [current_school_id()]);
    if ($emptyEntries === 0) {
        addResult($cat, 'Curriculum content', 'pass', 'All curriculum entries have at least one content field');
    } else {
        addResult($cat, 'Curriculum content', 'warn', "$emptyEntries curriculum entry(ies) have no content in any field");
    }
}

// Class enrollments
if (tableExists($pdo, 'class_enrollments')) {
    $enrollmentCount = countSchoolRows($pdo, 'class_enrollments');
    $activeEnrollments = countRows($pdo,
        "SELECT COUNT(*) FROM class_enrollments WHERE status = 'active'" . school_where(),
        [current_school_id()]);
    addResult($cat, 'Class enrollments', 'pass',
        "$activeEnrollments active of $enrollmentCount total enrollment(s)");

    // Orphaned enrollments
    $orphanedEnroll = countRows($pdo,
        "SELECT COUNT(*) FROM class_enrollments ce LEFT JOIN students s ON ce.student_id = s.id WHERE s.id IS NULL");
    if ($orphanedEnroll === 0) {
        addResult($cat, 'Orphaned enrollments', 'pass', 'All enrollments reference valid students');
    } else {
        addResult($cat, 'Orphaned enrollments', 'warn', "$orphanedEnroll enrollment(s) reference missing students");
    }
}

// Make-up classes
if (tableExists($pdo, 'makeup_classes')) {
    $makeupCount = countSchoolRows($pdo, 'makeup_classes');
    addResult($cat, 'Make-up classes', 'pass', "$makeupCount make-up class record(s)");
}

// Absence warnings
if (tableExists($pdo, 'absence_warnings')) {
    $warningCount = countSchoolRows($pdo, 'absence_warnings');
    addResult($cat, 'Absence warnings', 'pass', "$warningCount absence warning(s) sent");
}

// Attendance page shows curriculum (check for integration)
if (fileAccessible('attendance.php')) {
    try {
        $attContent = file_get_contents(__DIR__ . '/attendance.php');
        if (str_contains($attContent, 'class_curriculum')) {
            addResult($cat, 'Attendance curriculum display', 'pass', 'attendance.php integrates curriculum display');
        } else {
            addResult($cat, 'Attendance curriculum display', 'warn', 'attendance.php does not appear to show curriculum');
        }
        if (str_contains($attContent, 'Test Prep') || str_contains($attContent, 'test_prep')) {
            addResult($cat, 'Test Prep auto-fill', 'pass', 'attendance.php supports Test Prep auto-fill for post-cycle dates');
        } else {
            addResult($cat, 'Test Prep auto-fill', 'warn', 'attendance.php may not support Test Prep auto-fill');
        }
    } catch (\Throwable $e) {}
}

// Navigation: Curriculum link in sidebar
if (fileAccessible('includes/header.php')) {
    try {
        $headerContent = file_get_contents(__DIR__ . '/includes/header.php');
        if (str_contains($headerContent, 'curriculum.php')) {
            addResult($cat, 'Sidebar curriculum link', 'pass', 'Curriculum link present in sidebar navigation');
        } else {
            addResult($cat, 'Sidebar curriculum link', 'warn', 'No curriculum link found in sidebar — add to includes/header.php');
        }
    } catch (\Throwable $e) {}
}

// ---------------------------------------------------------------------------
// 19. TRAINING GUIDES
// ---------------------------------------------------------------------------
$cat = 'Training Guides';

// Admin training
if (fileAccessible('admin_training.php')) {
    addResult($cat, 'Admin training', 'pass', 'admin_training.php exists');

    try {
        $adminTrainContent = file_get_contents(__DIR__ . '/admin_training.php');
        // Check for key training sections
        $trainingSections = [
            'Initial Setup' => 'Initial Setup group',
            'Belt System' => 'Belt System group',
            'Membership' => 'Membership Plans group',
            'Class Setup' => 'Class Setup group',
            'Student Management' => 'Student Management group',
            'Attendance' => 'Attendance & Tracking group',
            'Payments' => 'Payments & Billing group',
            'Curriculum' => 'Curriculum Management group',
        ];
        foreach ($trainingSections as $section => $desc) {
            if (stripos($adminTrainContent, $section) !== false) {
                addResult($cat, "Admin guide: $section", 'pass', $desc . ' present');
            } else {
                addResult($cat, "Admin guide: $section", 'warn', $desc . ' MISSING from admin training');
            }
        }
    } catch (\Throwable $e) {
        addResult($cat, 'Admin training content', 'warn', 'Could not read admin_training.php');
    }
} else {
    addResult($cat, 'Admin training', 'fail', 'admin_training.php is MISSING');
}

// Student training wizard
if (fileAccessible('student_training_wizard.php')) {
    addResult($cat, 'Student training wizard', 'pass', 'student_training_wizard.php exists');

    try {
        $studentTrainContent = file_get_contents(__DIR__ . '/student_training_wizard.php');
        $studentSections = [
            'Dashboard' => 'Dashboard section',
            'Profile' => 'Profile section',
            'Membership' => 'Membership section',
            'Class' => 'Class Schedule section',
            'Events' => 'Events section',
            'Training Resources' => 'Training Resources section',
            'Payment' => 'Payments section',
            'Message' => 'Messages section',
        ];
        // Check for role banner (visual differentiation)
        if (stripos($studentTrainContent, 'wizard-role-student') !== false) {
            addResult($cat, 'Student guide: role banner', 'pass', 'Student guide has role-specific banner for visual differentiation');
        } else {
            addResult($cat, 'Student guide: role banner', 'warn', 'Student guide missing role-specific banner');
        }
        foreach ($studentSections as $section => $desc) {
            if (stripos($studentTrainContent, $section) !== false) {
                addResult($cat, "Student guide: $section", 'pass', $desc . ' present');
            } else {
                addResult($cat, "Student guide: $section", 'warn', $desc . ' MISSING from student training');
            }
        }
    } catch (\Throwable $e) {
        addResult($cat, 'Student training content', 'warn', 'Could not read student_training_wizard.php');
    }
} else {
    addResult($cat, 'Student training wizard', 'fail', 'student_training_wizard.php is MISSING');
}

// Parent training wizard
if (fileAccessible('parent_training_wizard.php')) {
    addResult($cat, 'Parent training wizard', 'pass', 'parent_training_wizard.php exists');

    try {
        $parentTrainContent = file_get_contents(__DIR__ . '/parent_training_wizard.php');
        $parentSections = [
            'Dashboard' => 'Family Dashboard section',
            'Children' => 'Managing Children section',
            'Events' => 'Events section',
            'Payment' => 'Payment section',
            'Transaction' => 'Transaction History section',
            'Tax Statement' => 'Tax Statements section',
            'Profile' => 'Profile section',
        ];
        // Check for role banner (visual differentiation)
        if (stripos($parentTrainContent, 'wizard-role-parent') !== false) {
            addResult($cat, 'Parent guide: role banner', 'pass', 'Parent guide has role-specific banner for visual differentiation');
        } else {
            addResult($cat, 'Parent guide: role banner', 'warn', 'Parent guide missing role-specific banner');
        }
        foreach ($parentSections as $section => $desc) {
            if (stripos($parentTrainContent, $section) !== false) {
                addResult($cat, "Parent guide: $section", 'pass', $desc . ' present');
            } else {
                addResult($cat, "Parent guide: $section", 'warn', $desc . ' MISSING from parent training');
            }
        }
    } catch (\Throwable $e) {
        addResult($cat, 'Parent training content', 'warn', 'Could not read parent_training_wizard.php');
    }
} else {
    addResult($cat, 'Parent training wizard', 'fail', 'parent_training_wizard.php is MISSING');
}

// Training wizard assets
if (fileAccessible('assets/js/training-wizard.js')) {
    addResult($cat, 'Training wizard JS', 'pass', 'assets/js/training-wizard.js exists');
} else {
    addResult($cat, 'Training wizard JS', 'fail', 'assets/js/training-wizard.js is MISSING — training wizards will not function');
}

if (fileAccessible('assets/css/training-wizard.css')) {
    addResult($cat, 'Training wizard CSS', 'pass', 'assets/css/training-wizard.css exists');
} else {
    addResult($cat, 'Training wizard CSS', 'fail', 'assets/css/training-wizard.css is MISSING — training wizards will not render properly');
}

// Check role-banner CSS exists in the stylesheet
try {
    $wizardCss = file_get_contents(__DIR__ . '/assets/css/training-wizard.css');
    if (strpos($wizardCss, 'wizard-role-banner') !== false && strpos($wizardCss, 'wizard-role-parent') !== false && strpos($wizardCss, 'wizard-role-student') !== false) {
        addResult($cat, 'Training wizard role CSS', 'pass', 'Role-specific banner styles present in training-wizard.css');
    } else {
        addResult($cat, 'Training wizard role CSS', 'warn', 'Role-specific banner styles missing from training-wizard.css');
    }
} catch (\Throwable $e) {
    addResult($cat, 'Training wizard role CSS', 'warn', 'Could not read training-wizard.css');
}

// Parent tax statement page
if (fileAccessible('parent_tax_statement.php')) {
    addResult($cat, 'Parent tax statement', 'pass', 'parent_tax_statement.php exists — Federal tax-compliant dependent care statement');
    try {
        $taxStatContent = file_get_contents(__DIR__ . '/parent_tax_statement.php');
        $taxChecks = [
            'Form 2441' => 'IRS Form 2441 reference',
            'W-10' => 'IRS Form W-10 reference',
            'tax_business_name' => 'Provider name from settings',
            'tax_id_ein' => 'Provider EIN from settings',
            'per-child' => 'Per-child breakdown',
        ];
        foreach ($taxChecks as $check => $desc) {
            if (stripos($taxStatContent, $check) !== false) {
                addResult($cat, "Tax statement: $desc", 'pass', "$desc present");
            } else {
                addResult($cat, "Tax statement: $desc", 'warn', "$desc MISSING from tax statement");
            }
        }
    } catch (\Throwable $e) {
        addResult($cat, 'Tax statement content', 'warn', 'Could not read parent_tax_statement.php');
    }
} else {
    addResult($cat, 'Parent tax statement', 'warn', 'parent_tax_statement.php is MISSING — parents cannot generate tax statements');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$totalPass = 0; $totalWarn = 0; $totalFail = 0;
foreach ($categoryTotals as $cat => $counts) {
    $totalPass += $counts['pass'];
    $totalWarn += $counts['warn'];
    $totalFail += $counts['fail'];
}
$totalTests = $totalPass + $totalWarn + $totalFail;

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-6 max-w-7xl">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Regression Test Suite</h1>
            <p class="text-gray-500 text-sm mt-1">Automated diagnostic checks across all application subsystems</p>
        </div>
        <div class="flex gap-2">
            <a href="seed_test_data.php" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition">
                &#127793; Seed Data
            </a>
            <a href="test_renewals.php" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                Renewal Tests
            </a>
            <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-sm font-medium transition">
                &larr; Dashboard
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border p-5 text-center">
            <div class="text-3xl font-bold text-gray-700"><?= $totalTests ?></div>
            <div class="text-sm text-gray-500 mt-1">Total Tests</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 text-center">
            <div class="text-3xl font-bold text-green-600"><?= $totalPass ?></div>
            <div class="text-sm text-gray-500 mt-1">Passed</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 text-center">
            <div class="text-3xl font-bold text-yellow-600"><?= $totalWarn ?></div>
            <div class="text-sm text-gray-500 mt-1">Warnings</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 text-center">
            <div class="text-3xl font-bold text-red-600"><?= $totalFail ?></div>
            <div class="text-sm text-gray-500 mt-1">Failed</div>
        </div>
    </div>

    <!-- Overall Health Bar -->
    <?php
    $healthPct = $totalTests > 0 ? round(($totalPass / $totalTests) * 100) : 0;
    $healthColor = $totalFail > 0 ? 'bg-red-500' : ($totalWarn > 3 ? 'bg-yellow-500' : 'bg-green-500');
    ?>
    <div class="bg-white rounded-xl shadow-sm border p-5 mb-6">
        <div class="flex justify-between items-center mb-2">
            <span class="text-sm font-medium text-gray-700">Overall Health</span>
            <span class="text-sm font-bold <?= $totalFail > 0 ? 'text-red-600' : ($totalWarn > 3 ? 'text-yellow-600' : 'text-green-600') ?>"><?= $healthPct ?>%</span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-3">
            <div class="<?= $healthColor ?> h-3 rounded-full transition-all" style="width: <?= $healthPct ?>%"></div>
        </div>
    </div>

    <!-- Filter Buttons -->
    <div class="flex gap-2 mb-4">
        <button onclick="filterResults('all')" class="filter-btn px-3 py-1.5 rounded-lg text-sm font-medium bg-gray-800 text-white">All (<?= $totalTests ?>)</button>
        <button onclick="filterResults('fail')" class="filter-btn px-3 py-1.5 rounded-lg text-sm font-medium bg-red-100 text-red-700 hover:bg-red-200">Failed (<?= $totalFail ?>)</button>
        <button onclick="filterResults('warn')" class="filter-btn px-3 py-1.5 rounded-lg text-sm font-medium bg-yellow-100 text-yellow-700 hover:bg-yellow-200">Warnings (<?= $totalWarn ?>)</button>
        <button onclick="filterResults('pass')" class="filter-btn px-3 py-1.5 rounded-lg text-sm font-medium bg-green-100 text-green-700 hover:bg-green-200">Passed (<?= $totalPass ?>)</button>
    </div>

    <!-- Results by Category -->
    <?php
    $currentCat = '';
    foreach ($testResults as $idx => $r):
        if ($r['category'] !== $currentCat):
            if ($currentCat !== '') echo '</tbody></table></div></div>';
            $currentCat = $r['category'];
            $catCounts = $categoryTotals[$currentCat];
            $catStatus = $catCounts['fail'] > 0 ? 'fail' : ($catCounts['warn'] > 0 ? 'warn' : 'pass');
            $catBorder = $catStatus === 'fail' ? 'border-red-200' : ($catStatus === 'warn' ? 'border-yellow-200' : 'border-green-200');
            $catBg = $catStatus === 'fail' ? 'bg-red-50' : ($catStatus === 'warn' ? 'bg-yellow-50' : 'bg-green-50');
    ?>
    <div class="bg-white rounded-xl shadow-sm border <?= $catBorder ?> mb-4 category-block">
        <div class="px-5 py-3 border-b <?= $catBg ?> flex justify-between items-center cursor-pointer" onclick="toggleCategory('cat-<?= $idx ?>')">
            <h3 class="font-semibold text-gray-800"><?= htmlspecialchars($currentCat) ?></h3>
            <div class="flex gap-2 text-xs">
                <?php if ($catCounts['pass'] > 0): ?>
                    <span class="px-2 py-0.5 rounded bg-green-100 text-green-700"><?= $catCounts['pass'] ?> pass</span>
                <?php endif; ?>
                <?php if ($catCounts['warn'] > 0): ?>
                    <span class="px-2 py-0.5 rounded bg-yellow-100 text-yellow-700"><?= $catCounts['warn'] ?> warn</span>
                <?php endif; ?>
                <?php if ($catCounts['fail'] > 0): ?>
                    <span class="px-2 py-0.5 rounded bg-red-100 text-red-700"><?= $catCounts['fail'] ?> fail</span>
                <?php endif; ?>
            </div>
        </div>
        <div id="cat-<?= $idx ?>">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
        <?php endif; ?>
                    <tr class="hover:bg-gray-50 test-row" data-status="<?= $r['status'] ?>">
                        <td class="px-5 py-2 w-8 text-center">
                            <?php if ($r['status'] === 'pass'): ?>
                                <span class="text-green-500 text-lg">&#10003;</span>
                            <?php elseif ($r['status'] === 'warn'): ?>
                                <span class="text-yellow-500 text-lg">&#9888;</span>
                            <?php else: ?>
                                <span class="text-red-500 text-lg">&#10007;</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 font-medium text-gray-700 whitespace-nowrap"><?= htmlspecialchars($r['name']) ?></td>
                        <td class="px-3 py-2 text-gray-500"><?= htmlspecialchars($r['detail']) ?></td>
                        <td class="px-3 py-2 text-right">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                <?= $r['status'] === 'pass' ? 'bg-green-100 text-green-800' :
                                   ($r['status'] === 'warn' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
                                <?= strtoupper($r['status']) ?>
                            </span>
                        </td>
                    </tr>
    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Run timestamp -->
    <div class="text-center text-sm text-gray-400 mt-8">
        Tests run at <?= date('F j, Y g:i:s A') ?> &mdash; School ID: <?= current_school_id() ?>
        &mdash; <a href="test_regression.php" class="text-blue-500 hover:underline">Re-run tests</a>
    </div>

</div>

<script>
function filterResults(status) {
    document.querySelectorAll('.test-row').forEach(row => {
        if (status === 'all' || row.dataset.status === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    // Update button styles
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.remove('bg-gray-800', 'text-white');
    });
    event.target.classList.add('bg-gray-800', 'text-white');
}

function toggleCategory(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? '' : 'none';
}
</script>

<?php include 'includes/footer.php'; ?>
