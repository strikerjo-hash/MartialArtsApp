<?php
/**
 * includes/transfer.php — Student/family transfer between schools.
 *
 * Provides functions for super admins to transfer a student (or an entire
 * parent-family) from one school to another.  All operations are wrapped
 * in a database transaction so they either fully succeed or fully roll back.
 *
 * Depends on: tenant.php (current_school_id, get_all_schools, etc.),
 *             parent_auth.php (student_is_parent, get_parent_children),
 *             db.php (get_db).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/parent_auth.php';

// ─────────────────────────────────────────────────────────────────────────────
// Preview — read-only summary of what a transfer would affect
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Gather a read-only preview of what would be affected by transferring a
 * student to another school.  Powers the confirmation modal.
 *
 * @return array{
 *   student_name: string,
 *   current_school: string,
 *   is_parent: bool,
 *   children: array,
 *   active_enrollments: int,
 *   future_events: int,
 *   active_membership: ?array,
 *   pending_plan_changes: int,
 *   pending_makeups: int,
 *   has_parent_at_school: bool,
 *   parent_names: array
 * }
 */
function get_transfer_preview(int $studentId): array
{
    $pdo = get_db();

    // Student record (no school_where — super admin context)
    $stmt = $pdo->prepare("SELECT s.*, sc.name AS school_name FROM students s LEFT JOIN schools sc ON sc.id = s.school_id WHERE s.id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if (!$student) {
        return ['error' => 'Student not found'];
    }

    $schoolId = (int) $student['school_id'];

    // Is this student a parent?
    $isParent = (int) ($student['is_parent'] ?? 0) === 1;

    // Children (if parent)
    $children = [];
    if ($isParent) {
        $ch = $pdo->prepare("
            SELECT s.id, s.first_name, s.last_name
            FROM parent_students ps
            JOIN students s ON s.id = ps.student_id
            WHERE ps.parent_id = ? AND ps.school_id = ?
            ORDER BY s.first_name, s.last_name
        ");
        $ch->execute([$studentId, $schoolId]);
        $children = $ch->fetchAll();
    }

    // Active class enrollments
    $ce = $pdo->prepare("SELECT COUNT(*) FROM class_enrollments WHERE student_id = ? AND school_id = ? AND status = 'active'");
    $ce->execute([$studentId, $schoolId]);
    $activeEnrollments = (int) $ce->fetchColumn();

    // Future event registrations
    $fe = $pdo->prepare("
        SELECT COUNT(*) FROM event_registrations er
        JOIN events e ON er.event_id = e.id
        WHERE er.student_id = ? AND er.school_id = ? AND e.event_date > CURDATE()
    ");
    $fe->execute([$studentId, $schoolId]);
    $futureEvents = (int) $fe->fetchColumn();

    // Active membership
    $am = $pdo->prepare("
        SELECT m.id, mp.name AS plan_name, m.end_date, m.status
        FROM memberships m
        JOIN membership_plans mp ON m.plan_id = mp.id
        WHERE m.student_id = ? AND m.school_id = ? AND m.status = 'active'
        ORDER BY m.created_at DESC LIMIT 1
    ");
    $am->execute([$studentId, $schoolId]);
    $activeMembership = $am->fetch() ?: null;

    // Pending plan changes
    $pc = 0;
    try {
        $pcStmt = $pdo->prepare("SELECT COUNT(*) FROM pending_plan_changes WHERE student_id = ? AND status = 'pending'");
        $pcStmt->execute([$studentId]);
        $pc = (int) $pcStmt->fetchColumn();
    } catch (\PDOException $e) {
        // Table may not exist
    }

    // Pending makeup classes
    // Note: the makeup_classes table may or may not have a `status` column
    // depending on whether it was created via database.sql (has status) or
    // via the runtime migration in belt_cycle.php (no status column).
    $pendingMakeups = 0;
    try {
        $mc = $pdo->prepare("SELECT COUNT(*) FROM makeup_classes WHERE student_id = ? AND school_id = ? AND status IN ('pending','scheduled')");
        $mc->execute([$studentId, $schoolId]);
        $pendingMakeups = (int) $mc->fetchColumn();
    } catch (\PDOException $e) {
        // status column doesn't exist — count all makeup_classes for this student instead
        try {
            $mc = $pdo->prepare("SELECT COUNT(*) FROM makeup_classes WHERE student_id = ? AND school_id = ?");
            $mc->execute([$studentId, $schoolId]);
            $pendingMakeups = (int) $mc->fetchColumn();
        } catch (\PDOException $e2) {
            // Table may not exist at all
        }
    }

    // Does this student have a parent at the current school?
    $pp = $pdo->prepare("
        SELECT s.first_name, s.last_name
        FROM parent_students ps
        JOIN students s ON s.id = ps.parent_id
        WHERE ps.student_id = ? AND ps.school_id = ?
    ");
    $pp->execute([$studentId, $schoolId]);
    $parentNames = $pp->fetchAll();

    return [
        'student_name'         => $student['first_name'] . ' ' . $student['last_name'],
        'current_school'       => $student['school_name'] ?? 'Unknown',
        'current_school_id'    => $schoolId,
        'is_parent'            => $isParent,
        'children'             => $children,
        'active_enrollments'   => $activeEnrollments,
        'future_events'        => $futureEvents,
        'active_membership'    => $activeMembership,
        'pending_plan_changes' => $pc,
        'pending_makeups'      => $pendingMakeups,
        'has_parent_at_school' => !empty($parentNames),
        'parent_names'         => $parentNames,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Single-student transfer
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Transfer a single student from their current school to $targetSchoolId.
 *
 * Everything runs inside a transaction.  On success returns an array with
 * 'success' => true and summary counts; on failure returns
 * 'success' => false and 'error' => string.
 *
 * @param bool $inTransaction  If true, caller owns the transaction (skip begin/commit).
 */
function transfer_student(int $studentId, int $targetSchoolId, bool $inTransaction = false): array
{
    $pdo = get_db();

    // ── 1. Validate inputs ──────────────────────────────────────────────

    // Student exists?
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if (!$student) {
        return ['success' => false, 'error' => 'Student not found.'];
    }

    $sourceSchoolId = (int) $student['school_id'];

    // Already at target school?
    if ($sourceSchoolId === $targetSchoolId) {
        return ['success' => false, 'error' => 'Student is already at the selected school.'];
    }

    // Target school exists and is active?
    $ts = $pdo->prepare("SELECT id, name FROM schools WHERE id = ? AND status = 'active'");
    $ts->execute([$targetSchoolId]);
    $targetSchool = $ts->fetch();
    if (!$targetSchool) {
        return ['success' => false, 'error' => 'Target school not found or inactive.'];
    }

    // Source school name (for audit)
    $ss = $pdo->prepare("SELECT name FROM schools WHERE id = ?");
    $ss->execute([$sourceSchoolId]);
    $sourceSchoolName = $ss->fetchColumn() ?: 'Unknown';

    // ── 2. Check uniqueness conflicts ───────────────────────────────────

    if (!empty($student['username'])) {
        $ck = $pdo->prepare("SELECT id FROM students WHERE username = ? AND school_id = ? AND id != ?");
        $ck->execute([$student['username'], $targetSchoolId, $studentId]);
        if ($ck->fetch()) {
            return ['success' => false, 'error' => 'A student with username "' . $student['username'] . '" already exists at ' . $targetSchool['name'] . '.'];
        }
    }
    if (!empty($student['email'])) {
        $ck = $pdo->prepare("SELECT id FROM students WHERE email = ? AND school_id = ? AND id != ?");
        $ck->execute([$student['email'], $targetSchoolId, $studentId]);
        if ($ck->fetch()) {
            return ['success' => false, 'error' => 'A student with email "' . $student['email'] . '" already exists at ' . $targetSchool['name'] . '.'];
        }
    }

    // ── 3. Begin transaction (if not already in one) ────────────────────

    $ownTransaction = false;
    if (!$inTransaction) {
        $pdo->beginTransaction();
        $ownTransaction = true;
    }

    try {
        $enrollmentsRemoved  = 0;
        $futureEventsRemoved = 0;
        $membershipsExpired  = 0;

        // ── 3a. DELETE class enrollments ─────────────────────────────
        $del = $pdo->prepare("DELETE FROM class_enrollments WHERE student_id = ? AND school_id = ?");
        $del->execute([$studentId, $sourceSchoolId]);
        $enrollmentsRemoved = $del->rowCount();

        // ── 3b. Event registrations — delete future, transfer past ──
        $delFuture = $pdo->prepare("
            DELETE er FROM event_registrations er
            JOIN events e ON er.event_id = e.id
            WHERE er.student_id = ? AND er.school_id = ? AND e.event_date > CURDATE()
        ");
        $delFuture->execute([$studentId, $sourceSchoolId]);
        $futureEventsRemoved = $delFuture->rowCount();

        $pdo->prepare("UPDATE event_registrations SET school_id = ? WHERE student_id = ? AND school_id = ?")
            ->execute([$targetSchoolId, $studentId, $sourceSchoolId]);

        // ── 3c. Memberships — expire active, transfer all records ───
        $adminName = $_SESSION['full_name'] ?? 'Super Admin';
        $transferNote = "\n[Transferred from {$sourceSchoolName} to {$targetSchool['name']} by {$adminName} on " . date('Y-m-d') . "]";

        $expMem = $pdo->prepare("
            UPDATE memberships
            SET status = 'expired',
                notes = CONCAT(IFNULL(notes, ''), ?)
            WHERE student_id = ? AND school_id = ? AND status = 'active'
        ");
        $expMem->execute([$transferNote, $studentId, $sourceSchoolId]);
        $membershipsExpired = $expMem->rowCount();

        $pdo->prepare("UPDATE memberships SET school_id = ? WHERE student_id = ? AND school_id = ?")
            ->execute([$targetSchoolId, $studentId, $sourceSchoolId]);

        // ── 3d. Cancel pending plan changes ─────────────────────────
        try {
            $pdo->prepare("
                UPDATE pending_plan_changes
                SET status = 'expired', resolved_at = NOW()
                WHERE student_id = ? AND status = 'pending'
            ")->execute([$studentId]);
        } catch (\PDOException $e) {
            // Table may not exist
        }

        // ── 3e. Makeup classes — delete pending, transfer completed ─
        // Note: status column may not exist (schema varies by install method)
        try {
            $pdo->prepare("
                DELETE FROM makeup_classes
                WHERE student_id = ? AND school_id = ? AND status IN ('pending','scheduled')
            ")->execute([$studentId, $sourceSchoolId]);
        } catch (\PDOException $e) {
            // No status column — skip selective delete; transfer all rows instead
        }

        try {
            $pdo->prepare("UPDATE makeup_classes SET school_id = ? WHERE student_id = ? AND school_id = ?")
                ->execute([$targetSchoolId, $studentId, $sourceSchoolId]);
        } catch (\PDOException $e) {
            // Table may not exist
        }

        // ── 3f. Transfer simple history tables ──────────────────────
        $simpleTables = [
            'payments',
            'attendance',
            'student_belts',
            'training_logs',
            'absence_warnings',
            'renewal_log',
            'message_recipients',
        ];

        foreach ($simpleTables as $table) {
            try {
                $col = ($table === 'message_recipients') ? 'recipient_id' : 'student_id';
                $pdo->prepare("UPDATE {$table} SET school_id = ? WHERE {$col} = ? AND school_id = ?")
                    ->execute([$targetSchoolId, $studentId, $sourceSchoolId]);
            } catch (\PDOException $e) {
                // Table may not exist in some installs
            }
        }

        // ── 3g. Parent-related tables ───────────────────────────────

        // parent_students where this student is a child
        $pdo->prepare("UPDATE parent_students SET school_id = ? WHERE student_id = ? AND school_id = ?")
            ->execute([$targetSchoolId, $studentId, $sourceSchoolId]);

        // parent_students where this student IS the parent
        $pdo->prepare("UPDATE parent_students SET school_id = ? WHERE parent_id = ? AND school_id = ?")
            ->execute([$targetSchoolId, $studentId, $sourceSchoolId]);

        // parent_payment_methods (if student is a parent)
        try {
            $pdo->prepare("UPDATE parent_payment_methods SET school_id = ? WHERE parent_id = ? AND school_id = ?")
                ->execute([$targetSchoolId, $studentId, $sourceSchoolId]);
        } catch (\PDOException $e) {
            // Table may not exist
        }

        // ── 3h. Transfer the student record itself ──────────────────
        $pdo->prepare("UPDATE students SET school_id = ? WHERE id = ? AND school_id = ?")
            ->execute([$targetSchoolId, $studentId, $sourceSchoolId]);

        // ── 3i. Audit trail ─────────────────────────────────────────
        try {
            $pdo->prepare("
                INSERT INTO renewal_log (school_id, membership_id, student_id, action, notes, created_at)
                VALUES (?, 0, ?, 'expired', ?, NOW())
            ")->execute([
                $targetSchoolId,
                $studentId,
                "Student transferred from {$sourceSchoolName} (ID:{$sourceSchoolId}) to {$targetSchool['name']} (ID:{$targetSchoolId}) by {$adminName}"
            ]);
        } catch (\PDOException $e) {
            // FK on membership_id may reject 0 — ignore
        }

        // ── 4. Commit (if we own the transaction) ─────────────────
        if ($ownTransaction) {
            $pdo->commit();
        }

        return [
            'success'              => true,
            'student_id'           => $studentId,
            'student_name'         => $student['first_name'] . ' ' . $student['last_name'],
            'from_school_id'       => $sourceSchoolId,
            'from_school_name'     => $sourceSchoolName,
            'to_school_id'         => $targetSchoolId,
            'to_school_name'       => $targetSchool['name'],
            'enrollments_removed'  => $enrollmentsRemoved,
            'future_events_removed'=> $futureEventsRemoved,
            'memberships_expired'  => $membershipsExpired,
        ];

    } catch (\PDOException $e) {
        if ($ownTransaction) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Family transfer (parent + all children)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Transfer a parent-student and ALL linked children to $targetSchoolId.
 *
 * Each child is transferred first, then the parent.  The entire operation
 * is wrapped in a single transaction.
 */
function transfer_parent_family(int $parentStudentId, int $targetSchoolId): array
{
    $pdo = get_db();

    // Verify parent
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND is_parent = 1");
    $stmt->execute([$parentStudentId]);
    $parent = $stmt->fetch();
    if (!$parent) {
        return ['success' => false, 'error' => 'Student is not a parent account.'];
    }

    $sourceSchoolId = (int) $parent['school_id'];

    // Get children
    $ch = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? AND school_id = ?");
    $ch->execute([$parentStudentId, $sourceSchoolId]);
    $childIds = $ch->fetchAll(\PDO::FETCH_COLUMN);

    // Pre-validate all students for uniqueness conflicts before starting
    $allIds = array_merge($childIds, [$parentStudentId]);
    foreach ($allIds as $sid) {
        $s = $pdo->prepare("SELECT username, email, first_name, last_name FROM students WHERE id = ?");
        $s->execute([$sid]);
        $row = $s->fetch();
        if (!$row) continue;

        if (!empty($row['username'])) {
            $ck = $pdo->prepare("SELECT id FROM students WHERE username = ? AND school_id = ? AND id != ?");
            $ck->execute([$row['username'], $targetSchoolId, $sid]);
            if ($ck->fetch()) {
                return ['success' => false, 'error' => 'Username "' . $row['username'] . '" (for ' . $row['first_name'] . ' ' . $row['last_name'] . ') already exists at the target school.'];
            }
        }
        if (!empty($row['email'])) {
            $ck = $pdo->prepare("SELECT id FROM students WHERE email = ? AND school_id = ? AND id != ?");
            $ck->execute([$row['email'], $targetSchoolId, $sid]);
            if ($ck->fetch()) {
                return ['success' => false, 'error' => 'Email "' . $row['email'] . '" (for ' . $row['first_name'] . ' ' . $row['last_name'] . ') already exists at the target school.'];
            }
        }
    }

    // Wrap entire family transfer in a single transaction
    $pdo->beginTransaction();

    try {
        $transferred = [];
        $totalEnrollments = 0;
        $totalEvents = 0;
        $totalMemberships = 0;

        // Transfer children first
        foreach ($childIds as $childId) {
            $result = transfer_student((int) $childId, $targetSchoolId, true);
            if (!$result['success']) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Failed to transfer child ' . ($result['student_name'] ?? "ID:{$childId}") . ': ' . $result['error']];
            }
            $transferred[] = $result;
            $totalEnrollments  += $result['enrollments_removed'];
            $totalEvents       += $result['future_events_removed'];
            $totalMemberships  += $result['memberships_expired'];
        }

        // Transfer the parent
        $parentResult = transfer_student($parentStudentId, $targetSchoolId, true);
        if (!$parentResult['success']) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to transfer parent: ' . $parentResult['error']];
        }
        $transferred[] = $parentResult;
        $totalEnrollments  += $parentResult['enrollments_removed'];
        $totalEvents       += $parentResult['future_events_removed'];
        $totalMemberships  += $parentResult['memberships_expired'];

        $pdo->commit();

        return [
            'success'               => true,
            'transferred_students'  => $transferred,
            'total_transferred'     => count($transferred),
            'total_enrollments_removed'  => $totalEnrollments,
            'total_events_removed'       => $totalEvents,
            'total_memberships_expired'  => $totalMemberships,
        ];

    } catch (\PDOException $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => 'Database error during family transfer: ' . $e->getMessage()];
    }
}
