<?php
/**
 * includes/messaging.php — Messaging & Communication Backend
 *
 * Provides:
 *   - Idempotent database migrations (messages, message_recipients, users.phone)
 *   - Audience/recipient resolution with flexible filters
 *   - SMTP email sending via direct socket with AUTH LOGIN & TLS/SSL
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
// Communication Consent (TCPA / CAN-SPAM)
// ---------------------------------------------------------------------------

/**
 * Get the communication consent text to display to users.
 * Returns admin-configured text, or TCPA/CAN-SPAM compliant default.
 */
function get_comm_consent_text(): string
{
    $custom = getSetting('comm_consent_content', '');
    if ($custom !== '') {
        return $custom;
    }

    $schoolName = getSiteName();
    return "CONSENT FOR DIGITAL COMMUNICATIONS\n\n"
         . "By checking the boxes below, you voluntarily consent to receive digital communications "
         . "from {$schoolName} regarding class schedules, attendance updates, event notifications, "
         . "membership and billing information, and other school-related announcements.\n\n"
         . "EMAIL COMMUNICATIONS\n"
         . "You may receive emails including but not limited to: class schedule changes, attendance "
         . "alerts, event registrations, payment receipts, membership renewal notices, and general "
         . "school announcements. You may withdraw your email consent at any time by updating your "
         . "preferences on your profile page or by clicking the unsubscribe link in any email.\n\n"
         . "SMS/TEXT MESSAGE COMMUNICATIONS\n"
         . "You may receive text messages including but not limited to: class reminders, schedule "
         . "changes, attendance notifications, event updates, and important school announcements. "
         . "Message frequency varies based on school activity. Message and data rates may apply. "
         . "Your wireless carrier may charge additional fees for text messages received. You may "
         . "opt out of text messages at any time by replying STOP to any message or by updating "
         . "your preferences on your profile page. Reply HELP for assistance.\n\n"
         . "YOUR RIGHTS\n"
         . "- Consent is voluntary and is not a condition of enrollment or continued membership.\n"
         . "- You may withdraw consent at any time from your profile page without affecting your enrollment status.\n"
         . "- Withdrawal of consent applies to future communications only.\n\n"
         . "COMPATIBLE CARRIERS\n"
         . "Most major wireless carriers are supported. Carrier message and data rates may apply. "
         . "Contact your wireless provider for details about your text messaging plan.\n\n"
         . "By checking the email and/or SMS boxes during registration, you acknowledge that you "
         . "have read and understand this consent and agree to receive the selected types of communications.";
}

/**
 * Get the current communication consent version.
 */
function get_comm_consent_version(): string
{
    return getSetting('comm_consent_version', '1.0');
}

/**
 * Check if a student has consented to a communication channel.
 *
 * @param int    $studentId
 * @param string $channel  'email' or 'sms'
 * @return bool  Returns true if consented, or true if columns don't exist yet (graceful degradation)
 */
function has_comm_consent(int $studentId, string $channel): bool
{
    try {
        $pdo = get_db();
        $col = ($channel === 'sms') ? 'comm_consent_sms' : 'comm_consent_email';
        $stmt = $pdo->prepare("SELECT {$col} FROM students WHERE id = ?" . school_where() . " LIMIT 1");
        $params = [$studentId];
        school_param($params);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return !empty($row[$col]);
    } catch (\PDOException $e) {
        // Column may not exist yet — default to allowing (graceful degradation)
        return true;
    }
}

/**
 * Check if a parent has consented to a communication channel.
 *
 * @param int    $parentId
 * @param string $channel  'email' or 'sms'
 * @return bool
 */
function has_parent_comm_consent(int $parentId, string $channel): bool
{
    try {
        $pdo = get_db();
        $col = ($channel === 'sms') ? 'comm_consent_sms' : 'comm_consent_email';
        $stmt = $pdo->prepare("SELECT {$col} FROM parents WHERE id = ?" . school_where() . " LIMIT 1");
        $params = [$parentId];
        school_param($params);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return !empty($row[$col]);
    } catch (\PDOException $e) {
        return true;
    }
}

// ---------------------------------------------------------------------------
// Unsubscribe Token Helpers
// ---------------------------------------------------------------------------

/**
 * Generate an HMAC-based unsubscribe token for a user.
 *
 * Token format: base64url( type:id:channel : HMAC-SHA256 )
 * No DB storage needed — verified via HMAC signature.
 *
 * @param string $type    'student' or 'parent'
 * @param int    $id      User ID
 * @param string $channel 'email' or 'sms' or 'all'
 * @return string URL-safe token
 */
