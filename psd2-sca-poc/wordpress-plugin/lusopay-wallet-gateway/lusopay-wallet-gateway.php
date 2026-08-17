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
