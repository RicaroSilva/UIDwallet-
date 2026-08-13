// storefront.js
// Simple web storefront (Relying Party UI): a 1-item cart where the buyer
// can edit the amount and their Public ID, then "pay" with LusoPay /
// Carteira Digital. Simulates the OpenID4VP QR flow -- a QR stands in for
// the authorization request, and a button stands in for the wallet's
// confirmation -- before the resulting proof package is sent through the
// same LusoPay Router -> Authorizing Party chain used by merchant.js.

const express = require('express')
const crypto = require('crypto')
const path = require('path')
const net = require('net')
const { spawn } = require('child_process')
const QRCode = require('qrcode')

const PORT = 4000
const ROUTER_PORT = 4001
const AUTHORIZING_PARTY_PORT = 4002
const CYCLOS_MOCK_PORT = 4003
const ROUTER_URL = `http://localhost:${ROUTER_PORT}/proof-package`

const pendingPayments = new Map()

function log(message) {
  console.log(`[STOREFRONT] ${message}`)
}

function spawnService(scriptName) {
  const child = spawn('node', [path.join(__dirname, scriptName)], { stdio: ['ignore', 'pipe', 'pipe'] })
  const forward = (stream) => {
    stream.on('data', (chunk) => {
      for (const line of chunk.toString().split('\n')) {
        if (line.trim().length > 0) console.log(line)
      }
    })
  }
  forward(child.stdout)
  forward(child.stderr)
  return child
}

function waitForPort(port, timeoutMs = 5000) {
  const deadline = Date.now() + timeoutMs
  return new Promise((resolve, reject) => {
    const tryConnect = () => {
      const socket = net.connect(port, 'localhost')
      socket.once('connect', () => {
        socket.end()
        resolve()
      })
      socket.once('error', () => {
        socket.destroy()
        if (Date.now() > deadline) reject(new Error(`timed out waiting for port ${port}`))
        else setTimeout(tryConnect, 100)
      })
    }
    tryConnect()
  })
}

