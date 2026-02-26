<?php
/**
 * messages.php — Admin Messaging Center
 *
 * Compose and send messages to students, instructors, and staff via:
 *   - Email (SMTP)
 *   - SMS (Twilio)
 *   - In-App Message Board (student portal)
 *
 * Includes audience filtering by status, membership plan, event, and role.
 */

require_once 'config.php';
requireLogin();
require_once __DIR__ . '/includes/messaging.php';

// ── AJAX: Preview recipient count ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'preview_recipients') {
    header('Content-Type: application/json');
    $filters = [
        'audience_type'  => $_GET['audience_type'] ?? 'all_students',
        'student_status' => $_GET['student_status'] ?? '',
        'plan_id'        => !empty($_GET['plan_id']) ? (int) $_GET['plan_id'] : null,
        'event_id'       => !empty($_GET['event_id']) ? (int) $_GET['event_id'] : null,
        'user_roles'     => !empty($_GET['user_roles']) ? explode(',', $_GET['user_roles']) : [],
    ];
    $recipients = get_recipients_by_filter($filters);
    echo json_encode([
        'student_count' => count($recipients['students']),
        'user_count'    => count($recipients['users']),
        'total'         => count($recipients['students']) + count($recipients['users']),
    ]);
    exit;
}

$message = '';

// ── POST Handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'send':
                $subject      = trim($_POST['msg_subject'] ?? '');
                $body         = trim($_POST['msg_body'] ?? '');
                $channelEmail = isset($_POST['channel_email']) ? 1 : 0;
                $channelSms   = isset($_POST['channel_sms']) ? 1 : 0;
                $channelInapp = isset($_POST['channel_inapp']) ? 1 : 0;
                $audienceType = $_POST['audience_type'] ?? 'all_students';

                $filters = [
                    'audience_type'  => $audienceType,
                    'student_status' => $_POST['student_status'] ?? '',
                    'plan_id'        => !empty($_POST['plan_id']) ? (int) $_POST['plan_id'] : null,
                    'event_id'       => !empty($_POST['event_id']) ? (int) $_POST['event_id'] : null,
                    'user_roles'     => $_POST['user_roles'] ?? [],
                ];

                if (empty($subject) || empty($body)) {
                    $message = showAlert('Subject and message body are required.', 'error');
                    break;
                }
                if (!$channelEmail && !$channelSms && !$channelInapp) {
                    $message = showAlert('Select at least one delivery channel.', 'error');
                    break;
                }

                // Insert message record
                $stmt = $pdo->prepare("
                    INSERT INTO messages (school_id, subject, body, sender_id, channel_email, channel_sms, channel_inapp, audience_type, audience_filters, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
                ");
                $stmt->execute([
                    current_school_id(),
                    $subject, $body, $_SESSION['user_id'],
                    $channelEmail, $channelSms, $channelInapp,
                    $audienceType, json_encode($filters),
                ]);
                $newMsgId = (int) $pdo->lastInsertId();

                // Dispatch
                $stats = dispatch_message($newMsgId);

                $parts = [];
                $parts[] = "{$stats['total']} recipient" . ($stats['total'] !== 1 ? 's' : '');
                if ($channelEmail) $parts[] = "Emails: {$stats['emails_sent']} sent" . ($stats['emails_failed'] > 0 ? ", {$stats['emails_failed']} failed" : '');
                if ($channelSms)   $parts[] = "SMS: {$stats['sms_sent']} sent" . ($stats['sms_failed'] > 0 ? ", {$stats['sms_failed']} failed" : '');
                if ($channelInapp) $parts[] = 'In-app: delivered';

                $alertType = ($stats['emails_failed'] + $stats['sms_failed'] > 0) ? 'warning' : 'success';
                $message = showAlert('Message sent! ' . implode(' | ', $parts), $alertType);
                break;

            case 'delete':
                $delId = (int) ($_POST['message_id'] ?? 0);
                if ($delId) {
                    $pdo->prepare("DELETE FROM message_recipients WHERE message_id = ?")->execute([$delId]);
                    $params = [$delId];
                    $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ?" . school_where());
                    school_param($params);
                    $stmt->execute($params);
                    $message = showAlert('Message deleted.', 'success');
                }
                break;
        }
    }
}

// ── Fetch data ──
$pageNum  = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 20;
$offset   = ($pageNum - 1) * $perPage;

