<?php
/**
 * student_portal.php — Student Dashboard
 *
 * The main landing page after a student logs in.  Shows upcoming
 * classes, attendance history, announcements, and profile info.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/db.php';

require_student();

$theme    = get_theme();
$pdo      = get_db();
$studentId = $_SESSION['user_id'];

// Fetch student profile
$stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $studentId]);
$student = $stmt->fetch();

// Fetch enrolled classes
$classesStmt = $pdo->prepare(
    'SELECT c.* FROM classes c
     JOIN enrollments e ON e.class_id = c.id
     WHERE e.student_id = :sid AND c.is_active = 1
     ORDER BY FIELD(c.day_of_week, "Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"), c.start_time'
);
$classesStmt->execute([':sid' => $studentId]);
$classes = $classesStmt->fetchAll();

// Fetch recent attendance (last 30 records)
$attendStmt = $pdo->prepare(
    'SELECT a.attendance_date, a.status, c.class_name
     FROM attendance a
     JOIN classes c ON c.id = a.class_id
     WHERE a.student_id = :sid
     ORDER BY a.attendance_date DESC
     LIMIT 30'
);
$attendStmt->execute([':sid' => $studentId]);
$attendance = $attendStmt->fetchAll();

// Fetch announcements (latest 5)
$annStmt = $pdo->query(
    'SELECT a.title, a.body, a.created_at, ad.full_name AS author
     FROM announcements a
     LEFT JOIN admins ad ON ad.id = a.posted_by
     ORDER BY a.created_at DESC
     LIMIT 5'
);
$announcements = $annStmt->fetchAll();

// Attendance stats
$statsStmt = $pdo->prepare(
    'SELECT status, COUNT(*) AS cnt FROM attendance WHERE student_id = :sid GROUP BY status'
);
$statsStmt->execute([':sid' => $studentId]);
$statsRows = $statsStmt->fetchAll();
$stats = ['present' => 0, 'absent' => 0, 'late' => 0];
foreach ($statsRows as $r) {
    $stats[$r['status']] = (int)$r['cnt'];
}
$totalClasses = array_sum($stats);
$attendanceRate = $totalClasses > 0 ? round(($stats['present'] / $totalClasses) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal — <?= htmlspecialchars($theme['studio_name']) ?></title>
    <?php if (!empty($theme['favicon_url'])): ?>
        <link rel="icon" href="<?= htmlspecialchars($theme['favicon_url']) ?>">
    <?php endif; ?>
    <style><?= theme_css_vars() ?></style>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="portal-page">

    <!-- Top navigation -->
    <nav class="top-nav">
        <div class="nav-brand">
            <?php if (!empty($theme['logo_url'])): ?>
                <img src="<?= htmlspecialchars($theme['logo_url']) ?>" alt="" class="nav-logo">
            <?php endif; ?>
            <span><?= htmlspecialchars($theme['studio_name']) ?></span>
        </div>
        <div class="nav-user">
            <span class="nav-greeting">Welcome, <?= htmlspecialchars($student['first_name']) ?></span>
            <a href="logout.php" class="btn btn-sm btn-outline">Sign Out</a>
        </div>
    </nav>

    <main class="portal-main">
        <!-- Profile Summary -->
        <section class="card profile-card">
            <h2>My Profile</h2>
            <div class="profile-details">
                <div class="profile-field">
                    <strong>Name:</strong>
                    <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                </div>
                <div class="profile-field">
                    <strong>Belt Rank:</strong>
                    <span class="belt-badge"><?= htmlspecialchars($student['belt_rank']) ?></span>
                </div>
                <div class="profile-field">
                    <strong>Member Since:</strong>
                    <?= htmlspecialchars(date('F j, Y', strtotime($student['join_date']))) ?>
                </div>
                <div class="profile-field">
                    <strong>Email:</strong>
                    <?= htmlspecialchars($student['email'] ?? '—') ?>
                </div>
            </div>
        </section>

        <!-- Stats Row -->
        <section class="stats-row">
            <div class="card stat-card">
                <div class="stat-number"><?= $totalClasses ?></div>
                <div class="stat-label">Total Classes</div>
            </div>
            <div class="card stat-card">
                <div class="stat-number"><?= $attendanceRate ?>%</div>
                <div class="stat-label">Attendance Rate</div>
            </div>
            <div class="card stat-card">
                <div class="stat-number"><?= count($classes) ?></div>
                <div class="stat-label">Enrolled Classes</div>
            </div>
        </section>

        <!-- My Classes -->
        <section class="card">
            <h2>My Schedule</h2>
            <?php if (empty($classes)): ?>
                <p class="empty-state">You are not enrolled in any classes yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Day</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['class_name']) ?></td>
                                <td><?= htmlspecialchars($c['day_of_week']) ?></td>
                                <td>
                                    <?= date('g:i A', strtotime($c['start_time'])) ?>
                                    &ndash;
                                    <?= date('g:i A', strtotime($c['end_time'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <!-- Recent Attendance -->
        <section class="card">
            <h2>Recent Attendance</h2>
            <?php if (empty($attendance)): ?>
                <p class="empty-state">No attendance records yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Class</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance as $a): ?>
                            <tr>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($a['attendance_date']))) ?></td>
                                <td><?= htmlspecialchars($a['class_name']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $a['status'] ?>">
                                        <?= ucfirst($a['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <!-- Announcements -->
        <section class="card">
            <h2>Announcements</h2>
            <?php if (empty($announcements)): ?>
                <p class="empty-state">No announcements at this time.</p>
            <?php else: ?>
                <?php foreach ($announcements as $ann): ?>
                    <div class="announcement">
                        <h3><?= htmlspecialchars($ann['title']) ?></h3>
                        <p class="announcement-meta">
                            <?= htmlspecialchars(date('M j, Y', strtotime($ann['created_at']))) ?>
                            <?php if ($ann['author']): ?>
                                &mdash; <?= htmlspecialchars($ann['author']) ?>
                            <?php endif; ?>
                        </p>
                        <p><?= nl2br(htmlspecialchars($ann['body'])) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

    <footer class="portal-footer">
        <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($theme['studio_name']) ?>. All rights reserved.</p>
    </footer>
</body>
</html>
