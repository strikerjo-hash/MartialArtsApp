<?php
require_once 'config.php';
require_once 'includes/payment_gateway.php';
requireLogin();
requireFinancialAccess();

$message = '';
$gatewayReady = is_gateway_ready();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    switch ($_POST['action']) {
        case 'add_payment':
            $stmt = $pdo->prepare("
                INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount,
                                    payment_method, payment_date, receipt_number, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                current_school_id(),
                $_POST['student_id'],
                $_POST['payment_type'],
                $_POST['reference_id'] ?: null,
                $_POST['amount'],
                $_POST['payment_method'],
                $_POST['payment_date'],
                generateReceiptNumber(),
                sanitizeInput($_POST['notes'])
            ]);
            $message = showAlert('Payment recorded successfully!', 'success');
            break;

        case 'edit_payment':
            $stmt = $pdo->prepare("
                UPDATE payments
                SET payment_method = ?, amount = ?, notes = ?
                WHERE id = ? AND school_id = ?
            ");
            $stmt->execute([
                $_POST['payment_method'],
                $_POST['amount'],
                sanitizeInput($_POST['notes']),
                (int)$_POST['payment_id'],
                current_school_id()
            ]);
            $message = showAlert('Payment updated successfully!', 'success');
            break;

        case 'process_card':
            $paymentId = (int)$_POST['payment_id'];
            // Fetch the payment record
            $pStmt = $pdo->prepare("
                SELECT p.*, s.first_name, s.last_name, s.email
                FROM payments p
                JOIN students s ON p.student_id = s.id
                WHERE p.id = ? AND p.school_id = ?
            ");
            $pStmt->execute([$paymentId, current_school_id()]);
            $paymentRecord = $pStmt->fetch();

            if (!$paymentRecord) {
                $message = showAlert('Payment record not found.', 'danger');
                break;
            }

            if (!$gatewayReady) {
                $message = showAlert('Payment gateway is not configured. Please set up Stripe or Square in settings.', 'danger');
                break;
            }

            $chargeAmount = (float)$paymentRecord['amount'];
            $studentName = $paymentRecord['first_name'] . ' ' . $paymentRecord['last_name'];
            $desc = ucfirst($paymentRecord['payment_type']) . ' payment for ' . $studentName . ' (Receipt: ' . $paymentRecord['receipt_number'] . ')';

            $result = charge_student((int)$paymentRecord['student_id'], $chargeAmount, $desc);

            if ($result['success']) {
                // Update the payment record
                $newNotes = trim($paymentRecord['notes'] . ' | Txn: ' . $result['transaction_id']);
                $upStmt = $pdo->prepare("
                    UPDATE payments
                    SET payment_method = 'credit_card', notes = ?
                    WHERE id = ? AND school_id = ?
                ");
                $upStmt->execute([$newNotes, $paymentId, current_school_id()]);

                // If membership payment with reference_id, mark membership as paid
                if ($paymentRecord['payment_type'] === 'membership' && !empty($paymentRecord['reference_id'])) {
                    $memStmt = $pdo->prepare("
                        UPDATE memberships SET payment_status = 'paid'
                        WHERE id = ? AND school_id = ?
                    ");
                    $memStmt->execute([(int)$paymentRecord['reference_id'], current_school_id()]);
                }

                // Send receipt email
                try {
                    if (function_exists('send_payment_receipt_email')) {
                        send_payment_receipt_email([
                            'student_id'     => $paymentRecord['student_id'],
                            'amount'         => $chargeAmount,
                            'payment_type'   => $paymentRecord['payment_type'],
                            'description'    => $desc,
                            'receipt_number' => $paymentRecord['receipt_number'],
                            'transaction_id' => $result['transaction_id'],
                            'payment_method' => 'credit_card',
                        ]);
                    }
                } catch (\Throwable $e) { /* email failure shouldn't block success */ }

                $creditNote = $result['credit_used'] > 0 ? ' (' . formatMoney($result['credit_used']) . ' covered by credit)' : '';
                $message = showAlert('Card charged successfully! Transaction: ' . $result['transaction_id'] . $creditNote, 'success');
            } else {
                $message = showAlert('Card charge failed: ' . ($result['error'] ?? 'Unknown error'), 'danger');
            }
            break;
    }
}

// Get payments with filters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$payment_type = $_GET['type'] ?? '';

$query = "
    SELECT p.*, s.first_name, s.last_name, s.email
    FROM payments p
    JOIN students s ON p.student_id = s.id
    WHERE p.payment_date BETWEEN :date_from AND :date_to
";

if (!is_viewing_all_schools()) {
    $query .= " AND p.school_id = :school_id";
}

if ($payment_type) {
    $query .= " AND p.payment_type = :type";
}

$query .= " ORDER BY p.payment_date DESC, p.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->bindValue(':date_from', $date_from);
$stmt->bindValue(':date_to', $date_to);
if (!is_viewing_all_schools()) {
    $stmt->bindValue(':school_id', current_school_id(), PDO::PARAM_INT);
}
if ($payment_type) {
    $stmt->bindValue(':type', $payment_type);
}
$stmt->execute();
$payments = $stmt->fetchAll();

// Calculate totals
$total_amount = array_sum(array_column($payments, 'amount'));

// Get students for dropdown
$student_params = ['active'];
school_param($student_params);
$student_stmt = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE status = ?" . school_where() . " ORDER BY first_name, last_name");
$student_stmt->execute($student_params);
$students = $student_stmt->fetchAll();

// Pre-fetch card info for students that appear in the payments list
$studentCardInfo = [];
$uniqueStudentIds = array_unique(array_column($payments, 'student_id'));
foreach ($uniqueStudentIds as $sid) {
    $cardInfo = get_student_default_payment((int)$sid);
    if ($cardInfo) {
        $studentCardInfo[$sid] = $cardInfo;
    }
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Payments</h1>
        <button onclick="document.getElementById('addPaymentModal').classList.remove('hidden')" 
                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
            + Record Payment
        </button>
    </div>
    
    <!-- Payment Integration Notice -->
    <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-blue-700">
                    <strong>Payment Gateway Integration:</strong> To accept online payments, configure your Stripe or Square API keys in settings. 
                    <a href="settings.php" class="underline">Configure Now →</a>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Filters and Summary -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg">
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
            
            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                    Filter
                </button>
                <a href="payments.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                    Reset
                </a>
            </div>
        </form>
        
        <!-- Summary -->
        <div class="mt-6 pt-6 border-t border-gray-200">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-green-50 rounded-lg p-4">
                    <p class="text-sm text-green-600 font-medium">Total Payments</p>
                    <p class="text-2xl font-bold text-green-700"><?php echo count($payments); ?></p>
                </div>
                <div class="bg-blue-50 rounded-lg p-4">
                    <p class="text-sm text-blue-600 font-medium">Total Amount</p>
                    <p class="text-2xl font-bold text-blue-700"><?php echo formatMoney($total_amount); ?></p>
                </div>
                <div class="bg-purple-50 rounded-lg p-4">
                    <p class="text-sm text-purple-600 font-medium">Average Payment</p>
                    <p class="text-2xl font-bold text-purple-700">
                        <?php echo count($payments) > 0 ? formatMoney($total_amount / count($payments)) : '$0.00'; ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Payments Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Payment History</h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($payments as $payment):
                        $hasTxn = strpos($payment['notes'] ?? '', 'Txn:') !== false;
                        $paymentJson = htmlspecialchars(json_encode([
                            'id'             => $payment['id'],
                            'receipt_number' => $payment['receipt_number'],
                            'student_id'     => $payment['student_id'],
                            'student_name'   => $payment['first_name'] . ' ' . $payment['last_name'],
                            'payment_type'   => $payment['payment_type'],
                            'payment_method' => $payment['payment_method'],
                            'amount'         => $payment['amount'],
                            'notes'          => $payment['notes'] ?? '',
                            'has_txn'        => $hasTxn,
                        ]), ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900">
                                <?php echo $payment['receipt_number']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo formatDate($payment['payment_date']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo $payment['first_name'] . ' ' . $payment['last_name']; ?>
                                </div>
                                <div class="text-sm text-gray-500"><?php echo $payment['email']; ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $typeColors = [
                                    'membership' => 'bg-blue-100 text-blue-800',
                                    'event' => 'bg-purple-100 text-purple-800',
                                    'merchandise' => 'bg-green-100 text-green-800',
                                    'other' => 'bg-gray-100 text-gray-800'
                                ];
                                ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $typeColors[$payment['payment_type']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                    <?php echo ucfirst($payment['payment_type']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 capitalize">
                                <?php echo str_replace('_', ' ', $payment['payment_method']); ?>
                                <?php if ($hasTxn): ?>
                                    <span class="inline-block ml-1 text-green-600" title="Card processed">✓</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600">
                                <?php echo formatMoney($payment['amount']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate" title="<?php echo htmlspecialchars($payment['notes'] ?? ''); ?>">
                                <?php echo $payment['notes'] ?: '-'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <div class="relative inline-block text-left">
                                    <button onclick="togglePaymentMenu(this)" type="button"
                                            class="text-gray-400 hover:text-gray-600 focus:outline-none p-1 rounded hover:bg-gray-100">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/>
                                        </svg>
                                    </button>
                                    <div class="payment-menu hidden absolute right-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-30">
                                        <div class="py-1">
                                            <a href="#" onclick="openEditModal(<?php echo $paymentJson; ?>); return false;"
                                               class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                ✏️ Edit Payment
                                            </a>
                                            <?php if (!$hasTxn): ?>
                                                <a href="#" onclick="openProcessCardModal(<?php echo $paymentJson; ?>); return false;"
                                                   class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 <?php echo !$gatewayReady ? 'opacity-50 cursor-not-allowed' : ''; ?>"
                                                   <?php echo !$gatewayReady ? 'title="Payment gateway not configured"' : ''; ?>>
                                                    💳 Process Card
                                                </a>
                                            <?php else: ?>
                                                <span class="block px-4 py-2 text-sm text-gray-400 cursor-not-allowed" title="Card already processed">
                                                    ✅ Card Processed
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (empty($payments)): ?>
                <div class="text-center py-12 text-gray-500">
                    <p class="text-lg">No payments found for the selected filters</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Payment Modal -->
<div id="addPaymentModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Record Payment</h3>
            <button onclick="document.getElementById('addPaymentModal').classList.add('hidden')" 
                    class="text-gray-600 hover:text-gray-800">✕</button>
        </div>
        
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_payment">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Student *</label>
                <div id="payment-student-picker"></div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Type *</label>
                    <select name="payment_type" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="membership">Membership</option>
                        <option value="event">Event</option>
                        <option value="merchandise">Merchandise</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                    <select name="payment_method" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="cash">Cash</option>
                        <option value="credit_card">Credit Card</option>
                        <option value="debit_card">Debit Card</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                    <input type="number" name="amount" step="0.01" min="0" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date *</label>
                    <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Reference ID (optional)</label>
                <input type="text" name="reference_id" placeholder="Membership ID or Event ID"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addPaymentModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Record Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Payment Modal -->
<div id="editPaymentModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit Payment</h3>
            <button onclick="document.getElementById('editPaymentModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">✕</button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_payment">
            <input type="hidden" name="payment_id" id="edit_payment_id">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Receipt #</label>
                    <input type="text" id="edit_receipt" readonly
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-500 font-mono">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Student</label>
                    <input type="text" id="edit_student_name" readonly
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-500">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                    <select name="payment_method" id="edit_payment_method" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="cash">Cash</option>
                        <option value="credit_card">Credit Card</option>
                        <option value="debit_card">Debit Card</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                    <input type="number" name="amount" id="edit_amount" step="0.01" min="0" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" id="edit_notes" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editPaymentModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Process Card Modal -->
<div id="processCardModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Process Card Payment</h3>
            <button onclick="document.getElementById('processCardModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">✕</button>
        </div>

        <form method="POST" id="processCardForm" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="process_card">
            <input type="hidden" name="payment_id" id="pc_payment_id">

            <!-- Payment Details -->
            <div class="bg-gray-50 rounded-lg p-4 space-y-2">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Student:</span>
                    <span class="font-medium text-gray-800" id="pc_student_name"></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Receipt #:</span>
                    <span class="font-mono text-gray-800" id="pc_receipt"></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Type:</span>
                    <span class="font-medium text-gray-800 capitalize" id="pc_type"></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Amount to Charge:</span>
                    <span class="font-bold text-green-700 text-lg" id="pc_amount"></span>
                </div>
            </div>

            <!-- Card Info -->
            <div id="pc_card_info" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p class="text-sm font-medium text-blue-800 mb-1">Card on File</p>
                <p class="text-lg font-semibold text-blue-900" id="pc_card_display"></p>
                <p class="text-xs text-blue-600" id="pc_card_exp"></p>
            </div>

            <div id="pc_no_card" class="hidden bg-red-50 border border-red-200 rounded-lg p-4">
                <p class="text-sm font-medium text-red-800">No Card on File</p>
                <p class="text-xs text-red-600">This student does not have a saved payment method. Please add a card before processing.</p>
            </div>

            <!-- Warning -->
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-3">
                <p class="text-sm text-yellow-700">
                    <strong>Warning:</strong> This will charge the student's card on file for the amount shown above. This action cannot be undone.
                </p>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('processCardModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" id="pc_submit_btn"
                        class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium">
                    💳 Charge Card
                </button>
            </div>
        </form>
    </div>
</div>

<!-- JavaScript for Payment Actions -->
<script>
// Student card info lookup
const studentCardInfo = <?php echo json_encode($studentCardInfo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

// Toggle dropdown menu
function togglePaymentMenu(btn) {
    // Close all other open menus first
    document.querySelectorAll('.payment-menu').forEach(m => {
        if (m !== btn.nextElementSibling) m.classList.add('hidden');
    });
    const menu = btn.nextElementSibling;
    menu.classList.toggle('hidden');
}

// Close menus when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.relative.inline-block')) {
        document.querySelectorAll('.payment-menu').forEach(m => m.classList.add('hidden'));
    }
});

// Open Edit Payment Modal
function openEditModal(payment) {
    document.querySelectorAll('.payment-menu').forEach(m => m.classList.add('hidden'));
    document.getElementById('edit_payment_id').value = payment.id;
    document.getElementById('edit_receipt').value = payment.receipt_number;
    document.getElementById('edit_student_name').value = payment.student_name;
    document.getElementById('edit_payment_method').value = payment.payment_method;
    document.getElementById('edit_amount').value = parseFloat(payment.amount).toFixed(2);
    document.getElementById('edit_notes').value = payment.notes || '';
    document.getElementById('editPaymentModal').classList.remove('hidden');
}

// Open Process Card Modal
function openProcessCardModal(payment) {
    document.querySelectorAll('.payment-menu').forEach(m => m.classList.add('hidden'));

    <?php if (!$gatewayReady): ?>
    alert('Payment gateway is not configured. Please set up Stripe or Square in Settings first.');
    return;
    <?php endif; ?>

    document.getElementById('pc_payment_id').value = payment.id;
    document.getElementById('pc_student_name').textContent = payment.student_name;
    document.getElementById('pc_receipt').textContent = payment.receipt_number;
    document.getElementById('pc_type').textContent = payment.payment_type;
    document.getElementById('pc_amount').textContent = '$' + parseFloat(payment.amount).toFixed(2);

    // Look up card info
    const card = studentCardInfo[payment.student_id];
    const cardInfoDiv = document.getElementById('pc_card_info');
    const noCardDiv = document.getElementById('pc_no_card');
    const submitBtn = document.getElementById('pc_submit_btn');

    if (card) {
        cardInfoDiv.classList.remove('hidden');
        noCardDiv.classList.add('hidden');
        document.getElementById('pc_card_display').textContent = card.brand + ' •••• ' + card.last_four;
        document.getElementById('pc_card_exp').textContent = card.exp ? 'Expires ' + card.exp : '';
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    } else {
        cardInfoDiv.classList.add('hidden');
        noCardDiv.classList.remove('hidden');
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
    }

    // Reset button state
    submitBtn.textContent = '💳 Charge Card';

    document.getElementById('processCardModal').classList.remove('hidden');
}

// Double-submit prevention for process card
document.getElementById('processCardForm').addEventListener('submit', function(e) {
    const btn = document.getElementById('pc_submit_btn');
    if (btn.disabled) {
        e.preventDefault();
        return;
    }
    btn.disabled = true;
    btn.textContent = 'Processing...';
    btn.classList.add('opacity-75');
});
</script>

<script src="assets/js/student-picker.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    StudentPicker.init({
        container: '#payment-student-picker',
        inputName: 'student_id',
        placeholder: 'Type student name to search\u2026',
        data: <?= json_encode(array_map(function($s) { return ['id' => $s['id'], 'name' => trim($s['first_name'] . ' ' . $s['last_name'])]; }, $students)) ?>
    });
});
</script>

<?php include 'includes/footer.php'; ?>
