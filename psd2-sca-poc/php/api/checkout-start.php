<?php
// api/checkout-start.php
// Builds the transaction_data + holder binding proof and returns them to
// the browser together with a QR code. Stateless: the browser is expected
// to resend both objects to checkout-confirm.php once the (simulated)
// wallet has "confirmed".

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$body = read_json_body();
$merchant = $body['merchant'] ?? null;
$amount = $body['amount'] ?? null;
$currency = $body['currency'] ?? 'EUR';

if (!$merchant || !$amount || !is_numeric($amount)) {
    http_response_code(400);
    echo json_encode(['error' => 'merchant e amount são obrigatórios']);
    exit;
}

$transactionData = build_transaction_data($merchant, $amount, $currency);
$holderBindingProof = build_holder_binding_proof($transactionData);

// Deliberately NOT an "openid4vp://" URI: real wallet apps (e.g. Paradym)
// register that scheme and would try to process this as a genuine
// authorization request, then reject it since it's missing required
// fields. This QR is a visual stand-in only.
$qrPayload = "LUSOPAY-DEMO:{$transactionData['transaction_id']}:{$transactionData['amount']}:{$transactionData['currency']}";

echo json_encode([
    'transactionData' => $transactionData,
    'holderBindingProof' => $holderBindingProof,
    'qrCodeDataUrl' => qr_code_data_uri($qrPayload),
]);
