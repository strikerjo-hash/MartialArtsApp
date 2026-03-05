<?php
/**
 * admin_delinquency.php — Delinquent Students & Projected Income Report
 *
 * Two sections:
 *   1. Delinquent Students: Active students with memberships who have not made a
 *      payment in 30+ days. Admin can preview and bulk-mark them as Inactive.
 *   2. Projected Income: Revenue forecast based on members in good standing
 *      (active membership + payment_status = 'paid') with no past-due payments.
 */

require_once 'config.php';
requireLogin();
requireFinancialAccess();
require_once 'includes/report_helpers.php';

$pdo     = get_db();
$message = '';

// ── Handle bulk deactivation ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_deactivate'])) {
    verify_csrf();

    $ids = $_POST['student_ids'] ?? [];
    if (is_array($ids) && !empty($ids)) {
        $deactivated = 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_map('intval', $ids);
        school_param($params);

        try {
            // Capture which IDs are actually active before the update (for cascade)
            $selectParams = array_map('intval', $ids);
            school_param($selectParams);
            $selectStmt = $pdo->prepare("SELECT id FROM students WHERE id IN ({$ph}) AND status = 'active'" . school_where());
            $selectStmt->execute($selectParams);
            $affectedIds = $selectStmt->fetchAll(PDO::FETCH_COLUMN);

            $stmt = $pdo->prepare("
                UPDATE students
                SET status = 'inactive', inactive_since = CURDATE()
                WHERE id IN ({$ph}) AND status = 'active'" . school_where() . "
            ");
            $stmt->execute($params);
            $deactivated = $stmt->rowCount();

            // Run cascade for each deactivated student (payment reason — delinquency)
            foreach ($affectedIds as $affectedId) {
                deactivate_student_cascade((int)$affectedId, 'payment');
            }

            if ($deactivated > 0 && function_exists('audit_log')) {
                audit_log('bulk_deactivate', 'Bulk deactivated ' . $deactivated . ' students for non-payment (30+ days)');
            }

            $message = showAlert("{$deactivated} student(s) marked as Inactive. Class enrollments dropped and future event registrations cancelled.", 'success');
        } catch (PDOException $e) {
            $message = showAlert('Error deactivating students: ' . $e->getMessage(), 'error');
        }
    } else {
        $message = showAlert('No students selected.', 'error');
    }
}

// ── Fetch delinquent students ───────────────────────────────────────────────
// Active students with an active membership whose last completed payment
// is older than 30 days (or who have never made a completed payment).
$delinquentStudents = [];
try {
    $params = [];
    school_param($params);
    $delinqSql = "
        SELECT s.id, s.first_name, s.last_name, s.email, s.status, s.phone,
               m.id as membership_id, mp.name as plan_name, mp.price as plan_price,
               mp.billing_frequency, mp.duration_months,
               m.payment_status, m.start_date, m.end_date,
               (SELECT MAX(p.payment_date) FROM payments p WHERE p.student_id = s.id AND p.status = 'completed') as last_payment_date,
               DATEDIFF(CURDATE(), (SELECT MAX(p2.payment_date) FROM payments p2 WHERE p2.student_id = s.id AND p2.status = 'completed')) as days_since_payment
        FROM students s
        JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
        JOIN membership_plans mp ON mp.id = m.plan_id
        WHERE s.status = 'active'
          AND s.payment_lockout_override = 0
          AND (
              m.payment_status IN ('declined', 'pending', 'partial')
              OR s.id NOT IN (
                  SELECT p.student_id FROM payments p
                  WHERE p.status = 'completed' AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              )
          )" . school_where('s') . "
        ORDER BY days_since_payment DESC, s.last_name, s.first_name
    ";
    $stmt = $pdo->prepare($delinqSql);
    $stmt->execute($params);
    $delinquentStudents = $stmt->fetchAll();
} catch (PDOException $e) {
    $message .= showAlert('Error loading delinquent students: ' . $e->getMessage(), 'error');
}

