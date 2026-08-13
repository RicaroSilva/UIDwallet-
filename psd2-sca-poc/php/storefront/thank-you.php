<?php
// storefront/thank-you.php
// Where the buyer lands back on the partner site's own domain after
// LusoPay Checkout finishes, with the outcome in the query string.

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

$status = $_GET['status'] ?? '';
$transactionId = $_GET['transaction_id'] ?? '';
$ok = $status === 'AUTHORIZED';
?>
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>LusoMarket</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f2f4f7; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
  .card { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(20,30,60,0.08); padding: 28px; text-align: center; }
  .status { padding: 16px; border-radius: 12px; }
  .status.ok { background: #ecfdf5; color: #065f46; }
  .status.fail { background: #fef2f2; color: #991b1b; }
  a { display: block; margin-top: 16px; color: #6d28d9; font-weight: 600; text-decoration: none; }
</style>
</head>
<body>
  <div class="card">
    <h1>🛒 LusoMarket</h1>
    <div class="status <?= $ok ? 'ok' : 'fail' ?>">
      <h2><?= $ok ? '✅ Encomenda confirmada' : '❌ Pagamento não concluído' ?></h2>
      <?php if ($transactionId): ?>
        <p>Referência: <?= escape_html($transactionId) ?></p>
      <?php endif; ?>
    </div>
    <a href="index.php">← Voltar à loja</a>
  </div>
</body>
</html>
