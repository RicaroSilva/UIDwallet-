<?php
// class-wc-gateway-lusopay-wallet-blocks-support.php
// Registers this gateway with the newer WooCommerce Blocks checkout (Cart
// & Checkout blocks). Without this, WooCommerce shows "LusoPay Wallet
// ainda não suporta este bloco" and hides the method there entirely --
// the classic WC_Payment_Gateway API (class-wc-gateway-lusopay-wallet.php)
// only covers the legacy shortcode checkout.

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Gateway_LusoPay_Wallet_Blocks_Support extends AbstractPaymentMethodType
{
    protected $name = 'lusopay_wallet';
    private $gateway;

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_lusopay_wallet_settings', []);
        $this->gateway = new WC_Gateway_LusoPay_Wallet();
    }

    public function is_active(): bool
    {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles(): array
    {
        $pluginRoot = dirname(__DIR__);
        wp_register_script(
            'wc-lusopay-wallet-blocks',
            plugins_url('assets/js/lusopay-wallet-blocks.js', $pluginRoot . '/lusopay-wallet-gateway.php'),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'],
            '0.1.2',
            true
        );
        return ['wc-lusopay-wallet-blocks'];
    }

    public function get_payment_method_data(): array
    {
        return [
            'title' => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            // The gateway's constructor already falls back to the form
            // field's default when the saved option is blank -- read
            // through it instead of get_option()/get_setting() directly
            // so that fallback applies here too.
            'checkoutUrl' => $this->gateway->checkout_url,
            'merchantPublicId' => $this->gateway->public_id,
            'merchant' => get_bloginfo('name'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lusopay_wallet_checkout'),
        ];
    }
}
