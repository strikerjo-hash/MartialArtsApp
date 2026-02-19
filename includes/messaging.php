<?php
/**
 * includes/messaging.php — Messaging & Communication Backend
 *
 * Provides:
 *   - Idempotent database migrations (messages, message_recipients, users.phone)
 *   - Audience/recipient resolution with flexible filters
 *   - SMTP email sending via PHP mail() with ini_set
 *   - Twilio SMS sending via cURL
 *   - Message dispatch orchestration across all channels
 *   - Unread message count helpers
 */

require_once __DIR__ . '/../config.php';

// ---------------------------------------------------------------------------
// Database Migrations
// ---------------------------------------------------------------------------

function ensure_messaging_tables(): void
{
    $pdo = get_db();

    // 1. messages table — stores each composed message
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            sender_id INT NOT NULL COMMENT 'users.id of the admin/instructor who sent it',
            channel_email TINYINT(1) NOT NULL DEFAULT 0,
            channel_sms TINYINT(1) NOT NULL DEFAULT 0,
            channel_inapp TINYINT(1) NOT NULL DEFAULT 1,
            audience_type ENUM('all','all_students','all_staff','custom') NOT NULL DEFAULT 'all_students',
            audience_filters TEXT DEFAULT NULL COMMENT 'JSON blob of filter criteria',
            total_recipients INT NOT NULL DEFAULT 0,
            emails_sent INT NOT NULL DEFAULT 0,
            emails_failed INT NOT NULL DEFAULT 0,
            sms_sent INT NOT NULL DEFAULT 0,
            sms_failed INT NOT NULL DEFAULT 0,
            status ENUM('draft','sending','sent','failed') NOT NULL DEFAULT 'draft',
            sent_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_msg_sender (sender_id),
            INDEX idx_msg_status (status),
            INDEX idx_msg_sent (sent_at)
        )");
    } catch (\PDOException $e) {}

    // 2. message_recipients table — per-recipient delivery tracking
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS message_recipients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            recipient_type ENUM('student','user') NOT NULL,
            recipient_id INT NOT NULL COMMENT 'students.id or users.id',
            email_status ENUM('pending','sent','failed','skipped') DEFAULT 'skipped',
            sms_status ENUM('pending','sent','failed','skipped') DEFAULT 'skipped',
            inapp_status ENUM('delivered','read') DEFAULT 'delivered',
            read_at DATETIME DEFAULT NULL,
            email_error TEXT DEFAULT NULL,
            sms_error TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mr_message (message_id),
            INDEX idx_mr_recipient (recipient_type, recipient_id),
            INDEX idx_mr_unread (recipient_type, recipient_id, inapp_status),
            UNIQUE KEY unique_msg_recipient (message_id, recipient_type, recipient_id)
        )");
    } catch (\PDOException $e) {}

    // 3. Add phone column to users table (for SMS to staff/instructors)
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER email");
    } catch (\PDOException $e) {}

    // 4. Default role_permissions for messages.php
    try {
        $pdo->exec("INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete) VALUES
            ('admin', 'messages.php', 1, 1, 1, 1),
            ('instructor', 'messages.php', 1, 1, 0, 0),
            ('staff', 'messages.php', 1, 0, 0, 0)
        ");
    } catch (\PDOException $e) {}
}

// Run migrations on include
ensure_messaging_tables();

// ---------------------------------------------------------------------------
// Phone Number Normalization
// ---------------------------------------------------------------------------

/**
 * Normalize a phone number to E.164 format for Twilio.
 * Strips non-digits, prepends +1 if no country code.
 */
function normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === '') return '';

    // If it starts with 1 and is 11 digits, assume US +1
    if (strlen($digits) === 11 && $digits[0] === '1') {
        return '+' . $digits;
    }
    // If 10 digits, assume US and prepend +1
    if (strlen($digits) === 10) {
        return '+1' . $digits;
    }
    // Already has country code or international
    if (strlen($digits) > 10) {
        return '+' . $digits;
    }

    // Too short — return as-is with + prefix
    return '+' . $digits;
}

