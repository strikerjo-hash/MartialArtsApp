<?php
$theme = getActiveTheme();
$logoPath = getLogoPath();
$siteName = getSiteName();

// Build a hover color by darkening the primary — safe fallback if primary_hover isn't set
$primaryHover = $theme['primary_hover'] ?? '';
if (!$primaryHover) {
    // Darken primary by ~15%
    $hex = ltrim($theme['primary'], '#');
    if (strlen($hex) === 6) {
        $r = max(0, hexdec(substr($hex, 0, 2)) - 30);
        $g = max(0, hexdec(substr($hex, 2, 2)) - 30);
        $b = max(0, hexdec(substr($hex, 4, 2)) - 30);
        $primaryHover = sprintf('#%02x%02x%02x', $r, $g, $b);
    } else {
        $primaryHover = $theme['primary'];
    }
}

// Student display name — works with both session formats
$studentDisplayName = $_SESSION['student_name']
    ?? (isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '') : 'Student');

// Unread message counts for nav badge (separate broadcast + conversations)
require_once __DIR__ . '/messaging.php';
require_once __DIR__ . '/conversation_helpers.php';
$_broadcastUnreadCount = 0;
$_convUnreadCount = 0;
$_studentUnreadCount = 0;
if (!empty($_SESSION['student_id'])) {
    $_broadcastUnreadCount = get_unread_message_count((int)$_SESSION['student_id']);
    $_convUnreadCount = get_unread_conversation_count(
        (int)($_SESSION['school_id'] ?? 1), 'student', (int)$_SESSION['student_id']
    );
    $_studentUnreadCount = $_broadcastUnreadCount + $_convUnreadCount;
}

// Payment lockout state for nav rendering
$_isPaymentLocked = is_student_payment_locked();

// Check if student is a parent (has is_parent=1)
$_isStudentParent = !empty($_SESSION['is_parent']);

// Fetch children for navigation if this student is a parent
$_navChildren = [];
if ($_isStudentParent && !empty($_SESSION['student_id'])) {
    try {
        require_once __DIR__ . '/parent_auth.php';
        $_navChildren = get_parent_children((int)$_SESSION['student_id']);
    } catch (\Throwable $e) {}
}

// Check if student is linked to a parent account (as a child)
$_hasParentAccount = false;
if (!$_isStudentParent) {
    $_parentCacheTime = $_SESSION['parent_link_checked_at'] ?? 0;
    if ((time() - $_parentCacheTime) > 300) {
        try {
            require_once __DIR__ . '/parent_auth.php';
            $_SESSION['has_parent_account'] = !empty(get_student_parents((int)($_SESSION['student_id'] ?? 0)));
            $_SESSION['parent_link_checked_at'] = time();
        } catch (\Throwable $e) {
            $_SESSION['has_parent_account'] = false;
            $_SESSION['parent_link_checked_at'] = time();
        }
    }
    $_hasParentAccount = !empty($_SESSION['has_parent_account']);
}

// Determine if we're on a child management page
$_isChildPage = in_array(basename($_SERVER['PHP_SELF']), [
    'parent_child.php', 'parent_child_training.php', 'parent_child_membership.php', 'parent_portal.php'
]);

