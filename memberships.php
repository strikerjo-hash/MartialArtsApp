<?php
require_once 'config.php';
requireLogin();

$message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_plan':
                $stmt = $pdo->prepare("
                    INSERT INTO membership_plans (name, description, duration_months, price, classes_per_week, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    sanitizeInput($_POST['name']),
                    sanitizeInput($_POST['description']),
                    $_POST['duration_months'],
                    $_POST['price'],
                    $_POST['classes_per_week'],
                    $_POST['status']
                ]);
                $message = showAlert('Membership plan created successfully!', 'success');
                break;
                
            case 'edit_plan':
                $stmt = $pdo->prepare("
                    UPDATE membership_plans 
                    SET name = ?, description = ?, duration_months = ?, price = ?, 
                        classes_per_week = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    sanitizeInput($_POST['name']),
                    sanitizeInput($_POST['description']),
                    $_POST['duration_months'],
                    $_POST['price'],
                    $_POST['classes_per_week'],
                    $_POST['status'],
                    $_POST['plan_id']
                ]);
                $message = showAlert('Membership plan updated successfully!', 'success');
                break;
                
            case 'delete_plan':
                // Check if plan has active memberships
                $check = $pdo->prepare("SELECT COUNT(*) as count FROM memberships WHERE plan_id = ? AND status = 'active'");
                $check->execute([$_POST['plan_id']]);
                $count = $check->fetch()['count'];
                
                if ($count > 0) {
                    $message = showAlert('Cannot delete plan with active memberships! Deactivate it instead.', 'error');
                } else {
                    $stmt = $pdo->prepare("DELETE FROM membership_plans WHERE id = ?");
                    $stmt->execute([$_POST['plan_id']]);
                    $message = showAlert('Membership plan deleted successfully!', 'success');
                }
                break;
                
            case 'add_membership':
                $stmt = $pdo->prepare("
                    INSERT INTO memberships (student_id, plan_id, start_date, end_date, status, payment_status, amount_paid)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $start_date = $_POST['start_date'];
                $plan_id = $_POST['plan_id'];
                
                // Get plan details to calculate end date
                $plan = $pdo->prepare("SELECT duration_months, price FROM membership_plans WHERE id = ?");
                $plan->execute([$plan_id]);
                $plan_data = $plan->fetch();
                
                $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $plan_data['duration_months'] . ' months'));
                
                $stmt->execute([
                    $_POST['student_id'],
                    $plan_id,
                    $start_date,
                    $end_date,
                    'active',
                    $_POST['payment_status'],
                    $_POST['amount_paid']
                ]);
                
                // Record payment if paid
                if ($_POST['payment_status'] === 'paid' && $_POST['amount_paid'] > 0) {
                    $membership_id = $pdo->lastInsertId();
                    $stmt = $pdo->prepare("
                        INSERT INTO payments (student_id, payment_type, reference_id, amount, 
                                            payment_method, payment_date, receipt_number, notes)
                        VALUES (?, 'membership', ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['student_id'],
                        $membership_id,
                        $_POST['amount_paid'],
                        $_POST['payment_method'],
                        date('Y-m-d'),
                        generateReceiptNumber(),
                        'Membership payment'
                    ]);
                }
                
                $message = showAlert('Membership added successfully!', 'success');
                break;
                
            case 'cancel_membership':
                $stmt = $pdo->prepare("UPDATE memberships SET status = 'cancelled' WHERE id = ?");
                $stmt->execute([$_POST['membership_id']]);
                $message = showAlert('Membership cancelled!', 'success');
                break;
        }
    }
}

// Get all membership plans
$plans = $pdo->query("SELECT * FROM membership_plans ORDER BY price ASC")->fetchAll();

// Get active memberships
$status_filter = $_GET['status'] ?? 'active';

$query = "
    SELECT m.*, 
           s.first_name, s.last_name, s.email,
           mp.name as plan_name, mp.price as plan_price
    FROM memberships m
    JOIN students s ON m.student_id = s.id
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE 1=1
";

if ($status_filter) {
    $query .= " AND m.status = :status";
}

$query .= " ORDER BY m.created_at DESC";

$stmt = $pdo->prepare($query);
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$memberships = $stmt->fetchAll();

