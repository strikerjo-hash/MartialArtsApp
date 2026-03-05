<?php
/**
 * admin_flagged_messages.php — Admin Review of Flagged Student Messages
 *
 * Shows messages auto-flagged by the content moderation system.
 * Admins can dismiss, warn, or suspend the sender.
 */

require_once 'config.php';
requireLogin();
require_once __DIR__ . '/includes/conversation_helpers.php';

// ── AJAX: Review action ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'review' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    verify_csrf();

    $reviewId    = (int) ($_POST['review_id'] ?? 0);
    $action      = $_POST['action'] ?? '';
    $notes       = trim($_POST['notes'] ?? '');
    $reviewerId  = (int) ($_SESSION['user_id'] ?? 0);

    if (!$reviewId || !in_array($action, ['dismissed', 'warned', 'suspended'])) {
        echo json_encode(['error' => 'Invalid parameters.']);
        exit;
    }

    try {
        $params = [$action, $reviewerId, $notes, $reviewId];
        $sql = "UPDATE flagged_message_reviews SET review_action = ?, reviewed_by = ?, review_notes = ?, reviewed_at = NOW() WHERE id = ?" . school_where();
        school_param($params);
        $pdo->prepare($sql)->execute($params);

        if (function_exists('audit_log')) {
            audit_log('flagged_message_reviewed', [
                'description' => "Flagged review #{$reviewId} marked as {$action}",
                'entity_type' => 'flagged_message_review',
                'entity_id'   => $reviewId,
            ]);
        }

        echo json_encode(['success' => true]);
    } catch (\PDOException $e) {
        echo json_encode(['error' => 'Database error.']);
    }
    exit;
}

// ── AJAX: Get conversation context ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'context') {
    header('Content-Type: application/json');

    $dmId = (int) ($_GET['dm_id'] ?? 0);
    if (!$dmId) {
        echo json_encode(['error' => 'Missing message ID.']);
        exit;
    }

    try {
        // Get the direct message and its conversation
        $params = [$dmId];
        $sql = "SELECT dm.*, c.id AS conv_id FROM direct_messages dm JOIN conversations c ON c.id = dm.conversation_id WHERE dm.id = ?" . school_where('dm');
        school_param($params);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $dm = $stmt->fetch();

        if (!$dm) {
            echo json_encode(['error' => 'Message not found.']);
            exit;
        }

        // Get surrounding messages (5 before and 5 after)
        $schoolId = (int) ($_SESSION['school_id'] ?? 1);
        $ctxParams = [$dm['conversation_id'], $schoolId, $dm['created_at'], $dm['conversation_id'], $schoolId, $dm['created_at'], $dm['conversation_id'], $schoolId, $dm['created_at']];
        $ctxSql = "(SELECT id, sender_type, sender_id, body, is_flagged, created_at FROM direct_messages WHERE conversation_id = ? AND school_id = ? AND created_at < ? ORDER BY created_at DESC LIMIT 5)
                   UNION ALL
                   (SELECT id, sender_type, sender_id, body, is_flagged, created_at FROM direct_messages WHERE conversation_id = ? AND school_id = ? AND created_at = ?)
                   UNION ALL
                   (SELECT id, sender_type, sender_id, body, is_flagged, created_at FROM direct_messages WHERE conversation_id = ? AND school_id = ? AND created_at > ? ORDER BY created_at ASC LIMIT 5)
                   ORDER BY created_at ASC";
        $ctxStmt = $pdo->prepare($ctxSql);
        $ctxStmt->execute($ctxParams);
        $contextMsgs = $ctxStmt->fetchAll();

        // Gather sender names
        $sIds = [];
        $aIds = [];
        foreach ($contextMsgs as $m) {
            if ($m['sender_type'] === 'student') $sIds[] = (int) $m['sender_id'];
            else $aIds[] = (int) $m['sender_id'];
        }
        $sIds = array_unique($sIds);
        $aIds = array_unique($aIds);

        $names = [];
        if (!empty($sIds)) {
            $ph = implode(',', array_fill(0, count($sIds), '?'));
            $s = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE id IN ({$ph})");
            $s->execute(array_values($sIds));
            foreach ($s->fetchAll() as $r) $names['student_' . $r['id']] = trim($r['first_name'] . ' ' . $r['last_name']);
        }
        if (!empty($aIds)) {
            $ph = implode(',', array_fill(0, count($aIds), '?'));
            $a = $pdo->prepare("SELECT id, full_name FROM users WHERE id IN ({$ph})");
            $a->execute(array_values($aIds));
            foreach ($a->fetchAll() as $r) $names['admin_' . $r['id']] = $r['full_name'];
        }

        $context = [];
        foreach ($contextMsgs as $m) {
            $context[] = [
                'id'          => (int) $m['id'],
                'sender_name' => $names[$m['sender_type'] . '_' . $m['sender_id']] ?? 'Unknown',
                'sender_type' => $m['sender_type'],
                'body'        => $m['body'],
                'is_flagged'  => (bool) $m['is_flagged'],
                'created_at'  => $m['created_at'],
                'is_target'   => (int) $m['id'] === $dmId,
            ];
        }

        echo json_encode(['success' => true, 'context' => $context]);
    } catch (\PDOException $e) {
        echo json_encode(['error' => 'Database error.']);
    }
    exit;
}