// Get the student's school name for display
$_studentSchoolName = '';
try {
    $_schoolRow = get_current_school();
    $_studentSchoolName = $_schoolRow['name'] ?? '';
} catch (\Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?php echo $theme['primary']; ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo htmlspecialchars($siteName); ?> - Student Portal</title>
    <link rel="stylesheet" href="assets/css/tailwind.css">
    <style>
        .active-nav { background-color: <?php echo $theme['primary']; ?>; color: white; }
        .student-header {
            background: linear-gradient(135deg, <?php echo $theme['gradient_from']; ?> 0%, <?php echo $theme['primary']; ?> 100%);
        }
        .nav-link {
            color: <?php echo $theme['primary']; ?>;
        }
        .nav-link:hover {
            color: <?php echo $primaryHover; ?>;
        }
        .nav-link-active {
            font-weight: 700;
            color: <?php echo $theme['primary']; ?>;
        }
        .mobile-active {
            background-color: <?php echo $theme['primary']; ?>;
            color: white;
            border-radius: 0.5rem;
        }
        /* Children dropdown */
        .children-dropdown { position: relative; display: inline-block; }
        .children-dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            min-width: 220px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            z-index: 50;
            padding: 0.5rem 0;
            margin-top: 0.25rem;
        }
        .children-dropdown:hover .children-dropdown-menu { display: block; }
        .children-dropdown-menu a {
            display: block;
            padding: 0.5rem 1rem;
            color: #374151;
            font-size: 0.875rem;
            text-decoration: none;
            transition: background-color 0.15s;
        }
        .children-dropdown-menu a:hover { background-color: #f3f4f6; }
        .children-dropdown-menu .child-name { font-weight: 600; color: #1f2937; }
        .children-dropdown-menu .child-links {
            display: flex;
            gap: 0.5rem;
            padding: 0.25rem 1rem 0.5rem;
            font-size: 0.75rem;
        }
        .children-dropdown-menu .child-links a {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            display: inline-block;
        }
        .children-dropdown-menu .child-divider {
            border-top: 1px solid #e5e7eb;
            margin: 0.25rem 0;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm" style="border-bottom: 3px solid <?php echo $theme['primary']; ?>;">
        <div class="container mx-auto px-4 py-3 flex justify-between items-center gap-2 min-w-0">
            <div class="flex items-center gap-2 min-w-0 flex-1">
                <?php if ($logoPath): ?>
                    <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo" class="max-h-9 object-contain flex-shrink-0">
                <?php else: ?>
                    <span class="text-2xl flex-shrink-0">🥋</span>
                <?php endif; ?>
                <div class="min-w-0">
                    <h1 class="text-base sm:text-xl font-bold text-gray-800 truncate"><?php echo htmlspecialchars($siteName); ?></h1>
                    <?php if (!empty($_studentSchoolName)): ?>
                        <p class="text-xs text-gray-500" style="margin-top: -1px;"><?php echo htmlspecialchars($_studentSchoolName); ?></p>
                    <?php endif; ?>
                    <p class="text-sm text-gray-600">Welcome, <?php echo htmlspecialchars($studentDisplayName); ?>
                        <?php if ($_isStudentParent): ?>
                            <span class="inline-block px-2 py-0.5 text-xs font-semibold bg-blue-100 text-blue-700 rounded-full ml-1">Parent Account</span>
                        <?php elseif ($_hasParentAccount): ?>
                            <span class="inline-block px-2 py-0.5 text-xs font-semibold bg-blue-100 text-blue-700 rounded-full ml-1">Family Account</span>
                        <?php endif; ?>
                        <?php if ($_isPaymentLocked): ?>
                            <span class="inline-block px-2 py-0.5 text-xs font-semibold bg-red-100 text-red-700 rounded-full ml-1">Payment Issue</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Student Navigation -->
            <nav class="hidden md:flex space-x-4 items-center">
                <?php if ($_isPaymentLocked): ?>
                    <span class="text-gray-400 cursor-not-allowed text-sm py-1" title="Access restricted — please update your payment method">Dashboard</span>
                <?php else: ?>
                <a href="student_portal.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_portal.php' ? 'nav-link-active' : ''; ?>">
                    Dashboard
                </a>
                <?php endif; ?>
                <?php if ($_isStudentParent && !empty($_navChildren)): ?>
                    <div class="children-dropdown">
                        <a href="#" class="nav-link <?php echo $_isChildPage ? 'nav-link-active' : ''; ?>" onclick="return false;">
                            Children &#9662;
                        </a>
                        <div class="children-dropdown-menu">
                            <?php foreach ($_navChildren as $i => $_nc): ?>
                                <?php if ($i > 0): ?><div class="child-divider"></div><?php endif; ?>
                                <a href="parent_child.php?id=<?php echo $_nc['id']; ?>" class="child-name">
                                    <?php echo htmlspecialchars($_nc['first_name'] . ' ' . $_nc['last_name']); ?>
                                </a>
                                <div class="child-links">
                                    <a href="parent_child.php?id=<?php echo $_nc['id']; ?>" class="text-blue-600 hover:bg-blue-50">Overview</a>
                                    <a href="parent_child_training.php?id=<?php echo $_nc['id']; ?>" class="text-purple-600 hover:bg-purple-50">Training</a>
                                    <a href="parent_child_membership.php?id=<?php echo $_nc['id']; ?>" class="text-green-600 hover:bg-green-50">Plan</a>
                                </div>
                            <?php endforeach; ?>
                            <div class="child-divider"></div>
                            <a href="parent_portal.php" class="text-blue-600 font-medium">&#128101; Manage All Children</a>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($_isPaymentLocked): ?>
                    <span class="text-gray-400 cursor-not-allowed text-sm py-1" title="Access restricted — please update your payment method">Events</span>
                <?php else: ?>
                <a href="student_events.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_events.php' ? 'nav-link-active' : ''; ?>">
                    Events
                </a>
                <?php endif; ?>
                <?php if ($_isPaymentLocked): ?>
                    <span class="text-gray-400 cursor-not-allowed text-sm py-1" title="Access restricted — please update your payment method">Membership</span>
                <?php else: ?>
                <a href="student_upgrade.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_upgrade.php' ? 'nav-link-active' : ''; ?>">
                    Membership
                </a>
                <?php endif; ?>
                <?php if ($_isPaymentLocked): ?>
                    <span class="text-gray-400 cursor-not-allowed text-sm py-1" title="Access restricted — please update your payment method">Training</span>
                <?php else: ?>
                <a href="student_training.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_training.php' ? 'nav-link-active' : ''; ?>">
                    Training
                </a>
                <?php endif; ?>
                <a href="student_payment.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_payment.php' ? 'nav-link-active' : ''; ?>">
                    Payment
                </a>
                <a href="student_transactions.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_transactions.php' ? 'nav-link-active' : ''; ?>">
                    Transactions
                </a>
                <?php if ($_isPaymentLocked): ?>
                    <span class="text-gray-400 cursor-not-allowed text-sm py-1" title="Access restricted — please update your payment method">Messages</span>
                <?php else: ?>
                <a href="student_messages.php" class="nav-link relative <?php echo in_array(basename($_SERVER['PHP_SELF']), ['student_messages.php', 'student_conversations.php']) ? 'nav-link-active' : ''; ?>">
                    Messages
                    <?php if ($_studentUnreadCount > 0): ?>
                        <span class="msg-unread-badge absolute -top-1 -right-2 inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-red-500 rounded-full"><?php echo $_studentUnreadCount; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="student_training_wizard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_training_wizard.php' ? 'nav-link-active' : ''; ?>" title="Portal Guide">
                    Guide
                </a>
            </nav>

            <!-- Notification Icons -->
            <div class="flex items-center space-x-1 flex-shrink-0">
                <?php if (!$_isPaymentLocked): ?>
                <a href="student_messages.php" class="relative p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-full transition-colors" title="Broadcast Messages">
                    <span class="text-base leading-none">💬</span>
                    <?php if ($_broadcastUnreadCount > 0): ?>
                        <span class="absolute top-0 right-0 inline-flex items-center justify-center min-w-[16px] h-[16px] px-0.5 text-[9px] font-bold text-white bg-red-500 rounded-full transform translate-x-1 -translate-y-1"><?= min($_broadcastUnreadCount, 99) ?></span>
                    <?php endif; ?>
                </a>
                <a href="student_conversations.php" class="relative p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-full transition-colors" title="Conversations">
                    <span class="text-base leading-none">✉️</span>
                    <?php if ($_convUnreadCount > 0): ?>
                        <span class="absolute top-0 right-0 inline-flex items-center justify-center min-w-[16px] h-[16px] px-0.5 text-[9px] font-bold text-white bg-red-500 rounded-full transform translate-x-1 -translate-y-1"><?= min($_convUnreadCount, 99) ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($_SESSION['_impersonating'])): ?>
            <a href="admin_impersonate.php?stop=1" class="flex-shrink-0 text-white px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm transition-colors whitespace-nowrap bg-red-600 hover:bg-red-700">
                &larr; Return to Admin
            </a>
            <?php else: ?>
            <a href="student_logout.php" class="flex-shrink-0 text-white px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm transition-colors whitespace-nowrap" style="background-color: <?php echo $theme['primary']; ?>;" onmouseover="this.style.backgroundColor='<?php echo $primaryHover; ?>'" onmouseout="this.style.backgroundColor='<?php echo $theme['primary']; ?>'">
                Logout
            </a>
            <?php endif; ?>
        </div>

        <!-- Mobile Navigation -->
        <nav class="md:hidden border-t border-gray-200 px-4 py-2">
            <div class="flex space-x-1 overflow-x-auto -mx-4 px-4" style="-webkit-overflow-scrolling: touch;">
                <?php if ($_isPaymentLocked): ?>
                    <span class="flex-shrink-0 text-center py-2 px-2.5 text-xs text-gray-400 cursor-not-allowed rounded-lg">Dashboard</span>
                <?php else: ?>
                <a href="student_portal.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'student_portal.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                    Dashboard
                </a>
                <?php endif; ?>
                <?php if ($_isPaymentLocked): ?>
                    <span class="flex-shrink-0 text-center py-2 px-2.5 text-xs text-gray-400 cursor-not-allowed rounded-lg">Events</span>
                <?php else: ?>
                <a href="student_events.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'student_events.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                    Events
                </a>
                <?php endif; ?>
                <?php if ($_isPaymentLocked): ?>
                    <span class="flex-shrink-0 text-center py-2 px-2.5 text-xs text-gray-400 cursor-not-allowed rounded-lg">Membership</span>
                <?php else: ?>
                <a href="student_upgrade.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'student_upgrade.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                    Membership
                </a>
                <?php endif; ?>
                <?php if ($_isPaymentLocked): ?>
                    <span class="flex-shrink-0 text-center py-2 px-2.5 text-xs text-gray-400 cursor-not-allowed rounded-lg">Training</span>
                <?php else: ?>
                <a href="student_training.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'student_training.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                    Training
                </a>
                <?php endif; ?>
                <a href="student_payment.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'student_payment.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                    Payment
                </a>
                <a href="student_transactions.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'student_transactions.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                    Transactions
                </a>
                <?php if ($_isPaymentLocked): ?>
                    <span class="flex-shrink-0 text-center py-2 px-2.5 text-xs text-gray-400 cursor-not-allowed rounded-lg">Messages</span>
                <?php else: ?>
                <a href="student_messages.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg relative <?php echo in_array(basename($_SERVER['PHP_SELF']), ['student_messages.php', 'student_conversations.php']) ? 'mobile-active' : 'text-gray-700'; ?>">
                    Messages
                    <?php if ($_studentUnreadCount > 0): ?>
                        <span class="msg-unread-badge absolute -top-1 -right-0 inline-flex items-center justify-center w-4 h-4 text-[10px] font-bold text-white bg-red-500 rounded-full"><?php echo $_studentUnreadCount; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="student_training_wizard.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'student_training_wizard.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                    Guide
                </a>
            </div>
            <?php if ($_isStudentParent && !empty($_navChildren)): ?>
                <div class="border-t border-gray-100 mt-1 pt-1 flex space-x-2 overflow-x-auto">
                    <?php foreach ($_navChildren as $_nc): ?>
                        <a href="parent_child.php?id=<?php echo $_nc['id']; ?>"
                           class="flex-shrink-0 text-center py-1.5 px-3 text-xs rounded-full <?php echo ($_isChildPage && isset($_GET['id']) && (int)$_GET['id'] === (int)$_nc['id']) ? 'mobile-active' : 'bg-gray-100 text-gray-700'; ?>">
                            <?php echo htmlspecialchars($_nc['first_name']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </nav>
    </header>
<?php if (!empty($_SESSION['_impersonating'])): ?>
    <div style="background:linear-gradient(90deg,#dc2626,#b91c1c);color:#fff;padding:10px 16px;text-align:center;font-size:14px;font-weight:600;position:sticky;top:0;z-index:9999;box-shadow:0 2px 8px rgba(0,0,0,.2);">
        &#128065; Viewing as <strong><?php echo htmlspecialchars($_SESSION['_impersonating_name'] ?? 'Student'); ?></strong>
        <span style="margin-left:8px;font-weight:400;opacity:.9;">(Admin Preview Mode)</span>
        <a href="admin_impersonate.php?stop=1"
           style="margin-left:16px;background:#fff;color:#dc2626;padding:5px 18px;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;display:inline-block;">
            &larr; Return to Admin
        </a>
    </div>
<?php endif; ?>
