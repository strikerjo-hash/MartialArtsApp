<?php
/**
 * merge_accounts.php — Account Merge Utility
 *
 * Allows admins to detect and merge duplicate student/parent accounts.
 * Flow:  scan → compare → merge → result
 *
 * Duplicate detection uses:
 *   - Levenshtein distance on full name (≤ 2 = match)
 *   - Same last name + similar first name (or vice versa)
 *   - Exact email or phone match
 *
 * Merge moves all related records from the secondary into the primary
 * account, fills missing profile fields, then deactivates the secondary.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security.php';
requireLogin();
if (!canView('merge_accounts.php')) { accessDenied(); }

$pdo = get_db();
$message = '';
$step = $_GET['step'] ?? 'scan';

// ─── Related tables that reference student_id ─────────────────────────
// Each entry: [table_name, fk_column, has_unique_constraint, description]
$relatedTables = [
    ['memberships',        'student_id',   false, 'Memberships'],
    ['class_enrollments',  'student_id',   true,  'Class Enrollments'],
    ['attendance',         'student_id',   false, 'Attendance Records'],
    ['event_registrations','student_id',   true,  'Event Registrations'],
    ['payments',           'student_id',   false, 'Payments'],
    ['payment_methods',    'student_id',   false, 'Payment Methods'],
    ['parent_students',    'student_id',   false, 'Parent Links (as child)'],
    ['parent_students',    'parent_id',    false, 'Parent Links (as parent)'],
    ['student_belts',      'student_id',   false, 'Belt Records'],
    ['training_logs',      'student_id',   false, 'Training Logs'],
    ['makeup_classes',     'student_id',   false, 'Make-Up Classes'],
    ['absence_warnings',   'student_id',   false, 'Absence Warnings'],
    ['renewal_log',        'student_id',   false, 'Renewal Log'],
    ['credit_ledger',      'student_id',   false, 'Credit Ledger'],
    ['discount_code_uses', 'student_id',   false, 'Discount Code Uses'],
];

// ═══════════════════════════════════════════════════════════════════════
// CORE FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════

/**
 * Find potential duplicate accounts using fuzzy name matching + contact info.
 */
function findPotentialDuplicates(PDO $pdo): array {
    $params = [];
    school_param($params);
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, phone, username, status, is_parent,
               CONCAT(first_name, ' ', last_name) AS full_name
        FROM students
        WHERE 1=1 " . school_where() . " AND status = 'active'
        ORDER BY last_name, first_name
    ");
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $duplicates = [];
    $count = count($students);

    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $a = $students[$i];
            $b = $students[$j];
            $reasons = [];
            $confidence = 0;

            // Full-name levenshtein
            $nameA = strtolower(trim($a['full_name']));
            $nameB = strtolower(trim($b['full_name']));
            $dist = levenshtein($nameA, $nameB);
            if ($dist === 0) {
                $reasons[] = 'Exact name match';
                $confidence += 3;
            } elseif ($dist <= 2) {
                $reasons[] = "Similar name (distance={$dist})";
                $confidence += 2;
            }

            // Same last name + similar first name
            $lastA = strtolower(trim($a['last_name']));
            $lastB = strtolower(trim($b['last_name']));
            $firstA = strtolower(trim($a['first_name']));
            $firstB = strtolower(trim($b['first_name']));

            if ($lastA === $lastB && $firstA !== $firstB) {
                $firstDist = levenshtein($firstA, $firstB);
                if ($firstDist <= 2 && $firstDist > 0) {
                    if (empty($reasons)) {
                        $reasons[] = "Same last name, similar first name (distance={$firstDist})";
                        $confidence += 2;
                    }
                }
            }

            // Same first name + similar last name
            if ($firstA === $firstB && $lastA !== $lastB) {
                $lastDist = levenshtein($lastA, $lastB);
                if ($lastDist <= 2 && $lastDist > 0) {
                    if (empty($reasons)) {
                        $reasons[] = "Same first name, similar last name (distance={$lastDist})";
                        $confidence += 2;
                    }
                }
            }

            // Exact email match
            if (!empty($a['email']) && !empty($b['email'])
                && strtolower(trim($a['email'])) === strtolower(trim($b['email']))) {
                $reasons[] = 'Same email';
                $confidence += 3;
            }

            // Exact phone match (digits only)
            $phoneA = preg_replace('/\D/', '', $a['phone'] ?? '');
            $phoneB = preg_replace('/\D/', '', $b['phone'] ?? '');
            if (strlen($phoneA) >= 7 && $phoneA === $phoneB) {
                $reasons[] = 'Same phone';
                $confidence += 2;
            }

            if (!empty($reasons)) {
                // Skip exact-same record (name, email, phone all identical — same person viewed twice shouldn't happen)
                if ($a['id'] === $b['id']) continue;

                $duplicates[] = [
                    'studentA'   => $a,
                    'studentB'   => $b,
                    'reasons'    => $reasons,
                    'confidence' => $confidence,
                ];
            }
        }
    }

    // Sort by confidence descending
    usort($duplicates, function($x, $y) {
        return $y['confidence'] - $x['confidence'];
    });

    return $duplicates;
}

/**
 * Search students by term for manual pair selection.
 */