// ── Fetch projected income data ─────────────────────────────────────────────
// Members in good standing: active membership + payment_status = 'paid'
// + at least one completed payment in last 30 days (or payment_status is paid)
$projectedData = [];
$projectedTotals = ['monthly' => 0, 'quarterly' => 0, 'annual' => 0, 'member_count' => 0];
try {
    $params = [];
    school_param($params);
    $projSql = "
        SELECT s.id, s.first_name, s.last_name, s.email,
               m.id as membership_id, mp.name as plan_name, mp.price as plan_price,
               mp.billing_frequency, mp.duration_months,
               m.payment_status, m.start_date, m.end_date, m.auto_renew,
               (SELECT MAX(p.payment_date) FROM payments p WHERE p.student_id = s.id AND p.status = 'completed') as last_payment_date
        FROM students s
        JOIN memberships m ON m.student_id = s.id AND m.status = 'active' AND m.end_date >= CURDATE()
        JOIN membership_plans mp ON mp.id = m.plan_id
        WHERE s.status = 'active'
          AND m.payment_status = 'paid'" . school_where('s') . "
        ORDER BY mp.name, s.last_name, s.first_name
    ";
    $stmt = $pdo->prepare($projSql);
    $stmt->execute($params);
    $projectedData = $stmt->fetchAll();

    // Calculate projections
    foreach ($projectedData as $row) {
        $monthlyRate = 0;
        if ($row['billing_frequency'] === 'monthly' && $row['duration_months'] > 0) {
            $monthlyRate = round($row['plan_price'] / $row['duration_months'], 2);
        } elseif ($row['duration_months'] > 0) {
            // Upfront plans: amortize to monthly
            $monthlyRate = round($row['plan_price'] / $row['duration_months'], 2);
        } else {
            $monthlyRate = $row['plan_price'];
        }

        $projectedTotals['monthly'] += $monthlyRate;
        $projectedTotals['member_count']++;
    }

    $projectedTotals['quarterly'] = round($projectedTotals['monthly'] * 3, 2);
    $projectedTotals['annual']    = round($projectedTotals['monthly'] * 12, 2);
    $projectedTotals['monthly']   = round($projectedTotals['monthly'], 2);

} catch (PDOException $e) {
    $message .= showAlert('Error loading projected income: ' . $e->getMessage(), 'error');
}

// Group projected data by plan for summary table
$planSummary = [];
foreach ($projectedData as $row) {
    $planKey = $row['plan_name'];
    if (!isset($planSummary[$planKey])) {
        $planSummary[$planKey] = [
            'plan_name'  => $row['plan_name'],
            'plan_price' => $row['plan_price'],
            'billing'    => $row['billing_frequency'],
            'duration'   => $row['duration_months'],
            'count'      => 0,
            'monthly'    => 0,
        ];
    }
    $planSummary[$planKey]['count']++;

    if ($row['duration_months'] > 0) {
        $planSummary[$planKey]['monthly'] += round($row['plan_price'] / $row['duration_months'], 2);
    } else {
        $planSummary[$planKey]['monthly'] += $row['plan_price'];
    }
}

