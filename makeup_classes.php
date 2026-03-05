<?php
/**
 * makeup_classes.php — Admin Make-Up Class Tracking
 *
 * Allows admins/instructors to:
 *   - See students who have absences in the current belt testing cycle
 *   - Log make-up class sessions that offset those absences
 *   - View/delete make-up class history
 */

require_once 'config.php';
requireLogin();
if (!canView('makeup_classes.php')) { accessDenied(); }
require_once __DIR__ . '/includes/belt_cycle.php';

$message = '';
$theme = getActiveTheme();
$cycle = get_current_cycle();
$threshold = (int) getSetting('absence_warning_threshold', '3');

// --- POST Handlers ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['add_makeup'])) {
        $studentId  = (int) ($_POST['student_id'] ?? 0);
        $classId    = !empty($_POST['class_id']) ? (int) $_POST['class_id'] : null;
        $absenceId  = !empty($_POST['absence_id']) ? (int) $_POST['absence_id'] : null;
        $makeupDate = $_POST['makeup_date'] ?? date('Y-m-d');
        $notes      = sanitizeInput($_POST['notes'] ?? '');

        if ($studentId <= 0) {
            $message = showAlert('Please select a student.', 'error');
        } else {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO makeup_classes (school_id, student_id, original_absence_id, class_id, makeup_date, logged_by, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([current_school_id(), $studentId, $absenceId, $classId, $makeupDate, $_SESSION['user_id'], $notes]);
                $message = showAlert('Make-up class logged successfully!', 'success');
            } catch (\PDOException $e) {
                $message = showAlert('Error logging make-up class: ' . $e->getMessage(), 'error');
            }
        }
    }

    if (isset($_POST['delete_makeup'])) {
        $makeupId = (int) ($_POST['makeup_id'] ?? 0);
        if ($makeupId > 0) {
            try {
                $params = [$makeupId];
                $stmt = $pdo->prepare("DELETE FROM makeup_classes WHERE id = ?" . school_where());
                school_param($params);
                $stmt->execute($params);
                $message = showAlert('Make-up class record deleted.', 'success');
            } catch (\PDOException $e) {
                $message = showAlert('Error deleting record.', 'error');
            }
        }
    }
}

