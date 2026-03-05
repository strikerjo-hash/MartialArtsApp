<?php
/**
 * includes/error_handler.php — Global Error & Exception Handlers
 *
 * Sets up:
 *   set_error_handler()        — Converts PHP warnings/notices to logged events
 *   set_exception_handler()    — Catches uncaught exceptions, logs & redirects to error.php
 *   register_shutdown_function — Catches fatal errors that bypass the exception handler
 *
 * Loaded by config.php after audit.php so app_log() is available.
 */

// ─── Recursion guard ─────────────────────────────────────────────────
// Prevents infinite loops if the error handler itself triggers an error
// (e.g., database connection down while trying to log).

$_error_handler_active = false;

/**
 * Generate a unique error reference ID for user-to-admin correlation.
 * Format: ERR-YYYYMMDD-xxxxxx
 */
function _generate_error_reference(): string
{
    return 'ERR-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

// ─── Custom Error Handler ────────────────────────────────────────────
// Converts PHP errors (warnings, notices, deprecations) into log entries.
// Fatal errors (E_ERROR, E_PARSE, etc.) are NOT catchable here — they
// are handled by the shutdown function.

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    global $_error_handler_active;

    // Respect the error_reporting level (@ operator sets it to 0)
    if (!(error_reporting() & $errno)) {
        return false;
    }

    // Prevent recursion
    if ($_error_handler_active) {
        return false;
    }
    $_error_handler_active = true;

    // Map error types to log levels
    $levelMap = [
        E_WARNING         => 'warning',
        E_NOTICE          => 'info',
        E_DEPRECATED      => 'info',
        E_USER_ERROR      => 'error',
        E_USER_WARNING    => 'warning',
        E_USER_NOTICE     => 'info',
        E_USER_DEPRECATED => 'info',
        E_STRICT          => 'info',
    ];

    $level = $levelMap[$errno] ?? 'warning';
    $refId = _generate_error_reference();

    // Log the error
    if (function_exists('app_log')) {
        app_log($level, $errstr, [
            'category'     => 'php_error',
            'file'         => $errfile,
            'line'         => $errline,
            'reference_id' => $refId,
            'error_type'   => $errno,
        ]);
    }

    $_error_handler_active = false;

    // For E_USER_ERROR, throw an exception so it can be caught or handled
    if ($errno === E_USER_ERROR) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    // Return true = don't execute the standard PHP error handler
    return true;
});

// ─── Custom Exception Handler ────────────────────────────────────────
// Catches any uncaught exception, logs it, and redirects to error.php.

