<?php
// pay-with-card.php
// Real OpenID4VP authorization request asking the wallet for the LusoPay
// Card credential (name + lusopay_id), delivered by value via the
// "redirect_uri:" client_id scheme -- same working pattern as
// verify-test.php. Once the wallet posts back, api/pay-with-card-response.php
// parses out the two claims and pay-with-card-result.php shows them.
//
// This page only retrieves the card's data and hands it off -- it does not
// itself authorize or execute a payment; that's for whatever calls this
// flow to build on top of the retrieved name/lusopay_id.
//
// Accepts an optional ?session= from the caller (e.g. the WooCommerce
// plugin's "Ligar com a Carteira Digital" admin button) so it can know
// the session id up front, before the wallet answers, instead of having
// to scrape it out of this page. Falls back to a random one otherwise.

declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

function base64url_random(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

$scheme = (($_SERVER['HTTPS'] ?? '') !== '') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$basePath = rtrim(str_replace('pay-with-card.php', '', $_SERVER['SCRIPT_NAME']), '/');

$requestedSession = $_GET['session'] ?? '';
$session = preg_match('/^[A-Za-z0-9_-]{8,64}$/', $requestedSession) ? $requestedSession : base64url_random(8);
$responseUri = "{$scheme}://{$host}{$basePath}/api/pay-with-card-response.php?session={$session}";
$resultUri = "{$scheme}://{$host}{$basePath}/pay-with-card-result.php?session={$session}";
$nonce = base64url_random();
$state = base64url_random();

$dcqlQuery = [
    'credentials' => [
        [
            'id' => 'lusopay_card',
            'format' => 'dc+sd-jwt',
            'meta' => ['vct_values' => ['urn:lusopay:card:1']],
            'claims' => [
                ['path' => ['name'], 'id' => 'name'],
                ['path' => ['lusopay_id'], 'id' => 'lusopay_id'],
            ],
        ],
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
<title>LusoPay - Pagar com o cartão</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f2f4f7; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1c2333; }
  .card { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(20,30,60,0.08); padding: 28px; text-align: center; }
  img { width: 220px; height: 220px; border-radius: 12px; border: 1px solid #eef0f3; }
  .btn-open { display: block; background: #6d28d9; color: #fff; font-weight: 700; padding: 14px; border-radius: 10px; text-decoration: none; margin: 16px 0; }
  .hint { font-size: 0.8rem; color: #6b7280; margin-top: 12px; word-break: break-all; }
  a { color: #6d28d9; font-weight: 600; text-decoration: none; }
</style>
</head>
<body>
  <div class="card">
    <h1>💳 Pagar com o LusoPay Card</h1>
    <p>Pede à wallet o teu <strong>LusoPay Card</strong> (nome + LusoPay ID) para identificar quem está a pagar.</p>

    <a class="btn-open" href="<?= escape_html($authorizationRequestUri) ?>">📱 Abrir na Carteira Digital (toca aqui no telemóvel)</a>
    <img src="<?= $qrCodeDataUrl ?>" alt="QR" />
    <p class="hint">Ou digitaliza este QR de outro dispositivo.</p>

    <p class="hint">Depois de aprovares na wallet, os dados aparecem em <a href="<?= escape_html($resultUri) ?>">pay-with-card-result.php</a>.</p>
  </div>
</body>
</html>
