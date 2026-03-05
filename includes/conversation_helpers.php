<?php
/**
 * conversation_helpers.php — Business logic for the student conversation messaging system.
 *
 * Provides functions for:
 *   - Finding/creating 1-on-1 conversations
 *   - Sending direct messages with content moderation
 *   - Listing conversations and messages
 *   - Unread counts
 *   - Blocking/unblocking students
 *   - Hiding conversations
 *   - Contact search
 */

require_once __DIR__ . '/db.php';

// ─── Participant Ordering ────────────────────────────────────────────────────

/**
 * Returns participants in canonical order for the UNIQUE constraint.
 * Order: 'admin' before 'student' (alphabetical), then lower ID first within same type.
 */
function normalize_participants(string $typeA, int $idA, string $typeB, int $idB): array
{
    if ($typeA < $typeB || ($typeA === $typeB && $idA <= $idB)) {
        return [$typeA, $idA, $typeB, $idB];
    }
    return [$typeB, $idB, $typeA, $idA];
}

// ─── Find or Create Conversation ─────────────────────────────────────────────

/**
 * Find an existing conversation between two participants, or create one.
 * Returns the conversation ID.
 */
function find_or_create_conversation(int $schoolId, string $typeA, int $idA, string $typeB, int $idB): int
{
    $pdo = get_db();
    [$p1Type, $p1Id, $p2Type, $p2Id] = normalize_participants($typeA, $idA, $typeB, $idB);

    // Try to find existing
    $stmt = $pdo->prepare(
        "SELECT id FROM conversations
         WHERE school_id = ?
           AND participant_one_type = ? AND participant_one_id = ?
           AND participant_two_type = ? AND participant_two_id = ?"
    );
    $stmt->execute([$schoolId, $p1Type, $p1Id, $p2Type, $p2Id]);
    $row = $stmt->fetch();

    if ($row) {
        return (int) $row['id'];
    }

    // Create new conversation
    $ins = $pdo->prepare(
        "INSERT INTO conversations (school_id, participant_one_type, participant_one_id, participant_two_type, participant_two_id)
         VALUES (?, ?, ?, ?, ?)"
    );
    $ins->execute([$schoolId, $p1Type, $p1Id, $p2Type, $p2Id]);
    $convId = (int) $pdo->lastInsertId();

    // Create participant rows for both users
    $cpIns = $pdo->prepare(
        "INSERT INTO conversation_participants (school_id, conversation_id, participant_type, participant_id)
         VALUES (?, ?, ?, ?)"
    );
    $cpIns->execute([$schoolId, $convId, $typeA, $idA]);
    $cpIns->execute([$schoolId, $convId, $typeB, $idB]);

    return $convId;
}

// ─── Send Direct Message ─────────────────────────────────────────────────────

/**
 * Send a direct message within a conversation.
 * Runs content moderation, inserts the message, updates conversation metadata,
 * and unhides the conversation for the recipient if hidden.
 *
 * Returns ['success' => bool, 'message_id' => int|null, 'flagged' => bool, 'error' => string|null]
 */