function searchStudents(PDO $pdo, string $term): array {
    $params = [];
    $like = '%' . $term . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    school_param($params);
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, phone, username, status, is_parent,
               CONCAT(first_name, ' ', last_name) AS full_name
        FROM students
        WHERE (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR username LIKE ?)
        " . school_where() . " AND status = 'active'
        ORDER BY last_name, first_name
        LIMIT 50
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Load full student profile + record counts for merge preview.
 */
function getStudentMergePreview(PDO $pdo, int $studentId): ?array {
    global $relatedTables;

    $params = [$studentId];
    school_param($params);
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? " . school_where());
    $stmt->execute($params);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) return null;

    // Count records in each related table
    $recordCounts = [];
    foreach ($relatedTables as [$table, $fkCol, $hasUnique, $label]) {
        try {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$fkCol}` = ?");
            $countStmt->execute([$studentId]);
            $recordCounts[$label] = (int) $countStmt->fetchColumn();
        } catch (\PDOException $e) {
            $recordCounts[$label] = 0; // table may not exist
        }
    }

    // message_recipients (special case — uses recipient_id + recipient_type)
    try {
        $msgStmt = $pdo->prepare("SELECT COUNT(*) FROM message_recipients WHERE recipient_id = ? AND recipient_type = 'student'");
        $msgStmt->execute([$studentId]);
        $recordCounts['Messages'] = (int) $msgStmt->fetchColumn();
    } catch (\PDOException $e) {
        $recordCounts['Messages'] = 0;
    }

    $student['_record_counts'] = $recordCounts;
    $student['_total_records'] = array_sum($recordCounts);
    return $student;
}

/**
 * Execute the merge: move records, fill fields, deactivate secondary.
 */
function executeAccountMerge(PDO $pdo, int $primaryId, int $secondaryId, string $reason = ''): array {
    global $relatedTables;

    $result = [
        'success'              => false,
        'records_moved'        => [],
        'profile_fields_filled'=> [],
        'conflicts_skipped'    => [],
        'error'                => null,
    ];

    // Load both accounts
    $primary   = getStudentMergePreview($pdo, $primaryId);
    $secondary = getStudentMergePreview($pdo, $secondaryId);

    if (!$primary || !$secondary) {
        $result['error'] = 'One or both accounts not found.';
        return $result;
    }

    // Snapshot of secondary before merge
    $snapshot = $secondary;
    unset($snapshot['_record_counts'], $snapshot['_total_records']);

    try {
        $pdo->beginTransaction();

        // ── a) Fill missing profile fields on primary from secondary ──
        $fillableFields = [
            'email', 'phone', 'date_of_birth', 'address',
            'emergency_contact_name', 'emergency_contact_phone',
            'photo', 'medical_info', 'school_district'
        ];

        $updates = [];
        $updateParams = [];
        foreach ($fillableFields as $field) {
            if (!isset($primary[$field]) || $primary[$field] === '' || $primary[$field] === null) {
                if (isset($secondary[$field]) && $secondary[$field] !== '' && $secondary[$field] !== null) {
                    // Special: check email uniqueness before transferring
                    if ($field === 'email') {
                        $emailParams = [$secondary[$field], $primaryId];
                        school_param($emailParams);
                        $emailCheck = $pdo->prepare("SELECT COUNT(*) FROM students WHERE email = ? AND id != ? " . school_where());
                        $emailCheck->execute($emailParams);
                        if ((int)$emailCheck->fetchColumn() > 0) {
                            $result['conflicts_skipped'][] = "Email '{$secondary[$field]}' already used by another account";
                            continue;
                        }
                    }
                    $updates[] = "`{$field}` = ?";
                    $updateParams[] = $secondary[$field];
                    $result['profile_fields_filled'][] = $field;
                }
            }
        }

        // Sum account_credit
        $primaryCredit = (float)($primary['account_credit'] ?? 0);
        $secondaryCredit = (float)($secondary['account_credit'] ?? 0);
        if ($secondaryCredit > 0) {
            $updates[] = "`account_credit` = ?";
            $updateParams[] = $primaryCredit + $secondaryCredit;
            $result['profile_fields_filled'][] = "account_credit (+$" . number_format($secondaryCredit, 2) . ")";
        }

        // Append notes
        $primaryNotes = trim($primary['notes'] ?? '');
        $secondaryNotes = trim($secondary['notes'] ?? '');
        if ($secondaryNotes !== '') {
            $merged = $primaryNotes !== ''
                ? $primaryNotes . "\n\n--- Merged from account #{$secondaryId} ---\n" . $secondaryNotes
                : $secondaryNotes;
            $updates[] = "`notes` = ?";
            $updateParams[] = $merged;
            $result['profile_fields_filled'][] = 'notes (appended)';
        }

        // Transfer stripe_customer_id if primary lacks one
        if (empty($primary['stripe_customer_id']) && !empty($secondary['stripe_customer_id'])) {
            $updates[] = "`stripe_customer_id` = ?";
            $updateParams[] = $secondary['stripe_customer_id'];
            $result['profile_fields_filled'][] = 'stripe_customer_id';
        }

        if (!empty($updates)) {
            $updateParams[] = $primaryId;
            school_param($updateParams);
            $sql = "UPDATE students SET " . implode(', ', $updates) . " WHERE id = ? " . school_where();
            $pdo->prepare($sql)->execute($updateParams);
        }

        // ── b) Move records from secondary → primary ──

        foreach ($relatedTables as [$table, $fkCol, $hasUnique, $label]) {
            try {
                // Check if table exists
                $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
            } catch (\PDOException $e) {
                continue; // table doesn't exist in this installation
            }

            try {
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$fkCol}` = ?");
                $countStmt->execute([$secondaryId]);
                $count = (int) $countStmt->fetchColumn();
                if ($count === 0) continue;

                if ($hasUnique) {
                    // Handle tables with unique constraints (class_enrollments, event_registrations)
                    $moved = 0;
                    $skipped = 0;

                    if ($table === 'class_enrollments') {
                        $rows = $pdo->prepare("SELECT id, class_id, school_id FROM `{$table}` WHERE student_id = ?");
                        $rows->execute([$secondaryId]);
                        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            // Check if primary already has this enrollment
                            $dup = $pdo->prepare("SELECT COUNT(*) FROM class_enrollments WHERE student_id = ? AND class_id = ? AND school_id = ?");
                            $dup->execute([$primaryId, $row['class_id'], $row['school_id']]);
                            if ((int)$dup->fetchColumn() > 0) {
                                $pdo->prepare("DELETE FROM class_enrollments WHERE id = ?")->execute([$row['id']]);
                                $skipped++;
                            } else {
                                $pdo->prepare("UPDATE class_enrollments SET student_id = ? WHERE id = ?")->execute([$primaryId, $row['id']]);
                                $moved++;
                            }
                        }
                    } elseif ($table === 'event_registrations') {
                        $rows = $pdo->prepare("SELECT id, event_id, school_id FROM `{$table}` WHERE student_id = ?");
                        $rows->execute([$secondaryId]);
                        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            $dup = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE student_id = ? AND event_id = ? AND school_id = ?");
                            $dup->execute([$primaryId, $row['event_id'], $row['school_id']]);
                            if ((int)$dup->fetchColumn() > 0) {
                                $pdo->prepare("DELETE FROM event_registrations WHERE id = ?")->execute([$row['id']]);
                                $skipped++;
                            } else {
                                $pdo->prepare("UPDATE event_registrations SET student_id = ? WHERE id = ?")->execute([$primaryId, $row['id']]);
                                $moved++;
                            }
                        }
                    }

                    $result['records_moved'][$label] = $moved;
                    if ($skipped > 0) {
                        $result['conflicts_skipped'][] = "{$label}: {$skipped} duplicate(s) removed";
                    }

                } elseif ($table === 'parent_students') {
                    // Check for duplicate parent-child links
                    if ($fkCol === 'student_id') {
                        $rows = $pdo->prepare("SELECT id, parent_id FROM parent_students WHERE student_id = ?");
                        $rows->execute([$secondaryId]);
                        $moved = 0;
                        $skipped = 0;
                        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            $dup = $pdo->prepare("SELECT COUNT(*) FROM parent_students WHERE student_id = ? AND parent_id = ?");
                            $dup->execute([$primaryId, $row['parent_id']]);
                            if ((int)$dup->fetchColumn() > 0) {
                                $pdo->prepare("DELETE FROM parent_students WHERE id = ?")->execute([$row['id']]);
                                $skipped++;
                            } else {
                                $pdo->prepare("UPDATE parent_students SET student_id = ? WHERE id = ?")->execute([$primaryId, $row['id']]);
                                $moved++;
                            }
                        }
                    } else {
                        // parent_id references
                        $rows = $pdo->prepare("SELECT id, student_id FROM parent_students WHERE parent_id = ?");
                        $rows->execute([$secondaryId]);
                        $moved = 0;
                        $skipped = 0;
                        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            $dup = $pdo->prepare("SELECT COUNT(*) FROM parent_students WHERE parent_id = ? AND student_id = ?");
                            $dup->execute([$primaryId, $row['student_id']]);
                            if ((int)$dup->fetchColumn() > 0) {
                                $pdo->prepare("DELETE FROM parent_students WHERE id = ?")->execute([$row['id']]);
                                $skipped++;
                            } else {
                                $pdo->prepare("UPDATE parent_students SET parent_id = ? WHERE id = ?")->execute([$primaryId, $row['id']]);
                                $moved++;
                            }
                        }
                    }
                    $result['records_moved'][$label] = $moved;
                    if ($skipped > 0) {
                        $result['conflicts_skipped'][] = "{$label}: {$skipped} duplicate link(s) removed";
                    }

                } else {
                    // Simple bulk update
                    $updateStmt = $pdo->prepare("UPDATE `{$table}` SET `{$fkCol}` = ? WHERE `{$fkCol}` = ?");
                    $updateStmt->execute([$primaryId, $secondaryId]);
                    $result['records_moved'][$label] = $count;
                }
            } catch (\PDOException $e) {
                $result['conflicts_skipped'][] = "{$label}: Error — " . $e->getMessage();
            }
        }

        // Handle message_recipients separately
        try {
            $msgCount = $pdo->prepare("SELECT COUNT(*) FROM message_recipients WHERE recipient_id = ? AND recipient_type = 'student'");
            $msgCount->execute([$secondaryId]);
            $mc = (int) $msgCount->fetchColumn();
            if ($mc > 0) {
                $pdo->prepare("UPDATE message_recipients SET recipient_id = ? WHERE recipient_id = ? AND recipient_type = 'student'")
                    ->execute([$primaryId, $secondaryId]);
                $result['records_moved']['Messages'] = $mc;
            }
        } catch (\PDOException $e) {
            // table may not exist
        }

        // ── c) Deactivate secondary account ──
        $deactivateParams = [
            'merged_' . $secondaryId . '_' . time(), // mangled username
            $secondaryId,
        ];
        school_param($deactivateParams);
        $pdo->prepare("
            UPDATE students SET
                status = 'inactive',
                deactivation_reason = 'merged',
                inactive_since = CURDATE(),
                email = NULL,
                username = ?,
                notes = CONCAT(COALESCE(notes,''), '\n\n[MERGED into account #{$primaryId} on " . date('Y-m-d H:i') . "]')
            WHERE id = ? " . school_where()
        )->execute($deactivateParams);

        // Lost stripe_customer_id from secondary (if primary already had one)
        $stripeIdLost = null;
        if (!empty($secondary['stripe_customer_id']) && !empty($primary['stripe_customer_id'])
            && $secondary['stripe_customer_id'] !== $primary['stripe_customer_id']) {
            $stripeIdLost = $secondary['stripe_customer_id'];
        }

        // ── d) Log the merge ──
        try {
            $logParams = [
                current_school_id(),
                $primaryId,
                $secondaryId,
                getCurrentUser()['id'],
                $reason ?: null,
                json_encode($result['records_moved']),
                json_encode($result['profile_fields_filled']),
                json_encode($result['conflicts_skipped']),
                $stripeIdLost,
                json_encode($snapshot),
            ];
            $pdo->prepare("
                INSERT INTO account_merges
                    (school_id, primary_student_id, merged_student_id, merged_by_user_id,
                     merge_reason, records_moved, profile_fields_filled, conflicts_skipped,
                     stripe_customer_id_lost, source_account_snapshot)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute($logParams);
        } catch (\PDOException $e) {
            // If account_merges table doesn't exist yet, don't fail the merge
            $result['conflicts_skipped'][] = 'Could not log merge: ' . $e->getMessage();
        }

        // Audit log
        if (function_exists('audit_log')) {
            audit_log('merge_accounts', [
                'entity_type' => 'student',
                'entity_id'   => $primaryId,
                'description' => "Merged account #{$secondaryId} ({$secondary['first_name']} {$secondary['last_name']}) into #{$primaryId} ({$primary['first_name']} {$primary['last_name']})",
                'new_values'  => [
                    'primary_id'   => $primaryId,
                    'secondary_id' => $secondaryId,
                    'reason'       => $reason,
                    'records_moved'=> $result['records_moved'],
                ],
            ]);
        }

        $pdo->commit();
        $result['success'] = true;

    } catch (\Exception $e) {
        $pdo->rollBack();
        $result['error'] = $e->getMessage();
    }

    return $result;
}


