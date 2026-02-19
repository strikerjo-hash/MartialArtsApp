<?php
require_once 'config.php';
requireLogin();

// --- Idempotent migrations ---
try { $pdo->exec("ALTER TABLE memberships ADD COLUMN auto_renew TINYINT(1) NOT NULL DEFAULT 1"); } catch (PDOException $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS renewal_log (
        id INT PRIMARY KEY AUTO_INCREMENT,
        membership_id INT NOT NULL,
        student_id INT NOT NULL,
        action ENUM('renewed','expired','payment_failed','no_gateway') NOT NULL,
        old_end_date DATE,
        new_end_date DATE,
        amount DECIMAL(10,2),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    )");
} catch (PDOException $e) {}

// Billing frequency: per-plan control over upfront vs monthly installments
try { $pdo->exec("ALTER TABLE membership_plans ADD COLUMN billing_frequency ENUM('upfront','monthly') NOT NULL DEFAULT 'upfront'"); } catch (PDOException $e) {}

// Tax-deductible flag for dependent care / camp programs
try { $pdo->exec("ALTER TABLE membership_plans ADD COLUMN tax_deductible TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}

// Afterschool program support: fixed-term plans with start/end dates and proration
try { $pdo->exec("ALTER TABLE membership_plans ADD COLUMN is_afterschool TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE membership_plans ADD COLUMN program_start_date DATE DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE membership_plans ADD COLUMN program_end_date DATE DEFAULT NULL"); } catch (PDOException $e) {}

// Day-of-month for monthly billing (capped at 28 for safety)
try { $pdo->exec("ALTER TABLE memberships ADD COLUMN billing_day TINYINT DEFAULT NULL"); } catch (PDOException $e) {}

// Track how many monthly installments have been charged in the current cycle
try { $pdo->exec("ALTER TABLE memberships ADD COLUMN monthly_charges_made INT NOT NULL DEFAULT 0"); } catch (PDOException $e) {}

$message = '';

// Flash message from cron.php
if (isset($_SESSION['flash_message'])) {
    $message = showAlert($_SESSION['flash_message'], 'info');
    unset($_SESSION['flash_message']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_plan':
                $billing_freq = (isset($_POST['billing_frequency']) && $_POST['billing_frequency'] === 'monthly') ? 'monthly' : 'upfront';
                $reg_fee = max(0, (float) ($_POST['registration_fee'] ?? 0));
                $tax_ded = isset($_POST['tax_deductible']) ? 1 : 0;
                $is_afterschool = isset($_POST['is_afterschool']) ? 1 : 0;
                $program_start = $is_afterschool ? (trim($_POST['program_start_date'] ?? '') ?: null) : null;
                $program_end   = $is_afterschool ? (trim($_POST['program_end_date']   ?? '') ?: null) : null;

                if ($is_afterschool && (!$program_start || !$program_end || $program_end <= $program_start)) {
                    $message = showAlert('Afterschool plans require a valid start date before the end date.', 'error');
                    break;
                }

                // Auto-compute duration_months from program dates for afterschool plans
                $duration_months = $is_afterschool
                    ? max(1, (int) round((strtotime($program_end) - strtotime($program_start)) / (30.44 * 86400)))
                    : (int) $_POST['duration_months'];

                $stmt = $pdo->prepare("INSERT INTO membership_plans (name, description, duration_months, price, classes_per_week, status, billing_frequency, registration_fee, tax_deductible, is_afterschool, program_start_date, program_end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([sanitizeInput($_POST['name']), sanitizeInput($_POST['description']), $duration_months, $_POST['price'], $_POST['classes_per_week'], $_POST['status'], $billing_freq, $reg_fee, $tax_ded, $is_afterschool, $program_start, $program_end]);
                $message = showAlert('Membership plan created successfully!', 'success');
                break;

            case 'edit_plan':
                $billing_freq = (isset($_POST['billing_frequency']) && $_POST['billing_frequency'] === 'monthly') ? 'monthly' : 'upfront';
                $reg_fee = max(0, (float) ($_POST['registration_fee'] ?? 0));
                $tax_ded = isset($_POST['tax_deductible']) ? 1 : 0;
                $is_afterschool = isset($_POST['is_afterschool']) ? 1 : 0;
                $program_start = $is_afterschool ? (trim($_POST['program_start_date'] ?? '') ?: null) : null;
                $program_end   = $is_afterschool ? (trim($_POST['program_end_date']   ?? '') ?: null) : null;

                if ($is_afterschool && (!$program_start || !$program_end || $program_end <= $program_start)) {
                    $message = showAlert('Afterschool plans require a valid start date before the end date.', 'error');
                    break;
                }

                // Auto-compute duration_months from program dates for afterschool plans
                $duration_months = $is_afterschool
                    ? max(1, (int) round((strtotime($program_end) - strtotime($program_start)) / (30.44 * 86400)))
                    : (int) $_POST['duration_months'];

                $stmt = $pdo->prepare("UPDATE membership_plans SET name = ?, description = ?, duration_months = ?, price = ?, classes_per_week = ?, status = ?, billing_frequency = ?, registration_fee = ?, tax_deductible = ?, is_afterschool = ?, program_start_date = ?, program_end_date = ? WHERE id = ?");
                $stmt->execute([sanitizeInput($_POST['name']), sanitizeInput($_POST['description']), $duration_months, $_POST['price'], $_POST['classes_per_week'], $_POST['status'], $billing_freq, $reg_fee, $tax_ded, $is_afterschool, $program_start, $program_end, $_POST['plan_id']]);
                $message = showAlert('Membership plan updated successfully!', 'success');
                break;

            case 'delete_plan':
                $check = $pdo->prepare("SELECT COUNT(*) as count FROM memberships WHERE plan_id = ? AND status = 'active'");
                $check->execute([$_POST['plan_id']]);
                if ($check->fetch()['count'] > 0) {
                    $message = showAlert('Cannot delete plan with active memberships! Deactivate it instead.', 'error');
                } else {
                    $pdo->prepare("DELETE FROM membership_plans WHERE id = ?")->execute([$_POST['plan_id']]);
                    $message = showAlert('Membership plan deleted successfully!', 'success');
                }
                break;

            case 'add_membership':
                $start_date = $_POST['start_date'];
                $plan_id = $_POST['plan_id'];
                $auto_renew = isset($_POST['auto_renew']) ? 1 : 0;

                $plan = $pdo->prepare("SELECT duration_months, price, billing_frequency, is_afterschool, program_start_date, program_end_date FROM membership_plans WHERE id = ?");
                $plan->execute([$plan_id]);
                $plan_data = $plan->fetch();

                // Afterschool plans: use fixed program end date, force no auto-renew
                if (!empty($plan_data['is_afterschool'])) {
                    if ($start_date > $plan_data['program_end_date']) {
                        $message = showAlert('Cannot enroll — this afterschool program has already ended.', 'error');
                        break;
                    }
                    $end_date = $plan_data['program_end_date'];
                    $auto_renew = 0;
                } else {
                    $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $plan_data['duration_months'] . ' months'));
                }

                // For monthly plans, set billing_day (capped at 28) and monthly_charges_made
                $billing_day = null;
                $monthly_charges = 0;
                if (($plan_data['billing_frequency'] ?? 'upfront') === 'monthly') {
                    $billing_day = min((int) date('j', strtotime($start_date)), 28);
                    // If the admin marked it as paid, count the first installment
                    if ($_POST['payment_status'] === 'paid' && $_POST['amount_paid'] > 0) {
                        $monthly_charges = 1;
                    }
                }

                $stmt = $pdo->prepare("INSERT INTO memberships (student_id, plan_id, start_date, end_date, status, payment_status, amount_paid, auto_renew, billing_day, monthly_charges_made) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$_POST['student_id'], $plan_id, $start_date, $end_date, 'active', $_POST['payment_status'], $_POST['amount_paid'], $auto_renew, $billing_day, $monthly_charges]);

                if ($_POST['payment_status'] === 'paid' && $_POST['amount_paid'] > 0) {
                    $membership_id = $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO payments (student_id, payment_type, reference_id, amount, payment_method, payment_date, receipt_number, notes) VALUES (?, 'membership', ?, ?, ?, ?, ?, ?)")
                        ->execute([$_POST['student_id'], $membership_id, $_POST['amount_paid'], $_POST['payment_method'], date('Y-m-d'), generateReceiptNumber(), 'Membership payment']);
                }
                $message = showAlert('Membership added successfully!', 'success');
                break;

            case 'cancel_membership':
                $pdo->prepare("UPDATE memberships SET status = 'cancelled' WHERE id = ?")->execute([$_POST['membership_id']]);
                $message = showAlert('Membership cancelled!', 'success');
                break;

            case 'toggle_auto_renew':
                $new_value = $_POST['auto_renew_value'] == '1' ? 1 : 0;
                $pdo->prepare("UPDATE memberships SET auto_renew = ? WHERE id = ?")->execute([$new_value, $_POST['membership_id']]);
                $message = showAlert('Auto-renewal ' . ($new_value ? 'enabled' : 'disabled') . '!', 'success');
                break;
        }
    }
}

// Get all membership plans
$plans = $pdo->query("SELECT * FROM membership_plans ORDER BY price ASC")->fetchAll();

// Get memberships (filtered)
$status_filter = $_GET['status'] ?? 'active';
$query = "SELECT m.*, s.first_name, s.last_name, s.email, mp.name as plan_name, mp.price as plan_price, mp.billing_frequency FROM memberships m JOIN students s ON m.student_id = s.id JOIN membership_plans mp ON m.plan_id = mp.id WHERE 1=1";
if ($status_filter) { $query .= " AND m.status = :status"; }
$query .= " ORDER BY m.created_at DESC";
$stmt = $pdo->prepare($query);
if ($status_filter) { $stmt->bindValue(':status', $status_filter); }
$stmt->execute();
$memberships = $stmt->fetchAll();

// Get expiring soon (within 7 days)
$expiring_soon = $pdo->query("
    SELECT m.*, s.first_name, s.last_name, mp.name as plan_name
    FROM memberships m JOIN students s ON m.student_id = s.id JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.status = 'active' AND m.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY m.end_date ASC
")->fetchAll();

// Get active students for dropdown
$students = $pdo->query("SELECT id, first_name, last_name FROM students WHERE status = 'active' ORDER BY first_name, last_name")->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Memberships</h1>
        <div class="space-x-3 flex items-center">
            <?php if (getCurrentUser()['role'] === 'admin'): ?>
                <a href="cron.php" onclick="return confirm('Run membership renewal processing now?')"
                   class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg font-medium text-sm">
                    ⟳ Process Renewals
                </a>
            <?php endif; ?>
            <button onclick="document.getElementById('addPlanModal').classList.remove('hidden')" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-medium">+ Add Plan</button>
            <button onclick="document.getElementById('addMembershipModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">+ Add Membership</button>
        </div>
    </div>

    <?php if (!empty($expiring_soon)): ?>
    <div class="bg-orange-50 border-l-4 border-orange-500 p-4 mb-6 rounded-r-lg">
        <h3 class="text-orange-700 font-semibold mb-2">&#9888; Expiring Soon (<?php echo count($expiring_soon); ?>)</h3>
        <div class="space-y-2">
            <?php foreach ($expiring_soon as $exp): ?>
                <div class="flex items-center justify-between bg-white rounded p-3 border border-orange-200">
                    <span class="font-medium text-gray-800"><?php echo $exp['first_name'] . ' ' . $exp['last_name']; ?> <span class="text-sm text-gray-500">&mdash; <?php echo $exp['plan_name']; ?></span></span>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-orange-700 font-semibold">Expires: <?php echo formatDate($exp['end_date']); ?></span>
                        <span class="px-2 py-1 text-xs rounded-full <?php echo (isset($exp['auto_renew']) && $exp['auto_renew']) ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                            <?php echo (isset($exp['auto_renew']) && $exp['auto_renew']) ? 'Auto-renew ON' : 'Auto-renew OFF'; ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Membership Plans -->
    <div class="mb-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-4">Membership Plans</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($plans as $plan): ?>
                <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-6">
                        <h3 class="text-2xl font-bold mb-2"><?php echo $plan['name']; ?></h3>
                        <?php
                        $isMonthly = (isset($plan['billing_frequency']) && $plan['billing_frequency'] === 'monthly' && $plan['duration_months'] > 1);
                        $monthlyAmount = $isMonthly ? round($plan['price'] / $plan['duration_months'], 2) : 0;
                        ?>
                        <?php if ($isMonthly): ?>
                            <p class="text-3xl font-bold"><?php echo formatMoney($monthlyAmount); ?><span class="text-base font-normal">/mo</span></p>
                            <p class="text-sm opacity-90"><?php echo formatMoney($plan['price']); ?> total over <?php echo $plan['duration_months']; ?> months</p>
                        <?php else: ?>
                            <p class="text-3xl font-bold"><?php echo formatMoney($plan['price']); ?></p>
                            <p class="text-sm opacity-90">per <?php echo $plan['duration_months']; ?> month(s)</p>
                        <?php endif; ?>
                        <?php if (isset($plan['registration_fee']) && $plan['registration_fee'] > 0): ?>
                            <p class="text-sm opacity-90">+ <?php echo formatMoney($plan['registration_fee']); ?> registration fee</p>
                        <?php endif; ?>
                    </div>
                    <div class="p-6">
                        <p class="text-gray-700 mb-4"><?php echo $plan['description']; ?></p>
                        <ul class="space-y-2 text-gray-600">
                            <li>&#10003; <?php echo $plan['classes_per_week'] == 99 ? 'Unlimited' : $plan['classes_per_week']; ?> classes/week</li>
                            <li>&#10003; <?php echo $plan['duration_months']; ?> month duration</li>
                            <li>&#10003; <?php echo $isMonthly ? 'Billed monthly' : 'Billed upfront'; ?></li>
                            <?php if (!empty($plan['is_afterschool'])): ?>
                                <li>&#10003; Fixed-term (no auto-renewal)</li>
                                <li>&#10003; Mid-program proration available</li>
                            <?php else: ?>
                                <li>&#10003; Auto-renewable subscription</li>
                            <?php endif; ?>
                        </ul>
                        <div class="mt-4 flex items-center gap-2 flex-wrap">
                            <span class="px-3 py-1 text-sm rounded-full <?php echo $plan['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>"><?php echo ucfirst($plan['status']); ?></span>
                            <?php if (!empty($plan['is_afterschool'])): ?>
                                <span class="px-3 py-1 text-sm rounded-full bg-indigo-100 text-indigo-800 font-semibold">Afterschool</span>
                            <?php endif; ?>
                            <?php if (!empty($plan['tax_deductible'])): ?>
                                <span class="px-3 py-1 text-sm rounded-full bg-green-100 text-green-800 font-semibold">Tax-Deductible</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($plan['is_afterschool']) && $plan['program_start_date'] && $plan['program_end_date']): ?>
                            <p class="text-sm text-indigo-700 mt-2">&#128197; Program: <?php echo date('M j, Y', strtotime($plan['program_start_date'])); ?> &ndash; <?php echo date('M j, Y', strtotime($plan['program_end_date'])); ?></p>
                        <?php endif; ?>
                        <?php if (getCurrentUser()['role'] === 'admin'): ?>
                        <div class="mt-4 pt-4 border-t border-gray-200 flex space-x-2">
                            <button onclick="editPlan(<?php echo htmlspecialchars(json_encode($plan)); ?>)" class="flex-1 text-blue-600 hover:text-blue-800 text-sm font-medium">Edit</button>
                            <form method="POST" class="flex-1" onsubmit="return confirmDelete('Delete this plan?')">
                                <input type="hidden" name="action" value="delete_plan">
                                <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                <button type="submit" class="w-full text-red-600 hover:text-red-800 text-sm font-medium">Delete</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Memberships Table -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-xl font-semibold text-gray-800">Memberships</h2>
            <div class="flex space-x-2">
                <a href="?status=active" class="px-4 py-2 rounded-lg text-sm <?php echo $status_filter === 'active' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">Active</a>
                <a href="?status=expired" class="px-4 py-2 rounded-lg text-sm <?php echo $status_filter === 'expired' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">Expired</a>
                <a href="?status=cancelled" class="px-4 py-2 rounded-lg text-sm <?php echo $status_filter === 'cancelled' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">Cancelled</a>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50"><tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Period</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Auto-Renew</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr></thead>
                <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($memberships as $m): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="font-medium text-gray-900"><?php echo $m['first_name'] . ' ' . $m['last_name']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo $m['email']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900"><?php echo $m['plan_name']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo formatMoney($m['plan_price']); ?></div>
                            <?php if (isset($m['billing_day']) && $m['billing_day']): ?>
                                <div class="text-xs text-blue-600">Monthly &middot; Day <?php echo $m['billing_day']; ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php echo formatDate($m['start_date']); ?> &ndash; <?php echo formatDate($m['end_date']); ?>
                            <?php $days_left = (strtotime($m['end_date']) - time()) / 86400; if ($m['status'] === 'active' && $days_left > 0 && $days_left <= 30): ?>
                                <div class="text-xs text-orange-600 font-medium"><?php echo floor($days_left); ?> days left</div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php $sc = ['active'=>'bg-green-100 text-green-800','expired'=>'bg-red-100 text-red-800','cancelled'=>'bg-gray-100 text-gray-800']; ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $sc[$m['status']]; ?>"><?php echo ucfirst($m['status']); ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php $pc = ['paid'=>'bg-green-100 text-green-800','pending'=>'bg-yellow-100 text-yellow-800','partial'=>'bg-orange-100 text-orange-800']; ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $pc[$m['payment_status']]; ?>"><?php echo ucfirst($m['payment_status']); ?></span>
                            <div class="text-xs text-gray-600 mt-1"><?php echo formatMoney($m['amount_paid']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if ($m['status'] === 'active'): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle_auto_renew">
                                    <input type="hidden" name="membership_id" value="<?php echo $m['id']; ?>">
                                    <input type="hidden" name="auto_renew_value" value="<?php echo (isset($m['auto_renew']) && $m['auto_renew']) ? '0' : '1'; ?>">
                                    <button type="submit" class="px-2 py-1 text-xs font-semibold rounded-full <?php echo (isset($m['auto_renew']) && $m['auto_renew']) ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                        <?php echo (isset($m['auto_renew']) && $m['auto_renew']) ? '&#10003; ON' : '&#10007; OFF'; ?>
                                    </button>
                                </form>
                            <?php else: ?><span class="text-xs text-gray-400">N/A</span><?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <a href="student_detail.php?id=<?php echo $m['student_id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                            <?php if ($m['status'] === 'active'): ?>
                                <form method="POST" class="inline" onsubmit="return confirmDelete('Cancel this membership?')">
                                    <input type="hidden" name="action" value="cancel_membership">
                                    <input type="hidden" name="membership_id" value="<?php echo $m['id']; ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-900">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($memberships)): ?><div class="text-center py-12 text-gray-500"><p class="text-lg">No <?php echo $status_filter; ?> memberships found</p></div><?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Plan Modal -->
<div id="addPlanModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4"><h3 class="text-xl font-bold text-gray-800">Create Membership Plan</h3><button onclick="document.getElementById('addPlanModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button></div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_plan">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Plan Name *</label><input type="text" name="name" required placeholder="e.g., Premium Monthly" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Description</label><textarea name="description" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea></div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Duration (months) *</label><input type="number" name="duration_months" min="1" required value="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Price *</label><input type="number" name="price" step="0.01" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Classes/Week *</label><input type="number" name="classes_per_week" min="1" max="99" required placeholder="99=unlimited" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Registration Fee</label><input type="number" name="registration_fee" step="0.01" min="0" value="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg"><p class="text-xs text-gray-500 mt-1">One-time fee for new enrollees</p></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Status</label><select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Billing Frequency</label>
                    <select name="billing_frequency" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="upfront">Upfront (full price at once)</option>
                        <option value="monthly">Monthly Installments</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Monthly = total price &divide; duration charged each month</p>
                </div>
            </div>
            <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="tax_deductible" value="1" class="w-5 h-5 text-green-600 border-gray-300 rounded focus:ring-green-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Tax-Deductible (Afterschool/Camp)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Mark if this plan qualifies as dependent care for tax purposes</p>
                    </div>
                </label>
            </div>
            <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_afterschool" value="1" id="add_is_afterschool" onchange="toggleAfterschoolFields('add')" class="w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Afterschool Program (Fixed Term)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Set a fixed start &amp; end date — students enrolling mid-program pay a prorated amount</p>
                    </div>
                </label>
                <div id="add_afterschool_fields" class="hidden mt-3 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Program Start Date *</label>
                        <input type="date" name="program_start_date" id="add_program_start_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Program End Date *</label>
                        <input type="date" name="program_end_date" id="add_program_end_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div class="col-span-2">
                        <p class="text-xs text-indigo-600">&#128161; Duration will be auto-calculated from the program dates. Auto-renewal is disabled for afterschool plans.</p>
                    </div>
                </div>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addPlanModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">Create Plan</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Membership Modal -->
<div id="addMembershipModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4"><h3 class="text-xl font-bold text-gray-800">Add Membership</h3><button onclick="document.getElementById('addMembershipModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button></div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_membership">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Student *</label><select name="student_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"><option value="">Choose a student...</option><?php foreach ($students as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo $s['first_name'] . ' ' . $s['last_name']; ?></option><?php endforeach; ?></select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Plan *</label><select name="plan_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"><option value="">Choose a plan...</option><?php foreach ($plans as $p): ?><?php if ($p['status'] === 'active'): ?><option value="<?php echo $p['id']; ?>"><?php echo $p['name']; ?> - <?php echo formatMoney($p['price']); ?> (<?php echo $p['duration_months']; ?>mo)</option><?php endif; ?><?php endforeach; ?></select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Start Date *</label><input type="date" name="start_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Payment Status *</label><select name="payment_status" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"><option value="paid">Paid</option><option value="pending">Pending</option><option value="partial">Partial</option></select></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Amount Paid *</label><input type="number" name="amount_paid" step="0.01" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
            </div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label><select name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg"><option value="cash">Cash</option><option value="credit_card">Credit Card</option><option value="debit_card">Debit Card</option><option value="bank_transfer">Bank Transfer</option><option value="other">Other</option></select></div>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                <label class="flex items-center space-x-2 cursor-pointer"><input type="checkbox" name="auto_renew" value="1" checked class="rounded border-gray-300 text-blue-600"><span class="text-sm font-medium text-blue-800">Enable auto-renewal (subscription)</span></label>
                <p class="text-xs text-blue-600 mt-1">Membership auto-renews if payment gateway is configured; otherwise expires for manual renewal.</p>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addMembershipModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Add Membership</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Plan Modal -->
<div id="editPlanModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4"><h3 class="text-xl font-bold text-gray-800">Edit Plan</h3><button onclick="document.getElementById('editPlanModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button></div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="edit_plan"><input type="hidden" name="plan_id" id="edit_plan_id">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Plan Name *</label><input type="text" name="name" id="edit_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Description</label><textarea name="description" id="edit_description" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea></div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Duration (months)</label><input type="number" name="duration_months" id="edit_duration_months" min="1" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Price</label><input type="number" name="price" id="edit_price" step="0.01" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Classes/Week</label><input type="number" name="classes_per_week" id="edit_classes_per_week" min="1" max="99" required class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Registration Fee</label><input type="number" name="registration_fee" id="edit_registration_fee" step="0.01" min="0" value="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg"><p class="text-xs text-gray-500 mt-1">One-time fee for new enrollees</p></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Status</label><select name="status" id="edit_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Billing Frequency</label>
                    <select name="billing_frequency" id="edit_billing_frequency" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="upfront">Upfront (full price at once)</option>
                        <option value="monthly">Monthly Installments</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Monthly = total price &divide; duration charged each month</p>
                </div>
            </div>
            <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="tax_deductible" value="1" id="edit_tax_deductible" class="w-5 h-5 text-green-600 border-gray-300 rounded focus:ring-green-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Tax-Deductible (Afterschool/Camp)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Mark if this plan qualifies as dependent care for tax purposes</p>
                    </div>
                </label>
            </div>
            <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_afterschool" value="1" id="edit_is_afterschool" onchange="toggleAfterschoolFields('edit')" class="w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Afterschool Program (Fixed Term)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Set a fixed start &amp; end date — students enrolling mid-program pay a prorated amount</p>
                    </div>
                </label>
                <div id="edit_afterschool_fields" class="hidden mt-3 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Program Start Date *</label>
                        <input type="date" name="program_start_date" id="edit_program_start_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Program End Date *</label>
                        <input type="date" name="program_end_date" id="edit_program_end_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div class="col-span-2">
                        <p class="text-xs text-indigo-600">&#128161; Duration will be auto-calculated from the program dates. Auto-renewal is disabled for afterschool plans.</p>
                    </div>
                </div>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editPlanModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Update Plan</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAfterschoolFields(prefix) {
    var checked = document.getElementById(prefix + '_is_afterschool').checked;
    var fields = document.getElementById(prefix + '_afterschool_fields');
    var durationInput = document.querySelector('#' + prefix + (prefix === 'add' ? 'PlanModal' : 'PlanModal') + ' input[name="duration_months"]')
                     || document.getElementById(prefix + '_duration_months');

    if (checked) {
        fields.classList.remove('hidden');
        // For add modal, find the duration field by name in the parent form
        if (prefix === 'add') {
            var addForm = document.querySelector('#addPlanModal form');
            var durField = addForm ? addForm.querySelector('input[name="duration_months"]') : null;
            if (durField) {
                durField.closest('div').style.opacity = '0.4';
                durField.removeAttribute('required');
                durField.value = '';
                durField.placeholder = 'Auto';
            }
        } else {
            if (durationInput) {
                durationInput.closest('div').style.opacity = '0.4';
                durationInput.removeAttribute('required');
                durationInput.placeholder = 'Auto';
            }
        }
    } else {
        fields.classList.add('hidden');
        if (prefix === 'add') {
            var addForm = document.querySelector('#addPlanModal form');
            var durField = addForm ? addForm.querySelector('input[name="duration_months"]') : null;
            if (durField) {
                durField.closest('div').style.opacity = '1';
                durField.setAttribute('required', 'required');
                durField.placeholder = '';
            }
        } else {
            if (durationInput) {
                durationInput.closest('div').style.opacity = '1';
                durationInput.setAttribute('required', 'required');
                durationInput.placeholder = '';
            }
        }
    }
}

function editPlan(plan) {
    document.getElementById('edit_plan_id').value = plan.id;
    document.getElementById('edit_name').value = plan.name;
    document.getElementById('edit_description').value = plan.description || '';
    document.getElementById('edit_duration_months').value = plan.duration_months;
    document.getElementById('edit_price').value = plan.price;
    document.getElementById('edit_classes_per_week').value = plan.classes_per_week;
    document.getElementById('edit_status').value = plan.status;
    document.getElementById('edit_billing_frequency').value = plan.billing_frequency || 'upfront';
    document.getElementById('edit_registration_fee').value = plan.registration_fee || 0;
    document.getElementById('edit_tax_deductible').checked = (plan.tax_deductible == 1);

    // Afterschool fields
    var isAfterschool = (plan.is_afterschool == 1);
    document.getElementById('edit_is_afterschool').checked = isAfterschool;
    document.getElementById('edit_program_start_date').value = plan.program_start_date || '';
    document.getElementById('edit_program_end_date').value = plan.program_end_date || '';
    toggleAfterschoolFields('edit');

    document.getElementById('editPlanModal').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