const PAGE_HTML = `<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>LusoMarket</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f2f4f7;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: #1c2333;
  }
  .card {
    width: 100%;
    max-width: 420px;
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 8px 24px rgba(20, 30, 60, 0.08);
    padding: 28px;
  }
  h1 { font-size: 1.25rem; margin: 0 0 4px; }
  .subtitle { color: #6b7280; font-size: 0.85rem; margin: 0 0 20px; }
  .product {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px;
    background: #f8f9fb;
    border-radius: 12px;
    margin-bottom: 18px;
  }
  .product .icon { font-size: 2.2rem; flex-shrink: 0; }
  .product input[type="text"] {
    flex: 1;
    min-width: 0;
    font-weight: 600;
    font-size: 0.95rem;
    border: none;
    background: transparent;
    padding: 2px 0;
  }
  .product input[type="text"]:focus { outline: none; border-bottom: 1px dashed #9ca3af; }
  .price-box { display: flex; align-items: center; gap: 4px; white-space: nowrap; flex-shrink: 0; }
  .price-box span { color: #6b7280; font-weight: 600; }
  .price-box input[type="number"] {
    width: 80px;
    font-weight: 700;
    font-size: 0.95rem;
    border: 1px solid #d8dce3;
    border-radius: 8px;
    padding: 6px 8px;
    text-align: right;
  }
  label { display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin: 14px 0 6px; }
  .hint { font-size: 0.72rem; color: #9ca3af; margin: 4px 0 0; }
  input[type="text"].full, input[type="number"].full {
    width: 100%;
    border: 1px solid #d8dce3;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.9rem;
  }
  .total-row {
    display: flex;
    justify-content: space-between;
    font-weight: 700;
    font-size: 1.05rem;
    margin: 20px 0;
    padding-top: 14px;
    border-top: 1px solid #eef0f3;
  }
  button {
    width: 100%;
    border: none;
    border-radius: 10px;
    padding: 13px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
  }
  .btn-pay { background: #6d28d9; color: white; }
  .btn-pay:disabled { background: #c4b5fd; cursor: not-allowed; }
  .btn-confirm { background: #059669; color: white; margin-top: 14px; }
  .btn-reset { background: #e5e7eb; color: #374151; margin-top: 14px; }
  .panel { text-align: center; margin-top: 18px; }
  .panel img { width: 220px; height: 220px; border-radius: 12px; border: 1px solid #eef0f3; }
  .panel .caption { font-size: 0.85rem; color: #4b5563; margin: 12px 0 0; }
  .status { padding: 16px; border-radius: 12px; margin-top: 16px; text-align: center; }
  .status.ok { background: #ecfdf5; color: #065f46; }
  .status.fail { background: #fef2f2; color: #991b1b; }
  .status h2 { margin: 0 0 6px; font-size: 1.05rem; }
  .status p { margin: 2px 0; font-size: 0.85rem; }
  .hidden { display: none; }
  .spinner {
    width: 22px; height: 22px; margin: 10px auto;
    border: 3px solid #ddd6fe; border-top-color: #6d28d9;
    border-radius: 50%; animation: spin 0.8s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
  <div class="card">
    <h1>🛒 LusoMarket</h1>
    <p class="subtitle">Checkout de demonstração — PSD2 / EUDI Wallet SCA</p>

    <div id="cartView">
      <div class="product">
        <div class="icon">🎧</div>
        <input type="text" id="productName" class="full" value="Auscultadores BT" />
        <div class="price-box">
          <span>€</span>
          <input type="number" id="amount" step="0.01" min="0" value="129.90" />
        </div>
      </div>

      <label for="publicId">O seu Public ID (identifica o seu banco)</label>
      <input type="text" id="publicId" class="full" value="user-1001" />
      <p class="hint">IDs de teste conhecidos pelo LusoPay: user-1001 (Banco Lusitano), user-2002 (Caixa Real)</p>

      <div class="total-row">
        <span>Total</span>
        <span id="totalDisplay">€ 129.90</span>
      </div>

      <button id="payButton" class="btn-pay">Pagar com LusoPay / Carteira Digital</button>
    </div>

    <div id="qrView" class="panel hidden">
      <div class="spinner" id="qrSpinner"></div>
      <img id="qrImage" class="hidden" />
      <p class="caption">Digitalize este código com a sua <strong>Carteira Digital LusoPay</strong> para autorizar o pagamento.</p>
      <button id="confirmButton" class="btn-confirm hidden">🔓 Simular confirmação na Wallet</button>
    </div>

    <div id="resultView" class="hidden"></div>

    <button id="resetButton" class="btn-reset hidden">Nova compra</button>
  </div>

<script>
  const cartView = document.getElementById('cartView')
  const qrView = document.getElementById('qrView')
  const resultView = document.getElementById('resultView')
  const resetButton = document.getElementById('resetButton')
  const payButton = document.getElementById('payButton')
  const confirmButton = document.getElementById('confirmButton')
  const qrSpinner = document.getElementById('qrSpinner')
  const qrImage = document.getElementById('qrImage')

  document.getElementById('amount').addEventListener('input', (e) => {
    const value = parseFloat(e.target.value || '0')
    document.getElementById('totalDisplay').textContent = '€ ' + value.toFixed(2)
  })

  let currentTransactionId = null

  payButton.addEventListener('click', async () => {
    const productName = document.getElementById('productName').value
    const amount = document.getElementById('amount').value
    const publicId = document.getElementById('publicId').value

    payButton.disabled = true
    payButton.textContent = 'A gerar pedido de pagamento...'

    try {
      const response = await fetch('/api/checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ productName, amount, currency: 'EUR', publicId }),
      })
      const data = await response.json()

      if (!response.ok) throw new Error(data.error || 'falha ao iniciar o pagamento')

      currentTransactionId = data.transactionId
      cartView.classList.add('hidden')
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

    try {
      const response = await fetch('/api/checkout/' + currentTransactionId + '/confirm', { method: 'POST' })
      const result = await response.json()

      qrView.classList.add('hidden')
      resultView.classList.remove('hidden')

      if (result.status === 'AUTHORIZED') {
        resultView.innerHTML =
          '<div class="status ok"><h2>✅ Pagamento autorizado</h2>' +
          '<p>Transação: ' + result.transaction_id + '</p>' +
          '<p>Banco: ' + (result.bank || '-') + '</p>' +
          '<p>Autorizado em: ' + result.authorized_at + '</p></div>'
      } else {
        resultView.innerHTML =
          '<div class="status fail"><h2>❌ Pagamento rejeitado</h2>' +
          '<p>' + (result.errors || []).join('<br/>') + '</p></div>'
      }
    } catch (error) {
      resultView.classList.remove('hidden')
      resultView.innerHTML = '<div class="status fail"><h2>❌ Erro</h2><p>' + error.message + '</p></div>'
    } finally {
      resetButton.classList.remove('hidden')
    }
  })

  resetButton.addEventListener('click', () => window.location.reload())
</script>
</body>
</html>`