// ── Fetch flagged reviews ──
$filter = $_GET['filter'] ?? 'pending';
$filterClause = $filter === 'all' ? '' : " AND fmr.review_action = 'pending'";

$params = [];
$sql = "SELECT fmr.id AS review_id, fmr.review_action, fmr.reviewed_at, fmr.review_notes,
               dm.id AS dm_id, dm.body, dm.is_flagged, dm.flag_reason, dm.sender_type, dm.sender_id, dm.created_at AS message_date, dm.conversation_id,
               rv.full_name AS reviewer_name
        FROM flagged_message_reviews fmr
        JOIN direct_messages dm ON dm.id = fmr.direct_message_id
        LEFT JOIN users rv ON rv.id = fmr.reviewed_by
        WHERE 1=1 {$filterClause}" . school_where('fmr') . "
        ORDER BY fmr.created_at DESC
        LIMIT 100";
school_param($params);

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reviews = $stmt->fetchAll();
} catch (\PDOException $e) {
    $reviews = [];
}

// Gather sender names
$senderNames = [];
$sIds = [];
$aIds = [];
foreach ($reviews as $r) {
    if ($r['sender_type'] === 'student') $sIds[] = (int) $r['sender_id'];
    else $aIds[] = (int) $r['sender_id'];
}
$sIds = array_unique($sIds);
$aIds = array_unique($aIds);

if (!empty($sIds)) {
    $ph = implode(',', array_fill(0, count($sIds), '?'));
    $s = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE id IN ({$ph})");
    $s->execute(array_values($sIds));
    foreach ($s->fetchAll() as $r) $senderNames['student_' . $r['id']] = trim($r['first_name'] . ' ' . $r['last_name']);
}
if (!empty($aIds)) {
    $ph = implode(',', array_fill(0, count($aIds), '?'));
    $a = $pdo->prepare("SELECT id, full_name FROM users WHERE id IN ({$ph})");
    $a->execute(array_values($aIds));
    foreach ($a->fetchAll() as $r) $senderNames['admin_' . $r['id']] = $r['full_name'];
}

