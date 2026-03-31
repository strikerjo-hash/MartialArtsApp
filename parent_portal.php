<?php
/**
 * parent_portal.php — Parent/Family Dashboard
 *
 * Shows linked children with summary cards, quick actions,
 * and the ability to add/link new children.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/parent_auth.php';

require_parent();

$parentId = get_effective_parent_id();
$message  = '';

$pdo = get_db();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Request to link existing student — creates pending request for admin approval
    if (isset($_POST['link_student'])) {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $identifier = trim($_POST['student_identifier'] ?? '');
        $relationship = $_POST['relationship'] ?? 'parent';

        $foundStudent = null;

        if ($studentId > 0) {
            $findParams = [$studentId];
            $findSql = "SELECT id, first_name, last_name FROM students WHERE id = ? AND status = 'active'" . school_where();
            school_param($findParams);
            $findStmt = $pdo->prepare($findSql);
            $findStmt->execute($findParams);
            $foundStudent = $findStmt->fetch();
        } elseif ($identifier !== '') {
            $findSql = "SELECT id, first_name, last_name FROM students WHERE (username = :u1 OR email = :u2) AND status = 'active'";
            if (!is_viewing_all_schools()) {
                $findSql .= ' AND school_id = :school_id';
            }
            $findSql .= ' LIMIT 1';
            $findStmt = $pdo->prepare($findSql);
            $findStmt->bindValue(':u1', $identifier);
            $findStmt->bindValue(':u2', $identifier);
            if (!is_viewing_all_schools()) {
                $findStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT);
            }
            $findStmt->execute();
            $foundStudent = $findStmt->fetch();
        }

        if (!$foundStudent) {
            $message = showAlert('No active student found. Please select a student from the list.', 'error');
        } else {
            // Check if already linked
            $alreadyLinked = $pdo->prepare("SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ?" . school_where());
            $alParams = [$parentId, $foundStudent['id']];
            school_param($alParams);
            $alreadyLinked->execute($alParams);
            if ($alreadyLinked->fetch()) {
                $message = showAlert('This student is already linked to your account.', 'error');
            } else {
                // Check if there's already a pending request
                $existingReq = $pdo->prepare("SELECT 1 FROM pending_parent_links WHERE parent_id = ? AND student_id = ? AND status = 'pending'" . school_where());
                $erParams = [$parentId, $foundStudent['id']];
                school_param($erParams);
                $existingReq->execute($erParams);
                if ($existingReq->fetch()) {
                    $message = showAlert('You already have a pending request for this student. Please wait for admin approval.', 'info');
                } else {
                    // Create pending link request
                    $reqStmt = $pdo->prepare("INSERT INTO pending_parent_links (school_id, parent_id, student_id, relationship) VALUES (?, ?, ?, ?)");
                    $reqStmt->execute([current_school_id(), $parentId, $foundStudent['id'], $relationship]);
                    $childName = htmlspecialchars($foundStudent['first_name'] . ' ' . $foundStudent['last_name']);
                    $message = showAlert('Your request to link ' . $childName . ' has been submitted. An administrator will review and approve it shortly.', 'success');
                }
            }
        }
    }

    // Unlink a child
    if (isset($_POST['unlink_student'])) {
        $unlinkId = (int)($_POST['student_id'] ?? 0);
        if ($unlinkId) {
            unlink_student_from_parent($parentId, $unlinkId);
            $message = showAlert('Student removed from your family account.', 'success');
        }
    }
}

// Fetch pending link requests for this parent
$pendingLinks = [];
try {
    $plParams = [$parentId];
    $plSql = "SELECT ppl.*, s.first_name, s.last_name
              FROM pending_parent_links ppl
              JOIN students s ON s.id = ppl.student_id
              WHERE ppl.parent_id = ? AND ppl.status = 'pending'" . school_where('ppl') . "
              ORDER BY ppl.requested_at DESC";
    school_param($plParams);
    $plStmt = $pdo->prepare($plSql);
    $plStmt->execute($plParams);
    $pendingLinks = $plStmt->fetchAll();
} catch (\PDOException $e) {}

// Fetch children
$children = get_parent_children($parentId);

// Fetch pending plan changes for all children (keyed by student_id)
$childPendingPlans = [];
$childIds_temp = array_column($children, 'id');
if (!empty($childIds_temp)) {
    try {
        $ppPh = implode(',', array_fill(0, count($childIds_temp), '?'));
        $ppParams = $childIds_temp;
        $ppSql = "SELECT pc.student_id, mp.name as plan_name, mp.price as plan_price, mp.duration_months,
                         mp.billing_frequency, pc.requested_by, u.full_name as requested_by_name, pc.expires_at, pc.id as change_id,
                         pc.proration_type, pc.proration_amount, pc.proration_credit, pc.notes
                  FROM pending_plan_changes pc
                  JOIN membership_plans mp ON pc.new_plan_id = mp.id
                  LEFT JOIN users u ON pc.requested_by = u.id
                  WHERE pc.student_id IN ({$ppPh}) AND pc.status = 'pending' AND pc.expires_at > NOW()" . school_where('pc') . "
                  ORDER BY pc.created_at DESC";
        school_param($ppParams);
        $ppStmt = $pdo->prepare($ppSql);
        $ppStmt->execute($ppParams);
        foreach ($ppStmt->fetchAll() as $pp) {
            if (!isset($childPendingPlans[(int)$pp['student_id']])) {
                $childPendingPlans[(int)$pp['student_id']] = $pp;
            }
        }
    } catch (PDOException $e) {}
}

// Fetch upcoming events for all children
$childIds = array_column($children, 'id');
$upcomingEvents = [];
if (!empty($childIds)) {
    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $params = $childIds;
    $evStmt = $pdo->prepare("
        SELECT e.id, e.name, e.event_date, e.start_time, e.event_type, e.location,
               er.student_id, s.first_name, s.last_name
        FROM events e
        JOIN event_registrations er ON er.event_id = e.id
        JOIN students s ON s.id = er.student_id
        WHERE er.student_id IN ({$placeholders}) AND e.event_date >= CURDATE()" . school_where('e') . "
        ORDER BY e.event_date ASC LIMIT 10
    ");
    school_param($params);
    $evStmt->execute($params);
    $upcomingEvents = $evStmt->fetchAll();
}

// Fetch parent profile (from students table since parent is a student)
$params = [$parentId];
$parentStmt = $pdo->prepare("SELECT * FROM students WHERE id = ?" . school_where() . " LIMIT 1");
school_param($params);
$parentStmt->execute($params);
$parent = $parentStmt->fetch();

include 'includes/parent_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Family Dashboard</h1>
        <p class="text-gray-600">Manage your children's memberships, events, and payments</p>
    </div>

    <?php if (!empty($pendingLinks)): ?>
    <div class="mb-6">
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
            <h3 class="text-sm font-semibold text-amber-800 mb-2">Pending Link Requests</h3>
            <div class="space-y-2">
                <?php foreach ($pendingLinks as $pl): ?>
                    <div class="flex items-center justify-between bg-white rounded-md px-4 py-2 border border-amber-100">
                        <div class="flex items-center gap-3">
                            <span class="text-amber-500">⏳</span>
                            <span class="text-sm text-gray-700">
                                <strong><?= htmlspecialchars($pl['first_name'] . ' ' . $pl['last_name']) ?></strong>
                                <span class="text-gray-500 capitalize">(<?= htmlspecialchars($pl['relationship']) ?>)</span>
                            </span>
                        </div>
                        <span class="text-xs text-amber-600 font-medium">Awaiting admin approval</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Children Cards -->
    <div class="mb-8">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-semibold text-gray-800">My Children</h2>
            <button onclick="document.getElementById('linkModal').classList.remove('hidden')"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                + Add Child
            </button>
        </div>

        <?php if (empty($children)): ?>
            <div class="bg-white rounded-lg shadow p-12 text-center">
                <div class="text-6xl mb-4">👨‍👩‍👧‍👦</div>
                <h3 class="text-xl font-bold text-gray-800 mb-2">No Children Linked Yet</h3>
                <p class="text-gray-600 mb-4">Link your children's student accounts to manage them from here.</p>
                <button onclick="document.getElementById('linkModal').classList.remove('hidden')"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium">
                    Link a Student Account
                </button>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($children as $child): ?>
                    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                        <a href="parent_child.php?id=<?= $child['id'] ?>" class="block bg-gradient-to-r from-blue-500 to-blue-600 text-white p-4 hover:from-blue-600 hover:to-blue-700 transition">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-lg font-bold">
                                        <?= htmlspecialchars($child['first_name'] . ' ' . $child['last_name']) ?>
                                    </h3>
                                    <p class="text-sm opacity-90 capitalize"><?= htmlspecialchars($child['relationship']) ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full <?= ($child['status'] ?? 'active') === 'active' ? 'bg-green-400 text-green-900' : 'bg-gray-300 text-gray-700' ?>">
                                        <?= ucfirst($child['status'] ?? 'active') ?>
                                    </span>
                                </div>
                            </div>
                        </a>

                        <div class="p-4 space-y-3">
                            <div class="flex items-center text-sm text-gray-600">
                                <span class="mr-2">🥋</span>
                                <span>Belt: <strong><?= htmlspecialchars($child['current_belt']) ?></strong></span>
                            </div>

                            <?php if ($child['plan_name']): ?>
                                <div class="flex items-center text-sm text-gray-600">
                                    <span class="mr-2">📋</span>
                                    <span>Plan: <strong><?= htmlspecialchars($child['plan_name']) ?></strong></span>
                                </div>
                                <?php if ($child['membership_end']): ?>
                                    <div class="flex items-center text-sm text-gray-600">
                                        <span class="mr-2">📅</span>
                                        <span>Valid until: <strong><?= formatDate($child['membership_end']) ?></strong></span>
                                    </div>
                                    <?php
                                    $_childDaysLeft = (strtotime($child['membership_end']) - time()) / 86400;
                                    if ($_childDaysLeft > 0 && $_childDaysLeft <= 30):
                                    ?>
                                        <div class="flex items-center text-sm text-orange-600 font-medium">
                                            <span class="mr-2">&#9888;&#65039;</span>
                                            <span>Expires in <?= floor($_childDaysLeft) ?> day<?= floor($_childDaysLeft) != 1 ? 's' : '' ?>!</span>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (isset($childPendingPlans[(int)$child['id']])): ?>
                                    <?php $cpPending = $childPendingPlans[(int)$child['id']]; ?>
                                    <div class="flex items-center text-sm text-orange-600">
                                        <span class="mr-2">&#128232;</span>
                                        <span>Change proposed: <strong><?= htmlspecialchars($cpPending['plan_name']) ?></strong>
                                            <span class="inline-block ml-1 px-1.5 py-0.5 text-xs font-semibold rounded-full bg-orange-100 text-orange-700">Pending</span>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            <?php elseif (isset($childPendingPlans[(int)$child['id']])): ?>
                                <?php $cpPending = $childPendingPlans[(int)$child['id']]; ?>
                                <div class="flex items-center text-sm text-orange-600">
                                    <span class="mr-2">📋</span>
                                    <span>Proposed Plan: <strong><?= htmlspecialchars($cpPending['plan_name']) ?></strong>
                                        <span class="inline-block ml-1 px-1.5 py-0.5 text-xs font-semibold rounded-full bg-orange-100 text-orange-700">Pending Approval</span>
                                    </span>
                                </div>
                                <div class="flex items-center text-sm text-gray-500">
                                    <span class="mr-2">&#128336;</span>
                                    <span>Expires: <?= date('M j, Y', strtotime($cpPending['expires_at'])) ?></span>
                                </div>
                            <?php else: ?>
                                <div class="flex items-center text-sm text-gray-500">
                                    <span class="mr-2">📋</span>
                                    <span>No active membership</span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($child['email'])): ?>
                                <div class="flex items-center text-sm text-gray-600">
                                    <span class="mr-2">📧</span>
                                    <span><?= htmlspecialchars($child['email']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="px-4 pb-4 pt-2 border-t border-gray-100">
                            <div class="grid grid-cols-4 gap-2 mb-2">
                                <a href="parent_child.php?id=<?= $child['id'] ?>" class="text-center bg-blue-50 hover:bg-blue-100 text-blue-700 py-2 rounded-lg text-xs font-medium transition" title="Overview">
                                    👤 Overview
                                </a>
                                <a href="parent_child_training.php?id=<?= $child['id'] ?>" class="text-center bg-purple-50 hover:bg-purple-100 text-purple-700 py-2 rounded-lg text-xs font-medium transition" title="Training">
                                    📚 Training
                                </a>
                                <a href="parent_child_membership.php?id=<?= $child['id'] ?>" class="text-center bg-green-50 hover:bg-green-100 text-green-700 py-2 rounded-lg text-xs font-medium transition" title="Membership">
                                    📋 Plan
                                </a>
                                <a href="parent_events.php?child=<?= $child['id'] ?>" class="text-center bg-orange-50 hover:bg-orange-100 text-orange-700 py-2 rounded-lg text-xs font-medium transition" title="Events">
                                    🏆 Events
                                </a>
                            </div>
                            <form method="POST" class="text-center" onsubmit="return confirm('Remove this child from your family account?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="unlink_student" value="1">
                                <input type="hidden" name="student_id" value="<?= $child['id'] ?>">
                                <button type="submit" class="text-xs text-red-500 hover:text-red-700 transition">
                                    Unlink from account
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Upcoming Events for All Children -->
    <?php if (!empty($upcomingEvents)): ?>
        <div class="bg-white rounded-lg shadow mb-8">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-800">Upcoming Events</h2>
                <a href="parent_events.php" class="text-sm text-blue-600 hover:underline">Browse All</a>
            </div>
            <div class="divide-y divide-gray-200">
                <?php foreach ($upcomingEvents as $ev): ?>
                    <div class="px-6 py-4 hover:bg-gray-50">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-medium text-gray-800"><?= htmlspecialchars($ev['name']) ?></p>
                                <p class="text-sm text-gray-500">
                                    <?= formatDate($ev['event_date']) ?>
                                    <?php if ($ev['start_time']): ?>
                                        at <?= date('g:i A', strtotime($ev['start_time'])) ?>
                                    <?php endif; ?>
                                    <?php if ($ev['location']): ?>
                                        &bull; <?= htmlspecialchars($ev['location']) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                <?= htmlspecialchars($ev['first_name']) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Quick Links -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Quick Links</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <a href="parent_events.php" class="flex flex-col items-center p-4 bg-blue-50 rounded-lg hover:bg-blue-100 transition">
                <span class="text-2xl mb-2">🏆</span>
                <span class="text-sm font-medium text-gray-700">Register for Events</span>
            </a>
            <a href="parent_payment.php" class="flex flex-col items-center p-4 bg-green-50 rounded-lg hover:bg-green-100 transition">
                <span class="text-2xl mb-2">💳</span>
                <span class="text-sm font-medium text-gray-700">Payment Methods</span>
            </a>
            <a href="student_portal.php" class="flex flex-col items-center p-4 bg-purple-50 rounded-lg hover:bg-purple-100 transition">
                <span class="text-2xl mb-2">👤</span>
                <span class="text-sm font-medium text-gray-700">My Dashboard</span>
            </a>
            <button onclick="document.getElementById('linkModal').classList.remove('hidden')"
                    class="flex flex-col items-center p-4 bg-orange-50 rounded-lg hover:bg-orange-100 transition cursor-pointer">
                <span class="text-2xl mb-2">➕</span>
                <span class="text-sm font-medium text-gray-700">Add Child</span>
            </button>
        </div>
    </div>
</div>

<!-- Link Student Modal -->
<div id="linkModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add Child to Family Account</h3>
            <button onclick="document.getElementById('linkModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <p class="text-sm text-gray-600 mb-4">
            Search for your child's name to request linking them to your family account.
            An administrator will review and approve your request.
        </p>

        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="link_student" value="1">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Find Student *</label>
                <div id="parent-link-picker"></div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Relationship</label>
                <select name="relationship"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="parent">Parent</option>
                    <option value="guardian">Guardian</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('linkModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Request Link
                </button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/student-picker.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    StudentPicker.init({
        container: '#parent-link-picker',
        inputName: 'student_id',
        placeholder: 'Type student name to search\u2026',
        ajaxUrl: 'ajax_student_search.php',
        ajaxParams: { context: 'unlinked:<?= $parentId ?>' },
        renderOption: function(s) {
            var html = '<div class="sp-option-name">' + s.name + '</div>';
            if (s.email) html += '<div class="sp-option-sub">' + s.email + '</div>';
            return html;
        }
    });
});
</script>
<?php include 'includes/student_footer.php'; ?>
