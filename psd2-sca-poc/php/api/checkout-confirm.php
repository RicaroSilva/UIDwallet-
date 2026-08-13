<?php
// api/checkout-confirm.php
// Receives back the transaction_data + holder_binding_proof the browser was
// given by checkout-start.php (standing in for the wallet's presentation),
// independently re-validates them (dynamic linking hash, SCA factor count),
// looks up the buyer's bank, and -- if authorized -- notifies the LusoPay
// Cyclos backend before returning the result.

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$body = read_json_body();
$transactionData = $body['transactionData'] ?? null;
$holderBindingProof = $body['holderBindingProof'] ?? null;
$publicId = $body['publicId'] ?? null;

if (!is_array($transactionData) || !is_array($holderBindingProof) || !$publicId) {
    http_response_code(400);
    echo json_encode(['status' => 'REJECTED', 'errors' => ['transactionData, holderBindingProof e publicId são obrigatórios']]);
    exit;
}

$result = authorize_payment($transactionData, $holderBindingProof, $publicId);

if ($result['status'] === 'AUTHORIZED') {
    notify_cyclos($publicId, $transactionData);
}

echo json_encode($result);
