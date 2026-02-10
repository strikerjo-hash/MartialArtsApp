<?php
/**
 * admin_dashboard.php — Studio Branding & Theme Management
 *
 * Lets the studio owner customise colours, name, tagline, logo, and
 * custom CSS. Theme values are stored in the studio_config table and
 * immediately applied across the whole application.
 */

require_once 'config.php';
requireLogin();

require_once __DIR__ . '/includes/theme.php';

$theme_data = get_theme();
$message = '';

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

    $theme_data = get_theme();
    $message = showAlert('Branding settings saved successfully!', 'success');
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Studio Branding & Theme</h1>
        <p class="text-gray-600 mt-1">Customise the look and feel of your application. Changes apply immediately to all pages including the student login and portal.</p>
    </div>

    <form method="POST" action="admin_dashboard.php" class="space-y-6">
        <input type="hidden" name="save_branding" value="1">

        <!-- Studio Identity -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Studio Identity</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="studio_name" class="block text-sm font-medium text-gray-700 mb-1">Studio Name</label>
                    <input type="text" id="studio_name" name="studio_name"
                           value="<?php echo htmlspecialchars($theme_data['studio_name']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label for="studio_tagline" class="block text-sm font-medium text-gray-700 mb-1">Tagline</label>
                    <input type="text" id="studio_tagline" name="studio_tagline"
                           value="<?php echo htmlspecialchars($theme_data['studio_tagline']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
        </div>

        <!-- Logo & Favicon -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Logo & Favicon</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="logo_url" class="block text-sm font-medium text-gray-700 mb-1">Logo URL</label>
                    <input type="text" id="logo_url" name="logo_url"
                           value="<?php echo htmlspecialchars($theme_data['logo_url']); ?>"
                           placeholder="assets/images/logo.png"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <?php if (!empty($theme_data['logo_url'])): ?>
                        <div class="mt-2 p-2 bg-gray-50 rounded inline-block">
                            <img src="<?php echo htmlspecialchars($theme_data['logo_url']); ?>" alt="Current logo" class="max-h-12">
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="favicon_url" class="block text-sm font-medium text-gray-700 mb-1">Favicon URL</label>
                    <input type="text" id="favicon_url" name="favicon_url"
                           value="<?php echo htmlspecialchars($theme_data['favicon_url']); ?>"
                           placeholder="assets/images/favicon.ico"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
        </div>

        <!-- Colour Palette -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Colour Palette</h2>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div>
                    <label for="primary_color" class="block text-sm font-medium text-gray-700 mb-1">Primary</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="primary_color" name="primary_color"
                               value="<?php echo htmlspecialchars($theme_data['primary_color']); ?>"
                               class="w-10 h-10 rounded cursor-pointer">
                        <span class="text-xs text-gray-500"><?php echo htmlspecialchars($theme_data['primary_color']); ?></span>
                    </div>
                </div>
                <div>
                    <label for="secondary_color" class="block text-sm font-medium text-gray-700 mb-1">Secondary</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="secondary_color" name="secondary_color"
                               value="<?php echo htmlspecialchars($theme_data['secondary_color']); ?>"
                               class="w-10 h-10 rounded cursor-pointer">
                        <span class="text-xs text-gray-500"><?php echo htmlspecialchars($theme_data['secondary_color']); ?></span>
                    </div>
                </div>
                <div>
                    <label for="accent_color" class="block text-sm font-medium text-gray-700 mb-1">Accent</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="accent_color" name="accent_color"
                               value="<?php echo htmlspecialchars($theme_data['accent_color']); ?>"
                               class="w-10 h-10 rounded cursor-pointer">
                        <span class="text-xs text-gray-500"><?php echo htmlspecialchars($theme_data['accent_color']); ?></span>
                    </div>
                </div>
                <div>
                    <label for="background_color" class="block text-sm font-medium text-gray-700 mb-1">Background</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="background_color" name="background_color"
                               value="<?php echo htmlspecialchars($theme_data['background_color']); ?>"
                               class="w-10 h-10 rounded cursor-pointer">
                        <span class="text-xs text-gray-500"><?php echo htmlspecialchars($theme_data['background_color']); ?></span>
                    </div>
                </div>
                <div>
                    <label for="text_color" class="block text-sm font-medium text-gray-700 mb-1">Text</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="text_color" name="text_color"
                               value="<?php echo htmlspecialchars($theme_data['text_color']); ?>"
                               class="w-10 h-10 rounded cursor-pointer">
                        <span class="text-xs text-gray-500"><?php echo htmlspecialchars($theme_data['text_color']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Typography & Extras -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Typography & Extras</h2>
            <div class="space-y-4">
                <div>
                    <label for="font_family" class="block text-sm font-medium text-gray-700 mb-1">Font Family (CSS)</label>
                    <input type="text" id="font_family" name="font_family"
                           value="<?php echo htmlspecialchars($theme_data['font_family']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label for="login_background_image" class="block text-sm font-medium text-gray-700 mb-1">Login Background Image URL</label>
                    <input type="text" id="login_background_image" name="login_background_image"
                           value="<?php echo htmlspecialchars($theme_data['login_background_image']); ?>"
                           placeholder="Optional — URL or path to a background image"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label for="custom_css" class="block text-sm font-medium text-gray-700 mb-1">Custom CSS</label>
                    <textarea id="custom_css" name="custom_css" rows="6"
                              placeholder="Add any extra CSS rules here..."
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 font-mono text-sm"><?php echo htmlspecialchars($theme_data['custom_css']); ?></textarea>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg font-medium">
                Save Branding
            </button>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>
