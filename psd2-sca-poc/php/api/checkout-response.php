<?php
// api/checkout-response.php
// Receives the wallet's OpenID4VP direct_post for checkout.php. Verifies
// the presented LusoPay Card, then calls Cyclos's adduidpayment with the
// card's lusopay_id (the buyer, paying) and the checkout session's
// merchant_public_id (the receiver, set by whoever integrated
// checkout.php) to actually move the money for this session's amount.

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$session = $_GET['session'] ?? '';
if ($session === '' || !ctype_alnum(str_replace(['-', '_'], '', $session))) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'missing or invalid session']);
    exit;
}

$checkoutSession = get_checkout_session($session);
if ($checkoutSession === null) {
    http_response_code(404);
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'unknown checkout session']);
    exit;
}

$body = $_POST;
if (empty($body)) {
    parse_str(file_get_contents('php://input'), $body);
}

$vpTokenRaw = $body['vp_token'] ?? null;
$presentation = null;
if ($vpTokenRaw) {
    $vpToken = json_decode($vpTokenRaw, true);
    $presentation = is_string($vpToken)
        ? $vpToken
        : ($vpToken['lusopay_card'][0] ?? $vpToken['lusopay_card'] ?? null);
}

if (!is_string($presentation)) {
    update_checkout_session($session, ['status' => 'REJECTED', 'errors' => ['nenhum LusoPay Card apresentado']]);
    echo json_encode([]);
    exit;
}

$parsed = parse_sd_jwt_vc_presentation($presentation);
$signatureValid = verify_es256_jwt($parsed['issuer_jwt'], get_signing_key()['jwk']);
$lusopayId = $parsed['claims']['lusopay_id'] ?? null;

if (!$signatureValid || !$lusopayId) {
    update_checkout_session($session, ['status' => 'REJECTED', 'errors' => ['assinatura do cartão inválida ou lusopay_id em falta']]);
    echo json_encode([]);
    exit;
}

$checkoutParams = $checkoutSession['params'];
$cyclosDebug = null;
$authorized = execute_wallet_payment(
    (string) $lusopayId,
    $checkoutParams['merchant_public_id'],
    $checkoutParams['amount'],
    $checkoutParams['currency'],
    $checkoutParams['description'],
    $cyclosDebug
);

if ($authorized) {
    update_checkout_session($session, [
        'status' => 'AUTHORIZED',
        'transaction_id' => 'txn_' . bin2hex(random_bytes(16)),
        'lusopay_id' => $lusopayId,
        'name' => $parsed['claims']['name'] ?? null,
    ]);
} else {
    // "debug" is not returned by api/checkout-status.php (it only
    // returns status/transaction_id/errors) -- it's only visible via
    // debug-checkout.php, so the raw adduidpayment response is
    // inspectable without needing access to this server's PHP error log.
    update_checkout_session($session, [
        'status' => 'REJECTED',
        'errors' => ['pagamento rejeitado pelo Cyclos'],
        'debug' => $cyclosDebug,
    ]);
}

echo json_encode([]);
