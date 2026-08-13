# LusoPay Checkout — versão PHP

Port em PHP (8.1+) do protótipo Node.js em `../`. Mesma lógica de negócio
(dynamic linking, verificação de 2 fatores SCA, lookup de banco por
`publicId`, notificação best-effort ao backend Cyclos), mas pensado para
correr diretamente no IIS via FastCGI — sem precisar de manter um processo
Node.js em segundo plano nem de um proxy reverso.

## Diferença de arquitetura em relação à versão Node.js

A versão Node.js tinha processos separados e persistentes para cada papel
(`lusopay-router.js`, `authorizing-party.js`, `cyclos-mock.js`), com estado
em memória entre pedidos. Em PHP, cada pedido HTTP é independente (sem
memória partilhada entre pedidos por defeito), por isso o desenho é
diferente:

- **Sem estado no servidor.** Entre o passo "gerar QR" e o passo "confirmar
  pagamento", é o **browser** que guarda o `transaction_data` e a
  `holder_binding_proof` recebidos (em variáveis JS), e reenvia-os no passo
  de confirmação. Isto evita sessões/base de dados e funciona bem mesmo com
  vários processos PHP-FPM em paralelo.
- **Um único ficheiro de lógica** (`lib/functions.php`) junta o que antes
  eram `lusopay-router.js` (lookup do banco) e `authorizing-party.js`
  (validação de campos, dynamic linking) numa função `authorize_payment()`.
  A separação em papéis fica documentada em comentários, não em processos.

## Estrutura

- `checkout.php` — a página de checkout (equivalente a `lusopay-checkout.js`).
- `api/checkout-start.php` — constrói `transaction_data` + prova, gera o QR.
- `api/checkout-confirm.php` — revalida, consulta `bank-registry.json`,
  autoriza/rejeita, notifica o Cyclos.
- `storefront/` — exemplo de site parceiro (equivalente ao `storefront.js`).
- `lib/functions.php` — toda a lógica partilhada.
- `bank-registry.json` — mesmo registo de bancos de teste.

## Testar localmente

```bash
composer install
php -S localhost:8000
```

Abre `http://localhost:8000/storefront/index.php`.

## Publicar no IIS (Windows Server)

1. Instala o **PHP** (via IIS + Microsoft's PHP Manager, ou XAMPP/similar) e
   ativa o módulo FastCGI no IIS para ficheiros `.php`.
2. Corre `composer install --no-dev -o` na pasta `php/` no servidor.
3. Cria um site no IIS Manager, com esta pasta (`php/`) como raiz, e
   `Default Document` a incluir `checkout.php` se quiseres que a raiz do
   site aponte diretamente para lá.
4. Usa o `win-acme` para emitir um certificado real para o domínio
   (ex.: `services.lusopay.com`) e associá-lo ao binding HTTPS do site.
5. Define a variável de ambiente `LUSOPAY_CHECKOUT_URL` no IIS (App Pool ou
   `web.config`) se o `storefront/` correr num site diferente do
   `checkout.php`, apontando para o URL público completo (ex.:
   `https://services.lusopay.com/checkout.php`).

Não precisas de PM2, ARR, nem proxy reverso — o IIS serve os `.php`
diretamente via FastCGI.

## Nota

Tal como a versão Node.js, isto é uma simulação: `transaction_data` e
`holder_binding_proof` são fictícios, sem OpenID4VP/OpenID4VCI real. O QR
gerado é só visual (não usa o esquema `openid4vp://`, para não confundir
wallets reais como a Paradym).
