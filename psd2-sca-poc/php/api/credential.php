<?php
// api/credential.php
// OpenID4VCI credential endpoint: verifies the access token and the
// wallet's key-possession proof, then issues a signed SD-JWT VC binding
// the requested claims to the holder's key.

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer error="invalid_token"');
    echo json_encode(['error' => 'invalid_token']);
    exit;
}
$accessToken = $matches[1];

$session = get_issuance_session_by_access_token($accessToken);
if (!$session) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer error="invalid_token"');
    echo json_encode(['error' => 'invalid_token']);
    exit;
}

$body = read_json_body();
// Draft 13+ of OpenID4VCI replaced the singular "proof" with a plural
// "proofs" object keyed by proof type (e.g. {"jwt": ["<jwt>"]}), to
// support requesting multiple credential instances in one call. Accept
// both shapes; only single-JWT proofs are supported here.
$proofJwt = $body['proof']['jwt'] ?? $body['proofs']['jwt'][0] ?? null;
$isPluralProofs = isset($body['proofs']);
if (!$proofJwt) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_proof', 'error_description' => 'missing proof.jwt or proofs.jwt']);
    exit;
}

$parts = explode('.', $proofJwt);
if (count($parts) !== 3) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_proof', 'error_description' => 'malformed proof JWT']);
    exit;
}

$proofHeader = json_decode(base64url_decode($parts[0]), true);
$proofPayload = json_decode(base64url_decode($parts[1]), true);
$holderJwk = $proofHeader['jwk'] ?? null;

if (!$holderJwk) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_proof', 'error_description' => 'missing jwk in proof header']);
    exit;
}

if (!verify_es256_jwt($proofJwt, $holderJwk)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_proof', 'error_description' => 'proof signature invalid']);
    exit;
}

if (($proofPayload['nonce'] ?? null) !== $session['c_nonce']) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_proof', 'error_description' => 'nonce mismatch or expired']);
    exit;
}

$credential = build_sd_jwt_vc(LUSOPAY_CREDENTIAL_VCT, $session['claims'], $holderJwk);
if (isset($session['issuance_code'])) {
    mark_issuance_status($session['issuance_code'], 'ISSUED');
}
consume_issuance_session($accessToken);

// A request using the plural "proofs" form must get back the plural
// "credentials" form, per the same draft 13+ change.
echo json_encode($isPluralProofs ? ['credentials' => [['credential' => $credential]]] : ['credential' => $credential]);
