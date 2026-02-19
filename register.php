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

auth_start_session();

// ─── Idempotent schema migrations ───────────────────────────────────
$pdo = get_db();

try { $pdo->exec("ALTER TABLE students ADD COLUMN username VARCHAR(50) DEFAULT NULL"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE students ADD UNIQUE INDEX idx_students_username (username)"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE students ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE students ADD COLUMN belt_rank VARCHAR(50) DEFAULT 'White'"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE students ADD COLUMN waiver_accepted_at DATETIME DEFAULT NULL"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE students ADD COLUMN waiver_version VARCHAR(50) DEFAULT NULL"); } catch (\PDOException $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attempts_user (username, attempted_at),
        INDEX idx_attempts_ip (ip_address, attempted_at)
    )");
} catch (\PDOException $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS studio_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        config_key VARCHAR(100) NOT NULL UNIQUE,
        config_value TEXT NOT NULL
    )");
} catch (\PDOException $e) {}

// Detect available columns
$cols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll();
$colNames = array_column($cols, 'Field');
$hasPasswordHash = in_array('password_hash', $colNames, true);
$hasPassword     = in_array('password', $colNames, true);
$passwordCol     = $hasPasswordHash ? 'password_hash' : ($hasPassword ? 'password' : 'password_hash');
$hasUsername      = in_array('username', $colNames, true);

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
$plans           = $pdo->query("SELECT * FROM membership_plans WHERE status = 'active' ORDER BY price ASC")->fetchAll();

// Stripe publishable key for Step 2
$stripePk   = '';
$activeGw   = getSetting('active_payment_gateway', 'none');
if ($activeGw === 'stripe') {
    $stripePk = getSetting('stripe_publishable_key', '');
}

$form = [
    'username'   => '',
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'phone'      => '',
    'plan_id'    => '',
];

