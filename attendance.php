<?php
require_once 'config.php';
requireLogin();

$message = '';
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_class = $_GET['class_id'] ?? '';

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['attendance'])) {
    verify_csrf();

    // Prepare statements once outside the loop (avoids N+1)
    $checkStmt = $pdo->prepare("
        SELECT id FROM attendance
        WHERE student_id = ? AND class_id = ? AND attendance_date = ?" . school_where() . "
    ");
    $updateStmt = $pdo->prepare("
        UPDATE attendance
        SET status = ?, check_in_time = ?
        WHERE student_id = ? AND class_id = ? AND attendance_date = ?" . school_where() . "
    ");
    $insertStmt = $pdo->prepare("
        INSERT INTO attendance (school_id, student_id, class_id, attendance_date, status, check_in_time)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($_POST['attendance'] as $student_id => $status) {
        $student_id = (int) $student_id;
        $checkInTime = $_POST['check_in_time'][$student_id] ?? null;

        // Check if attendance record exists
        $checkParams = [$student_id, $_POST['class_id'], $_POST['date']];
        school_param($checkParams);
        $checkStmt->execute($checkParams);

        if ($checkStmt->fetch()) {
            // Update existing
            $updateParams = [$status, $checkInTime, $student_id, $_POST['class_id'], $_POST['date']];
            school_param($updateParams);
            $updateStmt->execute($updateParams);
        } else {
            // Insert new
            $insertStmt->execute([
                current_school_id(),
                $student_id,
                $_POST['class_id'],
                $_POST['date'],
                $status,
                $checkInTime
            ]);
        }
    }
    $message = showAlert('Attendance recorded successfully!', 'success');
}

// Get classes for the selected date
$day_of_week = date('l', strtotime($selected_date));
$params = [$day_of_week];
$classes_on_day = $pdo->prepare("
    SELECT c.*, mas.name as style_name, u.full_name as instructor_name
    FROM classes c
    LEFT JOIN martial_arts_styles mas ON c.style_id = mas.id
    LEFT JOIN users u ON c.instructor_id = u.id
    WHERE c.day_of_week = ? AND c.status = 'active'" . school_where('c') . "
    ORDER BY c.start_time
");
school_param($params);
$classes_on_day->execute($params);
$classes_on_day = $classes_on_day->fetchAll();

// Get enrolled students for selected class with membership & payment status
$enrolled_students = [];
if ($selected_class) {
    $params = [$selected_date, $selected_class];
    $enrolled_students = $pdo->prepare("
        SELECT s.*,
               COALESCE(a.status, 'absent') as attendance_status,
               a.check_in_time,
               m.status as membership_status,
               m.payment_status,
               m.end_date as membership_end_date,
               mp.name as plan_name,
               s.emergency_contact_name as parent_name,
               s.emergency_contact_phone as parent_phone,
               s.email as student_email
        FROM class_enrollments ce
        JOIN students s ON ce.student_id = s.id
        LEFT JOIN attendance a ON s.id = a.student_id
            AND a.class_id = ce.class_id
            AND a.attendance_date = ?
        LEFT JOIN memberships m ON m.student_id = s.id
            AND m.id = (
                SELECT m2.id FROM memberships m2
                WHERE m2.student_id = s.id
                ORDER BY m2.end_date DESC LIMIT 1
            )
        LEFT JOIN membership_plans mp ON m.plan_id = mp.id
        WHERE ce.class_id = ? AND ce.status = 'active'" . school_where('ce') . "
        ORDER BY s.first_name, s.last_name
    ");
    school_param($params);
    $enrolled_students->execute($params);
    $enrolled_students = $enrolled_students->fetchAll();
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Attendance</h1>
    
    <!-- Date and Class Selection -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select Date</label>
                <input type="date" name="date" value="<?php echo $selected_date; ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                       onchange="this.form.submit()">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select Class</label>
                <select name="class_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                        onchange="this.form.submit()">
                    <option value="">Choose a class...</option>
                    <?php foreach ($classes_on_day as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>>
                            <?php echo date('g:i A', strtotime($class['start_time'])); ?> - 
                            <?php echo $class['name']; ?> (<?php echo $class['style_name']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="flex items-end">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                    Load Students
                </button>
            </div>
        </form>
        
        <div class="mt-4 text-sm text-gray-600">
            <strong>Selected Date:</strong> <?php echo formatDate($selected_date); ?> (<?php echo $day_of_week; ?>)
            <?php if (empty($classes_on_day)): ?>
                <span class="text-orange-600 ml-2">⚠️ No classes scheduled for this day</span>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Attendance Form -->
    <?php if ($selected_class && !empty($enrolled_students)): ?>
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">Mark Attendance</h2>
            </div>
            
            <form method="POST" class="p-6">
                <?= csrf_field() ?>
                <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                
                <div class="overflow-x-auto">
                    <?php
                    // Count students with payment issues for the summary bar
                    $late_payment_count = 0;
                    $no_membership_count = 0;
                    foreach ($enrolled_students as $s) {
                        if (empty($s['membership_status']) || ($s['membership_status'] !== 'active') || (!empty($s['membership_end_date']) && $s['membership_end_date'] < date('Y-m-d'))) {
                            $no_membership_count++;
                        } elseif (in_array($s['payment_status'] ?? '', ['pending', 'partial'])) {
                            $late_payment_count++;
                        }
                    }
                    ?>
                    <?php if ($late_payment_count > 0 || $no_membership_count > 0): ?>
                    <div class="mb-4 flex flex-wrap gap-3">
                        <?php if ($late_payment_count > 0): ?>
                            <div class="flex items-center px-4 py-2 bg-yellow-50 border border-yellow-200 rounded-lg">
                                <span class="text-yellow-600 font-semibold text-sm"><?php echo $late_payment_count; ?> student(s) with late/pending payments</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($no_membership_count > 0): ?>
                            <div class="flex items-center px-4 py-2 bg-red-50 border border-red-200 rounded-lg">
                                <span class="text-red-600 font-semibold text-sm"><?php echo $no_membership_count; ?> student(s) without active membership</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <table class="min-w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Attendance</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Check-in Time</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($enrolled_students as $student):
                                $hasActiveMembership = !empty($student['membership_status'])
                                    && $student['membership_status'] === 'active'
                                    && !empty($student['membership_end_date'])
                                    && $student['membership_end_date'] >= date('Y-m-d');
                                $paymentLate = $hasActiveMembership && in_array($student['payment_status'] ?? '', ['pending', 'partial']);
                                $noMembership = !$hasActiveMembership;
                                $showWarning = $paymentLate || $noMembership;
                            ?>
                                <tr class="<?php echo $showWarning ? 'bg-yellow-50' : ''; ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="font-medium text-gray-900">
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                            <?php if ($showWarning): ?>
                                                <span class="ml-1 text-xs text-red-500" title="Payment attention needed">&#9888;</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($student['plan_name'])): ?>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($student['plan_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($noMembership): ?>
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">No Membership</span>
                                        <?php elseif ($paymentLate): ?>
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800"><?php echo ucfirst($student['payment_status']); ?></span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Paid</span>
                                        <?php endif; ?>
                                        <?php if ($showWarning && (!empty($student['parent_phone']) || !empty($student['student_email']))): ?>
                                            <button type="button" onclick="showReminderModal(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['first_name'] . ' ' . $student['last_name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($student['parent_name'] ?? ''), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($student['parent_phone'] ?? ''), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($student['student_email'] ?? ''), ENT_QUOTES); ?>')"
                                                    class="ml-2 text-xs text-blue-600 hover:text-blue-800 underline" title="Send payment reminder">
                                                Remind
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex space-x-4">
                                            <label class="inline-flex items-center">
                                                <input type="radio" name="attendance[<?php echo $student['id']; ?>]"
                                                       value="present"
                                                       <?php echo $student['attendance_status'] === 'present' ? 'checked' : ''; ?>
                                                       class="text-blue-600">
                                                <span class="ml-2 text-sm text-green-700">Present</span>
                                            </label>
                                            <label class="inline-flex items-center">
                                                <input type="radio" name="attendance[<?php echo $student['id']; ?>]"
                                                       value="absent"
                                                       <?php echo $student['attendance_status'] === 'absent' ? 'checked' : ''; ?>
                                                       class="text-blue-600">
                                                <span class="ml-2 text-sm text-red-700">Absent</span>
                                            </label>
                                            <label class="inline-flex items-center">
                                                <input type="radio" name="attendance[<?php echo $student['id']; ?>]"
                                                       value="late"
                                                       <?php echo $student['attendance_status'] === 'late' ? 'checked' : ''; ?>
                                                       class="text-blue-600">
                                                <span class="ml-2 text-sm text-orange-700">Late</span>
                                            </label>
                                            <label class="inline-flex items-center">
                                                <input type="radio" name="attendance[<?php echo $student['id']; ?>]"
                                                       value="excused"
                                                       <?php echo $student['attendance_status'] === 'excused' ? 'checked' : ''; ?>
                                                       class="text-blue-600">
                                                <span class="ml-2 text-sm text-blue-700">Excused</span>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <input type="time"
                                               name="check_in_time[<?php echo $student['id']; ?>]"
                                               value="<?php echo $student['check_in_time'] ?? date('H:i'); ?>"
                                               class="px-3 py-1 border border-gray-300 rounded">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-6 flex justify-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg font-medium">
                        Save Attendance
                    </button>
                </div>
            </form>
        </div>
    <?php elseif ($selected_class && empty($enrolled_students)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
            <p class="text-lg">No students enrolled in this class</p>
            <a href="classes.php" class="text-blue-600 hover:text-blue-800 mt-2 inline-block">
                Manage Class Enrollments →
            </a>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
            <p class="text-lg">Select a date and class to mark attendance</p>
        </div>
    <?php endif; ?>
</div>

<!-- Payment Reminder Modal -->
<div id="reminderModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Send Payment Reminder</h3>
            <button onclick="closeReminderModal()" class="text-gray-600 hover:text-gray-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <div class="space-y-4">
            <div>
                <p class="text-sm text-gray-600">Student: <strong id="reminderStudentName"></strong></p>
                <p class="text-sm text-gray-600">Parent/Contact: <strong id="reminderParentName"></strong></p>
            </div>

            <div id="reminderContactOptions" class="space-y-3">
                <!-- Phone option -->
                <div id="reminderPhoneOption" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <div class="flex items-center gap-3">
                        <span id="reminderPhone" class="text-sm text-gray-800 font-mono"></span>
                        <a id="reminderPhoneLink" href="#" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium">
                            Call
                        </a>
                        <a id="reminderSmsLink" href="#" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                            Text
                        </a>
                    </div>
                </div>

                <!-- Email option -->
                <div id="reminderEmailOption" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <div class="flex items-center gap-3">
                        <span id="reminderEmail" class="text-sm text-gray-800 font-mono"></span>
                        <a id="reminderEmailLink" href="#" class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium">
                            Email Reminder
                        </a>
                    </div>
                </div>
            </div>

            <div class="bg-gray-50 rounded-lg p-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Suggested Message</label>
                <textarea id="reminderMessage" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-blue-500" readonly></textarea>
                <button onclick="copyReminderMessage()" class="mt-2 text-sm text-blue-600 hover:text-blue-800 underline">
                    Copy to clipboard
                </button>
            </div>

            <div class="flex justify-end">
                <button onclick="closeReminderModal()" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function showReminderModal(studentId, studentName, parentName, parentPhone, studentEmail) {
    document.getElementById('reminderStudentName').textContent = studentName;
    document.getElementById('reminderParentName').textContent = parentName || 'N/A';

    var siteName = <?php echo json_encode(getSiteName()); ?>;
    var message = 'Hi' + (parentName ? ' ' + parentName : '') + ', this is a friendly reminder from ' + siteName + ' that the tuition payment for ' + studentName + ' is currently outstanding. Please arrange payment at your earliest convenience. Thank you!';
    document.getElementById('reminderMessage').value = message;

    // Phone options
    var phoneSection = document.getElementById('reminderPhoneOption');
    if (parentPhone) {
        phoneSection.classList.remove('hidden');
        document.getElementById('reminderPhone').textContent = parentPhone;
        document.getElementById('reminderPhoneLink').href = 'tel:' + parentPhone;
        document.getElementById('reminderSmsLink').href = 'sms:' + parentPhone + '?body=' + encodeURIComponent(message);
    } else {
        phoneSection.classList.add('hidden');
    }

    // Email option
    var emailSection = document.getElementById('reminderEmailOption');
    if (studentEmail) {
        emailSection.classList.remove('hidden');
        document.getElementById('reminderEmail').textContent = studentEmail;
        document.getElementById('reminderEmailLink').href = 'mailto:' + studentEmail + '?subject=' + encodeURIComponent('Payment Reminder - ' + siteName) + '&body=' + encodeURIComponent(message);
    } else {
        emailSection.classList.add('hidden');
    }

    document.getElementById('reminderModal').classList.remove('hidden');
}

function closeReminderModal() {
    document.getElementById('reminderModal').classList.add('hidden');
}

function copyReminderMessage() {
    var msg = document.getElementById('reminderMessage');
    msg.select();
    msg.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(msg.value).then(function() {
        alert('Message copied to clipboard!');
    });
}
</script>

<?php include 'includes/footer.php'; ?>
