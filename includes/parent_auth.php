<?php
/**
 * Parent/Family account authentication and helpers.
 *
 * A student can be "promoted" to a parent account by setting is_parent=1
 * on their students row.  They keep the same login credentials and gain
 * the ability to manage linked child-students via the parent_students table.
 *
 * parent_students.parent_id  = students.id of the parent
 * parent_students.student_id = students.id of the child
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tenant.php';

// Migrations have been moved to migrate.php

// ─── Legacy parent authentication (kept for backward compat) ─────────

/**
 * Authenticate a parent by username & password.
 * Checks the legacy parents table and, if a match is found, looks for a
 * corresponding promoted student (is_parent=1) so we can log them in under
 * the student-as-parent model.  Returns a student row (with '_legacy_parent'
 * flag) when possible, otherwise the raw parent row for backward compat.
 */
function authenticate_parent(string $username, string $password, string $ip = '')
{
    if ($ip === '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    $pdo = get_db();

    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM parents WHERE (username = :u1 OR email = :u2) AND status = 'active' LIMIT 1"
        );
        $stmt->execute([':u1' => $username, ':u2' => $username]);
        $parent = $stmt->fetch();

        if ($parent) {
            $hash = $parent['password_hash'] ?? '';
            if ($hash && password_verify($password, $hash)) {
                clear_login_attempts($username, $ip);

                // Try to find the matching promoted student so we can use
                // the student-as-parent login path (correct parent_students IDs)
                $matchStmt = $pdo->prepare("
                    SELECT * FROM students
                    WHERE is_parent = 1
                      AND status = 'active'
                      AND (
                          (first_name = :fn AND last_name = :ln)
                          OR (email IS NOT NULL AND email != '' AND email = :em)
                      )
                    LIMIT 1
                ");
                $matchStmt->execute([
                    ':fn' => $parent['first_name'],
                    ':ln' => $parent['last_name'],
                    ':em' => $parent['email'] ?? '',
                ]);
                $matchedStudent = $matchStmt->fetch();

                if ($matchedStudent) {
                    // Return the student row so login.php can call login_student()
                    $matchedStudent['_legacy_parent'] = true;
                    return $matchedStudent;
                }

                return $parent;
            }
        }
    } catch (\PDOException $e) {
        // parents table may not exist
    }

    return false;
}

/**
 * Persist parent identity into the session after successful login.
 * Used only for legacy parent accounts that exist in the parents table.
 */
function login_parent(array $parent): void
{
    auth_start_session();
    session_regenerate_id(true);

    $_SESSION['user_type']  = 'parent';
    $_SESSION['parent_id']  = $parent['id'];
    $_SESSION['user_id']    = $parent['id'];
    $_SESSION['username']   = $parent['username'];
    $_SESSION['first_name'] = $parent['first_name'];
    $_SESSION['last_name']  = $parent['last_name'];

    // Audit log: parent login
    if (function_exists('audit_log')) {
        audit_log('login', [
            'description' => 'Parent login: ' . trim($parent['first_name'] . ' ' . $parent['last_name']),
            'entity_type' => 'parent',
            'entity_id'   => $parent['id'],
        ]);
    }
}

/**
 * Require an authenticated parent session.
 * Now accepts BOTH student-as-parent sessions AND legacy parent sessions.
 */
