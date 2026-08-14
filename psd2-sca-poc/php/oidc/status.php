<?php
// oidc/status.php
// Polled by the JS on oidc/authorize.php while it waits for the wallet.
// Once oidc/wallet-callback.php has marked the session READY, hands back
// the full URL to redirect the browser back to Cyclos with (code + the
// original state) -- Cyclos never talks to this endpoint itself.

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$oidcSession = $_GET['oidc_session'] ?? '';
$session = ($oidcSession !== '' && ctype_alnum($oidcSession)) ? get_oidc_session($oidcSession) : null;

if ($session === null) {
    echo json_encode(['status' => 'PENDING']);
    exit;
}

if ($session['status'] !== 'READY') {
    echo json_encode(['status' => 'PENDING']);
    exit;
}

$redirectUrl = $session['redirect_uri']
    . (str_contains($session['redirect_uri'], '?') ? '&' : '?')
    . 'code=' . rawurlencode($session['code'])
    . '&state=' . rawurlencode($session['state']);

echo json_encode(['status' => 'READY', 'redirect_url' => $redirectUrl]);
