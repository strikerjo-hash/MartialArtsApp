<?php
require_once 'config.php';
requireLogin();

$student_id = $_GET['id'] ?? 0;

// Get student details
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: students.php');
    exit;
}

// Get current belt
$current_belt = $pdo->prepare("
    SELECT sb.*, b.name as belt_name, b.color, mas.name as style_name
    FROM student_belts sb
    JOIN belts b ON sb.belt_id = b.id
    JOIN martial_arts_styles mas ON sb.style_id = mas.id
    WHERE sb.student_id = ?
    ORDER BY sb.awarded_date DESC
    LIMIT 1
");
$current_belt->execute([$student_id]);
$current_belt = $current_belt->fetch();

// Get belt history
$belt_history = $pdo->prepare("
    SELECT sb.*, b.name as belt_name, b.color, mas.name as style_name, u.full_name as instructor
    FROM student_belts sb
    JOIN belts b ON sb.belt_id = b.id
    JOIN martial_arts_styles mas ON sb.style_id = mas.id
    LEFT JOIN users u ON sb.instructor_id = u.id
    WHERE sb.student_id = ?
    ORDER BY sb.awarded_date DESC
");
$belt_history->execute([$student_id]);
$belt_history = $belt_history->fetchAll();

// Get memberships
$memberships = $pdo->prepare("
    SELECT m.*, mp.name as plan_name, mp.price
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.student_id = ?
    ORDER BY m.created_at DESC
");
$memberships->execute([$student_id]);
$memberships = $memberships->fetchAll();

// Get enrolled classes
$enrolled_classes = $pdo->prepare("
    SELECT ce.*, c.name as class_name, c.day_of_week, c.start_time, c.end_time,
           mas.name as style_name
    FROM class_enrollments ce
    JOIN classes c ON ce.class_id = c.id
    JOIN martial_arts_styles mas ON c.style_id = mas.id
    WHERE ce.student_id = ? AND ce.status = 'active'
    ORDER BY FIELD(c.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
");
$enrolled_classes->execute([$student_id]);
$enrolled_classes = $enrolled_classes->fetchAll();

// Get event registrations
$event_registrations = $pdo->prepare("
    SELECT er.*, e.name as event_name, e.event_type, e.event_date
    FROM event_registrations er
    JOIN events e ON er.event_id = e.id
    WHERE er.student_id = ?
    ORDER BY e.event_date DESC
");
$event_registrations->execute([$student_id]);
$event_registrations = $event_registrations->fetchAll();

// Get payment history
$payments = $pdo->prepare("
    SELECT * FROM payments WHERE student_id = ? ORDER BY payment_date DESC LIMIT 10
");
$payments->execute([$student_id]);
$payments = $payments->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="mb-6">
        <a href="students.php" class="text-blue-600 hover:text-blue-800">← Back to Students</a>
    </div>
    
    <!-- Student Header -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-6">
                <div class="w-24 h-24 bg-blue-600 rounded-full flex items-center justify-center text-white text-3xl font-bold">
                    <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">
                        <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                    </h1>
                    <p class="text-gray-600 mt-1">
                        <?php if ($current_belt): ?>
                            Current Belt: <span class="font-semibold"><?php echo $current_belt['belt_name']; ?></span> 
                            (<?php echo $current_belt['style_name']; ?>)
                        <?php else: ?>
                            No belt awarded yet
                        <?php endif; ?>
                    </p>
                    <div class="mt-2">
                        <span class="px-3 py-1 text-sm font-semibold rounded-full <?php 
                            $colors = ['active' => 'bg-green-100 text-green-800', 'inactive' => 'bg-gray-100 text-gray-800', 'suspended' => 'bg-red-100 text-red-800'];
                            echo $colors[$student['status']]; 
                        ?>">
                            <?php echo ucfirst($student['status']); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Student Info -->
        <div class="mt-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div>
                <p class="text-sm text-gray-600">Email</p>
                <p class="font-medium text-gray-800"><?php echo $student['email'] ?: 'N/A'; ?></p>
            </div>
            <div>
                <p class="text-sm text-gray-600">Phone</p>
                <p class="font-medium text-gray-800"><?php echo $student['phone'] ?: 'N/A'; ?></p>
            </div>
            <div>
                <p class="text-sm text-gray-600">Join Date</p>
                <p class="font-medium text-gray-800"><?php echo formatDate($student['join_date']); ?></p>
            </div>
            <div>
                <p class="text-sm text-gray-600">Date of Birth</p>
                <p class="font-medium text-gray-800"><?php echo $student['date_of_birth'] ? formatDate($student['date_of_birth']) : 'N/A'; ?></p>
            </div>
            <div>
                <p class="text-sm text-gray-600">Emergency Contact</p>
                <p class="font-medium text-gray-800"><?php echo $student['emergency_contact_name'] ?: 'N/A'; ?></p>
                <p class="text-sm text-gray-600"><?php echo $student['emergency_contact_phone'] ?: ''; ?></p>
            </div>
        </div>
    </div>
    
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Belt History -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="text-xl font-semibold">Belt History</h2>
            </div>
            <div class="p-6">
                <?php if (empty($belt_history)): ?>
                    <p class="text-gray-500 text-center py-8">No belt promotions yet</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($belt_history as $belt): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div>
                                    <p class="font-semibold"><?php echo $belt['belt_name']; ?></p>
                                    <p class="text-sm text-gray-600"><?php echo $belt['style_name']; ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm text-gray-600"><?php echo formatDate($belt['awarded_date']); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Enrolled Classes -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="text-xl font-semibold">Enrolled Classes</h2>
            </div>
            <div class="p-6">
                <?php if (empty($enrolled_classes)): ?>
                    <p class="text-gray-500 text-center py-8">Not enrolled in any classes</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($enrolled_classes as $class): ?>
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <p class="font-semibold"><?php echo $class['class_name']; ?></p>
                                <p class="text-sm text-gray-600"><?php echo $class['style_name']; ?></p>
                                <p class="text-sm text-gray-600">
                                    <?php echo $class['day_of_week']; ?> • 
                                    <?php echo date('g:i A', strtotime($class['start_time'])); ?> - 
                                    <?php echo date('g:i A', strtotime($class['end_time'])); ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Memberships -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="text-xl font-semibold">Membership History</h2>
            </div>
            <div class="p-6">
                <?php if (empty($memberships)): ?>
                    <p class="text-gray-500 text-center py-8">No memberships</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($memberships as $membership): ?>
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="font-semibold"><?php echo $membership['plan_name']; ?></p>
                                        <p class="text-sm text-gray-600">
                                            <?php echo formatDate($membership['start_date']); ?> - 
                                            <?php echo formatDate($membership['end_date']); ?>
                                        </p>
                                    </div>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php 
                                        $colors = ['active' => 'bg-green-100 text-green-800', 'expired' => 'bg-red-100 text-red-800', 'cancelled' => 'bg-gray-100 text-gray-800'];
                                        echo $colors[$membership['status']]; 
                                    ?>">
                                        <?php echo ucfirst($membership['status']); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Event Registrations -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="text-xl font-semibold">Event Registrations</h2>
            </div>
            <div class="p-6">
                <?php if (empty($event_registrations)): ?>
                    <p class="text-gray-500 text-center py-8">No event registrations</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($event_registrations as $reg): ?>
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <p class="font-semibold"><?php echo $reg['event_name']; ?></p>
                                <p class="text-sm text-gray-600 capitalize"><?php echo str_replace('_', ' ', $reg['event_type']); ?></p>
                                <p class="text-sm text-gray-600"><?php echo formatDate($reg['event_date']); ?></p>
                                <?php if ($reg['result']): ?>
                                    <p class="text-sm font-semibold text-green-600 mt-1">Result: <?php echo $reg['result']; ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Payment History -->
    <div class="bg-white rounded-lg shadow mt-6">
        <div class="px-6 py-4 border-b">
            <h2 class="text-xl font-semibold">Recent Payments</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo formatDate($payment['payment_date']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm capitalize"><?php echo $payment['payment_type']; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm capitalize"><?php echo str_replace('_', ' ', $payment['payment_method']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600"><?php echo formatMoney($payment['amount']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-mono"><?php echo $payment['receipt_number']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($payments)): ?>
                <div class="text-center py-12 text-gray-500">
                    <p>No payment history</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
