<?php
$theme = getActiveTheme();
$logoPath = getLogoPath();
$siteName = getSiteName();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?php echo $theme['primary']; ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo htmlspecialchars($siteName); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .active-nav {
            background-color: <?php echo $theme['sidebar_active']; ?>22;
            border-left: 3px solid <?php echo $theme['sidebar_active']; ?>;
        }
        .sidebar-custom {
            background: linear-gradient(180deg, <?php echo $theme['gradient_from']; ?> 0%, <?php echo $theme['sidebar_bg']; ?> 100%);
            display: flex;
            flex-direction: column;
        }
        .sidebar-custom a {
            color: <?php echo $theme['sidebar_text']; ?>;
        }
        .sidebar-custom a:hover {
            background-color: rgba(255,255,255,0.08);
        }
        .sidebar-custom .active-nav {
            background-color: <?php echo $theme['sidebar_active']; ?>33;
            border-left-color: <?php echo $theme['accent']; ?>;
        }
        .sidebar-custom .divider {
            border-color: rgba(255,255,255,0.12);
        }
        .topbar-accent {
            border-bottom: 2px solid <?php echo $theme['primary']; ?>;
        }
        .user-avatar {
            background-color: <?php echo $theme['primary']; ?>;
        }
        /* Sidebar scrolling */
        .sidebar-nav-scroll {
            flex: 1 1 0%;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
        }
        .sidebar-nav-scroll::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar-nav-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-nav-scroll::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15);
            border-radius: 3px;
        }
        .sidebar-nav-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.3);
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <!-- Mobile sidebar overlay -->
        <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden" onclick="closeSidebar()"></div>

        <!-- Sidebar -->
        <aside id="mobileSidebar" class="w-64 shadow-lg sidebar-custom h-full flex-col overflow-hidden
            fixed md:relative inset-y-0 left-0 z-50
            transform -translate-x-full md:translate-x-0
            transition-transform duration-300 ease-in-out
            md:flex" role="navigation" aria-label="Main navigation">
            <!-- Sidebar Header (fixed) -->
            <div class="p-6 border-b border-white/10 flex-shrink-0">
                <?php if ($logoPath): ?>
                    <div class="flex items-center gap-3">
                        <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo" class="max-h-10 max-w-[140px] object-contain">
                    </div>
                    <p class="text-xs mt-1 opacity-60" style="color: <?php echo $theme['sidebar_text']; ?>;"><?php echo htmlspecialchars($siteName); ?></p>
                <?php else: ?>
                    <h1 class="text-2xl font-bold text-white">🥋 <?php echo htmlspecialchars(substr($siteName, 0, 20)); ?></h1>
                    <p class="text-xs mt-1 opacity-60" style="color: <?php echo $theme['sidebar_text']; ?>;">Management System</p>
                <?php endif; ?>
            </div>

            <!-- Scrollable Navigation (fills remaining space) -->
            <div class="sidebar-nav-scroll">
            <nav class="p-4">
                <a href="index.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">📊</span>
                    <span>Dashboard</span>
                </a>

                <?php if (canView('students.php')): ?>
                <a href="students.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'students.php' || basename($_SERVER['PHP_SELF']) == 'student_detail.php' || basename($_SERVER['PHP_SELF']) == 'student_edit.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">👥</span>
                    <span>Students</span>
                </a>
                <?php endif; ?>

                <a href="parent_accounts.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'parent_accounts.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">👨‍👩‍👧‍👦</span>
                    <span>Parent Accounts</span>
                </a>

                <?php if (canView('memberships.php')): ?>
                <a href="memberships.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'memberships.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">📋</span>
                    <span>Memberships</span>
                </a>
                <?php endif; ?>

                <?php if (canView('discount_codes.php')): ?>
                <a href="discount_codes.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'discount_codes.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🏷️</span>
                    <span>Discount Codes</span>
                </a>
                <?php endif; ?>

                <?php if (canView('classes.php')): ?>
                <a href="classes.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'classes.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🥋</span>
                    <span>Classes</span>
                </a>
                <?php endif; ?>

                <?php if (canView('events.php')): ?>
                <a href="events.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'events.php' || basename($_SERVER['PHP_SELF']) == 'event_detail.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🎯</span>
                    <span>Events</span>
                </a>
                <?php endif; ?>

                <a href="calendar.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'calendar.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">📅</span>
                    <span>Calendar</span>
                </a>

                <?php if (canView('attendance.php')): ?>
                <a href="attendance.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">✅</span>
                    <span>Attendance</span>
                </a>
                <?php endif; ?>

                <?php if (canView('makeup_classes.php')): ?>
                <a href="makeup_classes.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'makeup_classes.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🔄</span>
                    <span>Make-Up Classes</span>
                </a>
                <?php endif; ?>

                <?php if (canView('payments.php')): ?>
                <a href="payments.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'payments.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">💰</span>
                    <span>Payments</span>
                </a>
                <?php
                // Count pending event payments for the badge (school-scoped)
                try {
                    $_ppParams = [];
                    $_ppSql = "SELECT COUNT(*) FROM event_registrations WHERE payment_status = 'pending'" . school_where();
                    school_param($_ppParams);
                    $_ppStmt = $pdo->prepare($_ppSql);
                    $_ppStmt->execute($_ppParams);
                    $_pendingPayCount = (int)$_ppStmt->fetchColumn();
                } catch (\PDOException $e) { $_pendingPayCount = 0; }
                ?>
                <a href="pending_payments.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'pending_payments.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">⏳</span>
                    <span>Pending Payments</span>
                    <?php if ($_pendingPayCount > 0): ?>
                        <span class="ml-auto bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full"><?php echo $_pendingPayCount; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if (canView('belts.php')): ?>
                <a href="belts.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'belts.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🏆</span>
                    <span>Belt System</span>
                </a>
                <?php endif; ?>

                <?php if (canView('reports.php')): ?>
                <a href="reports.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">📈</span>
                    <span>Reports</span>
                </a>
                <?php endif; ?>

                <a href="tax_statement.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'tax_statement.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">📋</span>
                    <span>Tax Statements</span>
                </a>

                <?php if (canView('messages.php')): ?>
                <a href="messages.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">💬</span>
                    <span>Messages</span>
                </a>
                <?php endif; ?>

                <div class="border-t divider my-4"></div>

                <?php if (is_super_admin()): ?>
                <a href="schools.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'schools.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🏫</span>
                    <span>Manage Schools</span>
                </a>
                <?php endif; ?>

                <?php if (in_array(getCurrentUser()['role'], ['admin', 'super_admin'])): ?>
                <a href="users.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">👤</span>
                    <span>User Management</span>
                </a>

                <a href="permissions.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'permissions.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🔐</span>
                    <span>Role Permissions</span>
                </a>

                <a href="admin_dashboard.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🎨</span>
                    <span>Studio Branding</span>
                </a>
                <?php endif; ?>

                <?php
                // Check for pending registrations (admin, super_admin, and staff only)
                if (in_array(getCurrentUser()['role'], ['admin', 'super_admin', 'staff'])):
                    $_prParams = [];
                    $_prSql = "SELECT COUNT(*) as count FROM students s
                        JOIN memberships m ON s.id = m.student_id
                        WHERE s.status = 'inactive' AND m.status = 'cancelled' AND m.payment_status = 'pending'" . school_where('s');
                    school_param($_prParams);
                    $_prStmt = $pdo->prepare($_prSql);
                    $_prStmt->execute($_prParams);
                    $pending_count = $_prStmt->fetch()['count'];
                ?>
                <a href="pending_registrations.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'pending_registrations.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">⏳</span>
                    <span>Pending Registrations</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="ml-auto text-white text-xs font-bold px-2 py-1 rounded-full" style="background-color: <?php echo $theme['accent']; ?>;">
                            <?php echo $pending_count; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if (canView('settings.php')): ?>
                <a href="settings.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">⚙️</span>
                    <span>Settings</span>
                </a>
                <?php endif; ?>

                <?php if (in_array(getCurrentUser()['role'], ['admin', 'super_admin'])): ?>
                <a href="import_data.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo in_array(basename($_SERVER['PHP_SELF']), ['import_data.php', 'import_payments.php', 'export_data.php']) ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🔄</span>
                    <span>Import / Export</span>
                </a>
                <?php endif; ?>

                <?php if (is_super_admin()): ?>
                <a href="audit_log.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'audit_log.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">📋</span>
                    <span>Audit Log</span>
                </a>
                <?php endif; ?>
            </nav>
            </div><!-- /.sidebar-nav-scroll -->

            <!-- Sidebar Footer — always visible, pinned to bottom -->
            <div class="flex-shrink-0 border-t border-white/10 p-4">
                <a href="admin_training.php" class="flex items-center px-4 py-3 mb-2 rounded-lg hover:!bg-blue-500/20 <?php echo basename($_SERVER['PHP_SELF']) == 'admin_training.php' ? 'active-nav' : ''; ?>" style="color: #93C5FD;">
                    <span class="mr-3">📖</span>
                    <span>Training Guide</span>
                </a>
                <a href="student_portal.php" class="flex items-center px-4 py-3 mb-2 rounded-lg hover:!bg-blue-500/20" target="_blank" style="color: #93C5FD;">
                    <span class="mr-3">🎓</span>
                    <span>Student Portal</span>
                </a>

                <a href="logout.php" class="flex items-center px-4 py-3 rounded-lg hover:!bg-red-500/20" style="color: #FCA5A5;">
                    <span class="mr-3">🚪</span>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Top Bar -->
            <header class="bg-white shadow-sm topbar-accent">
                <div class="flex items-center justify-between px-6 py-4">
                    <div class="flex items-center">
                        <button class="md:hidden mr-4" onclick="toggleSidebar()" aria-label="Toggle menu" aria-expanded="false" id="sidebarToggle">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                            </svg>
                        </button>
                        <h2 class="text-xl font-semibold text-gray-800"><?php echo htmlspecialchars($siteName); ?></h2>
                    </div>

                    <div class="flex items-center space-x-4">
                        <span class="text-sm text-gray-600">
                            <?php echo date('l, F j, Y'); ?>
                        </span>

                        <?php if (is_super_admin()): ?>
                        <!-- School Switcher (Super Admin) -->
                        <div class="relative" id="schoolSwitcher">
                            <button onclick="document.getElementById('schoolDropdown').classList.toggle('hidden')"
                                    class="flex items-center px-3 py-1.5 bg-yellow-100 text-yellow-800 rounded-lg text-sm font-medium hover:bg-yellow-200 border border-yellow-300">
                                <span class="mr-1.5">🏫</span>
                                <?php
                                if (is_viewing_all_schools()) {
                                    echo 'All Schools';
                                } else {
                                    $currentSchool = get_current_school();
                                    echo htmlspecialchars($currentSchool['name']);
                                }
                                ?>
                                <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div id="schoolDropdown" class="hidden absolute right-0 mt-2 w-64 bg-white rounded-lg shadow-lg border z-50">
                                <a href="?switch_school=0" class="block px-4 py-2 text-sm hover:bg-gray-100 <?php echo is_viewing_all_schools() ? 'bg-blue-50 font-bold text-blue-700' : 'text-gray-700'; ?>">
                                    🌐 All Schools
                                </a>
                                <div class="border-t"></div>
                                <?php foreach (get_all_schools() as $_school): ?>
                                    <a href="?switch_school=<?php echo $_school['id']; ?>"
                                       class="block px-4 py-2 text-sm hover:bg-gray-100 <?php echo (!is_viewing_all_schools() && current_school_id() === (int)$_school['id']) ? 'bg-blue-50 font-bold text-blue-700' : 'text-gray-700'; ?>">
                                        🏫 <?php echo htmlspecialchars($_school['name']); ?>
                                        <?php if ($_school['status'] !== 'active'): ?>
                                            <span class="text-xs text-gray-400">(inactive)</span>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <script>
                        // Close school dropdown when clicking outside
                        document.addEventListener('click', function(e) {
                            var dd = document.getElementById('schoolDropdown');
                            var sw = document.getElementById('schoolSwitcher');
                            if (dd && sw && !sw.contains(e.target)) dd.classList.add('hidden');
                        });
                        </script>
                        <?php endif; ?>

                        <div class="flex items-center space-x-2">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-white font-bold user-avatar">
                                <?php echo htmlspecialchars(strtoupper(substr(getCurrentUser()['username'], 0, 1))); ?>
                            </div>
                            <span class="text-sm font-medium text-gray-700">
                                <?php echo htmlspecialchars(getCurrentUser()['full_name']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50">
