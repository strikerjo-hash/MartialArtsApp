<?php
/**
 * api/routes/auth.php — Authentication endpoints
 *
 * POST /auth/login         — Student login → access + refresh tokens
 * POST /auth/admin-login   — Admin login → access + refresh tokens
 * POST /auth/refresh       — Exchange refresh token for new access token
 * POST /auth/logout        — Revoke refresh token
 */

function route_auth(string $method, string $action): void
{
    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    switch ($action) {
        case 'login':
            _auth_login();
            break;
        case 'admin-login':
            _auth_admin_login();
            break;
        case 'refresh':
            _auth_refresh();
            break;
        case 'logout':
            _auth_logout();
            break;
        default:
            api_error('Unknown auth action', 404);
    }
}

// ---------- POST /auth/login ----------

function _auth_login(): void
{
    $input = api_input();

    $email    = trim($input['email'] ?? '');
    $password = trim($input['password'] ?? '');
    $device   = trim($input['device_info'] ?? '');

    if (empty($email) || empty($password)) {
        api_error('Email and password are required', 422);
    }

    // Use existing authenticate_student() from includes/auth.php
    $inactive_status = null;
    $student = authenticate_student($email, $password, '', $inactive_status);

    if (!$student) {
        if ($inactive_status === 'suspended') {
            api_error('Your account has been suspended. Please contact administration.', 403);
        } elseif ($inactive_status) {
            $reason = get_deactivation_reason($email);
            if ($reason === 'payment') {
                api_error('Your account has been deactivated due to an outstanding balance. Please contact us to resolve your payment.', 403);
            } else {
                api_error('Your account has been deactivated. Please contact administration.', 403);
            }
        }
        api_error('Invalid email or password', 401);
    }

    $studentId = (int) $student['id'];
    $schoolId  = (int) ($student['school_id'] ?? 1);

    // Create tokens
    $accessToken  = jwt_create_access_token($studentId, 'student', $schoolId);
    $refreshToken = jwt_create_refresh_token($studentId, 'student', $schoolId, $device);

    // Audit log
    if (function_exists('audit_log')) {
        // Temporarily set session vars for audit_log to read
        $_SESSION['user_id']   = $studentId;
        $_SESSION['user_type'] = 'student';
        $_SESSION['username']  = $student['email'] ?? $student['username'] ?? '';
        $_SESSION['school_id'] = $schoolId;

        audit_log('api_login', [
            'description' => 'API login: ' . ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''),
            'entity_type' => 'student',
            'entity_id'   => $studentId,
        ]);
    }

    api_json([
        'access_token'  => $accessToken,
        'refresh_token' => $refreshToken,
        'token_type'    => 'Bearer',
        'expires_in'    => 3600,
        'user'          => [
            'id'         => $studentId,
            'first_name' => $student['first_name'] ?? '',
            'last_name'  => $student['last_name'] ?? '',
            'email'      => $student['email'] ?? '',
            'phone'      => $student['phone'] ?? '',
            'belt_rank'  => $student['belt_rank'] ?? '',
            'school_id'  => $schoolId,
            'is_parent'  => (int) ($student['is_parent'] ?? 0),
        ],
    ]);
}

// ---------- POST /auth/refresh ----------

function _auth_refresh(): void
{
    $input = api_input();
    $refreshToken = trim($input['refresh_token'] ?? '');

    if (empty($refreshToken)) {
        api_error('Refresh token is required', 422);
    }

    $tokenRow = jwt_validate_refresh_token($refreshToken);
    if (!$tokenRow) {
        api_error('Invalid or expired refresh token', 401);
    }

    // Verify student account is still active before issuing new tokens
    if ($tokenRow['user_type'] === 'student') {
        $pdo = get_db();
        $statusStmt = $pdo->prepare("SELECT status FROM students WHERE id = ? LIMIT 1");
        $statusStmt->execute([$tokenRow['user_id']]);
        $stuRow = $statusStmt->fetch();
        if (!$stuRow || $stuRow['status'] !== 'active') {
            jwt_revoke_refresh_token($refreshToken);
            api_error('Your account has been deactivated or suspended. Please contact administration.', 403);
        }
    }

    // Issue new access token
    $accessToken = jwt_create_access_token(
        (int) $tokenRow['user_id'],
        $tokenRow['user_type'],
        (int) $tokenRow['school_id']
    );

    // Optionally rotate the refresh token for added security
    jwt_revoke_refresh_token($refreshToken);
    $newRefreshToken = jwt_create_refresh_token(
        (int) $tokenRow['user_id'],
        $tokenRow['user_type'],
        (int) $tokenRow['school_id'],
        $tokenRow['device_info'] ?? ''
    );

    api_json([
        'access_token'  => $accessToken,
        'refresh_token' => $newRefreshToken,
        'token_type'    => 'Bearer',
        'expires_in'    => 3600,
    ]);
}

// ---------- POST /auth/logout ----------

function _auth_logout(): void
{
    $payload = api_authenticate(); // Requires valid access token

    $input = api_input();
    $refreshToken = trim($input['refresh_token'] ?? '');

    if (!empty($refreshToken)) {
        jwt_revoke_refresh_token($refreshToken);
    }

    // Audit log
    if (function_exists('audit_log')) {
        audit_log('api_logout', [
            'description' => 'API logout',
            'entity_type' => $payload['type'],
            'entity_id'   => $payload['sub'],
        ]);
    }

    api_json(['message' => 'Logged out successfully']);
}

// ---------- POST /auth/admin-login ----------

function _auth_admin_login(): void
{
    $input = api_input();

    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    $device   = trim($input['device_info'] ?? '');

    if (empty($username) || empty($password)) {
        api_error('Username and password are required', 422);
    }

    // Use existing authenticate_admin() from includes/auth.php
    $admin = authenticate_admin($username, $password);

    if (!$admin) {
        api_error('Invalid username or password', 401);
    }

    $adminId  = (int) $admin['id'];
    $schoolId = (int) ($admin['school_id'] ?? 1);
    $role     = $admin['role'] ?? 'admin';

    // Create tokens with user_type='admin'
    $accessToken  = jwt_create_access_token($adminId, 'admin', $schoolId);
    $refreshToken = jwt_create_refresh_token($adminId, 'admin', $schoolId, $device);

    // Audit log
    if (function_exists('audit_log')) {
        $_SESSION['user_id']   = $adminId;
        $_SESSION['user_type'] = 'admin';
        $_SESSION['username']  = $admin['username'] ?? '';
        $_SESSION['role']      = $role;
        $_SESSION['school_id'] = $schoolId;

        audit_log('api_admin_login', [
            'description' => 'API admin login: ' . ($admin['full_name'] ?? $admin['username'] ?? ''),
            'entity_type' => 'user',
            'entity_id'   => $adminId,
        ]);
    }

    api_json([
        'access_token'  => $accessToken,
        'refresh_token' => $refreshToken,
        'token_type'    => 'Bearer',
        'expires_in'    => 3600,
        'user'          => [
            'id'        => $adminId,
            'username'  => $admin['username'] ?? '',
            'full_name' => $admin['full_name'] ?? '',
            'email'     => $admin['email'] ?? '',
            'role'      => $role,
            'school_id' => $schoolId,
        ],
    ]);
}
