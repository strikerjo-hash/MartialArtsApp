<?php
$theme = getActiveTheme();
$logoPath = getLogoPath();
$siteName = getSiteName();

// Build hover color
$primaryHover = $theme['primary_hover'] ?? '';
if (!$primaryHover) {
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

$parentDisplayName = (isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '') : 'Parent');

// Fetch children for the navigation dropdown
$_navChildren = [];
$_effectiveParentId = get_effective_parent_id();
if ($_effectiveParentId > 0) {
    try {
        $_navChildren = get_parent_children($_effectiveParentId);
    } catch (\Exception $e) {}
}

// Determine if we're on a child-related page
$_isChildPage = in_array(basename($_SERVER['PHP_SELF']), [
    'parent_child.php', 'parent_child_training.php', 'parent_child_membership.php', 'parent_child_membership_payment.php'
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($siteName); ?> - Family Portal</title>
    <link rel="stylesheet" href="assets/css/tailwind.css">
    <style>
        .active-nav { background-color: <?php echo $theme['primary']; ?>; color: white; }
        .nav-link { color: <?php echo $theme['primary']; ?>; }
        .nav-link:hover { color: <?php echo $primaryHover; ?>; }
        .nav-link-active { font-weight: 700; color: <?php echo $theme['primary']; ?>; }
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
                    <p class="text-xs sm:text-sm text-gray-600 truncate">Family Portal &mdash; <?php echo htmlspecialchars($parentDisplayName); ?></p>
                </div>
            </div>

            <nav class="hidden md:flex space-x-4 items-center">
                <a href="parent_portal.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'parent_portal.php' ? 'nav-link-active' : ''; ?>">
                    Dashboard
                </a>
                <?php if (!empty($_navChildren)): ?>
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
                        </div>
                    </div>
                <?php endif; ?>
                <a href="parent_events.php" class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['parent_events.php', 'parent_event_payment.php']) ? 'nav-link-active' : ''; ?>">
                    Events
                </a>
                <a href="parent_payment.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'parent_payment.php' ? 'nav-link-active' : ''; ?>">
                    Payment
                </a>
                <a href="parent_transactions.php" class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['parent_transactions.php', 'parent_tax_statement.php']) ? 'nav-link-active' : ''; ?>">
                    Transactions
                </a>
                <a href="parent_profile.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'parent_profile.php' ? 'nav-link-active' : ''; ?>">
                    Profile
                </a>
                <a href="parent_training_wizard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'parent_training_wizard.php' ? 'nav-link-active' : ''; ?>" title="Portal Guide">
                    Guide
                </a>
            </nav>

            <?php if (!empty($_SESSION['_impersonating'])): ?>
            <a href="admin_impersonate.php?stop=1" class="flex-shrink-0 text-white px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm transition-colors whitespace-nowrap bg-red-600 hover:bg-red-700">
                &larr; Return to Admin
            </a>
            <?php else: ?>
            <a href="parent_logout.php" class="flex-shrink-0 text-white px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm transition-colors whitespace-nowrap" style="background-color: <?php echo $theme['primary']; ?>;" onmouseover="this.style.backgroundColor='<?php echo $primaryHover; ?>'" onmouseout="this.style.backgroundColor='<?php echo $theme['primary']; ?>'">
                Logout
            </a>
            <?php endif; ?>
        </div>

        <!-- Mobile Navigation -->
        <nav class="md:hidden border-t border-gray-200 px-4 py-2">
            <div class="flex space-x-1 overflow-x-auto -mx-4 px-4 mb-1" style="-webkit-overflow-scrolling: touch;">
                <a href="parent_portal.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'parent_portal.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                    Dashboard
                </a>
                <a href="parent_events.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo in_array(basename($_SERVER['PHP_SELF']), ['parent_events.php', 'parent_event_payment.php']) ? 'mobile-active' : 'text-gray-700'; ?>">
                    Events
                </a>
                <a href="parent_payment.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'parent_payment.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                    Payment
                </a>
                <a href="parent_transactions.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo in_array(basename($_SERVER['PHP_SELF']), ['parent_transactions.php', 'parent_tax_statement.php']) ? 'mobile-active' : 'text-gray-700'; ?>">
                    Transactions
                </a>
                <a href="parent_profile.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'parent_profile.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                    Profile
                </a>
                <a href="parent_training_wizard.php" class="flex-shrink-0 text-center py-2 px-2.5 text-xs rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'parent_training_wizard.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                    Guide
                </a>
            </div>
            <?php if (!empty($_navChildren)): ?>
                <div class="border-t border-gray-100 pt-1 flex space-x-2 overflow-x-auto">
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
        &#128065; Viewing as <strong><?php echo htmlspecialchars($_SESSION['_impersonating_name'] ?? 'Parent'); ?></strong>
        <span style="margin-left:8px;font-weight:400;opacity:.9;">(Admin Preview Mode)</span>
        <a href="admin_impersonate.php?stop=1"
           style="margin-left:16px;background:#fff;color:#dc2626;padding:5px 18px;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;display:inline-block;">
            &larr; Return to Admin
        </a>
    </div>
<?php endif; ?>
