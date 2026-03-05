<?php
/**
 * admin_conversations.php — Admin Conversation Inbox
 *
 * Allows admins/instructors/staff to:
 *   - View conversations students have started with them
 *   - Search and select specific individual students to message directly
 *   - Reply to conversations in thread view
 *   - Hide conversations from their view
 *
 * Uses the same conversation system as student_conversations.php.
 */

require_once 'config.php';
requireLogin();
require_once __DIR__ . '/includes/messaging.php';
require_once __DIR__ . '/includes/conversation_helpers.php';

$adminUserId = (int) $_SESSION['user_id'];
$schoolId    = (int) (current_school_id() ?: ($_SESSION['school_id'] ?? 1));
$adminRole   = $_SESSION['role'] ?? 'staff';

// ── AJAX Endpoints ──────────────────────────────────────────────────────────

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // CSRF verification for all POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!hash_equals($expected, $csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
    }

    $ajax = $_GET['ajax'];

    // ── List conversations ──
    if ($ajax === 'list_conversations') {
        $conversations = get_conversations_for_user($schoolId, 'admin', $adminUserId);
        echo json_encode(['success' => true, 'conversations' => $conversations]);
        exit;
    }

    // ── Get messages for a conversation ──
    if ($ajax === 'get_messages') {
        $convId = (int) ($_GET['conversation_id'] ?? 0);
        $page   = max(1, (int) ($_GET['page'] ?? 1));

        if (!$convId || !verify_conversation_participant($convId, $schoolId, 'admin', $adminUserId)) {
            echo json_encode(['error' => 'Conversation not found.']);
            exit;
        }

        // Mark as read
        mark_conversation_read($convId, $schoolId, 'admin', $adminUserId);

        $data = get_conversation_messages($convId, $schoolId, $page);
        $other = get_other_participant($convId, $schoolId, 'admin', $adminUserId);

        echo json_encode([
            'success'  => true,
            'messages' => $data['messages'],
            'has_more' => $data['has_more'],
            'other'    => $other,
        ]);
        exit;
    }

    // ── Send a message ──
    if ($ajax === 'send_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $convId = (int) ($_POST['conversation_id'] ?? 0);
        $body   = $_POST['body'] ?? '';

        if (!$convId) {
            echo json_encode(['error' => 'Invalid conversation.']);
            exit;
        }

        $result = send_direct_message($schoolId, $convId, 'admin', $adminUserId, $body);
        if ($result['success']) {
            send_message_notification_email($schoolId, $convId, 'admin', $adminUserId);
        }
        echo json_encode($result);
        exit;
    }

    // ── Start a new conversation ──
    if ($ajax === 'start_conversation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $targetType = $_POST['target_type'] ?? '';
        $targetId   = (int) ($_POST['target_id'] ?? 0);
        $body       = $_POST['body'] ?? '';

        if (!in_array($targetType, ['student', 'admin']) || !$targetId) {
            echo json_encode(['error' => 'Invalid recipient.']);
            exit;
        }

        // Cannot message yourself
        if ($targetType === 'admin' && $targetId === $adminUserId) {
            echo json_encode(['error' => 'You cannot message yourself.']);
            exit;
        }

        $convId = find_or_create_conversation($schoolId, 'admin', $adminUserId, $targetType, $targetId);

        if ($body !== '') {
            $result = send_direct_message($schoolId, $convId, 'admin', $adminUserId, $body);
            if ($result['success']) {
                send_message_notification_email($schoolId, $convId, 'admin', $adminUserId);
            }
            echo json_encode(array_merge($result, ['conversation_id' => $convId]));
        } else {
            echo json_encode(['success' => true, 'conversation_id' => $convId]);
        }
        exit;
    }

    // ── Mark conversation as read ──
    if ($ajax === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $convId = (int) ($_POST['conversation_id'] ?? 0);
        if ($convId) {
            mark_conversation_read($convId, $schoolId, 'admin', $adminUserId);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Hide conversation ──
    if ($ajax === 'hide_conversation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $convId = (int) ($_POST['conversation_id'] ?? 0);
        if ($convId) {
            hide_conversation($convId, $schoolId, 'admin', $adminUserId);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Search contacts (students + other staff) ──
    if ($ajax === 'search_contacts') {
        $q = trim($_GET['q'] ?? '');
        $contacts = get_messageable_contacts_admin($schoolId, $adminUserId, $q);
        echo json_encode(['success' => true, 'contacts' => $contacts]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ── Page HTML ───────────────────────────────────────────────────────────────

$convUnread = get_unread_conversation_count($schoolId, 'admin', $adminUserId);

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-6">
    <!-- Tab Navigation: Broadcasts | Conversations -->
    <div class="mb-6">
        <div class="flex border-b border-gray-200">
            <a href="messages.php"
               class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300 border-b-2 border-transparent transition-colors">
                Broadcasts
            </a>
            <a href="admin_conversations.php"
               class="px-6 py-3 text-sm font-medium text-blue-600 border-b-2 border-blue-500 transition-colors relative">
                Conversations
                <?php if ($convUnread > 0): ?>
                    <span class="ml-1.5 inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-500 rounded-full"><?= $convUnread ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <!-- Main Chat Layout -->
    <div class="bg-white rounded-lg shadow overflow-hidden" style="height: calc(100vh - 260px); min-height: 400px;">
        <div class="flex h-full">

            <!-- Left Panel: Conversation List -->
            <div id="conv-list-panel" class="w-full md:w-1/3 border-r border-gray-200 flex flex-col h-full">
                <!-- Search + New Message -->
                <div class="p-3 border-b border-gray-100 flex-shrink-0">
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <input type="text" id="conv-search" placeholder="Search conversations..."
                                   class="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <button onclick="showNewMessage()" class="flex-shrink-0 bg-blue-600 hover:bg-blue-700 text-white p-2 rounded-lg transition-colors" title="New Conversation">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Conversation Items -->
                <div id="conv-list" class="flex-1 overflow-y-auto">
                    <div class="p-8 text-center text-gray-400">
                        <div class="animate-pulse">Loading conversations...</div>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Message Thread -->
            <div id="conv-thread-panel" class="hidden md:flex flex-col flex-1 h-full">
                <!-- Empty state -->
                <div id="thread-empty" class="flex-1 flex items-center justify-center">
                    <div class="text-center text-gray-400">
                        <svg class="mx-auto w-16 h-16 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                        <p class="text-lg font-medium">Select a conversation</p>
                        <p class="text-sm mt-1">Or message a specific student using the compose button</p>
                    </div>
                </div>

                <!-- Active thread (hidden initially) -->
                <div id="thread-active" class="hidden flex flex-col h-full">
                    <!-- Thread header -->
                    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 flex-shrink-0">
                        <div class="flex items-center gap-3">
                            <button onclick="backToList()" class="md:hidden text-gray-500 hover:text-gray-700">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                </svg>
                            </button>
                            <div id="thread-avatar" class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0"></div>
                            <div>
                                <h3 id="thread-name" class="font-semibold text-gray-800"></h3>
                                <span id="thread-role" class="text-xs px-2 py-0.5 rounded-full"></span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button onclick="hideCurrentConversation()" class="text-sm px-3 py-1.5 rounded-lg border border-gray-300 hover:bg-gray-50 text-gray-600 transition-colors" title="Hide conversation">
                                <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"/>
                                </svg>
                                Hide
                            </button>
                        </div>
                    </div>

                    <!-- Messages area -->
                    <div id="thread-messages" class="flex-1 overflow-y-auto p-4 space-y-3">
                    </div>

                    <!-- Message input -->
                    <div id="thread-input-area" class="border-t border-gray-200 p-3 flex-shrink-0">
                        <form id="send-form" onsubmit="sendMessage(event)" class="flex gap-2">
                            <input type="hidden" id="csrf-token" value="<?= csrf_token() ?>">
                            <input type="text" id="msg-input" placeholder="Type a message..."
                                   class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 text-sm"
                                   maxlength="2000" autocomplete="off">
                            <button type="submit" id="btn-send"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg text-sm font-medium transition-colors flex-shrink-0">
                                Send
                            </button>
                        </form>
                        <div id="char-count" class="text-xs text-gray-400 mt-1 text-right hidden">
                            <span id="char-current">0</span>/2000
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Message Modal -->
<div id="new-msg-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md max-h-[80vh] flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-800">New Conversation</h3>
            <button onclick="closeNewMessage()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-4">
            <input type="text" id="contact-search" placeholder="Search for a student or staff member..."
                   class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 text-sm"
                   oninput="searchContacts(this.value)">
        </div>
        <div id="contact-results" class="flex-1 overflow-y-auto px-4 pb-4 min-h-[200px]">
            <p class="text-center text-gray-400 text-sm py-8">Type a name to search, or browse all contacts...</p>
        </div>
    </div>
</div>

<script>
// ── State ──
var currentConvId = null;
var currentOther = null;
var csrfToken = document.getElementById('csrf-token').value;
var adminUserId = <?= $adminUserId ?>;
var conversations = [];
var searchTimeout = null;
var pollInterval = null;

// ── Initialize ──
document.addEventListener('DOMContentLoaded', function() {
    loadConversations();
    // Poll for new messages every 15 seconds
    pollInterval = setInterval(pollForUpdates, 15000);

    // Character count on input
    var msgInput = document.getElementById('msg-input');
    msgInput.addEventListener('input', function() {
        var count = this.value.length;
        var countEl = document.getElementById('char-count');
        var currentEl = document.getElementById('char-current');
        if (count > 0) {
            countEl.classList.remove('hidden');
            currentEl.textContent = count;
            currentEl.className = count > 1800 ? 'text-red-500 font-bold' : '';
        } else {
            countEl.classList.add('hidden');
        }
    });

    // Enter to send
    msgInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(e);
        }
    });

    // Conversation search filter
    document.getElementById('conv-search').addEventListener('input', function() {
        filterConversations(this.value);
    });

    // Load initial contacts when opening New Message modal
});

// ── Load Conversations ──
function loadConversations() {
    fetch('admin_conversations.php?ajax=list_conversations')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                conversations = data.conversations;
                renderConversationList(conversations);
            }
        })
        .catch(function() {});
}

