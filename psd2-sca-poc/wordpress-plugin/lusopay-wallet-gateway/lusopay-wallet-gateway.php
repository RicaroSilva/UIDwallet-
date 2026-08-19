<?php
/**
 * Plugin Name: LusoPay Wallet Gateway
 * Description: Método de pagamento WooCommerce que identifica o cliente pela Carteira Digital LusoPay (EUDI Wallet) antes de autorizar o pagamento via Cyclos.
 * Version: 0.1.3
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    require_once __DIR__ . '/includes/class-wc-gateway-lusopay-wallet.php';
});

add_filter('woocommerce_payment_gateways', function (array $gateways): array {
    $gateways[] = 'WC_Gateway_LusoPay_Wallet';
    return $gateways;
});

// Declares this gateway as compatible with the Cart & Checkout blocks --
// without this WooCommerce shows a "may affect the shopping experience"
// warning and hides it from the block-based checkout entirely.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    require_once __DIR__ . '/includes/class-wc-gateway-lusopay-wallet-blocks-support.php';
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function ($payment_method_registry) {
            $payment_method_registry->register(new WC_Gateway_LusoPay_Wallet_Blocks_Support());
        }
    );
});

// Polled by the "Ligar com a Carteira Digital" button on the gateway's
// own settings screen (see generate_connect_html() in the gateway
// class). Admin-only: forwards server-to-server to
// pay-with-card-result.php?format=json, same-origin here so no CORS
// setup is needed there.
add_action('wp_ajax_lusopay_wallet_connect_status', 'lusopay_wallet_connect_status');

function lusopay_wallet_connect_status(): void
{
    if (!current_user_can('manage_woocommerce') || !check_ajax_referer('lusopay_wallet_connect', 'nonce', false)) {
        wp_send_json(['status' => 'ERROR'], 403);
    }

    $session = isset($_GET['session']) ? sanitize_text_field(wp_unslash($_GET['session'])) : '';
    // Reads through the gateway's own properties (not get_option()
    // directly) so this benefits from the same "fall back to the form
    // field's default when the saved value is blank" logic its
    // constructor already applies -- see class-wc-gateway-lusopay-wallet.php.
    $gateway = new WC_Gateway_LusoPay_Wallet();
    $resultUrl = $gateway->connect_result_url;

    if ($session === '' || $resultUrl === '') {
        wp_send_json(['status' => 'PENDING']);
    }

    $response = wp_remote_get(add_query_arg(['session' => $session, 'format' => 'json'], $resultUrl), ['timeout' => 10]);
    if (is_wp_error($response)) {
        wp_send_json(['status' => 'PENDING']);
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    wp_send_json($data ?: ['status' => 'PENDING']);
}

// Polled by the Blocks checkout payment method
// (assets/js/lusopay-wallet-blocks.js) while its onPaymentSetup()
// callback holds the actual WooCommerce order back, waiting to see
// whether the wallet authorized the payment. Public/nopriv because
// checkout is open to guests -- it only ever proxies a read of one
// session's status (same pattern as lusopay_wallet_connect_status
// above), nothing sensitive.
add_action('wp_ajax_lusopay_wallet_checkout_status', 'lusopay_wallet_checkout_status');
add_action('wp_ajax_nopriv_lusopay_wallet_checkout_status', 'lusopay_wallet_checkout_status');

function lusopay_wallet_checkout_status(): void
{
    if (!check_ajax_referer('lusopay_wallet_checkout', 'nonce', false)) {
        wp_send_json(['status' => 'ERROR'], 403);
    }

    $session = isset($_GET['session']) ? sanitize_text_field(wp_unslash($_GET['session'])) : '';
    if ($session === '' || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $session)) {
        wp_send_json(['status' => 'PENDING']);
    }

    // Same as above: read through the gateway's own $status_url property
    // (already defaulted in its constructor) instead of get_option()
    // directly, so a blank saved setting can't silently make every poll
    // report PENDING forever without ever actually checking.
    $gateway = new WC_Gateway_LusoPay_Wallet();
    $statusUrl = $gateway->status_url;
    if ($statusUrl === '') {
        wp_send_json(['status' => 'PENDING']);
    }

    $response = wp_remote_get(add_query_arg('session', $session, $statusUrl), ['timeout' => 10]);
    if (is_wp_error($response)) {
        wp_send_json(['status' => 'PENDING']);
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    wp_send_json($data ?: ['status' => 'PENDING']);
}
