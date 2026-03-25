<?php
/**
 * ajax_validate_discount.php — AJAX endpoint for real-time discount code validation
 *
 * Accepts POST with:
 *   code             — discount code string (single code to validate)
 *   existing_codes   — (optional) comma-separated already-applied codes
 *   plan_id          — (optional) plan ID for scope check
 *   event_id         — (optional) event ID for scope check
 *   base_amount      — plan charge / event fee
 *   registration_fee — registration fee (0 for events/upgrades)
 *
 * Returns JSON response. When existing_codes is provided, the breakdown
 * reflects all codes combined.
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
// Note: this is a read-only validation endpoint (no state changes),
// so CSRF is best-effort. If token is provided, we verify it.
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
$expected = $_SESSION['csrf_token'] ?? '';
if ($csrfToken !== '' && $expected !== '' && !hash_equals($expected, $csrfToken)) {
    http_response_code(403);
    echo json_encode(['valid' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$code            = trim($_POST['code'] ?? '');
$existingCodes   = trim($_POST['existing_codes'] ?? '');
$planId          = !empty($_POST['plan_id']) ? (int) $_POST['plan_id'] : null;
$eventId         = !empty($_POST['event_id']) ? (int) $_POST['event_id'] : null;
$baseAmount      = (float) ($_POST['base_amount'] ?? 0);
$registrationFee = (float) ($_POST['registration_fee'] ?? 0);

if ($code === '') {
    echo json_encode(['valid' => false, 'error' => 'Please enter a discount code.']);
    exit;
}

// Validate the new code individually first
$validation = validateDiscountCode($code, $planId, $eventId);

if (!$validation['valid']) {
    echo json_encode(['valid' => false, 'error' => $validation['error']]);
    exit;
}

$discount = $validation['discount'];

// Build combined codes string (existing + new)
$allCodes = $existingCodes !== '' ? $existingCodes . ',' . $code : $code;

// Check for duplicate
$existingArr = array_filter(array_map('trim', explode(',', strtoupper($existingCodes))));
if (in_array(strtoupper($code), $existingArr, true)) {
    echo json_encode(['valid' => false, 'error' => 'This code is already applied.']);
    exit;
}

// Build the full breakdown with all codes combined
$breakdown = calculateTotalWithFees([
    'base_amount'      => $baseAmount,
    'registration_fee' => $registrationFee,
    'discount_code'    => $allCodes,
    'plan_id'          => $planId,
    'event_id'         => $eventId,
]);

// Check if the new code was actually accepted (not rejected due to scope conflict)
if ($breakdown['discount_error'] && stripos($breakdown['discount_error'], strtoupper($code)) !== false) {
    echo json_encode(['valid' => false, 'error' => $breakdown['discount_error']]);
    exit;
}

// Human-readable message for the new code
if ($discount['discount_type'] === 'percentage') {
    $msg = number_format($discount['discount_value'], 0) . '% discount applied!';
} else {
    $msg = formatMoney($discount['discount_value']) . ' discount applied!';
}

// Per-code discount details for display
$perCodeDetails = [];
foreach ($breakdown['discount_details_all'] ?? [] as $dd) {
    $perCodeDetails[] = [
        'code'           => $dd['code'],
        'total_discount' => $dd['total_discount'],
    ];
}

echo json_encode([
    'valid'            => true,
    'discount_type'    => $discount['discount_type'],
    'discount_value'   => (float) $discount['discount_value'],
    'applies_to'       => $discount['applies_to'],
    'plan_discount'    => $breakdown['discount_detail']['plan_discount'] ?? 0,
    'reg_fee_discount' => $breakdown['discount_detail']['reg_fee_discount'] ?? 0,
    'total_discount'   => $breakdown['discount_amount'],
    'subtotal'         => $breakdown['subtotal'],
    'service_fee'      => $breakdown['service_fee'],
    'total'            => $breakdown['total'],
    'message'          => $msg,
    'all_codes'        => implode(',', $breakdown['discount_codes'] ?? []),
    'per_code_details' => $perCodeDetails,
]);
