<?php
/**
 * api/routes/theme.php — Public theme/branding endpoint
 *
 * GET /theme?school_id=X  — Returns school branding (colors, logo, name)
 *
 * No authentication required. Used by mobile apps to fetch
 * school branding before/during login.
 */

function route_theme(string $method): void
{
    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $pdo = get_db();
    $schoolId = (int) api_query('school_id', 1);

    // Verify the school exists and is active
    $stmt = $pdo->prepare("SELECT id, name, slug, address, phone, email, timezone FROM schools WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        api_error('School not found', 404);
    }

    // Get theme data using existing functions from config.php
    // Note: theme functions use current_school_id() which reads from session,
    // but API is stateless so we rely on the default school or query directly.
    $theme = function_exists('getActiveTheme') ? getActiveTheme() : [];
    $siteName = function_exists('getSiteName') ? getSiteName() : ($school['name'] ?? 'Martial Arts School');
    $logoPath = function_exists('getLogoPath') ? getLogoPath() : '';

    // Build absolute logo URL if path is relative
    $logoUrl = '';
    if (!empty($logoPath)) {
        if (strpos($logoPath, 'http') === 0) {
            $logoUrl = $logoPath;
        } else {
            // Build from request
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $basePath = dirname(dirname($_SERVER['SCRIPT_NAME'])); // Go up from /api/
            $logoUrl = $protocol . '://' . $host . rtrim($basePath, '/') . '/' . ltrim($logoPath, '/');
        }
    }

    // Get studio tagline from studio_config if available
    $tagline = '';
    try {
        $tagStmt = $pdo->prepare("SELECT config_value FROM studio_config WHERE config_key = 'studio_tagline' AND school_id = ? LIMIT 1");
        $tagStmt->execute([$schoolId]);
        $tagRow = $tagStmt->fetch(PDO::FETCH_ASSOC);
        if ($tagRow) {
            $tagline = $tagRow['config_value'] ?? '';
        }
    } catch (PDOException $e) {
        // studio_config table may not exist
    }

    api_json([
        'data' => [
            'school_id'        => (int) $school['id'],
            'school_name'      => $siteName,
            'school_tagline'   => $tagline,
            'school_slug'      => $school['slug'] ?? '',
            'school_address'   => $school['address'] ?? '',
            'school_phone'     => $school['phone'] ?? '',
            'school_email'     => $school['email'] ?? '',
            'school_timezone'  => $school['timezone'] ?? 'America/New_York',
            'logo_url'         => $logoUrl,
            'primary_color'    => $theme['primary'] ?? '#3b82f6',
            'secondary_color'  => $theme['sidebar_bg'] ?? '#1e293b',
            'accent_color'     => $theme['accent'] ?? '#f59e0b',
            'text_color'       => $theme['sidebar_text'] ?? '#e2e8f0',
            'primary_light'    => $theme['primary_light'] ?? '#60a5fa',
        ],
    ]);
}
