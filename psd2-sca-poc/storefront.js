// storefront.js
// Example partner site: a 1-item cart where the buyer can edit the amount
// and product name. This is what integrating with LusoPay looks like from
// the OUTSIDE -- on "Pagar", it just redirects the browser to the
// lusopay-checkout.js service with a few query parameters. It never touches
// transaction_data, holder binding proofs, or the LusoPay backend directly;
// that all lives in lusopay-checkout.js.

const express = require('express')

const PORT = 4000
const LUSOPAY_CHECKOUT_URL = 'http://localhost:4004/checkout'

const PAGE_HTML = `<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>LusoMarket</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    background: #f2f4f7; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: #1c2333;
  }
  .card { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(20,30,60,0.08); padding: 28px; }
  h1 { font-size: 1.25rem; margin: 0 0 4px; }
  .subtitle { color: #6b7280; font-size: 0.85rem; margin: 0 0 20px; }
  .product { display: flex; align-items: center; gap: 14px; padding: 14px; background: #f8f9fb; border-radius: 12px; margin-bottom: 18px; }
  .product .icon { font-size: 2.2rem; flex-shrink: 0; }
  .product input[type="text"] { flex: 1; min-width: 0; font-weight: 600; font-size: 0.95rem; border: none; background: transparent; padding: 2px 0; }
  .product input[type="text"]:focus { outline: none; border-bottom: 1px dashed #9ca3af; }
  .price-box { display: flex; align-items: center; gap: 4px; white-space: nowrap; flex-shrink: 0; }
  .price-box span { color: #6b7280; font-weight: 600; }
  .price-box input[type="number"] { width: 80px; font-weight: 700; font-size: 0.95rem; border: 1px solid #d8dce3; border-radius: 8px; padding: 6px 8px; text-align: right; }
  .total-row { display: flex; justify-content: space-between; font-weight: 700; font-size: 1.05rem; margin: 20px 0; padding-top: 14px; border-top: 1px solid #eef0f3; }
  button { width: 100%; border: none; border-radius: 10px; padding: 13px; font-size: 0.95rem; font-weight: 700; cursor: pointer; }
  .btn-pay { background: #6d28d9; color: white; }
</style>
</head>
<body>
  <div class="card">
    <h1>🛒 LusoMarket</h1>
    <p class="subtitle">Exemplo de site parceiro LusoPay</p>

    <div class="product">
      <div class="icon">🎧</div>
      <input type="text" id="productName" value="Auscultadores BT" />
      <div class="price-box">
        <span>€</span>
        <input type="number" id="amount" step="0.01" min="0" value="129.90" />
      </div>
    </div>

    <div class="total-row">
      <span>Total</span>
      <span id="totalDisplay">€ 129.90</span>
    </div>

    <button id="payButton" class="btn-pay">Pagar com LusoPay / Carteira Digital</button>
  </div>

<script>
  document.getElementById('amount').addEventListener('input', (e) => {
    const value = parseFloat(e.target.value || '0')
    document.getElementById('totalDisplay').textContent = '€ ' + value.toFixed(2)
  })

  document.getElementById('payButton').addEventListener('click', () => {
    const productName = document.getElementById('productName').value
    const amount = document.getElementById('amount').value

    const params = new URLSearchParams({
      amount,
      currency: 'EUR',
      description: productName,
      merchant: 'LusoMarket Lda.',
      return_url: window.location.origin + '/thank-you',
    })

    window.location.href = ${JSON.stringify(LUSOPAY_CHECKOUT_URL)} + '?' + params.toString()
  })
</script>
</body>
</html>`

function renderThankYouPage(status, transactionId) {
  const ok = status === 'AUTHORIZED'
  return `<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>LusoMarket</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f2f4f7; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
  .card { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(20,30,60,0.08); padding: 28px; text-align: center; }
  .status { padding: 16px; border-radius: 12px; }
  .status.ok { background: #ecfdf5; color: #065f46; }
  .status.fail { background: #fef2f2; color: #991b1b; }
  a { display: block; margin-top: 16px; color: #6d28d9; font-weight: 600; text-decoration: none; }
</style>
</head>
<body>
  <div class="card">
    <h1>🛒 LusoMarket</h1>
    <div class="status ${ok ? 'ok' : 'fail'}">
      <h2>${ok ? '✅ Encomenda confirmada' : '❌ Pagamento não concluído'}</h2>
      ${transactionId ? `<p>Referência: ${transactionId}</p>` : ''}
    </div>
    <a href="/">← Voltar à loja</a>
  </div>
</body>
</html>`
}

const app = express()

app.get('/', (_req, res) => {
  res.type('html').send(PAGE_HTML)
})

app.get('/thank-you', (req, res) => {
  const { status, transaction_id: transactionId } = req.query
  res.type('html').send(renderThankYouPage(status, transactionId))
})

app.listen(PORT, () => console.log(`[STOREFRONT] loja disponível em http://localhost:${PORT}`))