function generate_unsubscribe_token(string $type, int $id, string $channel = 'all'): string
{
    $secret  = getSetting('app_secret_key', 'martialarts-default-secret-key');
    $payload = "{$type}:{$id}:{$channel}";
    $sig     = hash_hmac('sha256', $payload, $secret);
    return rtrim(strtr(base64_encode("{$payload}:{$sig}"), '+/', '-_'), '=');
}

/**
 * Verify and decode an unsubscribe token.
 *
 * @param string $token The URL-safe token
 * @return array|false  ['type' => ..., 'id' => ..., 'channel' => ...] or false
 */
function verify_unsubscribe_token(string $token)
{
    $secret  = getSetting('app_secret_key', 'martialarts-default-secret-key');
    $decoded = base64_decode(strtr($token, '-_', '+/'));
    if ($decoded === false) return false;

    $parts = explode(':', $decoded);
    if (count($parts) !== 4) return false;

    [$type, $id, $channel, $sig] = $parts;
    $payload  = "{$type}:{$id}:{$channel}";
    $expected = hash_hmac('sha256', $payload, $secret);

    if (!hash_equals($expected, $sig)) return false;
    if (!in_array($type, ['student', 'parent'], true)) return false;
    if (!in_array($channel, ['email', 'sms', 'all'], true)) return false;

    return ['type' => $type, 'id' => (int) $id, 'channel' => $channel];
}

/**
 * Revoke communication consent for a user.
 *
 * @param string $type    'student' or 'parent'
 * @param int    $id      User ID
 * @param string $channel 'email', 'sms', or 'all'
 * @return bool
 */
function revoke_comm_consent(string $type, int $id, string $channel): bool
{
    try {
        $pdo = get_db();
        $tbl = ($type === 'parent') ? 'parents' : 'students';
        $sets = [];
        if ($channel === 'email' || $channel === 'all') {
            $sets[] = 'comm_consent_email = 0';
            $sets[] = 'comm_consent_email_at = NULL';
        }
        if ($channel === 'sms' || $channel === 'all') {
            $sets[] = 'comm_consent_sms = 0';
            $sets[] = 'comm_consent_sms_at = NULL';
        }
        if (empty($sets)) return false;

        $sql = "UPDATE {$tbl} SET " . implode(', ', $sets) . " WHERE id = ?";
        $pdo->prepare($sql)->execute([$id]);
        return true;
    } catch (\PDOException $e) {
        return false;
    }
}

/**
 * Build the base URL of the application from server variables.
 */
