// lusopay-checkout.js
// The actual "LusoPay Checkout" service. Any site integrates with it just by
// redirecting the buyer to GET /checkout with a few query parameters -- no
// SDK, no API keys, no CORS to deal with, since it's a full page redirect.
// This service owns starting the LusoPay backend (router, authorizing
// party, cyclos mock), building the transaction_data + holder binding proof,
// showing the QR / wallet-confirmation step, and sending the proof package
// through the same chain used by merchant.js. What happens with the payment
// afterwards (storing it, paying out the final beneficiary) is out of scope
// here -- that already happens on the receiving end of authorizing-party.js's
// Cyclos/LusoPay notification.

const express = require('express')
const crypto = require('crypto')
const path = require('path')
const net = require('net')
const { spawn } = require('child_process')
const QRCode = require('qrcode')

const PORT = 4004
const ROUTER_PORT = 4001
const AUTHORIZING_PARTY_PORT = 4002
const CYCLOS_MOCK_PORT = 4003
const ROUTER_URL = `http://localhost:${ROUTER_PORT}/proof-package`

const pendingCheckouts = new Map()

function log(message) {
  console.log(`[LUSOPAY-CHECKOUT] ${message}`)
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

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
}

function renderCheckoutPage({ checkoutId, amount, currency, description, merchant, publicId }) {
  return `<!doctype html>
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
        <div class="row"><span>A pagar a</span><span>${escapeHtml(merchant)}</span></div>
        <div class="row"><span>Descrição</span><span>${escapeHtml(description)}</span></div>
        <div class="row total"><span>Total</span><span>€ ${escapeHtml(amount)}</span></div>
      </div>

      <label for="publicId">O seu Public ID (identifica o seu banco)</label>
      <input type="text" id="publicId" class="full" value="${escapeHtml(publicId)}" />
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
  const CHECKOUT_ID = ${JSON.stringify(checkoutId)}
  const startView = document.getElementById('startView')
  const qrView = document.getElementById('qrView')
  const resultView = document.getElementById('resultView')
  const returnRow = document.getElementById('returnRow')
  const payButton = document.getElementById('payButton')
  const confirmButton = document.getElementById('confirmButton')
  const qrSpinner = document.getElementById('qrSpinner')
  const qrImage = document.getElementById('qrImage')

  payButton.addEventListener('click', async () => {
    const publicId = document.getElementById('publicId').value
    payButton.disabled = true
    payButton.textContent = 'A gerar pedido de pagamento...'

    try {
      const response = await fetch('/api/checkout/' + CHECKOUT_ID + '/start', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ publicId }),
      })
      const data = await response.json()
      if (!response.ok) throw new Error(data.error || 'falha ao iniciar o pagamento')

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

    let result
    try {
      const response = await fetch('/api/checkout/' + CHECKOUT_ID + '/confirm', { method: 'POST' })
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

    if (result.return_url) {
      const url = new URL(result.return_url)
      url.searchParams.set('status', result.status)
      if (result.transaction_id) url.searchParams.set('transaction_id', result.transaction_id)
      returnRow.classList.remove('hidden')
      returnRow.innerHTML = '<button class="btn-return" id="returnButton">Voltar a ${escapeHtml(merchant)}</button>'
      document.getElementById('returnButton').addEventListener('click', () => {
        window.location.href = url.toString()
      })
    }
  })
</script>
</body>
</html>`
}

const app = express()
app.use(express.json())

app.get('/checkout', (req, res) => {
  const { amount, currency, description, publicId, merchant, return_url: returnUrl } = req.query

  if (!amount || Number.isNaN(Number(amount))) {
    return res.status(400).send('parâmetro "amount" é obrigatório e deve ser numérico')
  }

  const checkoutId = crypto.randomUUID()
  pendingCheckouts.set(checkoutId, {
    amount: Number(amount).toFixed(2),
    currency: currency || 'EUR',
    description: description || 'Compra online',
    merchant: merchant || 'Loja parceira',
    returnUrl: returnUrl || null,
  })

  const pending = pendingCheckouts.get(checkoutId)
  res.type('html').send(renderCheckoutPage({ checkoutId, ...pending, publicId: publicId || '' }))
})

app.post('/api/checkout/:checkoutId/start', async (req, res) => {
  const pending = pendingCheckouts.get(req.params.checkoutId)
  if (!pending) return res.status(404).json({ error: 'checkout não encontrado ou já expirado' })

  const { publicId } = req.body ?? {}
  if (!publicId) return res.status(400).json({ error: 'publicId é obrigatório' })

  const transactionData = {
    type: 'urn:eudi:sca:payment:1',
    transaction_id: `txn_${crypto.randomUUID()}`,
    payee: pending.merchant,
    amount: pending.amount,
    currency: pending.currency,
  }

  const holderBindingProof = {
    jti: crypto.randomUUID(),
    amr: ['knowledge_pin', 'inherence_biometric'],
    transaction_data_hash: crypto.createHash('sha256').update(JSON.stringify(transactionData)).digest('hex'),
  }

  pending.transactionId = transactionData.transaction_id
  pending.proofPackage = {
    user_id: publicId,
    request: { relying_party: pending.merchant, transaction_data: transactionData },
    presentation: { vp_token: `fake-vp-token-${crypto.randomUUID()}`, holder_binding_proof: holderBindingProof },
  }

  const qrPayload = `LUSOPAY-DEMO:${transactionData.transaction_id}:${transactionData.amount}:${transactionData.currency}`
  const qrCodeDataUrl = await QRCode.toDataURL(qrPayload, { margin: 1, width: 260 })

  log(
    `checkout ${req.params.checkoutId} iniciado: ${transactionData.transaction_id} (${transactionData.amount} ${transactionData.currency}) para publicId="${publicId}" -- lojista: ${pending.merchant}`
  )

  res.json({ transactionId: transactionData.transaction_id, qrCodeDataUrl })
})

app.post('/api/checkout/:checkoutId/confirm', async (req, res) => {
  const pending = pendingCheckouts.get(req.params.checkoutId)
  if (!pending || !pending.proofPackage) {
    return res.status(404).json({ status: 'REJECTED', errors: ['checkout não encontrado ou ainda não iniciado'] })
  }

  log(`wallet confirmou -- a enviar proof package ao LusoPay Router (${pending.transactionId})`)

  try {
    const response = await fetch(ROUTER_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(pending.proofPackage),
    })
    const result = await response.json()
    pendingCheckouts.delete(req.params.checkoutId)

    log(`resultado: ${result.status} (${pending.transactionId})`)
    res.status(response.status).json({ ...result, return_url: pending.returnUrl })
  } catch (error) {
    log(`falha ao contactar o LusoPay Router: ${error.message}`)
    res.status(502).json({ status: 'REJECTED', errors: ['LusoPay Router inacessível'], return_url: pending.returnUrl })
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
  app.listen(PORT, () => log(`checkout disponível em http://localhost:${PORT}/checkout`))
}

main()
