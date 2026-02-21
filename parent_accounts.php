<?php
/**
 * parent_accounts.php — Admin listing of all students with parent capabilities
 *
 * Shows students that have is_parent=1, along with their linked children.
 * Allows admin to promote students to parents and manage child links.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/parent_auth.php';
requireLogin();

$pdo = get_db();
$message = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Revoke parent capabilities
    if (isset($_POST['revoke_parent'])) {
        $studentId = (int)($_POST['student_id'] ?? 0);
        if ($studentId) {
            // Remove all child links first
            $pdo->prepare("DELETE FROM parent_students WHERE parent_id = ?")->execute([$studentId]);
            // Revoke parent flag
            $pdo->prepare("UPDATE students SET is_parent = 0 WHERE id = ?")->execute([$studentId]);
            $message = showAlert('Parent capabilities revoked and all child links removed.', 'success');
        }
    }

    // Promote a student to parent
    if (isset($_POST['promote_student'])) {
        $promoteStudentId = (int)($_POST['student_id'] ?? 0);
        if ($promoteStudentId) {
            $existingCheck = student_has_parent_account($promoteStudentId);
            if ($existingCheck) {
                $message = showAlert('This student already has parent capabilities enabled.', 'error');
            } else {
                $promoted = promote_student_to_parent($promoteStudentId);
                if ($promoted) {
                    $studentName = htmlspecialchars($promoted['first_name'] . ' ' . $promoted['last_name']);
                    $message = showAlert(
                        'Parent capabilities enabled for ' . $studentName . '. ' .
                        'Go to <a href="student_detail.php?id=' . $promoteStudentId . '" class="underline font-semibold">their student detail page</a> to link children.',
                        'success'
                    );
                } else {
                    $message = showAlert('Failed to enable parent capabilities. Please try again.', 'error');
                }
            }
        }
    }
}

// Search & filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$children_filter = $_GET['children'] ?? '';

// Fetch all students with is_parent=1, with child counts
$query = "
    SELECT s.*,
           COUNT(DISTINCT ps.student_id) as child_count,
           GROUP_CONCAT(DISTINCT CONCAT(cs.first_name, ' ', cs.last_name) ORDER BY cs.first_name SEPARATOR ', ') as children_names
    FROM students s
    LEFT JOIN parent_students ps ON ps.parent_id = s.id
    LEFT JOIN students cs ON cs.id = ps.student_id
    WHERE s.is_parent = 1
";

if ($search) {
    $query .= " AND (s.first_name LIKE :search1 OR s.last_name LIKE :search2 OR s.email LIKE :search3 OR s.username LIKE :search4)";
}
if ($status_filter) {
    $query .= " AND s.status = :status";
}

$query .= " GROUP BY s.id";

if ($children_filter === 'linked') {
    $query .= " HAVING child_count > 0";
} elseif ($children_filter === 'none') {
    $query .= " HAVING child_count = 0";
}

$query .= " ORDER BY s.first_name, s.last_name";

$stmt = $pdo->prepare($query);
if ($search) {
    $searchVal = "%$search%";
    $stmt->bindValue(':search1', $searchVal);
    $stmt->bindValue(':search2', $searchVal);
    $stmt->bindValue(':search3', $searchVal);
    $stmt->bindValue(':search4', $searchVal);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$parents = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Parent / Family Accounts</h1>
        <div class="flex items-center gap-4">
            <button onclick="document.getElementById('promoteModal').classList.remove('hidden')"
                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                + Enable Parent for Student
            </button>
            <div class="text-sm text-gray-500">
                <?= count($parents) ?> parent account<?= count($parents) !== 1 ? 's' : '' ?><?php if ($search || $status_filter || $children_filter): ?> found<?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Search & Filters -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4">
            <input type="text" name="search" placeholder="Search by name, email, or username..."
                   value="<?php echo htmlspecialchars($search); ?>"
                   class="flex-1 min-w-[200px] px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">

            <select name="status" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                <option value="">All Status</option>
                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="suspended" <?php echo $status_filter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
            </select>

            <select name="children" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                <option value="">All Children</option>
                <option value="linked" <?php echo $children_filter === 'linked' ? 'selected' : ''; ?>>Has Children Linked</option>
                <option value="none" <?php echo $children_filter === 'none' ? 'selected' : ''; ?>>No Children Linked</option>
            </select>

            <button type="submit" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded-lg">
                Filter
            </button>
            <a href="parent_accounts.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg">
                Reset
            </a>
        </form>
    </div>

    <?php if (empty($parents)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <div class="text-6xl mb-4">👨‍👩‍👧‍👦</div>
            <?php if ($search || $status_filter || $children_filter): ?>
                <h2 class="text-2xl font-bold text-gray-800 mb-2">No Results Found</h2>
                <p class="text-gray-600">No parent accounts match your search criteria. <a href="parent_accounts.php" class="text-blue-600 hover:underline">Reset filters</a></p>
            <?php else: ?>
                <h2 class="text-2xl font-bold text-gray-800 mb-2">No Parent Accounts</h2>
                <p class="text-gray-600">Promote a student to a parent account to enable them to manage their children from the student portal.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Parent</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Username</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Children</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($parents as $p): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                            <span class="text-blue-600 font-bold text-xs"><?= strtoupper(substr($p['first_name'], 0, 1) . substr($p['last_name'], 0, 1)) ?></span>
                                        </div>
                                        <a href="student_detail.php?id=<?= $p['id'] ?>" class="font-medium text-blue-600 hover:text-blue-800">
                                            <?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?>
                                        </a>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= htmlspecialchars($p['username'] ?? '—') ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?= htmlspecialchars($p['email'] ?? '—') ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($p['phone'] ?? '') ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center justify-center w-6 h-6 bg-blue-100 text-blue-800 text-xs font-bold rounded-full">
                                            <?= $p['child_count'] ?>
                                        </span>
                                        <?php if ($p['children_names']): ?>
                                            <span class="text-sm text-gray-600 truncate max-w-xs" title="<?= htmlspecialchars($p['children_names']) ?>">
                                                <?= htmlspecialchars($p['children_names']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-400">No children linked</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $statusColors = [
                                        'active' => 'bg-green-100 text-green-800',
                                        'inactive' => 'bg-gray-100 text-gray-800',
                                        'suspended' => 'bg-red-100 text-red-800',
                                    ];
                                    $st = $p['status'] ?? 'active';
                                    ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $statusColors[$st] ?? 'bg-gray-100 text-gray-800' ?>">
                                        <?= ucfirst($st) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="flex items-center gap-2">
                                        <a href="student_detail.php?id=<?= $p['id'] ?>" class="text-blue-600 hover:text-blue-800">View</a>
                                        <form method="POST" class="inline" onsubmit="return confirm('Revoke parent capabilities? This will unlink all children from this parent.')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="revoke_parent" value="1">
                                            <input type="hidden" name="student_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-800">Revoke</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Promote Student Modal -->
<?php
// Fetch active students that are NOT already parents
$allStudents = $pdo->query("
    SELECT id, first_name, last_name, email, username
    FROM students
    WHERE status = 'active' AND is_parent = 0
    ORDER BY first_name, last_name
")->fetchAll();
?>
<div id="promoteModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Enable Parent Account for Student</h3>
            <button onclick="document.getElementById('promoteModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
            <p class="text-sm text-blue-800">
                Select an existing student to enable parent capabilities. They will be able to manage their
                children's accounts from their student portal using the same login credentials.
            </p>
        </div>

        <form method="POST" class="space-y-4" onsubmit="return confirm('Enable parent capabilities for the selected student?')">
            <?= csrf_field() ?>
            <input type="hidden" name="promote_student" value="1">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Student *</label>
                <select name="student_id" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="">— Choose a student —</option>
                    <?php foreach ($allStudents as $s): ?>
                        <option value="<?= $s['id'] ?>">
                            <?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?>
                            <?php if ($s['username']): ?> (<?= htmlspecialchars($s['username']) ?>)<?php endif; ?>
                            <?php if ($s['email']): ?> — <?= htmlspecialchars($s['email']) ?><?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-400 mt-1">The student will use their existing login. No separate account is created.</p>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('promoteModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">
                    Enable Parent Account
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