// ---------------------------------------------------------------------------
// Audience / Recipient Resolution
// ---------------------------------------------------------------------------

/**
 * Build a list of recipients based on audience filters.
 *
 * @param array $filters [
 *   'audience_type'  => 'all' | 'all_students' | 'all_staff' | 'custom',
 *   'student_status' => 'active' | 'inactive' | '' (any),
 *   'plan_id'        => int|null,
 *   'event_id'       => int|null,
 *   'user_roles'     => ['admin','instructor','staff'] or [],
 * ]
 * @return array ['students' => [...rows...], 'users' => [...rows...]]
 */
function get_recipients_by_filter(array $filters): array
{
    $pdo = get_db();
    $students = [];
    $users    = [];
    $audienceType = $filters['audience_type'] ?? 'all_students';

    // ── Fetch Students ──
    $includeStudents = in_array($audienceType, ['all', 'all_students', 'custom'], true);
    if ($includeStudents) {
        $sql    = "SELECT DISTINCT s.id, s.first_name, s.last_name, s.email, s.phone FROM students s WHERE 1=1";
        $params = [];

        // Student status filter
        $status = $filters['student_status'] ?? '';
        if ($status === 'active') {
            $sql .= " AND (s.status = 'active' OR s.is_active = 1)";
        } elseif ($status === 'inactive') {
            $sql .= " AND (s.status = 'inactive' OR s.is_active = 0)";
        }

        // Membership plan filter
        if (!empty($filters['plan_id'])) {
            $sql .= " AND s.id IN (SELECT m.student_id FROM memberships m WHERE m.plan_id = :plan_id AND m.status = 'active')";
            $params[':plan_id'] = (int) $filters['plan_id'];
        }

        // Event registration filter
        if (!empty($filters['event_id'])) {
            $sql .= " AND s.id IN (SELECT er.student_id FROM event_registrations er WHERE er.event_id = :event_id)";
            $params[':event_id'] = (int) $filters['event_id'];
        }

        $sql .= " ORDER BY s.last_name, s.first_name";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $students = $stmt->fetchAll();
        } catch (\PDOException $e) {
            // Gracefully handle missing columns (status vs is_active)
            // Retry without status filter
            try {
                $sql2    = "SELECT DISTINCT s.id, s.first_name, s.last_name, s.email, s.phone FROM students s WHERE 1=1";
                $params2 = [];
                if (!empty($filters['plan_id'])) {
                    $sql2 .= " AND s.id IN (SELECT m.student_id FROM memberships m WHERE m.plan_id = :plan_id AND m.status = 'active')";
                    $params2[':plan_id'] = (int) $filters['plan_id'];
                }
                if (!empty($filters['event_id'])) {
                    $sql2 .= " AND s.id IN (SELECT er.student_id FROM event_registrations er WHERE er.event_id = :event_id)";
                    $params2[':event_id'] = (int) $filters['event_id'];
                }
                $sql2 .= " ORDER BY s.last_name, s.first_name";
                $stmt2 = $pdo->prepare($sql2);
                $stmt2->execute($params2);
                $students = $stmt2->fetchAll();
            } catch (\PDOException $e2) {}
        }
    }

    // ── Fetch Users (Staff / Instructors / Admin) ──
    $includeUsers = in_array($audienceType, ['all', 'all_staff', 'custom'], true);
    if ($includeUsers) {
        $userRoles = $filters['user_roles'] ?? [];

        // For 'all_staff', include all roles
        if ($audienceType === 'all_staff' && empty($userRoles)) {
            $userRoles = ['admin', 'instructor', 'staff'];
        }

        if (!empty($userRoles)) {
            $rolePlaceholders = implode(',', array_fill(0, count($userRoles), '?'));
            try {
                $uStmt = $pdo->prepare(
                    "SELECT id, full_name, email, phone FROM users WHERE role IN ({$rolePlaceholders}) ORDER BY full_name"
                );
                $uStmt->execute(array_values($userRoles));
                $users = $uStmt->fetchAll();
            } catch (\PDOException $e) {
                // phone column might not exist yet
                try {
                    $uStmt2 = $pdo->prepare(
                        "SELECT id, full_name, email, '' as phone FROM users WHERE role IN ({$rolePlaceholders}) ORDER BY full_name"
                    );
                    $uStmt2->execute(array_values($userRoles));
                    $users = $uStmt2->fetchAll();
                } catch (\PDOException $e2) {}
            }
        }
    }

    return ['students' => $students, 'users' => $users];
}

