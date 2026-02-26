<?php
require_once 'config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/parent_auth.php';
require_once __DIR__ . '/includes/payment_gateway.php';
requireLogin();

// Admin / Super Admin access
if (!in_array(getCurrentUser()['role'], ['admin', 'super_admin'])) {
    accessDenied('Import/Export requires Admin or Super Admin privileges.');
}

$pdo = get_db();

// =====================================================================
//  Helper Functions
// =====================================================================

/**
 * Parse a MyStudio CSV export into an array of associative arrays.
 */
function parseMyStudioCsv(string $filepath): array
{
    $rows = [];
    $handle = fopen($filepath, 'r');
    if (!$handle) return [];

    // Read header row
    $headers = fgetcsv($handle);
    if (!$headers) { fclose($handle); return []; }

    // Strip UTF-8 BOM from first header if present
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    $headers = array_map('trim', $headers);

    while (($data = fgetcsv($handle)) !== false) {
        // fgetcsv handles quoted multi-line values automatically
        if (count($data) >= count($headers)) {
            // Take only as many fields as we have headers (ignore extras)
            $row = array_combine($headers, array_slice($data, 0, count($headers)));
            $row = array_map('trim', $row);
            $rows[] = $row;
        }
    }

    fclose($handle);
    return $rows;
}

/**
 * Convert MyStudio date format ("Feb 8, 2021") to MySQL date ("2021-02-08").
 */
function parseMyStudioDate(string $dateStr): ?string
{
    $dateStr = trim($dateStr);
    if (empty($dateStr)) return null;
    $ts = strtotime($dateStr);
    if ($ts === false) return null;
    return date('Y-m-d', $ts);
}

/**
 * Generate a unique username from first/last name.
 */
function generateUniqueUsername(PDO $pdo, string $firstName, string $lastName): string
{
    $first = preg_replace('/[^a-z]/', '', strtolower($firstName));
    $last  = preg_replace('/[^a-z]/', '', strtolower($lastName));

    $base = $first . substr($last, 0, 1);
    if (strlen($base) < 3) $base = $first . $last;
    if (strlen($base) < 3) $base = 'user' . $first;
    $base = substr($base, 0, 40); // keep under 50-char limit

    $username = $base;
    $counter  = 1;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE username = ?' . school_where());

    while (true) {
        $params = [$username];
        school_param($params);
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() === 0) break;
        $username = $base . $counter;
        $counter++;
    }

    return $username;
}

/**
 * Deduplicate CSV rows by participant name (case-insensitive).
 *
 * MyStudio exports can contain the same person on multiple rows (e.g. when
 * they re-enrolled or changed plans).  This merges them into one row per
 * unique participant, keeping the earliest join date, summing payments and
 * past due, and preserving the most recent customer (parent) name.
 *
 * Returns: ['rows' => [...merged rows], 'csv_dupes_merged' => int]
 */
function deduplicateCSVRows(array $rows): array
{
    $merged = [];       // key = "firstname|lastname" (lowered/trimmed)
    $dupeCount = 0;

    foreach ($rows as $row) {
        $fn = strtolower(trim($row['Participant First Name'] ?? ''));
        $ln = strtolower(trim($row['Participant Last Name'] ?? ''));
        if ($fn === '' || $ln === '') {
            // Keep invalid rows as-is — they'll produce an error later
            $merged[] = $row;
            continue;
        }

        $key = $fn . '|' . $ln;

        if (!isset($merged[$key])) {
            // First occurrence — store as-is
            $merged[$key] = $row;
            // Keep the original-case name from first occurrence
            $merged[$key]['_total_payments_sum'] = (float) ($row['Total Payments'] ?? 0);
            $merged[$key]['_past_due_sum']       = (float) ($row['Past Due'] ?? 0);
            $merged[$key]['_csv_row_count']      = 1;
        } else {
            $dupeCount++;
            $merged[$key]['_csv_row_count']++;

            // Sum financial data
            $merged[$key]['_total_payments_sum'] += (float) ($row['Total Payments'] ?? 0);
            $merged[$key]['_past_due_sum']       += (float) ($row['Past Due'] ?? 0);

            // Keep the earliest join date
            $existingDate = parseMyStudioDate($merged[$key]['Participant Since'] ?? '');
            $newDate      = parseMyStudioDate($row['Participant Since'] ?? '');
            if ($newDate && $existingDate && $newDate < $existingDate) {
                $merged[$key]['Participant Since'] = $row['Participant Since'];
            }

            // Keep customer (parent) info from the most recent row (last seen)
            $cf = trim($row['Customer First Name'] ?? '');
            $cl = trim($row['Customer Last Name'] ?? '');
            if (!empty($cf) && !empty($cl)) {
                $merged[$key]['Customer First Name'] = $row['Customer First Name'];
                $merged[$key]['Customer Last Name']  = $row['Customer Last Name'];
            }
        }
    }

    // Write the summed financials back into the standard CSV columns
    $result = [];
    foreach ($merged as $row) {
        if (isset($row['_total_payments_sum'])) {
            $row['Total Payments'] = number_format($row['_total_payments_sum'], 2, '.', '');
            $row['Past Due']       = number_format($row['_past_due_sum'], 2, '.', '');
        }
        $result[] = $row;
    }

    return ['rows' => $result, 'csv_dupes_merged' => $dupeCount];
}

