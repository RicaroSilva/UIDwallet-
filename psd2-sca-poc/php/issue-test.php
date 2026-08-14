<?php
// issue-test.php
// Builds a real OpenID4VCI credential offer (pre-authorized_code flow) for
// a "LusoPay Card" carrying a name + lusopay_id, and shows it as a QR /
// direct link for the wallet to accept.

declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

$name = $_GET['name'] ?? 'Ricardo Silva';
$lusopayId = $_GET['id'] ?? '76';
$email = $_GET['email'] ?? 'ricardo.silva@lusopay.com';

$preAuthCode = create_issuance_session(['name' => $name, 'lusopay_id' => $lusopayId, 'email' => $email]);

$credentialOffer = [
    'credential_issuer' => LUSOPAY_ISSUER_URL,
    'credential_configuration_ids' => ['lusopay-card'],
    'grants' => [
        'urn:ietf:params:oauth:grant-type:pre-authorized_code' => [
            'pre-authorized_code' => $preAuthCode,
        ],
    ],
];

$offerUri = 'openid-credential-offer://?credential_offer=' . rawurlencode(json_encode($credentialOffer, JSON_UNESCAPED_SLASHES));
$qrCodeDataUrl = qr_code_data_uri($offerUri);
?>
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>LusoPay - Emitir cartão</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f2f4f7; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1c2333; }
  .card { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(20,30,60,0.08); padding: 28px; text-align: center; }
  img { width: 220px; height: 220px; border-radius: 12px; border: 1px solid #eef0f3; }
  label { display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin: 10px 0 6px; text-align: left; }
  input { width: 100%; border: 1px solid #d8dce3; border-radius: 8px; padding: 8px 10px; font-size: 0.9rem; }
  form { margin-top: 16px; text-align: left; }
  button { width: 100%; border: none; border-radius: 10px; padding: 12px; font-size: 0.9rem; font-weight: 700; cursor: pointer; margin-top: 14px; background: #6d28d9; color: #fff; }
  .btn-open { display: block; background: #059669; color: #fff; font-weight: 700; padding: 14px; border-radius: 10px; text-decoration: none; margin: 16px 0; }
  .hint { font-size: 0.8rem; color: #6b7280; margin-top: 12px; word-break: break-all; }
</style>
</head>
<body>
  <div class="card">
    <h1>💳 Emitir LusoPay Card</h1>
    <p>Nome: <strong><?= escape_html($name) ?></strong> · ID: <strong><?= escape_html($lusopayId) ?></strong> · Email: <strong><?= escape_html($email) ?></strong></p>

    <a class="btn-open" href="<?= escape_html($offerUri) ?>">📱 Adicionar à Carteira Digital</a>
    <img src="<?= $qrCodeDataUrl ?>" alt="QR" />
    <p class="hint">Ou digitaliza este QR de outro dispositivo.</p>

    <form method="get">
      <label for="name">Nome</label>
      <input type="text" id="name" name="name" value="<?= escape_html($name) ?>" />
      <label for="id">LusoPay ID</label>
      <input type="text" id="id" name="id" value="<?= escape_html($lusopayId) ?>" />
      <label for="email">Email</label>
      <input type="text" id="email" name="email" value="<?= escape_html($email) ?>" />
      <button type="submit">Gerar nova oferta</button>
    </form>
  </div>
</body>
</html>
