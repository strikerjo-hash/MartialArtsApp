<?php
require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';
require_once __DIR__ . '/includes/parent_auth.php';
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
    SELECT m.*, mp.name as plan_name, mp.price, mp.classes_per_week
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

// Ensure payment_methods table exists and has exp columns
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        label VARCHAR(100) DEFAULT NULL,
        card_brand VARCHAR(50) DEFAULT NULL,
        last_four VARCHAR(4) NOT NULL,
        exp_month TINYINT DEFAULT NULL,
        exp_year SMALLINT DEFAULT NULL,
        encrypted_token TEXT NOT NULL,
        is_default TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Add exp columns if missing (older installs)
    $cols = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'exp_month'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE payment_methods ADD COLUMN exp_month TINYINT DEFAULT NULL AFTER last_four");
        $pdo->exec("ALTER TABLE payment_methods ADD COLUMN exp_year SMALLINT DEFAULT NULL AFTER exp_month");
    }
} catch (\PDOException $e) {
    // Ignore — table ops may fail on some setups
}

// Get saved payment methods (masked)
$paymentMethods = [];
try {
    $pmStmt = $pdo->prepare(
        "SELECT id, label, card_brand, last_four, exp_month, exp_year, is_default, created_at
         FROM payment_methods WHERE student_id = ? ORDER BY is_default DESC, created_at DESC"
    );
    $pmStmt->execute([$student_id]);
    $paymentMethods = $pmStmt->fetchAll();
} catch (\PDOException $e) {
    // payment_methods table may not exist yet
}

// Get student credit balance
$studentCredit = get_student_credit($student_id);