// =====================================================================
//  STEP 1 — Account creation
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step == 1) {
    verify_csrf();

    $form['username']   = trim($_POST['username']   ?? '');
    $form['first_name'] = trim($_POST['first_name'] ?? '');
    $form['last_name']  = trim($_POST['last_name']  ?? '');
    $form['email']      = trim($_POST['email']      ?? '');
    $form['phone']      = trim($_POST['phone']      ?? '');
    $form['plan_id']    = $_POST['plan_id']          ?? '';
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

    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
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
        $stmt = $pdo->prepare('SELECT id FROM students WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $form['username']]);
        if ($stmt->fetch()) {
            $errors[] = 'That username is already taken.';
        }
    }

    if (empty($errors) && $form['email'] !== '') {
        $stmt = $pdo->prepare('SELECT id FROM students WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $form['email']]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    // ── Create the account ──
    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $insertCols   = ['first_name', 'last_name', 'email', 'phone', 'join_date'];
        $insertVals   = [':fn', ':ln', ':em', ':ph', ':jd'];
        $insertParams = [
            ':fn' => $form['first_name'],
            ':ln' => $form['last_name'],
            ':em' => $form['email'] ?: null,
            ':ph' => $form['phone'] ?: null,
            ':jd' => date('Y-m-d'),
        ];

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

        $colList = implode(', ', $insertCols);
        $valList = implode(', ', $insertVals);

        $insert = $pdo->prepare("INSERT INTO students ({$colList}) VALUES ({$valList})");
        $insert->execute($insertParams);

        $newId   = $pdo->lastInsertId();
        $stmt    = $pdo->prepare('SELECT * FROM students WHERE id = :id');
        $stmt->execute([':id' => $newId]);
        $student = $stmt->fetch();

        login_student($student);

        // Plan selected? → Go to payment step
        if (!empty($form['plan_id'])) {
            $_SESSION['registration_student_id'] = (int) $newId;
            $_SESSION['registration_plan_id']    = (int) $form['plan_id'];
            header('Location: register.php?step=2');
            exit;
        }

        // No plan → straight to portal
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

    $stmt = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?");
    $stmt->execute([$regPlanId]);
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
            // Save the card first (Stripe)
            $stripePmId = trim($_POST['stripe_pm_id'] ?? '');
            if ($activeGw === 'stripe' && $stripePmId !== '') {
                $saveResult = save_card_from_token($regStudentId, $stripePmId, 'Registration Card');
                if (!$saveResult['success']) {
                    $payment_error = $saveResult['error'] ?? 'Failed to save payment method.';
                }
            }

            // Charge the student
            if ($payment_error === '') {
                $desc = 'Membership enrollment: ' . $selected_plan['name'];
                if ($feeBreakdown['service_fee'] > 0) {
                    $desc .= ' (incl. service fee)';
                }
                $chargeResult = charge_student($regStudentId, $chargeAmount, $desc);
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
                INSERT INTO memberships (student_id, plan_id, start_date, end_date, status, payment_status, amount_paid, auto_renew, billing_day, monthly_charges_made)
                VALUES (?, ?, ?, ?, 'active', 'paid', ?, ?, ?, ?)
            ");
            $billingDay      = $isMonthly ? min((int) date('j'), 28) : null;
            $chargesMade     = $isMonthly ? 1 : 0;
            $mStmt->execute([
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
                if ($feeBreakdown['discount_amount'] > 0) {
                    $notes .= ' | Discount: -$' . number_format($feeBreakdown['discount_amount'], 2) . ' (' . $feeBreakdown['discount_code'] . ')';
                }
                if ($feeBreakdown['service_fee'] > 0) {
                    $notes .= ' | Service fee: $' . number_format($feeBreakdown['service_fee'], 2);
                }
                if ($regFee > 0) {
                    $notes .= ' | Reg fee: $' . number_format($regFee, 2);
                }
                $pStmt = $pdo->prepare("
                    INSERT INTO payments (student_id, payment_type, reference_id, amount, payment_method, payment_date, receipt_number, notes)
                    VALUES (?, 'membership', ?, ?, 'credit_card', CURDATE(), ?, ?)
                ");
                $pStmt->execute([
                    $regStudentId,
                    $membershipId,
                    $chargeAmount,
                    generateReceiptNumber(),
                    $notes,
                ]);
            }

            // Record discount code usage
            if ($feeBreakdown['discount_code_id']) {
                recordDiscountCodeUse(
                    $feeBreakdown['discount_code_id'],
                    $regStudentId,
                    $feeBreakdown['discount_amount'],
                    'registration',
                    $membershipId
                );
            }

            // Clean up session
            unset($_SESSION['registration_student_id'], $_SESSION['registration_plan_id']);

            header('Location: register.php?step=3');
            exit;
        }
    }

    // ── Skip payment POST (creates account without membership) ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['skip_payment'])) {
        verify_csrf();
        unset($_SESSION['registration_student_id'], $_SESSION['registration_plan_id']);
        header('Location: student_portal.php');
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
                <p class="login-tagline">Create Your Account</p>
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
      //  STEP 1 — Registration Form
      // =============================================================
if ($step == 1): ?>

            <form method="POST" action="register.php?step=1" class="login-form" autocomplete="on">
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
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email"
                               value="<?= htmlspecialchars($form['email']) ?>"
                               placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="text" id="phone" name="phone"
                               value="<?= htmlspecialchars($form['phone']) ?>"
                               placeholder="Optional">
                    </div>
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

                <?php // ── Waiver Section ── ?>
                <?php if ($waiver_content !== ''): ?>
                <div class="waiver-section">
                    <label>Waiver of Liability *</label>
                    <div class="waiver-box"><?= nl2br(htmlspecialchars($waiver_content)) ?></div>
                    <label class="waiver-agree">
                        <input type="checkbox" name="waiver_agree" value="1"
                               <?= !empty($_POST['waiver_agree']) ? 'checked' : '' ?>>
                        I have read and agree to the waiver of liability
                    </label>
                </div>
                <?php endif; ?>

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
            </script>

<?php // =============================================================
      //  STEP 2 — Payment
      // =============================================================
elseif ($step == 2 && $selected_plan): ?>

            <?php
            $chargeAmount = $feeBreakdown['total'];
            $isMonthly    = $proration['is_monthly'] ?? false;
            ?>

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
                <div style="display:flex;gap:.5rem;">
                    <input type="text" id="discount-input" placeholder="Enter code"
                           value="<?= htmlspecialchars($discountCodeFromPost) ?>"
                           style="flex:1;padding:.5rem .75rem;border:1px solid var(--border-color);border-radius:8px;font-size:.875rem;background:var(--card-bg);color:var(--text-color);">
                    <button type="button" id="apply-discount-btn"
                            style="padding:.5rem 1rem;border:none;border-radius:8px;background:var(--accent-color);color:#fff;font-size:.875rem;cursor:pointer;">
                        Apply
                    </button>
                </div>
                <div id="discount-message" style="font-size:.8rem;margin-top:.25rem;"></div>
            </div>

            <?php if ($activeGw !== 'none' && !empty($activeGw) && $chargeAmount > 0): ?>
                <!-- Payment form -->
                <form method="POST" action="register.php?step=2" id="payment-form" class="login-form">
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
                <form method="POST" action="register.php?step=2" style="margin-top:.5rem;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="skip_payment" value="1">
                    <button type="submit" class="skip-link" style="background:none;border:none;cursor:pointer;color:var(--accent-color);font-family:inherit;">
                        Skip payment &mdash; complete registration without membership
                    </button>
                </form>

                <?php if ($stripePk): ?>
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

                    // ── Discount code AJAX ──
                    const discountInput  = document.getElementById('discount-input');
                    const applyBtn       = document.getElementById('apply-discount-btn');
                    const discountMsg    = document.getElementById('discount-message');
                    const discountHidden = document.getElementById('discount_code_hidden');
                    const breakdownArea  = document.getElementById('fee-breakdown-area');

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
                            fd.append('plan_id', '<?= $regPlanId ?>');
                            fd.append('base_amount', '<?= $proration['amount'] ?>');
                            fd.append('registration_fee', '<?= $regFee ?>');

                            try {
                                const resp = await fetch('ajax_validate_discount.php', { method: 'POST', body: fd });
                                const data = await resp.json();

                                if (data.valid) {
                                    discountMsg.innerHTML = '<span style="color:#4caf50;">' + data.message + '</span>';
                                    discountHidden.value = code;

                                    // Update pay button
                                    btnText.textContent = 'Pay $' + parseFloat(data.total).toFixed(2);

                                    // Re-render breakdown via server (reload with code in URL would be complex, so update inline)
                                    let html = '';
                                    html += row('<?= $isMonthly ? 'First Month' : 'Plan Price' ?>:', '$' + parseFloat('<?= $proration['amount'] ?>').toFixed(2));
                                    <?php if ($regFee > 0): ?>
                                    html += row('Registration Fee:', '$<?= number_format($regFee, 2) ?>');
                                    <?php endif; ?>
                                    if (data.total_discount > 0) {
                                        html += '<div class="payment-row"><span class="text-green">Discount (' + code + '):</span><span class="text-green">-$' + data.total_discount.toFixed(2) + '</span></div>';
                                    }
                                    if (data.service_fee > 0) {
                                        html += row('Service Fee (<?= number_format($feeBreakdown['service_fee_percentage'], 2) ?>%):', '$' + data.service_fee.toFixed(2));
                                    }
                                    html += '<div class="payment-row fee-total-row"><span class="fee-total-label">Total Due:</span><span class="fee-total-value">$' + data.total.toFixed(2) + '</span></div>';
                                    breakdownArea.innerHTML = '<div class="fee-breakdown">' + html + '</div>';
                                } else {
                                    discountMsg.innerHTML = '<span style="color:#ef5350;">' + data.error + '</span>';
                                    discountHidden.value = '';
                                }
                            } catch (err) {
                                discountMsg.innerHTML = '<span style="color:#ef5350;">Error validating code.</span>';
                            }
                            applyBtn.disabled = false;
                            applyBtn.textContent = 'Apply';
                        });
                    }

                    function row(label, val) {
                        return '<div class="payment-row"><span>' + label + '</span><span>' + val + '</span></div>';
                    }
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

                <form method="POST" action="register.php?step=2" class="login-form">
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

            <div class="success-message">
                <div class="success-icon">&#10004;</div>
                <h2>Registration Complete!</h2>
                <p>Your account has been created and your membership is now active. Welcome to <?= htmlspecialchars($theme['studio_name']) ?>!</p>
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
