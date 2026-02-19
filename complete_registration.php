<?php
/**
 * complete_registration.php — Finish Registration for Imported Students
 *
 * Imported students (from MyStudio or other platforms) are created with a
 * default password ("Procomp123") and flagged with:
 *   - must_change_password = 1
 *   - registration_incomplete = 1
 *
 * On first login they are redirected here and MUST:
 *   1. Set a new password (meeting strength requirements)
 *   2. Complete missing profile fields (email, phone, etc.)
 *
 * They cannot access the student portal until both flags are cleared.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/db.php';

require_student();

$theme     = get_theme();
$pdo       = get_db();
$studentId = $_SESSION['student_id'];

// If neither flag is set, they don't belong here — send to portal
if (empty($_SESSION['must_change_password']) && empty($_SESSION['registration_incomplete'])) {
    header('Location: student_portal.php');
    exit;
}

// Load current profile
$stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $studentId]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: logout.php');
    exit;
}

// Detect available columns
$cols     = $pdo->query("SHOW COLUMNS FROM students")->fetchAll();
$colNames = array_column($cols, 'Field');

$success = '';
$errors  = [];

// Load waiver content if configured
$waiver_content = '';
$waiver_version = '1.0';
if (function_exists('getSetting')) {
    $waiver_content = getSetting('waiver_content', '');
    $waiver_version = getSetting('waiver_version', '1.0');
}

// Check if waiver was previously accepted
$waiver_already_accepted = !empty($student['waiver_accepted_at']);

// ---------- Handle form submission ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // ── Collect fields ──
    $new_password      = $_POST['new_password']      ?? '';
    $confirm_password  = $_POST['confirm_password']   ?? '';
    $email             = trim($_POST['email']         ?? '');
    $phone             = trim($_POST['phone']         ?? '');
    $date_of_birth     = trim($_POST['date_of_birth'] ?? '');
    $address           = trim($_POST['address']       ?? '');
    $emergency_name    = trim($_POST['emergency_contact_name']  ?? '');
    $emergency_phone   = trim($_POST['emergency_contact_phone'] ?? '');

    // ── Validate password change (required) ──
    if (!empty($_SESSION['must_change_password'])) {
        if ($new_password === '') {
            $errors[] = 'You must set a new password.';
        } else {
            $pwErr = validate_password($new_password);
            if ($pwErr !== '') {
                $errors[] = $pwErr;
            } elseif ($new_password !== $confirm_password) {
                $errors[] = 'Passwords do not match.';
            } elseif ($new_password === 'Procomp123') {
                $errors[] = 'You cannot reuse the default password. Please choose a new one.';
            }
        }
    }

    // ── Validate required profile fields ──
    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Check email uniqueness (excluding current student)
    if ($email !== '' && empty($errors)) {
        $emailCheck = $pdo->prepare('SELECT id FROM students WHERE email = ? AND id != ? LIMIT 1');
        $emailCheck->execute([$email, $studentId]);
        if ($emailCheck->fetch()) {
            $errors[] = 'That email address is already in use by another account.';
        }
    }

    // ── Validate waiver if required and not previously accepted ──
    if ($waiver_content !== '' && !$waiver_already_accepted && empty($_POST['waiver_agree'])) {
        $errors[] = 'You must read and agree to the waiver to continue.';
    }

    // ── Save changes ──
    if (empty($errors)) {
        $updateFields = [];
        $updateParams = [];

        // Password
        if (!empty($_SESSION['must_change_password']) && $new_password !== '') {
            $updateFields[] = 'password_hash = ?';
            $updateParams[] = password_hash($new_password, PASSWORD_DEFAULT);
            $updateFields[] = 'must_change_password = 0';
        }

        // Email
        $updateFields[] = 'email = ?';
        $updateParams[] = $email;

        // Phone
        if (in_array('phone', $colNames, true)) {
            $updateFields[] = 'phone = ?';
            $updateParams[] = $phone ?: null;
        }

        // Date of birth
        if (in_array('date_of_birth', $colNames, true) && $date_of_birth !== '') {
            $updateFields[] = 'date_of_birth = ?';
            $updateParams[] = $date_of_birth;
        }

        // Address
        if (in_array('address', $colNames, true) && $address !== '') {
            $updateFields[] = 'address = ?';
            $updateParams[] = $address;
        }

        // Emergency contact
        if (in_array('emergency_contact_name', $colNames, true)) {
            $updateFields[] = 'emergency_contact_name = ?';
            $updateParams[] = $emergency_name ?: null;
        }
        if (in_array('emergency_contact_phone', $colNames, true)) {
            $updateFields[] = 'emergency_contact_phone = ?';
            $updateParams[] = $emergency_phone ?: null;
        }

        // Waiver acceptance
        if ($waiver_content !== '' && !$waiver_already_accepted && !empty($_POST['waiver_agree'])) {
            if (in_array('waiver_accepted_at', $colNames, true)) {
                $updateFields[] = 'waiver_accepted_at = ?';
                $updateParams[] = date('Y-m-d H:i:s');
            }
            if (in_array('waiver_version', $colNames, true)) {
                $updateFields[] = 'waiver_version = ?';
                $updateParams[] = $waiver_version;
            }
        }

        // Clear incomplete flag
        $updateFields[] = 'registration_incomplete = 0';

        $updateParams[] = $studentId;
        $sql = "UPDATE students SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $pdo->prepare($sql)->execute($updateParams);

        // Clear session flags
        $_SESSION['must_change_password']    = false;
        $_SESSION['registration_incomplete'] = false;

        // Refresh session name in case it changed
        $_SESSION['first_name'] = $student['first_name'];
        $_SESSION['last_name']  = $student['last_name'];

        // Redirect to portal
        header('Location: student_portal.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Your Registration &mdash; <?= htmlspecialchars($theme['studio_name']) ?></title>
    <?php if (!empty($theme['favicon_url'])): ?>
        <link rel="icon" href="<?= htmlspecialchars($theme['favicon_url']) ?>">
    <?php endif; ?>
    <style><?= theme_css_vars() ?></style>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .form-card { max-width: 640px; margin: 0 auto; }
        .field-group { margin-bottom: 1.25rem; }
        .field-group label { display: block; font-weight: 600; margin-bottom: 0.25rem; font-size: 0.875rem; color: #374151; }
        .field-group input, .field-group textarea { width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #d1d5db; border-radius: 0.5rem; font-size: 0.9375rem; transition: border-color 0.15s; }
        .field-group input:focus, .field-group textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
        .required-star { color: #ef4444; }
        .pw-requirements { font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem; }
        .pw-requirements li.met { color: #059669; }
        .pw-requirements li.met::before { content: "\2713 "; }
        .pw-requirements li.unmet::before { content: "\2717 "; color: #ef4444; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <!-- Top bar -->
    <nav class="bg-white shadow-sm border-b border-gray-200 px-6 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <?php if (!empty($theme['logo_url'])): ?>
                <img src="<?= htmlspecialchars($theme['logo_url']) ?>" alt="" class="h-8">
            <?php endif; ?>
            <span class="font-bold text-gray-800"><?= htmlspecialchars($theme['studio_name']) ?></span>
        </div>
        <div class="flex items-center gap-4 text-sm">
            <span class="text-gray-600">Welcome, <?= htmlspecialchars($student['first_name']) ?>!</span>
            <a href="logout.php" class="text-red-600 hover:text-red-800 font-medium">Sign Out</a>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8">
        <div class="form-card">

            <!-- Welcome Banner -->
            <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 mb-6 text-center">
                <div class="text-4xl mb-2">&#x1F44B;</div>
                <h1 class="text-2xl font-bold text-gray-800 mb-2">Welcome, <?= htmlspecialchars($student['first_name']) ?>!</h1>
                <p class="text-gray-600">Your account has been set up. Please complete the steps below to finish your registration and access the student portal.</p>
            </div>

            <!-- Errors -->
            <?php if (!empty($errors)): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                <h3 class="font-semibold text-red-800 mb-1">Please fix the following:</h3>
                <ul class="text-sm text-red-700 list-disc list-inside">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST" class="bg-white rounded-xl shadow-lg overflow-hidden">
                <?= csrf_field() ?>

                <!-- Step 1: Password -->
                <?php if (!empty($_SESSION['must_change_password'])): ?>
                <div class="border-b border-gray-200 p-6">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-8 h-8 bg-red-100 text-red-600 rounded-full flex items-center justify-center font-bold text-sm">1</div>
                        <h2 class="text-lg font-bold text-gray-800">Change Your Password</h2>
                        <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-medium">Required</span>
                    </div>
                    <p class="text-sm text-gray-500 mb-4">For security, you must change your password from the default before continuing.</p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="field-group">
                            <label for="new_password">New Password <span class="required-star">*</span></label>
                            <input type="password" id="new_password" name="new_password" required
                                   placeholder="Enter new password" autocomplete="new-password"
                                   oninput="checkPasswordStrength(this.value)">
                            <ul class="pw-requirements mt-2 space-y-0.5" id="pw-reqs">
                                <li id="pw-len" class="unmet">At least 8 characters</li>
                                <li id="pw-upper" class="unmet">One uppercase letter</li>
                                <li id="pw-lower" class="unmet">One lowercase letter</li>
                                <li id="pw-num" class="unmet">One number</li>
                            </ul>
                        </div>
                        <div class="field-group">
                            <label for="confirm_password">Confirm Password <span class="required-star">*</span></label>
                            <input type="password" id="confirm_password" name="confirm_password" required
                                   placeholder="Confirm new password" autocomplete="new-password">
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Step 2: Profile Info -->
                <div class="p-6 <?php echo !empty($_SESSION['must_change_password']) ? '' : 'border-b-0'; ?>">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-8 h-8 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center font-bold text-sm">
                            <?= !empty($_SESSION['must_change_password']) ? '2' : '1' ?>
                        </div>
                        <h2 class="text-lg font-bold text-gray-800">Complete Your Profile</h2>
                    </div>
                    <p class="text-sm text-gray-500 mb-4">Please provide or verify your contact information. Fields marked with <span class="required-star">*</span> are required.</p>

                    <!-- Name (read-only) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div class="field-group">
                            <label>First Name</label>
                            <input type="text" value="<?= htmlspecialchars($student['first_name']) ?>" disabled
                                   class="bg-gray-50 text-gray-500 cursor-not-allowed">
                        </div>
                        <div class="field-group">
                            <label>Last Name</label>
                            <input type="text" value="<?= htmlspecialchars($student['last_name']) ?>" disabled
                                   class="bg-gray-50 text-gray-500 cursor-not-allowed">
                        </div>
                    </div>

                    <!-- Username (read-only) -->
                    <div class="field-group mb-4">
                        <label>Username</label>
                        <input type="text" value="<?= htmlspecialchars($student['username'] ?? '') ?>" disabled
                               class="bg-gray-50 text-gray-500 cursor-not-allowed">
                        <p class="text-xs text-gray-500 mt-1">This is your login username. You can also log in with your email address.</p>
                    </div>

                    <!-- Email (required) -->
                    <div class="field-group">
                        <label for="email">Email Address <span class="required-star">*</span></label>
                        <input type="email" id="email" name="email" required
                               value="<?= htmlspecialchars($_POST['email'] ?? $student['email'] ?? '') ?>"
                               placeholder="your.email@example.com">
                        <p class="text-xs text-gray-500 mt-1">You can use this email to log in instead of your username.</p>
                    </div>

                    <!-- Phone -->
                    <div class="field-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone"
                               value="<?= htmlspecialchars($_POST['phone'] ?? $student['phone'] ?? '') ?>"
                               placeholder="(555) 123-4567">
                    </div>

                    <!-- Date of Birth -->
                    <?php if (in_array('date_of_birth', $colNames, true)): ?>
                    <div class="field-group">
                        <label for="date_of_birth">Date of Birth</label>
                        <input type="date" id="date_of_birth" name="date_of_birth"
                               value="<?= htmlspecialchars($_POST['date_of_birth'] ?? $student['date_of_birth'] ?? '') ?>">
                    </div>
                    <?php endif; ?>

                    <!-- Address -->
                    <?php if (in_array('address', $colNames, true)): ?>
                    <div class="field-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" rows="2"
                                  placeholder="Street address, City, State ZIP"><?= htmlspecialchars($_POST['address'] ?? $student['address'] ?? '') ?></textarea>
                    </div>
                    <?php endif; ?>

                    <!-- Emergency Contact -->
                    <?php if (in_array('emergency_contact_name', $colNames, true)): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="field-group">
                            <label for="emergency_contact_name">Emergency Contact Name</label>
                            <input type="text" id="emergency_contact_name" name="emergency_contact_name"
                                   value="<?= htmlspecialchars($_POST['emergency_contact_name'] ?? $student['emergency_contact_name'] ?? '') ?>"
                                   placeholder="Full name">
                        </div>
                        <div class="field-group">
                            <label for="emergency_contact_phone">Emergency Contact Phone</label>
                            <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone"
                                   value="<?= htmlspecialchars($_POST['emergency_contact_phone'] ?? $student['emergency_contact_phone'] ?? '') ?>"
                                   placeholder="(555) 123-4567">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Waiver (if configured and not previously accepted) -->
                <?php if ($waiver_content !== '' && !$waiver_already_accepted): ?>
                <div class="border-t border-gray-200 p-6">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-8 h-8 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center font-bold text-sm">
                            <?= !empty($_SESSION['must_change_password']) ? '3' : '2' ?>
                        </div>
                        <h2 class="text-lg font-bold text-gray-800">Waiver Agreement</h2>
                        <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-medium">Required</span>
                    </div>

                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 max-h-48 overflow-y-auto mb-4 text-sm text-gray-700 leading-relaxed">
                        <?= nl2br(htmlspecialchars($waiver_content)) ?>
                    </div>

                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="waiver_agree" value="1" class="w-5 h-5 text-blue-600 rounded"
                               <?= !empty($_POST['waiver_agree']) ? 'checked' : '' ?>>
                        <span class="text-sm font-medium text-gray-700">I have read and agree to the waiver above <span class="required-star">*</span></span>
                    </label>
                </div>
                <?php endif; ?>

                <!-- Submit -->
                <div class="bg-gray-50 border-t border-gray-200 p-6 flex justify-between items-center">
                    <a href="logout.php" class="text-gray-500 hover:text-gray-700 text-sm font-medium">Sign Out</a>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-8 py-3 rounded-lg shadow-sm transition-colors">
                        Complete Registration &amp; Continue
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function checkPasswordStrength(pw) {
        const reqs = {
            'pw-len':   pw.length >= 8,
            'pw-upper': /[A-Z]/.test(pw),
            'pw-lower': /[a-z]/.test(pw),
            'pw-num':   /[0-9]/.test(pw),
        };

        for (const [id, met] of Object.entries(reqs)) {
            const el = document.getElementById(id);
            if (el) {
                el.className = met ? 'met' : 'unmet';
            }
        }
    }
    </script>
</body>
</html>