function require_parent(): void
{
    auth_start_session();

    // New model: student with is_parent capability
    if ((!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')
        && !empty($_SESSION['is_parent'])) {
        return; // OK — student with parent abilities
    }

    // Legacy model: separate parent account
    if (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'parent') {
        return; // OK — legacy parent session
    }

    header('Location: login.php');
    exit;
}

/**
 * Get the effective parent ID for the current session.
 * For student-as-parent: returns the student's ID (since parent_students.parent_id = students.id)
 * For legacy parent: tries to resolve to the matching students.id, falls back to parents.id.
 */
function get_effective_parent_id(): int
{
    if (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'student' && !empty($_SESSION['is_parent'])) {
        return (int)$_SESSION['student_id'];
    }
    if (!empty($_SESSION['parent_id'])) {
        // Legacy parent session — try to resolve to the matching promoted student ID
        // so that parent_students lookups work correctly.
        if (empty($_SESSION['_resolved_parent_student_id'])) {
            try {
                $pdo = get_db();
                $parentId = (int)$_SESSION['parent_id'];
                $pParams = [$parentId];
                $pStmt = $pdo->prepare("SELECT first_name, last_name, email FROM parents WHERE id = ?" . school_where() . " LIMIT 1");
                school_param($pParams);
                $pStmt->execute($pParams);
                $pRow = $pStmt->fetch();
                if ($pRow) {
                    $mStmt = $pdo->prepare("
                        SELECT id FROM students
                        WHERE is_parent = 1 AND status = 'active'
                          AND (
                              (first_name = ? AND last_name = ?)
                              OR (email IS NOT NULL AND email != '' AND email = ?)
                          )" . school_where() . "
                        LIMIT 1
                    ");
                    $mParams = [$pRow['first_name'], $pRow['last_name'], $pRow['email'] ?? ''];
                    school_param($mParams);
                    $mStmt->execute($mParams);
                    $resolvedId = $mStmt->fetchColumn();
                    $_SESSION['_resolved_parent_student_id'] = $resolvedId ?: $parentId;
                } else {
                    $_SESSION['_resolved_parent_student_id'] = $parentId;
                }
            } catch (\PDOException $e) {
                $_SESSION['_resolved_parent_student_id'] = (int)$_SESSION['parent_id'];
            }
        }
        return (int)$_SESSION['_resolved_parent_student_id'];
    }
    return 0;
}

// ─── Child (student) management ──────────────────────────────────────

/**
 * Get all children linked to a parent, with belt and membership summary.
 * parent_id = students.id of the parent-student
 */
function get_parent_children(int $parentId): array
{
    $pdo = get_db();
    $stmt = $pdo->prepare("
        SELECT s.*, ps.relationship,
               COALESCE(b.name, 'No Belt') as current_belt,
               COALESCE(b.color, '') as belt_color,
               mp.name as plan_name,
               m.end_date as membership_end,
               m.status as membership_status
        FROM parent_students ps
        JOIN students s ON s.id = ps.student_id
        LEFT JOIN (
            SELECT sb.student_id, b.name, b.color
            FROM student_belts sb
            JOIN belts b ON sb.belt_id = b.id
            WHERE sb.id IN (SELECT MAX(id) FROM student_belts GROUP BY student_id)
        ) b ON s.id = b.student_id
        LEFT JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
        LEFT JOIN membership_plans mp ON mp.id = m.plan_id
        WHERE ps.parent_id = ?" . school_where("s") . "
        ORDER BY s.first_name, s.last_name
    ");
    $params = [$parentId];
    school_param($params);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Link an existing student-child to a parent-student.
 * parent_id = students.id of the parent, student_id = students.id of the child.
 * Copies the parent-student's payment methods to the child.
 */
function link_student_to_parent(int $parentId, int $studentId, string $relationship = 'parent'): bool
{
    // Prevent linking to yourself
    if ($parentId === $studentId) {
        return false;
    }

    $pdo = get_db();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO parent_students (school_id, parent_id, student_id, relationship) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([current_school_id(), $parentId, $studentId, $relationship]);
    } catch (\PDOException $e) {
        // Duplicate or FK constraint
        return false;
    }

    // Copy parent's payment methods to the child student
    sync_parent_payment_methods_to_child($parentId, $studentId);

    return true;
}

/**
 * Copy payment methods from a parent-student to a child-student.
 * Both are rows in the students table; payment_methods.student_id is the key.
 */
function sync_parent_payment_methods_to_child(int $parentStudentId, int $childStudentId): int
{
    $pdo = get_db();
    $copied = 0;

    try {
        // Get parent-student's payment methods
        $parentMethods = $pdo->prepare(
            "SELECT * FROM payment_methods WHERE student_id = ? ORDER BY is_default DESC, created_at DESC"
        );
        $parentMethods->execute([$parentStudentId]);
        $parentCards = $parentMethods->fetchAll();

        if (empty($parentCards)) {
            return 0;
        }

        // Get parent name for the label
        $stParams = [$parentStudentId];
        $parentStmt = $pdo->prepare("SELECT first_name, last_name FROM students WHERE id = ?" . school_where() . " LIMIT 1");
        school_param($stParams);
        $parentStmt->execute($stParams);
        $parentInfo = $parentStmt->fetch();
        $parentName = $parentInfo ? trim($parentInfo['first_name'] . ' ' . $parentInfo['last_name']) : 'Parent';

        // Get child's existing payment methods to avoid duplicates
        $existingStmt = $pdo->prepare(
            "SELECT last_four, card_brand FROM payment_methods WHERE student_id = ?"
        );
        $existingStmt->execute([$childStudentId]);
        $existingCards = [];
        foreach ($existingStmt->fetchAll() as $ec) {
            $existingCards[] = ($ec['card_brand'] ?? '') . ':' . $ec['last_four'];
        }

        // Count existing cards to decide default status
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payment_methods WHERE student_id = ?");
        $countStmt->execute([$childStudentId]);
        $existingCount = (int) $countStmt->fetchColumn();

        foreach ($parentCards as $pc) {
            $cardKey = ($pc['card_brand'] ?? '') . ':' . $pc['last_four'];
            if (in_array($cardKey, $existingCards, true)) {
                continue;
            }

            $label = $pc['label'] . ' (via ' . $parentName . ')';
            $isDefault = ($existingCount === 0 && $copied === 0) ? 1 : 0;

            try {
                $ins = $pdo->prepare(
                    "INSERT INTO payment_methods (student_id, label, card_brand, last_four, exp_month, exp_year, encrypted_token, gateway_payment_method_id, is_default)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $ins->execute([
                    $childStudentId,
                    $label,
                    $pc['card_brand'],
                    $pc['last_four'],
                    $pc['exp_month'] ?? null,
                    $pc['exp_year'] ?? null,
                    $pc['encrypted_token'] ?? '',
                    $pc['gateway_payment_method_id'] ?? null,
                    $isDefault,
                ]);
                $copied++;
            } catch (\PDOException $e) {
                try {
                    $ins = $pdo->prepare(
                        "INSERT INTO payment_methods (student_id, label, card_brand, last_four, encrypted_token, is_default)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $ins->execute([
                        $childStudentId,
                        $label,
                        $pc['card_brand'],
                        $pc['last_four'],
                        $pc['encrypted_token'] ?? '',
                        $isDefault,
                    ]);
                    $copied++;
                } catch (\PDOException $e2) {}
            }
        }
    } catch (\PDOException $e) {}

    return $copied;
}

// Keep legacy function name as alias
function sync_parent_payment_methods_to_student(int $parentId, int $studentId): int
{
    return sync_parent_payment_methods_to_child($parentId, $studentId);
}

/**
 * Copy payment methods from a child-student to a parent-student (reverse sync).
 * Used for edge cases where the child had a card before the parent account existed.
 * Both are rows in the students table; payment_methods.student_id is the key.
 */
function sync_child_payment_methods_to_parent(int $childStudentId, int $parentStudentId): int
{
    $pdo = get_db();
    $copied = 0;

    try {
        // Get child-student's payment methods
        $childMethods = $pdo->prepare(
            "SELECT * FROM payment_methods WHERE student_id = ? ORDER BY is_default DESC, created_at DESC"
        );
        $childMethods->execute([$childStudentId]);
        $childCards = $childMethods->fetchAll();

        if (empty($childCards)) {
            return 0;
        }

        // Get child name for the label
        $stParams = [$childStudentId];
        $childStmt = $pdo->prepare("SELECT first_name, last_name FROM students WHERE id = ?" . school_where() . " LIMIT 1");
        school_param($stParams);
        $childStmt->execute($stParams);
        $childInfo = $childStmt->fetch();
        $childName = $childInfo ? trim($childInfo['first_name'] . ' ' . $childInfo['last_name']) : 'Child';

        // Get parent's existing payment methods to avoid duplicates
        $existingStmt = $pdo->prepare(
            "SELECT last_four, card_brand FROM payment_methods WHERE student_id = ?"
        );
        $existingStmt->execute([$parentStudentId]);
        $existingCards = [];
        foreach ($existingStmt->fetchAll() as $ec) {
            $existingCards[] = ($ec['card_brand'] ?? '') . ':' . $ec['last_four'];
        }

        // Count existing parent cards to decide default status
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payment_methods WHERE student_id = ?");
        $countStmt->execute([$parentStudentId]);
        $existingCount = (int) $countStmt->fetchColumn();

        foreach ($childCards as $cc) {
            // Skip cards that are themselves already a "(via ...)" copy to avoid chains
            if (stripos($cc['label'] ?? '', '(via ') !== false) {
                continue;
            }

            $cardKey = ($cc['card_brand'] ?? '') . ':' . $cc['last_four'];
            if (in_array($cardKey, $existingCards, true)) {
                continue;
            }

            $label = ($cc['label'] ?: ($cc['card_brand'] . ' ' . $cc['last_four'])) . ' (via ' . $childName . ')';
            $isDefault = ($existingCount === 0 && $copied === 0) ? 1 : 0;

            try {
                $ins = $pdo->prepare(
                    "INSERT INTO payment_methods (student_id, label, card_brand, last_four, exp_month, exp_year, encrypted_token, gateway_payment_method_id, is_default)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $ins->execute([
                    $parentStudentId,
                    $label,
                    $cc['card_brand'],
                    $cc['last_four'],
                    $cc['exp_month'] ?? null,
                    $cc['exp_year'] ?? null,
                    $cc['encrypted_token'] ?? '',
                    $cc['gateway_payment_method_id'] ?? null,
                    $isDefault,
                ]);
                $copied++;
            } catch (\PDOException $e) {
                try {
                    $ins = $pdo->prepare(
                        "INSERT INTO payment_methods (student_id, label, card_brand, last_four, encrypted_token, is_default)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $ins->execute([
                        $parentStudentId,
                        $label,
                        $cc['card_brand'],
                        $cc['last_four'],
                        $cc['encrypted_token'] ?? '',
                        $isDefault,
                    ]);
                    $copied++;
                } catch (\PDOException $e2) {}
            }
        }
    } catch (\PDOException $e) {}

    return $copied;
}

