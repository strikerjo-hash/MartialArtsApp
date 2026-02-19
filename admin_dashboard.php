<?php
/**
 * admin_dashboard.php — Unified Studio Branding & Theme Management
 *
 * Combines quick theme presets (from config.php color schemes) with
 * custom color pickers, studio identity, logo upload, and advanced
 * options. All values are stored in the studio_config table.
 */

require_once 'config.php';
requireLogin();

require_once __DIR__ . '/includes/theme.php';

$theme_data = get_theme();
$message = '';

// Ensure studio_config table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS studio_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        config_key VARCHAR(100) UNIQUE NOT NULL,
        config_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (\PDOException $e) {}

// ---------- Handle branding form submission ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_branding'])) {
    verify_csrf();

    $editable_keys = [
        'studio_name', 'studio_tagline',
        'primary_color', 'secondary_color', 'accent_color',
        'background_color', 'text_color', 'font_family',
        'login_background_image', 'custom_css',
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

    // Handle logo upload
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $logoDir = 'uploads/logo/';
        if (!file_exists($logoDir)) {
            mkdir($logoDir, 0777, true);
        }

        $fileExt = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];

        if (!in_array($fileExt, $allowed)) {
            $message = showAlert('Invalid logo file type. Allowed: ' . implode(', ', $allowed), 'error');
        } elseif ($_FILES['logo_file']['size'] > 2097152) {
            $message = showAlert('Logo file too large. Max 2MB.', 'error');
        } else {
            // Remove old logo file if it exists in uploads
            $oldLogo = $theme_data['logo_url'];
            if ($oldLogo && file_exists($oldLogo)) {
                @unlink($oldLogo);
            }

            $newFilename = 'logo_' . time() . '.' . $fileExt;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $logoDir . $newFilename)) {
                $upsert->execute([':k' => 'logo_url', ':v' => $logoDir . $newFilename]);
            } else {
                $message = showAlert('Logo upload failed.', 'warning');
            }
        }
    }

    // Handle logo removal
    if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
        $oldLogo = $theme_data['logo_url'];
        if ($oldLogo && file_exists($oldLogo)) {
            @unlink($oldLogo);
        }
        $upsert->execute([':k' => 'logo_url', ':v' => '']);
    }

    // Handle favicon upload
    if (isset($_FILES['favicon_file']) && $_FILES['favicon_file']['error'] === UPLOAD_ERR_OK) {
        $faviconDir = 'uploads/logo/';
        if (!file_exists($faviconDir)) {
            mkdir($faviconDir, 0777, true);
        }

        $fileExt = strtolower(pathinfo($_FILES['favicon_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['ico', 'png', 'svg'];

        if (!in_array($fileExt, $allowed)) {
            $message = showAlert('Invalid favicon file type. Allowed: ico, png, svg', 'error');
        } elseif ($_FILES['favicon_file']['size'] > 1048576) {
            $message = showAlert('Favicon file too large. Max 1MB.', 'error');
        } else {
            $newFilename = 'favicon_' . time() . '.' . $fileExt;
            if (move_uploaded_file($_FILES['favicon_file']['tmp_name'], $faviconDir . $newFilename)) {
                $upsert->execute([':k' => 'favicon_url', ':v' => $faviconDir . $newFilename]);
            }
        }
    }

    // Reload theme after save
    $theme_data = get_theme();

    if (!$message) {
        $message = showAlert('Branding settings saved successfully!', 'success');
    }
}

// Get preset schemes for the JS
$presetSchemes = getThemeColorSchemes();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Studio Branding & Theme</h1>
        <p class="text-gray-600 mt-1">Customise the look and feel of your application. Changes apply immediately to all pages including the student login and portal.</p>
    </div>

    <form method="POST" action="admin_dashboard.php" enctype="multipart/form-data" class="space-y-6">
        <?= csrf_field() ?>
        <input type="hidden" name="save_branding" value="1">

        <!-- Quick Theme Presets -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-2">Quick Theme Presets</h2>
            <p class="text-sm text-gray-500 mb-4">Select a preset to auto-fill the color palette below. You can further customise individual colors after selecting a preset.</p>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                <?php foreach ($presetSchemes as $key => $scheme): ?>
                    <button type="button" onclick="applyPreset('<?php echo $key; ?>')"
                            class="preset-btn border-2 rounded-lg p-3 transition-all hover:border-blue-400 hover:shadow-md border-gray-200 text-left"
                            data-preset="<?php echo $key; ?>">
                        <div class="flex gap-1 mb-2">
                            <div class="w-6 h-6 rounded" style="background-color: <?php echo $scheme['sidebar_bg']; ?>"></div>
                            <div class="w-6 h-6 rounded" style="background-color: <?php echo $scheme['primary']; ?>"></div>
                            <div class="w-6 h-6 rounded" style="background-color: <?php echo $scheme['accent']; ?>"></div>
                        </div>
                        <p class="text-xs font-medium text-gray-700"><?php echo $scheme['name']; ?></p>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Studio Identity -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Studio Identity</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label for="studio_name" class="block text-sm font-medium text-gray-700 mb-1">Studio Name</label>
                    <input type="text" id="studio_name" name="studio_name"
                           value="<?php echo htmlspecialchars($theme_data['studio_name']); ?>"
                           placeholder="<?php echo APP_NAME; ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Displayed in sidebar, header, and login page</p>
                </div>
                <div>
                    <label for="studio_tagline" class="block text-sm font-medium text-gray-700 mb-1">Tagline</label>
                    <input type="text" id="studio_tagline" name="studio_tagline"
                           value="<?php echo htmlspecialchars($theme_data['studio_tagline']); ?>"
                           placeholder="Discipline. Respect. Excellence."
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <!-- Logo Upload -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Logo</label>
                    <?php if (!empty($theme_data['logo_url'])): ?>
                        <div class="mb-3 flex items-center gap-4">
                            <div class="w-32 h-16 border border-gray-200 rounded-lg flex items-center justify-center bg-gray-50 p-2">
                                <img src="<?php echo htmlspecialchars($theme_data['logo_url']); ?>" alt="Logo" class="max-h-full max-w-full object-contain">
                            </div>
                            <label class="flex items-center gap-2 text-sm text-red-600 cursor-pointer">
                                <input type="checkbox" name="remove_logo" value="1" class="rounded">
                                Remove logo
                            </label>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="logo_file" accept=".jpg,.jpeg,.png,.gif,.svg,.webp"
                           class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    <p class="text-xs text-gray-500 mt-1">Recommended: 200x60px, max 2MB. JPG, PNG, GIF, SVG, or WebP.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Favicon</label>
                    <?php if (!empty($theme_data['favicon_url'])): ?>
                        <div class="mb-3 flex items-center gap-4">
                            <img src="<?php echo htmlspecialchars($theme_data['favicon_url']); ?>" alt="Favicon" class="w-8 h-8">
                            <span class="text-xs text-gray-500">Current favicon</span>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="favicon_file" accept=".ico,.png,.svg"
                           class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    <p class="text-xs text-gray-500 mt-1">ICO, PNG, or SVG. Max 1MB.</p>
                </div>
            </div>
        </div>

        <!-- Custom Color Palette -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-2">Custom Colour Palette</h2>
            <p class="text-sm text-gray-500 mb-4">Fine-tune individual colours. These are pre-filled when you select a preset above.</p>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div>
                    <label for="primary_color" class="block text-sm font-medium text-gray-700 mb-1">Primary</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="primary_color" name="primary_color"
                               value="<?php echo htmlspecialchars($theme_data['primary_color']); ?>"
                               class="w-10 h-10 rounded cursor-pointer border border-gray-300">
                        <input type="text" id="primary_color_hex" value="<?php echo htmlspecialchars($theme_data['primary_color']); ?>"
                               class="w-20 text-xs px-2 py-1 border border-gray-300 rounded" readonly>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Buttons, links, accents</p>
                </div>
                <div>
                    <label for="secondary_color" class="block text-sm font-medium text-gray-700 mb-1">Secondary</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="secondary_color" name="secondary_color"
                               value="<?php echo htmlspecialchars($theme_data['secondary_color']); ?>"
                               class="w-10 h-10 rounded cursor-pointer border border-gray-300">
                        <input type="text" id="secondary_color_hex" value="<?php echo htmlspecialchars($theme_data['secondary_color']); ?>"
                               class="w-20 text-xs px-2 py-1 border border-gray-300 rounded" readonly>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Sidebar background</p>
                </div>
                <div>
                    <label for="accent_color" class="block text-sm font-medium text-gray-700 mb-1">Accent</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="accent_color" name="accent_color"
                               value="<?php echo htmlspecialchars($theme_data['accent_color']); ?>"
                               class="w-10 h-10 rounded cursor-pointer border border-gray-300">
                        <input type="text" id="accent_color_hex" value="<?php echo htmlspecialchars($theme_data['accent_color']); ?>"
                               class="w-20 text-xs px-2 py-1 border border-gray-300 rounded" readonly>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Highlights, badges</p>
                </div>
                <div>
                    <label for="background_color" class="block text-sm font-medium text-gray-700 mb-1">Background</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="background_color" name="background_color"
                               value="<?php echo htmlspecialchars($theme_data['background_color']); ?>"
                               class="w-10 h-10 rounded cursor-pointer border border-gray-300">
                        <input type="text" id="background_color_hex" value="<?php echo htmlspecialchars($theme_data['background_color']); ?>"
                               class="w-20 text-xs px-2 py-1 border border-gray-300 rounded" readonly>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Login page background</p>
                </div>
                <div>
                    <label for="text_color" class="block text-sm font-medium text-gray-700 mb-1">Text</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="text_color" name="text_color"
                               value="<?php echo htmlspecialchars($theme_data['text_color']); ?>"
                               class="w-10 h-10 rounded cursor-pointer border border-gray-300">
                        <input type="text" id="text_color_hex" value="<?php echo htmlspecialchars($theme_data['text_color']); ?>"
                               class="w-20 text-xs px-2 py-1 border border-gray-300 rounded" readonly>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Sidebar text colour</p>
                </div>
            </div>

            <!-- Live Preview -->
            <div class="mt-6">
                <p class="text-xs font-medium text-gray-500 mb-2">Live Preview</p>
                <div id="theme-preview" class="rounded-lg overflow-hidden border border-gray-200">
                    <div class="flex h-28">
                        <div id="preview-sidebar" class="w-20 p-2 flex flex-col items-center gap-1 transition-colors" style="background-color: <?php echo htmlspecialchars($theme_data['secondary_color']); ?>">
                            <div class="w-8 h-8 rounded bg-white/20"></div>
                            <div class="w-14 h-1.5 rounded bg-white/30"></div>
                            <div class="w-14 h-1.5 rounded bg-white/30"></div>
                            <div id="preview-active" class="w-14 h-1.5 rounded transition-colors" style="background-color: <?php echo htmlspecialchars($theme_data['primary_color']); ?>"></div>
                            <div class="w-14 h-1.5 rounded bg-white/30"></div>
                            <div id="preview-accent" class="w-8 h-3 rounded-full mt-1 transition-colors" style="background-color: <?php echo htmlspecialchars($theme_data['accent_color']); ?>"></div>
                        </div>
                        <div class="flex-1 bg-gray-50 p-3">
                            <div id="preview-header" class="h-4 rounded mb-3 w-28 transition-colors" style="background-color: <?php echo htmlspecialchars($theme_data['primary_color']); ?>"></div>
                            <div class="flex gap-2">
                                <div class="flex-1 bg-white rounded p-2 h-14 border border-gray-200">
                                    <div id="preview-btn" class="w-12 h-4 rounded mb-1 transition-colors" style="background-color: <?php echo htmlspecialchars($theme_data['primary_color']); ?>"></div>
                                    <div class="w-16 h-1 rounded bg-gray-200"></div>
                                </div>
                                <div class="flex-1 bg-white rounded p-2 h-14 border border-gray-200">
                                    <div class="w-10 h-1 rounded bg-gray-300 mb-1"></div>
                                    <div class="w-14 h-1 rounded bg-gray-200"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Typography & Extras -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Typography & Advanced</h2>
            <div class="space-y-4">
                <div>
                    <label for="font_family" class="block text-sm font-medium text-gray-700 mb-1">Font Family (CSS)</label>
                    <input type="text" id="font_family" name="font_family"
                           value="<?php echo htmlspecialchars($theme_data['font_family']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">e.g. 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif</p>
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

<script>
// Preset color schemes from PHP
const presets = <?php echo json_encode($presetSchemes); ?>;

// Map preset keys to studio_config color picker IDs
function applyPreset(key) {
    const s = presets[key];
    if (!s) return;

    // Map preset → color pickers
    setColor('primary_color', s.primary);
    setColor('secondary_color', s.sidebar_bg);
    setColor('accent_color', s.accent);
    setColor('background_color', s.gradient_from);
    setColor('text_color', s.sidebar_text);

    // Highlight the selected preset button
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.classList.remove('border-blue-500', 'ring-2', 'ring-blue-200');
        btn.classList.add('border-gray-200');
    });
    const active = document.querySelector(`.preset-btn[data-preset="${key}"]`);
    if (active) {
        active.classList.remove('border-gray-200');
        active.classList.add('border-blue-500', 'ring-2', 'ring-blue-200');
    }

    // Update preview
    updatePreview();
}

