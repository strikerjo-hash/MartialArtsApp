<?php
/**
 * pending_payments.php — Admin Pending Payments Report & Processing
 *
 * Lists all event registrations (and optionally memberships) with a pending
 * payment status.  Admins can:
 *   • View the full pending-payment report
 *   • Select individual or all registrations
 *   • Batch-process selected payments against the student's (or parent's)
 *     card on file, OR mark them as paid (cash / manual), OR waive them.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';
requireLogin();

$message = '';

$gw      = get_active_gateway();
$gwReady = is_gateway_ready();

// ---------------------------------------------------------------------------
// Handle POST actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $selectedIds = $_POST['registration_ids'] ?? [];
    $selectedIds = array_map('intval', $selectedIds);
    $selectedIds = array_filter($selectedIds);

    if (empty($selectedIds)) {
        $message = showAlert('No registrations selected.', 'error');
    } else {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));

        switch ($_POST['action']) {

            // ------------------------------------------------------------------
            // CHARGE CARD ON FILE — Process via Stripe/Square
            // ------------------------------------------------------------------
            case 'charge_card':
                if (!$gwReady) {
                    $message = showAlert('Payment gateway is not configured. Cannot process card charges.', 'error');
                    break;
                }

                // Fetch registrations with student + event info
                $regQuery = "
                    SELECT er.id, er.student_id, er.event_id, er.parent_id,
                           e.name AS event_name, e.registration_fee,
                           s.first_name, s.last_name
                    FROM event_registrations er
                    JOIN events e ON er.event_id = e.id
                    JOIN students s ON s.id = er.student_id
                    WHERE er.id IN ({$placeholders}) AND er.payment_status = 'pending'"
                    . school_where('s');
                $regParams = $selectedIds;
                school_param($regParams);
                $regStmt = $pdo->prepare($regQuery);
                $regStmt->execute($regParams);
                $regs = $regStmt->fetchAll();

                $successCount = 0;
                $failCount    = 0;
                $failMessages = [];

                foreach ($regs as $reg) {
                    $baseFee = (float)$reg['registration_fee'];
                    if ($baseFee <= 0) {
                        // No fee — just mark as waived
                        $wParams = [$reg['id']];
                        school_param($wParams);
                        $pdo->prepare("UPDATE event_registrations SET payment_status = 'waived', amount_paid = 0 WHERE id = ?" . school_where())->execute($wParams);
                        $successCount++;
                        continue;
                    }

                    // Calculate total with processing/service fee
                    $regFeeBreakdown = calculateTotalWithFees([
                        'base_amount'      => $baseFee,
                        'registration_fee' => 0,
                        'discount_code'    => '',
                        'event_id'         => $reg['event_id'],
                    ]);
                    $totalToCharge = $regFeeBreakdown['total']; // base + service fee
                    $serviceFee    = $regFeeBreakdown['service_fee'];

                    // Determine who to charge: parent if set, otherwise student
                    $chargeStudentId = $reg['parent_id'] ?: $reg['student_id'];

                    // Check if they have a payment method on file
                    $pmCheck = $pdo->prepare("SELECT id FROM payment_methods WHERE student_id = ? AND is_default = 1 LIMIT 1");
                    $pmCheck->execute([$chargeStudentId]);
                    if (!$pmCheck->fetch()) {
                        $failCount++;
                        $failMessages[] = $reg['first_name'] . ' ' . $reg['last_name'] . ' — no card on file' . ($reg['parent_id'] ? ' (parent)' : '');
                        continue;
                    }

                    $desc = 'Event: ' . $reg['event_name'] . ' — ' . $reg['first_name'] . ' ' . $reg['last_name'] . ' (admin processed)';
                    if ($serviceFee > 0) {
                        $desc .= ' (incl. $' . number_format($serviceFee, 2) . ' processing fee)';
                    }
                    $result = charge_student($chargeStudentId, $totalToCharge, $desc);

                    if ($result['success']) {
                        $upParams = [$totalToCharge, $reg['id']];
                        school_param($upParams);
                        $pdo->prepare("UPDATE event_registrations SET payment_status = 'paid', amount_paid = ? WHERE id = ?" . school_where())->execute($upParams);

                        // Record in payments table
                        $payNotes = $desc . ' | Txn: ' . ($result['transaction_id'] ?? 'N/A');
                        if ($serviceFee > 0) {
                            $payNotes .= ' | Service fee: $' . number_format($serviceFee, 2);
                        }
                        $pdo->prepare("
                            INSERT INTO payments (student_id, payment_type, reference_id, amount,
                                                payment_method, payment_date, receipt_number, notes, school_id)
                            VALUES (?, 'event', ?, ?, 'credit_card', CURDATE(), ?, ?, ?)
                        ")->execute([
                            $reg['student_id'],
                            $reg['event_id'],
                            $result['amount_charged'] ?? $totalToCharge,
                            generateReceiptNumber(),
                            $payNotes,
                            current_school_id()
                        ]);

                        // Record credit if used
                        if (($result['credit_used'] ?? 0) > 0) {
                            $pdo->prepare("
                                INSERT INTO payments (student_id, payment_type, reference_id, amount,
                                                    payment_method, payment_date, receipt_number, notes, school_id)
                                VALUES (?, 'event', ?, ?, 'account_credit', CURDATE(), ?, ?, ?)
                            ")->execute([
                                $chargeStudentId,
                                $reg['event_id'],
                                $result['credit_used'],
                                generateReceiptNumber(),
                                'Account credit applied (admin processed) — ' . $reg['event_name'],
                                current_school_id()
                            ]);
                        }

                        $successCount++;
                    } else {
                        $failCount++;
                        $failMessages[] = $reg['first_name'] . ' ' . $reg['last_name'] . ' — ' . ($result['error'] ?? 'Unknown error');
                    }
                }

                $msgs = [];
                if ($successCount > 0) $msgs[] = "Successfully charged {$successCount} registration(s).";
                if ($failCount > 0)    $msgs[] = "Failed to charge {$failCount} registration(s).";
                $alertType = $failCount === 0 ? 'success' : ($successCount > 0 ? 'warning' : 'error');
                $message = showAlert(implode(' ', $msgs), $alertType);
                if (!empty($failMessages)) {
                    $message .= '<div class="bg-red-50 border border-red-200 rounded-lg p-4 mt-3"><ul class="list-disc list-inside text-sm text-red-700">';
                    foreach ($failMessages as $fm) {
                        $message .= '<li>' . htmlspecialchars($fm) . '</li>';
                    }
                    $message .= '</ul></div>';
                }
                break;

            // ------------------------------------------------------------------
            // MARK AS PAID (manual / cash / in-person)
            // ------------------------------------------------------------------
            case 'mark_paid':
                $method = sanitizeInput($_POST['manual_method'] ?? 'cash');
                $notes  = sanitizeInput($_POST['manual_notes'] ?? '');

                $regQuery2 = "
                    SELECT er.id, er.student_id, er.event_id,
                           e.name AS event_name, e.registration_fee,
                           s.first_name, s.last_name
                    FROM event_registrations er
                    JOIN events e ON er.event_id = e.id
                    JOIN students s ON s.id = er.student_id
                    WHERE er.id IN ({$placeholders}) AND er.payment_status = 'pending'"
                    . school_where('s');
                $regParams2 = $selectedIds;
                school_param($regParams2);
                $regStmt = $pdo->prepare($regQuery2);
                $regStmt->execute($regParams2);
                $regs = $regStmt->fetchAll();

                $count = 0;
                foreach ($regs as $reg) {
                    $fee = (float)$reg['registration_fee'];
                    $upParams2 = [$fee, $reg['id']];
                    school_param($upParams2);
                    $pdo->prepare("UPDATE event_registrations SET payment_status = 'paid', amount_paid = ? WHERE id = ?" . school_where())->execute($upParams2);

                    // Record payment
                    $pdo->prepare("
                        INSERT INTO payments (student_id, payment_type, reference_id, amount,
                                            payment_method, payment_date, receipt_number, notes, school_id)
                        VALUES (?, 'event', ?, ?, ?, CURDATE(), ?, ?, ?)
                    ")->execute([
                        $reg['student_id'],
                        $reg['event_id'],
                        $fee,
                        $method,
                        generateReceiptNumber(),
                        'Event: ' . $reg['event_name'] . ' — ' . $reg['first_name'] . ' ' . $reg['last_name'] . ($notes ? " | {$notes}" : '') . ' (admin marked paid)',
                        current_school_id()
                    ]);
                    $count++;
                }
                $message = showAlert("Marked {$count} registration(s) as paid ({$method}).", 'success');
                break;

            // ------------------------------------------------------------------
            // WAIVE PAYMENT
            // ------------------------------------------------------------------
            case 'waive':
                $pdo->prepare("UPDATE event_registrations SET payment_status = 'waived', amount_paid = 0 WHERE id IN ({$placeholders}) AND payment_status = 'pending'")->execute($selectedIds);
                $message = showAlert('Selected registrations have been waived.', 'success');
                break;
        }
    }
}

// ---------------------------------------------------------------------------
// Fetch all pending event registrations
// ---------------------------------------------------------------------------
$filterEvent  = (int)($_GET['event_id'] ?? 0);
$filterSearch = trim($_GET['search'] ?? '');

$query = "
    SELECT er.id, er.student_id, er.event_id, er.parent_id, er.registration_date, er.payment_status,
           e.name AS event_name, e.event_date, e.event_type, e.registration_fee,
           s.first_name, s.last_name, s.email,
           ps.first_name AS parent_first, ps.last_name AS parent_last
    FROM event_registrations er
    JOIN events e ON er.event_id = e.id
    JOIN students s ON s.id = er.student_id
    LEFT JOIN students ps ON ps.id = er.parent_id AND ps.is_parent = 1
    WHERE er.payment_status = 'pending'
";
$params = [];

if ($filterEvent) {
    $query .= " AND er.event_id = ?";
    $params[] = $filterEvent;
}
if ($filterSearch !== '') {
    $query .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR e.name LIKE ?)";
    $searchWild = "%{$filterSearch}%";
    $params[] = $searchWild;
    $params[] = $searchWild;
    $params[] = $searchWild;
}

$query .= " ORDER BY e.event_date ASC, s.last_name ASC, s.first_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$pending = $stmt->fetchAll();

// Summary stats
$totalPending    = count($pending);
$totalOwedBase   = array_sum(array_column($pending, 'registration_fee'));
$uniqueEvents    = count(array_unique(array_column($pending, 'event_id')));
$uniqueStudents  = count(array_unique(array_column($pending, 'student_id')));

// Calculate total with processing fees for display
$serviceFeeRate  = getServiceFeePercentage();
$totalServiceFee = ($serviceFeeRate > 0) ? round($totalOwedBase * ($serviceFeeRate / 100), 2) : 0;
$totalOwedWithFees = round($totalOwedBase + $totalServiceFee, 2);

// Events list for filter dropdown
$events = $pdo->query("
    SELECT DISTINCT e.id, e.name, e.event_date
    FROM events e
    JOIN event_registrations er ON er.event_id = e.id
    WHERE er.payment_status = 'pending'
    ORDER BY e.event_date ASC
")->fetchAll();

// Check which students / parents have a card on file
$cardOnFile = [];
foreach ($pending as $reg) {
    $ownerId = $reg['parent_id'] ?: $reg['student_id'];
    if (!isset($cardOnFile[$ownerId])) {
        $pmStmt = $pdo->prepare("SELECT id FROM payment_methods WHERE student_id = ? AND is_default = 1 AND gateway_payment_method_id IS NOT NULL AND gateway_payment_method_id != '' LIMIT 1");
        $pmStmt->execute([$ownerId]);
        $cardOnFile[$ownerId] = (bool)$pmStmt->fetch();
    }
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Pending Payments</h1>
            <p class="text-gray-600 mt-1">Review and process outstanding event registration payments</p>
        </div>
        <a href="payments.php" class="text-blue-600 hover:text-blue-800 text-sm">View All Payments &rarr;</a>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <p class="text-sm text-yellow-700 font-medium">Pending Registrations</p>
            <p class="text-3xl font-bold text-yellow-800"><?= $totalPending ?></p>
        </div>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <p class="text-sm text-red-700 font-medium">Total Outstanding</p>
            <p class="text-3xl font-bold text-red-800"><?= formatMoney($totalOwedBase) ?></p>
            <?php if ($totalServiceFee > 0): ?>
                <p class="text-xs text-red-600 mt-1">
                    + <?= formatMoney($totalServiceFee) ?> processing fee (<?= number_format($serviceFeeRate, 2) ?>%)
                    = <strong><?= formatMoney($totalOwedWithFees) ?></strong> total
                </p>
            <?php endif; ?>
        </div>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <p class="text-sm text-blue-700 font-medium">Events Affected</p>
            <p class="text-3xl font-bold text-blue-800"><?= $uniqueEvents ?></p>
        </div>
        <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
            <p class="text-sm text-purple-700 font-medium">Students Owing</p>
            <p class="text-3xl font-bold text-purple-800"><?= $uniqueStudents ?></p>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-4">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">Search Student / Event</label>
                <input type="text" name="search" value="<?= htmlspecialchars($filterSearch) ?>" placeholder="Name or event..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div class="min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">Event</label>
                <select name="event_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="">All Events</option>
                    <?php foreach ($events as $ev): ?>
                        <option value="<?= $ev['id'] ?>" <?= $filterEvent == $ev['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ev['name']) ?> (<?= formatDate($ev['event_date']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Filter</button>
                <a href="pending_payments.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">Reset</a>
            </div>
        </form>
    </div>

    <?php if (empty($pending)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <div class="text-6xl mb-4">&#10004;&#65039;</div>
            <h2 class="text-2xl font-bold text-gray-800 mb-2">No Pending Payments</h2>
            <p class="text-gray-600">All event registration payments are up to date!</p>
        </div>
    <?php else: ?>

    <!-- Action Bar -->
    <form method="POST" id="pendingForm">
        <?= csrf_field() ?>
        <div class="bg-white rounded-lg shadow mb-4">
            <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700 cursor-pointer">
                        <input type="checkbox" id="selectAll" class="w-4 h-4 text-blue-600 border-gray-300 rounded">
                        Select All
                    </label>
                    <span id="selectedCount" class="text-sm text-gray-500">0 selected</span>
                    <span id="selectedTotal" class="text-sm font-semibold text-gray-700"></span>
                </div>

                <div class="flex items-center gap-2">
                    <?php if ($gwReady): ?>
                        <button type="submit" name="action" value="charge_card"
                                onclick="return confirmAction('Charge the selected registrations to the card on file? Processing fees will be included in the charge amount.')"
                                class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
                                id="chargeBtn" disabled
                                title="Includes <?= number_format($serviceFeeRate, 2) ?>% processing fee">
                            &#128179; Charge Card on File
                        </button>
                    <?php endif; ?>
                    <button type="button" onclick="showMarkPaidModal()"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
                            id="markPaidBtn" disabled>
                        &#9989; Mark as Paid
                    </button>
                    <button type="submit" name="action" value="waive"
                            onclick="return confirmAction('Waive the fees for the selected registrations?')"
                            class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
                            id="waiveBtn" disabled>
                        Waive
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-10"></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Event Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fee</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Card Charge</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Registered</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Parent</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Card on File</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($pending as $row):
                            $ownerId  = $row['parent_id'] ?: $row['student_id'];
                            $hasCard  = $cardOnFile[$ownerId] ?? false;
                            $fee      = (float)$row['registration_fee'];
                            $rowServiceFee = ($serviceFeeRate > 0) ? round($fee * ($serviceFeeRate / 100), 2) : 0;
                            $rowTotal = round($fee + $rowServiceFee, 2);
                        ?>
                            <tr class="hover:bg-gray-50 reg-row" data-fee="<?= $rowTotal ?>">
                                <td class="px-4 py-4">
                                    <input type="checkbox" name="registration_ids[]" value="<?= $row['id'] ?>"
                                           class="row-checkbox w-4 h-4 text-blue-600 border-gray-300 rounded">
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <a href="student_detail.php?id=<?= $row['student_id'] ?>" class="text-blue-600 hover:underline font-medium">
                                        <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                                    </a>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($row['email'] ?: '') ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <a href="event_detail.php?id=<?= $row['event_id'] ?>" class="text-blue-600 hover:underline">
                                        <?= htmlspecialchars($row['event_name']) ?>
                                    </a>
                                    <div class="text-xs text-gray-500 capitalize"><?= str_replace('_', ' ', $row['event_type']) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= formatDate($row['event_date']) ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm font-semibold text-red-600">
                                    <?= formatMoney($fee) ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm">
                                    <span class="font-semibold text-gray-900"><?= formatMoney($rowTotal) ?></span>
                                    <?php if ($rowServiceFee > 0): ?>
                                        <div class="text-xs text-gray-500">incl. <?= formatMoney($rowServiceFee) ?> fee</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= formatDate($row['registration_date']) ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php if ($row['parent_first']): ?>
                                        <?= htmlspecialchars($row['parent_first'] . ' ' . $row['parent_last']) ?>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    <?php if ($hasCard): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">&#128179; Yes</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-700">&#10060; No</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Hidden fields for mark-paid modal -->
        <input type="hidden" name="manual_method" id="hidden_method" value="cash">
        <input type="hidden" name="manual_notes"  id="hidden_notes"  value="">
    </form>

    <?php endif; ?>
</div>

<!-- Mark as Paid Modal -->
<div id="markPaidModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Mark as Paid</h3>
            <button onclick="document.getElementById('markPaidModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>

        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                <select id="modal_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="cash">Cash</option>
                    <option value="credit_card">Credit Card (Manual)</option>
                    <option value="debit_card">Debit Card</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="check">Check</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                <textarea id="modal_notes" rows="2" placeholder="e.g., Check #1234"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('markPaidModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="button" onclick="submitMarkPaid()"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var selectAll    = document.getElementById('selectAll');
    var checkboxes   = document.querySelectorAll('.row-checkbox');
    var countEl      = document.getElementById('selectedCount');
    var totalEl      = document.getElementById('selectedTotal');
    var chargeBtn    = document.getElementById('chargeBtn');
    var markPaidBtn  = document.getElementById('markPaidBtn');
    var waiveBtn     = document.getElementById('waiveBtn');

    function updateState() {
        var checked = document.querySelectorAll('.row-checkbox:checked');
        var count = checked.length;
        var total = 0;
        checked.forEach(function(cb) {
            var row = cb.closest('tr');
            total += parseFloat(row.getAttribute('data-fee') || 0);
        });

        countEl.textContent = count + ' selected';
        totalEl.textContent = count > 0 ? '(' + '$' + total.toFixed(2) + ')' : '';

        if (chargeBtn)  chargeBtn.disabled  = count === 0;
        if (markPaidBtn) markPaidBtn.disabled = count === 0;
        if (waiveBtn)   waiveBtn.disabled   = count === 0;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
            updateState();
        });
    }

    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', function() {
            if (!this.checked && selectAll) selectAll.checked = false;
            updateState();
        });
    });

    updateState();
})();

function confirmAction(msg) {
    return confirm(msg);
}

function showMarkPaidModal() {
    var checked = document.querySelectorAll('.row-checkbox:checked');
    if (checked.length === 0) { alert('No registrations selected.'); return; }
    document.getElementById('markPaidModal').classList.remove('hidden');
}

function submitMarkPaid() {
    document.getElementById('hidden_method').value = document.getElementById('modal_method').value;
    document.getElementById('hidden_notes').value  = document.getElementById('modal_notes').value;
    document.getElementById('markPaidModal').classList.add('hidden');

    // Create a hidden action input and submit
    var form = document.getElementById('pendingForm');
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'action'; inp.value = 'mark_paid';
    form.appendChild(inp);
    form.submit();
}
</script>

<?php include 'includes/footer.php'; ?>
