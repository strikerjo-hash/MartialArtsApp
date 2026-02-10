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
    <title><?php echo htmlspecialchars($siteName); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .active-nav {
            background-color: <?php echo $theme['sidebar_active']; ?>22;
            border-left: 3px solid <?php echo $theme['sidebar_active']; ?>;
        }
        .sidebar-custom {
            background: linear-gradient(180deg, <?php echo $theme['gradient_from']; ?> 0%, <?php echo $theme['sidebar_bg']; ?> 100%);
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
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <aside class="w-64 shadow-lg hidden md:block sidebar-custom">
            <div class="p-6 border-b border-white/10">
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

            <nav class="p-4">
                <a href="index.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">📊</span>
                    <span>Dashboard</span>
                </a>

                <?php if (canView('students.php')): ?>
                <a href="students.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'students.php' || basename($_SERVER['PHP_SELF']) == 'student_detail.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">👥</span>
                    <span>Students</span>
                </a>
                <?php endif; ?>

                <?php if (canView('memberships.php')): ?>
                <a href="memberships.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'memberships.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">📋</span>
                    <span>Memberships</span>
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

                <?php if (canView('attendance.php')): ?>
                <a href="attendance.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">✅</span>
                    <span>Attendance</span>
                </a>
                <?php endif; ?>

                <?php if (canView('payments.php')): ?>
                <a href="payments.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'payments.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">💰</span>
                    <span>Payments</span>
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

                <div class="border-t divider my-4"></div>

                <?php if (getCurrentUser()['role'] === 'admin'): ?>
                <a href="users.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">👤</span>
                    <span>User Management</span>
                </a>

                <a href="permissions.php" class="flex items-center px-4 py-3 mb-2 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'permissions.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🔐</span>
                    <span>Role Permissions</span>
                </a>
                <?php endif; ?>

                <?php
                // Check for pending registrations (admin and staff only)
                if (in_array(getCurrentUser()['role'], ['admin', 'staff'])):
                    $pending_count = $pdo->query("
                        SELECT COUNT(*) as count FROM students s
                        JOIN memberships m ON s.id = m.student_id
                        WHERE s.status = 'inactive' AND m.status = 'cancelled' AND m.payment_status = 'pending'
                    ")->fetch()['count'];
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

                <a href="login.php?type=student" class="flex items-center px-4 py-3 mb-2 rounded-lg hover:!bg-blue-500/20" target="_blank" style="color: #93C5FD;">
                    <span class="mr-3">🎓</span>
                    <span>Student Portal</span>
                </a>

                <a href="logout.php" class="flex items-center px-4 py-3 rounded-lg hover:!bg-red-500/20" style="color: #FCA5A5;">
                    <span class="mr-3">🚪</span>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Top Bar -->
            <header class="bg-white shadow-sm topbar-accent">
                <div class="flex items-center justify-between px-6 py-4">
                    <div class="flex items-center">
                        <button class="md:hidden mr-4" onclick="toggleSidebar()">
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
                        <div class="flex items-center space-x-2">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-white font-bold user-avatar">
                                <?php echo strtoupper(substr(getCurrentUser()['username'], 0, 1)); ?>
                            </div>
                            <span class="text-sm font-medium text-gray-700">
                                <?php echo getCurrentUser()['full_name']; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50">