$pendingCount = 0;
foreach ($reviews as $r) {
    if ($r['review_action'] === 'pending') $pendingCount++;
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Flagged Messages</h1>
            <p class="text-gray-500 mt-1">
                <?php if ($pendingCount > 0): ?>
                    <span class="text-red-600 font-semibold"><?= $pendingCount ?></span> message<?= $pendingCount !== 1 ? 's' : '' ?> pending review
                <?php else: ?>
                    No pending flagged messages
                <?php endif; ?>
            </p>
        </div>
        <div class="flex gap-2">
            <a href="?filter=pending" class="px-4 py-2 text-sm rounded-lg <?= $filter === 'pending' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?> transition-colors">Pending</a>
            <a href="?filter=all" class="px-4 py-2 text-sm rounded-lg <?= $filter === 'all' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?> transition-colors">All</a>
        </div>
    </div>

    <?php if (empty($reviews)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <span class="text-5xl mb-4 block">&#9989;</span>
            <h3 class="text-lg font-semibold text-gray-700 mb-2">All Clear</h3>
            <p class="text-gray-500">No flagged messages to review.</p>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sender</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Message Preview</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Flag Reason</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($reviews as $rv): ?>
                        <?php
                        $senderKey = $rv['sender_type'] . '_' . $rv['sender_id'];
                        $senderName = $senderNames[$senderKey] ?? 'Unknown';
                        $statusColors = [
                            'pending'   => 'bg-yellow-100 text-yellow-800',
                            'dismissed' => 'bg-gray-100 text-gray-600',
                            'warned'    => 'bg-orange-100 text-orange-800',
                            'suspended' => 'bg-red-100 text-red-800',
                        ];
                        $statusColor = $statusColors[$rv['review_action']] ?? 'bg-gray-100 text-gray-600';
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full <?= $statusColor ?>">
                                    <?= ucfirst($rv['review_action']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                                <?= date('M j, Y g:ia', strtotime($rv['message_date'])) ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-800 font-medium">
                                <?= htmlspecialchars($senderName) ?>
                                <span class="text-xs text-gray-400 block"><?= ucfirst($rv['sender_type']) ?></span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate">
                                <?= htmlspecialchars(mb_substr($rv['body'], 0, 80)) ?><?= mb_strlen($rv['body']) > 80 ? '...' : '' ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-red-600">
                                <?= htmlspecialchars($rv['flag_reason'] ?? '') ?>
                            </td>
                            <td class="px-4 py-3">
                                <button onclick="openReview(<?= $rv['review_id'] ?>, <?= $rv['dm_id'] ?>, '<?= htmlspecialchars(addslashes($senderName)) ?>', '<?= $rv['sender_type'] ?>')"
                                        class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                                    Review
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Review Modal -->
<div id="review-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-800">Review Flagged Message</h3>
            <button onclick="closeReview()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto px-6 py-4">
            <div class="mb-4">
                <p class="text-sm text-gray-500">Sender: <strong id="review-sender" class="text-gray-800"></strong></p>
            </div>

            <h4 class="text-sm font-semibold text-gray-700 mb-2">Conversation Context</h4>
            <div id="review-context" class="bg-gray-50 rounded-lg p-4 space-y-2 mb-4 max-h-60 overflow-y-auto">
                <p class="text-gray-400 text-sm text-center animate-pulse">Loading context...</p>
            </div>

            <div class="border-t border-gray-200 pt-4">
                <h4 class="text-sm font-semibold text-gray-700 mb-2">Take Action</h4>
                <div class="mb-3">
                    <label class="text-sm text-gray-600">Notes (optional)</label>
                    <textarea id="review-notes" class="w-full mt-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-blue-500" rows="2" placeholder="Add review notes..."></textarea>
                </div>
                <div class="flex gap-2">
                    <button onclick="submitReview('dismissed')" class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium transition-colors">
                        Dismiss
                    </button>
                    <button onclick="submitReview('warned')" class="flex-1 bg-orange-500 hover:bg-orange-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium transition-colors">
                        Warn Sender
                    </button>
                    <button onclick="submitReview('suspended')" class="flex-1 bg-red-600 hover:bg-red-700 text-white px-4 py-2.5 rounded-lg text-sm font-medium transition-colors">
                        Suspend
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var currentReviewId = null;
var csrfToken = '<?= csrf_token() ?>';

function openReview(reviewId, dmId, senderName, senderType) {
    currentReviewId = reviewId;
    document.getElementById('review-sender').textContent = senderName + ' (' + senderType + ')';
    document.getElementById('review-notes').value = '';
    document.getElementById('review-context').innerHTML = '<p class="text-gray-400 text-sm text-center animate-pulse">Loading context...</p>';
    document.getElementById('review-modal').classList.remove('hidden');

    // Load conversation context
    fetch('admin_flagged_messages.php?ajax=context&dm_id=' + dmId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                document.getElementById('review-context').innerHTML = '<p class="text-red-500 text-sm">' + data.error + '</p>';
                return;
            }

            var html = '';
            data.context.forEach(function(msg) {
                var bgClass = msg.is_target ? 'bg-red-50 border border-red-200' : 'bg-white border border-gray-200';
                html += '<div class="' + bgClass + ' rounded-lg px-3 py-2 text-sm">' +
                    '<div class="flex justify-between items-center mb-1">' +
                    '<span class="font-medium text-gray-700">' + escapeHtml(msg.sender_name) +
                    (msg.is_flagged ? ' <span class="text-red-500">&#9888;</span>' : '') +
                    '</span>' +
                    '<span class="text-xs text-gray-400">' + msg.created_at + '</span>' +
                    '</div>' +
                    '<p class="text-gray-600">' + escapeHtml(msg.body) + '</p>' +
                    '</div>';
            });

            document.getElementById('review-context').innerHTML = html || '<p class="text-gray-400 text-sm">No context available</p>';
        })
        .catch(function() {
            document.getElementById('review-context').innerHTML = '<p class="text-red-500 text-sm">Failed to load context</p>';
        });
}

function closeReview() {
    document.getElementById('review-modal').classList.add('hidden');
    currentReviewId = null;
}

function submitReview(action) {
    if (!currentReviewId) return;

    var confirmMsg = {
        dismissed: 'Dismiss this flag? No action will be taken against the sender.',
        warned: 'Warn the sender about this message?',
        suspended: 'Suspend the sender? This is a severe action.'
    };

    if (!confirm(confirmMsg[action] || 'Are you sure?')) return;

    var fd = new FormData();
    fd.append('review_id', currentReviewId);
    fd.append('action', action);
    fd.append('notes', document.getElementById('review-notes').value);
    fd.append('csrf_token', csrfToken);

    fetch('admin_flagged_messages.php?ajax=review', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                alert(data.error);
                return;
            }
            closeReview();
            location.reload();
        })
        .catch(function() { alert('Error processing review.'); });
}

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}
</script>

<?php include 'includes/footer.php'; ?>
