<?php
require_once 'config.php';
requireLogin();

// --- Idempotent migrations ---
try { $pdo->exec("ALTER TABLE events ADD COLUMN tax_deductible TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE events MODIFY COLUMN event_type ENUM('belt_test','tournament','seminar','workshop','demonstration','camp','other') NOT NULL"); } catch (PDOException $e) {}

$message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $stmt = $pdo->prepare("
                    INSERT INTO events (name, event_type, description, event_date, start_time,
                                       end_time, location, max_participants, registration_fee,
                                       registration_deadline, instructor_id, status, requirements, requires_registration, tax_deductible)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    sanitizeInput($_POST['name']),
                    $_POST['event_type'],
                    sanitizeInput($_POST['description']),
                    $_POST['event_date'],
                    $_POST['start_time'] ?: null,
                    $_POST['end_time'] ?: null,
                    sanitizeInput($_POST['location']),
                    $_POST['max_participants'] ?: null,
                    $_POST['registration_fee'],
                    $_POST['registration_deadline'] ?: null,
                    $_POST['instructor_id'] ?: null,
                    $_POST['status'],
                    sanitizeInput($_POST['requirements']),
                    isset($_POST['requires_registration']) ? 1 : 0,
                    isset($_POST['tax_deductible']) ? 1 : 0
                ]);
                $message = showAlert('Event created successfully!', 'success');
                break;
                
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
                $stmt->execute([$_POST['event_id']]);
                $message = showAlert('Event deleted successfully!', 'success');
                break;
                
            case 'update_status':
                $stmt = $pdo->prepare("UPDATE events SET status = ? WHERE id = ?");
                $stmt->execute([$_POST['new_status'], $_POST['event_id']]);
                $message = showAlert('Event status updated!', 'success');
                break;
        }
    }
}

// Get events with registration counts
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';

$query = "
    SELECT e.*, 
           u.full_name as instructor_name,
           COUNT(er.id) as total_registrations,
           SUM(CASE WHEN er.payment_status = 'paid' THEN 1 ELSE 0 END) as paid_registrations
    FROM events e
    LEFT JOIN users u ON e.instructor_id = u.id
    LEFT JOIN event_registrations er ON e.id = er.event_id
    WHERE 1=1
";

if ($type_filter) {
    $query .= " AND e.event_type = :type";
}
if ($status_filter) {
    $query .= " AND e.status = :status";
}

$query .= " GROUP BY e.id ORDER BY e.event_date ASC";

