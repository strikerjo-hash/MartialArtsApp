<?php
/**
 * register.php — Student Self-Registration
 *
 * Multi-step registration flow:
 *   Step 1: Account info + waiver acceptance + optional plan selection
 *   Step 2: Payment (only if a plan was chosen in Step 1)
 *   Step 3: Success confirmation
 *
 * If no plan is selected, the student is created and redirected straight
 * to the student portal. Plans can be assigned later by an admin.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/payment_gateway.php';
require_once __DIR__ . '/includes/parent_auth.php';

auth_start_session();

// Migrations have been moved to migrate.php
$pdo = get_db();

// Detect available columns
$cols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll();
$colNames = array_column($cols, 'Field');
$hasPasswordHash = in_array('password_hash', $colNames, true);
$hasPassword     = in_array('password', $colNames, true);
$passwordCol     = $hasPasswordHash ? 'password_hash' : ($hasPassword ? 'password' : 'password_hash');
$hasUsername      = in_array('username', $colNames, true);

// ─── Resolve school from URL slug (multi-tenancy) ───────────────────
$registration_school_id = current_school_id(); // fallback to session/default
$schoolRow = null;
if (!empty($_GET['school'])) {
    $schoolStmt = $pdo->prepare("SELECT id FROM schools WHERE slug = ? AND status = 'active' LIMIT 1");
    $schoolStmt->execute([trim($_GET['school'])]);
    $schoolRow = $schoolStmt->fetch();
    if ($schoolRow) {
        $registration_school_id = (int) $schoolRow['id'];
    } else {
        // Invalid slug — redirect back to school selector
        header('Location: register.php');
        exit;
    }
}

// ─── Multi-school selector logic ────────────────────────────────────
$all_schools = get_all_schools();
$active_schools = array_filter($all_schools, fn($s) => $s['status'] === 'active');
$active_schools = array_values($active_schools); // re-index
$show_school_selector = (count($active_schools) > 1 && empty($_GET['school']));
$schoolParam = !empty($_GET['school']) ? '&school=' . urlencode($_GET['school']) : '';

// ─── Redirect if already logged in ──────────────────────────────────
// Allow students through to Step 2 if mid-registration, and Step 3 (success) always
$currentStep = $_GET['step'] ?? 1;
$isInRegistrationFlow = !empty($_SESSION['registration_student_id']) && !empty($_SESSION['registration_plan_id']);
$isStep3Success       = in_array($currentStep, [3, '3'], true);
$isStep2Payment       = in_array($currentStep, [2, '2'], true) && $isInRegistrationFlow;

if (current_user_type() === 'student' && !$isStep2Payment && !$isStep3Success) {
    header('Location: student_portal.php');
    exit;
}

// ─── Load data ──────────────────────────────────────────────────────
$theme           = get_theme();
$step            = $_GET['step'] ?? 1;
$errors          = [];
$message         = '';
$waiver_content  = getSetting('waiver_content', '');
$waiver_version  = getSetting('waiver_version', '1.0');
require_once __DIR__ . '/includes/messaging.php';
$comm_consent_text    = get_comm_consent_text();
$comm_consent_version = get_comm_consent_version();
$plansStmt = $pdo->prepare("SELECT * FROM membership_plans WHERE status = 'active' AND (is_grandfathered = 0 OR is_grandfathered IS NULL) AND school_id = ? ORDER BY price ASC");
$plansStmt->execute([$registration_school_id]);
$plans = $plansStmt->fetchAll();

// Stripe publishable key for Step 2
$stripePk   = '';
$activeGw   = getSetting('active_payment_gateway', 'none');
if ($activeGw === 'stripe') {
    $stripePk = getSetting('stripe_publishable_key', '');
}

$form = [
    'username'         => '',
    'first_name'       => '',
    'last_name'        => '',
    'email'            => '',
    'phone'            => '',
    'date_of_birth'    => '',
    'plan_id'          => '',
    'parent_first_name' => '',
    'parent_last_name'  => '',
    'parent_email'      => '',
    'parent_phone'      => '',
    'parent_dob'        => '',
];

// =====================================================================
//  STEP 1 — Account creation
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step == 1) {
    verify_csrf();

    $form['username']         = trim($_POST['username']   ?? '');
    $form['first_name']       = trim($_POST['first_name'] ?? '');
    $form['last_name']        = trim($_POST['last_name']  ?? '');
    $form['email']            = trim($_POST['email']      ?? '');
    $form['phone']            = trim($_POST['phone']      ?? '');
    $form['date_of_birth']    = trim($_POST['date_of_birth'] ?? '');
    $form['plan_id']          = $_POST['plan_id']          ?? '';
    $form['parent_first_name'] = trim($_POST['parent_first_name'] ?? '');
    $form['parent_last_name']  = trim($_POST['parent_last_name']  ?? '');
    $form['parent_email']      = trim($_POST['parent_email']      ?? '');
    $form['parent_phone']      = trim($_POST['parent_phone']      ?? '');
    $form['parent_dob']        = trim($_POST['parent_dob']        ?? '');
    $password           = $_POST['password']         ?? '';
    $password_confirm   = $_POST['password_confirm'] ?? '';

    // ── Validate fields ──
    if ($form['username'] === '') {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $form['username'])) {
        $errors[] = 'Username must be 3-50 characters: letters, numbers, or underscores.';
    }

    if ($form['first_name'] === '') $errors[] = 'First name is required.';
    if ($form['last_name'] === '')  $errors[] = 'Last name is required.';

    if ($form['email'] === '') {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($form['phone'] === '') {
        $errors[] = 'Phone number is required.';
    }

    // ── Date of birth & age check ──
    $isMinor = false;
    if ($form['date_of_birth'] === '') {
        $errors[] = 'Date of birth is required.';
    } else {
        $dob = \DateTime::createFromFormat('Y-m-d', $form['date_of_birth']);
        if (!$dob) {
            $errors[] = 'Please enter a valid date of birth.';
        } else {
            $today = new \DateTime();
            $age = $dob->diff($today)->y;
            if ($dob > $today) {
                $errors[] = 'Date of birth cannot be in the future.';
            } elseif ($age < 18) {
                $isMinor = true;
            }
        }
    }

    // ── Parent/Guardian validation (required for minors) ──
    if ($isMinor) {
        if ($form['parent_first_name'] === '') $errors[] = 'Parent/guardian first name is required for students under 18.';
        if ($form['parent_last_name'] === '')  $errors[] = 'Parent/guardian last name is required for students under 18.';
        if ($form['parent_email'] === '') {
            $errors[] = 'Parent/guardian email is required for students under 18.';
        } elseif (!filter_var($form['parent_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid parent/guardian email address.';
        } elseif (strtolower($form['parent_email']) === strtolower($form['email'])) {
            $errors[] = 'Parent/guardian email must be different from the student email.';
        }
        if ($form['parent_phone'] === '') $errors[] = 'Parent/guardian phone number is required for students under 18.';
        if ($form['parent_dob'] === '') {
            $errors[] = 'Parent/guardian date of birth is required for students under 18.';
        } else {
            $parentDob = \DateTime::createFromFormat('Y-m-d', $form['parent_dob']);
            if (!$parentDob) {
                $errors[] = 'Please enter a valid parent/guardian date of birth.';
            } else {
                $parentAge = $parentDob->diff(new \DateTime())->y;
                if ($parentAge < 18) {
                    $errors[] = 'Parent/guardian must be at least 18 years old.';
                }
            }
        }
    }

    $pwError = validate_password($password);
    if ($pwError !== '') {
        $errors[] = $pwError;
    } elseif ($password !== $password_confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // ── Waiver validation ──
    if ($waiver_content !== '' && empty($_POST['waiver_agree'])) {
        $errors[] = 'You must read and agree to the waiver to create an account.';
    }

    // ── Duplicate checks ──
    if (empty($errors) && $hasUsername) {
        $stmt = $pdo->prepare('SELECT id FROM students WHERE username = :u AND school_id = :sid LIMIT 1');
        $stmt->execute([':u' => $form['username'], ':sid' => $registration_school_id]);
        if ($stmt->fetch()) {
            $errors[] = 'That username is already taken.';
        }
    }

    if (empty($errors) && $form['email'] !== '') {
        $stmt = $pdo->prepare('SELECT id FROM students WHERE email = :e AND school_id = :sid LIMIT 1');
        $stmt->execute([':e' => $form['email'], ':sid' => $registration_school_id]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    // ── Parent email check (for minors) ──
    $existingParentId = null;
    if (empty($errors) && $isMinor && $form['parent_email'] !== '') {
        $stmt = $pdo->prepare('SELECT id, is_parent, first_name, last_name FROM students WHERE email = :e AND school_id = :sid LIMIT 1');
        $stmt->execute([':e' => $form['parent_email'], ':sid' => $registration_school_id]);
        $existingParent = $stmt->fetch();
        if ($existingParent) {
            if ((int)($existingParent['is_parent'] ?? 0) === 1) {
                // Reuse existing parent account
                $existingParentId = (int) $existingParent['id'];
            } else {
                $errors[] = 'An account with the parent/guardian email already exists but is not a parent account. Please use a different email or contact the studio.';
            }
        }
    }

    // ── Create the account ──
    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $insertCols   = ['school_id', 'first_name', 'last_name', 'email', 'phone', 'join_date'];
        $insertVals   = [':sid', ':fn', ':ln', ':em', ':ph', ':jd'];
        $insertParams = [
            ':sid' => $registration_school_id,
            ':fn' => $form['first_name'],
            ':ln' => $form['last_name'],
            ':em' => $form['email'] ?: null,
            ':ph' => $form['phone'] ?: null,
            ':jd' => date('Y-m-d'),
        ];

        // Date of birth
        if ($form['date_of_birth'] !== '' && in_array('date_of_birth', $colNames, true)) {
            $insertCols[]          = 'date_of_birth';
            $insertVals[]          = ':dob';
            $insertParams[':dob']  = $form['date_of_birth'];
        }

        if ($hasUsername) {
            $insertCols[]          = 'username';
            $insertVals[]          = ':u';
            $insertParams[':u']    = $form['username'];
        }

        $insertCols[]          = $passwordCol;
        $insertVals[]          = ':h';
        $insertParams[':h']    = $hash;

        if (in_array('belt_rank', $colNames, true)) {
            $insertCols[]          = 'belt_rank';
            $insertVals[]          = ':br';
            $insertParams[':br']   = 'White';
        }

        // Waiver acceptance
        if ($waiver_content !== '' && !empty($_POST['waiver_agree'])) {
            if (in_array('waiver_accepted_at', $colNames, true)) {
                $insertCols[]          = 'waiver_accepted_at';
                $insertVals[]          = ':wa';
                $insertParams[':wa']   = date('Y-m-d H:i:s');
            }
            if (in_array('waiver_version', $colNames, true)) {
                $insertCols[]          = 'waiver_version';
                $insertVals[]          = ':wv';
                $insertParams[':wv']   = $waiver_version;
            }
        }

        // Communication consent (email / SMS)
        $consentNow = date('Y-m-d H:i:s');
        $consentIp  = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($_POST['comm_consent_email']) && in_array('comm_consent_email', $colNames, true)) {
            $insertCols[] = 'comm_consent_email';    $insertVals[] = ':cce';  $insertParams[':cce'] = 1;
            $insertCols[] = 'comm_consent_email_at'; $insertVals[] = ':ccea'; $insertParams[':ccea'] = $consentNow;
        }
        if (!empty($_POST['comm_consent_sms']) && in_array('comm_consent_sms', $colNames, true)) {
            $insertCols[] = 'comm_consent_sms';    $insertVals[] = ':ccs';  $insertParams[':ccs'] = 1;
            $insertCols[] = 'comm_consent_sms_at'; $insertVals[] = ':ccsa'; $insertParams[':ccsa'] = $consentNow;
        }
        if ((!empty($_POST['comm_consent_email']) || !empty($_POST['comm_consent_sms'])) && in_array('comm_consent_version', $colNames, true)) {
            $insertCols[] = 'comm_consent_version'; $insertVals[] = ':ccv';  $insertParams[':ccv'] = $comm_consent_version;
            $insertCols[] = 'comm_consent_ip';      $insertVals[] = ':ccip'; $insertParams[':ccip'] = $consentIp;
        }

        $colList = implode(', ', $insertCols);
        $valList = implode(', ', $insertVals);

        $insert = $pdo->prepare("INSERT INTO students ({$colList}) VALUES ({$valList})");
        $insert->execute($insertParams);

        $newId   = $pdo->lastInsertId();
        $stmt    = $pdo->prepare('SELECT * FROM students WHERE id = :id');
        $stmt->execute([':id' => $newId]);
        $student = $stmt->fetch();

        login_student($student);

        // ── Parent account creation for minors ──
        $parentId = null;
        if ($isMinor) {
            if ($existingParentId) {
                // Reuse existing parent account
                $parentId = $existingParentId;
            } else {
                // Create a new parent account
                $parentUsername = 'parent_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $form['parent_first_name'])) . '_' . time();
                $parentHash    = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT); // random password — parent uses "Forgot Password"

                $pCols   = ['school_id', 'first_name', 'last_name', 'email', 'phone', 'join_date', 'is_parent'];
                $pVals   = [':psid', ':pfn', ':pln', ':pem', ':pph', ':pjd', ':pip'];
                $pParams = [
                    ':psid' => $registration_school_id,
                    ':pfn'  => $form['parent_first_name'],
                    ':pln'  => $form['parent_last_name'],
                    ':pem'  => $form['parent_email'],
                    ':pph'  => $form['parent_phone'],
                    ':pjd'  => date('Y-m-d'),
                    ':pip'  => 1,
                ];

                if ($form['parent_dob'] !== '' && in_array('date_of_birth', $colNames, true)) {
                    $pCols[]          = 'date_of_birth';
                    $pVals[]          = ':pdob';
                    $pParams[':pdob'] = $form['parent_dob'];
                }

                if ($hasUsername) {
                    $pCols[]         = 'username';
                    $pVals[]         = ':pu';
                    $pParams[':pu']  = $parentUsername;
                }

                $pCols[]         = $passwordCol;
                $pVals[]         = ':ph2';
                $pParams[':ph2'] = $parentHash;

                $pColList = implode(', ', $pCols);
                $pValList = implode(', ', $pVals);

                $pInsert = $pdo->prepare("INSERT INTO students ({$pColList}) VALUES ({$pValList})");
                $pInsert->execute($pParams);
                $parentId = (int) $pdo->lastInsertId();
            }

            // Link student to parent
            link_student_to_parent($parentId, (int)$newId, 'parent');

            // Store parent name in session for Step 2 & 3 display
            $_SESSION['registration_parent_id']   = $parentId;
            $_SESSION['registration_parent_name']  = trim($form['parent_first_name'] . ' ' . $form['parent_last_name']);
            $_SESSION['registration_parent_email'] = $form['parent_email'];
        }

        // Plan selected? → Go to payment step
        if (!empty($form['plan_id'])) {
            $_SESSION['registration_student_id'] = (int) $newId;
            $_SESSION['registration_plan_id']    = (int) $form['plan_id'];
            header('Location: register.php?step=2' . $schoolParam);
            exit;
        }

        // No plan → straight to portal (or success page if minor with parent created)
        if ($isMinor) {
            $_SESSION['registration_success_parent_name']  = trim($form['parent_first_name'] . ' ' . $form['parent_last_name']);
            $_SESSION['registration_success_parent_email'] = $form['parent_email'];
            header('Location: register.php?step=3' . $schoolParam);
            exit;
        }
        header('Location: student_portal.php');
        exit;
    }
}

// =====================================================================
//  STEP 2 — Payment processing
// =====================================================================
$selected_plan  = null;
$proration      = null;
$payment_error  = '';

if ($step == 2) {
    // Must have session data from Step 1
    if (empty($_SESSION['registration_student_id']) || empty($_SESSION['registration_plan_id'])) {
        header('Location: register.php');
        exit;
    }

    $regStudentId = (int) $_SESSION['registration_student_id'];
    $regPlanId    = (int) $_SESSION['registration_plan_id'];
    $regParentId  = !empty($_SESSION['registration_parent_id']) ? (int) $_SESSION['registration_parent_id'] : null;
    $isMinorFlow  = !empty($regParentId);
    $chargeTargetId = $isMinorFlow ? $regParentId : $regStudentId;

    // Load parent name for display
    $regParentName  = $_SESSION['registration_parent_name'] ?? '';
    $regParentEmail = $_SESSION['registration_parent_email'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ? AND school_id = ?");
    $stmt->execute([$regPlanId, $registration_school_id]);
    $selected_plan = $stmt->fetch();

    if (!$selected_plan) {
        header('Location: register.php');
        exit;
    }

    // Calculate first charge (base amount from proration)
    $proration = calculateProration(null, $selected_plan);
    $regFee    = (float) ($selected_plan['registration_fee'] ?? 0);

    // Calculate full fee breakdown (before discount — AJAX updates client-side)
    $discountCodeFromPost = trim($_POST['discount_code'] ?? '');
    $feeBreakdown = calculateTotalWithFees([
        'base_amount'      => $proration['amount'],
        'registration_fee' => $regFee,
        'discount_code'    => $discountCodeFromPost,
        'plan_id'          => $regPlanId,
    ]);

    // ── Handle payment POST ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
        verify_csrf();

        // Recalculate with the submitted discount code
        $submittedDiscount = trim($_POST['discount_code'] ?? '');
        $feeBreakdown = calculateTotalWithFees([
            'base_amount'      => $proration['amount'],
            'registration_fee' => $regFee,
            'discount_code'    => $submittedDiscount,
            'plan_id'          => $regPlanId,
        ]);

        $chargeAmount = $feeBreakdown['total'];

        if ($chargeAmount > 0 && $activeGw !== 'none' && !empty($activeGw)) {
            // Save the card first (Stripe) — saved to parent account for minors
            $stripePmId = trim($_POST['stripe_pm_id'] ?? '');
            if ($activeGw === 'stripe' && $stripePmId !== '') {
                $cardLabel = $isMinorFlow ? 'Registration Card (parent)' : 'Registration Card';
                $saveResult = save_card_from_token($chargeTargetId, $stripePmId, $cardLabel);
                if (!$saveResult['success']) {
                    $payment_error = $saveResult['error'] ?? 'Failed to save payment method.';
                }
                // For minors, sync the parent's new card down to the child
                if ($isMinorFlow && $payment_error === '') {
                    sync_parent_payment_methods_to_child($regParentId, $regStudentId);
                }
            }

            // Charge the account (parent for minors, student for adults)
            if ($payment_error === '') {
                $desc = 'Membership enrollment: ' . $selected_plan['name'];
                if ($isMinorFlow) {
                    $desc .= ' (charged to parent: ' . $regParentName . ')';
                }
                if ($feeBreakdown['service_fee'] > 0) {
                    $desc .= ' (incl. service fee)';
                }
                $chargeResult = charge_student($chargeTargetId, $chargeAmount, $desc);
                if (!$chargeResult['success']) {
                    $payment_error = $chargeResult['error'] ?? 'Payment failed. Please try again.';
                }
            }
        }

        if ($payment_error === '') {
            // Create membership
            $start_date = date('Y-m-d');

            // Afterschool plans use fixed program end date & no auto-renewal
            if (!empty($selected_plan['is_afterschool'])) {
                $end_date   = $selected_plan['program_end_date'];
                $auto_renew = 0;
            } else {
                $end_date   = date('Y-m-d', strtotime("+{$selected_plan['duration_months']} months"));
                $auto_renew = 1;
            }

            $billingFreq = $selected_plan['billing_frequency'] ?? 'upfront';
            $isMonthly   = ($billingFreq === 'monthly' && $selected_plan['duration_months'] > 1);

            $mStmt = $pdo->prepare("
                INSERT INTO memberships (school_id, student_id, plan_id, start_date, end_date, status, payment_status, amount_paid, auto_renew, billing_day, monthly_charges_made)
                VALUES (?, ?, ?, ?, ?, 'active', 'paid', ?, ?, ?, ?)
            ");
            $billingDay      = $isMonthly ? min((int) date('j'), 28) : null;
            $chargesMade     = $isMonthly ? 1 : 0;
            $mStmt->execute([
                $registration_school_id,
                $regStudentId,
                $regPlanId,
                $start_date,
                $end_date,
                $chargeAmount,
                $auto_renew,
                $billingDay,
                $chargesMade,
            ]);
            $membershipId = $pdo->lastInsertId();

            // Record payment (if amount > 0)
            if ($chargeAmount > 0) {
                $notes = 'Initial membership enrollment';
                if ($isMinorFlow) {
                    $notes .= ' | Charged via parent account: ' . $regParentName;
                }
                if ($feeBreakdown['discount_amount'] > 0) {
                    $notes .= ' | Discount: -$' . number_format($feeBreakdown['discount_amount'], 2) . ' (' . $feeBreakdown['discount_code'] . ')';
                }
                if ($feeBreakdown['service_fee'] > 0) {
                    $notes .= ' | Service fee: $' . number_format($feeBreakdown['service_fee'], 2);
                }
                if ($regFee > 0) {
                    $notes .= ' | Reg fee: $' . number_format($regFee, 2);
                }
                $regReceiptNum = generateReceiptNumber();
                $pStmt = $pdo->prepare("
                    INSERT INTO payments (school_id, student_id, payment_type, reference_id, amount, payment_method, payment_date, receipt_number, notes)
                    VALUES (?, ?, 'membership', ?, ?, 'credit_card', CURDATE(), ?, ?)
                ");
                $pStmt->execute([
                    $registration_school_id,
                    $regStudentId,
                    $membershipId,
                    $chargeAmount,
                    $regReceiptNum,
                    $notes,
                ]);

                // Send payment receipt email
                send_payment_receipt_email([
                    'student_id'     => $regStudentId,
                    'amount'         => $chargeAmount,
                    'payment_type'   => 'membership',
                    'description'    => 'Membership enrollment: ' . $selected_plan['name'],
                    'receipt_number' => $regReceiptNum,
                    'transaction_id' => $chargeResult['transaction_id'] ?? null,
                    'payment_method' => 'credit_card',
                ]);
            }

            // Record discount code usage (supports multiple codes)
            recordAllDiscountCodeUses($feeBreakdown, $regStudentId, 'registration', $membershipId);

            // Preserve parent info for success page
            if ($isMinorFlow) {
                $_SESSION['registration_success_parent_name']  = $regParentName;
                $_SESSION['registration_success_parent_email'] = $regParentEmail;
            }

            // Clean up session
            unset(
                $_SESSION['registration_student_id'],
                $_SESSION['registration_plan_id'],
                $_SESSION['registration_parent_id'],
                $_SESSION['registration_parent_name'],
                $_SESSION['registration_parent_email']
            );

            header('Location: register.php?step=3' . $schoolParam);
            exit;
        }
    }

    // ── Skip payment POST (creates account without membership) ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['skip_payment'])) {
        verify_csrf();

        // For minors, preserve parent info for success page
        if ($isMinorFlow) {
            $_SESSION['registration_success_parent_name']  = $regParentName;
            $_SESSION['registration_success_parent_email'] = $regParentEmail;
        }

        unset(
            $_SESSION['registration_student_id'],
            $_SESSION['registration_plan_id'],
            $_SESSION['registration_parent_id'],
            $_SESSION['registration_parent_name'],
            $_SESSION['registration_parent_email']
        );

        if ($isMinorFlow) {
            header('Location: register.php?step=3' . $schoolParam);
        } else {
            header('Location: student_portal.php');
        }
        exit;
    }
}

// =====================================================================
//  HTML Output
// =====================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — <?= htmlspecialchars($theme['studio_name']) ?></title>
    <?php if (!empty($theme['favicon_url'])): ?>
        <link rel="icon" href="<?= htmlspecialchars($theme['favicon_url']) ?>">
    <?php endif; ?>
    <style><?= theme_css_vars() ?></style>
    <link rel="stylesheet" href="assets/css/style.css">
    <?php if ($step == 2 && $stripePk): ?>
        <script src="https://js.stripe.com/v3/"></script>
    <?php endif; ?>
</head>
<body class="login-page">
    <div class="login-container register-container">
        <div class="login-card">
            <?php if (!empty($theme['logo_url'])): ?>
                <img src="<?= htmlspecialchars($theme['logo_url']) ?>"
                     alt="<?= htmlspecialchars($theme['studio_name']) ?>"
                     class="login-logo">
            <?php endif; ?>

            <h1 class="login-title"><?= htmlspecialchars($theme['studio_name']) ?></h1>

            <?php
            // ── Step indicator ──
            $stepLabels = ['Account', 'Payment', 'Complete'];
            if ($step == 2 || $step == 3):
            ?>
            <div class="step-indicator">
                <?php foreach ($stepLabels as $i => $label):
                    $num = $i + 1;
                    $circleClass = '';
                    $labelClass  = '';
                    $connClass   = '';
                    if ($num < $step)      { $circleClass = 'completed'; $labelClass = ''; $connClass = 'completed'; }
                    elseif ($num == $step)  { $circleClass = 'active';    $labelClass = 'active'; }
                ?>
                    <?php if ($i > 0): ?>
                        <div class="step-connector <?= $connClass ?>"></div>
                    <?php endif; ?>
                    <div class="step-item">
                        <div class="step-circle <?= $circleClass ?>"><?= $num < $step ? '&#10003;' : $num ?></div>
                        <span class="step-label <?= $labelClass ?>"><?= $label ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <?php if ($show_school_selector): ?>
                    <p class="login-tagline">Select Your School</p>
                <?php else: ?>
                    <p class="login-tagline">Create Your Account</p>
                    <?php if (count($active_schools) > 1 && !empty($_GET['school'])):
                        $selectedSchool = array_filter($active_schools, fn($s) => $s['slug'] === $_GET['school']);
                        $selectedSchool = reset($selectedSchool);
                        if ($selectedSchool): ?>
                        <div style="text-align:center;margin-bottom:1.25rem;">
                            <div style="display:inline-block;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.15);padding:8px 20px;border-radius:8px;">
                                <span style="font-size:1.1rem;font-weight:600;color:var(--primary-color, #64b5f6);letter-spacing:.02em;">
                                    <?= htmlspecialchars($selectedSchool['name']) ?>
                                </span>
                                <a href="register.php" style="margin-left:10px;font-size:.75rem;opacity:.6;color:inherit;text-decoration:underline;">change</a>
                            </div>
                        </div>
                    <?php endif; endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php // ── Errors / messages ── ?>
            <?php if ($errors): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $e): ?>
                        <div><?= htmlspecialchars($e) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($payment_error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($payment_error) ?></div>
            <?php endif; ?>

<?php // =============================================================
      //  SCHOOL SELECTOR — shown when multiple schools and none selected
      // =============================================================
if ($show_school_selector): ?>

            <div class="plan-grid" style="margin:1.25rem 0;">
                <?php foreach ($active_schools as $school): ?>
                <a href="register.php?school=<?= urlencode($school['slug']) ?>"
                   class="plan-card school-card" style="text-decoration:none;color:inherit;">
                    <div class="plan-card-inner" style="text-align:center;padding:1.5rem 1rem;">
                        <div class="plan-card-name"><?= htmlspecialchars($school['name']) ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($active_schools)): ?>
                <p style="text-align:center;opacity:.6;margin:2rem 0;">No schools are currently accepting registrations.</p>
            <?php endif; ?>

            <div class="login-footer">
                <a href="login.php" class="admin-link">&larr; Already have an account? Sign In</a>
            </div>

<?php // =============================================================
      //  STEP 1 — Registration Form
      // =============================================================
elseif ($step == 1): ?>

            <form method="POST" action="register.php?step=1<?= $schoolParam ?>" class="login-form" autocomplete="on">
                <?= csrf_field() ?>

                <!-- Name -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name"
                               value="<?= htmlspecialchars($form['first_name']) ?>"
                               placeholder="First name" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name"
                               value="<?= htmlspecialchars($form['last_name']) ?>"
                               placeholder="Last name" required>
                    </div>
                </div>

                <!-- Username -->
                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username"
                           value="<?= htmlspecialchars($form['username']) ?>"
                           placeholder="Choose a username" required
                           pattern="[a-zA-Z0-9_]{3,50}"
                           title="3-50 characters: letters, numbers, underscores">
                </div>

                <!-- Email & Phone -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email"
                               value="<?= htmlspecialchars($form['email']) ?>"
                               placeholder="Email address" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone *</label>
                        <input type="text" id="phone" name="phone"
                               value="<?= htmlspecialchars($form['phone']) ?>"
                               placeholder="Phone number" required>
                    </div>
                </div>

                <!-- Date of Birth -->
                <div class="form-group">
                    <label for="date_of_birth">Date of Birth *</label>
                    <input type="date" id="date_of_birth" name="date_of_birth"
                           value="<?= htmlspecialchars($form['date_of_birth']) ?>"
                           required max="<?= date('Y-m-d') ?>">
                </div>

                <!-- Password -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password"
                               placeholder="Min 8 chars, mixed case + number" required>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm">Confirm Password *</label>
                        <input type="password" id="password_confirm" name="password_confirm"
                               placeholder="Re-enter password" required>
                    </div>
                </div>
                <p class="password-hint">
                    At least 8 characters with uppercase, lowercase, and a number.
                </p>

                <!-- Parent/Guardian Section (shown for minors via JS) -->
                <div id="parent-fields" style="display:none; margin-top:1rem; padding:1rem; border:1px solid rgba(255,255,255,.15); border-radius:10px; background:rgba(255,255,255,.04);">
                    <div style="margin-bottom:.75rem;">
                        <strong style="font-size:.95rem; color:var(--primary-color, #64b5f6);">Parent / Guardian Information</strong>
                        <p style="font-size:.8rem; color:#9ca3af; margin-top:.25rem;">
                            Required for students under 18. The parent/guardian assumes responsibility for agreements and payments.
                        </p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="parent_first_name">Parent First Name *</label>
                            <input type="text" id="parent_first_name" name="parent_first_name"
                                   value="<?= htmlspecialchars($form['parent_first_name']) ?>"
                                   placeholder="Parent first name">
                        </div>
                        <div class="form-group">
                            <label for="parent_last_name">Parent Last Name *</label>
                            <input type="text" id="parent_last_name" name="parent_last_name"
                                   value="<?= htmlspecialchars($form['parent_last_name']) ?>"
                                   placeholder="Parent last name">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="parent_email">Parent Email *</label>
                            <input type="email" id="parent_email" name="parent_email"
                                   value="<?= htmlspecialchars($form['parent_email']) ?>"
                                   placeholder="Parent email address">
                        </div>
                        <div class="form-group">
                            <label for="parent_phone">Parent Phone *</label>
                            <input type="text" id="parent_phone" name="parent_phone"
                                   value="<?= htmlspecialchars($form['parent_phone']) ?>"
                                   placeholder="Parent phone number">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="parent_dob">Parent Date of Birth *</label>
                        <input type="date" id="parent_dob" name="parent_dob"
                               value="<?= htmlspecialchars($form['parent_dob']) ?>"
                               max="<?= date('Y-m-d') ?>">
                        <p style="font-size:.75rem; color:#9ca3af; margin-top:.25rem;">
                            Parent/guardian must be at least 18 years old.
                        </p>
                    </div>
                </div>

                <?php // ── Waiver Section ── ?>
                <?php if ($waiver_content !== ''): ?>
                <div class="waiver-section">
                    <label>Waiver of Liability *</label>
                    <div class="waiver-box"><?= nl2br(htmlspecialchars($waiver_content)) ?></div>
                    <label class="waiver-agree" id="waiver-agree-label">
                        <input type="checkbox" name="waiver_agree" value="1"
                               <?= !empty($_POST['waiver_agree']) ? 'checked' : '' ?>>
                        <span id="waiver-agree-text">I have read and agree to the waiver of liability</span>
                    </label>
                </div>
                <?php endif; ?>

                <?php // ── Communication Consent ── ?>
                <div class="waiver-section" style="margin-top:1rem;">
                    <label>Digital Communication Consent</label>
                    <div class="waiver-box"><?= nl2br(htmlspecialchars($comm_consent_text)) ?></div>
                    <div style="margin-top:.5rem;">
                        <label class="waiver-agree" style="display:block; margin-bottom:.35rem;">
                            <input type="checkbox" name="comm_consent_email" value="1"
                                   <?= !empty($_POST['comm_consent_email']) ? 'checked' : '' ?>>
                            I consent to receive <strong>email</strong> communications
                        </label>
                        <label class="waiver-agree" style="display:block;">
                            <input type="checkbox" name="comm_consent_sms" value="1"
                                   <?= !empty($_POST['comm_consent_sms']) ? 'checked' : '' ?>>
                            I consent to receive <strong>SMS/text message</strong> communications
                        </label>
                    </div>
                    <p style="font-size:.75rem; color:#6b7280; margin-top:.35rem;">
                        Consent is optional and not required for enrollment. You can update your preferences at any time from your profile page.
                    </p>
                </div>

                <?php // ── Plan Selection ── ?>
                <?php if (!empty($plans)): ?>
                <div style="margin-top:.5rem; margin-bottom:1.25rem;">
                    <div class="plan-section-title">Choose a Membership Plan</div>
                    <div class="plan-section-subtitle">Optional — you can skip this and enroll later</div>

                    <div class="plan-grid">
                        <!-- Skip option -->
                        <label class="plan-card plan-card-skip">
                            <input type="radio" name="plan_id" value=""
                                   <?= $form['plan_id'] === '' ? 'checked' : '' ?>>
                            <div class="plan-card-inner">
                                <div class="plan-card-name">Skip for Now</div>
                                <div class="plan-card-desc">No membership — you can be enrolled later by an administrator</div>
                            </div>
                        </label>

                        <?php foreach ($plans as $plan):
                            $billingFreq = $plan['billing_frequency'] ?? 'upfront';
                            $isMonthly   = ($billingFreq === 'monthly' && $plan['duration_months'] > 1);
                            $displayPrice = $isMonthly
                                ? '$' . number_format($plan['price'] / $plan['duration_months'], 2) . '/mo'
                                : '$' . number_format($plan['price'], 2);
                        ?>
                        <label class="plan-card">
                            <input type="radio" name="plan_id" value="<?= $plan['id'] ?>"
                                   <?= $form['plan_id'] == $plan['id'] ? 'checked' : '' ?>
                                   data-regfee="<?= (float)($plan['registration_fee'] ?? 0) ?>">
                            <div class="plan-card-inner">
                                <div class="plan-card-name"><?= htmlspecialchars($plan['name']) ?></div>
                                <div class="plan-card-price"><?= $displayPrice ?></div>
                                <div class="plan-card-duration">
                                    <?= $plan['duration_months'] ?> month<?= $plan['duration_months'] > 1 ? 's' : '' ?>
                                    <?php if ($isMonthly): ?>
                                        <span style="opacity:.6;">• $<?= number_format($plan['price'], 2) ?> total</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (isset($plan['registration_fee']) && $plan['registration_fee'] > 0): ?>
                                    <div class="plan-card-regfee">+ $<?= number_format($plan['registration_fee'], 2) ?> registration fee</div>
                                <?php endif; ?>
                                <?php if (!empty($plan['is_afterschool']) && $plan['program_start_date'] && $plan['program_end_date']): ?>
                                    <div style="margin-top:6px;">
                                        <span style="display:inline-block;background:#e0e7ff;color:#4338ca;font-size:.7rem;padding:2px 8px;border-radius:9999px;font-weight:600;">Afterschool Program</span>
                                        <div style="font-size:.75rem;color:#6366f1;margin-top:3px;">&#128197; <?= date('M j, Y', strtotime($plan['program_start_date'])) ?> &ndash; <?= date('M j, Y', strtotime($plan['program_end_date'])) ?></div>
                                        <div style="font-size:.7rem;color:#888;margin-top:2px;">Prorated if enrolling mid-program</div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($plan['description'])): ?>
                                    <div class="plan-card-desc"><?= htmlspecialchars($plan['description']) ?></div>
                                <?php endif; ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" id="submit-btn" class="btn btn-primary btn-block">Create Account</button>
            </form>

            <div class="login-footer">
                <a href="login.php" class="admin-link">&larr; Already have an account? Sign In</a>
            </div>

            <script>
            // Toggle button text based on plan selection
            document.querySelectorAll('input[name="plan_id"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    var btn = document.getElementById('submit-btn');
                    btn.textContent = this.value ? 'Continue to Payment' : 'Create Account';
                });
            });

            // ── Age-based parent/guardian toggle ──
            (function() {
                var dobInput     = document.getElementById('date_of_birth');
                var parentFields = document.getElementById('parent-fields');
                var waiverText   = document.getElementById('waiver-agree-text');
                var defaultWaiverText = 'I have read and agree to the waiver of liability';
                var parentWaiverText  = 'I, the parent/legal guardian, have read and agree to the waiver of liability on behalf of the student';

                if (!dobInput || !parentFields) return;

                function checkAge() {
                    var val = dobInput.value;
                    if (!val) {
                        parentFields.style.display = 'none';
                        toggleParentRequired(false);
                        if (waiverText) waiverText.textContent = defaultWaiverText;
                        return;
                    }

                    var parts = val.split('-');
                    var dob = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
                    var today = new Date();
                    var age = today.getFullYear() - dob.getFullYear();
                    var m = today.getMonth() - dob.getMonth();
                    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;

                    var isMinor = age < 18;
                    parentFields.style.display = isMinor ? 'block' : 'none';
                    toggleParentRequired(isMinor);

                    if (waiverText) {
                        waiverText.textContent = isMinor ? parentWaiverText : defaultWaiverText;
                    }
                }

                function toggleParentRequired(required) {
                    var inputs = parentFields.querySelectorAll('input');
                    for (var i = 0; i < inputs.length; i++) {
                        inputs[i].required = required;
                    }
                }

                dobInput.addEventListener('change', checkAge);
                dobInput.addEventListener('input', checkAge);

                // Check on page load (if DOB was pre-filled from form error re-render)
                checkAge();
            })();
            </script>

<?php // =============================================================
      //  STEP 2 — Payment
      // =============================================================
elseif ($step == 2 && $selected_plan): ?>

            <?php
            $chargeAmount = $feeBreakdown['total'];
            $isMonthly    = $proration['is_monthly'] ?? false;
            ?>

            <?php if ($isMinorFlow && $regParentName): ?>
            <div style="background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.25);padding:10px 14px;border-radius:8px;margin-bottom:1rem;font-size:.875rem;color:#93c5fd;">
                <strong>Parent/Guardian Payment:</strong> Payment will be processed through the account of
                <strong><?= htmlspecialchars($regParentName) ?></strong> (<?= htmlspecialchars($regParentEmail) ?>).
            </div>
            <?php endif; ?>

            <div class="payment-summary-card">
                <h3>Plan Summary</h3>
                <div class="payment-row">
                    <span>Plan</span>
                    <span><?= htmlspecialchars($selected_plan['name']) ?></span>
                </div>
                <div class="payment-row">
                    <span>Duration</span>
                    <span><?= $selected_plan['duration_months'] ?> month<?= $selected_plan['duration_months'] > 1 ? 's' : '' ?></span>
                </div>
                <?php if (!empty($selected_plan['is_afterschool']) && $selected_plan['program_start_date'] && $selected_plan['program_end_date']): ?>
                <div class="payment-row">
                    <span>Program Dates</span>
                    <span><?= date('M j, Y', strtotime($selected_plan['program_start_date'])) ?> &ndash; <?= date('M j, Y', strtotime($selected_plan['program_end_date'])) ?></span>
                </div>
                <div style="background:#e0e7ff;color:#4338ca;font-size:.75rem;padding:6px 10px;border-radius:8px;margin-top:4px;">
                    &#128161; This is an afterschool program. Your cost is prorated from today through the program end date. No auto-renewal.
                </div>
                <?php endif; ?>

                <div id="fee-breakdown-area">
                    <?= renderFeeBreakdownHtml($feeBreakdown, false, $isMonthly ? 'First Month' : 'Plan Price') ?>
                </div>
            </div>

            <!-- Discount Code Input -->
            <div class="discount-code-section" style="margin-bottom:1rem;">
                <label style="font-size:.875rem;font-weight:600;color:var(--text-color);display:block;margin-bottom:.25rem;">Discount Code</label>
                <div id="applied-codes-list" style="margin-bottom:.35rem;"></div>
                <div style="display:flex;gap:.5rem;">
                    <input type="text" id="discount-input" placeholder="Enter code"
                           style="flex:1;padding:.5rem .75rem;border:1px solid var(--border-color);border-radius:8px;font-size:.875rem;background:var(--card-bg);color:var(--text-color);">
                    <button type="button" id="apply-discount-btn"
                            style="padding:.5rem 1rem;border:none;border-radius:8px;background:var(--accent-color);color:#fff;font-size:.875rem;cursor:pointer;">
                        Apply
                    </button>
                </div>
                <div id="discount-message" style="font-size:.8rem;margin-top:.25rem;"></div>
            </div>

            <?php if ($activeGw !== 'none' && !empty($activeGw) && $chargeAmount > 0): ?>
                <!-- Wallet Pay (Apple Pay / Google Pay) -->
                <div id="wallet-pay-container" style="display:none;"></div>
                <div id="wallet-pay-divider" style="display:none;" class="flex items-center gap-3 my-4" style="display:flex;align-items:center;gap:.75rem;margin:1rem 0;">
                    <div style="flex:1;height:1px;background:var(--border-color,#ccc);"></div>
                    <span style="font-size:.85rem;color:#888;">or pay with card</span>
                    <div style="flex:1;height:1px;background:var(--border-color,#ccc);"></div>
                </div>

                <!-- Payment form -->
                <form method="POST" action="register.php?step=2<?= $schoolParam ?>" id="payment-form" class="login-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="process_payment" value="1">
                    <input type="hidden" name="stripe_pm_id" id="stripe_pm_id" value="">
                    <input type="hidden" name="discount_code" id="discount_code_hidden" value="<?= htmlspecialchars($discountCodeFromPost) ?>">

                    <div class="payment-form-section">
                        <div class="form-group">
                            <label>Card Information</label>
                            <div id="card-element" class="stripe-card-element"></div>
                            <div id="card-errors" class="card-errors"></div>
                        </div>

                        <button type="submit" id="pay-btn" class="btn btn-primary btn-block">
                            <span id="btn-text">Pay $<?= number_format($chargeAmount, 2) ?></span>
                            <span id="btn-spinner" style="display:none;">Processing...</span>
                        </button>
                    </div>
                </form>

                <!-- Skip payment option -->
                <form method="POST" action="register.php?step=2<?= $schoolParam ?>" style="margin-top:.5rem;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="skip_payment" value="1">
                    <button type="submit" class="skip-link" style="background:none;border:none;cursor:pointer;color:var(--accent-color);font-family:inherit;">
                        Skip payment &mdash; complete registration without membership
                    </button>
                </form>

                <?php if ($stripePk): ?>
                <script src="assets/js/wallet-pay.js"></script>
                <script>
                (function() {
                    const stripe = Stripe('<?= htmlspecialchars($stripePk) ?>');
                    const elements = stripe.elements();
                    const cardElement = elements.create('card', {
                        style: {
                            base: {
                                fontSize: '16px',
                                color: '#e0e0e0',
                                fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                                '::placeholder': { color: '#888' }
                            },
                            invalid: {
                                color: '#ef9a9a',
                                iconColor: '#ef9a9a'
                            }
                        }
                    });
                    cardElement.mount('#card-element');

                    const form      = document.getElementById('payment-form');
                    const payBtn    = document.getElementById('pay-btn');
                    const btnText   = document.getElementById('btn-text');
                    const btnSpin   = document.getElementById('btn-spinner');
                    const errDiv    = document.getElementById('card-errors');

                    form.addEventListener('submit', async function(e) {
                        e.preventDefault();
                        payBtn.disabled = true;
                        btnText.style.display = 'none';
                        btnSpin.style.display = 'inline';

                        const { paymentMethod, error } = await stripe.createPaymentMethod({
                            type: 'card',
                            card: cardElement,
                        });

                        if (error) {
                            errDiv.textContent = error.message;
                            payBtn.disabled = false;
                            btnText.style.display = 'inline';
                            btnSpin.style.display = 'none';
                            return;
                        }

                        document.getElementById('stripe_pm_id').value = paymentMethod.id;
                        form.submit();
                    });

                    // ── Discount code AJAX (multi-code) ──
                    const discountInput  = document.getElementById('discount-input');
                    const applyBtn       = document.getElementById('apply-discount-btn');
                    const discountMsg    = document.getElementById('discount-message');
                    const discountHidden = document.getElementById('discount_code_hidden');
                    const breakdownArea  = document.getElementById('fee-breakdown-area');
                    const appliedList    = document.getElementById('applied-codes-list');
                    let appliedCodes = [];

                    function renderAppliedCodes(perCodeDetails) {
                        if (!appliedList) return;
                        if (!perCodeDetails || perCodeDetails.length === 0) {
                            appliedList.innerHTML = '';
                            return;
                        }
                        let html = '';
                        perCodeDetails.forEach(function(d) {
                            html += '<span style="display:inline-flex;align-items:center;gap:.25rem;background:var(--accent-color,#4caf50);color:#fff;padding:.15rem .5rem;border-radius:12px;font-size:.75rem;margin-right:.35rem;margin-bottom:.25rem;">'
                                + d.code + ' (-$' + d.total_discount.toFixed(2) + ')'
                                + ' <button type="button" onclick="removeDiscountCode(\'' + d.code + '\')" style="background:none;border:none;color:#fff;cursor:pointer;font-size:.85rem;padding:0 2px;">&times;</button>'
                                + '</span>';
                        });
                        appliedList.innerHTML = html;
                    }

                    window.removeDiscountCode = function(code) {
                        appliedCodes = appliedCodes.filter(function(c) { return c.toUpperCase() !== code.toUpperCase(); });
                        discountHidden.value = appliedCodes.join(',');
                        // Re-validate with remaining codes
                        if (appliedCodes.length === 0) {
                            appliedList.innerHTML = '';
                            discountMsg.innerHTML = '';
                            btnText.textContent = 'Pay $' + parseFloat('<?= $feeBreakdown['total'] ?>').toFixed(2);
                            breakdownArea.innerHTML = '<?= addslashes(renderFeeBreakdownHtml($feeBreakdown, false, $isMonthly ? "First Month" : "Plan Price")) ?>';
                            return;
                        }
                        revalidateAll();
                    };

                    async function revalidateAll() {
                        const fd = new FormData();
                        fd.append('code', appliedCodes[appliedCodes.length - 1]);
                        fd.append('existing_codes', appliedCodes.slice(0, -1).join(','));
                        fd.append('plan_id', '<?= $regPlanId ?>');
                        fd.append('base_amount', '<?= $proration['amount'] ?>');
                        fd.append('registration_fee', '<?= $regFee ?>');
                        try {
                            const resp = await fetch('ajax_validate_discount.php', { method: 'POST', body: fd });
                            const data = await resp.json();
                            if (data.valid) {
                                updateBreakdown(data);
                                renderAppliedCodes(data.per_code_details || []);
                            }
                        } catch(e) {}
                    }

                    function updateBreakdown(data) {
                        btnText.textContent = 'Pay $' + parseFloat(data.total).toFixed(2);
                        let html = '';
                        html += row('<?= $isMonthly ? 'First Month' : 'Plan Price' ?>:', '$' + parseFloat('<?= $proration['amount'] ?>').toFixed(2));
                        <?php if ($regFee > 0): ?>
                        html += row('Registration Fee:', '$<?= number_format($regFee, 2) ?>');
                        <?php endif; ?>
                        if (data.per_code_details && data.per_code_details.length > 1) {
                            data.per_code_details.forEach(function(d) {
                                if (d.total_discount > 0) {
                                    html += '<div class="payment-row"><span class="text-green">Discount (' + d.code + '):</span><span class="text-green">-$' + d.total_discount.toFixed(2) + '</span></div>';
                                }
                            });
                        } else if (data.total_discount > 0) {
                            html += '<div class="payment-row"><span class="text-green">Discount (' + (data.all_codes || appliedCodes.join(',')) + '):</span><span class="text-green">-$' + data.total_discount.toFixed(2) + '</span></div>';
                        }
                        if (data.service_fee > 0) {
                            html += row('Service Fee (<?= number_format($feeBreakdown['service_fee_percentage'], 2) ?>%):', '$' + data.service_fee.toFixed(2));
                        }
                        html += '<div class="payment-row fee-total-row"><span class="fee-total-label">Total Due:</span><span class="fee-total-value">$' + data.total.toFixed(2) + '</span></div>';
                        breakdownArea.innerHTML = '<div class="fee-breakdown">' + html + '</div>';
                    }

                    if (applyBtn) {
                        applyBtn.addEventListener('click', async function() {
                            const code = (discountInput.value || '').trim();
                            if (!code) {
                                discountMsg.innerHTML = '<span style="color:#ef5350;">Please enter a code.</span>';
                                return;
                            }
                            applyBtn.disabled = true;
                            applyBtn.textContent = '...';

                            const fd = new FormData();
                            fd.append('code', code);
                            fd.append('existing_codes', appliedCodes.join(','));
                            fd.append('plan_id', '<?= $regPlanId ?>');
                            fd.append('base_amount', '<?= $proration['amount'] ?>');
                            fd.append('registration_fee', '<?= $regFee ?>');

                            try {
                                const resp = await fetch('ajax_validate_discount.php', { method: 'POST', body: fd });
                                const data = await resp.json();

                                if (data.valid) {
                                    discountMsg.innerHTML = '<span style="color:#4caf50;">' + data.message + '</span>';
                                    appliedCodes = (data.all_codes || code).split(',').filter(Boolean);
                                    discountHidden.value = appliedCodes.join(',');
                                    discountInput.value = '';
                                    updateBreakdown(data);
                                    renderAppliedCodes(data.per_code_details || []);
                                } else {
                                    discountMsg.innerHTML = '<span style="color:#ef5350;">' + data.error + '</span>';
                                }
                            } catch (err) {
                                discountMsg.innerHTML = '<span style="color:#ef5350;">Unable to validate code. Please try again.</span>';
                            }
                            applyBtn.disabled = false;
                            applyBtn.textContent = 'Apply';
                        });
                    }

                    function row(label, val) {
                        return '<div class="payment-row"><span>' + label + '</span><span>' + val + '</span></div>';
                    }

                    // Initialize Wallet Pay (Apple Pay / Google Pay)
                    initWalletPay(stripe, {
                        amount:      <?= (int) round($chargeAmount * 100) ?>,
                        label:       <?= json_encode($selected_plan['name'] ?? 'Registration') ?>,
                        containerId: 'wallet-pay-container',
                        dividerId:   'wallet-pay-divider',
                        onToken: function(pm) {
                            document.getElementById('stripe_pm_id').value = pm.id;
                            form.submit();
                        }
                    });
                })();
                </script>
                <?php endif; ?>

            <?php else: ?>
                <!-- No payment gateway configured -->
                <div class="gateway-notice">
                    <strong>Payment Processing Unavailable</strong><br>
                    Online payment is not currently configured. Your account has been created
                    but your membership will need to be activated by the studio.
                    <br><br>
                    <strong>To complete enrollment:</strong>
                    <ul style="margin-top:.5rem; padding-left:1.25rem; list-style:disc;">
                        <li>Visit the studio in person to make payment</li>
                        <li>Or contact us for alternative payment arrangements</li>
                    </ul>
                </div>

                <form method="POST" action="register.php?step=2<?= $schoolParam ?>" class="login-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="skip_payment" value="1">
                    <button type="submit" class="btn btn-primary btn-block">
                        Complete Registration
                    </button>
                </form>
            <?php endif; ?>

            <div class="login-footer">
                <a href="student_portal.php" class="admin-link">Go to Student Portal &rarr;</a>
            </div>

<?php // =============================================================
      //  STEP 3 — Success
      // =============================================================
elseif ($step == 3): ?>

            <?php
            $successParentName  = $_SESSION['registration_success_parent_name'] ?? '';
            $successParentEmail = $_SESSION['registration_success_parent_email'] ?? '';
            unset($_SESSION['registration_success_parent_name'], $_SESSION['registration_success_parent_email']);
            ?>

            <div class="success-message">
                <div class="success-icon">&#10004;</div>
                <h2>Registration Complete!</h2>
                <p>Your account has been created<?= $successParentName ? '' : ' and your membership is now active' ?>. Welcome to <?= htmlspecialchars($theme['studio_name']) ?>!</p>

                <?php if ($successParentName): ?>
                <div style="background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.25);padding:12px 16px;border-radius:8px;margin:1rem 0;font-size:.875rem;color:#93c5fd;text-align:left;">
                    <strong>Parent/Guardian Account Created</strong><br>
                    A parent account has been created for <strong><?= htmlspecialchars($successParentName) ?></strong>
                    (<?= htmlspecialchars($successParentEmail) ?>).<br>
                    The parent can log in by using the "Forgot Password" link with their email to set a password.
                </div>
                <?php endif; ?>

                <a href="student_portal.php" class="btn btn-primary btn-block">Go to Student Portal</a>
            </div>

            <div class="login-footer">
                <a href="login.php" class="admin-link">Back to Login</a>
            </div>

<?php endif; ?>

        </div>
    </div>
</body>
</html>
