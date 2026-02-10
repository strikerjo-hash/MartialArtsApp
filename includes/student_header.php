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
    <title><?php echo htmlspecialchars($siteName); ?> - Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .active-nav { background-color: <?php echo $theme['primary']; ?>; color: white; }
        .student-header {
            background: linear-gradient(135deg, <?php echo $theme['gradient_from']; ?> 0%, <?php echo $theme['primary']; ?> 100%);
        }
        .nav-link {
            color: <?php echo $theme['primary']; ?>;
        }
        .nav-link:hover {
            color: <?php echo $theme['primary_hover']; ?>;
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
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm" style="border-bottom: 3px solid <?php echo $theme['primary']; ?>;">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <?php if ($logoPath): ?>
                    <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo" class="max-h-9 object-contain">
                <?php else: ?>
                    <span class="text-2xl">🥋</span>
                <?php endif; ?>
                <div>
                    <h1 class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($siteName); ?></h1>
                    <p class="text-sm text-gray-600">Welcome, <?php echo $_SESSION['student_name'] ?? 'Student'; ?></p>
                </div>
            </div>

            <!-- Student Navigation -->
            <nav class="hidden md:flex space-x-4">
                <a href="student_portal.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_portal.php' ? 'nav-link-active' : ''; ?>">
                    Dashboard
                </a>
                <a href="student_events.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_events.php' ? 'nav-link-active' : ''; ?>">
                    Events
                </a>
                <a href="student_upgrade.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_upgrade.php' ? 'nav-link-active' : ''; ?>">
                    Membership
                </a>
            </nav>

            <a href="student_logout.php" class="text-white px-4 py-2 rounded-lg text-sm transition-colors" style="background-color: <?php echo $theme['primary']; ?>;" onmouseover="this.style.backgroundColor='<?php echo $theme['primary_hover']; ?>'" onmouseout="this.style.backgroundColor='<?php echo $theme['primary']; ?>'">
                Logout
            </a>
        </div>

        <!-- Mobile Navigation -->
        <nav class="md:hidden border-t border-gray-200 px-4 py-2 flex space-x-2">
            <a href="student_portal.php" class="flex-1 text-center py-2 text-sm <?php echo basename($_SERVER['PHP_SELF']) == 'student_portal.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                Dashboard
            </a>
            <a href="student_events.php" class="flex-1 text-center py-2 text-sm <?php echo basename($_SERVER['PHP_SELF']) == 'student_events.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                Events
            </a>
            <a href="student_upgrade.php" class="flex-1 text-center py-2 text-sm <?php echo basename($_SERVER['PHP_SELF']) == 'student_upgrade.php' ? 'mobile-active' : 'text-gray-700'; ?>">
                Membership
            </a>
        </nav>
    </header>