$schoolFilter = '';
if (!is_viewing_all_schools()) {
    $schoolFilter = ' WHERE m.school_id = :school_id';
}
$histStmt = $pdo->prepare("
    SELECT m.*, u.full_name as sender_name
    FROM messages m
    LEFT JOIN users u ON m.sender_id = u.id
    $schoolFilter
    ORDER BY m.created_at DESC
    LIMIT :lim OFFSET :off
");
if (!is_viewing_all_schools()) {
    $histStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT);
}
$histStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$histStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$histStmt->execute();
$messagesList = $histStmt->fetchAll();

$params = [];
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE 1=1" . school_where());
school_param($params);
$stmt->execute($params);
$totalMessages = (int) $stmt->fetchColumn();
$totalPages    = max(1, (int) ceil($totalMessages / $perPage));

// Data for audience builder
$params = [];
$stmt = $pdo->prepare("SELECT id, name FROM membership_plans WHERE status = 'active'" . school_where() . " ORDER BY name");
school_param($params);
$stmt->execute($params);
$plans = $stmt->fetchAll();
$eventsList = [];
try {
    $params = [];
    $stmt = $pdo->prepare("SELECT id, name, event_date FROM events WHERE 1=1" . school_where() . " ORDER BY event_date DESC LIMIT 50");
    school_param($params);
    $stmt->execute($params);
    $eventsList = $stmt->fetchAll();
} catch (\PDOException $e) {}