/**
 * Find duplicate students by first_name + last_name (already in database).
 */
function findDuplicateStudents(PDO $pdo, array $rows): array
{
    $duplicates = [];
    $stmt = $pdo->prepare(
        'SELECT id, first_name, last_name, join_date, email, phone
         FROM students
         WHERE LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?)' . school_where()
    );

    foreach ($rows as $i => $row) {
        $params = [
            trim($row['Participant First Name'] ?? ''),
            trim($row['Participant Last Name'] ?? ''),
        ];
        school_param($params);
        $stmt->execute($params);
        $match = $stmt->fetch();
        if ($match) {
            $duplicates[$i] = [
                'csv_row'  => $row,
                'existing' => $match,
                'row_index' => $i,
            ];
        }
    }

    return $duplicates;
}

/**
 * Execute the import (or dry run).
 */
function executeImport(PDO $pdo, array $rows, array $options): array
{
    $results = [
        'students_created'     => 0,
        'students_skipped'     => 0,
        'students_merged'      => 0,
        'adults_self_enrolled' => 0,
        'parents_created'      => 0,
        'parent_links_created' => 0,
        'errors'               => [],
        'details'              => [],
        'is_dry_run'           => $options['dry_run'] ?? true,
    ];

    $isDryRun       = $options['dry_run'] ?? true;
    $createParents  = $options['create_parents'] ?? true;
    $dupAction      = $options['duplicate_action'] ?? 'skip';

    if (!$isDryRun) {
        $pdo->beginTransaction();
    }

    try {
        // Cache of parents by "firstname|lastname" => student_id
        $parentCache = [];

        // Pre-load existing parent records for matching
        if ($createParents) {
            try {
                $parentSql = "SELECT id, LOWER(CONCAT(TRIM(first_name), '|', TRIM(last_name))) as name_key
                     FROM students WHERE is_parent = 1" . school_where();
                $parentParams = [];
                school_param($parentParams);
                $parentStmt = $pdo->prepare($parentSql);
                $parentStmt->execute($parentParams);
                $existingParents = $parentStmt->fetchAll();
                foreach ($existingParents as $ep) {
                    $parentCache[$ep['name_key']] = (int) $ep['id'];
                }
            } catch (\PDOException $e) {
                // is_parent column might not exist on very old installs
            }
        }

        // Deduplicate CSV rows by participant name first
        $dedup = deduplicateCSVRows($rows);
        $dedupedRows = $dedup['rows'];
        $results['csv_dupes_merged'] = $dedup['csv_dupes_merged'];

        // Duplicate check against DB (name-only, no join_date)
        $dupStmt = $pdo->prepare(
            'SELECT id, notes FROM students
             WHERE LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?)' . school_where()
        );

        foreach ($dedupedRows as $i => $row) {
            $firstName     = trim($row['Participant First Name'] ?? '');
            $lastName      = trim($row['Participant Last Name'] ?? '');
            $joinDateStr   = $row['Participant Since'] ?? '';
            $joinDate      = parseMyStudioDate($joinDateStr);
            $totalPayments = (float) ($row['Total Payments'] ?? 0);
            $pastDue       = (float) ($row['Past Due'] ?? 0);
            $custFirst     = trim($row['Customer First Name'] ?? '');
            $custLast      = trim($row['Customer Last Name'] ?? '');
            $csvRowCount   = (int) ($row['_csv_row_count'] ?? 1);

            // Skip rows with no name
            if (empty($firstName) || empty($lastName)) {
                $results['errors'][] = "Row " . ($i + 1) . ": Missing name, skipped.";
                continue;
            }
            if (!$joinDate) {
                $results['errors'][] = "Row " . ($i + 1) . " ($firstName $lastName): Invalid date '{$joinDateStr}', skipped.";
                continue;
            }

            // Check for duplicate in DB (name-only)
            $dupParams = [$firstName, $lastName];
            school_param($dupParams);
            $dupStmt->execute($dupParams);
            $existing = $dupStmt->fetch();
            $studentId = null;

            if ($existing) {
                if ($dupAction === 'skip') {
                    $results['students_skipped']++;
                    $results['details'][] = [
                        'name'   => "$firstName $lastName",
                        'action' => 'skipped',
                        'reason' => "Duplicate (existing ID: {$existing['id']})",
                    ];
                    $studentId = (int) $existing['id'];
                } else {
                    // Merge: add missing info
                    $studentId = (int) $existing['id'];
                    $results['students_merged']++;

                    // Add past due note if not already there
                    if ($pastDue > 0 && !$isDryRun) {
                        $existingNotes = $existing['notes'] ?? '';
                        if (stripos($existingNotes, 'MyStudio') === false) {
                            $updateParams = ["
[MyStudio Import] Past due: $" . number_format($pastDue, 2), $studentId];
                            school_param($updateParams);
                            $pdo->prepare("UPDATE students SET notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?" . school_where())
                                ->execute($updateParams);
                        }
                    }

                    $results['details'][] = [
                        'name'   => "$firstName $lastName",
                        'action' => 'merged',
                        'reason' => "Merged with existing ID: {$existing['id']}",
                    ];
                }
            } else {
                // Create new student with default password "Procomp123"
                // They must change it on first login and complete registration
                if (!$isDryRun) {
                    $username = generateUniqueUsername($pdo, $firstName, $lastName);
                    $defaultPasswordHash = password_hash('Procomp123', PASSWORD_DEFAULT);
                    $notes = '';
                    if ($pastDue > 0) {
                        $notes = "[MyStudio Import] Past due: $" . number_format($pastDue, 2);
                    }

                    // Build email from CSV if available (used as login identifier)
                    $importEmail = trim($row['Email'] ?? $row['email'] ?? $row['Participant Email'] ?? '');

                    $pdo->prepare("
                        INSERT INTO students (school_id, first_name, last_name, username, email, password_hash, join_date, belt_rank, status, notes, is_parent, must_change_password, registration_incomplete)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'White', 'active', ?, 0, 1, 1)
                    ")->execute([
                        current_school_id(),
                        sanitizeInput($firstName),
                        sanitizeInput($lastName),
                        $username,
                        $importEmail ?: null,
                        $defaultPasswordHash,
                        $joinDate,
                        $notes ?: null,
                    ]);
                    $studentId = (int) $pdo->lastInsertId();
                }

                $results['students_created']++;
                $mergeNote = $csvRowCount > 1 ? " (merged from {$csvRowCount} CSV rows)" : '';
                $results['details'][] = [
                    'name'   => "$firstName $lastName",
                    'action' => 'created',
                    'reason' => ($isDryRun ? 'New student (dry run — will require password change on first login)' : "New student (ID: {$studentId}) — must change password on first login") . $mergeNote,
                ];
            }

            // --- Parent creation & linking ---
            if ($createParents && !empty($custFirst) && !empty($custLast)) {
                $isParentSameAsSelf = (
                    strtolower($custFirst) === strtolower($firstName) &&
                    strtolower($custLast) === strtolower($lastName)
                );

                if ($isParentSameAsSelf) {
                    // Customer name matches participant — this is an adult who enrolled
                    // themselves. Do NOT create a separate parent account.
                    $results['adults_self_enrolled']++;
                    // Amend the last detail entry with the self-enrolled note
                    $lastIdx = count($results['details']) - 1;
                    if ($lastIdx >= 0) {
                        $results['details'][$lastIdx]['reason'] .= ' | Adult self-enrollment — no separate parent account';
                        $results['details'][$lastIdx]['is_adult_self'] = true;
                    }
                } else {
                    $parentKey = strtolower(trim($custFirst) . '|' . trim($custLast));

                    if (!isset($parentCache[$parentKey])) {
                        if (!$isDryRun) {
                            $parentUsername = generateUniqueUsername($pdo, $custFirst, $custLast);
                            $parentPasswordHash = password_hash('Procomp123', PASSWORD_DEFAULT);
                            $pdo->prepare("
                                INSERT INTO students (school_id, first_name, last_name, username, password_hash, join_date, belt_rank, status, is_parent, notes, must_change_password, registration_incomplete)
                                VALUES (?, ?, ?, ?, ?, ?, 'White', 'active', 1, '[MyStudio Import] Parent/Guardian account', 1, 1)
                            ")->execute([
                                current_school_id(),
                                sanitizeInput($custFirst),
                                sanitizeInput($custLast),
                                $parentUsername,
                                $parentPasswordHash,
                                $joinDate,
                            ]);
                            $parentId = (int) $pdo->lastInsertId();
                        } else {
                            $parentId = -1; // placeholder for dry run
                        }

                        $parentCache[$parentKey] = $parentId;
                        $results['parents_created']++;
                    }

                    $parentId = $parentCache[$parentKey];

                    // Link parent to student
                    if (!$isDryRun && $studentId && $parentId > 0) {
                        try {
                            link_student_to_parent($parentId, $studentId);
                            $results['parent_links_created']++;
                        } catch (\Exception $e) {
                            // Link may already exist — that's OK
                        }
                    } elseif ($isDryRun) {
                        $results['parent_links_created']++;
                    }
                }
            }

        }

        if (!$isDryRun) {
            $pdo->commit();
        }
    } catch (\Exception $e) {
        if (!$isDryRun) {
            $pdo->rollBack();
        }
        $results['errors'][] = 'Import failed: ' . $e->getMessage();
    }

    return $results;
}

