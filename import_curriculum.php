<?php
/**
 * import_curriculum.php — Import class curriculum schedules and student assignments from XLSX
 *
 * 4-step wizard:
 *   Step 1: Upload XLSX file
 *   Step 2: Parse & map sheets to classes
 *   Step 3: Preview & confirm (dry run)
 *   Step 4: Execute & results
 */

require_once 'config.php';
require_once __DIR__ . '/includes/security.php';
requireLogin();
if (!canView('import_data.php')) {
    accessDenied('Import requires appropriate permissions.');
}

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

$pdo = get_db();
$message = '';

// =====================================================================
//  Helper Functions
// =====================================================================

/**
 * Convert an Excel serial date number to Y-m-d string.
 */
function parseExcelDate($value): ?string
{
    if ($value === null || $value === '') return null;

    // "TESTING" or non-date text
    if (!is_numeric($value)) {
        if (strtoupper(trim((string)$value)) === 'TESTING') return null;
        $ts = strtotime((string)$value);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    $num = (float)$value;
    if ($num < 40000 || $num > 60000) return null;

    try {
        $dt = ExcelDate::excelToDateTimeObject($num);
        return $dt->format('Y-m-d');
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Detect whether a sheet uses Pattern A (has curriculum rows) or Pattern B.
 *
 * Pattern A: Row 5 (index 4) has "First Name" in cell 0 AND rows 2-4 (indices 1-3)
 *            have non-empty content starting from column 2.
 * Pattern B: Row 2 (index 1) has "First Name" in cell 0.
 */
function detectSheetPattern(array $data): string
{
    // Check if row index 4 (row 5) starts with "First Name"
    $row5 = $data[4] ?? [];
    if (isset($row5[0]) && strtolower(trim((string)$row5[0])) === 'first name') {
        // Check if rows 2-4 (indices 1-3) have curriculum content in columns 2+
        for ($r = 1; $r <= 3; $r++) {
            $row = $data[$r] ?? [];
            for ($c = 2; $c < count($row); $c++) {
                $v = trim((string)($row[$c] ?? ''));
                if ($v !== '' && strtoupper($v) !== 'TESTING') {
                    return 'A';
                }
            }
        }
    }

    return 'B';
}

/**
 * Parse a Pattern A sheet (with curriculum rows) into structured data.
 */
function parsePatternASheet(array $data): array
{
    $result = [
        'title'     => trim((string)($data[0][0] ?? '')),
        'pattern'   => 'A',
        'curriculum' => [],
        'students'  => [],
        'dates'     => [],
        'day_of_week' => null,
        'has_testing' => false,
    ];

    $headerRow = $data[4] ?? [];
    $currRow1  = $data[1] ?? [];
    $currRow2  = $data[2] ?? [];
    $currRow3  = $data[3] ?? [];

    // Parse dates from header row (columns 2+)
    $dateColumns = [];
    for ($col = 2; $col < count($headerRow); $col++) {
        $val = $headerRow[$col] ?? null;
        if (is_string($val) && strtoupper(trim($val)) === 'TESTING') {
            $result['has_testing'] = true;
            continue;
        }
        $date = parseExcelDate($val);
        if ($date) {
            $dateColumns[$col] = $date;
            $result['dates'][] = $date;
        }
    }

    // Detect day of week from first date
    if (!empty($result['dates'])) {
        $result['day_of_week'] = date('l', strtotime($result['dates'][0]));
    }

    // Build curriculum entries per date
    foreach ($dateColumns as $col => $date) {
        $line1 = trim((string)($currRow1[$col] ?? ''));
        $line2 = trim((string)($currRow2[$col] ?? ''));
        $line3 = trim((string)($currRow3[$col] ?? ''));

        // Skip "TESTING" values
        if (strtoupper($line1) === 'TESTING') $line1 = '';
        if (strtoupper($line2) === 'TESTING') $line2 = '';
        if (strtoupper($line3) === 'TESTING') $line3 = '';

        if ($line1 !== '' || $line2 !== '' || $line3 !== '') {
            $result['curriculum'][$date] = [
                'line1' => $line1 ?: null,
                'line2' => $line2 ?: null,
                'line3' => $line3 ?: null,
            ];
        }
    }

    // Parse students (rows 7+ i.e. index 6+, skip "Instructor Initial" row at index 5)
    for ($row = 6; $row < count($data); $row++) {
        $firstName = trim((string)($data[$row][0] ?? ''));
        $lastName  = trim((string)($data[$row][1] ?? ''));
        if ($firstName !== '' && strtolower($firstName) !== 'instructor initial') {
            $result['students'][] = [
                'first_name' => $firstName,
                'last_name'  => $lastName,
            ];
        }
    }

    return $result;
}

/**
 * Parse a Pattern B sheet into one or more class blocks.
 * Detects multi-schedule sheets by finding multiple "First Name" header rows.
 */
function parsePatternBSheet(array $data): array
{
    $blocks = [];
    $title = trim((string)($data[0][0] ?? ''));
    $currentBlock = null;

    for ($rowIdx = 1; $rowIdx < count($data); $rowIdx++) {
        $row = $data[$rowIdx] ?? [];
        $cell0 = strtolower(trim((string)($row[0] ?? '')));

        if ($cell0 === 'first name') {
            // Save previous block
            if ($currentBlock !== null) {
                $blocks[] = $currentBlock;
            }

            // Start new block
            $currentBlock = [
                'title'       => $title,
                'pattern'     => 'B',
                'curriculum'  => [],
                'students'    => [],
                'dates'       => [],
                'day_of_week' => null,
                'has_testing' => false,
            ];

            // Parse dates from this header row (columns 2+)
            for ($col = 2; $col < count($row); $col++) {
                $val = $row[$col] ?? null;
                if (is_string($val) && strtoupper(trim($val)) === 'TESTING') {
                    $currentBlock['has_testing'] = true;
                    continue;
                }
                $date = parseExcelDate($val);
                if ($date) {
                    $currentBlock['dates'][] = $date;
                }
            }

            // Detect day of week
            if (!empty($currentBlock['dates'])) {
                $currentBlock['day_of_week'] = date('l', strtotime($currentBlock['dates'][0]));
            }
        } elseif ($currentBlock !== null) {
            $firstName = trim((string)($row[0] ?? ''));
            $lastName  = trim((string)($row[1] ?? ''));
            if ($firstName !== '' && strtolower($firstName) !== 'instructor initial') {
                $currentBlock['students'][] = [
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                ];
            }
        }
    }

    // Save last block
    if ($currentBlock !== null) {
        $blocks[] = $currentBlock;
    }

    // Add schedule labels if multiple blocks
    if (count($blocks) > 1) {
        foreach ($blocks as &$block) {
            $block['schedule_label'] = ($block['day_of_week'] ?? 'Unknown') . 's';
        }
        unset($block);
    }

    return $blocks;
}

/**
 * Get all active classes for the current school.
 */
function getActiveClasses(PDO $pdo): array
{
    $params = [];
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.day_of_week, c.start_time, c.end_time, c.skill_level,
               mas.name as style_name
        FROM classes c
        LEFT JOIN martial_arts_styles mas ON c.style_id = mas.id
        WHERE c.status = 'active'" . school_where('c') . "
        ORDER BY c.name, c.day_of_week
    ");
    school_param($params);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Fuzzy-match a sheet title + optional day to an existing class.
 */
function fuzzyMatchClass(string $sheetTitle, ?string $dayOfWeek, array $classes): ?int
{
    $sheetTitle = strtolower(trim($sheetTitle));
    $bestMatch = null;
    $bestScore = 0;

    foreach ($classes as $class) {
        $className = strtolower($class['name']);

        // Calculate similarity
        similar_text($sheetTitle, $className, $pct);

        // Bonus if day of week matches
        if ($dayOfWeek && strtolower($class['day_of_week']) === strtolower($dayOfWeek)) {
            $pct += 15;
        }

        if ($pct > $bestScore) {
            $bestScore = $pct;
            $bestMatch = (int)$class['id'];
        }
    }

    return ($bestScore > 35) ? $bestMatch : null;
}

/**
 * Match a student name to the students table (case-insensitive).
 */
function matchStudentByName(PDO $pdo, string $firstName, string $lastName): ?int
{
    $params = [trim($firstName), trim($lastName)];
    school_param($params);
    $stmt = $pdo->prepare(
        "SELECT id FROM students
         WHERE LOWER(TRIM(first_name)) = LOWER(?)
         AND LOWER(TRIM(last_name)) = LOWER(?)
         AND status = 'active'" . school_where() . "
         LIMIT 1"
    );
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

// =====================================================================
//  Determine current step
// =====================================================================

$step = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step'])) {
    $step = (int)$_POST['step'];
}
if (isset($_GET['step'])) {
    $step = (int)$_GET['step'];
}

// =====================================================================
//  POST handlers
// =====================================================================

// --- Step 1: Upload & Parse ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1) {
    verify_csrf();

    if (empty($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
        $message = showAlert('Please select a valid XLSX file to upload.', 'error');
    } else {
        $tmpFile = $_FILES['xlsx_file']['tmp_name'];
        $origName = basename($_FILES['xlsx_file']['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($ext !== 'xlsx') {
            $message = showAlert('Only .xlsx files are supported.', 'error');
        } else {
            try {
                $spreadsheet = IOFactory::load($tmpFile);
                $sheetNames = $spreadsheet->getSheetNames();
                $parsedBlocks = [];

                foreach ($sheetNames as $idx => $name) {
                    $sheet = $spreadsheet->getSheet($idx);
                    $data = $sheet->toArray(null, false, false, false);

                    // Remove completely empty rows
                    $data = array_values(array_filter($data, function ($row) {
                        return !empty(array_filter($row, function ($v) { return $v !== null && $v !== ''; }));
                    }));

                    if (count($data) < 2) continue;

                    $pattern = detectSheetPattern($data);

                    if ($pattern === 'A') {
                        $block = parsePatternASheet($data);
                        $block['sheet_name'] = $name;
                        $block['block_key'] = 'sheet_' . $idx;
                        $parsedBlocks[] = $block;
                    } else {
                        $blocks = parsePatternBSheet($data);
                        foreach ($blocks as $bIdx => $block) {
                            $block['sheet_name'] = $name;
                            $block['block_key'] = 'sheet_' . $idx . '_' . $bIdx;
                            $parsedBlocks[] = $block;
                        }
                    }
                }

                if (empty($parsedBlocks)) {
                    $message = showAlert('No valid class data found in the uploaded file.', 'error');
                } else {
                    $_SESSION['curriculum_import'] = [
                        'filename' => $origName,
                        'blocks'   => $parsedBlocks,
                    ];
                    $step = 2;
                }
            } catch (\Throwable $e) {
                $message = showAlert('Error reading XLSX file: ' . htmlspecialchars($e->getMessage()), 'error');
            }
        }
    }
}

// --- Step 2: Save mappings, advance to step 3 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    verify_csrf();

    if (empty($_SESSION['curriculum_import']['blocks'])) {
        $message = showAlert('Session expired. Please re-upload the file.', 'error');
        $step = 1;
    } else {
        $mappings = [];
        foreach ($_SESSION['curriculum_import']['blocks'] as $idx => $block) {
            $key = $block['block_key'];
            $skip = !empty($_POST['skip_' . $key]);
            $classId = (int)($_POST['class_' . $key] ?? 0);
            $importCurriculum = !empty($_POST['curriculum_' . $key]);
            $importStudents = !empty($_POST['students_' . $key]);

            $mappings[$idx] = [
                'skip'              => $skip,
                'class_id'          => $classId,
                'import_curriculum' => $importCurriculum,
                'import_students'   => $importStudents,
            ];
        }
        $_SESSION['curriculum_import']['mappings'] = $mappings;
    }
}

// --- Step 4: Execute import ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 4) {
    verify_csrf();

    if (empty($_SESSION['curriculum_import']['blocks']) || empty($_SESSION['curriculum_import']['mappings'])) {
        $message = showAlert('Session expired. Please re-upload the file.', 'error');
        $step = 1;
    } else {
        $blocks   = $_SESSION['curriculum_import']['blocks'];
        $mappings = $_SESSION['curriculum_import']['mappings'];
        $importResults = [
            'curriculum_created' => 0,
            'students_enrolled'  => 0,
            'students_existing'  => 0,
            'students_not_found' => 0,
            'not_found_names'    => [],
            'classes_imported'   => 0,
        ];

        try {
            $pdo->beginTransaction();

            $currStmt = $pdo->prepare("
                INSERT INTO class_curriculum (school_id, class_id, class_date, line1, line2, line3)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE line1 = VALUES(line1), line2 = VALUES(line2), line3 = VALUES(line3),
                                        updated_at = CURRENT_TIMESTAMP
            ");

            $enrollStmt = $pdo->prepare("
                INSERT INTO class_enrollments (school_id, student_id, class_id, enrollment_date, status)
                VALUES (?, ?, ?, CURDATE(), 'active')
                ON DUPLICATE KEY UPDATE status = 'active'
            ");

            $checkEnrollStmt = $pdo->prepare("
                SELECT id FROM class_enrollments
                WHERE student_id = ? AND class_id = ? AND status = 'active'" . school_where() . "
                LIMIT 1
            ");

            foreach ($blocks as $idx => $block) {
                $map = $mappings[$idx] ?? null;
                if (!$map || $map['skip'] || $map['class_id'] <= 0) continue;

                $classId = $map['class_id'];
                $importResults['classes_imported']++;

                // Import curriculum
                if ($map['import_curriculum'] && !empty($block['curriculum'])) {
                    foreach ($block['curriculum'] as $date => $lines) {
                        $currStmt->execute([
                            current_school_id(),
                            $classId,
                            $date,
                            $lines['line1'],
                            $lines['line2'],
                            $lines['line3'],
                        ]);
                        $importResults['curriculum_created']++;
                    }
                }

                // Import student enrollments
                if ($map['import_students'] && !empty($block['students'])) {
                    foreach ($block['students'] as $student) {
                        $studentId = matchStudentByName($pdo, $student['first_name'], $student['last_name']);
                        if (!$studentId) {
                            $importResults['students_not_found']++;
                            $importResults['not_found_names'][] = $student['first_name'] . ' ' . $student['last_name'] . ' (' . $block['title'] . ')';
                            continue;
                        }

                        // Check if already enrolled
                        $checkParams = [$studentId, $classId];
                        school_param($checkParams);
                        $checkEnrollStmt->execute($checkParams);
                        if ($checkEnrollStmt->fetch()) {
                            $importResults['students_existing']++;
                        } else {
                            $enrollStmt->execute([current_school_id(), $studentId, $classId]);
                            $importResults['students_enrolled']++;
                        }
                    }
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $message = showAlert('Import failed: ' . htmlspecialchars($e->getMessage()), 'error');
            $step = 3;
        }

        // Store results for display
        $_SESSION['curriculum_import']['results'] = $importResults;
    }
}

// =====================================================================
//  Prepare data for display
// =====================================================================

$dbClasses = getActiveClasses($pdo);

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Import Curriculum</h1>
        <div class="space-x-3">
            <a href="curriculum.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Manage Curriculum</a>
            <a href="import_data.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Import Students</a>
            <a href="import_payments.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Import Payments</a>
            <a href="import_curriculum.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-blue-600 text-white">Import Curriculum</a>
            <a href="export_data.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Export</a>
        </div>
    </div>

    <!-- Step Progress Bar -->
    <div class="flex items-center mb-8">
        <?php
        $steps = ['Upload XLSX', 'Map Classes', 'Preview', 'Results'];
        foreach ($steps as $idx => $label):
            $sNum = $idx + 1;
            $active = ($step === $sNum);
            $done   = ($step > $sNum);
        ?>
        <div class="flex items-center <?php echo $idx > 0 ? 'flex-1' : ''; ?>">
            <?php if ($idx > 0): ?>
                <div class="flex-1 h-1 mx-2 <?php echo $done ? 'bg-blue-500' : 'bg-gray-300'; ?>"></div>
            <?php endif; ?>
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold
                    <?php echo $active ? 'bg-blue-600 text-white' : ($done ? 'bg-green-500 text-white' : 'bg-gray-300 text-gray-600'); ?>">
                    <?php echo $done ? '&#10003;' : $sNum; ?>
                </div>
                <span class="text-sm font-medium <?php echo $active ? 'text-blue-600' : 'text-gray-500'; ?>"><?php echo $label; ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

<?php // =============================================================
      //  STEP 1 — Upload XLSX
      // =============================================================
if ($step === 1): ?>

    <div class="bg-white rounded-lg shadow-lg p-8 max-w-3xl mx-auto">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Upload Class Schedule Spreadsheet</h2>
        <p class="text-gray-600 mb-6">Upload the XLSX spreadsheet containing class curricula and student rosters. Each sheet in the workbook represents one class.</p>

        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-blue-800 mb-2">Supported Spreadsheet Format</h3>
            <ul class="text-sm text-blue-700 space-y-1">
                <li>&#8226; Each sheet = one class (e.g., "Warrior Beginner", "Ninjas")</li>
                <li>&#8226; Optional curriculum rows at the top (focus areas per date)</li>
                <li>&#8226; "First Name / Last Name" header row followed by student roster</li>
                <li>&#8226; Date columns with Excel date values</li>
                <li>&#8226; Multi-schedule sheets (e.g., Ninjas meeting Mon + Thu) are auto-detected</li>
            </ul>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="1">

            <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-blue-400 transition">
                <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                </svg>
                <input type="file" name="xlsx_file" accept=".xlsx" required
                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                <p class="text-xs text-gray-500 mt-2">Only .xlsx files are accepted</p>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg font-medium">
                    Upload &amp; Parse &raquo;
                </button>
            </div>
        </form>
    </div>

<?php // =============================================================
      //  STEP 2 — Map Sheets to Classes
      // =============================================================
elseif ($step === 2):
    $importData = $_SESSION['curriculum_import'] ?? null;
    if (!$importData):
?>
    <div class="bg-white rounded-lg shadow p-8 text-center">
        <p class="text-gray-600">Session expired. Please re-upload the file.</p>
        <a href="import_curriculum.php" class="mt-4 inline-block bg-blue-600 text-white px-6 py-2 rounded-lg">Start Over</a>
    </div>
<?php else:
    $blocks = $importData['blocks'];
?>

    <form method="POST" class="space-y-6">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="3">

        <div class="bg-white rounded-lg shadow p-4 mb-4">
            <p class="text-sm text-gray-600">
                <strong>File:</strong> <?= htmlspecialchars($importData['filename']) ?> &mdash;
                <strong><?= count($blocks) ?></strong> class block(s) detected
            </p>
        </div>

        <?php foreach ($blocks as $idx => $block):
            $key = $block['block_key'];
            $dateCount = count($block['dates']);
            $studentCount = count($block['students']);
            $currCount = count($block['curriculum']);
            $scheduleLabel = $block['schedule_label'] ?? null;
            $preMatched = fuzzyMatchClass($block['title'], $block['day_of_week'], $dbClasses);
        ?>
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold text-gray-800">
                        <?= htmlspecialchars($block['title']) ?>
                        <?php if ($scheduleLabel): ?>
                            <span class="ml-2 px-2 py-1 bg-blue-100 text-blue-700 text-xs font-medium rounded-full"><?= htmlspecialchars($scheduleLabel) ?></span>
                        <?php endif; ?>
                    </h3>
                    <div class="text-sm text-gray-500 mt-1">
                        Pattern <?= $block['pattern'] ?> &bull;
                        <?= $block['day_of_week'] ?? 'Unknown day' ?> &bull;
                        <?= $dateCount ?> date(s) &bull;
                        <?= $studentCount ?> student(s) &bull;
                        <?= $currCount ?> curriculum entri<?= $currCount === 1 ? 'y' : 'es' ?>
                        <?php if (!empty($block['dates'])): ?>
                            &bull; <?= date('M j', strtotime($block['dates'][0])) ?> &ndash; <?= date('M j, Y', strtotime(end($block['dates']))) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="skip_<?= $key ?>" value="1"
                           onchange="toggleBlock(this, '<?= $key ?>')"
                           class="rounded border-gray-300 text-red-600">
                    <span class="text-gray-600">Skip</span>
                </label>
            </div>

            <div id="block_<?= $key ?>" class="p-6 space-y-4">
                <!-- Class Mapping -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Map to Class</label>
                        <select name="class_<?= $key ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="0">-- Select a class --</option>
                            <?php foreach ($dbClasses as $cls): ?>
                                <option value="<?= $cls['id'] ?>" <?= $preMatched === (int)$cls['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cls['name']) ?> (<?= $cls['day_of_week'] ?> <?= date('g:i A', strtotime($cls['start_time'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end gap-4">
                        <?php if ($currCount > 0): ?>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="curriculum_<?= $key ?>" value="1" checked
                                   class="rounded border-gray-300 text-blue-600">
                            <span>Import Curriculum (<?= $currCount ?>)</span>
                        </label>
                        <?php endif; ?>
                        <?php if ($studentCount > 0): ?>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="students_<?= $key ?>" value="1"
                                   class="rounded border-gray-300 text-blue-600">
                            <span>Import Enrollments (<?= $studentCount ?>)</span>
                        </label>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($currCount > 0): ?>
                <!-- Curriculum Preview -->
                <details class="border border-gray-200 rounded-lg">
                    <summary class="px-4 py-2 bg-gray-50 text-sm font-medium text-gray-700 cursor-pointer hover:bg-gray-100">
                        Preview Curriculum (<?= $currCount ?> entries)
                    </summary>
                    <div class="overflow-x-auto max-h-64 overflow-y-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="px-3 py-2 text-left text-gray-500">Date</th>
                                    <th class="px-3 py-2 text-left text-gray-500">Line 1 (Focus)</th>
                                    <th class="px-3 py-2 text-left text-gray-500">Line 2 (Rotation)</th>
                                    <th class="px-3 py-2 text-left text-gray-500">Line 3 (Technique)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($block['curriculum'] as $date => $lines): ?>
                                <tr>
                                    <td class="px-3 py-1 text-gray-700 whitespace-nowrap"><?= date('M j', strtotime($date)) ?> <span class="text-gray-400">(<?= date('D', strtotime($date)) ?>)</span></td>
                                    <td class="px-3 py-1 text-gray-700"><?= htmlspecialchars($lines['line1'] ?? '') ?></td>
                                    <td class="px-3 py-1 text-gray-700"><?= htmlspecialchars($lines['line2'] ?? '') ?></td>
                                    <td class="px-3 py-1 text-gray-700"><?= htmlspecialchars($lines['line3'] ?? '') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
                <?php endif; ?>

                <?php if ($studentCount > 0): ?>
                <!-- Student Preview -->
                <details class="border border-gray-200 rounded-lg">
                    <summary class="px-4 py-2 bg-gray-50 text-sm font-medium text-gray-700 cursor-pointer hover:bg-gray-100">
                        Preview Students (<?= $studentCount ?>)
                    </summary>
                    <div class="p-4">
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($block['students'] as $s): ?>
                                <span class="px-2 py-1 bg-gray-100 rounded text-xs text-gray-700"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="flex justify-between">
            <a href="import_curriculum.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-3 rounded-lg font-medium">
                &laquo; Start Over
            </a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg font-medium">
                Preview Import &raquo;
            </button>
        </div>
    </form>

<?php endif; ?>

<?php // =============================================================
      //  STEP 3 — Preview & Confirm (Dry Run)
      // =============================================================
elseif ($step === 3):
    $importData = $_SESSION['curriculum_import'] ?? null;
    if (!$importData || empty($importData['mappings'])):
?>
    <div class="bg-white rounded-lg shadow p-8 text-center">
        <p class="text-gray-600">Session expired. Please re-upload the file.</p>
        <a href="import_curriculum.php" class="mt-4 inline-block bg-blue-600 text-white px-6 py-2 rounded-lg">Start Over</a>
    </div>
<?php else:
    $blocks   = $importData['blocks'];
    $mappings = $importData['mappings'];

    // Build class ID → name lookup
    $classLookup = [];
    foreach ($dbClasses as $c) {
        $classLookup[$c['id']] = $c['name'] . ' (' . $c['day_of_week'] . ')';
    }

    // Dry run summary
    $totalCurr = 0;
    $totalStudentsMatched = 0;
    $totalStudentsNotFound = 0;
    $totalStudentsExisting = 0;
    $unmatchedNames = [];
    $classImports = [];

    foreach ($blocks as $idx => $block) {
        $map = $mappings[$idx] ?? null;
        if (!$map || $map['skip'] || $map['class_id'] <= 0) continue;

        $classId = $map['class_id'];
        $className = $classLookup[$classId] ?? "Class #{$classId}";
        $currCount = 0;
        $matched = 0;
        $notFound = 0;
        $existing = 0;

        if ($map['import_curriculum'] && !empty($block['curriculum'])) {
            $currCount = count($block['curriculum']);
            $totalCurr += $currCount;
        }

        if ($map['import_students'] && !empty($block['students'])) {
            foreach ($block['students'] as $s) {
                $studentId = matchStudentByName($pdo, $s['first_name'], $s['last_name']);
                if (!$studentId) {
                    $notFound++;
                    $unmatchedNames[] = $s['first_name'] . ' ' . $s['last_name'] . ' (' . $block['title'] . ')';
                } else {
                    // Check if already enrolled
                    $checkParams = [$studentId, $classId];
                    school_param($checkParams);
                    $checkStmt = $pdo->prepare(
                        "SELECT id FROM class_enrollments WHERE student_id = ? AND class_id = ? AND status = 'active'" . school_where() . " LIMIT 1"
                    );
                    $checkStmt->execute($checkParams);
                    if ($checkStmt->fetch()) {
                        $existing++;
                    } else {
                        $matched++;
                    }
                }
            }
        }

        $totalStudentsMatched += $matched;
        $totalStudentsNotFound += $notFound;
        $totalStudentsExisting += $existing;

        $classImports[] = [
            'class_name' => $className,
            'block_title' => $block['title'] . (isset($block['schedule_label']) ? ' — ' . $block['schedule_label'] : ''),
            'curriculum'  => $currCount,
            'matched'     => $matched,
            'existing'    => $existing,
            'not_found'   => $notFound,
        ];
    }
?>

    <div class="bg-white rounded-lg shadow-lg p-8 max-w-4xl mx-auto">
        <h2 class="text-xl font-bold text-gray-800 mb-6">Import Preview</h2>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-indigo-50 rounded-lg p-4 text-center">
                <div class="text-3xl font-bold text-indigo-700"><?= $totalCurr ?></div>
                <div class="text-sm text-indigo-600">Curriculum Entries</div>
            </div>
            <div class="bg-green-50 rounded-lg p-4 text-center">
                <div class="text-3xl font-bold text-green-700"><?= $totalStudentsMatched ?></div>
                <div class="text-sm text-green-600">New Enrollments</div>
            </div>
            <div class="bg-gray-50 rounded-lg p-4 text-center">
                <div class="text-3xl font-bold text-gray-500"><?= $totalStudentsExisting ?></div>
                <div class="text-sm text-gray-500">Already Enrolled</div>
            </div>
            <div class="bg-red-50 rounded-lg p-4 text-center">
                <div class="text-3xl font-bold text-red-600"><?= $totalStudentsNotFound ?></div>
                <div class="text-sm text-red-500">Students Not Found</div>
            </div>
        </div>

        <!-- Per-class breakdown -->
        <h3 class="text-lg font-semibold text-gray-800 mb-3">Import Breakdown by Class</h3>
        <div class="overflow-x-auto mb-6">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-gray-500">Sheet</th>
                        <th class="px-4 py-2 text-left text-gray-500">Mapped To</th>
                        <th class="px-4 py-2 text-center text-gray-500">Curriculum</th>
                        <th class="px-4 py-2 text-center text-gray-500">New Enrollments</th>
                        <th class="px-4 py-2 text-center text-gray-500">Already Enrolled</th>
                        <th class="px-4 py-2 text-center text-gray-500">Not Found</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($classImports as $ci): ?>
                    <tr>
                        <td class="px-4 py-2 text-gray-700"><?= htmlspecialchars($ci['block_title']) ?></td>
                        <td class="px-4 py-2 text-gray-700 font-medium"><?= htmlspecialchars($ci['class_name']) ?></td>
                        <td class="px-4 py-2 text-center"><?= $ci['curriculum'] ?: '—' ?></td>
                        <td class="px-4 py-2 text-center text-green-700"><?= $ci['matched'] ?: '—' ?></td>
                        <td class="px-4 py-2 text-center text-gray-400"><?= $ci['existing'] ?: '—' ?></td>
                        <td class="px-4 py-2 text-center text-red-600"><?= $ci['not_found'] ?: '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($unmatchedNames)): ?>
        <details class="border border-red-200 rounded-lg mb-6">
            <summary class="px-4 py-2 bg-red-50 text-sm font-medium text-red-700 cursor-pointer hover:bg-red-100">
                Unmatched Students (<?= count($unmatchedNames) ?>) — will be skipped
            </summary>
            <div class="p-4">
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($unmatchedNames as $name): ?>
                        <span class="px-2 py-1 bg-red-50 border border-red-200 rounded text-xs text-red-700"><?= htmlspecialchars($name) ?></span>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-500 mt-3">These students were not found in the database (by first + last name). Add them as students first, then re-import.</p>
            </div>
        </details>
        <?php endif; ?>

        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
            <p class="text-sm text-yellow-800">
                <strong>Note:</strong> Student enrollments use admin override &mdash; students will be enrolled even without an active membership.
                Curriculum entries will be created or updated (existing entries for the same class + date will be overwritten).
            </p>
        </div>

        <div class="flex justify-between">
            <a href="import_curriculum.php?step=2" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-3 rounded-lg font-medium">
                &laquo; Back
            </a>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="step" value="4">
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-8 py-3 rounded-lg font-medium">
                    Confirm &amp; Import
                </button>
            </form>
        </div>
    </div>

<?php endif; ?>

<?php // =============================================================
      //  STEP 4 — Results
      // =============================================================
elseif ($step === 4):
    $importResults = $_SESSION['curriculum_import']['results'] ?? null;
    // Clear session data
    unset($_SESSION['curriculum_import']);

    if (!$importResults):
?>
    <div class="bg-white rounded-lg shadow p-8 text-center">
        <p class="text-gray-600">No import results found.</p>
        <a href="import_curriculum.php" class="mt-4 inline-block bg-blue-600 text-white px-6 py-2 rounded-lg">Start Over</a>
    </div>
<?php else: ?>

    <div class="bg-white rounded-lg shadow-lg p-8 max-w-3xl mx-auto">
        <div class="text-center mb-8">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
            </div>
            <h2 class="text-2xl font-bold text-gray-800">Import Complete!</h2>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-8">
            <div class="bg-indigo-50 rounded-lg p-4 text-center">
                <div class="text-3xl font-bold text-indigo-700"><?= $importResults['curriculum_created'] ?></div>
                <div class="text-sm text-indigo-600">Curriculum Entries</div>
            </div>
            <div class="bg-green-50 rounded-lg p-4 text-center">
                <div class="text-3xl font-bold text-green-700"><?= $importResults['students_enrolled'] ?></div>
                <div class="text-sm text-green-600">Students Enrolled</div>
            </div>
            <div class="bg-blue-50 rounded-lg p-4 text-center">
                <div class="text-3xl font-bold text-blue-700"><?= $importResults['classes_imported'] ?></div>
                <div class="text-sm text-blue-600">Classes Imported</div>
            </div>
        </div>

        <?php if ($importResults['students_existing'] > 0 || $importResults['students_not_found'] > 0): ?>
        <div class="bg-gray-50 rounded-lg p-4 mb-6 text-sm text-gray-600 space-y-1">
            <?php if ($importResults['students_existing'] > 0): ?>
                <p>&#8226; <?= $importResults['students_existing'] ?> student(s) were already enrolled (skipped)</p>
            <?php endif; ?>
            <?php if ($importResults['students_not_found'] > 0): ?>
                <p class="text-red-600">&#8226; <?= $importResults['students_not_found'] ?> student(s) were not found in the database:</p>
                <div class="flex flex-wrap gap-1 ml-4 mt-1">
                    <?php foreach ($importResults['not_found_names'] as $name): ?>
                        <span class="px-2 py-0.5 bg-red-50 border border-red-200 rounded text-xs text-red-700"><?= htmlspecialchars($name) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="flex justify-center gap-4">
            <a href="import_curriculum.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-3 rounded-lg font-medium">
                Import Another
            </a>
            <a href="attendance.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium">
                Go to Attendance &raquo;
            </a>
        </div>
    </div>

<?php endif; ?>

<?php endif; ?>

</div>

<script>
function toggleBlock(checkbox, key) {
    var block = document.getElementById('block_' + key);
    if (block) {
        block.style.opacity = checkbox.checked ? '0.4' : '1';
        block.style.pointerEvents = checkbox.checked ? 'none' : 'auto';
    }
}
</script>

<?php include 'includes/footer.php'; ?>
