<?php
require_once 'config.php';
requireLogin();
require_super_admin();

$message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    switch ($_POST['action']) {

        case 'add':
            $name     = sanitizeInput($_POST['name'] ?? '');
            $slug     = sanitizeInput($_POST['slug'] ?? '');
            $address  = sanitizeInput($_POST['address'] ?? '');
            $phone    = sanitizeInput($_POST['phone'] ?? '');
            $email    = sanitizeInput($_POST['email'] ?? '');
            $timezone = sanitizeInput($_POST['timezone'] ?? 'America/New_York');

            if ($name === '' || $slug === '') {
                $message = showAlert('School name and slug are required.', 'error');
                break;
            }

            // Normalise slug (lowercase, hyphens only)
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $slug)));

            // Duplicate check
            $check = $pdo->prepare("SELECT id FROM schools WHERE slug = ?");
            $check->execute([$slug]);
            if ($check->fetch()) {
                $message = showAlert('A school with that slug already exists!', 'error');
                break;
            }

            $bannerColor = sanitizeInput($_POST['banner_color'] ?? '#3b82f6');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $bannerColor)) { $bannerColor = '#3b82f6'; }

            $stmt = $pdo->prepare("
                INSERT INTO schools (name, slug, address, phone, email, timezone, banner_color, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$name, $slug, $address, $phone, $email, $timezone, $bannerColor]);
            $newId = $pdo->lastInsertId();

            // Copy default settings from school 1 into the new school
            try {
                $copyStmt = $pdo->prepare("
                    INSERT IGNORE INTO settings (school_id, setting_key, setting_value)
                    SELECT ?, setting_key, setting_value FROM settings WHERE school_id = 1
                ");
                $copyStmt->execute([$newId]);
            } catch (\PDOException $e) {}

            $message = showAlert("School \"{$name}\" created successfully!", 'success');
            break;

        case 'edit':
            $schoolId = (int)($_POST['school_id'] ?? 0);
            $name     = sanitizeInput($_POST['name'] ?? '');
            $slug     = sanitizeInput($_POST['slug'] ?? '');
            $address  = sanitizeInput($_POST['address'] ?? '');
            $phone    = sanitizeInput($_POST['phone'] ?? '');
            $email    = sanitizeInput($_POST['email'] ?? '');
            $timezone = sanitizeInput($_POST['timezone'] ?? 'America/New_York');

            if ($schoolId < 1 || $name === '' || $slug === '') {
                $message = showAlert('School name and slug are required.', 'error');
                break;
            }

            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $slug)));

            // Duplicate slug check (exclude current school)
            $check = $pdo->prepare("SELECT id FROM schools WHERE slug = ? AND id != ?");
            $check->execute([$slug, $schoolId]);
            if ($check->fetch()) {
                $message = showAlert('Another school already uses that slug.', 'error');
                break;
            }

            $bannerColor = sanitizeInput($_POST['banner_color'] ?? '#3b82f6');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $bannerColor)) { $bannerColor = '#3b82f6'; }

            $stmt = $pdo->prepare("
                UPDATE schools SET name = ?, slug = ?, address = ?, phone = ?, email = ?, timezone = ?, banner_color = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $slug, $address, $phone, $email, $timezone, $bannerColor, $schoolId]);
            $message = showAlert("School \"{$name}\" updated successfully!", 'success');
            break;

        case 'toggle_status':
            $schoolId  = (int)($_POST['school_id'] ?? 0);
            $newStatus = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'inactive';

            // Prevent deactivating the default school
            if ($schoolId === 1 && $newStatus === 'inactive') {
                $message = showAlert('Cannot deactivate the default school.', 'error');
                break;
            }

            $stmt = $pdo->prepare("UPDATE schools SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $schoolId]);
            $label = $newStatus === 'active' ? 'activated' : 'deactivated';
            $message = showAlert("School {$label} successfully.", 'success');
            break;
    }
}

