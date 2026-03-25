<?php
/**
 * pending_parent_links.php — Admin approval queue for parent-child link requests.
 *
 * Parents request to link a child from parent_portal.php; admins approve or deny here.
 */

require_once 'config.php';
requireLogin();

if (!in_array(getCurrentUser()['role'], ['admin', 'super_admin'])) {
    accessDenied('Parent link approval requires Admin privileges.');
}

$message = '';

// Handle approve
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_link'])) {
    verify_csrf();
    $linkId = (int)($_POST['link_id'] ?? 0);

    if ($linkId) {
        // Fetch the pending request
        $params = [$linkId];
        $fetchSql = "SELECT * FROM pending_parent_links WHERE id = ? AND status = 'pending'" . school_where();
        school_param($params);
        $stmt = $pdo->prepare($fetchSql);
        $stmt->execute($params);
        $request = $stmt->fetch();

        if ($request) {
            require_once __DIR__ . '/includes/parent_auth.php';

            // Actually link the parent to the child
            $linked = link_student_to_parent((int)$request['parent_id'], (int)$request['student_id'], $request['relationship']);

            // Mark request as approved
            $updParams = [getCurrentUser()['id'], $linkId];
            $updSql = "UPDATE pending_parent_links SET status = 'approved', resolved_at = NOW(), resolved_by = ? WHERE id = ?" . school_where();
            school_param($updParams);
            $pdo->prepare($updSql)->execute($updParams);

            if ($linked) {
                // Fetch names for the message
                $pStmt = $pdo->prepare("SELECT first_name, last_name FROM students WHERE id = ? LIMIT 1");
                $pStmt->execute([$request['parent_id']]);
                $parentInfo = $pStmt->fetch();
                $sStmt = $pdo->prepare("SELECT first_name, last_name FROM students WHERE id = ? LIMIT 1");
                $sStmt->execute([$request['student_id']]);
                $studentInfo = $sStmt->fetch();

                $parentName = $parentInfo ? htmlspecialchars($parentInfo['first_name'] . ' ' . $parentInfo['last_name']) : 'Parent';
                $studentName = $studentInfo ? htmlspecialchars($studentInfo['first_name'] . ' ' . $studentInfo['last_name']) : 'Student';

                $message = showAlert("Approved! {$parentName} is now linked to {$studentName}. Payment methods have been synced.", 'success');
            } else {
                $message = showAlert('Request approved but the link already existed.', 'info');
            }
        } else {
            $message = showAlert('Request not found or already processed.', 'error');
        }
    }
}

// Handle deny
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deny_link'])) {
    verify_csrf();
    $linkId = (int)($_POST['link_id'] ?? 0);
    $adminNotes = trim($_POST['admin_notes'] ?? '');

    if ($linkId) {
        $updParams = [getCurrentUser()['id'], $adminNotes ?: null, $linkId];
        $updSql = "UPDATE pending_parent_links SET status = 'denied', resolved_at = NOW(), resolved_by = ?, admin_notes = ? WHERE id = ? AND status = 'pending'" . school_where();
        school_param($updParams);
        $pdo->prepare($updSql)->execute($updParams);
        $message = showAlert('Link request denied.', 'success');
    }
}

// Fetch pending requests
$params = [];
$sql = "SELECT ppl.*,
               p.first_name as parent_first, p.last_name as parent_last, p.email as parent_email, p.phone as parent_phone,
               s.first_name as student_first, s.last_name as student_last, s.email as student_email
        FROM pending_parent_links ppl
        JOIN students p ON p.id = ppl.parent_id
        JOIN students s ON s.id = ppl.student_id
        WHERE ppl.status = 'pending'" . school_where('ppl') . "
        ORDER BY ppl.requested_at ASC";
school_param($params);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pendingRequests = $stmt->fetchAll();

// Fetch recent resolved requests (last 30 days)
$rParams = [];
$rSql = "SELECT ppl.*,
                p.first_name as parent_first, p.last_name as parent_last,
                s.first_name as student_first, s.last_name as student_last,
                u.full_name as resolved_by_name
         FROM pending_parent_links ppl
         JOIN students p ON p.id = ppl.parent_id
         JOIN students s ON s.id = ppl.student_id
         LEFT JOIN users u ON u.id = ppl.resolved_by
         WHERE ppl.status IN ('approved','denied') AND ppl.resolved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . school_where('ppl') . "
         ORDER BY ppl.resolved_at DESC
         LIMIT 20";
school_param($rParams);
$rStmt = $pdo->prepare($rSql);
$rStmt->execute($rParams);
$recentResolved = $rStmt->fetchAll();