/**
 * Unlink a child-student from a parent-student.
 */
function unlink_student_from_parent(int $parentId, int $studentId): bool
{
    $pdo = get_db();
    $params = [$parentId, $studentId];
    $stmt = $pdo->prepare("DELETE FROM parent_students WHERE parent_id = ? AND student_id = ?" . school_where());
    school_param($params);
    $stmt->execute($params);
    return $stmt->rowCount() > 0;
}

/**
 * Check if a student has been promoted to a parent account.
 * Returns true if is_parent=1 on the student row.
 */
function student_is_parent(int $studentId): bool
{
    $pdo = get_db();
    try {
        $params = [$studentId];
        $stmt = $pdo->prepare("SELECT is_parent FROM students WHERE id = ?" . school_where() . " LIMIT 1");
        school_param($params);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row && (int)$row['is_parent'] === 1;
    } catch (\PDOException $e) {
        return false;
    }
}

/**
 * Check if a student already has parent capabilities (is_parent=1).
 * Returns the student row if they are a parent, or false.
 * (Replaces the old student_has_parent_account that looked in the parents table.)
 */
function student_has_parent_account(int $studentId): array|false
{
    $pdo = get_db();
    try {
        $params = [$studentId];
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND is_parent = 1" . school_where() . " LIMIT 1");
        school_param($params);
        $stmt->execute($params);
        $student = $stmt->fetch();
        return $student ?: false;
    } catch (\PDOException $e) {
        return false;
    }
}