function send_direct_message(int $schoolId, int $convId, string $senderType, int $senderId, string $body): array
{
    $pdo = get_db();

    // Validate message body
    $body = trim($body);
    if ($body === '') {
        return ['success' => false, 'message_id' => null, 'flagged' => false, 'error' => 'Message cannot be empty.'];
    }
    if (mb_strlen($body) > 2000) {
        return ['success' => false, 'message_id' => null, 'flagged' => false, 'error' => 'Message is too long (max 2000 characters).'];
    }

    // Strip HTML tags — store as plain text
    $body = strip_tags($body);

    // Rate limiting: max 30 messages per 5 minutes per sender
    $rateStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM direct_messages
         WHERE sender_type = ? AND sender_id = ? AND school_id = ?
           AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    );
    $rateStmt->execute([$senderType, $senderId, $schoolId]);
    if ((int) $rateStmt->fetchColumn() >= 30) {
        return ['success' => false, 'message_id' => null, 'flagged' => false, 'error' => 'You are sending messages too quickly. Please wait a moment.'];
    }

    // Verify sender is a participant in this conversation
    $partStmt = $pdo->prepare(
        "SELECT id FROM conversation_participants
         WHERE conversation_id = ? AND participant_type = ? AND participant_id = ? AND school_id = ?"
    );
    $partStmt->execute([$convId, $senderType, $senderId, $schoolId]);
    if (!$partStmt->fetch()) {
        return ['success' => false, 'message_id' => null, 'flagged' => false, 'error' => 'You are not a participant in this conversation.'];
    }

    // Check blocking (only between students)
    if ($senderType === 'student') {
        $otherStmt = $pdo->prepare(
            "SELECT participant_type, participant_id FROM conversation_participants
             WHERE conversation_id = ? AND NOT (participant_type = ? AND participant_id = ?) AND school_id = ?"
        );
        $otherStmt->execute([$convId, $senderType, $senderId, $schoolId]);
        $other = $otherStmt->fetch();

        if ($other && $other['participant_type'] === 'student') {
            if (is_blocked($schoolId, $senderId, (int) $other['participant_id'])) {
                return ['success' => false, 'message_id' => null, 'flagged' => false, 'error' => 'This user is not available for messaging.'];
            }
        }
    }

    // Content moderation
    $modResult = check_message_moderation($body, $schoolId);
    $isFlagged = $modResult['flagged'] ? 1 : 0;
    $flagReason = $modResult['flagged'] ? implode(', ', $modResult['reasons']) : null;

    // Insert the message
    $insStmt = $pdo->prepare(
        "INSERT INTO direct_messages (school_id, conversation_id, sender_type, sender_id, body, is_flagged, flag_reason)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $insStmt->execute([$schoolId, $convId, $senderType, $senderId, $body, $isFlagged, $flagReason]);
    $messageId = (int) $pdo->lastInsertId();

    // Update conversation metadata
    $preview = mb_strlen($body) > 97 ? mb_substr($body, 0, 97) . '...' : $body;
    $pdo->prepare(
        "UPDATE conversations SET last_message_at = NOW(), last_message_preview = ? WHERE id = ? AND school_id = ?"
    )->execute([$preview, $convId, $schoolId]);

    // Unhide the conversation for the OTHER participant (if hidden)
    $pdo->prepare(
        "UPDATE conversation_participants
         SET hidden_at = NULL
         WHERE conversation_id = ? AND NOT (participant_type = ? AND participant_id = ?) AND school_id = ?"
    )->execute([$convId, $senderType, $senderId, $schoolId]);

    // If flagged, create a review entry for admins
    if ($isFlagged) {
        $pdo->prepare(
            "INSERT INTO flagged_message_reviews (school_id, direct_message_id, review_action)
             VALUES (?, ?, 'pending')"
        )->execute([$schoolId, $messageId]);

        // Audit log
        if (function_exists('audit_log')) {
            audit_log('message_flagged', [
                'description' => "Direct message ID {$messageId} flagged: {$flagReason}",
                'entity_type' => 'direct_message',
                'entity_id'   => $messageId,
                'user_type'   => $senderType,
                'username'    => $senderType . '_' . $senderId,
            ]);
        }
    }

    return ['success' => true, 'message_id' => $messageId, 'flagged' => (bool) $isFlagged, 'error' => null];
}

// ─── Content Moderation ──────────────────────────────────────────────────────

/**
 * Check message body against the moderation word list.
 * Returns ['flagged' => bool, 'reasons' => string[]]
 */
function check_message_moderation(string $body, int $schoolId): array
{
    static $wordCache = [];

    $pdo = get_db();

    // Cache word list per school per request
    if (!isset($wordCache[$schoolId])) {
        try {
            $stmt = $pdo->prepare("SELECT word, severity FROM moderation_words WHERE school_id = ?");
            $stmt->execute([$schoolId]);
            $wordCache[$schoolId] = $stmt->fetchAll();
        } catch (\PDOException $e) {
            $wordCache[$schoolId] = [];
        }
    }

    if (empty($wordCache[$schoolId])) {
        return ['flagged' => false, 'reasons' => []];
    }

    // Normalize the body for matching: lowercase, leetspeak substitution
    $normalized = mb_strtolower($body);
    $normalized = strtr($normalized, [
        '1' => 'i', '!' => 'i',
        '0' => 'o',
        '@' => 'a',
        '3' => 'e',
        '$' => 's', '5' => 's',
        '7' => 't',
        '4' => 'a',
    ]);

    $flagged = false;
    $reasons = [];

    foreach ($wordCache[$schoolId] as $row) {
        $word = mb_strtolower($row['word']);
        // Use word-boundary matching for single words, contains matching for phrases
        if (str_contains($word, ' ')) {
            // Phrase: check if the normalized body contains it
            if (str_contains($normalized, $word)) {
                $flagged = true;
                $reasons[] = 'matched: "' . $row['word'] . '"';
            }
        } else {
            // Single word: use word-boundary regex
            $escaped = preg_quote($word, '/');
            if (preg_match('/\b' . $escaped . '\b/iu', $normalized)) {
                $flagged = true;
                $reasons[] = 'matched: "' . $row['word'] . '"';
            }
        }
    }

    return ['flagged' => $flagged, 'reasons' => $reasons];
}

