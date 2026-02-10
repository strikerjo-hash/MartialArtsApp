<?php
require_once 'config.php';
requireLogin();

$message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $stmt = $pdo->prepare("
                    INSERT INTO classes (name, style_id, instructor_id, day_of_week, start_time,
                                       end_time, max_students, skill_level, description, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    sanitizeInput($_POST['name']),
                    $_POST['style_id'],
                    $_POST['instructor_id'] ?: null,
                    $_POST['day_of_week'],
                    $_POST['start_time'],
                    $_POST['end_time'],
                    $_POST['max_students'] ?: 20,
                    $_POST['skill_level'],
                    sanitizeInput($_POST['description']),
                    $_POST['status']
                ]);
                $message = showAlert('Class created successfully!', 'success');
                break;

            case 'enroll':
                $student_id = $_POST['student_id'];
                $class_id   = $_POST['class_id'];
                $admin_override = isset($_POST['admin_override']) ? true : false;

                // --- Enrollment enforcement ---
                $enrollment_error = '';

                if (!$admin_override) {
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
                        $stmt = $pdo->prepare("
                            INSERT INTO class_enrollments (student_id, class_id, enrollment_date, status)
                            VALUES (?, ?, CURDATE(), 'active')
                        ");
                        $stmt->execute([$student_id, $class_id]);
                        $override_note = $admin_override ? ' (Admin Override)' : '';
                        $message = showAlert('Student enrolled successfully!' . $override_note, 'success');
                    } catch (PDOException $e) {
                        $message = showAlert('Error: Student may already be enrolled in this class.', 'error');
                    }
                }
                break;

            case 'unenroll':
                $pdo->prepare("UPDATE class_enrollments SET status = 'inactive' WHERE id = ?")->execute([$_POST['enrollment_id']]);
                $message = showAlert('Student unenrolled successfully!', 'success');
                break;

            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM classes WHERE id = ?");
                $stmt->execute([$_POST['class_id']]);
                $message = showAlert('Class deleted successfully!', 'success');
                break;
        }
    }
}

// Get all classes with enrollment counts
$day_filter = $_GET['day'] ?? '';

$query = "
    SELECT c.*,
           u.full_name as instructor_name,
           mas.name as style_name,
           COUNT(ce.id) as enrolled_count
    FROM classes c
    LEFT JOIN users u ON c.instructor_id = u.id
    LEFT JOIN martial_arts_styles mas ON c.style_id = mas.id
    LEFT JOIN class_enrollments ce ON c.id = ce.class_id AND ce.status = 'active'
    WHERE 1=1
";

if ($day_filter) {
    $query .= " AND c.day_of_week = :day";
}

