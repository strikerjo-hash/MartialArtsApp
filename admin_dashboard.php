<?php
/**
 * admin_dashboard.php — Admin / Owner Dashboard
 *
 * Includes a "Studio Branding" section that lets the studio owner
 * customise colours, name, tagline, logo, and custom CSS — the theme
 * values are stored in the studio_config table and immediately applied
 * across the whole application.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/db.php';

require_admin();

$pdo   = get_db();
$theme = get_theme();

$success = '';

// ---------- Handle branding form submission ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_branding'])) {
    $editable_keys = [
        'studio_name', 'studio_tagline', 'logo_url',
        'primary_color', 'secondary_color', 'accent_color',
        'background_color', 'text_color', 'font_family',
        'login_background_image', 'favicon_url', 'custom_css',
    ];

    $upsert = $pdo->prepare(
        'INSERT INTO studio_config (config_key, config_value)
         VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
    );

    foreach ($editable_keys as $key) {
        if (isset($_POST[$key])) {
            $upsert->execute([':k' => $key, ':v' => $_POST[$key]]);
        }
    }

    // Reload theme after saving.
    $theme   = get_theme();
    $success = 'Branding settings saved successfully.';
}

// ---------- Dashboard stats ----------
$studentCount = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE is_active = 1')->fetchColumn();
$classCount   = (int)$pdo->query('SELECT COUNT(*) FROM classes WHERE is_active = 1')->fetchColumn();
$todayAttend  = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE()")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — <?= htmlspecialchars($theme['studio_name']) ?></title>
    <?php if (!empty($theme['favicon_url'])): ?>
        <link rel="icon" href="<?= htmlspecialchars($theme['favicon_url']) ?>">
    <?php endif; ?>
    <style><?= theme_css_vars() ?></style>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="portal-page">

    <nav class="top-nav">
        <div class="nav-brand">
            <?php if (!empty($theme['logo_url'])): ?>
                <img src="<?= htmlspecialchars($theme['logo_url']) ?>" alt="" class="nav-logo">
            <?php endif; ?>
            <span><?= htmlspecialchars($theme['studio_name']) ?> — Admin</span>
        </div>
        <div class="nav-user">
            <span class="nav-greeting"><?= htmlspecialchars($_SESSION['full_name']) ?></span>
            <a href="logout.php" class="btn btn-sm btn-outline">Sign Out</a>
        </div>
    </nav>

    <main class="portal-main">

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- Quick Stats -->
        <section class="stats-row">
            <div class="card stat-card">
                <div class="stat-number"><?= $studentCount ?></div>
                <div class="stat-label">Active Students</div>
            </div>
            <div class="card stat-card">
                <div class="stat-number"><?= $classCount ?></div>
                <div class="stat-label">Active Classes</div>
            </div>
            <div class="card stat-card">
                <div class="stat-number"><?= $todayAttend ?></div>
                <div class="stat-label">Today's Attendance</div>
            </div>
        </section>

        <!-- Studio Branding -->
        <section class="card">
            <h2>Studio Branding &amp; Theme</h2>
            <p class="section-description">
                Customise the look and feel of your application. Changes apply
                immediately to all pages including the student login and portal.
            </p>

            <form method="POST" action="admin_dashboard.php" class="branding-form">
                <input type="hidden" name="save_branding" value="1">

                <div class="form-row">
                    <div class="form-group">
                        <label for="studio_name">Studio Name</label>
                        <input type="text" id="studio_name" name="studio_name"
                               value="<?= htmlspecialchars($theme['studio_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="studio_tagline">Tagline</label>
                        <input type="text" id="studio_tagline" name="studio_tagline"
                               value="<?= htmlspecialchars($theme['studio_tagline']) ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="logo_url">Logo URL</label>
                        <input type="text" id="logo_url" name="logo_url"
                               value="<?= htmlspecialchars($theme['logo_url']) ?>"
                               placeholder="assets/images/logo.png">
                    </div>
                    <div class="form-group">
                        <label for="favicon_url">Favicon URL</label>
                        <input type="text" id="favicon_url" name="favicon_url"
                               value="<?= htmlspecialchars($theme['favicon_url']) ?>"
                               placeholder="assets/images/favicon.ico">
                    </div>
                </div>

                <h3>Colour Palette</h3>
                <div class="color-grid">
                    <div class="form-group">
                        <label for="primary_color">Primary</label>
                        <input type="color" id="primary_color" name="primary_color"
                               value="<?= htmlspecialchars($theme['primary_color']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="secondary_color">Secondary</label>
                        <input type="color" id="secondary_color" name="secondary_color"
                               value="<?= htmlspecialchars($theme['secondary_color']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="accent_color">Accent</label>
                        <input type="color" id="accent_color" name="accent_color"
                               value="<?= htmlspecialchars($theme['accent_color']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="background_color">Background</label>
                        <input type="color" id="background_color" name="background_color"
                               value="<?= htmlspecialchars($theme['background_color']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="text_color">Text</label>
                        <input type="color" id="text_color" name="text_color"
                               value="<?= htmlspecialchars($theme['text_color']) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="font_family">Font Family (CSS)</label>
                    <input type="text" id="font_family" name="font_family"
                           value="<?= htmlspecialchars($theme['font_family']) ?>">
                </div>

                <div class="form-group">
                    <label for="login_background_image">Login Background Image URL</label>
                    <input type="text" id="login_background_image" name="login_background_image"
                           value="<?= htmlspecialchars($theme['login_background_image']) ?>"
                           placeholder="Optional — URL or path to a background image">
                </div>

                <div class="form-group">
                    <label for="custom_css">Custom CSS</label>
                    <textarea id="custom_css" name="custom_css" rows="6"
                              placeholder="Add any extra CSS rules here..."><?= htmlspecialchars($theme['custom_css']) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary">Save Branding</button>
            </form>
        </section>
    </main>

    <footer class="portal-footer">
        <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($theme['studio_name']) ?>. All rights reserved.</p>
    </footer>
</body>
</html>