// ─── List Conversations ──────────────────────────────────────────────────────

/**
 * Get conversations for a user, with other participant info and unread status.
 * Returns array of conversation data ordered by last_message_at DESC.
 */
function get_conversations_for_user(int $schoolId, string $userType, int $userId, bool $includeHidden = false): array
{
    $pdo = get_db();

    $hiddenClause = $includeHidden ? '' : ' AND cp.hidden_at IS NULL';

    $sql = "SELECT c.id AS conversation_id, c.last_message_at, c.last_message_preview,
                   cp.last_read_at, cp.hidden_at,
                   -- other participant info
                   cp2.participant_type AS other_type, cp2.participant_id AS other_id
            FROM conversation_participants cp
            JOIN conversations c ON c.id = cp.conversation_id AND c.school_id = cp.school_id
            JOIN conversation_participants cp2 ON cp2.conversation_id = c.id
                 AND NOT (cp2.participant_type = cp.participant_type AND cp2.participant_id = cp.participant_id)
            WHERE cp.participant_type = ? AND cp.participant_id = ? AND cp.school_id = ?
              AND c.last_message_at IS NOT NULL
              {$hiddenClause}
            ORDER BY c.last_message_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userType, $userId, $schoolId]);
    $rows = $stmt->fetchAll();

    // Gather other participant details (names) in bulk
    $studentIds = [];
    $adminIds = [];
    foreach ($rows as $row) {
        if ($row['other_type'] === 'student') {
            $studentIds[] = (int) $row['other_id'];
        } else {
            $adminIds[] = (int) $row['other_id'];
        }
    }

    $studentNames = [];
    if (!empty($studentIds)) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $ns = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE id IN ({$placeholders})");
        $ns->execute($studentIds);
        foreach ($ns->fetchAll() as $s) {
            $studentNames[(int) $s['id']] = trim($s['first_name'] . ' ' . $s['last_name']);
        }
    }

    $adminNames = [];
    if (!empty($adminIds)) {
        $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
        $na = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id IN ({$placeholders})");
        $na->execute($adminIds);
        foreach ($na->fetchAll() as $a) {
            $adminNames[(int) $a['id']] = ['name' => $a['full_name'], 'role' => $a['role']];
        }
    }

    // Count unread per conversation
    $result = [];
    foreach ($rows as $row) {
        $convId = (int) $row['conversation_id'];
        $otherType = $row['other_type'];
        $otherId = (int) $row['other_id'];

        // Determine other participant name and role
        if ($otherType === 'student') {
            $otherName = $studentNames[$otherId] ?? 'Student';
            $otherRole = 'student';
        } else {
            $info = $adminNames[$otherId] ?? ['name' => 'Staff', 'role' => 'staff'];
            $otherName = $info['name'];
            $otherRole = $info['role'];
        }

        // Count unread messages (messages after last_read_at, not sent by current user)
        $unread = 0;
        $lastRead = $row['last_read_at'];
        if ($lastRead === null && $row['last_message_at'] !== null) {
            // Never read: count all messages not from self
            $uStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM direct_messages
                 WHERE conversation_id = ? AND school_id = ?
                   AND NOT (sender_type = ? AND sender_id = ?)"
            );
            $uStmt->execute([$convId, $schoolId, $userType, $userId]);
            $unread = (int) $uStmt->fetchColumn();
        } elseif ($lastRead !== null && $row['last_message_at'] > $lastRead) {
            $uStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM direct_messages
                 WHERE conversation_id = ? AND school_id = ?
                   AND created_at > ?
                   AND NOT (sender_type = ? AND sender_id = ?)"
            );
            $uStmt->execute([$convId, $schoolId, $lastRead, $userType, $userId]);
            $unread = (int) $uStmt->fetchColumn();
        }

        $result[] = [
            'conversation_id' => $convId,
            'other_type'      => $otherType,
            'other_id'        => $otherId,
            'other_name'      => $otherName,
            'other_role'      => $otherRole,
            'last_message_at' => $row['last_message_at'] ? date('Y-m-d H:i:s', strtotime($row['last_message_at'])) : null,
            'last_message_preview' => $row['last_message_preview'],
            'unread_count'    => $unread,
            'is_hidden'       => $row['hidden_at'] !== null,
        ];
    }

    return $result;
}

