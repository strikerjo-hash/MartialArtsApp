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
if (!canView('parent_accounts.php')) { accessDenied(); }

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
            $params = [$studentId];
            school_param($params);
            $pdo->prepare("DELETE FROM parent_students WHERE parent_id = ?" . school_where())->execute($params);
            // Revoke parent flag
            $params = [$studentId];
            school_param($params);
            $pdo->prepare("UPDATE students SET is_parent = 0 WHERE id = ?" . school_where())->execute($params);
            $message = showAlert('Parent capabilities revoked and all child links removed.', 'success');
        }
    }

    // Change parent account status (active / inactive / suspended)
    if (isset($_POST['change_parent_status'])) {
        $statusStudentId = (int)($_POST['student_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if ($statusStudentId && in_array($newStatus, ['active', 'inactive', 'suspended'])) {
            $statusParams = [$newStatus, $statusStudentId];
            school_param($statusParams);
            $pdo->prepare("UPDATE students SET status = ? WHERE id = ?" . school_where())
                ->execute($statusParams);

            if ($newStatus === 'inactive' || $newStatus === 'suspended') {
                $inactParams = [date('Y-m-d'), $statusStudentId];
                school_param($inactParams);
                $pdo->prepare("UPDATE students SET inactive_since = ? WHERE id = ?" . school_where())
                    ->execute($inactParams);
                deactivate_student_cascade($statusStudentId, 'manual');
            } elseif ($newStatus === 'active') {
                $actParams = [$statusStudentId];
                school_param($actParams);
                $pdo->prepare("UPDATE students SET inactive_since = NULL WHERE id = ?" . school_where())
                    ->execute($actParams);
                reactivate_student($statusStudentId);
            }

            $message = showAlert('Parent account status changed to ' . ucfirst($newStatus) . '.', 'success');
        }
    }

    // ── Bulk actions ─────────────────────────────────────────────────
    if (isset($_POST['bulk_deactivate']) || isset($_POST['bulk_suspend']) ||
        isset($_POST['bulk_reactivate']) || isset($_POST['bulk_revoke'])) {
        $ids = $_POST['student_ids'] ?? [];
        if (is_array($ids) && !empty($ids)) {
            $ids = array_map('intval', array_filter($ids));
            $affected = 0;

            if (isset($_POST['bulk_deactivate'])) {
                foreach ($ids as $id) {
                    $params = ['inactive', date('Y-m-d'), $id];
                    school_param($params);
                    $stmt = $pdo->prepare("UPDATE students SET status = ?, inactive_since = ? WHERE id = ? AND status = 'active'" . school_where());
                    $stmt->execute($params);
                    if ($stmt->rowCount() > 0) {
                        deactivate_student_cascade($id, 'manual');
                        $affected++;
                    }
                }
                if ($affected && function_exists('audit_log')) {
                    audit_log('bulk_deactivate', [
                        'entity_type' => 'parent_account',
                        'description' => "Bulk deactivated {$affected} parent account(s)",
                    ]);
                }
                $message = showAlert("Deactivated {$affected} parent account(s) and cascaded to linked children.", 'success');

            } elseif (isset($_POST['bulk_suspend'])) {
                foreach ($ids as $id) {
                    $params = ['suspended', date('Y-m-d'), $id];
                    school_param($params);
                    $stmt = $pdo->prepare("UPDATE students SET status = ?, inactive_since = ? WHERE id = ? AND status = 'active'" . school_where());
                    $stmt->execute($params);
                    if ($stmt->rowCount() > 0) {
                        deactivate_student_cascade($id, 'manual');
                        $affected++;
                    }
                }
                if ($affected && function_exists('audit_log')) {
                    audit_log('bulk_suspend', [
                        'entity_type' => 'parent_account',
                        'description' => "Bulk suspended {$affected} parent account(s)",
                    ]);
                }
                $message = showAlert("Suspended {$affected} parent account(s).", 'success');

            } elseif (isset($_POST['bulk_reactivate'])) {
                foreach ($ids as $id) {
                    $params = ['active', $id];
                    school_param($params);
                    $stmt = $pdo->prepare("UPDATE students SET status = ?, inactive_since = NULL WHERE id = ? AND status IN ('inactive','suspended')" . school_where());
                    $stmt->execute($params);
                    if ($stmt->rowCount() > 0) {
                        reactivate_student($id);
                        $affected++;
                    }
                }
                if ($affected && function_exists('audit_log')) {
                    audit_log('bulk_reactivate', [
                        'entity_type' => 'parent_account',
                        'description' => "Bulk reactivated {$affected} parent account(s)",
                    ]);
                }
                $message = showAlert("Reactivated {$affected} parent account(s) and linked children.", 'success');

            } elseif (isset($_POST['bulk_revoke'])) {
                foreach ($ids as $id) {
                    // Remove child links
                    $params = [$id];
                    school_param($params);
                    $pdo->prepare("DELETE FROM parent_students WHERE parent_id = ?" . school_where())->execute($params);
                    // Remove parent flag
                    $params = [$id];
                    school_param($params);
                    $pdo->prepare("UPDATE students SET is_parent = 0 WHERE id = ?" . school_where())->execute($params);
                    $affected++;
                }
                if ($affected && function_exists('audit_log')) {
                    audit_log('bulk_revoke', [
                        'entity_type' => 'parent_account',
                        'description' => "Bulk revoked parent capabilities for {$affected} account(s)",
                    ]);
                }
                $message = showAlert("Revoked parent capabilities and unlinked children for {$affected} account(s).", 'success');
            }
        } else {
            $message = showAlert('No accounts selected. Please check at least one account.', 'error');
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
           GROUP_CONCAT(DISTINCT CONCAT(cs.first_name, ' ', cs.last_name) ORDER BY cs.first_name SEPARATOR ', ') as children_names,
           (SELECT MAX(pay.payment_date)
            FROM payments pay
            WHERE pay.student_id = s.id
               OR pay.student_id IN (SELECT ps2.student_id FROM parent_students ps2 WHERE ps2.parent_id = s.id)
           ) as last_payment_date
    FROM students s
    LEFT JOIN parent_students ps ON ps.parent_id = s.id
    LEFT JOIN students cs ON cs.id = ps.student_id
    WHERE s.is_parent = 1
";

if (!is_viewing_all_schools()) {
    $query .= " AND s.school_id = :school_id";
}

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
if (!is_viewing_all_schools()) {
    $stmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT);
}
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
        <form method="POST" id="bulkForm">
            <?= csrf_field() ?>

            <!-- Bulk Action Bar (hidden until checkboxes are checked) -->
            <div id="bulk-bar" class="hidden bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4 flex flex-wrap items-center justify-between gap-3 sticky top-0 z-10 shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-700">
                        <strong id="selected-count" class="text-blue-700 text-lg">0</strong> of <?= count($parents) ?> selected
                    </span>
                    <button type="button" onclick="selectAllParents()" class="px-3 py-1.5 bg-white border border-gray-300 rounded text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Select All
                    </button>
                    <button type="button" onclick="clearAll()" class="px-3 py-1.5 text-sm text-gray-500 hover:text-gray-700">
                        Clear
                    </button>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <button type="submit" name="bulk_deactivate" value="1"
                            onclick="return confirm('Deactivate ' + document.querySelectorAll('.parent-cb:checked').length + ' parent account(s)?\n\nThis will also deactivate their linked children and drop class enrollments.')"
                            class="px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium rounded-lg transition">
                        Deactivate
                    </button>
                    <button type="submit" name="bulk_suspend" value="1"
                            onclick="return confirm('Suspend ' + document.querySelectorAll('.parent-cb:checked').length + ' parent account(s)?')"
                            class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white text-sm font-medium rounded-lg transition">
                        Suspend
                    </button>
                    <button type="submit" name="bulk_reactivate" value="1"
                            onclick="return confirm('Reactivate ' + document.querySelectorAll('.parent-cb:checked').length + ' parent account(s)?\n\nThis will also reactivate their linked children.')"
                            class="px-4 py-2 bg-green-500 hover:bg-green-600 text-white text-sm font-medium rounded-lg transition">
                        Reactivate
                    </button>
                    <button type="submit" name="bulk_revoke" value="1"
                            onclick="return confirm('Revoke parent capabilities for ' + document.querySelectorAll('.parent-cb:checked').length + ' account(s)?\n\nThis will unlink ALL children from these parents. This cannot be undone.')"
                            class="px-4 py-2 bg-red-700 hover:bg-red-800 text-white text-sm font-medium rounded-lg transition">
                        Revoke Parent
                    </button>
                </div>
            </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-center w-10">
                                <input type="checkbox" id="select-all" onchange="toggleAll(this)" class="rounded w-4 h-4 text-blue-600">
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Parent</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Username</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Children</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Payment</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($parents as $p): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-4 text-center">
                                    <input type="checkbox" name="student_ids[]" value="<?= $p['id'] ?>" class="parent-cb rounded w-4 h-4 text-blue-600">
                                </td>
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
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php if (!empty($p['last_payment_date'])): ?>
                                        <?php
                                        $daysSince = (int)((time() - strtotime($p['last_payment_date'])) / 86400);
                                        $dateColor = $daysSince > 60 ? 'text-red-600' : ($daysSince > 30 ? 'text-yellow-600' : 'text-gray-700');
                                        ?>
                                        <span class="<?= $dateColor ?> font-medium"><?= formatDate($p['last_payment_date']) ?></span>
                                        <div class="text-xs text-gray-400"><?= $daysSince === 0 ? 'Today' : $daysSince . 'd ago' ?></div>
                                    <?php else: ?>
                                        <span class="text-gray-400">No payments</span>
                                    <?php endif; ?>
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
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <a href="student_detail.php?id=<?= $p['id'] ?>" class="text-blue-600 hover:text-blue-800">View</a>
                                        <?php $parentStatus = $p['status'] ?? 'active'; ?>
                                        <?php if ($parentStatus === 'active'): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Deactivate this parent account? They will not be able to log in.')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="change_parent_status" value="1">
                                                <input type="hidden" name="student_id" value="<?= $p['id'] ?>">
                                                <input type="hidden" name="new_status" value="inactive">
                                                <button type="submit" class="text-orange-600 hover:text-orange-800">Deactivate</button>
                                            </form>
                                            <form method="POST" class="inline" onsubmit="return confirm('Suspend this parent account?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="change_parent_status" value="1">
                                                <input type="hidden" name="student_id" value="<?= $p['id'] ?>">
                                                <input type="hidden" name="new_status" value="suspended">
                                                <button type="submit" class="text-red-600 hover:text-red-800">Suspend</button>
                                            </form>
                                        <?php elseif ($parentStatus === 'inactive' || $parentStatus === 'suspended'): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Reactivate this parent account?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="change_parent_status" value="1">
                                                <input type="hidden" name="student_id" value="<?= $p['id'] ?>">
                                                <input type="hidden" name="new_status" value="active">
                                                <button type="submit" class="text-green-600 hover:text-green-800">Reactivate</button>
                                            </form>
                                        <?php endif; ?>
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
        </form>
    <?php endif; ?>
</div>

<!-- Promote Student Modal -->
<?php
// Fetch active students that are NOT already parents
$modalParams = [];
school_param($modalParams);
$modalQuery = "
    SELECT id, first_name, last_name, email, username
    FROM students
    WHERE status = 'active' AND is_parent = 0" . school_where() . "
    ORDER BY first_name, last_name
";
$modalStmt = $pdo->prepare($modalQuery);
$modalStmt->execute($modalParams);
$allStudents = $modalStmt->fetchAll();
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
                <div id="parent-promote-picker"></div>
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

<script>
// ── Bulk selection logic ──────────────────────────────────────────
function toggleAll(source) {
    document.querySelectorAll('.parent-cb').forEach(function(cb) {
        cb.checked = source.checked;
    });
    updateSelectedCount();
}

function selectAllParents() {
    document.querySelectorAll('.parent-cb').forEach(function(cb) {
        cb.checked = true;
    });
    var master = document.getElementById('select-all');
    if (master) master.checked = true;
    updateSelectedCount();
}

function clearAll() {
    document.querySelectorAll('.parent-cb').forEach(function(cb) {
        cb.checked = false;
    });
    var master = document.getElementById('select-all');
    if (master) master.checked = false;
    updateSelectedCount();
}

function updateSelectedCount() {
    var checked = document.querySelectorAll('.parent-cb:checked').length;
    var el = document.getElementById('selected-count');
    if (el) el.textContent = checked;

    var bar = document.getElementById('bulk-bar');
    if (bar) {
        if (checked > 0) {
            bar.classList.remove('hidden');
        } else {
            bar.classList.add('hidden');
        }
    }

    // Sync master checkbox
    var all = document.querySelectorAll('.parent-cb');
    var master = document.getElementById('select-all');
    if (master) {
        master.checked = all.length > 0 && checked === all.length;
        master.indeterminate = checked > 0 && checked < all.length;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.parent-cb').forEach(function(cb) {
        cb.addEventListener('change', updateSelectedCount);
    });
});
</script>

<script src="assets/js/student-picker.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    StudentPicker.init({
        container: '#parent-promote-picker',
        inputName: 'student_id',
        placeholder: 'Type student name to search\u2026',
        data: <?= json_encode(array_map(function($s) {
            return [
                'id' => $s['id'],
                'name' => trim($s['first_name'] . ' ' . $s['last_name']),
                'email' => $s['email'] ?? '',
                'extra' => $s['username'] ? '(' . $s['username'] . ')' : ''
            ];
        }, $allStudents)) ?>,
        renderOption: function(s) {
            var html = '<div class="sp-option-name">' + s.name;
            if (s.extra) html += ' <span style="color:#6b7280;font-size:12px">' + s.extra + '</span>';
            html += '</div>';
            if (s.email) html += '<div class="sp-option-sub">' + s.email + '</div>';
            return html;
        }
    });
});
</script>
<?php include 'includes/footer.php'; ?>