$emailConfigured = is_email_configured();
$smsConfigured   = is_sms_configured();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Messages</h1>
    </div>

    <!-- ============================================================= -->
    <!--  COMPOSE MESSAGE                                               -->
    <!-- ============================================================= -->
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Compose Message</h2>
        </div>
        <form method="POST" class="p-6 space-y-5" id="compose-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">

            <!-- Subject -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Subject *</label>
                <input type="text" name="msg_subject" required placeholder="Message subject"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <!-- Body -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Message Body *</label>
                <textarea name="msg_body" rows="6" required placeholder="Write your message here..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
                <p class="text-xs text-gray-500 mt-1">HTML is supported for email. SMS will receive a plain-text version.</p>
            </div>

            <!-- Delivery Channels -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Delivery Channels</label>
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 px-4 py-2 border rounded-lg cursor-pointer hover:bg-blue-50 <?php echo $emailConfigured ? '' : 'opacity-60'; ?>">
                        <input type="checkbox" name="channel_email" value="1" <?php echo $emailConfigured ? '' : ''; ?>>
                        <span>&#9993; Email</span>
                        <?php if (!$emailConfigured): ?>
                            <span class="text-xs text-orange-600" title="Configure SMTP in Settings">&#9888; Not configured</span>
                        <?php endif; ?>
                    </label>
                    <label class="flex items-center gap-2 px-4 py-2 border rounded-lg cursor-pointer hover:bg-blue-50 <?php echo $smsConfigured ? '' : 'opacity-60'; ?>">
                        <input type="checkbox" name="channel_sms" value="1">
                        <span>&#128241; SMS</span>
                        <?php if (!$smsConfigured): ?>
                            <span class="text-xs text-orange-600" title="Configure Twilio in Settings">&#9888; Not configured</span>
                        <?php endif; ?>
                    </label>
                    <label class="flex items-center gap-2 px-4 py-2 border rounded-lg cursor-pointer hover:bg-blue-50">
                        <input type="checkbox" name="channel_inapp" value="1" checked>
                        <span>&#128203; In-App Board</span>
                    </label>
                </div>
            </div>

            <!-- Audience Selector -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Audience</label>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2 mb-3">
                    <label class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer hover:bg-gray-50">
                        <input type="radio" name="audience_type" value="all_students" checked class="audience-radio"> All Students
                    </label>
                    <label class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer hover:bg-gray-50">
                        <input type="radio" name="audience_type" value="all_staff" class="audience-radio"> All Staff
                    </label>
                    <label class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer hover:bg-gray-50">
                        <input type="radio" name="audience_type" value="all" class="audience-radio"> Everyone
                    </label>
                    <label class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer hover:bg-gray-50">
                        <input type="radio" name="audience_type" value="custom" class="audience-radio"> Custom Filter
                    </label>
                </div>

                <!-- Custom Filters (shown when "Custom" is selected) -->
                <div id="custom-filters" class="hidden bg-gray-50 rounded-lg p-4 border border-gray-200 space-y-3">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Student Status</label>
                            <select name="student_status" id="filter-status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                <option value="">Any</option>
                                <option value="active">Active Only</option>
                                <option value="inactive">Inactive Only</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Membership Plan</label>
                            <select name="plan_id" id="filter-plan" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                <option value="">Any Plan</option>
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Event Registration</label>
                            <select name="event_id" id="filter-event" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                <option value="">Any Event</option>
                                <?php foreach ($eventsList as $ev): ?>
                                    <option value="<?= $ev['id'] ?>"><?= htmlspecialchars($ev['name']) ?> (<?= $ev['event_date'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Include Staff Roles</label>
                        <div class="flex flex-wrap gap-3">
                            <label class="flex items-center gap-1 text-sm">
                                <input type="checkbox" name="user_roles[]" value="admin" class="role-checkbox"> Admin
                            </label>
                            <label class="flex items-center gap-1 text-sm">
                                <input type="checkbox" name="user_roles[]" value="instructor" class="role-checkbox"> Instructors
                            </label>
                            <label class="flex items-center gap-1 text-sm">
                                <input type="checkbox" name="user_roles[]" value="staff" class="role-checkbox"> Staff
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Recipient Preview -->
                <div id="recipient-preview" class="mt-3 flex items-center gap-2">
                    <span class="text-sm text-gray-600">Recipients:</span>
                    <span id="recipient-count" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-blue-100 text-blue-800">
                        Calculating...
                    </span>
                </div>
            </div>

            <!-- Send Button -->
            <div class="flex items-center gap-4 pt-2">
                <button type="submit" onclick="return confirm('Send this message to all selected recipients?')"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-2.5 rounded-lg font-medium">
                    &#9993; Send Message
                </button>
            </div>
        </form>
    </div>

    <!-- ============================================================= -->
    <!--  MESSAGE HISTORY                                               -->
    <!-- ============================================================= -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">Message History</h2>
            <span class="text-sm text-gray-500"><?= $totalMessages ?> message<?= $totalMessages !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($messagesList)): ?>
            <div class="p-12 text-center text-gray-500">
                <span class="text-4xl block mb-3">&#128172;</span>
                <p class="text-lg">No messages sent yet</p>
                <p class="text-sm mt-1">Compose your first message above to get started.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Subject</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sender</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Channels</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Recipients</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Delivery</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($messagesList as $m): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?= $m['sent_at'] ? date('M j, Y g:ia', strtotime($m['sent_at'])) : date('M j, Y', strtotime($m['created_at'])) ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                <?= htmlspecialchars(substr($m['subject'], 0, 60)) ?><?= strlen($m['subject']) > 60 ? '...' : '' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?= htmlspecialchars($m['sender_name'] ?? 'System') ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?= $m['channel_email'] ? '<span title="Email">&#9993;</span> ' : '' ?>
                                <?= $m['channel_sms'] ? '<span title="SMS">&#128241;</span> ' : '' ?>
                                <?= $m['channel_inapp'] ? '<span title="In-App">&#128203;</span>' : '' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?= $m['total_recipients'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php if ($m['channel_email'] && ($m['emails_sent'] > 0 || $m['emails_failed'] > 0)): ?>
                                    <span class="text-green-600"><?= $m['emails_sent'] ?>&#10003;</span>
                                    <?php if ($m['emails_failed'] > 0): ?>
                                        <span class="text-red-600"><?= $m['emails_failed'] ?>&#10007;</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($m['channel_sms'] && ($m['sms_sent'] > 0 || $m['sms_failed'] > 0)): ?>
                                    <span class="text-blue-600 ml-1"><?= $m['sms_sent'] ?>&#128241;</span>
                                    <?php if ($m['sms_failed'] > 0): ?>
                                        <span class="text-red-600"><?= $m['sms_failed'] ?>&#10007;</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($m['status'] === 'sent' && !$m['channel_email'] && !$m['channel_sms']): ?>
                                    <span class="text-green-600">Delivered</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <button onclick="viewMessage(<?= htmlspecialchars(json_encode($m)) ?>)"
                                        class="text-blue-600 hover:text-blue-800 mr-3">View</button>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete this message and all recipient records?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="message_id" value="<?= $m['id'] ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                <span class="text-sm text-gray-600">Page <?= $pageNum ?> of <?= $totalPages ?></span>
                <div class="flex gap-2">
                    <?php if ($pageNum > 1): ?>
                        <a href="?page=<?= $pageNum - 1 ?>" class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">&laquo; Prev</a>
                    <?php endif; ?>
                    <?php if ($pageNum < $totalPages): ?>
                        <a href="?page=<?= $pageNum + 1 ?>" class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- View Message Modal -->
<div id="viewModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-6 border w-full max-w-2xl shadow-lg rounded-lg bg-white" style="margin-bottom:4rem;">
        <div class="flex justify-between items-start mb-4">
            <div>
                <h3 id="vm-subject" class="text-xl font-bold text-gray-800"></h3>
                <p class="text-sm text-gray-500 mt-1">
                    <span id="vm-sender"></span> &bull; <span id="vm-date"></span>
                </p>
            </div>
            <button onclick="document.getElementById('viewModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800 text-2xl">&times;</button>
        </div>
        <div class="mb-4">
            <span id="vm-channels" class="text-sm"></span>
            <span id="vm-audience" class="text-sm text-gray-500 ml-3"></span>
        </div>
        <div class="border-t pt-4">
            <div id="vm-body" class="prose text-gray-700 text-sm" style="max-height:400px;overflow-y:auto;"></div>
        </div>
        <div id="vm-stats" class="mt-4 pt-4 border-t text-sm text-gray-600"></div>
    </div>
</div>

<script>
// ── Audience selector: toggle custom filters ──
document.querySelectorAll('.audience-radio').forEach(function(radio) {
    radio.addEventListener('change', function() {
        var custom = document.getElementById('custom-filters');
        custom.classList.toggle('hidden', this.value !== 'custom');
        updatePreview();
    });
});

// ── Live recipient preview ──
var previewTimeout;
function updatePreview() {
    clearTimeout(previewTimeout);
    previewTimeout = setTimeout(function() {
        var audType = document.querySelector('input[name="audience_type"]:checked').value;
        var params = 'ajax=preview_recipients&audience_type=' + audType;

        if (audType === 'custom') {
            var status = document.getElementById('filter-status').value;
            var planId = document.getElementById('filter-plan').value;
            var eventId = document.getElementById('filter-event').value;
            var roles = [];
            document.querySelectorAll('.role-checkbox:checked').forEach(function(cb) { roles.push(cb.value); });

            if (status) params += '&student_status=' + status;
            if (planId) params += '&plan_id=' + planId;
            if (eventId) params += '&event_id=' + eventId;
            if (roles.length) params += '&user_roles=' + roles.join(',');
        }

        fetch('messages.php?' + params)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var parts = [];
                if (data.student_count > 0) parts.push(data.student_count + ' student' + (data.student_count !== 1 ? 's' : ''));
                if (data.user_count > 0) parts.push(data.user_count + ' staff');
                var text = parts.length > 0 ? parts.join(', ') + ' (' + data.total + ' total)' : '0 recipients';
                document.getElementById('recipient-count').textContent = text;
            })
            .catch(function() {
                document.getElementById('recipient-count').textContent = 'Error';
            });
    }, 300);
}

