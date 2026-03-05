<?php
/**
 * api/routes/admin.php — Admin-facing API endpoints
 *
 * All endpoints require a valid admin access token via api_require_admin().
 *
 * GET    /admin/dashboard               — Dashboard statistics
 * GET    /admin/students                — Paginated student list
 * GET    /admin/students/{id}           — Single student detail
 * GET    /admin/classes                 — All active classes
 * GET    /admin/classes/{id}/students   — Enrolled students for a class
 * POST   /admin/attendance              — Mark attendance for a class
 * GET    /admin/events                  — All events
 * POST   /admin/events                  — Create event
 * PUT    /admin/events/{id}             — Update event
 * POST   /admin/messages                — Send a message
 * GET    /admin/messages                — Sent messages history
 * POST   /admin/device-token            — Register FCM device token
 * DELETE /admin/device-token            — Unregister FCM device token
 */

function route_admin(string $method, string $path): void
{
    $payload  = api_require_admin();
    $adminId  = (int) $payload['sub'];
    $schoolId = (int) $payload['school'];
    $pdo      = get_db();

    switch (true) {
        case $path === 'dashboard' && $method === 'GET':
            _admin_dashboard($pdo, $schoolId);
            break;

        case $path === 'students' && $method === 'GET':
            _admin_students($pdo, $schoolId);
            break;

        case preg_match('#^students/(\d+)$#', $path, $m) === 1 && $method === 'GET':
            _admin_student_detail($pdo, $schoolId, (int) $m[1]);
            break;

        case $path === 'classes' && $method === 'GET':
            _admin_classes($pdo, $schoolId);
            break;

        case preg_match('#^classes/(\d+)/students$#', $path, $m) === 1 && $method === 'GET':
            _admin_class_students($pdo, $schoolId, (int) $m[1]);
            break;

        case $path === 'attendance' && $method === 'POST':
            _admin_mark_attendance($pdo, $adminId, $schoolId);
            break;

        case $path === 'events' && $method === 'GET':
            _admin_events($pdo, $schoolId);
            break;

        case $path === 'events' && $method === 'POST':
            _admin_create_event($pdo, $adminId, $schoolId);
            break;

        case preg_match('#^events/(\d+)$#', $path, $m) === 1 && $method === 'PUT':
            _admin_update_event($pdo, $adminId, $schoolId, (int) $m[1]);
            break;

        case $path === 'messages' && $method === 'POST':
            _admin_send_message($pdo, $adminId, $schoolId);
            break;

        case $path === 'messages' && $method === 'GET':
            _admin_messages($pdo, $adminId, $schoolId);
            break;

        case $path === 'device-token' && $method === 'POST':
            // Reuse student device token functions (shared in student.php)
            require_once __DIR__ . '/student.php';
            _register_device_token($pdo, $adminId, 'admin', $schoolId);
            break;

        case $path === 'device-token' && $method === 'DELETE':
            require_once __DIR__ . '/student.php';
            _unregister_device_token($pdo, $adminId, 'admin');
            break;

        default:
            api_error('Admin endpoint not found', 404);
    }
}

// ---------- GET /admin/dashboard ----------

