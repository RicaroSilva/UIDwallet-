<?php
// oidc/wallet-callback.php
// The wallet's OpenID4VP direct_post lands here for the login flow (as
// opposed to api/pay-with-card-response.php, which is the same idea for
// the standalone "pay with card" flow). Parses out name + lusopay_id,
// verifies the issuer signature, and -- if valid -- issues an OAuth2
// authorization code and marks the oidc/authorize.php session READY.

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$oidcSession = $_GET['oidc_session'] ?? '';
if ($oidcSession === '' || !ctype_alnum($oidcSession)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'missing or invalid oidc_session']);
    exit;
}

$body = $_POST;
if (empty($body)) {
    parse_str(file_get_contents('php://input'), $body);
}

$vpTokenRaw = $body['vp_token'] ?? null;
if (!$vpTokenRaw) {
    echo json_encode([]);
    exit;
}

$vpToken = json_decode($vpTokenRaw, true);
$presentation = is_string($vpToken)
    ? $vpToken
    : ($vpToken['lusopay_card'][0] ?? $vpToken['lusopay_card'] ?? null);

if (!is_string($presentation)) {
    echo json_encode([]);
    exit;
}

$parsed = parse_sd_jwt_vc_presentation($presentation);
$signatureValid = verify_es256_jwt($parsed['issuer_jwt'], get_signing_key()['jwk']);

if ($signatureValid && isset($parsed['claims']['name'], $parsed['claims']['lusopay_id'])) {
    complete_oidc_session($oidcSession, [
        'name' => $parsed['claims']['name'],
        'lusopay_id' => $parsed['claims']['lusopay_id'],
    ]);
}

echo json_encode([]);
