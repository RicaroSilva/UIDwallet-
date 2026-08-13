// authorizing-party.js
// Mock "Authorizing Party" (the bank), per the PaSO role model:
// receives the proof package forwarded by the payment scheme (LusoPay Router),
// re-verifies it independently, and authorizes or rejects the transaction.

const http = require('http')
const crypto = require('crypto')

const PORT = 4002

function log(message) {
  console.log(`[AUTHORIZING-PARTY] ${message}`)
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

function hashTransactionData(transactionData) {
  return crypto.createHash('sha256').update(JSON.stringify(transactionData)).digest('hex')
}

function validateProofPackage(proofPackage) {
  const errors = []
  const transactionData = proofPackage?.request?.transaction_data
  const proof = proofPackage?.presentation?.holder_binding_proof

  if (!transactionData) errors.push('missing request.transaction_data')
  else {
    for (const field of ['type', 'transaction_id', 'payee', 'amount', 'currency']) {
      if (!transactionData[field]) errors.push(`missing transaction_data.${field}`)
    }
  }

  if (!proof) errors.push('missing presentation.holder_binding_proof')
  else {
    if (!proof.jti) errors.push('missing holder_binding_proof.jti')
    if (!Array.isArray(proof.amr) || proof.amr.length < 2) {
      errors.push('holder_binding_proof.amr must list at least two independent SCA factors')
    }
    if (!proof.transaction_data_hash) errors.push('missing holder_binding_proof.transaction_data_hash')
  }

  if (errors.length > 0) return { valid: false, errors }

  const recomputedHash = hashTransactionData(transactionData)
  if (recomputedHash !== proof.transaction_data_hash) {
    return {
      valid: false,
      errors: [
        `dynamic linking check failed: transaction_data_hash does not match transaction_data (expected ${recomputedHash}, got ${proof.transaction_data_hash})`,
      ],
    }
  }

  return { valid: true, errors: [] }
}

const server = http.createServer(async (req, res) => {
  if (req.method !== 'POST' || req.url !== '/authorize') {
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

  const transactionId = proofPackage?.request?.transaction_data?.transaction_id ?? '(unknown)'
  log(`received proof package for transaction ${transactionId}`)

  const { valid, errors } = validateProofPackage(proofPackage)

  if (!valid) {
    log(`REJECTED transaction ${transactionId}:`)
    for (const error of errors) log(`  - ${error}`)

    res.writeHead(422, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ status: 'REJECTED', transaction_id: transactionId, errors }))
    return
  }

  const { transaction_data: transactionData } = proofPackage.request
  const { holder_binding_proof: proof } = proofPackage.presentation

  log('--- registering transaction ---')
  log(`  transaction_id : ${transactionData.transaction_id}`)
  log(`  payee          : ${transactionData.payee}`)
  log(`  amount         : ${transactionData.amount} ${transactionData.currency}`)
  log(`  auth factors   : ${proof.amr.join(' + ')}`)
  log(`  jti            : ${proof.jti}`)
  log('  dynamic linking: OK (transaction_data_hash verified)')
  log('-------------------------------')
  log(`AUTHORIZED transaction ${transactionId}`)

  res.writeHead(200, { 'Content-Type': 'application/json' })
  res.end(
    JSON.stringify({
      status: 'AUTHORIZED',
      transaction_id: transactionId,
      authorized_at: new Date().toISOString(),
      authorizing_party: 'Authorizing Party Mock',
    })
  )
})

server.listen(PORT, () => log(`listening on http://localhost:${PORT}`))
