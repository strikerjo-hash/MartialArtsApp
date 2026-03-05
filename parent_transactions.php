<?php
/**
 * parent_transactions.php — Parent Transaction History
 *
 * Shows a parent all payments across their children with filters
 * for child, year, type, and a tax-eligible summary section with
 * a printable tax statement.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/parent_auth.php';

require_parent();
$parentId = get_effective_parent_id();
$children = get_parent_children($parentId);

if (empty($children)) {
    header('Location: parent_portal.php');
    exit;
}

$childIds = array_column($children, 'id');
$childMap = [];
foreach ($children as $c) {
    $childMap[$c['id']] = $c['first_name'] . ' ' . $c['last_name'];
}

// Filters
$year = $_GET['year'] ?? date('Y');
$payment_type = $_GET['type'] ?? '';
$child_filter = $_GET['child'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build query
$placeholders = implode(',', array_fill(0, count($childIds), '?'));
$params = $childIds;

$query = "
    SELECT p.*, s.first_name, s.last_name, s.id as child_id
    FROM payments p
    JOIN students s ON p.student_id = s.id
    WHERE p.student_id IN ({$placeholders})
      AND YEAR(p.payment_date) = ?
";
$params[] = $year;

if ($child_filter && in_array((int)$child_filter, $childIds)) {
    $query .= " AND p.student_id = ?";
    $params[] = (int) $child_filter;
}

if ($payment_type) {
    $query .= " AND p.payment_type = ?";
    $params[] = $payment_type;
}

if ($search !== '') {
    $query .= " AND (p.notes LIKE ? OR p.receipt_number LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$query .= school_where('p');
$query .= " ORDER BY p.payment_date DESC, p.created_at DESC";

school_param($params);
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Calculate totals
$total_amount = array_sum(array_column($payments, 'amount'));
$total_count = count($payments);
$avg_amount = $total_count > 0 ? $total_amount / $total_count : 0;

// Per-child breakdown
$perChild = [];
foreach ($payments as $p) {
    $cid = $p['child_id'];
    if (!isset($perChild[$cid])) {
        $perChild[$cid] = ['name' => $p['first_name'] . ' ' . $p['last_name'], 'total' => 0, 'count' => 0];
    }
    $perChild[$cid]['total'] += $p['amount'];
    $perChild[$cid]['count']++;
}

// Available years
$yearPlaceholders = implode(',', array_fill(0, count($childIds), '?'));
$yearParams = $childIds;
school_param($yearParams);
$yearStmt = $pdo->prepare("SELECT DISTINCT YEAR(payment_date) as yr FROM payments WHERE student_id IN ({$yearPlaceholders})" . school_where() . " ORDER BY yr DESC");
$yearStmt->execute($yearParams);
$availableYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($availableYears)) {
    $availableYears = [date('Y')];
}

// ---------- Tax-Eligible Payments ----------
$taxEligiblePayments = [];
$taxEligibleTotal = 0;
try {
    $taxPlaceholders = implode(',', array_fill(0, count($childIds), '?'));
    $taxParams = $childIds;
    $taxParams[] = $year;
    $taxParamsForSub = $childIds;

    $taxQuery = "
        SELECT p.*, s.first_name, s.last_name, s.id as child_id,
               COALESCE(e.name, '') as event_name
        FROM payments p
        JOIN students s ON p.student_id = s.id
        LEFT JOIN events e ON p.payment_type = 'event' AND p.reference_id = e.id
        WHERE p.student_id IN ({$taxPlaceholders})
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
                  SELECT 1 FROM memberships m
                  JOIN membership_plans mpl ON m.plan_id = mpl.id
                  WHERE mpl.tax_deductible = 1 AND m.student_id = p.student_id
                    AND m.id = p.reference_id
              ))
          )
        ORDER BY p.payment_date DESC
    ";
    $taxQuery .= school_where('p');

    school_param($taxParams);
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

// Parent display name
$parentName = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');

include 'includes/parent_header.php';
?>

<style>
@media print {
    header, nav, .no-print, .filters-section, .summary-cards { display: none !important; }
    .print-only { display: block !important; }
    body { background: white !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
    .bg-white { box-shadow: none !important; }
}
.print-only { display: none; }
</style>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-5xl mx-auto">

        <div class="flex justify-between items-center mb-6 no-print flex-wrap gap-3">
            <h1 class="text-2xl font-bold text-gray-800">Family Transaction History</h1>
            <div class="flex items-center gap-4">
                <a href="parent_tax_statement.php?year=<?php echo htmlspecialchars($year); ?>" class="inline-flex items-center gap-1.5 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Tax Statement
                </a>
                <a href="parent_payment.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                    &larr; Payment Methods
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6 filters-section no-print">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Child</label>
                    <select name="child" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">All Children</option>
                        <?php foreach ($children as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $child_filter == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Year</label>
                    <select name="year" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <?php foreach ($availableYears as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo $yr == $year ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
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
                    <a href="parent_transactions.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 summary-cards no-print">
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

        <!-- Per-Child Breakdown -->
        <?php if (count($perChild) > 1): ?>
        <div class="bg-white rounded-lg shadow p-4 mb-6 no-print">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Per-Child Breakdown</h3>
            <div class="grid grid-cols-2 md:grid-cols-<?php echo min(count($perChild), 4); ?> gap-3">
                <?php foreach ($perChild as $cid => $cd): ?>
                    <div class="bg-gray-50 rounded-lg p-3 text-center">
                        <p class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($cd['name']); ?></p>
                        <p class="text-lg font-bold text-blue-700"><?php echo formatMoney($cd['total']); ?></p>
                        <p class="text-xs text-gray-500"><?php echo $cd['count']; ?> payment(s)</p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Child</th>
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
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800">
                                    <?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?>
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
                    class="w-full px-6 py-4 border-b border-gray-200 flex justify-between items-center hover:bg-gray-50 transition-colors no-print">
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
                <!-- Print Header (only visible when printing) -->
                <div class="print-only px-6 py-4 border-b border-gray-300">
                    <h1 class="text-xl font-bold text-gray-900">Tax-Eligible Payment Statement — <?php echo htmlspecialchars($year); ?></h1>
                    <p class="text-sm text-gray-600 mt-1">Prepared for: <?php echo htmlspecialchars(trim($parentName)); ?></p>
                </div>

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
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Child</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($taxEligiblePayments as $tp): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-sm text-gray-600"><?php echo formatDate($tp['payment_date']); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700 font-medium"><?php echo htmlspecialchars($tp['first_name'] . ' ' . $tp['last_name']); ?></td>
                                    <td class="px-6 py-3 text-sm">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            <?php echo ucfirst($tp['payment_type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-600">
                                        <?php echo htmlspecialchars($tp['notes'] ?: ($tp['event_name'] ?: '-')); ?>
                                    </td>
                                    <td class="px-6 py-3 text-sm font-semibold text-green-600"><?php echo formatMoney($tp['amount']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-green-50">
                            <tr>
                                <td colspan="4" class="px-6 py-3 text-sm font-bold text-gray-700 text-right">Total Tax-Eligible:</td>
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

                <div class="px-6 py-4 border-t border-gray-200 no-print flex items-center gap-3">
                    <a href="parent_tax_statement.php?year=<?php echo htmlspecialchars($year); ?>"
                       class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg text-sm font-medium inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        View Full Tax Statement
                    </a>
                    <button onclick="document.getElementById('taxContent').classList.remove('hidden'); window.print();"
                            class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
                        Quick Print
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include 'includes/student_footer.php'; ?>
