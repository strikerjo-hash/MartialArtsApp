<?php
/**
 * student_messages.php — Student Message Board
 *
 * Displays in-app messages sent to the student by admin/instructors.
 * One-way: students can read messages and mark them as read.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/messaging.php';

// Require student login
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}
require_student_payment_clear();

$studentId = $_SESSION['student_id'];

// ── AJAX: Mark message as read ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!hash_equals($expected, $csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    $msgId = (int) ($_POST['message_id'] ?? 0);
    if ($msgId && $studentId) {
        try {
            $params = [$msgId, $studentId];
            $sql = "UPDATE message_recipients
                SET inapp_status = 'read', read_at = NOW()
                WHERE message_id = ? AND recipient_type = 'student' AND recipient_id = ? AND inapp_status = 'delivered'" . school_where();
            school_param($params);
            $pdo->prepare($sql)->execute($params);
            echo json_encode(['success' => true]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// ── Fetch messages for this student ──
$msgs = [];
try {
    $params = [$studentId];
    $sql = "SELECT m.id as message_id, m.subject, m.body, m.sent_at,
               mr.inapp_status, mr.read_at,
               u.full_name as sender_name
        FROM message_recipients mr
        JOIN messages m ON m.id = mr.message_id
        LEFT JOIN users u ON m.sender_id = u.id
        WHERE mr.recipient_type = 'student'
          AND mr.recipient_id = ?
          AND m.channel_inapp = 1
          AND m.status = 'sent'" . school_where('m') . "
        ORDER BY m.sent_at DESC";
    school_param($params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $msgs = $stmt->fetchAll();
} catch (\PDOException $e) {}

$unreadCount = 0;
foreach ($msgs as $m) {
    if ($m['inapp_status'] === 'delivered') $unreadCount++;
}

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Messages</h1>
            <p class="text-gray-600 mt-1">
                <?php if ($unreadCount > 0): ?>
                    You have <strong class="text-blue-600"><?= $unreadCount ?></strong> unread message<?= $unreadCount !== 1 ? 's' : '' ?>
                <?php else: ?>
                    All messages read
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if (empty($msgs)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <span class="text-5xl mb-4 block">&#128172;</span>
            <h3 class="text-lg font-semibold text-gray-700 mb-2">No Messages Yet</h3>
            <p class="text-gray-500">You don't have any messages from the studio yet. When the studio sends you a message, it will appear here.</p>
        </div>
    <?php else: ?>
        <div class="space-y-3" id="message-list">
            <?php foreach ($msgs as $idx => $m):
                $isUnread = ($m['inapp_status'] === 'delivered');
                $msgId    = (int) $m['message_id'];
            ?>
                <div class="bg-white rounded-lg shadow overflow-hidden message-card <?= $isUnread ? 'border-l-4 border-blue-500' : '' ?>"
                     id="msg-<?= $msgId ?>">
                    <!-- Header (clickable to expand) -->
                    <div class="px-6 py-4 cursor-pointer hover:bg-gray-50 flex items-start gap-3"
                         onclick="toggleMessage(<?= $msgId ?>, <?= $isUnread ? 'true' : 'false' ?>)">
                        <!-- Unread dot -->
                        <div class="flex-shrink-0 mt-1">
                            <?php if ($isUnread): ?>
                                <span class="inline-block w-3 h-3 rounded-full bg-blue-500" id="dot-<?= $msgId ?>"></span>
                            <?php else: ?>
                                <span class="inline-block w-3 h-3 rounded-full bg-gray-300"></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between">
                                <h3 class="font-semibold text-gray-800 <?= $isUnread ? '' : 'font-normal' ?> truncate" id="subj-<?= $msgId ?>">
                                    <?= htmlspecialchars($m['subject']) ?>
                                </h3>
                                <span class="text-xs text-gray-500 flex-shrink-0 ml-4">
                                    <?= date('M j, Y', strtotime($m['sent_at'])) ?>
                                </span>
                            </div>
                            <p class="text-sm text-gray-500 mt-0.5">
                                From: <?= htmlspecialchars($m['sender_name'] ?? 'Studio') ?>
                            </p>
                        </div>
                        <div class="flex-shrink-0 text-gray-400 transition-transform" id="arrow-<?= $msgId ?>">
                            &#9660;
                        </div>
                    </div>

                    <!-- Body (hidden by default, expanded on click) -->
                    <div class="hidden px-6 pb-5 pt-2 border-t border-gray-100" id="body-<?= $msgId ?>">
                        <div class="prose text-gray-700 text-sm">
                            <?= nl2br($m['body']) ?>
                        </div>
                        <?php if ($m['read_at']): ?>
                            <p class="text-xs text-gray-400 mt-4">Read on <?= date('M j, Y g:ia', strtotime($m['read_at'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
var expandedMessages = {};

function toggleMessage(msgId, isUnread) {
    var body  = document.getElementById('body-' + msgId);
    var arrow = document.getElementById('arrow-' + msgId);

    if (expandedMessages[msgId]) {
        // Collapse
        body.classList.add('hidden');
        arrow.style.transform = '';
        expandedMessages[msgId] = false;
    } else {
        // Expand
        body.classList.remove('hidden');
        arrow.style.transform = 'rotate(180deg)';
        expandedMessages[msgId] = true;

        // Mark as read if unread
        if (isUnread) {
            markAsRead(msgId);
        }
    }
}

function markAsRead(msgId) {
    var fd = new FormData();
    fd.append('message_id', msgId);
    fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '<?= csrf_token() ?>');

    fetch('student_messages.php?ajax=mark_read', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                // Update UI: remove blue dot, remove bold, remove border
                var dot = document.getElementById('dot-' + msgId);
                if (dot) {
                    dot.classList.remove('bg-blue-500');
                    dot.classList.add('bg-gray-300');
                }
                var subj = document.getElementById('subj-' + msgId);
                if (subj) subj.classList.remove('font-semibold');

                var card = document.getElementById('msg-' + msgId);
                if (card) {
                    card.classList.remove('border-l-4', 'border-blue-500');
                }

                // Update unread badge in nav if present
                var badges = document.querySelectorAll('.msg-unread-badge');
                badges.forEach(function(badge) {
                    var count = parseInt(badge.textContent) || 0;
                    if (count > 1) {
                        badge.textContent = count - 1;
                    } else {
                        badge.style.display = 'none';
                    }
                });
            }
        })
        .catch(function() {});
}
</script>

<?php include 'includes/student_footer.php'; ?>
