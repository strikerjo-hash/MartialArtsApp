<?php
/**
 * includes/payment_gateway.php — Unified Payment Gateway Abstraction
 *
 * Provides a single interface for charging cards via Stripe or Square.
 * Uses cURL to call the REST APIs directly — no Composer or SDK required.
 *
 * Stripe flow:
 *   1. When a card is saved → create a Stripe Customer + PaymentMethod, attach it
 *   2. When charging        → create a PaymentIntent with the stored customer + pm
 *
 * Square flow:
 *   1. When a card is saved → create a Square Customer + Card on file
 *   2. When charging        → create a Payment with the stored card_id
 *
 * Every function returns an array with at least:
 *   ['success' => bool, 'error' => ?string]
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

// ---------------------------------------------------------------------------
// Gateway detection
// ---------------------------------------------------------------------------

function get_active_gateway(): string
{
    $pdo = get_db();
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'active_payment_gateway' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && !empty($row['setting_value']) && $row['setting_value'] !== 'none') {
            return $row['setting_value'];
        }
    } catch (\PDOException $e) {}
    return 'none';
}

function is_gateway_ready(): bool
{
    $gw = get_active_gateway();
    if ($gw === 'none') return false;
    $pdo = get_db();
    try {
        if ($gw === 'stripe') {
            $sk = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'stripe_secret_key' LIMIT 1");
            $sk->execute();
            $row = $sk->fetch();
            return $row && !empty($row['setting_value']);
        }
        if ($gw === 'square') {
            $at = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'square_access_token' LIMIT 1");
            $at->execute();
            $row = $at->fetch();
            return $row && !empty($row['setting_value']);
        }
    } catch (\PDOException $e) {}
    return false;
}

/**
 * Get the Stripe secret key from settings.
 */
function get_stripe_secret_key(): string
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'stripe_secret_key' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    return $row['setting_value'] ?? '';
}

/**
 * Get the Square access token from settings.
 */
function get_square_access_token(): string
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'square_access_token' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    return $row['setting_value'] ?? '';
}

/**
 * Detect if Stripe is in sandbox/test mode based on the key prefix.
 */
function is_stripe_test_mode(): bool
{
    $key = get_stripe_secret_key();
    return str_starts_with($key, 'sk_test_');
}

/**
 * Detect if Square is in sandbox mode based on the token prefix.
 */
function is_square_sandbox(): bool
{
    $token = get_square_access_token();
    return str_contains($token, 'sandbox') || str_starts_with($token, 'EAAAl');
}

// ---------------------------------------------------------------------------
// Database migrations — ensure gateway columns exist
// ---------------------------------------------------------------------------

function ensure_gateway_columns(): void
{
    $pdo = get_db();

    // students.stripe_customer_id
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM students LIKE 'stripe_customer_id'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE students ADD COLUMN stripe_customer_id VARCHAR(255) DEFAULT NULL");
        }
    } catch (\PDOException $e) {}

    // students.square_customer_id
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM students LIKE 'square_customer_id'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE students ADD COLUMN square_customer_id VARCHAR(255) DEFAULT NULL");
        }
    } catch (\PDOException $e) {}

    // payment_methods.gateway_payment_method_id  (Stripe pm_xxx or Square card ID)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'gateway_payment_method_id'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE payment_methods ADD COLUMN gateway_payment_method_id VARCHAR(255) DEFAULT NULL AFTER encrypted_token");
        }
    } catch (\PDOException $e) {}
}

// ---------------------------------------------------------------------------
// Credit / Balance system migrations
// ---------------------------------------------------------------------------

function ensure_credit_tables(): void
{
    $pdo = get_db();

    // students.account_credit — running balance
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM students LIKE 'account_credit'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE students ADD COLUMN account_credit DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        }
    } catch (\PDOException $e) {}

    // credit_ledger — audit trail for all credit changes
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS credit_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL COMMENT 'Positive = credit added, Negative = credit used',
            balance_after DECIMAL(10,2) NOT NULL,
            description VARCHAR(255) NOT NULL,
            reference_type VARCHAR(50) DEFAULT NULL COMMENT 'downgrade, admin_adjustment, payment_offset, refund',
            reference_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_student (student_id),
            INDEX idx_created (created_at)
        )");
    } catch (\PDOException $e) {}

    // Fix payments.payment_method ENUM to include 'account_credit'
    try {
        $pdo->exec("ALTER TABLE payments MODIFY COLUMN payment_method ENUM('cash','credit_card','debit_card','bank_transfer','account_credit','other') NOT NULL DEFAULT 'other'");
    } catch (\PDOException $e) {}
}

// ---------------------------------------------------------------------------
// Pending plan changes table migration
// ---------------------------------------------------------------------------

function ensure_pending_changes_table(): void
{
    $pdo = get_db();

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pending_plan_changes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            new_plan_id INT NOT NULL,
            old_plan_id INT DEFAULT NULL,
            old_membership_id INT DEFAULT NULL,
            requested_by INT NOT NULL COMMENT 'admin user_id',
            status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
            proration_amount DECIMAL(10,2) DEFAULT 0,
            proration_credit DECIMAL(10,2) DEFAULT 0,
            proration_type VARCHAR(20) DEFAULT NULL,
            proration_data TEXT DEFAULT NULL COMMENT 'JSON blob of full proration details',
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            resolved_at DATETIME DEFAULT NULL,
            INDEX idx_student_status (student_id, status),
            INDEX idx_expires (expires_at, status)
        )");
    } catch (\PDOException $e) {}

    // students.last_plan_change — lockout tracking
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM students LIKE 'last_plan_change'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE students ADD COLUMN last_plan_change DATE DEFAULT NULL");
        }
    } catch (\PDOException $e) {}
}

function ensure_payment_status_column(): void
{
    $pdo = get_db();
    // Add status column to payments table so revenue queries can filter by completed/failed/refunded
    try {
        $pdo->exec("ALTER TABLE payments ADD COLUMN status ENUM('completed','failed','refunded') NOT NULL DEFAULT 'completed' AFTER payment_method");
    } catch (\PDOException $e) {}
}

// Run migrations on include
ensure_gateway_columns();
ensure_credit_tables();
ensure_pending_changes_table();
ensure_fee_tables();
ensure_payment_status_column();

// ---------------------------------------------------------------------------
// Fee & discount code migrations
// ---------------------------------------------------------------------------

function ensure_fee_tables(): void
{
    $pdo = get_db();

    // Registration fee per plan
    try { $pdo->exec("ALTER TABLE membership_plans ADD COLUMN registration_fee DECIMAL(10,2) NOT NULL DEFAULT 0"); } catch (\PDOException $e) {}

    // Discount codes
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS discount_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            description VARCHAR(255) DEFAULT NULL,
            discount_type ENUM('percentage','flat') NOT NULL DEFAULT 'percentage',
            discount_value DECIMAL(10,2) NOT NULL,
            applies_to ENUM('plan_price','registration_fee','both') NOT NULL DEFAULT 'both',
            plan_id INT DEFAULT NULL,
            event_id INT DEFAULT NULL,
            max_uses INT DEFAULT NULL,
            uses_count INT NOT NULL DEFAULT 0,
            valid_from DATE DEFAULT NULL,
            valid_until DATE DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_code (code),
            INDEX idx_active (is_active, valid_from, valid_until)
        )");
    } catch (\PDOException $e) {}

    // Discount code usage audit trail
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS discount_code_uses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            discount_code_id INT NOT NULL,
            student_id INT NOT NULL,
            applied_amount DECIMAL(10,2) NOT NULL,
            context VARCHAR(50) NOT NULL,
            reference_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_code_student (discount_code_id, student_id)
        )");
    } catch (\PDOException $e) {}
}

