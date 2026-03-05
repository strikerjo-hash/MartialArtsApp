<?php
/**
 * api/routes/student.php — Student-facing API endpoints
 *
 * All endpoints require a valid student access token via api_require_student().
 *
 * GET  /student/profile       — Student profile
 * PUT  /student/profile       — Update profile
 * GET  /student/classes       — Enrolled classes with schedule
 * GET  /student/attendance    — Attendance history (paginated)
 * GET  /student/memberships   — Active/recent memberships
 * GET  /student/events        — Upcoming events + registration status
 * POST /student/events/{id}/register — Register for an event
 * GET  /student/payments      — Payment history (paginated)
 * GET  /student/messages      — Message inbox (broadcast)
 * GET  /student/belts         — Belt progress history
 * POST /student/messages/{id}/read  — Mark message as read
 * POST /student/device-token  — Register FCM device token
 * DELETE /student/device-token — Unregister FCM device token
 *
 * ── Conversation Messaging ──
 * GET    /student/conversations               — List conversations
 * GET    /student/conversations/{id}/messages  — Get conversation messages
 * POST   /student/conversations               — Create/find conversation
 * POST   /student/conversations/{id}/messages  — Send a message
 * POST   /student/conversations/{id}/read      — Mark conversation as read
 * POST   /student/conversations/{id}/hide      — Hide conversation
 * GET    /student/contacts                     — Search messageable contacts
 * GET    /student/blocks                       — List blocked students
 * POST   /student/blocks                       — Block a student
 * DELETE /student/blocks/{id}                  — Unblock a student
 */

function route_student(string $method, string $path): void
{
    $payload   = api_require_student();
    $studentId = (int) $payload['sub'];
    $schoolId  = (int) $payload['school'];
    $pdo       = get_db();

    // Simple routing
    switch (true) {
        case $path === 'profile' && $method === 'GET':
            _student_profile($pdo, $studentId, $schoolId);
            break;

        case $path === 'profile' && $method === 'PUT':
            _student_update_profile($pdo, $studentId, $schoolId);
            break;

        case $path === 'classes' && $method === 'GET':
            _student_classes($pdo, $studentId, $schoolId);
            break;

        case $path === 'attendance' && $method === 'GET':
            _student_attendance($pdo, $studentId, $schoolId);
            break;

        case $path === 'memberships' && $method === 'GET':
            _student_memberships($pdo, $studentId, $schoolId);
            break;

        case $path === 'events' && $method === 'GET':
            _student_events($pdo, $studentId, $schoolId);
            break;

        case preg_match('#^events/(\d+)/register$#', $path, $m) === 1 && $method === 'POST':
            _student_event_register($pdo, $studentId, $schoolId, (int) $m[1]);
            break;

        case $path === 'payments' && $method === 'GET':
            _student_payments($pdo, $studentId, $schoolId);
            break;

        case $path === 'messages' && $method === 'GET':
            _student_messages($pdo, $studentId, $schoolId);
            break;

        case $path === 'belts' && $method === 'GET':
            _student_belts($pdo, $studentId, $schoolId);
            break;

        case preg_match('#^messages/(\d+)/read$#', $path, $m) === 1 && $method === 'POST':
            _student_message_read($pdo, $studentId, $schoolId, (int) $m[1]);
            break;

        case $path === 'device-token' && $method === 'POST':
            _register_device_token($pdo, $studentId, 'student', $schoolId);
            break;

        case $path === 'device-token' && $method === 'DELETE':
            _unregister_device_token($pdo, $studentId, 'student');
            break;

        // ── Conversation Messaging Endpoints ──
        case $path === 'conversations' && $method === 'GET':
            _student_conversations_list($pdo, $studentId, $schoolId);
            break;

        case preg_match('#^conversations/(\d+)/messages$#', $path, $m) === 1 && $method === 'GET':
            _student_conversation_messages($pdo, $studentId, $schoolId, (int) $m[1]);
            break;

        case $path === 'conversations' && $method === 'POST':
            _student_conversation_create($pdo, $studentId, $schoolId);
            break;

        case preg_match('#^conversations/(\d+)/messages$#', $path, $m) === 1 && $method === 'POST':
            _student_conversation_send($pdo, $studentId, $schoolId, (int) $m[1]);
            break;

        case preg_match('#^conversations/(\d+)/read$#', $path, $m) === 1 && $method === 'POST':
            _student_conversation_mark_read($pdo, $studentId, $schoolId, (int) $m[1]);
            break;

        case preg_match('#^conversations/(\d+)/hide$#', $path, $m) === 1 && $method === 'POST':
            _student_conversation_hide($pdo, $studentId, $schoolId, (int) $m[1]);
            break;

        case $path === 'contacts' && $method === 'GET':
            _student_contacts($pdo, $studentId, $schoolId);
            break;

        case $path === 'blocks' && $method === 'GET':
            _student_blocks_list($pdo, $studentId, $schoolId);
            break;

        case $path === 'blocks' && $method === 'POST':
            _student_block_create($pdo, $studentId, $schoolId);
            break;

        case preg_match('#^blocks/(\d+)$#', $path, $m) === 1 && $method === 'DELETE':
            _student_block_delete($pdo, $studentId, $schoolId, (int) $m[1]);
            break;

        default:
            api_error('Student endpoint not found', 404);
    }
}

