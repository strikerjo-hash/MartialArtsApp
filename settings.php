<?php
require_once 'config.php';
requireLogin();

$message = '';

// Create settings table if it doesn't exist (functions are in config.php)
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['change_password'])) {
        $current_user = getCurrentUser();
        
        if (password_verify($_POST['current_password'], $current_user['password'])) {
            if ($_POST['new_password'] === $_POST['confirm_password']) {
                $new_hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$new_hash, $_SESSION['user_id']]);
                $message = showAlert('Password changed successfully!', 'success');
            } else {
                $message = showAlert('New passwords do not match!', 'error');
            }
        } else {
            $message = showAlert('Current password is incorrect!', 'error');
        }
    }
    
    if (isset($_POST['save_branding'])) {
        // Save site name
        saveSetting('site_name', sanitizeInput($_POST['site_name']));

        // Save color scheme
        $valid_schemes = array_keys(getThemeColorSchemes());
        $scheme = $_POST['theme_color_scheme'] ?? 'blue';
        if (in_array($scheme, $valid_schemes)) {
            saveSetting('theme_color_scheme', $scheme);
        }

        // Handle logo upload
        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
            $logoDir = 'uploads/logo/';
            if (!file_exists($logoDir)) {
                mkdir($logoDir, 0777, true);
            }

            $fileExt = strtolower(pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];

            if (!in_array($fileExt, $allowed)) {
                $message = showAlert('Invalid logo file type. Allowed: ' . implode(', ', $allowed), 'error');
            } elseif ($_FILES['site_logo']['size'] > 2097152) {
                $message = showAlert('Logo file too large. Max 2MB.', 'error');
            } else {
                // Remove old logo
                $oldLogo = getSetting('site_logo', '');
                if ($oldLogo && file_exists($logoDir . $oldLogo)) {
                    unlink($logoDir . $oldLogo);
                }

                $newFilename = 'logo_' . time() . '.' . $fileExt;
                if (move_uploaded_file($_FILES['site_logo']['tmp_name'], $logoDir . $newFilename)) {
                    saveSetting('site_logo', $newFilename);
                    $message = showAlert('Branding settings saved with new logo!', 'success');
                } else {
                    $message = showAlert('Logo upload failed. Other settings saved.', 'warning');
                }
            }
        }

        // Handle logo removal
        if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
            $oldLogo = getSetting('site_logo', '');
            if ($oldLogo && file_exists('uploads/logo/' . $oldLogo)) {
                unlink('uploads/logo/' . $oldLogo);
            }
            saveSetting('site_logo', '');
        }

        if (!$message) {
            $message = showAlert('Branding settings saved!', 'success');
        }
    }

    if (isset($_POST['save_stripe'])) {
        // If enabling Stripe, disable Square
        if (isset($_POST['stripe_enabled'])) {
            saveSetting('square_enabled', '0');
            saveSetting('active_payment_gateway', 'stripe');
        } else {
            // If disabling Stripe and it was active, set to none
            if (getSetting('active_payment_gateway') === 'stripe') {
                saveSetting('active_payment_gateway', 'none');
            }
        }
        
        saveSetting('stripe_publishable_key', sanitizeInput($_POST['stripe_publishable_key']));
        saveSetting('stripe_secret_key', sanitizeInput($_POST['stripe_secret_key']));
        saveSetting('stripe_enabled', isset($_POST['stripe_enabled']) ? '1' : '0');
        $message = showAlert('Stripe settings saved! Square has been automatically disabled.', 'success');
    }
    
    if (isset($_POST['save_square'])) {
        // If enabling Square, disable Stripe
        if (isset($_POST['square_enabled'])) {
            saveSetting('stripe_enabled', '0');
            saveSetting('active_payment_gateway', 'square');
        } else {
            // If disabling Square and it was active, set to none
            if (getSetting('active_payment_gateway') === 'square') {
                saveSetting('active_payment_gateway', 'none');
            }
        }
        
        saveSetting('square_application_id', sanitizeInput($_POST['square_application_id']));
        saveSetting('square_access_token', sanitizeInput($_POST['square_access_token']));
        saveSetting('square_enabled', isset($_POST['square_enabled']) ? '1' : '0');
        $message = showAlert('Square settings saved! Stripe has been automatically disabled.', 'success');
    }
}