// --- Fetch Students With Absences in Current Cycle ---
$studentsWithAbsences = [];
try {
    $params = [$cycle['start'], $cycle['end']];
    $stmt = $pdo->prepare("
        SELECT s.id, s.first_name, s.last_name, s.email,
               COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absences
        FROM students s
        JOIN class_enrollments ce ON ce.student_id = s.id AND ce.status = 'active'
        JOIN attendance a ON a.student_id = s.id
            AND a.attendance_date BETWEEN ? AND ?
        WHERE s.status = 'active'" . school_where('s') . "
        GROUP BY s.id, s.first_name, s.last_name, s.email
        HAVING absences > 0
        ORDER BY absences DESC
    ");
    school_param($params);
    $stmt->execute($params);
    $rawStudents = $stmt->fetchAll();

    foreach ($rawStudents as $rs) {
        $makeups = count_makeups_in_cycle((int) $rs['id'], $cycle['start'], $cycle['end']);
        $net = max(0, (int) $rs['absences'] - $makeups);
        $rs['makeups'] = $makeups;
        $rs['net_absences'] = $net;

        // Check if warning was sent
        $warn = $pdo->prepare("SELECT email_sent FROM absence_warnings WHERE student_id = ? AND cycle_start = ?");
        $warn->execute([$rs['id'], $cycle['start']]);
        $wRow = $warn->fetch();
        $rs['warning_sent'] = $wRow && (int) $wRow['email_sent'] === 1;

        $studentsWithAbsences[] = $rs;
    }

    // Sort by net absences descending
    usort($studentsWithAbsences, function($a, $b) {
        return $b['net_absences'] - $a['net_absences'];
    });
} catch (\PDOException $e) {}

// --- Fetch Make-Up Class Log ---
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$totalMakeups = 0;
$makeupLog = [];
try {
    $totalMakeups = (int) $pdo->query("SELECT COUNT(*) FROM makeup_classes")->fetchColumn();
    $makeupLog = $pdo->query("
        SELECT mc.*, s.first_name, s.last_name, c.name as class_name,
               u.full_name as logged_by_name
        FROM makeup_classes mc
        JOIN students s ON s.id = mc.student_id
        LEFT JOIN classes c ON c.id = mc.class_id
        LEFT JOIN users u ON u.id = mc.logged_by
        ORDER BY mc.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ")->fetchAll();
} catch (\PDOException $e) {}

$totalPages = max(1, ceil($totalMakeups / $perPage));

// --- Fetch data for modal dropdowns ---
$allActiveStudents = $pdo->query("SELECT id, first_name, last_name FROM students WHERE status = 'active' ORDER BY last_name, first_name")->fetchAll();
$allActiveClasses = $pdo->query("SELECT id, name, day_of_week FROM classes WHERE status = 'active' ORDER BY name")->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Make-Up Class Tracking</h1>
            <p class="text-gray-600 mt-1">Log make-up sessions to offset student absences</p>
        </div>
        <button onclick="document.getElementById('addMakeupModal').classList.remove('hidden')"
                class="text-white px-4 py-2 rounded-lg hover:opacity-90 transition"
                style="background-color: <?php echo $theme['primary']; ?>;">
            + Log Make-Up Class
        </button>
    </div>

    <!-- Current Testing Cycle Info -->
    <div class="bg-teal-50 border border-teal-200 rounded-lg p-5 mb-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h3 class="text-lg font-semibold text-teal-800">Current Belt Testing Cycle</h3>
                <p class="text-sm text-teal-600 mt-1">
                    <?php echo date('M j, Y', strtotime($cycle['start'])); ?> &mdash;
                    <?php echo date('M j, Y', strtotime($cycle['end'])); ?>
                </p>
            </div>
            <div class="text-right">
                <p class="text-sm text-teal-700">
                    Absence Warning Threshold: <strong><?php echo $threshold; ?></strong>
                </p>
                <a href="settings.php" class="text-xs text-teal-600 hover:underline">Change in Settings</a>
            </div>
        </div>
    </div>

    <!-- Students Needing Make-Ups -->
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="px-6 py-4 border-b border-gray-200 bg-orange-50">
            <h2 class="text-xl font-semibold text-orange-800">Students With Absences This Cycle</h2>
            <p class="text-sm text-orange-600 mt-1"><?php echo count($studentsWithAbsences); ?> student(s) have absences</p>
        </div>

        <?php if (empty($studentsWithAbsences)): ?>
            <div class="p-8 text-center text-gray-500">
                <p class="text-lg">No students have absences in the current testing cycle.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Absences</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Make-Ups</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Net Remaining</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($studentsWithAbsences as $sa): ?>
                        <tr class="hover:bg-gray-50 <?php echo $sa['net_absences'] >= $threshold ? 'bg-red-50' : ''; ?>">
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($sa['first_name'] . ' ' . $sa['last_name']); ?>
                                </div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($sa['email'] ?? ''); ?></div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-2 py-1 text-xs font-bold rounded-full bg-red-100 text-red-800">
                                    <?php echo $sa['absences']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-2 py-1 text-xs font-bold rounded-full bg-green-100 text-green-800">
                                    <?php echo $sa['makeups']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-2 py-1 text-xs font-bold rounded-full <?php echo $sa['net_absences'] >= $threshold ? 'bg-red-200 text-red-900' : 'bg-yellow-100 text-yellow-800'; ?>">
                                    <?php echo $sa['net_absences']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($sa['warning_sent']): ?>
                                    <span class="px-2 py-1 text-xs rounded-full bg-orange-100 text-orange-700">Warning Sent</span>
                                <?php elseif ($sa['net_absences'] >= $threshold): ?>
                                    <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">Over Threshold</span>
                                <?php elseif ($sa['net_absences'] > 0): ?>
                                    <span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-700">Needs Make-Up</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Resolved</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button onclick="openMakeupModalFor(<?php echo $sa['id']; ?>, '<?php echo htmlspecialchars(addslashes($sa['first_name'] . ' ' . $sa['last_name'])); ?>')"
                                        class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                                    Log Make-Up
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Make-Up Class Log -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Make-Up Class Log</h2>
            <p class="text-sm text-gray-500 mt-1"><?php echo $totalMakeups; ?> total records</p>
        </div>

        <?php if (empty($makeupLog)): ?>
            <div class="p-8 text-center text-gray-500">
                <p>No make-up classes have been logged yet.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Logged By</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($makeupLog as $ml): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo date('M j, Y', strtotime($ml['makeup_date'])); ?></td>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($ml['first_name'] . ' ' . $ml['last_name']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($ml['class_name'] ?? 'N/A'); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($ml['logged_by_name'] ?? 'System'); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate"><?php echo htmlspecialchars($ml['notes'] ?? ''); ?></td>
                            <td class="px-6 py-4 text-right">
                                <form method="POST" class="inline" onsubmit="return confirm('Delete this make-up record?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="makeup_id" value="<?php echo $ml['id']; ?>">
                                    <button type="submit" name="delete_makeup" class="text-sm text-red-600 hover:text-red-800">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                <p class="text-sm text-gray-600">Page <?php echo $page; ?> of <?php echo $totalPages; ?></p>
                <div class="flex gap-2">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>" class="px-3 py-1 text-sm bg-gray-200 rounded hover:bg-gray-300">Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" class="px-3 py-1 text-sm bg-gray-200 rounded hover:bg-gray-300">Next</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Log Make-Up Class Modal -->
<div id="addMakeupModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-800">Log Make-Up Class</h3>
            <button onclick="document.getElementById('addMakeupModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="add_makeup" value="1">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Student *</label>
                <div id="makeup-student-picker"></div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Class (optional)</label>
                <select name="class_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">No specific class</option>
                    <?php foreach ($allActiveClasses as $cl): ?>
                        <option value="<?php echo $cl['id']; ?>">
                            <?php echo htmlspecialchars($cl['name'] . ' (' . $cl['day_of_week'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Make-Up Date *</label>
                <input type="date" name="makeup_date" required value="<?php echo date('Y-m-d'); ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                <textarea name="notes" rows="2" placeholder="e.g. Made up missed Tuesday class"
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('addMakeupModal').classList.add('hidden')"
                        class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition">
                    Cancel
                </button>
                <button type="submit"
                        class="px-4 py-2 text-white rounded-lg hover:opacity-90 transition"
                        style="background-color: <?php echo $theme['primary']; ?>;">
                    Log Make-Up
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openMakeupModalFor(studentId, studentName) {
    const select = document.getElementById('makeupStudentSelect');
    if (select) {
        select.value = studentId;
    }
    document.getElementById('addMakeupModal').classList.remove('hidden');
}
</script>

<script src="assets/js/student-picker.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    StudentPicker.init({
        container: '#makeup-student-picker',
        inputName: 'student_id',
        placeholder: 'Type student name to search\u2026',
        data: <?= json_encode(array_map(function($s) { return ['id' => $s['id'], 'name' => trim($s['first_name'] . ' ' . $s['last_name'])]; }, $allActiveStudents)) ?>
    });
});
</script>

<?php include 'includes/footer.php'; ?>
