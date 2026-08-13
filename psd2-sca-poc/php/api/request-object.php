<?php
// api/request-object.php
// EXPERIMENTAL: serves the authorization request object that
// verify-test.php stashed on disk, for the wallet to fetch by reference
// (request_uri). RFC 9101 (JAR) requires request objects delivered this way
// to be a JWT -- even when, as here, the client_id scheme is "redirect_uri"
// and no signature verification is expected. So this wraps the JSON payload
// as an unsigned JWT (alg: none) rather than returning plain JSON.

declare(strict_types=1);

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$session = $_GET['session'] ?? '';
$path = __DIR__ . "/../request-store/{$session}.json";

// Basic guard against path traversal via a crafted session value.
if ($session === '' || !ctype_alnum(str_replace(['-', '_'], '', $session)) || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'request not found']);
    exit;
}

$payload = file_get_contents($path);

$header = base64url_encode(json_encode(['alg' => 'none', 'typ' => 'oauth-authz-req+jwt']));
$body = base64url_encode($payload);

header('Content-Type: application/oauth-authz-req+jwt');
echo "{$header}.{$body}.";
