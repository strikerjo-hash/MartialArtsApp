<?php
/**
 * Theme / Branding System
 *
 * Loads studio_config values from the database and exposes them as an
 * associative array.  Falls back to sensible defaults when the DB is
 * unreachable so the login page still renders.
 */

require_once __DIR__ . '/db.php';

function get_theme(): array
{
    // Defaults — used when the DB table hasn't been populated yet or the
    // connection fails.  These also serve as the "out of the box" branding.
    $defaults = [
        'studio_name'            => 'Martial Arts Academy',
        'studio_tagline'         => 'Discipline. Respect. Excellence.',
        'logo_url'               => '',
        'primary_color'          => '#b71c1c',
        'secondary_color'        => '#1a1a2e',
        'accent_color'           => '#f5c518',
        'background_color'       => '#0f0f1a',
        'text_color'             => '#e0e0e0',
        'font_family'            => "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif",
        'login_background_image' => '',
        'favicon_url'            => '',
        'custom_css'             => '',
    ];

    try {
        $pdo  = get_db();
        $stmt = $pdo->query('SELECT config_key, config_value FROM studio_config');
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $defaults[$row['config_key']] = $row['config_value'];
        }
    } catch (\Exception $e) {
        // Silently fall back to defaults so the page still loads.
    }

    return $defaults;
}

/**
 * Output CSS custom-property declarations from the current theme.
 * Call inside a <style> block: <?php echo theme_css_vars(); ?>
 */
function theme_css_vars(): string
{
    $t = get_theme();

    $css  = ":root {\n";
    $css .= "  --primary-color: {$t['primary_color']};\n";
    $css .= "  --secondary-color: {$t['secondary_color']};\n";
    $css .= "  --accent-color: {$t['accent_color']};\n";
    $css .= "  --background-color: {$t['background_color']};\n";
    $css .= "  --text-color: {$t['text_color']};\n";
    $css .= "  --font-family: {$t['font_family']};\n";
    $css .= "}\n";

    // Append any custom CSS the studio owner has provided.
    if (!empty($t['custom_css'])) {
        $css .= $t['custom_css'] . "\n";
    }

    return $css;
}
