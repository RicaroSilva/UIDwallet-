<?php
// oidc/token.php
// The token_endpoint Cyclos's backend calls server-to-server after the
// browser lands back on its callback URL with a code. Standard OAuth2
// authorization_code grant: validates the client credentials and the
// code, then returns an access_token + a signed id_token carrying
// name + lusopay_id.

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$body = $_POST;
if (empty($body)) {
    parse_str(file_get_contents('php://input'), $body);
}

$clientId = $body['client_id'] ?? null;
$clientSecret = $body['client_secret'] ?? null;

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($clientId === null && preg_match('/^Basic\s+(.+)$/i', $authHeader, $matches)) {
    $decoded = base64_decode($matches[1], true);
    if ($decoded !== false && str_contains($decoded, ':')) {
        [$clientId, $clientSecret] = explode(':', $decoded, 2);
    }
}

if ($clientId !== OIDC_CLIENT_ID || !hash_equals(OIDC_CLIENT_SECRET, (string) $clientSecret)) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_client']);
    exit;
}

$grantType = $body['grant_type'] ?? '';
$code = $body['code'] ?? '';

if ($grantType !== 'authorization_code' || !$code) {
    http_response_code(400);
    echo json_encode(['error' => 'unsupported_grant_type']);
    exit;
}

$codeData = consume_oidc_authorization_code($code);
if ($codeData === null) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_grant', 'error_description' => 'unknown or already used code']);
    exit;
}

$idToken = build_oidc_id_token($codeData['claims'], $codeData['nonce'] ?? null);

echo json_encode([
    'access_token' => bin2hex(random_bytes(24)),
    'token_type' => 'Bearer',
    'expires_in' => 300,
    'id_token' => $idToken,
]);
