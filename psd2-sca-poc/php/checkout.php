<?php
// checkout.php
// The LusoPay Checkout page itself. Any site integrates just by redirecting
// the buyer here with query parameters -- no SDK, no API key, no CORS,
// since it's a plain page redirect (same "hosted checkout" pattern as
// Stripe Checkout / PayPal).
//
// The buyer is identified by presenting their LusoPay Card to a real
// OpenID4VP request (same DCQL shape as pay-with-card.php) instead of
// typing in their own "Public ID" -- once the wallet answers,
// api/checkout-response.php calls Cyclos's adduidpayment with the buyer's
// publicId (from the card) and the merchant's own receiving
// merchant_public_id (passed in below by whoever integrates this page,
// e.g. the WooCommerce gateway's settings) to actually move the money.

declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

function base64url_random(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

$amount = $_GET['amount'] ?? null;
$currency = $_GET['currency'] ?? 'EUR';
$description = $_GET['description'] ?? 'Compra online';
$merchant = $_GET['merchant'] ?? 'Loja parceira';
$returnUrl = $_GET['return_url'] ?? '';
$merchantPublicId = $_GET['merchant_public_id'] ?? '';

if ($amount === null || !is_numeric($amount)) {
    http_response_code(400);
    echo 'parâmetro "amount" é obrigatório e deve ser numérico';
    exit;
}
if ($merchantPublicId === '') {
    http_response_code(400);
    echo 'parâmetro "merchant_public_id" é obrigatório (identifica quem recebe o pagamento)';
    exit;
}
$amount = number_format((float) $amount, 2, '.', '');

// Accepts an optional ?session= from the caller (e.g. the WooCommerce
// Blocks payment method, which needs to know the session id up front so
// it can poll for the outcome while holding the actual WooCommerce order
// back) so it can know the session id up front, before the wallet
// answers, instead of having to scrape it out of this page. Falls back
// to a random one otherwise -- same pattern as pay-with-card.php. This
// is now always resolved to a concrete value *here* (rather than left
// for create_checkout_session() to fill in when absent), since it also
// doubles as this transaction's transaction_id below.
$requestedSession = $_GET['session'] ?? '';
$session = preg_match('/^[A-Za-z0-9_-]{8,64}$/', $requestedSession) ? $requestedSession : bin2hex(random_bytes(16));

// Optional external order/cart reference (e.g. a WooCommerce order id)
// so it can be forwarded to Cyclos alongside the payment -- '' if the
// caller doesn't have one yet (e.g. the Blocks flow asks the wallet for
// payment before the WooCommerce order exists).
$orderId = isset($_GET['order_id']) && ctype_digit((string) $_GET['order_id']) ? (string) $_GET['order_id'] : '';

// Generated once and stored below so api/checkout-response.php can
// compare it against the Key Binding JWT's own "nonce" claim -- proof
// that a specific wallet presentation was made *for this specific
// checkout session*, not replayed from an earlier one.
$nonce = base64url_random();

// EUDI Wallet TS12 ("SCA implementation with the wallet") dynamic
// linking: the wallet is asked to cryptographically bind its approval
// to this exact amount and payee, not just to "a session" -- it hashes
// this object (as the exact base64url string below) and returns that
// hash inside the Key Binding JWT's "transaction_data_hashes", which
// api/checkout-response.php recomputes and compares. Without this, a
// KB-JWT only proves "the wallet approved something for this nonce",
// never "the wallet was shown and approved *this amount, to this
// payee*" -- which is the actual PSD2 dynamic-linking requirement.
$transactionDataPayload = [
    'type' => 'urn:eudi:sca:payment:1',
    'credential_ids' => ['lusopay_card'],
    'transaction_data_hashes_alg' => ['sha-256'],
    'payload' => [
        'transaction_id' => $session,
        'payee' => [
            'name' => $merchant,
            'id' => $merchantPublicId,
        ],
        'currency' => $currency,
        'amount' => (float) $amount,
    ],
];
$transactionDataEncoded = base64url_encode(json_encode($transactionDataPayload, JSON_UNESCAPED_SLASHES));

create_checkout_session([
    'amount' => $amount,
    'currency' => $currency,
    'description' => $description,
    'merchant' => $merchant,
    'return_url' => $returnUrl,
    'merchant_public_id' => $merchantPublicId,
    'order_id' => $orderId,
    'nonce' => $nonce,
    'transaction_data' => $transactionDataEncoded,
], $session);

$scheme = (($_SERVER['HTTPS'] ?? '') !== '') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$basePath = rtrim(str_replace('checkout.php', '', $_SERVER['SCRIPT_NAME']), '/');

$walletResponseUri = "{$scheme}://{$host}{$basePath}/api/checkout-response.php?session={$session}";
$statusUri = "{$scheme}://{$host}{$basePath}/api/checkout-status.php?session={$session}";

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
    'nonce' => $nonce,
    'dcql_query' => json_encode($dcqlQuery, JSON_UNESCAPED_SLASHES),
    'client_metadata' => json_encode($clientMetadata, JSON_UNESCAPED_SLASHES),
    'transaction_data' => json_encode([$transactionDataEncoded], JSON_UNESCAPED_SLASHES),
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
<title>LusoPay Checkout</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    background: #f2f4f7; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: #1c2333;
  }
  .card { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(20,30,60,0.08); padding: 28px; }
  h1 { font-size: 1.15rem; margin: 0 0 4px; display: flex; align-items: center; gap: 8px; }
  .subtitle { color: #6b7280; font-size: 0.85rem; margin: 0 0 20px; }
  .summary { background: #f8f9fb; border-radius: 12px; padding: 16px; margin-bottom: 18px; }
  .summary .row { display: flex; justify-content: space-between; font-size: 0.9rem; margin: 4px 0; }
  .summary .row.total { font-weight: 700; font-size: 1.05rem; margin-top: 8px; padding-top: 8px; border-top: 1px solid #eef0f3; }
  .btn-open { display: block; background: #6d28d9; color: #fff; font-weight: 700; padding: 14px; border-radius: 10px; text-decoration: none; margin: 16px 0; text-align: center; }
  .panel { text-align: center; }
  .panel img { width: 220px; height: 220px; border-radius: 12px; border: 1px solid #eef0f3; }
  .panel .caption { font-size: 0.85rem; color: #4b5563; margin: 12px 0 0; }
  .status { padding: 16px; border-radius: 12px; margin-top: 16px; text-align: center; }
  .status.pending { background: #fef9c3; color: #854d0e; }
  .status.ok { background: #ecfdf5; color: #065f46; }
  .status.fail { background: #fef2f2; color: #991b1b; }
  .status h2 { margin: 0 0 6px; font-size: 1.05rem; }
  .status p { margin: 2px 0; font-size: 0.85rem; }
  .hidden { display: none; }
  button { width: 100%; border: none; border-radius: 10px; padding: 13px; font-size: 0.95rem; font-weight: 700; cursor: pointer; margin-top: 16px; background: #e5e7eb; color: #374151; }
</style>
</head>
<body>
  <div class="card">
    <h1>🔒 LusoPay Checkout</h1>
    <p class="subtitle">Pagamento seguro via Carteira Digital</p>

    <div class="summary">
      <div class="row"><span>A pagar a</span><span><?= escape_html($merchant) ?></span></div>
      <div class="row"><span>Descrição</span><span><?= escape_html($description) ?></span></div>
      <div class="row total"><span>Total</span><span>€ <?= escape_html($amount) ?></span></div>
    </div>

    <div id="qrView" class="panel">
      <a class="btn-open" href="<?= escape_html($authorizationRequestUri) ?>">📱 Pagar com a Carteira Digital</a>
      <img id="qrImage" src="<?= $qrCodeDataUrl ?>" alt="QR" />
      <p class="caption">Digitaliza este código com a tua Carteira Digital LusoPay para identificares a conta a debitar.</p>
    </div>

    <div id="statusView" class="status pending">
      <p>⏳ A aguardar confirmação na wallet...</p>
    </div>

    <div id="returnRow" class="hidden"></div>
  </div>

<script>
  const STATUS_URL = <?= json_encode($statusUri) ?>
  const SESSION = <?= json_encode($session) ?>
  const RETURN_URL = <?= json_encode($returnUrl) ?>
  const MERCHANT = <?= json_encode($merchant) ?>

  const qrView = document.getElementById('qrView')
  const statusView = document.getElementById('statusView')
  const returnRow = document.getElementById('returnRow')

  async function poll() {
    try {
      const response = await fetch(STATUS_URL)
      const data = await response.json()

      if (data.status === 'AUTHORIZED') {
        qrView.classList.add('hidden')
        statusView.className = 'status ok'
        statusView.innerHTML = '<h2>✅ Pagamento autorizado</h2><p>Transação: ' + data.transaction_id + '</p>'
        showReturn(data)
        return
      }
      if (data.status === 'REJECTED') {
        qrView.classList.add('hidden')
        statusView.className = 'status fail'
        statusView.innerHTML = '<h2>❌ Pagamento rejeitado</h2><p>' + (data.errors || []).join('<br/>') + '</p>'
        showReturn(data)
        return
      }
    } catch (error) {
      // keep polling -- a transient network hiccup shouldn't stop the flow
    }
    setTimeout(poll, 2000)
  }

  function showReturn(data) {
    if (!RETURN_URL) return
    const url = new URL(RETURN_URL)
    url.searchParams.set('status', data.status)
    url.searchParams.set('lusopay_session', SESSION)
    if (data.transaction_id) url.searchParams.set('transaction_id', data.transaction_id)
    returnRow.classList.remove('hidden')
    returnRow.innerHTML = '<button id="returnButton">Voltar a ' + MERCHANT + '</button>'
    document.getElementById('returnButton').addEventListener('click', () => {
      window.location.href = url.toString()
    })
    // Also go automatically after a short pause, for integrations (like a
    // WooCommerce order-received redirect) that expect this on their own.
    setTimeout(() => { window.location.href = url.toString() }, 2500)
  }

  poll()
</script>
</body>
</html>
