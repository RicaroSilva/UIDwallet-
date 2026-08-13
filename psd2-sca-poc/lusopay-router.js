// lusopay-router.js
// Mock "payment scheme" role: receives the proof package from the Relying
// Party (merchant), looks up which bank issued the user's credential, and
// forwards the proof package to that bank's Authorizing Party endpoint.

const http = require('http')
const fs = require('fs')
const path = require('path')

const PORT = 4001
const BANK_REGISTRY_PATH = path.join(__dirname, 'bank-registry.json')

function log(message) {
  console.log(`[LUSOPAY-ROUTER] ${message}`)
}

function readJsonBody(req) {
  return new Promise((resolve, reject) => {
    let raw = ''
    req.on('data', (chunk) => (raw += chunk))
    req.on('end', () => {
      try {
        resolve(raw ? JSON.parse(raw) : {})
      } catch (error) {
        reject(error)
      }
    })
    req.on('error', reject)
  })
}

function loadBankRegistry() {
  return JSON.parse(fs.readFileSync(BANK_REGISTRY_PATH, 'utf-8'))
}

const server = http.createServer(async (req, res) => {
  if (req.method !== 'POST' || req.url !== '/proof-package') {
    res.writeHead(404, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ error: 'not found' }))
    return
  }

  let proofPackage
  try {
    proofPackage = await readJsonBody(req)
  } catch (error) {
    res.writeHead(400, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ error: 'invalid JSON body' }))
    return
  }

  const { user_id: userId } = proofPackage
  const transactionId = proofPackage?.request?.transaction_data?.transaction_id ?? '(unknown)'

  log(`received proof package from Relying Party "${proofPackage?.request?.relying_party ?? '(unknown)'}"`)
  log(`  transaction_id : ${transactionId}`)
  log(`  user_id        : ${userId}`)

  const bankRegistry = loadBankRegistry()
  const bankEntry = bankRegistry[userId]

  if (!bankEntry) {
    log(`no bank found for user_id "${userId}" — rejecting`)
    res.writeHead(404, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ status: 'REJECTED', errors: [`unknown user_id "${userId}"`] }))
    return
  }

  log(`resolved user_id "${userId}" -> ${bankEntry.bank} (${bankEntry.endpoint})`)
  log(`forwarding proof package to Authorizing Party...`)

  try {
    const apResponse = await fetch(bankEntry.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(proofPackage),
    })
    const apResult = await apResponse.json()

    log(`Authorizing Party responded: ${apResult.status} (transaction ${transactionId})`)

    res.writeHead(apResponse.status, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ ...apResult, routed_via: 'LusoPay', bank: bankEntry.bank }))
  } catch (error) {
    log(`failed to reach Authorizing Party at ${bankEntry.endpoint}: ${error.message}`)
    res.writeHead(502, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ status: 'REJECTED', errors: ['authorizing party unreachable'] }))
  }
})

server.listen(PORT, () => log(`listening on http://localhost:${PORT}`))