function renderConversationList(convs) {
    var list = document.getElementById('conv-list');

    if (convs.length === 0) {
        list.innerHTML = '<div class="p-8 text-center text-gray-400">' +
            '<svg class="mx-auto w-12 h-12 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>' +
            '</svg>' +
            '<p class="text-sm font-medium">No conversations yet</p>' +
            '<p class="text-xs mt-1">Click the compose button to message a student</p>' +
            '</div>';
        return;
    }

    var html = '';
    convs.forEach(function(conv) {
        var isActive = conv.conversation_id === currentConvId;
        var hasUnread = conv.unread_count > 0;

        var roleColor = getRoleColor(conv.other_role);
        var initials = getInitials(conv.other_name);
        var timeStr = conv.last_message_at ? formatRelativeTime(conv.last_message_at) : '';

        html += '<div class="conv-item flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-gray-50 transition-colors ' +
            (isActive ? 'bg-blue-50 border-l-4 border-blue-500' : 'border-l-4 border-transparent') +
            '" data-conv-id="' + conv.conversation_id + '" onclick="openConversation(' + conv.conversation_id + ')">' +
            '<div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0" style="background-color: ' + roleColor + '">' + initials + '</div>' +
            '<div class="flex-1 min-w-0">' +
            '<div class="flex items-center justify-between">' +
            '<span class="font-' + (hasUnread ? 'bold' : 'medium') + ' text-gray-800 text-sm truncate">' + escapeHtml(conv.other_name) + '</span>' +
            '<span class="text-xs text-gray-400 flex-shrink-0 ml-2">' + timeStr + '</span>' +
            '</div>' +
            '<div class="flex items-center justify-between mt-0.5">' +
            '<p class="text-xs text-gray-500 truncate">' + (conv.last_message_preview ? escapeHtml(conv.last_message_preview) : '<em>No messages yet</em>') + '</p>' +
            (hasUnread ? '<span class="flex-shrink-0 ml-2 inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-blue-500 rounded-full">' + conv.unread_count + '</span>' : '') +
            '</div>' +
            '</div>' +
            '</div>';
    });

    list.innerHTML = html;
}

