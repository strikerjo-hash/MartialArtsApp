<?php
/**
 * Security helpers — CSRF tokens, rate limiting, encryption.
 *
 * Every form should call csrf_field() and every POST handler should
 * call verify_csrf() before processing input.
 */

require_once __DIR__ . '/db.php';

// ------------------------------------------------------------------ CSRF

function csrf_token(): string
{
    auth_start_session();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Emit a hidden <input> containing the CSRF token.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Verify the CSRF token submitted with a POST request.
 * Returns true if valid; aborts with 403 if not.
 */
function verify_csrf(): bool
{
    auth_start_session();

    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';

    if (!hash_equals($expected, $submitted)) {
        // Log CSRF failure
        if (function_exists('app_log')) {
            app_log('warning', 'CSRF token validation failed', [
                'category' => 'security',
                'ip'       => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'uri'      => $_SERVER['REQUEST_URI'] ?? 'unknown',
            ]);
        }
        http_response_code(403);
        die('Invalid security token. Please go back and try again.');
    }

    // Rotate token after successful verification.
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // Auto-log POST action for audit trail
    if (function_exists('audit_log_post')) {
        audit_log_post();
    }

    return true;
}

// ----------------------------------------------------------- Rate Limiting

/**
 * Record a failed login attempt.
 */
function record_failed_login(string $username, string $ip): void
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (username, ip_address, attempted_at) VALUES (:u, :ip, NOW())'
    );
    $stmt->execute([':u' => $username, ':ip' => $ip]);
}

/**
 * Clear login attempts after a successful login.
 */
function clear_login_attempts(string $username, string $ip): void
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'DELETE FROM login_attempts WHERE username = :u OR ip_address = :ip'
    );
    $stmt->execute([':u' => $username, ':ip' => $ip]);
}

/**
 * Check whether the username or IP has exceeded the attempt threshold.
 * Returns the number of seconds the user must wait, or 0 if not limited.
 */
function check_rate_limit(string $username, string $ip): int
{
    $pdo = get_db();

    // Count attempts in the last 15 minutes.
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE (username = :u OR ip_address = :ip)
           AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    );
    $stmt->execute([':u' => $username, ':ip' => $ip]);
    $count = (int) $stmt->fetchColumn();

    if ($count >= MAX_LOGIN_ATTEMPTS) {
        // Find the most recent attempt to calculate remaining lockout.
        $stmt2 = $pdo->prepare(
            'SELECT MAX(attempted_at) FROM login_attempts
             WHERE (username = :u OR ip_address = :ip)
               AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $stmt2->execute([':u' => $username, ':ip' => $ip]);
        $last = $stmt2->fetchColumn();

        $unlock = strtotime($last) + LOGIN_LOCKOUT_SECONDS;
        $remaining = $unlock - time();

        return max($remaining, 1);
    }

    return 0;
}

// ------------------------------------------------- Payment Data Encryption

/**
 * Encrypt a string using AES-256-GCM.
 * Returns a base64-encoded blob containing IV + tag + ciphertext.
 */
function encrypt_payment_data(string $plaintext): string
{
    $key    = hex2bin(ENCRYPTION_KEY);
    $iv     = random_bytes(12);                      // 96-bit IV for GCM
    $cipher = 'aes-256-gcm';
    $tag    = '';

    $ciphertext = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

    // Pack: IV (12) + tag (16) + ciphertext
    return base64_encode($iv . $tag . $ciphertext);
}

/**
 * Decrypt a blob produced by encrypt_payment_data().
 */
function decrypt_payment_data(string $encoded): string
{
    $key  = hex2bin(ENCRYPTION_KEY);
    $raw  = base64_decode($encoded, true);
    $iv   = substr($raw, 0, 12);
    $tag  = substr($raw, 12, 16);
    $data = substr($raw, 28);

    $plaintext = openssl_decrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    if ($plaintext === false) {
        throw new \RuntimeException('Decryption failed — data may be corrupted or the key has changed.');
    }

    return $plaintext;
}

// --------------------------------------------------- Password Validation

/**
 * Validate password strength for registration / password changes.
 * Returns an error message or empty string if the password is acceptable.
 */
function validate_password(string $password): string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number.';
    }

    return '';
}