// Check for existing links to warn about duplicates
$existingParentLinks = [];
foreach ($pendingRequests as $req) {
    $chkStmt = $pdo->prepare("SELECT parent_id FROM parent_students WHERE student_id = ?");
    $chkStmt->execute([$req['student_id']]);
    $existingParentLinks[$req['student_id']] = array_column($chkStmt->fetchAll(), 'parent_id');
}

$pageTitle = 'Pending Parent Link Requests';
include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?= $message ?>

    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Parent Link Requests</h1>
        <p class="text-gray-600">Review and approve parent requests to link to student accounts.</p>
    </div>

    <!-- Pending Requests -->
    <?php if (empty($pendingRequests)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <div class="text-5xl mb-4">✅</div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">No Pending Requests</h3>
            <p class="text-gray-500">All parent link requests have been processed.</p>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-hidden mb-8">
            <div class="px-6 py-4 border-b border-gray-200 bg-amber-50">
                <h2 class="text-lg font-semibold text-amber-800">
                    <?= count($pendingRequests) ?> Pending Request<?= count($pendingRequests) !== 1 ? 's' : '' ?>
                </h2>
            </div>
            <div class="divide-y divide-gray-200">
                <?php foreach ($pendingRequests as $req): ?>
                    <?php
                    $hasExistingParent = !empty($existingParentLinks[$req['student_id']]);
                    $isAlreadyLinked = in_array($req['parent_id'], $existingParentLinks[$req['student_id']] ?? []);
                    ?>
                    <div class="px-6 py-5 <?= $hasExistingParent ? 'bg-yellow-50' : '' ?>">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="text-2xl">👤</span>
                                    <div>
                                        <p class="font-semibold text-gray-800">
                                            <?= htmlspecialchars($req['parent_first'] . ' ' . $req['parent_last']) ?>
                                            <span class="text-sm font-normal text-gray-500 ml-1">
                                                (<?= htmlspecialchars($req['parent_email'] ?: 'no email') ?>)
                                            </span>
                                        </p>
                                        <p class="text-sm text-gray-500">
                                            Wants to link as <strong class="capitalize"><?= htmlspecialchars($req['relationship']) ?></strong> of:
                                        </p>
                                    </div>
                                </div>
                                <div class="ml-9 mb-2">
                                    <div class="flex items-center gap-2">
                                        <span class="text-lg">🧒</span>
                                        <span class="font-medium text-gray-800">
                                            <?= htmlspecialchars($req['student_first'] . ' ' . $req['student_last']) ?>
                                        </span>
                                        <?php if ($req['student_email']): ?>
                                            <span class="text-sm text-gray-500">(<?= htmlspecialchars($req['student_email']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($hasExistingParent && !$isAlreadyLinked): ?>
                                    <div class="ml-9 mt-2 p-2 bg-yellow-100 border border-yellow-300 rounded text-sm text-yellow-800">
                                        <strong>Note:</strong> This student already has <?= count($existingParentLinks[$req['student_id']]) ?> parent(s) linked. Approving will add a second parent (e.g., separated parents).
                                    </div>
                                <?php elseif ($isAlreadyLinked): ?>
                                    <div class="ml-9 mt-2 p-2 bg-red-100 border border-red-300 rounded text-sm text-red-800">
                                        <strong>Already linked!</strong> This parent is already linked to this student.
                                    </div>
                                <?php endif; ?>

                                <p class="ml-9 text-xs text-gray-400 mt-2">
                                    Requested <?= date('M j, Y g:i A', strtotime($req['requested_at'])) ?>
                                </p>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <?php if (!$isAlreadyLinked): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Approve this link request? The parent will gain access to the student\'s account and payment methods will be synced.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="link_id" value="<?= $req['id'] ?>">
                                        <button type="submit" name="approve_link" value="1"
                                                class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition">
                                            Approve
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Deny this link request?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="link_id" value="<?= $req['id'] ?>">
                                    <input type="hidden" name="admin_notes" value="">
                                    <button type="submit" name="deny_link" value="1"
                                            class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-lg transition">
                                        Deny
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Recent History -->
    <?php if (!empty($recentResolved)): ?>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800">Recent History (Last 30 Days)</h2>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Parent</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Resolved By</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($recentResolved as $r): ?>
                        <tr>
                            <td class="px-6 py-3 text-sm text-gray-800"><?= htmlspecialchars($r['parent_first'] . ' ' . $r['parent_last']) ?></td>
                            <td class="px-6 py-3 text-sm text-gray-800"><?= htmlspecialchars($r['student_first'] . ' ' . $r['student_last']) ?></td>
                            <td class="px-6 py-3 text-sm">
                                <?php if ($r['status'] === 'approved'): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Approved</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Denied</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-600"><?= htmlspecialchars($r['resolved_by_name'] ?? 'System') ?></td>
                            <td class="px-6 py-3 text-sm text-gray-500"><?= $r['resolved_at'] ? date('M j, Y', strtotime($r['resolved_at'])) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
