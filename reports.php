<?php
require_once 'config.php';
requireLogin();

// Get summary stats
$stats = [];

// Student stats
$stats['total_students'] = $pdo->query("SELECT COUNT(*) as c FROM students")->fetch()['c'];
$stats['active_students'] = $pdo->query("SELECT COUNT(*) as c FROM students WHERE status = 'active'")->fetch()['c'];

// Membership stats
$stats['active_memberships'] = $pdo->query("SELECT COUNT(*) as c FROM memberships WHERE status = 'active' AND end_date >= CURDATE()")->fetch()['c'];
$stats['expiring_soon'] = $pdo->query("SELECT COUNT(*) as c FROM memberships WHERE status = 'active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetch()['c'];

// Financial stats
$stats['monthly_revenue'] = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())")->fetch()['total'];
$stats['yearly_revenue'] = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE())")->fetch()['total'];

// Event stats
$stats['total_events'] = $pdo->query("SELECT COUNT(*) as c FROM events")->fetch()['c'];
$stats['upcoming_events'] = $pdo->query("SELECT COUNT(*) as c FROM events WHERE status = 'upcoming' AND event_date >= CURDATE()")->fetch()['c'];

// Class stats
$stats['total_classes'] = $pdo->query("SELECT COUNT(*) as c FROM classes WHERE status = 'active'")->fetch()['c'];

// Revenue by month
$monthly_revenue = $pdo->query("
    SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total
    FROM payments
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month ASC
")->fetchAll();

// Top students by attendance
$top_attendance = $pdo->query("
    SELECT s.first_name, s.last_name, COUNT(a.id) as attendance_count
    FROM students s
    JOIN attendance a ON s.id = a.student_id
    WHERE a.status = 'present' AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY s.id
    ORDER BY attendance_count DESC
    LIMIT 10
")->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Reports & Analytics</h1>
    
    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Total Students</h3>
            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['total_students']; ?></p>
            <p class="text-sm text-green-600 mt-1"><?php echo $stats['active_students']; ?> active</p>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Active Memberships</h3>
            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['active_memberships']; ?></p>
            <p class="text-sm text-orange-600 mt-1"><?php echo $stats['expiring_soon']; ?> expiring soon</p>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Monthly Revenue</h3>
            <p class="text-3xl font-bold text-green-600"><?php echo formatMoney($stats['monthly_revenue']); ?></p>
            <p class="text-sm text-gray-600 mt-1">This month</p>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Yearly Revenue</h3>
            <p class="text-3xl font-bold text-blue-600"><?php echo formatMoney($stats['yearly_revenue']); ?></p>
            <p class="text-sm text-gray-600 mt-1"><?php echo date('Y'); ?> total</p>
        </div>
    </div>
    
    <!-- Revenue Chart -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Revenue Trend (Last 12 Months)</h2>
        <div class="overflow-x-auto">
            <div class="flex items-end space-x-2 h-64">
                <?php foreach ($monthly_revenue as $data): ?>
                    <?php 
                    $height = $stats['yearly_revenue'] > 0 ? ($data['total'] / $stats['yearly_revenue']) * 100 * 2 : 0;
                    ?>
                    <div class="flex-1 bg-blue-500 hover:bg-blue-600 rounded-t transition-all" 
                         style="height: <?php echo max($height, 5); ?>%;"
                         title="<?php echo date('M Y', strtotime($data['month'] . '-01')); ?>: <?php echo formatMoney($data['total']); ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="flex space-x-2 mt-2">
                <?php foreach ($monthly_revenue as $data): ?>
                    <div class="flex-1 text-xs text-center text-gray-600">
                        <?php echo date('M', strtotime($data['month'] . '-01')); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Top Attendance -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Top Students by Attendance (Last 30 Days)</h2>
        <div class="space-y-3">
            <?php foreach ($top_attendance as $index => $student): ?>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold">
                            <?php echo $index + 1; ?>
                        </div>
                        <span class="font-medium text-gray-900">
                            <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                        </span>
                    </div>
                    <span class="text-blue-600 font-semibold">
                        <?php echo $student['attendance_count']; ?> classes
                    </span>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($top_attendance)): ?>
                <p class="text-center text-gray-500 py-8">No attendance data for the last 30 days</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
