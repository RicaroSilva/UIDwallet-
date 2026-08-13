<?php
// view-log.php
// EXPERIMENTAL: plain-text viewer for wallet-response.log, so we can see
// what the wallet actually sent back without needing RDP/file explorer.

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$logFile = __DIR__ . '/wallet-response.log';
echo file_exists($logFile) ? file_get_contents($logFile) : '(vazio - ainda sem respostas da wallet)';
