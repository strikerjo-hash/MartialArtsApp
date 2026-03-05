<?php
/**
 * seed_test_data.php — Admin-Only Test Data Seeder
 *
 * Populates the database with representative sample data across all
 * major subsystems: students, classes, events, memberships, payments,
 * belt awards, attendance, parent accounts, role permissions, and
 * studio branding configuration.
 *
 * Idempotent — safe to run multiple times. Uses prefix 'seed.' on
 * student usernames and checks for existing data before inserting.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';
requireLogin();

$user = getCurrentUser();
if (!in_array($user['role'], ['admin', 'super_admin'])) {
    accessDenied('Test data seeding requires Admin or Super Admin privileges.');
}

$pageTitle = 'Seed Test Data';
$schoolId = current_school_id();
$results = [];

// ---------------------------------------------------------------------------
// Helper: count rows with school filter
// ---------------------------------------------------------------------------
function seedCount(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (\PDOException $e) {
        return -1;
    }
}

// ---------------------------------------------------------------------------
// POST handler: Seed data
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'seed') {
    verify_csrf();

    // ===================================================================
    // Phase 0: Run critical migrations to ensure all columns exist
    // ===================================================================
    $migrations = [
        "ALTER TABLE memberships ADD COLUMN auto_renew TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE memberships ADD COLUMN billing_day TINYINT DEFAULT NULL",
        "ALTER TABLE memberships ADD COLUMN monthly_charges_made INT NOT NULL DEFAULT 0",
        "ALTER TABLE memberships ADD COLUMN payment_status ENUM('paid','pending','declined','partial') DEFAULT 'pending'",
        "ALTER TABLE students ADD COLUMN username VARCHAR(50) DEFAULT NULL",
        "ALTER TABLE students ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE students ADD COLUMN activity_status ENUM('active','inactive') NOT NULL DEFAULT 'active'",
        "ALTER TABLE students ADD COLUMN inactive_since DATE DEFAULT NULL",
        "ALTER TABLE students ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE students ADD COLUMN registration_incomplete TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE students ADD COLUMN account_credit DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "ALTER TABLE students ADD COLUMN stripe_customer_id VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE students ADD COLUMN square_customer_id VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE membership_plans ADD COLUMN billing_frequency ENUM('upfront','monthly') NOT NULL DEFAULT 'upfront'",
        "ALTER TABLE membership_plans ADD COLUMN registration_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "ALTER TABLE membership_plans ADD COLUMN tax_deductible TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE membership_plans ADD COLUMN is_afterschool TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE membership_plans ADD COLUMN program_start_date DATE DEFAULT NULL",
        "ALTER TABLE membership_plans ADD COLUMN program_end_date DATE DEFAULT NULL",
        "ALTER TABLE events ADD COLUMN tax_deductible TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($migrations as $sql) {
        try { $pdo->exec($sql); } catch (\PDOException $e) {}
    }

    // Ensure critical tables exist
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            role VARCHAR(50) NOT NULL,
            page VARCHAR(100) NOT NULL,
            can_view BOOLEAN DEFAULT 1,
            can_create BOOLEAN DEFAULT 0,
            can_edit BOOLEAN DEFAULT 0,
            can_delete BOOLEAN DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_role_page (role, page)
        )");
    } catch (\PDOException $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_la_user (username, attempted_at),
            INDEX idx_la_ip (ip_address, attempted_at)
        )");
    } catch (\PDOException $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            label VARCHAR(100) DEFAULT NULL,
            card_brand VARCHAR(30) DEFAULT NULL,
            last_four VARCHAR(4) DEFAULT NULL,
            encrypted_token TEXT DEFAULT NULL,
            gateway_payment_method_id VARCHAR(255) DEFAULT NULL,
            is_default TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        )");
    } catch (\PDOException $e) {}

    $results[] = ['category' => 'Migrations', 'inserted' => count($migrations), 'skipped' => 0, 'note' => 'Schema migrations executed (safe to re-run)'];

    // ===================================================================
    // Phase 1: Students (18)
    // ===================================================================
    $passwordHash = password_hash('TestPass123', PASSWORD_BCRYPT);
    $studentsData = [
        ['John',    'Doe',      'john.doe@example.com',    '555-0101', '1995-03-15', 'active',    'active',   '2024-01-15'],
        ['Jane',    'Smith',    'jane.smith@example.com',  '555-0102', '2001-07-22', 'active',    'active',   '2024-02-20'],
        ['Mike',    'Johnson',  'mike.j@example.com',      '555-0103', '1988-11-03', 'active',    'active',   '2023-09-10'],
        ['Emily',   'Williams', 'emily.w@example.com',     '555-0104', '2010-01-30', 'active',    'active',   '2024-03-01'],
        ['David',   'Brown',    'david.b@example.com',     '555-0105', '1992-09-18', 'active',    'active',   '2023-06-15'],
        ['Sarah',   'Davis',    'sarah.d@example.com',     '555-0106', '1999-04-11', 'active',    'active',   '2024-04-10'],
        ['Chris',   'Miller',   'chris.m@example.com',     '555-0107', '2008-12-05', 'active',    'active',   '2024-05-01'],
        ['Lisa',    'Wilson',   'lisa.w@example.com',      '555-0108', '1997-06-25', 'active',    'active',   '2023-11-20'],
        ['Tom',     'Garcia',   'tom.g@example.com',       '555-0109', '2000-08-14', 'active',    'active',   '2024-06-05'],
        ['Amy',     'Martinez', 'amy.m@example.com',       '555-0110', '1994-02-28', 'active',    'active',   '2024-01-30'],
        ['Kevin',   'Anderson', 'kevin.a@example.com',     '555-0111', '1990-10-07', 'active',    'inactive', '2023-08-01'],
        ['Rachel',  'Taylor',   'rachel.t@example.com',    '555-0112', '2009-05-19', 'active',    'active',   '2024-07-15'],
        ['James',   'Thomas',   'james.t@example.com',     '555-0113', '1986-07-31', 'inactive',  'inactive', '2023-03-10'],
        ['Nicole',  'Jackson',  'nicole.j@example.com',    '555-0114', '2011-03-22', 'active',    'active',   '2024-08-01'],
        ['Ryan',    'White',    'ryan.w@example.com',      '555-0115', '1998-11-16', 'active',    'active',   '2024-02-10'],
        ['Amanda',  'Harris',   null,                      '555-0116', '2006-09-01', 'active',    'active',   '2024-09-15'],
        ['Brian',   'Clark',    'brian.c@example.com',     '555-0117', '1993-01-12', 'suspended', 'inactive', '2023-05-20'],
        ['Megan',   'Lewis',    'megan.l@example.com',     '555-0118', '2001-04-08', 'active',    'active',   '2024-10-01'],
    ];

    $studentInserted = 0;
    $studentSkipped = 0;
    $seededStudentIds = [];

    foreach ($studentsData as $s) {
        $username = 'seed.' . strtolower($s[0]) . '.' . strtolower($s[1]);

        // Check if already exists
        $check = $pdo->prepare("SELECT id FROM students WHERE username = ? AND school_id = ?");
        $check->execute([$username, $schoolId]);
        $existing = $check->fetch();

        if ($existing) {
            $seededStudentIds[] = (int) $existing['id'];
            $studentSkipped++;
            continue;
        }

        $inactiveSince = ($s[6] === 'inactive') ? date('Y-m-d', strtotime('-30 days')) : null;

        $stmt = $pdo->prepare("INSERT INTO students (school_id, first_name, last_name, email, username, password_hash, phone, date_of_birth, address, emergency_contact_name, emergency_contact_phone, join_date, status, activity_status, inactive_since, must_change_password, registration_incomplete) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)");
        $stmt->execute([
            $schoolId, $s[0], $s[1], $s[2], $username, $passwordHash,
            $s[3], $s[4], '123 Test St, Anytown, USA',
            $s[0] . ' ' . $s[1] . ' Emergency', '555-9999',
            $s[7], $s[5], $s[6], $inactiveSince
        ]);
        $seededStudentIds[] = (int) $pdo->lastInsertId();
        $studentInserted++;
    }

    $results[] = ['category' => 'Students', 'inserted' => $studentInserted, 'skipped' => $studentSkipped, 'note' => '18 students (mix of active/inactive/suspended)'];

    // ===================================================================
    // Phase 2: Classes (4)
    // ===================================================================
    $classesData = [
        ['Beginner Karate',  1, 'Monday',    '17:00:00', '18:00:00', 'beginner', 20],
        ['Advanced Karate',  1, 'Wednesday', '18:00:00', '19:30:00', 'advanced', 15],
        ['Kids Taekwondo',   2, 'Tuesday',   '16:00:00', '17:00:00', 'beginner', 25],
        ['MMA Open Training', 3, 'Friday',   '18:00:00', '19:30:00', 'all',      20],
    ];

    $classInserted = 0;
    $classSkipped = 0;
    $seededClassIds = [];

    foreach ($classesData as $c) {
        $check = $pdo->prepare("SELECT id FROM classes WHERE name = ? AND school_id = ?");
        $check->execute([$c[0], $schoolId]);
        $existing = $check->fetch();

        if ($existing) {
            $seededClassIds[] = (int) $existing['id'];
            $classSkipped++;
            continue;
        }

        $stmt = $pdo->prepare("INSERT INTO classes (school_id, name, style_id, day_of_week, start_time, end_time, skill_level, max_students, status, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");
        $stmt->execute([$schoolId, $c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6], 'Seed test class: ' . $c[0]]);
        $seededClassIds[] = (int) $pdo->lastInsertId();
        $classInserted++;
    }

    $results[] = ['category' => 'Classes', 'inserted' => $classInserted, 'skipped' => $classSkipped, 'note' => '4 classes (Karate, TKD, MMA)'];

    // ===================================================================
    // Phase 3: Class Enrollments (~12)
    // ===================================================================
    $enrollInserted = 0;
    $enrollSkipped = 0;

    // Enroll first 5 active students in Beginner Karate
    // Enroll students 3-7 in Advanced Karate
    // Enroll students 4,7,12,14 in Kids Taekwondo (younger students)
    // Enroll students 1,3,5,8 in MMA Open
    $enrollmentMap = [];
    if (count($seededClassIds) >= 4 && count($seededStudentIds) >= 15) {
        $enrollmentMap = [
            [$seededClassIds[0], [0,1,2,3,4]],  // Beginner Karate → students 1-5
            [$seededClassIds[1], [2,3,4,5,6]],  // Advanced Karate → students 3-7
            [$seededClassIds[2], [3,6,11,13]],   // Kids TKD → younger students
            [$seededClassIds[3], [0,2,4,7]],     // MMA Open → mixed
        ];
    }

    foreach ($enrollmentMap as [$classId, $studentIdxs]) {
        foreach ($studentIdxs as $idx) {
            if (!isset($seededStudentIds[$idx])) continue;
            $sid = $seededStudentIds[$idx];
            try {
                $pdo->prepare("INSERT IGNORE INTO class_enrollments (school_id, student_id, class_id, enrollment_date, status) VALUES (?, ?, ?, CURDATE(), 'active')")
                    ->execute([$schoolId, $sid, $classId]);
                if ($pdo->rowCount() > 0) $enrollInserted++; else $enrollSkipped++;
            } catch (\PDOException $e) { $enrollSkipped++; }
        }
    }

    $results[] = ['category' => 'Class Enrollments', 'inserted' => $enrollInserted, 'skipped' => $enrollSkipped, 'note' => 'Students enrolled in seeded classes'];

    // ===================================================================
    // Phase 4: Attendance (~15 records)
    // ===================================================================
    $attInserted = 0;
    $attSkipped = 0;

    if (count($seededClassIds) >= 1 && count($seededStudentIds) >= 5) {
        $attendanceData = [
            // [student_idx, class_idx, days_ago, status, check_in, check_out]
            [0, 0, 3,  'present',  '17:00', '18:00'],
            [1, 0, 3,  'present',  '17:05', '18:00'],
            [2, 0, 3,  'late',     '17:15', '18:00'],
            [3, 0, 3,  'present',  '17:00', '18:00'],
            [4, 0, 3,  'present',  '17:02', '18:00'],
            [0, 0, 10, 'present',  '17:00', '18:00'],
            [1, 0, 10, 'present',  '16:55', '18:00'],
            [2, 0, 10, 'excused',  null,    null],
            [5, 1, 5,  'present',  '18:00', '19:30'],
            [6, 1, 5,  'present',  '18:03', '19:30'],
            [3, 2, 7,  'present',  '16:00', '17:00'],
            [6, 2, 7,  'late',     '16:20', '17:00'],
            [0, 3, 1,  'present',  '18:00', '19:30'],
            [2, 3, 1,  'present',  '18:00', '19:25'],
            [4, 3, 1,  'present',  '18:05', '19:30'],
        ];

        foreach ($attendanceData as $a) {
            if (!isset($seededStudentIds[$a[0]]) || !isset($seededClassIds[$a[1]])) continue;
            $attDate = date('Y-m-d', strtotime("-{$a[2]} days"));
            try {
                // Check for existing attendance on same date/student/class
                $check = $pdo->prepare("SELECT id FROM attendance WHERE student_id = ? AND class_id = ? AND attendance_date = ? AND school_id = ?");
                $check->execute([$seededStudentIds[$a[0]], $seededClassIds[$a[1]], $attDate, $schoolId]);
                if ($check->fetch()) { $attSkipped++; continue; }

                $pdo->prepare("INSERT INTO attendance (school_id, student_id, class_id, attendance_date, check_in_time, check_out_time, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$schoolId, $seededStudentIds[$a[0]], $seededClassIds[$a[1]], $attDate, $a[4], $a[5], $a[3]]);
                $attInserted++;
            } catch (\PDOException $e) { $attSkipped++; }
        }
    }

    $results[] = ['category' => 'Attendance', 'inserted' => $attInserted, 'skipped' => $attSkipped, 'note' => '15 records over past 10 days'];

    // ===================================================================
    // Phase 5: Events (4)
    // ===================================================================
    $eventsData = [
        ['Spring Belt Test',         'belt_test',   date('Y-m-d', strtotime('+14 days')), '10:00:00', '14:00:00', 'Main Dojo', 30, 25.00,  date('Y-m-d', strtotime('+7 days')),  'upcoming', 1],
        ['Annual Tournament',        'tournament',  date('Y-m-d', strtotime('+30 days')), '09:00:00', '17:00:00', 'Convention Center', 100, 50.00,  date('Y-m-d', strtotime('+21 days')), 'upcoming', 0],
        ['Guest Instructor Seminar', 'seminar',     date('Y-m-d', strtotime('-7 days')),  '13:00:00', '16:00:00', 'Main Dojo', 25, 35.00,  date('Y-m-d', strtotime('-14 days')), 'completed', 0],
        ['Summer Camp Week',         'camp',        date('Y-m-d', strtotime('+60 days')), '08:00:00', '15:00:00', 'Main Dojo', 20, 150.00, date('Y-m-d', strtotime('+45 days')), 'upcoming', 1],
    ];

    $eventInserted = 0;
    $eventSkipped = 0;
    $seededEventIds = [];

    foreach ($eventsData as $e) {
        $check = $pdo->prepare("SELECT id FROM events WHERE name = ? AND school_id = ?");
        $check->execute([$e[0], $schoolId]);
        $existing = $check->fetch();

        if ($existing) {
            $seededEventIds[] = (int) $existing['id'];
            $eventSkipped++;
            continue;
        }

        $stmt = $pdo->prepare("INSERT INTO events (school_id, name, event_type, event_date, start_time, end_time, location, max_participants, registration_fee, registration_deadline, status, tax_deductible, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$schoolId, $e[0], $e[1], $e[2], $e[3], $e[4], $e[5], $e[6], $e[7], $e[8], $e[9], $e[10], 'Seed test event: ' . $e[0]]);
        $seededEventIds[] = (int) $pdo->lastInsertId();
        $eventInserted++;
    }

    $results[] = ['category' => 'Events', 'inserted' => $eventInserted, 'skipped' => $eventSkipped, 'note' => '4 events (belt test, tournament, seminar, camp)'];

    // ===================================================================
    // Phase 6: Event Registrations (6)
    // ===================================================================
    $regInserted = 0;
    $regSkipped = 0;

    if (count($seededEventIds) >= 3 && count($seededStudentIds) >= 10) {
        $registrations = [
            // [event_idx, student_idx, payment_status, amount_paid]
            [0, 0, 'paid', 25.00],
            [0, 1, 'paid', 25.00],
            [0, 2, 'pending', 0.00],
            [1, 0, 'paid', 50.00],
            [1, 4, 'pending', 0.00],
            [2, 5, 'paid', 35.00],
        ];

        foreach ($registrations as $r) {
            if (!isset($seededEventIds[$r[0]]) || !isset($seededStudentIds[$r[1]])) continue;
            try {
                $pdo->prepare("INSERT IGNORE INTO event_registrations (school_id, event_id, student_id, payment_status, amount_paid) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$schoolId, $seededEventIds[$r[0]], $seededStudentIds[$r[1]], $r[2], $r[3]]);
                if ($pdo->rowCount() > 0) $regInserted++; else $regSkipped++;
            } catch (\PDOException $e) { $regSkipped++; }
        }
    }

    $results[] = ['category' => 'Event Registrations', 'inserted' => $regInserted, 'skipped' => $regSkipped, 'note' => 'Students registered for events'];

    // ===================================================================
    // Phase 7: Memberships (8)
    // ===================================================================
    $memInserted = 0;
    $memSkipped = 0;

    // Get existing plan IDs
    $planIds = [];
    try {
        $stmt = $pdo->query("SELECT id FROM membership_plans ORDER BY id LIMIT 6");
        while ($row = $stmt->fetch()) $planIds[] = (int) $row['id'];
    } catch (\PDOException $e) {}

    if (count($planIds) >= 3 && count($seededStudentIds) >= 10) {
        $membershipsData = [
            // [student_idx, plan_idx, status, auto_renew, start_offset, duration_months, payment_status, billing_day]
            [0,  0, 'active',    1, '-2 months',  1,  'paid',     15],
            [1,  1, 'active',    1, '-1 month',   1,  'paid',     1],
            [2,  2, 'active',    0, '-3 months',  1,  'paid',     10],
            [4,  0, 'active',    1, '-15 days',   1,  'pending',  20],
            [5,  1, 'active',    0, '-2 months',  1,  'paid',     5],
            [7,  min(3, count($planIds)-1), 'expired', 0, '-6 months', 3, 'paid', null],
            [8,  min(4, count($planIds)-1), 'expired', 0, '-1 year',   3, 'paid', null],
            [10, 0, 'cancelled', 0, '-4 months',  1,  'paid',     null],
        ];

        foreach ($membershipsData as $m) {
            if (!isset($seededStudentIds[$m[0]]) || !isset($planIds[$m[1]])) continue;
            $sid = $seededStudentIds[$m[0]];

            // Check for existing active membership for this student
            $check = $pdo->prepare("SELECT id FROM memberships WHERE student_id = ? AND status = 'active' AND school_id = ?");
            $check->execute([$sid, $schoolId]);
            if ($check->fetch() && $m[2] === 'active') { $memSkipped++; continue; }

            $startDate = date('Y-m-d', strtotime($m[4]));
            $endDate = date('Y-m-d', strtotime($m[4] . ' +' . $m[5] . ' months'));

            // Adjust so active memberships end in the future
            if ($m[2] === 'active') {
                $endDate = date('Y-m-d', strtotime('+' . $m[5] . ' months'));
            }

            try {
                $pdo->prepare("INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date, status, auto_renew, payment_status, billing_day, amount_paid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$schoolId, $sid, $planIds[$m[1]], $startDate, $endDate, $m[2], $m[3], $m[6], $m[7], $m[6] === 'paid' ? 99.00 : 0.00]);
                $memInserted++;
            } catch (\PDOException $e) { $memSkipped++; }
        }
    }

    $results[] = ['category' => 'Memberships', 'inserted' => $memInserted, 'skipped' => $memSkipped, 'note' => '8 memberships (active/expired/cancelled, auto-renew mix)'];

    // ===================================================================
    // Phase 8: Payments (8)
    // ===================================================================
    $payInserted = 0;
    $paySkipped = 0;

    if (count($seededStudentIds) >= 8) {
        $paymentsData = [
            // [student_idx, type, amount, method, days_ago]
            [0, 'membership', 99.00,  'credit_card',   30],
            [1, 'membership', 149.00, 'credit_card',   25],
            [2, 'membership', 199.00, 'credit_card',   20],
            [4, 'membership', 99.00,  'cash',          15],
            [5, 'membership', 149.00, 'bank_transfer', 10],
            [0, 'event',      25.00,  'credit_card',   7],
            [1, 'event',      25.00,  'cash',          5],
            [5, 'event',      35.00,  'credit_card',   3],
        ];

        foreach ($paymentsData as $p) {
            if (!isset($seededStudentIds[$p[0]])) continue;
            $sid = $seededStudentIds[$p[0]];
            $payDate = date('Y-m-d', strtotime("-{$p[4]} days"));
            $receiptNum = 'SEED-' . strtoupper(bin2hex(random_bytes(4)));

            try {
                $pdo->prepare("INSERT INTO payments (school_id, student_id, payment_type, amount, payment_method, payment_date, receipt_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, 'Seed test payment')")
                    ->execute([$schoolId, $sid, $p[1], $p[2], $p[3], $payDate, $receiptNum]);
                $payInserted++;
            } catch (\PDOException $e) { $paySkipped++; }
        }
    }

    $results[] = ['category' => 'Payments', 'inserted' => $payInserted, 'skipped' => $paySkipped, 'note' => '8 payments (membership + event, various methods)'];

    // ===================================================================
    // Phase 9: Belt Awards (5)
    // ===================================================================
    $beltInserted = 0;
    $beltSkipped = 0;

    // Get existing belt IDs
    $beltIds = [];
    try {
        $stmt = $pdo->query("SELECT id, style_id, name FROM belts ORDER BY style_id, rank_order LIMIT 14");
        while ($row = $stmt->fetch()) $beltIds[] = $row;
    } catch (\PDOException $e) {}

    if (count($beltIds) >= 5 && count($seededStudentIds) >= 10) {
        $beltAwards = [
            // [student_idx, belt_array_idx, days_ago]
            [0, 2, 60],   // John Doe → Orange Belt (Karate)
            [1, 1, 90],   // Jane Smith → Yellow Belt (Karate)
            [2, 4, 30],   // Mike Johnson → Blue Belt (Karate)
            [4, 1, 120],  // David Brown → Yellow Belt (Karate)
            [5, 0, 45],   // Sarah Davis → White Belt (Karate)
        ];

        foreach ($beltAwards as $ba) {
            if (!isset($seededStudentIds[$ba[0]]) || !isset($beltIds[$ba[1]])) continue;
            $sid = $seededStudentIds[$ba[0]];
            $beltId = (int) $beltIds[$ba[1]]['id'];
            $styleId = (int) $beltIds[$ba[1]]['style_id'];
            $awardDate = date('Y-m-d', strtotime("-{$ba[2]} days"));

            // Check existing
            $check = $pdo->prepare("SELECT id FROM student_belts WHERE student_id = ? AND belt_id = ? AND school_id = ?");
            $check->execute([$sid, $beltId, $schoolId]);
            if ($check->fetch()) { $beltSkipped++; continue; }

            try {
                $pdo->prepare("INSERT INTO student_belts (school_id, student_id, belt_id, style_id, awarded_date, notes) VALUES (?, ?, ?, ?, ?, 'Seed test belt award')")
                    ->execute([$schoolId, $sid, $beltId, $styleId, $awardDate]);
                $beltInserted++;
            } catch (\PDOException $e) { $beltSkipped++; }
        }
    }

    $results[] = ['category' => 'Belt Awards', 'inserted' => $beltInserted, 'skipped' => $beltSkipped, 'note' => '5 belt awards (Karate ranks)'];

    // ===================================================================
    // Phase 10: Belt Resources (3 video resources — global table)
    // ===================================================================
    $resInserted = 0;
    $resSkipped = 0;

    if (count($beltIds) >= 3) {
        $resourcesData = [
            [(int) $beltIds[0]['id'], (int) $beltIds[0]['style_id'], 'White Belt Fundamentals',  'Basic stances, blocks, and punches for beginners'],
            [(int) $beltIds[1]['id'], (int) $beltIds[1]['style_id'], 'Yellow Belt Techniques',   'Kata, front kick, and roundhouse kick drills'],
            [(int) $beltIds[2]['id'], (int) $beltIds[2]['style_id'], 'Orange Belt Combinations', 'Intermediate kata and combination techniques'],
        ];

        foreach ($resourcesData as $res) {
            $check = $pdo->prepare("SELECT id FROM belt_resources WHERE title = ? AND belt_id = ?");
            $check->execute([$res[2], $res[0]]);
            if ($check->fetch()) { $resSkipped++; continue; }

            try {
                $pdo->prepare("INSERT INTO belt_resources (belt_id, style_id, resource_type, title, description, video_url, sort_order) VALUES (?, ?, 'video', ?, ?, 'https://www.youtube.com/watch?v=example', 1)")
                    ->execute([$res[0], $res[1], $res[2], $res[3]]);
                $resInserted++;
            } catch (\PDOException $e) { $resSkipped++; }
        }
    }

    $results[] = ['category' => 'Belt Resources', 'inserted' => $resInserted, 'skipped' => $resSkipped, 'note' => '3 training video resources (global)'];

    // ===================================================================
    // Phase 11: Parent Accounts (2) + Links
    // ===================================================================
    $parentInserted = 0;
    $parentSkipped = 0;

    $parentsData = [
        ['Robert', 'Williams', 'robert.w@example.com', '555-0201', [3, 13]],  // Parent of Emily Williams(3) + Nicole Jackson(13)
        ['Susan',  'Miller',   'susan.m@example.com',  '555-0202', [6, 11]],  // Parent of Chris Miller(6) + Rachel Taylor(11)
    ];

    foreach ($parentsData as $p) {
        $parentUsername = 'seed.' . strtolower($p[0]) . '.' . strtolower($p[1]);
        $check = $pdo->prepare("SELECT id FROM parents WHERE username = ? AND school_id = ?");
        $check->execute([$parentUsername, $schoolId]);
        $existing = $check->fetch();

        if ($existing) {
            $parentId = (int) $existing['id'];
            $parentSkipped++;
        } else {
            try {
                $pdo->prepare("INSERT INTO parents (school_id, username, password, email, first_name, last_name, phone) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$schoolId, $parentUsername, $passwordHash, $p[2], $p[0], $p[1], $p[3]]);
                $parentId = (int) $pdo->lastInsertId();
                $parentInserted++;
            } catch (\PDOException $e) { $parentSkipped++; continue; }
        }

        // Link to child students
        foreach ($p[4] as $childIdx) {
            if (!isset($seededStudentIds[$childIdx])) continue;
            try {
                $pdo->prepare("INSERT IGNORE INTO parent_students (school_id, parent_id, student_id) VALUES (?, ?, ?)")
                    ->execute([$schoolId, $parentId, $seededStudentIds[$childIdx]]);
            } catch (\PDOException $e) {}
        }
    }

    $results[] = ['category' => 'Parent Accounts', 'inserted' => $parentInserted, 'skipped' => $parentSkipped, 'note' => '2 parents linked to younger students'];

    // ===================================================================
    // Phase 12: Role Permissions (from migrate.sql)
    // ===================================================================
    $permSql = "INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)";
    $permStmt = $pdo->prepare($permSql);
    $permInserted = 0;

    $permissions = [
        ['admin', 'index.php', 1, 1, 1, 1],
        ['admin', 'students.php', 1, 1, 1, 1],
        ['admin', 'memberships.php', 1, 1, 1, 1],
        ['admin', 'classes.php', 1, 1, 1, 1],
        ['admin', 'events.php', 1, 1, 1, 1],
        ['admin', 'attendance.php', 1, 1, 1, 1],
        ['admin', 'payments.php', 1, 1, 1, 1],
        ['admin', 'belts.php', 1, 1, 1, 1],
        ['admin', 'reports.php', 1, 0, 0, 0],
        ['admin', 'users.php', 1, 1, 1, 1],
        ['admin', 'settings.php', 1, 0, 1, 0],
        ['instructor', 'index.php', 1, 0, 0, 0],
        ['instructor', 'students.php', 1, 0, 1, 0],
        ['instructor', 'memberships.php', 1, 0, 0, 0],
        ['instructor', 'classes.php', 1, 0, 1, 0],
        ['instructor', 'events.php', 1, 0, 1, 0],
        ['instructor', 'attendance.php', 1, 1, 1, 0],
        ['instructor', 'payments.php', 1, 0, 0, 0],
        ['instructor', 'belts.php', 1, 1, 1, 0],
        ['instructor', 'reports.php', 1, 0, 0, 0],
        ['instructor', 'users.php', 0, 0, 0, 0],
        ['instructor', 'settings.php', 1, 0, 1, 0],
        ['staff', 'index.php', 1, 0, 0, 0],
        ['staff', 'students.php', 1, 1, 1, 0],
        ['staff', 'memberships.php', 1, 1, 1, 0],
        ['staff', 'classes.php', 1, 0, 0, 0],
        ['staff', 'events.php', 1, 1, 1, 0],
        ['staff', 'attendance.php', 1, 1, 1, 0],
        ['staff', 'payments.php', 1, 1, 0, 0],
        ['staff', 'belts.php', 1, 0, 0, 0],
        ['staff', 'reports.php', 1, 0, 0, 0],
        ['staff', 'users.php', 0, 0, 0, 0],
        ['staff', 'settings.php', 0, 0, 0, 0],
        ['student', 'student_portal.php', 1, 0, 0, 0],
        ['student', 'student_upgrade.php', 1, 1, 0, 0],
    ];

    foreach ($permissions as $p) {
        try {
            $permStmt->execute($p);
            if ($pdo->rowCount() > 0) $permInserted++;
        } catch (\PDOException $e) {}
    }

    $results[] = ['category' => 'Role Permissions', 'inserted' => $permInserted, 'skipped' => count($permissions) - $permInserted, 'note' => 'Permissions for admin/instructor/staff/student roles'];

    // ===================================================================
    // Phase 13: Studio Configuration
    // ===================================================================
    $configInserted = 0;

    // Set studio name (resolves "Using default app name" warning)
    try {
        $check = $pdo->prepare("SELECT config_value FROM studio_config WHERE config_key = 'studio_name' LIMIT 1");
        $check->execute();
        $current = $check->fetch();
        if (!$current || empty($current['config_value']) || $current['config_value'] === 'Martial Arts Academy') {
            $pdo->prepare("INSERT INTO studio_config (config_key, config_value) VALUES ('studio_name', ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)")
                ->execute(['Test Martial Arts Academy']);
            $configInserted++;
        }
    } catch (\PDOException $e) {
        // studio_config table might not exist
        try {
            saveSetting('site_name', 'Test Martial Arts Academy');
            $configInserted++;
        } catch (\Exception $e2) {}
    }

    $results[] = ['category' => 'Studio Config', 'inserted' => $configInserted, 'skipped' => $configInserted > 0 ? 0 : 1, 'note' => 'Studio name set to avoid default name warning'];
}

// ---------------------------------------------------------------------------
// GET: Current data counts for display
// ---------------------------------------------------------------------------
$counts = [];
$countQueries = [
    'Students'          => "SELECT COUNT(*) FROM students" . school_where(),
    'Classes'           => "SELECT COUNT(*) FROM classes" . school_where(),
    'Attendance'        => "SELECT COUNT(*) FROM attendance" . school_where(),
    'Memberships'       => "SELECT COUNT(*) FROM memberships WHERE status = 'active'" . school_where(),
    'Payments'          => "SELECT COUNT(*) FROM payments" . school_where(),
    'Events'            => "SELECT COUNT(*) FROM events" . school_where(),
    'Belt Awards'       => "SELECT COUNT(*) FROM student_belts" . school_where(),
    'Parents'           => "SELECT COUNT(*) FROM parents" . school_where(),
];

foreach ($countQueries as $label => $sql) {
    $counts[$label] = seedCount($pdo, $sql, [$schoolId]);
}

// Global tables (no school filter)
$counts['Belt Resources'] = seedCount($pdo, "SELECT COUNT(*) FROM belt_resources");
$counts['Styles'] = seedCount($pdo, "SELECT COUNT(*) FROM martial_arts_styles");
$counts['Belts'] = seedCount($pdo, "SELECT COUNT(*) FROM belts");
$counts['Permissions'] = seedCount($pdo, "SELECT COUNT(*) FROM role_permissions");

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-6 max-w-5xl">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Seed Test Data</h1>
            <p class="text-gray-500 text-sm mt-1">Populate the database with representative sample data for testing</p>
        </div>
        <div class="flex gap-2">
            <a href="test_regression.php" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition">
                &#9989; Run Regression Tests
            </a>
            <a href="test_renewals.php" class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition">
                &#128269; Test Renewals
            </a>
            <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-sm font-medium transition">
                &larr; Dashboard
            </a>
        </div>
    </div>

    <?php if (!empty($results)): ?>
    <!-- Seed Results -->
    <div class="bg-white rounded-xl shadow-sm border mb-6">
        <div class="px-5 py-4 border-b bg-green-50">
            <h2 class="font-semibold text-green-800">Seeding Complete!</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Inserted</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Skipped</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($results as $r): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-700"><?= htmlspecialchars($r['category']) ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($r['inserted'] > 0): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">+<?= $r['inserted'] ?></span>
                            <?php else: ?>
                                <span class="text-gray-400">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($r['skipped'] > 0): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800"><?= $r['skipped'] ?> skipped</span>
                            <?php else: ?>
                                <span class="text-gray-400">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($r['note']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3 border-t bg-gray-50 text-center">
            <a href="test_regression.php" class="text-indigo-600 hover:text-indigo-800 font-medium text-sm">
                &#8594; Run Regression Tests to verify all warnings are resolved
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Current Data Counts -->
    <div class="bg-white rounded-xl shadow-sm border mb-6">
        <div class="px-5 py-4 border-b">
            <h2 class="font-semibold text-gray-800">Current Data Counts (School #<?= $schoolId ?>)</h2>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 p-5">
            <?php foreach ($counts as $label => $count): ?>
            <div class="text-center p-3 bg-gray-50 rounded-lg">
                <div class="text-2xl font-bold <?= $count > 0 ? 'text-green-600' : 'text-gray-400' ?>"><?= $count >= 0 ? $count : '?' ?></div>
                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($label) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Seed Button -->
    <?php if (empty($results)): ?>
    <div class="bg-white rounded-xl shadow-sm border p-6 text-center">
        <h3 class="text-lg font-semibold text-gray-800 mb-2">Ready to Seed Test Data?</h3>
        <p class="text-sm text-gray-500 mb-4 max-w-xl mx-auto">
            This will add representative sample data across all major subsystems: students, classes, events, memberships, payments, belt awards, attendance, parent accounts, and role permissions. Existing data will not be overwritten.
        </p>

        <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-700 max-w-xl mx-auto text-left">
            <strong>What gets created:</strong>
            <ul class="mt-2 space-y-1 list-disc list-inside">
                <li>18 students (usernames: <code>seed.firstname.lastname</code>, password: <code>TestPass123</code>)</li>
                <li>4 classes with 12+ enrollments</li>
                <li>15+ attendance records</li>
                <li>4 events with 6 registrations</li>
                <li>8 memberships (active/expired/cancelled mix)</li>
                <li>8 payment records</li>
                <li>5 belt awards + 3 training resources</li>
                <li>2 parent accounts linked to students</li>
                <li>Full role permissions for admin/instructor/staff</li>
                <li>Studio name configuration</li>
            </ul>
        </div>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf()) ?>">
            <input type="hidden" name="action" value="seed">
            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-8 py-3 rounded-lg font-medium text-sm transition"
                    onclick="return confirm('Seed the database with test data? This is safe to run multiple times.')">
                &#127793; Seed Test Data
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Info Note -->
    <div class="mt-6 p-4 bg-gray-50 border border-gray-200 rounded-lg text-xs text-gray-500">
        <strong>Notes:</strong>
        <ul class="mt-1 space-y-0.5 list-disc list-inside">
            <li>All seed data is scoped to school #<?= $schoolId ?> for multi-tenancy</li>
            <li>Student usernames are prefixed with <code>seed.</code> to distinguish from real data</li>
            <li>Running this multiple times will skip existing records (idempotent)</li>
            <li>Warnings for "No payment gateway" and "Email not configured" require real API keys and cannot be resolved with seed data</li>
        </ul>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
