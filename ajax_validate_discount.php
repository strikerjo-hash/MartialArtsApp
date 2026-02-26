<?php
/**
 * ajax_validate_discount.php — AJAX endpoint for real-time discount code validation
 *
 * Accepts POST with:
 *   code             — the discount code string
 *   plan_id          — (optional) plan ID for scope check
 *   event_id         — (optional) event ID for scope check
 *   base_amount      — plan charge / event fee
 *   registration_fee — registration fee (0 for events/upgrades)
 *
 * Returns JSON response.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/payment_gateway.php';

// Must be a student (logged-in or mid-registration)
$studentOk = false;
if (isset($_SESSION['student_id']) || isset($_SESSION['registration_student_id'])) {
    $studentOk = true;
}
// Also allow admin sessions
if (isset($_SESSION['user_id'])) {
    $studentOk = true;
}

if (!$studentOk) {
    echo json_encode(['valid' => false, 'error' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['valid' => false, 'error' => 'Invalid request method.']);
    exit;
}

// CSRF check — accepts token from POST body or X-CSRF-TOKEN header
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
$expected = $_SESSION['csrf_token'] ?? '';
if (!hash_equals($expected, $csrfToken)) {
    http_response_code(403);
    echo json_encode(['valid' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$code           = trim($_POST['code'] ?? '');
$planId         = !empty($_POST['plan_id']) ? (int) $_POST['plan_id'] : null;
$eventId        = !empty($_POST['event_id']) ? (int) $_POST['event_id'] : null;
$baseAmount     = (float) ($_POST['base_amount'] ?? 0);
$registrationFee = (float) ($_POST['registration_fee'] ?? 0);

if ($code === '') {
    echo json_encode(['valid' => false, 'error' => 'Please enter a discount code.']);
    exit;
}

// Validate the code
$validation = validateDiscountCode($code, $planId, $eventId);

if (!$validation['valid']) {
    echo json_encode(['valid' => false, 'error' => $validation['error']]);
    exit;
}

$discount = $validation['discount'];

// Calculate amounts
$discountAmounts = calculateDiscountAmount($discount, $baseAmount, $registrationFee);

// Build the full breakdown for display
$breakdown = calculateTotalWithFees([
    'base_amount'      => $baseAmount,
    'registration_fee' => $registrationFee,
    'discount_code'    => $code,
    'plan_id'          => $planId,
    'event_id'         => $eventId,
]);

// Human-readable message
if ($discount['discount_type'] === 'percentage') {
    $msg = number_format($discount['discount_value'], 0) . '% discount applied!';
} else {
    $msg = formatMoney($discount['discount_value']) . ' discount applied!';
}

echo json_encode([
    'valid'            => true,
    'discount_type'    => $discount['discount_type'],
    'discount_value'   => (float) $discount['discount_value'],
    'applies_to'       => $discount['applies_to'],
    'plan_discount'    => $discountAmounts['plan_discount'],
    'reg_fee_discount' => $discountAmounts['reg_fee_discount'],
    'total_discount'   => $discountAmounts['total_discount'],
    'subtotal'         => $breakdown['subtotal'],
    'service_fee'      => $breakdown['service_fee'],
    'total'            => $breakdown['total'],
    'message'          => $msg,
]);
