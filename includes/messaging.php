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

// Migrations have been moved to migrate.php

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
        // Uses named params so we use manual is_viewing_all_schools() approach
        $sql    = "SELECT DISTINCT s.id, s.first_name, s.last_name, s.email, s.phone FROM students s WHERE 1=1";
        $params = [];

        // Multi-tenancy scoping
        if (!is_viewing_all_schools()) {
            $sql .= " AND s.school_id = :school_id";
            $params[':school_id'] = current_school_id();
        }

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

                // Multi-tenancy scoping (retry)
                if (!is_viewing_all_schools()) {
                    $sql2 .= " AND s.school_id = :school_id";
                    $params2[':school_id'] = current_school_id();
                }

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
            $uParams = array_values($userRoles);
            try {
                $uSql = "SELECT id, full_name, email, phone FROM users WHERE role IN ({$rolePlaceholders})" . school_where() . " ORDER BY full_name";
                school_param($uParams);
                $uStmt = $pdo->prepare($uSql);
                $uStmt->execute($uParams);
                $users = $uStmt->fetchAll();
            } catch (\PDOException $e) {
                // phone column might not exist yet
                try {
                    $uParams2 = array_values($userRoles);
                    $uSql2 = "SELECT id, full_name, email, '' as phone FROM users WHERE role IN ({$rolePlaceholders})" . school_where() . " ORDER BY full_name";
                    school_param($uParams2);
                    $uStmt2 = $pdo->prepare($uSql2);
                    $uStmt2->execute($uParams2);
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

    // Fetch the message (scoped to current school)
    $params = [$messageId];
    $mStmt = $pdo->prepare("SELECT * FROM messages WHERE id = ?" . school_where());
    school_param($params);
    $mStmt->execute($params);
    $msg = $mStmt->fetch();

    if (!$msg) {
        return ['total' => 0, 'emails_sent' => 0, 'emails_failed' => 0, 'sms_sent' => 0, 'sms_failed' => 0];
    }

    // Mark as sending (scoped to current school)
    $updParams = [$messageId];
    $updSql = "UPDATE messages SET status = 'sending' WHERE id = ?" . school_where();
    school_param($updParams);
    $pdo->prepare($updSql)->execute($updParams);

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
        INSERT IGNORE INTO message_recipients (school_id, message_id, recipient_type, recipient_id, email_status, sms_status, inapp_status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $updateRecip = $pdo->prepare("
        UPDATE message_recipients
        SET email_status = ?, email_error = ?, sms_status = ?, sms_error = ?
        WHERE message_id = ? AND recipient_type = ? AND recipient_id = ?" . school_where()
    );

    // Process students
    foreach ($recipients['students'] as $s) {
        $emailStatus = 'skipped';
        $emailError  = null;
        $smsStatus   = 'skipped';
        $smsError    = null;
        $inappStatus = $msg['channel_inapp'] ? 'delivered' : 'read'; // 'read' effectively means no in-app

        // Insert recipient row (with school_id)
        $insertRecip->execute([
            current_school_id(),
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

        // Update delivery statuses (scoped to current school)
        $urParams = [
            $emailStatus, $emailError, $smsStatus, $smsError,
            $messageId, 'student', $s['id'],
        ];
        school_param($urParams);
        $updateRecip->execute($urParams);

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
            current_school_id(),
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

        $urParams2 = [
            $emailStatus, $emailError, $smsStatus, $smsError,
            $messageId, 'user', $u['id'],
        ];
        school_param($urParams2);
        $updateRecip->execute($urParams2);

        $stats['total']++;
    }

    // Update aggregate stats on the message (scoped to current school)
    $finalParams = [
        $stats['total'],
        $stats['emails_sent'], $stats['emails_failed'],
        $stats['sms_sent'], $stats['sms_failed'],
        $messageId,
    ];
    $finalSql = "
        UPDATE messages
        SET total_recipients = ?,
            emails_sent = ?, emails_failed = ?,
            sms_sent = ?, sms_failed = ?,
            status = 'sent', sent_at = NOW()
        WHERE id = ?" . school_where();
    school_param($finalParams);
    $pdo->prepare($finalSql)->execute($finalParams);

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
        $params = [$studentId];
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM message_recipients mr
            JOIN messages m ON m.id = mr.message_id
            WHERE mr.recipient_type = 'student'
              AND mr.recipient_id = ?
              AND mr.inapp_status = 'delivered'
              AND m.channel_inapp = 1
              AND m.status = 'sent'" . school_where('m')
        );
        school_param($params);
        $stmt->execute($params);
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
        $params = [$userId];
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM message_recipients mr
            JOIN messages m ON m.id = mr.message_id
            WHERE mr.recipient_type = 'user'
              AND mr.recipient_id = ?
              AND mr.inapp_status = 'delivered'
              AND m.channel_inapp = 1
              AND m.status = 'sent'" . school_where('m')
        );
        school_param($params);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (\PDOException $e) {
        return 0;
    }
}