// ---------------------------------------------------------------------------
// Email Sending (SMTP via PHP mail())
// ---------------------------------------------------------------------------

/**
 * Send an email via SMTP using PHP's mail() function.
 *
 * Settings: smtp_host, smtp_port, smtp_username, smtp_password,
 *           smtp_encryption, smtp_from_email, smtp_from_name
 *
 * @return array ['success' => bool, 'error' => ?string]
 */
function send_email(string $to, string $subject, string $htmlBody): array
{
    $host       = getSetting('smtp_host', '');
    $port       = getSetting('smtp_port', '587');
    $user       = getSetting('smtp_username', '');
    $pass       = getSetting('smtp_password', '');
    $encryption = getSetting('smtp_encryption', 'tls');
    $fromEmail  = getSetting('smtp_from_email', '');
    $fromName   = getSetting('smtp_from_name', getSiteName());

    if (empty($host) || empty($fromEmail)) {
        return ['success' => false, 'error' => 'SMTP not configured. Set host and from-email in Settings.'];
    }

    // Configure PHP mail settings
    $smtpHost = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
    ini_set('SMTP', $smtpHost);
    ini_set('smtp_port', $port);

    // Build headers
    $headers  = "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: MartialArtsApp/1.0\r\n";

    // Wrap body in a minimal HTML template
    $wrappedBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;font-size:14px;color:#333;">'
        . $htmlBody
        . '</body></html>';

    $result = @mail($to, $subject, $wrappedBody, $headers);

    if ($result) {
        return ['success' => true, 'error' => null];
    }

    $lastError = error_get_last();
    $errMsg = $lastError['message'] ?? 'mail() returned false. Check server SMTP configuration.';
    return ['success' => false, 'error' => $errMsg];
}

/**
 * Check if SMTP email is configured.
 */
function is_email_configured(): bool
{
    return getSetting('smtp_host', '') !== '' && getSetting('smtp_from_email', '') !== '';
}

// ---------------------------------------------------------------------------
// SMS Sending (Twilio REST API via cURL)
// ---------------------------------------------------------------------------

/**
 * Send an SMS via Twilio REST API.
 *
 * Settings: twilio_account_sid, twilio_auth_token, twilio_from_number
 *
 * @return array ['success' => bool, 'error' => ?string, 'sid' => ?string]
 */
function send_sms(string $to, string $body): array
{
    $accountSid = getSetting('twilio_account_sid', '');
    $authToken  = getSetting('twilio_auth_token', '');
    $fromNumber = getSetting('twilio_from_number', '');

    if (empty($accountSid) || empty($authToken) || empty($fromNumber)) {
        return ['success' => false, 'error' => 'Twilio not configured. Set Account SID, Auth Token, and From Number in Settings.', 'sid' => null];
    }

    // Normalize the recipient number
    $to = normalize_phone($to);
    if (strlen($to) < 10) {
        return ['success' => false, 'error' => 'Invalid phone number.', 'sid' => null];
    }

    // Truncate SMS body to 1600 chars (Twilio max)
    $body = substr(strip_tags($body), 0, 1600);

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$accountSid}:{$authToken}");
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'To'   => $to,
        'From' => $fromNumber,
        'Body' => $body,
    ]));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'error' => "cURL error: {$curlErr}", 'sid' => null];
    }

    $data = json_decode($response, true) ?: [];

    if ($httpCode === 201 && !empty($data['sid'])) {
        return ['success' => true, 'error' => null, 'sid' => $data['sid']];
    }

    $errMsg = $data['message'] ?? "Twilio API error (HTTP {$httpCode})";
    return ['success' => false, 'error' => $errMsg, 'sid' => null];
}

