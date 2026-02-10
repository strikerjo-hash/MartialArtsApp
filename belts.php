<?php
require_once 'config.php';
requireLogin();

$message = '';

// Handle all POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {

        // ======== STYLES ========
        case 'add_style':
            $stmt = $pdo->prepare("INSERT INTO martial_arts_styles (name, description) VALUES (?, ?)");
            $stmt->execute([sanitizeInput($_POST['style_name']), sanitizeInput($_POST['style_description'])]);
            $message = showAlert('Martial art style added successfully!', 'success');
            break;

        case 'edit_style':
            $stmt = $pdo->prepare("UPDATE martial_arts_styles SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([sanitizeInput($_POST['style_name']), sanitizeInput($_POST['style_description']), $_POST['style_id']]);
            $message = showAlert('Style updated successfully!', 'success');
            break;

        case 'delete_style':
            // Check for belts or student_belts referencing this style
            $beltCount = $pdo->prepare("SELECT COUNT(*) as c FROM belts WHERE style_id = ?");
            $beltCount->execute([$_POST['style_id']]);
            if ($beltCount->fetch()['c'] > 0) {
                $message = showAlert('Cannot delete style that has belts assigned. Remove all belts first.', 'error');
            } else {
                $pdo->prepare("DELETE FROM martial_arts_styles WHERE id = ?")->execute([$_POST['style_id']]);
                $message = showAlert('Style deleted successfully!', 'success');
            }
            break;

        // ======== BELTS ========
        case 'add_belt':
            $stmt = $pdo->prepare("INSERT INTO belts (style_id, name, color, rank_order, requirements) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['belt_style_id'],
                sanitizeInput($_POST['belt_name']),
                sanitizeInput($_POST['belt_color']),
                (int)$_POST['belt_rank_order'],
                sanitizeInput($_POST['belt_requirements'])
            ]);
            $message = showAlert('Belt added successfully!', 'success');
            break;

        case 'edit_belt':
            $stmt = $pdo->prepare("UPDATE belts SET name = ?, color = ?, rank_order = ?, requirements = ? WHERE id = ?");
            $stmt->execute([
                sanitizeInput($_POST['belt_name']),
                sanitizeInput($_POST['belt_color']),
                (int)$_POST['belt_rank_order'],
                sanitizeInput($_POST['belt_requirements']),
                $_POST['belt_id']
            ]);
            $message = showAlert('Belt updated successfully!', 'success');
            break;

        case 'delete_belt':
            // Check if any students hold this belt
            $promoCount = $pdo->prepare("SELECT COUNT(*) as c FROM student_belts WHERE belt_id = ?");
            $promoCount->execute([$_POST['belt_id']]);
            if ($promoCount->fetch()['c'] > 0) {
                $message = showAlert('Cannot delete belt that has been awarded to students. Remove those promotions first.', 'error');
            } else {
                $pdo->prepare("DELETE FROM belts WHERE id = ?")->execute([$_POST['belt_id']]);
                $message = showAlert('Belt deleted successfully!', 'success');
            }
            break;

        // ======== PROMOTIONS ========
        case 'promote':
            $stmt = $pdo->prepare("
                INSERT INTO student_belts (student_id, belt_id, style_id, awarded_date, instructor_id, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['student_id'],
                $_POST['belt_id'],
                $_POST['style_id'],
                $_POST['awarded_date'],
                $_SESSION['user_id'],
                sanitizeInput($_POST['notes'])
            ]);
            $message = showAlert('Student promoted successfully!', 'success');
            break;

        case 'edit_promotion':
            $stmt = $pdo->prepare("UPDATE student_belts SET belt_id = ?, style_id = ?, awarded_date = ?, notes = ? WHERE id = ?");
            $stmt->execute([
                $_POST['belt_id'],
                $_POST['style_id'],
                $_POST['awarded_date'],
                sanitizeInput($_POST['notes']),
                $_POST['promotion_id']
            ]);
            $message = showAlert('Promotion updated successfully!', 'success');
            break;

        case 'delete_promotion':
            $pdo->prepare("DELETE FROM student_belts WHERE id = ?")->execute([$_POST['promotion_id']]);
            $message = showAlert('Promotion deleted successfully!', 'success');
            break;
    }
}