// =====================================================================
//  Step Handling
// =====================================================================
$step    = (int) ($_GET['step'] ?? ($_POST['step'] ?? 1));
$message = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // --- Step 1: Upload CSV ---
    if (isset($_POST['action']) && $_POST['action'] === 'upload') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $message = showAlert('Please select a valid CSV file to upload.', 'error');
            $step = 1;
        } else {
            $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                $message = showAlert('Only .csv files are accepted.', 'error');
                $step = 1;
            } else {
                $rows = parseMyStudioCsv($_FILES['csv_file']['tmp_name']);
                if (empty($rows)) {
                    $message = showAlert('No valid data rows found in the CSV file.', 'error');
                    $step = 1;
                } else {
                    $_SESSION['import_rows']    = $rows;
                    $_SESSION['import_filename'] = $_FILES['csv_file']['name'];
                    header('Location: import_data.php?step=2');
                    exit;
                }
            }
        }
    }

    // --- Step 2/3: Execute Import ---
    if (isset($_POST['action']) && $_POST['action'] === 'execute_import') {
        $rows = $_SESSION['import_rows'] ?? [];
        if (empty($rows)) {
            $message = showAlert('No import data found. Please upload a CSV file first.', 'error');
            $step = 1;
        } else {
            $isDryRun = !empty($_POST['dry_run']);
            $results = executeImport($pdo, $rows, [
                'dry_run'          => $isDryRun,
                'create_parents'   => isset($_POST['create_parents']),
                'duplicate_action' => $_POST['duplicate_action'] ?? 'skip',
            ]);
            $_SESSION['import_results'] = $results;
            $_SESSION['import_options'] = [
                'create_parents'   => isset($_POST['create_parents']),
                'duplicate_action' => $_POST['duplicate_action'] ?? 'skip',
            ];
            header('Location: import_data.php?step=3');
            exit;
        }
    }

    // --- Step 3: Confirm (run for real after dry run) ---
    if (isset($_POST['action']) && $_POST['action'] === 'confirm_import') {
        $rows    = $_SESSION['import_rows'] ?? [];
        $options = $_SESSION['import_options'] ?? [];
        if (empty($rows)) {
            $message = showAlert('No import data found. Please start over.', 'error');
            $step = 1;
        } else {
            $options['dry_run'] = false;
            $results = executeImport($pdo, $rows, $options);
            $_SESSION['import_results'] = $results;
            // Clear import data after real import
            unset($_SESSION['import_rows'], $_SESSION['import_filename'], $_SESSION['import_options']);
            header('Location: import_data.php?step=3');
            exit;
        }
    }

    // --- Clear / Start Over ---
    if (isset($_POST['action']) && $_POST['action'] === 'clear') {
        unset($_SESSION['import_rows'], $_SESSION['import_filename'], $_SESSION['import_results'], $_SESSION['import_options']);
        header('Location: import_data.php');
        exit;
    }
}