function filterConversations(query) {
    var q = query.toLowerCase().trim();
    if (!q) {
        renderConversationList(conversations);
        return;
    }
    var filtered = conversations.filter(function(c) {
        return c.other_name.toLowerCase().indexOf(q) !== -1;
    });
    renderConversationList(filtered);
}

// ── Open Conversation ──
function openConversation(convId) {
    currentConvId = convId;

    // Show thread panel on mobile
    document.getElementById('conv-list-panel').classList.add('hidden', 'md:flex');
    document.getElementById('conv-list-panel').classList.remove('flex');
    document.getElementById('conv-thread-panel').classList.remove('hidden');
    document.getElementById('conv-thread-panel').classList.add('flex');

    // Show loading in thread
    document.getElementById('thread-empty').classList.add('hidden');
    document.getElementById('thread-active').classList.remove('hidden');
    document.getElementById('thread-messages').innerHTML = '<div class="text-center text-gray-400 py-8"><div class="animate-pulse">Loading messages...</div></div>';

    // Update active state in list
    document.querySelectorAll('.conv-item').forEach(function(el) {
        var isThis = parseInt(el.dataset.convId) === convId;
        el.classList.toggle('bg-blue-50', isThis);
        el.classList.toggle('border-blue-500', isThis);
        el.classList.toggle('border-transparent', !isThis);
    });

    fetch('admin_conversations.php?ajax=get_messages&conversation_id=' + convId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                document.getElementById('thread-messages').innerHTML = '<div class="text-center text-red-500 py-8">' + escapeHtml(data.error) + '</div>';
                return;
            }

            currentOther = data.other;
            renderThreadHeader(data.other);
            renderMessages(data.messages);

            // Update unread count in conv list item
            conversations.forEach(function(c) {
                if (c.conversation_id === convId) c.unread_count = 0;
            });
            renderConversationList(conversations);

            // Scroll to bottom
            var messagesDiv = document.getElementById('thread-messages');
            messagesDiv.scrollTop = messagesDiv.scrollHeight;

            // Focus input
            document.getElementById('msg-input').focus();
        })
        .catch(function() {});
}

