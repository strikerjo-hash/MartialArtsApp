<?php
/**
 * admin_conversation_audit.php — Admin Conversation Audit & Search
 *
 * Allows admins to search and view any student conversation for oversight.
 * Read-only — admins cannot modify or delete messages.
 */

require_once 'config.php';
requireLogin();
require_once __DIR__ . '/includes/conversation_helpers.php';

$schoolId = (int) ($_SESSION['school_id'] ?? 1);

// ── AJAX: View conversation messages ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'view_conversation') {
    header('Content-Type: application/json');

    $convId = (int) ($_GET['conversation_id'] ?? 0);
    $page   = max(1, (int) ($_GET['page'] ?? 1));

    if (!$convId) {
        echo json_encode(['error' => 'Missing conversation ID.']);
        exit;
    }

    try {
        // Verify conversation belongs to this school
        $params = [$convId, $schoolId];
        $check = $pdo->prepare("SELECT id FROM conversations WHERE id = ? AND school_id = ?");
        $check->execute($params);
        if (!$check->fetch()) {
            echo json_encode(['error' => 'Conversation not found.']);
            exit;
        }

        $data = get_conversation_messages($convId, $schoolId, $page, 100);

        // Get both participants
        $partStmt = $pdo->prepare(
            "SELECT participant_type, participant_id FROM conversation_participants WHERE conversation_id = ? AND school_id = ?"
        );
        $partStmt->execute([$convId, $schoolId]);
        $participants = $partStmt->fetchAll();

        $partInfo = [];
        foreach ($participants as $p) {
            if ($p['participant_type'] === 'student') {
                $s = $pdo->prepare("SELECT first_name, last_name FROM students WHERE id = ?");
                $s->execute([$p['participant_id']]);
                $sr = $s->fetch();
                $partInfo[] = ['type' => 'student', 'id' => (int) $p['participant_id'], 'name' => $sr ? trim($sr['first_name'] . ' ' . $sr['last_name']) : 'Student #' . $p['participant_id']];
            } else {
                $a = $pdo->prepare("SELECT full_name, role FROM users WHERE id = ?");
                $a->execute([$p['participant_id']]);
                $ar = $a->fetch();
                $partInfo[] = ['type' => 'admin', 'id' => (int) $p['participant_id'], 'name' => $ar ? $ar['full_name'] : 'Staff #' . $p['participant_id'], 'role' => $ar ? $ar['role'] : 'staff'];
            }
        }

        echo json_encode([
            'success'      => true,
            'messages'     => $data['messages'],
            'has_more'     => $data['has_more'],
            'participants' => $partInfo,
        ]);
    } catch (\PDOException $e) {
        echo json_encode(['error' => 'Database error.']);
    }
    exit;
}

// ── Search for conversations ──
$search = trim($_GET['search'] ?? '');
$conversations = [];