set_exception_handler(function (\Throwable $e): void {
    global $_error_handler_active;

    // Prevent recursion
    if ($_error_handler_active) {
        // Fallback: minimal output
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
        echo '<h1>An unexpected error occurred</h1>';
        echo '<p>The system encountered a critical error. Please contact the administrator.</p>';
        echo '</body></html>';
        exit(1);
    }
    $_error_handler_active = true;

    $refId = _generate_error_reference();

    // Log the exception
    if (function_exists('app_log')) {
        try {
            app_log('error', $e->getMessage(), [
                'category'     => 'exception',
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'trace'        => $e->getTraceAsString(),
                'reference_id' => $refId,
                'exception_class' => get_class($e),
            ]);
        } catch (\Throwable $logError) {
            // Logging itself failed — fall through to display
            error_log('[error_handler FALLBACK] ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    // Store error details in session for error.php to display
    try {
        if (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE) {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $_SESSION['last_error'] = [
                'reference_id' => $refId,
                'message'      => $e->getMessage(),
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'trace'        => $e->getTraceAsString(),
                'class'        => get_class($e),
                'time'         => date('Y-m-d H:i:s'),
            ];
        }
    } catch (\Throwable $sessionError) {
        // Session not available
    }

    // Redirect to error.php
    if (!headers_sent()) {
        http_response_code(500);
        header('Location: ' . _error_page_url($refId));
        exit(1);
    }

    // Headers already sent — output inline error page
    _render_fallback_error($refId, $e);
    exit(1);
});

// ─── Shutdown Function ───────────────────────────────────────────────
// Catches fatal errors (E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR)
// that are not catchable by set_error_handler().

register_shutdown_function(function (): void {
    global $_error_handler_active;

    $error = error_get_last();
    if ($error === null) return;

    // Only handle fatal errors
    $fatalTypes = [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) return;

    // Prevent recursion
    if ($_error_handler_active) return;
    $_error_handler_active = true;

    $refId = _generate_error_reference();

    // Log the fatal error
    if (function_exists('app_log')) {
        try {
            app_log('error', $error['message'], [
                'category'     => 'fatal',
                'file'         => $error['file'],
                'line'         => $error['line'],
                'reference_id' => $refId,
                'error_type'   => $error['type'],
            ]);
        } catch (\Throwable $e) {
            error_log('[fatal_handler FALLBACK] ' . $error['message'] . ' at ' . $error['file'] . ':' . $error['line']);
        }
    }

    // Store error in session
    try {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['last_error'] = [
                'reference_id' => $refId,
                'message'      => $error['message'],
                'file'         => $error['file'],
                'line'         => $error['line'],
                'trace'        => '',
                'class'        => 'FatalError',
                'time'         => date('Y-m-d H:i:s'),
            ];
        }
    } catch (\Throwable $e) {}

    // Redirect to error page (may not work if headers already sent)
    if (!headers_sent()) {
        header('Location: ' . _error_page_url($refId));
        exit(1);
    }

    // Inline fallback
    echo '<!DOCTYPE html><html><head><title>Error</title>';
    echo '<link rel="stylesheet" href="assets/css/tailwind.css"></head>';
    echo '<body class="bg-gray-100 flex items-center justify-center min-h-screen">';
    echo '<div class="bg-white rounded-xl shadow-lg max-w-md w-full overflow-hidden">';
    echo '<div class="bg-red-600 px-6 py-8 text-center"><div class="text-5xl mb-3">&#9888;&#65039;</div>';
    echo '<h1 class="text-2xl font-bold text-white">Something Went Wrong</h1></div>';
    echo '<div class="px-6 py-8 text-center">';
    echo '<p class="text-gray-600 mb-4">A critical error occurred. Please contact the administrator.</p>';
    echo '<p class="text-xs text-gray-400 mb-6">Reference: <code class="bg-gray-100 px-2 py-1 rounded">' . htmlspecialchars($refId) . '</code></p>';
    echo '<a href="index.php" class="inline-block px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Dashboard</a>';
    echo '</div></div></body></html>';
});

// ─── Helper Functions ────────────────────────────────────────────────

/**
 * Build the URL to error.php with the reference ID.
 */
function _error_page_url(string $refId): string
{
    // Detect if we're in a subdirectory
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $base = rtrim($scriptDir, '/');

    // If we're already in the root directory of the app, error.php is right here
    // If we're in /includes/ or deeper, go up one level
    $scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if (basename(dirname($scriptFile)) === 'includes') {
        $base = dirname($base);
    }

    return $base . '/error.php?ref=' . urlencode($refId);
}

/**
 * Render a minimal inline error page when headers are already sent.
 */
function _render_fallback_error(string $refId, \Throwable $e): void
{
    echo '<div style="font-family:system-ui,sans-serif; max-width:600px; margin:2rem auto; padding:2rem; background:#fff; border-radius:12px; box-shadow:0 4px 24px rgba(0,0,0,.1);">';
    echo '<div style="text-align:center; padding:1.5rem; background:#dc2626; border-radius:8px 8px 0 0; color:white;">';
    echo '<div style="font-size:3rem;">&#9888;&#65039;</div>';
    echo '<h1 style="font-size:1.5rem; font-weight:700; margin:0.5rem 0 0;">Something Went Wrong</h1></div>';
    echo '<div style="padding:1.5rem; text-align:center;">';
    echo '<p style="color:#666; margin-bottom:1rem;">An unexpected error occurred. Please try again or contact the administrator.</p>';
    echo '<p style="font-size:0.75rem; color:#999; margin-bottom:1.5rem;">Reference: <code style="background:#f3f4f6; padding:2px 6px; border-radius:4px;">' . htmlspecialchars($refId) . '</code></p>';

    // Admin-only: show technical details
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
        echo '<details style="text-align:left; margin-top:1rem; padding:1rem; background:#fef2f2; border:1px solid #fecaca; border-radius:8px;">';
        echo '<summary style="cursor:pointer; font-weight:600; color:#991b1b;">Technical Details (Admin Only)</summary>';
        echo '<pre style="margin-top:0.5rem; font-size:0.75rem; color:#7f1d1d; white-space:pre-wrap; word-break:break-all;">';
        echo htmlspecialchars(get_class($e) . ': ' . $e->getMessage()) . "\n";
        echo htmlspecialchars($e->getFile() . ':' . $e->getLine()) . "\n\n";
        echo htmlspecialchars($e->getTraceAsString());
        echo '</pre></details>';
    }

    echo '<div style="margin-top:1.5rem;">';
    echo '<a href="javascript:history.back()" style="display:inline-block; padding:0.5rem 1rem; background:#e5e7eb; color:#374151; border-radius:8px; text-decoration:none; font-size:0.875rem; margin-right:0.5rem;">&larr; Go Back</a>';
    echo '<a href="index.php" style="display:inline-block; padding:0.5rem 1rem; background:#2563eb; color:white; border-radius:8px; text-decoration:none; font-size:0.875rem;">Dashboard</a>';
    echo '</div></div></div>';
}
