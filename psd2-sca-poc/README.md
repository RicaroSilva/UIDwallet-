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
- **`lusopay-checkout.js`** — o serviço LusoPay Checkout em si: qualquer site
  integra-se com ele só redirecionando o comprador para
  `GET /checkout?amount=...&publicId=...&...`, sem SDK nem API keys. Arranca
  o router, o authorizing party e o cyclos mock, mostra o QR e a confirmação
  da wallet, e envia o proof package pelo mesmo caminho do `merchant.js`.
- **`storefront.js`** — exemplo de site parceiro (carrinho com 1 produto). Ao
  clicar em "Pagar", só redireciona o browser para o `lusopay-checkout.js` —
  não sabe nada sobre `transaction_data`, provas, ou o backend LusoPay.

Os serviços de backend comunicam por HTTP simples em `localhost` — sem
HTTPS, sem exposição externa. `cyclos-mock.js`, `storefront.js` e
`lusopay-checkout.js` usam Express (dependências do projeto).

## Correr (linha de comandos)

```bash
node merchant.js
```

Este comando arranca automaticamente o `lusopay-router.js` (porta 4001), o
`authorizing-party.js` (porta 4002) e o `cyclos-mock.js` (porta 4003) como
processos filho, espera que fiquem disponíveis, e depois envia o pedido de
pagamento. Os logs de todos aparecem no mesmo terminal, prefixados por papel
(`[MERCHANT]`, `[LUSOPAY-ROUTER]`, `[AUTHORIZING-PARTY]`, `[CYCLOS-MOCK]`).

## Loja (interface visual, multi-site)

Precisas de **dois processos** separados (o serviço LusoPay, e o site
parceiro de exemplo):

```bash
# terminal 1 -- o serviço LusoPay (backend + página de checkout)
node lusopay-checkout.js

# terminal 2 -- um site parceiro qualquer que o integra
node storefront.js
```

Abre `http://localhost:4000` no browser (a loja). Ao clicar em "Pagar com
LusoPay / Carteira Digital":

1. O browser é **redirecionado** para
   `http://localhost:4004/checkout?amount=...&description=...&merchant=...&return_url=...`
   — é assim que qualquer site se integra: sem SDK, sem API key, só um
   redirecionamento com estes parâmetros na query string.
2. Na página do LusoPay, escreves o teu **Public ID** (`user-1001` ou
   `user-2002`, os únicos presentes em `bank-registry.json`) e clicas em
   pagar. É gerado o `transaction_data` e a holder binding proof fictícia, e
   aparece um QR code (gerado localmente) que simula o pedido de autorização
   OpenID4VP.
3. Como não há uma wallet real a integrar, o botão "Simular confirmação na
   Wallet" faz o papel do utilizador a autenticar-se e devolver a prova — é
   aí que o proof package é enviado ao `lusopay-router.js`, seguindo
   exatamente o mesmo caminho do `merchant.js`.
4. O resultado aparece na página do LusoPay, com um botão "Voltar a
   `<lojista>`" que reencaminha o browser de volta ao `return_url` que o
   site parceiro indicou, com o resultado (`status`, `transaction_id`) na
   query string — é assim que o site parceiro sabe o desfecho, sem
   precisares de bases de dados nem webhooks.

Nota importante: o QR gerado é apenas um **elemento visual** (uma string sem
significado de protocolo) — não uses o esquema `openid4vp://` nele. Uma
wallet real (como a Paradym) reconhece esse esquema e tenta processar o
conteúdo como um pedido genuíno de OpenID4VP, falhando porque faltam campos
obrigatórios (`response_uri`, assinatura, etc.). Construir um QR realmente
funcional exigiria um verificador OpenID4VP completo exposto em HTTPS
pública — fora do âmbito desta simulação local.

Só `lusopay-checkout.js` arranca o router, o authorizing party e o cyclos
mock; `storefront.js` não tem nenhuma dependência do backend LusoPay.

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
