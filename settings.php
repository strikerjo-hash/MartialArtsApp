<?php
require_once 'config.php';
requireLogin();

if (!canViewAnySettings()) {
    accessDenied('You do not have permission to view settings.');
}

$message = '';

// Migrations have been moved to migrate.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Profile and password editing moved to profile_handler.php

    if (isset($_POST['save_stripe']) && canEdit('settings_billing.php')) {
        requireFinancialAccess('Only admins can modify payment gateway settings.');
        // If enabling Stripe, disable Square
        if (isset($_POST['stripe_enabled'])) {
            saveSetting('square_enabled', '0');
            saveSetting('active_payment_gateway', 'stripe');
        } else {
            // If disabling Stripe and it was active, set to none
            if (getSetting('active_payment_gateway') === 'stripe') {
                saveSetting('active_payment_gateway', 'none');
            }
        }
        
        saveSetting('stripe_publishable_key', sanitizeInput($_POST['stripe_publishable_key']));
        saveSetting('stripe_secret_key', sanitizeInput($_POST['stripe_secret_key']));
        saveSetting('stripe_enabled', isset($_POST['stripe_enabled']) ? '1' : '0');
        $message = showAlert('Stripe settings saved! Square has been automatically disabled.', 'success');
    }
    
    if (isset($_POST['save_membership_settings']) && canEdit('settings_school.php')) {
        saveSetting('allow_plan_change_override', isset($_POST['allow_plan_change_override']) ? '1' : '0');
        $message = showAlert('Membership settings saved!', 'success');
    }

    if (isset($_POST['save_belt_cycle']) && canEdit('settings_belt.php')) {
        saveSetting('belt_testing_cycle_start_date', $_POST['belt_testing_cycle_start_date'] ?? date('Y-m-d'));
        $defaultEnd = (new \DateTime())->modify('+4 months')->format('Y-m-d');
        saveSetting('belt_testing_cycle_end_date', $_POST['belt_testing_cycle_end_date'] ?? $defaultEnd);
        $absenceThreshold = max(1, min(20, (int) ($_POST['absence_warning_threshold'] ?? 3)));
        saveSetting('absence_warning_threshold', (string) $absenceThreshold);
        saveSetting('payment_failure_email_enabled', isset($_POST['payment_failure_email_enabled']) ? '1' : '0');
        $message = showAlert('Belt testing cycle and notification settings saved!', 'success');
    }

    if (isset($_POST['save_waiver']) && canEdit('settings_registration.php')) {
        $waiver_text = trim($_POST['waiver_content'] ?? '');
        saveSetting('waiver_content', $waiver_text);

        $new_version = trim($_POST['waiver_version'] ?? '');
        if ($new_version === '') {
            $new_version = date('Y-m-d');
        }
        saveSetting('waiver_version', $new_version);

        $message = showAlert('Registration waiver saved successfully!', 'success');
    }

    if (isset($_POST['save_comm_consent']) && canEdit('settings_registration.php')) {
        $consentText = trim($_POST['comm_consent_content'] ?? '');
        saveSetting('comm_consent_content', $consentText);

        $consentVersion = trim($_POST['comm_consent_version'] ?? '');
        if ($consentVersion === '') {
            $consentVersion = date('Y-m-d');
        }
        saveSetting('comm_consent_version', $consentVersion);

        $message = showAlert('Communication consent settings saved successfully!', 'success');
    }

    if (isset($_POST['save_fee_settings']) && canEdit('settings_registration.php')) {
        requireFinancialAccess('Only admins can modify fee settings.');
        $feePct = (float) ($_POST['service_fee_percentage'] ?? 0);
        $feePct = max(0, min(100, $feePct));
        saveSetting('service_fee_percentage', (string) $feePct);
        $message = showAlert('Fee settings saved successfully!', 'success');
    }

    if (isset($_POST['save_tax_settings']) && canEdit('settings_billing.php')) {
        requireFinancialAccess('Only admins can modify tax settings.');
        saveSetting('tax_business_name', trim($_POST['tax_business_name'] ?? ''));
        saveSetting('tax_id_ein', trim($_POST['tax_id_ein'] ?? ''));
        saveSetting('tax_business_address', trim($_POST['tax_business_address'] ?? ''));
        saveSetting('tax_statement_note', trim($_POST['tax_statement_note'] ?? ''));
        $message = showAlert('Tax statement settings saved!', 'success');
    }

    // ── Timezone Setting ──
    if (isset($_POST['save_timezone']) && canEdit('settings_school.php')) {
        verify_csrf();
        $newTz = trim($_POST['school_timezone'] ?? 'America/New_York');
        // Validate the timezone string
        if (in_array($newTz, timezone_identifiers_list())) {
            $pdo->prepare("UPDATE schools SET timezone = ? WHERE id = ?")
                ->execute([$newTz, current_school_id()]);
            // Apply immediately for the rest of this request
            date_default_timezone_set($newTz);
            $message = showAlert("Timezone updated to {$newTz}. All timestamps will now display in this timezone.", 'success');
        } else {
            $message = showAlert('Invalid timezone selected.', 'error');
        }
    }

    // ── SMTP Email Settings ──
    if (isset($_POST['save_smtp']) && canEdit('settings_communications.php')) {
        verify_csrf();
        requireFinancialAccess('Only admins can modify email settings.');
        saveSetting('smtp_host', trim($_POST['smtp_host'] ?? ''));
        saveSetting('smtp_port', trim($_POST['smtp_port'] ?? '587'));
        saveSetting('smtp_username', trim($_POST['smtp_username'] ?? ''));
        if (!empty($_POST['smtp_password'])) {
            saveSetting('smtp_password', $_POST['smtp_password']);
        }
        saveSetting('smtp_encryption', trim($_POST['smtp_encryption'] ?? 'tls'));
        saveSetting('smtp_from_email', trim($_POST['smtp_from_email'] ?? ''));
        saveSetting('smtp_from_name', trim($_POST['smtp_from_name'] ?? ''));
        $message = showAlert('SMTP email settings saved!', 'success');
    }

    if (isset($_POST['test_email']) && canEdit('settings_communications.php')) {
        verify_csrf();
        require_once __DIR__ . '/includes/messaging.php';
        $testTo = getCurrentUser()['email'] ?? '';
        if ($testTo) {
            $result = send_email($testTo, 'Test Email from ' . getSiteName(), '<h2>Test Email</h2><p>This is a test email from your Martial Arts Studio app. If you received this, your SMTP settings are working correctly!</p>');
            $message = $result['success']
                ? showAlert("Test email sent to {$testTo}!", 'success')
                : showAlert("Test email failed: " . htmlspecialchars($result['error']), 'error');
        } else {
            $message = showAlert('No email address on your admin account.', 'error');
        }
    }

    // ── Twilio SMS Settings ──
    if (isset($_POST['save_twilio']) && canEdit('settings_communications.php')) {
        verify_csrf();
        requireFinancialAccess('Only admins can modify SMS settings.');
        saveSetting('twilio_account_sid', trim($_POST['twilio_account_sid'] ?? ''));
        if (!empty($_POST['twilio_auth_token'])) {
            saveSetting('twilio_auth_token', $_POST['twilio_auth_token']);
        }
        saveSetting('twilio_from_number', trim($_POST['twilio_from_number'] ?? ''));
        $message = showAlert('Twilio SMS settings saved!', 'success');
    }

    if (isset($_POST['test_sms']) && canEdit('settings_communications.php')) {
        verify_csrf();
        require_once __DIR__ . '/includes/messaging.php';
        $testNumber = trim($_POST['test_sms_number'] ?? '');
        if ($testNumber) {
            $result = send_sms($testNumber, 'Test SMS from ' . getSiteName() . '. Your Twilio settings are working!');
            $message = $result['success']
                ? showAlert("Test SMS sent to {$testNumber}!", 'success')
                : showAlert("Test SMS failed: " . htmlspecialchars($result['error']), 'error');
        } else {
            $message = showAlert('Please enter a phone number to send the test SMS to.', 'error');
        }
    }

    // ── Save Notification Templates ──
    if (isset($_POST['save_notification_templates']) && canEdit('settings_communications.php')) {
        verify_csrf();
        require_once __DIR__ . '/includes/messaging.php';
        $defs = get_notification_template_definitions();
        foreach ($defs as $tplKey => $tplDef) {
            $subjectField = "notif_tpl_{$tplKey}_subject";
            $bodyField    = "notif_tpl_{$tplKey}_body";
            if ($tplDef['channel'] === 'email') {
                saveSetting($subjectField, trim($_POST[$subjectField] ?? ''));
            }
            saveSetting($bodyField, trim($_POST[$bodyField] ?? ''));
        }
        $message = showAlert('Notification templates saved!', 'success');
    }

    // ── Certificate Template Upload (dual config: color & black belt) ──
    // Helper: save cert settings for a given type prefix (color or black)
    function saveCertTypeSettings($typePrefix, $post, $files, $uploadDir) {
        $msg = '';
        $allowedImgTypes = ['image/png', 'image/jpeg', 'image/jpg'];
        $allowedFontExts = ['ttf', 'woff', 'woff2', 'otf'];

        // Template image upload
        $tplKey = $typePrefix . '_belt_template';
        if (!empty($files[$tplKey]['name'])) {
            $file = $files[$tplKey];
            if (in_array($file['type'], $allowedImgTypes) && $file['size'] <= 10 * 1024 * 1024) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $filename = $tplKey . '.' . $ext;
                foreach (glob($uploadDir . $tplKey . '.*') as $old) { @unlink($old); }
                move_uploaded_file($file['tmp_name'], $uploadDir . $filename);
                saveSetting('cert_' . $tplKey, 'uploads/certificates/' . $filename);
            } else {
                $msg = ucfirst($typePrefix) . ' belt template must be PNG or JPG, max 10MB.';
            }
        }

        // Font upload
        $fontKey = $typePrefix . '_font';
        if (!empty($files[$fontKey]['name'])) {
            $fontFile = $files[$fontKey];
            $fontExt = strtolower(pathinfo($fontFile['name'], PATHINFO_EXTENSION));
            if (in_array($fontExt, $allowedFontExts) && $fontFile['size'] <= 5 * 1024 * 1024) {
                $fontFilename = 'cert_' . $fontKey . '.' . $fontExt;
                foreach (glob($uploadDir . 'cert_' . $fontKey . '.*') as $old) { @unlink($old); }
                move_uploaded_file($fontFile['tmp_name'], $uploadDir . $fontFilename);
                saveSetting('cert_' . $fontKey, 'uploads/certificates/' . $fontFilename);
                saveSetting('cert_' . $fontKey . '_name', pathinfo($fontFile['name'], PATHINFO_FILENAME));
            } else {
                $msg = 'Font file must be TTF, OTF, WOFF, or WOFF2 format, max 5MB.';
            }
        }

        // Save per-field settings
        $certFields = ['name', 'rank', 'style', 'date', 'instructor', 'bb_number'];
        $certDefaults = [
            'name'       => ['top' => '48', 'left' => '50', 'size' => '42', 'color' => '#1a1a1a', 'stroke' => '0', 'stroke_color' => '#000000'],
            'rank'       => ['top' => '60', 'left' => '50', 'size' => '36', 'color' => '#1a1a1a', 'stroke' => '0', 'stroke_color' => '#000000'],
            'style'      => ['top' => '67', 'left' => '50', 'size' => '22', 'color' => '#555555', 'stroke' => '0', 'stroke_color' => '#000000'],
            'date'       => ['top' => '78', 'left' => '25', 'size' => '18', 'color' => '#333333', 'stroke' => '0', 'stroke_color' => '#000000'],
            'instructor' => ['top' => '78', 'left' => '75', 'size' => '18', 'color' => '#333333', 'stroke' => '0', 'stroke_color' => '#000000'],
            'bb_number'  => ['top' => '72', 'left' => '50', 'size' => '18', 'color' => '#b8860b', 'stroke' => '0', 'stroke_color' => '#000000'],
        ];

        foreach ($certFields as $field) {
            $prefix = 'cert_' . $typePrefix . '_' . $field;
            saveSetting($prefix . '_visible', isset($post[$prefix . '_visible']) ? '1' : '0');
            saveSetting($prefix . '_top_pct', $post[$prefix . '_top_pct'] ?? $certDefaults[$field]['top']);
            saveSetting($prefix . '_left_pct', $post[$prefix . '_left_pct'] ?? $certDefaults[$field]['left']);
            saveSetting($prefix . '_font_size', $post[$prefix . '_font_size'] ?? $certDefaults[$field]['size']);
            saveSetting($prefix . '_color', $post[$prefix . '_color'] ?? $certDefaults[$field]['color']);
            saveSetting($prefix . '_stroke', $post[$prefix . '_stroke'] ?? $certDefaults[$field]['stroke']);
            saveSetting($prefix . '_stroke_color', $post[$prefix . '_stroke_color'] ?? $certDefaults[$field]['stroke_color']);
        }
        return $msg;
    }

    if (isset($_POST['save_certificate_templates']) && canEdit('settings_certs.php')) {
        $uploadDir = __DIR__ . '/uploads/certificates/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }

        $errColor = saveCertTypeSettings('color', $_POST, $_FILES, $uploadDir);
        $errBlack = saveCertTypeSettings('black', $_POST, $_FILES, $uploadDir);

        if ($errColor) { $message = showAlert($errColor, 'error'); }
        elseif ($errBlack) { $message = showAlert($errBlack, 'error'); }
        else { $message = showAlert('Certificate settings saved!', 'success'); }
    }

    // Remove handlers
    if (isset($_POST['remove_color_belt_template']) && canEdit('settings_certs.php')) {
        $path = getSetting('cert_color_belt_template', '');
        if ($path && file_exists(__DIR__ . '/' . $path)) { @unlink(__DIR__ . '/' . $path); }
        saveSetting('cert_color_belt_template', '');
        $message = showAlert('Color belt template removed.', 'success');
    }
    if (isset($_POST['remove_black_belt_template']) && canEdit('settings_certs.php')) {
        $path = getSetting('cert_black_belt_template', '');
        if ($path && file_exists(__DIR__ . '/' . $path)) { @unlink(__DIR__ . '/' . $path); }
        saveSetting('cert_black_belt_template', '');
        $message = showAlert('Black belt template removed.', 'success');
    }
    if (isset($_POST['remove_color_font']) && canEdit('settings_certs.php')) {
        $path = getSetting('cert_color_font', '');
        if ($path && file_exists(__DIR__ . '/' . $path)) { @unlink(__DIR__ . '/' . $path); }
        saveSetting('cert_color_font', ''); saveSetting('cert_color_font_name', '');
        $message = showAlert('Color belt font removed.', 'success');
    }
    if (isset($_POST['remove_black_font']) && canEdit('settings_certs.php')) {
        $path = getSetting('cert_black_font', '');
        if ($path && file_exists(__DIR__ . '/' . $path)) { @unlink(__DIR__ . '/' . $path); }
        saveSetting('cert_black_font', ''); saveSetting('cert_black_font_name', '');
        $message = showAlert('Black belt font removed.', 'success');
    }
    // Legacy single-font remove
    if (isset($_POST['remove_custom_font']) && canEdit('settings_certs.php')) {
        $path = getSetting('cert_custom_font', '');
        if ($path && file_exists(__DIR__ . '/' . $path)) { @unlink(__DIR__ . '/' . $path); }
        saveSetting('cert_custom_font', '');
        saveSetting('cert_custom_font_name', '');
        $message = showAlert('Custom font removed. Default serif font will be used.', 'success');
    }

    if (isset($_POST['save_square']) && canEdit('settings_billing.php')) {
        requireFinancialAccess('Only admins can modify payment gateway settings.');
        // If enabling Square, disable Stripe
        if (isset($_POST['square_enabled'])) {
            saveSetting('stripe_enabled', '0');
            saveSetting('active_payment_gateway', 'square');
        } else {
            // If disabling Square and it was active, set to none
            if (getSetting('active_payment_gateway') === 'square') {
                saveSetting('active_payment_gateway', 'none');
            }
        }
        
        saveSetting('square_application_id', sanitizeInput($_POST['square_application_id']));
        saveSetting('square_access_token', sanitizeInput($_POST['square_access_token']));
        saveSetting('square_enabled', isset($_POST['square_enabled']) ? '1' : '0');
        $message = showAlert('Square settings saved! Stripe has been automatically disabled.', 'success');
    }

    // ── Room Management ──
    if (isset($_POST['add_room']) && canEdit('settings_school.php')) {
        $roomName = sanitizeInput($_POST['room_name'] ?? '');
        if ($roomName) {
            $params = [];
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM rooms WHERE 1=1" . school_where());
            school_param($params);
            $stmt->execute($params);
            $maxOrder = $stmt->fetchColumn();
            $pdo->prepare("INSERT INTO rooms (school_id, name, sort_order) VALUES (?, ?, ?)")->execute([current_school_id(), $roomName, $maxOrder]);
            $message = showAlert('Room "' . htmlspecialchars($roomName) . '" added successfully!', 'success');
        } else {
            $message = showAlert('Room name is required.', 'error');
        }
    }

    if (isset($_POST['edit_room']) && canEdit('settings_school.php')) {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $roomName = sanitizeInput($_POST['room_name'] ?? '');
        if ($roomId && $roomName) {
            $params = [$roomName, $roomId];
            $stmt = $pdo->prepare("UPDATE rooms SET name = ? WHERE id = ?" . school_where());
            school_param($params);
            $stmt->execute($params);
            $message = showAlert('Room updated successfully!', 'success');
        }
    }

    if (isset($_POST['toggle_room_status']) && canEdit('settings_school.php')) {
        $roomId = (int)($_POST['room_id'] ?? 0);
        if ($roomId) {
            $params = [$roomId];
            $current = $pdo->prepare("SELECT status FROM rooms WHERE id = ?" . school_where());
            school_param($params);
            $current->execute($params);
            $row = $current->fetch();
            if ($row) {
                $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
                $params = [$newStatus, $roomId];
                $stmt = $pdo->prepare("UPDATE rooms SET status = ? WHERE id = ?" . school_where());
                school_param($params);
                $stmt->execute($params);
                $message = showAlert('Room ' . ($newStatus === 'active' ? 'activated' : 'deactivated') . ' successfully!', 'success');
            }
        }
    }

    if (isset($_POST['reorder_room']) && canEdit('settings_school.php')) {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $direction = $_POST['direction'] ?? '';
        if ($roomId && in_array($direction, ['up', 'down'])) {
            $params = [$roomId];
            $currentRoom = $pdo->prepare("SELECT id, sort_order FROM rooms WHERE id = ?" . school_where());
            school_param($params);
            $currentRoom->execute($params);
            $cr = $currentRoom->fetch();
            if ($cr) {
                $params = [$cr['sort_order']];
                if ($direction === 'up') {
                    $neighbor = $pdo->prepare("SELECT id, sort_order FROM rooms WHERE sort_order < ?" . school_where() . " ORDER BY sort_order DESC LIMIT 1");
                } else {
                    $neighbor = $pdo->prepare("SELECT id, sort_order FROM rooms WHERE sort_order > ?" . school_where() . " ORDER BY sort_order ASC LIMIT 1");
                }
                school_param($params);
                $neighbor->execute($params);
                $nr = $neighbor->fetch();
                if ($nr) {
                    $params1 = [$nr['sort_order'], $cr['id']];
                    $stmt1 = $pdo->prepare("UPDATE rooms SET sort_order = ? WHERE id = ?" . school_where());
                    school_param($params1);
                    $stmt1->execute($params1);
                    $params2 = [$cr['sort_order'], $nr['id']];
                    $stmt2 = $pdo->prepare("UPDATE rooms SET sort_order = ? WHERE id = ?" . school_where());
                    school_param($params2);
                    $stmt2->execute($params2);
                }
            }
        }
    }

    // Hours of Operation
    if (isset($_POST['save_hours_of_operation']) && canEdit('settings_school.php')) {
        verify_csrf();
        $frames = [];
        $labels = $_POST['hop_label'] ?? [];
        $starts = $_POST['hop_start'] ?? [];
        $ends   = $_POST['hop_end'] ?? [];

        for ($i = 0; $i < count($starts); $i++) {
            $label = trim($labels[$i] ?? '');
            $start = trim($starts[$i] ?? '');
            $end   = trim($ends[$i] ?? '');
            if ($start && $end) {
                $frames[] = [
                    'label' => $label ?: ('Frame ' . ($i + 1)),
                    'start' => $start,
                    'end'   => $end,
                ];
            }
        }

        saveSetting('hours_of_operation', json_encode($frames));

        $slotInterval = max(15, min(120, (int)($_POST['schedule_slot_interval'] ?? 30)));
        saveSetting('schedule_slot_interval', (string)$slotInterval);

        $message = showAlert('Hours of operation saved successfully!', 'success');
    }

    // Testing & Debug: payment lockout test toggle
    if (isset($_POST['save_test_settings']) && canEdit('settings_system.php')) {
        verify_csrf();
        saveSetting('test_lockout_all_students', isset($_POST['test_lockout_all_students']) ? '1' : '0');
        $lockoutState = isset($_POST['test_lockout_all_students']) ? 'ENABLED' : 'DISABLED';
        $message = showAlert('Test settings saved. Global student lockout is now ' . $lockoutState . '.', $lockoutState === 'ENABLED' ? 'warning' : 'success');
    }

    // Log Retention Settings
    if (isset($_POST['save_log_retention']) && canEdit('settings_system.php')) {
        verify_csrf();
        $retentionDays = max(7, min(365, (int) ($_POST['log_retention_days'] ?? 90)));
        saveSetting('log_retention_days', (string) $retentionDays);
        $message = showAlert('Log retention set to ' . $retentionDays . ' days. Old entries will be cleaned up during the next cron run.', 'success');
    }

    // Push Notifications (FCM) Settings — Service Account Upload
    if (isset($_POST['save_fcm_settings']) && canEdit('settings_system.php')) {
        verify_csrf();

        if (!empty($_FILES['fcm_service_account']['name'])) {
            $file = $_FILES['fcm_service_account'];

            // Validate file extension
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'json') {
                $message = showAlert('Service account file must be a .json file.', 'error');
            } elseif ($file['size'] > 100 * 1024) {
                $message = showAlert('Service account file is too large. Maximum size is 100KB.', 'error');
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $message = showAlert('File upload error (code ' . $file['error'] . '). Please try again.', 'error');
            } else {
                // Read and validate JSON structure
                $jsonContent = file_get_contents($file['tmp_name']);
                $serviceAccount = json_decode($jsonContent, true);

                if (!$serviceAccount || !is_array($serviceAccount)) {
                    $message = showAlert('Invalid JSON file. Could not parse contents.', 'error');
                } elseif (($serviceAccount['type'] ?? '') !== 'service_account') {
                    $message = showAlert('This does not appear to be a Firebase service account key file. Expected "type": "service_account".', 'error');
                } elseif (empty($serviceAccount['project_id']) || empty($serviceAccount['private_key']) || empty($serviceAccount['client_email'])) {
                    $message = showAlert('Service account JSON is missing required fields (project_id, private_key, or client_email).', 'error');
                } else {
                    // Save the file
                    $uploadDir = __DIR__ . '/uploads/fcm/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0750, true);
                    }
                    $schoolId = current_school_id();
                    $filename = 'school_' . $schoolId . '_service_account.json';
                    $filepath = $uploadDir . $filename;

                    // Remove old file if exists
                    if (file_exists($filepath)) {
                        @unlink($filepath);
                    }

                    if (move_uploaded_file($file['tmp_name'], $filepath)) {
                        @chmod($filepath, 0640);

                        // Store path and project ID in settings
                        saveSetting('fcm_service_account_path', 'uploads/fcm/' . $filename);
                        saveSetting('fcm_project_id', $serviceAccount['project_id']);

                        // Clear old legacy server key if present
                        saveSetting('fcm_server_key', '');

                        // Clear any cached OAuth token (force fresh auth)
                        $tokenCache = $uploadDir . 'school_' . $schoolId . '_oauth_token.json';
                        if (file_exists($tokenCache)) {
                            @unlink($tokenCache);
                        }

                        $message = showAlert(
                            'Firebase service account configured successfully for project: <strong>'
                            . htmlspecialchars($serviceAccount['project_id']) . '</strong>',
                            'success'
                        );
                    } else {
                        $message = showAlert('Failed to save service account file. Check directory permissions on uploads/fcm/.', 'error');
                    }
                }
            }
        } else {
            $message = showAlert('Please select a service account JSON file to upload.', 'warning');
        }
    }

    // Remove FCM service account
    if (isset($_POST['remove_fcm_service_account']) && canEdit('settings_system.php')) {
        verify_csrf();
        $schoolId = current_school_id();

        // Delete the service account file
        $saPath = getSetting('fcm_service_account_path', '');
        if (!empty($saPath)) {
            $fullPath = __DIR__ . '/' . $saPath;
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        // Clear settings
        saveSetting('fcm_service_account_path', '');
        saveSetting('fcm_project_id', '');
        saveSetting('fcm_server_key', '');

        // Clear cached OAuth token
        $tokenCache = __DIR__ . '/uploads/fcm/school_' . $schoolId . '_oauth_token.json';
        if (file_exists($tokenCache)) {
            @unlink($tokenCache);
        }

        $message = showAlert('Firebase service account removed. Push notifications are disabled.', 'warning');
    }
}

