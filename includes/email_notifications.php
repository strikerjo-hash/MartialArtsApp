<?php
/**
 * email_notifications.php — Payment Receipt & Communication Email Helpers
 *
 * All functions are fire-and-forget: wrapped in try/catch so that payment
 * processing and messaging flows are never interrupted by email failures.
 *
 * Requires: messaging.php (send_email, is_email_configured, has_comm_consent)
 *           config.php    (getSetting, getSiteName, formatMoney, formatDate)
 *           tenant.php    (current_school_id)
 */

if (!function_exists('has_comm_consent')) {
    require_once __DIR__ . '/messaging.php';
}

// ─── Helper: Look up student + parent emails ────────────────────────────────

/**
 * Get student name/email and their linked parent's email (if any).
 *
 * @return array{student_name: string, student_email: string, parent_email: string|null}
 */
function get_student_parent_emails(int $studentId): array
{
    $result = ['student_name' => '', 'student_email' => '', 'parent_email' => null];

    try {
        $pdo = get_db();
        $schoolId = current_school_id();

        // Student info
        $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM students WHERE id = ? AND school_id = ?");
        $stmt->execute([$studentId, $schoolId]);
        $stu = $stmt->fetch();
        if ($stu) {
            $result['student_name']  = trim($stu['first_name'] . ' ' . $stu['last_name']);
            $result['student_email'] = trim($stu['email'] ?? '');
        }

        // Parent email — parent is a student linked via parent_students table
        $pStmt = $pdo->prepare("
            SELECT s.email
            FROM parent_students ps
            JOIN students s ON s.id = ps.parent_id AND s.school_id = ?
            WHERE ps.student_id = ?
            LIMIT 1
        ");
        $pStmt->execute([$schoolId, $studentId]);
        $parentRow = $pStmt->fetch();
        if ($parentRow && !empty($parentRow['email'])) {
            $result['parent_email'] = trim($parentRow['email']);
        }
    } catch (\Throwable $e) {
        // Silently fail — don't break calling code
    }

    return $result;
}

// ─── HTML Templates ─────────────────────────────────────────────────────────

/**
 * Build a professional HTML payment receipt email body.
 */
function build_receipt_html(array $data, string $studentName, string $schoolName): string
{
    $amount        = formatMoney($data['amount'] ?? 0);
    $date          = formatDate(date('Y-m-d'));
    $receiptNum    = htmlspecialchars($data['receipt_number'] ?? 'N/A');
    $transactionId = htmlspecialchars($data['transaction_id'] ?? '');
    $description   = htmlspecialchars($data['description'] ?? '');
    $paymentType   = ucfirst($data['payment_type'] ?? 'other');
    $schoolNameSafe = htmlspecialchars($schoolName);
    $studentNameSafe = htmlspecialchars($studentName);

    // Payment method label
    $methodLabels = [
        'credit_card'    => 'Credit/Debit Card',
        'account_credit' => 'Account Credit',
        'cash'           => 'Cash',
        'check'          => 'Check',
        'bank_transfer'  => 'Bank Transfer',
        'other'          => 'Other',
    ];
    $methodLabel = $methodLabels[$data['payment_method'] ?? 'other'] ?? 'Other';

    $transactionRow = '';
    if ($transactionId !== '') {
        $transactionRow = "
            <tr>
                <td style='padding:8px 12px;font-weight:600;color:#555;border-bottom:1px solid #eee;'>Transaction ID</td>
                <td style='padding:8px 12px;color:#333;border-bottom:1px solid #eee;'>{$transactionId}</td>
            </tr>";
    }

    return "
    <div style='max-width:600px;margin:0 auto;font-family:Arial,sans-serif;color:#333;'>
        <!-- Header -->
        <div style='background:#1e3a5f;padding:24px 20px;text-align:center;border-radius:8px 8px 0 0;'>
            <h1 style='margin:0;color:#ffffff;font-size:22px;'>{$schoolNameSafe}</h1>
            <p style='margin:6px 0 0;color:#a0c4e8;font-size:14px;'>Payment Receipt</p>
        </div>

        <!-- Body -->
        <div style='background:#ffffff;padding:24px 20px;border:1px solid #e0e0e0;border-top:none;'>
            <p style='margin:0 0 16px;font-size:15px;'>Hello <strong>{$studentNameSafe}</strong>,</p>
            <p style='margin:0 0 20px;font-size:14px;color:#555;'>
                Thank you for your payment. Here is your receipt:
            </p>

            <table style='width:100%;border-collapse:collapse;font-size:14px;margin-bottom:20px;'>
                <tr>
                    <td style='padding:8px 12px;font-weight:600;color:#555;border-bottom:1px solid #eee;'>Date</td>
                    <td style='padding:8px 12px;color:#333;border-bottom:1px solid #eee;'>{$date}</td>
                </tr>
                <tr>
                    <td style='padding:8px 12px;font-weight:600;color:#555;border-bottom:1px solid #eee;'>Amount</td>
                    <td style='padding:8px 12px;color:#333;border-bottom:1px solid #eee;font-size:18px;font-weight:700;color:#1e3a5f;'>{$amount}</td>
                </tr>
                <tr>
                    <td style='padding:8px 12px;font-weight:600;color:#555;border-bottom:1px solid #eee;'>Type</td>
                    <td style='padding:8px 12px;color:#333;border-bottom:1px solid #eee;'>{$paymentType}</td>
                </tr>
                <tr>
                    <td style='padding:8px 12px;font-weight:600;color:#555;border-bottom:1px solid #eee;'>Payment Method</td>
                    <td style='padding:8px 12px;color:#333;border-bottom:1px solid #eee;'>{$methodLabel}</td>
                </tr>
                <tr>
                    <td style='padding:8px 12px;font-weight:600;color:#555;border-bottom:1px solid #eee;'>Receipt #</td>
                    <td style='padding:8px 12px;color:#333;border-bottom:1px solid #eee;font-family:monospace;'>{$receiptNum}</td>
                </tr>
                {$transactionRow}
                <tr>
                    <td style='padding:8px 12px;font-weight:600;color:#555;vertical-align:top;'>Description</td>
                    <td style='padding:8px 12px;color:#333;'>{$description}</td>
                </tr>
            </table>
        </div>

        <!-- Footer -->
        <div style='background:#f7f7f7;padding:16px 20px;text-align:center;font-size:12px;color:#888;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px;'>
            <p style='margin:0;'>This is an automated receipt from {$schoolNameSafe}.</p>
            <p style='margin:4px 0 0;'>Please keep this for your records.</p>
        </div>
    </div>";
}

/**
 * Build a simple notification email for new messages.
 */
function build_message_notification_html(string $schoolName, string $recipientName, string $customBody = ''): string
{
    $schoolNameSafe = htmlspecialchars($schoolName);
    $recipientSafe  = htmlspecialchars($recipientName);

    // Use custom body from template system, or fall back to hardcoded default
    if ($customBody !== '') {
        $innerHtml = "<div style='font-size:14px;color:#555;'>{$customBody}</div>";
    } else {
        $innerHtml = "
            <p style='margin:0 0 16px;font-size:15px;'>Hello <strong>{$recipientSafe}</strong>,</p>
            <p style='margin:0 0 16px;font-size:14px;color:#555;'>
                You have a new message from <strong>{$schoolNameSafe}</strong>.
            </p>
            <p style='margin:0 0 16px;font-size:14px;color:#555;'>
                Please log in to your portal to view and respond to your message.
            </p>";
    }

    return "
    <div style='max-width:600px;margin:0 auto;font-family:Arial,sans-serif;color:#333;'>
        <div style='background:#1e3a5f;padding:24px 20px;text-align:center;border-radius:8px 8px 0 0;'>
            <h1 style='margin:0;color:#ffffff;font-size:22px;'>{$schoolNameSafe}</h1>
        </div>
        <div style='background:#ffffff;padding:24px 20px;border:1px solid #e0e0e0;border-top:none;'>
            {$innerHtml}
            <div style='text-align:center;margin:24px 0;'>
                <span style='display:inline-block;background:#1e3a5f;color:#ffffff;padding:12px 28px;border-radius:6px;font-size:14px;font-weight:600;text-decoration:none;'>
                    Log in to view your message
                </span>
            </div>
        </div>
        <div style='background:#f7f7f7;padding:16px 20px;text-align:center;font-size:12px;color:#888;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px;'>
            <p style='margin:0;'>This is an automated notification from {$schoolNameSafe}.</p>
        </div>
    </div>";
}

/**
 * Build a plan change proposal notification email.
 */
function build_plan_change_notification_html(string $schoolName, string $studentName, string $newPlanName, ?string $notes, string $customBody = ''): string
{
    $schoolNameSafe = htmlspecialchars($schoolName);
    $studentSafe    = htmlspecialchars($studentName);
    $planSafe       = htmlspecialchars($newPlanName);

    // Use custom body from template system, or fall back to hardcoded default
    if ($customBody !== '') {
        $innerHtml = "<div style='font-size:14px;color:#555;'>{$customBody}</div>";
    } else {
        $notesHtml = '';
        if ($notes) {
            $notesSafe = htmlspecialchars($notes);
            $notesHtml = "
            <p style='margin:0 0 16px;font-size:14px;color:#555;'>
                <strong>Note from your instructor:</strong> {$notesSafe}
            </p>";
        }

        $innerHtml = "
            <p style='margin:0 0 16px;font-size:15px;'>Hello <strong>{$studentSafe}</strong>,</p>
            <p style='margin:0 0 16px;font-size:14px;color:#555;'>
                Your instructor has proposed a change to your membership plan.
            </p>
            <div style='background:#f0f7ff;border:1px solid #c8ddf5;border-radius:6px;padding:16px;margin:0 0 16px;'>
                <p style='margin:0;font-size:14px;color:#333;'>
                    <strong>Proposed new plan:</strong> {$planSafe}
                </p>
            </div>
            {$notesHtml}
            <p style='margin:0 0 16px;font-size:14px;color:#555;'>
                Please log in to your student portal to review and approve or decline this change.
                <strong>This proposal will expire in 7 days.</strong>
            </p>";
    }

    return "
    <div style='max-width:600px;margin:0 auto;font-family:Arial,sans-serif;color:#333;'>
        <div style='background:#1e3a5f;padding:24px 20px;text-align:center;border-radius:8px 8px 0 0;'>
            <h1 style='margin:0;color:#ffffff;font-size:22px;'>{$schoolNameSafe}</h1>
        </div>
        <div style='background:#ffffff;padding:24px 20px;border:1px solid #e0e0e0;border-top:none;'>
            {$innerHtml}
            <div style='text-align:center;margin:24px 0;'>
                <span style='display:inline-block;background:#1e3a5f;color:#ffffff;padding:12px 28px;border-radius:6px;font-size:14px;font-weight:600;'>
                    Log in to review
                </span>
            </div>
        </div>
        <div style='background:#f7f7f7;padding:16px 20px;text-align:center;font-size:12px;color:#888;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px;'>
            <p style='margin:0;'>This is an automated notification from {$schoolNameSafe}.</p>
        </div>
    </div>";
}

// ─── Main Notification Functions ────────────────────────────────────────────

/**
 * Send a payment receipt email to the student, their parent (if linked),
 * and the admin/school from-email.
 *
 * @param array $data Keys: student_id (int, required), parent_id (int|null),
 *                    amount (float), payment_type (string), description (string),
 *                    receipt_number (string), transaction_id (string|null),
 *                    payment_method (string)
 */
function send_payment_receipt_email(array $data): void
{
    try {
        if (!function_exists('is_email_configured') || !is_email_configured()) {
            return;
        }

        $studentId = (int) ($data['student_id'] ?? 0);
        if ($studentId <= 0) return;

        $schoolName = getSiteName();
        $adminEmail = getSetting('smtp_from_email', '');

        // Look up student + parent emails
        $emails = get_student_parent_emails($studentId);
        $studentName  = $emails['student_name'] ?: 'Student';
        $studentEmail = $emails['student_email'];
        $parentEmail  = $emails['parent_email'];

        // If a specific parent_id was passed, also look up that parent's email
        $parentId = $data['parent_id'] ?? null;
        if ($parentId && !$parentEmail) {
            try {
                $pdo = get_db();
                $pStmt = $pdo->prepare("SELECT email FROM students WHERE id = ? AND school_id = ?");
                $pStmt->execute([$parentId, current_school_id()]);
                $pRow = $pStmt->fetch();
                if ($pRow && !empty($pRow['email'])) {
                    $parentEmail = trim($pRow['email']);
                }
            } catch (\Throwable $e) {}
        }

        // Build the receipt HTML
        $subject = 'Payment Receipt — ' . $schoolName;
        $html = build_receipt_html($data, $studentName, $schoolName);

        // Send to student
        if (!empty($studentEmail)) {
            send_email($studentEmail, $subject, $html);
        }

        // Send to parent (if different from student)
        if (!empty($parentEmail) && $parentEmail !== $studentEmail) {
            send_email($parentEmail, $subject, $html);
        }

        // Send copy to admin/school
        if (!empty($adminEmail) && $adminEmail !== $studentEmail && $adminEmail !== $parentEmail) {
            send_email($adminEmail, $subject, $html);
        }
    } catch (\Throwable $e) {
        // Never let email sending break payment processing
        if (function_exists('app_log')) {
            app_log('error', 'Payment receipt email failed: ' . $e->getMessage(), ['category' => 'email']);
        }
    }
}

/**
 * Send a "you have a new message" notification email to the other participant
 * in a conversation. Only fires when the sender is an admin.
 */
function send_message_notification_email(int $schoolId, int $conversationId, string $senderType, int $senderId): void
{
    try {
        if (!function_exists('is_email_configured') || !is_email_configured()) {
            return;
        }

        // Only send notifications for admin → student messages
        if ($senderType !== 'admin') {
            return;
        }

        $pdo = get_db();
        $schoolName = getSiteName();
        $adminEmail = getSetting('smtp_from_email', '');

        // Find the OTHER participant
        $stmt = $pdo->prepare("
            SELECT participant_type, participant_id
            FROM conversation_participants
            WHERE conversation_id = ? AND school_id = ?
              AND NOT (participant_type = ? AND participant_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$conversationId, $schoolId, $senderType, $senderId]);
        $other = $stmt->fetch();

        if (!$other) return;

        $recipientEmail = '';
        $recipientName  = '';

        if ($other['participant_type'] === 'student') {
            $sStmt = $pdo->prepare("SELECT first_name, last_name, email FROM students WHERE id = ? AND school_id = ?");
            $sStmt->execute([$other['participant_id'], $schoolId]);
            $sRow = $sStmt->fetch();
            if ($sRow) {
                $recipientName  = trim($sRow['first_name'] . ' ' . $sRow['last_name']);
                $recipientEmail = trim($sRow['email'] ?? '');
            }
        } elseif ($other['participant_type'] === 'admin') {
            $uStmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
            $uStmt->execute([$other['participant_id']]);
            $uRow = $uStmt->fetch();
            if ($uRow) {
                $recipientName  = trim($uRow['full_name'] ?? '');
                $recipientEmail = trim($uRow['email'] ?? '');
            }
        }

        if (empty($recipientEmail)) return;

        // Check email consent for student recipients
        if ($other['participant_type'] === 'student' && !has_comm_consent((int) $other['participant_id'], 'email')) {
            return; // No email consent — skip notification
        }

        $tpl = get_notification_template('new_message', [
            '{student_name}' => htmlspecialchars($recipientName ?: 'there'),
            '{school_name}'  => htmlspecialchars($schoolName),
        ]);
        $subject = $tpl['subject'];
        $html = build_message_notification_html($schoolName, $recipientName ?: 'there', $tpl['body']);

        // Build unsubscribe context for the student
        $unsubCtx = ($other['participant_type'] === 'student')
            ? ['type' => 'student', 'id' => $other['participant_id']]
            : [];

        // Send to recipient
        send_email($recipientEmail, $subject, $html, $unsubCtx);

        // Also notify the student's parent (if recipient is a student)
        if ($other['participant_type'] === 'student') {
            $emails = get_student_parent_emails((int) $other['participant_id']);
            if (!empty($emails['parent_email']) && $emails['parent_email'] !== $recipientEmail) {
                send_email($emails['parent_email'], $subject, $html, $unsubCtx);
            }
        }

        // Send copy to admin from-email (no unsubscribe for admin copies)
        if (!empty($adminEmail) && $adminEmail !== $recipientEmail) {
            send_email($adminEmail, $subject, $html);
        }
    } catch (\Throwable $e) {
        if (function_exists('app_log')) {
            app_log('error', 'Message notification email failed: ' . $e->getMessage(), ['category' => 'email']);
        }
    }
}

/**
 * Send a "plan change proposed" notification email to the student (and parent).
 */
function send_plan_change_notification_email(int $studentId, string $newPlanName, ?string $notes = null): void
{
    try {
        if (!function_exists('is_email_configured') || !is_email_configured()) {
            return;
        }

        $schoolName = getSiteName();
        $adminEmail = getSetting('smtp_from_email', '');

        $emails = get_student_parent_emails($studentId);
        $studentName  = $emails['student_name'] ?: 'Student';
        $studentEmail = $emails['student_email'];
        $parentEmail  = $emails['parent_email'];

        // Check email consent before sending
        if (!has_comm_consent($studentId, 'email')) {
            return; // No email consent — skip notification
        }

        $notesText = $notes ? '<strong>Note from your instructor:</strong> ' . htmlspecialchars($notes) : '';
        $tpl = get_notification_template('plan_change_proposal', [
            '{student_name}' => htmlspecialchars($studentName),
            '{plan_name}'    => htmlspecialchars($newPlanName),
            '{notes}'        => $notesText,
            '{school_name}'  => htmlspecialchars($schoolName),
        ]);
        $subject = $tpl['subject'];
        $html = build_plan_change_notification_html($schoolName, $studentName, $newPlanName, $notes, $tpl['body']);
        $unsubCtx = ['type' => 'student', 'id' => $studentId];

        // Send to student
        if (!empty($studentEmail)) {
            send_email($studentEmail, $subject, $html, $unsubCtx);
        }

        // Send to parent
        if (!empty($parentEmail) && $parentEmail !== $studentEmail) {
            send_email($parentEmail, $subject, $html, $unsubCtx);
        }

        // Send copy to admin (no unsubscribe for admin copies)
        if (!empty($adminEmail) && $adminEmail !== $studentEmail && $adminEmail !== $parentEmail) {
            send_email($adminEmail, $subject, $html);
        }
    } catch (\Throwable $e) {
        if (function_exists('app_log')) {
            app_log('error', 'Plan change notification email failed: ' . $e->getMessage(), ['category' => 'email']);
        }
    }
}
