<?php
/**
 * api/fcm.php — Firebase Cloud Messaging helper (HTTP v1 API)
 *
 * Provides server-side push notification sending via the FCM HTTP v1 API.
 * Uses OAuth 2.0 service account authentication with short-lived access tokens.
 * JWT creation uses PHP's openssl extension (no external libraries).
 *
 * Requires:
 *   - Firebase service account JSON file uploaded via admin settings
 *   - Path stored in setting 'fcm_service_account_path'
 *   - Project ID stored in setting 'fcm_project_id'
 *
 * @see https://firebase.google.com/docs/cloud-messaging/send-message
 * @see https://firebase.google.com/docs/cloud-messaging/migrate-v1
 */

// ──────────────────────────────────────────────────────────────
// Internal helpers — JWT creation, OAuth token, service account
// ──────────────────────────────────────────────────────────────

/**
 * Base64 URL-safe encoding (required for JWT).
 */
function fcm_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Load and validate the Firebase service account JSON file.
 *
 * @return array|null Decoded service account data, or null on failure.
 */
function fcm_load_service_account(): ?array
{
    $relativePath = getSetting('fcm_service_account_path', '');
    if (empty($relativePath)) {
        return null;
    }

    // Resolve path relative to project root (one level above api/)
    $fullPath = dirname(__DIR__) . '/' . $relativePath;
    if (!file_exists($fullPath) || !is_readable($fullPath)) {
        if (function_exists('app_log')) {
            app_log('warning', 'FCM service account file not found or not readable', [
                'category' => 'fcm',
                'path'     => $relativePath,
            ]);
        }
        return null;
    }

    $contents = file_get_contents($fullPath);
    $sa = json_decode($contents, true);
    if (!is_array($sa)) {
        if (function_exists('app_log')) {
            app_log('warning', 'FCM service account file contains invalid JSON', ['category' => 'fcm']);
        }
        return null;
    }

    // Validate required fields
    $required = ['type', 'project_id', 'private_key', 'client_email', 'token_uri'];
    foreach ($required as $field) {
        if (empty($sa[$field])) {
            if (function_exists('app_log')) {
                app_log('warning', 'FCM service account missing required field: ' . $field, ['category' => 'fcm']);
            }
            return null;
        }
    }

    if ($sa['type'] !== 'service_account') {
        if (function_exists('app_log')) {
            app_log('warning', 'FCM file is not a service account (type=' . $sa['type'] . ')', ['category' => 'fcm']);
        }
        return null;
    }

    return $sa;
}

/**
 * Create a signed JWT for the Google OAuth2 token exchange.
 *
 * @param array $serviceAccount Decoded service account JSON.
 * @return string Signed JWT string.
 * @throws RuntimeException If openssl_sign fails.
 */
function fcm_create_jwt(array $serviceAccount): string
{
    $now = time();

    $header = fcm_base64url_encode(json_encode([
        'alg' => 'RS256',
        'typ' => 'JWT',
    ]));

    $payload = fcm_base64url_encode(json_encode([
        'iss'   => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => $serviceAccount['token_uri'],
        'exp'   => $now + 3600,
        'iat'   => $now,
    ]));

    $signingInput = $header . '.' . $payload;
    $signature = '';

    $success = openssl_sign(
        $signingInput,
        $signature,
        $serviceAccount['private_key'],
        'SHA256'
    );

    if (!$success) {
        throw new RuntimeException('FCM JWT signing failed: ' . openssl_error_string());
    }

    return $signingInput . '.' . fcm_base64url_encode($signature);
}

/**
 * Get an OAuth 2.0 access token for the FCM v1 API.
 * Uses file-based caching to avoid exchanging a new token for every request.
 *
 * @param array    $serviceAccount Decoded service account JSON.
 * @param int|null $schoolId       School ID for cache file isolation.
 * @param bool     $forceRefresh   Skip cache and fetch a new token.
 * @return string|null Access token, or null on failure.
 */