$query .= " GROUP BY c.id ORDER BY
    FIELD(c.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
    c.start_time ASC";

$stmt = $pdo->prepare($query);
if ($day_filter) {
    $stmt->bindValue(':day', $day_filter);
}
$stmt->execute();
$classes = $stmt->fetchAll();

// Get martial arts styles
$styles = $pdo->query("SELECT * FROM martial_arts_styles ORDER BY name")->fetchAll();

// Get instructors
$instructors = $pdo->query("SELECT id, full_name FROM users WHERE role IN ('admin', 'instructor') ORDER BY full_name")->fetchAll();

// Get students for enrollment (with their membership info)
$students = $pdo->query("
    SELECT s.id, s.first_name, s.last_name,
           m.status as mem_status, mp.classes_per_week,
           (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.student_id = s.id AND ce.status = 'active') as current_enrollments
    FROM students s
    LEFT JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
    LEFT JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE s.status = 'active'
    GROUP BY s.id
    ORDER BY s.first_name, s.last_name
")->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Classes</h1>
        <button onclick="document.getElementById('addModal').classList.remove('hidden')"
                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
            + Add Class
        </button>
    </div>

    <!-- Filter by Day -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <div class="flex flex-wrap gap-2">
            <a href="classes.php" class="px-4 py-2 rounded-lg text-sm <?php echo $day_filter === '' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">
                All Days
            </a>
            <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day): ?>
                <a href="?day=<?php echo $day; ?>"
                   class="px-4 py-2 rounded-lg text-sm <?php echo $day_filter === $day ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">
                    <?php echo $day; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Classes by Day -->
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
                                        <span class="capitalize"><?php echo $class['skill_level']; ?> Level</span>
                                    </div>
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
                                        <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this class?')">
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
</div>

<!-- Add Class Modal -->
<div id="addModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white my-10">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add New Class</h3>
            <button onclick="document.getElementById('addModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>

        <form method="POST" class="space-y-4">
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

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Students</label>
                    <input type="number" name="max_students" min="1" value="20"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Skill Level *</label>
                    <select name="skill_level" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="all">All Levels</option>
                        <option value="beginner">Beginner</option>
                        <option value="intermediate">Intermediate</option>
                        <option value="advanced">Advanced</option>
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
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>

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

<!-- Enroll Student Modal (with enforcement) -->
<div id="enrollModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Enroll Student</h3>
            <button onclick="document.getElementById('enrollModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>

        <form method="POST" class="space-y-4" id="enrollForm">
            <input type="hidden" name="action" value="enroll">
            <input type="hidden" name="class_id" id="enroll_class_id">

            <div>
                <p class="text-sm text-gray-600 mb-2">Class: <span id="enroll_class_name" class="font-semibold"></span></p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Student *</label>
                <select name="student_id" id="enroll_student_select" required onchange="checkEnrollmentEligibility(this.value)"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="">Choose a student...</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['id']; ?>"
                                data-has-membership="<?php echo $student['mem_status'] === 'active' ? '1' : '0'; ?>"
                                data-classes-per-week="<?php echo $student['classes_per_week'] ?? 0; ?>"
                                data-current-enrollments="<?php echo $student['current_enrollments']; ?>">
                            <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                            <?php if ($student['mem_status'] !== 'active'): ?> (No Membership)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Enrollment eligibility warning -->
            <div id="enrollmentWarning" class="hidden">
                <div id="enrollmentWarningContent" class="p-3 rounded-lg text-sm"></div>
            </div>

            <!-- Admin Override -->
            <?php if (getCurrentUser()['role'] === 'admin'): ?>
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
        <div id="rosterContent" class="space-y-2">
            <p class="text-gray-500 text-center py-8">Loading...</p>
        </div>
    </div>
</div>

<script>
// Student data for client-side eligibility check
const studentData = <?php echo json_encode(array_map(function($s) {
    return [
        'id' => $s['id'],
        'name' => $s['first_name'] . ' ' . $s['last_name'],
        'has_membership' => $s['mem_status'] === 'active',
        'classes_per_week' => (int)($s['classes_per_week'] ?? 0),
        'current_enrollments' => (int)$s['current_enrollments']
    ];
}, $students)); ?>;

function enrollStudent(classId, className) {
    document.getElementById('enroll_class_id').value = classId;
    document.getElementById('enroll_class_name').textContent = className;
    document.getElementById('enroll_student_select').value = '';
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
        $enrolled = $pdo->prepare("
            SELECT ce.id as enrollment_id, s.first_name, s.last_name, s.email, ce.enrollment_date
            FROM class_enrollments ce
            JOIN students s ON ce.student_id = s.id
            WHERE ce.class_id = ? AND ce.status = 'active'
            ORDER BY s.first_name, s.last_name
        ");
        $enrolled->execute([$class['id']]);
        $rosterData[$class['id']] = $enrolled->fetchAll();
    }
    echo json_encode($rosterData);
?>;

function viewEnrolled(classId, className) {
    document.getElementById('roster_class_name').textContent = className;
    const content = document.getElementById('rosterContent');
    const enrolled = rosterData[classId] || [];

    if (enrolled.length === 0) {
        content.innerHTML = '<p class="text-gray-500 text-center py-8">No students enrolled in this class.</p>';
    } else {
        let html = '<div class="space-y-2">';
        enrolled.forEach(function(s) {
            html += '<div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">'
                + '<div>'
                + '<span class="font-medium text-gray-900">' + s.first_name + ' ' + s.last_name + '</span>'
                + '<span class="text-sm text-gray-500 ml-2">' + (s.email || '') + '</span>'
                + '</div>'
                + '<div class="flex items-center space-x-3">'
                + '<span class="text-xs text-gray-400">Enrolled: ' + s.enrollment_date + '</span>'
                + '<form method="POST" class="inline" onsubmit="return confirmDelete(\'Unenroll this student?\')">'
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
</script>

<?php include 'includes/footer.php'; ?>
