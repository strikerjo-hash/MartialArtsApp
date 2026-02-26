<?php
require_once 'config.php';

// Check if student is logged in
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}
require_student_payment_clear();

$student_id = $_SESSION['student_id'];
$message = '';

// Get student details
$params = [$student_id];
$student = $pdo->prepare("SELECT * FROM students WHERE id = ?" . school_where());
school_param($params);
$student->execute($params);
$student = $student->fetch();

// Get upcoming events (not yet happened)
$filter = $_GET['filter'] ?? 'all';

$query = "
    SELECT e.*,
           COUNT(er.id) as registration_count,
           (SELECT er2.id FROM event_registrations er2 WHERE er2.event_id = e.id AND er2.student_id = ? LIMIT 1) as student_registered,
           (SELECT er3.payment_status FROM event_registrations er3 WHERE er3.event_id = e.id AND er3.student_id = ? LIMIT 1) as student_payment_status,
           e.requires_registration
    FROM events e
    LEFT JOIN event_registrations er ON e.id = er.event_id
    WHERE e.event_date >= CURDATE() AND e.status = 'upcoming'
";

$params = [$student_id, $student_id];

if ($filter !== 'all') {
    $query .= " AND e.event_type = ?";
    $params[] = $filter;
}

$query .= school_where('e');

$query .= " GROUP BY e.id ORDER BY e.event_date ASC";

school_param($params);
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$events = $stmt->fetchAll();

