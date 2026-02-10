<?php
/**
 * cron.php — Membership Renewal Processing
 *
 * Processes auto-renewing memberships that have reached their end date.
 * Can be triggered two ways:
 *   1. Via cron job:   php /path/to/cron.php
 *   2. Via browser:    Admin clicks "Process Renewals" on memberships page
 *
 * Logic for each expired/expiring membership:
 *   - If auto_renew is ON and a payment gateway is configured => attempt charge, renew on success
 *   - If auto_renew is ON but no gateway => expire the membership, log as "no_gateway"
 *   - If auto_renew is OFF => expire the membership
 */

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    require_once 'config.php';
    requireLogin();

    // Only admins can trigger from browser
    if (getCurrentUser()['role'] !== 'admin') {
        header('Location: memberships.php');
        exit;
    }
}

// --- Database connection ---
if ($is_cli) {
    // When running from CLI, set up our own PDO
    require_once __DIR__ . '/config.php';
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false,
            ]
        );
    } catch (PDOException $e) {
        echo "Database connection failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// --- Check for payment gateway ---
// Look in settings or studio_config for a payment gateway key
$gateway_configured = false;
try {
    $gw = $pdo->query("SELECT config_value FROM studio_config WHERE config_key = 'payment_gateway_key' LIMIT 1")->fetch();
    if ($gw && !empty($gw['config_value'])) {
        $gateway_configured = true;
    }
} catch (PDOException $e) {
    // studio_config may not have that key; try settings table
    try {
        $gw = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'payment_gateway_key' LIMIT 1")->fetch();
        if ($gw && !empty($gw['setting_value'])) {
            $gateway_configured = true;
        }
    } catch (PDOException $e2) {
        // No gateway configured
    }
}

// --- Find memberships due for renewal ---
$due = $pdo->query("
    SELECT m.*, mp.name AS plan_name, mp.duration_months, mp.price,
           s.first_name, s.last_name, s.email
    FROM memberships m
    JOIN membership_plans mp ON m.plan_id = mp.id
    JOIN students s ON m.student_id = s.id
    WHERE m.status = 'active'
      AND m.end_date <= CURDATE()
    ORDER BY m.end_date ASC
")->fetchAll();

$results = [
    'renewed'        => 0,
    'expired'        => 0,
    'payment_failed' => 0,
    'no_gateway'     => 0,
    'total_processed' => count($due),
];

$logStmt = $pdo->prepare("
    INSERT INTO renewal_log (membership_id, student_id, action, old_end_date, new_end_date, amount, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$updateMembership = $pdo->prepare("UPDATE memberships SET end_date = ?, status = ? WHERE id = ?");

foreach ($due as $m) {
    $old_end = $m['end_date'];

    if (!$m['auto_renew']) {
        // Auto-renew is OFF — expire
        $updateMembership->execute([$old_end, 'expired', $m['id']]);
        $logStmt->execute([
            $m['id'], $m['student_id'], 'expired',
            $old_end, null, null,
            'Auto-renew was disabled. Membership expired.'
        ]);
        $results['expired']++;
        continue;
    }

    // Auto-renew is ON
    if ($gateway_configured) {
        // Attempt payment via gateway
        $payment_success = attempt_gateway_payment($m);

        if ($payment_success) {
            // Renew: extend from end_date by duration_months
            $new_end = date('Y-m-d', strtotime($old_end . ' + ' . $m['duration_months'] . ' months'));
            $updateMembership->execute([$new_end, 'active', $m['id']]);

            // Record the payment
            try {
                $receiptNum = 'REN-' . strtoupper(substr(md5(uniqid()), 0, 8));
                $pdo->prepare("
                    INSERT INTO payments (student_id, payment_type, reference_id, amount, payment_method, payment_date, receipt_number, notes)
                    VALUES (?, 'membership', ?, ?, 'gateway_auto', CURDATE(), ?, ?)
                ")->execute([
                    $m['student_id'], $m['id'], $m['price'], $receiptNum,
                    'Auto-renewal payment for ' . $m['plan_name']
                ]);
            } catch (PDOException $e) {
                // Payment record failed but membership was renewed
            }

            $logStmt->execute([
                $m['id'], $m['student_id'], 'renewed',
                $old_end, $new_end, $m['price'],
                'Auto-renewed via payment gateway.'
            ]);
            $results['renewed']++;
        } else {
            // Payment failed — expire
            $updateMembership->execute([$old_end, 'expired', $m['id']]);
            $logStmt->execute([
                $m['id'], $m['student_id'], 'payment_failed',
                $old_end, null, $m['price'],
                'Gateway payment failed. Membership expired.'
            ]);
            $results['payment_failed']++;
        }
    } else {
        // No gateway configured — expire with specific note
        $updateMembership->execute([$old_end, 'expired', $m['id']]);
        $logStmt->execute([
            $m['id'], $m['student_id'], 'no_gateway',
            $old_end, null, $m['price'],
            'No payment gateway configured. Membership expired for manual renewal.'
        ]);
        $results['no_gateway']++;
    }
}

/**
 * Attempt to charge the student via the payment gateway.
 *
 * This is a placeholder — replace with your actual Stripe / Square / etc. integration.
 * Return true if payment succeeded, false otherwise.
 */
function attempt_gateway_payment(array $membership): bool
{
    // TODO: Integrate with your payment provider here.
    // Example Stripe pseudo-code:
    //   $charge = \Stripe\Charge::create([
    //       'amount'   => $membership['price'] * 100,
    //       'currency' => 'usd',
    //       'customer' => $membership['stripe_customer_id'],
    //   ]);
    //   return $charge->status === 'succeeded';

    // For now, return false so memberships expire gracefully
    // until a real gateway is hooked up.
    return false;
}

// --- Output results ---
$summary = "Renewal processing complete. "
    . "{$results['total_processed']} membership(s) processed: "
    . "{$results['renewed']} renewed, "
    . "{$results['expired']} expired, "
    . "{$results['payment_failed']} payment failed, "
    . "{$results['no_gateway']} no gateway.";

if ($is_cli) {
    echo $summary . "\n";
    echo "Done.\n";
    exit(0);
} else {
    // Browser: flash message and redirect back
    $_SESSION['flash_message'] = $summary;
    header('Location: memberships.php');
    exit;
}
