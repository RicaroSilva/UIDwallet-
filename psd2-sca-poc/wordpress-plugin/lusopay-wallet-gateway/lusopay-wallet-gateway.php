<?php
/**
 * Plugin Name: LusoPay Wallet Gateway
 * Description: Método de pagamento WooCommerce que identifica o cliente pela Carteira Digital LusoPay (EUDI Wallet) antes de autorizar o pagamento via Cyclos.
 * Version: 0.1.0
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
