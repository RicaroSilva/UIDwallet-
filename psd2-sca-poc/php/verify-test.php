<?php
// verify-test.php
// EXPERIMENTAL: builds a real (unsigned) OpenID4VP authorization request
// asking the wallet for every claim available on the PID, via DCQL, using
// the "redirect_uri:" client_id scheme (the scheme meant for unsigned
// requests). Structure mirrors what Credo (the library behind the Paradym
// Wallet) produces -- captured from a local openid4vc-playground instance
// during development, not guessed from the spec by hand.
//
// Delivered BY VALUE (embedded directly in the URI), not by-reference. The
// underlying protocol library (@openid4vc/oauth2, used by Credo) defines
// zero allowed JWS signer methods for the "redirect_uri" client_id scheme
// (checked directly in its source) -- by-reference (request_uri) delivery
// requires the fetched object to be a JWT per RFC 9101, and ANY signer info
// in that JWT (even just an embedded "jwk") gets rejected as "not allowed"
// for this scheme, with no way to satisfy it. By-value has no such
// requirement, so it works for exactly the request this scheme is meant for.
//
// The tradeoff: with every PID claim included, the URI is too long to
// reliably encode as a QR ("Data too big"), so open the direct link instead
// of scanning the QR when testing on the same phone.

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
$nonce = base64url_random();
$state = base64url_random();

// Every claim the static test PID actually carries (see Animo's
// openid4vc-playground eudiPidSdJwt.ts / eudiPidMdoc.ts), one entry per
// format since field names differ between them (e.g. birthdate vs
// birth_date, nationalities vs nationality). credential_sets lets the
// wallet match whichever format it actually holds.
$PID_MDOC_NAMESPACE = 'eu.europa.ec.eudi.pid.1';

// Curated subset, not literally every claim: with all ~34 fields the
// request URI got long enough (~5000 chars) that something in the
// phone/browser/app hand-off corrupted it in transit (a dcql format value
// arrived as "dc sd-jwt" instead of "dc+sd-jwt", even though our own
// encoding was verified correct). Keeping this comfortably short avoids
// that, same as the QR "Data too big" limit -- both are real ceilings on
// how much a single request can carry.
$sdJwtClaimNames = [
    'given_name', 'family_name', 'birthdate', 'nationalities', 'address', 'portrait', 'issuing_country',
];

$mdocClaimNames = [
    'given_name', 'family_name', 'birth_date', 'nationality', 'resident_address', 'portrait', 'issuing_country',
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

$clientMetadata = [
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

try {
    $qrCodeDataUrl = qr_code_data_uri($authorizationRequestUri);
} catch (\Throwable $error) {
    // With every PID claim included this is usually too big for a QR --
    // that's fine, the direct link below doesn't have that limit.
    $qrCodeDataUrl = null;
}
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
  .btn-open { display: block; background: #6d28d9; color: #fff; font-weight: 700; padding: 14px; border-radius: 10px; text-decoration: none; margin: 16px 0; }
  .hint { font-size: 0.8rem; color: #6b7280; margin-top: 12px; word-break: break-all; }
  a { color: #6d28d9; font-weight: 600; text-decoration: none; }
</style>
</head>
<body>
  <div class="card">
    <h1>🧪 Teste OpenID4VP real</h1>
    <p>Pede à wallet <strong>todos os dados disponíveis</strong> do PID (nome, data de nascimento, morada, nacionalidade, foto, etc.).</p>

    <a class="btn-open" href="<?= escape_html($authorizationRequestUri) ?>">📱 Abrir na Carteira Digital (toca aqui no telemóvel)</a>

    <?php if ($qrCodeDataUrl): ?>
      <img src="<?= $qrCodeDataUrl ?>" alt="QR" />
      <p class="hint">Ou digitaliza este QR de outro dispositivo.</p>
    <?php else: ?>
      <p class="hint">(Pedido demasiado grande para gerar QR -- usa o botão acima em vez de digitalizar.)</p>
    <?php endif; ?>

    <p class="hint">Depois de responderes na wallet, vê o resultado em <a href="view-log.php">view-log.php</a>.</p>
  </div>
</body>
</html>
