<?php
/**
 * api/jwt.php — Pure PHP JWT implementation (HMAC-SHA256)
 *
 * No external libraries required. Uses hash_hmac() with SHA-256.
 *
 * Token types:
 *   - Access token:  JWT, 1-hour expiry, stateless validation
 *   - Refresh token: random hex string, SHA-256 hash stored in api_tokens table, 30-day expiry
 */

// ---------- Base64url helpers (RFC 7515) ----------

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

// ---------- Core JWT functions ----------

/**
 * Encode a payload into a signed JWT string.
 */
function jwt_encode(array $payload, string $secret): string
{
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body   = base64url_encode(json_encode($payload));

    $signature = base64url_encode(
        hash_hmac('sha256', "$header.$body", $secret, true)
    );

    return "$header.$body.$signature";
}

/**
 * Decode and verify a JWT string. Returns the payload or null on failure.
 */
function jwt_decode(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$header, $body, $signature] = $parts;

    // Verify signature
    $expected = base64url_encode(
        hash_hmac('sha256', "$header.$body", $secret, true)
    );

    if (!hash_equals($expected, $signature)) {
        return null; // Signature mismatch
    }

    $payload = json_decode(base64url_decode($body), true);
    if (!is_array($payload)) {
        return null;
    }

    // Check expiry
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null; // Expired
    }

    return $payload;
}

// ---------- Token factory functions ----------

/**
 * Get (or auto-generate) the JWT signing secret.
 */
function jwt_get_secret(): string
{
    $secret = getSetting('api_jwt_secret', '', 1); // Global setting, school_id=1
    if (empty($secret)) {
        $secret = bin2hex(random_bytes(32));
        saveSetting('api_jwt_secret', $secret, 1);
    }
    return $secret;
}

/**
 * Create a signed access token (JWT, 1-hour expiry).
 */
function jwt_create_access_token(int $userId, string $userType, int $schoolId): string
{
    $payload = [
        'sub'    => $userId,
        'type'   => $userType,   // 'student', 'parent', 'admin'
        'school' => $schoolId,
        'iat'    => time(),
        'exp'    => time() + 3600, // 1 hour
    ];

    return jwt_encode($payload, jwt_get_secret());
}

/**
 * Create a refresh token (random hex, stored as SHA-256 hash in DB).
 * Returns the plain-text token to send to the client.
 */
function jwt_create_refresh_token(int $userId, string $userType, int $schoolId, string $deviceInfo = ''): string
{
    $plainToken = bin2hex(random_bytes(32)); // 64-char hex
    $hash       = hash('sha256', $plainToken);
    $expiresAt  = date('Y-m-d H:i:s', time() + (30 * 86400)); // 30 days

    $pdo = get_db();
    $stmt = $pdo->prepare("INSERT INTO api_tokens (user_id, user_type, token_hash, school_id, device_info, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $userType, $hash, $schoolId, $deviceInfo ?: null, $expiresAt]);

    return $plainToken;
}

/**
 * Validate a refresh token. Returns the token row or null.
 */
function jwt_validate_refresh_token(string $plainToken): ?array
{
    $hash = hash('sha256', $plainToken);
    $pdo  = get_db();

    $stmt = $pdo->prepare("SELECT * FROM api_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$hash]);
    return $stmt->fetch() ?: null;
}

/**
 * Revoke a refresh token (delete from DB).
 */
function jwt_revoke_refresh_token(string $plainToken): void
{
    $hash = hash('sha256', $plainToken);
    $pdo  = get_db();
    $pdo->prepare("DELETE FROM api_tokens WHERE token_hash = ?")->execute([$hash]);
}

/**
 * Revoke ALL refresh tokens for a user (e.g., password change).
 */
function jwt_revoke_all_tokens(int $userId, string $userType): void
{
    $pdo = get_db();
    $pdo->prepare("DELETE FROM api_tokens WHERE user_id = ? AND user_type = ?")->execute([$userId, $userType]);
}

/**
 * Clean up expired refresh tokens (called periodically).
 */
function jwt_cleanup_expired_tokens(): int
{
    $pdo  = get_db();
    $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE expires_at < NOW()");
    $stmt->execute();
    return $stmt->rowCount();
}