// ─── Get Conversation Messages ───────────────────────────────────────────────

/**
 * Get paginated messages for a conversation.
 * Returns ['messages' => array, 'has_more' => bool]
 */
function get_conversation_messages(int $convId, int $schoolId, int $page = 1, int $perPage = 50): array
{
    $pdo = get_db();
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT dm.id, dm.sender_type, dm.sender_id, dm.body, dm.is_flagged, dm.flag_reason,
                dm.is_system, dm.created_at
         FROM direct_messages dm
         WHERE dm.conversation_id = ? AND dm.school_id = ?
         ORDER BY dm.created_at ASC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$convId, $schoolId, $perPage + 1, $offset]);
    $rows = $stmt->fetchAll();

    $hasMore = count($rows) > $perPage;
    if ($hasMore) {
        array_pop($rows);
    }

    // Gather sender names
    $studentIds = [];
    $adminIds = [];
    foreach ($rows as $r) {
        if ($r['sender_type'] === 'student') {
            $studentIds[] = (int) $r['sender_id'];
        } else {
            $adminIds[] = (int) $r['sender_id'];
        }
    }
    $studentIds = array_unique($studentIds);
    $adminIds = array_unique($adminIds);

    $sNames = [];
    if (!empty($studentIds)) {
        $ph = implode(',', array_fill(0, count($studentIds), '?'));
        $s = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE id IN ({$ph})");
        $s->execute(array_values($studentIds));
        foreach ($s->fetchAll() as $r) {
            $sNames[(int) $r['id']] = trim($r['first_name'] . ' ' . $r['last_name']);
        }
    }
    $aNames = [];
    if (!empty($adminIds)) {
        $ph = implode(',', array_fill(0, count($adminIds), '?'));
        $a = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id IN ({$ph})");
        $a->execute(array_values($adminIds));
        foreach ($a->fetchAll() as $r) {
            $aNames[(int) $r['id']] = ['name' => $r['full_name'], 'role' => $r['role']];
        }
    }

    $messages = [];
    foreach ($rows as $r) {
        $senderId = (int) $r['sender_id'];
        if ($r['sender_type'] === 'student') {
            $senderName = $sNames[$senderId] ?? 'Student';
            $senderRole = 'student';
        } else {
            $info = $aNames[$senderId] ?? ['name' => 'Staff', 'role' => 'staff'];
            $senderName = $info['name'];
            $senderRole = $info['role'];
        }

        $messages[] = [
            'id'          => (int) $r['id'],
            'sender_type' => $r['sender_type'],
            'sender_id'   => $senderId,
            'sender_name' => $senderName,
            'sender_role' => $senderRole,
            'body'        => $r['body'],
            'is_flagged'  => (bool) $r['is_flagged'],
            'flag_reason' => $r['flag_reason'],
            'is_system'   => (bool) $r['is_system'],
            'created_at'  => $r['created_at'] ? date('Y-m-d H:i:s', strtotime($r['created_at'])) : null,
        ];
    }

    return ['messages' => $messages, 'has_more' => $hasMore];
}

// ─── Unread Count ────────────────────────────────────────────────────────────

/**
 * Count the number of conversations with unread messages (non-hidden).
 */