$stmt = $pdo->prepare($query);
if ($type_filter) {
    $stmt->bindValue(':type', $type_filter);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$events = $stmt->fetchAll();

// Get instructors for dropdown
$instructors = $pdo->query("SELECT id, full_name FROM users WHERE role IN ('admin', 'instructor') ORDER BY full_name")->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Events</h1>
        <button onclick="document.getElementById('addModal').classList.remove('hidden')" 
                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
            + Create Event
        </button>
    </div>
    
    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4">
            <select name="type" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                <option value="">All Event Types</option>
                <option value="belt_test" <?php echo $type_filter === 'belt_test' ? 'selected' : ''; ?>>Belt Test</option>
                <option value="tournament" <?php echo $type_filter === 'tournament' ? 'selected' : ''; ?>>Tournament</option>
                <option value="seminar" <?php echo $type_filter === 'seminar' ? 'selected' : ''; ?>>Seminar</option>
                <option value="workshop" <?php echo $type_filter === 'workshop' ? 'selected' : ''; ?>>Workshop</option>
                <option value="demonstration" <?php echo $type_filter === 'demonstration' ? 'selected' : ''; ?>>Demonstration</option>
                <option value="camp" <?php echo $type_filter === 'camp' ? 'selected' : ''; ?>>Camp</option>
                <option value="other" <?php echo $type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
            </select>
            
            <select name="status" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                <option value="">All Status</option>
                <option value="upcoming" <?php echo $status_filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                <option value="ongoing" <?php echo $status_filter === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
            
            <button type="submit" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded-lg">
                Filter
            </button>
            <a href="events.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg">
                Reset
            </a>
        </form>
    </div>
    
    <!-- Events Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($events as $event): ?>
            <div class="bg-white rounded-lg shadow-lg overflow-hidden hover:shadow-xl transition-shadow">
                <!-- Event Header -->
                <div class="<?php 
                    $headerColors = [
                        'belt_test' => 'bg-yellow-500',
                        'tournament' => 'bg-red-500',
                        'seminar' => 'bg-blue-500',
                        'workshop' => 'bg-green-500',
                        'demonstration' => 'bg-purple-500',
                        'camp' => 'bg-teal-500',
                        'other' => 'bg-gray-500'
                    ];
                    echo $headerColors[$event['event_type']] ?? 'bg-gray-500';
                ?> text-white p-4">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <h3 class="text-lg font-bold mb-1"><?php echo $event['name']; ?></h3>
                            <p class="text-sm opacity-90 capitalize"><?php echo str_replace('_', ' ', $event['event_type']); ?></p>
                        </div>
                        <?php
                        $statusBadges = [
                            'upcoming' => 'bg-white text-gray-800',
                            'ongoing' => 'bg-yellow-400 text-gray-900',
                            'completed' => 'bg-green-400 text-gray-900',
                            'cancelled' => 'bg-red-400 text-white'
                        ];
                        ?>
                        <div class="flex flex-col items-end gap-1">
                            <span class="px-2 py-1 text-xs font-semibold rounded <?php echo $statusBadges[$event['status']]; ?>">
                                <?php echo ucfirst($event['status']); ?>
                            </span>
                            <?php if (empty($event['requires_registration'])): ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded bg-gray-200 text-gray-700">
                                    Info Only
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($event['tax_deductible'])): ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded bg-green-200 text-green-800">
                                    Tax-Deductible
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Event Body -->
                <div class="p-4">
                    <div class="space-y-3 mb-4">
                        <div class="flex items-center text-sm text-gray-600">
                            <span class="mr-2">📅</span>
                            <span><?php echo formatDate($event['event_date']); ?></span>
                        </div>
                        
                        <?php if ($event['start_time']): ?>
                            <div class="flex items-center text-sm text-gray-600">
                                <span class="mr-2">⏰</span>
                                <span>
                                    <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                    <?php if ($event['end_time']): ?>
                                        - <?php echo date('g:i A', strtotime($event['end_time'])); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($event['location']): ?>
                            <div class="flex items-center text-sm text-gray-600">
                                <span class="mr-2">📍</span>
                                <span><?php echo $event['location']; ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($event['instructor_name']): ?>
                            <div class="flex items-center text-sm text-gray-600">
                                <span class="mr-2">👨‍🏫</span>
                                <span><?php echo $event['instructor_name']; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($event['description']): ?>
                        <p class="text-sm text-gray-600 mb-4 line-clamp-2"><?php echo $event['description']; ?></p>
                    <?php endif; ?>
                    
                    <!-- Registration Info -->
                    <div class="border-t border-gray-200 pt-4 mt-4">
                        <?php if (!empty($event['requires_registration'])): ?>
                            <div class="flex justify-between items-center mb-3">
                                <div>
                                    <p class="text-xs text-gray-500">Registrations</p>
                                    <p class="text-lg font-bold text-gray-800">
                                        <?php echo $event['total_registrations']; ?>
                                        <?php if ($event['max_participants']): ?>
                                            / <?php echo $event['max_participants']; ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-gray-500">Fee</p>
                                    <p class="text-lg font-bold text-green-600">
                                        <?php echo formatMoney($event['registration_fee']); ?>
                                    </p>
                                </div>
                            </div>

                            <?php if ($event['registration_deadline']): ?>
                                <p class="text-xs text-gray-500">
                                    Registration deadline: <?php echo formatDate($event['registration_deadline']); ?>
                                </p>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-2">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    Calendar Only &mdash; No Registration
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Actions -->
                    <div class="flex space-x-2 mt-4">
                        <a href="event_detail.php?id=<?php echo $event['id']; ?>" 
                           class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-center py-2 rounded-lg text-sm font-medium">
                            View Details
                        </a>
                        <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this event?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
                            <button type="submit" 
                                    class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                                🗑️
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <?php if (empty($events)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <p class="text-lg text-gray-500">No events found</p>
            <p class="text-sm text-gray-400 mt-2">Create your first event to get started</p>
        </div>
    <?php endif; ?>
</div>

<!-- Add Event Modal -->
<div id="addModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-3xl shadow-lg rounded-md bg-white my-10">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Create New Event</h3>
            <button onclick="document.getElementById('addModal').classList.add('hidden')" 
                    class="text-gray-600 hover:text-gray-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add">
            
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Event Name *</label>
                    <input type="text" name="name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Event Type *</label>
                    <select name="event_type" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="belt_test">Belt Test</option>
                        <option value="tournament">Tournament</option>
                        <option value="seminar">Seminar</option>
                        <option value="workshop">Workshop</option>
                        <option value="demonstration">Demonstration</option>
                        <option value="camp">Camp</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status *</label>
                    <select name="status" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="upcoming">Upcoming</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Event Date *</label>
                    <input type="date" name="event_date" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Time</label>
                    <input type="time" name="start_time"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">End Time</label>
                    <input type="time" name="end_time"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                    <input type="text" name="location"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Instructor</label>
                    <select name="instructor_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">Select Instructor</option>
                        <?php foreach ($instructors as $instructor): ?>
                            <option value="<?php echo $instructor['id']; ?>">
                                <?php echo $instructor['full_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Participants</label>
                    <input type="number" name="max_participants" min="1"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Registration Fee *</label>
                    <input type="number" name="registration_fee" step="0.01" value="0" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Registration Deadline</label>
                    <input type="date" name="registration_deadline"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Requirements</label>
                <textarea name="requirements" rows="2" placeholder="e.g., Minimum belt rank, equipment needed, etc."
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="bg-gray-50 rounded-lg p-4">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="requires_registration" value="1" checked
                           class="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Requires Registration</span>
                        <p class="text-xs text-gray-500 mt-0.5">Uncheck to create a calendar-only event (no student registration or payment)</p>
                    </div>
                </label>
            </div>

            <div class="bg-green-50 rounded-lg p-4">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="tax_deductible" value="1"
                           class="w-5 h-5 text-green-600 border-gray-300 rounded focus:ring-green-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Tax-Deductible (Childcare/Camp)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Mark if this event qualifies as dependent care for tax purposes (e.g., afterschool program, camp)</p>
                    </div>
                </label>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Create Event
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
