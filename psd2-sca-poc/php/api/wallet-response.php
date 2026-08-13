<?php
// api/wallet-response.php
// EXPERIMENTAL: receives whatever the wallet posts back (direct_post),
// without verifying anything yet. Just logs it so we can inspect the raw
// shape and iterate. Always acks with an empty JSON object, as OpenID4VP's
// direct_post response mode expects.

declare(strict_types=1);

$logFile = __DIR__ . '/../wallet-response.log';
$session = $_GET['session'] ?? 'unknown';
$body = file_get_contents('php://input');
$contentType = $_SERVER['CONTENT_TYPE'] ?? '(none)';

$entry = "\n=== " . date('c') . " session={$session} content-type={$contentType} ===\n";
$entry .= 'POST fields: ' . json_encode($_POST, JSON_PRETTY_PRINT) . "\n";
$entry .= "Raw body:\n{$body}\n";

file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
echo json_encode([]);