// ── Tab persistence after POST ──────────────────────────────────────
$postTabMap = [
    'save_membership_settings' => 'school-schedule',
    'add_room' => 'school-schedule', 'edit_room' => 'school-schedule',
    'toggle_room_status' => 'school-schedule', 'reorder_room' => 'school-schedule',
    'save_hours_of_operation' => 'school-schedule', 'save_timezone' => 'school-schedule',
    'save_belt_cycle' => 'belt-testing',
    'save_certificate_templates' => 'certificates',
    'remove_color_belt_template' => 'certificates', 'remove_black_belt_template' => 'certificates',
    'remove_color_font' => 'certificates', 'remove_black_font' => 'certificates',
    'remove_custom_font' => 'certificates',
    'save_waiver' => 'registration', 'save_comm_consent' => 'registration', 'save_fee_settings' => 'registration',
    'save_tax_settings' => 'billing', 'save_stripe' => 'billing', 'save_square' => 'billing',
    'save_smtp' => 'communications', 'test_email' => 'communications',
    'save_twilio' => 'communications', 'test_sms' => 'communications',
    'save_notification_templates' => 'communications',
    'save_test_settings' => 'system',
    'save_log_retention' => 'system',
    'save_fcm_settings' => 'system',
    'remove_fcm_service_account' => 'system',
];
$activeTabAfterPost = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($postTabMap as $key => $tab) {
        if (isset($_POST[$key])) { $activeTabAfterPost = $tab; break; }
    }
}