// Add change listeners to all filter elements
document.querySelectorAll('#filter-status, #filter-plan, #filter-event').forEach(function(el) {
    el.addEventListener('change', updatePreview);
});
document.querySelectorAll('.role-checkbox').forEach(function(cb) {
    cb.addEventListener('change', updatePreview);
});

// Initial preview
updatePreview();

// ── View message modal ──
function viewMessage(m) {
    document.getElementById('vm-subject').textContent = m.subject;
    document.getElementById('vm-sender').textContent = m.sender_name || 'System';
    document.getElementById('vm-date').textContent = m.sent_at || m.created_at;
    document.getElementById('vm-body').innerHTML = m.body;

    var channels = [];
    if (m.channel_email == 1) channels.push('\u2709 Email');
    if (m.channel_sms == 1) channels.push('\uD83D\uDCF1 SMS');
    if (m.channel_inapp == 1) channels.push('\uD83D\uDCCB In-App');
    document.getElementById('vm-channels').textContent = 'Channels: ' + channels.join(', ');
    document.getElementById('vm-audience').textContent = 'Audience: ' + m.audience_type;

    var stats = [];
    stats.push(m.total_recipients + ' recipients');
    if (m.channel_email == 1) stats.push('Emails: ' + m.emails_sent + ' sent, ' + m.emails_failed + ' failed');
    if (m.channel_sms == 1) stats.push('SMS: ' + m.sms_sent + ' sent, ' + m.sms_failed + ' failed');
    document.getElementById('vm-stats').textContent = stats.join(' | ');

    document.getElementById('viewModal').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
