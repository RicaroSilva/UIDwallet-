<?php
// pay-with-card-result.php
// Shows whatever api/pay-with-card-response.php stored for this session:
// the name + lusopay_id read off the presented LusoPay Card. This is the
// hand-off point -- build the rest of the payment on top of this data
// (e.g. poll ?format=json from your own backend instead of loading this
// page, or copy the same lookup call).

declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

$session = $_GET['session'] ?? '';
$result = ($session !== '' && ctype_alnum(str_replace(['-', '_'], '', $session)))
    ? get_card_presentation($session)
    : null;

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    echo json_encode($result ?? ['status' => 'PENDING']);
    exit;
}
?>
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta http-equiv="refresh" content="2" />
<title>LusoPay - Dados do cartão</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f2f4f7; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1c2333; }
  .card { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(20,30,60,0.08); padding: 28px; text-align: center; }
  .row { display: flex; justify-content: space-between; font-size: 0.9rem; margin: 8px 0; padding: 8px 0; border-bottom: 1px solid #eef0f3; text-align: left; }
  .status { padding: 16px; border-radius: 12px; margin-bottom: 16px; }
  .status.ok { background: #ecfdf5; color: #065f46; }
  .status.pending { background: #fef9c3; color: #854d0e; }
  .status.fail { background: #fef2f2; color: #991b1b; }
</style>
</head>
<body>
  <div class="card">
    <h1>💳 Dados do LusoPay Card</h1>
    <?php if ($result === null): ?>
      <div class="status pending"><p>⏳ Ainda à espera da resposta da wallet... (esta página atualiza-se sozinha)</p></div>
    <?php elseif ($result['status'] === 'OK'): ?>
      <div class="status ok"><p>✅ Dados recebidos e assinatura do emissor válida.</p></div>
      <div class="row"><span>Nome</span><strong><?= escape_html((string) $result['name']) ?></strong></div>
      <div class="row"><span>LusoPay ID</span><strong><?= escape_html((string) $result['lusopay_id']) ?></strong></div>
    <?php else: ?>
      <div class="status fail"><p>❌ <?= escape_html($result['error'] ?? $result['status']) ?></p></div>
    <?php endif; ?>
    <p style="font-size:0.75rem;color:#9ca3af;">JSON: <a href="?session=<?= escape_html($session) ?>&format=json">pay-with-card-result.php?session=<?= escape_html($session) ?>&format=json</a></p>
  </div>
</body>
</html>