// Get all styles and their belts
$styles = $pdo->query("
    SELECT mas.*, COUNT(b.id) as belt_count
    FROM martial_arts_styles mas
    LEFT JOIN belts b ON mas.id = b.style_id
    GROUP BY mas.id
    ORDER BY mas.name
")->fetchAll();

// Pre-load all belts grouped by style for JS
$allBeltsByStyle = [];
foreach ($styles as $style) {
    $belts = $pdo->prepare("SELECT * FROM belts WHERE style_id = ? ORDER BY rank_order ASC");
    $belts->execute([$style['id']]);
    $allBeltsByStyle[$style['id']] = $belts->fetchAll();
}

// Get recent belt promotions
$recent_promotions = $pdo->query("
    SELECT sb.*,
           s.first_name, s.last_name,
           b.name as belt_name, b.color,
           mas.name as style_name,
           u.full_name as instructor_name
    FROM student_belts sb
    JOIN students s ON sb.student_id = s.id
    JOIN belts b ON sb.belt_id = b.id
    JOIN martial_arts_styles mas ON sb.style_id = mas.id
    LEFT JOIN users u ON sb.instructor_id = u.id
    ORDER BY sb.awarded_date DESC
    LIMIT 20
")->fetchAll();

// Get students for dropdown
$students = $pdo->query("SELECT id, first_name, last_name FROM students WHERE status = 'active' ORDER BY first_name, last_name")->fetchAll();

// Belt color map
$colorMap = [
    'White' => '#FFFFFF', 'Yellow' => '#FFD700', 'Orange' => '#FF8C00',
    'Green' => '#228B22', 'Blue' => '#0000CD', 'Purple' => '#800080',
    'Brown' => '#8B4513', 'Red' => '#DC143C', 'Black' => '#000000'
];

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Belt System</h1>
        <div class="space-x-2">
            <button onclick="document.getElementById('addStyleModal').classList.remove('hidden')"
                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium text-sm">
                + Add Style
            </button>
            <button onclick="document.getElementById('addBeltModal').classList.remove('hidden')"
                    class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium text-sm">
                + Add Belt
            </button>
            <button onclick="document.getElementById('promoteModal').classList.remove('hidden')"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                + Promote Student
            </button>
        </div>
    </div>

    <!-- Belt Systems by Style -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <?php foreach ($styles as $style): ?>
            <?php $belts = $allBeltsByStyle[$style['id']] ?? []; ?>
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-500 to-blue-600 rounded-t-lg">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-xl font-semibold text-white"><?php echo htmlspecialchars($style['name']); ?></h2>
                            <p class="text-sm text-blue-100 mt-1"><?php echo count($belts); ?> belts<?php if ($style['description']): ?> &mdash; <?php echo htmlspecialchars(substr($style['description'], 0, 60)); ?><?php endif; ?></p>
                        </div>
                        <div class="flex space-x-2">
                            <button onclick="editStyle(<?php echo htmlspecialchars(json_encode($style)); ?>)"
                                    class="text-blue-100 hover:text-white text-sm" title="Edit Style">&#9998;</button>
                            <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this style? All belts must be removed first.')">
                                <input type="hidden" name="action" value="delete_style">
                                <input type="hidden" name="style_id" value="<?php echo $style['id']; ?>">
                                <button type="submit" class="text-blue-100 hover:text-white text-sm" title="Delete Style">&#10005;</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <?php if (empty($belts)): ?>
                        <p class="text-gray-400 text-center py-4">No belts defined for this style yet.</p>
                    <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($belts as $belt): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-white font-bold text-sm border border-gray-300"
                                         style="background-color: <?php echo $colorMap[$belt['color']] ?? '#6B7280'; ?>; <?php echo in_array($belt['color'], ['White', 'Yellow']) ? 'color: #000;' : ''; ?>">
                                        <?php echo $belt['rank_order']; ?>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($belt['name']); ?></p>
                                        <?php if ($belt['requirements']): ?>
                                            <p class="text-xs text-gray-600"><?php echo htmlspecialchars(substr($belt['requirements'], 0, 60)); ?>...</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex space-x-2">
                                    <button onclick="editBelt(<?php echo htmlspecialchars(json_encode($belt)); ?>)"
                                            class="text-blue-600 hover:text-blue-800 text-sm" title="Edit Belt">&#9998;</button>
                                    <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this belt?')">
                                        <input type="hidden" name="action" value="delete_belt">
                                        <input type="hidden" name="belt_id" value="<?php echo $belt['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm" title="Delete Belt">&#10005;</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($styles)): ?>
            <div class="col-span-2 bg-white rounded-lg shadow p-12 text-center text-gray-500">
                <p class="text-lg mb-2">No martial art styles defined yet.</p>
                <p class="text-sm">Click "Add Style" to create your first martial art style.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Promotions -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Recent Promotions</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Belt</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Style</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Instructor</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($recent_promotions as $promo): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo formatDate($promo['awarded_date']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo $promo['first_name'] . ' ' . $promo['last_name']; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-3 py-1 text-sm font-semibold rounded-full"
                                      style="background-color: <?php echo $colorMap[$promo['color']] ?? '#6B7280'; ?>; <?php echo in_array($promo['color'], ['White', 'Yellow']) ? 'color: #000;' : 'color: #FFF;'; ?>">
                                    <?php echo $promo['belt_name']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo $promo['style_name']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo $promo['instructor_name'] ?? 'N/A'; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate">
                                <?php echo $promo['notes'] ?: '-'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <button onclick="editPromotion(<?php echo htmlspecialchars(json_encode($promo)); ?>)"
                                        class="text-blue-600 hover:text-blue-900 mr-2">Edit</button>
                                <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this promotion record?')">
                                    <input type="hidden" name="action" value="delete_promotion">
                                    <input type="hidden" name="promotion_id" value="<?php echo $promo['id']; ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (empty($recent_promotions)): ?>
                <div class="text-center py-12 text-gray-500">
                    <p class="text-lg">No promotions recorded yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ==================== MODALS ==================== -->

<!-- Add Style Modal -->
<div id="addStyleModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add Martial Art Style</h3>
            <button onclick="document.getElementById('addStyleModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_style">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Style Name *</label>
                <input type="text" name="style_name" required placeholder="e.g., Karate, Taekwondo, BJJ" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="style_description" rows="3" placeholder="Brief description of this martial art..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addStyleModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">Add Style</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Style Modal -->
<div id="editStyleModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit Martial Art Style</h3>
            <button onclick="document.getElementById('editStyleModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="edit_style">
            <input type="hidden" name="style_id" id="edit_style_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Style Name *</label>
                <input type="text" name="style_name" id="edit_style_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="style_description" id="edit_style_description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editStyleModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Update Style</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Belt Modal -->
<div id="addBeltModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add Belt</h3>
            <button onclick="document.getElementById('addBeltModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_belt">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Martial Art Style *</label>
                <select name="belt_style_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select style...</option>
                    <?php foreach ($styles as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Belt Name *</label>
                <input type="text" name="belt_name" required placeholder="e.g., White Belt, 1st Dan" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Color *</label>
                    <select name="belt_color" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">Select color...</option>
                        <option value="White">White</option>
                        <option value="Yellow">Yellow</option>
                        <option value="Orange">Orange</option>
                        <option value="Green">Green</option>
                        <option value="Blue">Blue</option>
                        <option value="Purple">Purple</option>
                        <option value="Brown">Brown</option>
                        <option value="Red">Red</option>
                        <option value="Black">Black</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rank Order *</label>
                    <input type="number" name="belt_rank_order" min="1" required placeholder="1 = lowest" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Requirements</label>
                <textarea name="belt_requirements" rows="3" placeholder="Requirements to achieve this belt..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addBeltModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg">Add Belt</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Belt Modal -->
<div id="editBeltModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit Belt</h3>
            <button onclick="document.getElementById('editBeltModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="edit_belt">
            <input type="hidden" name="belt_id" id="edit_belt_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Belt Name *</label>
                <input type="text" name="belt_name" id="edit_belt_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Color *</label>
                    <select name="belt_color" id="edit_belt_color" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="White">White</option>
                        <option value="Yellow">Yellow</option>
                        <option value="Orange">Orange</option>
                        <option value="Green">Green</option>
                        <option value="Blue">Blue</option>
                        <option value="Purple">Purple</option>
                        <option value="Brown">Brown</option>
                        <option value="Red">Red</option>
                        <option value="Black">Black</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rank Order *</label>
                    <input type="number" name="belt_rank_order" id="edit_belt_rank_order" min="1" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Requirements</label>
                <textarea name="belt_requirements" id="edit_belt_requirements" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editBeltModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Update Belt</button>
            </div>
        </form>
    </div>
</div>

<!-- Promote Student Modal -->
<div id="promoteModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Promote Student</h3>
            <button onclick="document.getElementById('promoteModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4" id="promoteForm">
            <input type="hidden" name="action" value="promote">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Student *</label>
                <select name="student_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select student...</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['id']; ?>"><?php echo $student['first_name'] . ' ' . $student['last_name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Martial Art Style *</label>
                <select name="style_id" required onchange="loadBelts(this.value, 'belt_select')" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select style...</option>
                    <?php foreach ($styles as $style): ?>
                        <option value="<?php echo $style['id']; ?>"><?php echo htmlspecialchars($style['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Belt/Rank *</label>
                <select name="belt_id" id="belt_select" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select style first...</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Award Date *</label>
                <input type="date" name="awarded_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" placeholder="Test results, comments, etc." class="w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('promoteModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Promote Student</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Promotion Modal -->
<div id="editPromotionModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit Promotion</h3>
            <button onclick="document.getElementById('editPromotionModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="edit_promotion">
            <input type="hidden" name="promotion_id" id="edit_promo_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Martial Art Style *</label>
                <select name="style_id" id="edit_promo_style" required onchange="loadBelts(this.value, 'edit_promo_belt')" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select style...</option>
                    <?php foreach ($styles as $style): ?>
                        <option value="<?php echo $style['id']; ?>"><?php echo htmlspecialchars($style['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Belt/Rank *</label>
                <select name="belt_id" id="edit_promo_belt" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select style first...</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Award Date *</label>
                <input type="date" name="awarded_date" id="edit_promo_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" id="edit_promo_notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editPromotionModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Update Promotion</button>
            </div>
        </form>
    </div>
</div>

<script>
// Pre-loaded belt data
const beltsByStyle = <?php echo json_encode($allBeltsByStyle); ?>;

function loadBelts(styleId, targetSelectId) {
    const beltSelect = document.getElementById(targetSelectId);
    beltSelect.innerHTML = '<option value="">Loading...</option>';

    if (!styleId) {
        beltSelect.innerHTML = '<option value="">Select style first...</option>';
        return;
    }

    beltSelect.innerHTML = '<option value="">Select belt...</option>';
    if (beltsByStyle[styleId]) {
        beltsByStyle[styleId].forEach(belt => {
            const option = document.createElement('option');
            option.value = belt.id;
            option.textContent = belt.name + ' (' + belt.color + ')';
            beltSelect.appendChild(option);
        });
    }
}

function editStyle(style) {
    document.getElementById('edit_style_id').value = style.id;
    document.getElementById('edit_style_name').value = style.name;
    document.getElementById('edit_style_description').value = style.description || '';
    document.getElementById('editStyleModal').classList.remove('hidden');
}

function editBelt(belt) {
    document.getElementById('edit_belt_id').value = belt.id;
    document.getElementById('edit_belt_name').value = belt.name;
    document.getElementById('edit_belt_color').value = belt.color;
    document.getElementById('edit_belt_rank_order').value = belt.rank_order;
    document.getElementById('edit_belt_requirements').value = belt.requirements || '';
    document.getElementById('editBeltModal').classList.remove('hidden');
}

function editPromotion(promo) {
    document.getElementById('edit_promo_id').value = promo.id;
    document.getElementById('edit_promo_date').value = promo.awarded_date;
    document.getElementById('edit_promo_notes').value = promo.notes || '';

    // Set style and load belts
    document.getElementById('edit_promo_style').value = promo.style_id;
    loadBelts(promo.style_id, 'edit_promo_belt');

    // After loading belts, select the current belt (small delay for DOM update)
    setTimeout(() => {
        document.getElementById('edit_promo_belt').value = promo.belt_id;
    }, 100);

    document.getElementById('editPromotionModal').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
