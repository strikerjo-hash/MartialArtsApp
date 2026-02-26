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

// Migrations have been moved to migrate.php

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
    $params = [$studentId, $cycleStart, $cycleEnd];
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM attendance
         WHERE student_id = ? AND status = 'absent'
         AND attendance_date BETWEEN ? AND ?" . school_where()
    );
    school_param($params);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Count make-up class records for a student within a date range.
 */
function count_makeups_in_cycle(int $studentId, string $cycleStart, string $cycleEnd): int
{
    $pdo = get_db();
    $params = [$studentId, $cycleStart, $cycleEnd];
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM makeup_classes
         WHERE student_id = ? AND makeup_date BETWEEN ? AND ?" . school_where()
    );
    school_param($params);
    $stmt->execute($params);
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
        $wParams = [$studentId, $cycle['start']];
        $stmt = $pdo->prepare(
            "SELECT email_sent FROM absence_warnings
             WHERE student_id = ? AND cycle_start = ?" . school_where()
        );
        school_param($wParams);
        $stmt->execute($wParams);
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
        $sParams = [];
        $sSql = "
            SELECT DISTINCT s.id, s.first_name, s.last_name, s.email
            FROM students s
            JOIN class_enrollments ce ON ce.student_id = s.id AND ce.status = 'active'
            WHERE s.status = 'active'" . school_where('s');
        school_param($sParams);
        $sStmt = $pdo->prepare($sSql);
        $sStmt->execute($sParams);
        $students = $sStmt->fetchAll();
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
        $exParams = [$s['id'], $cycle['start']];
        $existing = $pdo->prepare(
            "SELECT id FROM absence_warnings WHERE student_id = ? AND cycle_start = ?" . school_where()
        );
        school_param($exParams);
        $existing->execute($exParams);
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

        // Insert warning record (with school_id)
        try {
            $pdo->prepare(
                "INSERT INTO absence_warnings (school_id, student_id, cycle_start, cycle_end, absence_count, email_sent, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([current_school_id(), $s['id'], $cycle['start'], $cycle['end'], $netAbsences, $emailSent, $sentAt]);
        } catch (\PDOException $e) {}

        $results['warned']++;
    }

    return $results;
}
