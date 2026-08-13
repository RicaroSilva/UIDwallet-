<?php
// verify-test.php
// EXPERIMENTAL: builds a real (unsigned) OpenID4VP authorization request
// asking the wallet for every claim available on the PID, via DCQL, using
// the "redirect_uri:" client_id scheme (the scheme meant for unsigned
// requests). Structure mirrors what Credo (the library behind the Paradym
// Wallet) produces -- captured from a local openid4vc-playground instance
// during development, not guessed from the spec by hand.
//
// The full request is too large to fit in a QR by value (endroid/qr-code
// throws "Data too big"), so this uses the standard by-reference delivery:
// the QR only carries a short request_uri, which the wallet fetches over
// HTTPS to get the actual request object (see api/request-object.php).
//
// This is step 1 of getting a real wallet interaction working: no
// transaction_data yet, no response verification yet -- just confirming the
// wallet accepts the request and can deliver a presentation to
// api/wallet-response.php. Once this round-trips successfully, transaction
// data and verification get added on top.

declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

function base64url_random(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function sdjwt_claim(string $name): array
{
    return ['path' => [$name], 'id' => $name];
}

function mdoc_claim(string $namespace, string $name): array
{
    return ['path' => [$namespace, $name], 'id' => $name];
}

$scheme = (($_SERVER['HTTPS'] ?? '') !== '') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$basePath = rtrim(str_replace('verify-test.php', '', $_SERVER['SCRIPT_NAME']), '/');

$session = base64url_random(8);
$responseUri = "{$scheme}://{$host}{$basePath}/api/wallet-response.php?session={$session}";
$requestUri = "{$scheme}://{$host}{$basePath}/api/request-object.php?session={$session}";
$nonce = base64url_random();
$state = base64url_random();

// Every claim the static test PID actually carries (see Animo's
// openid4vc-playground eudiPidSdJwt.ts / eudiPidMdoc.ts), one entry per
// format since field names differ between them (e.g. birthdate vs
// birth_date, nationalities vs nationality). credential_sets lets the
// wallet match whichever format it actually holds.
$PID_MDOC_NAMESPACE = 'eu.europa.ec.eudi.pid.1';

$sdJwtClaimNames = [
    'given_name', 'family_name', 'birthdate', 'place_of_birth', 'nationalities',
    'address', 'portrait', 'date_of_expiry', 'issuing_authority', 'issuing_country',
    'date_of_issuance',
];

$mdocClaimNames = [
    'given_name', 'family_name', 'birth_date', 'place_of_birth', 'nationality',
    'resident_address', 'resident_country', 'resident_state', 'resident_city',
    'resident_postal_code', 'resident_street', 'resident_house_number',
    'personal_administrative_number', 'portrait', 'family_name_birth', 'given_name_birth',
    'sex', 'email_address', 'mobile_phone_number', 'expiry_date', 'issuing_authority',
    'issuing_country', 'document_number', 'issuance_date',
];

$dcqlQuery = [
    'credentials' => [
        [
            'id' => 'pid_sdjwt',
            'format' => 'dc+sd-jwt',
            'meta' => ['vct_values' => ['urn:eudi:pid:1']],
            'claims' => array_map('sdjwt_claim', $sdJwtClaimNames),
        ],
        [
            'id' => 'pid_mdoc',
            'format' => 'mso_mdoc',
            'meta' => ['doctype_value' => $PID_MDOC_NAMESPACE],
            'claims' => array_map(fn (string $name) => mdoc_claim($PID_MDOC_NAMESPACE, $name), $mdocClaimNames),
        ],
    ],
    'credential_sets' => [
        ['options' => [['pid_sdjwt'], ['pid_mdoc']], 'purpose' => 'PID completo - todos os dados disponíveis'],
    ],
];

$clientId = 'redirect_uri:' . $responseUri;

$requestObject = [
    'response_type' => 'vp_token',
    'client_id' => $clientId,
    'response_uri' => $responseUri,
    'response_mode' => 'direct_post',
    'nonce' => $nonce,
    'dcql_query' => $dcqlQuery,
    'client_metadata' => [
        'vp_formats_supported' => [
            'dc+sd-jwt' => [
                'sd-jwt_alg_values' => ['ES256'],
                'kb-jwt_alg_values' => ['ES256'],
            ],
            'mso_mdoc' => [
                'issuerauth_alg_values' => ['ES256'],
                'deviceauth_alg_values' => ['ES256'],
            ],
        ],
        'client_name' => 'LusoPay',
        'response_types_supported' => ['vp_token'],
    ],
    'state' => $state,
];

$requestStoreDir = __DIR__ . '/request-store';
if (!is_dir($requestStoreDir)) {
    mkdir($requestStoreDir, 0700, true);
}
file_put_contents("{$requestStoreDir}/{$session}.json", json_encode($requestObject, JSON_UNESCAPED_SLASHES));

// By-reference delivery: only client_id + request_uri go in the QR.
$queryParts = [
    'client_id=' . rawurlencode($clientId),
    'request_uri=' . rawurlencode($requestUri),
];
$authorizationRequestUri = 'openid4vp://?' . implode('&', $queryParts);

$qrCodeDataUrl = qr_code_data_uri($authorizationRequestUri);
?>
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>LusoPay - Teste OpenID4VP real</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f2f4f7; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1c2333; }
  .card { width: 100%; max-width: 460px; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(20,30,60,0.08); padding: 28px; text-align: center; }
  img { width: 240px; height: 240px; border-radius: 12px; border: 1px solid #eef0f3; }
  .hint { font-size: 0.8rem; color: #6b7280; margin-top: 12px; word-break: break-all; }
  a { color: #6d28d9; font-weight: 600; text-decoration: none; }
</style>
</head>
<body>
  <div class="card">
    <h1>🧪 Teste OpenID4VP real</h1>
    <p>Pede à wallet <strong>todos os dados disponíveis</strong> do PID (nome, data de nascimento, morada, nacionalidade, foto, etc.).</p>
    <img src="<?= $qrCodeDataUrl ?>" alt="QR" />
    <p class="hint">
      <a href="<?= escape_html($authorizationRequestUri) ?>">Abrir diretamente (se estiveres no telemóvel)</a>
    </p>
    <p class="hint">Pedido completo em: <a href="<?= escape_html($requestUri) ?>"><?= escape_html($requestUri) ?></a></p>
    <p class="hint">Depois de digitalizares, vê a resposta em <a href="view-log.php">view-log.php</a>.</p>
  </div>
</body>
</html>