// ═══════════════════════════════════════════════════════════════════════
// POST HANDLERS
// ═══════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'merge') {
        $primaryId   = (int)($_POST['primary_id'] ?? 0);
        $secondaryId = (int)($_POST['secondary_id'] ?? 0);
        $reason      = trim($_POST['merge_reason'] ?? '');

        if ($primaryId && $secondaryId && $primaryId !== $secondaryId) {
            $mergeResult = executeAccountMerge($pdo, $primaryId, $secondaryId, $reason);
            $_SESSION['merge_result'] = $mergeResult;
            $_SESSION['merge_primary_id'] = $primaryId;
            $_SESSION['merge_secondary_id'] = $secondaryId;
            header('Location: merge_accounts.php?step=result');
            exit;
        } else {
            $message = showAlert('Invalid account selection. Primary and secondary must be different.', 'error');
        }
    }
}


// ═══════════════════════════════════════════════════════════════════════
// PAGE DATA
// ═══════════════════════════════════════════════════════════════════════

$duplicates = [];
$searchResults = [];
$searchTerm = '';
$primaryStudent = null;
$secondaryStudent = null;

if ($step === 'scan') {
    if (isset($_GET['scan']) && $_GET['scan'] === '1') {
        $duplicates = findPotentialDuplicates($pdo);
    }
    if (!empty($_GET['search'])) {
        $searchTerm = trim($_GET['search']);
        $searchResults = searchStudents($pdo, $searchTerm);
    }
} elseif ($step === 'compare') {
    $idA = (int)($_GET['a'] ?? 0);
    $idB = (int)($_GET['b'] ?? 0);
    if ($idA && $idB) {
        $studentA = getStudentMergePreview($pdo, $idA);
        $studentB = getStudentMergePreview($pdo, $idB);
        if ($studentA && $studentB) {
            // Default: the one with more records is primary
            if ($studentB['_total_records'] > $studentA['_total_records']) {
                $primaryStudent = $studentB;
                $secondaryStudent = $studentA;
            } else {
                $primaryStudent = $studentA;
                $secondaryStudent = $studentB;
            }
            // Allow swap via query param
            if (isset($_GET['swap']) && $_GET['swap'] === '1') {
                [$primaryStudent, $secondaryStudent] = [$secondaryStudent, $primaryStudent];
            }
        } else {
            $message = showAlert('One or both accounts not found.', 'error');
            $step = 'scan';
        }
    } else {
        $step = 'scan';
    }
} elseif ($step === 'result') {
    // Results loaded from session (set by POST handler)
}

