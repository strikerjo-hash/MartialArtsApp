<?php
/**
 * api/middleware.php — API response helpers and authentication middleware
 *
 * Provides:
 *   - JSON response helpers (api_json, api_error)
 *   - JWT authentication middleware (api_authenticate, api_require_student, api_require_parent)
 *   - Request parsing helpers (api_input)
 */

// ---------- Response helpers ----------

/**
 * Send a JSON response and exit.
 */
function api_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a JSON error response and exit.
 */
function api_error(string $message, int $status = 400, array $extra = []): void
{
    api_json(array_merge(['error' => true, 'message' => $message], $extra), $status);
}

/**
 * Send a paginated JSON response.
 */
function api_paginated(array $items, int $total, int $page, int $perPage): void
{
    api_json([
        'data'       => $items,
        'pagination' => [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / max(1, $perPage)),
        ],
    ]);
}

// ---------- Request parsing ----------

/**
 * Parse JSON request body. Returns associative array.
 */
function api_input(): array
{
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Get a query parameter with default.
 */
function api_query(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

// ---------- Authentication middleware ----------

/**
 * Authenticate the request via Bearer JWT.
 * Returns the decoded token payload: ['sub', 'type', 'school'].
 * Calls api_error(401) on failure.
 */
function api_authenticate(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        api_error('Missing or invalid Authorization header', 401);
    }

    $token   = trim($matches[1]);
    $payload = jwt_decode($token, jwt_get_secret());

    if (!$payload) {
        api_error('Invalid or expired access token', 401);
    }

    // Set tenant context so school_where() / school_param() work
    $_SESSION['school_id']        = $payload['school'] ?? 1;
    $_SESSION['active_school_id'] = $payload['school'] ?? 1;
    $_SESSION['user_id']          = $payload['sub'];
    $_SESSION['user_type']        = $payload['type'];

    // Verify student account is still active (prevents deactivated students from using cached tokens)
    if ($payload['type'] === 'student') {
        $pdo = get_db();
        $statusStmt = $pdo->prepare("SELECT status FROM students WHERE id = ? LIMIT 1");
        $statusStmt->execute([$payload['sub']]);
        $stuRow = $statusStmt->fetch();
        if (!$stuRow || $stuRow['status'] !== 'active') {
            api_error('Your account has been deactivated or suspended. Please contact administration.', 403);
        }
    }

    return $payload;
}

/**
 * Require the authenticated user to be a student.
 * Returns the token payload.
 */
function api_require_student(): array
{
    $payload = api_authenticate();
    if ($payload['type'] !== 'student') {
        api_error('This endpoint requires student authentication', 403);
    }
    return $payload;
}

/**
 * Require the authenticated user to be a parent (student with is_parent=1).
 * Returns the token payload.
 */
function api_require_parent(): array
{
    $payload = api_authenticate();
    if ($payload['type'] !== 'student') {
        api_error('This endpoint requires parent authentication', 403);
    }

    // Verify is_parent flag
    $pdo  = get_db();
    $stmt = $pdo->prepare("SELECT is_parent FROM students WHERE id = ? LIMIT 1");
    $stmt->execute([$payload['sub']]);
    $row = $stmt->fetch();

    if (!$row || empty($row['is_parent'])) {
        api_error('This endpoint requires a parent account', 403);
    }

    return $payload;
}

/**
 * Require the authenticated user to be an admin.
 * Returns the token payload.
 */
function api_require_admin(): array
{
    $payload = api_authenticate();
    if ($payload['type'] !== 'admin') {
        api_error('This endpoint requires admin authentication', 403);
    }
    return $payload;
}
