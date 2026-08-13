<?php
// api/request-object.php
// EXPERIMENTAL: serves the full (unsigned) authorization request object
// that verify-test.php stashed on disk, for the wallet to fetch by
// reference (request_uri). Since this is unsigned (client_id scheme
// "redirect_uri"), it's returned as plain JSON, not a signed JWT.

declare(strict_types=1);

$session = $_GET['session'] ?? '';
$path = __DIR__ . "/../request-store/{$session}.json";

// Basic guard against path traversal via a crafted session value.
if ($session === '' || !ctype_alnum(str_replace(['-', '_'], '', $session)) || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'request not found']);
    exit;
}

header('Content-Type: application/json');
readfile($path);
