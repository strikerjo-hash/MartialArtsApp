<?php
require_once 'config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/parent_auth.php';
require_once __DIR__ . '/includes/payment_gateway.php';
requireLogin();
if (!canView('import_data.php')) {
    accessDenied('Import/Export requires appropriate permissions.');
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
 * Normalize a phone number: strip +1, spaces, dashes, parens, newlines.
 */
function normalizePhone(string $phone): ?string
{
    $phone = preg_replace('/[\s\r\n]+/', '', $phone);         // strip whitespace/newlines
    $phone = preg_replace('/\(Not.*$/i', '', $phone);         // strip "(Not ...)" suffixes
    $phone = preg_replace('/\(Is.*$/i', '', $phone);          // strip "(Is ...)" suffixes
    $phone = preg_replace('/[^\d]/', '', $phone);             // digits only
    if (strlen($phone) === 11 && $phone[0] === '1') {
        $phone = substr($phone, 1);                           // strip leading 1 for US numbers
    }
    if (strlen($phone) < 7) return null;
    // Format as (XXX) XXX-XXXX if 10 digits
    if (strlen($phone) === 10) {
        return '(' . substr($phone, 0, 3) . ') ' . substr($phone, 3, 3) . '-' . substr($phone, 6);
    }
    return $phone;
}

/**
 * Reformat CSV address from "street,city,ST,US,zip" to "street, city, ST zip".
 */
function formatAddress(string $addr): ?string
{
    $addr = trim($addr);
    if (empty($addr)) return null;
    $parts = array_map('trim', explode(',', $addr));
    // Remove "US" if present
    $parts = array_filter($parts, fn($p) => strtoupper($p) !== 'US');
    $parts = array_values($parts);
    if (count($parts) >= 4) {
        // street, city, ST, zip
        $street = $parts[0];
        // Check if parts[1] looks like an apartment/unit (starts with Apt, #, Suite, etc.)
        $city = $parts[count($parts) - 3];
        $state = $parts[count($parts) - 2];
        $zip = $parts[count($parts) - 1];
        // Everything before city is part of the street address
        $streetParts = array_slice($parts, 0, count($parts) - 3);
        $street = implode(', ', $streetParts);
        return $street . ', ' . $city . ', ' . $state . ' ' . $zip;
    }
    return implode(', ', $parts);
}

/**
 * Extract custom field value from a CSV row by field label.
 */
function getCustomFieldValue(array $row, string $fieldLabel): ?string
{
    for ($i = 1; $i <= 3; $i++) {
        $fieldKey = "Custom Field $i";
        $valueKey = "Custom Value $i";
        if (isset($row[$fieldKey]) && stripos(trim($row[$fieldKey]), $fieldLabel) !== false) {
            $val = trim($row[$valueKey] ?? '');
            return $val !== '' ? $val : null;
        }
    }
    return null;
}

/**
 * Parse a CSV Membership column string into structured data.
 *
 * Input formats:
 *   "ProComp Kids Karate, $125/Month for 9 months"
 *   "Little Ninjas PreSchool LifeSkills, 9 Month Membership Paid in Full"
 *   "Competitive Elite (Current Members), Recurring monthly plan"
 *   "Warrior Weapons, 12 Month Upgrade Paid in Full (Tuition and Upgrade)"
 *
 * Returns: ['base_name', 'pricing', 'monthly_rate', 'duration_months',
 *           'is_paid_in_full', 'classes_per_week', 'is_afterschool']
 */
function parseMembershipString(string $membership, string $nextPaymentAmount = '0', string $attendanceLimits = ''): array
{
    $result = [
        'base_name'        => '',
        'pricing'          => '',
        'monthly_rate'     => 0.0,
        'duration_months'  => 9,     // default
        'is_paid_in_full'  => false,
        'classes_per_week' => 2,     // default
        'is_afterschool'   => false,
        'is_camp'          => false,
        'is_addon'         => false, // Must be coupled with a base program (e.g. Warriors Weapons, Competitive Elite)
        'is_removed'       => false, // Program being discontinued (e.g. Kidjitsu) — skip entirely
        'billing_frequency'=> 'monthly',
    ];

    if (empty(trim($membership))) return $result;

    // Split on first comma
    $parts = explode(',', $membership, 2);
    $result['base_name'] = trim($parts[0]);
    $result['pricing']   = trim($parts[1] ?? '');

    // Normalize base name — strip location prefixes
    $normalized = preg_replace('/^TYLER[\s-]*/i', '', $result['base_name']);
    $result['base_name'] = $normalized;

    // Detect afterschool
    if (stripos($membership, 'afterschool') !== false || stripos($membership, 'after school') !== false) {
        $result['is_afterschool'] = true;
    }

    // Detect camp programs
    if (preg_match('/\b(daycamp|day\s*camp|summer\s*camp|camp)\b/i', $membership)) {
        $result['is_camp'] = true;
    }

    // Detect addon-only programs (must be coupled with a base program)
    if (preg_match('/\bwarriors?\s*weapons?\b/i', $membership) || preg_match('/\bcompetitive\s*elite\b/i', $membership)) {
        $result['is_addon'] = true;
    }

    // Detect removed/discontinued programs — skip entirely
    if (preg_match('/\bkidjitsu\b/i', $membership)) {
        $result['is_removed'] = true;
    }

    // Detect paid in full
    if (stripos($membership, 'paid in full') !== false) {
        $result['is_paid_in_full'] = true;
        $result['billing_frequency'] = 'upfront';
    }

    // Parse duration from pricing text
    if (preg_match('/(\d+)\s*month/i', $result['pricing'], $dm)) {
        $result['duration_months'] = (int) $dm[1];
    } elseif (preg_match('/(\d+)\s*month/i', $membership, $dm)) {
        $result['duration_months'] = (int) $dm[1];
    }

    // Parse monthly rate from pricing text ("$125/Month")
    if (preg_match('/\$(\d+(?:\.\d+)?)\s*\/\s*month/i', $result['pricing'], $pm)) {
        $result['monthly_rate'] = (float) $pm[1];
    }

    // If no rate parsed from text, use Next Payment Amount
    $nextPay = (float) $nextPaymentAmount;
    if ($result['monthly_rate'] <= 0 && $nextPay > 0) {
        // For paid-in-full, the next payment is the total — compute monthly
        if ($result['is_paid_in_full'] && $result['duration_months'] > 0) {
            $result['monthly_rate'] = round($nextPay / $result['duration_months'], 2);
        } else {
            $result['monthly_rate'] = $nextPay;
        }
    }

    // If STILL no rate but we have pricing text with a dollar amount
    if ($result['monthly_rate'] <= 0) {
        if (preg_match('/\$(\d+(?:\.\d+)?)/', $result['pricing'], $anyPrice)) {
            $result['monthly_rate'] = (float) $anyPrice[1];
        }
    }

    // Parse classes per week from attendance limits
    if (preg_match('/(\d+)\s*per\s*week/i', $attendanceLimits, $cpw)) {
        $result['classes_per_week'] = (int) $cpw[1];
    }

    // Recurring monthly plan → set to 1 month duration (ongoing)
    if (stripos($result['pricing'], 'recurring monthly') !== false && $result['duration_months'] === 9) {
        $result['duration_months'] = 1; // month-to-month
    }

    return $result;
}

/**
 * Find the closest existing plan by monthly rate.
 *
 * Matching criteria (in order):
 * 1. Exact match: same name + same monthly rate (within $1)
 * 2. Closest monthly rate: find the plan whose monthly rate is nearest to the target
 *
 * Does NOT create new plans — only matches to existing ones.
 * Returns: ['id' => plan_id|0, 'name' => string, 'monthly_rate' => float, 'matched' => bool]
 */
function findClosestPlan(
    PDO $pdo,
    string $planName,
    float $targetMonthlyRate,
    array &$planCache
): array {
    $noMatch = ['id' => 0, 'name' => '', 'monthly_rate' => 0, 'matched' => false];

    if ($targetMonthlyRate <= 0) return $noMatch;

    // Check cache first
    $cacheKey = 'rate|' . number_format($targetMonthlyRate, 2);
    if (isset($planCache[$cacheKey])) {
        return $planCache[$cacheKey];
    }

    // Detect if is_camp column exists (may not yet if migration hasn't run)
    static $hasCampCol = null;
    if ($hasCampCol === null) {
        try {
            $pdo->query("SELECT is_camp FROM membership_plans LIMIT 1");
            $hasCampCol = true;
        } catch (\PDOException $e) {
            $hasCampCol = false;
        }
    }
    $campFilter = $hasCampCol ? "AND (is_camp = 0 OR is_camp IS NULL)" : "";

    // 1. Try exact match by name + monthly rate (within $1)
    $matchParams = [$planName, $targetMonthlyRate];
    school_param($matchParams);
    $matchStmt = $pdo->prepare("
        SELECT id, name, price, duration_months
        FROM membership_plans
        WHERE LOWER(TRIM(name)) = LOWER(?)
        AND ABS(price / GREATEST(duration_months, 1) - ?) < 1.00
        AND (is_afterschool = 0 OR is_afterschool IS NULL)
        {$campFilter}" . school_where() . "
        LIMIT 1
    ");
    $matchStmt->execute($matchParams);
    $match = $matchStmt->fetch();

    if ($match) {
        $result = [
            'id'           => (int) $match['id'],
            'name'         => $match['name'],
            'monthly_rate' => round($match['price'] / max(1, $match['duration_months']), 2),
            'matched'      => true,
        ];
        $planCache[$cacheKey] = $result;
        return $result;
    }

    // 2. Find the closest plan by monthly rate (exclude afterschool/camp plans from matching)
    $closestParams = [$targetMonthlyRate];
    school_param($closestParams);
    $closestStmt = $pdo->prepare("
        SELECT id, name, price, duration_months,
               ABS(price / GREATEST(duration_months, 1) - ?) AS rate_diff
        FROM membership_plans
        WHERE (is_afterschool = 0 OR is_afterschool IS NULL)
        {$campFilter}" . school_where() . "
        ORDER BY rate_diff ASC
        LIMIT 1
    ");
    $closestStmt->execute($closestParams);
    $closest = $closestStmt->fetch();

    if ($closest) {
        $result = [
            'id'           => (int) $closest['id'],
            'name'         => $closest['name'],
            'monthly_rate' => round($closest['price'] / max(1, $closest['duration_months']), 2),
            'matched'      => true,
        ];
        $planCache[$cacheKey] = $result;
        return $result;
    }

    // No plans exist at all
    $planCache[$cacheKey] = $noMatch;
    return $noMatch;
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

        // Resolve past due from either column name
        $rowPastDue = (float) ($row['Bill Past Due'] ?? $row['Past Due'] ?? 0);

        // Build membership entry for this row
        $rowMembership = trim($row['Membership'] ?? '');
        $rowNextPay    = trim($row['Next Payment Amount'] ?? '0');
        $rowAttLimits  = trim($row['Attendance Limits'] ?? '');
        $rowEndDate    = trim($row['End Date'] ?? '');
        $rowNextPayDate= trim($row['Next Payment Date'] ?? '');

        if (!isset($merged[$key])) {
            // First occurrence — store as-is
            $merged[$key] = $row;
            $merged[$key]['_total_payments_sum'] = (float) ($row['Total Payments'] ?? $row['Next Payment Amount'] ?? 0);
            $merged[$key]['_past_due_sum']       = $rowPastDue;
            $merged[$key]['_csv_row_count']      = 1;
            // Track ALL memberships for this student (for multi-plan handling)
            $merged[$key]['_all_memberships']    = [];
            if (!empty($rowMembership)) {
                $merged[$key]['_all_memberships'][] = [
                    'membership'        => $rowMembership,
                    'next_payment'      => $rowNextPay,
                    'attendance_limits' => $rowAttLimits,
                    'end_date'          => $rowEndDate,
                    'next_payment_date' => $rowNextPayDate,
                ];
            }
        } else {
            $dupeCount++;
            $merged[$key]['_csv_row_count']++;

            // Track additional memberships
            if (!empty($rowMembership)) {
                $merged[$key]['_all_memberships'][] = [
                    'membership'        => $rowMembership,
                    'next_payment'      => $rowNextPay,
                    'attendance_limits' => $rowAttLimits,
                    'end_date'          => $rowEndDate,
                    'next_payment_date' => $rowNextPayDate,
                ];
            }

            // Sum financial data
            $merged[$key]['_total_payments_sum'] += (float) ($row['Total Payments'] ?? $row['Next Payment Amount'] ?? 0);
            // For past due, keep the HIGHEST amount (not sum — same student may appear on multiple plans)
            if ($rowPastDue > $merged[$key]['_past_due_sum']) {
                $merged[$key]['_past_due_sum'] = $rowPastDue;
            }

            // Keep the earliest join date (check both column name formats)
            $existingDateStr = $merged[$key]['Registration Date'] ?? $merged[$key]['Participant Since'] ?? '';
            $newDateStr = $row['Registration Date'] ?? $row['Participant Since'] ?? '';
            $existingDate = parseMyStudioDate($existingDateStr);
            $newDate      = parseMyStudioDate($newDateStr);
            if ($newDate && $existingDate && $newDate < $existingDate) {
                $merged[$key]['Registration Date'] = $newDateStr;
                $merged[$key]['Participant Since'] = $newDateStr;
            }

            // Keep customer (parent) info from the most recent row (last seen)
            $cf = trim($row['Customer First Name'] ?? '');
            $cl = trim($row['Customer Last Name'] ?? '');
            if (!empty($cf) && !empty($cl)) {
                $merged[$key]['Customer First Name'] = $row['Customer First Name'];
                $merged[$key]['Customer Last Name']  = $row['Customer Last Name'];
            }

            // Preserve non-empty profile fields from later rows if first was blank
            foreach (['Mobile Phone', 'Email', 'Birthday', 'Rank', 'Address'] as $field) {
                if (empty(trim($merged[$key][$field] ?? '')) && !empty(trim($row[$field] ?? ''))) {
                    $merged[$key][$field] = $row[$field];
                }
            }
            // Preserve non-empty custom fields
            for ($ci = 1; $ci <= 3; $ci++) {
                $cfKey = "Custom Field $ci";
                $cvKey = "Custom Value $ci";
                if (empty(trim($merged[$key][$cvKey] ?? '')) && !empty(trim($row[$cvKey] ?? ''))) {
                    $merged[$key][$cfKey] = $row[$cfKey] ?? '';
                    $merged[$key][$cvKey] = $row[$cvKey];
                }
            }
        }
    }

    // Write the summed financials back into the standard CSV columns
    $result = [];
    foreach ($merged as $row) {
        if (isset($row['_total_payments_sum'])) {
            $row['Total Payments'] = number_format($row['_total_payments_sum'], 2, '.', '');
            $row['Past Due']       = number_format($row['_past_due_sum'], 2, '.', '');
            $row['Bill Past Due']  = number_format($row['_past_due_sum'], 2, '.', '');
        }
        $result[] = $row;
    }

    return ['rows' => $result, 'csv_dupes_merged' => $dupeCount];
}

/**
 * Find duplicate students by email (primary) or first_name + last_name (fallback).
 */
function findDuplicateStudents(PDO $pdo, array $rows): array
{
    $duplicates = [];

    // Email match (most reliable)
    $emailStmt = $pdo->prepare(
        'SELECT id, first_name, last_name, join_date, email, phone
         FROM students
         WHERE LOWER(TRIM(email)) = LOWER(?) AND email IS NOT NULL AND email != \'\'' . school_where()
    );

    // Name match (fallback)
    $nameStmt = $pdo->prepare(
        'SELECT id, first_name, last_name, join_date, email, phone
         FROM students
         WHERE LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?)' . school_where()
    );

    foreach ($rows as $i => $row) {
        $match = null;
        $email = trim($row['Email'] ?? '');

        // Try email match first
        if (!empty($email)) {
            $params = [$email];
            school_param($params);
            $emailStmt->execute($params);
            $match = $emailStmt->fetch();
        }

        // Fall back to name match
        if (!$match) {
            $params = [
                trim($row['Participant First Name'] ?? ''),
                trim($row['Participant Last Name'] ?? ''),
            ];
            school_param($params);
            $nameStmt->execute($params);
            $match = $nameStmt->fetch();
        }

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
        'students_created'       => 0,
        'students_skipped'       => 0,
        'students_merged'        => 0,
        'students_deactivated'   => 0,
        'adults_self_enrolled'   => 0,
        'parents_created'        => 0,
        'parent_links_created'   => 0,
        'memberships_created'    => 0,
        'plans_unmatched'        => 0,
        'unmatched_students'     => [],  // students whose total monthly cost didn't closely match a plan
        'errors'                 => [],
        'details'                => [],
        'is_dry_run'             => $options['dry_run'] ?? true,
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
                $parentSql = "SELECT id, LOWER(CONCAT(TRIM(first_name), '|', TRIM(last_name))) as name_key,
                              email, phone
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

        // Plan cache for closest-plan matching (keyed by rate → result array)
        $planCache = [];
        $createMemberships = $options['create_memberships'] ?? true;

        // Deduplicate CSV rows by participant name first
        $dedup = deduplicateCSVRows($rows);
        $dedupedRows = $dedup['rows'];
        $results['csv_dupes_merged'] = $dedup['csv_dupes_merged'];

        // Duplicate check against DB — email first, then name
        $dupEmailStmt = $pdo->prepare(
            'SELECT id, first_name, last_name, email, phone, date_of_birth, address, belt_rank,
                    medical_info, school_district, account_credit, status, notes
             FROM students
             WHERE LOWER(TRIM(email)) = LOWER(?) AND email IS NOT NULL AND email != \'\'' . school_where()
        );
        $dupNameStmt = $pdo->prepare(
            'SELECT id, first_name, last_name, email, phone, date_of_birth, address, belt_rank,
                    medical_info, school_district, account_credit, status, notes
             FROM students
             WHERE LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?)' . school_where()
        );

        foreach ($dedupedRows as $i => $row) {
            // --- Parse all CSV fields ---
            $firstName     = trim($row['Participant First Name'] ?? '');
            $lastName      = trim($row['Participant Last Name'] ?? '');
            $joinDateStr   = $row['Registration Date'] ?? $row['Participant Since'] ?? '';
            $joinDate      = parseMyStudioDate($joinDateStr);
            $pastDue       = (float) ($row['Bill Past Due'] ?? $row['Past Due'] ?? $row['_past_due_sum'] ?? 0);
            $custFirst     = trim($row['Customer First Name'] ?? '');
            $custLast      = trim($row['Customer Last Name'] ?? '');
            $csvRowCount   = (int) ($row['_csv_row_count'] ?? 1);

            // Extended fields
            $importEmail   = trim($row['Email'] ?? $row['email'] ?? '');
            $importPhone   = normalizePhone($row['Mobile Phone'] ?? '');
            $importDob     = parseMyStudioDate($row['Birthday'] ?? '');
            $importAddress = formatAddress($row['Address'] ?? '');
            $importRank    = trim($row['Rank'] ?? '');
            if (empty($importRank)) $importRank = 'White';
            $importMedical    = getCustomFieldValue($row, 'Restrictions');
            $importDistrict   = getCustomFieldValue($row, 'School District');

            // Skip rows with no name
            if (empty($firstName) || empty($lastName)) {
                $results['errors'][] = "Row " . ($i + 1) . ": Missing name, skipped.";
                continue;
            }
            if (!$joinDate) {
                // Use today if no date available
                $joinDate = date('Y-m-d');
            }

            // Check for duplicate in DB — email first, then name
            $existing = null;
            if (!empty($importEmail)) {
                $dupParams = [$importEmail];
                school_param($dupParams);
                $dupEmailStmt->execute($dupParams);
                $existing = $dupEmailStmt->fetch();
            }
            if (!$existing) {
                $dupParams = [$firstName, $lastName];
                school_param($dupParams);
                $dupNameStmt->execute($dupParams);
                $existing = $dupNameStmt->fetch();
            }

            $studentId = null;

            if ($existing) {
                $studentId = (int) $existing['id'];

                if ($dupAction === 'skip') {
                    $results['students_skipped']++;
                    $results['details'][] = [
                        'name'   => "$firstName $lastName",
                        'action' => 'skipped',
                        'reason' => "Duplicate (existing ID: {$existing['id']})",
                    ];
                } else {
                    // Merge: update missing fields, handle past due
                    $results['students_merged']++;
                    $mergeUpdates = [];
                    $mergeParams = [];

                    // Fill in blank fields on existing record
                    if (empty($existing['phone']) && $importPhone) {
                        $mergeUpdates[] = 'phone = ?';
                        $mergeParams[] = $importPhone;
                    }
                    if (empty($existing['email']) && !empty($importEmail)) {
                        $mergeUpdates[] = 'email = ?';
                        $mergeParams[] = $importEmail;
                    }
                    if (empty($existing['date_of_birth']) && $importDob) {
                        $mergeUpdates[] = 'date_of_birth = ?';
                        $mergeParams[] = $importDob;
                    }
                    if (empty($existing['address']) && $importAddress) {
                        $mergeUpdates[] = 'address = ?';
                        $mergeParams[] = $importAddress;
                    }
                    if ((empty($existing['belt_rank']) || $existing['belt_rank'] === 'White') && $importRank !== 'White') {
                        $mergeUpdates[] = 'belt_rank = ?';
                        $mergeParams[] = $importRank;
                    }
                    if (empty($existing['medical_info']) && $importMedical) {
                        $mergeUpdates[] = 'medical_info = ?';
                        $mergeParams[] = $importMedical;
                    }
                    if (empty($existing['school_district']) && $importDistrict) {
                        $mergeUpdates[] = 'school_district = ?';
                        $mergeParams[] = $importDistrict;
                    }

                    // Handle past due balance
                    $deactivatedNote = '';
                    if ($pastDue > 0) {
                        $mergeUpdates[] = 'account_credit = ?';
                        $mergeParams[] = -abs($pastDue);

                        if ($existing['status'] === 'active') {
                            $mergeUpdates[] = "status = 'inactive'";
                            $mergeUpdates[] = 'inactive_since = ?';
                            $mergeParams[] = date('Y-m-d');
                            $deactivatedNote = ' | DEACTIVATED (past due: $' . number_format($pastDue, 2) . ')';
                            $results['students_deactivated']++;
                        }
                    }

                    if (!empty($mergeUpdates) && !$isDryRun) {
                        $mergeParams[] = $studentId;
                        school_param($mergeParams);
                        $pdo->prepare("UPDATE students SET " . implode(', ', $mergeUpdates) . " WHERE id = ?" . school_where())
                            ->execute($mergeParams);

                        // Run deactivation cascade for past due
                        if ($pastDue > 0 && $existing['status'] === 'active') {
                            deactivate_student_cascade($studentId, 'payment');
                        }
                    }

                    $fieldCount = count(array_filter($mergeUpdates, fn($u) => strpos($u, '?') !== false));
                    $results['details'][] = [
                        'name'   => "$firstName $lastName",
                        'action' => 'merged',
                        'reason' => "Merged with existing ID: {$existing['id']} ({$fieldCount} field(s) updated)" . $deactivatedNote,
                    ];
                }
            } else {
                // Create new student with default password "Procomp123"
                $isDeactivated = ($pastDue > 0);
                $newStatus = $isDeactivated ? 'inactive' : 'active';

                if (!$isDryRun) {
                    $username = generateUniqueUsername($pdo, $firstName, $lastName);
                    $defaultPasswordHash = password_hash('Procomp123', PASSWORD_DEFAULT);

                    $pdo->prepare("
                        INSERT INTO students (school_id, first_name, last_name, username, email, phone,
                                              date_of_birth, address, belt_rank, join_date, status, notes,
                                              is_parent, password_hash, must_change_password, registration_incomplete,
                                              medical_info, school_district, account_credit, inactive_since)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 1, 1, ?, ?, ?, ?)
                    ")->execute([
                        current_school_id(),
                        sanitizeInput($firstName),
                        sanitizeInput($lastName),
                        $username,
                        $importEmail ?: null,
                        $importPhone,
                        $importDob,
                        $importAddress,
                        sanitizeInput($importRank),
                        $joinDate,
                        $newStatus,
                        null, // notes
                        $defaultPasswordHash,
                        $importMedical,
                        $importDistrict,
                        $pastDue > 0 ? -abs($pastDue) : 0.00,
                        $isDeactivated ? date('Y-m-d') : null,
                    ]);
                    $studentId = (int) $pdo->lastInsertId();

                    // Run deactivation cascade for past due students
                    if ($isDeactivated) {
                        deactivate_student_cascade($studentId, 'payment');
                    }
                }

                if ($isDeactivated) {
                    $results['students_deactivated']++;
                }

                $results['students_created']++;
                $mergeNote = $csvRowCount > 1 ? " (merged from {$csvRowCount} CSV rows)" : '';
                $deactivatedNote = $isDeactivated ? " | DEACTIVATED (past due: \$" . number_format($pastDue, 2) . ")" : '';
                $results['details'][] = [
                    'name'   => "$firstName $lastName",
                    'action' => 'created',
                    'reason' => ($isDryRun ? 'New student (dry run)' : "New student (ID: {$studentId})") . $mergeNote . $deactivatedNote,
                    'past_due' => $pastDue,
                ];
            }

            // --- Membership plan matching & creation (multi-plan aware) ---
            $allMemberships = $row['_all_memberships'] ?? [];
            // Fallback: if no _all_memberships array (single-row student), build from CSV columns
            if (empty($allMemberships)) {
                $csvMembership = trim($row['Membership'] ?? '');
                if (!empty($csvMembership)) {
                    $allMemberships = [[
                        'membership'        => $csvMembership,
                        'next_payment'      => trim($row['Next Payment Amount'] ?? '0'),
                        'attendance_limits' => trim($row['Attendance Limits'] ?? ''),
                        'end_date'          => trim($row['End Date'] ?? ''),
                        'next_payment_date' => trim($row['Next Payment Date'] ?? ''),
                    ]];
                }
            }

            if ($createMemberships && $studentId && !empty($allMemberships)) {
                // Parse all plans and separate into regular vs afterschool/camp
                $regularPlans = [];
                $specialPlans = []; // afterschool and camp — get separate memberships

                foreach ($allMemberships as $memEntry) {
                    $planInfo = parseMembershipString($memEntry['membership'], $memEntry['next_payment'], $memEntry['attendance_limits']);
                    $planInfo['_end_date']          = $memEntry['end_date'];
                    $planInfo['_next_payment_date']  = $memEntry['next_payment_date'];
                    $planInfo['_raw_membership']     = $memEntry['membership'];

                    if ($planInfo['monthly_rate'] <= 0 && !$planInfo['is_paid_in_full']) {
                        continue; // Skip plans with no pricing
                    }

                    // Skip removed/discontinued programs (e.g. Kidjitsu)
                    if ($planInfo['is_removed']) {
                        continue;
                    }

                    if ($planInfo['is_afterschool'] || $planInfo['is_camp']) {
                        $specialPlans[] = $planInfo;
                    } else {
                        $regularPlans[] = $planInfo;
                    }
                }

                // --- Handle regular plans only: sum monthly costs → find closest existing match ---
                // Afterschool/camp programs are disregarded (they change yearly)
                if (!empty($regularPlans)) {
                    // Check if student only has addon programs (no base program)
                    // Addons: Warriors Weapons, Competitive Elite — must be coupled with a base program
                    $basePlans  = array_filter($regularPlans, fn($rp) => !$rp['is_addon']);
                    $addonPlans = array_filter($regularPlans, fn($rp) => $rp['is_addon']);
                    $addonOnly  = empty($basePlans) && !empty($addonPlans);

                    // Sum up all regular plan monthly costs
                    $totalMonthlyRate = 0;
                    $planNames = [];
                    $anyPaidInFull = false;
                    $maxDuration = 0;

                    foreach ($regularPlans as $rp) {
                        $totalMonthlyRate += $rp['monthly_rate'];
                        $planNames[] = $rp['base_name'];
                        if ($rp['is_paid_in_full']) $anyPaidInFull = true;
                        $maxDuration = max($maxDuration, $rp['duration_months']);
                    }

                    $csvPlanLabel = count($regularPlans) > 1
                        ? implode(' + ', $planNames)
                        : $regularPlans[0]['base_name'];

                    // If student only has addon programs (no base), suggest Grandfathered Basic Plus
                    if ($addonOnly) {
                        $matchResult = findClosestPlan($pdo, 'Grandfathered Basic Plus', $totalMonthlyRate, $planCache);
                        // If exact name match didn't work, try finding by name alone
                        if (!$matchResult['matched'] || stripos($matchResult['name'], 'basic plus') === false) {
                            $nameParams = [];
                            school_param($nameParams);
                            $nameStmt = $pdo->prepare("SELECT id, name, price, duration_months FROM membership_plans WHERE LOWER(name) LIKE '%basic plus%'" . school_where() . " LIMIT 1");
                            $nameStmt->execute($nameParams);
                            $basicPlus = $nameStmt->fetch();
                            if ($basicPlus) {
                                $matchResult = [
                                    'id'           => (int) $basicPlus['id'],
                                    'name'         => $basicPlus['name'],
                                    'monthly_rate' => round($basicPlus['price'] / max(1, $basicPlus['duration_months']), 2),
                                    'matched'      => true,
                                ];
                            }
                        }
                        $csvPlanLabel .= ' (addon only → Basic Plus)';
                    } else {
                        // Normal matching: find the closest existing plan by total monthly rate
                        $matchResult = findClosestPlan($pdo, $csvPlanLabel, $totalMonthlyRate, $planCache);
                    }
                    $planId = $matchResult['id'];

                    // Create membership if a match was found
                    if ($matchResult['matched'] && $planId > 0) {
                        if (!$isDryRun) {
                            $existMemParams = [$studentId, $planId];
                            school_param($existMemParams);
                            $existMemStmt = $pdo->prepare("SELECT id FROM memberships WHERE student_id = ? AND plan_id = ? AND status IN ('active','on_hold')" . school_where() . " LIMIT 1");
                            $existMemStmt->execute($existMemParams);
                            if (!$existMemStmt->fetch()) {
                                $memStartDate = parseMyStudioDate($row['Registration Date'] ?? $row['Participant Since'] ?? '') ?: date('Y-m-d');
                                $memEndDate = parseMyStudioDate($regularPlans[0]['_end_date'] ?? '');
                                if (!$memEndDate) {
                                    $memEndDate = date('Y-m-d', strtotime($memStartDate . ' + ' . $maxDuration . ' months'));
                                    if ($memEndDate < date('Y-m-d') && $pastDue <= 0) {
                                        $memEndDate = date('Y-m-d', strtotime('+' . $maxDuration . ' months'));
                                    }
                                }

                                $billingDay = null;
                                $nextPayParsed = parseMyStudioDate($regularPlans[0]['_next_payment_date'] ?? '');
                                if ($nextPayParsed) {
                                    $billingDay = (int) date('j', strtotime($nextPayParsed));
                                }

                                $paymentStatus = ($pastDue > 0) ? 'pending' : 'paid';
                                $autoRenew = $anyPaidInFull ? 0 : 1;

                                $pdo->prepare("
                                    INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date,
                                                             status, payment_status, auto_renew, billing_day, notes)
                                    VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)
                                ")->execute([
                                    current_school_id(),
                                    $studentId,
                                    $planId,
                                    $memStartDate,
                                    $memEndDate,
                                    $paymentStatus,
                                    $autoRenew,
                                    $billingDay,
                                    'Imported — CSV: ' . $csvPlanLabel . ' ($' . number_format($totalMonthlyRate, 2) . '/mo) → Matched: ' . $matchResult['name'],
                                ]);
                                $results['memberships_created']++;
                            }
                        } else {
                            $results['memberships_created']++;
                        }
                    } else {
                        // No match found — track as unmatched
                        $results['plans_unmatched']++;
                        $unmatchedLabel = "$firstName $lastName — $csvPlanLabel (\$" . number_format($totalMonthlyRate, 2) . '/mo)';
                        if (!in_array($unmatchedLabel, $results['unmatched_students'])) {
                            $results['unmatched_students'][] = $unmatchedLabel;
                        }
                    }

                    // Add plan info to the detail entry
                    $lastIdx = count($results['details']) - 1;
                    if ($lastIdx >= 0) {
                        $results['details'][$lastIdx]['csv_monthly_total'] = $totalMonthlyRate;
                        $results['details'][$lastIdx]['csv_plans'] = $csvPlanLabel;
                        if ($matchResult['matched']) {
                            $results['details'][$lastIdx]['proposed_plan'] = $matchResult['name'];
                            $results['details'][$lastIdx]['monthly_rate'] = $matchResult['monthly_rate'];
                            $results['details'][$lastIdx]['rate_diff'] = abs($totalMonthlyRate - $matchResult['monthly_rate']);
                        } else {
                            $results['details'][$lastIdx]['proposed_plan'] = 'No match';
                            $results['details'][$lastIdx]['monthly_rate'] = $totalMonthlyRate;
                            $results['details'][$lastIdx]['rate_diff'] = -1;
                        }
                    }
                }
            }

            // --- Parent creation & linking ---
            if ($createParents && !empty($custFirst) && !empty($custLast)) {
                $isParentSameAsSelf = (
                    strtolower($custFirst) === strtolower($firstName) &&
                    strtolower($custLast) === strtolower($lastName)
                );

                if ($isParentSameAsSelf) {
                    $results['adults_self_enrolled']++;
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
                                INSERT INTO students (school_id, first_name, last_name, username, email, phone,
                                                      password_hash, join_date, belt_rank, status, is_parent,
                                                      notes, must_change_password, registration_incomplete)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'White', 'active', 1,
                                        '[MyStudio Import] Parent/Guardian account', 1, 1)
                            ")->execute([
                                current_school_id(),
                                sanitizeInput($custFirst),
                                sanitizeInput($custLast),
                                $parentUsername,
                                $importEmail ?: null,  // Share email from CSV
                                $importPhone,           // Share phone from CSV
                                $parentPasswordHash,
                                $joinDate,
                            ]);
                            $parentId = (int) $pdo->lastInsertId();
                        } else {
                            $parentId = -1;
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
                'dry_run'            => $isDryRun,
                'create_parents'     => isset($_POST['create_parents']),
                'create_memberships' => isset($_POST['create_memberships']),
                'duplicate_action'   => $_POST['duplicate_action'] ?? 'skip',
            ]);
            $_SESSION['import_results'] = $results;
            $_SESSION['import_options'] = [
                'create_parents'     => isset($_POST['create_parents']),
                'create_memberships' => isset($_POST['create_memberships']),
                'duplicate_action'   => $_POST['duplicate_action'] ?? 'skip',
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
            <a href="curriculum.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Manage Curriculum</a>
            <a href="import_data.php" class="px-4 py-2 rounded-lg font-medium text-sm <?php echo $step <= 3 ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">Import Students</a>
            <a href="import_payments.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Import Payments</a>
            <a href="import_curriculum.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Import Curriculum</a>
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
            <h3 class="font-semibold text-blue-800 mb-2">Expected CSV Format (MyStudio Export)</h3>
            <p class="text-sm text-blue-700 mb-2">The CSV should contain these columns (auto-detected):</p>
            <code class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded block overflow-x-auto">
                Participant First Name, Participant Last Name, Customer First Name, Customer Last Name, Mobile Phone, Email, Birthday, Rank, Registration Date, Address, Bill Past Due, Custom Field/Value 1-3
            </code>
            <p class="text-xs text-blue-600 mt-2">Membership/program data will be ignored. Students with a past due balance will be imported as inactive.</p>
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
            // Count unique parents, self-enrolled adults, and past due students (from deduplicated rows)
            $uniqueParents = [];
            $adultSelfCount = 0;
            $pastDueCount = 0;
            $totalPastDueAmount = 0;
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
                $rowPastDue = (float) ($row['Bill Past Due'] ?? $row['Past Due'] ?? 0);
                if ($rowPastDue > 0) {
                    $pastDueCount++;
                    $totalPastDueAmount += $rowPastDue;
                }
            }
            ?>
            <div class="bg-purple-50 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-purple-600"><?php echo count($uniqueParents); ?></div>
                <div class="text-sm text-gray-600">Unique Parents</div>
            </div>
        </div>
        <?php if ($pastDueCount > 0): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-6">
            <span class="text-sm text-red-800">
                <strong>&#9888; <?php echo $pastDueCount; ?> student<?php echo $pastDueCount !== 1 ? 's' : ''; ?> with past due balance</strong>
                totaling <strong>$<?php echo number_format($totalPastDueAmount, 2); ?></strong>.
                These accounts will be imported as <strong>inactive</strong> with negative credit balances.
            </span>
        </div>
        <?php endif; ?>
        <?php if ($adultSelfCount > 0): ?>
        <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3 mb-6">
            <span class="text-sm text-indigo-800">
                <strong>👤 <?php echo $adultSelfCount; ?> adult self-enrollment<?php echo $adultSelfCount !== 1 ? 's' : ''; ?> detected</strong>
                — Customer name matches Participant name. These will be imported as regular students without creating a separate parent account.
            </span>
        </div>
        <?php endif; ?>

        <!-- Plan Matching Preview -->
        <?php
        // Compute plan matching preview: group students by total regular monthly cost
        // Afterschool/camp plans are excluded from matching (they change yearly)
        $studentCostPreview = []; // key = total monthly rate → ['rate', 'students' => [], 'csv_plans' => []]
        try {
            $planParams = [];
            school_param($planParams);
            $planStmt = $pdo->prepare("SELECT id, name, price, duration_months, classes_per_week, status, COALESCE(is_afterschool,0) as is_afterschool, COALESCE(is_camp,0) as is_camp FROM membership_plans WHERE 1=1" . school_where() . " ORDER BY price ASC");
            $planStmt->execute($planParams);
            $existingPlansList = $planStmt->fetchAll();
        } catch (\PDOException $e) {
            // is_camp column may not exist yet — fall back without it
            $planParams = [];
            school_param($planParams);
            $planStmt = $pdo->prepare("SELECT id, name, price, duration_months, classes_per_week, status, COALESCE(is_afterschool,0) as is_afterschool FROM membership_plans WHERE 1=1" . school_where() . " ORDER BY price ASC");
            $planStmt->execute($planParams);
            $existingPlansList = $planStmt->fetchAll();
            foreach ($existingPlansList as &$_pl) { $_pl['is_camp'] = 0; }
            unset($_pl);
        }
        // Build list of regular plans only for matching
        $regularExistingPlans = array_filter($existingPlansList, fn($p) => empty($p['is_afterschool']) && empty($p['is_camp']));

        foreach ($dedupedPreviewRows as $pRow) {
            $pAllMems = $pRow['_all_memberships'] ?? [];
            // Fallback for single-row students
            if (empty($pAllMems)) {
                $pMem = trim($pRow['Membership'] ?? '');
                if (!empty($pMem)) {
                    $pAllMems = [['membership' => $pMem, 'next_payment' => trim($pRow['Next Payment Amount'] ?? '0'), 'attendance_limits' => trim($pRow['Attendance Limits'] ?? '')]];
                }
            }
            if (empty($pAllMems)) continue;

            // Parse all memberships and sum regular plan costs
            // Skip removed programs (Kidjitsu), exclude afterschool/camp
            $studentRegularTotal = 0;
            $studentPlanNames = [];
            $hasBasePlan = false;
            $hasAddonOnly = false;
            foreach ($pAllMems as $pmEntry) {
                $pInfo = parseMembershipString($pmEntry['membership'], $pmEntry['next_payment'] ?? '0', $pmEntry['attendance_limits'] ?? '');
                if (($pInfo['monthly_rate'] <= 0 && !$pInfo['is_paid_in_full']) || $pInfo['is_afterschool'] || $pInfo['is_camp'] || $pInfo['is_removed']) continue;
                $studentRegularTotal += $pInfo['monthly_rate'];
                $studentPlanNames[] = $pInfo['base_name'];
                if (!$pInfo['is_addon']) $hasBasePlan = true;
            }
            if ($studentRegularTotal <= 0) continue;
            $addonOnly = !$hasBasePlan && !empty($studentPlanNames);

            $rateKey = $addonOnly ? 'addon_' . number_format($studentRegularTotal, 2) : number_format($studentRegularTotal, 2);
            $studentName = trim(($pRow['Participant First Name'] ?? '') . ' ' . ($pRow['Participant Last Name'] ?? ''));
            if (!isset($studentCostPreview[$rateKey])) {
                if ($addonOnly) {
                    // Addon-only students → suggest Grandfathered Basic Plus
                    $closestPlan = null;
                    foreach ($regularExistingPlans as $ep) {
                        if (stripos($ep['name'], 'basic plus') !== false) {
                            $closestPlan = $ep;
                            $closestPlan['_monthly'] = $ep['duration_months'] > 0 ? round($ep['price'] / $ep['duration_months'], 2) : $ep['price'];
                            break;
                        }
                    }
                    $closestDiff = $closestPlan ? abs($closestPlan['_monthly'] - $studentRegularTotal) : -1;
                    $studentCostPreview[$rateKey] = [
                        'rate'        => $studentRegularTotal,
                        'csv_plans'   => implode(' + ', $studentPlanNames) . ' (addon only)',
                        'count'       => 0,
                        'matched'     => $closestPlan,
                        'rate_diff'   => $closestDiff,
                        'addon_only'  => true,
                    ];
                } else {
                    // Normal: find the closest existing regular plan by rate
                    $closestPlan = null;
                    $closestDiff = PHP_FLOAT_MAX;
                    foreach ($regularExistingPlans as $ep) {
                        $epMonthly = $ep['duration_months'] > 0 ? round($ep['price'] / $ep['duration_months'], 2) : $ep['price'];
                        $diff = abs($epMonthly - $studentRegularTotal);
                        if ($diff < $closestDiff) {
                            $closestDiff = $diff;
                            $closestPlan = $ep;
                            $closestPlan['_monthly'] = $epMonthly;
                        }
                    }
                    $studentCostPreview[$rateKey] = [
                        'rate'        => $studentRegularTotal,
                        'csv_plans'   => implode(' + ', $studentPlanNames),
                        'count'       => 0,
                        'matched'     => $closestPlan,
                        'rate_diff'   => $closestPlan ? $closestDiff : -1,
                    ];
                }
            }
            $studentCostPreview[$rateKey]['count']++;
        }
        ksort($studentCostPreview);
        $unmatchedPreviewCount = count(array_filter($studentCostPreview, fn($p) => $p['matched'] === null));
        ?>
        <?php if (!empty($studentCostPreview)): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-blue-800 mb-3">Plan Matching Preview — by Total Monthly Cost (<?php echo count($studentCostPreview); ?> unique cost levels)</h3>
            <p class="text-sm text-blue-700 mb-3">Each student's regular program costs are summed and matched to the closest existing plan. Afterschool, camp &amp; Kidjitsu programs are excluded. Addon-only students (Warriors Weapons / Competitive Elite with no base program) are matched to Basic Plus.</p>
            <?php if ($unmatchedPreviewCount > 0): ?>
                <p class="text-sm text-red-700 mb-3">&#9888; <?php echo $unmatchedPreviewCount; ?> cost level<?php echo $unmatchedPreviewCount !== 1 ? 's' : ''; ?> have no existing plans to match. Create the appropriate plans first or they won't get a membership assigned.</p>
            <?php endif; ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-blue-200">
                            <th class="px-3 py-1 text-left text-xs font-medium text-blue-600">CSV Programs</th>
                            <th class="px-3 py-1 text-right text-xs font-medium text-blue-600">CSV Total $/Mo</th>
                            <th class="px-3 py-1 text-center text-xs font-medium text-blue-600">Students</th>
                            <th class="px-3 py-1 text-left text-xs font-medium text-blue-600">Closest Existing Plan</th>
                            <th class="px-3 py-1 text-right text-xs font-medium text-blue-600">Plan $/Mo</th>
                            <th class="px-3 py-1 text-right text-xs font-medium text-blue-600">Diff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studentCostPreview as $sp): ?>
                        <tr class="border-b border-blue-100 <?php echo ($sp['matched'] === null) ? 'bg-red-50' : (!empty($sp['addon_only']) ? 'bg-amber-50' : ''); ?>">
                            <td class="px-3 py-1.5 text-gray-800">
                                <?php echo htmlspecialchars($sp['csv_plans']); ?>
                                <?php if (!empty($sp['addon_only'])): ?>
                                    <span class="ml-1 px-1.5 py-0.5 text-xs rounded bg-amber-100 text-amber-700">→ Basic Plus</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-1.5 text-right font-medium">$<?php echo number_format($sp['rate'], 2); ?></td>
                            <td class="px-3 py-1.5 text-center"><?php echo $sp['count']; ?></td>
                            <td class="px-3 py-1.5">
                                <?php if ($sp['matched']): ?>
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800"><?php echo htmlspecialchars($sp['matched']['name']); ?></span>
                                    <?php if (!empty($sp['matched']['status']) && $sp['matched']['status'] === 'inactive'): ?>
                                        <span class="px-1.5 py-0.5 text-xs rounded-full bg-amber-100 text-amber-800 ml-1">GF</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800">No Match</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-1.5 text-right">
                                <?php echo $sp['matched'] ? '$' . number_format($sp['matched']['_monthly'], 2) : '—'; ?>
                            </td>
                            <td class="px-3 py-1.5 text-right <?php echo ($sp['rate_diff'] > 5) ? 'text-orange-600 font-medium' : 'text-gray-500'; ?>">
                                <?php echo $sp['rate_diff'] >= 0 ? '$' . number_format($sp['rate_diff'], 2) : '—'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Column Mapping -->
        <div class="bg-gray-50 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-gray-700 mb-3">Column Mapping (Auto-Detected)</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                <div><span class="text-gray-500">Participant Name</span> &rarr; <span class="font-medium text-green-700">Student Name</span></div>
                <div><span class="text-gray-500">Customer Name</span> &rarr; <span class="font-medium text-green-700">Parent Account</span></div>
                <div><span class="text-gray-500">Mobile Phone</span> &rarr; <span class="font-medium text-green-700">Phone</span></div>
                <div><span class="text-gray-500">Email</span> &rarr; <span class="font-medium text-green-700">Email</span></div>
                <div><span class="text-gray-500">Birthday</span> &rarr; <span class="font-medium text-green-700">Date of Birth</span></div>
                <div><span class="text-gray-500">Rank</span> &rarr; <span class="font-medium text-green-700">Belt Rank</span></div>
                <div><span class="text-gray-500">Registration Date</span> &rarr; <span class="font-medium text-green-700">Join Date</span></div>
                <div><span class="text-gray-500">Address</span> &rarr; <span class="font-medium text-green-700">Address</span></div>
                <div><span class="text-gray-500">Restrictions/Allergies</span> &rarr; <span class="font-medium text-green-700">Medical Info</span></div>
                <div><span class="text-gray-500">School District</span> &rarr; <span class="font-medium text-green-700">School District</span></div>
                <div><span class="text-gray-500">Bill Past Due</span> &rarr; <span class="font-medium text-red-700">Negative Credit + Deactivate</span></div>
                <div><span class="text-gray-500">Membership</span> &rarr; <span class="font-medium text-blue-700">Closest Plan Match</span></div>
            </div>
        </div>

        <!-- Preview Table (first 20 deduplicated rows) -->
        <div class="overflow-x-auto mb-6">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Student Name</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Parent</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">DOB</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Belt</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Since</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Past Due</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach (array_slice($dedupedPreviewRows, 0, 20) as $i => $row):
                        $isDup = isset($duplicates[$i]);
                        $csvRowCount = (int) ($row['_csv_row_count'] ?? 1);
                        $prevPastDue = (float) ($row['Bill Past Due'] ?? $row['Past Due'] ?? 0);
                    ?>
                    <tr class="<?php echo $isDup ? 'bg-yellow-50' : ($prevPastDue > 0 ? 'bg-red-50' : ''); ?>">
                        <td class="px-3 py-2 text-gray-500"><?php echo $i + 1; ?></td>
                        <td class="px-3 py-2 font-medium text-gray-800">
                            <?php echo htmlspecialchars(($row['Participant First Name'] ?? '') . ' ' . ($row['Participant Last Name'] ?? '')); ?>
                            <?php if ($csvRowCount > 1): ?>
                                <span class="ml-1 px-1.5 py-0.5 text-xs rounded bg-cyan-100 text-cyan-700 font-medium" title="Merged from <?php echo $csvRowCount; ?> CSV rows"><?php echo $csvRowCount; ?>x</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-gray-600 text-xs">
                            <?php
                            $prevCF = trim($row['Customer First Name'] ?? '');
                            $prevCL = trim($row['Customer Last Name'] ?? '');
                            $prevPF = trim($row['Participant First Name'] ?? '');
                            $prevPL = trim($row['Participant Last Name'] ?? '');
                            $isSelf = (!empty($prevCF) && !empty($prevCL) && strtolower($prevCF) === strtolower($prevPF) && strtolower($prevCL) === strtolower($prevPL));
                            ?>
                            <?php if ($isSelf): ?>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-indigo-100 text-indigo-800 font-medium">Self</span>
                            <?php else: ?>
                                <?php echo htmlspecialchars($prevCF . ' ' . $prevCL); ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-gray-600 text-xs"><?php echo htmlspecialchars(normalizePhone($row['Mobile Phone'] ?? '') ?? ''); ?></td>
                        <td class="px-3 py-2 text-gray-600 text-xs"><?php echo htmlspecialchars($row['Birthday'] ?? ''); ?></td>
                        <td class="px-3 py-2 text-gray-600 text-xs"><?php echo htmlspecialchars($row['Rank'] ?? ''); ?></td>
                        <td class="px-3 py-2 text-gray-600 text-xs"><?php echo htmlspecialchars($row['Registration Date'] ?? $row['Participant Since'] ?? ''); ?></td>
                        <td class="px-3 py-2 text-right <?php echo $prevPastDue > 0 ? 'text-red-600 font-semibold' : 'text-gray-400'; ?>">
                            <?php if ($prevPastDue > 0): ?>
                                $<?php echo number_format($prevPastDue, 2); ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-center">
                            <?php if ($prevPastDue > 0): ?>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800" title="Will be deactivated">Deactivate</span>
                            <?php elseif ($isDup): ?>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-800">In DB</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800">New</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($dedupedPreviewRows) > 20): ?>
                    <tr>
                        <td colspan="9" class="px-3 py-3 text-center text-gray-500 text-sm italic">
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
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="create_memberships" value="1" checked class="w-5 h-5 text-blue-600 rounded">
                    <div>
                        <span class="font-medium text-gray-700">Match &amp; Assign Memberships</span>
                        <p class="text-xs text-gray-500">Sum each student's regular program costs and match to the closest existing plan. Afterschool &amp; camp programs are excluded from matching.</p>
                    </div>
                </label>
            </div>

            <!-- Duplicate Handling -->
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <h3 class="font-semibold text-yellow-800 mb-3">Duplicate Handling (<?php echo $duplicateCount; ?> already in database)</h3>
                <div class="space-y-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="duplicate_action" value="skip" class="text-blue-600">
                        <div>
                            <span class="font-medium text-gray-700">Skip Duplicates</span>
                            <span class="text-xs text-gray-500 ml-1">Don't modify existing records</span>
                        </div>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="duplicate_action" value="merge" checked class="text-blue-600">
                        <div>
                            <span class="font-medium text-gray-700">Update Existing Records</span>
                            <span class="text-xs text-gray-500 ml-1">Fill in missing profile fields (phone, DOB, address, belt, medical, district), set past due balance, create parent links</span>
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
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4 mb-4">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-green-600"><?php echo $importResults['students_created']; ?></div>
            <div class="text-xs text-gray-600">Students Created</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-blue-600"><?php echo $importResults['students_merged']; ?></div>
            <div class="text-xs text-gray-600">Updated</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-yellow-600"><?php echo $importResults['students_skipped']; ?></div>
            <div class="text-xs text-gray-600">Skipped</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-red-600"><?php echo $importResults['students_deactivated'] ?? 0; ?></div>
            <div class="text-xs text-gray-600">Deactivated</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-purple-600"><?php echo $importResults['parents_created']; ?></div>
            <div class="text-xs text-gray-600">Parents Created</div>
        </div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-indigo-600"><?php echo $importResults['parent_links_created']; ?></div>
            <div class="text-xs text-gray-600">Parent Links</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-teal-600"><?php echo $importResults['memberships_created'] ?? 0; ?></div>
            <div class="text-xs text-gray-600">Memberships Matched</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold <?php echo ($importResults['plans_unmatched'] ?? 0) > 0 ? 'text-orange-600' : 'text-gray-400'; ?>"><?php echo $importResults['plans_unmatched'] ?? 0; ?></div>
            <div class="text-xs text-gray-600">Unmatched</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-indigo-600"><?php echo $importResults['adults_self_enrolled'] ?? 0; ?></div>
            <div class="text-xs text-gray-600">Adults (Self)</div>
        </div>
    </div>

    <?php if (!empty($importResults['unmatched_students'])): ?>
    <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 mb-6">
        <h3 class="font-semibold text-orange-800 mb-2">&#9888; Unmatched Students (<?php echo count($importResults['unmatched_students']); ?>)</h3>
        <p class="text-sm text-orange-700 mb-2">These students' total monthly cost didn't match any existing plan. Create the appropriate plans and re-import, or assign them manually.</p>
        <ul class="text-sm text-orange-700 space-y-1 max-h-40 overflow-y-auto">
            <?php foreach ($importResults['unmatched_students'] as $us): ?>
                <li>&bull; <?php echo htmlspecialchars($us); ?></li>
            <?php endforeach; ?>
        </ul>
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
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Proposed Plan</th>
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
                        <td class="px-4 py-2 text-xs">
                            <?php if (!empty($detail['proposed_plan']) && $detail['proposed_plan'] !== 'No match'): ?>
                                <div>
                                    <span class="text-gray-800 font-medium"><?php echo htmlspecialchars($detail['proposed_plan']); ?></span>
                                    <span class="text-gray-500 ml-1">$<?php echo number_format($detail['monthly_rate'] ?? 0, 2); ?>/mo</span>
                                </div>
                                <?php if (isset($detail['csv_monthly_total'])): ?>
                                    <div class="text-gray-400 mt-0.5">CSV: $<?php echo number_format($detail['csv_monthly_total'], 2); ?>/mo
                                        <?php if (isset($detail['rate_diff']) && $detail['rate_diff'] > 0): ?>
                                            <span class="<?php echo $detail['rate_diff'] > 5 ? 'text-orange-600' : 'text-gray-400'; ?>">(&pm;$<?php echo number_format($detail['rate_diff'], 2); ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php elseif (!empty($detail['proposed_plan']) && $detail['proposed_plan'] === 'No match'): ?>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-orange-100 text-orange-700">No match — $<?php echo number_format($detail['csv_monthly_total'] ?? $detail['monthly_rate'] ?? 0, 2); ?>/mo</span>
                            <?php else: ?>
                                <span class="text-gray-400">—</span>
                            <?php endif; ?>
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