$current_user = getCurrentUser();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Settings</h1>

    <!-- Settings Tab Navigation -->
    <?php
    // Build list of visible tabs for this user
    $settingsTabs = [];
    if (canView('settings_school.php'))          $settingsTabs[] = ['id' => 'school-schedule', 'label' => 'School &amp; Schedule'];
    if (canView('settings_belt.php'))            $settingsTabs[] = ['id' => 'belt-testing',    'label' => 'Belt Testing'];
    if (canView('settings_certs.php'))           $settingsTabs[] = ['id' => 'certificates',    'label' => 'Certificates'];
    if (canView('settings_registration.php'))    $settingsTabs[] = ['id' => 'registration',    'label' => 'Registration'];
    if (canView('settings_billing.php') && hasFinancialAccess())        $settingsTabs[] = ['id' => 'billing',        'label' => 'Billing'];
    if (canView('settings_communications.php') && hasFinancialAccess()) $settingsTabs[] = ['id' => 'communications', 'label' => 'Communications'];
    if (canView('settings_system.php'))          $settingsTabs[] = ['id' => 'system',          'label' => 'System'];
    $firstVisibleTab = !empty($settingsTabs) ? $settingsTabs[0]['id'] : '';
    ?>
    <div class="border-b border-gray-200 mb-6 overflow-x-auto">
        <nav class="flex gap-1 -mb-px min-w-max" id="settingsTabs">
            <?php foreach ($settingsTabs as $i => $tab): ?>
            <button type="button" data-tab="<?php echo $tab['id']; ?>" class="settings-tab px-4 py-2.5 text-sm font-medium rounded-t-lg border-b-2 <?php echo $i === 0 ? 'border-blue-500 text-blue-600 bg-blue-50' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo $tab['label']; ?></button>
            <?php endforeach; ?>
        </nav>
    </div>

    <!-- Tab Content Panels -->
    <div id="settingsContent">

    <!-- ═══ SCHOOL & SCHEDULE TAB ═══ -->
    <?php if (canView('settings_school.php')): ?>
    <div class="settings-panel" data-panel="school-schedule" style="display:none;">

            <!-- Membership Settings -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Membership Settings</h2>
                <p class="text-sm text-gray-600 mb-4">Configure membership management options</p>
                <form method="POST" class="space-y-4">
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="allow_plan_change_override" value="1"
                                   <?php echo getSetting('allow_plan_change_override') == '1' ? 'checked' : ''; ?>
                                   class="mt-1 rounded border-gray-300 text-blue-600">
                            <div>
                                <span class="font-medium text-gray-800">Allow staff/instructors to bypass student plan change lockout</span>
                                <p class="text-xs text-gray-500 mt-1">When enabled, staff and instructors can propose membership changes for students even if the student is within their 30-day lockout period. Admins can always bypass the lockout.</p>
                            </div>
                        </label>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
                        <strong>Note:</strong> Students can only change their own membership plan once every 30 days. Admin-initiated changes require student confirmation before taking effect.
                    </div>
                    <button type="submit" name="save_membership_settings" value="1"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">
                        Save Membership Settings
                    </button>
                </form>
            </div>

            <!-- Rooms / Locations -->
            <div class="bg-white rounded-lg shadow p-6 mb-6 border-t-4 border-indigo-500">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Rooms / Locations</h2>
                <p class="text-sm text-gray-600 mb-4">Manage the rooms or training areas in your studio. Classes can be assigned to a room for scheduling purposes.</p>

                <?php
                // Rooms table created by migrate.php
                $roomParams = [];
                $roomStmt = $pdo->prepare("SELECT r.*, (SELECT COUNT(*) FROM classes c WHERE c.room_id = r.id) as class_count FROM rooms r WHERE 1=1" . school_where('r') . " ORDER BY r.sort_order, r.name");
                school_param($roomParams);
                $roomStmt->execute($roomParams);
                $allRooms = $roomStmt->fetchAll();
                ?>

                <?php if (!empty($allRooms)): ?>
                <div class="space-y-2 mb-4">
                    <?php foreach ($allRooms as $ri => $room): ?>
                    <div class="flex items-center justify-between p-3 rounded-lg border <?php echo $room['status'] === 'active' ? 'bg-white border-gray-200' : 'bg-gray-50 border-gray-200 opacity-60'; ?>">
                        <div class="flex items-center gap-3">
                            <div class="flex flex-col gap-1">
                                <form method="POST" class="inline"><?php echo csrf_field(); ?>
                                    <input type="hidden" name="reorder_room" value="1">
                                    <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="text-gray-400 hover:text-gray-600 text-xs" title="Move up">&#9650;</button>
                                </form>
                                <form method="POST" class="inline"><?php echo csrf_field(); ?>
                                    <input type="hidden" name="reorder_room" value="1">
                                    <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="text-gray-400 hover:text-gray-600 text-xs" title="Move down">&#9660;</button>
                                </form>
                            </div>
                            <div>
                                <span class="font-medium text-gray-800"><?php echo htmlspecialchars($room['name']); ?></span>
                                <span class="text-xs text-gray-400 ml-2"><?php echo $room['class_count']; ?> class<?php echo $room['class_count'] != 1 ? 'es' : ''; ?></span>
                                <?php if ($room['status'] === 'inactive'): ?>
                                    <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-gray-200 text-gray-500">Inactive</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button onclick="editRoom(<?php echo $room['id']; ?>, '<?php echo addslashes(htmlspecialchars($room['name'])); ?>')"
                                    class="text-blue-600 hover:text-blue-800 text-sm">Edit</button>
                            <form method="POST" class="inline" onsubmit="return confirm('<?php echo $room['status'] === 'active' ? 'Deactivate' : 'Reactivate'; ?> this room?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="toggle_room_status" value="1">
                                <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                <button type="submit" class="<?php echo $room['status'] === 'active' ? 'text-orange-600 hover:text-orange-800' : 'text-green-600 hover:text-green-800'; ?> text-sm">
                                    <?php echo $room['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-sm text-gray-500 mb-4">No rooms configured yet. Add your first room below.</p>
                <?php endif; ?>

                <!-- Add Room -->
                <form method="POST" class="flex gap-3 items-end">
                    <?php echo csrf_field(); ?>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Add New Room</label>
                        <input type="text" name="room_name" required placeholder="e.g., Main Floor, Studio B, Mat Room..."
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <button type="submit" name="add_room" value="1"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm whitespace-nowrap">
                        + Add Room
                    </button>
                </form>

                <!-- Edit Room Modal (simple inline) -->
                <div id="editRoomModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
                    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Edit Room Name</h3>
                        <form method="POST" class="space-y-4">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="edit_room" value="1">
                            <input type="hidden" name="room_id" id="editRoomId">
                            <input type="text" name="room_name" id="editRoomName" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                            <div class="flex justify-end gap-3">
                                <button type="button" onclick="document.getElementById('editRoomModal').classList.add('hidden')"
                                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg">Save</button>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                function editRoom(id, name) {
                    document.getElementById('editRoomId').value = id;
                    document.getElementById('editRoomName').value = name;
                    document.getElementById('editRoomModal').classList.remove('hidden');
                }
                </script>
            </div>

            <!-- Hours of Operation / Schedule Time Slots -->
            <div class="bg-white rounded-lg shadow p-6 mb-6 border-t-4 border-blue-500">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Hours of Operation</h2>
                <p class="text-sm text-gray-600 mb-4">
                    Define your studio's hours of operation. The Kanban schedule board will display
                    time slots based on these hours. You can add multiple time frames (e.g.,
                    morning and evening sessions).
                </p>

                <form method="POST" class="space-y-4">
                    <?php echo csrf_field(); ?>

                    <!-- Slot interval -->
                    <div class="max-w-xs">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Time Slot Interval</label>
                        <?php $currentInterval = getSetting('schedule_slot_interval', '30'); ?>
                        <select name="schedule_slot_interval"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            <option value="15" <?php echo $currentInterval == '15' ? 'selected' : ''; ?>>15 minutes</option>
                            <option value="30" <?php echo $currentInterval == '30' ? 'selected' : ''; ?>>30 minutes</option>
                            <option value="45" <?php echo $currentInterval == '45' ? 'selected' : ''; ?>>45 minutes</option>
                            <option value="60" <?php echo $currentInterval == '60' ? 'selected' : ''; ?>>1 hour</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Controls the granularity of time slots on the schedule board</p>
                    </div>

                    <!-- Time Frames container -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Time Frames</label>
                        <div id="hopFrames" class="space-y-3">
                            <?php
                            $frames = getHoursOfOperation();
                            foreach ($frames as $i => $frame):
                            ?>
                            <div class="flex flex-wrap items-end gap-3 p-3 bg-gray-50 rounded-lg hop-frame">
                                <div class="flex-1 min-w-[120px]">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Label</label>
                                    <input type="text" name="hop_label[]" value="<?php echo htmlspecialchars($frame['label']); ?>"
                                           placeholder="e.g., Morning"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Start</label>
                                    <input type="time" name="hop_start[]" value="<?php echo $frame['start']; ?>" required
                                           class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">End</label>
                                    <input type="time" name="hop_end[]" value="<?php echo $frame['end']; ?>" required
                                           class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                </div>
                                <button type="button" onclick="this.closest('.hop-frame').remove()"
                                        class="text-red-500 hover:text-red-700 px-2 py-2 text-sm font-medium">Remove</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="button" onclick="addHopFrame()"
                            class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                        + Add Time Frame
                    </button>

                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
                        <strong>Note:</strong> If no time frames are configured, the schedule board
                        defaults to 6:00 AM - 9:00 PM.
                    </div>

                    <button type="submit" name="save_hours_of_operation" value="1"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">
                        Save Hours of Operation
                    </button>
                </form>

                <script>
                function addHopFrame() {
                    var container = document.getElementById('hopFrames');
                    var html = '<div class="flex flex-wrap items-end gap-3 p-3 bg-gray-50 rounded-lg hop-frame">'
                        + '<div class="flex-1 min-w-[120px]">'
                        + '<label class="block text-xs font-medium text-gray-600 mb-1">Label</label>'
                        + '<input type="text" name="hop_label[]" placeholder="e.g., Evening" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">'
                        + '</div>'
                        + '<div>'
                        + '<label class="block text-xs font-medium text-gray-600 mb-1">Start</label>'
                        + '<input type="time" name="hop_start[]" required class="px-3 py-2 border border-gray-300 rounded-lg text-sm">'
                        + '</div>'
                        + '<div>'
                        + '<label class="block text-xs font-medium text-gray-600 mb-1">End</label>'
                        + '<input type="time" name="hop_end[]" required class="px-3 py-2 border border-gray-300 rounded-lg text-sm">'
                        + '</div>'
                        + '<button type="button" onclick="this.closest(\'.hop-frame\').remove()" class="text-red-500 hover:text-red-700 px-2 py-2 text-sm font-medium">Remove</button>'
                        + '</div>';
                    container.insertAdjacentHTML('beforeend', html);
                }
                </script>
            </div>
            <!-- Timezone Settings -->
            <?php if (is_super_admin()): ?>
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Timezone</h2>
                <p class="text-sm text-gray-600 mb-4">Set the timezone for your school. All dates and times throughout the system will display in this timezone.</p>
                <?php
                    $currentTz = function_exists('get_school_timezone') ? get_school_timezone() : 'America/New_York';
                    $commonTimezones = [
                        'US & Canada' => [
                            'America/New_York'    => 'Eastern Time (ET)',
                            'America/Chicago'     => 'Central Time (CT)',
                            'America/Denver'      => 'Mountain Time (MT)',
                            'America/Los_Angeles' => 'Pacific Time (PT)',
                            'America/Phoenix'     => 'Arizona (no DST)',
                            'America/Anchorage'   => 'Alaska Time (AKT)',
                            'Pacific/Honolulu'    => 'Hawaii Time (HST)',
                        ],
                        'Canada' => [
                            'America/Toronto'   => 'Eastern (Toronto)',
                            'America/Winnipeg'  => 'Central (Winnipeg)',
                            'America/Edmonton'  => 'Mountain (Edmonton)',
                            'America/Vancouver' => 'Pacific (Vancouver)',
                            'America/Halifax'   => 'Atlantic (Halifax)',
                            'America/St_Johns'  => 'Newfoundland',
                        ],
                        'Europe' => [
                            'Europe/London' => 'London (GMT/BST)',
                            'Europe/Paris'  => 'Paris / Berlin (CET)',
                            'Europe/Berlin' => 'Berlin (CET)',
                            'Europe/Moscow' => 'Moscow (MSK)',
                        ],
                        'Asia & Pacific' => [
                            'Asia/Tokyo'      => 'Tokyo (JST)',
                            'Asia/Shanghai'   => 'Shanghai (CST)',
                            'Asia/Kolkata'    => 'India (IST)',
                            'Asia/Dubai'      => 'Dubai (GST)',
                            'Australia/Sydney'    => 'Sydney (AEST)',
                            'Australia/Melbourne' => 'Melbourne (AEST)',
                            'Pacific/Auckland'    => 'Auckland (NZST)',
                        ],
                    ];
                ?>
                <form method="POST" class="space-y-4">
                    <?= csrf_field() ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">School Timezone</label>
                        <select name="school_timezone"
                                class="w-full md:w-1/2 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            <?php foreach ($commonTimezones as $group => $zones): ?>
                                <optgroup label="<?= htmlspecialchars($group) ?>">
                                    <?php foreach ($zones as $tz => $label): ?>
                                        <option value="<?= htmlspecialchars($tz) ?>" <?= $currentTz === $tz ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?> (<?= htmlspecialchars($tz) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                        <p class="text-sm text-gray-700">
                            <span class="font-medium">Current timezone:</span> <?= htmlspecialchars($currentTz) ?><br>
                            <span class="font-medium">Current time:</span> <?= date('l, M j, Y g:i A T') ?>
                        </p>
                    </div>
                    <button type="submit" name="save_timezone" value="1"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                        Save Timezone
                    </button>
                </form>
            </div>
            <?php endif; ?>
    </div><!-- /school-schedule panel -->
    <?php endif; ?>

    <!-- ═══ BELT TESTING TAB ═══ -->
    <?php if (canView('settings_belt.php')): ?>
    <div class="settings-panel" data-panel="belt-testing" style="display:none;">

            <!-- Belt Testing Cycle & Notifications -->
            <div class="bg-white rounded-lg shadow p-6 mb-6 border-t-4 border-teal-500">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Belt Testing Cycle &amp; Notifications</h2>
                <p class="text-sm text-gray-600 mb-4">Configure the belt testing cycle period and automated student notifications.</p>
                <form method="POST" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cycle Start Date</label>
                            <input type="date" name="belt_testing_cycle_start_date"
                                   value="<?php echo htmlspecialchars(getSetting('belt_testing_cycle_start_date', date('Y-m-d'))); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-teal-500">
                            <p class="text-xs text-gray-500 mt-1">First day of the current testing cycle</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cycle End Date</label>
                            <?php $defaultEnd = (new \DateTime())->modify('+4 months')->format('Y-m-d'); ?>
                            <input type="date" name="belt_testing_cycle_end_date"
                                   value="<?php echo htmlspecialchars(getSetting('belt_testing_cycle_end_date', $defaultEnd)); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-teal-500">
                            <p class="text-xs text-gray-500 mt-1">Last day of the current testing cycle</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Absence Warning Threshold</label>
                            <input type="number" name="absence_warning_threshold" min="1" max="20"
                                   value="<?php echo htmlspecialchars(getSetting('absence_warning_threshold', '3')); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-teal-500">
                            <p class="text-xs text-gray-500 mt-1">Email sent after this many absences</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 bg-teal-50 border border-teal-200 rounded-lg p-4">
                        <input type="checkbox" name="payment_failure_email_enabled" value="1"
                               <?php echo getSetting('payment_failure_email_enabled', '1') === '1' ? 'checked' : ''; ?>
                               class="mt-1 h-4 w-4 text-teal-600 border-gray-300 rounded">
                        <div>
                            <span class="font-medium text-gray-800">Send email to students on payment failure</span>
                            <p class="text-xs text-gray-500 mt-1">When enabled, students will receive an automatic email notification when their membership payment fails. Requires SMTP to be configured.</p>
                        </div>
                    </div>
                    <button type="submit" name="save_belt_cycle" value="1"
                            class="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-lg text-sm">
                        Save Cycle &amp; Notification Settings
                    </button>
                </form>
            </div>
    </div><!-- /belt-testing panel -->
    <?php endif; ?>

    <!-- ═══ CERTIFICATES TAB ═══ -->
    <?php if (canView('settings_certs.php')): ?>
    <div class="settings-panel" data-panel="certificates" style="display:none;">

            <!-- Certificate Templates (Dual Config: Color Belt & Black Belt) -->
            <?php
            $colorTpl = getSetting('cert_color_belt_template', '');
            $blackTpl = getSetting('cert_black_belt_template', '');
            $hasColorTpl = $colorTpl && file_exists(__DIR__ . '/' . $colorTpl);
            $hasBlackTpl = $blackTpl && file_exists(__DIR__ . '/' . $blackTpl);

            // Font paths per type
            $colorFont = getSetting('cert_color_font', getSetting('cert_custom_font', ''));
            $colorFontName = getSetting('cert_color_font_name', getSetting('cert_custom_font_name', ''));
            $hasColorFont = $colorFont && file_exists(__DIR__ . '/' . $colorFont);
            $blackFont = getSetting('cert_black_font', getSetting('cert_custom_font', ''));
            $blackFontName = getSetting('cert_black_font_name', getSetting('cert_custom_font_name', ''));
            $hasBlackFont = $blackFont && file_exists(__DIR__ . '/' . $blackFont);

            // Field definitions
            $certFieldDefs = [
                'name'       => ['label' => '{STUDENT_NAME}', 'sample' => 'John Smith'],
                'rank'       => ['label' => '{BELT_RANK}',    'sample' => 'Black Belt 1st Dan'],
                'style'      => ['label' => '{STYLE}',        'sample' => 'Taekwondo'],
                'date'       => ['label' => '{DATE}',         'sample' => date('F j, Y')],
                'instructor' => ['label' => '{INSTRUCTOR}',   'sample' => 'Master Kim'],
                'bb_number'  => ['label' => '{BB_NUMBER}',    'sample' => 'Black Belt #1234'],
            ];
            $certDefaults = [
                'name'       => ['top' => '48', 'left' => '50', 'size' => '42', 'color' => '#1a1a1a', 'stroke' => '0', 'stroke_color' => '#000000'],
                'rank'       => ['top' => '60', 'left' => '50', 'size' => '36', 'color' => '#1a1a1a', 'stroke' => '0', 'stroke_color' => '#000000'],
                'style'      => ['top' => '67', 'left' => '50', 'size' => '22', 'color' => '#555555', 'stroke' => '0', 'stroke_color' => '#000000'],
                'date'       => ['top' => '78', 'left' => '25', 'size' => '18', 'color' => '#333333', 'stroke' => '0', 'stroke_color' => '#000000'],
                'instructor' => ['top' => '78', 'left' => '75', 'size' => '18', 'color' => '#333333', 'stroke' => '0', 'stroke_color' => '#000000'],
                'bb_number'  => ['top' => '72', 'left' => '50', 'size' => '18', 'color' => '#b8860b', 'stroke' => '0', 'stroke_color' => '#000000'],
            ];

            // Build JS data for both types
            function buildCertJsData($typePrefix, $certFieldDefs, $certDefaults) {
                $jsData = [];
                foreach ($certFieldDefs as $field => $def) {
                    $prefix = 'cert_' . $typePrefix . '_' . $field;
                    // Fallback to legacy single-config settings
                    $legacyPrefix = 'cert_' . $field;
                    $jsData[$field] = [
                        'label'        => $def['label'],
                        'sample'       => $def['sample'],
                        'visible'      => (int) (getSetting($prefix . '_visible', '') !== '' ? getSetting($prefix . '_visible', '1') : getSetting($legacyPrefix . '_visible', '1')),
                        'top'          => (float) (getSetting($prefix . '_top_pct', '') !== '' ? getSetting($prefix . '_top_pct') : getSetting($legacyPrefix . '_top_pct', $certDefaults[$field]['top'])),
                        'left'         => (float) (getSetting($prefix . '_left_pct', '') !== '' ? getSetting($prefix . '_left_pct') : getSetting($legacyPrefix . '_left_pct', $certDefaults[$field]['left'])),
                        'size'         => (int) (getSetting($prefix . '_font_size', '') !== '' ? getSetting($prefix . '_font_size') : getSetting($legacyPrefix . '_font_size', $certDefaults[$field]['size'])),
                        'color'        => getSetting($prefix . '_color', '') !== '' ? getSetting($prefix . '_color') : getSetting($legacyPrefix . '_color', $certDefaults[$field]['color']),
                        'stroke'       => (float) getSetting($prefix . '_stroke', $certDefaults[$field]['stroke']),
                        'stroke_color' => getSetting($prefix . '_stroke_color', $certDefaults[$field]['stroke_color']),
                    ];
                }
                return $jsData;
            }
            $colorJsData = buildCertJsData('color', $certFieldDefs, $certDefaults);
            $blackJsData = buildCertJsData('black', $certFieldDefs, $certDefaults);
            ?>
            <?php
            // Font face CSS for both types (used in preview)
            if ($hasColorFont) echo '<style>@font-face{font-family:"CertColorFont";src:url(\'' . htmlspecialchars($colorFont) . '\');font-display:swap;}</style>';
            if ($hasBlackFont) echo '<style>@font-face{font-family:"CertBlackFont";src:url(\'' . htmlspecialchars($blackFont) . '\');font-display:swap;}</style>';
            ?>
            <div class="bg-white rounded-lg shadow p-6 mb-6 border-t-4 border-amber-500">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Certificate Templates</h2>
                <p class="text-sm text-gray-600 mb-4">Each certificate type has its own template, font, text placement, and style settings. Switch between tabs to configure each independently.</p>

                <form method="POST" enctype="multipart/form-data" class="space-y-4" id="certTemplateForm">

                    <!-- Tab Switcher -->
                    <div class="flex border-b border-gray-200 mb-4">
                        <button type="button" id="certTab_color" onclick="switchCertTab('color')"
                                class="px-6 py-3 text-sm font-semibold border-b-2 border-amber-500 text-amber-700 bg-amber-50 rounded-t-lg -mb-px">
                            &#127993; Color Belt Certificate
                        </button>
                        <button type="button" id="certTab_black" onclick="switchCertTab('black')"
                                class="px-6 py-3 text-sm font-semibold border-b-2 border-transparent text-gray-500 hover:text-gray-700 rounded-t-lg -mb-px ml-1">
                            &#129351; Black Belt Certificate
                        </button>
                    </div>

                    <?php foreach (['color', 'black'] as $certType):
                        $isColor = ($certType === 'color');
                        $tplPath = $isColor ? $colorTpl : $blackTpl;
                        $hasTpl = $isColor ? $hasColorTpl : $hasBlackTpl;
                        $fontPath = $isColor ? $colorFont : $blackFont;
                        $fontName = $isColor ? $colorFontName : $blackFontName;
                        $hasFont = $isColor ? $hasColorFont : $hasBlackFont;
                        $jsData = $isColor ? $colorJsData : $blackJsData;
                        $fontFamilyCSS = $hasFont ? ($isColor ? "'CertColorFont'" : "'CertBlackFont'") . ", Georgia, 'Times New Roman', serif" : "Georgia, 'Times New Roman', serif";
                        $typeLabel = $isColor ? 'Color Belt' : 'Black Belt';
                        $typePfx = $certType; // 'color' or 'black'
                    ?>
                    <div id="certPanel_<?php echo $certType; ?>" class="cert-type-panel" style="<?php echo $isColor ? '' : 'display:none;'; ?>">

                        <!-- Template Upload -->
                        <div class="border border-gray-200 rounded-lg p-4 mb-4">
                            <h4 class="font-semibold text-gray-800 mb-2"><?php echo $typeLabel; ?> Template Image</h4>
                            <?php if ($hasTpl): ?>
                                <div class="mb-3">
                                    <img src="<?php echo htmlspecialchars($tplPath); ?>" alt="<?php echo $typeLabel; ?> Template"
                                         class="w-full h-36 object-contain rounded border border-gray-200 bg-gray-50">
                                    <div class="flex items-center justify-between mt-2">
                                        <span class="text-xs text-green-600 font-medium">Custom template active</span>
                                        <button type="submit" name="remove_<?php echo $certType; ?>_belt_template" value="1"
                                                class="text-xs text-red-600 hover:text-red-800 underline"
                                                onclick="return confirm('Remove this template?')">Remove</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="mb-3 w-full h-36 bg-gray-100 rounded border-2 border-dashed border-gray-300 flex items-center justify-center">
                                    <span class="text-sm text-gray-400">Using system default</span>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="<?php echo $certType; ?>_belt_template" accept=".png,.jpg,.jpeg"
                                   class="w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium <?php echo $isColor ? 'file:bg-amber-100 file:text-amber-700 hover:file:bg-amber-200' : 'file:bg-gray-800 file:text-white hover:file:bg-gray-700'; ?>">
                        </div>

                        <!-- Font Upload -->
                        <div class="border border-gray-200 rounded-lg p-4 mb-4">
                            <h4 class="font-semibold text-gray-800 mb-1"><?php echo $typeLabel; ?> Font</h4>
                            <p class="text-xs text-gray-500 mb-3">TTF, OTF, WOFF, or WOFF2 (max 5MB)</p>
                            <?php if ($hasFont): ?>
                                <div class="flex items-center gap-4 bg-green-50 border border-green-200 rounded-lg p-3 mb-3">
                                    <div class="flex-1">
                                        <span class="text-sm font-semibold text-green-800"><?php echo htmlspecialchars($fontName ?: 'Custom Font'); ?></span>
                                        <span style="font-family: <?php echo $fontFamilyCSS; ?>; font-size: 18px; color: #1a1a1a;" class="block mt-1">
                                            Preview: John Smith — <?php echo $isColor ? 'Blue Belt' : 'Black Belt 1st Dan'; ?>
                                        </span>
                                    </div>
                                    <button type="submit" name="remove_<?php echo $certType; ?>_font" value="1"
                                            class="text-xs text-red-600 hover:text-red-800 underline flex-shrink-0"
                                            onclick="return confirm('Remove font?')">Remove</button>
                                </div>
                            <?php else: ?>
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-2 mb-3">
                                    <span class="text-sm text-gray-500">Using default: <strong style="font-family: Georgia, serif;">Georgia</strong></span>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="<?php echo $certType; ?>_font" accept=".ttf,.otf,.woff,.woff2"
                                   class="w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-100 file:text-indigo-700 hover:file:bg-indigo-200">
                        </div>

                        <!-- Visual Placement Preview -->
                        <?php if ($hasTpl): ?>
                        <div class="border-2 <?php echo $isColor ? 'border-amber-300 bg-amber-50' : 'border-gray-600 bg-gray-50'; ?> rounded-lg p-4 mb-4">
                            <h4 class="font-semibold text-gray-800 mb-1">Visual Text Placement — <?php echo $typeLabel; ?></h4>
                            <p class="text-xs text-gray-500 mb-3">Drag labels to position them. Changes sync to controls below.</p>

                            <div id="certPreview_<?php echo $certType; ?>" class="cert-preview-box" style="position:relative;width:100%;max-width:800px;margin:0 auto;background:#f3f4f6;border-radius:8px;overflow:hidden;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);">
                                <img id="certPreviewImg_<?php echo $certType; ?>" src="<?php echo htmlspecialchars($tplPath); ?>" alt="Preview"
                                     style="width:100%;display:block;user-select:none;-webkit-user-drag:none;" draggable="false">
                                <div id="certOverlay_<?php echo $certType; ?>" style="position:absolute;inset:0;width:100%;height:100%;"></div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center bg-gray-50 mb-4">
                            <p class="text-gray-500 text-sm">Upload a <?php echo strtolower($typeLabel); ?> template above to enable the visual placement tool.</p>
                        </div>
                        <?php endif; ?>

                        <!-- Text Overlay Settings -->
                        <div class="border border-gray-200 rounded-lg p-4">
                            <h4 class="font-semibold text-gray-800 mb-3"><?php echo $typeLabel; ?> — Text Overlay Settings</h4>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($certFieldDefs as $field => $def):
                                    $prefix = 'cert_' . $typePfx . '_' . $field;
                                    $data = $jsData[$field];
                                    $bbOnly = ($field === 'bb_number');
                                    $isVis = $data['visible'];
                                ?>
                                <div class="rounded-lg p-3 cert-field-control-<?php echo $certType; ?> <?php echo $isVis ? 'bg-gray-50' : 'bg-gray-100 opacity-60'; ?>"
                                     data-field="<?php echo $field; ?>" data-type="<?php echo $certType; ?>"
                                     id="ctrl_<?php echo $certType; ?>_<?php echo $field; ?>">
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="text-sm font-semibold text-gray-700">
                                            <?php echo $def['label']; ?>
                                            <?php if ($bbOnly): ?><span class="text-xs text-gray-400 font-normal">(BB only)</span><?php endif; ?>
                                        </label>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="<?php echo $prefix; ?>_visible" value="1"
                                                   id="toggle_<?php echo $certType; ?>_<?php echo $field; ?>"
                                                   class="sr-only peer cert-vis-toggle" data-field="<?php echo $field; ?>" data-type="<?php echo $certType; ?>"
                                                   <?php echo $isVis ? 'checked' : ''; ?>>
                                            <div class="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-amber-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-amber-500"></div>
                                            <span class="ml-1.5 text-xs font-medium text-gray-500" id="tlbl_<?php echo $certType; ?>_<?php echo $field; ?>"><?php echo $isVis ? 'ON' : 'OFF'; ?></span>
                                        </label>
                                    </div>
                                    <div class="cert-field-inputs-wrap" id="inp_<?php echo $certType; ?>_<?php echo $field; ?>" style="<?php echo $isVis ? '' : 'opacity:0.4;pointer-events:none;'; ?>">
                                        <div class="grid grid-cols-4 gap-2 mb-2">
                                            <div>
                                                <label class="block text-xs text-gray-500">Top %</label>
                                                <input type="number" name="<?php echo $prefix; ?>_top_pct" min="0" max="100" step="1"
                                                       id="in_<?php echo $certType; ?>_<?php echo $field; ?>_top"
                                                       value="<?php echo $data['top']; ?>"
                                                       class="w-full px-2 py-1 border border-gray-300 rounded text-sm cert-inp" data-field="<?php echo $field; ?>" data-type="<?php echo $certType; ?>" data-prop="top">
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-500">Left %</label>
                                                <input type="number" name="<?php echo $prefix; ?>_left_pct" min="0" max="100" step="1"
                                                       id="in_<?php echo $certType; ?>_<?php echo $field; ?>_left"
                                                       value="<?php echo $data['left']; ?>"
                                                       class="w-full px-2 py-1 border border-gray-300 rounded text-sm cert-inp" data-field="<?php echo $field; ?>" data-type="<?php echo $certType; ?>" data-prop="left">
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-500">Size</label>
                                                <input type="number" name="<?php echo $prefix; ?>_font_size" min="8" max="80" step="1"
                                                       id="in_<?php echo $certType; ?>_<?php echo $field; ?>_size"
                                                       value="<?php echo $data['size']; ?>"
                                                       class="w-full px-2 py-1 border border-gray-300 rounded text-sm cert-inp" data-field="<?php echo $field; ?>" data-type="<?php echo $certType; ?>" data-prop="size">
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-500">Color</label>
                                                <input type="color" name="<?php echo $prefix; ?>_color"
                                                       id="in_<?php echo $certType; ?>_<?php echo $field; ?>_color"
                                                       value="<?php echo htmlspecialchars($data['color']); ?>"
                                                       class="w-full h-8 border border-gray-300 rounded cursor-pointer cert-inp" data-field="<?php echo $field; ?>" data-type="<?php echo $certType; ?>" data-prop="color">
                                            </div>
                                        </div>
                                        <!-- Stroke / Text Effects -->
                                        <div class="grid grid-cols-4 gap-2">
                                            <div class="col-span-2">
                                                <label class="block text-xs text-gray-500">Stroke Width (px)</label>
                                                <input type="number" name="<?php echo $prefix; ?>_stroke" min="0" max="10" step="0.5"
                                                       id="in_<?php echo $certType; ?>_<?php echo $field; ?>_stroke"
                                                       value="<?php echo $data['stroke']; ?>"
                                                       class="w-full px-2 py-1 border border-gray-300 rounded text-sm cert-inp" data-field="<?php echo $field; ?>" data-type="<?php echo $certType; ?>" data-prop="stroke">
                                            </div>
                                            <div class="col-span-2">
                                                <label class="block text-xs text-gray-500">Stroke Color</label>
                                                <input type="color" name="<?php echo $prefix; ?>_stroke_color"
                                                       id="in_<?php echo $certType; ?>_<?php echo $field; ?>_stroke_color"
                                                       value="<?php echo htmlspecialchars($data['stroke_color']); ?>"
                                                       class="w-full h-8 border border-gray-300 rounded cursor-pointer cert-inp" data-field="<?php echo $field; ?>" data-type="<?php echo $certType; ?>" data-prop="stroke_color">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    </div><!-- end certPanel -->
                    <?php endforeach; ?>

                    <div class="flex items-center gap-3 pt-2">
                        <button type="submit" name="save_certificate_templates" value="1"
                                class="bg-amber-600 hover:bg-amber-700 text-white px-6 py-2.5 rounded-lg text-sm font-medium">
                            Save All Certificate Settings
                        </button>
                        <span class="text-xs text-gray-500">Saves both Color Belt and Black Belt configurations</span>
                    </div>
                </form>
            </div>

            <!-- Certificate Placement JavaScript -->
            <script>
            // Tab switching
            function switchCertTab(type) {
                document.querySelectorAll('.cert-type-panel').forEach(function(p) { p.style.display = 'none'; });
                document.getElementById('certPanel_' + type).style.display = '';
                document.querySelectorAll('[id^="certTab_"]').forEach(function(t) {
                    t.classList.remove('border-amber-500', 'text-amber-700', 'bg-amber-50');
                    t.classList.add('border-transparent', 'text-gray-500');
                });
                var tab = document.getElementById('certTab_' + type);
                tab.classList.add('border-amber-500', 'text-amber-700', 'bg-amber-50');
                tab.classList.remove('border-transparent', 'text-gray-500');
                // Re-create overlays for the switched tab
                if (certEngines[type]) certEngines[type].refresh();
            }

            // Visual placement engine (one per cert type)
            var certEngines = {};
            <?php foreach (['color', 'black'] as $certType):
                $hasTplCheck = ($certType === 'color') ? $hasColorTpl : $hasBlackTpl;
                $jsDataVar = ($certType === 'color') ? $colorJsData : $blackJsData;
                $fontFam = ($certType === 'color')
                    ? ($hasColorFont ? "'CertColorFont', Georgia, serif" : "Georgia, 'Times New Roman', serif")
                    : ($hasBlackFont ? "'CertBlackFont', Georgia, serif" : "Georgia, 'Times New Roman', serif");
                if (!$hasTplCheck) continue;
            ?>
            (function() {
                var TYPE = '<?php echo $certType; ?>';
                var fields = <?php echo json_encode($jsDataVar, JSON_HEX_TAG | JSON_HEX_APOS); ?>;
                var fontFamily = <?php echo json_encode($fontFam); ?>;
                var container = document.getElementById('certPreview_' + TYPE);
                var overlay = document.getElementById('certOverlay_' + TYPE);
                var img = document.getElementById('certPreviewImg_' + TYPE);
                var elems = {};
                var fColors = {name:'#3b82f6',rank:'#8b5cf6',style:'#10b981',date:'#f59e0b',instructor:'#ef4444',bb_number:'#d97706'};

                function buildStrokeCSS(d) {
                    var s = parseFloat(d.stroke) || 0;
                    if (s <= 0) return 'none';
                    var c = d.stroke_color || '#000';
                    return s + 'px ' + s + 'px 0 ' + c + ',-' + s + 'px ' + s + 'px 0 ' + c + ',' + s + 'px -' + s + 'px 0 ' + c + ',-' + s + 'px -' + s + 'px 0 ' + c;
                }

                function updateStyle(el, d) {
                    el.style.top = d.top + '%';
                    el.style.left = d.left + '%';
                    var txt = el.querySelector('.cpt');
                    if (!txt) return;
                    var scale = container.offsetWidth / 960;
                    txt.style.fontSize = Math.max(8, Math.round(d.size * scale)) + 'px';
                    txt.style.color = d.color;
                    txt.style.textShadow = buildStrokeCSS(d);
                }

                function build() {
                    overlay.innerHTML = '';
                    elems = {};
                    Object.keys(fields).forEach(function(f) {
                        var d = fields[f];
                        var el = document.createElement('div');
                        el.dataset.field = f;
                        el.style.cssText = 'position:absolute;cursor:move;user-select:none;white-space:nowrap;font-family:'+fontFamily+';font-weight:bold;padding:2px 6px;border-radius:3px;transform:translate(-50%,0);';
                        if (!d.visible) el.style.display = 'none';

                        var tag = document.createElement('div');
                        tag.style.cssText = 'position:absolute;top:-18px;left:50%;transform:translateX(-50%);font-size:9px;font-weight:700;font-family:sans-serif;padding:1px 6px;border-radius:3px;white-space:nowrap;color:white;background:'+(fColors[f]||'#6b7280')+';pointer-events:none;line-height:14px;';
                        tag.textContent = d.label;
                        el.appendChild(tag);

                        var sp = document.createElement('span');
                        sp.className = 'cpt';
                        sp.textContent = d.sample;
                        el.appendChild(sp);

                        updateStyle(el, d);
                        el.style.boxShadow = '0 1px 3px rgba(0,0,0,0.2)';
                        el.style.border = '1px dashed '+(fColors[f]||'#6b7280')+'80';
                        el.style.zIndex = '10';

                        // Drag
                        (function(el, f) {
                            var sx,sy,st,sl,dragging=false;
                            el.addEventListener('mousedown', function(e) {
                                e.preventDefault(); dragging=true; sx=e.clientX; sy=e.clientY; st=fields[f].top; sl=fields[f].left; el.style.zIndex='30';
                                function mm(e){if(!dragging)return;var r=container.getBoundingClientRect();var nt=st+(e.clientY-sy)/r.height*100;var nl=sl+(e.clientX-sx)/r.width*100;nt=Math.max(0,Math.min(100,nt));nl=Math.max(0,Math.min(100,nl));fields[f].top=Math.round(nt);fields[f].left=Math.round(nl);el.style.top=nt+'%';el.style.left=nl+'%';var ti=document.getElementById('in_'+TYPE+'_'+f+'_top');var li=document.getElementById('in_'+TYPE+'_'+f+'_left');if(ti)ti.value=Math.round(nt);if(li)li.value=Math.round(nl);}
                                function mu(){dragging=false;document.removeEventListener('mousemove',mm);document.removeEventListener('mouseup',mu);}
                                document.addEventListener('mousemove',mm);document.addEventListener('mouseup',mu);
                            });
                            el.addEventListener('touchstart', function(e) {
                                if(e.touches.length!==1)return;e.preventDefault();var t=e.touches[0];dragging=true;sx=t.clientX;sy=t.clientY;st=fields[f].top;sl=fields[f].left;el.style.zIndex='30';
                                function tm(e){if(!dragging||e.touches.length!==1)return;e.preventDefault();var t=e.touches[0];var r=container.getBoundingClientRect();var nt=st+(t.clientY-sy)/r.height*100;var nl=sl+(t.clientX-sx)/r.width*100;nt=Math.max(0,Math.min(100,nt));nl=Math.max(0,Math.min(100,nl));fields[f].top=Math.round(nt);fields[f].left=Math.round(nl);el.style.top=nt+'%';el.style.left=nl+'%';var ti=document.getElementById('in_'+TYPE+'_'+f+'_top');var li=document.getElementById('in_'+TYPE+'_'+f+'_left');if(ti)ti.value=Math.round(nt);if(li)li.value=Math.round(nl);}
                                function te(){dragging=false;document.removeEventListener('touchmove',tm);document.removeEventListener('touchend',te);}
                                document.addEventListener('touchmove',tm,{passive:false});document.addEventListener('touchend',te);
                            },{passive:false});
                        })(el, f);

                        overlay.appendChild(el);
                        elems[f] = el;
                    });
                }

                // Input sync
                document.querySelectorAll('.cert-inp[data-type="'+TYPE+'"]').forEach(function(inp) {
                    inp.addEventListener('input', function() {
                        var f = this.dataset.field, p = this.dataset.prop;
                        if (!fields[f]) return;
                        if (p==='top') fields[f].top=parseFloat(this.value)||0;
                        else if (p==='left') fields[f].left=parseFloat(this.value)||0;
                        else if (p==='size') fields[f].size=parseInt(this.value)||14;
                        else if (p==='color') fields[f].color=this.value;
                        else if (p==='stroke') fields[f].stroke=parseFloat(this.value)||0;
                        else if (p==='stroke_color') fields[f].stroke_color=this.value;
                        if (elems[f]) updateStyle(elems[f], fields[f]);
                    });
                });

                // Visibility toggles
                document.querySelectorAll('.cert-vis-toggle[data-type="'+TYPE+'"]').forEach(function(tog) {
                    tog.addEventListener('change', function() {
                        var f = this.dataset.field, on = this.checked;
                        fields[f].visible = on ? 1 : 0;
                        if (elems[f]) elems[f].style.display = on ? '' : 'none';
                        var ctrl = document.getElementById('ctrl_'+TYPE+'_'+f);
                        var inp = document.getElementById('inp_'+TYPE+'_'+f);
                        var lbl = document.getElementById('tlbl_'+TYPE+'_'+f);
                        if (ctrl) { ctrl.classList.toggle('opacity-60',!on); ctrl.classList.toggle('bg-gray-100',!on); ctrl.classList.toggle('bg-gray-50',on); }
                        if (inp) { inp.style.opacity = on?'1':'0.4'; inp.style.pointerEvents = on?'':'none'; }
                        if (lbl) lbl.textContent = on?'ON':'OFF';
                    });
                });

                // Resize
                var rt;
                window.addEventListener('resize', function() { clearTimeout(rt); rt=setTimeout(function() { Object.keys(fields).forEach(function(f) { if(elems[f]) updateStyle(elems[f],fields[f]); }); },100); });

                function init() { if(container.offsetWidth > 0) build(); }
                img.addEventListener('load', init);
                if (img.complete) init();

                certEngines[TYPE] = { refresh: function() { if(img.complete) build(); } };
            })();
            <?php endforeach; ?>
            </script>
    </div><!-- /certificates panel -->
    <?php endif; ?>

    <!-- ═══ REGISTRATION TAB ═══ -->
    <?php if (canView('settings_registration.php')): ?>
    <div class="settings-panel" data-panel="registration" style="display:none;">

            <!-- Registration Waiver -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Registration Waiver</h2>
                <p class="text-sm text-gray-600 mb-4">Edit the liability waiver that all students must accept during registration. Leave the content empty to disable the waiver requirement.</p>
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Waiver Version</label>
                        <input type="text" name="waiver_version"
                               value="<?php echo htmlspecialchars(getSetting('waiver_version', '1.0')); ?>"
                               placeholder="e.g. v1.0 or 2026-02-12"
                               class="w-full max-w-xs px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Used to track which version each student agreed to. Leave blank to auto-generate from today's date.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Waiver Content</label>
                        <textarea name="waiver_content" rows="12"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                                  placeholder="Enter your waiver / liability agreement text here. This will be displayed to students during registration and they must check a box to agree before creating their account."><?php echo htmlspecialchars(getSetting('waiver_content', '')); ?></textarea>
                        <p class="text-xs text-gray-500 mt-1">Plain text. Students will see this in a scrollable box during registration and must check a box to agree.</p>
                    </div>
                    <?php
                    // Show how many students have accepted the current version
                    try {
                        $currentVer = getSetting('waiver_version', '1.0');
                        $waiverStats = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN waiver_version = ? THEN 1 ELSE 0 END) as current_ver FROM students WHERE waiver_accepted_at IS NOT NULL");
                        $waiverStats->execute([$currentVer]);
                        $ws = $waiverStats->fetch();
                        if ($ws && $ws['total'] > 0):
                    ?>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
                        <strong>Waiver Stats:</strong> <?php echo (int)$ws['total']; ?> student(s) have accepted a waiver. <?php echo (int)$ws['current_ver']; ?> accepted the current version (<?php echo htmlspecialchars($currentVer); ?>).
                    </div>
                    <?php endif; } catch (\PDOException $e) { /* columns may not exist yet */ } ?>
                    <button type="submit" name="save_waiver" value="1"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">
                        Save Waiver
                    </button>
                </form>
            </div>

            <!-- Communication Consent Waiver -->
            <div class="bg-white rounded-lg shadow p-6 mb-6 border-t-4 border-indigo-500">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Communication Consent Waiver</h2>
                <p class="text-sm text-gray-600 mb-4">Configure the digital communication consent text shown during registration and on profile pages. This waiver collects consent for email and SMS communications in compliance with TCPA and CAN-SPAM regulations.</p>
                <form method="POST" class="space-y-4">
                    <?php echo csrf_field(); ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Consent Version</label>
                        <input type="text" name="comm_consent_version"
                               value="<?php echo htmlspecialchars(getSetting('comm_consent_version', '1.0')); ?>"
                               placeholder="e.g. 1.0 or 2024-01-15"
                               class="w-64 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 text-sm">
                        <p class="text-xs text-gray-500 mt-1">When you change the consent text, update this version. Students/parents who consented to an older version will be shown the updated text on their profile page.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Consent Text</label>
                        <textarea name="comm_consent_content" rows="10"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 text-sm"
                                  placeholder="Leave blank to use the default TCPA-compliant consent text"><?php echo htmlspecialchars(getSetting('comm_consent_content', '')); ?></textarea>
                        <p class="text-xs text-gray-500 mt-1">Leave blank to use the built-in default text which includes all required TCPA/CAN-SPAM disclosures. If you customize this text, ensure it includes: message frequency disclosure, "Message and data rates may apply", opt-out instructions (Reply STOP for SMS), and a statement that consent is not required for enrollment.</p>
                    </div>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                        <h4 class="text-sm font-semibold text-gray-700 mb-2">Default Consent Text Preview:</h4>
                        <div class="text-xs text-gray-600 whitespace-pre-line"><?php
                            require_once __DIR__ . '/includes/messaging.php';
                            echo htmlspecialchars(get_comm_consent_text());
                        ?></div>
                    </div>
                    <?php
                    // Consent statistics
                    try {
                        $currentConsentVer = getSetting('comm_consent_version', '1.0');
                        $studentConsentStats = $pdo->prepare("SELECT
                            COUNT(*) as total,
                            SUM(CASE WHEN comm_consent_email = 1 THEN 1 ELSE 0 END) as email_consented,
                            SUM(CASE WHEN comm_consent_sms = 1 THEN 1 ELSE 0 END) as sms_consented,
                            SUM(CASE WHEN comm_consent_version = ? THEN 1 ELSE 0 END) as current_ver
                            FROM students WHERE (comm_consent_email = 1 OR comm_consent_sms = 1)");
                        $studentConsentStats->execute([$currentConsentVer]);
                        $scs = $studentConsentStats->fetch();

                        $parentConsentStats = $pdo->prepare("SELECT
                            COUNT(*) as total,
                            SUM(CASE WHEN comm_consent_email = 1 THEN 1 ELSE 0 END) as email_consented,
                            SUM(CASE WHEN comm_consent_sms = 1 THEN 1 ELSE 0 END) as sms_consented,
                            SUM(CASE WHEN comm_consent_version = ? THEN 1 ELSE 0 END) as current_ver
                            FROM parents WHERE (comm_consent_email = 1 OR comm_consent_sms = 1)");
                        $parentConsentStats->execute([$currentConsentVer]);
                        $pcs = $parentConsentStats->fetch();

                        if (($scs && $scs['total'] > 0) || ($pcs && $pcs['total'] > 0)):
                    ?>
                    <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3 text-xs text-indigo-700">
                        <strong>Consent Stats:</strong>
                        <?php if ($scs && $scs['total'] > 0): ?>
                            Students: <?php echo (int)$scs['email_consented']; ?> email, <?php echo (int)$scs['sms_consented']; ?> SMS consented (<?php echo (int)$scs['current_ver']; ?> on version <?php echo htmlspecialchars($currentConsentVer); ?>).
                        <?php endif; ?>
                        <?php if ($pcs && $pcs['total'] > 0): ?>
                            Parents: <?php echo (int)$pcs['email_consented']; ?> email, <?php echo (int)$pcs['sms_consented']; ?> SMS consented (<?php echo (int)$pcs['current_ver']; ?> on version <?php echo htmlspecialchars($currentConsentVer); ?>).
                        <?php endif; ?>
                    </div>
                    <?php endif; } catch (\PDOException $e) { /* consent columns may not exist yet */ } ?>
                    <button type="submit" name="save_comm_consent" value="1"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm">
                        Save Communication Consent
                    </button>
                </form>
            </div>

            <?php if (hasFinancialAccess()): ?>
            <!-- Fees & Processing -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Fees &amp; Processing</h2>
                <p class="text-sm text-gray-600 mb-4">Configure service fees applied to all payments as a visible line item.</p>
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Service Fee Percentage (%)</label>
                        <div class="flex items-center gap-2">
                            <input type="number" name="service_fee_percentage" step="0.01" min="0" max="100"
                                   value="<?php echo htmlspecialchars(getSetting('service_fee_percentage', '0')); ?>"
                                   class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            <span class="text-gray-500">%</span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Applied to all payments (memberships, events, renewals) as a visible line item. Set to 0 to disable.</p>
                    </div>
                    <button type="submit" name="save_fee_settings" value="1"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">
                        Save Fee Settings
                    </button>
                </form>
            </div>
            <?php endif; ?>
    </div><!-- /registration panel -->
    <?php endif; ?>

    <!-- ═══ BILLING TAB ═══ -->
    <?php if (canView('settings_billing.php') && hasFinancialAccess()): ?>
    <div class="settings-panel" data-panel="billing" style="display:none;">

            <!-- Tax Statement Settings -->
            <div class="bg-white rounded-lg shadow p-6 mb-6 border-t-4 border-green-500">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Tax Statement Settings</h2>
                <p class="text-sm text-gray-600 mb-4">Configure your business information for year-end tax statements. Parents can view and print tax-eligible payment summaries for dependent care expenses.</p>
                <form method="POST" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Business / Provider Name</label>
                            <input type="text" name="tax_business_name"
                                   value="<?php echo htmlspecialchars(getSetting('tax_business_name', '')); ?>"
                                   placeholder="e.g., ABC Martial Arts Academy LLC"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tax ID / EIN</label>
                            <input type="text" name="tax_id_ein"
                                   value="<?php echo htmlspecialchars(getSetting('tax_id_ein', '')); ?>"
                                   placeholder="e.g., 12-3456789"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Business Address</label>
                        <textarea name="tax_business_address" rows="2"
                                  placeholder="123 Main St, Suite 100&#10;City, State 12345"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500"><?php echo htmlspecialchars(getSetting('tax_business_address', '')); ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tax Statement Footer Note</label>
                        <textarea name="tax_statement_note" rows="2"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500"><?php echo htmlspecialchars(getSetting('tax_statement_note', 'This statement is provided for informational purposes. Please consult your tax advisor.')); ?></textarea>
                        <p class="text-xs text-gray-500 mt-1">Appears at the bottom of all tax statements.</p>
                    </div>
                    <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-xs text-green-700">
                        <strong>Tip:</strong> To flag programs as tax-eligible, check the "Tax-Deductible" option when creating events (Events page) or membership plans (Memberships page). Only payments linked to tax-deductible programs will appear on tax statements.
                    </div>
                    <button type="submit" name="save_tax_settings" value="1"
                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm">
                        Save Tax Settings
                    </button>
                </form>
            </div>

            <!-- Payment Gateway Settings -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Payment Gateway Integration</h2>
                <p class="text-sm text-gray-600 mb-4">Configure your payment processing settings</p>
                
                <!-- Stripe Settings -->
                <form method="POST" class="mb-6">
                    <div class="border-l-4 border-blue-500 pl-4 py-2 bg-blue-50">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-semibold text-gray-800">Stripe</h3>
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="stripe_enabled" value="1" 
                                       <?php echo getSetting('stripe_enabled') == '1' ? 'checked' : ''; ?>
                                       class="mr-2">
                                <span class="text-sm text-gray-700">Enable Stripe</span>
                            </label>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Publishable Key</label>
                                <input type="text" name="stripe_publishable_key" 
                                       value="<?php echo getSetting('stripe_publishable_key'); ?>"
                                       placeholder="pk_test_..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Secret Key</label>
                                <input type="password" name="stripe_secret_key" 
                                       value="<?php echo getSetting('stripe_secret_key'); ?>"
                                       placeholder="sk_test_..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            </div>
                            <button type="submit" name="save_stripe" value="1"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">
                                Save Stripe Settings
                            </button>
                            <p class="text-xs text-gray-500 mt-2">
                                Get your Stripe keys from <a href="https://dashboard.stripe.com/apikeys" target="_blank" class="text-blue-600 underline">Stripe Dashboard</a>
                            </p>
                        </div>
                    </div>
                </form>
                
                <!-- Square Settings -->
                <form method="POST">
                    <div class="border-l-4 border-green-500 pl-4 py-2 bg-green-50">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-semibold text-gray-800">Square</h3>
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="square_enabled" value="1"
                                       <?php echo getSetting('square_enabled') == '1' ? 'checked' : ''; ?>
                                       class="mr-2">
                                <span class="text-sm text-gray-700">Enable Square</span>
                            </label>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Application ID</label>
                                <input type="text" name="square_application_id" 
                                       value="<?php echo getSetting('square_application_id'); ?>"
                                       placeholder="sq0idp-..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Access Token</label>
                                <input type="password" name="square_access_token" 
                                       value="<?php echo getSetting('square_access_token'); ?>"
                                       placeholder="sq0atp-..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                            </div>
                            <button type="submit" name="save_square" value="1"
                                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm">
                                Save Square Settings
                            </button>
                            <p class="text-xs text-gray-500 mt-2">
                                Get your Square credentials from <a href="https://developer.squareup.com/apps" target="_blank" class="text-green-600 underline">Square Developer Portal</a>
                            </p>
                        </div>
                    </div>
                </form>
            </div>
    </div><!-- /billing panel -->
    <?php endif; ?>

    <!-- ═══ COMMUNICATIONS TAB ═══ -->
    <?php if (canView('settings_communications.php') && hasFinancialAccess()): ?>
    <div class="settings-panel" data-panel="communications" style="display:none;">

            <!-- Email (SMTP) Configuration -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Email Configuration (SMTP)</h2>
                <p class="text-sm text-gray-600 mb-4">Configure SMTP settings to send emails to students and staff</p>

                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="border-l-4 border-purple-500 pl-4 py-2 bg-purple-50 rounded-r-lg">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Host *</label>
                                <input type="text" name="smtp_host"
                                       value="<?php echo htmlspecialchars(getSetting('smtp_host', '')); ?>"
                                       placeholder="smtp.gmail.com"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Port</label>
                                <input type="number" name="smtp_port"
                                       value="<?php echo htmlspecialchars(getSetting('smtp_port', '587')); ?>"
                                       placeholder="587"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Username</label>
                                <input type="text" name="smtp_username"
                                       value="<?php echo htmlspecialchars(getSetting('smtp_username', '')); ?>"
                                       placeholder="your@email.com"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Password</label>
                                <input type="password" name="smtp_password"
                                       value=""
                                       placeholder="<?php echo getSetting('smtp_password', '') !== '' ? '********' : 'Enter password'; ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                                <p class="text-xs text-gray-400 mt-1">Leave blank to keep existing password</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Encryption</label>
                                <select name="smtp_encryption" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                                    <option value="tls" <?php echo getSetting('smtp_encryption', 'tls') === 'tls' ? 'selected' : ''; ?>>TLS (Recommended)</option>
                                    <option value="ssl" <?php echo getSetting('smtp_encryption', 'tls') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                    <option value="none" <?php echo getSetting('smtp_encryption', 'tls') === 'none' ? 'selected' : ''; ?>>None</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">From Email *</label>
                                <input type="email" name="smtp_from_email"
                                       value="<?php echo htmlspecialchars(getSetting('smtp_from_email', '')); ?>"
                                       placeholder="noreply@yourstudio.com"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">From Name</label>
                                <input type="text" name="smtp_from_name"
                                       value="<?php echo htmlspecialchars(getSetting('smtp_from_name', '')); ?>"
                                       placeholder="<?php echo htmlspecialchars(getSiteName()); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                            </div>
                        </div>
                        <div class="flex items-center gap-3 mt-4">
                            <button type="submit" name="save_smtp" value="1"
                                    class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm">
                                Save SMTP Settings
                            </button>
                            <?php
                            $adminEmail = $current_user['email'] ?? '';
                            if ($adminEmail): ?>
                                <button type="submit" name="test_email" value="1"
                                        class="bg-purple-100 hover:bg-purple-200 text-purple-700 px-4 py-2 rounded-lg text-sm">
                                    Send Test Email
                                </button>
                                <span class="text-xs text-gray-500">Sends to <strong><?php echo htmlspecialchars($adminEmail); ?></strong></span>
                            <?php else: ?>
                                <button type="button" disabled
                                        class="bg-gray-100 text-gray-400 px-4 py-2 rounded-lg text-sm cursor-not-allowed">
                                    Send Test Email
                                </button>
                                <span class="text-xs text-red-500">⚠ No email on your admin account — set it in the <a href="settings.php#general" class="underline text-red-600" onclick="if(typeof switchTab==='function')switchTab('general');">General tab</a> first</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- SMS (Twilio) Configuration -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">SMS Configuration (Twilio)</h2>
                <p class="text-sm text-gray-600 mb-4">Configure Twilio to send text messages to students and staff</p>

                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="border-l-4 border-red-500 pl-4 py-2 bg-red-50 rounded-r-lg">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Account SID *</label>
                                <input type="text" name="twilio_account_sid"
                                       value="<?php echo htmlspecialchars(getSetting('twilio_account_sid', '')); ?>"
                                       placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Auth Token *</label>
                                <input type="password" name="twilio_auth_token"
                                       value=""
                                       placeholder="<?php echo getSetting('twilio_auth_token', '') !== '' ? '********' : 'Enter auth token'; ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
                                <p class="text-xs text-gray-400 mt-1">Leave blank to keep existing token</p>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">From Phone Number *</label>
                                <input type="text" name="twilio_from_number"
                                       value="<?php echo htmlspecialchars(getSetting('twilio_from_number', '')); ?>"
                                       placeholder="+1234567890"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
                                <p class="text-xs text-gray-400 mt-1">Your Twilio phone number in E.164 format</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 mt-4">
                            <button type="submit" name="save_twilio" value="1"
                                    class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm">
                                Save Twilio Settings
                            </button>
                        </div>
                        <div class="flex items-center gap-3 mt-3 pt-3 border-t border-red-200">
                            <input type="text" name="test_sms_number" placeholder="+1234567890"
                                   class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-red-500" style="max-width:200px;">
                            <button type="submit" name="test_sms" value="1"
                                    class="bg-red-100 hover:bg-red-200 text-red-700 px-4 py-2 rounded-lg text-sm">
                                Send Test SMS
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-3">
                            Get your credentials from <a href="https://console.twilio.com/" target="_blank" class="text-red-600 underline">Twilio Console</a>
                        </p>
                        <?php
                        $webhookBaseUrl = getSetting('app_base_url', '');
                        if ($webhookBaseUrl === '') {
                            $wScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                            $wHost   = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
                            $wPath   = dirname($_SERVER['SCRIPT_NAME'] ?? '');
                            $webhookBaseUrl = $wScheme . '://' . $wHost . rtrim($wPath, '/');
                        }
                        $webhookUrl = rtrim($webhookBaseUrl, '/') . '/twilio_sms_webhook.php';
                        ?>
                        <div class="mt-4 pt-3 border-t border-red-200">
                            <p class="text-xs font-semibold text-gray-700 mb-1">SMS Webhook URL (for STOP/START handling):</p>
                            <div class="flex items-center gap-2">
                                <code class="text-xs bg-gray-100 px-2 py-1 rounded break-all flex-1"><?= htmlspecialchars($webhookUrl) ?></code>
                                <button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent).then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',2000)})"
                                        class="text-xs bg-gray-200 hover:bg-gray-300 px-2 py-1 rounded whitespace-nowrap">Copy</button>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">
                                Paste this URL into your Twilio phone number's <strong>Messaging &rarr; "A message comes in"</strong> webhook field.
                                This enables automatic STOP/START/HELP handling for SMS opt-outs.
                            </p>
                        </div>
                    </div>
                </form>
            </div>
            <!-- Notification Templates -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Notification Templates</h2>
                <p class="text-sm text-gray-600 mb-4">
                    Customize the automated messages sent to students and parents.
                    Leave any field blank to use the default text.
                    Use the placeholders shown below each template &mdash; they will be replaced with actual values when the message is sent.
                </p>

                <form method="POST" class="space-y-3">
                    <?php echo csrf_field(); ?>
                    <?php
                    if (!function_exists('get_notification_template_definitions')) {
                        require_once __DIR__ . '/includes/messaging.php';
                    }
                    $tplDefs = get_notification_template_definitions();
                    $tplIndex = 0;
                    foreach ($tplDefs as $tplKey => $tplDef):
                        $tplIndex++;
                        $subjectSettingKey = "notif_tpl_{$tplKey}_subject";
                        $bodySettingKey    = "notif_tpl_{$tplKey}_body";
                        $customSubject = getSetting($subjectSettingKey, '');
                        $customBody    = getSetting($bodySettingKey, '');
                        $channelColor  = $tplDef['channel'] === 'sms' ? 'red' : 'purple';
                        $channelLabel  = strtoupper($tplDef['channel']);
                        $isCustomized  = ($customSubject !== '' || $customBody !== '');
                    ?>
                    <div class="border border-gray-200 rounded-lg overflow-hidden">
                        <!-- Accordion Header -->
                        <button type="button"
                                onclick="var b=this.parentElement.querySelector('.tpl-body');b.style.display=b.style.display==='none'?'':'none';this.querySelector('.tpl-chevron').classList.toggle('rotate-180');"
                                class="w-full flex items-center justify-between px-4 py-3 bg-gray-50 hover:bg-gray-100 transition text-left">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-<?php echo $channelColor; ?>-100 text-<?php echo $channelColor; ?>-700">
                                    <?php echo $channelLabel; ?>
                                </span>
                                <span class="text-sm font-medium text-gray-800">
                                    <?php echo htmlspecialchars($tplDef['label']); ?>
                                </span>
                                <?php if ($isCustomized): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-green-100 text-green-700">Customized</span>
                                <?php endif; ?>
                            </div>
                            <svg class="tpl-chevron w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <!-- Accordion Body -->
                        <div class="tpl-body px-4 py-4 border-t border-gray-200 space-y-3" style="display:none;">
                            <p class="text-xs text-gray-500 italic">
                                <?php echo htmlspecialchars($tplDef['description']); ?>
                            </p>

                            <?php if ($tplDef['channel'] === 'email'): ?>
                            <!-- Subject (email only) -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                                <input type="text"
                                       name="<?php echo $subjectSettingKey; ?>"
                                       value="<?php echo htmlspecialchars($customSubject); ?>"
                                       placeholder="<?php echo htmlspecialchars($tplDef['default_subject']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500 text-sm">
                            </div>
                            <?php endif; ?>

                            <!-- Body -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <?php echo $tplDef['channel'] === 'sms' ? 'Message Text' : 'Message Body (HTML)'; ?>
                                </label>
                                <textarea name="<?php echo $bodySettingKey; ?>"
                                          rows="<?php echo $tplDef['channel'] === 'sms' ? '3' : '6'; ?>"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500 text-sm font-mono"
                                          placeholder="Leave blank to use default"
                                ><?php echo htmlspecialchars($customBody); ?></textarea>
                                <?php if ($tplDef['channel'] === 'email'): ?>
                                    <p class="text-xs text-gray-400 mt-1">
                                        HTML formatting is supported. The school header, footer, and styling are added automatically.
                                    </p>
                                <?php else: ?>
                                    <p class="text-xs text-gray-400 mt-1">Plain text only. Max 1600 characters.</p>
                                <?php endif; ?>
                            </div>

                            <!-- Available Placeholders -->
                            <div class="bg-blue-50 rounded-lg p-3">
                                <p class="text-xs font-semibold text-gray-700 mb-1.5">Available Placeholders:</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <?php foreach ($tplDef['placeholders'] as $ph => $phDesc): ?>
                                        <span class="inline-flex items-center gap-1 text-xs bg-white border border-blue-200 rounded px-2 py-1"
                                              title="<?php echo htmlspecialchars($phDesc); ?>">
                                            <code class="text-purple-600 font-semibold"><?php echo htmlspecialchars($ph); ?></code>
                                            <span class="text-gray-400">&ndash;</span>
                                            <span class="text-gray-500"><?php echo htmlspecialchars($phDesc); ?></span>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Default Preview -->
                            <details class="text-xs">
                                <summary class="cursor-pointer text-blue-600 hover:text-blue-800 font-medium">
                                    View Default Template
                                </summary>
                                <div class="mt-2 bg-gray-50 border border-gray-200 rounded p-3 space-y-2">
                                    <?php if ($tplDef['channel'] === 'email' && $tplDef['default_subject']): ?>
                                        <div>
                                            <span class="font-semibold text-gray-600">Subject:</span>
                                            <span class="text-gray-700"><?php echo htmlspecialchars($tplDef['default_subject']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <span class="font-semibold text-gray-600">Body:</span>
                                        <pre class="mt-1 text-gray-600 whitespace-pre-wrap break-words text-xs bg-white p-2 rounded border border-gray-100"><?php echo htmlspecialchars($tplDef['default_body']); ?></pre>
                                    </div>
                                </div>
                            </details>

                            <?php if ($isCustomized): ?>
                                <button type="button"
                                        onclick="if(confirm('Reset this template to default? Your custom text will be cleared.')){var p=this.closest('.tpl-body');var s=p.querySelector('input[name=&quot;<?php echo $subjectSettingKey; ?>&quot;]');if(s)s.value='';p.querySelector('textarea[name=&quot;<?php echo $bodySettingKey; ?>&quot;]').value='';}"
                                        class="text-xs text-red-600 hover:text-red-800 font-medium">
                                    &#x21bb; Reset to Default
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="pt-4">
                        <button type="submit" name="save_notification_templates" value="1"
                                class="bg-green-600 hover:bg-green-700 text-white px-6 py-2.5 rounded-lg text-sm font-medium">
                            Save All Templates
                        </button>
                    </div>
                </form>
            </div>
    </div><!-- /communications panel -->
    <?php endif; ?>

    <!-- ═══ SYSTEM TAB ═══ -->
    <?php if (canView('settings_system.php')): ?>
    <div class="settings-panel" data-panel="system" style="display:none;">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div>
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Testing &amp; Debug</h2>
                <form method="POST" class="space-y-4">
                    <?php echo csrf_field(); ?>

                    <!-- Global Student Lockout Test -->
                    <div class="p-4 rounded-lg <?php echo getSetting('test_lockout_all_students') == '1' ? 'bg-red-50 border-2 border-red-300' : 'bg-gray-50 border border-gray-200'; ?>">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <label class="text-sm font-semibold <?php echo getSetting('test_lockout_all_students') == '1' ? 'text-red-800' : 'text-gray-700'; ?>">
                                    Force Payment Lockout (All Students)
                                </label>
                                <p class="text-xs <?php echo getSetting('test_lockout_all_students') == '1' ? 'text-red-600' : 'text-gray-500'; ?> mt-1">
                                    When enabled, ALL students will see the payment lockout screen regardless of their actual payment status. Use this to verify the lockout UI, disabled navigation, and redirect behavior.
                                </p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" name="test_lockout_all_students" value="1" class="sr-only peer"
                                       <?php echo getSetting('test_lockout_all_students') == '1' ? 'checked' : ''; ?>>
                                <div class="w-11 h-6 bg-gray-300 peer-focus:ring-2 peer-focus:ring-red-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-red-500"></div>
                            </label>
                        </div>
                        <?php if (getSetting('test_lockout_all_students') == '1'): ?>
                            <div class="mt-3 flex items-center gap-2 text-red-700 text-xs font-semibold bg-red-100 rounded px-3 py-2">
                                <span>&#9888;&#65039;</span>
                                <span>ACTIVE &mdash; All students are currently locked out. Remember to disable this when done testing!</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" name="save_test_settings"
                            class="w-full bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        Save Test Settings
                    </button>
                </form>
            </div>

            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Log Retention</h2>
                <form method="POST" class="space-y-4">
                    <?php echo csrf_field(); ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Retention Period (days)</label>
                        <input type="number" name="log_retention_days" min="7" max="365"
                               value="<?php echo htmlspecialchars(getSetting('log_retention_days', '90')); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Audit log and application log entries older than this will be automatically deleted during cron processing. Min: 7 days, Max: 365 days.</p>
                    </div>
                    <?php
                    $currentRetention = (int) getSetting('log_retention_days', '90');
                    $cutoffDate = date('Y-m-d', strtotime("-{$currentRetention} days"));
                    ?>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-xs text-gray-600 space-y-1">
                        <div class="flex justify-between">
                            <span>Current retention:</span>
                            <span class="font-semibold"><?php echo $currentRetention; ?> days</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Entries before this date will be cleaned:</span>
                            <span class="font-semibold"><?php echo $cutoffDate; ?></span>
                        </div>
                    </div>
                    <button type="submit" name="save_log_retention"
                            class="w-full bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        Save Log Retention
                    </button>
                </form>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">System Information</h2>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between border-b pb-2">
                        <span class="text-gray-600">Version</span>
                        <span class="font-semibold">1.0.0</span>
                    </div>
                    <div class="flex justify-between border-b pb-2">
                        <span class="text-gray-600">PHP Version</span>
                        <span class="font-semibold"><?php echo phpversion(); ?></span>
                    </div>
                    <div class="flex justify-between border-b pb-2">
                        <span class="text-gray-600">Database</span>
                        <span class="font-semibold">MySQL</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Server Time</span>
                        <span class="font-semibold"><?php echo date('Y-m-d H:i:s'); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Push Notifications (FCM)</h2>
                <?php
                $fcmSaPath = getSetting('fcm_service_account_path', '');
                $fcmProjectId = getSetting('fcm_project_id', '');
                $fcmLegacyKey = getSetting('fcm_server_key', '');
                $fcmConfigured = !empty($fcmSaPath) && file_exists(__DIR__ . '/' . $fcmSaPath);
                $fcmLegacyOnly = !$fcmConfigured && !empty($fcmLegacyKey);

                // Read client_email from the service account file for display
                $fcmClientEmail = '';
                if ($fcmConfigured) {
                    try {
                        $saContent = json_decode(file_get_contents(__DIR__ . '/' . $fcmSaPath), true);
                        $fcmClientEmail = $saContent['client_email'] ?? '';
                    } catch (Throwable $e) {}
                }
                ?>

                <!-- Status Badge -->
                <?php if ($fcmConfigured): ?>
                    <div class="flex items-start gap-2 p-3 rounded-lg text-sm bg-green-50 border border-green-200 text-green-700 mb-4">
                        <svg class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        <div>
                            <strong>Configured (v1 API)</strong>
                            <div class="mt-1 text-xs space-y-0.5">
                                <div>Project: <code class="bg-green-100 px-1 rounded"><?php echo htmlspecialchars($fcmProjectId); ?></code></div>
                                <?php if ($fcmClientEmail): ?>
                                    <div>Account: <code class="bg-green-100 px-1 rounded"><?php echo htmlspecialchars($fcmClientEmail); ?></code></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php elseif ($fcmLegacyOnly): ?>
                    <div class="flex items-start gap-2 p-3 rounded-lg text-sm bg-orange-50 border border-orange-200 text-orange-700 mb-4">
                        <svg class="w-5 h-5 text-orange-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                        <div>
                            <strong>Legacy API Detected (Deprecated)</strong>
                            <p class="text-xs mt-1">You have a legacy server key configured, but Google shut down the Legacy FCM API in July 2024. Upload a service account key below to migrate to the v1 API.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="flex items-center gap-2 p-3 rounded-lg text-sm bg-yellow-50 border border-yellow-200 text-yellow-700 mb-4">
                        <svg class="w-5 h-5 text-yellow-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                        <span><strong>Not configured</strong> &mdash; Push notifications are disabled. Upload a service account key to enable.</span>
                    </div>
                <?php endif; ?>

                <!-- Upload Form -->
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <?php echo csrf_field(); ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <?php echo $fcmConfigured ? 'Replace' : 'Upload'; ?> Firebase Service Account Key
                        </label>
                        <input type="file" name="fcm_service_account" accept=".json"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 text-sm
                                      file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-medium
                                      file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                        <p class="text-xs text-gray-500 mt-1">
                            Download from <strong>Firebase Console &rarr; Project Settings &rarr; Service accounts &rarr; Generate new private key</strong>.
                            The JSON file contains your project credentials for sending push notifications via the FCM v1 API.
                        </p>
                    </div>

                    <?php
                    // Device token stats
                    try {
                        $tokenStmt = $pdo->query("SELECT COUNT(*) as total, COUNT(DISTINCT user_id) as users FROM device_tokens WHERE is_active = 1" . (function_exists('school_where') ? " AND " . school_where() : ""));
                        $tokenStats = $tokenStmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Throwable $e) {
                        $tokenStats = ['total' => 0, 'users' => 0];
                    }
                    ?>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-xs text-gray-600 space-y-1">
                        <div class="flex justify-between">
                            <span>Registered devices:</span>
                            <span class="font-semibold"><?php echo (int) $tokenStats['total']; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Unique users with app:</span>
                            <span class="font-semibold"><?php echo (int) $tokenStats['users']; ?></span>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" name="save_fcm_settings"
                                class="flex-1 bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-medium">
                            <?php echo $fcmConfigured ? 'Replace Service Account' : 'Upload Service Account'; ?>
                        </button>
                        <?php if ($fcmConfigured): ?>
                            <button type="submit" name="remove_fcm_service_account"
                                    class="bg-red-100 hover:bg-red-200 text-red-700 px-4 py-2 rounded-lg text-sm font-medium"
                                    onclick="return confirm('Remove the Firebase service account? Push notifications will be disabled.');">
                                Remove
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Quick Actions</h2>
                <div class="space-y-2">
                    <a href="test_regression.php" class="block w-full text-center bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                        &#9989; Run Regression Tests
                    </a>
                    <a href="test_renewals.php" class="block w-full text-center bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
                        &#128269; Test Renewals
                    </a>
                    <a href="index.php" class="block w-full text-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                        Dashboard
                    </a>
                    <a href="students.php" class="block w-full text-center bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        Manage Students
                    </a>
                    <a href="events.php" class="block w-full text-center bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
                        Manage Events
                    </a>
                    <a href="logout.php" class="block w-full text-center bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        Logout
                    </a>
                </div>
            </div>
    </div><!-- /grid inside system -->
    </div><!-- /system panel -->
    <?php endif; ?>

    </div><!-- /settingsContent -->
</div><!-- /container -->

<script>
(function() {
    var tabs = document.querySelectorAll('.settings-tab');
    var panels = document.querySelectorAll('.settings-panel');

    function switchTab(tabName) {
        tabs.forEach(function(t) {
            if (t.dataset.tab === tabName) {
                t.classList.remove('border-transparent', 'text-gray-500');
                t.classList.add('border-blue-500', 'text-blue-600', 'bg-blue-50');
            } else {
                t.classList.remove('border-blue-500', 'text-blue-600', 'bg-blue-50');
                t.classList.add('border-transparent', 'text-gray-500');
            }
        });
        panels.forEach(function(p) {
            p.style.display = (p.dataset.panel === tabName) ? '' : 'none';
        });
        window.location.hash = tabName;
        try { localStorage.setItem('settings_active_tab', tabName); } catch(e) {}

        // Refresh certificate preview when switching to that tab
        if (tabName === 'certificates' && typeof certEngines !== 'undefined') {
            setTimeout(function() {
                Object.keys(certEngines).forEach(function(k) {
                    if (certEngines[k] && certEngines[k].refresh) certEngines[k].refresh();
                });
            }, 100);
        }
    }

    tabs.forEach(function(t) {
        t.addEventListener('click', function() { switchTab(this.dataset.tab); });
    });

    // Determine initial tab: server-set (after POST) > URL hash > localStorage > default
    var validTabs = [<?php echo implode(',', array_map(function($t) { return "'" . $t['id'] . "'"; }, $settingsTabs)); ?>];
    var initialTab = '<?= $firstVisibleTab ?>';
    <?php if (!empty($activeTabAfterPost)): ?>
    initialTab = '<?= $activeTabAfterPost ?>';
    <?php else: ?>
    var hash = window.location.hash.replace('#','');
    if (hash && validTabs.indexOf(hash) !== -1) {
        initialTab = hash;
    } else {
        try {
            var stored = localStorage.getItem('settings_active_tab');
            if (stored && validTabs.indexOf(stored) !== -1) initialTab = stored;
        } catch(e) {}
    }
    <?php endif; ?>

    switchTab(initialTab);
})();
</script>

<?php include 'includes/footer.php'; ?>
