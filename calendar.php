<?php
/**
 * calendar.php - Monthly Calendar View
 *
 * Displays a combined calendar overlaying recurring class schedules
 * and one-time events. Click any item to drill down to its detail page.
 */

require_once 'config.php';
requireLogin();

// Determine which month to display
$year  = isset($_GET['year'])  ? (int) $_GET['year']  : (int) date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');

// Clamp values
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$firstDay   = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int) date('t', $firstDay);
$startDow   = (int) date('N', $firstDay); // 1=Mon .. 7=Sun
$monthName  = date('F', $firstDay);

$prevMonth = $month - 1;
$prevYear  = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

$nextMonth = $month + 1;
$nextYear  = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

// ---- Fetch EVENTS for this month ----
$events = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, name, event_type, event_date, start_time, end_time, location, status, requires_registration
        FROM events
        WHERE event_date BETWEEN ? AND ?
        ORDER BY start_time ASC, name ASC
    ");
    $stmt->execute([
        sprintf('%04d-%02d-01', $year, $month),
        sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth),
    ]);
    foreach ($stmt->fetchAll() as $ev) {
        $day = (int) date('j', strtotime($ev['event_date']));
        $events[$day][] = $ev;
    }
} catch (\PDOException $e) {}

// ---- Fetch CLASSES (recurring weekly schedule) ----
$classes = [];
try {
    $classes = $pdo->query("
        SELECT c.id, c.name, c.day_of_week, c.start_time, c.end_time,
               mas.name as style_name
        FROM classes c
        LEFT JOIN martial_arts_styles mas ON c.style_id = mas.id
        WHERE c.status = 'active'
        ORDER BY c.start_time ASC, c.name ASC
    ")->fetchAll();
} catch (\PDOException $e) {}

// Map classes by day_of_week name
$classesByDay = [];
foreach ($classes as $cls) {
    $classesByDay[$cls['day_of_week']][] = $cls;
}

// Event type colors
$eventTypeColors = [
    'belt_test'      => 'bg-yellow-200 text-yellow-800 border-yellow-300',
    'tournament'     => 'bg-red-200 text-red-800 border-red-300',
    'seminar'        => 'bg-blue-200 text-blue-800 border-blue-300',
    'workshop'       => 'bg-green-200 text-green-800 border-green-300',
    'demonstration'  => 'bg-purple-200 text-purple-800 border-purple-300',
    'other'          => 'bg-gray-200 text-gray-800 border-gray-300',
];

$dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between mb-6 gap-4">
        <h1 class="text-3xl font-bold text-gray-800">Calendar</h1>

        <!-- Month navigation -->
        <div class="flex items-center gap-3">
            <a href="calendar.php?year=<?php echo $prevYear; ?>&month=<?php echo $prevMonth; ?>"
               class="px-4 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg text-sm font-medium text-gray-700">&larr; Prev</a>

            <span class="text-xl font-semibold text-gray-800"><?php echo $monthName . ' ' . $year; ?></span>

            <a href="calendar.php?year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth; ?>"
               class="px-4 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg text-sm font-medium text-gray-700">Next &rarr;</a>

            <?php if ($year !== (int) date('Y') || $month !== (int) date('n')): ?>
                <a href="calendar.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Today</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Legend -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <div class="flex flex-wrap gap-4 items-center text-sm">
            <span class="font-medium text-gray-700">Legend:</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-indigo-400 inline-block"></span> Class</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-yellow-400 inline-block"></span> Belt Test</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-red-400 inline-block"></span> Tournament</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-blue-400 inline-block"></span> Seminar</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-green-400 inline-block"></span> Workshop</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-purple-400 inline-block"></span> Demonstration</span>
        </div>
    </div>

    <!-- Calendar Grid -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <!-- Day headers -->
        <div class="grid grid-cols-7 bg-gray-100 border-b border-gray-200">
            <?php foreach ($dayNames as $dn): ?>
                <div class="px-2 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider"><?php echo substr($dn, 0, 3); ?></div>
            <?php endforeach; ?>
        </div>

        <!-- Calendar cells -->
        <div class="grid grid-cols-7">
            <?php
            $today = date('Y-m-d');
            $cellCount = 0;

            // Empty cells before the 1st
            for ($i = 1; $i < $startDow; $i++):
                $cellCount++;
            ?>
                <div class="border-b border-r border-gray-100 min-h-[120px] bg-gray-50 p-1"></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $daysInMonth; $day++):
                $cellCount++;
                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $isToday = ($dateStr === $today);
                $dayOfWeek = $dayNames[((int) date('N', mktime(0,0,0,$month,$day,$year))) - 1];

                // Get classes for this day of week
                $dayClasses = $classesByDay[$dayOfWeek] ?? [];
                // Get events for this day
                $dayEvents = $events[$day] ?? [];
            ?>
                <div class="border-b border-r border-gray-100 min-h-[120px] p-1 <?php echo $isToday ? 'bg-blue-50' : ''; ?>">
                    <!-- Day number -->
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-bold <?php echo $isToday ? 'text-white bg-blue-600 w-7 h-7 flex items-center justify-center rounded-full' : 'text-gray-700 pl-1'; ?>">
                            <?php echo $day; ?>
                        </span>
                        <?php if (count($dayClasses) + count($dayEvents) > 3): ?>
                            <span class="text-xs text-gray-400"><?php echo count($dayClasses) + count($dayEvents); ?> items</span>
                        <?php endif; ?>
                    </div>

                    <!-- Classes -->
                    <?php
                    $maxVisible = 3;
                    $shown = 0;
                    foreach ($dayClasses as $cls):
                        if ($shown >= $maxVisible) break;
                        $shown++;
                    ?>
                        <a href="classes.php" title="<?php echo htmlspecialchars($cls['name'] . ' (' . ($cls['style_name'] ?? '') . ')'); ?>"
                           class="block text-xs px-1.5 py-0.5 mb-0.5 rounded border truncate bg-indigo-100 text-indigo-800 border-indigo-200 hover:bg-indigo-200 transition">
                            <?php echo date('g:i', strtotime($cls['start_time'])); ?>
                            <?php echo htmlspecialchars($cls['name']); ?>
                        </a>
                    <?php endforeach; ?>

                    <!-- Events -->
                    <?php foreach ($dayEvents as $ev):
                        if ($shown >= $maxVisible) break;
                        $shown++;
                        $evColor = $eventTypeColors[$ev['event_type']] ?? $eventTypeColors['other'];
                        $isInfoOnly = empty($ev['requires_registration']);
                    ?>
                        <a href="event_detail.php?id=<?php echo $ev['id']; ?>"
                           title="<?php echo htmlspecialchars($ev['name']) . ($isInfoOnly ? ' (Info Only)' : ''); ?>"
                           class="block text-xs px-1.5 py-0.5 mb-0.5 rounded border truncate <?php echo $evColor; ?> hover:opacity-80 transition font-medium">
                            <?php if ($isInfoOnly): ?><span class="opacity-70">📅</span> <?php endif; ?>
                            <?php if ($ev['start_time']): ?>
                                <?php echo date('g:i', strtotime($ev['start_time'])); ?>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($ev['name']); ?>
                        </a>
                    <?php endforeach; ?>

                    <?php
                    $totalItems = count($dayClasses) + count($dayEvents);
                    if ($totalItems > $maxVisible):
                    ?>
                        <button onclick="showDayDetail('<?php echo $dateStr; ?>', '<?php echo $dayOfWeek; ?>')"
                                class="text-xs text-blue-600 hover:text-blue-800 pl-1">
                            +<?php echo $totalItems - $maxVisible; ?> more
                        </button>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>

            <?php
            // Fill remaining cells to complete the row
            $remaining = (7 - ($cellCount % 7)) % 7;
            for ($i = 0; $i < $remaining; $i++):
            ?>
                <div class="border-b border-r border-gray-100 min-h-[120px] bg-gray-50 p-1"></div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- Day Detail Modal -->