// ---------------------------------------------------------------------------
// cURL helper
// ---------------------------------------------------------------------------

/**
 * Make an HTTP request via cURL.
 *
 * @param string $method  GET, POST, PUT, DELETE
 * @param string $url     Full URL
 * @param array  $headers HTTP headers
 * @param mixed  $body    Array (will be JSON-encoded) or null
 * @return array ['status' => int, 'body' => array]
 */
function gateway_http(string $method, string $url, array $headers = [], $body = null): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($body !== null) {
        if (is_array($body)) {
            // Check if this is a Stripe-style form-encoded request or JSON
            $isFormEncoded = false;
            foreach ($headers as $h) {
                if (stripos($h, 'application/x-www-form-urlencoded') !== false) {
                    $isFormEncoded = true;
                    break;
                }
            }
            if ($isFormEncoded) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[GATEWAY cURL ERROR] $error");
        return ['status' => 0, 'body' => ['error' => ['message' => "cURL error: $error"]]];
    }

    return ['status' => $httpCode, 'body' => json_decode($response, true) ?: []];
}

// ---------------------------------------------------------------------------
// Stripe — Customer & PaymentMethod management via REST API
// ---------------------------------------------------------------------------

/**
 * Get or create a Stripe Customer for the given student.
 */
function stripe_get_or_create_customer(int $studentId): ?string
{
    $pdo = get_db();
    $secretKey = get_stripe_secret_key();

    // Check if student already has a Stripe customer ID
    $stmt = $pdo->prepare("SELECT stripe_customer_id, first_name, last_name, email FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if (!$student) return null;

    if (!empty($student['stripe_customer_id'])) {
        return $student['stripe_customer_id'];
    }

    // Create a new Stripe Customer
    $resp = gateway_http('POST', 'https://api.stripe.com/v1/customers', [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/x-www-form-urlencoded',
    ], [
        'name'  => trim($student['first_name'] . ' ' . $student['last_name']),
        'email' => $student['email'] ?? '',
        'metadata' => ['student_id' => $studentId],
    ]);

    if ($resp['status'] === 200 && !empty($resp['body']['id'])) {
        $customerId = $resp['body']['id'];
        $pdo->prepare("UPDATE students SET stripe_customer_id = ? WHERE id = ?")->execute([$customerId, $studentId]);
        return $customerId;
    }

    error_log("[STRIPE] Failed to create customer: " . json_encode($resp['body']));
    return null;
}

/**
 * Charge a student via Stripe PaymentIntent.
 */
function charge_via_stripe(string $gatewayPmId, float $amount, string $description, array $pm): array
{
    $secretKey = get_stripe_secret_key();
    $pdo = get_db();

    // Get customer ID
    $stmt = $pdo->prepare("SELECT stripe_customer_id FROM students WHERE id = ?");
    $stmt->execute([$pm['student_id']]);
    $student = $stmt->fetch();
    $customerId = $student['stripe_customer_id'] ?? '';

    if (!$customerId) {
        return ['success' => false, 'transaction_id' => null, 'error' => 'Student has no Stripe customer ID. Please re-add the card.'];
    }

    // Create a PaymentIntent
    $params = [
        'amount'               => (int) round($amount * 100),
        'currency'             => 'usd',
        'customer'             => $customerId,
        'payment_method'       => $gatewayPmId,
        'off_session'          => 'true',
        'confirm'              => 'true',
        'description'          => $description,
        'metadata[student_id]' => $pm['student_id'],
        'metadata[last_four]'  => $pm['last_four'],
    ];

    $resp = gateway_http('POST', 'https://api.stripe.com/v1/payment_intents', [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/x-www-form-urlencoded',
    ], $params);

    if ($resp['status'] === 200 && ($resp['body']['status'] ?? '') === 'succeeded') {
        return [
            'success'        => true,
            'transaction_id' => $resp['body']['id'],
            'error'          => null,
        ];
    }

    // Handle specific statuses
    $status = $resp['body']['status'] ?? 'unknown';
    $errMsg = $resp['body']['error']['message']
           ?? $resp['body']['last_payment_error']['message']
           ?? "Payment failed (status: $status)";

    error_log("[STRIPE] PaymentIntent failed: " . json_encode($resp['body']));

    return ['success' => false, 'transaction_id' => null, 'error' => $errMsg];
}

// ---------------------------------------------------------------------------
// Square — Customer & Card management via REST API
// ---------------------------------------------------------------------------

/**
 * Get or create a Square Customer for the given student.
 */
function square_get_or_create_customer(int $studentId): ?string
{
    $pdo = get_db();
    $accessToken = get_square_access_token();
    $baseUrl = is_square_sandbox() ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';

    $stmt = $pdo->prepare("SELECT square_customer_id, first_name, last_name, email FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if (!$student) return null;

    if (!empty($student['square_customer_id'])) {
        return $student['square_customer_id'];
    }

    $resp = gateway_http('POST', "$baseUrl/v2/customers", [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Square-Version: 2024-01-18',
    ], [
        'given_name'    => $student['first_name'] ?? '',
        'family_name'   => $student['last_name'] ?? '',
        'email_address' => $student['email'] ?? '',
        'reference_id'  => (string) $studentId,
        'idempotency_key' => 'cust_' . $studentId . '_' . time(),
    ]);

    if (($resp['status'] === 200 || $resp['status'] === 201) && !empty($resp['body']['customer']['id'])) {
        $customerId = $resp['body']['customer']['id'];
        $pdo->prepare("UPDATE students SET square_customer_id = ? WHERE id = ?")->execute([$customerId, $studentId]);
        return $customerId;
    }

    error_log("[SQUARE] Failed to create customer: " . json_encode($resp['body']));
    return null;
}

/**
 * Create a Square card on file for a customer.
 * Note: Square requires a payment nonce from their Web Payments SDK for production.
 * For sandbox, you can use sandbox test nonce values.
 *
 * Returns: ['success' => bool, 'card_id' => string|null, 'error' => string|null]
 */
function square_create_card(int $studentId, string $nonce): array
{
    $accessToken = get_square_access_token();
    $baseUrl = is_square_sandbox() ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
    $customerId = square_get_or_create_customer($studentId);

    if (!$customerId) {
        return ['success' => false, 'card_id' => null, 'error' => 'Failed to create Square customer.'];
    }

    $resp = gateway_http('POST', "$baseUrl/v2/cards", [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Square-Version: 2024-01-18',
    ], [
        'idempotency_key' => 'card_' . $studentId . '_' . time(),
        'source_id'       => $nonce,
        'card' => [
            'customer_id' => $customerId,
        ],
    ]);

    if (($resp['status'] === 200 || $resp['status'] === 201) && !empty($resp['body']['card']['id'])) {
        return ['success' => true, 'card_id' => $resp['body']['card']['id'], 'error' => null];
    }

    $errMsg = $resp['body']['errors'][0]['detail'] ?? 'Failed to save card with Square.';
    return ['success' => false, 'card_id' => null, 'error' => $errMsg];
}

/**
 * Charge via Square Payments API.
 */
function charge_via_square(string $gatewayCardId, float $amount, string $description, array $pm): array
{
    $accessToken = get_square_access_token();
    $baseUrl = is_square_sandbox() ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
    $pdo = get_db();

    // Get customer ID
    $stmt = $pdo->prepare("SELECT square_customer_id FROM students WHERE id = ?");
    $stmt->execute([$pm['student_id']]);
    $student = $stmt->fetch();
    $customerId = $student['square_customer_id'] ?? '';

    // Get location ID
    $locationId = '';
    try {
        $locStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'square_location_id' LIMIT 1");
        $locStmt->execute();
        $locRow = $locStmt->fetch();
        $locationId = $locRow['setting_value'] ?? '';
    } catch (\PDOException $e) {}

    if (!$locationId) {
        // Try to fetch the primary location from Square
        $locResp = gateway_http('GET', "$baseUrl/v2/locations", [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Square-Version: 2024-01-18',
        ]);
        if (!empty($locResp['body']['locations'][0]['id'])) {
            $locationId = $locResp['body']['locations'][0]['id'];
        }
    }

    $params = [
        'idempotency_key' => 'pay_' . $pm['student_id'] . '_' . time() . '_' . bin2hex(random_bytes(4)),
        'amount_money'    => [
            'amount'   => (int) round($amount * 100),
            'currency' => 'USD',
        ],
        'source_id'    => $gatewayCardId,
        'customer_id'  => $customerId ?: null,
        'location_id'  => $locationId ?: null,
        'note'         => $description,
        'autocomplete' => true,
    ];

    $resp = gateway_http('POST', "$baseUrl/v2/payments", [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Square-Version: 2024-01-18',
    ], $params);

    if (($resp['status'] === 200 || $resp['status'] === 201) && !empty($resp['body']['payment']['id'])) {
        $paymentStatus = $resp['body']['payment']['status'] ?? '';
        if ($paymentStatus === 'COMPLETED' || $paymentStatus === 'APPROVED') {
            return ['success' => true, 'transaction_id' => $resp['body']['payment']['id'], 'error' => null];
        }
        return ['success' => false, 'transaction_id' => null, 'error' => "Payment status: $paymentStatus"];
    }

    $errMsg = $resp['body']['errors'][0]['detail'] ?? 'Square payment failed.';
    error_log("[SQUARE] Payment failed: " . json_encode($resp['body']));
    return ['success' => false, 'transaction_id' => null, 'error' => $errMsg];
}

// ---------------------------------------------------------------------------
// Unified charge function
// ---------------------------------------------------------------------------

/**
 * Charge a student's default (or specified) payment method.
 * Automatically applies any available account credit BEFORE charging the card.
 *
 * @return array [
 *   'success'        => bool,
 *   'transaction_id' => string|null,
 *   'error'          => string|null,
 *   'credit_used'    => float,      // how much credit was applied
 *   'amount_charged' => float,      // how much was charged to card (0 if fully covered by credit)
 *   'total_amount'   => float,      // original total
 * ]
 */
function charge_student(int $studentId, float $amount, string $description, ?int $paymentMethodId = null): array
{
    $pdo = get_db();
    $gateway = get_active_gateway();
    $originalAmount = round($amount, 2);

    // --- Check and apply credit ---
    $creditBalance = get_student_credit($studentId);
    $creditUsed = 0;
    $amountToCharge = $originalAmount;

    if ($creditBalance > 0 && $originalAmount > 0) {
        $creditToApply = min($creditBalance, $originalAmount);
        $amountToCharge = round($originalAmount - $creditToApply, 2);

        // Deduct credit now
        $creditResult = use_student_credit(
            $studentId,
            $creditToApply,
            'Credit applied to: ' . $description,
            'payment_offset'
        );
        $creditUsed = $creditResult['used'];
        $amountToCharge = round($originalAmount - $creditUsed, 2);
    }

    // --- If fully covered by credit, no card charge needed ---
    if ($amountToCharge <= 0) {
        return [
            'success'        => true,
            'transaction_id' => 'credit_' . date('Ymd') . '_' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6)),
            'error'          => null,
            'credit_used'    => $creditUsed,
            'amount_charged' => 0,
            'total_amount'   => $originalAmount,
        ];
    }

    // --- Need to charge card for remainder ---
    if ($gateway === 'none' || !is_gateway_ready()) {
        // Refund the credit we just deducted since the card charge can't happen
        if ($creditUsed > 0) {
            add_student_credit($studentId, $creditUsed, 'Credit refunded — no gateway configured', 'refund');
        }
        return ['success' => false, 'transaction_id' => null, 'error' => 'No payment gateway configured.',
                'credit_used' => 0, 'amount_charged' => 0, 'total_amount' => $originalAmount];
    }

    // Fetch the payment method
    if ($paymentMethodId) {
        $pmStmt = $pdo->prepare("SELECT * FROM payment_methods WHERE id = ? AND student_id = ?");
        $pmStmt->execute([$paymentMethodId, $studentId]);
    } else {
        $pmStmt = $pdo->prepare("SELECT * FROM payment_methods WHERE student_id = ? AND is_default = 1 LIMIT 1");
        $pmStmt->execute([$studentId]);
    }
    $pm = $pmStmt->fetch();

    if (!$pm) {
        // Refund credit since we can't complete the charge
        if ($creditUsed > 0) {
            add_student_credit($studentId, $creditUsed, 'Credit refunded — no payment method', 'refund');
        }
        return ['success' => false, 'transaction_id' => null, 'error' => 'No payment method on file. Please add a card first.',
                'credit_used' => 0, 'amount_charged' => 0, 'total_amount' => $originalAmount];
    }

    // Use the gateway-specific payment method ID (Stripe pm_xxx or Square card ID)
    $gatewayPmId = $pm['gateway_payment_method_id'] ?? '';

    if (empty($gatewayPmId)) {
        if ($creditUsed > 0) {
            add_student_credit($studentId, $creditUsed, 'Credit refunded — legacy card', 'refund');
        }
        return ['success' => false, 'transaction_id' => null, 'error' => 'This card was saved before gateway integration. Please remove it and add the card again.',
                'credit_used' => 0, 'amount_charged' => 0, 'total_amount' => $originalAmount];
    }

    $chargeDesc = $description;
    if ($creditUsed > 0) {
        $chargeDesc .= ' (after $' . number_format($creditUsed, 2) . ' credit applied)';
    }

    if ($gateway === 'stripe') {
        $result = charge_via_stripe($gatewayPmId, $amountToCharge, $chargeDesc, $pm);
    } elseif ($gateway === 'square') {
        $result = charge_via_square($gatewayPmId, $amountToCharge, $chargeDesc, $pm);
    } else {
        if ($creditUsed > 0) {
            add_student_credit($studentId, $creditUsed, 'Credit refunded — unknown gateway', 'refund');
        }
        return ['success' => false, 'transaction_id' => null, 'error' => 'Unknown gateway: ' . $gateway,
                'credit_used' => 0, 'amount_charged' => 0, 'total_amount' => $originalAmount];
    }

    if (!$result['success']) {
        // Card charge failed — refund the credit we deducted
        if ($creditUsed > 0) {
            add_student_credit($studentId, $creditUsed, 'Credit refunded — card charge failed: ' . ($result['error'] ?? ''), 'refund');
        }
        return array_merge($result, ['credit_used' => 0, 'amount_charged' => 0, 'total_amount' => $originalAmount]);
    }

    // Success — return full details
    return [
        'success'        => true,
        'transaction_id' => $result['transaction_id'],
        'error'          => null,
        'credit_used'    => $creditUsed,
        'amount_charged' => $amountToCharge,
        'total_amount'   => $originalAmount,
    ];
}

