<?php
// api/issuance-status.php
// Polled by issue-test.php while it waits for the wallet to actually
// accept the credential offer, so it can redirect back to Cyclos (or
// show a confirmation) once that's really happened -- not just once the
// QR was shown.

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$code = $_GET['code'] ?? '';
$status = ($code !== '' && ctype_alnum($code)) ? get_issuance_status($code) : 'PENDING';

echo json_encode(['status' => $status]);
