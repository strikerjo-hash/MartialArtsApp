<?php
/**
 * curriculum.php — Curriculum Management
 *
 * Full CRUD for class curriculum entries within a belt testing cycle.
 * Supports inline editing, bulk operations, and cycle rollover.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/belt_cycle.php';
requireLogin();
if (!canView('curriculum.php')) {
    accessDenied('Curriculum management requires appropriate permissions.');
}

$pdo = get_db();
$message = '';
$cycle = get_current_cycle();

// =====================================================================
//  Helper: generate all dates matching a day_of_week within a range
// =====================================================================
function generateClassDatesInCycle(string $dayOfWeek, string $cycleStart, string $cycleEnd): array
{
    $dates = [];
    $current = new DateTime($cycleStart);
    $end = new DateTime($cycleEnd);
    $targetDow = date('N', strtotime($dayOfWeek)); // 1=Mon .. 7=Sun

    while ((int) $current->format('N') !== (int) $targetDow && $current <= $end) {
        $current->modify('+1 day');
    }

    while ($current <= $end) {
        $dates[] = $current->format('Y-m-d');
        $current->modify('+7 days');
    }

    return $dates;
}

// =====================================================================
//  AJAX: inline cell save
// =====================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_cell' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $classId   = (int) ($_POST['class_id'] ?? 0);
    $classDate = $_POST['class_date'] ?? '';
    $field     = $_POST['field'] ?? '';
    $value     = trim($_POST['value'] ?? '');

    if (!$classId || !$classDate || !in_array($field, ['line1', 'line2', 'line3'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        exit;
    }

    try {
        $pdo->prepare("
            INSERT INTO class_curriculum (school_id, class_id, class_date, {$field})
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE {$field} = VALUES({$field}), updated_at = CURRENT_TIMESTAMP
        ")->execute([current_school_id(), $classId, $classDate, $value]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// =====================================================================
//  POST handlers
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();

    // --- Save single entry (add or edit) ---
    if ($_POST['action'] === 'save_entry') {
        $classId   = (int) $_POST['class_id'];
        $classDate = $_POST['class_date'] ?? '';
        $line1     = trim($_POST['line1'] ?? '');
        $line2     = trim($_POST['line2'] ?? '');
        $line3     = trim($_POST['line3'] ?? '');

        if ($classId > 0 && $classDate) {
            $pdo->prepare("
                INSERT INTO class_curriculum (school_id, class_id, class_date, line1, line2, line3)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    line1 = VALUES(line1), line2 = VALUES(line2), line3 = VALUES(line3),
                    updated_at = CURRENT_TIMESTAMP
            ")->execute([current_school_id(), $classId, $classDate, $line1, $line2, $line3]);
            $message = showAlert('Curriculum entry saved.', 'success');
        } else {
            $message = showAlert('Please select a class and date.', 'error');
        }
    }

    // --- Delete single entry ---
    if ($_POST['action'] === 'delete_entry') {
        $entryId = (int) $_POST['entry_id'];
        $params = [$entryId];
        school_param($params);
        $pdo->prepare("DELETE FROM class_curriculum WHERE id = ?" . school_where())
            ->execute($params);
        $message = showAlert('Curriculum entry deleted.', 'success');
    }

    // --- Bulk delete (date range) ---
    if ($_POST['action'] === 'bulk_delete') {
        $classId   = (int) $_POST['class_id'];
        $startDate = $_POST['start_date'] ?? '';
        $endDate   = $_POST['end_date'] ?? '';

        if ($classId > 0 && $startDate && $endDate) {
            $params = [$classId, $startDate, $endDate];
            school_param($params);
            $stmt = $pdo->prepare("
                DELETE FROM class_curriculum
                WHERE class_id = ? AND class_date BETWEEN ? AND ?" . school_where()
            );
            $stmt->execute($params);
            $deleted = $stmt->rowCount();
            $message = showAlert("Deleted {$deleted} curriculum entries.", 'success');
        } else {
            $message = showAlert('Please select a class and date range.', 'error');
        }
    }

    // --- Clear all for a class in current cycle ---
    if ($_POST['action'] === 'clear_class') {
        $classId = (int) $_POST['class_id'];
        if ($classId > 0) {
            $params = [$classId, $cycle['start'], $cycle['end']];
            school_param($params);
            $stmt = $pdo->prepare("
                DELETE FROM class_curriculum
                WHERE class_id = ? AND class_date BETWEEN ? AND ?" . school_where()
            );
            $stmt->execute($params);
            $deleted = $stmt->rowCount();
            $message = showAlert("Cleared {$deleted} curriculum entries for this class in the current cycle.", 'warning');
        }
    }

    // --- Copy week ---
    if ($_POST['action'] === 'copy_week') {
        $classId         = (int) $_POST['class_id'];
        $sourceWeekStart = $_POST['source_week_start'] ?? '';
        $targetWeekStart = $_POST['target_week_start'] ?? '';

        if ($classId > 0 && $sourceWeekStart && $targetWeekStart) {
            $offsetDays = (strtotime($targetWeekStart) - strtotime($sourceWeekStart)) / 86400;
            $sourceEnd = date('Y-m-d', strtotime($sourceWeekStart . ' +6 days'));

            $params = [$classId, $sourceWeekStart, $sourceEnd];
            school_param($params);
            $srcStmt = $pdo->prepare("
                SELECT class_date, line1, line2, line3
                FROM class_curriculum
                WHERE class_id = ? AND class_date BETWEEN ? AND ?" . school_where()
            );
            $srcStmt->execute($params);
            $entries = $srcStmt->fetchAll();

            $upsert = $pdo->prepare("
                INSERT INTO class_curriculum (school_id, class_id, class_date, line1, line2, line3)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    line1 = VALUES(line1), line2 = VALUES(line2), line3 = VALUES(line3),
                    updated_at = CURRENT_TIMESTAMP
            ");

            $count = 0;
            foreach ($entries as $e) {
                $newDate = date('Y-m-d', strtotime($e['class_date'] . " +{$offsetDays} days"));
                $upsert->execute([current_school_id(), $classId, $newDate, $e['line1'], $e['line2'], $e['line3']]);
                $count++;
            }
            $message = showAlert("Copied {$count} entries to target week.", 'success');
        } else {
            $message = showAlert('Please fill in all fields.', 'error');
        }
    }

    // --- Apply to new cycle (rollover) ---
    if ($_POST['action'] === 'apply_to_new_cycle') {
        $oldStart = $cycle['start'];
        $oldEnd   = $cycle['end'];
        $newStart = $_POST['new_cycle_start'] ?? '';
        $newEnd   = $_POST['new_cycle_end'] ?? '';
        $updateSettings = isset($_POST['update_settings']);

        if (!$newStart || !$newEnd || strtotime($newStart) >= strtotime($newEnd)) {
            $message = showAlert('New cycle end date must be after start date.', 'error');
        } else {
            $pdo->beginTransaction();
            try {
                // Fetch all old cycle curriculum joined to classes for day_of_week
                $oldParams = [$oldStart, $oldEnd];
                school_param($oldParams);
                $oldStmt = $pdo->prepare("
                    SELECT cc.class_id, cc.class_date, cc.line1, cc.line2, cc.line3,
                           c.day_of_week
                    FROM class_curriculum cc
                    JOIN classes c ON cc.class_id = c.id
                    WHERE cc.class_date BETWEEN ? AND ?" . school_where('cc') . "
                    ORDER BY cc.class_id, cc.class_date
                ");
                $oldStmt->execute($oldParams);
                $oldRows = $oldStmt->fetchAll();

                // Build pattern map: (class_id, week_offset, day_of_week) => content
                $oldCycleStartTs = strtotime($oldStart);
                $patternMap = [];
                foreach ($oldRows as $row) {
                    $dateTs = strtotime($row['class_date']);
                    $daysDiff = ($dateTs - $oldCycleStartTs) / 86400;
                    $weekNumber = (int) floor($daysDiff / 7);

                    $key = $row['class_id'] . '|' . $weekNumber . '|' . $row['day_of_week'];
                    $patternMap[$key] = [
                        'line1' => $row['line1'],
                        'line2' => $row['line2'],
                        'line3' => $row['line3'],
                    ];
                }

                // Map onto new cycle dates
                $newCycleStartTs = strtotime($newStart);
                $newCycleEndTs   = strtotime($newEnd);

                $upsertStmt = $pdo->prepare("
                    INSERT INTO class_curriculum (school_id, class_id, class_date, line1, line2, line3)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        line1 = VALUES(line1), line2 = VALUES(line2), line3 = VALUES(line3),
                        updated_at = CURRENT_TIMESTAMP
                ");

                $applied = 0;
                $skipped = 0;
                foreach ($patternMap as $key => $content) {
                    [$classId, $weekNumber, $dayOfWeek] = explode('|', $key);

                    $targetWeekStartTs = $newCycleStartTs + ($weekNumber * 7 * 86400);
                    $targetDow = (int) date('N', strtotime($dayOfWeek));
                    $weekStartDow = (int) date('N', $targetWeekStartTs);
                    $dowDiff = $targetDow - $weekStartDow;
                    $targetDateTs = $targetWeekStartTs + ($dowDiff * 86400);

                    if ($targetDateTs >= $newCycleStartTs && $targetDateTs <= $newCycleEndTs) {
                        $targetDate = date('Y-m-d', $targetDateTs);
                        $upsertStmt->execute([
                            current_school_id(),
                            (int) $classId,
                            $targetDate,
                            $content['line1'],
                            $content['line2'],
                            $content['line3'],
                        ]);
                        $applied++;
                    } else {
                        $skipped++;
                    }
                }

                // Optionally update belt testing cycle settings
                if ($updateSettings) {
                    saveSetting('belt_testing_cycle_start_date', $newStart);
                    saveSetting('belt_testing_cycle_end_date', $newEnd);
                }

                $pdo->commit();

                $msg = "Cycle rollover complete: {$applied} curriculum entries applied to new cycle "
                     . formatDate($newStart) . " &ndash; " . formatDate($newEnd) . ".";
                if ($skipped > 0) {
                    $msg .= " ({$skipped} entries skipped — new cycle is shorter)";
                }
                $message = showAlert($msg, 'success');

                // Refresh cycle
                $cycle = get_current_cycle();

            } catch (Exception $e) {
                $pdo->rollBack();
                $message = showAlert('Cycle rollover failed: ' . htmlspecialchars($e->getMessage()), 'error');
            }
        }
    }
}

// =====================================================================
//  Data retrieval
// =====================================================================

// All active classes
$classParams = [];
school_param($classParams);
$allClasses = $pdo->prepare("
    SELECT c.id, c.name, c.day_of_week, c.start_time, c.end_time,
           c.skill_level, mas.name as style_name
    FROM classes c
    LEFT JOIN martial_arts_styles mas ON c.style_id = mas.id
    WHERE c.status = 'active'" . school_where('c') . "
    ORDER BY FIELD(c.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), c.start_time
");
$allClasses->execute($classParams);
$allClasses = $allClasses->fetchAll();

// Selected class
$selectedClassId = (int) ($_GET['class_id'] ?? ($_POST['class_id'] ?? 0));
$selectedClass = null;
foreach ($allClasses as $c) {
    if ((int) $c['id'] === $selectedClassId) {
        $selectedClass = $c;
        break;
    }
}

// Curriculum for selected class in cycle
$curriculumByDate = [];
$classDates = [];
if ($selectedClass) {
    $classDates = generateClassDatesInCycle($selectedClass['day_of_week'], $cycle['start'], $cycle['end']);

    $currParams = [$selectedClassId, $cycle['start'], $cycle['end']];
    school_param($currParams);
    $currStmt = $pdo->prepare("
        SELECT id, class_date, line1, line2, line3
        FROM class_curriculum
        WHERE class_id = ? AND class_date BETWEEN ? AND ?" . school_where() . "
        ORDER BY class_date
    ");
    $currStmt->execute($currParams);
    foreach ($currStmt->fetchAll() as $row) {
        $curriculumByDate[$row['class_date']] = $row;
    }
}

// Summary counts per class (for no-class-selected view)
$classCurrCounts = [];
if (!$selectedClassId) {
    $countParams = [$cycle['start'], $cycle['end']];
    school_param($countParams);
    $countStmt = $pdo->prepare("
        SELECT class_id, COUNT(*) as entry_count
        FROM class_curriculum
        WHERE class_date BETWEEN ? AND ?" . school_where() . "
        GROUP BY class_id
    ");
    $countStmt->execute($countParams);
    foreach ($countStmt->fetchAll() as $row) {
        $classCurrCounts[$row['class_id']] = (int) $row['entry_count'];
    }
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <!-- Title + Tab Bar -->
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Curriculum Management</h1>
        <div class="space-x-3">
            <a href="curriculum.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-blue-600 text-white">Manage Curriculum</a>
            <a href="import_curriculum.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Import from XLSX</a>
            <a href="import_data.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Import Students</a>
            <a href="export_data.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Export</a>
        </div>
    </div>

    <!-- Cycle Info + Class Filter + Actions -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Current Test Cycle</label>
                <div class="text-sm text-gray-600 font-medium">
                    <?php echo formatDate($cycle['start']); ?> &ndash; <?php echo formatDate($cycle['end']); ?>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Class</label>
                <select id="classFilter" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                        onchange="if(this.value) location.href='curriculum.php?class_id='+this.value; else location.href='curriculum.php';">
                    <option value="">-- All Classes (Summary) --</option>
                    <?php foreach ($allClasses as $cls): ?>
                        <option value="<?php echo $cls['id']; ?>" <?php echo $selectedClassId === (int) $cls['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cls['name']); ?> (<?php echo $cls['day_of_week']; ?> <?php echo date('g:i A', strtotime($cls['start_time'])); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex gap-2">
                <?php if ($selectedClassId): ?>
                    <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
                        + Add Entry
                    </button>
                    <button onclick="document.getElementById('copyWeekModal').classList.remove('hidden')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
                        Copy Week
                    </button>
                <?php endif; ?>
            </div>

            <div class="flex gap-2">
                <button onclick="document.getElementById('applyCycleModal').classList.remove('hidden')" class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
                    Apply to New Cycle
                </button>
                <?php if ($selectedClassId): ?>
                    <button onclick="document.getElementById('bulkDeleteModal').classList.remove('hidden')" class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
                        Clear Range
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($selectedClass): ?>
    <!-- ============================================================= -->
    <!--  Calendar Grid for Selected Class                             -->
    <!-- ============================================================= -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">
                    <?php echo htmlspecialchars($selectedClass['name']); ?>
                    <span class="text-sm font-normal text-gray-500 ml-2">
                        <?php echo $selectedClass['day_of_week']; ?>s &bull;
                        <?php echo date('g:i A', strtotime($selectedClass['start_time'])); ?> &ndash;
                        <?php echo date('g:i A', strtotime($selectedClass['end_time'])); ?>
                        <?php if ($selectedClass['style_name']): ?>
                            &bull; <?php echo htmlspecialchars($selectedClass['style_name']); ?>
                        <?php endif; ?>
                    </span>
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    <?php echo count($classDates); ?> class dates in cycle &bull;
                    <?php echo count($curriculumByDate); ?> curriculum entries set
                    <?php if (count($classDates) > count($curriculumByDate)): ?>
                        <span class="text-orange-600">&bull; <?php echo count($classDates) - count($curriculumByDate); ?> dates without curriculum</span>
                    <?php endif; ?>
                </p>
            </div>
            <form method="POST" onsubmit="return confirm('Clear ALL curriculum for this class in the current cycle?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_class">
                <input type="hidden" name="class_id" value="<?php echo $selectedClassId; ?>">
                <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-medium">Clear All</button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-36">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-12">Wk</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Focus</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rotation</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Technique</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-24">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php
                    $cycleStartTs = strtotime($cycle['start']);
                    foreach ($classDates as $date):
                        $entry = $curriculumByDate[$date] ?? null;
                        $daysDiff = (strtotime($date) - $cycleStartTs) / 86400;
                        $weekNum = (int) floor($daysDiff / 7) + 1;
                        $isPast = ($date < date('Y-m-d'));
                        $isToday = ($date === date('Y-m-d'));
                    ?>
                    <tr class="<?php echo $isToday ? 'bg-blue-50 border-l-4 border-blue-500' : ($isPast ? 'bg-gray-50' : ''); ?> hover:bg-gray-100">
                        <td class="px-4 py-3 text-sm font-medium <?php echo $isPast ? 'text-gray-400' : 'text-gray-800'; ?> whitespace-nowrap">
                            <?php echo date('D, M j', strtotime($date)); ?>
                            <?php if ($isToday): ?>
                                <span class="ml-1 px-1.5 py-0.5 bg-blue-600 text-white text-xs rounded">Today</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500"><?php echo $weekNum; ?></td>

                        <!-- Inline-editable cells -->
                        <td class="px-4 py-3 text-sm cursor-pointer editable-cell <?php echo $isPast ? 'text-gray-400' : 'text-gray-700'; ?>"
                            data-class-id="<?php echo $selectedClassId; ?>"
                            data-date="<?php echo $date; ?>"
                            data-field="line1"
                            onclick="makeEditable(this)"
                            title="Click to edit">
                            <?php echo htmlspecialchars($entry['line1'] ?? ''); ?>
                        </td>
                        <td class="px-4 py-3 text-sm cursor-pointer editable-cell <?php echo $isPast ? 'text-gray-400' : 'text-gray-700'; ?>"
                            data-class-id="<?php echo $selectedClassId; ?>"
                            data-date="<?php echo $date; ?>"
                            data-field="line2"
                            onclick="makeEditable(this)"
                            title="Click to edit">
                            <?php echo htmlspecialchars($entry['line2'] ?? ''); ?>
                        </td>
                        <td class="px-4 py-3 text-sm cursor-pointer editable-cell <?php echo $isPast ? 'text-gray-400' : 'text-gray-700'; ?>"
                            data-class-id="<?php echo $selectedClassId; ?>"
                            data-date="<?php echo $date; ?>"
                            data-field="line3"
                            onclick="makeEditable(this)"
                            title="Click to edit">
                            <?php echo htmlspecialchars($entry['line3'] ?? ''); ?>
                        </td>

                        <td class="px-4 py-3 text-sm whitespace-nowrap">
                            <button onclick='openEditModal(<?php echo json_encode([
                                "id" => $entry["id"] ?? 0,
                                "class_id" => $selectedClassId,
                                "class_date" => $date,
                                "line1" => $entry["line1"] ?? "",
                                "line2" => $entry["line2"] ?? "",
                                "line3" => $entry["line3"] ?? "",
                            ]); ?>)' class="text-blue-600 hover:text-blue-800 text-xs font-medium">Edit</button>
                            <?php if ($entry): ?>
                            <form method="POST" class="inline ml-1" onsubmit="return confirm('Delete this entry?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_entry">
                                <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                <button type="submit" class="text-red-600 hover:text-red-800 text-xs font-medium">Del</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php else: ?>
    <!-- ============================================================= -->
    <!--  Summary View: All Classes                                    -->
    <!-- ============================================================= -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">All Classes &mdash; Curriculum Summary</h2>
            <p class="text-sm text-gray-500 mt-1">Select a class to view and edit its curriculum grid.</p>
        </div>

        <?php if (empty($allClasses)): ?>
            <div class="p-12 text-center text-gray-500">
                <p class="text-lg">No active classes found.</p>
                <a href="classes.php" class="text-blue-600 hover:text-blue-800 mt-2 inline-block">Manage Classes</a>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Day</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Style</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Curriculum Entries</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Class Dates in Cycle</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Coverage</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($allClasses as $cls):
                            $cDates = generateClassDatesInCycle($cls['day_of_week'], $cycle['start'], $cycle['end']);
                            $totalDates = count($cDates);
                            $entryCount = $classCurrCounts[$cls['id']] ?? 0;
                            $pct = $totalDates > 0 ? round($entryCount / $totalDates * 100) : 0;
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($cls['name']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo $cls['day_of_week']; ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo date('g:i A', strtotime($cls['start_time'])); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($cls['style_name'] ?? '—'); ?></td>
                            <td class="px-6 py-4 text-sm text-center font-semibold <?php echo $entryCount > 0 ? 'text-green-700' : 'text-gray-400'; ?>">
                                <?php echo $entryCount; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-center text-gray-600"><?php echo $totalDates; ?></td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 bg-gray-200 rounded-full h-2 w-24">
                                        <div class="h-2 rounded-full <?php echo $pct >= 80 ? 'bg-green-500' : ($pct >= 40 ? 'bg-yellow-500' : 'bg-red-400'); ?>"
                                             style="width: <?php echo $pct; ?>%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500"><?php echo $pct; ?>%</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <a href="curriculum.php?class_id=<?php echo $cls['id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                    Manage
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ================================================================= -->
<!--  MODALS                                                           -->
<!-- ================================================================= -->

<!-- Add Entry Modal -->
<div id="addModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add Curriculum Entry</h3>
            <button onclick="document.getElementById('addModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_entry">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Class</label>
                <select name="class_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <?php foreach ($allClasses as $cls): ?>
                        <option value="<?php echo $cls['id']; ?>" <?php echo $selectedClassId === (int) $cls['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cls['name']); ?> (<?php echo $cls['day_of_week']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                <input type="date" name="class_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                       value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Focus (Line 1)</label>
                <input type="text" name="line1" maxlength="500" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="e.g. Kicks">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Rotation (Line 2)</label>
                <input type="text" name="line2" maxlength="500" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="e.g. Drill A / Drill B">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Technique (Line 3)</label>
                <input type="text" name="line3" maxlength="500" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="e.g. Side Kick Combo">
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm">Cancel</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg text-sm font-medium">Save Entry</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Entry Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit Curriculum Entry</h3>
            <button onclick="document.getElementById('editModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_entry">
            <input type="hidden" id="edit_class_id" name="class_id" value="">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                <input type="date" id="edit_class_date" name="class_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-gray-50" readonly>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Focus (Line 1)</label>
                <input type="text" id="edit_line1" name="line1" maxlength="500" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Rotation (Line 2)</label>
                <input type="text" id="edit_line2" name="line2" maxlength="500" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Technique (Line 3)</label>
                <input type="text" id="edit_line3" name="line3" maxlength="500" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm">Cancel</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg text-sm font-medium">Update Entry</button>
            </div>
        </form>
    </div>
</div>

<!-- Copy Week Modal -->
<div id="copyWeekModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Copy Week</h3>
            <button onclick="document.getElementById('copyWeekModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="copy_week">
            <input type="hidden" name="class_id" value="<?php echo $selectedClassId; ?>">
            <p class="text-sm text-gray-600">Copy all curriculum entries from one week to another for this class. Existing entries on the target week will be overwritten.</p>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Source Week Start (Monday)</label>
                <input type="date" name="source_week_start" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Target Week Start (Monday)</label>
                <input type="date" name="target_week_start" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('copyWeekModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm">Cancel</button>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg text-sm font-medium">Copy Week</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Delete Modal -->
<div id="bulkDeleteModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Clear Curriculum Range</h3>
            <button onclick="document.getElementById('bulkDeleteModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4" onsubmit="return confirm('This will permanently delete all curriculum entries in this date range. Continue?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="class_id" value="<?php echo $selectedClassId; ?>">
            <p class="text-sm text-gray-600">Delete all curriculum entries for this class within the selected date range.</p>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                <input type="date" name="start_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                       value="<?php echo $cycle['start']; ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                <input type="date" name="end_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                       value="<?php echo $cycle['end']; ?>">
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('bulkDeleteModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm">Cancel</button>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg text-sm font-medium">Delete Entries</button>
            </div>
        </form>
    </div>
</div>

<!-- Apply to New Cycle Modal -->
<div id="applyCycleModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Apply Curriculum to New Cycle</h3>
            <button onclick="document.getElementById('applyCycleModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="apply_to_new_cycle">

            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p class="text-sm text-blue-800">This will take all curriculum entries from the <strong>current cycle</strong> and replicate them onto the new cycle dates, matching by week number and day of week. Existing entries on the new dates will be overwritten.</p>
            </div>

            <div class="bg-gray-50 rounded-lg p-3">
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Current Cycle (Source)</label>
                <div class="text-sm text-gray-700 font-medium">
                    <?php echo formatDate($cycle['start']); ?> &ndash; <?php echo formatDate($cycle['end']); ?>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">New Cycle Start Date</label>
                <input type="date" name="new_cycle_start" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                       value="<?php echo date('Y-m-d', strtotime($cycle['end'] . ' +1 day')); ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">New Cycle End Date</label>
                <input type="date" name="new_cycle_end" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                       value="<?php echo date('Y-m-d', strtotime($cycle['end'] . ' +4 months')); ?>">
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="update_settings" value="1" checked class="rounded border-gray-300 text-blue-600">
                <span>Also update belt testing cycle dates in Settings</span>
            </label>

            <div class="flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('applyCycleModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm">Cancel</button>
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg text-sm font-medium">Apply to New Cycle</button>
            </div>
        </form>
    </div>
</div>

<!-- ================================================================= -->
<!--  JavaScript                                                       -->
<!-- ================================================================= -->
<script>
// Inline cell editing
function makeEditable(cell) {
    if (cell.querySelector('input')) return;

    var currentValue = cell.textContent.trim();
    var classId = cell.dataset.classId;
    var dateVal = cell.dataset.date;
    var field = cell.dataset.field;

    var input = document.createElement('input');
    input.type = 'text';
    input.value = currentValue;
    input.className = 'w-full px-2 py-1 border border-blue-400 rounded text-sm focus:outline-none focus:ring-1 focus:ring-blue-500';
    input.maxLength = 500;

    cell.textContent = '';
    cell.appendChild(input);
    input.focus();
    input.select();

    function saveValue() {
        var newValue = input.value.trim();
        cell.textContent = newValue;

        var formData = new FormData();
        formData.append('class_id', classId);
        formData.append('class_date', dateVal);
        formData.append('field', field);
        formData.append('value', newValue);

        fetch('curriculum.php?ajax=save_cell', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                cell.textContent = currentValue;
                alert('Save failed: ' + (data.error || 'Unknown error'));
            } else {
                // Flash green briefly
                cell.style.backgroundColor = '#d1fae5';
                setTimeout(function() { cell.style.backgroundColor = ''; }, 600);
            }
        })
        .catch(function() {
            cell.textContent = currentValue;
            alert('Network error — could not save.');
        });
    }

    input.addEventListener('blur', saveValue);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { cell.textContent = currentValue; }
    });
}

// Edit modal
function openEditModal(data) {
    document.getElementById('edit_class_id').value = data.class_id;
    document.getElementById('edit_class_date').value = data.class_date;
    document.getElementById('edit_line1').value = data.line1 || '';
    document.getElementById('edit_line2').value = data.line2 || '';
    document.getElementById('edit_line3').value = data.line3 || '';
    document.getElementById('editModal').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
