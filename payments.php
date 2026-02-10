<?php
require_once 'config.php';
requireLogin();

$message = '';

// Handle manual payment entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_payment') {
        $stmt = $pdo->prepare("
            INSERT INTO payments (student_id, payment_type, reference_id, amount, 
                                payment_method, payment_date, receipt_number, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
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

if ($payment_type) {
    $query .= " AND p.payment_type = :type";
}

$query .= " ORDER BY p.payment_date DESC, p.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->bindValue(':date_from', $date_from);
$stmt->bindValue(':date_to', $date_to);
if ($payment_type) {
    $stmt->bindValue(':type', $payment_type);
}
$stmt->execute();
$payments = $stmt->fetchAll();

// Calculate totals
$total_amount = array_sum(array_column($payments, 'amount'));

// Get students for dropdown
$students = $pdo->query("SELECT id, first_name, last_name FROM students WHERE status = 'active' ORDER BY first_name, last_name")->fetchAll();

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
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($payments as $payment): ?>
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
                                <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $typeColors[$payment['payment_type']]; ?>">
                                    <?php echo ucfirst($payment['payment_type']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 capitalize">
                                <?php echo str_replace('_', ' ', $payment['payment_method']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600">
                                <?php echo formatMoney($payment['amount']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                <?php echo $payment['notes'] ?: '-'; ?>
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
            <input type="hidden" name="action" value="add_payment">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Student *</label>
                <select name="student_id" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="">Select student...</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['id']; ?>">
                            <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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

<?php include 'includes/footer.php'; ?>
