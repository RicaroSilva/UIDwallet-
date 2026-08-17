<?php
// class-wc-gateway-lusopay-wallet.php
// WooCommerce payment gateway backed by LusoPay's hosted checkout.php
// (which asks the EUDI wallet to present the LusoPay Card and calls
// Cyclos to move the money). Rather than sending the buyer off to that
// page, render_qr_receipt() fetches it server-to-server on WooCommerce's
// own "Pay for order" page and inlines its QR there, so the QR shows up
// immediately, on this site, with no redirect. It then re-verifies the
// outcome itself (server-to-server, via checkout-status.php) before
// marking the order paid -- never trusting a browser-supplied "status="
// query param on its own, since that's attacker-controlled.

defined('ABSPATH') || exit;

class WC_Gateway_LusoPay_Wallet extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'lusopay_wallet';
        $this->has_fields = false;
        $this->method_title = 'LusoPay Wallet';
        $this->method_description = 'Identifica o cliente pela Carteira Digital LusoPay (EUDI Wallet) e autoriza o pagamento via Cyclos.';
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->checkout_url = $this->get_option('checkout_url');
        $this->status_url = $this->get_option('status_url');
        $this->public_id = $this->get_option('public_id');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'verify_payment_on_thankyou']);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'render_qr_receipt']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => 'Ativar/Desativar',
                'type' => 'checkbox',
                'label' => 'Ativar LusoPay Wallet',
                'default' => 'yes',
            ],
            'title' => [
                'title' => 'Título',
                'type' => 'text',
                'description' => 'Mostrado ao cliente no checkout.',
                'default' => 'Pagar com a Carteira Digital LusoPay',
                'desc_tip' => true,
            ],
            'description' => [
                'title' => 'Descrição',
                'type' => 'textarea',
                'default' => 'Vais ser encaminhado para confirmar o pagamento com a tua Carteira Digital.',
            ],
            'checkout_url' => [
                'title' => 'URL do checkout LusoPay',
                'type' => 'text',
                'default' => 'https://pay.lusopay.com/uidwallettest/checkout.php',
                'description' => 'Página que mostra o QR e trata do pagamento.',
                'desc_tip' => true,
            ],
            'status_url' => [
                'title' => 'URL de estado do checkout',
                'type' => 'text',
                'default' => 'https://pay.lusopay.com/uidwallettest/api/checkout-status.php',
                'description' => 'Usado para confirmar o resultado do lado do servidor, sem confiar apenas no redirecionamento do browser.',
                'desc_tip' => true,
            ],
            'public_id' => [
                'title' => 'Public ID (LusoPay)',
                'type' => 'text',
                'description' => 'O identificador LusoPay/Cyclos desta loja -- é para esta conta que o dinheiro é enviado em cada pagamento.',
                'desc_tip' => true,
            ],
        ];
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice('Encomenda inválida.', 'error');
            return ['result' => 'failure'];
        }

        if ($this->public_id === '') {
            wc_add_notice('O método de pagamento LusoPay Wallet não está configurado (falta o Public ID da loja).', 'error');
            return ['result' => 'failure'];
        }

        // Deliberately NOT changing the order status here: WooCommerce's
        // "Pay for order" page only shows the payment (our QR receipt)
        // for orders still needing payment ($order->needs_payment(),
        // true only for "pending"/"failed"). Moving to "on-hold" here
        // made it show "não pode ser paga" instead of the QR --
        // verify_payment_on_thankyou() sets the real final status
        // ("failed" if the wallet rejects it, paid via payment_complete()
        // if it doesn't) once the wallet has actually answered.

        // Stays on this site: WooCommerce's own "Pay for order" page,
        // where woocommerce_receipt_{id} (render_qr_receipt below) shows
        // the QR immediately instead of sending the buyer to checkout_url.
        return [
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        ];
    }

    private function build_checkout_params(\WC_Order $order): array
    {
        return [
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'description' => sprintf('Encomenda #%s', $order->get_order_number()),
            'merchant' => get_bloginfo('name'),
            'merchant_public_id' => $this->public_id,
            'return_url' => add_query_arg('lusopay_order', $order->get_id(), $this->get_return_url($order)),
        ];
    }

    /**
     * Opens checkout_url in a popup (PayPal-style) instead of embedding
     * it -- no server-to-server fetch/scraping needed, checkout.php's own
     * existing QR + polling + auto-redirect-to-return_url just runs
     * as-is inside that window. When it reaches return_url it's landed on
     * this same WordPress site, so window.opener works from there to
     * bring the original tab along (see verify_payment_on_thankyou()).
     */
    public function render_qr_receipt($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $checkoutPageUrl = $this->checkout_url . '?' . http_build_query($this->build_checkout_params($order));
        ?>
        <div id="lusopay-wallet-popup-wrap" style="max-width:320px;margin:24px auto;text-align:center;">
            <p>A abrir o pagamento numa nova janela...</p>
            <p><a href="<?php echo esc_url($checkoutPageUrl); ?>" target="_blank">Clica aqui se a janela não abriu</a></p>
        </div>
        <script>
        (function () {
            var url = <?php echo wp_json_encode($checkoutPageUrl); ?>
            var popup = window.open(url, 'lusopay_wallet_popup', 'width=440,height=760')
            if (!popup) {
                return // blocked by the browser -- the fallback link above still works
            }
            var interval = setInterval(function () {
                if (popup.closed) {
                    clearInterval(interval)
                    window.location.reload()
                }
            }, 1000)
        })()
        </script>
        <?php
    }

    /**
     * Runs when the buyer lands back on WooCommerce's order-received page.
     * checkout.php appends "status" and "lusopay_session" to the redirect
     * it sends the buyer to -- "status" is informational only; the actual
     * decision comes from calling checkout-status.php ourselves.
     */
    public function verify_payment_on_thankyou($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if (!$order->is_paid()) {
            $session = isset($_GET['lusopay_session']) ? sanitize_text_field(wp_unslash($_GET['lusopay_session'])) : '';
            if ($session !== '') {
                $response = wp_remote_get(add_query_arg('session', $session, $this->status_url), ['timeout' => 10]);
                if (is_wp_error($response)) {
                    $order->update_status('failed', 'Não foi possível confirmar o pagamento LusoPay Wallet: ' . $response->get_error_message());
                } else {
                    $data = json_decode(wp_remote_retrieve_body($response), true);
                    $status = $data['status'] ?? null;
                    if ($status === 'AUTHORIZED') {
                        $order->payment_complete($data['transaction_id'] ?? '');
                        $order->add_order_note('Pago via LusoPay Wallet (transação ' . ($data['transaction_id'] ?? '?') . ').');
                    } elseif ($status === 'REJECTED') {
                        $order->update_status('failed', 'Pagamento LusoPay Wallet rejeitado: ' . implode(', ', $data['errors'] ?? []));
                    }
                    // Any other status (still PENDING) is left alone --
                    // the wallet hasn't answered checkout.php yet.
                }
            }
        }
        ?>
        <script>
        // Reached inside the payment popup render_qr_receipt() opened --
        // bring the original tab to this same page and close the popup.
        if (window.opener && !window.opener.closed) {
            window.opener.location.href = window.location.href
            window.close()
        }
        </script>
        <?php
    }
}