function get_app_base_url(): string
{
    $customUrl = getSetting('app_base_url', '');
    if ($customUrl !== '') {
        return rtrim($customUrl, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    // For files in /includes/, go up one level
    if (basename($path) === 'includes') {
        $path = dirname($path);
    }
    return $scheme . '://' . $host . rtrim($path, '/');
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
        $sql    = "SELECT DISTINCT s.id, s.first_name, s.last_name, s.email, s.phone, s.comm_consent_email, s.comm_consent_sms FROM students s WHERE 1=1";
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
                $uSql = "SELECT DISTINCT u.id, u.full_name, u.email, u.phone FROM users u INNER JOIN user_schools us ON u.id = us.user_id WHERE u.role IN ({$rolePlaceholders})" . school_where('us') . " ORDER BY u.full_name";
                school_param($uParams);
                $uStmt = $pdo->prepare($uSql);
                $uStmt->execute($uParams);
                $users = $uStmt->fetchAll();
            } catch (\PDOException $e) {
                // phone column might not exist yet
                try {
                    $uParams2 = array_values($userRoles);
                    $uSql2 = "SELECT DISTINCT u.id, u.full_name, u.email, '' as phone FROM users u INNER JOIN user_schools us ON u.id = us.user_id WHERE u.role IN ({$rolePlaceholders})" . school_where('us') . " ORDER BY u.full_name";
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
// Email Sending (Direct SMTP with authentication & TLS)
// ---------------------------------------------------------------------------

/**
 * Send a single SMTP command and read the response.
 *
 * @param resource $sock   Open socket
 * @param string   $cmd    Command to send (empty to just read the greeting)
 * @param int      $expect Expected status code (e.g. 250)
 * @return array ['code' => int, 'text' => string, 'ok' => bool]
 */
function smtp_command($sock, string $cmd, int $expect): array
{
    if ($cmd !== '') {
        fwrite($sock, $cmd . "\r\n");
    }
    $response = '';
    while ($line = fgets($sock, 512)) {
        $response .= $line;
        // SMTP multi-line: "250-..." continues, "250 ..." is last line
        if (isset($line[3]) && $line[3] === ' ') break;
        if (strlen($line) < 4) break;
    }
    $code = (int) substr($response, 0, 3);
    return ['code' => $code, 'text' => trim($response), 'ok' => $code === $expect];
}

/**
 * Send an email via direct SMTP with authentication and TLS/SSL support.
 *
 * Settings: smtp_host, smtp_port, smtp_username, smtp_password,
 *           smtp_encryption, smtp_from_email, smtp_from_name
 *
 * @return array ['success' => bool, 'error' => ?string]
 */
function send_email(string $to, string $subject, string $htmlBody, array $unsubscribeCtx = []): array
{
    $host       = getSetting('smtp_host', '');
    $port       = (int) getSetting('smtp_port', '587');
    $user       = getSetting('smtp_username', '');
    $pass       = getSetting('smtp_password', '');
    $encryption = getSetting('smtp_encryption', 'tls');
    $fromEmail  = getSetting('smtp_from_email', '');
    $fromName   = getSetting('smtp_from_name', getSiteName());

    if (empty($host) || empty($fromEmail)) {
        return ['success' => false, 'error' => 'SMTP not configured. Set host and from-email in Settings.'];
    }

    // Build unsubscribe URL and footer if context provided
    $unsubscribeUrl = '';
    if (!empty($unsubscribeCtx['type']) && !empty($unsubscribeCtx['id'])) {
        $token = generate_unsubscribe_token($unsubscribeCtx['type'], (int) $unsubscribeCtx['id'], 'email');
        $baseUrl = get_app_base_url();
        $unsubscribeUrl = $baseUrl . '/unsubscribe.php?token=' . urlencode($token);

        // Append unsubscribe footer to email body
        $htmlBody .= '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0 12px;">'
            . '<p style="font-size:11px;color:#9ca3af;text-align:center;margin:0;">'
            . 'You received this email because you consented to communications from ' . htmlspecialchars(getSiteName()) . '. '
            . '<a href="' . htmlspecialchars($unsubscribeUrl) . '" style="color:#6366f1;">Unsubscribe from emails</a> '
            . 'or reply STOP to opt out.'
            . '</p>';
    }

    // Build the RFC 2822 message
    $msg  = "From: {$fromName} <{$fromEmail}>\r\n";
    $msg .= "To: {$to}\r\n";
    $msg .= "Subject: {$subject}\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= "X-Mailer: MartialArtsApp/1.0\r\n";
    if ($unsubscribeUrl !== '') {
        $msg .= "List-Unsubscribe: <{$unsubscribeUrl}>\r\n";
        $msg .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
    }
    $msg .= "\r\n";

    $wrappedBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Arial,sans-serif;font-size:14px;color:#333;">'
        . $htmlBody
        . '</body></html>';
    $msg .= chunk_split(base64_encode($wrappedBody));

    // Determine connection address
    $connectHost = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
    if (!$port) $port = ($encryption === 'ssl') ? 465 : 587;

    $errno  = 0;
    $errstr = '';
    $ctx    = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ]);
    $sock = @stream_socket_client(
        "{$connectHost}:{$port}",
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $ctx
    );

    if (!$sock) {
        return ['success' => false, 'error' => "Could not connect to {$host}:{$port} — {$errstr} ({$errno})"];
    }

    stream_set_timeout($sock, 30);

    try {
        // Read server greeting
        $r = smtp_command($sock, '', 220);
        if (!$r['ok']) throw new \RuntimeException("SMTP greeting failed: {$r['text']}");

        // EHLO
        $ehloHost = gethostname() ?: 'localhost';
        $r = smtp_command($sock, "EHLO {$ehloHost}", 250);
        if (!$r['ok']) throw new \RuntimeException("EHLO failed: {$r['text']}");

        // STARTTLS for TLS encryption (not needed for direct SSL)
        if ($encryption === 'tls') {
            $r = smtp_command($sock, 'STARTTLS', 220);
            if (!$r['ok']) throw new \RuntimeException("STARTTLS failed: {$r['text']}");

            $cryptoOk = stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if (!$cryptoOk) {
                // Fallback: try broader TLS method
                $cryptoOk = stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            }
            if (!$cryptoOk) throw new \RuntimeException('TLS negotiation failed after STARTTLS.');

            // Re-issue EHLO after TLS
            $r = smtp_command($sock, "EHLO {$ehloHost}", 250);
            if (!$r['ok']) throw new \RuntimeException("EHLO after TLS failed: {$r['text']}");
        }

        // AUTH LOGIN (if credentials provided)
        if ($user !== '' && $pass !== '') {
            $r = smtp_command($sock, 'AUTH LOGIN', 334);
            if (!$r['ok']) throw new \RuntimeException("AUTH LOGIN failed: {$r['text']}");

            $r = smtp_command($sock, base64_encode($user), 334);
            if (!$r['ok']) throw new \RuntimeException("AUTH username rejected: {$r['text']}");

            $r = smtp_command($sock, base64_encode($pass), 235);
            if (!$r['ok']) throw new \RuntimeException("AUTH password rejected: {$r['text']}");
        }

        // MAIL FROM
        $r = smtp_command($sock, "MAIL FROM:<{$fromEmail}>", 250);
        if (!$r['ok']) throw new \RuntimeException("MAIL FROM rejected: {$r['text']}");

        // RCPT TO
        $r = smtp_command($sock, "RCPT TO:<{$to}>", 250);
        if (!$r['ok']) throw new \RuntimeException("RCPT TO rejected: {$r['text']}");

        // DATA
        $r = smtp_command($sock, 'DATA', 354);
        if (!$r['ok']) throw new \RuntimeException("DATA command rejected: {$r['text']}");

        // Send message body (dot-stuffing: lines starting with . get ..)
        $lines = explode("\r\n", $msg);
        foreach ($lines as $line) {
            if (isset($line[0]) && $line[0] === '.') $line = '.' . $line;
            fwrite($sock, $line . "\r\n");
        }

        // End DATA with <CRLF>.<CRLF>
        $r = smtp_command($sock, '.', 250);
        if (!$r['ok']) throw new \RuntimeException("Message delivery failed: {$r['text']}");

        // QUIT
        @smtp_command($sock, 'QUIT', 221);

        @fclose($sock);
        return ['success' => true, 'error' => null];

    } catch (\Throwable $e) {
        @fwrite($sock, "QUIT\r\n");
        @fclose($sock);
        return ['success' => false, 'error' => $e->getMessage()];
    }
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

        // Check communication consent (columns may not exist — default to allowed)
        $canEmail = isset($s['comm_consent_email']) ? (bool)$s['comm_consent_email'] : true;
        $canSms   = isset($s['comm_consent_sms'])   ? (bool)$s['comm_consent_sms']   : true;

        // Send email
        if ($msg['channel_email'] && !empty($s['email']) && $canEmail) {
            $result = send_email($s['email'], $msg['subject'], $msg['body'], ['type' => 'student', 'id' => $s['id']]);
            $emailStatus = $result['success'] ? 'sent' : 'failed';
            $emailError  = $result['error'];
            if ($result['success']) $stats['emails_sent']++;
            else $stats['emails_failed']++;
        } elseif ($msg['channel_email'] && !$canEmail) {
            $emailStatus = 'skipped';
            $emailError  = 'No email consent';
        } elseif ($msg['channel_email']) {
            $emailStatus = 'skipped';
            $emailError  = 'No email address';
        }

        // Send SMS
        if ($msg['channel_sms'] && !empty($s['phone']) && $canSms) {
            $smsBody = strip_tags($msg['body']);
            // Prefix with subject for context
            $smsText = $msg['subject'] . ': ' . $smsBody;
            $result = send_sms($s['phone'], $smsText);
            $smsStatus = $result['success'] ? 'sent' : 'failed';
            $smsError  = $result['error'];
            if ($result['success']) $stats['sms_sent']++;
            else $stats['sms_failed']++;
        } elseif ($msg['channel_sms'] && !$canSms) {
            $smsStatus = 'skipped';
            $smsError  = 'No SMS consent';
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

// ─── Notification Template System ─────────────────────────────────────

/**
 * Registry of all automated notification templates.
 *
 * Each entry defines the template key, human-readable label, channel,
 * available placeholders (with descriptions), and default subject/body text.
 * Admins can override any of these via Settings → Communications.
 *
 * @return array<string, array{label:string, description:string, channel:string,
 *               placeholders:array<string,string>, default_subject:string, default_body:string}>
 */
function get_notification_template_definitions(): array
{
    return [
        'password_reset_email' => [
            'label'       => 'Password Reset',
            'description' => 'Sent when a student, parent, or admin requests a password reset via email.',
            'channel'     => 'email',
            'placeholders' => [
                '{student_name}' => 'Recipient\'s full name',
                '{code}'         => '6-digit verification code',
                '{school_name}'  => 'Your school name',
            ],
            'default_subject' => 'Password Reset Code',
            'default_body'    => '<p>Hi {student_name},</p>'
                . '<p>Your password reset verification code is:</p>'
                . '<p style="font-size:28px;font-weight:bold;letter-spacing:6px;text-align:center;padding:16px;background:#f5f5f5;border-radius:8px;">{code}</p>'
                . '<p>This code expires in 15 minutes. If you did not request a password reset, you can safely ignore this message.</p>'
                . '<p>&mdash; {school_name}</p>',
        ],
        'password_reset_sms' => [
            'label'       => 'Password Reset (SMS)',
            'description' => 'SMS text sent alongside the email when a password reset is requested.',
            'channel'     => 'sms',
            'placeholders' => [
                '{code}'         => '6-digit verification code',
                '{school_name}'  => 'Your school name',
            ],
            'default_subject' => '',
            'default_body'    => 'Your password reset code is: {code}. It expires in 15 minutes.',
        ],
        'payment_failed_monthly' => [
            'label'       => 'Payment Failed (Monthly)',
            'description' => 'Sent when a monthly membership installment payment fails.',
            'channel'     => 'email',
            'placeholders' => [
                '{student_name}' => 'Student\'s full name',
                '{amount}'       => 'Payment amount (e.g. $50.00)',
                '{plan_name}'    => 'Membership plan name',
                '{school_name}'  => 'Your school name',
            ],
            'default_subject' => 'Payment Failed - Action Required',
            'default_body'    => '<h2>Payment Failed</h2>'
                . '<p>Dear {student_name},</p>'
                . '<p>We were unable to process your monthly payment of <strong>{amount}</strong> for your <strong>{plan_name}</strong> membership.</p>'
                . '<p>Please update your payment method as soon as possible to avoid any interruption to your membership.</p>'
                . '<p>If you have questions, please contact the studio.</p>'
                . '<p>Thank you,<br>{school_name}</p>',
        ],
        'membership_expired' => [
            'label'       => 'Membership Expired (Renewal Failed)',
            'description' => 'Sent when a membership renewal payment fails and the membership expires.',
            'channel'     => 'email',
            'placeholders' => [
                '{student_name}' => 'Student\'s full name',
                '{amount}'       => 'Renewal payment amount',
                '{plan_name}'    => 'Membership plan name',
                '{school_name}'  => 'Your school name',
            ],
            'default_subject' => 'Membership Expired - Payment Failed',
            'default_body'    => '<h2>Membership Renewal Payment Failed</h2>'
                . '<p>Dear {student_name},</p>'
                . '<p>We were unable to process your renewal payment of <strong>{amount}</strong> for your <strong>{plan_name}</strong> membership.</p>'
                . '<p>Your membership has been set to expired. Please update your payment method and contact the studio to reactivate your membership.</p>'
                . '<p>Thank you,<br>{school_name}</p>',
        ],
        'attendance_warning' => [
            'label'       => 'Attendance Warning',
            'description' => 'Sent when a student exceeds the absence threshold during a belt testing cycle.',
            'channel'     => 'email',
            'placeholders' => [
                '{student_name}'  => 'Student\'s full name',
                '{absence_count}' => 'Number of unexcused absences',
                '{cycle_start}'   => 'Cycle start date (e.g. Jan 1, 2026)',
                '{cycle_end}'     => 'Cycle end date (e.g. Mar 31, 2026)',
                '{max_absences}'  => 'Maximum allowed absences before warning',
                '{school_name}'   => 'Your school name',
            ],
            'default_subject' => 'Attendance Warning - Make-Up Classes Required',
            'default_body'    => '<h2>Attendance Warning</h2>'
                . '<p>Dear {student_name},</p>'
                . '<p>You have accumulated <strong>{absence_count}</strong> unexcused absence(s) during the current belt testing cycle ({cycle_start} &ndash; {cycle_end}).</p>'
                . '<p>Our attendance policy requires no more than <strong>{max_absences}</strong> absences per cycle. If you miss any more classes you will need to make up classes to maintain your testing eligibility.</p>'
                . '<p>Please contact the studio to schedule make-up sessions as soon as possible.</p>'
                . '<p>Thank you,<br>{school_name}</p>',
        ],
        'new_message' => [
            'label'       => 'New Message Notification',
            'description' => 'Sent when someone receives a new direct message through the Messaging Center.',
            'channel'     => 'email',
            'placeholders' => [
                '{student_name}' => 'Recipient\'s name',
                '{school_name}'  => 'Your school name',
            ],
            'default_subject' => 'New Message - {school_name}',
            'default_body'    => '<p>Hello {student_name},</p>'
                . '<p>You have a new message from <strong>{school_name}</strong>.</p>'
                . '<p>Please log in to your portal to view and respond to your message.</p>',
        ],
        'plan_change_proposal' => [
            'label'       => 'Plan Change Proposal',
            'description' => 'Sent when an instructor proposes a membership plan change for a student.',
            'channel'     => 'email',
            'placeholders' => [
                '{student_name}' => 'Student\'s full name',
                '{plan_name}'    => 'Proposed new plan name',
                '{notes}'        => 'Instructor\'s notes (may be empty)',
                '{school_name}'  => 'Your school name',
            ],
            'default_subject' => 'Plan Change Proposal - {school_name}',
            'default_body'    => '<p>Hello {student_name},</p>'
                . '<p>Your instructor has proposed a change to your membership plan.</p>'
                . '<p><strong>Proposed new plan:</strong> {plan_name}</p>'
                . '<p>{notes}</p>'
                . '<p>Please log in to your student portal to review and approve or decline this change.</p>',
        ],
        'sms_stop_response' => [
            'label'       => 'SMS STOP Auto-Reply',
            'description' => 'Sent automatically when someone texts STOP to unsubscribe from SMS.',
            'channel'     => 'sms',
            'placeholders' => [
                '{school_name}' => 'Your school name',
            ],
            'default_subject' => '',
            'default_body'    => 'You have been unsubscribed from {school_name} text messages. You will no longer receive SMS from us. Reply START to re-subscribe.',
        ],
        'sms_start_response' => [
            'label'       => 'SMS START Auto-Reply',
            'description' => 'Sent automatically when someone texts START to re-subscribe to SMS.',
            'channel'     => 'sms',
            'placeholders' => [
                '{school_name}' => 'Your school name',
            ],
            'default_subject' => '',
            'default_body'    => 'You have been re-subscribed to {school_name} text messages. Reply STOP at any time to unsubscribe. Msg & data rates may apply.',
        ],
        'sms_help_response' => [
            'label'       => 'SMS HELP Auto-Reply',
            'description' => 'Sent automatically when someone texts HELP for info about SMS alerts.',
            'channel'     => 'sms',
            'placeholders' => [
                '{school_name}' => 'Your school name',
            ],
            'default_subject' => '',
            'default_body'    => '{school_name} SMS alerts. Msg frequency varies. Msg & data rates may apply. Reply STOP to cancel. Contact your school for more info.',
        ],
    ];
}

/**
 * Retrieve a notification template with placeholders replaced.
 *
 * Checks for admin-customized text in settings first, falls back to defaults.
 *
 * @param string $key          Template key (e.g. 'password_reset_email')
 * @param array  $replacements Associative array of placeholder => value
 * @return array{subject: string, body: string}
 */
function get_notification_template(string $key, array $replacements = []): array
{
    $defs = get_notification_template_definitions();
    if (!isset($defs[$key])) {
        return ['subject' => '', 'body' => ''];
    }

    $def = $defs[$key];

    // Check for admin-customized subject/body in settings
    $subject = getSetting("notif_tpl_{$key}_subject", '');
    $body    = getSetting("notif_tpl_{$key}_body", '');

    // Fall back to defaults if custom is empty
    if ($subject === '') {
        $subject = $def['default_subject'];
    }
    if ($body === '') {
        $body = $def['default_body'];
    }

    // Replace placeholders
    foreach ($replacements as $placeholder => $value) {
        $subject = str_replace($placeholder, (string) $value, $subject);
        $body    = str_replace($placeholder, (string) $value, $body);
    }

    return ['subject' => $subject, 'body' => $body];
}
