<?php
// api/token.php
// OpenID4VCI / OAuth2 token endpoint, pre-authorized_code grant only.

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$body = $_POST;
if (empty($body)) {
    parse_str(file_get_contents('php://input'), $body);
}

$grantType = $body['grant_type'] ?? '';
$preAuthCode = $body['pre-authorized_code'] ?? '';

if ($grantType !== 'urn:ietf:params:oauth:grant-type:pre-authorized_code' || !$preAuthCode) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request']);
    exit;
}

$result = exchange_pre_authorized_code($preAuthCode);
if (!$result) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_grant', 'error_description' => 'unknown or already used pre-authorized_code']);
    exit;
}

echo json_encode([
    'access_token' => $result['access_token'],
    'token_type' => 'Bearer',
    'expires_in' => 300,
    'c_nonce' => $result['c_nonce'],
    'c_nonce_expires_in' => 300,
]);