function _admin_dashboard(PDO $pdo, int $schoolId): void
{
    // Active students
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE status = 'active' AND school_id = ?");
    $stmt->execute([$schoolId]);
    $activeStudents = (int) $stmt->fetchColumn();

    // Active classes
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE status = 'active' AND school_id = ?");
    $stmt->execute([$schoolId]);
    $activeClasses = (int) $stmt->fetchColumn();

    // Today's attendance
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE() AND school_id = ?");
    $stmt->execute([$schoolId]);
    $todayAttendance = (int) $stmt->fetchColumn();

    // Monthly revenue
    $monthlyRevenue = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM payments
            WHERE MONTH(payment_date) = MONTH(CURDATE())
              AND YEAR(payment_date) = YEAR(CURDATE())
              AND school_id = ?
        ");
        $stmt->execute([$schoolId]);
        $monthlyRevenue = (float) $stmt->fetchColumn();
    } catch (PDOException $e) {}

    // Pending registrations
    $pendingRegistrations = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM students s
            JOIN memberships m ON s.id = m.student_id AND m.school_id = s.school_id
            WHERE s.status = 'inactive' AND m.status = 'cancelled' AND m.payment_status = 'pending'
              AND s.school_id = ?
        ");
        $stmt->execute([$schoolId]);
        $pendingRegistrations = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {}

    // Upcoming events (next 5)
    $stmt = $pdo->prepare("
        SELECT id, name, event_type, event_date, location, start_time, status
        FROM events WHERE event_date >= CURDATE() AND school_id = ?
        ORDER BY event_date ASC LIMIT 5
    ");
    $stmt->execute([$schoolId]);
    $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent students (last 5)
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, join_date, status, belt_rank, email
        FROM students WHERE school_id = ?
        ORDER BY join_date DESC LIMIT 5
    ");
    $stmt->execute([$schoolId]);
    $recentStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_json([
        'data' => [
            'active_students'      => $activeStudents,
            'active_classes'       => $activeClasses,
            'today_attendance'     => $todayAttendance,
            'monthly_revenue'      => $monthlyRevenue,
            'pending_registrations' => $pendingRegistrations,
            'upcoming_events'      => $upcomingEvents,
            'recent_students'      => $recentStudents,
        ],
    ]);
}

// ---------- GET /admin/students ----------

function _admin_students(PDO $pdo, int $schoolId): void
{
    $page    = max(1, (int) api_query('page', 1));
    $perPage = min(100, max(1, (int) api_query('per_page', 25)));
    $search  = trim(api_query('search', ''));
    $status  = trim(api_query('status', ''));
    $offset  = ($page - 1) * $perPage;

    $where  = "s.school_id = ?";
    $params = [$schoolId];

    if (!empty($search)) {
        $where .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }

    if (!empty($status) && in_array($status, ['active', 'inactive', 'suspended'])) {
        $where .= " AND s.status = ?";
        $params[] = $status;
    }

    // Count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT s.id, s.first_name, s.last_name, s.email, s.phone, s.belt_rank,
               s.status, s.activity_status, s.join_date, s.photo, s.is_parent
        FROM students s
        WHERE $where
        ORDER BY s.last_name ASC, s.first_name ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    api_paginated($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $page, $perPage);
}

// ---------- GET /admin/students/{id} ----------