// ---------------------------------------------------------------------------
// Unified card-saving function
// ---------------------------------------------------------------------------

/**
 * Save a card for a student through the active gateway.
 *
 * For Stripe: sends card details directly via API to create a PaymentMethod.
 * For Square: requires a nonce (from Square Web Payments SDK on the frontend).
 *
 * @param int    $studentId
 * @param string $cardNumber  Full card number (digits only) — for Stripe
 * @param int    $expMonth
 * @param int    $expYear
 * @param string $cardBrand   visa, mastercard, etc.
 * @param string $label       Nickname
 * @param string $cvc         Optional CVC
 * @param string $nonce       Square payment nonce (only for Square)
 *
 * @return array ['success' => bool, 'error' => string|null]
 */
function save_card_to_gateway(
    int $studentId,
    string $cardNumber,
    int $expMonth,
    int $expYear,
    string $cardBrand = '',
    string $label = '',
    string $cvc = '',
    string $nonce = ''
): array {
    $pdo     = get_db();
    $gateway = get_active_gateway();

    $lastFour = substr($cardNumber, -4);
    if ($label === '') {
        $label = ucfirst($cardBrand ?: 'Card') . ' ending ' . $lastFour;
    }

    $gatewayPmId = null;

    // ---- Stripe ----
    if ($gateway === 'stripe' && is_gateway_ready()) {
        $result = stripe_create_payment_method($studentId, $cardNumber, $expMonth, $expYear, $cvc);

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error']];
        }
        $gatewayPmId = $result['payment_method_id'];

    // ---- Square ----
    } elseif ($gateway === 'square' && is_gateway_ready()) {
        if (empty($nonce)) {
            return ['success' => false, 'error' => 'Square requires a payment nonce from the Web Payments SDK. Please use the card form.'];
        }
        $result = square_create_card($studentId, $nonce);
        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error']];
        }
        $gatewayPmId = $result['card_id'];
    }

    // Encrypt a reference token (the gateway ID itself or a placeholder)
    $tokenToEncrypt = $gatewayPmId ?: ('local_' . bin2hex(random_bytes(16)));
    $encryptedToken = encrypt_payment_data($tokenToEncrypt);

    // Check if first card → auto-default
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payment_methods WHERE student_id = ?");
    $countStmt->execute([$studentId]);
    $isDefault = ((int) $countStmt->fetchColumn() === 0) ? 1 : 0;

    $ins = $pdo->prepare(
        'INSERT INTO payment_methods (student_id, label, card_brand, last_four, exp_month, exp_year, encrypted_token, gateway_payment_method_id, is_default)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $studentId,
        $label,
        $cardBrand ?: null,
        $lastFour,
        $expMonth,
        $expYear,
        $encryptedToken,
        $gatewayPmId,
        $isDefault,
    ]);

    return ['success' => true, 'error' => null];
}

