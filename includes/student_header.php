<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .active-nav { background-color: #3B82F6; color: white; }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">🥋 <?php echo APP_NAME; ?></h1>
                <p class="text-sm text-gray-600">Welcome, <?php echo $_SESSION['student_name'] ?? 'Student'; ?></p>
            </div>
            
            <!-- Student Navigation -->
            <nav class="hidden md:flex space-x-4">
                <a href="student_portal.php" class="text-gray-700 hover:text-blue-600 <?php echo basename($_SERVER['PHP_SELF']) == 'student_portal.php' ? 'font-bold text-blue-600' : ''; ?>">
                    Dashboard
                </a>
                <a href="student_events.php" class="text-gray-700 hover:text-blue-600 <?php echo basename($_SERVER['PHP_SELF']) == 'student_events.php' ? 'font-bold text-blue-600' : ''; ?>">
                    Events
                </a>
                <a href="student_upgrade.php" class="text-gray-700 hover:text-blue-600 <?php echo basename($_SERVER['PHP_SELF']) == 'student_upgrade.php' ? 'font-bold text-blue-600' : ''; ?>">
                    Membership
                </a>
            </nav>
            
            <a href="student_logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm">
                Logout
            </a>
        </div>
        
        <!-- Mobile Navigation -->
        <nav class="md:hidden border-t border-gray-200 px-4 py-2 flex space-x-2">
            <a href="student_portal.php" class="flex-1 text-center py-2 text-sm <?php echo basename($_SERVER['PHP_SELF']) == 'student_portal.php' ? 'bg-blue-600 text-white rounded-lg' : 'text-gray-700'; ?>">
                Dashboard
            </a>
            <a href="student_events.php" class="flex-1 text-center py-2 text-sm <?php echo basename($_SERVER['PHP_SELF']) == 'student_events.php' ? 'bg-blue-600 text-white rounded-lg' : 'text-gray-700'; ?>">
                Events
            </a>
            <a href="student_upgrade.php" class="flex-1 text-center py-2 text-sm <?php echo basename($_SERVER['PHP_SELF']) == 'student_upgrade.php' ? 'bg-blue-600 text-white rounded-lg' : 'text-gray-700'; ?>">
                Membership
            </a>
        </nav>
    </header>