function get_unread_conversation_count(int $schoolId, string $userType, int $userId): int
{
    $pdo = get_db();

    try {
        $sql = "SELECT COUNT(*) FROM conversation_participants cp
                JOIN conversations c ON c.id = cp.conversation_id AND c.school_id = cp.school_id
                WHERE cp.participant_type = ? AND cp.participant_id = ? AND cp.school_id = ?
                  AND cp.hidden_at IS NULL
                  AND c.last_message_at IS NOT NULL
                  AND (cp.last_read_at IS NULL OR c.last_message_at > cp.last_read_at)
                  AND EXISTS (
                      SELECT 1 FROM direct_messages dm
                      WHERE dm.conversation_id = c.id AND dm.school_id = c.school_id
                        AND NOT (dm.sender_type = ? AND dm.sender_id = ?)
                        AND (cp.last_read_at IS NULL OR dm.created_at > cp.last_read_at)
                  )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userType, $userId, $schoolId, $userType, $userId]);
        return (int) $stmt->fetchColumn();
    } catch (\PDOException $e) {
        return 0;
    }
}

// ─── Blocking ────────────────────────────────────────────────────────────────

/**
 * Check if either student has blocked the other (bidirectional check).
 */
function is_blocked(int $schoolId, int $studentIdA, int $studentIdB): bool
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM student_blocks
         WHERE school_id = ?
           AND ((blocker_student_id = ? AND blocked_student_id = ?)
             OR (blocker_student_id = ? AND blocked_student_id = ?))"
    );
    $stmt->execute([$schoolId, $studentIdA, $studentIdB, $studentIdB, $studentIdA]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Check if a student can block the target.
 * Cannot block: admins/staff, own parents.
 */
function can_block_user(int $schoolId, int $blockerStudentId, string $targetType, int $targetId): array
{
    // Cannot block admin/staff
    if ($targetType === 'admin') {
        return ['can_block' => false, 'reason' => 'You cannot block staff or administrators.'];
    }

    // Cannot block self
    if ($targetType === 'student' && $targetId === $blockerStudentId) {
        return ['can_block' => false, 'reason' => 'You cannot block yourself.'];
    }

    // Cannot block own parent
    $pdo = get_db();
    try {
        // Check if target is a parent of the blocker
        $parentCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM parent_students
             WHERE student_id = ? AND parent_id = ? AND school_id = ?"
        );
        $parentCheck->execute([$blockerStudentId, $targetId, $schoolId]);
        if ((int) $parentCheck->fetchColumn() > 0) {
            return ['can_block' => false, 'reason' => 'You cannot block your parent account.'];
        }

        // Also check if target is a child of the blocker (if blocker is a parent)
        $childCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM parent_students
             WHERE parent_id = ? AND student_id = ? AND school_id = ?"
        );
        $childCheck->execute([$blockerStudentId, $targetId, $schoolId]);
        if ((int) $childCheck->fetchColumn() > 0) {
            return ['can_block' => false, 'reason' => 'You cannot block your child account.'];
        }
    } catch (\PDOException $e) {
        // parent_students table might not exist in older installs
    }

    return ['can_block' => true, 'reason' => ''];
}

/**
 * Block a student.
 */
function block_student(int $schoolId, int $blockerStudentId, int $blockedStudentId): bool
{
    $pdo = get_db();
    try {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO student_blocks (school_id, blocker_student_id, blocked_student_id)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$schoolId, $blockerStudentId, $blockedStudentId]);

        if (function_exists('audit_log')) {
            audit_log('student_blocked', [
                'description' => "Student {$blockerStudentId} blocked student {$blockedStudentId}",
                'entity_type' => 'student',
                'entity_id'   => $blockerStudentId,
            ]);
        }

        return true;
    } catch (\PDOException $e) {
        return false;
    }
}

/**
 * Unblock a student.
 */
function unblock_student(int $schoolId, int $blockerStudentId, int $blockedStudentId): bool
{
    $pdo = get_db();
    try {
        $stmt = $pdo->prepare(
            "DELETE FROM student_blocks
             WHERE school_id = ? AND blocker_student_id = ? AND blocked_student_id = ?"
        );
        $stmt->execute([$schoolId, $blockerStudentId, $blockedStudentId]);

        if (function_exists('audit_log')) {
            audit_log('student_unblocked', [
                'description' => "Student {$blockerStudentId} unblocked student {$blockedStudentId}",
                'entity_type' => 'student',
                'entity_id'   => $blockerStudentId,
            ]);
        }

        return true;
    } catch (\PDOException $e) {
        return false;
    }
}

/**
 * Get list of student IDs blocked by a given student.
 */