/**
 * Check if Twilio SMS is configured.
 */
function is_sms_configured(): bool
{
    return getSetting('twilio_account_sid', '') !== ''
        && getSetting('twilio_auth_token', '') !== ''
        && getSetting('twilio_from_number', '') !== '';
}

// ---------------------------------------------------------------------------
// Message Dispatch Orchestration
// ---------------------------------------------------------------------------

/**
 * Send a message across all selected channels to all resolved recipients.
 * Creates message_recipients rows and dispatches email/SMS.
 *
 * @param int $messageId  The messages.id to send
 * @return array Summary stats
 */
function dispatch_message(int $messageId): array
{
    set_time_limit(300); // Allow up to 5 minutes for large sends

    $pdo = get_db();

    // Fetch the message
    $mStmt = $pdo->prepare("SELECT * FROM messages WHERE id = ?");
    $mStmt->execute([$messageId]);
    $msg = $mStmt->fetch();

    if (!$msg) {
        return ['total' => 0, 'emails_sent' => 0, 'emails_failed' => 0, 'sms_sent' => 0, 'sms_failed' => 0];
    }

    // Mark as sending
    $pdo->prepare("UPDATE messages SET status = 'sending' WHERE id = ?")->execute([$messageId]);

    // Resolve recipients
    $filters = json_decode($msg['audience_filters'] ?: '{}', true) ?: [];
    $recipients = get_recipients_by_filter($filters);

    $stats = [
        'total'         => 0,
        'emails_sent'   => 0,
        'emails_failed' => 0,
        'sms_sent'      => 0,
        'sms_failed'    => 0,
    ];

    $insertRecip = $pdo->prepare("
        INSERT IGNORE INTO message_recipients (message_id, recipient_type, recipient_id, email_status, sms_status, inapp_status)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $updateRecip = $pdo->prepare("
        UPDATE message_recipients
        SET email_status = ?, email_error = ?, sms_status = ?, sms_error = ?
        WHERE message_id = ? AND recipient_type = ? AND recipient_id = ?
    ");

    // Process students
    foreach ($recipients['students'] as $s) {
        $emailStatus = 'skipped';
        $emailError  = null;
        $smsStatus   = 'skipped';
        $smsError    = null;
        $inappStatus = $msg['channel_inapp'] ? 'delivered' : 'read'; // 'read' effectively means no in-app

        // Insert recipient row
        $insertRecip->execute([
            $messageId, 'student', $s['id'],
            $msg['channel_email'] ? 'pending' : 'skipped',
            $msg['channel_sms'] ? 'pending' : 'skipped',
            $inappStatus,
        ]);

        // Send email
        if ($msg['channel_email'] && !empty($s['email'])) {
            $result = send_email($s['email'], $msg['subject'], $msg['body']);
            $emailStatus = $result['success'] ? 'sent' : 'failed';
            $emailError  = $result['error'];
            if ($result['success']) $stats['emails_sent']++;
            else $stats['emails_failed']++;
        } elseif ($msg['channel_email']) {
            $emailStatus = 'skipped';
            $emailError  = 'No email address';
        }

        // Send SMS
        if ($msg['channel_sms'] && !empty($s['phone'])) {
            $smsBody = strip_tags($msg['body']);
            // Prefix with subject for context
            $smsText = $msg['subject'] . ': ' . $smsBody;
            $result = send_sms($s['phone'], $smsText);
            $smsStatus = $result['success'] ? 'sent' : 'failed';
            $smsError  = $result['error'];
            if ($result['success']) $stats['sms_sent']++;
            else $stats['sms_failed']++;
        } elseif ($msg['channel_sms']) {
            $smsStatus = 'skipped';
            $smsError  = 'No phone number';
        }

        // Update delivery statuses
        $updateRecip->execute([
            $emailStatus, $emailError, $smsStatus, $smsError,
            $messageId, 'student', $s['id'],
        ]);

        $stats['total']++;
    }

    // Process users (staff/instructors/admin)
    foreach ($recipients['users'] as $u) {
        $emailStatus = 'skipped';
        $emailError  = null;
        $smsStatus   = 'skipped';
        $smsError    = null;
        // In-app messages are delivered to users as well if channel is on
        $inappStatus = $msg['channel_inapp'] ? 'delivered' : 'read';

        $insertRecip->execute([
            $messageId, 'user', $u['id'],
            $msg['channel_email'] ? 'pending' : 'skipped',
            $msg['channel_sms'] ? 'pending' : 'skipped',
            $inappStatus,
        ]);

        // Send email
        if ($msg['channel_email'] && !empty($u['email'])) {
            $result = send_email($u['email'], $msg['subject'], $msg['body']);
            $emailStatus = $result['success'] ? 'sent' : 'failed';
            $emailError  = $result['error'];
            if ($result['success']) $stats['emails_sent']++;
            else $stats['emails_failed']++;
        } elseif ($msg['channel_email']) {
            $emailStatus = 'skipped';
            $emailError  = 'No email address';
        }

        // Send SMS
        if ($msg['channel_sms'] && !empty($u['phone'])) {
            $smsText = $msg['subject'] . ': ' . strip_tags($msg['body']);
            $result = send_sms($u['phone'], $smsText);
            $smsStatus = $result['success'] ? 'sent' : 'failed';
            $smsError  = $result['error'];
            if ($result['success']) $stats['sms_sent']++;
            else $stats['sms_failed']++;
        } elseif ($msg['channel_sms']) {
            $smsStatus = 'skipped';
            $smsError  = 'No phone number';
        }

        $updateRecip->execute([
            $emailStatus, $emailError, $smsStatus, $smsError,
            $messageId, 'user', $u['id'],
        ]);

        $stats['total']++;
    }

    // Update aggregate stats on the message
    $pdo->prepare("
        UPDATE messages
        SET total_recipients = ?,
            emails_sent = ?, emails_failed = ?,
            sms_sent = ?, sms_failed = ?,
            status = 'sent', sent_at = NOW()
        WHERE id = ?
    ")->execute([
        $stats['total'],
        $stats['emails_sent'], $stats['emails_failed'],
        $stats['sms_sent'], $stats['sms_failed'],
        $messageId,
    ]);

    return $stats;
}

// ---------------------------------------------------------------------------
// Unread Message Count Helpers
// ---------------------------------------------------------------------------

/**
 * Get the count of unread in-app messages for a student.
 */
function get_unread_message_count(int $studentId): int
{
    $pdo = get_db();
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM message_recipients mr
            JOIN messages m ON m.id = mr.message_id
            WHERE mr.recipient_type = 'student'
              AND mr.recipient_id = ?
              AND mr.inapp_status = 'delivered'
              AND m.channel_inapp = 1
              AND m.status = 'sent'
        ");
        $stmt->execute([$studentId]);
        return (int) $stmt->fetchColumn();
    } catch (\PDOException $e) {
        return 0;
    }
}

/**
 * Get the count of unread in-app messages for a staff/admin user.
 */
function get_unread_message_count_user(int $userId): int
{
    $pdo = get_db();
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM message_recipients mr
            JOIN messages m ON m.id = mr.message_id
            WHERE mr.recipient_type = 'user'
              AND mr.recipient_id = ?
              AND mr.inapp_status = 'delivered'
              AND m.channel_inapp = 1
              AND m.status = 'sent'
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (\PDOException $e) {
        return 0;
    }
}
