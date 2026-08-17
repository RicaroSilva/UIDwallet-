<?php
// api/checkout-status.php
// Polled by checkout.php's JS, and called server-to-server by the
// WooCommerce plugin (or any other integration) on its own thank-you
// page to verify the outcome before trusting it -- never trust the
// browser's own query-string "status=" alone for something that moves
// money.

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$session = $_GET['session'] ?? '';
$data = ($session !== '' && ctype_alnum(str_replace(['-', '_'], '', $session))) ? get_checkout_session($session) : null;

if ($data === null) {
    echo json_encode(['status' => 'PENDING']);
    exit;
}

echo json_encode([
    'status' => $data['status'] ?? 'PENDING',
    'transaction_id' => $data['transaction_id'] ?? null,
    'errors' => $data['errors'] ?? null,
]);
