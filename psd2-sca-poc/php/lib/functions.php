<?php
// lib/functions.php
// Shared logic for the LusoPay Checkout service (PHP port). Conceptually
// mirrors the three PaSO roles from the original Node.js prototype:
//   - build_transaction_data() / build_holder_binding_proof()  -> Relying Party (merchant)
//   - authorize_payment()                                       -> payment scheme (bank lookup) + Authorizing Party (validation)
//   - notify_cyclos()                                           -> the authorizing party's notification to the LusoPay/Cyclos backend
// Unlike the Node version (separate long-running processes per role), this
// is a single stateless PHP app: each request is independent, so the
// pending transaction_data + holder_binding_proof travel back and forth
// through the browser between the "start" and "confirm" steps instead of
// living in server memory.

declare(strict_types=1);

const CYCLOS_ENDPOINT = 'https://dev.lusopay.com:8444/web_dev/run/adduidpayment';
const BANK_REGISTRY_PATH = __DIR__ . '/../bank-registry.json';

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function escape_html(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function hash_transaction_data(array $transactionData): string
{
    return hash('sha256', json_encode($transactionData, JSON_UNESCAPED_SLASHES));
}

function build_transaction_data(string $merchant, string $amount, string $currency): array
{
    return [
        'type' => 'urn:eudi:sca:payment:1',
        'transaction_id' => 'txn_' . bin2hex(random_bytes(16)),
        'payee' => $merchant,
        'amount' => number_format((float) $amount, 2, '.', ''),
        'currency' => $currency,
    ];
}

function build_holder_binding_proof(array $transactionData): array
{
    return [
        'jti' => bin2hex(random_bytes(16)),
        'amr' => ['knowledge_pin', 'inherence_biometric'],
        'transaction_data_hash' => hash_transaction_data($transactionData),
    ];
}

function load_bank_registry(): array
{
    $json = file_get_contents(BANK_REGISTRY_PATH);
    return json_decode($json, true) ?? [];
}

/**
 * Re-validates a transaction_data + holder_binding_proof pair (as if
 * received from the wallet) and, if valid, "authorizes" it: looks up the
 * buyer's bank and returns the outcome. This folds together what the
 * Node.js prototype split across lusopay-router.js (bank lookup) and
 * authorizing-party.js (field validation + dynamic linking check).
 */
function authorize_payment(array $transactionData, array $holderBindingProof, string $publicId): array
{
    $errors = [];

    foreach (['type', 'transaction_id', 'payee', 'amount', 'currency'] as $field) {
        if (empty($transactionData[$field])) {
            $errors[] = "missing transaction_data.{$field}";
        }
    }

    $amr = $holderBindingProof['amr'] ?? null;
    if (empty($holderBindingProof['jti'])) {
        $errors[] = 'missing holder_binding_proof.jti';
    }
    if (!is_array($amr) || count($amr) < 2) {
        $errors[] = 'holder_binding_proof.amr must list at least two independent SCA factors';
    }
    if (empty($holderBindingProof['transaction_data_hash'])) {
        $errors[] = 'missing holder_binding_proof.transaction_data_hash';
    }

    if ($errors !== []) {
        return ['status' => 'REJECTED', 'errors' => $errors];
    }

    $recomputedHash = hash_transaction_data($transactionData);
    if ($recomputedHash !== $holderBindingProof['transaction_data_hash']) {
        return [
            'status' => 'REJECTED',
            'errors' => ["dynamic linking check failed: transaction_data_hash does not match transaction_data (expected {$recomputedHash}, got {$holderBindingProof['transaction_data_hash']})"],
        ];
    }

    $bankRegistry = load_bank_registry();
    $bankEntry = $bankRegistry[$publicId] ?? null;
    if ($bankEntry === null) {
        return ['status' => 'REJECTED', 'errors' => ["unknown user_id \"{$publicId}\""]];
    }

    return [
        'status' => 'AUTHORIZED',
        'transaction_id' => $transactionData['transaction_id'],
        'authorized_at' => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        'bank' => $bankEntry['bank'],
    ];
}

/**
 * Best-effort notification to the LusoPay/Cyclos backend. Failures are
 * logged but never change the authorization decision already made.
 */
function notify_cyclos(string $publicId, array $transactionData): void
{
    $payload = [
        'publicId' => $publicId,
        'amount' => $transactionData['amount'],
        'currency' => $transactionData['currency'],
        'description' => 'Payment to ' . $transactionData['payee'],
        'transactionId' => $transactionData['transaction_id'],
    ];

    $ch = curl_init(CYCLOS_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 4,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        error_log("[LUSOPAY-CHECKOUT] failed to reach Cyclos backend: {$error}");
        return;
    }
    error_log("[LUSOPAY-CHECKOUT] Cyclos backend responded with HTTP {$status}: {$response}");
}

function qr_code_data_uri(string $payload): string
{
    $qrCode = new \Endroid\QrCode\QrCode($payload);
    $qrCode->setMargin(1);
    $writer = new \Endroid\QrCode\Writer\PngWriter();
    return $writer->write($qrCode)->getDataUri();
}
