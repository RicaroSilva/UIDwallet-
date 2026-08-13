// merchant.js
// Mock "Relying Party" (merchant): builds a payment transaction_data payload
// (urn:eudi:sca:payment:1) plus a fictitious wallet holder binding proof,
// then sends the resulting proof package to the LusoPay Router.
//
// For convenience this single entry point also starts the other two
// services (lusopay-router.js and authorizing-party.js) as child processes,
// so the whole three-party flow can be observed with just `node merchant.js`.
// They still talk to each other over real localhost HTTP, exactly as three
// independently deployed services would.

const { spawn } = require('child_process')
const path = require('path')
const net = require('net')
const crypto = require('crypto')

const ROUTER_URL = 'http://localhost:4001/proof-package'
const ROUTER_PORT = 4001
const AUTHORIZING_PARTY_PORT = 4002

const args = process.argv.slice(2)
const scenario = {
  tamperAmountAfterSigning: args.includes('--tamper'),
  weakAuthFactors: args.includes('--weak-auth'),
  unknownUser: args.includes('--bad-user'),
}

function log(message) {
  console.log(`[MERCHANT] ${message}`)
}

function spawnService(scriptName, label) {
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

  child.on('exit', (code) => {
    if (code !== null && code !== 0) {
      console.error(`[MERCHANT] ${label} exited unexpectedly with code ${code}`)
    }
  })

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

function buildTransactionData() {
  return {
    type: 'urn:eudi:sca:payment:1',
    transaction_id: `txn_${crypto.randomUUID()}`,
    payee: 'LusoMarket Lda.',
    amount: '129.90',
    currency: 'EUR',
  }
}

function buildHolderBindingProof(transactionData) {
  const amr = scenario.weakAuthFactors
    ? ['knowledge_pin']
    : ['knowledge_pin', 'inherence_biometric']

  return {
    jti: crypto.randomUUID(),
    amr,
    transaction_data_hash: crypto.createHash('sha256').update(JSON.stringify(transactionData)).digest('hex'),
  }
}

async function main() {
  log('starting LusoPay Router and Authorizing Party mock...')
  const router = spawnService('lusopay-router.js', 'lusopay-router.js')
  const authorizingParty = spawnService('authorizing-party.js', 'authorizing-party.js')

  const shutdown = () => {
    router.kill()
    authorizingParty.kill()
  }
  process.on('SIGINT', () => {
    shutdown()
    process.exit(1)
  })

  try {
    await Promise.all([waitForPort(ROUTER_PORT), waitForPort(AUTHORIZING_PARTY_PORT)])
  } catch (error) {
    log(`services failed to start: ${error.message}`)
    shutdown()
    process.exit(1)
  }

  log('services are up')
  log('')

  const transactionData = buildTransactionData()
  const holderBindingProof = buildHolderBindingProof(transactionData)

  if (scenario.tamperAmountAfterSigning) {
    log('!! simulating tampering: changing amount AFTER the holder binding proof was produced !!')
    transactionData.amount = '999999.00'
  }

  const userId = scenario.unknownUser ? 'user-9999' : 'user-1001'

  const proofPackage = {
    user_id: userId,
    request: {
      relying_party: 'LusoMarket Lda.',
      transaction_data: transactionData,
    },
    presentation: {
      vp_token: `fake-vp-token-${crypto.randomUUID()}`,
      holder_binding_proof: holderBindingProof,
    },
  }

  log('built payment transaction_data:')
  console.log(JSON.stringify(transactionData, null, 2))
  log('built fictitious holder binding proof (as if produced by the wallet):')
  console.log(JSON.stringify(holderBindingProof, null, 2))
  log('')
  log(`sending proof package to LusoPay Router at ${ROUTER_URL}...`)
  log('')

  try {
    const response = await fetch(ROUTER_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(proofPackage),
    })
    const result = await response.json()

    log('')
    log(`final result (HTTP ${response.status}):`)
    console.log(JSON.stringify(result, null, 2))

    if (result.status === 'AUTHORIZED') log('>> payment AUTHORIZED <<')
    else log('>> payment REJECTED <<')
  } catch (error) {
    log(`request to LusoPay Router failed: ${error.message}`)
  } finally {
    shutdown()
  }
}

main()
