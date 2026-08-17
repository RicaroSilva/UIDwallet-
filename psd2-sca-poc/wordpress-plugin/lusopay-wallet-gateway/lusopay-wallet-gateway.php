<?php
/**
 * Plugin Name: LusoPay Wallet Gateway
 * Description: Método de pagamento WooCommerce que identifica o cliente pela Carteira Digital LusoPay (EUDI Wallet) antes de autorizar o pagamento via Cyclos.
 * Version: 0.1.1
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
    $settings = get_option('woocommerce_lusopay_wallet_settings', []);
    $resultUrl = $settings['connect_result_url'] ?? '';

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
