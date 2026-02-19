<?php
/**
 * includes/belt_cycle.php — Belt Testing Cycle, Absence Tracking & Make-Up Classes
 *
 * Provides:
 *   - Configurable belt testing cycle with admin-set start/end dates
 *   - Absence counting within the current cycle
 *   - Make-up class tracking that offsets absences
 *   - Automated absence warning emails (called from cron.php)
 *
 * Settings used (via getSetting):
 *   belt_testing_cycle_start_date   — start date of the current testing cycle
 *   belt_testing_cycle_end_date     — end date of the current testing cycle
 *   absence_warning_threshold       — number of net absences that triggers email (default 3)
 *   payment_failure_email_enabled   — toggle for payment failure emails (default 1)
 */

require_once __DIR__ . '/messaging.php';

// ---------------------------------------------------------------------------
// Database Migrations
// ---------------------------------------------------------------------------

function ensure_belt_cycle_tables(): void
{
    $pdo = get_db();

    // 1. makeup_classes table — tracks make-up sessions that offset absences
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS makeup_classes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            original_absence_id INT DEFAULT NULL COMMENT 'attendance.id of the missed class',
            class_id INT DEFAULT NULL COMMENT 'class where makeup was done',
            makeup_date DATE NOT NULL,
            logged_by INT NOT NULL COMMENT 'users.id of admin who logged it',
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mc_student (student_id),
            INDEX idx_mc_absence (original_absence_id),
            INDEX idx_mc_date (makeup_date)
        )");
    } catch (\PDOException $e) {}

    // 2. absence_warnings table — tracks when warning emails were sent per cycle
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS absence_warnings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            cycle_start DATE NOT NULL,
            cycle_end DATE NOT NULL,
            absence_count INT NOT NULL,
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            sent_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_student_cycle (student_id, cycle_start),
            INDEX idx_aw_student (student_id)
        )");
    } catch (\PDOException $e) {}

    // 3. Default settings (idempotent INSERT IGNORE)
    $defaultEnd = (new \DateTime())->modify('+4 months')->format('Y-m-d');
    try {
        $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
            ('belt_testing_cycle_start_date', '" . date('Y-m-d') . "'),
            ('belt_testing_cycle_end_date', '" . $defaultEnd . "'),
            ('absence_warning_threshold', '3'),
            ('payment_failure_email_enabled', '1')
        ");
    } catch (\PDOException $e) {}

    // 4. Role permissions for makeup_classes.php
    try {
        $pdo->exec("INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete) VALUES
            ('admin', 'makeup_classes.php', 1, 1, 1, 1),
            ('instructor', 'makeup_classes.php', 1, 1, 0, 0),
            ('staff', 'makeup_classes.php', 1, 1, 0, 0)
        ");
    } catch (\PDOException $e) {}
}

// Run migrations on include
ensure_belt_cycle_tables();

// ---------------------------------------------------------------------------
// Core Functions
// ---------------------------------------------------------------------------

/**
 * Get the current belt testing cycle window.
 *
 * Reads the admin-configured start and end dates directly from settings.
 * Returns ['start' => 'Y-m-d', 'end' => 'Y-m-d'].
 */
function get_current_cycle(): array
{
    $startDateStr = getSetting('belt_testing_cycle_start_date', date('Y-m-d'));
    $defaultEnd = (new \DateTime())->modify('+4 months')->format('Y-m-d');
    $endDateStr = getSetting('belt_testing_cycle_end_date', $defaultEnd);

    return [
        'start' => $startDateStr,
        'end'   => $endDateStr,
    ];
}

/**
 * Count attendance records marked 'absent' for a student within a date range.
 */
function count_absences_in_cycle(int $studentId, string $cycleStart, string $cycleEnd): int
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM attendance
         WHERE student_id = ? AND status = 'absent'
         AND attendance_date BETWEEN ? AND ?"
    );
    $stmt->execute([$studentId, $cycleStart, $cycleEnd]);
    return (int) $stmt->fetchColumn();
}

/**
 * Count make-up class records for a student within a date range.
 */
function count_makeups_in_cycle(int $studentId, string $cycleStart, string $cycleEnd): int
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM makeup_classes
         WHERE student_id = ? AND makeup_date BETWEEN ? AND ?"
    );
    $stmt->execute([$studentId, $cycleStart, $cycleEnd]);
    return (int) $stmt->fetchColumn();
}

/**
 * Net absences = raw absences minus make-up classes (floored at 0).
 */
function get_net_absences(int $studentId, string $cycleStart, string $cycleEnd): int
{
    return max(0,
        count_absences_in_cycle($studentId, $cycleStart, $cycleEnd)
        - count_makeups_in_cycle($studentId, $cycleStart, $cycleEnd)
    );
}

