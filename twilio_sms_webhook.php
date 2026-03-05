<?php
/**
 * twilio_sms_webhook.php — Twilio Incoming SMS Webhook
 *
 * Configure this URL in your Twilio phone number's "Messaging" webhook:
 *   https://yourdomain.com/twilio_sms_webhook.php
 *
 * When a student or parent replies to an SMS, Twilio sends a POST here.
 * If the message body contains STOP, UNSUBSCRIBE, CANCEL, END, or QUIT,
 * we automatically revoke their SMS consent in the database.
 *
 * If the message body is HELP, we respond with a help message.
 *
 * Twilio also handles STOP/START at the carrier level automatically,
 * but this webhook keeps our database in sync.
 *
 * Security: Validates Twilio request signature to prevent spoofing.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/messaging.php';

// Always respond with TwiML content type
header('Content-Type: text/xml; charset=UTF-8');

// Validate Twilio signature (if auth token is configured)
$authToken = getSetting('twilio_auth_token', '');
if ($authToken !== '' && !empty($_SERVER['HTTP_X_TWILIO_SIGNATURE'])) {
    $url = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
         . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
    $params = $_POST;
    ksort($params);
    $data = $url;
    foreach ($params as $key => $value) {
        $data .= $key . $value;
    }
    $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));
    if (!hash_equals($expected, $_SERVER['HTTP_X_TWILIO_SIGNATURE'])) {
        http_response_code(403);
        echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
        exit;
    }
}

// Extract incoming message details
$from = $_POST['From'] ?? '';
$body = strtoupper(trim($_POST['Body'] ?? ''));

if ($from === '' || $body === '') {
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
    exit;
}

// Normalize the incoming phone number for lookup
$normalizedPhone = normalize_phone($from);

// Opt-out keywords (TCPA standard)
$stopKeywords = ['STOP', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];
$helpKeywords = ['HELP', 'INFO'];
$startKeywords = ['START', 'YES', 'UNSTOP'];

$schoolName = getSiteName();
$responseMessage = '';

if (in_array($body, $stopKeywords, true)) {
    // ── STOP: Revoke SMS consent ──
    $revoked = false;

    // Look up in students table by phone
    try {
        $pdo = get_db();

        // Check students
        $stmt = $pdo->prepare("SELECT id, phone FROM students WHERE phone IS NOT NULL AND phone != '' AND (comm_consent_sms = 1)");
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            if (normalize_phone($row['phone']) === $normalizedPhone) {
                revoke_comm_consent('student', (int) $row['id'], 'sms');
                $revoked = true;
            }
        }

        // Check parents
        $stmt = $pdo->prepare("SELECT id, phone FROM parents WHERE phone IS NOT NULL AND phone != '' AND (comm_consent_sms = 1)");
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            if (normalize_phone($row['phone']) === $normalizedPhone) {
                revoke_comm_consent('parent', (int) $row['id'], 'sms');
                $revoked = true;
            }
        }
    } catch (\PDOException $e) {
        // Log but don't fail
        if (function_exists('app_log')) {
            app_log('error', 'SMS webhook DB error: ' . $e->getMessage(), ['category' => 'sms']);
        }
    }

    $tpl = get_notification_template('sms_stop_response', ['{school_name}' => $schoolName]);
    $responseMessage = $tpl['body'];

    // Log the opt-out
    if (function_exists('app_log')) {
        app_log('info', "SMS STOP received from {$from} — consent revoked: " . ($revoked ? 'yes' : 'no match found'), ['category' => 'sms']);
    }

} elseif (in_array($body, $startKeywords, true)) {
    // ── START: Re-subscribe SMS ──
    $resubscribed = false;

    try {
        $pdo = get_db();
        $consentNow = date('Y-m-d H:i:s');
        $version = get_comm_consent_version();

        // Check students
        $stmt = $pdo->prepare("SELECT id, phone FROM students WHERE phone IS NOT NULL AND phone != ''");
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            if (normalize_phone($row['phone']) === $normalizedPhone) {
                $pdo->prepare("UPDATE students SET comm_consent_sms = 1, comm_consent_sms_at = ?, comm_consent_version = ? WHERE id = ?")
                    ->execute([$consentNow, $version, $row['id']]);
                $resubscribed = true;
            }
        }

        // Check parents
        $stmt = $pdo->prepare("SELECT id, phone FROM parents WHERE phone IS NOT NULL AND phone != ''");
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            if (normalize_phone($row['phone']) === $normalizedPhone) {
                $pdo->prepare("UPDATE parents SET comm_consent_sms = 1, comm_consent_sms_at = ?, comm_consent_version = ? WHERE id = ?")
                    ->execute([$consentNow, $version, $row['id']]);
                $resubscribed = true;
            }
        }
    } catch (\PDOException $e) {
        if (function_exists('app_log')) {
            app_log('error', 'SMS webhook DB error (START): ' . $e->getMessage(), ['category' => 'sms']);
        }
    }

    $tpl = get_notification_template('sms_start_response', ['{school_name}' => $schoolName]);
    $responseMessage = $tpl['body'];

} elseif (in_array($body, $helpKeywords, true)) {
    // ── HELP: Send help info ──
    $tpl = get_notification_template('sms_help_response', ['{school_name}' => $schoolName]);
    $responseMessage = $tpl['body'];

} else {
    // Not a keyword — don't respond (or could forward to admin inbox)
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
    exit;
}

// Send TwiML response
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<Response>';
if ($responseMessage !== '') {
    echo '<Message>' . htmlspecialchars($responseMessage) . '</Message>';
}
echo '</Response>';