function get_blocked_student_ids(int $schoolId, int $studentId): array
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT blocked_student_id FROM student_blocks WHERE school_id = ? AND blocker_student_id = ?"
    );
    $stmt->execute([$schoolId, $studentId]);
    return array_column($stmt->fetchAll(), 'blocked_student_id');
}

// ─── Hide / Mark Read ────────────────────────────────────────────────────────

/**
 * Hide a conversation for a user.
 */
function hide_conversation(int $convId, int $schoolId, string $userType, int $userId): bool
{
    $pdo = get_db();
    try {
        $stmt = $pdo->prepare(
            "UPDATE conversation_participants
             SET hidden_at = NOW()
             WHERE conversation_id = ? AND participant_type = ? AND participant_id = ? AND school_id = ?"
        );
        $stmt->execute([$convId, $userType, $userId, $schoolId]);
        return $stmt->rowCount() > 0;
    } catch (\PDOException $e) {
        return false;
    }
}

/**
 * Mark a conversation as read for a user.
 */
function mark_conversation_read(int $convId, int $schoolId, string $userType, int $userId): bool
{
    $pdo = get_db();
    try {
        $stmt = $pdo->prepare(
            "UPDATE conversation_participants
             SET last_read_at = NOW()
             WHERE conversation_id = ? AND participant_type = ? AND participant_id = ? AND school_id = ?"
        );
        $stmt->execute([$convId, $userType, $userId, $schoolId]);
        return $stmt->rowCount() > 0;
    } catch (\PDOException $e) {
        return false;
    }
}

// ─── Contact Search ──────────────────────────────────────────────────────────

/**
 * Search for messageable contacts at the same school.
 * Returns students (excluding self and blocked) + staff/instructors/admins.
 */
function get_messageable_contacts(int $schoolId, int $studentId, string $search = ''): array
{
    $pdo = get_db();
    $contacts = [];

    // Get blocked student IDs to exclude
    $blockedIds = get_blocked_student_ids($schoolId, $studentId);

    // Also get IDs where someone else blocked this student
    $blockedByStmt = $pdo->prepare(
        "SELECT blocker_student_id FROM student_blocks WHERE school_id = ? AND blocked_student_id = ?"
    );
    $blockedByStmt->execute([$schoolId, $studentId]);
    $blockedByIds = array_column($blockedByStmt->fetchAll(), 'blocker_student_id');

    $allBlocked = array_unique(array_merge($blockedIds, $blockedByIds));

    // Search students
    $searchParam = '%' . $search . '%';
    $excludeIds = array_merge([$studentId], $allBlocked);
    $excludePlaceholders = implode(',', array_fill(0, count($excludeIds), '?'));

    $studentParams = array_merge([$schoolId], $excludeIds, [$searchParam, $searchParam]);
    $studentSql = "SELECT id, first_name, last_name, 'student' AS type, 'student' AS role
                   FROM students
                   WHERE school_id = ? AND status = 'active'
                     AND id NOT IN ({$excludePlaceholders})";

    if ($search !== '') {
        $studentSql .= " AND (CONCAT(first_name, ' ', last_name) LIKE ? OR email LIKE ?)";
    } else {
        // Remove the search params
        array_pop($studentParams);
        array_pop($studentParams);
    }

    $studentSql .= " ORDER BY first_name, last_name LIMIT 50";

    try {
        $stmt = $pdo->prepare($studentSql);
        $stmt->execute($studentParams);
        foreach ($stmt->fetchAll() as $s) {
            $contacts[] = [
                'id'   => (int) $s['id'],
                'name' => trim($s['first_name'] . ' ' . $s['last_name']),
                'type' => 'student',
                'role' => 'student',
                'blocked' => false,
            ];
        }
    } catch (\PDOException $e) {}

    // Search staff/instructors/admins
    $staffParams = [$schoolId];
    $staffSql = "SELECT id, full_name, role, 'admin' AS type
                 FROM users
                 WHERE school_id = ?";

    if ($search !== '') {
        $staffSql .= " AND (full_name LIKE ? OR email LIKE ?)";
        $staffParams[] = $searchParam;
        $staffParams[] = $searchParam;
    }

    $staffSql .= " ORDER BY full_name LIMIT 20";

    try {
        $stmt = $pdo->prepare($staffSql);
        $stmt->execute($staffParams);
        foreach ($stmt->fetchAll() as $a) {
            $contacts[] = [
                'id'   => (int) $a['id'],
                'name' => $a['full_name'],
                'type' => 'admin',
                'role' => $a['role'],
                'blocked' => false,
            ];
        }
    } catch (\PDOException $e) {}

    return $contacts;
}