// ---------------------------------------------------------------------------
// Token-based card saving (Stripe.js / Square Web Payments SDK)
// ---------------------------------------------------------------------------

/**
 * Save a card from a Stripe PaymentMethod token (pm_xxx) created by Stripe.js.
 * This is the PROPER PCI-compliant flow:
 *   1. Stripe.js in the browser creates the PaymentMethod (card never touches server)
 *   2. Only the pm_xxx token is sent to our server
 *   3. We attach it to the Stripe Customer and store the reference
 *
 * @param int    $studentId
 * @param string $paymentMethodId  Stripe pm_xxx ID from Stripe.js
 * @param string $label            Optional nickname
 *
 * @return array ['success' => bool, 'error' => string|null]
 */
function save_card_from_token(int $studentId, string $paymentMethodId, string $label = ''): array
{
    $pdo     = get_db();
    $gateway = get_active_gateway();

    if ($gateway !== 'stripe' || !is_gateway_ready()) {
        return ['success' => false, 'error' => 'Stripe is not configured.'];
    }

    $secretKey  = get_stripe_secret_key();
    $customerId = stripe_get_or_create_customer($studentId);

    if (!$customerId) {
        return ['success' => false, 'error' => 'Failed to create Stripe customer.'];
    }

    // Attach the PaymentMethod to the customer
    $attachResp = gateway_http('POST', "https://api.stripe.com/v1/payment_methods/{$paymentMethodId}/attach", [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/x-www-form-urlencoded',
    ], [
        'customer' => $customerId,
    ]);

    if ($attachResp['status'] !== 200) {
        $errMsg = $attachResp['body']['error']['message'] ?? 'Failed to attach card to customer.';
        error_log("[STRIPE] Attach PM failed: " . json_encode($attachResp['body']));
        return ['success' => false, 'error' => $errMsg];
    }

    // Retrieve the PaymentMethod details to get card info
    $pmResp = gateway_http('GET', "https://api.stripe.com/v1/payment_methods/{$paymentMethodId}", [
        'Authorization: Bearer ' . $secretKey,
    ]);

    $card = $pmResp['body']['card'] ?? [];
    $lastFour  = $card['last4'] ?? '0000';
    $cardBrand = $card['brand'] ?? 'card';
    $expMonth  = $card['exp_month'] ?? 0;
    $expYear   = $card['exp_year'] ?? 0;

    if ($label === '') {
        $label = ucfirst($cardBrand) . ' ending ' . $lastFour;
    }

    // Encrypt the gateway PM ID for storage
    $encryptedToken = encrypt_payment_data($paymentMethodId);

    // Check if first card → auto-default
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payment_methods WHERE student_id = ?");
    $countStmt->execute([$studentId]);
    $isDefault = ((int) $countStmt->fetchColumn() === 0) ? 1 : 0;

    $ins = $pdo->prepare(
        'INSERT INTO payment_methods (student_id, label, card_brand, last_four, exp_month, exp_year, encrypted_token, gateway_payment_method_id, is_default)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $studentId,
        $label,
        $cardBrand,
        $lastFour,
        $expMonth,
        $expYear,
        $encryptedToken,
        $paymentMethodId,
        $isDefault,
    ]);

    return ['success' => true, 'error' => null];
}

/**
 * Save a card from a Stripe PaymentMethod token for a *legacy* parent.
 * Mirrors save_card_from_token but writes to the parent_payment_methods table.
 *
 * @param int    $parentId          The legacy parent ID (parents.id)
 * @param string $paymentMethodId   Stripe pm_xxx token from the frontend
 * @param string $label             Optional display label
 * @return array ['success' => bool, 'error' => ?string]
 */