$pageTitle = 'Merge Accounts';
include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8 max-w-7xl">

    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">🔗 Merge Accounts</h1>
            <p class="text-gray-500 mt-1">Detect and merge duplicate student/parent accounts</p>
        </div>
        <?php if ($step !== 'scan'): ?>
        <a href="merge_accounts.php" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
            ← Back to Scan
        </a>
        <?php endif; ?>
    </div>

    <?php echo $message; ?>

<?php if ($step === 'scan'): ?>
<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- STEP 1: SCAN FOR DUPLICATES                                       -->
<!-- ═══════════════════════════════════════════════════════════════════ -->

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">

        <!-- Auto-Scan Card -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-3">Auto-Detect Duplicates</h2>
            <p class="text-gray-500 text-sm mb-4">
                Scans all accounts for similar names (1-2 character differences), matching emails, or matching phone numbers.
            </p>
            <a href="merge_accounts.php?scan=1"
               class="inline-flex items-center px-5 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                🔍 Scan for Duplicates
            </a>
        </div>

        <!-- Manual Search Card -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-3">Manual Comparison</h2>
            <p class="text-gray-500 text-sm mb-4">
                Search for specific accounts to compare and merge manually.
            </p>
            <form method="GET" class="flex gap-2">
                <input type="hidden" name="step" value="scan">
                <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>"
                       placeholder="Search by name, email, or username..."
                       class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <button type="submit"
                        class="px-5 py-2.5 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition font-medium">
                    Search
                </button>
            </form>
        </div>
    </div>

    <!-- Auto-Scan Results -->
    <?php if (isset($_GET['scan']) && $_GET['scan'] === '1'): ?>
    <div class="bg-white rounded-lg shadow overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-800">
                Potential Duplicates
                <span class="text-sm font-normal text-gray-500 ml-2">(<?php echo count($duplicates); ?> pair<?php echo count($duplicates) !== 1 ? 's' : ''; ?> found)</span>
            </h3>
        </div>

        <?php if (empty($duplicates)): ?>
            <div class="p-8 text-center text-gray-500">
                <div class="text-4xl mb-3">✅</div>
                <p class="text-lg">No potential duplicates detected!</p>
                <p class="text-sm mt-1">All accounts appear to be unique.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3 text-left">Account A</th>
                            <th class="px-4 py-3 text-left">Account B</th>
                            <th class="px-4 py-3 text-left">Match Reasons</th>
                            <th class="px-4 py-3 text-left">Confidence</th>
                            <th class="px-4 py-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($duplicates as $dup): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-800"><?php echo htmlspecialchars($dup['studentA']['full_name']); ?></div>
                                <div class="text-xs text-gray-500">
                                    #<?php echo $dup['studentA']['id']; ?>
                                    <?php echo $dup['studentA']['email'] ? ' · ' . htmlspecialchars($dup['studentA']['email']) : ''; ?>
                                    <span class="ml-1 px-1.5 py-0.5 rounded text-xs <?php echo $dup['studentA']['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
                                        <?php echo $dup['studentA']['status']; ?>
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-800"><?php echo htmlspecialchars($dup['studentB']['full_name']); ?></div>
                                <div class="text-xs text-gray-500">
                                    #<?php echo $dup['studentB']['id']; ?>
                                    <?php echo $dup['studentB']['email'] ? ' · ' . htmlspecialchars($dup['studentB']['email']) : ''; ?>
                                    <span class="ml-1 px-1.5 py-0.5 rounded text-xs <?php echo $dup['studentB']['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
                                        <?php echo $dup['studentB']['status']; ?>
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <?php foreach ($dup['reasons'] as $r): ?>
                                    <span class="inline-block px-2 py-1 mb-1 text-xs rounded-full bg-yellow-100 text-yellow-800"><?php echo htmlspecialchars($r); ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php
                                    $conf = $dup['confidence'];
                                    $confColor = $conf >= 5 ? 'text-red-600 bg-red-50' : ($conf >= 3 ? 'text-orange-600 bg-orange-50' : 'text-yellow-600 bg-yellow-50');
                                ?>
                                <span class="inline-block px-2 py-1 rounded font-bold text-sm <?php echo $confColor; ?>">
                                    <?php echo $conf >= 5 ? 'High' : ($conf >= 3 ? 'Medium' : 'Low'); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <a href="merge_accounts.php?step=compare&a=<?php echo $dup['studentA']['id']; ?>&b=<?php echo $dup['studentB']['id']; ?>"
                                   class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white text-sm rounded hover:bg-blue-700 transition">
                                    Compare →
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

    <!-- Manual Search Results -->
    <?php if (!empty($searchResults)): ?>
    <div class="bg-white rounded-lg shadow overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-800">
                Search Results for "<?php echo htmlspecialchars($searchTerm); ?>"
                <span class="text-sm font-normal text-gray-500 ml-2">(<?php echo count($searchResults); ?> found)</span>
            </h3>
            <p class="text-sm text-gray-500 mt-1">Select two accounts to compare them side-by-side.</p>
        </div>

        <form method="GET" class="p-4">
            <input type="hidden" name="step" value="compare">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3 text-center w-20">Account A</th>
                            <th class="px-4 py-3 text-center w-20">Account B</th>
                            <th class="px-4 py-3 text-left">Name</th>
                            <th class="px-4 py-3 text-left">Email</th>
                            <th class="px-4 py-3 text-left">Phone</th>
                            <th class="px-4 py-3 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($searchResults as $s): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-center">
                                <input type="radio" name="a" value="<?php echo $s['id']; ?>" class="w-4 h-4 text-blue-600">
                            </td>
                            <td class="px-4 py-3 text-center">
                                <input type="radio" name="b" value="<?php echo $s['id']; ?>" class="w-4 h-4 text-orange-500">
                            </td>
                            <td class="px-4 py-3 font-medium text-gray-800">
                                <?php echo htmlspecialchars($s['full_name']); ?>
                                <span class="text-xs text-gray-400 ml-1">#<?php echo $s['id']; ?></span>
                                <?php if ($s['is_parent']): ?>
                                    <span class="ml-1 px-1.5 py-0.5 rounded text-xs bg-purple-100 text-purple-700">Parent</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($s['email'] ?? '—'); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($s['phone'] ?? '—'); ?></td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 rounded text-xs <?php echo $s['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
                                    <?php echo $s['status']; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex justify-end">
                <button type="submit"
                        class="px-5 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                    Compare Selected →
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

<?php elseif ($step === 'compare' && $primaryStudent && $secondaryStudent): ?>
<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- STEP 2: SIDE-BY-SIDE COMPARISON                                   -->
<!-- ═══════════════════════════════════════════════════════════════════ -->

    <?php
    // Build swap URL
    $currentA = (int)$_GET['a'];
    $currentB = (int)$_GET['b'];
    $isSwapped = isset($_GET['swap']) && $_GET['swap'] === '1';
    $swapUrl = "merge_accounts.php?step=compare&a={$currentA}&b={$currentB}" . ($isSwapped ? '' : '&swap=1');

    // Determine which profile fields secondary can fill
    $fillableFields = [
        'email'                  => 'Email',
        'phone'                  => 'Phone',
        'date_of_birth'          => 'Date of Birth',
        'address'                => 'Address',
        'emergency_contact_name' => 'Emergency Contact',
        'emergency_contact_phone'=> 'Emergency Phone',
        'photo'                  => 'Photo',
        'medical_info'           => 'Medical Info',
        'school_district'        => 'School District',
    ];

    $canFill = [];
    foreach ($fillableFields as $field => $label) {
        $pVal = $primaryStudent[$field] ?? '';
        $sVal = $secondaryStudent[$field] ?? '';
        if (($pVal === '' || $pVal === null) && $sVal !== '' && $sVal !== null) {
            $canFill[$field] = $label;
        }
    }

    // Warnings
    $warnings = [];
    if (!empty($primaryStudent['stripe_customer_id']) && !empty($secondaryStudent['stripe_customer_id'])
        && $primaryStudent['stripe_customer_id'] !== $secondaryStudent['stripe_customer_id']) {
        $warnings[] = 'Both accounts have different Stripe Customer IDs. The secondary\'s Stripe ID will be lost.';
    }
    if ($primaryStudent['status'] === 'active' && $secondaryStudent['status'] === 'active') {
        // Check for overlapping active memberships
        try {
            $primMembParams = [$primaryStudent['id']];
            $secMembParams  = [$secondaryStudent['id']];
            $primMemb = $pdo->prepare("SELECT plan_id FROM memberships WHERE student_id = ? AND status = 'active'");
            $primMemb->execute($primMembParams);
            $primPlans = $primMemb->fetchAll(PDO::FETCH_COLUMN);
            $secMemb = $pdo->prepare("SELECT plan_id FROM memberships WHERE student_id = ? AND status = 'active'");
            $secMemb->execute($secMembParams);
            $secPlans = $secMemb->fetchAll(PDO::FETCH_COLUMN);
            $overlap = array_intersect($primPlans, $secPlans);
            if (!empty($overlap)) {
                $warnings[] = 'Both accounts have active memberships for the same plan(s). Review after merge.';
            }
        } catch (\PDOException $e) {}
    }
    ?>

    <!-- Swap Button -->
    <div class="flex justify-center mb-4">
        <a href="<?php echo $swapUrl; ?>"
           class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition text-sm font-medium">
            ⇄ Swap Primary / Secondary
        </a>
    </div>

    <!-- Warnings -->
    <?php if (!empty($warnings)): ?>
    <div class="mb-4 p-4 bg-yellow-50 border border-yellow-300 rounded-lg">
        <div class="font-semibold text-yellow-800 mb-1">⚠️ Warnings</div>
        <ul class="text-sm text-yellow-700 list-disc list-inside">
            <?php foreach ($warnings as $w): ?>
                <li><?php echo htmlspecialchars($w); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Side-by-Side Comparison -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        <!-- PRIMARY (Keep) -->
        <div class="bg-white rounded-lg shadow border-2 border-green-400">
            <div class="px-6 py-4 bg-green-50 border-b border-green-200 rounded-t-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-xs uppercase font-bold text-green-600 tracking-wider">Primary — Keep</span>
                        <h3 class="text-xl font-bold text-gray-800 mt-1">
                            <?php echo htmlspecialchars($primaryStudent['first_name'] . ' ' . $primaryStudent['last_name']); ?>
                        </h3>
                        <span class="text-sm text-gray-500">ID #<?php echo $primaryStudent['id']; ?></span>
                    </div>
                    <span class="text-3xl">✅</span>
                </div>
            </div>
            <div class="p-6">
                <?php echo renderProfileTable($primaryStudent, $secondaryStudent, true, $fillableFields); ?>
            </div>
        </div>

        <!-- SECONDARY (Merge Into Primary) -->
        <div class="bg-white rounded-lg shadow border-2 border-orange-400">
            <div class="px-6 py-4 bg-orange-50 border-b border-orange-200 rounded-t-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-xs uppercase font-bold text-orange-600 tracking-wider">Secondary — Merge into Primary</span>
                        <h3 class="text-xl font-bold text-gray-800 mt-1">
                            <?php echo htmlspecialchars($secondaryStudent['first_name'] . ' ' . $secondaryStudent['last_name']); ?>
                        </h3>
                        <span class="text-sm text-gray-500">ID #<?php echo $secondaryStudent['id']; ?></span>
                    </div>
                    <span class="text-3xl">🔀</span>
                </div>
            </div>
            <div class="p-6">
                <?php echo renderProfileTable($secondaryStudent, $primaryStudent, false, $fillableFields); ?>
            </div>
        </div>
    </div>

    <!-- Record Counts Comparison -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-800">Record Counts</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-3 text-left">Record Type</th>
                        <th class="px-4 py-3 text-center text-green-600">Primary</th>
                        <th class="px-4 py-3 text-center text-orange-600">Secondary</th>
                        <th class="px-4 py-3 text-left">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php
                    $allTypes = array_keys($primaryStudent['_record_counts']);
                    foreach ($allTypes as $type):
                        $pc = $primaryStudent['_record_counts'][$type] ?? 0;
                        $sc = $secondaryStudent['_record_counts'][$type] ?? 0;
                    ?>
                    <tr class="hover:bg-gray-50 <?php echo $sc > 0 ? 'bg-blue-50/30' : ''; ?>">
                        <td class="px-4 py-2 font-medium text-gray-700"><?php echo htmlspecialchars($type); ?></td>
                        <td class="px-4 py-2 text-center"><?php echo $pc; ?></td>
                        <td class="px-4 py-2 text-center font-semibold <?php echo $sc > 0 ? 'text-orange-600' : ''; ?>">
                            <?php echo $sc; ?>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-500">
                            <?php if ($sc > 0): ?>
                                <span class="text-blue-600">→ Will move to primary</span>
                            <?php else: ?>
                                <span class="text-gray-400">No action</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="bg-gray-50 font-semibold">
                        <td class="px-4 py-2">Total Records</td>
                        <td class="px-4 py-2 text-center text-green-600"><?php echo $primaryStudent['_total_records']; ?></td>
                        <td class="px-4 py-2 text-center text-orange-600"><?php echo $secondaryStudent['_total_records']; ?></td>
                        <td class="px-4 py-2"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Fields to Fill -->
    <?php if (!empty($canFill)): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <div class="font-semibold text-blue-800 mb-2">📋 Profile fields that will be filled from secondary:</div>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($canFill as $field => $label): ?>
                <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm">
                    <?php echo $label; ?>: <strong><?php echo htmlspecialchars(mb_strimwidth($secondaryStudent[$field] ?? '', 0, 40, '...')); ?></strong>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Merge Form -->
    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" id="mergeForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="merge">
            <input type="hidden" name="primary_id" value="<?php echo $primaryStudent['id']; ?>">
            <input type="hidden" name="secondary_id" value="<?php echo $secondaryStudent['id']; ?>">

            <div class="mb-4">
                <label for="merge_reason" class="block text-sm font-medium text-gray-700 mb-1">Merge Reason (optional)</label>
                <input type="text" id="merge_reason" name="merge_reason"
                       placeholder="e.g., Duplicate account — name misspelled"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-500">
                    This will merge <strong class="text-orange-600"><?php echo htmlspecialchars($secondaryStudent['first_name'] . ' ' . $secondaryStudent['last_name']); ?> (#<?php echo $secondaryStudent['id']; ?>)</strong>
                    into <strong class="text-green-600"><?php echo htmlspecialchars($primaryStudent['first_name'] . ' ' . $primaryStudent['last_name']); ?> (#<?php echo $primaryStudent['id']; ?>)</strong>
                    and deactivate the secondary account.
                </div>
                <button type="button" onclick="confirmMerge()"
                        class="px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-semibold">
                    🔗 Merge Accounts
                </button>
            </div>
        </form>
    </div>

    <script>
    function confirmMerge() {
        const primary = '<?php echo addslashes($primaryStudent['first_name'] . ' ' . $primaryStudent['last_name']); ?> (#<?php echo $primaryStudent['id']; ?>)';
        const secondary = '<?php echo addslashes($secondaryStudent['first_name'] . ' ' . $secondaryStudent['last_name']); ?> (#<?php echo $secondaryStudent['id']; ?>)';

        if (confirm(
            'Are you sure you want to merge these accounts?\n\n' +
            'PRIMARY (keep): ' + primary + '\n' +
            'SECONDARY (deactivate): ' + secondary + '\n\n' +
            'All records from the secondary account will be moved to the primary.\n' +
            'The secondary account will be deactivated.\n\n' +
            'This action cannot be easily undone!'
        )) {
            document.getElementById('mergeForm').submit();
        }
    }
    </script>

<?php elseif ($step === 'result'): ?>
<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- STEP 3: MERGE RESULT                                              -->
<!-- ═══════════════════════════════════════════════════════════════════ -->

    <?php
    $mergeResult = $_SESSION['merge_result'] ?? null;
    $mergePrimaryId = $_SESSION['merge_primary_id'] ?? 0;
    $mergeSecondaryId = $_SESSION['merge_secondary_id'] ?? 0;
    // Clear session data
    unset($_SESSION['merge_result'], $_SESSION['merge_primary_id'], $_SESSION['merge_secondary_id']);

    if (!$mergeResult):
    ?>
        <div class="bg-white rounded-lg shadow p-8 text-center">
            <p class="text-gray-500">No merge result to display.</p>
            <a href="merge_accounts.php" class="mt-4 inline-block text-blue-600 hover:underline">← Back to Scan</a>
        </div>
    <?php elseif ($mergeResult['success']): ?>
        <div class="bg-green-50 border border-green-300 rounded-lg p-6 mb-6">
            <div class="flex items-center gap-3 mb-2">
                <span class="text-3xl">✅</span>
                <h2 class="text-xl font-bold text-green-800">Merge Completed Successfully</h2>
            </div>
            <p class="text-green-700">
                Account #<?php echo $mergeSecondaryId; ?> has been merged into Account #<?php echo $mergePrimaryId; ?>
                and deactivated.
            </p>
        </div>

        <!-- Records Moved -->
        <?php if (!empty($mergeResult['records_moved'])): ?>
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-semibold text-gray-800">Records Moved</h3>
            </div>
            <div class="p-4">
                <table class="w-full">
                    <thead class="text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">Record Type</th>
                            <th class="px-4 py-2 text-center">Count</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($mergeResult['records_moved'] as $type => $count): ?>
                        <tr>
                            <td class="px-4 py-2 text-gray-700"><?php echo htmlspecialchars($type); ?></td>
                            <td class="px-4 py-2 text-center font-semibold text-blue-600"><?php echo $count; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="bg-gray-50 font-bold">
                            <td class="px-4 py-2">Total</td>
                            <td class="px-4 py-2 text-center text-blue-600"><?php echo array_sum($mergeResult['records_moved']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Profile Fields Filled -->
        <?php if (!empty($mergeResult['profile_fields_filled'])): ?>
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-semibold text-gray-800">Profile Fields Updated</h3>
            </div>
            <div class="p-4">
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($mergeResult['profile_fields_filled'] as $field): ?>
                        <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-sm"><?php echo htmlspecialchars($field); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Conflicts / Skipped -->
        <?php if (!empty($mergeResult['conflicts_skipped'])): ?>
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
            <div class="font-semibold text-yellow-800 mb-2">⚠️ Conflicts / Skipped</div>
            <ul class="text-sm text-yellow-700 list-disc list-inside">
                <?php foreach ($mergeResult['conflicts_skipped'] as $c): ?>
                    <li><?php echo htmlspecialchars($c); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Action Links -->
        <div class="flex gap-4">
            <a href="students.php?id=<?php echo $mergePrimaryId; ?>"
               class="px-5 py-2.5 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-medium">
                View Primary Account →
            </a>
            <a href="merge_accounts.php"
               class="px-5 py-2.5 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-medium">
                ← Scan for More Duplicates
            </a>
        </div>

    <?php else: ?>
        <div class="bg-red-50 border border-red-300 rounded-lg p-6 mb-6">
            <div class="flex items-center gap-3 mb-2">
                <span class="text-3xl">❌</span>
                <h2 class="text-xl font-bold text-red-800">Merge Failed</h2>
            </div>
            <p class="text-red-700"><?php echo htmlspecialchars($mergeResult['error'] ?? 'Unknown error occurred.'); ?></p>
        </div>
        <a href="merge_accounts.php" class="px-5 py-2.5 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-medium">
            ← Back to Scan
        </a>
    <?php endif; ?>

<?php endif; ?>

</div><!-- /.container -->

<?php
// ═══════════════════════════════════════════════════════════════════════
// HELPER: Render profile comparison table
// ═══════════════════════════════════════════════════════════════════════

function renderProfileTable(array $student, array $otherStudent, bool $isPrimary, array $fillableFieldLabels): string {
    $profileFields = [
        'username'               => 'Username',
        'email'                  => 'Email',
        'phone'                  => 'Phone',
        'date_of_birth'          => 'Date of Birth',
        'address'                => 'Address',
        'status'                 => 'Status',
        'is_parent'              => 'Is Parent',
        'stripe_customer_id'     => 'Stripe ID',
        'account_credit'         => 'Account Credit',
        'emergency_contact_name' => 'Emergency Contact',
        'emergency_contact_phone'=> 'Emergency Phone',
        'medical_info'           => 'Medical Info',
        'school_district'        => 'School District',
        'notes'                  => 'Notes',
        'created_at'             => 'Created',
    ];

    $html = '<table class="w-full text-sm">';
    $html .= '<tbody class="divide-y divide-gray-100">';

    foreach ($profileFields as $field => $label) {
        $value = $student[$field] ?? '';
        $otherValue = $otherStudent[$field] ?? '';
        $isEmpty = ($value === '' || $value === null);

        // Highlight: secondary has a value that can fill empty primary field
        $highlight = '';
        if ($isPrimary && $isEmpty && isset($fillableFieldLabels[$field]) && $otherValue !== '' && $otherValue !== null) {
            $highlight = ' bg-yellow-50'; // primary is missing this
        } elseif (!$isPrimary && !$isEmpty && isset($fillableFieldLabels[$field])) {
            $otherEmpty = ($otherValue === '' || $otherValue === null);
            if ($otherEmpty) {
                $highlight = ' bg-green-50'; // secondary can fill this
            }
        }

        // Format special fields
        $displayValue = htmlspecialchars($value ?? '');
        if ($field === 'is_parent') {
            $displayValue = $value ? '<span class="text-purple-600 font-medium">Yes</span>' : 'No';
        } elseif ($field === 'account_credit' && $value !== '' && $value !== null) {
            $displayValue = '$' . number_format((float)$value, 2);
        } elseif ($field === 'notes' && strlen($value) > 80) {
            $displayValue = htmlspecialchars(mb_strimwidth($value, 0, 80, '...'));
        } elseif ($field === 'stripe_customer_id' && $value) {
            $displayValue = '<code class="text-xs bg-gray-100 px-1 py-0.5 rounded">' . htmlspecialchars($value) . '</code>';
        } elseif ($field === 'status') {
            $statusColor = $value === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600';
            $displayValue = '<span class="px-2 py-0.5 rounded text-xs ' . $statusColor . '">' . htmlspecialchars($value) . '</span>';
        }

        if ($isEmpty) {
            $displayValue = '<span class="text-gray-300 italic">empty</span>';
        }

        $html .= "<tr class=\"{$highlight}\">";
        $html .= "<td class=\"px-3 py-2 text-gray-500 font-medium w-36\">{$label}</td>";
        $html .= "<td class=\"px-3 py-2 text-gray-800\">{$displayValue}</td>";
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    return $html;
}

include 'includes/footer.php';
?>