// ---------- GET /student/profile ----------

function _student_profile(PDO $pdo, int $studentId, int $schoolId): void
{
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, username, phone, date_of_birth, address,
               emergency_contact_name, emergency_contact_phone, join_date, status,
               activity_status, belt_rank, photo, is_parent, account_credit
        FROM students WHERE id = ? AND school_id = ? LIMIT 1
    ");
    $stmt->execute([$studentId, $schoolId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        api_error('Student not found', 404);
    }

    $student['account_credit'] = (float) ($student['account_credit'] ?? 0);
    $student['is_parent']      = (int) ($student['is_parent'] ?? 0);

    api_json(['data' => $student]);
}

// ---------- PUT /student/profile ----------

function _student_update_profile(PDO $pdo, int $studentId, int $schoolId): void
{
    $input = api_input();

    // Only allow updating safe fields
    $allowed = ['phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone'];
    $updates = [];
    $params  = [];

    foreach ($allowed as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            $params[]  = sanitizeInput($input[$field]);
        }
    }

    if (empty($updates)) {
        api_error('No valid fields to update', 422);
    }

    // If email is changing, check uniqueness
    if (isset($input['email'])) {
        $check = $pdo->prepare("SELECT id FROM students WHERE email = ? AND school_id = ? AND id != ? LIMIT 1");
        $check->execute([sanitizeInput($input['email']), $schoolId, $studentId]);
        if ($check->fetch()) {
            api_error('Email address is already in use', 409);
        }
    }

    $params[] = $studentId;
    $params[] = $schoolId;

    $sql = "UPDATE students SET " . implode(', ', $updates) . " WHERE id = ? AND school_id = ?";
    $pdo->prepare($sql)->execute($params);

    // Audit log
    if (function_exists('audit_log')) {
        audit_log('api_update_profile', [
            'entity_type' => 'student',
            'entity_id'   => $studentId,
            'new_values'  => array_intersect_key($input, array_flip($allowed)),
        ]);
    }

    api_json(['message' => 'Profile updated successfully']);
}

// ---------- GET /student/classes ----------

