<?php
require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';
require_once __DIR__ . '/includes/parent_auth.php';
requireLogin();

$student_id = $_GET['id'] ?? 0;

// Get student details (scoped to current school)
$params = [$student_id];
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?" . school_where());
school_param($params);
$stmt->execute($params);
$student = $stmt->fetch();

if (!$student) {
    header('Location: students.php');
    exit;
}

// Get current belt
$params = [$student_id];
$current_belt = $pdo->prepare("
    SELECT sb.*, b.name as belt_name, b.color, mas.name as style_name
    FROM student_belts sb
    JOIN belts b ON sb.belt_id = b.id
    JOIN martial_arts_styles mas ON sb.style_id = mas.id
    WHERE sb.student_id = ?" . school_where('sb') . "
    ORDER BY sb.awarded_date DESC
    LIMIT 1
");
school_param($params);
$current_belt->execute($params);
$current_belt = $current_belt->fetch();

// Get belt history
$params = [$student_id];
$belt_history = $pdo->prepare("
    SELECT sb.*, b.name as belt_name, b.color, mas.name as style_name, u.full_name as instructor
    FROM student_belts sb
    JOIN belts b ON sb.belt_id = b.id
    JOIN martial_arts_styles mas ON sb.style_id = mas.id
    LEFT JOIN users u ON sb.instructor_id = u.id
    WHERE sb.student_id = ?" . school_where('sb') . "
    ORDER BY sb.awarded_date DESC
");
school_param($params);
$belt_history->execute($params);
$belt_history = $belt_history->fetchAll();

// Get memberships
$params = [$student_id];
$memberships = $pdo->prepare("
    SELECT m.*, mp.name as plan_name, mp.price, mp.classes_per_week, mp.duration_months
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    WHERE m.student_id = ?" . school_where('m') . "
    ORDER BY m.created_at DESC
");
school_param($params);
$memberships->execute($params);
$memberships = $memberships->fetchAll();

// Get enrolled classes
$params = [$student_id];
$enrolled_classes = $pdo->prepare("
    SELECT ce.*, c.name as class_name, c.day_of_week, c.start_time, c.end_time,
           mas.name as style_name
    FROM class_enrollments ce
    JOIN classes c ON ce.class_id = c.id
    JOIN martial_arts_styles mas ON c.style_id = mas.id
    WHERE ce.student_id = ? AND ce.status = 'active'" . school_where('ce') . "
    ORDER BY FIELD(c.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
");
school_param($params);
$enrolled_classes->execute($params);
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

// Migrations have been moved to migrate.php

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
$status_message = '';

// PRG flash messages: restore after a redirect
if (!empty($_SESSION['student_detail_flash'])) {
    $flash = $_SESSION['student_detail_flash'];
    unset($_SESSION['student_detail_flash']);
    $pm_message     = $flash['pm'] ?? '';
    $credit_message = $flash['credit'] ?? '';
    $status_message = $flash['status'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/includes/security.php';

    // Handle account status change
    if (isset($_POST['change_account_status'])) {
        verify_csrf();
        $newStatus = $_POST['new_status'] ?? '';
        if (in_array($newStatus, ['active', 'inactive', 'suspended'])) {
            $stParams = [$newStatus, $student_id];
            school_param($stParams);
            $pdo->prepare("UPDATE students SET status = ? WHERE id = ?" . school_where())
                ->execute($stParams);

            if ($newStatus === 'inactive' || $newStatus === 'suspended') {
                $inactParams = [date('Y-m-d'), $student_id];
                school_param($inactParams);
                $pdo->prepare("UPDATE students SET inactive_since = ? WHERE id = ?" . school_where())
                    ->execute($inactParams);
                deactivate_student_cascade($student_id, 'manual');
            } elseif ($newStatus === 'active') {
                $actParams = [$student_id];
                school_param($actParams);
                $pdo->prepare("UPDATE students SET inactive_since = NULL WHERE id = ?" . school_where())
                    ->execute($actParams);
                reactivate_student($student_id);
            }

            $status_message = showAlert('Account status changed to ' . ucfirst($newStatus) . '.', 'success');

            // Refresh student data
            $refreshParams = [$student_id];
            school_param($refreshParams);
            $refreshStmt = $pdo->prepare("SELECT * FROM students WHERE id = ?" . school_where());
            $refreshStmt->execute($refreshParams);
            $student = $refreshStmt->fetch();
        }
    }

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

                // Send email notification to the student about the proposed plan change
                send_plan_change_notification_email($student_id, $newPlanRow['name'], $change_notes ?: null);

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

    // Membership management actions
    if (isset($_POST['membership_action'])) {
        verify_csrf();
        $memAction = $_POST['membership_action'];
        $memId = (int) ($_POST['membership_id'] ?? 0);

        switch ($memAction) {
            case 'cancel_membership':
                $cancel_reason = sanitizeInput($_POST['cancel_reason'] ?? '');
                $cancel_params = [date('Y-m-d H:i:s'), $cancel_reason ?: null, $memId, $student_id];
                school_param($cancel_params);
                $pdo->prepare("UPDATE memberships SET status = 'cancelled', auto_renew = 0, cancelled_at = ?, cancel_reason = ? WHERE id = ? AND student_id = ?" . school_where())->execute($cancel_params);
                $status_message = showAlert('Membership cancelled successfully.', 'success');
                // Refresh memberships
                $memberships = $pdo->prepare("SELECT m.*, mp.name as plan_name, mp.price, mp.classes_per_week, mp.duration_months FROM memberships m JOIN membership_plans mp ON m.plan_id = mp.id WHERE m.student_id = ?" . school_where('m') . " ORDER BY m.created_at DESC");
                $mRefreshParams = [$student_id];
                school_param($mRefreshParams);
                $memberships->execute($mRefreshParams);
                $memberships = $memberships->fetchAll();
                break;

            case 'hold_membership':
                $hold_reason = sanitizeInput($_POST['hold_reason'] ?? '');
                $hold_end = !empty($_POST['hold_end_date']) ? $_POST['hold_end_date'] : null;
                $hold_params = [date('Y-m-d'), $hold_end, $hold_reason ?: null, $memId, $student_id];
                school_param($hold_params);
                $pdo->prepare("UPDATE memberships SET status = 'on_hold', hold_start_date = ?, hold_end_date = ?, hold_reason = ? WHERE id = ? AND student_id = ?" . school_where())->execute($hold_params);
                $msg = 'Membership placed on hold.';
                if ($hold_end) $msg .= ' Will resume on ' . date('M j, Y', strtotime($hold_end)) . '.';
                $status_message = showAlert($msg, 'success');
                // Refresh memberships
                $memberships = $pdo->prepare("SELECT m.*, mp.name as plan_name, mp.price, mp.classes_per_week, mp.duration_months FROM memberships m JOIN membership_plans mp ON m.plan_id = mp.id WHERE m.student_id = ?" . school_where('m') . " ORDER BY m.created_at DESC");
                $mRefreshParams = [$student_id];
                school_param($mRefreshParams);
                $memberships->execute($mRefreshParams);
                $memberships = $memberships->fetchAll();
                break;

            case 'resume_membership':
                $resume_params = [$memId, $student_id];
                school_param($resume_params);
                $pdo->prepare("UPDATE memberships SET status = 'active', hold_start_date = NULL, hold_end_date = NULL, hold_reason = NULL WHERE id = ? AND student_id = ?" . school_where())->execute($resume_params);
                $status_message = showAlert('Membership resumed successfully!', 'success');
                // Refresh memberships
                $memberships = $pdo->prepare("SELECT m.*, mp.name as plan_name, mp.price, mp.classes_per_week, mp.duration_months FROM memberships m JOIN membership_plans mp ON m.plan_id = mp.id WHERE m.student_id = ?" . school_where('m') . " ORDER BY m.created_at DESC");
                $mRefreshParams = [$student_id];
                school_param($mRefreshParams);
                $memberships->execute($mRefreshParams);
                $memberships = $memberships->fetchAll();
                break;

            case 'reactivate_membership':
                $new_end = $_POST['new_end_date'] ?? '';
                if (empty($new_end)) {
                    $status_message = showAlert('Please provide a new end date to reactivate.', 'error');
                } else {
                    $react_params = [$new_end, $memId, $student_id];
                    school_param($react_params);
                    $pdo->prepare("UPDATE memberships SET status = 'active', end_date = ?, cancelled_at = NULL, cancel_reason = NULL WHERE id = ? AND student_id = ?" . school_where())->execute($react_params);
                    $status_message = showAlert('Membership reactivated until ' . date('M j, Y', strtotime($new_end)) . '.', 'success');
                }
                // Refresh memberships
                $memberships = $pdo->prepare("SELECT m.*, mp.name as plan_name, mp.price, mp.classes_per_week, mp.duration_months FROM memberships m JOIN membership_plans mp ON m.plan_id = mp.id WHERE m.student_id = ?" . school_where('m') . " ORDER BY m.created_at DESC");
                $mRefreshParams = [$student_id];
                school_param($mRefreshParams);
                $memberships->execute($mRefreshParams);
                $memberships = $memberships->fetchAll();
                break;
        }
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

    // Demote parent back to regular student account
    if (isset($_POST['revoke_parent'])) {
        verify_csrf();
        if (!empty($student['is_parent'])) {
            // Remove all child links
            $params = [$student_id];
            school_param($params);
            $pdo->prepare("DELETE FROM parent_students WHERE parent_id = ?" . school_where())->execute($params);
            // Revoke parent flag
            $params = [$student_id];
            school_param($params);
            $pdo->prepare("UPDATE students SET is_parent = 0 WHERE id = ?" . school_where())->execute($params);
            $student['is_parent'] = 0;
            $pm_message = showAlert('Parent capabilities removed. This account is now a regular student account.', 'success');
        }
    }

    // Sync payment methods from a child student to this parent account (used on PARENT's page)
    if (isset($_POST['sync_child_cards_to_parent'])) {
        verify_csrf();
        $childId = (int)($_POST['child_student_id'] ?? 0);

        if ($childId && !empty($student['is_parent'])) {
            // Verify this child is actually linked to this parent
            $verifyLink = $pdo->prepare("SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ? LIMIT 1");
            $verifyLink->execute([$student_id, $childId]);

            if ($verifyLink->fetch()) {
                $copied = sync_child_payment_methods_to_parent($childId, $student_id);
                if ($copied > 0) {
                    $pm_message = showAlert($copied . ' payment method(s) synced from child to this parent account.', 'success');
                    // Refresh payment methods list
                    $pmStmt->execute([$student_id]);
                    $paymentMethods = $pmStmt->fetchAll();
                } else {
                    $pm_message = showAlert('No new payment methods to sync &mdash; cards already exist on parent account or child has no cards.', 'info');
                }
            } else {
                $pm_message = showAlert('Child is not linked to this parent account.', 'error');
            }
        } else {
            $pm_message = showAlert('Invalid request.', 'error');
        }
    }

    // Sync THIS student's payment methods UP to a linked parent account (used on CHILD's page)
    if (isset($_POST['sync_my_cards_to_parent'])) {
        verify_csrf();
        $targetParentId = (int)($_POST['target_parent_id'] ?? 0);

        if ($targetParentId) {
            // Verify this parent is actually linked to this student
            $verifyLink = $pdo->prepare("SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ? LIMIT 1");
            $verifyLink->execute([$targetParentId, $student_id]);

            if ($verifyLink->fetch()) {
                $copied = sync_child_payment_methods_to_parent($student_id, $targetParentId);
                if ($copied > 0) {
                    $pm_message = showAlert($copied . ' payment method(s) synced from this student to the parent account.', 'success');
                } else {
                    $pm_message = showAlert('No new payment methods to sync &mdash; cards already exist on parent account or this student has no cards.', 'info');
                }
            } else {
                $pm_message = showAlert('Parent is not linked to this student.', 'error');
            }
        } else {
            $pm_message = showAlert('Invalid request.', 'error');
        }
    }

    // Transfer student to another school (super admin only)
    if (isset($_POST['transfer_student'])) {
        verify_csrf();
        if (!is_super_admin()) {
            $pm_message = showAlert('Only super admins can transfer students.', 'error');
        } else {
            require_once __DIR__ . '/includes/transfer.php';
            $targetSchool = (int)($_POST['target_school_id'] ?? 0);
            $transferFamily = !empty($_POST['transfer_family']);

            if ($transferFamily && student_is_parent($student_id)) {
                $result = transfer_parent_family($student_id, $targetSchool);
            } else {
                $result = transfer_student($student_id, $targetSchool);
            }

            if ($result['success']) {
                switch_school($targetSchool);
                header('Location: student_detail.php?id=' . $student_id . '&transferred=1');
                exit;
            } else {
                $pm_message = showAlert('Transfer failed: ' . htmlspecialchars($result['error']), 'error');
            }
        }
    }

    // PRG redirect: store any messages in session and redirect to prevent re-submission on refresh
    $_SESSION['student_detail_flash'] = [
        'pm'     => $pm_message,
        'credit' => $credit_message,
        'status' => $status_message,
    ];
    header('Location: student_detail.php?id=' . $student_id);
    exit;
}

// Fetch linked parent accounts for this student
$linkedParents = get_student_parents($student_id);

// If this student IS a parent, fetch their linked children
$linkedChildren = [];
$childCardCounts = [];
if (!empty($student['is_parent'])) {
    $linkedChildren = get_parent_children($student_id);
    // Pre-fetch which children have payment methods (for sync button)
    if (!empty($linkedChildren)) {
        $childIds = array_column($linkedChildren, 'id');
        $placeholders = implode(',', array_fill(0, count($childIds), '?'));
        $ccStmt = $pdo->prepare("SELECT student_id, COUNT(*) as cnt FROM payment_methods WHERE student_id IN ($placeholders) GROUP BY student_id");
        $ccStmt->execute($childIds);
        foreach ($ccStmt->fetchAll() as $ccRow) {
            $childCardCounts[(int)$ccRow['student_id']] = (int)$ccRow['cnt'];
        }
    }
}

// Transfer preview data (super admin only)
$transferPreview = [];
$transferSchools = [];
if (is_super_admin()) {
    require_once __DIR__ . '/includes/transfer.php';
    $transferPreview = get_transfer_preview($student_id);
    $allSchools = get_all_schools();
    $transferSchools = array_filter($allSchools, function($s) use ($student) {
        return (int)$s['id'] !== (int)$student['school_id'] && $s['status'] === 'active';
    });
    $transferSchools = array_values($transferSchools);
}

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

    <?php if (isset($_GET['transferred'])): ?>
        <?= showAlert('Student transferred successfully! They will need new class enrollments and a membership plan at this school.', 'success') ?>
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
                    <?php if (!empty($student['username'])): ?>
                        <p class="text-sm text-gray-400 mt-0.5">@<?php echo htmlspecialchars($student['username']); ?></p>
                    <?php endif; ?>
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
                        <?php
                        $detailActivityStatus = $student['activity_status'] ?? 'active';
                        if ($detailActivityStatus === 'inactive'):
                            $detailHasPaidMembership = !empty($active_membership);
                        ?>
                            <span class="px-3 py-1 text-sm font-semibold rounded-full <?php echo $detailHasPaidMembership ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-600'; ?>">
                                <?php echo $detailHasPaidMembership ? 'Inactive - Still Paying' : 'Not Attending'; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php
                    // Last attendance and inactive since info
                    $lastAttStmt = $pdo->prepare("SELECT MAX(attendance_date) FROM attendance WHERE student_id = ? AND status IN ('present','late')" . school_where());
                    $lastAttParams = [$student['id']];
                    school_param($lastAttParams);
                    $lastAttStmt->execute($lastAttParams);
                    $detailLastAttDate = $lastAttStmt->fetchColumn();
                    $detailInactiveSince = $student['inactive_since'] ?? null;
                    if ($detailLastAttDate || $detailInactiveSince): ?>
                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                        <?php if ($detailLastAttDate):
                            $detailDaysAgo = (int)((strtotime('today') - strtotime($detailLastAttDate)) / 86400);
                        ?>
                            <span>Last attended: <strong><?php echo formatDate($detailLastAttDate); ?></strong> (<?php echo $detailDaysAgo; ?> days ago)</span>
                        <?php else: ?>
                            <span>Last attended: <strong>Never</strong></span>
                        <?php endif; ?>
                        <?php if ($detailInactiveSince): ?>
                            <span>Inactive since: <strong><?php echo formatDate($detailInactiveSince); ?></strong></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0 flex-wrap">
                <?php if (!empty($status_message)) echo $status_message; ?>
                <?php if ($student['status'] === 'active'): ?>
                    <form method="POST" class="inline" onsubmit="return confirm('Deactivate this student account? They will not be able to log in.')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="change_account_status" value="1">
                        <input type="hidden" name="new_status" value="inactive">
                        <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg font-medium text-sm">
                            Deactivate
                        </button>
                    </form>
                    <form method="POST" class="inline" onsubmit="return confirm('Suspend this student account?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="change_account_status" value="1">
                        <input type="hidden" name="new_status" value="suspended">
                        <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg font-medium text-sm">
                            Suspend
                        </button>
                    </form>
                <?php elseif ($student['status'] === 'inactive' || $student['status'] === 'suspended'): ?>
                    <form method="POST" class="inline" onsubmit="return confirm('Reactivate this student account?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="change_account_status" value="1">
                        <input type="hidden" name="new_status" value="active">
                        <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg font-medium text-sm">
                            Reactivate Account
                        </button>
                    </form>
                <?php endif; ?>
                <?php if (is_super_admin() && !empty($transferSchools)): ?>
                <button onclick="document.getElementById('transferModal').classList.remove('hidden')"
                        class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded-lg font-medium text-sm">
                    &#8644; Transfer to School
                </button>
                <?php endif; ?>
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
                <form method="POST" action="admin_impersonate.php" class="inline-block">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="start_student">
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-5 py-2 rounded-lg font-medium text-sm">
                        &#128065; View as Student
                    </button>
                </form>
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
            <?php if (!empty($student['school_district'])): ?>
            <div>
                <p class="text-sm text-gray-600">School District</p>
                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($student['school_district']); ?></p>
            </div>
            <?php endif; ?>
            <?php if (!empty($student['medical_info'])): ?>
            <div class="col-span-2">
                <p class="text-sm text-gray-600">Medical Info / Allergies</p>
                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($student['medical_info']); ?></p>
            </div>
            <?php endif; ?>
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
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <p class="font-semibold"><?php echo $membership['plan_name']; ?></p>
                                        <p class="text-sm text-gray-600">
                                            <?php echo formatDate($membership['start_date']); ?> -
                                            <?php echo formatDate($membership['end_date']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            <?php echo (int)$membership['classes_per_week'] >= 99 ? 'Unlimited classes' : $membership['classes_per_week'] . ' classes/week'; ?>
                                            &bull; Auto-renew: <?php echo (isset($membership['auto_renew']) && $membership['auto_renew']) ? 'ON' : 'OFF'; ?>
                                        </p>
                                        <?php if ($membership['status'] === 'on_hold'): ?>
                                            <div class="mt-1">
                                                <?php if (!empty($membership['hold_end_date'])): ?>
                                                    <p class="text-xs text-yellow-700">On hold until <?php echo date('M j, Y', strtotime($membership['hold_end_date'])); ?></p>
                                                <?php else: ?>
                                                    <p class="text-xs text-yellow-700">On hold (indefinite)</p>
                                                <?php endif; ?>
                                                <?php if (!empty($membership['hold_reason'])): ?>
                                                    <p class="text-xs text-gray-500">Reason: <?php echo htmlspecialchars($membership['hold_reason']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($membership['status'] === 'cancelled' && !empty($membership['cancelled_at'])): ?>
                                            <div class="mt-1">
                                                <p class="text-xs text-gray-500">Cancelled <?php echo date('M j, Y', strtotime($membership['cancelled_at'])); ?></p>
                                                <?php if (!empty($membership['cancel_reason'])): ?>
                                                    <p class="text-xs text-gray-500">Reason: <?php echo htmlspecialchars($membership['cancel_reason']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex flex-col items-end gap-2">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?php
                                            $colors = ['active' => 'bg-green-100 text-green-800', 'expired' => 'bg-red-100 text-red-800', 'cancelled' => 'bg-gray-100 text-gray-800', 'on_hold' => 'bg-yellow-100 text-yellow-800'];
                                            echo $colors[$membership['status']] ?? 'bg-gray-100 text-gray-800';
                                        ?>">
                                            <?php echo $membership['status'] === 'on_hold' ? 'On Hold' : ucfirst($membership['status']); ?>
                                        </span>
                                        <!-- Membership action buttons -->
                                        <div class="flex items-center gap-1 flex-wrap justify-end">
                                            <?php if ($membership['status'] === 'active'): ?>
                                                <button type="button" onclick="openStudentHoldModal(<?php echo $membership['id']; ?>)" class="px-2 py-1 text-xs font-medium text-yellow-700 bg-yellow-50 border border-yellow-300 rounded hover:bg-yellow-100">Hold</button>
                                                <button type="button" onclick="openStudentCancelModal(<?php echo $membership['id']; ?>, '<?php echo htmlspecialchars(addslashes($membership['plan_name'])); ?>')" class="px-2 py-1 text-xs font-medium text-red-700 bg-red-50 border border-red-300 rounded hover:bg-red-100">Cancel</button>
                                            <?php elseif ($membership['status'] === 'on_hold'): ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('Resume this membership?')">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="membership_action" value="resume_membership">
                                                    <input type="hidden" name="membership_id" value="<?php echo $membership['id']; ?>">
                                                    <button type="submit" class="px-2 py-1 text-xs font-medium text-green-700 bg-green-50 border border-green-300 rounded hover:bg-green-100">Resume</button>
                                                </form>
                                                <button type="button" onclick="openStudentCancelModal(<?php echo $membership['id']; ?>, '<?php echo htmlspecialchars(addslashes($membership['plan_name'])); ?>')" class="px-2 py-1 text-xs font-medium text-red-700 bg-red-50 border border-red-300 rounded hover:bg-red-100">Cancel</button>
                                            <?php elseif ($membership['status'] === 'cancelled' || $membership['status'] === 'expired'): ?>
                                                <button type="button" onclick="openStudentReactivateModal(<?php echo $membership['id']; ?>, <?php echo (int)($membership['duration_months'] ?? 1); ?>)" class="px-2 py-1 text-xs font-medium text-green-700 bg-green-50 border border-green-300 rounded hover:bg-green-100">Reactivate</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
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

        <!-- Linked Children (shown when this student IS a parent) -->
        <?php if (!empty($student['is_parent'])): ?>
            <div class="px-6 py-4 border-b border-gray-200" id="linked-children">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wider">
                        Linked Students (<?= count($linkedChildren) ?>)
                    </h3>
                    <form method="POST" class="inline" onsubmit="return confirm('Remove parent capabilities from this account? This will unlink all children and revert to a regular student account.')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="revoke_parent" value="1">
                        <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-md bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 transition-colors">
                            Revoke Parent Status
                        </button>
                    </form>
                </div>
                <?php if (empty($linkedChildren)): ?>
                    <div class="py-4 text-center text-gray-400 text-sm">
                        <p>No students linked to this parent account yet.</p>
                        <p class="mt-1">Use the <a href="student_detail.php?id=<?= $student_id ?>" class="text-blue-600 hover:underline">Link to Parent</a> option on a student's profile to add them.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($linkedChildren as $child):
                            $childName = htmlspecialchars($child['first_name'] . ' ' . $child['last_name']);
                            $childBelt = htmlspecialchars($child['current_belt'] ?? 'No Belt');
                            $childMembership = $child['membership_status'] ?? null;
                            $childRelationship = ucfirst($child['relationship'] ?? 'parent');
                            $childStatus = $child['status'] ?? 'active';
                        ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 bg-purple-100 rounded-full flex items-center justify-center flex-shrink-0">
                                        <span class="text-purple-600 font-bold text-xs"><?= strtoupper(substr($child['first_name'], 0, 1) . substr($child['last_name'], 0, 1)) ?></span>
                                    </div>
                                    <div>
                                        <a href="student_detail.php?id=<?= $child['id'] ?>" class="font-medium text-gray-800 hover:text-blue-600">
                                            <?= $childName ?>
                                        </a>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="text-xs text-gray-500"><?= $childRelationship ?></span>
                                            <span class="text-xs text-gray-300">&bull;</span>
                                            <span class="text-xs text-gray-500"><?= $childBelt ?></span>
                                            <?php if ($childMembership === 'active'): ?>
                                                <span class="text-xs text-gray-300">&bull;</span>
                                                <span class="inline-flex px-1.5 py-0.5 text-xs rounded-full bg-green-100 text-green-700"><?= htmlspecialchars($child['plan_name'] ?? 'Active') ?></span>
                                            <?php elseif ($childMembership): ?>
                                                <span class="text-xs text-gray-300">&bull;</span>
                                                <span class="inline-flex px-1.5 py-0.5 text-xs rounded-full bg-gray-100 text-gray-600"><?= ucfirst($childMembership) ?></span>
                                            <?php endif; ?>
                                            <?php if ($childStatus !== 'active'): ?>
                                                <span class="inline-flex px-1.5 py-0.5 text-xs rounded-full bg-red-100 text-red-700"><?= ucfirst($childStatus) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <?php if (!empty($childCardCounts[$child['id']])): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Copy <?= $childCardCounts[$child['id']] ?> payment method(s) from <?= $childName ?> to this parent account?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="sync_child_cards_to_parent" value="1">
                                            <input type="hidden" name="child_student_id" value="<?= $child['id'] ?>">
                                            <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-md bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100 transition-colors" title="Copy this child's card(s) to the parent account">
                                                💳 Sync Card<?= $childCardCounts[$child['id']] > 1 ? 's' : '' ?> ↑
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="student_detail.php?id=<?= $child['id'] ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">View →</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Linked Parents (shown for this student's parent accounts) -->
        <div class="px-6 py-4">
            <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wider mb-3">
                👨‍👩‍👧 Parent Accounts
            </h3>
        <?php if (empty($linkedParents)): ?>
            <div class="py-4 text-center text-gray-400 text-sm">
                <p>No parent accounts linked to this student.</p>
                <p class="mt-1">Link a parent account to allow family management of this student.</p>
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
                        <div class="flex items-center gap-3">
                            <?php if (!empty($paymentMethods)): ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Copy this student\'s payment method(s) to <?= htmlspecialchars($lp['first_name'] . ' ' . $lp['last_name']) ?>\'s parent account?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="sync_my_cards_to_parent" value="1">
                                    <input type="hidden" name="target_parent_id" value="<?= $lp['id'] ?>">
                                    <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-md bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100 transition-colors" title="Copy this student's card(s) to the parent account">
                                        💳 Sync Card<?= count($paymentMethods) > 1 ? 's' : '' ?> to Parent ↑
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Unlink this parent from the student?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="unlink_from_parent" value="1">
                                <input type="hidden" name="parent_id" value="<?= $lp['id'] ?>">
                                <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-medium">Unlink</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
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

<!-- Transfer to School Modal (Super Admin Only) -->
<?php if (is_super_admin() && !empty($transferSchools)): ?>
<div id="transferModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">&#8644; Transfer Student to Another School</h3>
            <button onclick="document.getElementById('transferModal').classList.add('hidden')"
                    class="text-gray-600 hover:text-gray-800 text-xl">&times;</button>
        </div>

        <form method="POST" onsubmit="return confirm('Are you sure you want to transfer this student? This action will remove class enrollments and expire active memberships.')">
            <?= csrf_field() ?>
            <input type="hidden" name="transfer_student" value="1">

            <div class="space-y-4">
                <!-- Current School -->
                <div class="bg-gray-50 rounded-lg p-3">
                    <p class="text-sm text-gray-500">Current School</p>
                    <p class="font-semibold text-gray-800"><?= htmlspecialchars($transferPreview['current_school'] ?? '') ?></p>
                </div>

                <!-- Target School -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Transfer To *</label>
                    <select name="target_school_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">Select a school...</option>
                        <?php foreach ($transferSchools as $ts): ?>
                            <option value="<?= $ts['id'] ?>"><?= htmlspecialchars($ts['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Family Transfer (if parent) -->
                <?php if (!empty($transferPreview['is_parent']) && !empty($transferPreview['children'])): ?>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <label class="flex items-start space-x-3 cursor-pointer">
                        <input type="checkbox" name="transfer_family" value="1" checked
                               class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <div>
                            <span class="font-medium text-blue-800">Transfer entire family</span>
                            <p class="text-sm text-blue-600 mt-1">
                                This parent account has <?= count($transferPreview['children']) ?> linked child<?= count($transferPreview['children']) > 1 ? 'ren' : '' ?>:
                            </p>
                            <ul class="text-sm text-blue-600 mt-1 ml-4 list-disc">
                                <?php foreach ($transferPreview['children'] as $child): ?>
                                    <li><?= htmlspecialchars($child['first_name'] . ' ' . $child['last_name']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </label>
                </div>
                <?php endif; ?>

                <!-- Parent Warning (if child has a parent at this school) -->
                <?php if (!empty($transferPreview['has_parent_at_school']) && empty($transferPreview['is_parent'])): ?>
                <div class="bg-yellow-50 border border-yellow-300 rounded-lg p-3">
                    <p class="text-sm font-medium text-yellow-800">&#9888; Parent Account Warning</p>
                    <p class="text-sm text-yellow-700 mt-1">
                        This student is linked to a parent account
                        (<?php
                            $pNames = array_map(fn($p) => $p['first_name'] . ' ' . $p['last_name'], $transferPreview['parent_names']);
                            echo htmlspecialchars(implode(', ', $pNames));
                        ?>)
                        at the current school. After transfer, the parent will no longer see this student in their portal
                        unless the parent is also transferred.
                    </p>
                </div>
                <?php endif; ?>

                <!-- Transfer Impact Summary -->
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-3">
                    <p class="text-sm font-semibold text-orange-800 mb-2">What will happen:</p>
                    <ul class="text-sm text-orange-700 space-y-1">
                        <?php if (($transferPreview['active_enrollments'] ?? 0) > 0): ?>
                            <li>&#10060; <?= $transferPreview['active_enrollments'] ?> class enrollment<?= $transferPreview['active_enrollments'] > 1 ? 's' : '' ?> will be <strong>removed</strong></li>
                        <?php endif; ?>
                        <?php if (!empty($transferPreview['active_membership'])): ?>
                            <li>&#10060; Active membership (<?= htmlspecialchars($transferPreview['active_membership']['plan_name']) ?>) will be <strong>expired</strong></li>
                        <?php endif; ?>
                        <?php if (($transferPreview['future_events'] ?? 0) > 0): ?>
                            <li>&#10060; <?= $transferPreview['future_events'] ?> future event registration<?= $transferPreview['future_events'] > 1 ? 's' : '' ?> will be <strong>cancelled</strong></li>
                        <?php endif; ?>
                        <?php if (($transferPreview['pending_makeups'] ?? 0) > 0): ?>
                            <li>&#10060; <?= $transferPreview['pending_makeups'] ?> pending makeup class<?= $transferPreview['pending_makeups'] > 1 ? 'es' : '' ?> will be <strong>cancelled</strong></li>
                        <?php endif; ?>
                        <?php if (($transferPreview['pending_plan_changes'] ?? 0) > 0): ?>
                            <li>&#10060; <?= $transferPreview['pending_plan_changes'] ?> pending plan change<?= $transferPreview['pending_plan_changes'] > 1 ? 's' : '' ?> will be <strong>cancelled</strong></li>
                        <?php endif; ?>
                        <li>&#10004; Belt history, payment history, attendance records, and training logs will be <strong>preserved</strong></li>
                        <li>&#9888; Student will need new class enrollments and membership at the destination school</li>
                    </ul>
                </div>
            </div>

            <div class="flex justify-end space-x-3 pt-5">
                <button type="button" onclick="document.getElementById('transferModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-orange-500 hover:bg-orange-600 text-white rounded-lg font-medium">
                    Transfer Student
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Hold Membership Modal (Student Detail) -->
<div id="studentHoldModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
    <div class="relative mx-auto p-6 border w-full max-w-md shadow-lg rounded-lg bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-800">Put Membership On Hold</h3>
            <button onclick="document.getElementById('studentHoldModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="membership_action" value="hold_membership">
            <input type="hidden" name="membership_id" id="student_hold_membership_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Reason (optional)</label>
                <input type="text" name="hold_reason" id="student_hold_reason" maxlength="255" placeholder="e.g., Injury, Travel, Personal reasons"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-yellow-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Resume Date (optional)</label>
                <input type="date" name="hold_end_date" id="student_hold_end_date"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-yellow-500">
                <p class="text-xs text-gray-500 mt-1">Leave blank for indefinite hold. Manual resume required.</p>
            </div>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-sm text-yellow-700">
                Billing will be paused while the membership is on hold. The student will not be charged until the membership is resumed.
            </div>
            <div class="flex justify-end space-x-3 pt-2">
                <button type="button" onclick="document.getElementById('studentHoldModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg font-medium">Put On Hold</button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Membership Modal (Student Detail) -->
<div id="studentCancelModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
    <div class="relative mx-auto p-6 border w-full max-w-md shadow-lg rounded-lg bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-800">Cancel Membership</h3>
            <button onclick="document.getElementById('studentCancelModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>
        <form method="POST" class="space-y-4" onsubmit="return confirm('Are you sure you want to cancel this membership? This will disable auto-renewal.')">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="membership_action" value="cancel_membership">
            <input type="hidden" name="membership_id" id="student_cancel_membership_id">
            <p class="text-sm text-gray-600">Cancelling: <strong id="student_cancel_plan_name"></strong></p>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Reason (optional)</label>
                <input type="text" name="cancel_reason" id="student_cancel_reason" maxlength="255" placeholder="e.g., Student request, Non-payment, Moving"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
            </div>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700">
                This will cancel the membership and disable auto-renewal. The student will lose access to membership benefits.
            </div>
            <div class="flex justify-end space-x-3 pt-2">
                <button type="button" onclick="document.getElementById('studentCancelModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Go Back</button>
                <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium">Cancel Membership</button>
            </div>
        </form>
    </div>
</div>

<!-- Reactivate Membership Modal (Student Detail) -->
<div id="studentReactivateModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
    <div class="relative mx-auto p-6 border w-full max-w-md shadow-lg rounded-lg bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-800">Reactivate Membership</h3>
            <button onclick="document.getElementById('studentReactivateModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="membership_action" value="reactivate_membership">
            <input type="hidden" name="membership_id" id="student_reactivate_membership_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">New End Date *</label>
                <input type="date" name="new_end_date" id="student_new_end_date" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                <p class="text-xs text-gray-500 mt-1">Set the new membership end date.</p>
            </div>
            <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-sm text-green-700">
                This will reactivate the membership and set it back to active status with the specified end date.
            </div>
            <div class="flex justify-end space-x-3 pt-2">
                <button type="button" onclick="document.getElementById('studentReactivateModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium">Reactivate</button>
            </div>
        </form>
    </div>
</div>

<script>
function openStudentHoldModal(membershipId) {
    document.getElementById('student_hold_membership_id').value = membershipId;
    document.getElementById('student_hold_reason').value = '';
    document.getElementById('student_hold_end_date').value = '';
    document.getElementById('studentHoldModal').classList.remove('hidden');
}

function openStudentCancelModal(membershipId, planName) {
    document.getElementById('student_cancel_membership_id').value = membershipId;
    document.getElementById('student_cancel_plan_name').textContent = planName;
    document.getElementById('student_cancel_reason').value = '';
    document.getElementById('studentCancelModal').classList.remove('hidden');
}

function openStudentReactivateModal(membershipId, durationMonths) {
    document.getElementById('student_reactivate_membership_id').value = membershipId;
    // Default end date: today + plan duration
    var d = new Date();
    d.setMonth(d.getMonth() + (durationMonths || 1));
    document.getElementById('student_new_end_date').value = d.toISOString().split('T')[0];
    document.getElementById('studentReactivateModal').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