if ($search !== '') {
    try {
        $searchParam = '%' . $search . '%';

        // Find students matching the search
        $params = [$schoolId, $searchParam, $searchParam, $searchParam];
        $studentSql = "SELECT id FROM students WHERE school_id = ? AND (CONCAT(first_name, ' ', last_name) LIKE ? OR first_name LIKE ? OR last_name LIKE ?) LIMIT 50";
        $stmt = $pdo->prepare($studentSql);
        $stmt->execute($params);
        $matchedStudentIds = array_column($stmt->fetchAll(), 'id');

        // Also search staff
        $staffStmt = $pdo->prepare("SELECT id FROM users WHERE school_id = ? AND full_name LIKE ? LIMIT 20");
        $staffStmt->execute([$schoolId, $searchParam]);
        $matchedStaffIds = array_column($staffStmt->fetchAll(), 'id');

        if (!empty($matchedStudentIds) || !empty($matchedStaffIds)) {
            // Build a query that finds conversations involving any of these people
            $conditions = [];
            $queryParams = [$schoolId];

            if (!empty($matchedStudentIds)) {
                $ph = implode(',', array_fill(0, count($matchedStudentIds), '?'));
                $conditions[] = "(cp.participant_type = 'student' AND cp.participant_id IN ({$ph}))";
                $queryParams = array_merge($queryParams, $matchedStudentIds);
            }
            if (!empty($matchedStaffIds)) {
                $ph = implode(',', array_fill(0, count($matchedStaffIds), '?'));
                $conditions[] = "(cp.participant_type = 'admin' AND cp.participant_id IN ({$ph}))";
                $queryParams = array_merge($queryParams, $matchedStaffIds);
            }

            $condStr = implode(' OR ', $conditions);

            $sql = "SELECT DISTINCT c.id AS conversation_id, c.last_message_at, c.last_message_preview, c.created_at
                    FROM conversations c
                    JOIN conversation_participants cp ON cp.conversation_id = c.id
                    WHERE c.school_id = ? AND ({$condStr})
                    ORDER BY c.last_message_at DESC
                    LIMIT 50";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($queryParams);
            $convRows = $stmt->fetchAll();

            // Get participant info for each conversation
            foreach ($convRows as $cr) {
                $pStmt = $pdo->prepare(
                    "SELECT participant_type, participant_id FROM conversation_participants WHERE conversation_id = ? AND school_id = ?"
                );
                $pStmt->execute([$cr['conversation_id'], $schoolId]);
                $parts = $pStmt->fetchAll();

                $partNames = [];
                foreach ($parts as $p) {
                    if ($p['participant_type'] === 'student') {
                        $s = $pdo->prepare("SELECT first_name, last_name FROM students WHERE id = ?");
                        $s->execute([$p['participant_id']]);
                        $sr = $s->fetch();
                        $partNames[] = ($sr ? trim($sr['first_name'] . ' ' . $sr['last_name']) : 'Student') . ' (Student)';
                    } else {
                        $a = $pdo->prepare("SELECT full_name, role FROM users WHERE id = ?");
                        $a->execute([$p['participant_id']]);
                        $ar = $a->fetch();
                        $partNames[] = ($ar ? $ar['full_name'] : 'Staff') . ' (' . ucfirst($ar['role'] ?? 'staff') . ')';
                    }
                }

                // Get message count
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM direct_messages WHERE conversation_id = ? AND school_id = ?");
                $countStmt->execute([$cr['conversation_id'], $schoolId]);
                $msgCount = (int) $countStmt->fetchColumn();

                // Get flagged message count
                $flagStmt = $pdo->prepare("SELECT COUNT(*) FROM direct_messages WHERE conversation_id = ? AND school_id = ? AND is_flagged = 1");
                $flagStmt->execute([$cr['conversation_id'], $schoolId]);
                $flagCount = (int) $flagStmt->fetchColumn();

                $conversations[] = [
                    'id'           => (int) $cr['conversation_id'],
                    'participants' => implode(' ↔ ', $partNames),
                    'last_message' => $cr['last_message_at'],
                    'preview'      => $cr['last_message_preview'],
                    'msg_count'    => $msgCount,
                    'flag_count'   => $flagCount,
                    'created_at'   => $cr['created_at'],
                ];
            }
        }
    } catch (\PDOException $e) {
        // Tables may not exist yet
    }
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Conversation Audit</h1>
        <p class="text-gray-500 mt-1">Search and review student conversations for oversight purposes.</p>
    </div>

    <!-- Search Form -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex gap-3">
            <div class="relative flex-1">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Search by student or staff name..."
                       class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 text-sm">
                <svg class="absolute left-3 top-2.5 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg text-sm font-medium transition-colors">
                Search
            </button>
        </form>
    </div>

    <?php if ($search !== '' && empty($conversations)): ?>
        <div class="bg-white rounded-lg shadow p-8 text-center">
            <p class="text-gray-500">No conversations found for "<strong><?= htmlspecialchars($search) ?></strong>"</p>
        </div>
    <?php elseif (!empty($conversations)): ?>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Participants</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Message</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Messages</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Flagged</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($conversations as $conv): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-800 font-medium">
                                <?= htmlspecialchars($conv['participants']) ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <div class="text-xs text-gray-400"><?= $conv['last_message'] ? date('M j, Y g:ia', strtotime($conv['last_message'])) : 'Never' ?></div>
                                <div class="text-gray-500 truncate max-w-xs"><?= htmlspecialchars($conv['preview'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?= $conv['msg_count'] ?></td>
                            <td class="px-4 py-3 text-sm">
                                <?php if ($conv['flag_count'] > 0): ?>
                                    <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800"><?= $conv['flag_count'] ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <button onclick="viewConversation(<?= $conv['id'] ?>)"
                                        class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                                    View
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($search === ''): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <svg class="mx-auto w-16 h-16 mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <h3 class="text-lg font-semibold text-gray-700 mb-2">Search for Conversations</h3>
            <p class="text-gray-500">Enter a student or staff member name to find their conversations.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Conversation Viewer Modal -->
<div id="conv-viewer-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
            <div>
                <h3 class="text-lg font-semibold text-gray-800">Conversation Viewer</h3>
                <p id="viewer-participants" class="text-sm text-gray-500"></p>
            </div>
            <button onclick="closeViewer()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="viewer-messages" class="flex-1 overflow-y-auto p-4 space-y-2" style="max-height: 60vh;">
            <p class="text-center text-gray-400 animate-pulse">Loading messages...</p>
        </div>
        <div class="px-6 py-3 border-t border-gray-200 bg-gray-50 text-center text-xs text-gray-500">
            Read-only audit view — messages cannot be modified or deleted
        </div>
    </div>
</div>

<script>
function viewConversation(convId) {
    document.getElementById('conv-viewer-modal').classList.remove('hidden');
    document.getElementById('viewer-messages').innerHTML = '<p class="text-center text-gray-400 animate-pulse">Loading messages...</p>';
    document.getElementById('viewer-participants').textContent = '';

    fetch('admin_conversation_audit.php?ajax=view_conversation&conversation_id=' + convId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                document.getElementById('viewer-messages').innerHTML = '<p class="text-center text-red-500">' + escapeHtml(data.error) + '</p>';
                return;
            }

            // Show participants
            var partStr = data.participants.map(function(p) {
                return p.name + ' (' + (p.role ? ucfirst(p.role) : p.type) + ')';
            }).join(' \u2194 ');
            document.getElementById('viewer-participants').textContent = partStr;

            // Render messages
            if (data.messages.length === 0) {
                document.getElementById('viewer-messages').innerHTML = '<p class="text-center text-gray-400">No messages in this conversation.</p>';
                return;
            }

            var html = '';
            data.messages.forEach(function(msg) {
                var flagClass = msg.is_flagged ? 'border-l-4 border-red-400' : '';
                html += '<div class="' + flagClass + ' bg-gray-50 rounded-lg px-4 py-2.5 text-sm">' +
                    '<div class="flex justify-between items-center mb-1">' +
                    '<span class="font-medium text-gray-700">' + escapeHtml(msg.sender_name) +
                    ' <span class="text-xs text-gray-400">(' + ucfirst(msg.sender_role) + ')</span>' +
                    (msg.is_flagged ? ' <span class="text-red-500 text-xs">\u26A0 Flagged</span>' : '') +
                    '</span>' +
                    '<span class="text-xs text-gray-400">' + msg.created_at + '</span>' +
                    '</div>' +
                    '<p class="text-gray-600 whitespace-pre-wrap">' + escapeHtml(msg.body) + '</p>' +
                    '</div>';
            });

            document.getElementById('viewer-messages').innerHTML = html;
        })
        .catch(function() {
            document.getElementById('viewer-messages').innerHTML = '<p class="text-center text-red-500">Failed to load conversation.</p>';
        });
}

function closeViewer() {
    document.getElementById('conv-viewer-modal').classList.add('hidden');
}

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function ucfirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}
</script>

<?php include 'includes/footer.php'; ?>