// Fetch all schools with student counts and revenue
$schools = $pdo->query("
    SELECT s.*,
        (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id) AS student_count,
        (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.status = 'active') AS active_students,
        (SELECT COUNT(*) FROM users u WHERE u.school_id = s.id) AS staff_count,
        (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.school_id = s.id AND p.payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS monthly_revenue
    FROM schools s
    ORDER BY s.name
")->fetchAll();

// Common timezones for the dropdown
$timezones = [
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'America/Phoenix',
    'America/Anchorage',
    'Pacific/Honolulu',
    'America/Toronto',
    'America/Vancouver',
    'Europe/London',
    'Europe/Paris',
    'Europe/Berlin',
    'Asia/Tokyo',
    'Asia/Shanghai',
    'Australia/Sydney',
];

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Manage Schools</h1>
            <p class="text-sm text-gray-500 mt-1">Create and manage studio locations across your organisation.</p>
        </div>
        <button onclick="document.getElementById('addSchoolModal').classList.remove('hidden')"
                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
            + Add School
        </button>
    </div>

    <!-- Stats Summary -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <?php
        $totalSchools   = count($schools);
        $activeSchools  = count(array_filter($schools, fn($s) => $s['status'] === 'active'));
        $totalStudents  = array_sum(array_column($schools, 'student_count'));
        $totalRevenue   = array_sum(array_column($schools, 'monthly_revenue'));
        ?>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-500">Total Schools</p>
            <p class="text-2xl font-bold text-gray-800"><?php echo $totalSchools; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-500">Active Schools</p>
            <p class="text-2xl font-bold text-green-600"><?php echo $activeSchools; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-500">Total Students</p>
            <p class="text-2xl font-bold text-blue-600"><?php echo $totalStudents; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-500">Revenue (This Month)</p>
            <p class="text-2xl font-bold text-purple-600"><?php echo formatMoney($totalRevenue); ?></p>
        </div>
    </div>

    <!-- Schools Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">School</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Slug</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Students</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Staff</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Revenue (MTD)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($schools as $school): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <span class="inline-block w-3 h-3 rounded-full flex-shrink-0 border border-gray-300" style="background: <?php echo htmlspecialchars($school['banner_color'] ?? '#3b82f6'); ?>;"></span>
                                <div>
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($school['name']); ?></div>
                                    <?php if (!empty($school['email'])): ?>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($school['email']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <code class="text-sm bg-gray-100 px-2 py-0.5 rounded"><?php echo htmlspecialchars($school['slug']); ?></code>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <span class="font-semibold"><?php echo (int)$school['active_students']; ?></span>
                            <span class="text-gray-400">/ <?php echo (int)$school['student_count']; ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php echo (int)$school['staff_count']; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-700">
                            <?php echo formatMoney($school['monthly_revenue']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if ($school['status'] === 'active'): ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                            <?php else: ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                            <!-- Switch to this school -->
                            <a href="?switch_school=<?php echo $school['id']; ?>"
                               class="text-blue-600 hover:text-blue-900" title="Switch to this school">
                                Switch
                            </a>

                            <!-- Edit -->
                            <button onclick="editSchool(<?php echo htmlspecialchars(json_encode($school)); ?>)"
                                    class="text-yellow-600 hover:text-yellow-900" title="Edit">
                                Edit
                            </button>

                            <!-- Activate / Deactivate -->
                            <?php if ($school['id'] !== 1): ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to <?php echo $school['status'] === 'active' ? 'deactivate' : 'activate'; ?> this school?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="school_id" value="<?php echo $school['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $school['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                    <button type="submit" class="<?php echo $school['status'] === 'active' ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900'; ?>">
                                        <?php echo $school['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <!-- Registration link -->
                            <button onclick="showRegLink('<?php echo htmlspecialchars($school['slug']); ?>')"
                                    class="text-purple-600 hover:text-purple-900" title="Registration Link">
                                Reg Link
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($schools)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-gray-400">
                            No schools found. Click "+ Add School" to create your first school.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div><!-- /overflow-x-auto -->
    </div>
</div>

<?php $presetColors = ['#3b82f6'=>'Blue','#e53e3e'=>'Red','#38a169'=>'Green','#805ad5'=>'Purple','#f59e0b'=>'Amber','#14b8a6'=>'Teal','#6366f1'=>'Indigo','#ec4899'=>'Pink','#1a1a1a'=>'Black']; ?>
<!-- Add School Modal -->
<div id="addSchoolModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add New School</h3>
            <button onclick="document.getElementById('addSchoolModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&times;</button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">School Name *</label>
                    <input type="text" name="name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                           placeholder="Downtown Dojo"
                           oninput="autoSlug(this.value, 'add_slug')">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Slug * <span class="text-xs text-gray-400">(URL-safe)</span></label>
                    <input type="text" name="slug" id="add_slug" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                           placeholder="downtown-dojo" pattern="[a-z0-9\-]+">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <input type="text" name="address"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                       placeholder="123 Main St, City, State 12345">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="text" name="phone"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                           placeholder="(555) 123-4567">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                           placeholder="info@school.com">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Timezone</label>
                <select name="timezone"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <?php foreach ($timezones as $tz): ?>
                        <option value="<?php echo $tz; ?>" <?php echo $tz === 'America/New_York' ? 'selected' : ''; ?>>
                            <?php echo str_replace(['America/', 'Europe/', 'Asia/', 'Pacific/', 'Australia/'], ['US: ', 'EU: ', 'Asia: ', 'Pacific: ', 'AU: '], $tz); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Banner Color</label>
                <div class="flex items-center gap-3">
                    <input type="color" name="banner_color" id="add_banner_color" value="#3b82f6"
                           class="w-10 h-10 rounded cursor-pointer border border-gray-300 p-0.5">
                    <div class="flex flex-wrap gap-1.5" id="add_color_swatches">
                        <?php foreach ($presetColors as $hex => $label): ?>
                            <button type="button" title="<?= $label ?>"
                                    onclick="document.getElementById('add_banner_color').value='<?= $hex ?>'"
                                    class="w-6 h-6 rounded-full border-2 border-gray-200 hover:border-gray-400 transition-colors"
                                    style="background: <?= $hex ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-1">Displayed in the top banner when managing this school</p>
            </div>

            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-700">
                <strong>Note:</strong> Default settings (hours of operation, schedule intervals, etc.) will be copied from the primary school.
                You can customise them later from the Settings page after switching to this school.
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addSchoolModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Create School
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit School Modal -->
<div id="editSchoolModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit School</h3>
            <button onclick="document.getElementById('editSchoolModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&times;</button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="school_id" id="edit_school_id">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">School Name *</label>
                    <input type="text" name="name" id="edit_name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Slug *</label>
                    <input type="text" name="slug" id="edit_slug" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                           pattern="[a-z0-9\-]+">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <input type="text" name="address" id="edit_address"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="text" name="phone" id="edit_phone"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" id="edit_email"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Timezone</label>
                <select name="timezone" id="edit_timezone"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <?php foreach ($timezones as $tz): ?>
                        <option value="<?php echo $tz; ?>">
                            <?php echo str_replace(['America/', 'Europe/', 'Asia/', 'Pacific/', 'Australia/'], ['US: ', 'EU: ', 'Asia: ', 'Pacific: ', 'AU: '], $tz); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Banner Color</label>
                <div class="flex items-center gap-3">
                    <input type="color" name="banner_color" id="edit_banner_color" value="#3b82f6"
                           class="w-10 h-10 rounded cursor-pointer border border-gray-300 p-0.5">
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($presetColors as $hex => $label): ?>
                            <button type="button" title="<?= $label ?>"
                                    onclick="document.getElementById('edit_banner_color').value='<?= $hex ?>'"
                                    class="w-6 h-6 rounded-full border-2 border-gray-200 hover:border-gray-400 transition-colors"
                                    style="background: <?= $hex ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-1">Displayed in the top banner when managing this school</p>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editSchoolModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Registration Link Modal -->
<div id="regLinkModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Student Registration Link</h3>
            <button onclick="document.getElementById('regLinkModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&times;</button>
        </div>
        <p class="text-sm text-gray-600 mb-3">Share this link with prospective students to register at this school:</p>
        <div class="flex items-center space-x-2">
            <input type="text" id="regLinkInput" readonly
                   class="flex-1 px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-sm font-mono">
            <button onclick="copyRegLink()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm">
                Copy
            </button>
        </div>
        <p id="regLinkCopied" class="text-xs text-green-600 mt-2 hidden">Copied to clipboard!</p>
    </div>
</div>

<script>
// Auto-generate slug from school name
function autoSlug(value, targetId) {
    var slug = value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    document.getElementById(targetId).value = slug;
}

// Edit school modal
function editSchool(school) {
    document.getElementById('edit_school_id').value = school.id;
    document.getElementById('edit_name').value = school.name || '';
    document.getElementById('edit_slug').value = school.slug || '';
    document.getElementById('edit_address').value = school.address || '';
    document.getElementById('edit_phone').value = school.phone || '';
    document.getElementById('edit_email').value = school.email || '';
    document.getElementById('edit_timezone').value = school.timezone || 'America/New_York';
    document.getElementById('edit_banner_color').value = school.banner_color || '#3b82f6';
    document.getElementById('editSchoolModal').classList.remove('hidden');
}

// Registration link
function showRegLink(slug) {
    var base = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
    document.getElementById('regLinkInput').value = base + 'register.php?school=' + slug;
    document.getElementById('regLinkCopied').classList.add('hidden');
    document.getElementById('regLinkModal').classList.remove('hidden');
}

function copyRegLink() {
    var input = document.getElementById('regLinkInput');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(function() {
        document.getElementById('regLinkCopied').classList.remove('hidden');
    });
}
</script>

<?php include 'includes/footer.php'; ?>
