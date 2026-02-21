<?php
require_once 'config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/parent_auth.php';
require_once __DIR__ . '/includes/payment_gateway.php';
requireLogin();

// Admin-only access
if (getCurrentUser()['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$pdo = get_db();

// =====================================================================
//  Helper Functions
// =====================================================================

/**
 * Parse a MyStudio Payment CSV export into an array of associative arrays.
 */
function parsePaymentsCsv(string $filepath): array
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
            $row = array_combine($headers, array_slice($data, 0, count($headers)));
            $row = array_map('trim', $row);
            $rows[] = $row;
        }
    }

    fclose($handle);
    return $rows;
}

/**
 * Parse "Last, First" name format into ['first' => ..., 'last' => ...].
 * Returns null if unparseable.
 */
function parsePaymentName(string $nameStr): ?array
{
    $nameStr = trim($nameStr);
    if (empty($nameStr)) return null;

    // "Last, First" format
    if (strpos($nameStr, ',') !== false) {
        $parts = explode(',', $nameStr, 2);
        $last  = trim($parts[0]);
        $first = trim($parts[1] ?? '');
        if (!empty($first) && !empty($last)) {
            return ['first' => $first, 'last' => $last];
        }
    }

    return null;
}

/**
 * Parse "$85.00" or "$-40.47" into float.
 */
function parsePaymentAmount(string $amountStr): float
{
    $amountStr = trim($amountStr);
    // Remove $ and commas
    $cleaned = str_replace(['$', ','], '', $amountStr);
    return (float) $cleaned;
}

/**
 * Map CSV Category to payment_type ENUM.
 */
function mapCategory(string $category): string
{
    $category = strtolower(trim($category));
    switch ($category) {
        case 'program':       return 'membership';
        case 'events':        return 'event';
        case 'retail':        return 'merchandise';
        default:              return 'other';
    }
}

/**
 * Map CSV Payment Method to payment_method ENUM.
 */
function mapPaymentMethod(string $methodStr): string
{
    $method = strtolower(trim($methodStr));

    if (preg_match('/visa|mastercard|amex|american express|discover/', $method)) {
        return 'credit_card';
    }
    if (preg_match('/checking|savings|bank/', $method)) {
        return 'bank_transfer';
    }
    if ($method === 'cash payment' || $method === 'cash') {
        return 'cash';
    }
    // Manual Credit, blank, etc.
    return 'other';
}

/**
 * Map CSV Status to a simple status flag.
 * Returns 'completed', 'refunded', or 'other'.
 */
function mapPaymentStatus(string $statusStr): string
{
    $status = strtolower(trim($statusStr));
    switch ($status) {
        case 'completed':             return 'completed';
        case 'refund':                return 'refunded';
        case 'completed(refund)':     return 'completed'; // was completed, refund is separate
        case 'completed(dispute)':    return 'refunded';
        case 'dispute lost':          return 'refunded';
        default:                      return 'other';
    }
}

/**
 * Generate a unique username from first/last name.
 */
function generatePaymentImportUsername(PDO $pdo, string $firstName, string $lastName): string
{
    $first = preg_replace('/[^a-z]/', '', strtolower($firstName));
    $last  = preg_replace('/[^a-z]/', '', strtolower($lastName));

    $base = $first . substr($last, 0, 1);
    if (strlen($base) < 3) $base = $first . $last;
    if (strlen($base) < 3) $base = 'user' . $first;
    $base = substr($base, 0, 40); // keep under 50-char limit

    $username = $base;
    $counter  = 1;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE username = ?');

    while (true) {
        $stmt->execute([$username]);
        if ((int) $stmt->fetchColumn() === 0) break;
        $username = $base . $counter;
        $counter++;
    }

    return $username;
}

/**
 * Find matching student (non-parent) by first and last name (case-insensitive).
 * Returns student row or null.
 */
function findStudentByName(PDO $pdo, string $firstName, string $lastName): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, first_name, last_name, email, is_parent
         FROM students
         WHERE LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?)'
    );
    $stmt->execute([trim($firstName), trim($lastName)]);
    $match = $stmt->fetch();
    return $match ?: null;
}

/**
 * Find any account (student OR parent) by name, preferring students.
 * Returns account row with is_parent flag, or null.
 */
function findAnyAccountByName(PDO $pdo, string $firstName, string $lastName): ?array
{
    // Search all accounts, order so non-parent (student) comes first
    $stmt = $pdo->prepare(
        'SELECT id, first_name, last_name, email, is_parent
         FROM students
         WHERE LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?)
         ORDER BY is_parent ASC
         LIMIT 1'
    );
    $stmt->execute([trim($firstName), trim($lastName)]);
    $match = $stmt->fetch();
    return $match ?: null;
}

/**
 * Execute the payment import (or dry run).
 */