// Load data for display
$importRows    = $_SESSION['import_rows'] ?? [];
$importResults = $_SESSION['import_results'] ?? null;
$importFilename = $_SESSION['import_filename'] ?? '';

// Step 2: deduplicate CSV rows and find DB duplicates for preview
$duplicates = [];
$duplicateCount = 0;
$rawRowCount = count($importRows);
$dedupPreview = ['rows' => $importRows, 'csv_dupes_merged' => 0];
$dedupedPreviewRows = $importRows;
$csvDupesMerged = 0;

if ($step === 2 && !empty($importRows)) {
    // Run deduplication for preview
    $dedupPreview = deduplicateCSVRows($importRows);
    $dedupedPreviewRows = $dedupPreview['rows'];
    $csvDupesMerged = $dedupPreview['csv_dupes_merged'];

    // Find DB duplicates using deduplicated rows
    $duplicates = findDuplicateStudents($pdo, $dedupedPreviewRows);
    $duplicateCount = count($duplicates);
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Import / Export Data</h1>
        <div class="space-x-3">
            <a href="import_data.php" class="px-4 py-2 rounded-lg font-medium text-sm <?php echo $step <= 3 ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">Import Students</a>
            <a href="import_payments.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Import Payments</a>
            <a href="export_data.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Export</a>
        </div>
    </div>

    <!-- Step Progress Bar -->
    <div class="flex items-center mb-8">
        <?php
        $steps = ['Upload CSV', 'Preview & Options', 'Results'];
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
      //  STEP 1 — Upload CSV
      // =============================================================
if ($step === 1): ?>

    <div class="bg-white rounded-lg shadow-lg p-8 max-w-3xl mx-auto">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Upload MyStudio CSV Export</h2>
        <p class="text-gray-600 mb-6">Upload a CSV file exported from MyStudio. The system will parse student data, detect duplicates, and allow you to preview before importing.</p>

        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-blue-800 mb-2">Expected CSV Format</h3>
            <p class="text-sm text-blue-700 mb-2">The CSV should contain these columns:</p>
            <code class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded block overflow-x-auto">
                Participant First Name, Participant Last Name, Type, Total Payments, Past Due, Customer First Name, Customer Last Name, Participant Since
            </code>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="upload">
            <?php echo csrf_field(); ?>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select CSV File *</label>
                <input type="file" name="csv_file" accept=".csv" required
                       class="block w-full text-sm text-gray-700 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 p-2 focus:outline-none">
                <p class="text-xs text-gray-500 mt-1">Maximum file size depends on your server configuration (typically 2MB-8MB).</p>
            </div>

            <div class="flex justify-end pt-4">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-6 py-3 rounded-lg">
                    Upload &amp; Preview
                </button>
            </div>
        </form>
    </div>

<?php // =============================================================
      //  STEP 2 — Preview & Options
      // =============================================================
elseif ($step === 2 && !empty($importRows)): ?>

    <div class="bg-white rounded-lg shadow-lg p-8 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-gray-800">CSV Preview</h2>
            <div class="text-sm text-gray-500">
                File: <strong><?php echo htmlspecialchars($importFilename); ?></strong> &middot;
                <strong><?php echo $rawRowCount; ?></strong> CSV rows &rarr;
                <strong><?php echo count($dedupedPreviewRows); ?></strong> unique students
            </div>
        </div>

        <?php if ($csvDupesMerged > 0): ?>
        <div class="bg-cyan-50 border border-cyan-200 rounded-lg p-3 mb-4">
            <span class="text-sm text-cyan-800">
                <strong>🔀 <?php echo $csvDupesMerged; ?> duplicate CSV row<?php echo $csvDupesMerged !== 1 ? 's' : ''; ?> merged</strong>
                — The same student appeared multiple times in the CSV (e.g., different enrollment dates). Rows have been merged: earliest join date kept, payments summed.
            </span>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-blue-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-blue-600"><?php echo $rawRowCount; ?></div>
                <div class="text-sm text-gray-600">CSV Rows</div>
            </div>
            <div class="bg-cyan-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-cyan-600"><?php echo count($dedupedPreviewRows); ?></div>
                <div class="text-sm text-gray-600">Unique Students</div>
            </div>
            <div class="bg-<?php echo $duplicateCount > 0 ? 'yellow' : 'green'; ?>-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-<?php echo $duplicateCount > 0 ? 'yellow' : 'green'; ?>-600"><?php echo $duplicateCount; ?></div>
                <div class="text-sm text-gray-600">Already in DB</div>
            </div>
            <div class="bg-green-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-green-600"><?php echo count($dedupedPreviewRows) - $duplicateCount; ?></div>
                <div class="text-sm text-gray-600">New Students</div>
            </div>
            <?php
            // Count unique parents vs self-enrolled adults (from deduplicated rows)
            $uniqueParents = [];
            $adultSelfCount = 0;
            foreach ($dedupedPreviewRows as $row) {
                $pf = trim($row['Participant First Name'] ?? '');
                $pl = trim($row['Participant Last Name'] ?? '');
                $cf = trim($row['Customer First Name'] ?? '');
                $cl = trim($row['Customer Last Name'] ?? '');
                if (!empty($cf) && !empty($cl)) {
                    if (strtolower($cf) === strtolower($pf) && strtolower($cl) === strtolower($pl)) {
                        $adultSelfCount++;
                    } else {
                        $uniqueParents[strtolower($cf . '|' . $cl)] = true;
                    }
                }
            }
            ?>
            <div class="bg-purple-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-purple-600"><?php echo count($uniqueParents); ?></div>
                <div class="text-sm text-gray-600">Unique Parents</div>
            </div>
        </div>
        <?php if ($adultSelfCount > 0): ?>
        <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3 mb-6">
            <span class="text-sm text-indigo-800">
                <strong>👤 <?php echo $adultSelfCount; ?> adult self-enrollment<?php echo $adultSelfCount !== 1 ? 's' : ''; ?> detected</strong>
                — Customer name matches Participant name. These will be imported as regular students without creating a separate parent account.
            </span>
        </div>
        <?php endif; ?>

        <!-- Column Mapping -->
        <div class="bg-gray-50 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-gray-700 mb-3">Column Mapping (Auto-Detected)</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                <div><span class="text-gray-500">Participant First Name</span> &rarr; <span class="font-medium text-green-700">Student First Name</span></div>
                <div><span class="text-gray-500">Participant Last Name</span> &rarr; <span class="font-medium text-green-700">Student Last Name</span></div>
                <div><span class="text-gray-500">Customer First/Last</span> &rarr; <span class="font-medium text-green-700">Parent Account</span></div>
                <div><span class="text-gray-500">Participant Since</span> &rarr; <span class="font-medium text-green-700">Join Date</span></div>
                <div><span class="text-gray-500">Past Due</span> &rarr; <span class="font-medium text-green-700">Student Notes</span></div>
            </div>
        </div>

        <!-- Preview Table (first 20 deduplicated rows) -->
        <div class="overflow-x-auto mb-6">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Student Name</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Parent/Customer</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Since</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total Paid</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Past Due</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach (array_slice($dedupedPreviewRows, 0, 20) as $i => $row):
                        $isDup = isset($duplicates[$i]);
                        $csvRowCount = (int) ($row['_csv_row_count'] ?? 1);
                    ?>
                    <tr class="<?php echo $isDup ? 'bg-yellow-50' : ''; ?>">
                        <td class="px-3 py-2 text-gray-500"><?php echo $i + 1; ?></td>
                        <td class="px-3 py-2 font-medium text-gray-800">
                            <?php echo htmlspecialchars(($row['Participant First Name'] ?? '') . ' ' . ($row['Participant Last Name'] ?? '')); ?>
                            <?php if ($csvRowCount > 1): ?>
                                <span class="ml-1 px-1.5 py-0.5 text-xs rounded bg-cyan-100 text-cyan-700 font-medium" title="Merged from <?php echo $csvRowCount; ?> CSV rows"><?php echo $csvRowCount; ?>x</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-gray-600">
                            <?php
                            $prevCF = trim($row['Customer First Name'] ?? '');
                            $prevCL = trim($row['Customer Last Name'] ?? '');
                            $prevPF = trim($row['Participant First Name'] ?? '');
                            $prevPL = trim($row['Participant Last Name'] ?? '');
                            $isSelf = (!empty($prevCF) && !empty($prevCL) && strtolower($prevCF) === strtolower($prevPF) && strtolower($prevCL) === strtolower($prevPL));
                            ?>
                            <?php if ($isSelf): ?>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-indigo-100 text-indigo-800 font-medium">Self (Adult)</span>
                            <?php else: ?>
                                <?php echo htmlspecialchars($prevCF . ' ' . $prevCL); ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-gray-600"><?php echo htmlspecialchars($row['Participant Since'] ?? ''); ?></td>
                        <td class="px-3 py-2 text-right text-gray-800">
                            $<?php echo number_format((float)($row['Total Payments'] ?? 0), 2); ?>
                        </td>
                        <td class="px-3 py-2 text-right <?php echo (float)($row['Past Due'] ?? 0) > 0 ? 'text-red-600 font-semibold' : 'text-gray-400'; ?>">
                            $<?php echo number_format((float)($row['Past Due'] ?? 0), 2); ?>
                        </td>
                        <td class="px-3 py-2 text-center">
                            <?php if ($isDup): ?>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-800">In DB</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800">New</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($dedupedPreviewRows) > 20): ?>
                    <tr>
                        <td colspan="7" class="px-3 py-3 text-center text-gray-500 text-sm italic">
                            ... and <?php echo count($dedupedPreviewRows) - 20; ?> more unique students
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Import Options Form -->
    <div class="bg-white rounded-lg shadow-lg p-8 max-w-3xl mx-auto">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Import Options</h2>

        <!-- Login Credentials Info -->
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-4">
            <h3 class="font-semibold text-amber-800 mb-2">🔐 Login Credentials for Imported Students</h3>
            <div class="text-sm text-amber-700 space-y-1">
                <p><strong>Username:</strong> Auto-generated from their name (e.g., <code>johnd</code>). Students can also log in with their email address if one is on file.</p>
                <p><strong>Default Password:</strong> <code class="bg-amber-100 px-1.5 py-0.5 rounded font-mono">Procomp123</code></p>
                <p><strong>First Login:</strong> Students will be required to <strong>change their password</strong> and <strong>complete their profile</strong> (email, phone, etc.) before accessing the portal.</p>
            </div>
        </div>

        <form method="POST" class="space-y-6">
            <input type="hidden" name="action" value="execute_import">
            <?php echo csrf_field(); ?>

            <!-- Options Checkboxes -->
            <div class="space-y-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="create_parents" value="1" checked class="w-5 h-5 text-blue-600 rounded">
                    <div>
                        <span class="font-medium text-gray-700">Create Parent Accounts</span>
                        <p class="text-xs text-gray-500">Create parent accounts from Customer names and link to their children (~<?php echo count($uniqueParents); ?> parents)</p>
                    </div>
                </label>
            </div>

            <!-- Duplicate Handling -->
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <h3 class="font-semibold text-yellow-800 mb-3">Duplicate Handling (<?php echo $duplicateCount; ?> already in database)</h3>
                <div class="space-y-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="duplicate_action" value="skip" checked class="text-blue-600">
                        <div>
                            <span class="font-medium text-gray-700">Skip Duplicates</span>
                            <span class="text-xs text-gray-500 ml-1">Don't modify existing records</span>
                        </div>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="duplicate_action" value="merge" class="text-blue-600">
                        <div>
                            <span class="font-medium text-gray-700">Merge Missing Data</span>
                            <span class="text-xs text-gray-500 ml-1">Add past due notes and parent links to existing records</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex justify-between items-center pt-4 border-t">
                <div><!-- Start Over is a separate form below --></div>
                <div class="space-x-3">
                    <button type="submit" name="dry_run" value="1" class="bg-gray-600 hover:bg-gray-700 text-white font-medium px-6 py-3 rounded-lg">
                        Dry Run Preview
                    </button>
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-medium px-6 py-3 rounded-lg"
                            onclick="return confirm('This will import all data into the system. Are you sure?')">
                        Import Now
                    </button>
                </div>
            </div>
        </form>

        <!-- Start Over (separate form, outside the main import form) -->
        <form method="POST" class="mt-4">
            <input type="hidden" name="action" value="clear">
            <?php echo csrf_field(); ?>
            <button type="submit" class="text-gray-500 hover:text-gray-700 text-sm">&#8592; Start Over</button>
        </form>
    </div>

