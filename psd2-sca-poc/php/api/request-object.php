<?php
// api/request-object.php
// EXPERIMENTAL: serves the authorization request object that
// verify-test.php stashed on disk, for the wallet to fetch by reference
// (request_uri). RFC 9101 (JAR) requires this to be a JWT; the wallet
// rejected an unsigned (alg: none) one, so this signs it with ES256 using a
// throwaway key (no x509 trust chain -- the "redirect_uri" client_id scheme
// doesn't require the wallet to trust the signer, only that the signature
// is internally valid).

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

$session = $_GET['session'] ?? '';
$path = __DIR__ . "/../request-store/{$session}.json";

// Basic guard against path traversal via a crafted session value.
if ($session === '' || !ctype_alnum(str_replace(['-', '_'], '', $session)) || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'request not found']);
    exit;
}

$payload = json_decode(file_get_contents($path), true);

header('Content-Type: application/oauth-authz-req+jwt');
echo sign_request_object_jwt($payload);
