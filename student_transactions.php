<?php
/**
 * student_transactions.php — Student Transaction History
 *
 * Shows a student their full payment/transaction history with filters
 * and a tax-eligible summary section.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/security.php';

// Require student login
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}

$studentId = (int) $_SESSION['student_id'];

// Filters
$year = $_GET['year'] ?? date('Y');
$payment_type = $_GET['type'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build payment query
$query = "
    SELECT p.*, s.first_name, s.last_name
    FROM payments p
    JOIN students s ON p.student_id = s.id
    WHERE p.student_id = :student_id
      AND YEAR(p.payment_date) = :year
";
$params = [':student_id' => $studentId, ':year' => $year];

if ($payment_type) {
    $query .= " AND p.payment_type = :type";
    $params[':type'] = $payment_type;
}
if ($search !== '') {
    $query .= " AND (p.notes LIKE :search OR p.receipt_number LIKE :search2)";
    $params[':search'] = "%{$search}%";
    $params[':search2'] = "%{$search}%";
}

if (!is_viewing_all_schools()) {
    $query .= " AND p.school_id = :school_id";
    $params[':school_id'] = current_school_id();
}

$query .= " ORDER BY p.payment_date DESC, p.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Calculate totals
$total_amount = array_sum(array_column($payments, 'amount'));
$total_count = count($payments);
$avg_amount = $total_count > 0 ? $total_amount / $total_count : 0;

// Get available years for the dropdown
$yearSql = "SELECT DISTINCT YEAR(payment_date) as yr FROM payments WHERE student_id = :sid";
$yearParams = [':sid' => $studentId];
if (!is_viewing_all_schools()) {
    $yearSql .= " AND school_id = :school_id";
    $yearParams[':school_id'] = current_school_id();
}
$yearSql .= " ORDER BY yr DESC";
$yearStmt = $pdo->prepare($yearSql);
$yearStmt->execute($yearParams);
$availableYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($availableYears)) {
    $availableYears = [date('Y')];
}

// ---------- Tax-Eligible Payments ----------
$taxQuery = "
    SELECT p.*,
           COALESCE(e.name, mp.name) as item_name,
           CASE
               WHEN p.payment_type = 'event' THEN 'Event'
               WHEN p.payment_type = 'membership' THEN 'Membership'
               ELSE p.payment_type
           END as item_type
    FROM payments p
    LEFT JOIN events e ON p.payment_type = 'event' AND p.reference_id = e.id
    LEFT JOIN membership_plans mp ON p.payment_type = 'membership'
        AND p.reference_id IN (SELECT m.id FROM memberships m WHERE m.plan_id = mp.id)
    WHERE p.student_id = :sid
      AND YEAR(p.payment_date) = :year
      AND (
          (p.payment_type = 'event' AND p.reference_id IN (
              SELECT er.event_id FROM event_registrations er
              JOIN events ev ON er.event_id = ev.id
              WHERE ev.tax_deductible = 1 AND er.student_id = :sid2
          ))
          OR
          (p.payment_type = 'membership' AND p.reference_id IN (
              SELECT m.id FROM memberships m
              JOIN membership_plans mpl ON m.plan_id = mpl.id
              WHERE mpl.tax_deductible = 1 AND m.student_id = :sid3
          ))
      )
    ORDER BY p.payment_date DESC
";
$taxParams = [':sid' => $studentId, ':year' => $year, ':sid2' => $studentId, ':sid3' => $studentId];
if (!is_viewing_all_schools()) {
    $taxQuery = str_replace('ORDER BY p.payment_date DESC', 'AND p.school_id = :school_id ORDER BY p.payment_date DESC', $taxQuery);
    $taxParams[':school_id'] = current_school_id();
}

$taxEligiblePayments = [];
$taxEligibleTotal = 0;
try {
    $taxStmt = $pdo->prepare($taxQuery);
    $taxStmt->execute($taxParams);
    $taxEligiblePayments = $taxStmt->fetchAll();
    $taxEligibleTotal = array_sum(array_column($taxEligiblePayments, 'amount'));
} catch (PDOException $e) {
    // tax_deductible column may not exist yet
}

$taxBusinessName = getSetting('tax_business_name', '');
$taxEin = getSetting('tax_id_ein', '');
$taxAddress = getSetting('tax_business_address', '');
$taxNote = getSetting('tax_statement_note', 'This statement is provided for informational purposes. Please consult your tax advisor.');

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-5xl mx-auto">

        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Transaction History</h1>
            <a href="student_payment.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                &larr; Payment Methods
            </a>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Year</label>
                    <select name="year" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <?php foreach ($availableYears as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo $yr == $year ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Type</label>
                    <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">All Types</option>
                        <option value="membership" <?php echo $payment_type === 'membership' ? 'selected' : ''; ?>>Membership</option>
                        <option value="event" <?php echo $payment_type === 'event' ? 'selected' : ''; ?>>Event</option>
                        <option value="merchandise" <?php echo $payment_type === 'merchandise' ? 'selected' : ''; ?>>Merchandise</option>
                        <option value="other" <?php echo $payment_type === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Receipt # or notes..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                        Filter
                    </button>
                    <a href="student_transactions.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <p class="text-sm text-green-600 font-medium">Total Payments</p>
                <p class="text-2xl font-bold text-green-700"><?php echo $total_count; ?></p>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <p class="text-sm text-blue-600 font-medium">Total Amount</p>
                <p class="text-2xl font-bold text-blue-700"><?php echo formatMoney($total_amount); ?></p>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <p class="text-sm text-purple-600 font-medium">Average Payment</p>
                <p class="text-2xl font-bold text-purple-700"><?php echo formatMoney($avg_amount); ?></p>
            </div>
        </div>

        <!-- Transaction Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800">Payments in <?php echo htmlspecialchars($year); ?></h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt #</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($payments as $payment): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php echo formatDate($payment['payment_date']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $typeColors = [
                                        'membership' => 'bg-blue-100 text-blue-800',
                                        'event' => 'bg-purple-100 text-purple-800',
                                        'merchandise' => 'bg-green-100 text-green-800',
                                        'other' => 'bg-gray-100 text-gray-800'
                                    ];
                                    $color = $typeColors[$payment['payment_type']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $color; ?>">
                                        <?php echo ucfirst($payment['payment_type']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <?php echo htmlspecialchars($payment['notes'] ?: '-'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 capitalize">
                                    <?php echo str_replace('_', ' ', $payment['payment_method']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600">
                                    <?php echo formatMoney($payment['amount']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-500">
                                    <?php echo htmlspecialchars($payment['receipt_number'] ?? '-'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (empty($payments)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <p class="text-lg">No transactions found for <?php echo htmlspecialchars($year); ?></p>
                        <p class="text-sm mt-1">Try adjusting your filters or selecting a different year.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tax-Eligible Payments Section -->
        <?php if (!empty($taxEligiblePayments)): ?>
        <div class="bg-white rounded-lg shadow overflow-hidden mb-6" id="tax-section">
            <button onclick="document.getElementById('taxContent').classList.toggle('hidden'); this.querySelector('svg').classList.toggle('rotate-180');"
                    class="w-full px-6 py-4 border-b border-gray-200 flex justify-between items-center hover:bg-gray-50 transition-colors">
                <div class="flex items-center gap-3">
                    <span class="text-green-600 text-xl">&#9993;</span>
                    <h2 class="text-lg font-semibold text-gray-800">Tax-Eligible Payments (<?php echo htmlspecialchars($year); ?>)</h2>
                    <span class="bg-green-100 text-green-800 text-sm font-bold px-3 py-1 rounded-full">
                        <?php echo formatMoney($taxEligibleTotal); ?>
                    </span>
                </div>
                <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div id="taxContent" class="hidden">
                <?php if ($taxBusinessName || $taxEin): ?>
                <div class="px-6 py-4 bg-green-50 border-b border-green-200">
                    <p class="text-sm text-gray-700">
                        <strong>Provider:</strong> <?php echo htmlspecialchars($taxBusinessName); ?>
                        <?php if ($taxEin): ?>
                            &nbsp;&bull;&nbsp; <strong>EIN:</strong> <?php echo htmlspecialchars($taxEin); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($taxAddress): ?>
                        <p class="text-sm text-gray-600 mt-1"><?php echo nl2br(htmlspecialchars($taxAddress)); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($taxEligiblePayments as $tp): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-sm text-gray-600"><?php echo formatDate($tp['payment_date']); ?></td>
                                    <td class="px-6 py-3 text-sm">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            <?php echo htmlspecialchars($tp['item_type'] ?? ucfirst($tp['payment_type'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($tp['notes'] ?: ($tp['item_name'] ?? '-')); ?></td>
                                    <td class="px-6 py-3 text-sm font-semibold text-green-600"><?php echo formatMoney($tp['amount']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-green-50">
                            <tr>
                                <td colspan="3" class="px-6 py-3 text-sm font-bold text-gray-700 text-right">Total Tax-Eligible:</td>
                                <td class="px-6 py-3 text-sm font-bold text-green-700"><?php echo formatMoney($taxEligibleTotal); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if ($taxNote): ?>
                <div class="px-6 py-3 bg-gray-50 border-t border-gray-200">
                    <p class="text-xs text-gray-500 italic"><?php echo htmlspecialchars($taxNote); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include 'includes/student_footer.php'; ?>
