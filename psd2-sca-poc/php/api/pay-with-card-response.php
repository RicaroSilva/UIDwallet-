<?php
// api/pay-with-card-response.php
// Receives the wallet's OpenID4VP direct_post for pay-with-card.php,
// pulls the "name" and "lusopay_id" claims out of the presented LusoPay
// Card, and stores them so pay-with-card-result.php (or any other caller)
// can pick them up by session.

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$session = $_GET['session'] ?? '';
if ($session === '' || !ctype_alnum(str_replace(['-', '_'], '', $session))) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'missing or invalid session']);
    exit;
}

$body = $_POST;
if (empty($body)) {
    parse_str(file_get_contents('php://input'), $body);
}

$vpTokenRaw = $body['vp_token'] ?? null;
if (!$vpTokenRaw) {
    save_card_presentation($session, ['status' => 'ERROR', 'error' => 'missing vp_token', 'raw' => $body]);
    echo json_encode([]);
    exit;
}

$vpToken = json_decode($vpTokenRaw, true);
// The wallet may respond with a compact string directly (single-credential
// shorthand) or, per the DCQL result mapping, an object keyed by the
// dcql_query credential id whose value is either that string or a
// single-element array of it -- accept all three shapes.
$presentation = is_string($vpToken)
    ? $vpToken
    : ($vpToken['lusopay_card'][0] ?? $vpToken['lusopay_card'] ?? null);

if (!is_string($presentation)) {
    save_card_presentation($session, ['status' => 'ERROR', 'error' => 'unrecognized vp_token shape', 'raw' => $vpToken]);
    echo json_encode([]);
    exit;
}

$parsed = parse_sd_jwt_vc_presentation($presentation);

// We are both issuer and verifier here, so the credential's signing key
// is our own -- check it really was signed by us and not tampered with.
$signatureValid = verify_es256_jwt($parsed['issuer_jwt'], get_signing_key()['jwk']);

save_card_presentation($session, [
    'status' => $signatureValid ? 'OK' : 'INVALID_SIGNATURE',
    'name' => $parsed['claims']['name'] ?? null,
    'lusopay_id' => $parsed['claims']['lusopay_id'] ?? null,
    'issuer' => $parsed['payload']['iss'] ?? null,
    'vct' => $parsed['payload']['vct'] ?? null,
    'has_key_binding' => $parsed['kb_jwt'] !== null,
]);

echo json_encode([]);