function renderThreadHeader(other) {
    if (!other) return;

    var roleColor = getRoleColor(other.role);
    var initials = getInitials(other.name);

    document.getElementById('thread-avatar').style.backgroundColor = roleColor;
    document.getElementById('thread-avatar').textContent = initials;
    document.getElementById('thread-name').textContent = other.name;

    var roleEl = document.getElementById('thread-role');
    var roleLabelMap = { admin: 'Admin', instructor: 'Instructor', staff: 'Staff', student: 'Student', super_admin: 'Admin' };
    var roleLabel = roleLabelMap[other.role] || other.role;

    if (other.role !== 'student') {
        roleEl.textContent = roleLabel;
        roleEl.className = 'text-xs px-2 py-0.5 rounded-full font-medium bg-blue-100 text-blue-700';
    } else {
        roleEl.textContent = 'Student';
        roleEl.className = 'text-xs px-2 py-0.5 rounded-full font-medium bg-green-100 text-green-700';
    }
}

function renderMessages(messages) {
    var container = document.getElementById('thread-messages');

    if (messages.length === 0) {
        container.innerHTML = '<div class="text-center text-gray-400 py-8">' +
            '<p class="text-sm">No messages yet. Send the first message!</p></div>';
        return;
    }

    var html = '';
    var lastDate = '';

    messages.forEach(function(msg) {
        var isMine = msg.sender_type === 'admin' && msg.sender_id === adminUserId;
        var msgDate = formatDateHeader(msg.created_at);
        var msgTime = formatTime(msg.created_at);

        // Date separator
        if (msgDate !== lastDate) {
            html += '<div class="flex items-center justify-center my-3"><span class="bg-gray-100 text-gray-500 text-xs px-3 py-1 rounded-full">' + msgDate + '</span></div>';
            lastDate = msgDate;
        }

        // System message
        if (msg.is_system) {
            html += '<div class="text-center text-xs text-gray-400 italic py-1">' + escapeHtml(msg.body) + '</div>';
            return;
        }

        html += '<div class="flex ' + (isMine ? 'justify-end' : 'justify-start') + '">' +
            '<div class="max-w-[75%] ' + (isMine ? 'order-2' : '') + '">';

        if (!isMine) {
            html += '<p class="text-xs text-gray-500 mb-0.5 ml-1">' + escapeHtml(msg.sender_name);
            if (msg.sender_role !== 'student') {
                var roleLabelMap = { admin: 'Admin', instructor: 'Instructor', staff: 'Staff', super_admin: 'Admin' };
                html += ' <span class="text-blue-600 font-medium">' + (roleLabelMap[msg.sender_role] || '') + '</span>';
            } else {
                html += ' <span class="text-green-600 font-medium">Student</span>';
            }
            html += '</p>';
        }

        html += '<div class="px-4 py-2.5 rounded-2xl text-sm ' +
            (isMine
                ? 'bg-blue-600 text-white rounded-br-md'
                : 'bg-gray-100 text-gray-800 rounded-bl-md') + '">' +
            escapeHtml(msg.body).replace(/\n/g, '<br>') +
            '</div>';

        // Flagged indicator
        if (msg.is_flagged) {
            html += '<p class="text-xs text-orange-500 mt-0.5 ' + (isMine ? 'text-right mr-1' : 'ml-1') + '">&#9888; Flagged: ' + escapeHtml(msg.flag_reason || 'Content flagged') + '</p>';
        }

        html += '<p class="text-xs text-gray-400 mt-0.5 ' + (isMine ? 'text-right mr-1' : 'ml-1') + '">' + msgTime + '</p>' +
            '</div></div>';
    });

    container.innerHTML = html;
}

