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

// Proof that this specific presentation -- not a captured/replayed one
// -- came from the device that actually holds the card, right now, for
// this specific checkout session. See verify_holder_key_binding() for
// what each check means.
$holderJwk = $parsed['payload']['cnf']['jwk'] ?? null;
$expectedNonce = $checkoutSession['params']['nonce'] ?? null;
// Must match "client_id" exactly, prefix included -- for the
// "redirect_uri:" client_id scheme (unauthenticated requests, see
// checkout.php), the wallet is told the verifier's identity is that
// whole string, so that's what it binds "aud" to, not the bare URL.
$thisUrl = (($_SERVER['HTTPS'] ?? '') !== '' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$expectedAud = 'redirect_uri:' . $thisUrl;
// The exact base64url string sent as this session's "transaction_data"
// entry in checkout.php -- stored verbatim so the hash can be
// recomputed over the identical bytes, with no re-serialization risk.
$expectedTransactionData = $checkoutSession['params']['transaction_data'] ?? null;
$keyBinding = verify_holder_key_binding($parsed['kb_jwt'], $holderJwk, $expectedNonce, $expectedAud, $expectedTransactionData);

if (!$keyBinding['valid']) {
    update_checkout_session($session, [
        'status' => 'REJECTED',
        'errors' => ['prova de posse da carteira inválida: ' . $keyBinding['reason']],
        // Not returned by api/checkout-status.php -- only visible via
        // debug-checkout.php. Shows exactly what we expected vs what the
        // wallet actually sent, so a mismatch (e.g. a different aud
        // format) can be diagnosed without guessing.
        'debug' => [
            'expected' => $keyBinding['expected'] ?? null,
            'received' => $keyBinding['received'] ?? null,
        ],
    ]);
    echo json_encode([]);
    exit;
}

$checkoutParams = $checkoutSession['params'];
// Cyclos requires orderReference to be non-empty -- WooCommerce's real
// order id usually isn't known yet at this point (see checkout_id
// comments in lusopay-wallet-blocks.js: the Blocks flow asks the wallet
// for payment *before* the order exists), so fall back to this
// session's own id, which is always present and unique per attempt.
// Once the WooCommerce order is created, it's tagged with this same
// session id as order meta (see process_payment() in
// class-wc-gateway-lusopay-wallet.php) -- match the two up by that.
$orderReference = ($checkoutParams['order_id'] ?? '') !== '' ? $checkoutParams['order_id'] : $session;
$cyclosDebug = null;
$authorized = execute_wallet_payment(
    (string) $lusopayId,
    $checkoutParams['merchant_public_id'],
    $checkoutParams['amount'],
    $checkoutParams['currency'],
    $checkoutParams['description'],
    $orderReference,
    $cyclosDebug
);

if ($authorized) {
    update_checkout_session($session, [
        'status' => 'AUTHORIZED',
        'transaction_id' => 'txn_' . bin2hex(random_bytes(16)),
        'lusopay_id' => $lusopayId,
        'name' => $parsed['claims']['name'] ?? null,
        // Cryptographic proof this exact presentation was fresh and
        // bound to this session (see verify_holder_key_binding()) --
        // not returned by api/checkout-status.php, only visible via
        // debug-checkout.php.
        'key_binding' => ['iat' => $keyBinding['iat'], 'kb_jwt' => $keyBinding['kb_jwt']],
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
