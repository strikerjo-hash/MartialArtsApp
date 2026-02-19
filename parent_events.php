<?php
/**
 * parent_events.php — Browse & Register Children for Events
 *
 * Parents see upcoming events and can select multiple children to register.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/parent_auth.php';

require_parent();

$parentId = get_effective_parent_id();
$pdo = get_db();
$message = '';

// Fetch children
$children = get_parent_children($parentId);
$childIds = array_column($children, 'id');
$childMap = [];
foreach ($children as $c) {
    $childMap[$c['id']] = $c;
}

// Pre-select a specific child if linked from their page
$preselectedChildId = (int)($_GET['child'] ?? 0);
if ($preselectedChildId && !in_array($preselectedChildId, $childIds)) {
    $preselectedChildId = 0; // Ignore invalid child IDs
}

// Handle multi-child registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_children'])) {
    $eventId = (int)($_POST['event_id'] ?? 0);
    $selectedChildren = $_POST['children'] ?? [];

    if ($eventId && !empty($selectedChildren)) {
        // Get event info
        $evStmt = $pdo->prepare("SELECT * FROM events WHERE id = ? AND requires_registration = 1");
        $evStmt->execute([$eventId]);
        $regEvent = $evStmt->fetch();

        if ($regEvent) {
            $registeredCount = 0;
            $alreadyRegistered = [];
            $newRegistrationIds = [];

            foreach ($selectedChildren as $childId) {
                $childId = (int)$childId;
                if (!in_array($childId, $childIds)) continue;

                // Check if already registered
                $check = $pdo->prepare("SELECT id FROM event_registrations WHERE student_id = ? AND event_id = ?");
                $check->execute([$childId, $eventId]);
                if ($check->fetch()) {
                    $alreadyRegistered[] = $childMap[$childId]['first_name'] ?? 'Student';
                    continue;
                }

                // Check capacity
                $countStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM event_registrations WHERE event_id = ?");
                $countStmt->execute([$eventId]);
                $currentCount = $countStmt->fetch()['cnt'];

                if ($regEvent['max_participants'] > 0 && $currentCount >= $regEvent['max_participants']) {
                    $message = showAlert('Event is full. Could not register all children.', 'error');
                    break;
                }

                // Register
                $insStmt = $pdo->prepare("
                    INSERT INTO event_registrations (student_id, event_id, parent_id, registration_date, payment_status, attendance_status)
                    VALUES (?, ?, ?, CURDATE(), ?, 'registered')
                ");
                $paymentStatus = ($regEvent['registration_fee'] > 0) ? 'pending' : 'waived';
                $insStmt->execute([$childId, $eventId, $parentId, $paymentStatus]);
                $newRegistrationIds[] = $pdo->lastInsertId();
                $registeredCount++;
            }

            // If there are paid registrations, redirect to the payment page
            if ($registeredCount > 0 && $regEvent['registration_fee'] > 0 && !empty($newRegistrationIds)) {
                $regIds = implode(',', $newRegistrationIds);
                header("Location: parent_event_payment.php?registration_ids={$regIds}");
                exit;
            }

            $msgs = [];
            if ($registeredCount > 0) {
                $msgs[] = "Successfully registered {$registeredCount} child(ren)!";
            }
            if (!empty($alreadyRegistered)) {
                $msgs[] = htmlspecialchars(implode(', ', $alreadyRegistered)) . ' already registered.';
            }
            $message = showAlert(implode(' ', $msgs), $registeredCount > 0 ? 'success' : 'error');
        }
    } else {
        $message = showAlert('Please select at least one child to register.', 'error');
    }
}

// Show success message if redirected from payment
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'payment') {
        $message = showAlert('Payment completed successfully! All registrations are confirmed.', 'success');
    }
}

// Get upcoming events
$filter = $_GET['filter'] ?? 'all';
$query = "
    SELECT e.*,
           COUNT(er.id) as registration_count
    FROM events e
    LEFT JOIN event_registrations er ON e.id = er.event_id
    WHERE e.event_date >= CURDATE() AND e.status = 'upcoming' AND e.requires_registration = 1
";
$params = [];

if ($filter !== 'all') {
    $query .= " AND e.event_type = ?";
    $params[] = $filter;
}

$query .= " GROUP BY e.id ORDER BY e.event_date ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$events = $stmt->fetchAll();

// Get which children are already registered for each event
$childRegistrations = [];
if (!empty($childIds)) {
    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $regStmt = $pdo->prepare("
        SELECT er.event_id, er.student_id, er.payment_status
        FROM event_registrations er
        WHERE er.student_id IN ({$placeholders})
    ");
    $regStmt->execute($childIds);
    foreach ($regStmt->fetchAll() as $r) {
        $childRegistrations[$r['event_id']][$r['student_id']] = $r['payment_status'];
    }
}

// My children's registrations
$myRegistrations = [];
if (!empty($childIds)) {
    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $myRegStmt = $pdo->prepare("
        SELECT er.*, e.name as event_name, e.event_date, e.event_type, e.registration_fee as fee,
               s.first_name, s.last_name
        FROM event_registrations er
        JOIN events e ON er.event_id = e.id
        JOIN students s ON s.id = er.student_id
        WHERE er.student_id IN ({$placeholders})
        ORDER BY e.event_date ASC
    ");
    $myRegStmt->execute($childIds);
    $myRegistrations = $myRegStmt->fetchAll();
}

include 'includes/parent_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="mb-8">
        <div class="flex items-center gap-3 mb-2">
            <h1 class="text-3xl font-bold text-gray-800">Events</h1>
            <?php if ($preselectedChildId && isset($childMap[$preselectedChildId])): ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-blue-100 text-blue-800 text-sm font-semibold rounded-full">
                    Registering: <?= htmlspecialchars($childMap[$preselectedChildId]['first_name']) ?>
                </span>
            <?php endif; ?>
        </div>
        <p class="text-gray-600">
            <?php if ($preselectedChildId && isset($childMap[$preselectedChildId])): ?>
                Browse events and register <?= htmlspecialchars($childMap[$preselectedChildId]['first_name']) ?> — or select multiple children below
            <?php else: ?>
                Browse events and register your children
            <?php endif; ?>
        </p>
        <?php if ($preselectedChildId): ?>
            <a href="parent_child.php?id=<?= $preselectedChildId ?>" class="text-sm text-blue-600 hover:underline">&larr; Back to <?= htmlspecialchars($childMap[$preselectedChildId]['first_name']) ?>'s profile</a>
        <?php endif; ?>
    </div>

    <?php if (empty($children)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <div class="text-6xl mb-4">👨‍👩‍👧‍👦</div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">No Children Linked</h3>
            <p class="text-gray-600 mb-4">Link your children's student accounts first before registering for events.</p>
            <a href="parent_portal.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium inline-block">
                Go to Dashboard
            </a>
        </div>
    <?php else: ?>

    <!-- Filter Tabs -->
    <?php $childParam = $preselectedChildId ? '&child=' . $preselectedChildId : ''; ?>
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <div class="flex flex-wrap gap-2">
            <a href="?filter=all<?= $childParam ?>" class="px-4 py-2 rounded-lg text-sm font-medium <?= $filter === 'all' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">All Events</a>
            <a href="?filter=belt_test<?= $childParam ?>" class="px-4 py-2 rounded-lg text-sm font-medium <?= $filter === 'belt_test' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">Belt Tests</a>
            <a href="?filter=tournament<?= $childParam ?>" class="px-4 py-2 rounded-lg text-sm font-medium <?= $filter === 'tournament' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">Tournaments</a>
            <a href="?filter=seminar<?= $childParam ?>" class="px-4 py-2 rounded-lg text-sm font-medium <?= $filter === 'seminar' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">Seminars</a>
            <a href="?filter=workshop<?= $childParam ?>" class="px-4 py-2 rounded-lg text-sm font-medium <?= $filter === 'workshop' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">Workshops</a>
        </div>
    </div>

    <!-- Events List -->
    <?php if (empty($events)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <div class="text-6xl mb-4">📅</div>
            <h2 class="text-2xl font-bold text-gray-800 mb-2">No Upcoming Events</h2>
            <p class="text-gray-600">Check back later for new events</p>
        </div>
    <?php else: ?>
        <div class="space-y-6 mb-8">
            <?php foreach ($events as $event): ?>
                <?php
                $eventRegs = $childRegistrations[$event['id']] ?? [];
                $allRegistered = !empty($childIds) && count(array_intersect_key($eventRegs, array_flip($childIds))) === count($childIds);
                $isFull = $event['max_participants'] > 0 && $event['registration_count'] >= $event['max_participants'];
                ?>
                <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-5">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-xl font-bold mb-1"><?= htmlspecialchars($event['name']) ?></h3>
                                <p class="text-sm opacity-90 capitalize"><?= str_replace('_', ' ', $event['event_type']) ?></p>
                            </div>
                            <div class="text-right text-sm">
                                <p><?= formatDate($event['event_date']) ?></p>
                                <?php if ($event['location']): ?>
                                    <p class="opacity-80"><?= htmlspecialchars($event['location']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="p-5">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-4 text-sm text-gray-600">
                                <span>💰 <strong class="text-green-600"><?= formatMoney($event['registration_fee']) ?></strong></span>
                                <span>👥 <?= $event['registration_count'] ?> registered<?php if ($event['max_participants']): ?> / <?= $event['max_participants'] ?> max<?php endif; ?></span>
                            </div>
                        </div>

                        <?php if ($event['description']): ?>
                            <p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars(substr($event['description'], 0, 200)) ?></p>
                        <?php endif; ?>

                        <?php if ($isFull && empty($eventRegs)): ?>
                            <div class="text-center py-3">
                                <span class="text-gray-500 font-medium">Event Full</span>
                            </div>
                        <?php elseif ($allRegistered): ?>
                            <div class="text-center py-3">
                                <span class="text-green-600 font-semibold">✓ All children registered</span>
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="register_children" value="1">
                                <input type="hidden" name="event_id" value="<?= $event['id'] ?>">

                                <div class="border border-gray-200 rounded-lg p-4 mb-4">
                                    <p class="text-sm font-medium text-gray-700 mb-3">Select children to register:</p>
                                    <div class="space-y-2">
                                        <?php foreach ($children as $child): ?>
                                            <?php
                                            $isChildRegistered = isset($eventRegs[$child['id']]);
                                            ?>
                                            <label class="flex items-center gap-3 p-2 rounded-lg <?= $isChildRegistered ? 'bg-green-50' : 'hover:bg-gray-50' ?> cursor-pointer">
                                                <?php if ($isChildRegistered): ?>
                                                    <input type="checkbox" disabled checked
                                                           class="w-4 h-4 text-green-600 border-gray-300 rounded">
                                                    <span class="text-sm text-gray-700">
                                                        <?= htmlspecialchars($child['first_name'] . ' ' . $child['last_name']) ?>
                                                        <span class="text-xs text-green-600 font-semibold ml-1">✓ Registered</span>
                                                    </span>
                                                <?php else: ?>
                                                    <?php $isPreselected = ($preselectedChildId && (int)$child['id'] === $preselectedChildId); ?>
                                                    <input type="checkbox" name="children[]" value="<?= $child['id'] ?>"
                                                           <?= $isPreselected ? 'checked' : '' ?>
                                                           class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                                    <span class="text-sm text-gray-700">
                                                        <?= htmlspecialchars($child['first_name'] . ' ' . $child['last_name']) ?>
                                                        <span class="text-xs text-gray-500">(<?= htmlspecialchars($child['current_belt']) ?>)</span>
                                                    </span>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <button type="submit"
                                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg transition">
                                    Register Selected Children
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- My Children's Registrations -->
    <?php if (!empty($myRegistrations)): ?>
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">My Children's Registrations</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Child</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fee</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($myRegistrations as $reg): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                                    <?= htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= htmlspecialchars($reg['event_name']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= formatDate($reg['event_date']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 capitalize">
                                    <?= str_replace('_', ' ', $reg['event_type']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                    <?= formatMoney($reg['fee']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $paymentColors = [
                                        'paid' => 'bg-green-100 text-green-800',
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'waived' => 'bg-blue-100 text-blue-800'
                                    ];
                                    ?>
                                    <?php if ($reg['payment_status'] === 'pending' && $reg['fee'] > 0): ?>
                                        <a href="parent_event_payment.php?registration_ids=<?= $reg['id'] ?>"
                                           class="inline-block px-3 py-1 text-xs font-semibold rounded-full bg-orange-500 text-white hover:bg-orange-600 transition">
                                            Complete Payment
                                        </a>
                                    <?php else: ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $paymentColors[$reg['payment_status']] ?? 'bg-gray-100 text-gray-800' ?>">
                                        <?= ucfirst($reg['payment_status']) ?>
                                    </span>
                                    <?php endif; ?>
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
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $statusColors[$reg['attendance_status']] ?? 'bg-gray-100 text-gray-800' ?>">
                                        <?= ucfirst(str_replace('_', ' ', $reg['attendance_status'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php endif; /* end children check */ ?>
</div>

<?php include 'includes/student_footer.php'; ?>
