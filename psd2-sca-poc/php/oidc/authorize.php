<?php
// oidc/authorize.php
// The authorization_endpoint Cyclos redirects the user's browser to. A
// normal OIDC provider would show a login form here; instead this shows
// the same LusoPay Card OpenID4VP QR as pay-with-card.php, and only
// "logs the user in" once the wallet has actually presented the card
// (oidc/wallet-callback.php marks the session ready; the JS below polls
// oidc/status.php and then redirects back to Cyclos with the code).

declare(strict_types=1);

// TEMPORARY while diagnosing the 500 on the real server -- shows the exact
// PHP error instead of a bare 500. Remove once this endpoint is confirmed
// working (same technique already used in api/request-object.php).
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

function base64url_random(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

$responseType = $_GET['response_type'] ?? '';
$clientId = $_GET['client_id'] ?? '';
$redirectUri = $_GET['redirect_uri'] ?? '';
$state = $_GET['state'] ?? '';
$nonce = $_GET['nonce'] ?? '';

if ($responseType !== 'code' || $clientId !== OIDC_CLIENT_ID || $redirectUri !== OIDC_ALLOWED_REDIRECT_URI) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "invalid_request: response_type, client_id ou redirect_uri não correspondem ao provedor configurado.";
    exit;
}

$oidcSession = create_oidc_session($redirectUri, $state, $nonce);

$scheme = (($_SERVER['HTTPS'] ?? '') !== '') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$basePath = rtrim(str_replace('/oidc/authorize.php', '', $_SERVER['SCRIPT_NAME']), '/');

$walletResponseUri = "{$scheme}://{$host}{$basePath}/oidc/wallet-callback.php?oidc_session={$oidcSession}";
$statusUri = "{$scheme}://{$host}{$basePath}/oidc/status.php?oidc_session={$oidcSession}";

$dcqlQuery = [
    'credentials' => [
        [
            'id' => 'lusopay_card',
            'format' => 'dc+sd-jwt',
            'meta' => ['vct_values' => ['urn:lusopay:card:1']],
            'claims' => [
                ['path' => ['name'], 'id' => 'name'],
                ['path' => ['lusopay_id'], 'id' => 'lusopay_id'],
                ['path' => ['email'], 'id' => 'email'],
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
    'client_id' => 'redirect_uri:' . $walletResponseUri,
    'response_uri' => $walletResponseUri,
    'response_mode' => 'direct_post',
    'nonce' => base64url_random(),
    'dcql_query' => json_encode($dcqlQuery, JSON_UNESCAPED_SLASHES),
    'client_metadata' => json_encode($clientMetadata, JSON_UNESCAPED_SLASHES),
    'state' => base64url_random(),
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
<title>LusoPay - Login com a Carteira Digital</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 16px; background: #fff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1c2333; }
  p { margin: 0; font-size: 0.95rem; }
  img { width: 240px; height: 240px; }
  a { font-size: 0.8rem; color: #6b7280; text-decoration: underline; }
  #statusBox { font-size: 0.8rem; color: #6b7280; }
</style>
</head>
<body>
  <p>Lê o código com a tua Carteira Digital</p>
  <img src="<?= $qrCodeDataUrl ?>" alt="QR" />
  <a href="<?= escape_html($authorizationRequestUri) ?>">abrir na Carteira Digital neste telemóvel</a>
  <p id="statusBox">a aguardar confirmação...</p>

<script>
  const STATUS_URL = <?= json_encode($statusUri) ?>

  async function poll() {
    try {
      const response = await fetch(STATUS_URL)
      const data = await response.json()
      if (data.status === 'READY' && data.redirect_url) {
        document.getElementById('statusBox').textContent = 'confirmado, a voltar...'
        window.location.href = data.redirect_url
        return
      }
    } catch (error) {
      // keep polling -- a transient network hiccup shouldn't stop the flow
    }
    setTimeout(poll, 2000)
  }

  poll()
</script>
</body>
</html>