// ── Send Message ──
function sendMessage(e) {
    e.preventDefault();
    var input = document.getElementById('msg-input');
    var body = input.value.trim();
    if (!body || !currentConvId) return;

    var btn = document.getElementById('btn-send');
    btn.disabled = true;
    btn.textContent = '...';

    var fd = new FormData();
    fd.append('conversation_id', currentConvId);
    fd.append('body', body);
    fd.append('csrf_token', csrfToken);

    fetch('admin_conversations.php?ajax=send_message', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.textContent = 'Send';

            if (data.error) {
                alert(data.error);
                return;
            }

            if (data.success) {
                input.value = '';
                document.getElementById('char-count').classList.add('hidden');

                // Append the new message to the thread
                var container = document.getElementById('thread-messages');
                var emptyState = container.querySelector('.text-center.text-gray-400');
                if (emptyState) container.innerHTML = '';

                var now = new Date();
                var msgTime = formatTime(now.toISOString().replace('T', ' ').slice(0, 19));

                var newMsgHtml = '<div class="flex justify-end">' +
                    '<div class="max-w-[75%] order-2">' +
                    '<div class="px-4 py-2.5 rounded-2xl text-sm bg-blue-600 text-white rounded-br-md">' +
                    escapeHtml(body).replace(/\n/g, '<br>') + '</div>';

                if (data.flagged) {
                    newMsgHtml += '<p class="text-xs text-orange-500 mt-0.5 text-right mr-1">&#9888; Message flagged</p>';
                }

                newMsgHtml += '<p class="text-xs text-gray-400 mt-0.5 text-right mr-1">' + msgTime + '</p>' +
                    '</div></div>';

                container.insertAdjacentHTML('beforeend', newMsgHtml);
                container.scrollTop = container.scrollHeight;

                // Update conversation list
                loadConversations();
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Send';
        });
}

// ── New Message Modal ──
function showNewMessage() {
    document.getElementById('new-msg-modal').classList.remove('hidden');
    document.getElementById('contact-search').value = '';
    document.getElementById('contact-search').focus();
    // Load initial contact list (show all)
    searchContacts('');
}

function closeNewMessage() {
    document.getElementById('new-msg-modal').classList.add('hidden');
}

function searchContacts(query) {
    clearTimeout(searchTimeout);

    searchTimeout = setTimeout(function() {
        document.getElementById('contact-results').innerHTML = '<div class="text-center py-8"><div class="animate-pulse text-gray-400 text-sm">Searching...</div></div>';

        fetch('admin_conversations.php?ajax=search_contacts&q=' + encodeURIComponent(query))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || data.contacts.length === 0) {
                    document.getElementById('contact-results').innerHTML = '<p class="text-center text-gray-400 text-sm py-8">No contacts found</p>';
                    return;
                }

                // Group: Students first, then Staff
                var students = data.contacts.filter(function(c) { return c.type === 'student'; });
                var staff = data.contacts.filter(function(c) { return c.type === 'admin'; });

                var html = '';

                if (students.length > 0) {
                    html += '<div class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-3 py-2 mt-1">Students</div>';
                    students.forEach(function(c) {
                        html += renderContactItem(c);
                    });
                }

                if (staff.length > 0) {
                    html += '<div class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-3 py-2 mt-2">Staff</div>';
                    staff.forEach(function(c) {
                        html += renderContactItem(c);
                    });
                }

                document.getElementById('contact-results').innerHTML = html;
            })
            .catch(function() {
                document.getElementById('contact-results').innerHTML = '<p class="text-center text-red-400 text-sm py-8">Error loading contacts</p>';
            });
    }, 300);
}