/**
 * Promote a student to a parent account.
 * Simply sets is_parent=1 on the student row.
 * No separate account is created — the student keeps the same login.
 *
 * Returns the student row on success, or false on failure.
 */
function promote_student_to_parent(int $studentId, string $username = '', string $password = ''): array|false
{
    $pdo = get_db();

    // Fetch the student
    $params = [$studentId];
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?" . school_where() . " LIMIT 1");
    school_param($params);
    $stmt->execute($params);
    $student = $stmt->fetch();
    if (!$student) {
        return false;
    }

    // Already a parent? Return the student row (idempotent)
    if ((int)($student['is_parent'] ?? 0) === 1) {
        return $student;
    }

    // Set the is_parent flag
    try {
        $uParams = [$studentId];
        $upd = $pdo->prepare("UPDATE students SET is_parent = 1 WHERE id = ?" . school_where());
        school_param($uParams);
        $upd->execute($uParams);
    } catch (\PDOException $e) {
        return false;
    }

    // Re-fetch and return
    $stmt->execute($params);
    return $stmt->fetch() ?: false;
}

/**
 * Get linked parent accounts for a student (child).
 * Returns parent-students who have this student as a child.
 */
function get_student_parents(int $studentId): array
{
    $pdo = get_db();
    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.username, s.first_name, s.last_name, s.email, s.phone,
                   ps.relationship, ps.created_at as linked_at
            FROM parent_students ps
            JOIN students s ON s.id = ps.parent_id
            WHERE ps.student_id = ?" . school_where("s") . "
            ORDER BY ps.created_at DESC
        ");
        $params = [$studentId];
        school_param($params);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (\PDOException $e) {
        return [];
    }
}

/**
 * Verify that a parent-student is allowed to manage a specific child-student.
 * Returns the child student row if allowed, or false.
 * Redirects to student_portal.php if access is denied and $redirect is true.
 */
function parent_verify_child(int $parentId, int $studentId, bool $redirect = true): array|false
{
    $pdo = get_db();
    try {
        $stmt = $pdo->prepare("
            SELECT s.* FROM parent_students ps
            JOIN students s ON s.id = ps.student_id
            WHERE ps.parent_id = ? AND ps.student_id = ?" . school_where("s") . "
            LIMIT 1
        ");
        $params = [$parentId, $studentId];
        school_param($params);
        $stmt->execute($params);
        $child = $stmt->fetch();
        if ($child) return $child;
    } catch (\PDOException $e) {}

    if ($redirect) {
        header('Location: student_portal.php');
        exit;
    }
    return false;
}
