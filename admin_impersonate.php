<?php
/**
 * admin_impersonate.php — Start or Stop Admin Impersonation
 *
 * POST actions:
 *   start_student — Begin impersonating a student (requires student_id)
 *   start_parent  — Begin impersonating a parent (requires parent_id)
 *   stop          — End impersonation, restore admin session
 *
 * GET ?stop=1 also ends impersonation (safe — only restores own session).
 */

require_once 'config.php';
require_once __DIR__ . '/includes/impersonation.php';

// ── Stop impersonation (GET or POST) ──
if (isset($_GET['stop']) || (isset($_POST['action']) && $_POST['action'] === 'stop')) {
    stop_impersonation();
    header('Location: index.php');
    exit;
}

// ── All start actions require POST + admin login + CSRF ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

requireLogin(); // Ensures user_type === 'admin'
verify_csrf();

$pdo    = get_db();
$action = $_POST['action'] ?? '';

// ── Start Student Impersonation ──
if ($action === 'start_student') {
    $studentId = (int) ($_POST['student_id'] ?? 0);
    if (!$studentId) {
        header('Location: students.php');
        exit;
    }

    // Fetch the student (scoped to school for tenant isolation)
    $params = [$studentId];
    $sql    = "SELECT * FROM students WHERE id = ?" . school_where() . " LIMIT 1";
    school_param($params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $student = $stmt->fetch();

    if (!$student) {
        header('Location: students.php');
        exit;
    }

    start_impersonation_student($student);

    // Redirect to appropriate portal
    if (!empty($student['is_parent']) && (int)$student['is_parent'] === 1) {
        header('Location: parent_portal.php');
    } else {
        header('Location: student_portal.php');
    }
    exit;
}

// ── Start Parent Impersonation ──
if ($action === 'start_parent') {
    $parentId = (int) ($_POST['parent_id'] ?? 0);
    if (!$parentId) {
        header('Location: index.php');
        exit;
    }

    $params = [$parentId];
    $sql    = "SELECT * FROM parents WHERE id = ?" . school_where() . " LIMIT 1";
    school_param($params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $parent = $stmt->fetch();

    if (!$parent) {
        header('Location: index.php');
        exit;
    }

    start_impersonation_parent($parent);
    header('Location: parent_portal.php');
    exit;
}

// Unknown action — redirect home
header('Location: index.php');
exit;
