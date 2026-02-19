<?php
require_once 'config.php';
requireLogin();

$message = '';
$event_id = $_GET['id'] ?? 0;

// Handle registrations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'register':
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO event_registrations (event_id, student_id, payment_status, amount_paid, notes)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $event_id,
                    $_POST['student_id'],
                    $_POST['payment_status'],
                    $_POST['amount_paid'],
                    sanitizeInput($_POST['notes'])
                ]);
                
                // Record payment if paid
                if ($_POST['payment_status'] === 'paid' && $_POST['amount_paid'] > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO payments (student_id, payment_type, reference_id, amount, 
                                            payment_method, payment_date, receipt_number, notes)
                        VALUES (?, 'event', ?, ?, ?, CURDATE(), ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['student_id'],
                        $event_id,
                        $_POST['amount_paid'],
                        $_POST['payment_method'],
                        generateReceiptNumber(),
                        'Event registration payment'
                    ]);
                }
                
                $message = showAlert('Student registered successfully!', 'success');
            } catch (PDOException $e) {
                $message = showAlert('Error: Student may already be registered', 'error');
            }
            break;
            
        case 'update_registration':
            $stmt = $pdo->prepare("
                UPDATE event_registrations 
                SET attendance_status = ?, result = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['attendance_status'],
                sanitizeInput($_POST['result']),
                sanitizeInput($_POST['notes']),
                $_POST['registration_id']
            ]);
            $message = showAlert('Registration updated!', 'success');
            break;
            
        case 'cancel_registration':
            $stmt = $pdo->prepare("DELETE FROM event_registrations WHERE id = ?");
            $stmt->execute([$_POST['registration_id']]);
            $message = showAlert('Registration cancelled!', 'success');
            break;
    }
}