function executePaymentImport(PDO $pdo, array $rows, array $options): array
{
    $results = [
        'payments_created'     => 0,
        'refunds_created'      => 0,
        'payments_skipped'     => 0,
        'duplicates_reimported'=> 0,
        'students_created'     => 0,
        'parents_created'      => 0,
        'emails_updated'       => 0,
        'errors'               => [],
        'details'              => [],
        'created_students'     => [],
        'created_parents'      => [],
        'skipped_duplicates'   => [],
        'is_dry_run'           => $options['dry_run'] ?? true,
        'total_amount'         => 0.0,
        'date_min'             => null,
        'date_max'             => null,
    ];

    $isDryRun       = $options['dry_run'] ?? true;
    $updateEmails   = $options['update_emails'] ?? true;
    $reimportTxnIds = array_flip($options['reimport_txn_ids'] ?? []); // txn_id => index for O(1) lookup

    if (!$isDryRun) {
        $pdo->beginTransaction();
    }

    try {
        // Cache: "firstname|lastname" => student row
        $studentCache = [];

        // Cache: "email" => student row (for email uniqueness checking during auto-creation)
        $emailCache = [];
        try {
            $stmtE = $pdo->query("SELECT id, first_name, last_name, email, is_parent FROM students WHERE email IS NOT NULL AND email != ''");
            while ($eRow = $stmtE->fetch()) {
                $emailCache[strtolower(trim($eRow['email']))] = $eRow;
            }
        } catch (\PDOException $e) {}

        // Track seen Transaction ID + Participant + Amount combos (composite key)
        // Siblings and different purchases under the same txn are all valid separate payments
        $seenTxnComposite = [];

        // Pre-load existing payments for duplicate check (keyed by txn_id + name + amount)
        $existingPayments = [];
        try {
            $stmt = $pdo->query("
                SELECT p.receipt_number, p.payment_date, p.amount, p.payment_type, p.payment_method, p.notes,
                       s.first_name, s.last_name
                FROM payments p
                LEFT JOIN students s ON p.student_id = s.id
                WHERE p.receipt_number IS NOT NULL AND p.receipt_number != ''
            ");
            while ($r = $stmt->fetch()) {
                // Extract base transaction ID (strip -2, -3 suffixes we may have added)
                $baseTxn = preg_replace('/-\d+$/', '', $r['receipt_number']);
                $studentName = strtolower(trim(($r['first_name'] ?? '') . '|' . ($r['last_name'] ?? '')));
                $amtKey = number_format((float)($r['amount'] ?? 0), 2, '.', '');
                $compositeKey = $baseTxn . '::' . $studentName . '::' . $amtKey;
                $existingPayments[$compositeKey] = $r;
            }
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        // Track how many payments per transaction ID (for receipt_number suffixing)
        $txnIdCounts = [];

        // Track first-seen CSV row for each composite key (for CSV-internal duplicate comparison)
        $firstSeenCsvRows = [];

        foreach ($rows as $i => $row) {
            $participantStr = $row['Participant'] ?? '';
            $dateStr        = trim($row['Payment Processing Date'] ?? '');
            $grossStr       = $row['Gross'] ?? '$0.00';
            $category       = $row['Category'] ?? '';
            $methodStr      = $row['Payment Method'] ?? '';
            $statusStr      = $row['Status'] ?? '';
            $transactionId  = trim($row['Transaction ID'] ?? '');
            $detail         = trim($row['Detail'] ?? '');
            $otherDetails   = trim($row['Other Details'] ?? '');
            $emailStr       = trim($row['Email'] ?? '');
            $buyerStr       = $row['Buyer'] ?? '';

            // Parse name — try Participant first, fall back to Buyer
            $name = parsePaymentName($participantStr);
            $usedBuyerFallback = false;
            if (!$name) {
                $name = parsePaymentName($buyerStr);
                if ($name) {
                    $usedBuyerFallback = true;
                } else {
                    $results['errors'][] = "Row " . ($i + 1) . ": Could not parse participant or buyer name (Participant: '{$participantStr}', Buyer: '{$buyerStr}'), skipped.";
                    continue;
                }
            }

            // Parse amount
            $amount = parsePaymentAmount($grossStr);

            // Parse date
            if (empty($dateStr)) {
                $results['errors'][] = "Row " . ($i + 1) . " ({$name['first']} {$name['last']}): Missing date, skipped.";
                continue;
            }
            // Date is already YYYY-MM-DD
            $paymentDate = $dateStr;

            // Track date range
            if ($results['date_min'] === null || $paymentDate < $results['date_min']) {
                $results['date_min'] = $paymentDate;
            }
            if ($results['date_max'] === null || $paymentDate > $results['date_max']) {
                $results['date_max'] = $paymentDate;
            }

            // Duplicate check by Transaction ID + Participant + Amount (composite key)
            // Siblings and different purchases under the same txn are all valid separate payments
            $forceReimport = false;
            if (!empty($transactionId)) {
                $participantKey = strtolower($name['first'] . '|' . $name['last']);
                $amtKey = number_format($amount, 2, '.', '');
                $compositeKey = $transactionId . '::' . $participantKey . '::' . $amtKey;

                // Already in this CSV batch (same txn + same person + same amount)?
                if (isset($seenTxnComposite[$compositeKey])) {
                    $results['payments_skipped']++;
                    $firstSeen = $firstSeenCsvRows[$compositeKey] ?? null;
                    $results['skipped_duplicates'][] = [
                        'row_num'        => $i + 1,
                        'transaction_id' => $transactionId,
                        'date'           => $dateStr,
                        'participant'    => $name['first'] . ' ' . $name['last'],
                        'category'       => $category,
                        'detail'         => $detail,
                        'amount'         => $amount,
                        'status'         => $statusStr,
                        'source'         => 'csv',
                        'match'          => $firstSeen ? [
                            'row_num'     => $firstSeen['row_num'],
                            'date'        => $firstSeen['date'],
                            'participant' => $firstSeen['participant'],
                            'detail'      => $firstSeen['detail'],
                            'amount'      => $firstSeen['amount'],
                            'status'      => $firstSeen['status'],
                        ] : null,
                    ];
                    continue;
                }
                $seenTxnComposite[$compositeKey] = true;

                // Remember first-seen CSV row for this composite key
                $firstSeenCsvRows[$compositeKey] = [
                    'row_num'     => $i + 1,
                    'date'        => $dateStr,
                    'participant' => $name['first'] . ' ' . $name['last'],
                    'category'    => $category,
                    'detail'      => $detail,
                    'amount'      => $amount,
                    'status'      => $statusStr,
                ];

                // Already in database (same txn + same person)?
                if (isset($existingPayments[$compositeKey])) {
                    // Check if user selected this for reimport
                    if (isset($reimportTxnIds[$compositeKey])) {
                        $forceReimport = true;
                        $results['duplicates_reimported']++;
                    } else {
                        $dbRecord = $existingPayments[$compositeKey];
                        $results['payments_skipped']++;
                        $results['skipped_duplicates'][] = [
                            'row_num'        => $i + 1,
                            'transaction_id' => $transactionId,
                            'composite_key'  => $compositeKey,
                            'date'           => $dateStr,
                            'participant'    => $name['first'] . ' ' . $name['last'],
                            'category'       => $category,
                            'detail'         => $detail,
                            'amount'         => $amount,
                            'status'         => $statusStr,
                            'source'         => 'db',
                            'match'          => [
                                'date'        => $dbRecord['payment_date'] ?? '',
                                'participant' => trim(($dbRecord['first_name'] ?? '') . ' ' . ($dbRecord['last_name'] ?? '')),
                                'detail'      => $dbRecord['notes'] ?? '',
                                'amount'      => (float)($dbRecord['amount'] ?? 0),
                                'type'        => $dbRecord['payment_type'] ?? '',
                                'method'      => $dbRecord['payment_method'] ?? '',
                            ],
                        ];
                        continue;
                    }
                }

                // Track transaction ID usage count (for receipt_number suffixing)
                $txnIdCounts[$transactionId] = ($txnIdCounts[$transactionId] ?? 0) + 1;
            }

            // Find account — try student first, then any account (including parents) if using Buyer fallback
            $cacheKey = strtolower($name['first'] . '|' . $name['last']);
            if (!isset($studentCache[$cacheKey])) {
                if ($usedBuyerFallback) {
                    // Buyer name: search all accounts (students + parents), prefer students
                    $studentCache[$cacheKey] = findAnyAccountByName($pdo, $name['first'], $name['last']);
                } else {
                    // Participant name: search all accounts too (could be a parent paying for themselves)
                    $studentCache[$cacheKey] = findAnyAccountByName($pdo, $name['first'], $name['last']);
                }
            }
            $student = $studentCache[$cacheKey];

            if (!$student) {
                if ($usedBuyerFallback) {
                    // No Participant and Buyer not found — create a PARENT account
                    if (!$isDryRun) {
                        $username = generatePaymentImportUsername($pdo, $name['first'], $name['last']);
                        $defaultPasswordHash = password_hash('Procomp123', PASSWORD_DEFAULT);

                        // Check email uniqueness before insert (UNIQUE constraint on students.email)
                        $emailForInsert = null;
                        if (!empty($emailStr)) {
                            $emailLower = strtolower(trim($emailStr));
                            if (!isset($emailCache[$emailLower])) {
                                $emailForInsert = $emailStr;
                            }
                            // If email already taken, insert with NULL email instead
                        }

                        $pdo->prepare("
                            INSERT INTO students (first_name, last_name, username, email, password_hash, join_date, belt_rank, status, notes, is_parent, must_change_password, registration_incomplete)
                            VALUES (?, ?, ?, ?, ?, ?, 'White', 'active', '[Payment Import] Auto-created parent — buyer with no participant', 1, 1, 1)
                        ")->execute([
                            sanitizeInput($name['first']),
                            sanitizeInput($name['last']),
                            $username,
                            $emailForInsert,
                            $defaultPasswordHash,
                            $paymentDate,
                        ]);
                        $newId = (int) $pdo->lastInsertId();
                        $student = ['id' => $newId, 'first_name' => $name['first'], 'last_name' => $name['last'], 'email' => $emailForInsert, 'is_parent' => 1];
                        $studentCache[$cacheKey] = $student;
                        // Reserve email in cache so subsequent rows can't reuse it
                        if ($emailForInsert) {
                            $emailCache[strtolower(trim($emailForInsert))] = $student;
                        }
                    } else {
                        $student = ['id' => -1, 'first_name' => $name['first'], 'last_name' => $name['last'], 'email' => null, 'is_parent' => 1];
                        $studentCache[$cacheKey] = $student;
                    }

                    // Track parent creation
                    if (!isset($results['created_parents'][$cacheKey])) {
                        $results['parents_created']++;
                        $results['created_parents'][$cacheKey] = [
                            'name'  => $name['first'] . ' ' . $name['last'],
                            'count' => 0,
                            'total' => 0.0,
                        ];
                    }
                    $results['created_parents'][$cacheKey]['count']++;
                    $results['created_parents'][$cacheKey]['total'] += $amount;
                } else {
                    // Participant not found — create a STUDENT account
                    if (!$isDryRun) {
                        $username = generatePaymentImportUsername($pdo, $name['first'], $name['last']);
                        $defaultPasswordHash = password_hash('Procomp123', PASSWORD_DEFAULT);

                        // Check email uniqueness before insert (UNIQUE constraint on students.email)
                        $emailForInsert = null;
                        if (!empty($emailStr)) {
                            $emailLower = strtolower(trim($emailStr));
                            if (!isset($emailCache[$emailLower])) {
                                $emailForInsert = $emailStr;
                            }
                            // If email already taken, insert with NULL email instead
                        }

                        $pdo->prepare("
                            INSERT INTO students (first_name, last_name, username, email, password_hash, join_date, belt_rank, status, notes, is_parent, must_change_password, registration_incomplete)
                            VALUES (?, ?, ?, ?, ?, ?, 'White', 'active', '[Payment Import] Auto-created — student not found during payment import', 0, 1, 1)
                        ")->execute([
                            sanitizeInput($name['first']),
                            sanitizeInput($name['last']),
                            $username,
                            $emailForInsert,
                            $defaultPasswordHash,
                            $paymentDate,
                        ]);
                        $newStudentId = (int) $pdo->lastInsertId();
                        $student = ['id' => $newStudentId, 'first_name' => $name['first'], 'last_name' => $name['last'], 'email' => $emailForInsert, 'is_parent' => 0];
                        $studentCache[$cacheKey] = $student;
                        // Reserve email in cache so subsequent rows can't reuse it
                        if ($emailForInsert) {
                            $emailCache[strtolower(trim($emailForInsert))] = $student;
                        }
                    } else {
                        $student = ['id' => -1, 'first_name' => $name['first'], 'last_name' => $name['last'], 'email' => null, 'is_parent' => 0];
                        $studentCache[$cacheKey] = $student;
                    }

                    // Track student creation
                    if (!isset($results['created_students'][$cacheKey])) {
                        $results['students_created']++;
                        $results['created_students'][$cacheKey] = [
                            'name'  => $name['first'] . ' ' . $name['last'],
                            'count' => 0,
                            'total' => 0.0,
                        ];
                    }
                    $results['created_students'][$cacheKey]['count']++;
                    $results['created_students'][$cacheKey]['total'] += $amount;
                }
            }

            // Map fields
            $paymentType   = mapCategory($category);
            $paymentMethod = mapPaymentMethod($methodStr);
            $status        = mapPaymentStatus($statusStr);

            // Build notes
            $notes = '';
            if (!empty($detail)) {
                $notes = $detail;
            }
            if (!empty($otherDetails)) {
                $notes .= ($notes ? ' | ' : '') . $otherDetails;
            }
            // Add status info for non-standard
            if ($status === 'refunded') {
                $notes .= ($notes ? ' | ' : '') . "[Refund] Original status: {$statusStr}";
            }

            // Receipt number = Transaction ID (suffixed for siblings, or generate new if reimporting)
            if ($forceReimport) {
                $receiptNumber = generateReceiptNumber();
                $notes .= ($notes ? ' | ' : '') . "[Reimported] Original txn: {$transactionId}";
            } elseif (!empty($transactionId)) {
                // First payment under this txn_id gets the raw ID; siblings get -2, -3, etc.
                $txnCount = $txnIdCounts[$transactionId] ?? 1;
                $receiptNumber = $txnCount === 1 ? $transactionId : $transactionId . '-' . $txnCount;
            } else {
                $receiptNumber = null;
            }

            // Track total
            $results['total_amount'] += $amount;

            // Insert payment record
            if (!$isDryRun && $student) {
                $pdo->prepare("
                    INSERT INTO payments (student_id, payment_type, amount, payment_method, payment_date, receipt_number, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    (int) $student['id'],
                    $paymentType,
                    $amount,
                    $paymentMethod,
                    $paymentDate,
                    $receiptNumber,
                    $notes ?: null,
                ]);

                // Update student email if missing (check uniqueness first)
                if ($updateEmails && !empty($emailStr) && empty($student['email'])) {
                    $emailLower = strtolower(trim($emailStr));
                    if (!isset($emailCache[$emailLower])) {
                        $pdo->prepare("UPDATE students SET email = ? WHERE id = ? AND (email IS NULL OR email = '')")
                            ->execute([$emailStr, (int) $student['id']]);
                        $results['emails_updated']++;
                        $studentCache[$cacheKey]['email'] = $emailStr;
                        $emailCache[$emailLower] = $student; // Reserve in cache
                    }
                    // If email already taken by another account, silently skip
                }
            } elseif ($isDryRun && $student) {
                // Track email updates in dry run (check uniqueness via cache)
                if ($updateEmails && !empty($emailStr) && empty($student['email']) && !isset($studentCache[$cacheKey]['_email_counted'])) {
                    $emailLower = strtolower(trim($emailStr));
                    if (!isset($emailCache[$emailLower])) {
                        $results['emails_updated']++;
                        $studentCache[$cacheKey]['email'] = $emailStr;
                        $studentCache[$cacheKey]['_email_counted'] = true;
                        $emailCache[$emailLower] = $student; // Reserve in cache
                    }
                }
            }

            if ($amount < 0 || $status === 'refunded') {
                $results['refunds_created']++;
            } else {
                $results['payments_created']++;
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

/**
 * Pre-analyze payment rows for the preview (Step 2).
 * Matches students, counts duplicates, etc. without inserting.
 */
function analyzePaymentRows(PDO $pdo, array $rows): array
{
    $stats = [
        'total_rows'         => count($rows),
        'matched'            => 0,
        'new_students'       => 0,
        'new_parents'        => 0,
        'duplicates'         => 0,
        'refunds'            => 0,
        'total_amount'       => 0.0,
        'refund_amount'      => 0.0,
        'date_min'           => null,
        'date_max'           => null,
        'new_student_names'  => [],
        'new_parent_names'   => [],
        'duplicate_details'  => [],
        'category_counts'    => [],
        'preview_rows'       => [],
    ];

    $studentCache = [];
    $seenTxnComposite = [];

    // Load existing payments for duplicate check (keyed by txn_id + name + amount)
    $existingPayments = [];
    try {
        $stmt = $pdo->query("
            SELECT p.receipt_number, p.payment_date, p.amount, p.payment_type, p.payment_method, p.notes,
                   s.first_name, s.last_name
            FROM payments p
            LEFT JOIN students s ON p.student_id = s.id
            WHERE p.receipt_number IS NOT NULL AND p.receipt_number != ''
        ");
        while ($r = $stmt->fetch()) {
            $baseTxn = preg_replace('/-\d+$/', '', $r['receipt_number']);
            $studentName = strtolower(trim(($r['first_name'] ?? '') . '|' . ($r['last_name'] ?? '')));
            $amtKey = number_format((float)($r['amount'] ?? 0), 2, '.', '');
            $compositeKey = $baseTxn . '::' . $studentName . '::' . $amtKey;
            $existingPayments[$compositeKey] = $r;
        }
    } catch (\PDOException $e) {}

    // Track first-seen CSV row for each composite key
    $firstSeenCsvRows = [];

    foreach ($rows as $i => $row) {
        $participantStr = $row['Participant'] ?? '';
        $buyerStr       = $row['Buyer'] ?? '';
        $dateStr        = trim($row['Payment Processing Date'] ?? '');
        $grossStr       = $row['Gross'] ?? '$0.00';
        $category       = $row['Category'] ?? '';
        $statusStr      = $row['Status'] ?? '';
        $transactionId  = trim($row['Transaction ID'] ?? '');

        // Parse name — try Participant first, fall back to Buyer
        $name = parsePaymentName($participantStr);
        $usedBuyerFallback = false;
        if (!$name) {
            $name = parsePaymentName($buyerStr);
            if ($name) {
                $usedBuyerFallback = true;
            } else {
                continue; // Can't parse either name, skip
            }
        }

        $amount = parsePaymentAmount($grossStr);
        $status = mapPaymentStatus($statusStr);

        // Date range
        if (!empty($dateStr)) {
            if ($stats['date_min'] === null || $dateStr < $stats['date_min']) $stats['date_min'] = $dateStr;
            if ($stats['date_max'] === null || $dateStr > $stats['date_max']) $stats['date_max'] = $dateStr;
        }

        // Duplicate check by Transaction ID + Participant + Amount (composite key)
        // Siblings and different purchases under the same txn are all valid separate payments
        $isDup = false;
        $dupSource = '';
        if (!empty($transactionId)) {
            $participantKey = strtolower($name['first'] . '|' . $name['last']);
            $amtKey = number_format($amount, 2, '.', '');
            $compositeKey = $transactionId . '::' . $participantKey . '::' . $amtKey;

            if (isset($seenTxnComposite[$compositeKey])) {
                $stats['duplicates']++;
                $isDup = true;
                $dupSource = 'csv'; // same person + same txn + same amount in same CSV
            } elseif (isset($existingPayments[$compositeKey])) {
                $stats['duplicates']++;
                $isDup = true;
                $dupSource = 'db'; // same person + same txn + same amount already in database
            }
            if (!$isDup) {
                // Remember first-seen CSV row for this composite key
                $firstSeenCsvRows[$compositeKey] = [
                    'row_num'     => $i + 1,
                    'date'        => $dateStr,
                    'participant' => $name['first'] . ' ' . $name['last'],
                    'category'    => $category,
                    'detail'      => trim($row['Detail'] ?? ''),
                    'amount'      => $amount,
                    'status'      => $statusStr,
                ];
            }
            $seenTxnComposite[$compositeKey] = true;
        }

        if ($isDup) {
            // Collect details for duplicate review (only DB duplicates are reviewable for reimport)
            if ($dupSource === 'db') {
                $dbRecord = $existingPayments[$compositeKey];
                $stats['duplicate_details'][] = [
                    'row_num'        => $i + 1,
                    'row_index'      => $i,
                    'transaction_id' => $transactionId,
                    'composite_key'  => $compositeKey,
                    'date'           => $dateStr,
                    'participant'    => $name['first'] . ' ' . $name['last'],
                    'category'       => $category,
                    'amount'         => $amount,
                    'status'         => $statusStr,
                    'detail'         => trim($row['Detail'] ?? ''),
                    'match'          => [
                        'date'        => $dbRecord['payment_date'] ?? '',
                        'participant' => trim(($dbRecord['first_name'] ?? '') . ' ' . ($dbRecord['last_name'] ?? '')),
                        'detail'      => $dbRecord['notes'] ?? '',
                        'amount'      => (float)($dbRecord['amount'] ?? 0),
                        'type'        => $dbRecord['payment_type'] ?? '',
                        'method'      => $dbRecord['payment_method'] ?? '',
                    ],
                ];
            }
            continue;
        }

        // Category count
        $cat = trim($category) ?: '(blank)';
        $stats['category_counts'][$cat] = ($stats['category_counts'][$cat] ?? 0) + 1;

        // Total amount
        $stats['total_amount'] += $amount;

        // Refund
        if ($amount < 0 || $status === 'refunded') {
            $stats['refunds']++;
            $stats['refund_amount'] += $amount;
        }

        // Account match (student or parent)
        $cacheKey = strtolower($name['first'] . '|' . $name['last']);
        if (!isset($studentCache[$cacheKey])) {
            $studentCache[$cacheKey] = findAnyAccountByName($pdo, $name['first'], $name['last']);
        }
        $student = $studentCache[$cacheKey];

        if ($student) {
            $stats['matched']++;
        } else {
            if ($usedBuyerFallback) {
                // Will create a parent account
                $stats['new_parents']++;
                if (!isset($stats['new_parent_names'][$cacheKey])) {
                    $stats['new_parent_names'][$cacheKey] = [
                        'name'  => $name['first'] . ' ' . $name['last'],
                        'count' => 0,
                        'total' => 0.0,
                    ];
                }
                $stats['new_parent_names'][$cacheKey]['count']++;
                $stats['new_parent_names'][$cacheKey]['total'] += $amount;
            } else {
                // Will create a student account
                $stats['new_students']++;
                if (!isset($stats['new_student_names'][$cacheKey])) {
                    $stats['new_student_names'][$cacheKey] = [
                        'name'  => $name['first'] . ' ' . $name['last'],
                        'count' => 0,
                        'total' => 0.0,
                    ];
                }
                $stats['new_student_names'][$cacheKey]['count']++;
                $stats['new_student_names'][$cacheKey]['total'] += $amount;
            }
        }

        // Collect first 25 rows for preview table
        if (count($stats['preview_rows']) < 25) {
            $stats['preview_rows'][] = [
                'row_num'        => $i + 1,
                'date'           => $dateStr,
                'participant'    => $name['first'] . ' ' . $name['last'],
                'category'       => $category,
                'amount'         => $amount,
                'status'         => $statusStr,
                'method'         => $row['Payment Method'] ?? '',
                'matched'        => $student ? true : false,
                'new_student'    => (!$student && !$usedBuyerFallback),
                'new_parent'     => (!$student && $usedBuyerFallback),
                'buyer_fallback' => $usedBuyerFallback,
                'student_id'     => $student ? $student['id'] : null,
                'detail'         => trim($row['Detail'] ?? ''),
            ];
        }
    }

    return $stats;
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
                $rows = parsePaymentsCsv($_FILES['csv_file']['tmp_name']);
                if (empty($rows)) {
                    $message = showAlert('No valid data rows found in the CSV file.', 'error');
                    $step = 1;
                } else {
                    // Validate required headers
                    $requiredHeaders = ['Participant', 'Payment Processing Date', 'Gross', 'Status', 'Transaction ID'];
                    $csvHeaders = array_keys($rows[0]);
                    $missing = array_diff($requiredHeaders, $csvHeaders);
                    if (!empty($missing)) {
                        $message = showAlert('CSV is missing required columns: ' . implode(', ', $missing), 'error');
                        $step = 1;
                    } else {
                        $_SESSION['payment_import_rows']     = $rows;
                        $_SESSION['payment_import_filename'] = $_FILES['csv_file']['name'];
                        header('Location: import_payments.php?step=2');
                        exit;
                    }
                }
            }
        }
    }

    // --- Step 2/3: Execute Import ---
    if (isset($_POST['action']) && $_POST['action'] === 'execute_import') {
        $rows = $_SESSION['payment_import_rows'] ?? [];
        if (empty($rows)) {
            $message = showAlert('No import data found. Please upload a CSV file first.', 'error');
            $step = 1;
        } else {
            $isDryRun = !empty($_POST['dry_run']);
            $reimportTxnIds = $_POST['reimport_txn'] ?? [];
            $results = executePaymentImport($pdo, $rows, [
                'dry_run'          => $isDryRun,
                'update_emails'    => isset($_POST['update_emails']),
                'reimport_txn_ids' => $reimportTxnIds,
            ]);
            $_SESSION['payment_import_results'] = $results;
            $_SESSION['payment_import_options'] = [
                'update_emails'    => isset($_POST['update_emails']),
                'reimport_txn_ids' => $reimportTxnIds,
            ];
            header('Location: import_payments.php?step=3');
            exit;
        }
    }

    // --- Step 3: Confirm (run for real after dry run) ---
    if (isset($_POST['action']) && $_POST['action'] === 'confirm_import') {
        $rows    = $_SESSION['payment_import_rows'] ?? [];
        $options = $_SESSION['payment_import_options'] ?? [];
        if (empty($rows)) {
            $message = showAlert('No import data found. Please start over.', 'error');
            $step = 1;
        } else {
            $results = executePaymentImport($pdo, $rows, [
                'dry_run'          => false,
                'update_emails'    => $options['update_emails'] ?? true,
                'reimport_txn_ids' => $options['reimport_txn_ids'] ?? [],
            ]);
            $_SESSION['payment_import_results'] = $results;
            // Clear import data after real import
            unset($_SESSION['payment_import_rows'], $_SESSION['payment_import_filename'], $_SESSION['payment_import_options']);
            header('Location: import_payments.php?step=3');
            exit;
        }
    }

    // --- Clear / Start Over ---
    if (isset($_POST['action']) && $_POST['action'] === 'clear') {
        unset($_SESSION['payment_import_rows'], $_SESSION['payment_import_filename'], $_SESSION['payment_import_results'], $_SESSION['payment_import_options']);
        header('Location: import_payments.php');
        exit;
    }
}

// Load data for display
$importRows     = $_SESSION['payment_import_rows'] ?? [];
$importResults  = $_SESSION['payment_import_results'] ?? null;
$importFilename = $_SESSION['payment_import_filename'] ?? '';

// Step 2: analyze payment rows for preview
$analysisStats = null;
if ($step === 2 && !empty($importRows)) {
    $analysisStats = analyzePaymentRows($pdo, $importRows);
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Import Payment Transactions</h1>
        <div class="space-x-3">
            <a href="import_data.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Import Students</a>
            <a href="import_payments.php" class="px-4 py-2 rounded-lg font-medium text-sm <?php echo $step <= 3 ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">Import Payments</a>
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
        <h2 class="text-xl font-bold text-gray-800 mb-4">Upload MyStudio Payment Transactions CSV</h2>
        <p class="text-gray-600 mb-6">Upload the Payment Details CSV exported from MyStudio. Transactions are matched to existing students by name. Any participants not yet in the system will have accounts auto-created.</p>

        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-blue-800 mb-2">Expected CSV Format</h3>
            <p class="text-sm text-blue-700 mb-2">The CSV should contain these columns:</p>
            <code class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded block overflow-x-auto">
                Payment Processing Date, Status, Buyer, Participant, Category, Detail, Gross, Payment Method, Transaction ID
            </code>
        </div>

        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-green-800 mb-2">&#128100; Auto-Creates Missing Students</h3>
            <p class="text-sm text-green-700">
                Transactions are matched to existing student accounts by participant name.
                If a participant is not found, a new student account is <strong>automatically created</strong> so no financial data is lost.
                For best results, <a href="import_data.php" class="underline font-medium">import student accounts</a> first — but it's not required.
            </p>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="upload">
            <?php echo csrf_field(); ?>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select Payment CSV File *</label>
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
elseif ($step === 2 && !empty($importRows) && $analysisStats): ?>

    <div class="bg-white rounded-lg shadow-lg p-8 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-gray-800">Payment CSV Preview</h2>
            <div class="text-sm text-gray-500">
                File: <strong><?php echo htmlspecialchars($importFilename); ?></strong> &middot;
                <strong><?php echo number_format($analysisStats['total_rows']); ?></strong> transactions
            </div>
        </div>

        <?php if ($analysisStats['date_min'] && $analysisStats['date_max']): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
            <span class="text-sm text-blue-800">
                <strong>&#128197; Date Range:</strong>
                <?php echo date('M j, Y', strtotime($analysisStats['date_min'])); ?> &mdash;
                <?php echo date('M j, Y', strtotime($analysisStats['date_max'])); ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <?php $step2CardCount = 6 + (count($analysisStats['new_parent_names']) > 0 ? 1 : 0); ?>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-<?php echo $step2CardCount; ?> gap-4 mb-6">
            <div class="bg-blue-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-blue-600"><?php echo number_format($analysisStats['total_rows']); ?></div>
                <div class="text-sm text-gray-600">Total Transactions</div>
            </div>
            <div class="bg-green-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-green-600"><?php echo number_format($analysisStats['matched']); ?></div>
                <div class="text-sm text-gray-600">Matched Students</div>
            </div>
            <div class="bg-<?php echo $analysisStats['new_students'] > 0 ? 'purple' : 'green'; ?>-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-<?php echo $analysisStats['new_students'] > 0 ? 'purple' : 'green'; ?>-600"><?php echo number_format(count($analysisStats['new_student_names'])); ?></div>
                <div class="text-sm text-gray-600">New Students</div>
            </div>
            <?php if (count($analysisStats['new_parent_names']) > 0): ?>
            <div class="bg-indigo-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-indigo-600"><?php echo number_format(count($analysisStats['new_parent_names'])); ?></div>
                <div class="text-sm text-gray-600">New Parents</div>
            </div>
            <?php endif; ?>
            <div class="bg-yellow-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-yellow-600"><?php echo number_format($analysisStats['duplicates']); ?></div>
                <div class="text-sm text-gray-600">Duplicates (Skip)</div>
            </div>
            <div class="bg-orange-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-orange-600"><?php echo number_format($analysisStats['refunds']); ?></div>
                <div class="text-sm text-gray-600">Refunds</div>
            </div>
            <div class="bg-teal-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-teal-600">$<?php echo number_format($analysisStats['total_amount'], 2); ?></div>
                <div class="text-sm text-gray-600">Total Amount</div>
            </div>
        </div>

        <!-- Category Breakdown -->
        <?php if (!empty($analysisStats['category_counts'])): ?>
        <div class="bg-gray-50 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-gray-700 mb-3">Category Breakdown</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                <?php foreach ($analysisStats['category_counts'] as $cat => $cnt): ?>
                <div>
                    <span class="text-gray-500"><?php echo htmlspecialchars($cat); ?></span>
                    &rarr; <span class="font-medium text-green-700"><?php echo htmlspecialchars(mapCategory($cat)); ?></span>
                    <span class="text-gray-400">(<?php echo number_format($cnt); ?>)</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Field Mapping -->
        <div class="bg-gray-50 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-gray-700 mb-3">Field Mapping</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
                <div><span class="text-gray-500">Participant</span> &rarr; <span class="font-medium text-green-700">Student (by name)</span></div>
                <div><span class="text-gray-500">Payment Processing Date</span> &rarr; <span class="font-medium text-green-700">payment_date</span></div>
                <div><span class="text-gray-500">Gross</span> &rarr; <span class="font-medium text-green-700">amount</span></div>
                <div><span class="text-gray-500">Category</span> &rarr; <span class="font-medium text-green-700">payment_type</span></div>
                <div><span class="text-gray-500">Payment Method</span> &rarr; <span class="font-medium text-green-700">payment_method</span></div>
                <div><span class="text-gray-500">Transaction ID</span> &rarr; <span class="font-medium text-green-700">receipt_number</span></div>
                <div><span class="text-gray-500">Detail + Other Details</span> &rarr; <span class="font-medium text-green-700">notes</span></div>
            </div>
        </div>

        <!-- New Students to be Auto-Created -->
        <?php if (!empty($analysisStats['new_student_names'])): ?>
        <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-purple-800 mb-2">&#128100; <?php echo count($analysisStats['new_student_names']); ?> New Student Account<?php echo count($analysisStats['new_student_names']) !== 1 ? 's' : ''; ?> Will Be Created</h3>
            <p class="text-sm text-purple-700 mb-3">These participants don't have existing accounts. Student accounts will be auto-created so their payment history is preserved. They'll use the default password <code class="bg-purple-100 px-1 rounded font-mono">Procomp123</code> and must complete registration on first login.</p>
            <div class="max-h-48 overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-left text-xs font-medium text-purple-600 uppercase px-2 py-1">Name</th>
                            <th class="text-right text-xs font-medium text-purple-600 uppercase px-2 py-1">Transactions</th>
                            <th class="text-right text-xs font-medium text-purple-600 uppercase px-2 py-1">Total $</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analysisStats['new_student_names'] as $info): ?>
                        <tr>
                            <td class="px-2 py-1 text-purple-800 font-medium"><?php echo htmlspecialchars($info['name']); ?></td>
                            <td class="px-2 py-1 text-right text-purple-700"><?php echo $info['count']; ?></td>
                            <td class="px-2 py-1 text-right text-purple-700">$<?php echo number_format($info['total'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- New Parents to be Auto-Created (Buyer fallback) -->
        <?php if (!empty($analysisStats['new_parent_names'])): ?>
        <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-indigo-800 mb-2">&#128101; <?php echo count($analysisStats['new_parent_names']); ?> New Parent Account<?php echo count($analysisStats['new_parent_names']) !== 1 ? 's' : ''; ?> Will Be Created</h3>
            <p class="text-sm text-indigo-700 mb-3">These transactions have no participant name, so the <strong>Buyer</strong> name is used instead. Since the buyer was not found as an existing student or parent, a new <strong>parent account</strong> will be created to hold their payment history. Default password: <code class="bg-indigo-100 px-1 rounded font-mono">Procomp123</code>.</p>
            <div class="max-h-48 overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-left text-xs font-medium text-indigo-600 uppercase px-2 py-1">Name (Buyer)</th>
                            <th class="text-right text-xs font-medium text-indigo-600 uppercase px-2 py-1">Transactions</th>
                            <th class="text-right text-xs font-medium text-indigo-600 uppercase px-2 py-1">Total $</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analysisStats['new_parent_names'] as $info): ?>
                        <tr>
                            <td class="px-2 py-1 text-indigo-800 font-medium"><?php echo htmlspecialchars($info['name']); ?></td>
                            <td class="px-2 py-1 text-right text-indigo-700"><?php echo $info['count']; ?></td>
                            <td class="px-2 py-1 text-right text-indigo-700">$<?php echo number_format($info['total'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Preview Table -->
        <div class="overflow-x-auto mb-6">
            <h3 class="font-semibold text-gray-700 mb-3">Transaction Preview (first 25)</h3>
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Participant</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Detail</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Match</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($analysisStats['preview_rows'] as $pRow): ?>
                    <tr class="<?php echo $pRow['new_parent'] ? 'bg-indigo-50' : (!$pRow['matched'] ? 'bg-purple-50' : ''); ?>">
                        <td class="px-3 py-2 text-gray-500"><?php echo $pRow['row_num']; ?></td>
                        <td class="px-3 py-2 text-gray-600"><?php echo htmlspecialchars($pRow['date']); ?></td>
                        <td class="px-3 py-2 font-medium text-gray-800">
                            <?php echo htmlspecialchars($pRow['participant']); ?>
                            <?php if ($pRow['buyer_fallback']): ?>
                                <span class="ml-1 px-1.5 py-0.5 text-xs rounded bg-indigo-100 text-indigo-700">Buyer</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-gray-600"><?php echo htmlspecialchars($pRow['category']); ?></td>
                        <td class="px-3 py-2 text-gray-500 text-xs max-w-xs truncate"><?php echo htmlspecialchars($pRow['detail']); ?></td>
                        <td class="px-3 py-2 text-right <?php echo $pRow['amount'] < 0 ? 'text-red-600 font-semibold' : 'text-gray-800'; ?>">
                            $<?php echo number_format($pRow['amount'], 2); ?>
                        </td>
                        <td class="px-3 py-2">
                            <?php
                            $stColor = strtolower($pRow['status']) === 'completed' ? 'green' : (stripos($pRow['status'], 'refund') !== false ? 'orange' : 'gray');
                            ?>
                            <span class="px-2 py-0.5 text-xs rounded-full bg-<?php echo $stColor; ?>-100 text-<?php echo $stColor; ?>-800">
                                <?php echo htmlspecialchars($pRow['status']); ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 text-center">
                            <?php if ($pRow['matched']): ?>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800">&#10003; ID:<?php echo $pRow['student_id']; ?></span>
                            <?php elseif ($pRow['new_parent']): ?>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-indigo-100 text-indigo-800">+ New Parent</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-800">+ New Student</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($analysisStats['total_rows'] > 25): ?>
                    <tr>
                        <td colspan="8" class="px-3 py-3 text-center text-gray-500 text-sm italic">
                            ... and <?php echo number_format($analysisStats['total_rows'] - 25); ?> more transactions
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

        <form method="POST" class="space-y-6">
            <input type="hidden" name="action" value="execute_import">
            <?php echo csrf_field(); ?>

            <!-- Options Checkboxes -->
            <div class="space-y-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="update_emails" value="1" checked class="w-5 h-5 text-blue-600 rounded">
                    <div>
                        <span class="font-medium text-gray-700">Update Student Emails</span>
                        <p class="text-xs text-gray-500">Fill in missing student email addresses from payment CSV data</p>
                    </div>
                </label>
            </div>

            <!-- Auto-create info -->
            <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                <h3 class="font-semibold text-purple-800 mb-2">&#128100; Auto-Create Accounts</h3>
                <p class="text-sm text-purple-700">
                    Participants not found in the database will have a <strong>student account</strong> automatically created.
                    Transactions with no participant (buyer-only) will have a <strong>parent account</strong> created instead.
                    All auto-created accounts use the default password <code class="bg-purple-100 px-1 rounded font-mono">Procomp123</code> and must complete their profile on first login.
                </p>
            </div>

            <!-- Duplicate Handling -->
            <?php if (!empty($analysisStats['duplicate_details'])): ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <h3 class="font-semibold text-yellow-800 mb-2">&#128260; Duplicate Transactions Found (<?php echo $analysisStats['duplicates']; ?> total, <?php echo count($analysisStats['duplicate_details']); ?> already in DB)</h3>
                <p class="text-sm text-yellow-700 mb-3">
                    Each CSV transaction is shown alongside the <strong>existing DB record</strong> it matched.
                    Compare the details — if they differ, it may be a separate purchase under the same Transaction ID.
                    Check the box to <strong>re-import with a new receipt number</strong>.
                </p>
                <div class="flex items-center gap-2 mb-3">
                    <label class="flex items-center gap-2 cursor-pointer text-sm text-yellow-800">
                        <input type="checkbox" id="selectAllDups" class="w-4 h-4 text-yellow-600 rounded" title="Select/Deselect All">
                        <span class="font-medium">Select All for Reimport</span>
                    </label>
                </div>
                <div class="max-h-[32rem] overflow-y-auto space-y-3">
                    <?php foreach ($analysisStats['duplicate_details'] as $dupIdx => $dup):
                        $match = $dup['match'] ?? null;
                        $isDiff = false;
                        if ($match) {
                            $matchAmt = isset($match['amount']) ? (float)$match['amount'] : null;
                            if ($matchAmt !== null && abs($matchAmt - $dup['amount']) > 0.01) $isDiff = true;
                            if (!empty($match['detail']) && !empty($dup['detail']) && stripos($match['detail'], $dup['detail']) === false && stripos($dup['detail'], $match['detail']) === false) $isDiff = true;
                            if (!empty($match['participant']) && strtolower($match['participant']) !== strtolower($dup['participant'])) $isDiff = true;
                        }
                    ?>
                    <div class="bg-white rounded border <?php echo $isDiff ? 'border-red-300 ring-1 ring-red-200' : 'border-yellow-200'; ?> overflow-hidden">
                        <!-- Header bar with checkbox -->
                        <div class="flex items-center justify-between px-3 py-1.5 <?php echo $isDiff ? 'bg-red-50' : 'bg-yellow-100'; ?> text-xs">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="reimport_txn[]" value="<?php echo htmlspecialchars($dup['composite_key'] ?? $dup['transaction_id']); ?>"
                                       class="dup-checkbox w-4 h-4 text-yellow-600 rounded" <?php echo $isDiff ? 'checked' : ''; ?>>
                                <span class="font-mono text-gray-500"><?php echo htmlspecialchars($dup['transaction_id']); ?></span>
                            </div>
                            <?php if ($isDiff): ?>
                                <span class="px-2 py-0.5 rounded-full bg-red-100 text-red-800 font-semibold">&#9888; Differences — Likely Separate Purchase</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-medium">Records Match</span>
                            <?php endif; ?>
                        </div>
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="px-3 py-1.5 text-left text-xs font-medium text-gray-400 uppercase w-28"></th>
                                    <th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                    <th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500 uppercase">Detail</th>
                                    <th class="px-3 py-1.5 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                    <th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500 uppercase">Type/Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($match): ?>
                                <!-- Existing DB record -->
                                <tr class="bg-green-50 border-b border-green-100">
                                    <td class="px-3 py-1.5">
                                        <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800 font-medium">In DB</span>
                                    </td>
                                    <td class="px-3 py-1.5 text-gray-600"><?php echo htmlspecialchars($match['date'] ?? ''); ?></td>
                                    <td class="px-3 py-1.5 text-gray-800"><?php echo htmlspecialchars($match['participant'] ?? ''); ?></td>
                                    <td class="px-3 py-1.5 text-gray-500 text-xs max-w-xs truncate"><?php echo htmlspecialchars($match['detail'] ?? ''); ?></td>
                                    <td class="px-3 py-1.5 text-right text-gray-800">
                                        <?php if (isset($match['amount'])): ?>$<?php echo number_format((float)$match['amount'], 2); ?><?php endif; ?>
                                    </td>
                                    <td class="px-3 py-1.5 text-xs text-gray-600">
                                        <?php echo htmlspecialchars(($match['type'] ?? '') . (!empty($match['method']) ? ' / ' . $match['method'] : '')); ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <!-- CSV row -->
                                <tr class="bg-yellow-50">
                                    <td class="px-3 py-1.5">
                                        <span class="px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-800 font-medium">CSV Row <?php echo $dup['row_num']; ?></span>
                                    </td>
                                    <td class="px-3 py-1.5 text-gray-600 <?php echo ($match && isset($match['date']) && $match['date'] !== $dup['date']) ? 'font-semibold text-red-700' : ''; ?>">
                                        <?php echo htmlspecialchars($dup['date']); ?>
                                    </td>
                                    <td class="px-3 py-1.5 text-gray-800 <?php echo ($match && isset($match['participant']) && strtolower($match['participant'] ?? '') !== strtolower($dup['participant'])) ? 'font-semibold text-red-700' : ''; ?>">
                                        <?php echo htmlspecialchars($dup['participant']); ?>
                                    </td>
                                    <td class="px-3 py-1.5 text-xs max-w-xs truncate <?php
                                        $detailDiff = ($match && !empty($match['detail']) && !empty($dup['detail']) && stripos($match['detail'], $dup['detail']) === false && stripos($dup['detail'], $match['detail']) === false);
                                        echo $detailDiff ? 'font-semibold text-red-700' : 'text-gray-500';
                                    ?>">
                                        <?php echo htmlspecialchars($dup['detail']); ?>
                                    </td>
                                    <td class="px-3 py-1.5 text-right <?php echo ($match && isset($match['amount']) && abs((float)$match['amount'] - $dup['amount']) > 0.01) ? 'font-semibold text-red-700' : ($dup['amount'] < 0 ? 'text-red-600' : 'text-gray-800'); ?>">
                                        $<?php echo number_format($dup['amount'], 2); ?>
                                    </td>
                                    <td class="px-3 py-1.5 text-xs text-gray-600">
                                        <?php echo htmlspecialchars($dup['status']); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-yellow-600 mt-2">
                    <strong>Tip:</strong> Re-imported transactions get a new system-generated receipt number. The original Transaction ID is preserved in the notes.
                    Items with differences are auto-checked for reimport.
                </p>
            </div>
            <script>
            document.getElementById('selectAllDups')?.addEventListener('change', function() {
                document.querySelectorAll('.dup-checkbox').forEach(cb => cb.checked = this.checked);
            });
            </script>
            <?php else: ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <h3 class="font-semibold text-yellow-800 mb-2">Duplicate Protection</h3>
                <p class="text-sm text-yellow-700">
                    Transactions with a Transaction ID that already exists in the database will be automatically skipped.
                    <?php if ($analysisStats['duplicates'] > 0): ?>
                        <strong><?php echo $analysisStats['duplicates']; ?> duplicate<?php echo $analysisStats['duplicates'] !== 1 ? 's' : ''; ?></strong> found within the CSV itself (will be skipped).
                    <?php else: ?>
                        No duplicates detected — this CSV is safe to import.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="flex justify-between items-center pt-4 border-t">
                <div><!-- Start Over is a separate form below --></div>
                <div class="space-x-3">
                    <button type="submit" name="dry_run" value="1" class="bg-gray-600 hover:bg-gray-700 text-white font-medium px-6 py-3 rounded-lg">
                        Dry Run Preview
                    </button>
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-medium px-6 py-3 rounded-lg"
                            onclick="return confirm('This will import all payment transactions and auto-create any missing student/parent accounts. Are you sure?')">
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
            <?php echo $isDryRun ? '&#128269; Dry Run Preview — No changes were made' : '&#10003; Payment Import Complete!'; ?>
        </h2>
        <p class="text-sm text-<?php echo $isDryRun ? 'blue' : 'green'; ?>-700 mt-1">
            <?php echo $isDryRun ? 'Review the results below, then confirm to run the actual import.' : 'All payment transactions have been imported successfully.'; ?>
        </p>
    </div>

    <!-- Date Range Banner -->
    <?php if ($importResults['date_min'] && $importResults['date_max']): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
        <span class="text-sm text-blue-800">
            <strong>&#128197; Transaction Date Range:</strong>
            <?php echo date('M j, Y', strtotime($importResults['date_min'])); ?> &mdash;
            <?php echo date('M j, Y', strtotime($importResults['date_max'])); ?>
        </span>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <?php
    $dupsReimported = $importResults['duplicates_reimported'] ?? 0;
    $parentsCreated = $importResults['parents_created'] ?? 0;
    $step3CardCount = 6 + ($dupsReimported > 0 ? 1 : 0) + ($parentsCreated > 0 ? 1 : 0);
    ?>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-<?php echo $step3CardCount; ?> gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-green-600"><?php echo number_format($importResults['payments_created']); ?></div>
            <div class="text-xs text-gray-600">Payments Created</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-orange-600"><?php echo number_format($importResults['refunds_created']); ?></div>
            <div class="text-xs text-gray-600">Refunds</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-yellow-600"><?php echo number_format($importResults['payments_skipped']); ?></div>
            <div class="text-xs text-gray-600">Skipped (Dups)</div>
        </div>
        <?php if ($dupsReimported > 0): ?>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-amber-600"><?php echo number_format($dupsReimported); ?></div>
            <div class="text-xs text-gray-600">Reimported</div>
        </div>
        <?php endif; ?>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-purple-600"><?php echo number_format($importResults['students_created']); ?></div>
            <div class="text-xs text-gray-600">Students Created</div>
        </div>
        <?php if ($importResults['parents_created'] > 0): ?>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-indigo-600"><?php echo number_format($importResults['parents_created']); ?></div>
            <div class="text-xs text-gray-600">Parents Created</div>
        </div>
        <?php endif; ?>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-blue-600"><?php echo number_format($importResults['emails_updated']); ?></div>
            <div class="text-xs text-gray-600">Emails Updated</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-teal-600">$<?php echo number_format($importResults['total_amount'], 2); ?></div>
            <div class="text-xs text-gray-600">Total Amount</div>
        </div>
    </div>

    <!-- Auto-Created Students Detail -->
    <?php if (!empty($importResults['created_students'])): ?>
    <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 mb-6">
        <h3 class="font-semibold text-purple-800 mb-2">&#128100; <?php echo count($importResults['created_students']); ?> Student Account<?php echo count($importResults['created_students']) !== 1 ? 's' : ''; ?> Auto-Created</h3>
        <p class="text-sm text-purple-700 mb-3">These participants were not found in the database, so new student accounts were created automatically. Default password: <code class="bg-purple-100 px-1 rounded font-mono">Procomp123</code>. They must complete registration on first login.</p>
        <div class="max-h-48 overflow-y-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left text-xs font-medium text-purple-600 uppercase px-2 py-1">Name</th>
                        <th class="text-right text-xs font-medium text-purple-600 uppercase px-2 py-1">Transactions</th>
                        <th class="text-right text-xs font-medium text-purple-600 uppercase px-2 py-1">Total $</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($importResults['created_students'] as $info): ?>
                    <tr>
                        <td class="px-2 py-1 text-purple-800 font-medium"><?php echo htmlspecialchars($info['name']); ?></td>
                        <td class="px-2 py-1 text-right text-purple-700"><?php echo $info['count']; ?></td>
                        <td class="px-2 py-1 text-right text-purple-700">$<?php echo number_format($info['total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Auto-Created Parents Detail (Buyer fallback) -->
    <?php if (!empty($importResults['created_parents'])): ?>
    <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 mb-6">
        <h3 class="font-semibold text-indigo-800 mb-2">&#128101; <?php echo count($importResults['created_parents']); ?> Parent Account<?php echo count($importResults['created_parents']) !== 1 ? 's' : ''; ?> Auto-Created</h3>
        <p class="text-sm text-indigo-700 mb-3">These transactions had no participant name, so the Buyer name was used. Since the buyer was not found as an existing account, new parent accounts were created. Default password: <code class="bg-indigo-100 px-1 rounded font-mono">Procomp123</code>.</p>
        <div class="max-h-48 overflow-y-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left text-xs font-medium text-indigo-600 uppercase px-2 py-1">Name (Buyer)</th>
                        <th class="text-right text-xs font-medium text-indigo-600 uppercase px-2 py-1">Transactions</th>
                        <th class="text-right text-xs font-medium text-indigo-600 uppercase px-2 py-1">Total $</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($importResults['created_parents'] as $info): ?>
                    <tr>
                        <td class="px-2 py-1 text-indigo-800 font-medium"><?php echo htmlspecialchars($info['name']); ?></td>
                        <td class="px-2 py-1 text-right text-indigo-700"><?php echo $info['count']; ?></td>
                        <td class="px-2 py-1 text-right text-indigo-700">$<?php echo number_format($info['total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Skipped Duplicates Detail -->
    <?php if (!empty($importResults['skipped_duplicates'])): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
        <h3 class="font-semibold text-yellow-800 mb-2">&#128260; <?php echo count($importResults['skipped_duplicates']); ?> Duplicate Transaction<?php echo count($importResults['skipped_duplicates']) !== 1 ? 's' : ''; ?> Skipped</h3>
        <p class="text-sm text-yellow-700 mb-3">
            Each skipped transaction is shown with the <strong>existing record</strong> it matched against.
            Compare the details — if they differ, the CSV row may be a separate purchase under the same Transaction ID.
            <strong>DB</strong> = already in database, <strong>CSV</strong> = repeated within the CSV file.
            <?php if ($isDryRun): ?>
                To re-import specific DB duplicates, go back to Step 2 and check the ones you want to reimport.
            <?php endif; ?>
        </p>
        <div class="max-h-[32rem] overflow-y-auto space-y-3">
            <?php foreach ($importResults['skipped_duplicates'] as $dupIdx => $dup):
                $match = $dup['match'] ?? null;
                $isDiff = false;
                if ($match) {
                    // Check if key fields differ between the skipped row and match
                    $matchAmt = isset($match['amount']) ? (float)$match['amount'] : null;
                    $matchDetail = $match['detail'] ?? '';
                    $matchParticipant = $match['participant'] ?? '';
                    if ($matchAmt !== null && abs($matchAmt - $dup['amount']) > 0.01) $isDiff = true;
                    if (!empty($matchDetail) && !empty($dup['detail']) && stripos($matchDetail, $dup['detail']) === false && stripos($dup['detail'], $matchDetail) === false) $isDiff = true;
                    if (!empty($matchParticipant) && strtolower($matchParticipant) !== strtolower($dup['participant'])) $isDiff = true;
                }
            ?>
            <div class="bg-white rounded border <?php echo $isDiff ? 'border-red-300 ring-1 ring-red-200' : 'border-yellow-200'; ?> overflow-hidden">
                <!-- Header bar -->
                <div class="flex items-center justify-between px-3 py-1.5 <?php echo $isDiff ? 'bg-red-50' : 'bg-yellow-100'; ?> text-xs">
                    <div class="flex items-center gap-2">
                        <span class="font-bold <?php echo $isDiff ? 'text-red-800' : 'text-yellow-800'; ?>">#<?php echo $dupIdx + 1; ?></span>
                        <span class="px-2 py-0.5 rounded-full <?php echo $dup['source'] === 'db' ? 'bg-orange-100 text-orange-800' : 'bg-gray-200 text-gray-700'; ?> font-medium">
                            <?php echo strtoupper($dup['source']); ?>
                        </span>
                        <span class="font-mono text-gray-400"><?php echo htmlspecialchars($dup['transaction_id']); ?></span>
                    </div>
                    <?php if ($isDiff): ?>
                        <span class="px-2 py-0.5 rounded-full bg-red-100 text-red-800 font-semibold">&#9888; Differences Found</span>
                    <?php endif; ?>
                </div>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="px-3 py-1.5 text-left text-xs font-medium text-gray-400 uppercase w-24"></th>
                            <th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500 uppercase">Detail</th>
                            <th class="px-3 py-1.5 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500 uppercase">Status/Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($match): ?>
                        <!-- Existing record (match) -->
                        <tr class="bg-green-50 border-b border-green-100">
                            <td class="px-3 py-1.5">
                                <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800 font-medium">
                                    <?php echo $dup['source'] === 'db' ? 'In DB' : 'CSV Row ' . ($match['row_num'] ?? '?'); ?>
                                </span>
                            </td>
                            <td class="px-3 py-1.5 text-gray-600"><?php echo htmlspecialchars($match['date'] ?? ''); ?></td>
                            <td class="px-3 py-1.5 text-gray-800"><?php echo htmlspecialchars($match['participant'] ?? ''); ?></td>
                            <td class="px-3 py-1.5 text-gray-500 text-xs max-w-xs truncate"><?php echo htmlspecialchars($match['detail'] ?? ''); ?></td>
                            <td class="px-3 py-1.5 text-right text-gray-800">
                                <?php if (isset($match['amount'])): ?>$<?php echo number_format((float)$match['amount'], 2); ?><?php endif; ?>
                            </td>
                            <td class="px-3 py-1.5 text-xs text-gray-600">
                                <?php if ($dup['source'] === 'db'): ?>
                                    <?php echo htmlspecialchars(($match['type'] ?? '') . ($match['method'] ? ' / ' . $match['method'] : '')); ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($match['status'] ?? ''); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <!-- Skipped CSV row -->
                        <tr class="bg-yellow-50">
                            <td class="px-3 py-1.5">
                                <span class="px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-800 font-medium">
                                    CSV Row <?php echo $dup['row_num']; ?>
                                </span>
                            </td>
                            <td class="px-3 py-1.5 text-gray-600 <?php echo ($match && isset($match['date']) && $match['date'] !== $dup['date']) ? 'font-semibold text-red-700' : ''; ?>">
                                <?php echo htmlspecialchars($dup['date']); ?>
                            </td>
                            <td class="px-3 py-1.5 text-gray-800 <?php echo ($match && isset($match['participant']) && strtolower($match['participant'] ?? '') !== strtolower($dup['participant'])) ? 'font-semibold text-red-700' : ''; ?>">
                                <?php echo htmlspecialchars($dup['participant']); ?>
                            </td>
                            <td class="px-3 py-1.5 text-xs max-w-xs truncate <?php
                                $detailDiff = ($match && !empty($match['detail']) && !empty($dup['detail']) && stripos($match['detail'], $dup['detail']) === false && stripos($dup['detail'], $match['detail']) === false);
                                echo $detailDiff ? 'font-semibold text-red-700' : 'text-gray-500';
                            ?>">
                                <?php echo htmlspecialchars($dup['detail']); ?>
                            </td>
                            <td class="px-3 py-1.5 text-right <?php echo ($match && isset($match['amount']) && abs((float)$match['amount'] - $dup['amount']) > 0.01) ? 'font-semibold text-red-700' : ($dup['amount'] < 0 ? 'text-red-600' : 'text-gray-800'); ?>">
                                $<?php echo number_format($dup['amount'], 2); ?>
                            </td>
                            <td class="px-3 py-1.5 text-xs text-gray-600">
                                <?php echo htmlspecialchars($dup['status']); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

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

    <!-- Action Buttons -->
    <div class="flex justify-between items-center">
        <form method="POST">
            <input type="hidden" name="action" value="clear">
            <?php echo csrf_field(); ?>
            <button type="submit" class="text-gray-600 hover:text-gray-800 font-medium">&#8592; Start Over</button>
        </form>
        <div class="space-x-3">
            <?php if ($isDryRun && !empty($_SESSION['payment_import_rows'])): ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="confirm_import">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold px-8 py-3 rounded-lg"
                            onclick="return confirm('This will permanently import all payment transactions and create any missing student/parent accounts. Continue?')">
                        &#10003; Confirm &amp; Import For Real
                    </button>
                </form>
            <?php else: ?>
                <a href="reports.php" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-6 py-3 rounded-lg inline-block">View Reports</a>
                <a href="students.php" class="bg-purple-600 hover:bg-purple-700 text-white font-medium px-6 py-3 rounded-lg inline-block">View Students</a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // Clear results from session (but keep rows for confirm action)
    if (!$isDryRun) {
        unset($_SESSION['payment_import_results']);
    }
    ?>

<?php else: ?>
    <!-- Fallback: redirect to step 1 -->
    <div class="text-center py-12">
        <p class="text-gray-600 mb-4">No import data found. Please upload a CSV file to get started.</p>
        <a href="import_payments.php" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-6 py-3 rounded-lg">Start Payment Import</a>
    </div>
<?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>
