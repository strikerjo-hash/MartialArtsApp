<?php
/**
 * AJAX Student Search Endpoint
 *
 * GET params:
 *   q        — search string (first_name, last_name, email, username)
 *   context  — filtering mode:
 *              "active"             (default) all active students
 *              "unlinked:{parentId}" students NOT linked to this parent
 *              "unenrolled:{classId}" students NOT enrolled in this class
 *   exclude  — comma-separated student IDs to exclude
 *   limit    — max results (default 50)
 *
 * Returns JSON:
 *   { success: true, students: [ { id, name, email, username, extra }, ... ] }
 */

require_once __DIR__ . '/config.php';
requireLogin();

header('Content-Type: application/json');

$q       = trim($_GET['q'] ?? '');
$context = trim($_GET['context'] ?? 'active');
$exclude = trim($_GET['exclude'] ?? '');
$limit   = max(1, min(100, (int)($_GET['limit'] ?? 50)));

$search  = '%' . $q . '%';

// Build base SQL
$sql    = "SELECT s.id, s.first_name, s.last_name, s.email, s.username FROM students s WHERE s.status = 'active'";
$params = [];

// Multi-tenancy
$sql .= school_where('s');
school_param($params);

// Search filter
if ($q !== '') {
    $sql .= " AND (
        CONCAT(s.first_name, ' ', s.last_name) LIKE ?
        OR s.first_name LIKE ?
        OR s.last_name LIKE ?
        OR s.email LIKE ?
        OR s.username LIKE ?
    )";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Context-specific filtering
if (strpos($context, 'unlinked:') === 0) {
    $parentId = (int) substr($context, 9);
    if ($parentId > 0) {
        $sql .= " AND s.id != ? AND s.id NOT IN (
            SELECT ps.student_id FROM parent_students ps WHERE ps.parent_id = ?
        )";
        $params[] = $parentId;
        $params[] = $parentId;
    }
} elseif (strpos($context, 'unenrolled:') === 0) {
    $classId = (int) substr($context, 11);
    if ($classId > 0) {
        $sql .= " AND s.id NOT IN (
            SELECT ce.student_id FROM class_enrollments ce
            WHERE ce.class_id = ? AND ce.status = 'active'" . school_where('ce') . "
        )";
        $params[] = $classId;
        school_param($params);
    }
}

// Exclude specific IDs
if ($exclude !== '') {
    $excludeIds = array_filter(array_map('intval', explode(',', $exclude)));
    if (!empty($excludeIds)) {
        $ph = implode(',', array_fill(0, count($excludeIds), '?'));
        $sql .= " AND s.id NOT IN ($ph)";
        $params = array_merge($params, $excludeIds);
    }
}

$sql .= " ORDER BY s.first_name, s.last_name LIMIT ?";
$params[] = $limit;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $students = [];
    foreach ($rows as $r) {
        $students[] = [
            'id'       => (int) $r['id'],
            'name'     => trim($r['first_name'] . ' ' . $r['last_name']),
            'email'    => $r['email'] ?? '',
            'username' => $r['username'] ?? '',
        ];
    }

    echo json_encode(['success' => true, 'students' => $students]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
