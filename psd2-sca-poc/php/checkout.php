<?php
// checkout.php
// The LusoPay Checkout page itself. Any site integrates just by redirecting
// the buyer here with query parameters -- no SDK, no API key, no CORS,
// since it's a plain page redirect (same "hosted checkout" pattern as
// Stripe Checkout / PayPal).

declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

$amount = $_GET['amount'] ?? null;
$currency = $_GET['currency'] ?? 'EUR';
$description = $_GET['description'] ?? 'Compra online';
$merchant = $_GET['merchant'] ?? 'Loja parceira';
$publicId = $_GET['publicId'] ?? '';
$returnUrl = $_GET['return_url'] ?? '';

if ($amount === null || !is_numeric($amount)) {
    http_response_code(400);
    echo 'parâmetro "amount" é obrigatório e deve ser numérico';
    exit;
}
$amount = number_format((float) $amount, 2, '.', '');
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
  label { display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin: 4px 0 6px; }
  .hint { font-size: 0.72rem; color: #9ca3af; margin: 4px 0 0; }
  input[type="text"].full { width: 100%; border: 1px solid #d8dce3; border-radius: 8px; padding: 10px 12px; font-size: 0.9rem; }
  button { width: 100%; border: none; border-radius: 10px; padding: 13px; font-size: 0.95rem; font-weight: 700; cursor: pointer; margin-top: 16px; }
  .btn-pay { background: #6d28d9; color: white; }
  .btn-pay:disabled { background: #c4b5fd; cursor: not-allowed; }
  .btn-confirm { background: #059669; color: white; }
  .btn-return { background: #e5e7eb; color: #374151; }
  .panel { text-align: center; }
  .panel img { width: 220px; height: 220px; border-radius: 12px; border: 1px solid #eef0f3; }
  .panel .caption { font-size: 0.85rem; color: #4b5563; margin: 12px 0 0; }
  .status { padding: 16px; border-radius: 12px; margin-top: 16px; text-align: center; }
  .status.ok { background: #ecfdf5; color: #065f46; }
  .status.fail { background: #fef2f2; color: #991b1b; }
  .status h2 { margin: 0 0 6px; font-size: 1.05rem; }
  .status p { margin: 2px 0; font-size: 0.85rem; }
  .hidden { display: none; }
  .spinner { width: 22px; height: 22px; margin: 10px auto; border: 3px solid #ddd6fe; border-top-color: #6d28d9; border-radius: 50%; animation: spin 0.8s linear infinite; }
  @keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
  <div class="card">
    <h1>🔒 LusoPay Checkout</h1>
    <p class="subtitle">Pagamento seguro via Carteira Digital</p>

    <div id="startView">
      <div class="summary">
        <div class="row"><span>A pagar a</span><span><?= escape_html($merchant) ?></span></div>
        <div class="row"><span>Descrição</span><span><?= escape_html($description) ?></span></div>
        <div class="row total"><span>Total</span><span>€ <?= escape_html($amount) ?></span></div>
      </div>

      <label for="publicId">O seu Public ID (identifica o seu banco)</label>
      <input type="text" id="publicId" class="full" value="<?= escape_html($publicId) ?>" />
      <p class="hint">IDs de teste conhecidos pelo LusoPay: user-1001 (Banco Lusitano), user-2002 (Caixa Real)</p>

      <button id="payButton" class="btn-pay">Pagar com LusoPay / Carteira Digital</button>
    </div>

    <div id="qrView" class="panel hidden">
      <div class="spinner" id="qrSpinner"></div>
      <img id="qrImage" class="hidden" />
      <p class="caption">Digitalize este código com a sua <strong>Carteira Digital LusoPay</strong> para autorizar o pagamento.</p>
      <button id="confirmButton" class="btn-confirm hidden">🔓 Simular confirmação na Wallet</button>
    </div>

    <div id="resultView" class="hidden"></div>
    <div id="returnRow" class="hidden"></div>
  </div>

<script>
  const MERCHANT = <?= json_encode($merchant) ?>;
  const AMOUNT = <?= json_encode($amount) ?>;
  const CURRENCY = <?= json_encode($currency) ?>;
  const DESCRIPTION = <?= json_encode($description) ?>;
  const RETURN_URL = <?= json_encode($returnUrl) ?>;

  const startView = document.getElementById('startView')
  const qrView = document.getElementById('qrView')
  const resultView = document.getElementById('resultView')
  const returnRow = document.getElementById('returnRow')
  const payButton = document.getElementById('payButton')
  const confirmButton = document.getElementById('confirmButton')
  const qrSpinner = document.getElementById('qrSpinner')
  const qrImage = document.getElementById('qrImage')

  let pendingTransactionData = null
  let pendingHolderBindingProof = null

  payButton.addEventListener('click', async () => {
    const publicId = document.getElementById('publicId').value
    payButton.disabled = true
    payButton.textContent = 'A gerar pedido de pagamento...'

    try {
      const response = await fetch('api/checkout-start.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ merchant: MERCHANT, amount: AMOUNT, currency: CURRENCY }),
      })
      const data = await response.json()
      if (!response.ok) throw new Error(data.error || 'falha ao iniciar o pagamento')

      pendingTransactionData = data.transactionData
      pendingHolderBindingProof = data.holderBindingProof

      startView.classList.add('hidden')
      qrView.classList.remove('hidden')
      qrSpinner.classList.add('hidden')
      qrImage.src = data.qrCodeDataUrl
      qrImage.classList.remove('hidden')
      confirmButton.classList.remove('hidden')
    } catch (error) {
      alert('Erro ao iniciar pagamento: ' + error.message)
      payButton.disabled = false
      payButton.textContent = 'Pagar com LusoPay / Carteira Digital'
    }
  })

  confirmButton.addEventListener('click', async () => {
    confirmButton.disabled = true
    confirmButton.textContent = 'A validar na Wallet...'

    const publicId = document.getElementById('publicId').value
    let result
    try {
      const response = await fetch('api/checkout-confirm.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          transactionData: pendingTransactionData,
          holderBindingProof: pendingHolderBindingProof,
          publicId,
        }),
      })
      result = await response.json()
    } catch (error) {
      result = { status: 'REJECTED', errors: [error.message] }
    }

    qrView.classList.add('hidden')
    resultView.classList.remove('hidden')

    if (result.status === 'AUTHORIZED') {
      resultView.innerHTML =
        '<div class="status ok"><h2>✅ Pagamento autorizado</h2>' +
        '<p>Transação: ' + result.transaction_id + '</p>' +
        '<p>Banco: ' + (result.bank || '-') + '</p></div>'
    } else {
      resultView.innerHTML =
        '<div class="status fail"><h2>❌ Pagamento rejeitado</h2>' +
        '<p>' + (result.errors || []).join('<br/>') + '</p></div>'
    }

    if (RETURN_URL) {
      const url = new URL(RETURN_URL)
      url.searchParams.set('status', result.status)
      if (result.transaction_id) url.searchParams.set('transaction_id', result.transaction_id)
      returnRow.classList.remove('hidden')
      returnRow.innerHTML = '<button class="btn-return" id="returnButton">Voltar a ' + MERCHANT + '</button>'
      document.getElementById('returnButton').addEventListener('click', () => {
        window.location.href = url.toString()
      })
    }
  })
</script>
</body>
</html>