function save_parent_card_from_token(int $parentId, string $paymentMethodId, string $label = ''): array
{
    $pdo     = get_db();
    $gateway = get_active_gateway();

    if ($gateway !== 'stripe' || !is_gateway_ready()) {
        return ['success' => false, 'error' => 'Stripe is not configured.'];
    }

    $secretKey = get_stripe_secret_key();

    // Legacy parents may not have a Stripe customer yet — create one using parent info
    // Try to find an existing Stripe customer for this parent, or create a new one
    $custId = null;
    try {
        $parentStmt = $pdo->prepare("SELECT first_name, last_name, email FROM parents WHERE id = ? LIMIT 1");
        $parentStmt->execute([$parentId]);
        $parentInfo = $parentStmt->fetch();

        if ($parentInfo && !empty($parentInfo['email'])) {
            // Search for existing customer by email
            $searchResp = gateway_http('GET', 'https://api.stripe.com/v1/customers?email=' . urlencode($parentInfo['email']) . '&limit=1', [
                'Authorization: Bearer ' . $secretKey,
            ]);
            if (!empty($searchResp['body']['data'][0]['id'])) {
                $custId = $searchResp['body']['data'][0]['id'];
            }
        }

        if (!$custId) {
            // Create a new customer
            $custData = ['description' => 'Parent #' . $parentId];
            if ($parentInfo) {
                $custData['name']  = trim(($parentInfo['first_name'] ?? '') . ' ' . ($parentInfo['last_name'] ?? ''));
                if (!empty($parentInfo['email'])) $custData['email'] = $parentInfo['email'];
            }
            $createResp = gateway_http('POST', 'https://api.stripe.com/v1/customers', [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ], $custData);

            $custId = $createResp['body']['id'] ?? null;
        }
    } catch (\Exception $e) {
        error_log("[STRIPE] Parent customer creation failed: " . $e->getMessage());
    }

    if (!$custId) {
        return ['success' => false, 'error' => 'Failed to create Stripe customer for parent.'];
    }

    // Attach the PaymentMethod to the customer
    $attachResp = gateway_http('POST', "https://api.stripe.com/v1/payment_methods/{$paymentMethodId}/attach", [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/x-www-form-urlencoded',
    ], [
        'customer' => $custId,
    ]);

    if ($attachResp['status'] !== 200) {
        $errMsg = $attachResp['body']['error']['message'] ?? 'Failed to attach card.';
        error_log("[STRIPE] Parent attach PM failed: " . json_encode($attachResp['body']));
        return ['success' => false, 'error' => $errMsg];
    }

    // Retrieve the PaymentMethod details
    $pmResp = gateway_http('GET', "https://api.stripe.com/v1/payment_methods/{$paymentMethodId}", [
        'Authorization: Bearer ' . $secretKey,
    ]);

    $card = $pmResp['body']['card'] ?? [];
    $lastFour  = $card['last4'] ?? '0000';
    $cardBrand = $card['brand'] ?? 'card';
    $expMonth  = $card['exp_month'] ?? 0;
    $expYear   = $card['exp_year'] ?? 0;

    if ($label === '') {
        $label = ucfirst($cardBrand) . ' ending ' . $lastFour;
    }

    $encryptedToken = encrypt_payment_data($paymentMethodId);

    // Auto-default if first card
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM parent_payment_methods WHERE parent_id = ?");
    $countStmt->execute([$parentId]);
    $isDefault = ((int) $countStmt->fetchColumn() === 0) ? 1 : 0;

    $ins = $pdo->prepare(
        'INSERT INTO parent_payment_methods (parent_id, label, card_brand, last_four, exp_month, exp_year, encrypted_token, gateway_payment_method_id, is_default)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $parentId,
        $label,
        $cardBrand,
        $lastFour,
        $expMonth,
        $expYear,
        $encryptedToken,
        $paymentMethodId,
        $isDefault,
    ]);

    return ['success' => true, 'error' => null];
}

// ---------------------------------------------------------------------------
// Credit / Balance helper functions
// ---------------------------------------------------------------------------

/**
 * Get a student's current credit balance.
 */
function get_student_credit(int $studentId): float
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT account_credit FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    return (float) ($row['account_credit'] ?? 0);
}

/**
 * Add credit to a student's account (e.g. downgrade refund, admin adjustment).
 * Records a ledger entry for audit trail.
 *
 * @return float The new balance after adding
 */