<?php // =============================================================
      //  STEP 3 — Results
      // =============================================================
elseif ($step === 3 && $importResults): ?>

    <?php $isDryRun = $importResults['is_dry_run'] ?? true; ?>

    <!-- Results Header -->
    <div class="bg-<?php echo $isDryRun ? 'blue' : 'green'; ?>-50 border border-<?php echo $isDryRun ? 'blue' : 'green'; ?>-200 rounded-lg p-4 mb-6">
        <h2 class="text-lg font-bold text-<?php echo $isDryRun ? 'blue' : 'green'; ?>-800">
            <?php echo $isDryRun ? '&#128269; Dry Run Preview — No changes were made' : '&#10003; Import Complete!'; ?>
        </h2>
        <p class="text-sm text-<?php echo $isDryRun ? 'blue' : 'green'; ?>-700 mt-1">
            <?php echo $isDryRun ? 'Review the results below, then confirm to run the actual import.' : 'All data has been imported successfully.'; ?>
        </p>
    </div>

    <?php if (!$isDryRun && $importResults['students_created'] > 0): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
        <h3 class="font-semibold text-amber-800 mb-2">&#128272; Login Instructions for Imported Students</h3>
        <div class="text-sm text-amber-700 space-y-1">
            <p><strong>Username:</strong> Auto-generated from their name (visible in the student list). Students can also use their email if they provide one.</p>
            <p><strong>Default Password:</strong> <code class="bg-amber-100 px-1.5 py-0.5 rounded font-mono font-bold">Procomp123</code></p>
            <p><strong>First Login:</strong> All imported students will be required to <strong>change their password</strong> and <strong>complete their profile</strong> (email, phone, etc.) before they can access the portal.</p>
        </div>
    </div>
    <?php endif; ?>

    <?php $csvDupesMergedResult = $importResults['csv_dupes_merged'] ?? 0; ?>
    <?php if ($csvDupesMergedResult > 0): ?>
    <div class="bg-cyan-50 border border-cyan-200 rounded-lg p-3 mb-4">
        <span class="text-sm text-cyan-800">
            <strong>🔀 <?php echo $csvDupesMergedResult; ?> duplicate CSV row<?php echo $csvDupesMergedResult !== 1 ? 's' : ''; ?> were merged</strong>
            before import — same student appeared multiple times with different dates. Earliest join date kept, payments summed.
        </span>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-green-600"><?php echo $importResults['students_created']; ?></div>
            <div class="text-xs text-gray-600">Students Created</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-yellow-600"><?php echo $importResults['students_skipped']; ?></div>
            <div class="text-xs text-gray-600">Skipped (Dups)</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-blue-600"><?php echo $importResults['students_merged']; ?></div>
            <div class="text-xs text-gray-600">Merged</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-indigo-600"><?php echo $importResults['adults_self_enrolled'] ?? 0; ?></div>
            <div class="text-xs text-gray-600">Adults (Self)</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-purple-600"><?php echo $importResults['parents_created']; ?></div>
            <div class="text-xs text-gray-600">Parents Created</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-indigo-600"><?php echo $importResults['parent_links_created']; ?></div>
            <div class="text-xs text-gray-600">Parent Links</div>
        </div>
    </div>

    <!-- Errors -->
    <?php if (!empty($importResults['errors'])): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
        <h3 class="font-semibold text-red-800 mb-2">&#9888; Errors (<?php echo count($importResults['errors']); ?>)</h3>
        <ul class="text-sm text-red-700 space-y-1 max-h-40 overflow-y-auto">
            <?php foreach ($importResults['errors'] as $err): ?>
                <li>&bull; <?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Detail Table -->
    <?php if (!empty($importResults['details'])): ?>
    <div class="bg-white rounded-lg shadow-lg overflow-hidden mb-6">
        <div class="px-6 py-4 border-b">
            <h3 class="text-lg font-semibold text-gray-800">Import Details</h3>
        </div>
        <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($importResults['details'] as $idx => $detail):
                        $actionColors = ['created' => 'green', 'skipped' => 'yellow', 'merged' => 'blue', 'self_enrolled' => 'indigo'];
                        $actionLabels = ['created' => 'Created', 'skipped' => 'Skipped', 'merged' => 'Merged', 'self_enrolled' => 'Adult (Self)'];
                        $color = $actionColors[$detail['action']] ?? 'gray';
                    ?>
                    <tr>
                        <td class="px-4 py-2 text-gray-500"><?php echo $idx + 1; ?></td>
                        <td class="px-4 py-2 font-medium text-gray-800">
                            <?php echo htmlspecialchars($detail['name']); ?>
                            <?php if (!empty($detail['is_adult_self'])): ?>
                                <span class="ml-1 px-1.5 py-0.5 text-xs rounded bg-indigo-100 text-indigo-700 font-medium">Adult</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 text-xs rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800 font-semibold">
                                <?php echo $actionLabels[$detail['action']] ?? ucfirst($detail['action']); ?>
                            </span>
                        </td>
                        <td class="px-4 py-2 text-gray-600 text-xs"><?php echo htmlspecialchars($detail['reason']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <div class="flex justify-between items-center">
        <form method="POST">
            <input type="hidden" name="action" value="clear">
            <?php echo csrf_field(); ?>
            <button type="submit" class="text-gray-600 hover:text-gray-800 font-medium">&#8592; Start Over</button>
        </form>
        <div class="space-x-3">
            <?php if ($isDryRun && !empty($_SESSION['import_rows'])): ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="confirm_import">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold px-8 py-3 rounded-lg"
                            onclick="return confirm('This will permanently import all data. Continue?')">
                        &#10003; Confirm &amp; Import For Real
                    </button>
                </form>
            <?php else: ?>
                <a href="students.php" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-6 py-3 rounded-lg inline-block">View Students</a>
                <a href="parent_accounts.php" class="bg-purple-600 hover:bg-purple-700 text-white font-medium px-6 py-3 rounded-lg inline-block">View Parents</a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // Clear results from session (but keep rows for confirm action)
    if (!$isDryRun) {
        unset($_SESSION['import_results']);
    }
    ?>

<?php else: ?>
    <!-- Fallback: redirect to step 1 -->
    <div class="text-center py-12">
        <p class="text-gray-600 mb-4">No import data found. Please upload a CSV file to get started.</p>
        <a href="import_data.php" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-6 py-3 rounded-lg">Start Import</a>
    </div>
<?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>