// Get event details
$stmt = $pdo->prepare("
    SELECT e.*, u.full_name as instructor_name
    FROM events e
    LEFT JOIN users u ON e.instructor_id = u.id
    WHERE e.id = ?
");
$stmt->execute([$event_id]);
$event = $stmt->fetch();

if (!$event) {
    header('Location: events.php');
    exit;
}

// Get registrations
$registrations = $pdo->prepare("
    SELECT er.*,
           s.first_name, s.last_name, s.email, s.phone, s.date_of_birth,
           COALESCE(b.name, 'No Belt') as current_belt
    FROM event_registrations er
    JOIN students s ON er.student_id = s.id
    LEFT JOIN (
        SELECT sb.student_id, b.name
        FROM student_belts sb
        JOIN belts b ON sb.belt_id = b.id
        WHERE sb.id IN (SELECT MAX(id) FROM student_belts GROUP BY student_id)
    ) b ON s.id = b.student_id
    WHERE er.event_id = ?
    ORDER BY er.registration_date DESC
");
$registrations->execute([$event_id]);
$registrations = $registrations->fetchAll();

// Get students for registration dropdown (active students only)
$students = $pdo->query("
    SELECT s.id, s.first_name, s.last_name, s.email,
           COALESCE(b.name, 'No Belt') as current_belt
    FROM students s
    LEFT JOIN (
        SELECT sb.student_id, b.name
        FROM student_belts sb
        JOIN belts b ON sb.belt_id = b.id
        WHERE sb.id IN (SELECT MAX(id) FROM student_belts GROUP BY student_id)
    ) b ON s.id = b.student_id
    WHERE s.status = 'active'
    ORDER BY s.first_name, s.last_name
")->fetchAll();

// Calculate statistics
$total_registrations = count($registrations);
$paid_count = count(array_filter($registrations, fn($r) => $r['payment_status'] === 'paid'));
$attended_count = count(array_filter($registrations, fn($r) => $r['attendance_status'] === 'attended'));
$total_revenue = array_sum(array_column($registrations, 'amount_paid'));

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <div class="mb-6">
        <a href="events.php" class="text-blue-600 hover:text-blue-800 mb-4 inline-block">
            ← Back to Events
        </a>
    </div>
    
    <!-- Event Header -->
    <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
        <div class="flex justify-between items-start mb-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 mb-2"><?php echo $event['name']; ?></h1>
                <span class="inline-block px-3 py-1 text-sm font-semibold rounded-full capitalize <?php
                    $typeColors = [
                        'belt_test' => 'bg-yellow-100 text-yellow-800',
                        'tournament' => 'bg-red-100 text-red-800',
                        'seminar' => 'bg-blue-100 text-blue-800',
                        'workshop' => 'bg-green-100 text-green-800',
                        'demonstration' => 'bg-purple-100 text-purple-800',
                        'other' => 'bg-gray-100 text-gray-800'
                    ];
                    echo $typeColors[$event['event_type']] ?? 'bg-gray-100 text-gray-800';
                ?>">
                    <?php echo str_replace('_', ' ', $event['event_type']); ?>
                </span>
            </div>
            <?php if (!empty($event['requires_registration'])): ?>
                <button onclick="document.getElementById('registerModal').classList.remove('hidden')"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                    + Register Student
                </button>
            <?php else: ?>
                <span class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-600 rounded-lg font-medium text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    Calendar Only &mdash; No Registration
                </span>
            <?php endif; ?>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mt-6">
            <div class="border-l-4 border-blue-500 pl-4">
                <p class="text-sm text-gray-600">Event Date</p>
                <p class="text-lg font-semibold text-gray-800">
                    <?php echo formatDate($event['event_date']); ?>
                </p>
                <?php if ($event['start_time']): ?>
                    <p class="text-sm text-gray-600">
                        <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                        <?php if ($event['end_time']): ?>
                            - <?php echo date('g:i A', strtotime($event['end_time'])); ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            
            <div class="border-l-4 border-green-500 pl-4">
                <p class="text-sm text-gray-600">Registrations</p>
                <p class="text-lg font-semibold text-gray-800">
                    <?php echo $total_registrations; ?>
                    <?php if ($event['max_participants']): ?>
                        / <?php echo $event['max_participants']; ?>
                    <?php endif; ?>
                </p>
                <p class="text-sm text-gray-600">
                    <?php echo $paid_count; ?> paid, <?php echo $attended_count; ?> attended
                </p>
            </div>
            
            <div class="border-l-4 border-yellow-500 pl-4">
                <p class="text-sm text-gray-600">Registration Fee</p>
                <p class="text-lg font-semibold text-green-600">
                    <?php echo formatMoney($event['registration_fee']); ?>
                </p>
                <p class="text-sm text-gray-600">
                    Total: <?php echo formatMoney($total_revenue); ?>
                </p>
            </div>
            
            <div class="border-l-4 border-purple-500 pl-4">
                <p class="text-sm text-gray-600">Status</p>
                <p class="text-lg font-semibold text-gray-800 capitalize">
                    <?php echo $event['status']; ?>
                </p>
                <?php if ($event['registration_deadline']): ?>
                    <p class="text-sm text-gray-600">
                        Deadline: <?php echo formatDate($event['registration_deadline']); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($event['description']): ?>
            <div class="mt-6 pt-6 border-t border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Description</h3>
                <p class="text-gray-700"><?php echo nl2br($event['description']); ?></p>
            </div>
        <?php endif; ?>
        
        <div class="mt-6 pt-6 border-t border-gray-200 grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php if ($event['location']): ?>
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">📍 Location</h3>
                    <p class="text-gray-700"><?php echo $event['location']; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ($event['instructor_name']): ?>
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">👨‍🏫 Instructor</h3>
                    <p class="text-gray-700"><?php echo $event['instructor_name']; ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($event['requirements']): ?>
            <div class="mt-6 pt-6 border-t border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Requirements</h3>
                <p class="text-gray-700"><?php echo nl2br($event['requirements']); ?></p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Registrations Table -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Registered Students (<?php echo $total_registrations; ?>)</h2>
        </div>
        
        <?php if (empty($registrations)): ?>
            <div class="p-12 text-center text-gray-500">
                <p class="text-lg">No registrations yet</p>
                <p class="text-sm mt-2">Register students to this event using the button above</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">DOB</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Belt</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Registered</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Attendance</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Result</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($registrations as $reg): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-medium text-gray-900">
                                        <?php echo $reg['first_name'] . ' ' . $reg['last_name']; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php echo !empty($reg['date_of_birth']) ? formatDate($reg['date_of_birth']) : '<span class="text-gray-400">—</span>'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php echo $reg['current_belt']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo $reg['email'] ?: 'N/A'; ?></div>
                                    <div class="text-sm text-gray-500"><?php echo $reg['phone'] ?: 'N/A'; ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php echo formatDate($reg['registration_date']); ?>
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
                                    <div class="text-sm text-gray-600 mt-1">
                                        <?php echo formatMoney($reg['amount_paid']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $attendanceColors = [
                                        'registered' => 'bg-gray-100 text-gray-800',
                                        'attended' => 'bg-green-100 text-green-800',
                                        'no_show' => 'bg-red-100 text-red-800',
                                        'cancelled' => 'bg-orange-100 text-orange-800'
                                    ];
                                    ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $attendanceColors[$reg['attendance_status']]; ?>">
                                        <?php echo str_replace('_', ' ', ucfirst($reg['attendance_status'])); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <?php echo $reg['result'] ?: '-'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <button onclick="editRegistration(<?php echo htmlspecialchars(json_encode($reg)); ?>)" 
                                            class="text-blue-600 hover:text-blue-900 mr-2">
                                        Edit
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirmDelete('Cancel this registration?')">
                                        <input type="hidden" name="action" value="cancel_registration">
                                        <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-900">Cancel</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Register Student Modal -->
<div id="registerModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Register Student</h3>
            <button onclick="document.getElementById('registerModal').classList.add('hidden')" 
                    class="text-gray-600 hover:text-gray-800">✕</button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="register">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Student *</label>
                <select name="student_id" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="">Choose a student...</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['id']; ?>">
                            <?php echo $student['first_name'] . ' ' . $student['last_name']; ?> 
                            (<?php echo $student['current_belt']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Status *</label>
                    <select name="payment_status" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                        <option value="waived">Waived</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount Paid *</label>
                    <input type="number" name="amount_paid" step="0.01" value="<?php echo $event['registration_fee']; ?>" required
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
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('registerModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Register Student
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Registration Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Update Registration</h3>
            <button onclick="document.getElementById('editModal').classList.add('hidden')" 
                    class="text-gray-600 hover:text-gray-800">✕</button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update_registration">
            <input type="hidden" name="registration_id" id="edit_reg_id">
            
            <div id="edit_student_name" class="text-lg font-semibold text-gray-800 mb-4"></div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Attendance Status</label>
                <select name="attendance_status" id="edit_attendance_status"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="registered">Registered</option>
                    <option value="attended">Attended</option>
                    <option value="no_show">No Show</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Result (for belt tests/tournaments)</label>
                <input type="text" name="result" id="edit_result" placeholder="e.g., Passed, 1st Place, etc."
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" id="edit_notes" rows="3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Update
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editRegistration(reg) {
    document.getElementById('edit_reg_id').value = reg.id;
    document.getElementById('edit_student_name').textContent = reg.first_name + ' ' + reg.last_name;
    document.getElementById('edit_attendance_status').value = reg.attendance_status;
    document.getElementById('edit_result').value = reg.result || '';
    document.getElementById('edit_notes').value = reg.notes || '';
    document.getElementById('editModal').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
