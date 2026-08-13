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
  independentes, antes de autorizar ou rejeitar. Depois de autorizar, notifica
  o backend LusoPay/Cyclos (ver abaixo).
- **`cyclos-mock.js`** — servidor Express local que imita a rota
  `POST /web/run/adduidpayment` do backend Cyclos real, para se poder testar
  essa notificação sem depender de rede externa.
- **`storefront.js`** — página web simples (carrinho com 1 produto) para
  disparar o fluxo interativamente, em vez de pela linha de comandos. Ver
  secção "Loja (interface visual)" abaixo.

Os serviços de backend comunicam por HTTP simples em `localhost` — sem
HTTPS, sem exposição externa. `cyclos-mock.js` e `storefront.js` usam
Express (dependências do projeto).

## Correr (linha de comandos)

```bash
node merchant.js
```

Este comando arranca automaticamente o `lusopay-router.js` (porta 4001), o
`authorizing-party.js` (porta 4002) e o `cyclos-mock.js` (porta 4003) como
processos filho, espera que fiquem disponíveis, e depois envia o pedido de
pagamento. Os logs de todos aparecem no mesmo terminal, prefixados por papel
(`[MERCHANT]`, `[LUSOPAY-ROUTER]`, `[AUTHORIZING-PARTY]`, `[CYCLOS-MOCK]`).

## Loja (interface visual)

```bash
node storefront.js
```

Abre `http://localhost:4000` no browser. Mostra um carrinho com 1 produto
onde podes editar o nome, o valor e o teu Public ID (o `user_id` que o
`lusopay-router.js` usa para escolher o banco — usa `user-1001` ou
`user-2002`, os únicos presentes em `bank-registry.json`). Ao clicar em
"Pagar com LusoPay / Carteira Digital":

1. É gerado o `transaction_data` e a holder binding proof fictícia, e é
   mostrado um QR code (gerado localmente, sem depender de nenhum serviço
   externo) que simula o pedido de autorização OpenID4VP.
2. Como não há uma wallet real a integrar, o botão "Simular confirmação na
   Wallet" faz o papel do utilizador autenticar-se na wallet e devolver a
   prova — é aí que o proof package é enviado ao `lusopay-router.js`,
   seguindo exatamente o mesmo caminho do `merchant.js`.
3. O resultado final (autorizado ou rejeitado, com o motivo) aparece no
   carrinho.

Tal como `merchant.js`, o `storefront.js` arranca automaticamente o router,
o authorizing party e o cyclos mock.

## Notificação ao backend Cyclos

Depois de autorizar uma transação, `authorizing-party.js` faz um `POST` para
`CYCLOS_ENDPOINT` (constante no topo do ficheiro), atualmente definida para o
dev server real:

```
https://dev.lusopay.com:8444/web_dev/run/adduidpayment
```

com o corpo `{ publicId, amount, currency, description, transactionId }`
extraído do `transaction_data` já validado (`publicId` = `user_id` do proof
package). Este pedido é *best-effort*: se falhar (rede em baixo, dev server
inacessível), o erro é registado no terminal mas a decisão de autorização já
tomada não é revertida.

Nota: neste momento este endereço não está acessível a partir deste
ambiente (a ligação TLS é interrompida a meio do handshake). Se quiseres
testar a notificação localmente antes de apontar ao dev server real, muda
temporariamente `CYCLOS_ENDPOINT` em `authorizing-party.js` para
`http://localhost:4003/web/run/adduidpayment` — mas nota que o caminho da
rota no `cyclos-mock.js` é `/web/run/adduidpayment`, enquanto o dev server
real usa `/web_dev/run/adduidpayment` (prefixo diferente); confirma qual dos
dois é o correto antes de ligar a um ambiente real.

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