$current_user = getCurrentUser();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Settings</h1>
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Profile Settings -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Profile Information</h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                        <input type="text" value="<?php echo $current_user['username']; ?>" disabled
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                        <input type="text" value="<?php echo $current_user['full_name']; ?>" disabled
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" value="<?php echo $current_user['email']; ?>" disabled
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                        <input type="text" value="<?php echo ucfirst($current_user['role']); ?>" disabled
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100">
                    </div>
                </div>
            </div>
            
            <!-- Change Password -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Change Password</h2>
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                        <input type="password" name="current_password" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                        <input type="password" name="new_password" required minlength="6"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                        <input type="password" name="confirm_password" required minlength="6"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <button type="submit" name="change_password" value="1"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                        Update Password
                    </button>
                </form>
            </div>
            
            <?php if ($current_user['role'] === 'admin'): ?>
            <!-- Branding & Theme Settings -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Branding & Theme</h2>
                <p class="text-sm text-gray-600 mb-4">Customize your studio's logo and color scheme</p>

                <form method="POST" enctype="multipart/form-data" class="space-y-6">
                    <!-- Site Name -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Studio Name</label>
                        <input type="text" name="site_name"
                               value="<?php echo htmlspecialchars(getSiteName()); ?>"
                               placeholder="<?php echo APP_NAME; ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Displayed in the sidebar, header, and login page</p>
                    </div>

                    <!-- Logo Upload -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Logo</label>
                        <?php
                        $logoPath = getLogoPath();
                        if ($logoPath):
                        ?>
                            <div class="mb-3 flex items-center gap-4">
                                <div class="w-32 h-16 border border-gray-200 rounded-lg flex items-center justify-center bg-gray-50 p-2">
                                    <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo" class="max-h-full max-w-full object-contain">
                                </div>
                                <label class="flex items-center gap-2 text-sm text-red-600 cursor-pointer">
                                    <input type="checkbox" name="remove_logo" value="1" class="rounded">
                                    Remove current logo
                                </label>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="site_logo" accept=".jpg,.jpeg,.png,.gif,.svg,.webp"
                               class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                        <p class="text-xs text-gray-500 mt-1">Recommended: 200x60px, max 2MB. JPG, PNG, GIF, SVG, or WebP.</p>
                    </div>

                    <!-- Color Scheme -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">Color Scheme</label>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <?php
                            $schemes = getThemeColorSchemes();
                            $activeScheme = getActiveThemeKey();
                            foreach ($schemes as $key => $scheme):
                            ?>
                                <label class="relative cursor-pointer">
                                    <input type="radio" name="theme_color_scheme" value="<?php echo $key; ?>"
                                           <?php echo $activeScheme === $key ? 'checked' : ''; ?>
                                           class="peer sr-only">
                                    <div class="border-2 rounded-lg p-3 transition-all peer-checked:border-blue-500 peer-checked:ring-2 peer-checked:ring-blue-200 border-gray-200 hover:border-gray-300">
                                        <div class="flex gap-1 mb-2">
                                            <div class="w-8 h-8 rounded" style="background-color: <?php echo $scheme['sidebar_bg']; ?>"></div>
                                            <div class="w-8 h-8 rounded" style="background-color: <?php echo $scheme['primary']; ?>"></div>
                                            <div class="w-8 h-8 rounded" style="background-color: <?php echo $scheme['accent']; ?>"></div>
                                            <div class="w-8 h-8 rounded" style="background-color: <?php echo $scheme['primary_light']; ?>"></div>
                                        </div>
                                        <p class="text-sm font-medium text-gray-700"><?php echo $scheme['name']; ?></p>
                                    </div>
                                    <div class="absolute top-2 right-2 hidden peer-checked:block">
                                        <svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Preview -->
                    <div id="theme-preview" class="rounded-lg overflow-hidden border border-gray-200">
                        <div class="text-xs font-medium text-gray-500 px-3 py-1 bg-gray-50 border-b">Preview</div>
                        <div class="flex h-24">
                            <div id="preview-sidebar" class="w-16 p-2 flex flex-col items-center gap-1" style="background-color: <?php echo getActiveTheme()['sidebar_bg']; ?>">
                                <div class="w-6 h-6 rounded bg-white/20"></div>
                                <div class="w-10 h-1 rounded bg-white/30"></div>
                                <div class="w-10 h-1 rounded bg-white/30"></div>
                                <div id="preview-active" class="w-10 h-1 rounded" style="background-color: <?php echo getActiveTheme()['sidebar_active']; ?>"></div>
                                <div class="w-10 h-1 rounded bg-white/30"></div>
                            </div>
                            <div class="flex-1 bg-gray-50 p-3">
                                <div id="preview-header" class="h-3 rounded mb-2 w-24" style="background-color: <?php echo getActiveTheme()['primary']; ?>"></div>
                                <div class="flex gap-2">
                                    <div id="preview-card" class="flex-1 rounded p-2 h-12" style="background-color: <?php echo getActiveTheme()['primary_light']; ?>">
                                        <div class="w-8 h-1 rounded mb-1" style="background-color: <?php echo getActiveTheme()['primary']; ?>"></div>
                                        <div class="w-12 h-1 rounded bg-gray-300"></div>
                                    </div>
                                    <div class="flex-1 bg-white rounded p-2 h-12 border border-gray-200">
                                        <div class="w-8 h-1 rounded bg-gray-300 mb-1"></div>
                                        <div class="w-12 h-1 rounded bg-gray-200"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="save_branding" value="1"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                        Save Branding
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Payment Gateway Settings -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Payment Gateway Integration</h2>
                <p class="text-sm text-gray-600 mb-4">Configure your payment processing settings</p>
                
                <!-- Stripe Settings -->
                <form method="POST" class="mb-6">
                    <div class="border-l-4 border-blue-500 pl-4 py-2 bg-blue-50">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-semibold text-gray-800">Stripe</h3>
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="stripe_enabled" value="1" 
                                       <?php echo getSetting('stripe_enabled') == '1' ? 'checked' : ''; ?>
                                       class="mr-2">
                                <span class="text-sm text-gray-700">Enable Stripe</span>
                            </label>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Publishable Key</label>
                                <input type="text" name="stripe_publishable_key" 
                                       value="<?php echo getSetting('stripe_publishable_key'); ?>"
                                       placeholder="pk_test_..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Secret Key</label>
                                <input type="password" name="stripe_secret_key" 
                                       value="<?php echo getSetting('stripe_secret_key'); ?>"
                                       placeholder="sk_test_..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            </div>
                            <button type="submit" name="save_stripe" value="1"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">
                                Save Stripe Settings
                            </button>
                            <p class="text-xs text-gray-500 mt-2">
                                Get your Stripe keys from <a href="https://dashboard.stripe.com/apikeys" target="_blank" class="text-blue-600 underline">Stripe Dashboard</a>
                            </p>
                        </div>
                    </div>
                </form>
                
                <!-- Square Settings -->
                <form method="POST">
                    <div class="border-l-4 border-green-500 pl-4 py-2 bg-green-50">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-semibold text-gray-800">Square</h3>
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="square_enabled" value="1"
                                       <?php echo getSetting('square_enabled') == '1' ? 'checked' : ''; ?>
                                       class="mr-2">
                                <span class="text-sm text-gray-700">Enable Square</span>
                            </label>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Application ID</label>
                                <input type="text" name="square_application_id" 
                                       value="<?php echo getSetting('square_application_id'); ?>"
                                       placeholder="sq0idp-..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Access Token</label>
                                <input type="password" name="square_access_token" 
                                       value="<?php echo getSetting('square_access_token'); ?>"
                                       placeholder="sq0atp-..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                            </div>
                            <button type="submit" name="save_square" value="1"
                                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm">
                                Save Square Settings
                            </button>
                            <p class="text-xs text-gray-500 mt-2">
                                Get your Square credentials from <a href="https://developer.squareup.com/apps" target="_blank" class="text-green-600 underline">Square Developer Portal</a>
                            </p>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- System Information -->
        <div>
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">System Information</h2>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between border-b pb-2">
                        <span class="text-gray-600">Version</span>
                        <span class="font-semibold">1.0.0</span>
                    </div>
                    <div class="flex justify-between border-b pb-2">
                        <span class="text-gray-600">PHP Version</span>
                        <span class="font-semibold"><?php echo phpversion(); ?></span>
                    </div>
                    <div class="flex justify-between border-b pb-2">
                        <span class="text-gray-600">Database</span>
                        <span class="font-semibold">MySQL</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Server Time</span>
                        <span class="font-semibold"><?php echo date('Y-m-d H:i:s'); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Quick Actions</h2>
                <div class="space-y-2">
                    <a href="index.php" class="block w-full text-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                        Dashboard
                    </a>
                    <a href="students.php" class="block w-full text-center bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        Manage Students
                    </a>
                    <a href="events.php" class="block w-full text-center bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
                        Manage Events
                    </a>
                    <a href="logout.php" class="block w-full text-center bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Live preview for color scheme selection
const schemes = <?php echo json_encode(getThemeColorSchemes()); ?>;

document.querySelectorAll('input[name="theme_color_scheme"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const s = schemes[this.value];
        if (!s) return;

        const sidebar = document.getElementById('preview-sidebar');
        const active = document.getElementById('preview-active');
        const header = document.getElementById('preview-header');
        const card = document.getElementById('preview-card');

        if (sidebar) sidebar.style.backgroundColor = s.sidebar_bg;
        if (active) active.style.backgroundColor = s.sidebar_active;
        if (header) header.style.backgroundColor = s.primary;
        if (card) {
            card.style.backgroundColor = s.primary_light;
            const cardLine = card.querySelector('div');
            if (cardLine) cardLine.style.backgroundColor = s.primary;
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