function _admin_student_detail(PDO $pdo, int $schoolId, int $studentId): void
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

    // Get active membership
    $mStmt = $pdo->prepare("
        SELECT m.id, m.status, m.payment_status, m.start_date, m.end_date, m.auto_renew,
               mp.name AS plan_name, mp.price
        FROM memberships m
        JOIN membership_plans mp ON m.plan_id = mp.id
        WHERE m.student_id = ? AND m.school_id = ? AND m.status = 'active'
        ORDER BY m.end_date DESC LIMIT 1
    ");
    $mStmt->execute([$studentId, $schoolId]);
    $student['active_membership'] = $mStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Get enrolled classes count
    $cStmt = $pdo->prepare("
        SELECT COUNT(*) FROM class_enrollments ce
        JOIN classes c ON ce.class_id = c.id
        WHERE ce.student_id = ? AND c.school_id = ? AND c.status = 'active'
    ");
    $cStmt->execute([$studentId, $schoolId]);
    $student['enrolled_classes'] = (int) $cStmt->fetchColumn();

    // Attendance stats
    $aStmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present
        FROM attendance WHERE student_id = ? AND school_id = ?
    ");
    $aStmt->execute([$studentId, $schoolId]);
    $aStats = $aStmt->fetch(PDO::FETCH_ASSOC);
    $student['attendance_total']  = (int) ($aStats['total'] ?? 0);
    $student['attendance_rate']   = $student['attendance_total'] > 0
        ? round(((int) ($aStats['present'] ?? 0)) / $student['attendance_total'] * 100, 1)
        : 0;

    api_json(['data' => $student]);
}

// ---------- GET /admin/classes ----------

function _admin_classes(PDO $pdo, int $schoolId): void
{
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.day_of_week, c.start_time, c.end_time,
               c.max_students, c.skill_level, c.status,
               u.full_name AS instructor_name,
               r.name AS room_name,
               ms.name AS style_name,
               (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id = c.id) AS enrolled_count
        FROM classes c
        LEFT JOIN users u ON c.instructor_id = u.id
        LEFT JOIN rooms r ON c.room_id = r.id
        LEFT JOIN martial_arts_styles ms ON c.style_id = ms.id
        WHERE c.school_id = ? AND c.status = 'active'
        ORDER BY FIELD(c.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), c.start_time
    ");
    $stmt->execute([$schoolId]);

    api_json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ---------- GET /admin/classes/{id}/students ----------

function _admin_class_students(PDO $pdo, int $schoolId, int $classId): void
{
    // Verify class exists
    $check = $pdo->prepare("SELECT id, name FROM classes WHERE id = ? AND school_id = ? LIMIT 1");
    $check->execute([$classId, $schoolId]);
    $class = $check->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        api_error('Class not found', 404);
    }

    // Get enrolled students
    $stmt = $pdo->prepare("
        SELECT s.id, s.first_name, s.last_name, s.email, s.phone,
               s.belt_rank, s.status, s.photo
        FROM class_enrollments ce
        JOIN students s ON ce.student_id = s.id
        WHERE ce.class_id = ? AND s.school_id = ?
        ORDER BY s.last_name ASC, s.first_name ASC
    ");
    $stmt->execute([$classId, $schoolId]);

    // Optionally get attendance for a specific date
    $date = api_query('date');
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($date) {
        // Fetch existing attendance for this date
        $aStmt = $pdo->prepare("
            SELECT student_id, status, check_in_time
            FROM attendance
            WHERE class_id = ? AND attendance_date = ? AND school_id = ?
        ");
        $aStmt->execute([$classId, $date, $schoolId]);
        $attendanceMap = [];
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $attendanceMap[$a['student_id']] = $a;
        }

        foreach ($students as &$s) {
            $s['attendance_status']  = $attendanceMap[$s['id']]['status'] ?? null;
            $s['check_in_time']      = $attendanceMap[$s['id']]['check_in_time'] ?? null;
        }
        unset($s);
    }

    api_json([
        'data' => [
            'class' => $class,
            'students' => $students,
        ],
    ]);
}

// ---------- POST /admin/attendance ----------

function _admin_mark_attendance(PDO $pdo, int $adminId, int $schoolId): void
{
    $input = api_input();

    $classId = (int) ($input['class_id'] ?? 0);
    $date    = trim($input['date'] ?? '');
    $records = $input['records'] ?? [];

    if (empty($classId) || empty($date) || empty($records) || !is_array($records)) {
        api_error('class_id, date, and records array are required', 422);
    }

    // Verify class exists
    $check = $pdo->prepare("SELECT id FROM classes WHERE id = ? AND school_id = ? LIMIT 1");
    $check->execute([$classId, $schoolId]);
    if (!$check->fetch()) {
        api_error('Class not found', 404);
    }

    $updated = 0;
    $created = 0;

    foreach ($records as $record) {
        $studentId   = (int) ($record['student_id'] ?? 0);
        $status      = trim($record['status'] ?? 'present');
        $checkInTime = trim($record['check_in_time'] ?? '') ?: null;

        if (empty($studentId) || !in_array($status, ['present', 'absent', 'late', 'excused'])) {
            continue;
        }

        // Check if record exists
        $existing = $pdo->prepare("
            SELECT id FROM attendance
            WHERE student_id = ? AND class_id = ? AND attendance_date = ? AND school_id = ?
        ");
        $existing->execute([$studentId, $classId, $date, $schoolId]);

        if ($existing->fetch()) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE attendance SET status = ?, check_in_time = ?
                WHERE student_id = ? AND class_id = ? AND attendance_date = ? AND school_id = ?
            ");
            $stmt->execute([$status, $checkInTime, $studentId, $classId, $date, $schoolId]);
            $updated++;
        } else {
            // Insert
            $stmt = $pdo->prepare("
                INSERT INTO attendance (school_id, student_id, class_id, attendance_date, status, check_in_time)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$schoolId, $studentId, $classId, $date, $status, $checkInTime]);
            $created++;
        }
    }

    // Audit log
    if (function_exists('audit_log')) {
        audit_log('api_mark_attendance', [
            'description' => "Marked attendance for class $classId on $date: $created new, $updated updated",
            'entity_type' => 'class',
            'entity_id'   => $classId,
        ]);
    }

    api_json([
        'message' => 'Attendance recorded successfully',
        'created' => $created,
        'updated' => $updated,
    ]);
}

// ---------- GET /admin/events ----------

function _admin_events(PDO $pdo, int $schoolId): void
{
    $page    = max(1, (int) api_query('page', 1));
    $perPage = min(100, max(1, (int) api_query('per_page', 25)));
    $status  = trim(api_query('status', ''));
    $offset  = ($page - 1) * $perPage;

    $where  = "e.school_id = ?";
    $params = [$schoolId];

    if (!empty($status) && in_array($status, ['upcoming', 'ongoing', 'completed', 'cancelled'])) {
        $where .= " AND e.status = ?";
        $params[] = $status;
    }

    // Count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM events e WHERE $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT e.id, e.name, e.event_type, e.event_date, e.start_time, e.end_time,
               e.location, e.max_participants, e.registration_fee, e.registration_deadline,
               e.status, e.requires_registration,
               u.full_name AS instructor_name,
               (SELECT COUNT(*) FROM event_registrations er WHERE er.event_id = e.id AND er.school_id = e.school_id) AS registered_count
        FROM events e
        LEFT JOIN users u ON e.instructor_id = u.id
        WHERE $where
        ORDER BY e.event_date DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($events as &$ev) {
        $ev['registration_fee'] = (float) ($ev['registration_fee'] ?? 0);
        $ev['registered_count'] = (int) ($ev['registered_count'] ?? 0);
    }
    unset($ev);

    api_paginated($events, $total, $page, $perPage);
}

// ---------- POST /admin/events ----------

function _admin_create_event(PDO $pdo, int $adminId, int $schoolId): void
{
    $input = api_input();

    $required = ['name', 'event_date'];
    foreach ($required as $field) {
        if (empty(trim($input[$field] ?? ''))) {
            api_error("Field '$field' is required", 422);
        }
    }

    $validTypes = ['belt_test', 'tournament', 'seminar', 'workshop', 'demonstration', 'camp', 'other'];
    $eventType = in_array($input['event_type'] ?? '', $validTypes) ? $input['event_type'] : 'other';

    $stmt = $pdo->prepare("
        INSERT INTO events (school_id, name, event_type, event_date, start_time, end_time,
                           location, max_participants, registration_fee, registration_deadline,
                           requires_registration, status, instructor_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $schoolId,
        sanitizeInput($input['name']),
        $eventType,
        $input['event_date'],
        $input['start_time'] ?? null,
        $input['end_time'] ?? null,
        sanitizeInput($input['location'] ?? ''),
        (int) ($input['max_participants'] ?? 0),
        (float) ($input['registration_fee'] ?? 0),
        $input['registration_deadline'] ?? null,
        isset($input['requires_registration']) ? (int) $input['requires_registration'] : 1,
        'upcoming',
        $input['instructor_id'] ?? null,
    ]);

    $eventId = (int) $pdo->lastInsertId();

    if (function_exists('audit_log')) {
        audit_log('api_create_event', [
            'description' => 'Created event: ' . $input['name'],
            'entity_type' => 'event',
            'entity_id'   => $eventId,
        ]);
    }

    api_json(['message' => 'Event created successfully', 'event_id' => $eventId], 201);
}

// ---------- PUT /admin/events/{id} ----------

function _admin_update_event(PDO $pdo, int $adminId, int $schoolId, int $eventId): void
{
    // Verify event exists
    $check = $pdo->prepare("SELECT id FROM events WHERE id = ? AND school_id = ? LIMIT 1");
    $check->execute([$eventId, $schoolId]);
    if (!$check->fetch()) {
        api_error('Event not found', 404);
    }

    $input = api_input();
    $allowed = [
        'name', 'event_type', 'event_date', 'start_time', 'end_time',
        'location', 'max_participants', 'registration_fee', 'registration_deadline',
        'requires_registration', 'status', 'instructor_id',
    ];

    $updates = [];
    $params  = [];

    foreach ($allowed as $field) {
        if (array_key_exists($field, $input)) {
            $updates[] = "$field = ?";
            $value = $input[$field];
            if (in_array($field, ['name', 'location'])) {
                $value = sanitizeInput($value);
            } elseif (in_array($field, ['max_participants', 'requires_registration', 'instructor_id'])) {
                $value = $value !== null ? (int) $value : null;
            } elseif ($field === 'registration_fee') {
                $value = (float) $value;
            }
            $params[] = $value;
        }
    }

    if (empty($updates)) {
        api_error('No valid fields to update', 422);
    }

    $params[] = $eventId;
    $params[] = $schoolId;

    $sql = "UPDATE events SET " . implode(', ', $updates) . " WHERE id = ? AND school_id = ?";
    $pdo->prepare($sql)->execute($params);

    if (function_exists('audit_log')) {
        audit_log('api_update_event', [
            'description' => 'Updated event #' . $eventId,
            'entity_type' => 'event',
            'entity_id'   => $eventId,
        ]);
    }

    api_json(['message' => 'Event updated successfully']);
}

// ---------- POST /admin/messages ----------

function _admin_send_message(PDO $pdo, int $adminId, int $schoolId): void
{
    $input = api_input();

    $subject  = trim($input['subject'] ?? '');
    $body     = trim($input['body'] ?? '');
    $priority = in_array($input['priority'] ?? '', ['normal', 'high', 'urgent']) ? $input['priority'] : 'normal';

    if (empty($subject) || empty($body)) {
        api_error('Subject and body are required', 422);
    }

    // Audience type
    $audienceType = $input['audience_type'] ?? 'all_students';
    $validAudiences = ['all', 'all_students', 'all_staff', 'custom'];
    if (!in_array($audienceType, $validAudiences)) {
        $audienceType = 'all_students';
    }

    $audienceFilters = $input['audience_filters'] ?? [];

    // Channels
    $channelEmail = !empty($input['channel_email']) ? 1 : 0;
    $channelSms   = !empty($input['channel_sms']) ? 1 : 0;
    $channelInapp = 1; // Always send in-app

    // Insert message
    $stmt = $pdo->prepare("
        INSERT INTO messages (school_id, sender_id, sender_type, subject, body, priority,
                             audience_type, audience_filters, channel_email, channel_sms, channel_inapp, status)
        VALUES (?, ?, 'admin', ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
    ");
    $stmt->execute([
        $schoolId, $adminId, $subject, $body, $priority,
        $audienceType, json_encode($audienceFilters),
        $channelEmail, $channelSms, $channelInapp,
    ]);

    $messageId = (int) $pdo->lastInsertId();

    // Dispatch the message using existing function
    $stats = ['total' => 0, 'emails_sent' => 0, 'sms_sent' => 0];
    if (function_exists('dispatch_message')) {
        $stats = dispatch_message($messageId);
    }

    // Send push notifications for in-app messages
    if (file_exists(__DIR__ . '/../fcm.php')) {
        require_once __DIR__ . '/../fcm.php';
        if (function_exists('send_push_to_all') && in_array($audienceType, ['all', 'all_students'])) {
            send_push_to_all('student', $subject, mb_substr(strip_tags($body), 0, 200), [
                'type'       => 'message',
                'message_id' => (string) $messageId,
            ]);
        }
    }

    if (function_exists('audit_log')) {
        audit_log('api_send_message', [
            'description' => 'Sent message: ' . $subject,
            'entity_type' => 'message',
            'entity_id'   => $messageId,
        ]);
    }

    api_json([
        'message'    => 'Message sent successfully',
        'message_id' => $messageId,
        'stats'      => $stats,
    ], 201);
}

// ---------- GET /admin/messages ----------

function _admin_messages(PDO $pdo, int $adminId, int $schoolId): void
{
    $page    = max(1, (int) api_query('page', 1));
    $perPage = min(100, max(1, (int) api_query('per_page', 25)));
    $offset  = ($page - 1) * $perPage;

    // Count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ? AND school_id = ?");
    $countStmt->execute([$adminId, $schoolId]);
    $total = (int) $countStmt->fetchColumn();

    // Fetch
    $stmt = $pdo->prepare("
        SELECT id, subject, body, priority, audience_type, status,
               channel_email, channel_sms, channel_inapp,
               total_recipients, emails_sent, sms_sent,
               created_at
        FROM messages
        WHERE sender_id = ? AND school_id = ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$adminId, $schoolId, $perPage, $offset]);

    api_paginated($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $page, $perPage);
}