// Get pending plan changes for this student
$pendingChanges = [];
try {
    $pcStmt = $pdo->prepare("
        SELECT pc.*, mp_new.name as new_plan_name, mp_new.price as new_plan_price, mp_new.duration_months as new_duration,
               mp_old.name as old_plan_name, u.full_name as requested_by_name
        FROM pending_plan_changes pc
        JOIN membership_plans mp_new ON pc.new_plan_id = mp_new.id
        LEFT JOIN membership_plans mp_old ON pc.old_plan_id = mp_old.id
        LEFT JOIN users u ON pc.requested_by = u.id
        WHERE pc.student_id = ?
        ORDER BY pc.created_at DESC LIMIT 10
    ");
    $pcStmt->execute([$student_id]);
    $pendingChanges = $pcStmt->fetchAll();
} catch (PDOException $e) {}

// Get active plans for the propose change dropdown
$activePlans = $pdo->query("SELECT * FROM membership_plans WHERE status = 'active' ORDER BY price ASC")->fetchAll();

// Get credit ledger history
$creditLedger = [];
try {
    $clStmt = $pdo->prepare(
        "SELECT * FROM credit_ledger WHERE student_id = ? ORDER BY created_at DESC LIMIT 20"
    );
    $clStmt->execute([$student_id]);
    $creditLedger = $clStmt->fetchAll();
} catch (\PDOException $e) {}

// Handle admin payment method actions
$pm_message = '';
$credit_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/includes/security.php';

    if (isset($_POST['admin_delete_pm'])) {
        verify_csrf();
        $methodId = (int) ($_POST['pm_id'] ?? 0);
        $del = $pdo->prepare('DELETE FROM payment_methods WHERE id = ? AND student_id = ?');
        $del->execute([$methodId, $student_id]);
        $pm_message = showAlert('Payment method removed.', 'success');
        // Refresh list
        $pmStmt->execute([$student_id]);
        $paymentMethods = $pmStmt->fetchAll();
    }

    if (isset($_POST['admin_set_default_pm'])) {
        verify_csrf();
        $methodId = (int) ($_POST['pm_id'] ?? 0);
        $pdo->prepare('UPDATE payment_methods SET is_default = 0 WHERE student_id = ?')->execute([$student_id]);
        $pdo->prepare('UPDATE payment_methods SET is_default = 1 WHERE id = ? AND student_id = ?')->execute([$methodId, $student_id]);
        $pm_message = showAlert('Default payment method updated.', 'success');
        // Refresh list
        $pmStmt->execute([$student_id]);
        $paymentMethods = $pmStmt->fetchAll();
    }

    if (isset($_POST['admin_add_pm'])) {
        verify_csrf();

        $stripePaymentMethodId = trim($_POST['stripe_pm_id'] ?? '');
        $label = trim($_POST['pm_label'] ?? '');

        if (empty($stripePaymentMethodId)) {
            $pm_message = showAlert('Card tokenization failed. Please try again.', 'error');
        } else {
            $result = save_card_from_token($student_id, $stripePaymentMethodId, $label);

            if ($result['success']) {
                $pm_message = showAlert('Payment method added for student.', 'success');
            } else {
                $pm_message = showAlert($result['error'] ?? 'Failed to save card.', 'error');
            }
            // Refresh list
            $pmStmt->execute([$student_id]);
            $paymentMethods = $pmStmt->fetchAll();
        }
    }

    // Admin propose plan change
    if (isset($_POST['admin_initiate_plan_change'])) {
        verify_csrf();
        $new_plan_id = (int) ($_POST['new_plan_id'] ?? 0);
        $change_notes = trim($_POST['change_notes'] ?? '');

        // Get current active membership for this student
        $curMem = $pdo->prepare("
            SELECT m.*, mp.name as plan_name, mp.price as plan_price, mp.duration_months, mp.billing_frequency
            FROM memberships m
            JOIN membership_plans mp ON m.plan_id = mp.id
            WHERE m.student_id = ? AND m.status = 'active' AND m.end_date >= CURDATE()
            ORDER BY m.end_date DESC LIMIT 1
        ");
        $curMem->execute([$student_id]);
        $curMemRow = $curMem->fetch() ?: null;

        // Get new plan
        $newPlanStmt = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?");
        $newPlanStmt->execute([$new_plan_id]);
        $newPlanRow = $newPlanStmt->fetch();

        if (!$newPlanRow) {
            $credit_message = showAlert('Invalid plan selected.', 'error');
        } elseif ($curMemRow && $curMemRow['plan_id'] == $new_plan_id) {
            $credit_message = showAlert('Student is already on this plan.', 'error');
        } else {
            // Check if there's already a pending change
            $existingPending = $pdo->prepare("SELECT COUNT(*) FROM pending_plan_changes WHERE student_id = ? AND status = 'pending' AND expires_at > NOW()");
            $existingPending->execute([$student_id]);
            if ($existingPending->fetchColumn() > 0) {
                $credit_message = showAlert('There is already a pending plan change for this student. Please wait for them to respond or expire it.', 'error');
            } else {
                // Calculate proration
                $proration = calculateProration($curMemRow, $newPlanRow);
                $adminUserId = $_SESSION['user_id'];

                $ins = $pdo->prepare("
                    INSERT INTO pending_plan_changes (student_id, new_plan_id, old_plan_id, old_membership_id, requested_by, status, proration_amount, proration_credit, proration_type, proration_data, notes, expires_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
                ");
                $ins->execute([
                    $student_id,
                    $new_plan_id,
                    $curMemRow ? $curMemRow['plan_id'] : null,
                    $curMemRow ? $curMemRow['id'] : null,
                    $adminUserId,
                    $proration['amount'],
                    $proration['credit'],
                    $proration['type'],
                    json_encode($proration),
                    $change_notes ?: null,
                ]);

                $credit_message = showAlert('Plan change proposed! The student must approve this on their portal. It will expire in 7 days if not acted upon.', 'success');
            }
        }
    }

    // Admin credit adjustment
    if (isset($_POST['admin_adjust_credit'])) {
        verify_csrf();
        $adjustAmount = (float) ($_POST['credit_amount'] ?? 0);
        $adjustReason = trim($_POST['credit_reason'] ?? '');

        if ($adjustAmount == 0) {
            $credit_message = showAlert('Please enter a non-zero amount.', 'error');
        } elseif (empty($adjustReason)) {
            $credit_message = showAlert('Please provide a reason for the adjustment.', 'error');
        } else {
            if ($adjustAmount > 0) {
                $newBalance = add_student_credit($student_id, $adjustAmount, 'Admin adjustment: ' . $adjustReason, 'admin_adjustment');
                $credit_message = showAlert('Added ' . formatMoney($adjustAmount) . ' credit. New balance: ' . formatMoney($newBalance), 'success');
            } else {
                // Deduction — use_student_credit takes a positive amount
                $deductAmount = abs($adjustAmount);
                $result = use_student_credit($student_id, $deductAmount, 'Admin adjustment: ' . $adjustReason, 'admin_adjustment');
                if ($result['used'] > 0) {
                    $credit_message = showAlert('Deducted ' . formatMoney($result['used']) . ' credit. New balance: ' . formatMoney($result['new_balance']), 'success');
                } else {
                    $credit_message = showAlert('No credit available to deduct.', 'error');
                }
            }
            // Refresh credit balance and ledger
            $studentCredit = get_student_credit($student_id);
            $clStmt->execute([$student_id]);
            $creditLedger = $clStmt->fetchAll();
        }
    }

    // Admin clear payment lockout
    if (isset($_POST['admin_clear_payment_lockout'])) {
        verify_csrf();
        $overrideReason = trim($_POST['override_reason'] ?? '');

        $pdo->prepare("
            UPDATE students
            SET payment_lockout_override = 1,
                lockout_override_at = NOW(),
                lockout_override_by = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $student_id]);

        // Optionally mark the active membership as paid (in-person payment)
        if (isset($_POST['mark_as_paid'])) {
            $pdo->prepare("
                UPDATE memberships
                SET payment_status = 'paid'
                WHERE student_id = ? AND status = 'active'
            ")->execute([$student_id]);

            // Record the manual payment
            $pdo->prepare("
                INSERT INTO payments (student_id, payment_type, amount, payment_method, payment_date, receipt_number, notes)
                VALUES (?, 'membership', 0, 'cash', CURDATE(), ?, ?)
            ")->execute([
                $student_id,
                generateReceiptNumber(),
                'Admin payment lockout override' . ($overrideReason ? ': ' . $overrideReason : '') . ' — by ' . ($_SESSION['full_name'] ?? 'Admin')
            ]);
        }

        // Audit trail via renewal_log
        try {
            $pdo->prepare("
                INSERT INTO renewal_log (membership_id, student_id, action, notes, created_at)
                VALUES (0, ?, 'renewed', ?, NOW())
            ")->execute([
                $student_id,
                'Admin payment lockout override by ' . ($_SESSION['full_name'] ?? 'Admin') . ($overrideReason ? ': ' . $overrideReason : '')
            ]);
        } catch (\PDOException $e) {}

        $pm_message = showAlert('Payment lockout cleared for this student.', 'success');
        // Refresh student data
        $stmt->execute([$student_id]);
        $student = $stmt->fetch();
    }

    // Admin restore payment lockout (undo override)
    if (isset($_POST['admin_restore_payment_lockout'])) {
        verify_csrf();
        $pdo->prepare("
            UPDATE students
            SET payment_lockout_override = 0, lockout_override_at = NULL, lockout_override_by = NULL
            WHERE id = ?
        ")->execute([$student_id]);

        $pm_message = showAlert('Payment lockout override removed. Student will be locked out if payment is still due.', 'info');
        // Refresh student data
        $stmt->execute([$student_id]);
        $student = $stmt->fetch();
    }

    // Link student to parent account
    if (isset($_POST['link_to_parent'])) {
        verify_csrf();
        $parentIdentifier = trim($_POST['parent_identifier'] ?? '');
        $relationship = $_POST['relationship'] ?? 'parent';

        if ($parentIdentifier !== '') {
            // Search promoted parent-students first (new model: students with is_parent=1)
            $findParent = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE is_parent = 1 AND (username = :u1 OR email = :u2) AND status = 'active' LIMIT 1");
            $findParent->execute([':u1' => $parentIdentifier, ':u2' => $parentIdentifier]);
            $foundParent = $findParent->fetch();

            // Fall back to legacy parents table
            if (!$foundParent) {
                try {
                    $findLegacy = $pdo->prepare("SELECT id, first_name, last_name FROM parents WHERE (username = :u1 OR email = :u2) AND status = 'active' LIMIT 1");
                    $findLegacy->execute([':u1' => $parentIdentifier, ':u2' => $parentIdentifier]);
                    $foundParent = $findLegacy->fetch();
                } catch (\PDOException $e) {}
            }

            if ($foundParent) {
                $linked = link_student_to_parent($foundParent['id'], $student_id, $relationship);
                if ($linked) {
                    $parentFullName = htmlspecialchars($foundParent['first_name'] . ' ' . $foundParent['last_name']);
                    $pm_message = showAlert('Student linked to parent: ' . $parentFullName . '. Parent\'s payment methods have been copied to the student.', 'success');
                } else {
                    $pm_message = showAlert('Student is already linked to this parent.', 'error');
                }
            } else {
                $pm_message = showAlert('No active parent account found with that username or email.', 'error');
            }
        }
    }

    // Unlink student from parent
    if (isset($_POST['unlink_from_parent'])) {
        verify_csrf();
        $unlinkParentId = (int)($_POST['parent_id'] ?? 0);
        if ($unlinkParentId) {
            unlink_student_from_parent($unlinkParentId, $student_id);
            $pm_message = showAlert('Student unlinked from parent account.', 'success');
        }
    }

    // Promote this student to a parent account (sets is_parent=1 on their student row)
    if (isset($_POST['promote_to_parent'])) {
        verify_csrf();

        $existingCheck = student_has_parent_account($student_id);
        if ($existingCheck) {
            $pm_message = showAlert(
                'This student already has parent capabilities enabled.',
                'error'
            );
        } else {
            $promoted = promote_student_to_parent($student_id);
            if ($promoted) {
                $studentName = htmlspecialchars($promoted['first_name'] . ' ' . $promoted['last_name']);
                $pm_message = showAlert(
                    'Parent capabilities enabled for ' . $studentName . '. ' .
                    'They can now manage their children from their student portal. Use the link section below to add children to their account.',
                    'success'
                );
            } else {
                $pm_message = showAlert('Failed to enable parent capabilities. Please try again.', 'error');
            }
        }
    }
}

// Fetch linked parent accounts for this student
$linkedParents = get_student_parents($student_id);

// === COMPLIANCE CHECK ===
$compliance_warnings = [];

// 1. Check for active membership
$active_mem = $pdo->prepare("
    SELECT m.id, mp.name as plan_name, mp.classes_per_week, m.end_date
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.student_id = ? AND m.status = 'active' AND m.end_date >= CURDATE()
    ORDER BY mp.classes_per_week DESC
    LIMIT 1
");
$active_mem->execute([$student_id]);
$active_membership = $active_mem->fetch();

$active_enrollment_count = count($enrolled_classes);

if (!$active_membership && $active_enrollment_count > 0) {
    $compliance_warnings[] = [
        'type' => 'error',
        'icon' => '&#9888;',
        'title' => 'No Active Membership',
        'message' => 'This student is enrolled in ' . $active_enrollment_count . ' class(es) but does not have an active membership.'
    ];
} elseif (!$active_membership && $active_enrollment_count === 0) {
    $compliance_warnings[] = [
        'type' => 'warning',
        'icon' => '&#9888;',
        'title' => 'No Active Membership',
        'message' => 'This student does not have an active membership. They cannot be enrolled in classes.'
    ];
}

// 2. Check class enrollment limit
if ($active_membership) {
    $allowed = (int)$active_membership['classes_per_week'];
    if ($allowed < 99 && $active_enrollment_count > $allowed) {
        $compliance_warnings[] = [
            'type' => 'error',
            'icon' => '&#128680;',
            'title' => 'Over Enrollment Limit',
            'message' => 'This student is enrolled in ' . $active_enrollment_count . ' class(es) but their plan ('
                . htmlspecialchars($active_membership['plan_name']) . ') only allows ' . $allowed . ' classes per week.'
        ];
    }

    // 3. Check membership expiring soon
    $days_until_expiry = (strtotime($active_membership['end_date']) - time()) / 86400;
    if ($days_until_expiry <= 7 && $days_until_expiry > 0) {
        $compliance_warnings[] = [
            'type' => 'warning',
            'icon' => '&#9200;',
            'title' => 'Membership Expiring Soon',
            'message' => 'Membership expires in ' . ceil($days_until_expiry) . ' day(s) on ' . date('M j, Y', strtotime($active_membership['end_date'])) . '.'
        ];
    }
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="mb-6">
        <a href="students.php" class="text-blue-600 hover:text-blue-800">&larr; Back to Students</a>
    </div>

    <!-- Compliance Warnings -->
    <?php if (!empty($compliance_warnings)): ?>
        <div class="mb-6 space-y-3">
            <?php foreach ($compliance_warnings as $warn): ?>
                <?php
                    $bgColor = $warn['type'] === 'error' ? 'bg-red-50 border-red-500 text-red-800' : 'bg-yellow-50 border-yellow-500 text-yellow-800';
                    $iconBg = $warn['type'] === 'error' ? 'bg-red-100' : 'bg-yellow-100';
                ?>
                <div class="border-l-4 rounded-r-lg p-4 <?php echo $bgColor; ?>">
                    <div class="flex items-start space-x-3">
                        <span class="text-xl flex-shrink-0"><?php echo $warn['icon']; ?></span>
                        <div>
                            <h4 class="font-semibold"><?php echo $warn['title']; ?></h4>
                            <p class="text-sm mt-1"><?php echo $warn['message']; ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

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
                    <div class="mt-2 flex items-center space-x-2">
                        <span class="px-3 py-1 text-sm font-semibold rounded-full <?php
                            $colors = ['active' => 'bg-green-100 text-green-800', 'inactive' => 'bg-gray-100 text-gray-800', 'suspended' => 'bg-red-100 text-red-800'];
                            echo $colors[$student['status']];
                        ?>">
                            <?php echo ucfirst($student['status']); ?>
                        </span>
                        <?php if ($active_membership): ?>
                            <span class="px-3 py-1 text-sm font-semibold rounded-full bg-blue-100 text-blue-800">
                                <?php echo htmlspecialchars($active_membership['plan_name']); ?>
                                <?php if ((int)$active_membership['classes_per_week'] < 99): ?>
                                    (<?php echo $active_enrollment_count; ?>/<?php echo $active_membership['classes_per_week']; ?> classes)
                                <?php else: ?>
                                    (Unlimited)
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="px-3 py-1 text-sm font-semibold rounded-full bg-red-100 text-red-800">No Membership</span>
                        <?php endif; ?>
                        <?php if ($studentCredit > 0): ?>
                            <span class="px-3 py-1 text-sm font-semibold rounded-full bg-green-100 text-green-800">
                                &#128176; Credit: <?= formatMoney($studentCredit) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <?php if (!empty($belt_history)): ?>
                <a href="student_certificate.php?student_id=<?php echo $student_id; ?>"
                   class="bg-amber-600 hover:bg-amber-700 text-white px-5 py-2 rounded-lg font-medium text-sm"
                   target="_blank">
                    &#128220; View Certificate
                </a>
                <?php endif; ?>
                <a href="student_edit.php?id=<?php echo $student_id; ?>"
                   class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                    Edit Student
                </a>
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
                        <?php foreach ($belt_history as $bIdx => $belt): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div>
                                    <p class="font-semibold"><?php echo $belt['belt_name']; ?></p>
                                    <p class="text-sm text-gray-600"><?php echo $belt['style_name']; ?></p>
                                </div>
                                <div class="text-right flex items-center gap-3">
                                    <a href="student_certificate.php?student_id=<?php echo $student_id; ?>&belt_index=<?php echo $bIdx; ?>"
                                       target="_blank"
                                       class="text-xs text-amber-600 hover:text-amber-800 font-medium">&#128220; Certificate</a>
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
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h2 class="text-xl font-semibold">Enrolled Classes</h2>
                <?php if ($active_membership && (int)$active_membership['classes_per_week'] < 99): ?>
                    <span class="text-sm text-gray-500"><?php echo $active_enrollment_count; ?> / <?php echo $active_membership['classes_per_week']; ?> allowed</span>
                <?php endif; ?>
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
                                    <?php echo $class['day_of_week']; ?> &bull;
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
                                        <p class="text-xs text-gray-500">
                                            <?php echo (int)$membership['classes_per_week'] >= 99 ? 'Unlimited classes' : $membership['classes_per_week'] . ' classes/week'; ?>
                                            &bull; Auto-renew: <?php echo (isset($membership['auto_renew']) && $membership['auto_renew']) ? 'ON' : 'OFF'; ?>
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

        <!-- Pending Plan Changes & Propose Change -->
        <div class="bg-white rounded-lg shadow lg:col-span-2">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h2 class="text-xl font-semibold">Plan Changes</h2>
                <button onclick="document.getElementById('proposePlanChangeModal').classList.remove('hidden')"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                    Propose Plan Change
                </button>
            </div>
            <div class="p-6">
                <?php
                $activePending = array_filter($pendingChanges, fn($pc) => $pc['status'] === 'pending');
                ?>
                <?php if (!empty($activePending)): ?>
                    <div class="mb-4">
                        <h3 class="text-sm font-semibold text-orange-700 mb-2">Awaiting Student Confirmation</h3>
                        <?php foreach ($activePending as $pc): ?>
                            <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 mb-2">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="font-semibold text-gray-800">
                                            <?php echo htmlspecialchars($pc['old_plan_name'] ?? 'No Plan'); ?>
                                            &#8594;
                                            <?php echo htmlspecialchars($pc['new_plan_name']); ?>
                                        </p>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <?php if ($pc['proration_type'] === 'upgrade'): ?>
                                                Upgrade cost: <strong><?php echo formatMoney($pc['proration_amount']); ?></strong>
                                            <?php elseif ($pc['proration_type'] === 'downgrade'): ?>
                                                Downgrade credit: <strong><?php echo formatMoney($pc['proration_credit']); ?></strong>
                                            <?php else: ?>
                                                New enrollment: <strong><?php echo formatMoney($pc['proration_amount']); ?></strong>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($pc['notes']): ?>
                                            <p class="text-xs text-gray-500 mt-1">Note: <?php echo htmlspecialchars($pc['notes']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right text-xs text-gray-500">
                                        <p>Proposed by: <?php echo htmlspecialchars($pc['requested_by_name'] ?? 'Admin'); ?></p>
                                        <p>Expires: <?php echo date('M j, Y', strtotime($pc['expires_at'])); ?></p>
                                        <span class="inline-block mt-1 px-2 py-0.5 rounded-full bg-orange-100 text-orange-800 font-semibold">Pending</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php
                $resolvedChanges = array_filter($pendingChanges, fn($pc) => $pc['status'] !== 'pending');
                ?>
                <?php if (!empty($resolvedChanges)): ?>
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Change History</h3>
                    <div class="space-y-2">
                        <?php foreach ($resolvedChanges as $pc): ?>
                            <div class="p-3 bg-gray-50 rounded-lg flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-800">
                                        <?php echo htmlspecialchars($pc['old_plan_name'] ?? 'No Plan'); ?>
                                        &#8594; <?php echo htmlspecialchars($pc['new_plan_name']); ?>
                                    </p>
                                    <p class="text-xs text-gray-500"><?php echo date('M j, Y', strtotime($pc['created_at'])); ?></p>
                                </div>
                                <?php
                                $statusStyles = [
                                    'approved' => 'bg-green-100 text-green-800',
                                    'rejected' => 'bg-red-100 text-red-800',
                                    'expired'  => 'bg-gray-100 text-gray-600',
                                ];
                                $cls = $statusStyles[$pc['status']] ?? 'bg-gray-100 text-gray-800';
                                ?>
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?php echo $cls; ?>">
                                    <?php echo ucfirst($pc['status']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (empty($activePending)): ?>
                    <p class="text-gray-500 text-center py-4">No plan change history.</p>
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
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php if ($payment['payment_method'] === 'account_credit'): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-100 text-green-800 text-xs font-semibold">&#128176; Account Credit</span>
                                <?php else: ?>
                                    <span class="capitalize"><?php echo str_replace('_', ' ', $payment['payment_method']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold <?php echo $payment['amount'] < 0 ? 'text-red-600' : 'text-green-600'; ?>"><?php echo formatMoney($payment['amount']); ?></td>
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

    <!-- Account Credit Balance & Management -->
    <div class="bg-white rounded-lg shadow mt-6">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h2 class="text-xl font-semibold">Account Credit</h2>
            <div class="flex items-center gap-3">
                <span class="text-2xl font-bold <?= $studentCredit > 0 ? 'text-green-600' : 'text-gray-400' ?>">
                    <?= formatMoney($studentCredit) ?>
                </span>
                <button onclick="document.getElementById('adjustCreditModal').classList.remove('hidden')"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                    Adjust Credit
                </button>
            </div>
        </div>
        <div class="p-6">
            <?php echo $credit_message; ?>

            <?php if ($studentCredit > 0): ?>
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">&#128176;</span>
                        <div>
                            <p class="font-semibold text-green-800">Student has <?= formatMoney($studentCredit) ?> in account credit</p>
                            <p class="text-sm text-green-600">This amount will be automatically applied to their next payment.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($creditLedger)): ?>
                <p class="text-gray-500 text-center py-4">No credit history.</p>
            <?php else: ?>
                <h3 class="font-semibold text-gray-700 mb-3">Credit History</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($creditLedger as $entry): ?>
                                <tr>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-600">
                                        <?= date('M j, Y g:i A', strtotime($entry['created_at'])) ?>
                                    </td>
                                    <td class="px-4 py-2 text-sm text-gray-800">
                                        <?= htmlspecialchars($entry['description']) ?>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-xs">
                                        <?php
                                        $typeLabels = [
                                            'downgrade' => ['Downgrade Credit', 'bg-blue-100 text-blue-800'],
                                            'admin_adjustment' => ['Admin Adjustment', 'bg-purple-100 text-purple-800'],
                                            'payment_offset' => ['Payment Applied', 'bg-orange-100 text-orange-800'],
                                            'refund' => ['Refund', 'bg-yellow-100 text-yellow-800'],
                                        ];
                                        $refType = $entry['reference_type'] ?? '';
                                        $label = $typeLabels[$refType] ?? [ucfirst(str_replace('_', ' ', $refType ?: 'Other')), 'bg-gray-100 text-gray-800'];
                                        ?>
                                        <span class="px-2 py-0.5 rounded-full font-semibold <?= $label[1] ?>"><?= $label[0] ?></span>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-right font-semibold <?= $entry['amount'] > 0 ? 'text-green-600' : 'text-red-600' ?>">
                                        <?= $entry['amount'] > 0 ? '+' : '' ?><?= formatMoney($entry['amount']) ?>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-right font-mono text-gray-700">
                                        <?= formatMoney($entry['balance_after']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Adjust Credit Modal -->
    <div id="adjustCreditModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
        <div class="bg-white rounded-lg shadow-xl p-8 max-w-md w-full mx-4">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-gray-800">Adjust Account Credit</h3>
                <button onclick="document.getElementById('adjustCreditModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>

            <p class="text-sm text-gray-600 mb-4">
                Current balance: <strong class="text-green-700"><?= formatMoney($studentCredit) ?></strong>
            </p>

            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="admin_adjust_credit" value="1">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                    <input type="number" name="credit_amount" step="0.01" required placeholder="Enter amount (positive to add, negative to deduct)"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Use positive values to add credit, negative to deduct.</p>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reason</label>
                    <input type="text" name="credit_reason" required placeholder="e.g. Refund for cancelled class, Promotional credit"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" onclick="document.getElementById('adjustCreditModal').classList.add('hidden')"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                        Apply Adjustment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Payment Lockout Status -->
    <?php
    $lockoutMem = $pdo->prepare("
        SELECT payment_status FROM memberships
        WHERE student_id = ? AND status = 'active'
        ORDER BY end_date DESC LIMIT 1
    ");
    $lockoutMem->execute([$student_id]);
    $lockoutMemRow = $lockoutMem->fetch();
    $hasPaymentIssue = $lockoutMemRow && in_array($lockoutMemRow['payment_status'], ['declined', 'pending', 'partial']);
    $hasOverride = (int) ($student['payment_lockout_override'] ?? 0) === 1;
    $isEffectivelyLocked = $hasPaymentIssue && !$hasOverride;

    // Also check renewal_log for recent failures
    $recentFailures = 0;
    try {
        $failChk = $pdo->prepare("SELECT COUNT(*) FROM renewal_log WHERE student_id = ? AND action = 'payment_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $failChk->execute([$student_id]);
        $recentFailures = (int) $failChk->fetchColumn();
    } catch (\PDOException $e) {}
    if ($recentFailures > 0 && !$hasOverride) $isEffectivelyLocked = true;
    ?>
    <div class="bg-white rounded-lg shadow mt-6">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h2 class="text-xl font-semibold">Payment Lockout Status</h2>
            <?php if ($isEffectivelyLocked): ?>
                <span class="px-3 py-1 text-sm font-semibold rounded-full bg-red-100 text-red-800">LOCKED OUT</span>
            <?php elseif ($hasPaymentIssue && $hasOverride): ?>
                <span class="px-3 py-1 text-sm font-semibold rounded-full bg-blue-100 text-blue-800">Override Active</span>
            <?php else: ?>
                <span class="px-3 py-1 text-sm font-semibold rounded-full bg-green-100 text-green-800">No Lockout</span>
            <?php endif; ?>
        </div>
        <div class="p-6">
            <?php if ($hasPaymentIssue || $recentFailures > 0): ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                    <p class="text-sm text-yellow-800">
                        <?php if ($hasPaymentIssue): ?>
                            <strong>Membership Payment Status:</strong> <?php echo ucfirst($lockoutMemRow['payment_status']); ?>
                        <?php endif; ?>
                        <?php if ($recentFailures > 0): ?>
                            <?php if ($hasPaymentIssue): ?> &mdash; <?php endif; ?>
                            <strong><?php echo $recentFailures; ?> failed payment attempt<?php echo $recentFailures > 1 ? 's' : ''; ?></strong> in the last 30 days
                        <?php endif; ?>
                        <?php if ($hasOverride): ?>
                            <span class="ml-2 px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold">Admin Override Active</span>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (($hasPaymentIssue || $recentFailures > 0) && !$hasOverride): ?>
                <!-- Clear lockout form -->
                <form method="POST" class="space-y-3">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="admin_clear_payment_lockout" value="1">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="mark_as_paid" value="1" class="rounded border-gray-300 text-green-600">
                        Also mark active membership as <strong>Paid</strong> (e.g., payment received in person)
                    </label>
                    <input type="text" name="override_reason" placeholder="Reason (optional, e.g., 'Paid cash in studio')"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <button type="submit" onclick="return confirm('Clear payment lockout for this student?')"
                            class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg text-sm font-medium">
                        Clear Payment Lockout
                    </button>
                </form>
            <?php elseif ($hasOverride): ?>
                <!-- Remove override form -->
                <form method="POST" class="space-y-3">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="admin_restore_payment_lockout" value="1">
                    <p class="text-sm text-gray-600">
                        Override was set
                        <?php if (!empty($student['lockout_override_at'])): ?>
                            on <?php echo date('M j, Y g:i A', strtotime($student['lockout_override_at'])); ?>
                        <?php endif; ?>
                        <?php if (!empty($student['lockout_override_by'])):
                            $overrideAdmin = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                            $overrideAdmin->execute([$student['lockout_override_by']]);
                            $overrideAdminRow = $overrideAdmin->fetch();
                            if ($overrideAdminRow): ?>
                                by <?php echo htmlspecialchars($overrideAdminRow['full_name']); ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                    <button type="submit" onclick="return confirm('Remove the override? Student will be locked out again if payment is still due.')"
                            class="bg-red-500 hover:bg-red-600 text-white px-6 py-2 rounded-lg text-sm font-medium">
                        Remove Override
                    </button>
                </form>
            <?php else: ?>
                <p class="text-gray-500 text-sm">No payment issues detected. Student has full portal access.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Methods (Admin Management) -->
    <div class="bg-white rounded-lg shadow mt-6">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h2 class="text-xl font-semibold">Payment Methods</h2>
            <button onclick="document.getElementById('addPmModal').classList.remove('hidden')"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                Add Card
            </button>
        </div>
        <div class="p-6">
            <?php echo $pm_message; ?>

            <?php if (empty($paymentMethods)): ?>
                <p class="text-gray-500 text-center py-8">No saved payment methods.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($paymentMethods as $pm): ?>
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg <?= $pm['is_default'] ? 'ring-2 ring-blue-300' : '' ?>">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-8 bg-gray-200 rounded flex items-center justify-center text-xs font-bold uppercase text-gray-600">
                                    <?= htmlspecialchars($pm['card_brand'] ?: 'Card') ?>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($pm['label'] ?: 'Card') ?></p>
                                    <p class="text-sm text-gray-500">
                                        <span class="tracking-widest">&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;</span>
                                        <span class="font-mono font-semibold ml-1"><?= htmlspecialchars($pm['last_four']) ?></span>
                                        <?php if ($pm['exp_month'] && $pm['exp_year']): ?>
                                            <span class="ml-3 text-gray-400">Exp <?= str_pad($pm['exp_month'], 2, '0', STR_PAD_LEFT) ?>/<?= $pm['exp_year'] ?></span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php if ($pm['is_default']): ?>
                                    <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-semibold">Default</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-2">
                                <?php if (!$pm['is_default']): ?>
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="admin_set_default_pm" value="1">
                                        <input type="hidden" name="pm_id" value="<?= $pm['id'] ?>">
                                        <button type="submit" class="text-blue-600 hover:text-blue-800 text-sm">Set Default</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Remove this payment method?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="admin_delete_pm" value="1">
                                    <input type="hidden" name="pm_id" value="<?= $pm['id'] ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700 text-sm">Remove</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Propose Plan Change Modal -->
<div id="proposePlanChangeModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl p-8 max-w-lg w-full mx-4">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-bold text-gray-800">Propose Plan Change</h3>
            <button onclick="document.getElementById('proposePlanChangeModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>

        <p class="text-sm text-gray-600 mb-4">
            Select a new plan for <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong>.
            The student will need to approve this change on their portal.
        </p>

        <?php if ($active_membership): ?>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4 text-sm">
                <p class="font-semibold text-blue-800">Current Plan: <?php echo htmlspecialchars($active_membership['plan_name']); ?></p>
            </div>
        <?php else: ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-4 text-sm">
                <p class="text-yellow-800">Student has no active membership.</p>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="admin_initiate_plan_change" value="1">

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">New Plan *</label>
                <select name="new_plan_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">Choose a plan...</option>
                    <?php foreach ($activePlans as $ap): ?>
                        <?php
                        $isCurrent = $active_membership && $active_membership['plan_id'] == $ap['id'];
                        $isMonthly = (isset($ap['billing_frequency']) && $ap['billing_frequency'] === 'monthly' && $ap['duration_months'] > 1);
                        $displayPrice = $isMonthly
                            ? formatMoney($ap['price'] / $ap['duration_months']) . '/mo'
                            : formatMoney($ap['price']);
                        ?>
                        <option value="<?php echo $ap['id']; ?>" <?php echo $isCurrent ? 'disabled' : ''; ?>>
                            <?php echo htmlspecialchars($ap['name']); ?> — <?php echo $displayPrice; ?> (<?php echo $ap['duration_months']; ?>mo)
                            <?php echo $isCurrent ? ' (Current)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                <textarea name="change_notes" rows="2" placeholder="Reason for the change..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"></textarea>
            </div>

            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 mb-4 text-xs text-gray-600">
                <p>&#9432; The student will receive a notification on their portal. They have 7 days to approve or decline. Proration will be recalculated at the time of approval.</p>
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('proposePlanChangeModal').classList.add('hidden')"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                    Propose Change
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Payment Method Modal -->
<?php
    $adminGw = get_active_gateway();
    $adminGwReady = is_gateway_ready();
    $adminStripePk = ($adminGw === 'stripe') ? getSetting('stripe_publishable_key') : '';
?>
<div id="addPmModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl p-8 max-w-lg w-full mx-4">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-bold text-gray-800">Add Payment Method</h3>
            <button onclick="document.getElementById('addPmModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>

        <?php if ($adminGw === 'stripe' && $adminGwReady && $adminStripePk): ?>
            <p class="text-sm text-gray-500 mb-4">Card details are handled securely by Stripe. The full card number never reaches this server.</p>

            <form id="admin-stripe-form" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="admin_add_pm" value="1">
                <input type="hidden" name="stripe_pm_id" id="admin_stripe_pm_id" value="">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Card Details</label>
                    <div id="admin-card-element" class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-white" style="min-height: 44px;">
                        <!-- Stripe Elements injects the card input here -->
                    </div>
                    <div id="admin-card-errors" class="text-red-600 text-sm mt-2"></div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Card Nickname (optional)</label>
                    <input type="text" name="pm_label" placeholder="e.g. Mom's Visa" autocomplete="off"
                           class="w-full max-w-xs px-3 py-2 border border-gray-300 rounded-lg">
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" onclick="document.getElementById('addPmModal').classList.add('hidden')"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="submit" id="admin-submit-btn" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium disabled:opacity-50">
                        <span id="admin-btn-text">Add Card</span>
                        <span id="admin-btn-spinner" class="hidden">
                            <svg class="animate-spin inline-block w-5 h-5 ml-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Processing...
                        </span>
                    </button>
                </div>
            </form>
        <?php else: ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <p class="text-sm text-yellow-800">
                    A payment gateway (Stripe or Square) must be configured in Settings before cards can be added.
                </p>
            </div>
            <div class="flex justify-end mt-4">
                <button type="button" onclick="document.getElementById('addPmModal').classList.add('hidden')"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Close</button>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($adminGw === 'stripe' && $adminGwReady && $adminStripePk): ?>
<!-- Stripe.js for admin card modal -->
<script src="https://js.stripe.com/v3/"></script>
<script>
(function() {
    const stripe = Stripe('<?= htmlspecialchars($adminStripePk) ?>');
    const elements = stripe.elements();
    let cardElement = null;
    let mounted = false;

    // Mount card element when modal opens
    const modal = document.getElementById('addPmModal');
    const observer = new MutationObserver(function() {
        if (!modal.classList.contains('hidden') && !mounted) {
            cardElement = elements.create('card', {
                style: {
                    base: {
                        fontSize: '16px',
                        color: '#1f2937',
                        fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                        '::placeholder': { color: '#9ca3af' }
                    },
                    invalid: { color: '#dc2626', iconColor: '#dc2626' }
                }
            });
            cardElement.mount('#admin-card-element');
            cardElement.on('change', function(event) {
                document.getElementById('admin-card-errors').textContent = event.error ? event.error.message : '';
            });
            mounted = true;
        }
    });
    observer.observe(modal, { attributes: true, attributeFilter: ['class'] });

    // Handle form submit
    const form = document.getElementById('admin-stripe-form');
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('admin-submit-btn');
        const btnText = document.getElementById('admin-btn-text');
        const btnSpinner = document.getElementById('admin-btn-spinner');
        btn.disabled = true;
        btnText.classList.add('hidden');
        btnSpinner.classList.remove('hidden');

        const { paymentMethod, error } = await stripe.createPaymentMethod({
            type: 'card',
            card: cardElement,
        });

        if (error) {
            document.getElementById('admin-card-errors').textContent = error.message;
            btn.disabled = false;
            btnText.classList.remove('hidden');
            btnSpinner.classList.add('hidden');
            return;
        }

        document.getElementById('admin_stripe_pm_id').value = paymentMethod.id;
        form.submit();
    });
})();
</script>
<?php endif; ?>

<!-- Parent Account Section -->
<?php $existingParentAcct = student_has_parent_account($student_id); ?>
<div class="container mx-auto px-4 pb-8">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">👨‍👩‍👧‍👦 Parent / Family Accounts</h2>
            <div class="flex gap-2">
                <?php if ($existingParentAcct): ?>
                    <span class="inline-flex items-center gap-1.5 px-4 py-2 bg-green-100 text-green-800 rounded-lg text-sm font-medium">
                        ✓ Parent Account Enabled
                    </span>
                <?php else: ?>
                    <form method="POST" class="inline" onsubmit="return confirm('Enable parent capabilities for this student? They will be able to manage linked children from their student portal.')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="promote_to_parent" value="1">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                            ⬆ Enable Parent Account
                        </button>
                    </form>
                <?php endif; ?>
                <button onclick="document.getElementById('linkParentModal').classList.remove('hidden')"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                    + Link to Parent
                </button>
            </div>
        </div>

        <?php if (empty($linkedParents)): ?>
            <div class="p-8 text-center text-gray-500">
                <p>No parent accounts linked to this student.</p>
                <p class="text-sm mt-1">Link a parent account to allow family management of this student.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-gray-200">
                <?php foreach ($linkedParents as $lp): ?>
                    <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                                <span class="text-blue-600 font-bold text-sm"><?= strtoupper(substr($lp['first_name'], 0, 1) . substr($lp['last_name'], 0, 1)) ?></span>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800">
                                    <?= htmlspecialchars($lp['first_name'] . ' ' . $lp['last_name']) ?>
                                    <span class="text-xs text-gray-500 ml-1">(<?= htmlspecialchars($lp['username']) ?>)</span>
                                </p>
                                <p class="text-sm text-gray-500">
                                    <span class="capitalize"><?= htmlspecialchars($lp['relationship']) ?></span>
                                    <?php if ($lp['email']): ?>
                                        &bull; <?= htmlspecialchars($lp['email']) ?>
                                    <?php endif; ?>
                                    <?php if ($lp['phone']): ?>
                                        &bull; <?= htmlspecialchars($lp['phone']) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <form method="POST" class="inline" onsubmit="return confirm('Unlink this parent from the student?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="unlink_from_parent" value="1">
                            <input type="hidden" name="parent_id" value="<?= $lp['id'] ?>">
                            <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-medium">Unlink</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Link Parent Modal -->
<div id="linkParentModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Link to Parent Account</h3>
            <button onclick="document.getElementById('linkParentModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <p class="text-sm text-gray-600 mb-4">Enter the parent's username or email to link this student to their family account.</p>

        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="link_to_parent" value="1">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Parent Username or Email *</label>
                <input type="text" name="parent_identifier" required
                       placeholder="e.g., parent_username or parent@email.com"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Relationship</label>
                <select name="relationship"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="parent">Parent</option>
                    <option value="guardian">Guardian</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('linkParentModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Link Parent
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Promote modal removed — parent account is now a simple is_parent flag toggle -->

<?php include 'includes/footer.php'; ?>
