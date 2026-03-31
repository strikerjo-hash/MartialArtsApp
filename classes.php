<?php
require_once 'config.php';
requireLogin();

// Migrations have been moved to migrate.php

$message = '';

// AJAX handler for schedule drag-and-drop updates (must come BEFORE verify_csrf)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $classId = (int)($_POST['class_id'] ?? 0);
    $newDay = $_POST['day_of_week'] ?? '';
    $newRoomId = isset($_POST['room_id']) ? (int)$_POST['room_id'] : null;
    $newStartTime = $_POST['start_time'] ?? null;
    $validDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    if (!$classId || !in_array($newDay, $validDays)) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        exit;
    }
    try {
        $updates = ['day_of_week = ?'];
        $params = [$newDay];

        if ($newRoomId) {
            $updates[] = 'room_id = ?';
            $params[] = $newRoomId;
        }

        if ($newStartTime && preg_match('/^\d{2}:\d{2}$/', $newStartTime)) {
            // Preserve class duration: compute new end_time from old duration
            $old = $pdo->prepare("SELECT start_time, end_time FROM classes WHERE id = ?");
            $old->execute([$classId]);
            $oldRow = $old->fetch();
            if ($oldRow) {
                $oldDuration = strtotime($oldRow['end_time']) - strtotime($oldRow['start_time']);
                $newEndTime = date('H:i:s', strtotime($newStartTime) + $oldDuration);
                $updates[] = 'start_time = ?';
                $params[] = $newStartTime . ':00';
                $updates[] = 'end_time = ?';
                $params[] = $newEndTime;
            }
        }

        $params[] = $classId;
        $sql = 'UPDATE classes SET ' . implode(', ', $updates) . ' WHERE id = ?' . school_where();
        school_param($params);
        $pdo->prepare($sql)->execute($params);

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $stmt = $pdo->prepare("
                    INSERT INTO classes (school_id, name, style_id, instructor_id, day_of_week, start_time,
                                       end_time, max_students, skill_level, description, status, room_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    current_school_id(),
                    sanitizeInput($_POST['name']),
                    $_POST['style_id'],
                    $_POST['instructor_id'] ?: null,
                    $_POST['day_of_week'],
                    $_POST['start_time'],
                    $_POST['end_time'],
                    $_POST['max_students'] ?: 20,
                    $_POST['skill_level'],
                    sanitizeInput($_POST['description']),
                    $_POST['status'],
                    $_POST['room_id'] ?: null
                ]);

                // Copy to other schools if requested
                $copyResults = '';
                if (!empty($_POST['copy_to_schools']) && is_super_admin()) {
                    require_once __DIR__ . '/includes/program_copy_helpers.php';
                    $newClassId = (int)$pdo->lastInsertId();
                    $copyCount = 0;
                    foreach ($_POST['copy_to_schools'] as $targetSchoolId) {
                        if (copy_class_to_school($newClassId, (int)$targetSchoolId)) {
                            $copyCount++;
                        }
                    }
                    if ($copyCount > 0) {
                        $copyResults = " Also copied to $copyCount other school(s).";
                    }
                }

                $message = showAlert('Class created successfully!' . $copyResults, 'success');
                break;

            case 'enroll':
                $student_id = $_POST['student_id'];
                $class_id   = $_POST['class_id'];
                $admin_override = isset($_POST['admin_override']) ? true : false;

                // --- Enrollment enforcement ---
                $enrollment_error = '';

                // Check if student is already actively enrolled in this class
                $alreadyEnrolledParams = [$student_id, $class_id];
                $alreadyEnrolled = $pdo->prepare("SELECT id FROM class_enrollments WHERE student_id = ? AND class_id = ? AND status = 'active'" . school_where());
                school_param($alreadyEnrolledParams);
                $alreadyEnrolled->execute($alreadyEnrolledParams);
                if ($alreadyEnrolled->fetch()) {
                    $enrollment_error = 'This student is already enrolled in this class.';
                }

                if (!$enrollment_error && !$admin_override) {
                    // 1. Check for active membership
                    $memCheck = $pdo->prepare("
                        SELECT m.id, mp.classes_per_week
                        FROM memberships m
                        JOIN membership_plans mp ON m.plan_id = mp.id
                        WHERE m.student_id = ? AND m.status = 'active' AND m.end_date >= CURDATE()
                        ORDER BY mp.classes_per_week DESC
                        LIMIT 1
                    ");
                    $memCheck->execute([$student_id]);
                    $active_membership = $memCheck->fetch();

                    if (!$active_membership) {
                        $enrollment_error = 'Cannot enroll: This student does not have an active membership. Please add a membership first or use Admin Override.';
                    } else {
                        // 2. Check classes_per_week limit
                        $classes_per_week = (int)$active_membership['classes_per_week'];

                        if ($classes_per_week < 99) { // 99 = unlimited
                            // Count current active enrollments
                            $enrollCount = $pdo->prepare("
                                SELECT COUNT(*) as cnt
                                FROM class_enrollments
                                WHERE student_id = ? AND status = 'active'
                            ");
                            $enrollCount->execute([$student_id]);
                            $current_count = (int)$enrollCount->fetch()['cnt'];

                            if ($current_count >= $classes_per_week) {
                                $enrollment_error = 'Cannot enroll: This student is already enrolled in '
                                    . $current_count . ' class(es). Their plan allows a maximum of '
                                    . $classes_per_week . ' classes per week. Upgrade their plan or use Admin Override.';
                            }
                        }
                    }
                }

                if ($enrollment_error) {
                    $message = showAlert($enrollment_error, 'error');
                } else {
                    try {
                        // Use ON DUPLICATE KEY UPDATE to handle re-enrollment of previously dropped students
                        $stmt = $pdo->prepare("
                            INSERT INTO class_enrollments (school_id, student_id, class_id, enrollment_date, status)
                            VALUES (?, ?, ?, CURDATE(), 'active')
                            ON DUPLICATE KEY UPDATE status = 'active', enrollment_date = CURDATE()
                        ");
                        $stmt->execute([current_school_id(), $student_id, $class_id]);
                        $override_note = $admin_override ? ' (Admin Override)' : '';
                        $message = showAlert('Student enrolled successfully!' . $override_note, 'success');
                    } catch (PDOException $e) {
                        $message = showAlert('Error enrolling student: ' . $e->getMessage(), 'error');
                    }
                }
                break;

            case 'unenroll':
                $pdo->prepare("UPDATE class_enrollments SET status = 'dropped' WHERE id = ?")->execute([$_POST['enrollment_id']]);
                $message = showAlert('Student unenrolled successfully!', 'success');
                break;

            case 'bulk_unenroll':
                $enrollmentIds = $_POST['enrollment_ids'] ?? [];
                if (!empty($enrollmentIds) && is_array($enrollmentIds)) {
                    $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
                    $params = array_map('intval', $enrollmentIds);
                    // Only unenroll enrollments belonging to this school
                    $params[] = current_school_id();
                    $stmt = $pdo->prepare("
                        UPDATE class_enrollments SET status = 'dropped'
                        WHERE id IN ({$placeholders}) AND school_id = ?
                    ");
                    $stmt->execute($params);
                    $count = $stmt->rowCount();
                    $message = showAlert("{$count} student(s) unenrolled successfully!", 'success');
                } else {
                    $message = showAlert('No students selected for unenrollment.', 'error');
                }
                break;

            case 'edit':
                $editParams = [
                    sanitizeInput($_POST['name']),
                    $_POST['style_id'],
                    $_POST['instructor_id'] ?: null,
                    $_POST['day_of_week'],
                    $_POST['start_time'],
                    $_POST['end_time'],
                    $_POST['max_students'] ?: 20,
                    $_POST['skill_level'],
                    sanitizeInput($_POST['description']),
                    $_POST['status'],
                    $_POST['room_id'] ?: null,
                    $_POST['class_id']
                ];
                $stmt = $pdo->prepare("
                    UPDATE classes SET name=?, style_id=?, instructor_id=?, day_of_week=?,
                        start_time=?, end_time=?, max_students=?, skill_level=?, description=?, status=?, room_id=?
                    WHERE id = ?" . school_where() . "
                ");
                school_param($editParams);
                $stmt->execute($editParams);
                $message = showAlert('Class updated successfully!', 'success');
                break;

            case 'delete':
                $deleteParams = [$_POST['class_id']];
                $stmt = $pdo->prepare("DELETE FROM classes WHERE id = ?" . school_where());
                school_param($deleteParams);
                $stmt->execute($deleteParams);
                $message = showAlert('Class deleted successfully!', 'success');
                break;
        }
    }
}

// Get all classes with enrollment counts
$day_filter = $_GET['day'] ?? '';
$room_filter = $_GET['room'] ?? '';

// Fetch active rooms for dropdowns, filters, and tabs
$roomParams = [];
$roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE status='active'" . school_where() . " ORDER BY sort_order, name");
school_param($roomParams);
$roomStmt->execute($roomParams);
$rooms = $roomStmt->fetchAll();
// Also fetch all rooms (including inactive) for display on existing classes
$allRoomParams = [];
$allRoomStmt = $pdo->prepare("SELECT * FROM rooms WHERE 1=1" . school_where() . " ORDER BY sort_order, name");
school_param($allRoomParams);
$allRoomStmt->execute($allRoomParams);
$allRooms = $allRoomStmt->fetchAll();

$query = "
    SELECT c.*,
           u.full_name as instructor_name,
           mas.name as style_name,
           r.name as room_name,
           r.status as room_status,
           COUNT(ce.id) as enrolled_count
    FROM classes c
    LEFT JOIN users u ON c.instructor_id = u.id
    LEFT JOIN martial_arts_styles mas ON c.style_id = mas.id
    LEFT JOIN rooms r ON c.room_id = r.id
    LEFT JOIN class_enrollments ce ON c.id = ce.class_id AND ce.status = 'active'
    WHERE 1=1 AND c.school_id = :school_id
";

if ($day_filter) {
    $query .= " AND c.day_of_week = :day";
}
if ($room_filter) {
    $query .= " AND c.room_id = :room";
}

$query .= " GROUP BY c.id ORDER BY
    FIELD(c.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
    c.start_time ASC";

$stmt = $pdo->prepare($query);
$stmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT);
if ($day_filter) {
    $stmt->bindValue(':day', $day_filter);
}
if ($room_filter) {
    $stmt->bindValue(':room', (int)$room_filter, PDO::PARAM_INT);
}
$stmt->execute();
$classes = $stmt->fetchAll();

// Get martial arts styles
$styles = $pdo->query("SELECT * FROM martial_arts_styles ORDER BY name")->fetchAll();

// Get instructors (via user_schools junction for multi-school support)
$instructorParams = [];
$instructorStmt = $pdo->prepare("SELECT DISTINCT u.id, u.full_name FROM users u INNER JOIN user_schools us ON u.id = us.user_id WHERE u.role IN ('admin', 'super_admin', 'instructor')" . school_where('us') . " ORDER BY u.full_name");
school_param($instructorParams);
$instructorStmt->execute($instructorParams);
$instructors = $instructorStmt->fetchAll();

// Get students for enrollment (with their membership info)
$studentParams = [];
$studentStmt = $pdo->prepare("
    SELECT s.id, s.first_name, s.last_name,
           m.status as mem_status, mp.classes_per_week,
           (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.student_id = s.id AND ce.status = 'active' AND ce.school_id = s.school_id) as current_enrollments
    FROM students s
    LEFT JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
    LEFT JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE s.status = 'active'" . school_where('s') . "
    GROUP BY s.id
    ORDER BY s.first_name, s.last_name
");
school_param($studentParams);
$studentStmt->execute($studentParams);
$students = $studentStmt->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Classes</h1>
        <button onclick="openAddModal()"
                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
            + Add Class
        </button>
    </div>

    <!-- View Toggle + Day Filter + Room Filter -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap gap-2">
                <a href="classes.php<?php echo $room_filter ? '?room='.$room_filter : ''; ?>" class="px-4 py-2 rounded-lg text-sm <?php echo $day_filter === '' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">
                    All Days
                </a>
                <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day): ?>
                    <a href="?day=<?php echo $day; ?><?php echo $room_filter ? '&room='.$room_filter : ''; ?>"
                       class="px-4 py-2 rounded-lg text-sm <?php echo $day_filter === $day ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">
                        <?php echo $day; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="flex items-center gap-3">
                <?php if (count($rooms) > 1): ?>
                <select onchange="filterByRoom(this.value)"
                        class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-blue-500">
                    <option value="">All Rooms</option>
                    <?php foreach ($rooms as $room): ?>
                        <option value="<?php echo $room['id']; ?>" <?php echo $room_filter == $room['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($room['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-1">
                    <button id="btnCardView" onclick="setView('cards')"
                            class="px-4 py-1.5 rounded-md text-sm font-medium bg-white shadow text-gray-800">
                        Cards
                    </button>
                    <button id="btnScheduleView" onclick="setView('schedule')"
                            class="px-4 py-1.5 rounded-md text-sm font-medium text-gray-500 hover:text-gray-700">
                        Schedule
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Classes by Day (Card View) -->
    <div id="cardView">
    <?php
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    if ($day_filter) {
        $days = [$day_filter];
    }

    foreach ($days as $day):
        $day_classes = array_filter($classes, fn($c) => $c['day_of_week'] === $day);
        if (empty($day_classes) && $day_filter) continue;
    ?>
        <div class="mb-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-4"><?php echo $day; ?></h2>

            <?php if (empty($day_classes)): ?>
                <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                    <p>No classes scheduled for <?php echo $day; ?></p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($day_classes as $class): ?>
                        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                            <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-4">
                                <h3 class="text-lg font-bold mb-1"><?php echo $class['name']; ?></h3>
                                <p class="text-sm opacity-90"><?php echo $class['style_name']; ?></p>
                            </div>

                            <div class="p-4">
                                <div class="space-y-2 mb-4">
                                    <div class="flex items-center text-sm text-gray-600">
                                        <span class="mr-2">&#9200;</span>
                                        <span>
                                            <?php echo date('g:i A', strtotime($class['start_time'])); ?> -
                                            <?php echo date('g:i A', strtotime($class['end_time'])); ?>
                                        </span>
                                    </div>

                                    <?php if ($class['instructor_name']): ?>
                                        <div class="flex items-center text-sm text-gray-600">
                                            <span class="mr-2">&#128104;&#8205;&#127891;</span>
                                            <span><?php echo $class['instructor_name']; ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex items-center text-sm text-gray-600">
                                        <span class="mr-2">&#128101;</span>
                                        <span>
                                            <?php echo $class['enrolled_count']; ?> / <?php echo $class['max_students']; ?> students
                                        </span>
                                    </div>

                                    <div class="flex items-center text-sm text-gray-600">
                                        <span class="mr-2">&#128202;</span>
                                        <span><?php echo skillLevelLabel($class['skill_level']); ?></span>
                                    </div>

                                    <?php if ($class['room_name']): ?>
                                        <div class="flex items-center text-sm text-gray-600">
                                            <span class="mr-2">&#127970;</span>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                                                <?php echo htmlspecialchars($class['room_name']); ?>
                                                <?php if ($class['room_status'] === 'inactive'): ?>
                                                    <span class="ml-1 text-red-500">(Inactive)</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($class['description']): ?>
                                    <p class="text-sm text-gray-600 mb-4"><?php echo $class['description']; ?></p>
                                <?php endif; ?>

                                <div class="flex items-center justify-between pt-4 border-t border-gray-200">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $class['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                        <?php echo ucfirst($class['status']); ?>
                                    </span>

                                    <div class="space-x-2">
                                        <button onclick="enrollStudent(<?php echo $class['id']; ?>, '<?php echo addslashes($class['name']); ?>')"
                                                class="text-blue-600 hover:text-blue-900 text-sm">
                                            Enroll
                                        </button>
                                        <button onclick="viewEnrolled(<?php echo $class['id']; ?>, '<?php echo addslashes($class['name']); ?>')"
                                                class="text-green-600 hover:text-green-900 text-sm">
                                            Roster
                                        </button>
                                        <button onclick="editClass(<?php echo htmlspecialchars(json_encode($class), ENT_QUOTES); ?>)"
                                                class="text-yellow-600 hover:text-yellow-900 text-sm">
                                            Edit
                                        </button>
                                        <button onclick="copyClass(<?php echo htmlspecialchars(json_encode($class), ENT_QUOTES); ?>)"
                                                class="text-purple-600 hover:text-purple-900 text-sm">
                                            Copy
                                        </button>
                                        <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this class?')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="class_id" value="<?php echo $class['id']; ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-900 text-sm">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div><!-- /cardView -->

    <!-- Weekly Schedule (Kanban View with Time Slots) -->
    <div id="scheduleView" class="hidden">
        <?php if (count($rooms) > 1): ?>
        <div class="flex flex-wrap gap-2 mb-4" id="roomTabs">
            <button onclick="filterKanbanRoom('')"
                    class="room-tab px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white"
                    data-room="">
                All Rooms
            </button>
            <?php foreach ($rooms as $room): ?>
                <button onclick="filterKanbanRoom('<?php echo $room['id']; ?>')"
                        class="room-tab px-4 py-2 rounded-lg text-sm font-medium bg-gray-200 text-gray-700 hover:bg-gray-300"
                        data-room="<?php echo $room['id']; ?>">
                    <?php echo htmlspecialchars($room['name']); ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php
        $hoursOfOp = getHoursOfOperation();
        $slotInterval = getScheduleSlotInterval();
        $dayNames = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

        // Build per-frame slot data with 1-hour padding
        $frameSlotsData = [];
        foreach ($hoursOfOp as $fi => $frame) {
            $frameSlotsData[$fi] = generateTimeSlotsForFrame($frame, $slotInterval);
        }

        // Assign each class to the best board (frame index), or mark as orphan
        $classBoardAssignment = []; // classId => frameIndex
        $orphanedClasses = [];

        foreach ($classes as $c) {
            $classStartTs = strtotime($c['start_time']);
            $assigned = false;

            // Priority 1: class falls within a frame's actual operating hours
            foreach ($hoursOfOp as $fi => $frame) {
                $fStart = strtotime($frame['start']);
                $fEnd   = strtotime($frame['end']);
                if ($classStartTs >= $fStart && $classStartTs < $fEnd) {
                    $classBoardAssignment[$c['id']] = $fi;
                    $assigned = true;
                    break;
                }
            }

            // Priority 2: class falls in a padding zone — assign to nearest frame
            if (!$assigned) {
                $bestFrame = null;
                $bestDistance = PHP_INT_MAX;
                foreach ($hoursOfOp as $fi => $frame) {
                    $pStart = strtotime($frameSlotsData[$fi]['slots'][0] ?? $frame['start']);
                    $pEnd   = strtotime(end($frameSlotsData[$fi]['slots']) ?? $frame['end']);
                    if (is_array($frameSlotsData[$fi]['slots']) && !empty($frameSlotsData[$fi]['slots'])) {
                        $pStart = strtotime($frameSlotsData[$fi]['slots'][0]);
                        $lastSlot = $frameSlotsData[$fi]['slots'][count($frameSlotsData[$fi]['slots']) - 1];
                        $pEnd = strtotime($lastSlot) + ($slotInterval * 60);
                    }
                    if ($classStartTs >= $pStart && $classStartTs < $pEnd) {
                        $fStart = strtotime($frame['start']);
                        $fEnd   = strtotime($frame['end']);
                        $dist = min(abs($classStartTs - $fStart), abs($classStartTs - $fEnd));
                        if ($dist < $bestDistance) {
                            $bestDistance = $dist;
                            $bestFrame = $fi;
                        }
                    }
                }
                if ($bestFrame !== null) {
                    $classBoardAssignment[$c['id']] = $bestFrame;
                    $assigned = true;
                }
            }

            if (!$assigned) {
                $orphanedClasses[] = $c;
            }
        }
        ?>

        <style>
            .kanban-grid { min-width: 900px; }
            .kanban-cell { border-right: 1px solid #e5e7eb; border-bottom: 1px solid #f3f4f6; }
            .kanban-cell:hover { background-color: #f0f9ff; }
            .kanban-cell-padding { background-color: #f9fafb; border-right: 1px solid #e5e7eb; border-bottom: 1px solid #f3f4f6; }
            .kanban-cell-padding:hover { background-color: #f3f4f6; }
            .kanban-time-padding { color: #d1d5db; }
        </style>

        <?php foreach ($hoursOfOp as $frameIndex => $frame):
            $frameSlotInfo = $frameSlotsData[$frameIndex];
            $frameLabel = htmlspecialchars($frame['label']);
            $startFormatted = date('g:i A', strtotime($frame['start']));
            $endFormatted   = date('g:i A', strtotime($frame['end']));
        ?>

        <div class="mb-8">
            <h3 class="text-lg font-bold text-gray-700 mb-3">
                <i class="fas fa-clock text-blue-500 mr-1"></i>
                <?php echo $frameLabel; ?>
                <span class="text-sm font-normal text-gray-500">
                    (<?php echo $startFormatted; ?> &ndash; <?php echo $endFormatted; ?>)
                </span>
            </h3>

            <div class="overflow-x-auto pb-2">
                <div class="kanban-grid grid gap-px bg-gray-200 rounded-lg overflow-hidden"
                     style="grid-template-columns: 80px repeat(7, minmax(120px, 1fr));">

                    <!-- Header row: corner + day names -->
                    <div class="bg-gray-100 p-2 text-xs font-bold text-gray-500 text-center sticky top-0 z-10">Time</div>
                    <?php foreach ($dayNames as $kday): ?>
                    <div class="bg-gray-100 p-2 sticky top-0 z-10">
                        <h3 class="font-bold text-gray-700 text-sm text-center uppercase tracking-wider"><?php echo substr($kday, 0, 3); ?></h3>
                    </div>
                    <?php endforeach; ?>

                    <!-- Time slot rows -->
                    <?php foreach ($frameSlotInfo['slots'] as $slot):
                        $slotTs = strtotime($slot);
                        $frameStartTs = strtotime($frame['start']);
                        $frameEndTs   = strtotime($frame['end']);
                        $isPadding = ($slotTs < $frameStartTs || $slotTs >= $frameEndTs);
                    ?>
                        <!-- Time label cell -->
                        <div class="bg-white p-1 text-xs text-right pr-2 flex items-center justify-end <?php echo $isPadding ? 'kanban-time-padding' : 'text-gray-500'; ?>"
                             style="font-size: 11px; min-height: 52px;">
                            <?php echo date('g:i A', $slotTs); ?>
                        </div>

                        <!-- Day cells for this time slot -->
                        <?php foreach ($dayNames as $kday): ?>
                        <div class="<?php echo $isPadding ? 'kanban-cell-padding' : 'bg-white kanban-cell'; ?> p-0.5 min-h-[52px] transition-colors"
                             data-day="<?php echo $kday; ?>"
                             data-time="<?php echo $slot; ?>"
                             ondragover="event.preventDefault(); this.classList.add('bg-blue-50')"
                             ondragleave="this.classList.remove('bg-blue-50')"
                             ondrop="handleDrop(event, '<?php echo $kday; ?>', '<?php echo $slot; ?>'); this.classList.remove('bg-blue-50')">
                            <?php
                            // Render cards assigned to this board whose start_time falls in this slot
                            $slotStart = $slotTs;
                            $slotEnd = $slotStart + ($slotInterval * 60);
                            $slotClasses = array_filter($classes, function($c) use ($kday, $slotStart, $slotEnd, $frameIndex, $classBoardAssignment) {
                                if ($c['day_of_week'] !== $kday) return false;
                                if (($classBoardAssignment[$c['id']] ?? -1) !== $frameIndex) return false;
                                $classStart = strtotime($c['start_time']);
                                return $classStart >= $slotStart && $classStart < $slotEnd;
                            });

                            // Group by room for side-by-side display
                            $byRoom = [];
                            foreach ($slotClasses as $sc) {
                                $rKey = (int)($sc['room_id'] ?? 0);
                                $byRoom[$rKey][] = $sc;
                            }
                            $roomCount = count($byRoom);

                            if ($roomCount > 1): ?>
                            <div class="flex gap-0.5 h-full">
                                <?php foreach ($byRoom as $roomId => $roomClasses): ?>
                                <div class="flex-1 min-w-0">
                                    <?php foreach ($roomClasses as $kclass): ?>
                                    <div class="bg-white rounded shadow-sm p-1.5 cursor-move border-l-4 <?php echo $kclass['status'] === 'active' ? 'border-blue-500' : 'border-gray-300'; ?> hover:shadow-md transition-shadow kanban-card text-xs mb-0.5"
                                         draggable="true"
                                         data-class-id="<?php echo $kclass['id']; ?>"
                                         data-room-id="<?php echo $kclass['room_id'] ?? ''; ?>"
                                         data-start-time="<?php echo $kclass['start_time']; ?>"
                                         data-end-time="<?php echo $kclass['end_time']; ?>"
                                         ondragstart="handleDragStart(event, <?php echo $kclass['id']; ?>)">
                                        <?php if ($kclass['room_name']): ?>
                                            <span class="inline-block w-full px-1 py-0.5 rounded text-[9px] font-bold bg-indigo-100 text-indigo-700 mb-0.5 truncate text-center">
                                                <?php echo htmlspecialchars($kclass['room_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <div class="font-semibold text-gray-800 truncate" title="<?php echo htmlspecialchars($kclass['name']); ?>">
                                            <?php echo htmlspecialchars($kclass['name']); ?>
                                        </div>
                                        <div class="text-blue-600 font-medium">
                                            <?php echo date('g:i', strtotime($kclass['start_time'])); ?>-<?php echo date('g:i A', strtotime($kclass['end_time'])); ?>
                                        </div>
                                        <?php if ($kclass['instructor_name']): ?>
                                            <div class="text-gray-400 truncate"><?php echo htmlspecialchars($kclass['instructor_name']); ?></div>
                                        <?php endif; ?>
                                        <div class="flex items-center justify-between mt-1 pt-0.5 border-t border-gray-100">
                                            <span class="text-gray-400"><?php echo $kclass['enrolled_count']; ?>/<?php echo $kclass['max_students']; ?></span>
                                            <button onclick="editClass(<?php echo htmlspecialchars(json_encode($kclass), ENT_QUOTES); ?>); event.stopPropagation();"
                                                    class="text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else:
                            // Single room (or no room) — normal stacked layout
                            foreach ($slotClasses as $kclass):
                            ?>
                            <div class="bg-white rounded shadow-sm p-1.5 cursor-move border-l-4 <?php echo $kclass['status'] === 'active' ? 'border-blue-500' : 'border-gray-300'; ?> hover:shadow-md transition-shadow kanban-card text-xs mb-0.5"
                                 draggable="true"
                                 data-class-id="<?php echo $kclass['id']; ?>"
                                 data-room-id="<?php echo $kclass['room_id'] ?? ''; ?>"
                                 data-start-time="<?php echo $kclass['start_time']; ?>"
                                 data-end-time="<?php echo $kclass['end_time']; ?>"
                                 ondragstart="handleDragStart(event, <?php echo $kclass['id']; ?>)">
                                <div class="font-semibold text-gray-800 truncate" title="<?php echo htmlspecialchars($kclass['name']); ?>">
                                    <?php echo htmlspecialchars($kclass['name']); ?>
                                </div>
                                <div class="text-blue-600 font-medium">
                                    <?php echo date('g:i', strtotime($kclass['start_time'])); ?>-<?php echo date('g:i A', strtotime($kclass['end_time'])); ?>
                                </div>
                                <?php if ($kclass['instructor_name']): ?>
                                    <div class="text-gray-400 truncate"><?php echo htmlspecialchars($kclass['instructor_name']); ?></div>
                                <?php endif; ?>
                                <?php if ($kclass['room_name']): ?>
                                    <span class="inline-block px-1 py-0.5 rounded text-[9px] font-medium bg-indigo-100 text-indigo-700 mt-0.5">
                                        <?php echo htmlspecialchars($kclass['room_name']); ?>
                                    </span>
                                <?php endif; ?>
                                <div class="flex items-center justify-between mt-1 pt-0.5 border-t border-gray-100">
                                    <span class="text-gray-400"><?php echo $kclass['enrolled_count']; ?>/<?php echo $kclass['max_students']; ?></span>
                                    <button onclick="editClass(<?php echo htmlspecialchars(json_encode($kclass), ENT_QUOTES); ?>); event.stopPropagation();"
                                            class="text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                </div>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php endforeach; ?>

        <p class="text-xs text-gray-400 mt-2 text-center">
            Drag and drop classes to reschedule. Dropping onto a time slot updates both the day and start time.
            <a href="settings.php" class="text-blue-500 hover:underline ml-1">Configure hours of operation</a>
        </p>

        <?php
        // Classes Outside Configured Hours warning (orphanedClasses computed in board-assignment logic above)
        if (!empty($orphanedClasses)): ?>
        <div class="mt-4 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-yellow-800 mb-2">Classes Outside Configured Hours</h4>
            <p class="text-xs text-yellow-700 mb-2">These classes fall outside your configured hours of operation. Edit them or update your hours in <a href="settings.php" class="text-blue-600 hover:underline">Settings</a>.</p>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($orphanedClasses as $oc): ?>
                <div class="bg-white rounded p-2 shadow-sm border border-yellow-300 text-xs">
                    <span class="font-medium"><?php echo htmlspecialchars($oc['name']); ?></span>
                    <span class="text-gray-500 ml-1"><?php echo $oc['day_of_week']; ?> <?php echo date('g:i A', strtotime($oc['start_time'])); ?></span>
                    <button onclick="editClass(<?php echo htmlspecialchars(json_encode($oc), ENT_QUOTES); ?>)"
                            class="text-blue-600 hover:text-blue-800 ml-1 font-medium">Edit</button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Class Modal -->
<div id="addModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white my-10">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add New Class</h3>
            <button onclick="document.getElementById('addModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>

        <form method="POST" class="space-y-4" id="addClassForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Class Name *</label>
                <input type="text" name="name" required placeholder="e.g., Advanced Karate"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Martial Art Style *</label>
                    <select name="style_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">Select Style</option>
                        <?php foreach ($styles as $style): ?>
                            <option value="<?php echo $style['id']; ?>"><?php echo $style['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Instructor</label>
                    <select name="instructor_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">Select Instructor</option>
                        <?php foreach ($instructors as $instructor): ?>
                            <option value="<?php echo $instructor['id']; ?>"><?php echo $instructor['full_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Day of Week *</label>
                    <select name="day_of_week" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">Select Day</option>
                        <option value="Monday">Monday</option>
                        <option value="Tuesday">Tuesday</option>
                        <option value="Wednesday">Wednesday</option>
                        <option value="Thursday">Thursday</option>
                        <option value="Friday">Friday</option>
                        <option value="Saturday">Saturday</option>
                        <option value="Sunday">Sunday</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Time *</label>
                    <input type="time" name="start_time" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">End Time *</label>
                    <input type="time" name="end_time" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Students</label>
                    <input type="number" name="max_students" min="1" value="20"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Skill Level *</label>
                    <select name="skill_level" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <?php foreach (skillLevelOptions() as $val => $label): ?>
                            <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Room</label>
                    <select name="room_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">No Room</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?php echo $room['id']; ?>"><?php echo htmlspecialchars($room['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>

            <?php if (is_super_admin() && count(get_all_schools()) > 1): ?>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="enableCopyClass" onchange="document.getElementById('copyClassSchools').classList.toggle('hidden', !this.checked)" class="w-4 h-4 text-blue-600 rounded">
                    <span class="text-sm font-medium text-gray-700">Also create in other schools</span>
                </label>
                <div id="copyClassSchools" class="hidden mt-2 ml-6 space-y-1">
                    <p class="text-xs text-gray-500 mb-1">Instructor and Room will be cleared in copied classes.</p>
                    <?php foreach (get_all_schools() as $_cs): ?>
                        <?php if ((int)$_cs['id'] !== (int)current_school_id()): ?>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" name="copy_to_schools[]" value="<?= $_cs['id'] ?>" class="rounded">
                            <?= htmlspecialchars($_cs['name']) ?>
                        </label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Add Class
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Class Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white my-10">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit Class</h3>
            <button onclick="document.getElementById('editModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>

        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="class_id" id="edit_class_id">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Class Name *</label>
                <input type="text" name="name" id="edit_name" required placeholder="e.g., Advanced Karate"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Martial Art Style *</label>
                    <select name="style_id" id="edit_style_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">Select Style</option>
                        <?php foreach ($styles as $style): ?>
                            <option value="<?php echo $style['id']; ?>"><?php echo htmlspecialchars($style['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Instructor</label>
                    <select name="instructor_id" id="edit_instructor_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">Select Instructor</option>
                        <?php foreach ($instructors as $instructor): ?>
                            <option value="<?php echo $instructor['id']; ?>"><?php echo htmlspecialchars($instructor['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Day of Week *</label>
                    <select name="day_of_week" id="edit_day_of_week" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">Select Day</option>
                        <option value="Monday">Monday</option>
                        <option value="Tuesday">Tuesday</option>
                        <option value="Wednesday">Wednesday</option>
                        <option value="Thursday">Thursday</option>
                        <option value="Friday">Friday</option>
                        <option value="Saturday">Saturday</option>
                        <option value="Sunday">Sunday</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Time *</label>
                    <input type="time" name="start_time" id="edit_start_time" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">End Time *</label>
                    <input type="time" name="end_time" id="edit_end_time" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Students</label>
                    <input type="number" name="max_students" id="edit_max_students" min="1" value="20"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Skill Level *</label>
                    <select name="skill_level" id="edit_skill_level" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <?php foreach (skillLevelOptions() as $val => $label): ?>
                            <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" id="edit_status"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Room</label>
                    <select name="room_id" id="edit_room_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">No Room</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?php echo $room['id']; ?>"><?php echo htmlspecialchars($room['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" id="edit_description" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Enroll Student Modal (with enforcement) -->
<div id="enrollModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Enroll Student</h3>
            <button onclick="document.getElementById('enrollModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>

        <form method="POST" class="space-y-4" id="enrollForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="enroll">
            <input type="hidden" name="class_id" id="enroll_class_id">

            <div>
                <p class="text-sm text-gray-600 mb-2">Class: <span id="enroll_class_name" class="font-semibold"></span></p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Student *</label>
                <div id="enroll-student-picker"></div>
            </div>

            <!-- Enrollment eligibility warning -->
            <div id="enrollmentWarning" class="hidden">
                <div id="enrollmentWarningContent" class="p-3 rounded-lg text-sm"></div>
            </div>

            <!-- Admin Override -->
            <?php if (in_array(getCurrentUser()['role'], ['admin', 'super_admin'])): ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                <label class="flex items-center space-x-2 cursor-pointer">
                    <input type="checkbox" name="admin_override" value="1" class="rounded border-gray-300 text-yellow-600">
                    <span class="text-sm font-medium text-yellow-800">Admin Override</span>
                </label>
                <p class="text-xs text-yellow-600 mt-1">Bypass membership and class limit checks. Use with caution.</p>
            </div>
            <?php endif; ?>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('enrollModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" id="enrollSubmitBtn"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Enroll
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Class Roster Modal -->
<div id="rosterModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white my-10">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Class Roster: <span id="roster_class_name"></span></h3>
            <button onclick="document.getElementById('rosterModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>

        <!-- Bulk unenroll toolbar (hidden until checkboxes are checked) -->
        <div id="bulkUnenrollBar" class="hidden mb-3 p-3 bg-red-50 border border-red-200 rounded-lg flex items-center justify-between">
            <span class="text-sm text-red-700"><strong id="bulkCount">0</strong> student(s) selected</span>
            <button type="button" onclick="bulkUnenroll()"
                    class="bg-red-600 hover:bg-red-700 text-white text-sm px-4 py-1.5 rounded-lg font-medium">
                Unenroll Selected
            </button>
        </div>

        <div id="rosterContent" class="space-y-2">
            <p class="text-gray-500 text-center py-8">Loading...</p>
        </div>
    </div>
</div>

<!-- Hidden form for bulk unenroll submission -->
<form id="bulkUnenrollForm" method="POST" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="bulk_unenroll">
    <div id="bulkUnenrollInputs"></div>
</form>

<script src="assets/js/student-picker.js"></script>
<script>
const csrfToken = <?php echo json_encode(csrf_token()); ?>;

// Student data for client-side eligibility check
const studentData = <?php echo json_encode(array_map(function($s) {
    return [
        'id' => $s['id'],
        'name' => $s['first_name'] . ' ' . $s['last_name'],
        'has_membership' => $s['mem_status'] === 'active',
        'classes_per_week' => (int)($s['classes_per_week'] ?? 0),
        'current_enrollments' => (int)$s['current_enrollments'],
        'email' => $s['email'] ?? ''
    ];
}, $students)); ?>;

var enrollPicker = null;

document.addEventListener('DOMContentLoaded', function() {
    enrollPicker = StudentPicker.init({
        container: '#enroll-student-picker',
        inputName: 'student_id',
        placeholder: 'Type student name to search\u2026',
        data: studentData.map(function(s) {
            return {
                id: s.id,
                name: s.name,
                email: s.email || '',
                extra: s.has_membership ? '' : 'No Membership'
            };
        }),
        onSelect: function(student) {
            if (student) {
                checkEnrollmentEligibility(student.id);
            } else {
                document.getElementById('enrollmentWarning').classList.add('hidden');
            }
        },
        renderOption: function(s) {
            var html = '<div class="sp-option-name">' + s.name + '</div>';
            if (s.extra) html += '<div class="sp-option-sub" style="color:#ef4444">' + s.extra + '</div>';
            return html;
        }
    });
});

function enrollStudent(classId, className) {
    document.getElementById('enroll_class_id').value = classId;
    document.getElementById('enroll_class_name').textContent = className;
    if (enrollPicker) enrollPicker.clear();
    document.getElementById('enrollmentWarning').classList.add('hidden');
    document.getElementById('enrollModal').classList.remove('hidden');
}

function checkEnrollmentEligibility(studentId) {
    const warningDiv = document.getElementById('enrollmentWarning');
    const warningContent = document.getElementById('enrollmentWarningContent');

    if (!studentId) {
        warningDiv.classList.add('hidden');
        return;
    }

    const student = studentData.find(s => s.id == studentId);
    if (!student) {
        warningDiv.classList.add('hidden');
        return;
    }

    let warnings = [];

    if (!student.has_membership) {
        warnings.push('&#9888; This student does not have an active membership.');
    } else if (student.classes_per_week < 99 && student.current_enrollments >= student.classes_per_week) {
        warnings.push('&#9888; This student is at their enrollment limit (' + student.current_enrollments + '/' + student.classes_per_week + ' classes).');
    }

    if (warnings.length > 0) {
        warningContent.innerHTML = '<div class="bg-red-50 border border-red-200 text-red-700 p-3 rounded-lg">'
            + warnings.join('<br>')
            + '<br><span class="text-xs">Use Admin Override to bypass this restriction.</span></div>';
        warningDiv.classList.remove('hidden');
    } else {
        // Show green eligibility confirmation
        const remaining = student.classes_per_week >= 99 ? 'Unlimited' : (student.classes_per_week - student.current_enrollments);
        warningContent.innerHTML = '<div class="bg-green-50 border border-green-200 text-green-700 p-3 rounded-lg">'
            + '&#10003; Eligible. Currently enrolled in ' + student.current_enrollments + ' class(es). '
            + (student.classes_per_week >= 99 ? 'Unlimited plan.' : remaining + ' slot(s) remaining.')
            + '</div>';
        warningDiv.classList.remove('hidden');
    }
}

// Roster data (pre-loaded to avoid AJAX)
const rosterData = <?php
    $rosterData = [];
    foreach ($classes as $class) {
        $rosterParams = [$class['id']];
        $enrolled = $pdo->prepare("
            SELECT ce.id as enrollment_id, s.first_name, s.last_name, s.email, ce.enrollment_date
            FROM class_enrollments ce
            JOIN students s ON ce.student_id = s.id
            WHERE ce.class_id = ? AND ce.status = 'active'" . school_where('ce') . "
            ORDER BY s.first_name, s.last_name
        ");
        school_param($rosterParams);
        $enrolled->execute($rosterParams);
        $rosterData[$class['id']] = $enrolled->fetchAll();
    }
    echo json_encode($rosterData);
?>;

function viewEnrolled(classId, className) {
    document.getElementById('roster_class_name').textContent = className;
    const content = document.getElementById('rosterContent');
    const enrolled = rosterData[classId] || [];
    document.getElementById('bulkUnenrollBar').classList.add('hidden');

    if (enrolled.length === 0) {
        content.innerHTML = '<p class="text-gray-500 text-center py-8">No students enrolled in this class.</p>';
    } else {
        let html = '<div class="flex items-center justify-between mb-2 px-1">'
            + '<label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">'
            + '<input type="checkbox" id="selectAllRoster" onchange="toggleSelectAll(this)" class="rounded border-gray-300 text-blue-600">'
            + '<span>Select All (' + enrolled.length + ')</span>'
            + '</label>'
            + '<span class="text-xs text-gray-400">' + enrolled.length + ' enrolled</span>'
            + '</div>';
        html += '<div class="space-y-2 max-h-96 overflow-y-auto">';
        enrolled.forEach(function(s) {
            html += '<div class="flex items-center p-3 bg-gray-50 rounded-lg">'
                + '<input type="checkbox" class="roster-cb rounded border-gray-300 text-blue-600 mr-3" '
                + 'value="' + s.enrollment_id + '" onchange="updateBulkBar()">'
                + '<div class="flex-1 min-w-0">'
                + '<span class="font-medium text-gray-900">' + s.first_name + ' ' + s.last_name + '</span>'
                + '<span class="text-sm text-gray-500 ml-2">' + (s.email || '') + '</span>'
                + '</div>'
                + '<div class="flex items-center space-x-3 flex-shrink-0">'
                + '<span class="text-xs text-gray-400">Enrolled: ' + s.enrollment_date + '</span>'
                + '<form method="POST" class="inline" onsubmit="return confirmDelete(\'Unenroll this student?\')">'
                + '<input type="hidden" name="csrf_token" value="' + csrfToken + '">'
                + '<input type="hidden" name="action" value="unenroll">'
                + '<input type="hidden" name="enrollment_id" value="' + s.enrollment_id + '">'
                + '<button type="submit" class="text-red-600 hover:text-red-800 text-xs">Unenroll</button>'
                + '</form>'
                + '</div></div>';
        });
        html += '</div>';
        content.innerHTML = html;
    }

    document.getElementById('rosterModal').classList.remove('hidden');
}

function toggleSelectAll(masterCb) {
    var checkboxes = document.querySelectorAll('.roster-cb');
    checkboxes.forEach(function(cb) { cb.checked = masterCb.checked; });
    updateBulkBar();
}

function updateBulkBar() {
    var checked = document.querySelectorAll('.roster-cb:checked');
    var bar = document.getElementById('bulkUnenrollBar');
    var countEl = document.getElementById('bulkCount');
    var selectAll = document.getElementById('selectAllRoster');
    var allCbs = document.querySelectorAll('.roster-cb');

    if (checked.length > 0) {
        bar.classList.remove('hidden');
        countEl.textContent = checked.length;
    } else {
        bar.classList.add('hidden');
    }

    // Keep Select All in sync
    if (selectAll) {
        selectAll.checked = allCbs.length > 0 && checked.length === allCbs.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < allCbs.length;
    }
}

function bulkUnenroll() {
    var checked = document.querySelectorAll('.roster-cb:checked');
    if (checked.length === 0) return;
    if (!confirm('Unenroll ' + checked.length + ' student(s) from this class?')) return;

    var container = document.getElementById('bulkUnenrollInputs');
    container.innerHTML = '';
    checked.forEach(function(cb) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'enrollment_ids[]';
        input.value = cb.value;
        container.appendChild(input);
    });
    document.getElementById('bulkUnenrollForm').submit();
}

// ── Open Add modal (reset form first to clear stale copy data) ──
function openAddModal() {
    var form = document.getElementById('addClassForm');
    if (form) form.reset();
    document.getElementById('addModal').classList.remove('hidden');
}

// ── Edit Class — pre-fill edit modal with class data ──
function editClass(classData) {
    document.getElementById('edit_class_id').value = classData.id;
    document.getElementById('edit_name').value = classData.name || '';
    document.getElementById('edit_style_id').value = classData.style_id || '';
    document.getElementById('edit_instructor_id').value = classData.instructor_id || '';
    document.getElementById('edit_day_of_week').value = classData.day_of_week || '';
    document.getElementById('edit_start_time').value = classData.start_time || '';
    document.getElementById('edit_end_time').value = classData.end_time || '';
    document.getElementById('edit_max_students').value = classData.max_students || 20;
    document.getElementById('edit_skill_level').value = classData.skill_level || 'all';
    document.getElementById('edit_description').value = classData.description || '';
    document.getElementById('edit_status').value = classData.status || 'active';
    document.getElementById('edit_room_id').value = classData.room_id || '';
    document.getElementById('editModal').classList.remove('hidden');
}

// ── Copy Class — pre-fill ADD modal and let user create a new class ──
function copyClass(classData) {
    var form = document.getElementById('addClassForm');
    if (form) form.reset();
    form.querySelector('[name="name"]').value = classData.name + ' (Copy)';
    form.querySelector('[name="style_id"]').value = classData.style_id || '';
    form.querySelector('[name="instructor_id"]').value = classData.instructor_id || '';
    form.querySelector('[name="day_of_week"]').value = classData.day_of_week || '';
    form.querySelector('[name="start_time"]').value = classData.start_time || '';
    form.querySelector('[name="end_time"]').value = classData.end_time || '';
    form.querySelector('[name="max_students"]').value = classData.max_students || 20;
    form.querySelector('[name="skill_level"]').value = classData.skill_level || 'all';
    form.querySelector('[name="description"]').value = classData.description || '';
    form.querySelector('[name="status"]').value = classData.status || 'active';
    form.querySelector('[name="room_id"]').value = classData.room_id || '';
    document.getElementById('addModal').classList.remove('hidden');
}

// ── View Toggle: Cards vs Schedule ──
function setView(view) {
    var cardView = document.getElementById('cardView');
    var scheduleView = document.getElementById('scheduleView');
    var btnCard = document.getElementById('btnCardView');
    var btnSchedule = document.getElementById('btnScheduleView');

    if (view === 'schedule') {
        cardView.classList.add('hidden');
        scheduleView.classList.remove('hidden');
        btnCard.className = 'px-4 py-1.5 rounded-md text-sm font-medium text-gray-500 hover:text-gray-700';
        btnSchedule.className = 'px-4 py-1.5 rounded-md text-sm font-medium bg-white shadow text-gray-800';
    } else {
        cardView.classList.remove('hidden');
        scheduleView.classList.add('hidden');
        btnCard.className = 'px-4 py-1.5 rounded-md text-sm font-medium bg-white shadow text-gray-800';
        btnSchedule.className = 'px-4 py-1.5 rounded-md text-sm font-medium text-gray-500 hover:text-gray-700';
    }
    localStorage.setItem('classesView', view);
}

// ── Room filter (page-level — reloads with room query param) ──
function filterByRoom(roomId) {
    var params = new URLSearchParams(window.location.search);
    if (roomId) {
        params.set('room', roomId);
    } else {
        params.delete('room');
    }
    window.location.search = params.toString();
}

// ── Kanban room tab filter (client-side, no reload) ──
function filterKanbanRoom(roomId) {
    // Update tab styling
    document.querySelectorAll('.room-tab').forEach(function(btn) {
        if (btn.getAttribute('data-room') === roomId) {
            btn.className = 'room-tab px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white';
        } else {
            btn.className = 'room-tab px-4 py-2 rounded-lg text-sm font-medium bg-gray-200 text-gray-700 hover:bg-gray-300';
        }
    });
    // Show/hide cards
    document.querySelectorAll('.kanban-card').forEach(function(card) {
        if (!roomId || card.getAttribute('data-room-id') === roomId) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
    localStorage.setItem('kanbanRoomFilter', roomId);
}

// Restore saved view on page load
document.addEventListener('DOMContentLoaded', function() {
    var saved = localStorage.getItem('classesView');
    if (saved === 'schedule') setView('schedule');
    // Restore kanban room filter
    var savedRoom = localStorage.getItem('kanbanRoomFilter');
    if (savedRoom) filterKanbanRoom(savedRoom);
});

// ── Drag and Drop for Kanban Schedule ──
var draggedClassId = null;

function handleDragStart(event, classId) {
    draggedClassId = classId;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', classId);
    event.target.style.opacity = '0.4';
    event.target.addEventListener('dragend', function() {
        this.style.opacity = '1';
    }, { once: true });
}

function handleDrop(event, newDay, newTime) {
    event.preventDefault();
    var classId = event.dataTransfer.getData('text/plain');
    if (!classId) return;

    var card = document.querySelector('[data-class-id="' + classId + '"]');
    if (!card) return;

    // Move card into the target cell
    var targetCell = event.currentTarget;
    targetCell.appendChild(card);

    // Build AJAX form data
    var fd = new FormData();
    fd.append('class_id', classId);
    fd.append('day_of_week', newDay);
    if (newTime) {
        fd.append('start_time', newTime);
    }

    fetch('classes.php?ajax=update_schedule', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                card.classList.add('ring-2', 'ring-green-400');
                setTimeout(function() { card.classList.remove('ring-2', 'ring-green-400'); }, 1000);

                // Update card time display client-side (preserving duration)
                if (newTime) {
                    var oldStart = card.getAttribute('data-start-time');
                    var oldEnd = card.getAttribute('data-end-time');
                    if (oldStart && oldEnd) {
                        var durationMs = new Date('2000-01-01T' + oldEnd) - new Date('2000-01-01T' + oldStart);
                        var newStartDate = new Date('2000-01-01T' + newTime + ':00');
                        var newEndDate = new Date(newStartDate.getTime() + durationMs);
                        var newEndStr = ('0' + newEndDate.getHours()).slice(-2) + ':' + ('0' + newEndDate.getMinutes()).slice(-2) + ':00';

                        card.setAttribute('data-start-time', newTime + ':00');
                        card.setAttribute('data-end-time', newEndStr);

                        var timeEl = card.querySelector('.text-blue-600');
                        if (timeEl) {
                            timeEl.textContent = formatTime12(newTime) + '-' + formatTime12(newEndStr.substring(0, 5));
                        }
                    }
                }
            } else {
                alert('Failed to update schedule: ' + (data.error || 'Unknown error'));
                location.reload();
            }
        })
        .catch(function() {
            alert('Network error. Please try again.');
            location.reload();
        });
}

function formatTime12(time24) {
    var parts = time24.split(':');
    var h = parseInt(parts[0]);
    var m = parts[1];
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m + ' ' + ampm;
}
</script>

<?php include 'includes/footer.php'; ?>