function add_student_credit(int $studentId, float $amount, string $description, string $refType = '', ?int $refId = null): float
{
    $pdo = get_db();

    $pdo->beginTransaction();
    try {
        // Lock the student row
        $stmt = $pdo->prepare("SELECT account_credit FROM students WHERE id = ? FOR UPDATE");
        $stmt->execute([$studentId]);
        $row = $stmt->fetch();
        $currentBalance = (float) ($row['account_credit'] ?? 0);
        $newBalance = round($currentBalance + $amount, 2);

        // Update running balance
        $pdo->prepare("UPDATE students SET account_credit = ? WHERE id = ?")
            ->execute([$newBalance, $studentId]);

        // Insert ledger entry
        $pdo->prepare(
            "INSERT INTO credit_ledger (student_id, amount, balance_after, description, reference_type, reference_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$studentId, $amount, $newBalance, $description, $refType ?: null, $refId]);

        $pdo->commit();
        return $newBalance;
    } catch (\Exception $e) {
        $pdo->rollBack();
        error_log("[CREDIT] Failed to add credit for student $studentId: " . $e->getMessage());
        return (float) ($row['account_credit'] ?? 0);
    }
}

/**
 * Use (deduct) credit from a student's account.
 * Will not go below zero — returns the actual amount deducted.
 *
 * @return array ['used' => float, 'new_balance' => float]
 */
function use_student_credit(int $studentId, float $amount, string $description, string $refType = '', ?int $refId = null): array
{
    $pdo = get_db();

    $pdo->beginTransaction();
    try {
        // Lock the student row
        $stmt = $pdo->prepare("SELECT account_credit FROM students WHERE id = ? FOR UPDATE");
        $stmt->execute([$studentId]);
        $row = $stmt->fetch();
        $currentBalance = (float) ($row['account_credit'] ?? 0);

        // Can only use up to the available balance
        $actualUse = min($amount, $currentBalance);
        if ($actualUse <= 0) {
            $pdo->commit();
            return ['used' => 0, 'new_balance' => $currentBalance];
        }

        $newBalance = round($currentBalance - $actualUse, 2);

        // Update running balance
        $pdo->prepare("UPDATE students SET account_credit = ? WHERE id = ?")
            ->execute([$newBalance, $studentId]);

        // Insert ledger entry (negative amount = credit used)
        $pdo->prepare(
            "INSERT INTO credit_ledger (student_id, amount, balance_after, description, reference_type, reference_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$studentId, -$actualUse, $newBalance, $description, $refType ?: null, $refId]);

        $pdo->commit();
        return ['used' => $actualUse, 'new_balance' => $newBalance];
    } catch (\Exception $e) {
        $pdo->rollBack();
        error_log("[CREDIT] Failed to use credit for student $studentId: " . $e->getMessage());
        return ['used' => 0, 'new_balance' => (float) ($row['account_credit'] ?? 0)];
    }
}

// ---------------------------------------------------------------------------
// Shared proration calculation
// ---------------------------------------------------------------------------

/**
 * Calculate the charge for enrolling mid-program in an afterschool plan.
 *
 * Proration method (month-based, partial month prorated by days):
 *   1. Count the number of whole calendar months between enrollment date and program_end_date.
 *   2. If the enrollment day is not the 1st of a month, prorate the first partial month
 *      by days remaining in that month divided by days in that month.
 *   3. Total = (full_months * monthly_rate) + partial_month_charge
 *
 * @param array       $plan             Full membership_plans row (is_afterschool, program_start_date, program_end_date, price, billing_frequency)
 * @param string|null $enrollment_date  Y-m-d string; defaults to today
 * @return array  Same shape as calculateProration() return value
 */
function calculateAfterschoolProration(array $plan, ?string $enrollment_date = null): array
{
    $today        = new \DateTime($enrollment_date ?? date('Y-m-d'));
    $programEnd   = new \DateTime($plan['program_end_date']);
    $programStart = new \DateTime($plan['program_start_date']);

    // If enrollment is before program start, treat as starting on program_start_date
    if ($today < $programStart) {
        $today = clone $programStart;
    }

    // Total calendar days in the full program
    $totalProgramDays   = max(1, $programStart->diff($programEnd)->days);
    $totalProgramMonths = max(1, round($totalProgramDays / 30.44, 4));

    $totalPrice  = (float) $plan['price'];
    $monthlyRate = $totalPrice / $totalProgramMonths;
    $billingFreq = $plan['billing_frequency'] ?? 'upfront';

    // --- Compute remaining charge from today to program_end_date ---
    $enrollmentDay     = (int) $today->format('j');
    $daysInEnrollMonth = (int) $today->format('t');
    $partialDays       = $daysInEnrollMonth - $enrollmentDay + 1;
    $isFullMonth       = ($enrollmentDay === 1);

    $nextMonthStart = (clone $today)->modify('first day of next month');

    // Count full + partial calendar months from next month through program end
    $fullMonthsRemaining = 0;
    $cursor = clone $nextMonthStart;
    while ($cursor < $programEnd) {
        $monthEnd = (clone $cursor)->modify('last day of this month');
        if ($monthEnd <= $programEnd) {
            $fullMonthsRemaining++;
        } else {
            // Partial final month
            $partialEndDays  = $cursor->diff($programEnd)->days + 1;
            $daysInThisMonth = (int) $cursor->format('t');
            $fullMonthsRemaining += ($partialEndDays / $daysInThisMonth);
        }
        $cursor->modify('first day of next month');
    }

    // Partial enrollment month charge
    $partialMonthCharge = $isFullMonth
        ? 0
        : round($monthlyRate * ($partialDays / $daysInEnrollMonth), 2);

    // Full months charge
    $fullMonthsCharge = round($monthlyRate * $fullMonthsRemaining, 2);

    // Total prorated charge
    $proratedAmount = round($partialMonthCharge + $fullMonthsCharge, 2);

    // For upfront: charge the full prorated total immediately
    // For monthly: first charge = partial month + one full month's rate
    if ($billingFreq === 'monthly') {
        if ($isFullMonth) {
            $firstCharge = round($monthlyRate, 2);
        } else {
            $firstCharge = round($partialMonthCharge + $monthlyRate, 2);
        }
    } else {
        $firstCharge = $proratedAmount;
    }

    $daysRemaining = max(0, $today->diff($programEnd)->days);

    return [
        'amount'               => $firstCharge,
        'type'                 => 'afterschool',
        'credit'               => 0,
        'unused_value'         => 0,
        'new_cost'             => $proratedAmount,
        'days_remaining'       => $daysRemaining,
        'is_monthly'           => ($billingFreq === 'monthly'),
        'is_afterschool'       => true,
        'months_remaining'     => $fullMonthsRemaining,
        'partial_days'         => $isFullMonth ? 0 : $partialDays,
        'monthly_rate'         => round($monthlyRate, 2),
        'total_program_months' => $totalProgramMonths,
        'program_start_date'   => $plan['program_start_date'],
        'program_end_date'     => $plan['program_end_date'],
    ];
}

/**
 * Calculate proration for a membership plan change.
 * Used by student_upgrade.php, student_detail.php (admin proposal), and student_portal.php (approve).
 *
 * @param array|null $current_membership  Current active membership row (with plan_price, start_date, end_date)
 * @param array      $new_plan            Target plan row (with price, duration_months, billing_frequency)
 * @return array     Proration details
 */
function calculateProration(?array $current_membership, array $new_plan): array
{
    // Delegate to afterschool proration for fixed-term programs
    if (!empty($new_plan['is_afterschool'])) {
        return calculateAfterschoolProration($new_plan);
    }

    if (!$current_membership) {
        // First-time enrollment — charge based on billing frequency
        $billingFreq = $new_plan['billing_frequency'] ?? 'upfront';
        $firstCharge = ($billingFreq === 'monthly' && $new_plan['duration_months'] > 1)
            ? round($new_plan['price'] / $new_plan['duration_months'], 2)
            : $new_plan['price'];

        return [
            'amount'         => $firstCharge,
            'type'           => 'full',
            'credit'         => 0,
            'unused_value'   => 0,
            'new_cost'       => $firstCharge,
            'days_remaining' => ($new_plan['duration_months'] * 30),
            'is_monthly'     => ($billingFreq === 'monthly'),
        ];
    }

    // Calculate days remaining in current membership
    $today = new \DateTime();
    $end_date = new \DateTime($current_membership['end_date']);
    $days_remaining = max(0, $today->diff($end_date)->days);

    $start_date = new \DateTime($current_membership['start_date']);
    $total_days = max(1, $start_date->diff($end_date)->days);

    // -----------------------------------------------------------------------
    // UNUSED VALUE — based on what the student has ACTUALLY PAID, not the
    // full plan price.  On a 12-month monthly plan where only 3 installments
    // have been collected the student has paid 3 × (price / 12), not the full
    // plan price, so the credit must reflect only what they really spent.
    // -----------------------------------------------------------------------
    $curBillingFreq = $current_membership['billing_frequency'] ?? 'upfront';
    $curDuration    = max(1, (int) ($current_membership['duration_months'] ?? 1));
    $curPlanPrice   = (float) ($current_membership['plan_price'] ?? 0);

    if ($curBillingFreq === 'monthly' && $curDuration > 1) {
        // Monthly plan: amount actually paid = installments collected so far
        $installmentsPaid = (int) ($current_membership['monthly_charges_made'] ?? 0);
        $monthlyRate      = $curPlanPrice / $curDuration;
        $amount_actually_paid = round($monthlyRate * $installmentsPaid, 2);
    } else {
        // Upfront plan: use amount_paid from the membership record if available,
        // otherwise fall back to the plan price.
        $amount_actually_paid = isset($current_membership['amount_paid'])
            ? (float) $current_membership['amount_paid']
            : $curPlanPrice;
    }

    // Unused portion = what they paid × fraction of membership remaining
    $unused_value = ($days_remaining / $total_days) * $amount_actually_paid;

    // -----------------------------------------------------------------------
    // NEW PLAN — determine the immediate cost and compare with unused credit
    // -----------------------------------------------------------------------
    $newBillingFreq = $new_plan['billing_frequency'] ?? 'upfront';
    $newDuration    = max(1, (int) $new_plan['duration_months']);
    $newPrice       = (float) $new_plan['price'];

    if ($newBillingFreq === 'monthly' && $newDuration > 1) {
        // Monthly new plan: the student only owes the first installment right now.
        // The unused credit from their current plan offsets this first payment.
        $first_installment = round($newPrice / $newDuration, 2);
        $immediate_cost    = $first_installment - $unused_value;

        // Full pro-rated value is still useful for display
        $daily_rate_new_full    = $newPrice / max(1, $newDuration * 30);
        $new_plan_prorated_value = $daily_rate_new_full * $days_remaining;

        if ($immediate_cost > 0) {
            // Student owes some money on the first installment
            return [
                'amount'              => round($immediate_cost, 2),
                'type'                => 'upgrade',
                'credit'              => 0,
                'unused_value'        => round($unused_value, 2),
                'amount_actually_paid'=> round($amount_actually_paid, 2),
                'new_cost'            => round($first_installment, 2),
                'days_remaining'      => $days_remaining,
                'is_monthly'          => true,
            ];
        } else {
            // Unused credit fully covers (or exceeds) the first installment.
            // Any leftover becomes account credit for future payments.
            return [
                'amount'              => 0,
                'type'                => 'downgrade',
                'credit'              => round(abs($immediate_cost), 2),
                'unused_value'        => round($unused_value, 2),
                'amount_actually_paid'=> round($amount_actually_paid, 2),
                'new_cost'            => round($first_installment, 2),
                'days_remaining'      => $days_remaining,
                'is_monthly'          => true,
            ];
        }
    } else {
        // Upfront new plan: compare full pro-rated values
        $daily_rate_new_full     = $newPrice / max(1, $newDuration * 30);
        $new_plan_prorated_value = $daily_rate_new_full * $days_remaining;
        $difference              = $new_plan_prorated_value - $unused_value;

        if ($difference > 0) {
            return [
                'amount'              => round($difference, 2),
                'type'                => 'upgrade',
                'credit'              => 0,
                'unused_value'        => round($unused_value, 2),
                'amount_actually_paid'=> round($amount_actually_paid, 2),
                'new_cost'            => round($new_plan_prorated_value, 2),
                'days_remaining'      => $days_remaining,
                'is_monthly'          => false,
            ];
        } else {
            return [
                'amount'              => 0,
                'type'                => 'downgrade',
                'credit'              => round(abs($difference), 2),
                'unused_value'        => round($unused_value, 2),
                'amount_actually_paid'=> round($amount_actually_paid, 2),
                'new_cost'            => round($new_plan_prorated_value, 2),
                'days_remaining'      => $days_remaining,
                'is_monthly'          => false,
            ];
        }
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function generateReceiptNumber(): string
{
    return 'REC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
}

function get_student_default_payment(int $studentId): ?array
{
    $pdo = get_db();
    try {
        $stmt = $pdo->prepare("SELECT card_brand, last_four, exp_month, exp_year FROM payment_methods WHERE student_id = ? AND is_default = 1 LIMIT 1");
        $stmt->execute([$studentId]);
        $pm = $stmt->fetch();
        if ($pm) {
            return [
                'last_four' => $pm['last_four'],
                'brand'     => $pm['card_brand'] ?? 'Card',
                'exp'       => ($pm['exp_month'] ? str_pad($pm['exp_month'], 2, '0', STR_PAD_LEFT) . '/' . $pm['exp_year'] : ''),
            ];
        }
    } catch (\PDOException $e) {}
    return null;
}

// ---------------------------------------------------------------------------
// Service fee helpers
// ---------------------------------------------------------------------------

/**
 * Get the configured service/processing fee percentage.
 * Returns 0 if not set or disabled.
 */
function getServiceFeePercentage(): float
{
    require_once __DIR__ . '/../config.php';
    return max(0, (float) getSetting('service_fee_percentage', '0'));
}

// ---------------------------------------------------------------------------
// Discount code functions
// ---------------------------------------------------------------------------

/**
 * Validate a discount code for a given plan or event.
 *
 * @return array ['valid' => bool, 'error' => string, 'discount' => ?array]
 */
function validateDiscountCode(string $code, ?int $planId = null, ?int $eventId = null): array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return ['valid' => false, 'error' => 'No discount code provided.', 'discount' => null];
    }

    $pdo = get_db();

    try {
        $stmt = $pdo->prepare("SELECT * FROM discount_codes WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $discount = $stmt->fetch();
    } catch (\PDOException $e) {
        return ['valid' => false, 'error' => 'Unable to validate code.', 'discount' => null];
    }

    if (!$discount) {
        return ['valid' => false, 'error' => 'Invalid discount code.', 'discount' => null];
    }

    if (!$discount['is_active']) {
        return ['valid' => false, 'error' => 'This discount code is no longer active.', 'discount' => null];
    }

    // Date range check
    $today = date('Y-m-d');
    if ($discount['valid_from'] && $today < $discount['valid_from']) {
        return ['valid' => false, 'error' => 'This discount code is not yet valid.', 'discount' => null];
    }
    if ($discount['valid_until'] && $today > $discount['valid_until']) {
        return ['valid' => false, 'error' => 'This discount code has expired.', 'discount' => null];
    }

    // Max uses check
    if ($discount['max_uses'] !== null && $discount['uses_count'] >= $discount['max_uses']) {
        return ['valid' => false, 'error' => 'This discount code has reached its maximum number of uses.', 'discount' => null];
    }

    // Plan/event scope check
    if ($discount['plan_id'] !== null && $planId !== null && (int) $discount['plan_id'] !== $planId) {
        return ['valid' => false, 'error' => 'This discount code is not valid for the selected plan.', 'discount' => null];
    }
    if ($discount['event_id'] !== null && $eventId !== null && (int) $discount['event_id'] !== $eventId) {
        return ['valid' => false, 'error' => 'This discount code is not valid for this event.', 'discount' => null];
    }

    // Code is plan-specific but used in event context (or vice versa)
    if ($discount['plan_id'] !== null && $planId === null && $eventId !== null) {
        return ['valid' => false, 'error' => 'This discount code is for membership plans only.', 'discount' => null];
    }
    if ($discount['event_id'] !== null && $eventId === null && $planId !== null) {
        return ['valid' => false, 'error' => 'This discount code is for events only.', 'discount' => null];
    }

    return ['valid' => true, 'error' => '', 'discount' => $discount];
}

/**
 * Calculate the discount amounts given a validated discount record.
 *
 * @return array ['plan_discount' => float, 'reg_fee_discount' => float, 'total_discount' => float]
 */
function calculateDiscountAmount(array $discount, float $planPrice, float $registrationFee): array
{
    $planDiscount = 0;
    $regFeeDiscount = 0;

    $type     = $discount['discount_type'];   // 'percentage' or 'flat'
    $value    = (float) $discount['discount_value'];
    $appliesTo = $discount['applies_to'];      // 'plan_price', 'registration_fee', or 'both'

    if ($type === 'percentage') {
        $pct = min($value, 100) / 100;
        if ($appliesTo === 'plan_price' || $appliesTo === 'both') {
            $planDiscount = round($planPrice * $pct, 2);
        }
        if ($appliesTo === 'registration_fee' || $appliesTo === 'both') {
            $regFeeDiscount = round($registrationFee * $pct, 2);
        }
    } else {
        // Flat amount — distribute based on applies_to
        if ($appliesTo === 'plan_price') {
            $planDiscount = min($value, $planPrice);
        } elseif ($appliesTo === 'registration_fee') {
            $regFeeDiscount = min($value, $registrationFee);
        } else {
            // 'both' — apply to registration fee first, then plan price
            $regFeeDiscount = min($value, $registrationFee);
            $remaining = $value - $regFeeDiscount;
            $planDiscount = min($remaining, $planPrice);
        }
    }

    return [
        'plan_discount'    => round($planDiscount, 2),
        'reg_fee_discount' => round($regFeeDiscount, 2),
        'total_discount'   => round($planDiscount + $regFeeDiscount, 2),
    ];
}

/**
 * Central fee calculation — computes all fees, discounts, and totals.
 *
 * @param array $params [
 *   'base_amount'      => float,   Plan charge (first installment or full price)
 *   'registration_fee' => float,   0 for upgrades/renewals
 *   'discount_code'    => ?string, Optional discount code
 *   'plan_id'          => ?int,    For discount validation scope
 *   'event_id'         => ?int,    For event discount scope
 * ]
 * @return array Full breakdown
 */
function calculateTotalWithFees(array $params): array
{
    $baseAmount      = round((float) ($params['base_amount'] ?? 0), 2);
    $registrationFee = round((float) ($params['registration_fee'] ?? 0), 2);
    $discountCode    = trim($params['discount_code'] ?? '');
    $planId          = $params['plan_id'] ?? null;
    $eventId         = $params['event_id'] ?? null;

    $discountAmount  = 0;
    $discountDetail  = null;
    $discountCodeId  = null;
    $discountError   = '';
    $discountRecord  = null;

    // Validate & calculate discount
    if ($discountCode !== '') {
        $validation = validateDiscountCode($discountCode, $planId, $eventId);
        if ($validation['valid']) {
            $discountRecord = $validation['discount'];
            $discountDetail = calculateDiscountAmount($discountRecord, $baseAmount, $registrationFee);
            $discountAmount = $discountDetail['total_discount'];
            $discountCodeId = (int) $discountRecord['id'];
        } else {
            $discountError = $validation['error'];
        }
    }

    $subtotal = round($baseAmount + $registrationFee - $discountAmount, 2);
    $subtotal = max(0, $subtotal);

    // Service fee
    $feePct    = getServiceFeePercentage();
    $serviceFee = ($feePct > 0) ? round($subtotal * ($feePct / 100), 2) : 0;

    $total = round($subtotal + $serviceFee, 2);

    return [
        'base_amount'            => $baseAmount,
        'registration_fee'       => $registrationFee,
        'discount_amount'        => $discountAmount,
        'discount_detail'        => $discountDetail,
        'discount_code'          => $discountCode,
        'discount_code_id'       => $discountCodeId,
        'discount_record'        => $discountRecord,
        'discount_error'         => $discountError,
        'subtotal'               => $subtotal,
        'service_fee'            => $serviceFee,
        'service_fee_percentage' => $feePct,
        'total'                  => $total,
    ];
}

/**
 * Record that a discount code was used.
 */
function recordDiscountCodeUse(int $discountCodeId, int $studentId, float $appliedAmount, string $context, ?int $referenceId = null): void
{
    $pdo = get_db();
    try {
        $stmt = $pdo->prepare("INSERT INTO discount_code_uses (discount_code_id, student_id, applied_amount, context, reference_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$discountCodeId, $studentId, $appliedAmount, $context, $referenceId]);

        $pdo->prepare("UPDATE discount_codes SET uses_count = uses_count + 1 WHERE id = ?")->execute([$discountCodeId]);
    } catch (\PDOException $e) {
        error_log("[FEE] Failed to record discount use: " . $e->getMessage());
    }
}

/**
 * Render an HTML fee breakdown.
 *
 * @param array $breakdown  Output from calculateTotalWithFees()
 * @param bool  $isTailwind Whether to use Tailwind classes (true) or CSS-var classes (false)
 * @param string $baseLabel  Label for the base amount (e.g., "Plan Price", "First Installment", "Event Fee")
 * @return string HTML
 */
function renderFeeBreakdownHtml(array $breakdown, bool $isTailwind = true, string $baseLabel = 'Plan Price'): string
{
    require_once __DIR__ . '/../config.php';

    $tw = $isTailwind;

    // Wrapper styles
    $wrapClass = $tw ? 'space-y-2 text-sm' : 'fee-breakdown';
    $rowClass  = $tw ? 'flex justify-between' : 'payment-row';
    $labelClass = $tw ? 'text-gray-600' : '';
    $valueClass = $tw ? 'font-semibold' : '';
    $greenClass = $tw ? 'text-green-600 font-semibold' : 'text-green';
    $totalRowClass = $tw ? 'flex justify-between pt-2 border-t border-blue-200' : 'payment-row fee-total-row';
    $totalLabelClass = $tw ? 'text-lg font-semibold text-gray-800' : 'fee-total-label';
    $totalValueClass = $tw ? 'text-xl font-bold text-green-600' : 'fee-total-value';

    $html = '<div class="' . $wrapClass . '">';

    // Base amount
    $html .= '<div class="' . $rowClass . '">';
    $html .= '<span class="' . $labelClass . '">' . htmlspecialchars($baseLabel) . ':</span>';
    $html .= '<span class="' . $valueClass . '">' . formatMoney($breakdown['base_amount']) . '</span>';
    $html .= '</div>';

    // Registration fee
    if ($breakdown['registration_fee'] > 0) {
        $html .= '<div class="' . $rowClass . '">';
        $html .= '<span class="' . $labelClass . '">Registration Fee:</span>';
        $html .= '<span class="' . $valueClass . '">' . formatMoney($breakdown['registration_fee']) . '</span>';
        $html .= '</div>';
    }

    // Discount
    if ($breakdown['discount_amount'] > 0) {
        $codeDisplay = $breakdown['discount_code'] ? ' (' . htmlspecialchars($breakdown['discount_code']) . ')' : '';
        $discountLabel = 'Discount' . $codeDisplay . ':';
        $html .= '<div class="' . $rowClass . '">';
        $html .= '<span class="' . $greenClass . '">' . $discountLabel . '</span>';
        $html .= '<span class="' . $greenClass . '">-' . formatMoney($breakdown['discount_amount']) . '</span>';
        $html .= '</div>';
    }

    // Service fee
    if ($breakdown['service_fee'] > 0) {
        $feeLabel = 'Service Fee (' . number_format($breakdown['service_fee_percentage'], 2) . '%):';
        $html .= '<div class="' . $rowClass . '">';
        $html .= '<span class="' . $labelClass . '">' . $feeLabel . '</span>';
        $html .= '<span class="' . $valueClass . '">' . formatMoney($breakdown['service_fee']) . '</span>';
        $html .= '</div>';
    }

    // Total
    $html .= '<div class="' . $totalRowClass . '">';
    $html .= '<span class="' . $totalLabelClass . '">Total Due:</span>';
    $html .= '<span class="' . $totalValueClass . '">' . formatMoney($breakdown['total']) . '</span>';
    $html .= '</div>';

    $html .= '</div>';

    return $html;
}