// ─── Admin Contact Search ────────────────────────────────────────────────────

/**
 * Search for messageable contacts from the admin perspective.
 * Returns all active students + other staff/instructors/admins at the same school.
 */
function get_messageable_contacts_admin(int $schoolId, int $adminUserId, string $search = ''): array
{
    $pdo = get_db();
    $contacts = [];
    $searchParam = '%' . $search . '%';

    // Search students
    $studentParams = [$schoolId];
    $studentSql = "SELECT id, first_name, last_name, 'student' AS type, 'student' AS role
                   FROM students
                   WHERE school_id = ? AND status = 'active'";

    if ($search !== '') {
        $studentSql .= " AND (CONCAT(first_name, ' ', last_name) LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
        $studentParams[] = $searchParam;
        $studentParams[] = $searchParam;
        $studentParams[] = $searchParam;
        $studentParams[] = $searchParam;
    }

    $studentSql .= " ORDER BY first_name, last_name LIMIT 50";

    try {
        $stmt = $pdo->prepare($studentSql);
        $stmt->execute($studentParams);
        foreach ($stmt->fetchAll() as $s) {
            $contacts[] = [
                'id'   => (int) $s['id'],
                'name' => trim($s['first_name'] . ' ' . $s['last_name']),
                'type' => 'student',
                'role' => 'student',
            ];
        }
    } catch (\PDOException $e) {}

    // Search other staff/instructors/admins (exclude self)
    $staffParams = [$schoolId, $adminUserId];
    $staffSql = "SELECT id, full_name, role, 'admin' AS type
                 FROM users
                 WHERE school_id = ? AND id != ?";

    if ($search !== '') {
        $staffSql .= " AND (full_name LIKE ? OR email LIKE ?)";
        $staffParams[] = $searchParam;
        $staffParams[] = $searchParam;
    }

    $staffSql .= " ORDER BY full_name LIMIT 20";

    try {
        $stmt = $pdo->prepare($staffSql);
        $stmt->execute($staffParams);
        foreach ($stmt->fetchAll() as $a) {
            $contacts[] = [
                'id'   => (int) $a['id'],
                'name' => $a['full_name'],
                'type' => 'admin',
                'role' => $a['role'],
            ];
        }
    } catch (\PDOException $e) {}

    return $contacts;
}

// ─── Conversation Participant Verification ───────────────────────────────────

/**
 * Verify that a user is a participant in a conversation.
 */
function verify_conversation_participant(int $convId, int $schoolId, string $userType, int $userId): bool
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT id FROM conversation_participants
         WHERE conversation_id = ? AND participant_type = ? AND participant_id = ? AND school_id = ?"
    );
    $stmt->execute([$convId, $userType, $userId, $schoolId]);
    return (bool) $stmt->fetch();
}

/**
 * Get the other participant in a conversation.
 */
function get_other_participant(int $convId, int $schoolId, string $myType, int $myId): ?array
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT participant_type, participant_id FROM conversation_participants
         WHERE conversation_id = ? AND NOT (participant_type = ? AND participant_id = ?) AND school_id = ?
         LIMIT 1"
    );
    $stmt->execute([$convId, $myType, $myId, $schoolId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $type = $row['participant_type'];
    $id = (int) $row['participant_id'];

    if ($type === 'student') {
        $s = $pdo->prepare("SELECT first_name, last_name FROM students WHERE id = ?");
        $s->execute([$id]);
        $sr = $s->fetch();
        return [
            'type' => 'student',
            'id'   => $id,
            'name' => $sr ? trim($sr['first_name'] . ' ' . $sr['last_name']) : 'Student',
            'role' => 'student',
        ];
    } else {
        $a = $pdo->prepare("SELECT full_name, role FROM users WHERE id = ?");
        $a->execute([$id]);
        $ar = $a->fetch();
        return [
            'type' => 'admin',
            'id'   => $id,
            'name' => $ar ? $ar['full_name'] : 'Staff',
            'role' => $ar ? $ar['role'] : 'staff',
        ];
    }
}
