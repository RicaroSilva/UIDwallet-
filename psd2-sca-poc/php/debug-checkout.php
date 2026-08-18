<?php
// debug-checkout.php
// DEV/TEST ONLY -- lets you inspect a checkout session and fake the
// wallet's answer (AUTHORIZED/REJECTED) without needing a real EUDI
// wallet or a working Cyclos connection. Use this to find out *where*
// a stuck popup is actually stuck:
//
//   - If marking it AUTHORIZED here makes the WooCommerce popup close
//     and the order go through: the problem is upstream of this file --
//     the wallet never actually reached api/checkout-response.php (no
//     real wallet scanned the QR yet, or the wallet/Cyclos side is
//     failing before it could call update_checkout_session()).
//   - If marking it AUTHORIZED here does NOT make the popup close: the
//     problem is downstream -- the polling chain between the WooCommerce
//     site and this session (admin-ajax proxy -> api/checkout-status.php)
//     is broken (wrong status_url, nonce mismatch, blocked request,
//     JS error, etc.), not the wallet/Cyclos side.
//
// Remove this file (or otherwise block access to it) before this
// deployment is anything other than a private test environment -- it
// lets anyone who finds the URL mark any session as a "paid" payment.

declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

$session = $_GET['session'] ?? $_POST['session'] ?? '';
$session = preg_match('/^[A-Za-z0-9_-]{8,64}$/', $session) ? $session : '';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $session !== '') {
    $action = $_POST['action'] ?? '';
    if ($action === 'authorize') {
        update_checkout_session($session, [
            'status' => 'AUTHORIZED',
            'transaction_id' => 'debug_' . bin2hex(random_bytes(8)),
            'lusopay_id' => 'debug-user',
            'name' => 'Debug User',
        ]);
        $message = 'Sessão marcada como AUTHORIZED.';
    } elseif ($action === 'reject') {
        update_checkout_session($session, [
            'status' => 'REJECTED',
            'errors' => ['rejeitado manualmente a partir da página de debug'],
        ]);
        $message = 'Sessão marcada como REJECTED.';
    } elseif ($action === 'reset') {
        update_checkout_session($session, ['status' => 'PENDING', 'errors' => null, 'transaction_id' => null]);
        $message = 'Sessão reposta como PENDING.';
    }
}

$data = $session !== '' ? get_checkout_session($session) : null;
?>
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>LusoPay - Debug checkout</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; padding: 24px; background: #f2f4f7; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1c2333; }
  .card { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(20,30,60,0.08); padding: 28px; }
  h1 { font-size: 1.1rem; margin: 0 0 4px; }
  .warn { background: #fef2f2; color: #991b1b; border-radius: 10px; padding: 10px 14px; font-size: 0.85rem; margin-bottom: 18px; }
  .msg { background: #ecfdf5; color: #065f46; border-radius: 10px; padding: 10px 14px; font-size: 0.85rem; margin-bottom: 18px; }
  label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 4px; }
  input[type=text] { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #d7dbe3; margin-bottom: 12px; font-size: 0.9rem; }
  pre { background: #0f172a; color: #d1e7ff; padding: 14px; border-radius: 10px; overflow-x: auto; font-size: 0.8rem; }
  .actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
  button { border: none; border-radius: 10px; padding: 10px 16px; font-size: 0.85rem; font-weight: 700; cursor: pointer; }
  .ok { background: #16a34a; color: #fff; }
  .fail { background: #dc2626; color: #fff; }
  .neutral { background: #e5e7eb; color: #374151; }
  form.inline { display: inline; }
  .hint { font-size: 0.8rem; color: #6b7280; margin-top: 4px; }
</style>
</head>
<body>
  <div class="card">
    <h1>🛠️ Debug: sessão de checkout</h1>
    <p class="warn">Só para desenvolvimento/teste -- nunca deixar acessível numa loja real.</p>

    <?php if ($message !== ''): ?>
      <p class="msg"><?= escape_html($message) ?></p>
    <?php endif; ?>

    <form method="get">
      <label for="session">Session ID</label>
      <input type="text" id="session" name="session" value="<?= escape_html($session) ?>" placeholder="copia o ?session=... da URL do popup" />
      <button type="submit" class="neutral">Ver sessão</button>
    </form>
    <p class="hint">O ID da sessão está na URL que o popup abriu (o parâmetro <code>session=</code>), tanto para o checkout.php normal como para o fluxo dos Blocks.</p>

    <?php if ($session !== '' && $data === null): ?>
      <p class="warn">Não existe nenhuma sessão com este ID (ainda não foi criada, ou já expirou/foi apagada).</p>
    <?php elseif ($data !== null): ?>
      <pre><?= escape_html(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
      <div class="actions">
        <form class="inline" method="post">
          <input type="hidden" name="session" value="<?= escape_html($session) ?>" />
          <input type="hidden" name="action" value="authorize" />
          <button type="submit" class="ok">✅ Simular AUTHORIZED</button>
        </form>
        <form class="inline" method="post">
          <input type="hidden" name="session" value="<?= escape_html($session) ?>" />
          <input type="hidden" name="action" value="reject" />
          <button type="submit" class="fail">❌ Simular REJECTED</button>
        </form>
        <form class="inline" method="post">
          <input type="hidden" name="session" value="<?= escape_html($session) ?>" />
          <input type="hidden" name="action" value="reset" />
          <button type="submit" class="neutral">↺ Repor PENDING</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
