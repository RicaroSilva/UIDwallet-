<?php
// oidc/authorize.php
// The authorization_endpoint Cyclos redirects the user's browser to. A
// normal OIDC provider would show a login form here; instead this shows
// the same LusoPay Card OpenID4VP QR as pay-with-card.php, and only
// "logs the user in" once the wallet has actually presented the card
// (oidc/wallet-callback.php marks the session ready; the JS below polls
// oidc/status.php and then redirects back to Cyclos with the code).

declare(strict_types=1);
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
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f2f4f7; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1c2333; }
  .card { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(20,30,60,0.08); padding: 28px; text-align: center; }
  img { width: 220px; height: 220px; border-radius: 12px; border: 1px solid #eef0f3; }
  .btn-open { display: block; background: #6d28d9; color: #fff; font-weight: 700; padding: 14px; border-radius: 10px; text-decoration: none; margin: 16px 0; }
  .hint { font-size: 0.8rem; color: #6b7280; margin-top: 12px; word-break: break-all; }
  .status { padding: 12px; border-radius: 10px; margin-top: 14px; font-size: 0.85rem; }
  .status.pending { background: #fef9c3; color: #854d0e; }
</style>
</head>
<body>
  <div class="card">
    <h1>🔐 Entrar com o LusoPay Card</h1>
    <p>Digitaliza o código ou toca no botão para provares quem és com a tua Carteira Digital.</p>

    <a class="btn-open" href="<?= escape_html($authorizationRequestUri) ?>">📱 Abrir na Carteira Digital (toca aqui no telemóvel)</a>
    <img src="<?= $qrCodeDataUrl ?>" alt="QR" />
    <p class="hint">Ou digitaliza este QR de outro dispositivo.</p>

    <div class="status pending" id="statusBox">⏳ A aguardar confirmação na wallet...</div>
  </div>

<script>
  const STATUS_URL = <?= json_encode($statusUri) ?>

  async function poll() {
    try {
      const response = await fetch(STATUS_URL)
      const data = await response.json()
      if (data.status === 'READY' && data.redirect_url) {
        document.getElementById('statusBox').textContent = '✅ Confirmado, a voltar para o Cyclos...'
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