// Get student's registrations
$params = [$student_id];
$my_registrations = $pdo->prepare("
    SELECT er.*, e.name as event_name, e.event_date, e.event_type, e.registration_fee as fee
    FROM event_registrations er
    JOIN events e ON er.event_id = e.id
    WHERE er.student_id = ?" . school_where('er') . "
    ORDER BY e.event_date ASC
");
school_param($params);
$my_registrations->execute($params);
$my_registrations = $my_registrations->fetchAll();

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Browse Events</h1>
        <p class="text-gray-600">Register for upcoming tournaments, belt tests, and seminars</p>
    </div>
    
    <!-- Filter Tabs -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <div class="flex flex-wrap gap-2">
            <a href="?filter=all" 
               class="px-4 py-2 rounded-lg text-sm font-medium <?php echo $filter === 'all' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                All Events
            </a>
            <a href="?filter=belt_test" 
               class="px-4 py-2 rounded-lg text-sm font-medium <?php echo $filter === 'belt_test' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                Belt Tests
            </a>
            <a href="?filter=tournament" 
               class="px-4 py-2 rounded-lg text-sm font-medium <?php echo $filter === 'tournament' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                Tournaments
            </a>
            <a href="?filter=seminar" 
               class="px-4 py-2 rounded-lg text-sm font-medium <?php echo $filter === 'seminar' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                Seminars
            </a>
            <a href="?filter=workshop" 
               class="px-4 py-2 rounded-lg text-sm font-medium <?php echo $filter === 'workshop' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                Workshops
            </a>
        </div>
    </div>
    
    <!-- Upcoming Events -->
    <?php if (empty($events)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <div class="text-6xl mb-4">📅</div>
            <h2 class="text-2xl font-bold text-gray-800 mb-2">No Upcoming Events</h2>
            <p class="text-gray-600">Check back later for new events</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            <?php foreach ($events as $event): ?>
                <div class="bg-white rounded-lg shadow-lg overflow-hidden hover:shadow-xl transition-shadow">
                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-4">
                        <div class="flex justify-between items-start mb-2">
                            <h3 class="text-lg font-bold"><?php echo $event['name']; ?></h3>
                            <div class="flex flex-col items-end gap-1">
                                <?php if (empty($event['requires_registration'])): ?>
                                    <span class="px-2 py-1 bg-gray-200 text-gray-700 text-xs font-bold rounded-full">
                                        INFO ONLY
                                    </span>
                                <?php elseif ($event['student_registered']): ?>
                                    <span class="px-2 py-1 bg-green-500 text-white text-xs font-bold rounded-full">
                                        REGISTERED
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="text-sm opacity-90 capitalize"><?php echo str_replace('_', ' ', $event['event_type']); ?></p>
                    </div>
                    
                    <div class="p-4">
                        <div class="space-y-3 mb-4">
                            <div class="flex items-center text-sm text-gray-600">
                                <span class="mr-2">📅</span>
                                <span><?php echo formatDate($event['event_date']); ?></span>
                            </div>
                            
                            <?php if ($event['location']): ?>
                                <div class="flex items-center text-sm text-gray-600">
                                    <span class="mr-2">📍</span>
                                    <span><?php echo $event['location']; ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="flex items-center text-sm text-gray-600">
                                <span class="mr-2">👥</span>
                                <span><?php echo $event['registration_count']; ?> registered</span>
                                <?php if ($event['max_participants'] > 0): ?>
                                    <span class="text-gray-500"> / <?php echo $event['max_participants']; ?> max</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex items-center text-sm">
                                <span class="mr-2">💰</span>
                                <span class="text-lg font-bold text-green-600">
                                    <?php echo formatMoney($event['registration_fee']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($event['description']): ?>
                            <p class="text-sm text-gray-600 mb-4 line-clamp-2"><?php echo $event['description']; ?></p>
                        <?php endif; ?>
                        
                        <div class="pt-4 border-t border-gray-200">
                            <?php if (empty($event['requires_registration'])): ?>
                                <div class="text-center">
                                    <span class="inline-flex items-center gap-1.5 text-gray-500 text-sm font-medium">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                        Calendar Only
                                    </span>
                                </div>
                            <?php elseif ($event['student_registered']): ?>
                                <?php if ($event['student_payment_status'] === 'paid'): ?>
                                    <div class="text-center">
                                        <span class="text-green-600 font-semibold">✓ Registered & Paid</span>
                                    </div>
                                <?php else: ?>
                                    <a href="student_event_register.php?event_id=<?php echo $event['id']; ?>"
                                       class="block w-full text-center bg-orange-600 hover:bg-orange-700 text-white font-bold py-2 px-4 rounded-lg transition">
                                        Complete Payment
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php
                                $is_full = $event['max_participants'] > 0 && $event['registration_count'] >= $event['max_participants'];
                                if ($is_full):
                                ?>
                                    <button disabled class="w-full bg-gray-400 text-white font-bold py-2 px-4 rounded-lg cursor-not-allowed">
                                        Event Full
                                    </button>
                                <?php else: ?>
                                    <a href="student_event_register.php?event_id=<?php echo $event['id']; ?>"
                                       class="block w-full text-center bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition">
                                        Register Now
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- My Registrations -->
    <?php if (!empty($my_registrations)): ?>
        <div class="bg-white rounded-lg shadow mt-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">My Event Registrations</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fee</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($my_registrations as $reg): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                                    <?php echo $reg['event_name']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php echo formatDate($reg['event_date']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 capitalize">
                                    <?php echo str_replace('_', ' ', $reg['event_type']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                    <?php echo formatMoney($reg['fee']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $paymentColors = [
                                        'paid' => 'bg-green-100 text-green-800',
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'waived' => 'bg-blue-100 text-blue-800'
                                    ];
                                    ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $paymentColors[$reg['payment_status']]; ?>">
                                        <?php echo ucfirst($reg['payment_status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $statusColors = [
                                        'registered' => 'bg-blue-100 text-blue-800',
                                        'attended' => 'bg-green-100 text-green-800',
                                        'no_show' => 'bg-red-100 text-red-800',
                                        'cancelled' => 'bg-gray-100 text-gray-800'
                                    ];
                                    ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $statusColors[$reg['attendance_status']]; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $reg['attendance_status'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/student_footer.php'; ?>