function fcm_get_access_token(array $serviceAccount, ?int $schoolId = null, bool $forceRefresh = false): ?string
{
    if ($schoolId === null) {
        $schoolId = function_exists('current_school_id') ? current_school_id() : 1;
    }

    $cacheDir = dirname(__DIR__) . '/uploads/fcm';
    $cachePath = $cacheDir . '/school_' . $schoolId . '_oauth_token.json';

    // Check cache (unless force refresh)
    if (!$forceRefresh && file_exists($cachePath)) {
        $cached = json_decode(file_get_contents($cachePath), true);
        // Use token if it has at least 5 minutes remaining
        if (isset($cached['access_token'], $cached['expires_at'])
            && $cached['expires_at'] > time() + 300) {
            return $cached['access_token'];
        }
    }

    // Create JWT and exchange for access token
    try {
        $jwt = fcm_create_jwt($serviceAccount);
    } catch (RuntimeException $e) {
        if (function_exists('app_log')) {
            app_log('error', $e->getMessage(), ['category' => 'fcm']);
        }
        return null;
    }

    $tokenUri = $serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token';

    $ch = curl_init($tokenUri);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        if (function_exists('app_log')) {
            app_log('error', 'FCM OAuth token exchange failed', [
                'category'  => 'fcm',
                'http_code' => $httpCode,
                'error'     => $curlError ?: substr($response ?? '', 0, 200),
            ]);
        }
        return null;
    }

    $tokenData = json_decode($response, true);
    if (empty($tokenData['access_token'])) {
        if (function_exists('app_log')) {
            app_log('error', 'FCM OAuth response missing access_token', ['category' => 'fcm']);
        }
        return null;
    }

    // Cache the token
    $expiresIn = (int) ($tokenData['expires_in'] ?? 3600);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0750, true);
    }
    file_put_contents($cachePath, json_encode([
        'access_token' => $tokenData['access_token'],
        'expires_at'   => time() + $expiresIn,
    ]), LOCK_EX);

    return $tokenData['access_token'];
}


// ──────────────────────────────────────────────────────────────
// Public functions — same signatures as before (no call-site changes)
// ──────────────────────────────────────────────────────────────

/**
 * Send push notifications to specific users via FCM HTTP v1 API.
 *
 * @param array  $userIds   Array of user IDs to notify
 * @param string $userType  'student' or 'admin'
 * @param string $title     Notification title
 * @param string $body      Notification body text
 * @param array  $data      Optional data payload for deep linking
 * @return array Stats: ['sent' => int, 'failed' => int, 'invalid_tokens' => int]
 */
function send_push_notification(array $userIds, string $userType, string $title, string $body, array $data = []): array
{
    $emptyStats = ['sent' => 0, 'failed' => 0, 'invalid_tokens' => 0];

    if (empty($userIds)) {
        return $emptyStats;
    }

    // Load service account
    $serviceAccount = fcm_load_service_account();
    if ($serviceAccount === null) {
        if (function_exists('app_log')) {
            app_log('warning', 'FCM service account not configured, skipping push notifications', ['category' => 'fcm']);
        }
        return $emptyStats;
    }

    // Get OAuth access token
    $accessToken = fcm_get_access_token($serviceAccount);
    if ($accessToken === null) {
        return $emptyStats;
    }

    // Build v1 API endpoint URL
    $projectId = $serviceAccount['project_id'];
    $fcmUrl = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';

    // Fetch active device tokens for the specified users
    $pdo = get_db();
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id, fcm_token, user_id
        FROM device_tokens
        WHERE user_id IN ($placeholders)
          AND user_type = ?
          AND is_active = 1
    ");
    $params = array_merge($userIds, [$userType]);
    $stmt->execute($params);
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($tokens)) {
        return $emptyStats;
    }

    $stats = ['sent' => 0, 'failed' => 0, 'invalid_tokens' => 0];
    $invalidTokenIds = [];
    $tokenRefreshed = false; // Track if we've already refreshed the OAuth token this batch

    // Ensure all data values are strings (v1 API requirement)
    $dataPayload = array_map('strval', array_merge($data, [
        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
    ]));

    // Send to each device token using FCM HTTP v1 API
    foreach ($tokens as $tokenRow) {
        $payload = json_encode([
            'message' => [
                'token' => $tokenRow['fcm_token'],
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                ],
                'data' => $dataPayload,
                'android' => [
                    'notification' => [
                        'sound'        => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ],
                    ],
                ],
            ],
        ]);

        $result = _fcm_send_single($fcmUrl, $accessToken, $payload);

        if ($result['success']) {
            $stats['sent']++;
        } elseif ($result['unregistered']) {
            // Token is invalid — mark for cleanup
            $invalidTokenIds[] = $tokenRow['id'];
            $stats['invalid_tokens']++;
            $stats['failed']++;
        } elseif ($result['auth_error'] && !$tokenRefreshed) {
            // OAuth token expired mid-batch — refresh once and retry this message
            $tokenRefreshed = true;
            $accessToken = fcm_get_access_token($serviceAccount, null, true);
            if ($accessToken !== null) {
                $retryResult = _fcm_send_single($fcmUrl, $accessToken, $payload);
                if ($retryResult['success']) {
                    $stats['sent']++;
                } else {
                    $stats['failed']++;
                    if ($retryResult['unregistered']) {
                        $invalidTokenIds[] = $tokenRow['id'];
                        $stats['invalid_tokens']++;
                    }
                }
            } else {
                $stats['failed']++;
            }
        } else {
            $stats['failed']++;
            if (function_exists('app_log')) {
                app_log('warning', 'FCM v1 send failed', [
                    'category'   => 'fcm',
                    'http_code'  => $result['http_code'],
                    'error_code' => $result['error_code'],
                    'user_id'    => $tokenRow['user_id'],
                ]);
            }
        }
    }

    // Clean up invalid tokens
    if (!empty($invalidTokenIds)) {
        $placeholders = implode(',', array_fill(0, count($invalidTokenIds), '?'));
        $pdo->prepare("DELETE FROM device_tokens WHERE id IN ($placeholders)")->execute($invalidTokenIds);

        if (function_exists('app_log')) {
            app_log('info', 'Removed ' . count($invalidTokenIds) . ' invalid FCM tokens', [
                'category'  => 'fcm',
                'token_ids' => $invalidTokenIds,
            ]);
        }
    }

    return $stats;
}

