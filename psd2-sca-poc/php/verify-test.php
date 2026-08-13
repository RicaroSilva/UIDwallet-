<?php
// verify-test.php
// EXPERIMENTAL: builds a real (unsigned) OpenID4VP authorization request
// asking the wallet for the PID's given_name + family_name via DCQL, using
// the "redirect_uri:" client_id scheme (the scheme meant for unsigned
// requests). Structure mirrors exactly what Credo (the library behind the
// Paradym Wallet) produces -- captured from a local openid4vc-playground
// instance during development, not guessed from the spec by hand.
//
// This is step 1 of getting a REAL wallet interaction working: no
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

$scheme = (($_SERVER['HTTPS'] ?? '') !== '') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$basePath = rtrim(str_replace('verify-test.php', '', $_SERVER['SCRIPT_NAME']), '/');

$session = base64url_random(8);
$responseUri = "{$scheme}://{$host}{$basePath}/api/wallet-response.php?session={$session}";
$nonce = base64url_random();
$state = base64url_random();

// Offer both formats as alternatives: the sd-jwt-vc PID uses vct_values,
// the mdoc PID uses doctype_value + [namespace, element] claim paths. The
// wallet matches whichever one it actually holds.
$dcqlQuery = [
    'credentials' => [
        [
            'id' => 'pid_sdjwt',
            'format' => 'dc+sd-jwt',
            'meta' => ['vct_values' => ['urn:eudi:pid:1']],
            'claims' => [
                ['path' => ['given_name'], 'id' => 'given_name'],
                ['path' => ['family_name'], 'id' => 'family_name'],
            ],
        ],
        [
            'id' => 'pid_mdoc',
            'format' => 'mso_mdoc',
            'meta' => ['doctype_value' => 'eu.europa.ec.eudi.pid.1'],
            'claims' => [
                ['path' => ['eu.europa.ec.eudi.pid.1', 'given_name'], 'id' => 'given_name'],
                ['path' => ['eu.europa.ec.eudi.pid.1', 'family_name'], 'id' => 'family_name'],
            ],
        ],
    ],
    'credential_sets' => [
        ['options' => [['pid_sdjwt'], ['pid_mdoc']], 'purpose' => 'PID - Nome próprio e nome de família'],
    ],
];

$clientMetadata = [
    'vp_formats_supported' => [
        'dc+sd-jwt' => [
            'sd-jwt_alg_values' => ['ES256'],
            'kb-jwt_alg_values' => ['ES256'],
        ],
    ],
    'client_name' => 'LusoPay',
    'response_types_supported' => ['vp_token'],
];

$params = [
    'response_type' => 'vp_token',
    'client_id' => 'redirect_uri:' . $responseUri,
    'response_uri' => $responseUri,
    'response_mode' => 'direct_post',
    'nonce' => $nonce,
    'dcql_query' => json_encode($dcqlQuery, JSON_UNESCAPED_SLASHES),
    'client_metadata' => json_encode($clientMetadata, JSON_UNESCAPED_SLASHES),
    'state' => $state,
];

$queryParts = [];
foreach ($params as $key => $value) {
    $queryParts[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
}
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
    <p>Pede à wallet o <strong>given_name</strong> e <strong>family_name</strong> do PID.</p>
    <img src="<?= $qrCodeDataUrl ?>" alt="QR" />
    <p class="hint">
      <a href="<?= escape_html($authorizationRequestUri) ?>">Abrir diretamente (se estiveres no telemóvel)</a>
    </p>
    <p class="hint">Depois de digitalizares, vê a resposta em <a href="view-log.php">view-log.php</a>.</p>
  </div>
</body>
</html>
