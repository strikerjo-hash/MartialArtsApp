<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .active-nav {
            background-color: rgba(59, 130, 246, 0.1);
            border-left: 3px solid #3B82F6;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <aside class="w-64 bg-white shadow-lg hidden md:block">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-800">🥋 MAS</h1>
                <p class="text-xs text-gray-600 mt-1">Martial Arts Studio</p>
            </div>
            
            <nav class="p-4">
                <a href="index.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">📊</span>
                    <span>Dashboard</span>
                </a>
                
                <?php if (canView('students.php')): ?>
                <a href="students.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'students.php' || basename($_SERVER['PHP_SELF']) == 'student_detail.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">👥</span>
                    <span>Students</span>
                </a>
                <?php endif; ?>
                
                <?php if (canView('memberships.php')): ?>
                <a href="memberships.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'memberships.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">📋</span>
                    <span>Memberships</span>
                </a>
                <?php endif; ?>
                
                <?php if (canView('classes.php')): ?>
                <a href="classes.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'classes.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🥋</span>
                    <span>Classes</span>
                </a>
                <?php endif; ?>
                
                <?php if (canView('events.php')): ?>
                <a href="events.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'events.php' || basename($_SERVER['PHP_SELF']) == 'event_detail.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🎯</span>
                    <span>Events</span>
                </a>
                <?php endif; ?>
                
                <?php if (canView('attendance.php')): ?>
                <a href="attendance.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">✅</span>
                    <span>Attendance</span>
                </a>
                <?php endif; ?>
                
                <?php if (canView('payments.php')): ?>
                <a href="payments.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'payments.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">💰</span>
                    <span>Payments</span>
                </a>
                <?php endif; ?>
                
                <?php if (canView('belts.php')): ?>
                <a href="belts.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'belts.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">🏆</span>
                    <span>Belt System</span>
                </a>
                <?php endif; ?>
                
                <?php if (canView('reports.php')): ?>
                <a href="reports.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">📈</span>
                    <span>Reports</span>
                </a>
                <?php endif; ?>
                
                <div class="border-t border-gray-200 my-4"></div>
                
                <?php if (getCurrentUser()['role'] === 'admin'): ?>
                <a href="users.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">👤</span>
                    <span>User Management</span>
                </a>
                
                <a href="permissions.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'permissions.php' ? 'active-nav' : ''; ?>">
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
                <a href="pending_registrations.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'pending_registrations.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">⏳</span>
                    <span>Pending Registrations</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="ml-auto bg-orange-500 text-white text-xs font-bold px-2 py-1 rounded-full">
                            <?php echo $pending_count; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                
                <?php if (canView('settings.php')): ?>
                <a href="settings.php" class="flex items-center px-4 py-3 mb-2 text-gray-700 rounded-lg hover:bg-gray-100 <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active-nav' : ''; ?>">
                    <span class="mr-3">⚙️</span>
                    <span>Settings</span>
                </a>
                <?php endif; ?>
                
                <a href="student_login.php" class="flex items-center px-4 py-3 mb-2 text-blue-700 rounded-lg hover:bg-blue-50" target="_blank">
                    <span class="mr-3">🎓</span>
                    <span>Student Portal</span>
                </a>
                
                <a href="logout.php" class="flex items-center px-4 py-3 text-red-600 rounded-lg hover:bg-red-50">
                    <span class="mr-3">🚪</span>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Top Bar -->
            <header class="bg-white shadow-sm">
                <div class="flex items-center justify-between px-6 py-4">
                    <div class="flex items-center">
                        <button class="md:hidden mr-4" onclick="toggleSidebar()">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                            </svg>
                        </button>
                        <h2 class="text-xl font-semibold text-gray-800"><?php echo APP_NAME; ?></h2>
                    </div>
                    
                    <div class="flex items-center space-x-4">
                        <span class="text-sm text-gray-600">
                            <?php echo date('l, F j, Y'); ?>
                        </span>
                        <div class="flex items-center space-x-2">
                            <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold">
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