/**
 * Send a single FCM message via the v1 API.
 *
 * @param string $url         FCM v1 endpoint URL
 * @param string $accessToken OAuth 2.0 Bearer token
 * @param string $payload     JSON-encoded message payload
 * @return array Result with keys: success, unregistered, auth_error, http_code, error_code
 */
function _fcm_send_single(string $url, string $accessToken, string $payload): array
{
    $result = [
        'success'      => false,
        'unregistered' => false,
        'auth_error'   => false,
        'http_code'    => 0,
        'error_code'   => '',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $result['http_code'] = $httpCode;

    if ($curlError) {
        if (function_exists('app_log')) {
            app_log('warning', 'FCM cURL error', [
                'category' => 'fcm',
                'error'    => $curlError,
            ]);
        }
        return $result;
    }

    if ($httpCode === 200) {
        $result['success'] = true;
        return $result;
    }

    // Parse error response
    $body = json_decode($response, true);
    $errorCode = '';

    // v1 error format: {"error": {"code": 404, "message": "...", "status": "NOT_FOUND", "details": [{"errorCode": "UNREGISTERED"}]}}
    if (isset($body['error'])) {
        $errorStatus = $body['error']['status'] ?? '';

        // Check details array for specific FCM error codes
        if (isset($body['error']['details']) && is_array($body['error']['details'])) {
            foreach ($body['error']['details'] as $detail) {
                if (isset($detail['errorCode'])) {
                    $errorCode = $detail['errorCode'];
                    break;
                }
            }
        }

        $result['error_code'] = $errorCode ?: $errorStatus;

        // Token invalid / unregistered
        if ($httpCode === 404 || $errorCode === 'UNREGISTERED') {
            $result['unregistered'] = true;
        }

        // Authentication failure (token expired or invalid)
        if ($httpCode === 401 || $errorStatus === 'UNAUTHENTICATED') {
            $result['auth_error'] = true;
        }

        // Rate limited
        if ($httpCode === 429) {
            if (function_exists('app_log')) {
                app_log('warning', 'FCM rate limited — consider reducing send frequency', ['category' => 'fcm']);
            }
        }
    }

    return $result;
}

/**
 * Send a push notification to all active users of a given type in a school.
 *
 * @param string $userType  'student' or 'admin'
 * @param string $title     Notification title
 * @param string $body      Notification body
 * @param array  $data      Data payload
 * @return array Stats
 */
function send_push_to_all(string $userType, string $title, string $body, array $data = []): array
{
    $pdo = get_db();
    $params = [$userType, current_school_id()];
    $stmt = $pdo->prepare("SELECT DISTINCT user_id FROM device_tokens WHERE user_type = ? AND school_id = ? AND is_active = 1");
    $stmt->execute($params);
    $userIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'user_id');

    if (empty($userIds)) {
        return ['sent' => 0, 'failed' => 0, 'invalid_tokens' => 0];
    }

    return send_push_notification(array_map('intval', $userIds), $userType, $title, $body, $data);
}
