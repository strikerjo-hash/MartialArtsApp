<?php
/**
 * parent_tax_statement.php — Federal Tax-Compliant Dependent Care Statement
 *
 * Generates a printable annual tax statement listing all tax-eligible payments
 * (membership plans and events flagged as tax_deductible) for the parent's
 * linked children. Designed to comply with IRS requirements for Form 2441
 * (Child and Dependent Care Expenses) and W-10 (Dependent Care Provider's
 * Identification and Certification).
 *
 * Includes: Provider info (name, EIN, address), recipient info, per-child
 * breakdowns with dates of service, itemized payments, and grand total.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/parent_auth.php';

require_parent();

$parentId = get_effective_parent_id();
$pdo = get_db();
$children = get_parent_children($parentId);

if (empty($children)) {
    header('Location: parent_portal.php');
    exit;
}

$childIds = array_column($children, 'id');

// ── Determine parent info ────────────────────────────────────────
$isStudentParent = (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'student' && !empty($_SESSION['is_parent']));

if ($isStudentParent) {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email, phone, address FROM students WHERE id = ? AND is_parent = 1" . school_where() . " LIMIT 1");
} else {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email, phone, address FROM parents WHERE id = ?" . school_where() . " LIMIT 1");
}
$params = [$parentId];
school_param($params);
$stmt->execute($params);
$parentInfo = $stmt->fetch();

$parentName = trim(($parentInfo['first_name'] ?? $_SESSION['first_name'] ?? '') . ' ' . ($parentInfo['last_name'] ?? $_SESSION['last_name'] ?? ''));
$parentAddress = $parentInfo['address'] ?? '';

// ── Year selection ───────────────────────────────────────────────
$year = (int) ($_GET['year'] ?? date('Y'));

// Available years from payment history
$placeholders = implode(',', array_fill(0, count($childIds), '?'));
$yearParams = $childIds;
school_param($yearParams);
$yearStmt = $pdo->prepare("SELECT DISTINCT YEAR(payment_date) as yr FROM payments WHERE student_id IN ({$placeholders})" . school_where() . " ORDER BY yr DESC");
$yearStmt->execute($yearParams);
$availableYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($availableYears)) {
    $availableYears = [(int)date('Y')];
}

// ── Provider (school/business) info from settings ────────────────
$providerName    = getSetting('tax_business_name', getSiteName());
$providerEin     = getSetting('tax_id_ein', '');
$providerAddress = getSetting('tax_business_address', '');
$taxNote         = getSetting('tax_statement_note', 'This statement is provided for informational purposes. Please consult your tax advisor regarding the deductibility of these expenses.');

// ── Fetch all tax-eligible payments for the year ─────────────────
$taxPayments = [];
try {
    $taxParams = $childIds;
    $taxParams[] = $year;

    $taxQuery = "
        SELECT p.*, s.first_name, s.last_name, s.id as child_id,
               s.date_of_birth as child_dob,
               COALESCE(e.name, '') as event_name,
               COALESCE(e.event_type, '') as event_type,
               COALESCE(mpl.name, '') as plan_name,
               CASE
                   WHEN p.payment_type = 'event' THEN CONCAT('Event: ', COALESCE(e.name, p.notes, 'Event Fee'))
                   WHEN p.payment_type = 'membership' THEN CONCAT('Membership: ', COALESCE(mpl.name, p.notes, 'Plan'))
                   ELSE COALESCE(p.notes, p.payment_type)
               END as description
        FROM payments p
        JOIN students s ON p.student_id = s.id
        LEFT JOIN events e ON p.payment_type = 'event' AND p.reference_id = e.id
        LEFT JOIN memberships m2 ON p.payment_type = 'membership' AND p.reference_id = m2.id
        LEFT JOIN membership_plans mpl ON m2.plan_id = mpl.id
        WHERE p.student_id IN ({$placeholders})
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
                  JOIN membership_plans mplx ON m.plan_id = mplx.id
                  WHERE mplx.tax_deductible = 1 AND m.student_id = p.student_id
                    AND m.id = p.reference_id
              ))
          )
    ";
    $taxQuery .= school_where('p');
    $taxQuery .= " ORDER BY s.first_name, s.last_name, p.payment_date ASC";

    school_param($taxParams);
    $taxStmt = $pdo->prepare($taxQuery);
    $taxStmt->execute($taxParams);
    $taxPayments = $taxStmt->fetchAll();
} catch (PDOException $e) {
    // tax_deductible column may not exist yet
}

// ── Organize payments by child ───────────────────────────────────
$perChild = [];
$grandTotal = 0;
foreach ($taxPayments as $tp) {
    $cid = $tp['child_id'];
    if (!isset($perChild[$cid])) {
        $perChild[$cid] = [
            'name'       => $tp['first_name'] . ' ' . $tp['last_name'],
            'dob'        => $tp['child_dob'] ?? null,
            'payments'   => [],
            'total'      => 0,
            'first_date' => $tp['payment_date'],
            'last_date'  => $tp['payment_date'],
        ];
    }
    $perChild[$cid]['payments'][] = $tp;
    $perChild[$cid]['total'] += $tp['amount'];
    if ($tp['payment_date'] < $perChild[$cid]['first_date']) {
        $perChild[$cid]['first_date'] = $tp['payment_date'];
    }
    if ($tp['payment_date'] > $perChild[$cid]['last_date']) {
        $perChild[$cid]['last_date'] = $tp['payment_date'];
    }
    $grandTotal += $tp['amount'];
}

include 'includes/parent_header.php';
?>

<style>
@media print {
    header, nav, .no-print { display: none !important; }
    .print-only { display: block !important; }
    body { background: white !important; font-size: 11pt; }
    .container { max-width: 100% !important; padding: 0 !important; }
    .tax-statement { box-shadow: none !important; border: none !important; }
    .tax-statement-header { background: #f9fafb !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .child-section { page-break-inside: avoid; }
    a { text-decoration: none !important; color: inherit !important; }
}
.print-only { display: none; }
.tax-statement { max-width: 850px; margin: 0 auto; }
</style>

<div class="container mx-auto px-4 py-8">

    <!-- Controls (hidden when printing) -->
    <div class="max-w-4xl mx-auto mb-6 no-print">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Annual Tax Statement</h1>
                <p class="text-sm text-gray-500 mt-1">Tax-eligible dependent care expenses for your qualifying children</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="parent_transactions.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                    &larr; All Transactions
                </a>
            </div>
        </div>

        <!-- Year Selector -->
        <div class="mt-4 flex items-center gap-3">
            <label class="text-sm font-medium text-gray-700">Tax Year:</label>
            <form method="GET" class="flex items-center gap-2">
                <select name="year" onchange="this.form.submit()" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <?php foreach ($availableYears as $yr): ?>
                        <option value="<?php echo $yr; ?>" <?php echo (int)$yr === $year ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php if (!empty($perChild)): ?>
                <button onclick="window.print();" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    Print Statement
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($perChild)): ?>
        <!-- No qualifying payments -->
        <div class="max-w-4xl mx-auto">
            <div class="bg-white rounded-lg shadow p-8 text-center">
                <div class="text-5xl mb-4">&#128196;</div>
                <h2 class="text-xl font-semibold text-gray-700 mb-2">No Tax-Eligible Payments Found</h2>
                <p class="text-gray-500 mb-4">There are no payments for tax-deductible programs or events in <?php echo $year; ?>.</p>
                <p class="text-sm text-gray-400">Only membership plans and events marked as "tax-deductible" by the school appear on this statement.</p>
            </div>
        </div>
    <?php else: ?>

    <!-- Tax Statement Document -->
    <div class="tax-statement bg-white rounded-lg shadow overflow-hidden">

        <!-- Statement Header -->
        <div class="tax-statement-header bg-gray-50 border-b-2 border-gray-300 px-8 py-6">
            <div class="text-center mb-4">
                <h2 class="text-xl font-bold text-gray-900 uppercase tracking-wide">Dependent Care Provider Statement</h2>
                <p class="text-sm text-gray-600 mt-1">Calendar Year <?php echo $year; ?></p>
                <p class="text-xs text-gray-500 mt-1">For use with IRS Form 2441 (Child and Dependent Care Expenses)</p>
            </div>
        </div>

        <!-- Provider & Recipient Info -->
        <div class="px-8 py-6 border-b border-gray-200">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Care Provider -->
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <h3 class="text-xs font-bold text-green-800 uppercase tracking-wider mb-3">Care Provider Information</h3>
                    <div class="space-y-1.5">
                        <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($providerName); ?></p>
                        <?php if ($providerAddress): ?>
                            <p class="text-sm text-gray-700"><?php echo nl2br(htmlspecialchars($providerAddress)); ?></p>
                        <?php endif; ?>
                        <?php if ($providerEin): ?>
                            <p class="text-sm text-gray-700"><span class="font-medium">Federal EIN:</span> <?php echo htmlspecialchars($providerEin); ?></p>
                        <?php else: ?>
                            <p class="text-sm text-red-600 italic">EIN not provided &mdash; contact your school administrator</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recipient (Parent) -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h3 class="text-xs font-bold text-blue-800 uppercase tracking-wider mb-3">Taxpayer / Recipient Information</h3>
                    <div class="space-y-1.5">
                        <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($parentName); ?></p>
                        <?php if ($parentAddress): ?>
                            <p class="text-sm text-gray-700"><?php echo nl2br(htmlspecialchars($parentAddress)); ?></p>
                        <?php endif; ?>
                        <?php if ($parentInfo['email'] ?? ''): ?>
                            <p class="text-sm text-gray-700"><?php echo htmlspecialchars($parentInfo['email']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statement Period -->
        <div class="px-8 py-3 bg-gray-50 border-b border-gray-200">
            <div class="flex items-center justify-between text-sm">
                <span class="text-gray-600"><strong>Statement Period:</strong> January 1, <?php echo $year; ?> &ndash; December 31, <?php echo $year; ?></span>
                <span class="text-gray-600"><strong>Statement Date:</strong> <?php echo date('F j, Y'); ?></span>
            </div>
        </div>

        <!-- Grand Total Summary -->
        <div class="px-8 py-4 border-b border-gray-200">
            <div class="bg-green-50 border-2 border-green-300 rounded-lg p-4 flex items-center justify-between">
                <div>
                    <p class="text-sm font-bold text-green-800 uppercase">Total Tax-Eligible Expenses Paid in <?php echo $year; ?></p>
                    <p class="text-xs text-green-700 mt-1"><?php echo count($perChild); ?> qualifying child(ren) &bull; <?php echo count($taxPayments); ?> payment(s)</p>
                </div>
                <p class="text-3xl font-bold text-green-800"><?php echo formatMoney($grandTotal); ?></p>
            </div>
        </div>

        <!-- Per-Child Breakdown -->
        <?php foreach ($perChild as $cid => $child): ?>
        <div class="child-section px-8 py-6 <?php echo $cid !== array_key_last($perChild) ? 'border-b border-gray-200' : ''; ?>">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($child['name']); ?></h3>
                    <p class="text-xs text-gray-500">
                        Dates of Service: <?php echo formatDate($child['first_date']); ?> &ndash; <?php echo formatDate($child['last_date']); ?>
                        <?php if ($child['dob']): ?>
                            &bull; Date of Birth: <?php echo formatDate($child['dob']); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-gray-500 uppercase font-semibold">Child Subtotal</p>
                    <p class="text-xl font-bold text-green-700"><?php echo formatMoney($child['total']); ?></p>
                </div>
            </div>

            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-300">
                        <th class="text-left py-2 px-2 text-xs font-semibold text-gray-500 uppercase">Date</th>
                        <th class="text-left py-2 px-2 text-xs font-semibold text-gray-500 uppercase">Type</th>
                        <th class="text-left py-2 px-2 text-xs font-semibold text-gray-500 uppercase">Description</th>
                        <th class="text-left py-2 px-2 text-xs font-semibold text-gray-500 uppercase">Receipt #</th>
                        <th class="text-right py-2 px-2 text-xs font-semibold text-gray-500 uppercase">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($child['payments'] as $tp): ?>
                    <tr class="border-b border-gray-100">
                        <td class="py-2 px-2 text-gray-600"><?php echo formatDate($tp['payment_date']); ?></td>
                        <td class="py-2 px-2">
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                <?php echo ucfirst($tp['payment_type']); ?>
                            </span>
                        </td>
                        <td class="py-2 px-2 text-gray-700"><?php echo htmlspecialchars($tp['description']); ?></td>
                        <td class="py-2 px-2 text-gray-500 font-mono text-xs"><?php echo htmlspecialchars($tp['receipt_number'] ?? '-'); ?></td>
                        <td class="py-2 px-2 text-right font-semibold text-gray-800"><?php echo formatMoney($tp['amount']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-300">
                        <td colspan="4" class="py-2 px-2 text-right font-bold text-gray-700">Subtotal for <?php echo htmlspecialchars($child['name']); ?>:</td>
                        <td class="py-2 px-2 text-right font-bold text-green-700"><?php echo formatMoney($child['total']); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endforeach; ?>

        <!-- Grand Total Footer -->
        <div class="px-8 py-4 bg-gray-50 border-t-2 border-gray-300">
            <div class="flex items-center justify-between">
                <p class="text-sm font-bold text-gray-800 uppercase">Grand Total &mdash; All Qualifying Children</p>
                <p class="text-2xl font-bold text-green-800"><?php echo formatMoney($grandTotal); ?></p>
            </div>
        </div>

        <!-- Certification & Notes -->
        <div class="px-8 py-6 border-t border-gray-200">
            <div class="space-y-4">
                <!-- Certification Statement -->
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <h4 class="text-xs font-bold text-yellow-800 uppercase tracking-wider mb-2">Provider Certification</h4>
                    <p class="text-sm text-yellow-900 leading-relaxed">
                        The undersigned care provider certifies that the amounts shown above were received as payment
                        for qualifying dependent care services provided during the calendar year <?php echo $year; ?>.
                        The provider's Employer Identification Number (EIN) or taxpayer identification number is listed above.
                    </p>
                </div>

                <!-- IRS Reference -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h4 class="text-xs font-bold text-blue-800 uppercase tracking-wider mb-2">IRS Reference</h4>
                    <p class="text-sm text-blue-800 leading-relaxed">
                        This statement is provided to assist you in completing <strong>IRS Form 2441</strong>
                        (Child and Dependent Care Expenses). You may also need the provider information above
                        to complete <strong>IRS Form W-10</strong> (Dependent Care Provider's Identification and Certification).
                        Retain this statement with your tax records.
                    </p>
                </div>

                <!-- Custom Note from Settings -->
                <?php if ($taxNote): ?>
                <div class="bg-gray-100 rounded-lg p-4">
                    <p class="text-xs text-gray-600 italic"><?php echo htmlspecialchars($taxNote); ?></p>
                </div>
                <?php endif; ?>

                <!-- Signature line (visible in print) -->
                <div class="mt-8 pt-4 border-t border-gray-200">
                    <div class="grid grid-cols-2 gap-8">
                        <div>
                            <div class="border-b border-gray-400 mb-1 h-8"></div>
                            <p class="text-xs text-gray-500">Provider Signature / Authorized Representative</p>
                        </div>
                        <div>
                            <div class="border-b border-gray-400 mb-1 h-8"></div>
                            <p class="text-xs text-gray-500">Date</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /tax-statement -->

    <!-- Print button at bottom (no-print) -->
    <div class="max-w-4xl mx-auto mt-6 text-center no-print">
        <button onclick="window.print();" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-medium text-sm">
            Print / Save as PDF
        </button>
        <p class="text-xs text-gray-400 mt-2">Use your browser's Print function or "Save as PDF" for a permanent record.</p>
    </div>

    <?php endif; ?>
</div>

<?php include 'includes/student_footer.php'; ?>