function renderContactItem(c) {
    var roleColor = getRoleColor(c.role);
    var initials = getInitials(c.name);
    var roleLabelMap = { admin: 'Admin', instructor: 'Instructor', staff: 'Staff', student: 'Student', super_admin: 'Admin' };
    var roleLabel = roleLabelMap[c.role] || c.role;
    var roleClass = c.type === 'admin' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700';

    return '<div class="flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors" onclick="startConversation(\'' + c.type + '\',' + c.id + ')">' +
        '<div class="w-9 h-9 rounded-full flex items-center justify-center text-white font-bold text-xs flex-shrink-0" style="background-color: ' + roleColor + '">' + initials + '</div>' +
        '<div class="flex-1">' +
        '<span class="font-medium text-sm text-gray-800">' + escapeHtml(c.name) + '</span>' +
        ' <span class="text-xs px-1.5 py-0.5 rounded-full ' + roleClass + '">' + roleLabel + '</span>' +
        '</div>' +
        '</div>';
}

function startConversation(targetType, targetId) {
    closeNewMessage();

    var fd = new FormData();
    fd.append('target_type', targetType);
    fd.append('target_id', targetId);
    fd.append('body', '');
    fd.append('csrf_token', csrfToken);

    fetch('admin_conversations.php?ajax=start_conversation', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                alert(data.error);
                return;
            }
            if (data.conversation_id) {
                loadConversations();
                setTimeout(function() {
                    openConversation(data.conversation_id);
                }, 300);
            }
        })
        .catch(function() {});
}

// ── Back to list (mobile) ──
function backToList() {
    currentConvId = null;
    currentOther = null;
    document.getElementById('conv-list-panel').classList.remove('hidden');
    document.getElementById('conv-list-panel').classList.add('flex');
    document.getElementById('conv-list-panel').classList.remove('md:flex');
    document.getElementById('conv-thread-panel').classList.add('hidden');
    document.getElementById('conv-thread-panel').classList.remove('flex');
    loadConversations();
}

// ── Hide Conversation ──
function hideCurrentConversation() {
    if (!currentConvId) return;
    if (!confirm('Hide this conversation? It will reappear if a new message is received.')) return;

    var fd = new FormData();
    fd.append('conversation_id', currentConvId);
    fd.append('csrf_token', csrfToken);

    fetch('admin_conversations.php?ajax=hide_conversation', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                backToList();
            }
        })
        .catch(function() {});
}

// ── Polling ──
function pollForUpdates() {
    fetch('admin_conversations.php?ajax=list_conversations')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                conversations = data.conversations;
                renderConversationList(conversations);

                // If in a conversation, check for new messages
                if (currentConvId) {
                    var conv = conversations.find(function(c) { return c.conversation_id === currentConvId; });
                    if (conv && conv.unread_count > 0) {
                        openConversation(currentConvId);
                    }
                }
            }
        })
        .catch(function() {});
}

// ── Utility Functions ──
function getRoleColor(role) {
    var map = { admin: '#2563eb', super_admin: '#2563eb', instructor: '#059669', staff: '#7c3aed', student: '#6b7280' };
    return map[role] || '#6b7280';
}

function getInitials(name) {
    if (!name) return '?';
    var parts = name.trim().split(/\s+/);
    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function formatRelativeTime(dateStr) {
    if (!dateStr) return '';
    var date = new Date(dateStr.replace(' ', 'T'));
    var now = new Date();
    var diff = Math.floor((now - date) / 1000);

    if (diff < 60) return 'now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd';
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function formatDateHeader(dateStr) {
    if (!dateStr) return '';
    var date = new Date(dateStr.replace(' ', 'T'));
    var now = new Date();
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var msgDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    var diff = Math.floor((today - msgDay) / 86400000);

    if (diff === 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    return date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
}

function formatTime(dateStr) {
    if (!dateStr) return '';
    var date = new Date(dateStr.replace(' ', 'T'));
    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}
</script>

<?php include 'includes/footer.php'; ?>
