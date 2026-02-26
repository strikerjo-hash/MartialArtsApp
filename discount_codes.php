<?php
require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';
requireLogin();

$message = '';

// Get plans and events for dropdowns
$params = [];
$stmt = $pdo->prepare("SELECT id, name FROM membership_plans WHERE status = 'active'" . school_where() . " ORDER BY name");
school_param($params);
$stmt->execute($params);
$plans = $stmt->fetchAll();
$params = [];
$stmt = $pdo->prepare("SELECT id, name FROM events WHERE event_date >= CURDATE()" . school_where() . " ORDER BY event_date ASC");
school_param($params);
$stmt->execute($params);
$events = $stmt->fetchAll();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    switch ($_POST['action']) {
        case 'add_code':
            $code = strtoupper(trim(sanitizeInput($_POST['code'] ?? '')));
            if ($code === '') {
                $message = showAlert('Discount code is required.', 'error');
                break;
            }
            // Check uniqueness
            $params = [$code];
            $dup = $pdo->prepare("SELECT id FROM discount_codes WHERE code = ?" . school_where());
            school_param($params);
            $dup->execute($params);
            if ($dup->fetch()) {
                $message = showAlert('A discount code with that name already exists.', 'error');
                break;
            }
            $stmt = $pdo->prepare("INSERT INTO discount_codes
                (school_id, code, description, discount_type, discount_value, applies_to, plan_id, event_id, max_uses, valid_from, valid_until, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                current_school_id(),
                $code,
                sanitizeInput($_POST['description'] ?? ''),
                $_POST['discount_type'] === 'flat' ? 'flat' : 'percentage',
                max(0, (float) ($_POST['discount_value'] ?? 0)),
                in_array($_POST['applies_to'] ?? '', ['plan_price','registration_fee','both']) ? $_POST['applies_to'] : 'both',
                !empty($_POST['plan_id']) ? (int) $_POST['plan_id'] : null,
                !empty($_POST['event_id']) ? (int) $_POST['event_id'] : null,
                ($_POST['max_uses'] ?? '') !== '' ? max(1, (int) $_POST['max_uses']) : null,
                !empty($_POST['valid_from']) ? $_POST['valid_from'] : null,
                !empty($_POST['valid_until']) ? $_POST['valid_until'] : null,
                isset($_POST['is_active']) ? 1 : 0,
            ]);
            $message = showAlert('Discount code created successfully!', 'success');
            break;

        case 'edit_code':
            $code = strtoupper(trim(sanitizeInput($_POST['code'] ?? '')));
            $codeId = (int) $_POST['code_id'];
            // Check uniqueness excluding self
            $params = [$code, $codeId];
            $dup = $pdo->prepare("SELECT id FROM discount_codes WHERE code = ? AND id != ?" . school_where());
            school_param($params);
            $dup->execute($params);
            if ($dup->fetch()) {
                $message = showAlert('Another discount code with that name already exists.', 'error');
                break;
            }
            $params = [
                $code,
                sanitizeInput($_POST['description'] ?? ''),
                $_POST['discount_type'] === 'flat' ? 'flat' : 'percentage',
                max(0, (float) ($_POST['discount_value'] ?? 0)),
                in_array($_POST['applies_to'] ?? '', ['plan_price','registration_fee','both']) ? $_POST['applies_to'] : 'both',
                !empty($_POST['plan_id']) ? (int) $_POST['plan_id'] : null,
                !empty($_POST['event_id']) ? (int) $_POST['event_id'] : null,
                ($_POST['max_uses'] ?? '') !== '' ? max(1, (int) $_POST['max_uses']) : null,
                !empty($_POST['valid_from']) ? $_POST['valid_from'] : null,
                !empty($_POST['valid_until']) ? $_POST['valid_until'] : null,
                isset($_POST['is_active']) ? 1 : 0,
                $codeId,
            ];
            $stmt = $pdo->prepare("UPDATE discount_codes SET
                code = ?, description = ?, discount_type = ?, discount_value = ?,
                applies_to = ?, plan_id = ?, event_id = ?, max_uses = ?,
                valid_from = ?, valid_until = ?, is_active = ?
                WHERE id = ?" . school_where());
            school_param($params);
            $stmt->execute($params);
            $message = showAlert('Discount code updated successfully!', 'success');
            break;

        case 'delete_code':
            $codeId = (int) $_POST['code_id'];
            $params = [$codeId];
            $check = $pdo->prepare("SELECT uses_count FROM discount_codes WHERE id = ?" . school_where());
            school_param($params);
            $check->execute($params);
            $row = $check->fetch();
            if ($row && $row['uses_count'] > 0) {
                // Deactivate instead of delete
                $params = [$codeId];
                $stmt = $pdo->prepare("UPDATE discount_codes SET is_active = 0 WHERE id = ?" . school_where());
                school_param($params);
                $stmt->execute($params);
                $message = showAlert('Code has been used and cannot be deleted. It has been deactivated instead.', 'warning');
            } else {
                $params = [$codeId];
                $stmt = $pdo->prepare("DELETE FROM discount_codes WHERE id = ?" . school_where());
                school_param($params);
                $stmt->execute($params);
                $message = showAlert('Discount code deleted.', 'success');
            }
            break;

        case 'toggle_active':
            $codeId = (int) $_POST['code_id'];
            $newVal = (int) $_POST['new_active_value'];
            $params = [$newVal, $codeId];
            $stmt = $pdo->prepare("UPDATE discount_codes SET is_active = ? WHERE id = ?" . school_where());
            school_param($params);
            $stmt->execute($params);
            $message = showAlert('Discount code ' . ($newVal ? 'activated' : 'deactivated') . '.', 'success');
            break;
    }
}