const app = express()
app.use(express.json())

app.get('/', (_req, res) => {
  res.type('html').send(PAGE_HTML)
})

app.post('/api/checkout', async (req, res) => {
  const { productName, amount, currency, publicId } = req.body ?? {}

  if (!amount || !publicId) {
    return res.status(400).json({ error: 'amount e publicId são obrigatórios' })
  }

  const transactionData = {
    type: 'urn:eudi:sca:payment:1',
    transaction_id: `txn_${crypto.randomUUID()}`,
    payee: 'LusoMarket Lda.',
    amount: Number(amount).toFixed(2),
    currency: currency || 'EUR',
  }

  const holderBindingProof = {
    jti: crypto.randomUUID(),
    amr: ['knowledge_pin', 'inherence_biometric'],
    transaction_data_hash: crypto.createHash('sha256').update(JSON.stringify(transactionData)).digest('hex'),
  }

  const proofPackage = {
    user_id: publicId,
    request: { relying_party: 'LusoMarket Lda.', transaction_data: transactionData },
    presentation: { vp_token: `fake-vp-token-${crypto.randomUUID()}`, holder_binding_proof: holderBindingProof },
  }

  pendingPayments.set(transactionData.transaction_id, proofPackage)

  // Deliberately NOT an "openid4vp://" URI: real wallet apps (e.g. Paradym)
  // register that scheme and will try to process this as a genuine
  // authorization request, then reject it since it's missing required
  // fields. This QR is a visual stand-in only -- it carries no protocol
  // meaning, just a demo reference string.
  const qrPayload = `LUSOPAY-DEMO:${transactionData.transaction_id}:${transactionData.amount}:${transactionData.currency}`
  const qrCodeDataUrl = await QRCode.toDataURL(qrPayload, { margin: 1, width: 260 })

  log(
    `checkout iniciado: ${transactionData.transaction_id} (${transactionData.amount} ${transactionData.currency}) para publicId="${publicId}" (produto: "${productName}")`
  )

  res.json({ transactionId: transactionData.transaction_id, qrCodeDataUrl })
})

app.post('/api/checkout/:transactionId/confirm', async (req, res) => {
  const proofPackage = pendingPayments.get(req.params.transactionId)
  if (!proofPackage) {
    return res.status(404).json({ status: 'REJECTED', errors: ['transação não encontrada ou já processada'] })
  }
  pendingPayments.delete(req.params.transactionId)

  log(`wallet confirmou -- a enviar proof package ao LusoPay Router (${req.params.transactionId})`)

  try {
    const response = await fetch(ROUTER_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(proofPackage),
    })
    const result = await response.json()
    log(`resultado: ${result.status} (${req.params.transactionId})`)
    res.status(response.status).json(result)
  } catch (error) {
    log(`falha ao contactar o LusoPay Router: ${error.message}`)
    res.status(502).json({ status: 'REJECTED', errors: ['LusoPay Router inacessível'] })
  }
})

async function main() {
  log('a arrancar LusoPay Router, Authorizing Party e Cyclos mock...')
  const router = spawnService('lusopay-router.js')
  const authorizingParty = spawnService('authorizing-party.js')
  const cyclosMock = spawnService('cyclos-mock.js')

  const shutdown = () => {
    router.kill()
    authorizingParty.kill()
    cyclosMock.kill()
  }
  process.on('SIGINT', () => {
    shutdown()
    process.exit(0)
  })

  try {
    await Promise.all([
      waitForPort(ROUTER_PORT),
      waitForPort(AUTHORIZING_PARTY_PORT),
      waitForPort(CYCLOS_MOCK_PORT),
    ])
  } catch (error) {
    log(`serviços de apoio falharam ao arrancar: ${error.message}`)
    shutdown()
    process.exit(1)
  }

  log('serviços de apoio prontos')
  app.listen(PORT, () => log(`loja disponível em http://localhost:${PORT}`))
}

main()