/**
 * Full absence summary for a student — used by student portal and admin pages.
 */
function get_student_absence_summary(int $studentId): array
{
    $pdo = get_db();
    $cycle = get_current_cycle();

    $absences = count_absences_in_cycle($studentId, $cycle['start'], $cycle['end']);
    $makeups  = count_makeups_in_cycle($studentId, $cycle['start'], $cycle['end']);
    $net      = max(0, $absences - $makeups);
    $threshold = (int) getSetting('absence_warning_threshold', '3');

    // Check if a warning was already sent this cycle
    $warningSent = false;
    try {
        $stmt = $pdo->prepare(
            "SELECT email_sent FROM absence_warnings
             WHERE student_id = ? AND cycle_start = ?"
        );
        $stmt->execute([$studentId, $cycle['start']]);
        $row = $stmt->fetch();
        $warningSent = $row && (int) $row['email_sent'] === 1;
    } catch (\PDOException $e) {}

    return [
        'cycle_start'   => $cycle['start'],
        'cycle_end'     => $cycle['end'],
        'absences'      => $absences,
        'makeups'       => $makeups,
        'net_absences'  => $net,
        'threshold'     => $threshold,
        'warning_sent'  => $warningSent,
        'over_threshold' => $net >= $threshold,
    ];
}

/**
 * Check all active enrolled students and send absence warning emails
 * to those who have reached the threshold in the current cycle.
 *
 * Called by cron.php. Returns summary stats.
 */
function check_and_send_absence_warnings(): array
{
    $pdo = get_db();
    $cycle = get_current_cycle();
    $threshold = (int) getSetting('absence_warning_threshold', '3');

    $results = ['checked' => 0, 'warned' => 0, 'emails_sent' => 0, 'emails_failed' => 0];

    // Get all active students enrolled in at least one active class
    try {
        $students = $pdo->query("
            SELECT DISTINCT s.id, s.first_name, s.last_name, s.email
            FROM students s
            JOIN class_enrollments ce ON ce.student_id = s.id AND ce.status = 'active'
            WHERE s.status = 'active'
        ")->fetchAll();
    } catch (\PDOException $e) {
        return $results;
    }

    foreach ($students as $s) {
        $results['checked']++;
        $netAbsences = get_net_absences((int) $s['id'], $cycle['start'], $cycle['end']);

        if ($netAbsences < $threshold) {
            continue;
        }

        // Check if warning already sent for this student in this cycle
        $existing = $pdo->prepare(
            "SELECT id FROM absence_warnings WHERE student_id = ? AND cycle_start = ?"
        );
        $existing->execute([$s['id'], $cycle['start']]);
        if ($existing->fetch()) {
            continue; // Already warned this cycle
        }

        // Record the warning
        $emailSent = 0;
        $sentAt = null;

        // Send warning email
        if (is_email_configured() && !empty($s['email'])) {
            $name = trim($s['first_name'] . ' ' . $s['last_name']);
            $siteName = function_exists('getSiteName') ? getSiteName() : 'Our Studio';
            $body = "<h2>Attendance Warning</h2>"
                . "<p>Dear " . htmlspecialchars($name) . ",</p>"
                . "<p>You have accumulated <strong>{$netAbsences}</strong> unexcused absence(s) "
                . "during the current belt testing cycle "
                . "(" . date('M j, Y', strtotime($cycle['start'])) . " &ndash; " . date('M j, Y', strtotime($cycle['end'])) . ").</p>"
                . "<p>Our attendance policy requires no more than <strong>" . ($threshold - 1) . "</strong> absences per cycle. "
                . "If you miss any more classes you will need to make up classes to maintain your testing eligibility.</p>"
                . "<p>Please contact the studio to schedule make-up sessions as soon as possible.</p>"
                . "<p>Thank you,<br>" . htmlspecialchars($siteName) . "</p>";

            $emailResult = send_email($s['email'], 'Attendance Warning - Make-Up Classes Required', $body);
            if ($emailResult['success']) {
                $emailSent = 1;
                $sentAt = date('Y-m-d H:i:s');
                $results['emails_sent']++;
            } else {
                $results['emails_failed']++;
            }
        }

        // Insert warning record
        try {
            $pdo->prepare(
                "INSERT INTO absence_warnings (student_id, cycle_start, cycle_end, absence_count, email_sent, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$s['id'], $cycle['start'], $cycle['end'], $netAbsences, $emailSent, $sentAt]);
        } catch (\PDOException $e) {}

        $results['warned']++;
    }

    return $results;
}