<div id="dayDetailModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800" id="dayDetailTitle"></h3>
            <button onclick="document.getElementById('dayDetailModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <div id="dayDetailContent" class="space-y-2 max-h-96 overflow-y-auto"></div>
        <div class="mt-4 flex justify-end">
            <button onclick="document.getElementById('dayDetailModal').classList.add('hidden')"
                    class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Close</button>
        </div>
    </div>
</div>

<script>
// Calendar data for the day-detail modal
var calendarData = <?php
    $jsData = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $ds = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $dow = $dayNames[((int) date('N', mktime(0,0,0,$month,$d,$year))) - 1];
        $items = [];

        // Classes
        foreach ($classesByDay[$dow] ?? [] as $c) {
            $items[] = [
                'type' => 'class',
                'name' => $c['name'],
                'style' => $c['style_name'] ?? '',
                'time' => date('g:i A', strtotime($c['start_time'])) . ($c['end_time'] ? ' - ' . date('g:i A', strtotime($c['end_time'])) : ''),
                'url' => 'classes.php',
            ];
        }

        // Events
        foreach ($events[$d] ?? [] as $ev) {
            $items[] = [
                'type' => 'event',
                'name' => $ev['name'],
                'event_type' => str_replace('_', ' ', $ev['event_type']),
                'time' => $ev['start_time'] ? date('g:i A', strtotime($ev['start_time'])) . ($ev['end_time'] ? ' - ' . date('g:i A', strtotime($ev['end_time'])) : '') : '',
                'location' => $ev['location'] ?? '',
                'status' => $ev['status'],
                'url' => 'event_detail.php?id=' . $ev['id'],
                'info_only' => empty($ev['requires_registration']),
            ];
        }

        if (!empty($items)) {
            $jsData[$ds] = $items;
        }
    }
    echo json_encode($jsData, JSON_HEX_TAG | JSON_HEX_APOS);
