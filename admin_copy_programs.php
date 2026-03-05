<?php
require_once 'includes/auth.php';
requireLogin();

// Only super admins can copy programs between schools
if (!is_super_admin()) {
    $_SESSION['error'] = 'Only super admins can copy programs between schools.';
    header('Location: index.php');
    exit;
}

require_once 'includes/program_copy_helpers.php';

$pdo = get_db();
$schools = get_all_schools();

// Need at least 2 schools
if (count($schools) < 2) {
    $_SESSION['error'] = 'You need at least two schools to copy programs between them.';
    header('Location: index.php');
    exit;
}

$tab = $_GET['tab'] ?? 'plans';
$validTabs = ['plans', 'classes', 'events', 'rooms'];
if (!in_array($tab, $validTabs)) $tab = 'plans';

$sourceSchoolId = (int)($_GET['source'] ?? current_school_id());
$successMessages = [];
$errorMessages = [];

// ------- Handle POST — copy items -------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'copy') {
    verify_csrf();

    $targetSchoolIds = array_map('intval', $_POST['target_schools'] ?? []);
    $selectedIds     = array_map('intval', $_POST['selected_items'] ?? []);
    $copyType        = $_POST['copy_type'] ?? '';

    if (empty($targetSchoolIds)) {
        $errorMessages[] = 'Please select at least one target school.';
    } elseif (empty($selectedIds)) {
        $errorMessages[] = 'Please select at least one item to copy.';
    } else {
        $copiedCount = 0;
        $skippedCount = 0;

        foreach ($selectedIds as $itemId) {
            $itemName = get_program_item_name($copyType, $itemId);
            foreach ($targetSchoolIds as $targetId) {
                // Find target school name
                $targetName = '';
                foreach ($schools as $s) {
                    if ((int)$s['id'] === $targetId) { $targetName = $s['name']; break; }
                }

                $result = false;
                try {
                    switch ($copyType) {
                        case 'plans':
                            $result = copy_membership_plan($itemId, $targetId);
                            break;
                        case 'classes':
                            $result = copy_class_to_school($itemId, $targetId);
                            break;
                        case 'events':
                            $result = copy_event_to_school($itemId, $targetId);
                            break;
                        case 'rooms':
                            $result = copy_room_to_school($itemId, $targetId);
                            break;
                    }
                } catch (\PDOException $e) {
                    $errorMessages[] = "Error copying \"$itemName\" to $targetName: " . $e->getMessage();
                    continue;
                }

                if ($result) {
                    $copiedCount++;
                } else {
                    $skippedCount++;
                }
            }
        }

        if ($copiedCount > 0) {
            $successMessages[] = "Successfully copied $copiedCount item(s) to target school(s).";
        }
        if ($skippedCount > 0) {
            $errorMessages[] = "$skippedCount item(s) were skipped (already exist in target school or not found).";
        }
    }

    $tab = $copyType ?: $tab;
}