// Recently deactivated students (last 30 days) for reference
$recentlyDeactivated = [];
try {
    $params = [];
    school_param($params);
    $rdSql = "
        SELECT s.id, s.first_name, s.last_name, s.email, s.inactive_since
        FROM students s
        WHERE s.status = 'inactive' AND s.inactive_since >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . school_where('s') . "
        ORDER BY s.inactive_since DESC
    ";
    $stmt = $pdo->prepare($rdSql);
    $stmt->execute($params);
    $recentlyDeactivated = $stmt->fetchAll();
} catch (PDOException $e) {}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?= $message ?>

    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Payment Status & Projected Income</h1>
        <p class="text-gray-600">Manage delinquent accounts and view revenue projections for members in good standing.</p>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- Summary Cards                                                      -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-red-500">
            <p class="text-sm text-gray-500 mb-1">Past Due (30+ Days)</p>
            <p class="text-3xl font-bold text-red-600"><?= count($delinquentStudents) ?></p>
            <p class="text-xs text-gray-400 mt-1">students</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
            <p class="text-sm text-gray-500 mb-1">Members In Good Standing</p>
            <p class="text-3xl font-bold text-green-600"><?= $projectedTotals['member_count'] ?></p>
            <p class="text-xs text-gray-400 mt-1">active & paid</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
            <p class="text-sm text-gray-500 mb-1">Projected Monthly Income</p>
            <p class="text-3xl font-bold text-blue-600"><?= formatMoney($projectedTotals['monthly']) ?></p>
            <p class="text-xs text-gray-400 mt-1">from current members</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-purple-500">
            <p class="text-sm text-gray-500 mb-1">Projected Annual Income</p>
            <p class="text-3xl font-bold text-purple-600"><?= formatMoney($projectedTotals['annual']) ?></p>
            <p class="text-xs text-gray-400 mt-1">at current rate</p>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- Section 1: Delinquent Students (No Payment in 30+ Days)            -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="px-6 py-4 border-b border-gray-200 bg-red-50 flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-red-800">Past Due Students</h2>
                <p class="text-sm text-red-600">Active students with memberships who have not made a payment in 30+ days</p>
            </div>
            <?php if (!empty($delinquentStudents)): ?>
                <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-sm font-bold"><?= count($delinquentStudents) ?> student(s)</span>
            <?php endif; ?>
        </div>

        <?php if (empty($delinquentStudents)): ?>
            <div class="p-8 text-center text-gray-400">
                <div class="text-4xl mb-3">&#10003;</div>
                <p class="text-lg font-medium text-green-600">All students are in good standing!</p>
                <p class="text-sm text-gray-500 mt-1">No active students with outstanding payments found.</p>
            </div>
        <?php else: ?>
            <form method="POST" id="delinquency-form">
                <?= csrf_field() ?>
                <input type="hidden" name="bulk_deactivate" value="1">

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left">
                                    <input type="checkbox" id="select-all" onchange="toggleAll(this)" class="rounded">
                                </th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Student</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Plan</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Payment Status</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Last Payment</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Days Overdue</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Contact</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($delinquentStudents as $ds): ?>
                                <tr class="hover:bg-red-50/50">
                                    <td class="px-4 py-3">
                                        <input type="checkbox" name="student_ids[]" value="<?= $ds['id'] ?>" class="student-cb rounded">
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="student_detail.php?id=<?= $ds['id'] ?>" class="font-medium text-blue-600 hover:underline">
                                            <?= htmlspecialchars($ds['first_name'] . ' ' . $ds['last_name']) ?>
                                        </a>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-medium"><?= htmlspecialchars($ds['plan_name']) ?></span>
                                        <br>
                                        <span class="text-xs text-gray-400"><?= formatMoney($ds['plan_price']) ?>
                                            <?php if ($ds['billing_frequency'] === 'monthly'): ?>
                                                (<?= formatMoney(round($ds['plan_price'] / max(1, $ds['duration_months']), 2)) ?>/mo)
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php
                                        $psColor = match($ds['payment_status']) {
                                            'declined' => 'bg-red-100 text-red-700',
                                            'pending' => 'bg-yellow-100 text-yellow-700',
                                            'partial' => 'bg-orange-100 text-orange-700',
                                            default => 'bg-gray-100 text-gray-700',
                                        };
                                        ?>
                                        <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded-full <?= $psColor ?>">
                                            <?= ucfirst($ds['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($ds['last_payment_date']): ?>
                                            <?= formatDate($ds['last_payment_date']) ?>
                                        <?php else: ?>
                                            <span class="text-gray-400 italic">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($ds['days_since_payment'] !== null): ?>
                                            <span class="font-bold <?= $ds['days_since_payment'] > 60 ? 'text-red-600' : 'text-orange-600' ?>">
                                                <?= $ds['days_since_payment'] ?> days
                                            </span>
                                        <?php else: ?>
                                            <span class="font-bold text-red-600">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500">
                                        <?php if ($ds['email']): ?>
                                            <?= htmlspecialchars($ds['email']) ?><br>
                                        <?php endif; ?>
                                        <?php if ($ds['phone']): ?>
                                            <?= htmlspecialchars($ds['phone']) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                    <div class="text-sm text-gray-600">
                        <span id="selected-count">0</span> of <?= count($delinquentStudents) ?> selected
                    </div>
                    <div class="flex gap-3">
                        <button type="button" onclick="selectAll()" class="text-sm px-4 py-2 rounded-lg border border-gray-300 hover:bg-gray-100 text-gray-700 transition">
                            Select All
                        </button>
                        <button type="submit" onclick="return confirm('Mark selected students as Inactive? This will prevent them from accessing the student portal until reactivated.')"
                                class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg text-sm font-medium transition">
                            Mark Selected as Inactive
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- Section 2: Projected Income (Good Standing Members)                -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="px-6 py-4 border-b border-gray-200 bg-green-50">
            <h2 class="text-xl font-semibold text-green-800">Projected Income Report</h2>
            <p class="text-sm text-green-600">Revenue forecast from <?= $projectedTotals['member_count'] ?> members in good standing (active membership, no past-due payments)</p>
        </div>

        <?php if (empty($planSummary)): ?>
            <div class="p-8 text-center text-gray-400">
                <p class="text-lg font-medium">No members in good standing found.</p>
            </div>
        <?php else: ?>
            <!-- Projection Summary by Plan -->
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Revenue by Plan</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Plan</th>
                                <th class="px-4 py-3 text-center font-medium text-gray-600">Members</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Billing</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Plan Price</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-600">Monthly Total</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-600">Quarterly Total</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-600">Annual Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($planSummary as $ps): ?>
                                <?php
                                $billingLabel = $ps['billing'] === 'monthly' ? 'Monthly' : 'Upfront';
                                $perMonth = round($ps['monthly'], 2);
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($ps['plan_name']) ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-700 font-bold text-sm"><?= $ps['count'] ?></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= $billingLabel ?></span>
                                        <span class="text-xs text-gray-400 ml-1">(<?= $ps['duration'] ?>mo)</span>
                                    </td>
                                    <td class="px-4 py-3"><?= formatMoney($ps['plan_price']) ?></td>
                                    <td class="px-4 py-3 text-right font-medium"><?= formatMoney($perMonth) ?></td>
                                    <td class="px-4 py-3 text-right"><?= formatMoney($perMonth * 3) ?></td>
                                    <td class="px-4 py-3 text-right"><?= formatMoney($perMonth * 12) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-green-50 font-bold">
                            <tr>
                                <td class="px-4 py-3 text-green-800">Totals</td>
                                <td class="px-4 py-3 text-center text-green-800"><?= $projectedTotals['member_count'] ?></td>
                                <td class="px-4 py-3" colspan="2"></td>
                                <td class="px-4 py-3 text-right text-green-800"><?= formatMoney($projectedTotals['monthly']) ?></td>
                                <td class="px-4 py-3 text-right text-green-800"><?= formatMoney($projectedTotals['quarterly']) ?></td>
                                <td class="px-4 py-3 text-right text-green-800"><?= formatMoney($projectedTotals['annual']) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Individual Member Detail -->
            <div class="border-t border-gray-200">
                <div class="px-6 py-4 flex items-center justify-between cursor-pointer hover:bg-gray-50" onclick="document.getElementById('member-detail').classList.toggle('hidden')">
                    <h3 class="text-lg font-semibold text-gray-800">Individual Member Detail</h3>
                    <span class="text-gray-400 text-sm">Click to expand/collapse</span>
                </div>
                <div id="member-detail" class="hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium text-gray-600">Student</th>
                                    <th class="px-4 py-3 text-left font-medium text-gray-600">Plan</th>
                                    <th class="px-4 py-3 text-left font-medium text-gray-600">Billing</th>
                                    <th class="px-4 py-3 text-left font-medium text-gray-600">Valid Until</th>
                                    <th class="px-4 py-3 text-left font-medium text-gray-600">Auto-Renew</th>
                                    <th class="px-4 py-3 text-left font-medium text-gray-600">Last Payment</th>
                                    <th class="px-4 py-3 text-right font-medium text-gray-600">Monthly Rate</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($projectedData as $pd): ?>
                                    <?php
                                    $pdMonthly = ($pd['duration_months'] > 0)
                                        ? round($pd['plan_price'] / $pd['duration_months'], 2)
                                        : $pd['plan_price'];
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <a href="student_detail.php?id=<?= $pd['id'] ?>" class="text-blue-600 hover:underline">
                                                <?= htmlspecialchars($pd['first_name'] . ' ' . $pd['last_name']) ?>
                                            </a>
                                        </td>
                                        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($pd['plan_name']) ?></td>
                                        <td class="px-4 py-3">
                                            <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">
                                                <?= $pd['billing_frequency'] === 'monthly' ? 'Monthly' : 'Upfront' ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3"><?= formatDate($pd['end_date']) ?></td>
                                        <td class="px-4 py-3">
                                            <?php if ($pd['auto_renew']): ?>
                                                <span class="text-green-600 text-xs font-medium">&#10003; Yes</span>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-xs">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-xs">
                                            <?php if ($pd['last_payment_date']): ?>
                                                <?= formatDate($pd['last_payment_date']) ?>
                                            <?php else: ?>
                                                <span class="text-gray-400">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium text-green-700"><?= formatMoney($pdMonthly) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- Section 3: Recently Deactivated (last 30 days)                     -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <?php if (!empty($recentlyDeactivated)): ?>
        <div class="bg-white rounded-lg shadow mb-8">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex items-center justify-between cursor-pointer hover:bg-gray-100"
                 onclick="document.getElementById('recent-deactivated').classList.toggle('hidden')">
                <div>
                    <h2 class="text-lg font-semibold text-gray-700">Recently Deactivated</h2>
                    <p class="text-sm text-gray-500">Students marked inactive in the last 30 days</p>
                </div>
                <span class="bg-gray-200 text-gray-600 px-3 py-1 rounded-full text-sm font-bold"><?= count($recentlyDeactivated) ?></span>
            </div>
            <div id="recent-deactivated" class="hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Student</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Email</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Deactivated On</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($recentlyDeactivated as $rd): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <a href="student_detail.php?id=<?= $rd['id'] ?>" class="text-blue-600 hover:underline">
                                            <?= htmlspecialchars($rd['first_name'] . ' ' . $rd['last_name']) ?>
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($rd['email'] ?? '—') ?></td>
                                    <td class="px-4 py-3"><?= formatDate($rd['inactive_since']) ?></td>
                                    <td class="px-4 py-3">
                                        <a href="student_detail.php?id=<?= $rd['id'] ?>" class="text-sm text-blue-600 hover:underline">View Profile</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleAll(source) {
    var checkboxes = document.querySelectorAll('.student-cb');
    checkboxes.forEach(function(cb) { cb.checked = source.checked; });
    updateSelectedCount();
}

function selectAll() {
    var checkboxes = document.querySelectorAll('.student-cb');
    checkboxes.forEach(function(cb) { cb.checked = true; });
    document.getElementById('select-all').checked = true;
    updateSelectedCount();
}

function updateSelectedCount() {
    var checked = document.querySelectorAll('.student-cb:checked').length;
    var el = document.getElementById('selected-count');
    if (el) el.textContent = checked;
}

// Listen for individual checkbox changes
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.student-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            updateSelectedCount();
            var all = document.querySelectorAll('.student-cb');
            var checked = document.querySelectorAll('.student-cb:checked');
            document.getElementById('select-all').checked = all.length === checked.length;
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>
