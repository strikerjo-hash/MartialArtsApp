<?php
/**
 * error.php — Styled Error Page
 *
 * STANDALONE page — does NOT include header.php or footer.php because
 * those may themselves be the source of the error (e.g., DB down).
 *
 * Displays:
 *   - Friendly error message for all users
 *   - Error reference ID for admin correlation
 *   - Go Back / Dashboard buttons
 *   - Admin-only: technical details (message, file, line, trace)
 *
 * Error details come from $_SESSION['last_error'] (set by error_handler.php).
 */

// Safely start session to read error details
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Grab error details from session
$errorData = $_SESSION['last_error'] ?? null;
$refId     = $_GET['ref'] ?? ($errorData['reference_id'] ?? '');
$isAdmin   = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';

// Determine the dashboard URL based on user type
$userType    = $_SESSION['user_type'] ?? '';
$dashboardUrl = 'index.php';
if ($userType === 'student') {
    $dashboardUrl = 'student_portal.php';
} elseif ($userType === 'parent') {
    $dashboardUrl = 'parent_portal.php';
}

// Clear the error from session after reading (one-time display)
unset($_SESSION['last_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error — Something Went Wrong</title>
    <link rel="stylesheet" href="assets/css/tailwind.css">
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
        }
        .error-trace {
            max-height: 300px;
            overflow-y: auto;
        }
        .error-trace::-webkit-scrollbar { width: 4px; }
        .error-trace::-webkit-scrollbar-track { background: transparent; }
        .error-trace::-webkit-scrollbar-thumb { background: #fca5a5; border-radius: 2px; }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">

    <div class="bg-white rounded-xl shadow-lg max-w-lg w-full overflow-hidden">
        <!-- Header -->
        <div class="bg-red-600 px-6 py-8 text-center">
            <div class="text-5xl mb-3">&#9888;&#65039;</div>
            <h1 class="text-2xl font-bold text-white">Something Went Wrong</h1>
            <p class="text-red-200 text-sm mt-2">An unexpected error occurred while processing your request.</p>
        </div>

        <!-- Body -->
        <div class="px-6 py-8">
            <p class="text-gray-600 text-center mb-4">
                We're sorry for the inconvenience. Please try again or contact the administrator if the problem persists.
            </p>

            <?php if ($refId): ?>
            <div class="text-center mb-6">
                <p class="text-xs text-gray-400 mb-1">Error Reference</p>
                <code class="inline-block bg-gray-100 text-gray-700 px-3 py-1.5 rounded-lg text-sm font-mono font-medium">
                    <?php echo htmlspecialchars($refId); ?>
                </code>
                <p class="text-xs text-gray-400 mt-2">Provide this code when contacting support.</p>
            </div>
            <?php endif; ?>

            <?php if ($errorData && isset($errorData['time'])): ?>
            <div class="text-center mb-6">
                <p class="text-xs text-gray-400">
                    Occurred at: <?php echo htmlspecialchars($errorData['time']); ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Admin-only technical details -->
            <?php if ($isAdmin && $errorData): ?>
            <details class="mb-6 bg-red-50 border border-red-200 rounded-lg overflow-hidden">
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-red-800 hover:bg-red-100 transition">
                    &#128736; Technical Details (Admin Only)
                </summary>
                <div class="px-4 py-3 border-t border-red-200">
                    <div class="space-y-3">
                        <div>
                            <span class="text-xs font-semibold text-red-700 uppercase tracking-wide">Exception</span>
                            <p class="text-sm text-red-900 font-mono mt-0.5">
                                <?php echo htmlspecialchars($errorData['class'] ?? 'Unknown'); ?>
                            </p>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-red-700 uppercase tracking-wide">Message</span>
                            <p class="text-sm text-red-900 font-mono mt-0.5 break-all">
                                <?php echo htmlspecialchars($errorData['message'] ?? 'No message'); ?>
                            </p>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-red-700 uppercase tracking-wide">Location</span>
                            <p class="text-sm text-red-900 font-mono mt-0.5">
                                <?php echo htmlspecialchars(($errorData['file'] ?? '?') . ':' . ($errorData['line'] ?? '?')); ?>
                            </p>
                        </div>
                        <?php if (!empty($errorData['trace'])): ?>
                        <div>
                            <span class="text-xs font-semibold text-red-700 uppercase tracking-wide">Stack Trace</span>
                            <pre class="error-trace text-xs text-red-800 font-mono mt-1 p-2 bg-red-100 rounded whitespace-pre-wrap break-all"><?php echo htmlspecialchars($errorData['trace']); ?></pre>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </details>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="flex justify-center gap-3">
                <a href="javascript:history.back()"
                   class="inline-flex items-center px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-sm font-medium transition">
                    &larr; Go Back
                </a>
                <a href="<?php echo htmlspecialchars($dashboardUrl); ?>"
                   class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                    Dashboard
                </a>
            </div>
        </div>

        <!-- Footer -->
        <div class="bg-gray-50 px-6 py-3 text-center border-t border-gray-200">
            <p class="text-xs text-gray-400">
                If this error persists, please contact your school administrator.
            </p>
        </div>
    </div>

</body>
</html>
