# PSD2 / EUDI Wallet — SCA Payment Flow (local simulation)

Simulação local do fluxo *third-party requested* descrito na spec PaSO
(Payments and SCA for OpenID), com três papéis:

- **`merchant.js`** — Relying Party. Constrói um `transaction_data` de
  pagamento (`urn:eudi:sca:payment:1`) e uma "holder binding proof" fictícia
  (como se viesse da wallet), e envia o proof package resultante ao router.
- **`lusopay-router.js`** — papel de payment scheme. Recebe o proof package,
  consulta `bank-registry.json` para descobrir o endpoint do banco do
  utilizador, e reencaminha o pedido.
- **`authorizing-party.js`** — mock do banco. Valida os campos obrigatórios,
  recalcula o hash do `transaction_data` (verificação de dynamic linking) e
  confirma que existem pelo menos dois fatores de autenticação
  independentes, antes de autorizar ou rejeitar.

Os três comunicam por HTTP simples em `localhost` — sem HTTPS, sem
exposição externa, sem dependências além do Node.js.

## Correr

```bash
node merchant.js
```

Este comando arranca automaticamente o `lusopay-router.js` (porta 4001) e o
`authorizing-party.js` (porta 4002) como processos filho, espera que fiquem
disponíveis, e depois envia o pedido de pagamento. Os logs de todos os três
aparecem no mesmo terminal, prefixados por papel
(`[MERCHANT]`, `[LUSOPAY-ROUTER]`, `[AUTHORIZING-PARTY]`).

## Cenários de falha

```bash
node merchant.js --tamper     # adultera o amount depois do hash ser calculado -> falha na verificação de dynamic linking
node merchant.js --weak-auth  # usa só 1 fator de autenticação -> falha no requisito de SCA (2 fatores)
node merchant.js --bad-user   # user_id desconhecido -> o router não encontra banco
```

## Nota

Isto é uma simulação didática dos dados e da lógica de validação — não usa
credenciais reais, assinaturas criptográficas, OpenID4VP nem OpenID4VCI.
Serve para desenhar e testar os contratos de dados entre os três serviços
antes de qualquer integração real com uma wallet EUDI.
