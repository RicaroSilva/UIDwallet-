// cyclos-mock.js
// Local mock of the LusoPay/Cyclos backend, exposing the same
// `/web/run/adduidpayment` shape as the real dev server, so the
// authorizing-party -> Cyclos notification step can be exercised locally
// without depending on external network access.

const express = require('express')

const PORT = 4003
const app = express()
app.use(express.json())

function log(message) {
  console.log(`[CYCLOS-MOCK] ${message}`)
}

app.post('/web/run/adduidpayment', (req, res) => {
  log('received payment request:')
  for (const [key, value] of Object.entries(req.body)) {
    log(`  ${key.padEnd(14)}: ${value}`)
  }
  res.status(200).json({ status: 'received' })
})

app.listen(PORT, () => log(`listening on http://localhost:${PORT}`))
