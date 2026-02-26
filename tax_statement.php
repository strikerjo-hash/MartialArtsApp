<?php
/**
 * tax_statement.php — Admin Year-End Tax Report
 *
 * Lists all students/families who have tax-eligible payments for a given year.
 * Provides a printable per-student tax statement with provider info, EIN,
 * itemized payments, and yearly total.
 */

require_once 'config.php';
requireLogin();

$year = $_GET['year'] ?? (date('Y') - 1);
$viewStudentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
$printMode = isset($_GET['print']);

// Tax settings
$taxBusinessName = getSetting('tax_business_name', '');
$taxEin = getSetting('tax_id_ein', '');
$taxAddress = getSetting('tax_business_address', '');
$taxNote = getSetting('tax_statement_note', 'This statement is provided for informational purposes. Please consult your tax advisor.');

// Get available years
$yearParams = [];
$yearStmt = $pdo->prepare("SELECT DISTINCT YEAR(payment_date) as yr FROM payments " . school_where_clause() . " ORDER BY yr DESC");
school_param($yearParams);
$yearStmt->execute($yearParams);
$availableYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($availableYears)) {
    $availableYears = [date('Y')];
}

// ---------- Per-Student Detail View ----------
if ($viewStudentId > 0) {
    $studentParams = [$viewStudentId];
    $studentStmt = $pdo->prepare("SELECT id, first_name, last_name, email, phone FROM students WHERE id = ?" . school_where());
    school_param($studentParams);
    $studentStmt->execute($studentParams);
    $student = $studentStmt->fetch();

    if (!$student) {
        header('Location: tax_statement.php?year=' . urlencode($year));
        exit;
    }

    // Get parent/guardian info
    $parentInfo = null;
    try {
        $parentParams = [$viewStudentId];
        $parentStmt = $pdo->prepare("
            SELECT s.first_name, s.last_name, s.email, s.phone
            FROM parent_students ps
            JOIN students s ON ps.parent_id = s.id
            WHERE ps.student_id = ? AND s.is_parent = 1" . school_where('s') . "
            LIMIT 1
        ");
        school_param($parentParams);
        $parentStmt->execute($parentParams);
        $parentInfo = $parentStmt->fetch();
    } catch (PDOException $e) {}

    // Tax-eligible payments for this student
    $detailQuery = "
        SELECT p.*,
               COALESCE(e.name, '') as event_name,
               COALESCE(mp.name, '') as plan_name
        FROM payments p
        LEFT JOIN events e ON p.payment_type = 'event' AND p.reference_id = e.id
        LEFT JOIN memberships m_link ON p.payment_type = 'membership' AND p.reference_id = m_link.id
        LEFT JOIN membership_plans mp ON m_link.plan_id = mp.id
        WHERE p.student_id = ?
          AND YEAR(p.payment_date) = ?
          AND (
              (p.payment_type = 'event' AND EXISTS (
                  SELECT 1 FROM event_registrations er
                  JOIN events ev ON er.event_id = ev.id
                  WHERE ev.tax_deductible = 1 AND er.student_id = p.student_id
                    AND ev.id = p.reference_id
              ))
              OR
              (p.payment_type = 'membership' AND EXISTS (
                  SELECT 1 FROM memberships m2
                  JOIN membership_plans mpl ON m2.plan_id = mpl.id
                  WHERE mpl.tax_deductible = 1 AND m2.student_id = p.student_id
                    AND m2.id = p.reference_id
              ))
          )" . school_where('p') . "
        ORDER BY p.payment_date ASC
    ";
    $detailParams = [$viewStudentId, $year];
    school_param($detailParams);
    $detailStmt = $pdo->prepare($detailQuery);
    $detailStmt->execute($detailParams);
    $detailPayments = $detailStmt->fetchAll();
    $detailTotal = array_sum(array_column($detailPayments, 'amount'));

    // Print mode renders just the statement
    if ($printMode):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tax Statement - <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> - <?php echo $year; ?></title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; max-width: 800px; margin: 40px auto; color: #333; }
        .header { border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
        .header h1 { font-size: 22px; margin: 0 0 5px; }
        .header p { margin: 2px 0; font-size: 14px; color: #555; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
        .info-box { padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; }
        .info-box h3 { font-size: 13px; text-transform: uppercase; color: #888; margin: 0 0 8px; }
        .info-box p { margin: 2px 0; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #ddd; font-size: 14px; }
        th { background: #f5f5f5; font-weight: 600; text-transform: uppercase; font-size: 12px; color: #555; }
        .amount { text-align: right; font-weight: 600; }
        .total-row { font-weight: bold; background: #f0f9f0; }
        .total-row td { border-top: 2px solid #333; }
        .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 12px; color: #888; }
        .print-btn { position: fixed; top: 20px; right: 20px; background: #2563eb; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .print-btn:hover { background: #1d4ed8; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-btn">Print Statement</button>

    <div class="header">
        <h1>Dependent Care / Tax-Eligible Payment Statement</h1>
        <p>Tax Year: <strong><?php echo htmlspecialchars($year); ?></strong></p>
    </div>

    <div class="info-grid">
        <div class="info-box">
            <h3>Provider Information</h3>
            <?php if ($taxBusinessName): ?>
                <p><strong><?php echo htmlspecialchars($taxBusinessName); ?></strong></p>
            <?php endif; ?>
            <?php if ($taxEin): ?>
                <p>EIN: <?php echo htmlspecialchars($taxEin); ?></p>
            <?php endif; ?>
            <?php if ($taxAddress): ?>
                <p><?php echo nl2br(htmlspecialchars($taxAddress)); ?></p>
            <?php endif; ?>
        </div>
        <div class="info-box">
            <h3>Student / Dependent</h3>
            <p><strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong></p>
            <?php if ($parentInfo): ?>
                <p>Parent: <?php echo htmlspecialchars($parentInfo['first_name'] . ' ' . $parentInfo['last_name']); ?></p>
            <?php endif; ?>
            <?php if ($student['email']): ?>
                <p><?php echo htmlspecialchars($student['email']); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Description</th>
                <th>Receipt #</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($detailPayments as $dp): ?>
                <tr>
                    <td><?php echo formatDate($dp['payment_date']); ?></td>
                    <td><?php echo ucfirst($dp['payment_type']); ?></td>
                    <td><?php echo htmlspecialchars($dp['event_name'] ?: ($dp['plan_name'] ?: ($dp['notes'] ?: '-'))); ?></td>
                    <td><?php echo htmlspecialchars($dp['receipt_number'] ?? '-'); ?></td>
                    <td class="amount"><?php echo formatMoney($dp['amount']); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="4">Total Tax-Eligible Payments for <?php echo htmlspecialchars($year); ?></td>
                <td class="amount"><?php echo formatMoney($detailTotal); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <?php if ($taxNote): ?>
            <p><?php echo htmlspecialchars($taxNote); ?></p>
        <?php endif; ?>
        <p>Statement generated on <?php echo date('F j, Y'); ?></p>
    </div>
</body>
</html>
<?php
        exit; // end print mode
    endif;

    // Non-print detail view
    include 'includes/header.php';
?>
<div class="container mx-auto px-4 py-8">
    <div class="flex items-center gap-4 mb-6">
        <a href="tax_statement.php?year=<?php echo urlencode($year); ?>" class="text-blue-600 hover:text-blue-800">&larr; Back to Report</a>
        <h1 class="text-2xl font-bold text-gray-800">
            Tax Statement: <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> (<?php echo htmlspecialchars($year); ?>)
        </h1>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-5">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">Student Info</h3>
            <p class="text-lg font-medium text-gray-800"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></p>
            <?php if ($student['email']): ?><p class="text-sm text-gray-600"><?php echo htmlspecialchars($student['email']); ?></p><?php endif; ?>
            <?php if ($parentInfo): ?>
                <p class="text-sm text-gray-600 mt-2">Parent: <?php echo htmlspecialchars($parentInfo['first_name'] . ' ' . $parentInfo['last_name']); ?>
                <?php if ($parentInfo['email']): ?> &mdash; <?php echo htmlspecialchars($parentInfo['email']); ?><?php endif; ?></p>
            <?php endif; ?>
        </div>
        <div class="bg-green-50 rounded-lg shadow p-5">
            <h3 class="text-sm font-semibold text-green-600 uppercase mb-3">Tax-Eligible Total</h3>
            <p class="text-3xl font-bold text-green-700"><?php echo formatMoney($detailTotal); ?></p>
            <p class="text-sm text-green-600 mt-1"><?php echo count($detailPayments); ?> qualifying payment(s) in <?php echo htmlspecialchars($year); ?></p>
            <a href="tax_statement.php?student_id=<?php echo $viewStudentId; ?>&year=<?php echo urlencode($year); ?>&print=1"
               target="_blank"
               class="inline-block mt-3 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                Print Statement
            </a>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-800">Tax-Eligible Payments</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Program</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($detailPayments as $dp): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo formatDate($dp['payment_date']); ?></td>
                            <td class="px-6 py-4 text-sm">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                    <?php echo ucfirst($dp['payment_type']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700">
                                <?php echo htmlspecialchars($dp['event_name'] ?: ($dp['plan_name'] ?: ($dp['notes'] ?: '-'))); ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-500"><?php echo htmlspecialchars($dp['receipt_number'] ?? '-'); ?></td>
                            <td class="px-6 py-4 text-sm font-semibold text-green-600"><?php echo formatMoney($dp['amount']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-green-50">
                    <tr>
                        <td colspan="4" class="px-6 py-3 text-sm font-bold text-gray-700 text-right">Total:</td>
                        <td class="px-6 py-3 text-sm font-bold text-green-700"><?php echo formatMoney($detailTotal); ?></td>
                    </tr>
                </tfoot>
            </table>

            <?php if (empty($detailPayments)): ?>
                <div class="text-center py-12 text-gray-500">
                    <p>No tax-eligible payments found for this student in <?php echo htmlspecialchars($year); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
    include 'includes/footer.php';
    exit;
}

// ---------- Main Report View ----------

// Get all students with tax-eligible payments for the selected year
$reportQuery = "
    SELECT s.id, s.first_name, s.last_name, s.email,
           SUM(p.amount) as total_eligible,
           COUNT(p.id) as payment_count
    FROM payments p
    JOIN students s ON p.student_id = s.id
    WHERE YEAR(p.payment_date) = :year
      AND (
          (p.payment_type = 'event' AND EXISTS (
              SELECT 1 FROM event_registrations er
              JOIN events ev ON er.event_id = ev.id
              WHERE ev.tax_deductible = 1 AND er.student_id = p.student_id
                AND ev.id = p.reference_id
          ))
          OR
          (p.payment_type = 'membership' AND EXISTS (
              SELECT 1 FROM memberships m
              JOIN membership_plans mpl ON m.plan_id = mpl.id
              WHERE mpl.tax_deductible = 1 AND m.student_id = p.student_id
                AND m.id = p.reference_id
          ))
      )
    GROUP BY s.id, s.first_name, s.last_name, s.email
    ORDER BY s.last_name, s.first_name
";
if (!is_viewing_all_schools()) {
    $reportQuery = str_replace('WHERE YEAR(p.payment_date) = :year', 'WHERE p.school_id = :school_id AND YEAR(p.payment_date) = :year', $reportQuery);
}

$reportData = [];
try {
    $rStmt = $pdo->prepare($reportQuery);
    $rStmt->bindValue(':year', $year);
    if (!is_viewing_all_schools()) {
        $rStmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT);
    }
    $rStmt->execute();
    $reportData = $rStmt->fetchAll();
} catch (PDOException $e) {
    // tax_deductible column may not exist yet
}

$grandTotal = array_sum(array_column($reportData, 'total_eligible'));

// Try to get parent info for each student
$studentParents = [];
foreach ($reportData as $rd) {
    try {
        $pParams = [$rd['id']];
        $pStmt = $pdo->prepare("
            SELECT s.first_name, s.last_name, s.email
            FROM parent_students ps
            JOIN students s ON ps.parent_id = s.id
            WHERE ps.student_id = ? AND s.is_parent = 1" . school_where('s') . "
            LIMIT 1
        ");
        school_param($pParams);
        $pStmt->execute($pParams);
        $parentRow = $pStmt->fetch();
        if ($parentRow) {
            $studentParents[$rd['id']] = $parentRow['first_name'] . ' ' . $parentRow['last_name'];
        }
    } catch (PDOException $e) {}
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Tax Statements</h1>
    </div>

    <!-- Tax config status -->
    <?php if (!$taxBusinessName || !$taxEin): ?>
    <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6">
        <p class="text-sm text-yellow-800">
            <strong>Setup Required:</strong> Please configure your business name and Tax ID / EIN in
            <a href="settings.php" class="underline font-medium">Settings &rarr; Tax Statement Settings</a>
            before generating tax statements for families.
        </p>
    </div>
    <?php endif; ?>

    <!-- Year Selector & Summary -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <form method="GET" class="flex items-end gap-4 mb-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tax Year</label>
                <select name="year" class="px-4 py-2 border border-gray-300 rounded-lg">
                    <?php foreach ($availableYears as $yr): ?>
                        <option value="<?php echo $yr; ?>" <?php echo $yr == $year ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                View Report
            </button>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-green-50 rounded-lg p-4">
                <p class="text-sm text-green-600 font-medium">Students with Tax-Eligible Payments</p>
                <p class="text-2xl font-bold text-green-700"><?php echo count($reportData); ?></p>
            </div>
            <div class="bg-blue-50 rounded-lg p-4">
                <p class="text-sm text-blue-600 font-medium">Total Tax-Eligible Amount</p>
                <p class="text-2xl font-bold text-blue-700"><?php echo formatMoney($grandTotal); ?></p>
            </div>
            <div class="bg-purple-50 rounded-lg p-4">
                <p class="text-sm text-purple-600 font-medium">Provider</p>
                <p class="text-lg font-bold text-purple-700"><?php echo htmlspecialchars($taxBusinessName ?: 'Not configured'); ?></p>
                <?php if ($taxEin): ?>
                    <p class="text-sm text-purple-600">EIN: <?php echo htmlspecialchars($taxEin); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Report Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Tax-Eligible Payments by Student &mdash; <?php echo htmlspecialchars($year); ?></h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Parent / Contact</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payments</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Eligible</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($reportData as $rd): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($rd['first_name'] . ' ' . $rd['last_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($rd['email']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo htmlspecialchars($studentParents[$rd['id']] ?? '-'); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo $rd['payment_count']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600">
                                <?php echo formatMoney($rd['total_eligible']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <a href="tax_statement.php?student_id=<?php echo $rd['id']; ?>&year=<?php echo urlencode($year); ?>"
                                   class="text-blue-600 hover:text-blue-800 font-medium mr-3">View</a>
                                <a href="tax_statement.php?student_id=<?php echo $rd['id']; ?>&year=<?php echo urlencode($year); ?>&print=1"
                                   target="_blank"
                                   class="text-green-600 hover:text-green-800 font-medium">Print</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (empty($reportData)): ?>
                <div class="text-center py-12 text-gray-500">
                    <p class="text-lg">No tax-eligible payments found for <?php echo htmlspecialchars($year); ?></p>
                    <p class="text-sm mt-2">
                        Make sure events or membership plans are marked as "Tax-Deductible" and payments are recorded against them.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