?>;

function showDayDetail(dateStr, dayOfWeek) {
    var items = calendarData[dateStr] || [];
    var d = new Date(dateStr + 'T00:00:00');
    var opts = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    document.getElementById('dayDetailTitle').textContent = d.toLocaleDateString('en-US', opts);

    var html = '';
    items.forEach(function(item) {
        if (item.type === 'class') {
            html += '<a href="' + item.url + '" class="block p-3 rounded-lg border border-indigo-200 bg-indigo-50 hover:bg-indigo-100 transition">';
            html += '<div class="flex items-center gap-2"><span class="px-2 py-0.5 text-xs font-semibold bg-indigo-200 text-indigo-800 rounded">Class</span>';
            html += '<span class="font-medium text-gray-800">' + item.name + '</span></div>';
            html += '<div class="text-xs text-gray-500 mt-1">' + item.time;
            if (item.style) html += ' &middot; ' + item.style;
            html += '</div></a>';
        } else {
            html += '<a href="' + item.url + '" class="block p-3 rounded-lg border border-orange-200 bg-orange-50 hover:bg-orange-100 transition">';
            html += '<div class="flex items-center gap-2"><span class="px-2 py-0.5 text-xs font-semibold bg-orange-200 text-orange-800 rounded capitalize">' + item.event_type + '</span>';
            if (item.info_only) html += '<span class="px-2 py-0.5 text-xs font-semibold bg-gray-200 text-gray-600 rounded">Info Only</span>';
            html += '<span class="font-medium text-gray-800">' + item.name + '</span></div>';
            html += '<div class="text-xs text-gray-500 mt-1">';
            if (item.time) html += item.time;
            if (item.location) html += (item.time ? ' &middot; ' : '') + item.location;
            html += '</div></a>';
        }
    });

    if (!html) html = '<p class="text-gray-500 text-center py-4">No items for this day.</p>';

    document.getElementById('dayDetailContent').innerHTML = html;
    document.getElementById('dayDetailModal').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