// ------- Fetch items based on active tab + source school -------
$items = [];
switch ($tab) {
    case 'plans':
        $stmt = $pdo->prepare("SELECT * FROM membership_plans WHERE school_id = ? ORDER BY name");
        $stmt->execute([$sourceSchoolId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'classes':
        $stmt = $pdo->prepare("
            SELECT c.*, COALESCE(u.full_name, 'Unassigned') AS instructor_name,
                   COALESCE(r.name, 'No Room') AS room_name,
                   COALESCE(mas.name, 'N/A') AS style_name
            FROM classes c
            LEFT JOIN users u ON c.instructor_id = u.id
            LEFT JOIN rooms r ON c.room_id = r.id
            LEFT JOIN martial_arts_styles mas ON c.style_id = mas.id
            WHERE c.school_id = ?
            ORDER BY c.name, FIELD(c.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
        ");
        $stmt->execute([$sourceSchoolId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'events':
        $stmt = $pdo->prepare("
            SELECT e.*, COALESCE(u.full_name, 'Unassigned') AS instructor_name
            FROM events e
            LEFT JOIN users u ON e.instructor_id = u.id
            WHERE e.school_id = ?
            ORDER BY e.event_date DESC
        ");
        $stmt->execute([$sourceSchoolId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'rooms':
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE school_id = ? ORDER BY name");
        $stmt->execute([$sourceSchoolId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
}

// Source school name
$sourceSchoolName = '';
foreach ($schools as $s) {
    if ((int)$s['id'] === $sourceSchoolId) { $sourceSchoolName = $s['name']; break; }
}

$tabLabels = [
    'plans'   => ['label' => 'Membership Plans', 'icon' => '📋'],
    'classes'  => ['label' => 'Classes',          'icon' => '🥋'],
    'events'   => ['label' => 'Events',           'icon' => '🎯'],
    'rooms'    => ['label' => 'Rooms',            'icon' => '🏠'],
];

require_once 'includes/header.php';
?>

<div class="p-6 max-w-7xl mx-auto">
    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Copy Programs Between Schools</h1>
        <p class="text-gray-600 mt-1">Duplicate membership plans, classes, events, and rooms from one school to another.</p>
    </div>

    <!-- Success / Error Messages -->
    <?php if (!empty($successMessages)): ?>
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg">
            <?php foreach ($successMessages as $msg): ?>
                <p class="text-green-700 text-sm flex items-center gap-2"><span>&#10003;</span> <?= htmlspecialchars($msg) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($errorMessages)): ?>
        <div class="mb-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
            <?php foreach ($errorMessages as $msg): ?>
                <p class="text-yellow-700 text-sm flex items-center gap-2"><span>&#9888;</span> <?= htmlspecialchars($msg) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Source School Selector -->
    <div class="bg-white rounded-xl shadow-sm border p-4 mb-6">
        <form method="GET" class="flex flex-wrap items-center gap-4">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium text-gray-700 whitespace-nowrap">Source School:</label>
                <select name="source" onchange="this.form.submit()"
                        class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <?php foreach ($schools as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= (int)$s['id'] === $sourceSchoolId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="text-sm text-gray-500">Select the school to copy programs FROM.</p>
        </form>
    </div>

    <!-- Tabs -->
    <div class="flex space-x-1 mb-6 bg-white rounded-xl shadow-sm border p-1.5 overflow-x-auto">
        <?php foreach ($tabLabels as $key => $info): ?>
            <a href="?tab=<?= $key ?>&source=<?= $sourceSchoolId ?>"
               class="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors whitespace-nowrap
                      <?= $tab === $key ? 'bg-blue-600 text-white shadow' : 'text-gray-600 hover:bg-gray-100' ?>">
                <span><?= $info['icon'] ?></span>
                <span><?= $info['label'] ?></span>
                <span class="<?= $tab === $key ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-600' ?> text-xs px-1.5 py-0.5 rounded-full">
                    <?php
                    // Quick count
                    $countTable = match($key) {
                        'plans' => 'membership_plans',
                        'classes' => 'classes',
                        'events' => 'events',
                        'rooms' => 'rooms',
                    };
                    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM $countTable WHERE school_id = ?");
                    $cntStmt->execute([$sourceSchoolId]);
                    echo $cntStmt->fetchColumn();
                    ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Copy Form -->
    <form method="POST" id="copyForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="copy">
        <input type="hidden" name="copy_type" value="<?= htmlspecialchars($tab) ?>">

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <!-- Main Content: Items Table (3/4) -->
            <div class="lg:col-span-3">
                <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
                    <!-- Table Header -->
                    <div class="px-4 py-3 border-b bg-gray-50 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-700">
                            <?= $tabLabels[$tab]['icon'] ?> <?= $tabLabels[$tab]['label'] ?> at <?= htmlspecialchars($sourceSchoolName) ?>
                        </h3>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" id="selectAll" onchange="toggleAll(this.checked)" class="rounded">
                            Select All
                        </label>
                    </div>

                    <?php if (empty($items)): ?>
                        <div class="p-8 text-center text-gray-500">
                            <p class="text-lg mb-1">No <?= strtolower($tabLabels[$tab]['label']) ?> found</p>
                            <p class="text-sm">This school has no <?= strtolower($tabLabels[$tab]['label']) ?> to copy.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                                    <tr>
                                        <th class="px-4 py-2 text-left w-10"></th>
                                        <th class="px-4 py-2 text-left">Name</th>
                                        <?php if ($tab === 'plans'): ?>
                                            <th class="px-4 py-2 text-left">Price</th>
                                            <th class="px-4 py-2 text-left">Duration</th>
                                            <th class="px-4 py-2 text-left">Billing</th>
                                        <?php elseif ($tab === 'classes'): ?>
                                            <th class="px-4 py-2 text-left">Style</th>
                                            <th class="px-4 py-2 text-left">Day</th>
                                            <th class="px-4 py-2 text-left">Time</th>
                                            <th class="px-4 py-2 text-left">Instructor</th>
                                        <?php elseif ($tab === 'events'): ?>
                                            <th class="px-4 py-2 text-left">Type</th>
                                            <th class="px-4 py-2 text-left">Date</th>
                                            <th class="px-4 py-2 text-left">Fee</th>
                                        <?php elseif ($tab === 'rooms'): ?>
                                            <th class="px-4 py-2 text-left">Status</th>
                                        <?php endif; ?>
                                        <th class="px-4 py-2 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($items as $item): ?>
                                        <tr class="hover:bg-blue-50/30 transition-colors">
                                            <td class="px-4 py-3">
                                                <input type="checkbox" name="selected_items[]"
                                                       value="<?= $item['id'] ?>"
                                                       class="item-checkbox rounded">
                                            </td>
                                            <td class="px-4 py-3 font-medium text-gray-800">
                                                <?= htmlspecialchars($item['name']) ?>
                                                <?php if ($tab === 'plans' && !empty($item['is_afterschool'])): ?>
                                                    <span class="ml-1 text-xs bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded">Afterschool</span>
                                                <?php endif; ?>
                                                <?php if ($tab === 'plans' && !empty($item['is_grandfathered'])): ?>
                                                    <span class="ml-1 text-xs bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded">Legacy</span>
                                                <?php endif; ?>
                                            </td>

                                            <?php if ($tab === 'plans'): ?>
                                                <td class="px-4 py-3 text-gray-600">$<?= number_format($item['price'], 2) ?></td>
                                                <td class="px-4 py-3 text-gray-600"><?= $item['duration_months'] ?> mo</td>
                                                <td class="px-4 py-3 text-gray-600"><?= ucfirst($item['billing_frequency'] ?? 'upfront') ?></td>
                                            <?php elseif ($tab === 'classes'): ?>
                                                <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($item['style_name'] ?? 'N/A') ?></td>
                                                <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($item['day_of_week']) ?></td>
                                                <td class="px-4 py-3 text-gray-600">
                                                    <?= date('g:i A', strtotime($item['start_time'])) ?> - <?= date('g:i A', strtotime($item['end_time'])) ?>
                                                </td>
                                                <td class="px-4 py-3 text-gray-600">
                                                    <?= htmlspecialchars($item['instructor_name']) ?>
                                                    <?php if ($item['instructor_name'] !== 'Unassigned'): ?>
                                                        <span class="text-xs text-orange-500" title="Instructor will be cleared when copied">(won't copy)</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php elseif ($tab === 'events'): ?>
                                                <td class="px-4 py-3 text-gray-600"><?= ucfirst(str_replace('_', ' ', $item['event_type'])) ?></td>
                                                <td class="px-4 py-3 text-gray-600"><?= date('M j, Y', strtotime($item['event_date'])) ?></td>
                                                <td class="px-4 py-3 text-gray-600">$<?= number_format($item['registration_fee'], 2) ?></td>
                                            <?php elseif ($tab === 'rooms'): ?>
                                                <td class="px-4 py-3 text-gray-600"><!-- shown below --></td>
                                            <?php endif; ?>

                                            <td class="px-4 py-3 text-center">
                                                <?php
                                                $status = $item['status'] ?? 'active';
                                                $statusColors = [
                                                    'active'    => 'bg-green-100 text-green-700',
                                                    'inactive'  => 'bg-gray-100 text-gray-600',
                                                    'upcoming'  => 'bg-blue-100 text-blue-700',
                                                    'ongoing'   => 'bg-green-100 text-green-700',
                                                    'completed' => 'bg-gray-100 text-gray-600',
                                                    'cancelled' => 'bg-red-100 text-red-700',
                                                ];
                                                $colorClass = $statusColors[$status] ?? 'bg-gray-100 text-gray-600';
                                                ?>
                                                <span class="inline-block px-2 py-0.5 text-xs font-medium rounded-full <?= $colorClass ?>">
                                                    <?= ucfirst($status) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Notes -->
                <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-700">
                    <p class="font-medium mb-1">Notes about copying:</p>
                    <ul class="list-disc ml-5 space-y-0.5 text-xs">
                        <li>Items with the same name in the target school will be <strong>skipped</strong> (no duplicates).</li>
                        <?php if ($tab === 'classes'): ?>
                            <li>Instructor and Room assignments will be <strong>cleared</strong> in the copy (they are school-specific).</li>
                            <li>Martial arts style is preserved (styles are shared across all schools).</li>
                        <?php elseif ($tab === 'events'): ?>
                            <li>Instructor assignment will be <strong>cleared</strong> in the copy.</li>
                            <li>Event dates, times, and registration details are preserved as-is.</li>
                        <?php elseif ($tab === 'plans'): ?>
                            <li>All plan details (price, duration, billing frequency, etc.) are copied exactly.</li>
                            <li>Afterschool program dates are preserved.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Sidebar: Target Schools (1/4) -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border p-4 sticky top-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Copy To Schools</h3>
                    <p class="text-xs text-gray-500 mb-3">Select one or more target schools:</p>

                    <div class="space-y-2 mb-4 max-h-64 overflow-y-auto">
                        <?php foreach ($schools as $s): ?>
                            <?php if ((int)$s['id'] !== $sourceSchoolId): ?>
                                <label class="flex items-center gap-2 p-2 rounded-lg hover:bg-gray-50 cursor-pointer text-sm">
                                    <input type="checkbox" name="target_schools[]"
                                           value="<?= $s['id'] ?>"
                                           class="rounded target-school-checkbox">
                                    <span class="text-gray-700">
                                        <?= htmlspecialchars($s['name']) ?>
                                        <?php if ($s['status'] !== 'active'): ?>
                                            <span class="text-xs text-gray-400">(inactive)</span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <label class="flex items-center gap-2 text-xs text-gray-500 mb-4 cursor-pointer">
                        <input type="checkbox" id="selectAllSchools" onchange="toggleAllSchools(this.checked)" class="rounded">
                        Select All Schools
                    </label>

                    <button type="submit"
                            onclick="return confirmCopy()"
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 px-4 rounded-lg transition-colors text-sm flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                        Copy Selected Items
                    </button>

                    <div id="selectionSummary" class="mt-3 text-xs text-gray-500 text-center">
                        <span id="selectedCount">0</span> item(s) selected
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function toggleAll(checked) {
    document.querySelectorAll('.item-checkbox').forEach(cb => cb.checked = checked);
    updateCount();
}

function toggleAllSchools(checked) {
    document.querySelectorAll('.target-school-checkbox').forEach(cb => cb.checked = checked);
}

function updateCount() {
    const count = document.querySelectorAll('.item-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = count;
}

function confirmCopy() {
    const itemCount = document.querySelectorAll('.item-checkbox:checked').length;
    const schoolCount = document.querySelectorAll('.target-school-checkbox:checked').length;

    if (itemCount === 0) {
        alert('Please select at least one item to copy.');
        return false;
    }
    if (schoolCount === 0) {
        alert('Please select at least one target school.');
        return false;
    }

    return confirm(`Copy ${itemCount} item(s) to ${schoolCount} school(s)? Items that already exist in the target school will be skipped.`);
}

// Live update selection count
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('item-checkbox')) updateCount();
});

// Update select-all state when individual checkboxes change
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('item-checkbox')) {
        const all = document.querySelectorAll('.item-checkbox');
        const checked = document.querySelectorAll('.item-checkbox:checked');
        document.getElementById('selectAll').checked = all.length === checked.length;
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