// Get active students for dropdown
$students = $pdo->query("SELECT id, first_name, last_name FROM students WHERE status = 'active' ORDER BY first_name, last_name")->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Memberships</h1>
        <div class="space-x-3">
            <button onclick="document.getElementById('addPlanModal').classList.remove('hidden')" 
                    class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-medium">
                + Add Plan
            </button>
            <button onclick="document.getElementById('addMembershipModal').classList.remove('hidden')" 
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                + Add Membership
            </button>
        </div>
    </div>
    
    <!-- Membership Plans -->
    <div class="mb-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-4">Membership Plans</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($plans as $plan): ?>
                <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-6">
                        <h3 class="text-2xl font-bold mb-2"><?php echo $plan['name']; ?></h3>
                        <p class="text-3xl font-bold"><?php echo formatMoney($plan['price']); ?></p>
                        <p class="text-sm opacity-90">per <?php echo $plan['duration_months']; ?> month(s)</p>
                    </div>
                    <div class="p-6">
                        <p class="text-gray-700 mb-4"><?php echo $plan['description']; ?></p>
                        <ul class="space-y-2 text-gray-600">
                            <li>✓ <?php echo $plan['classes_per_week'] == 99 ? 'Unlimited' : $plan['classes_per_week']; ?> classes/week</li>
                            <li>✓ <?php echo $plan['duration_months']; ?> month duration</li>
                            <li>✓ All martial arts styles</li>
                        </ul>
                        <div class="mt-4">
                            <span class="px-3 py-1 text-sm rounded-full <?php echo $plan['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                <?php echo ucfirst($plan['status']); ?>
                            </span>
                        </div>
                        
                        <?php if (getCurrentUser()['role'] === 'admin'): ?>
                        <div class="mt-4 pt-4 border-t border-gray-200 flex space-x-2">
                            <button onclick="editPlan(<?php echo htmlspecialchars(json_encode($plan)); ?>)" 
                                    class="flex-1 text-blue-600 hover:text-blue-800 text-sm font-medium">
                                Edit
                            </button>
                            <form method="POST" class="flex-1" onsubmit="return confirmDelete('Delete this plan? This cannot be undone if no active memberships exist.')">
                                <input type="hidden" name="action" value="delete_plan">
                                <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                <button type="submit" class="w-full text-red-600 hover:text-red-800 text-sm font-medium">
                                    Delete
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Active Memberships -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-xl font-semibold text-gray-800">Active Memberships</h2>
            <div class="flex space-x-2">
                <a href="?status=active" class="px-4 py-2 rounded-lg text-sm <?php echo $status_filter === 'active' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">
                    Active
                </a>
                <a href="?status=expired" class="px-4 py-2 rounded-lg text-sm <?php echo $status_filter === 'expired' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">
                    Expired
                </a>
                <a href="?status=cancelled" class="px-4 py-2 rounded-lg text-sm <?php echo $status_filter === 'cancelled' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">
                    Cancelled
                </a>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Start Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">End Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($memberships as $membership): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="font-medium text-gray-900">
                                    <?php echo $membership['first_name'] . ' ' . $membership['last_name']; ?>
                                </div>
                                <div class="text-sm text-gray-500"><?php echo $membership['email']; ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900"><?php echo $membership['plan_name']; ?></div>
                                <div class="text-sm text-gray-500"><?php echo formatMoney($membership['plan_price']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo formatDate($membership['start_date']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo formatDate($membership['end_date']); ?>
                                <?php 
                                $days_left = (strtotime($membership['end_date']) - time()) / (60 * 60 * 24);
                                if ($days_left > 0 && $days_left <= 30):
                                ?>
                                    <div class="text-xs text-orange-600"><?php echo floor($days_left); ?> days left</div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $statusColors = [
                                    'active' => 'bg-green-100 text-green-800',
                                    'expired' => 'bg-red-100 text-red-800',
                                    'cancelled' => 'bg-gray-100 text-gray-800'
                                ];
                                ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $statusColors[$membership['status']]; ?>">
                                    <?php echo ucfirst($membership['status']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $paymentColors = [
                                    'paid' => 'bg-green-100 text-green-800',
                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                    'partial' => 'bg-orange-100 text-orange-800'
                                ];
                                ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $paymentColors[$membership['payment_status']]; ?>">
                                    <?php echo ucfirst($membership['payment_status']); ?>
                                </span>
                                <div class="text-xs text-gray-600 mt-1">
                                    <?php echo formatMoney($membership['amount_paid']); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <a href="student_detail.php?id=<?php echo $membership['student_id']; ?>" 
                                   class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                <?php if ($membership['status'] === 'active'): ?>
                                    <form method="POST" class="inline" onsubmit="return confirmDelete('Cancel this membership?')">
                                        <input type="hidden" name="action" value="cancel_membership">
                                        <input type="hidden" name="membership_id" value="<?php echo $membership['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-900">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (empty($memberships)): ?>
                <div class="text-center py-12 text-gray-500">
                    <p class="text-lg">No <?php echo $status_filter; ?> memberships found</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Plan Modal -->
<div id="addPlanModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Create Membership Plan</h3>
            <button onclick="document.getElementById('addPlanModal').classList.add('hidden')" 
                    class="text-gray-600 hover:text-gray-800">✕</button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_plan">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Plan Name *</label>
                <input type="text" name="name" required placeholder="e.g., Premium Monthly"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="2" placeholder="Brief description of this plan"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Duration (months) *</label>
                    <input type="number" name="duration_months" min="1" required value="1"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price *</label>
                    <input type="number" name="price" step="0.01" min="0" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Classes/Week *</label>
                    <input type="number" name="classes_per_week" min="1" max="99" required placeholder="99 = unlimited"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addPlanModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">
                    Create Plan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Membership Modal -->
<div id="addMembershipModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add Membership</h3>
            <button onclick="document.getElementById('addMembershipModal').classList.add('hidden')" 
                    class="text-gray-600 hover:text-gray-800">✕</button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_membership">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Student *</label>
                <select name="student_id" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="">Choose a student...</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['id']; ?>">
                            <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Plan *</label>
                <select name="plan_id" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="">Choose a plan...</option>
                    <?php foreach ($plans as $plan): ?>
                        <?php if ($plan['status'] === 'active'): ?>
                            <option value="<?php echo $plan['id']; ?>">
                                <?php echo $plan['name']; ?> - <?php echo formatMoney($plan['price']); ?> 
                                (<?php echo $plan['duration_months']; ?> months)
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date *</label>
                <input type="date" name="start_date" value="<?php echo date('Y-m-d'); ?>" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Status *</label>
                    <select name="payment_status" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                        <option value="partial">Partial</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount Paid *</label>
                    <input type="number" name="amount_paid" step="0.01" min="0" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                <select name="payment_method"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="cash">Cash</option>
                    <option value="credit_card">Credit Card</option>
                    <option value="debit_card">Debit Card</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="other">Other</option>
                </select>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addMembershipModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Add Membership
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Plan Modal -->
<div id="editPlanModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit Membership Plan</h3>
            <button onclick="document.getElementById('editPlanModal').classList.add('hidden')" 
                    class="text-gray-600 hover:text-gray-800">✕</button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="edit_plan">
            <input type="hidden" name="plan_id" id="edit_plan_id">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Plan Name *</label>
                <input type="text" name="name" id="edit_name" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" id="edit_description" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Duration (months) *</label>
                    <input type="number" name="duration_months" id="edit_duration_months" min="1" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price *</label>
                    <input type="number" name="price" id="edit_price" step="0.01" min="0" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Classes/Week *</label>
                    <input type="number" name="classes_per_week" id="edit_classes_per_week" min="1" max="99" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" id="edit_status"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editPlanModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Update Plan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editPlan(plan) {
    document.getElementById('edit_plan_id').value = plan.id;
    document.getElementById('edit_name').value = plan.name;
    document.getElementById('edit_description').value = plan.description || '';
    document.getElementById('edit_duration_months').value = plan.duration_months;
    document.getElementById('edit_price').value = plan.price;
    document.getElementById('edit_classes_per_week').value = plan.classes_per_week;
    document.getElementById('edit_status').value = plan.status;
    document.getElementById('editPlanModal').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
