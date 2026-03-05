<?php
/**
 * api/routes/parent.php — Parent-facing API endpoints
 *
 * Parents are students with is_parent=1, linked to children via parent_students table.
 * All endpoints require a valid student access token with parent privileges.
 *
 * GET  /parent/children               — List linked children
 * GET  /parent/child/{id}/profile     — Child profile
 * GET  /parent/child/{id}/classes     — Child's enrolled classes
 * GET  /parent/child/{id}/attendance  — Child's attendance history
 * GET  /parent/child/{id}/memberships — Child's memberships
 * GET  /parent/child/{id}/events      — Child's event registrations
 * GET  /parent/child/{id}/belts       — Child's belt progress
 */

function route_parent(string $method, string $path): void
{
    $payload  = api_require_parent();
    $parentId = (int) $payload['sub'];
    $schoolId = (int) $payload['school'];
    $pdo      = get_db();

    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    switch (true) {
        case $path === 'children':
            _parent_children($pdo, $parentId, $schoolId);
            break;

        case preg_match('#^child/(\d+)/(.+)$#', $path, $m) === 1:
            $childId  = (int) $m[1];
            $resource = $m[2];
            _parent_child_resource($pdo, $parentId, $childId, $schoolId, $resource);
            break;

        default:
            api_error('Parent endpoint not found', 404);
    }
}

// ---------- GET /parent/children ----------

function _parent_children(PDO $pdo, int $parentId, int $schoolId): void
{
    $stmt = $pdo->prepare("
        SELECT s.id, s.first_name, s.last_name, s.email, s.phone,
               s.date_of_birth, s.belt_rank, s.status, s.activity_status, s.photo,
               ps.relationship
        FROM parent_students ps
        JOIN students s ON ps.student_id = s.id
        WHERE ps.parent_id = ? AND s.school_id = ?
        ORDER BY s.first_name, s.last_name
    ");
    $stmt->execute([$parentId, $schoolId]);

    api_json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ---------- GET /parent/child/{id}/{resource} ----------

function _parent_child_resource(PDO $pdo, int $parentId, int $childId, int $schoolId, string $resource): void
{
    // Verify parent-child link exists
    $check = $pdo->prepare("SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ? LIMIT 1");
    $check->execute([$parentId, $childId]);
    if (!$check->fetch()) {
        api_error('Child not linked to this parent account', 403);
    }

    // Reuse student route functions — they accept studentId + schoolId
    require_once __DIR__ . '/student.php';

    switch ($resource) {
        case 'profile':
            _student_profile($pdo, $childId, $schoolId);
            break;
        case 'classes':
            _student_classes($pdo, $childId, $schoolId);
            break;
        case 'attendance':
            _student_attendance($pdo, $childId, $schoolId);
            break;
        case 'memberships':
            _student_memberships($pdo, $childId, $schoolId);
            break;
        case 'events':
            _student_events($pdo, $childId, $schoolId);
            break;
        case 'belts':
            _student_belts($pdo, $childId, $schoolId);
            break;
        default:
            api_error('Unknown child resource: ' . $resource, 404);
    }
}