// Fetch all discount codes
$params = [];
$sql = "SELECT dc.*,
           mp.name AS plan_name,
           e.name  AS event_name
    FROM discount_codes dc
    LEFT JOIN membership_plans mp ON dc.plan_id = mp.id
    LEFT JOIN events e ON dc.event_id = e.id
    " . school_where_clause('dc') . "
    ORDER BY dc.is_active DESC, dc.created_at DESC";
school_param($params);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$codes = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Discount Codes</h1>
        <button onclick="document.getElementById('addCodeModal').classList.remove('hidden')"
                class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-medium">
            + Add Discount Code
        </button>
    </div>

    <!-- Codes Table -->
    <div class="bg-white rounded-lg shadow">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Discount</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Applies To</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Scope</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Uses</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Valid</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($codes as $c): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-4 whitespace-nowrap">
                            <span class="font-mono font-bold text-gray-900"><?php echo htmlspecialchars($c['code']); ?></span>
                            <?php if ($c['description']): ?>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($c['description']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm">
                            <?php if ($c['discount_type'] === 'percentage'): ?>
                                <span class="font-semibold text-green-700"><?php echo number_format($c['discount_value'], 0); ?>%</span> off
                            <?php else: ?>
                                <span class="font-semibold text-green-700"><?php echo formatMoney($c['discount_value']); ?></span> off
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php
                            $appliesLabels = ['plan_price' => 'Plan Price', 'registration_fee' => 'Reg. Fee', 'both' => 'Both'];
                            echo $appliesLabels[$c['applies_to']] ?? $c['applies_to'];
                            ?>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php if ($c['plan_name']): ?>
                                <span class="text-blue-600"><?php echo htmlspecialchars($c['plan_name']); ?></span>
                            <?php elseif ($c['event_name']): ?>
                                <span class="text-purple-600"><?php echo htmlspecialchars($c['event_name']); ?></span>
                            <?php else: ?>
                                <span class="text-gray-400">All Plans/Events</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm">
                            <span class="font-semibold"><?php echo (int) $c['uses_count']; ?></span>
                            <?php if ($c['max_uses'] !== null): ?>
                                / <?php echo (int) $c['max_uses']; ?>
                            <?php else: ?>
                                <span class="text-gray-400">/ &infin;</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-xs text-gray-600">
                            <?php if ($c['valid_from'] || $c['valid_until']): ?>
                                <?php echo $c['valid_from'] ? formatDate($c['valid_from']) : 'Any'; ?>
                                &ndash;
                                <?php echo $c['valid_until'] ? formatDate($c['valid_until']) : 'Any'; ?>
                            <?php else: ?>
                                <span class="text-gray-400">No expiry</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <form method="POST" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="code_id" value="<?php echo $c['id']; ?>">
                                <input type="hidden" name="new_active_value" value="<?php echo $c['is_active'] ? 0 : 1; ?>">
                                <button type="submit" class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $c['is_active'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                    <?php echo $c['is_active'] ? '&#10003; Active' : '&#10007; Inactive'; ?>
                                </button>
                            </form>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm">
                            <button onclick='editCode(<?php echo json_encode($c); ?>)' class="text-blue-600 hover:text-blue-900 mr-2">Edit</button>
                            <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this discount code?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_code">
                                <input type="hidden" name="code_id" value="<?php echo $c['id']; ?>">
                                <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($codes)): ?>
                <div class="text-center py-12 text-gray-500"><p class="text-lg">No discount codes yet</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Code Modal -->
<div id="addCodeModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Create Discount Code</h3>
            <button onclick="document.getElementById('addCodeModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_code">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Code *</label>
                    <input type="text" name="code" required placeholder="e.g., NEWSTUDENT20" style="text-transform:uppercase"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Letters, numbers, and dashes only</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="description" placeholder="e.g., New student signup discount"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Discount Type *</label>
                    <select name="discount_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="percentage">Percentage (%)</option>
                        <option value="flat">Flat Amount ($)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Discount Value *</label>
                    <input type="number" name="discount_value" step="0.01" min="0" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Applies To *</label>
                    <select name="applies_to" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="both">Both (Plan + Reg Fee)</option>
                        <option value="plan_price">Plan Price Only</option>
                        <option value="registration_fee">Registration Fee Only</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Limit to Plan</label>
                    <select name="plan_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">All Plans</option>
                        <?php foreach ($plans as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Limit to Event</label>
                    <select name="event_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">All Events</option>
                        <?php foreach ($events as $ev): ?>
                            <option value="<?php echo $ev['id']; ?>"><?php echo htmlspecialchars($ev['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Uses</label>
                    <input type="number" name="max_uses" min="1" placeholder="Unlimited"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valid From</label>
                    <input type="date" name="valid_from"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valid Until</label>
                    <input type="date" name="valid_until"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                <label class="flex items-center space-x-2 cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" checked class="rounded border-gray-300 text-blue-600">
                    <span class="text-sm font-medium text-blue-800">Active</span>
                </label>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addCodeModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">Create Code</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Code Modal -->
<div id="editCodeModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit Discount Code</h3>
            <button onclick="document.getElementById('editCodeModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_code">
            <input type="hidden" name="code_id" id="ec_id">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Code *</label>
                    <input type="text" name="code" id="ec_code" required style="text-transform:uppercase"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="description" id="ec_description"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Discount Type *</label>
                    <select name="discount_type" id="ec_discount_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="percentage">Percentage (%)</option>
                        <option value="flat">Flat Amount ($)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Discount Value *</label>
                    <input type="number" name="discount_value" id="ec_discount_value" step="0.01" min="0" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Applies To *</label>
                    <select name="applies_to" id="ec_applies_to" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="both">Both (Plan + Reg Fee)</option>
                        <option value="plan_price">Plan Price Only</option>
                        <option value="registration_fee">Registration Fee Only</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Limit to Plan</label>
                    <select name="plan_id" id="ec_plan_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">All Plans</option>
                        <?php foreach ($plans as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Limit to Event</label>
                    <select name="event_id" id="ec_event_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">All Events</option>
                        <?php foreach ($events as $ev): ?>
                            <option value="<?php echo $ev['id']; ?>"><?php echo htmlspecialchars($ev['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Uses</label>
                    <input type="number" name="max_uses" id="ec_max_uses" min="1" placeholder="Unlimited"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valid From</label>
                    <input type="date" name="valid_from" id="ec_valid_from"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valid Until</label>
                    <input type="date" name="valid_until" id="ec_valid_until"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                <label class="flex items-center space-x-2 cursor-pointer">
                    <input type="checkbox" name="is_active" id="ec_is_active" value="1" class="rounded border-gray-300 text-blue-600">
                    <span class="text-sm font-medium text-blue-800">Active</span>
                </label>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editCodeModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Update Code</button>
            </div>
        </form>
    </div>
</div>

<script>
function editCode(c) {
    document.getElementById('ec_id').value = c.id;
    document.getElementById('ec_code').value = c.code;
    document.getElementById('ec_description').value = c.description || '';
    document.getElementById('ec_discount_type').value = c.discount_type;
    document.getElementById('ec_discount_value').value = c.discount_value;
    document.getElementById('ec_applies_to').value = c.applies_to;
    document.getElementById('ec_plan_id').value = c.plan_id || '';
    document.getElementById('ec_event_id').value = c.event_id || '';
    document.getElementById('ec_max_uses').value = c.max_uses || '';
    document.getElementById('ec_valid_from').value = c.valid_from || '';
    document.getElementById('ec_valid_until').value = c.valid_until || '';
    document.getElementById('ec_is_active').checked = !!parseInt(c.is_active);
    document.getElementById('editCodeModal').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