function setColor(id, value) {
    const picker = document.getElementById(id);
    const hex = document.getElementById(id + '_hex');
    if (picker) picker.value = value;
    if (hex) hex.value = value;
}

// Sync color pickers with hex display + preview
['primary_color', 'secondary_color', 'accent_color', 'background_color', 'text_color'].forEach(id => {
    const picker = document.getElementById(id);
    if (picker) {
        picker.addEventListener('input', function() {
            const hex = document.getElementById(id + '_hex');
            if (hex) hex.value = this.value;
            updatePreview();
        });
    }
});

function updatePreview() {
    const primary   = document.getElementById('primary_color').value;
    const secondary = document.getElementById('secondary_color').value;
    const accent    = document.getElementById('accent_color').value;

    const sidebar = document.getElementById('preview-sidebar');
    const active  = document.getElementById('preview-active');
    const accentEl = document.getElementById('preview-accent');
    const header  = document.getElementById('preview-header');
    const btn     = document.getElementById('preview-btn');

    if (sidebar)  sidebar.style.backgroundColor = secondary;
    if (active)   active.style.backgroundColor = primary;
    if (accentEl) accentEl.style.backgroundColor = accent;
    if (header)   header.style.backgroundColor = primary;
    if (btn)      btn.style.backgroundColor = primary;
}
</script>

<?php include 'includes/footer.php'; ?>