function _student_classes(PDO $pdo, int $studentId, int $schoolId): void
{
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.day_of_week, c.start_time, c.end_time, c.max_students,
               c.skill_level, c.description,
               u.full_name AS instructor_name,
               r.name AS room_name,
               ms.name AS style_name
        FROM class_enrollments ce
        JOIN classes c ON ce.class_id = c.id
        LEFT JOIN users u ON c.instructor_id = u.id
        LEFT JOIN rooms r ON c.room_id = r.id
        LEFT JOIN martial_arts_styles ms ON c.style_id = ms.id
        WHERE ce.student_id = ? AND c.school_id = ? AND c.status = 'active'
        ORDER BY FIELD(c.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), c.start_time
    ");
    $stmt->execute([$studentId, $schoolId]);

    api_json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ---------- GET /student/attendance ----------

function _student_attendance(PDO $pdo, int $studentId, int $schoolId): void
{
    $page    = max(1, (int) api_query('page', 1));
    $perPage = min(100, max(1, (int) api_query('per_page', 50)));
    $from    = api_query('from'); // YYYY-MM-DD
    $to      = api_query('to');   // YYYY-MM-DD
    $offset  = ($page - 1) * $perPage;

    $where  = "a.student_id = ? AND a.school_id = ?";
    $params = [$studentId, $schoolId];

    if ($from) { $where .= " AND a.date >= ?"; $params[] = $from; }
    if ($to)   { $where .= " AND a.date <= ?"; $params[] = $to;   }

    // Count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a WHERE $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT a.id, a.class_id, a.date, a.status, a.notes, a.check_in_time,
               c.name AS class_name
        FROM attendance a
        LEFT JOIN classes c ON a.class_id = c.id
        WHERE $where
        ORDER BY a.date DESC, a.check_in_time DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    api_paginated($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $page, $perPage);
}

// ---------- GET /student/memberships ----------

function _student_memberships(PDO $pdo, int $studentId, int $schoolId): void
{
    $stmt = $pdo->prepare("
        SELECT m.id, m.plan_id, m.start_date, m.end_date, m.status, m.payment_status,
               m.amount_paid, m.auto_renew, m.billing_day, m.monthly_charges_made,
               mp.name AS plan_name, mp.price AS plan_price, mp.duration_months,
               mp.classes_per_week, mp.billing_frequency, mp.registration_fee,
               mp.is_afterschool, mp.is_grandfathered
        FROM memberships m
        JOIN membership_plans mp ON m.plan_id = mp.id
        WHERE m.student_id = ? AND m.school_id = ?
        ORDER BY m.start_date DESC
    ");
    $stmt->execute([$studentId, $schoolId]);

    $memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast numeric fields
    foreach ($memberships as &$m) {
        $m['amount_paid']          = (float) $m['amount_paid'];
        $m['plan_price']           = (float) $m['plan_price'];
        $m['registration_fee']     = (float) ($m['registration_fee'] ?? 0);
        $m['auto_renew']           = (int) $m['auto_renew'];
        $m['classes_per_week']     = (int) $m['classes_per_week'];
        $m['duration_months']      = (int) $m['duration_months'];
        $m['monthly_charges_made'] = (int) ($m['monthly_charges_made'] ?? 0);
        $m['is_afterschool']       = (int) ($m['is_afterschool'] ?? 0);
        $m['is_grandfathered']     = (int) ($m['is_grandfathered'] ?? 0);
    }
    unset($m);

    api_json(['data' => $memberships]);
}

// ---------- GET /student/events ----------

function _student_events(PDO $pdo, int $studentId, int $schoolId): void
{
    $stmt = $pdo->prepare("
        SELECT e.id, e.name, e.event_type, e.event_date, e.start_time, e.end_time,
               e.location, e.max_participants, e.registration_fee, e.registration_deadline,
               e.status, e.requires_registration,
               u.full_name AS instructor_name,
               er.id AS registration_id, er.payment_status AS reg_payment_status
        FROM events e
        LEFT JOIN users u ON e.instructor_id = u.id
        LEFT JOIN event_registrations er ON er.event_id = e.id AND er.student_id = ?
        WHERE e.school_id = ? AND e.status IN ('upcoming', 'ongoing') AND e.event_date >= CURDATE()
        ORDER BY e.event_date ASC, e.start_time ASC
    ");
    $stmt->execute([$studentId, $schoolId]);

    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($events as &$ev) {
        $ev['registration_fee'] = (float) ($ev['registration_fee'] ?? 0);
        $ev['is_registered']    = !empty($ev['registration_id']);
        unset($ev['registration_id']);
    }
    unset($ev);

    api_json(['data' => $events]);
}

// ---------- POST /student/events/{id}/register ----------

function _student_event_register(PDO $pdo, int $studentId, int $schoolId, int $eventId): void
{
    // Verify event exists and is open
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ? AND school_id = ? AND status = 'upcoming' LIMIT 1");
    $stmt->execute([$eventId, $schoolId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        api_error('Event not found or not open for registration', 404);
    }

    // Check registration deadline
    if (!empty($event['registration_deadline']) && date('Y-m-d') > $event['registration_deadline']) {
        api_error('Registration deadline has passed', 422);
    }

    // Check max participants
    if ($event['max_participants'] > 0) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND school_id = ?");
        $countStmt->execute([$eventId, $schoolId]);
        if ((int) $countStmt->fetchColumn() >= (int) $event['max_participants']) {
            api_error('Event is full', 422);
        }
    }

    // Check if already registered
    $checkStmt = $pdo->prepare("SELECT id FROM event_registrations WHERE event_id = ? AND student_id = ? AND school_id = ? LIMIT 1");
    $checkStmt->execute([$eventId, $studentId, $schoolId]);
    if ($checkStmt->fetch()) {
        api_error('Already registered for this event', 409);
    }

    // Register
    $paymentStatus = ($event['registration_fee'] > 0) ? 'pending' : 'waived';
    $stmt = $pdo->prepare("INSERT INTO event_registrations (school_id, event_id, student_id, registration_date, payment_status) VALUES (?, ?, ?, CURDATE(), ?)");
    $stmt->execute([$schoolId, $eventId, $studentId, $paymentStatus]);

    if (function_exists('audit_log')) {
        audit_log('api_event_register', [
            'entity_type' => 'event',
            'entity_id'   => $eventId,
            'description' => "Student $studentId registered for event $eventId",
        ]);
    }

    api_json(['message' => 'Successfully registered for event', 'payment_status' => $paymentStatus], 201);
}

// ---------- GET /student/payments ----------

function _student_payments(PDO $pdo, int $studentId, int $schoolId): void
{
    $page    = max(1, (int) api_query('page', 1));
    $perPage = min(100, max(1, (int) api_query('per_page', 50)));
    $offset  = ($page - 1) * $perPage;

    // Count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE student_id = ? AND school_id = ?");
    $countStmt->execute([$studentId, $schoolId]);
    $total = (int) $countStmt->fetchColumn();

    // Fetch
    $stmt = $pdo->prepare("
        SELECT id, payment_type, reference_id, amount, payment_method, payment_date,
               receipt_number, notes, created_at
        FROM payments
        WHERE student_id = ? AND school_id = ?
        ORDER BY payment_date DESC, created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$studentId, $schoolId, $perPage, $offset]);

    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($payments as &$p) {
        $p['amount'] = (float) $p['amount'];
    }
    unset($p);

    api_paginated($payments, $total, $page, $perPage);
}

// ---------- GET /student/messages ----------

function _student_messages(PDO $pdo, int $studentId, int $schoolId): void
{
    $page    = max(1, (int) api_query('page', 1));
    $perPage = min(100, max(1, (int) api_query('per_page', 50)));
    $unread  = api_query('unread');
    $offset  = ($page - 1) * $perPage;

    $where  = "mr.recipient_id = ? AND mr.recipient_type = 'student' AND m.school_id = ?";
    $params = [$studentId, $schoolId];

    if ($unread === '1') {
        $where .= " AND mr.read_at IS NULL";
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM message_recipients mr JOIN messages m ON mr.message_id = m.id WHERE $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT m.id, m.subject, m.body, m.sender_type, m.priority, m.created_at,
               mr.read_at
        FROM message_recipients mr
        JOIN messages m ON mr.message_id = m.id
        WHERE $where
        ORDER BY m.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    api_paginated($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $page, $perPage);
}

// ---------- GET /student/belts ----------

function _student_belts(PDO $pdo, int $studentId, int $schoolId): void
{
    $stmt = $pdo->prepare("
        SELECT sb.id, sb.awarded_date, sb.notes,
               b.name AS belt_name, b.color AS belt_color, b.rank_order,
               ms.name AS style_name,
               u.full_name AS awarded_by
        FROM student_belts sb
        JOIN belts b ON sb.belt_id = b.id
        LEFT JOIN martial_arts_styles ms ON sb.style_id = ms.id
        LEFT JOIN users u ON sb.instructor_id = u.id
        WHERE sb.student_id = ? AND sb.school_id = ?
        ORDER BY b.rank_order ASC, sb.awarded_date DESC
    ");
    $stmt->execute([$studentId, $schoolId]);

    api_json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ---------- POST /student/messages/{id}/read ----------

function _student_message_read(PDO $pdo, int $studentId, int $schoolId, int $messageId): void
{
    $stmt = $pdo->prepare("
        UPDATE message_recipients
        SET inapp_status = 'read', read_at = NOW()
        WHERE message_id = ? AND recipient_type = 'student' AND recipient_id = ?
          AND inapp_status = 'delivered' AND school_id = ?
    ");
    $stmt->execute([$messageId, $studentId, $schoolId]);

    api_json(['message' => 'Message marked as read', 'updated' => $stmt->rowCount()]);
}

// ---------- POST /student/device-token ----------

function _register_device_token(PDO $pdo, int $userId, string $userType, int $schoolId): void
{
    $input = api_input();
    $fcmToken = trim($input['fcm_token'] ?? '');
    $platform = trim($input['platform'] ?? 'android');
    $deviceInfo = trim($input['device_info'] ?? '');

    if (empty($fcmToken)) {
        api_error('FCM token is required', 422);
    }

    if (!in_array($platform, ['android', 'ios', 'web'])) {
        $platform = 'android';
    }

    // Upsert: update if token exists, insert if new
    $stmt = $pdo->prepare("
        INSERT INTO device_tokens (school_id, user_id, user_type, fcm_token, device_platform, device_info, is_active)
        VALUES (?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            user_type = VALUES(user_type),
            school_id = VALUES(school_id),
            device_platform = VALUES(device_platform),
            device_info = VALUES(device_info),
            is_active = 1,
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$schoolId, $userId, $userType, $fcmToken, $platform, $deviceInfo]);

    api_json(['message' => 'Device token registered successfully']);
}

// ---------- DELETE /student/device-token ----------

function _unregister_device_token(PDO $pdo, int $userId, string $userType): void
{
    $input = api_input();
    $fcmToken = trim($input['fcm_token'] ?? '');

    if (empty($fcmToken)) {
        api_error('FCM token is required', 422);
    }

    $stmt = $pdo->prepare("DELETE FROM device_tokens WHERE fcm_token = ? AND user_id = ? AND user_type = ?");
    $stmt->execute([$fcmToken, $userId, $userType]);

    api_json(['message' => 'Device token unregistered']);
}

// ==========================================================================
// Conversation Messaging Endpoints
// ==========================================================================

// ---------- GET /student/conversations ----------

function _student_conversations_list(PDO $pdo, int $studentId, int $schoolId): void
{
    require_once __DIR__ . '/../../includes/conversation_helpers.php';
    $conversations = get_conversations_for_user($schoolId, 'student', $studentId);
    api_json(['data' => $conversations]);
}

// ---------- GET /student/conversations/{id}/messages ----------

function _student_conversation_messages(PDO $pdo, int $studentId, int $schoolId, int $convId): void
{
    require_once __DIR__ . '/../../includes/conversation_helpers.php';

    if (!verify_conversation_participant($convId, $schoolId, 'student', $studentId)) {
        api_error('Conversation not found', 404);
    }

    mark_conversation_read($convId, $schoolId, 'student', $studentId);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $data = get_conversation_messages($convId, $schoolId, $page);
    $other = get_other_participant($convId, $schoolId, 'student', $studentId);

    $isBlocked = false;
    if ($other && $other['type'] === 'student') {
        $isBlocked = is_blocked($schoolId, $studentId, $other['id']);
    }

    api_json([
        'data'       => $data['messages'],
        'has_more'   => $data['has_more'],
        'other'      => $other,
        'is_blocked' => $isBlocked,
        'can_send'   => !$isBlocked,
    ]);
}

// ---------- POST /student/conversations ----------

function _student_conversation_create(PDO $pdo, int $studentId, int $schoolId): void
{
    require_once __DIR__ . '/../../includes/conversation_helpers.php';

    $input = api_input();
    $targetType = $input['target_type'] ?? '';
    $targetId   = (int) ($input['target_id'] ?? 0);

    if (!in_array($targetType, ['student', 'admin']) || !$targetId) {
        api_error('Invalid recipient', 422);
    }

    if ($targetType === 'student' && $targetId === $studentId) {
        api_error('You cannot message yourself', 422);
    }

    if ($targetType === 'student' && is_blocked($schoolId, $studentId, $targetId)) {
        api_error('This user is not available for messaging', 403);
    }

    $convId = find_or_create_conversation($schoolId, 'student', $studentId, $targetType, $targetId);

    // Optionally send a message
    $body = trim($input['body'] ?? '');
    if ($body !== '') {
        $result = send_direct_message($schoolId, $convId, 'student', $studentId, $body);
        api_json(array_merge($result, ['conversation_id' => $convId]), $result['success'] ? 201 : 422);
        return;
    }

    api_json(['conversation_id' => $convId], 201);
}

// ---------- POST /student/conversations/{id}/messages ----------

function _student_conversation_send(PDO $pdo, int $studentId, int $schoolId, int $convId): void
{
    require_once __DIR__ . '/../../includes/conversation_helpers.php';

    $input = api_input();
    $body  = $input['body'] ?? '';

    $result = send_direct_message($schoolId, $convId, 'student', $studentId, $body);

    if (!$result['success']) {
        api_error($result['error'] ?? 'Failed to send message', 422);
    }

    api_json($result, 201);
}

// ---------- POST /student/conversations/{id}/read ----------

function _student_conversation_mark_read(PDO $pdo, int $studentId, int $schoolId, int $convId): void
{
    require_once __DIR__ . '/../../includes/conversation_helpers.php';
    mark_conversation_read($convId, $schoolId, 'student', $studentId);
    api_json(['message' => 'Conversation marked as read']);
}

// ---------- POST /student/conversations/{id}/hide ----------

function _student_conversation_hide(PDO $pdo, int $studentId, int $schoolId, int $convId): void
{
    require_once __DIR__ . '/../../includes/conversation_helpers.php';
    hide_conversation($convId, $schoolId, 'student', $studentId);
    api_json(['message' => 'Conversation hidden']);
}

// ---------- GET /student/contacts ----------

function _student_contacts(PDO $pdo, int $studentId, int $schoolId): void
{
    require_once __DIR__ . '/../../includes/conversation_helpers.php';
    $search = trim($_GET['q'] ?? '');
    $contacts = get_messageable_contacts($schoolId, $studentId, $search);
    api_json(['data' => $contacts]);
}

// ---------- GET /student/blocks ----------

function _student_blocks_list(PDO $pdo, int $studentId, int $schoolId): void
{
    require_once __DIR__ . '/../../includes/conversation_helpers.php';
    $blockedIds = get_blocked_student_ids($schoolId, $studentId);

    $blocks = [];
    if (!empty($blockedIds)) {
        $ph = implode(',', array_fill(0, count($blockedIds), '?'));
        $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE id IN ({$ph})");
        $stmt->execute($blockedIds);
        foreach ($stmt->fetchAll() as $s) {
            $blocks[] = [
                'id'   => (int) $s['id'],
                'name' => trim($s['first_name'] . ' ' . $s['last_name']),
            ];
        }
    }

    api_json(['data' => $blocks]);
}

// ---------- POST /student/blocks ----------

function _student_block_create(PDO $pdo, int $studentId, int $schoolId): void
{
    require_once __DIR__ . '/../../includes/conversation_helpers.php';

    $input = api_input();
    $targetId = (int) ($input['target_id'] ?? 0);

    if (!$targetId) {
        api_error('Target ID is required', 422);
    }

    $check = can_block_user($schoolId, $studentId, 'student', $targetId);
    if (!$check['can_block']) {
        api_error($check['reason'], 403);
    }

    $ok = block_student($schoolId, $studentId, $targetId);
    api_json(['success' => $ok], $ok ? 201 : 500);
}

// ---------- DELETE /student/blocks/{id} ----------

function _student_block_delete(PDO $pdo, int $studentId, int $schoolId, int $blockedId): void
{
    require_once __DIR__ . '/../../includes/conversation_helpers.php';
    $ok = unblock_student($schoolId, $studentId, $blockedId);
    api_json(['success' => $ok]);
}
