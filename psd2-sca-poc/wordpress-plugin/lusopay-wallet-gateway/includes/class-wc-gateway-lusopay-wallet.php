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
     * Fetches checkout.php server-to-server and inlines its QR + wallet
     * link directly on this page, instead of sending the buyer there.
     * Scrapes checkout.php's markup for the pieces we need -- fragile if
     * that page's HTML changes, but avoids modifying it for this.
     */
    public function render_qr_receipt($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $checkoutPageUrl = $this->checkout_url . '?' . http_build_query($this->build_checkout_params($order));
        $response = wp_remote_get($checkoutPageUrl, ['timeout' => 15]);

        if (is_wp_error($response)) {
            echo '<p>Não foi possível preparar o pagamento LusoPay Wallet: ' . esc_html($response->get_error_message()) . '</p>';
            return;
        }

        $html = wp_remote_retrieve_body($response);
        preg_match('/id="qrImage"\s+src="([^"]+)"/', $html, $qrMatch);
        preg_match('/class="btn-open"\s+href="([^"]+)"/', $html, $linkMatch);
        preg_match('/const SESSION = "([a-f0-9]+)"/', $html, $sessionMatch);

        if (empty($qrMatch[1]) || empty($sessionMatch[1])) {
            echo '<p>Não foi possível preparar o QR do LusoPay Wallet. Tenta novamente.</p>';
            return;
        }

        $qrSrc = $qrMatch[1];
        $walletLink = $linkMatch[1] ?? '';
        $session = $sessionMatch[1];
        $returnUrl = $this->get_return_url($order);
        ?>
        <div id="lusopay-wallet-receipt" style="max-width:320px;margin:24px auto;text-align:center;">
            <p><?php echo esc_html($this->description ?: 'Digitaliza o código com a tua Carteira Digital LusoPay.'); ?></p>
            <img src="<?php echo esc_attr($qrSrc); ?>" alt="QR" style="width:220px;height:220px;" />
            <?php if ($walletLink !== ''): ?>
                <p><a href="<?php echo esc_attr($walletLink); ?>">Abrir na Carteira Digital neste telemóvel</a></p>
            <?php endif; ?>
            <p id="lusopay-wallet-status">A aguardar confirmação...</p>
        </div>
        <script>
        (function () {
            var statusEl = document.getElementById('lusopay-wallet-status')
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>
            var session = <?php echo wp_json_encode($session); ?>
            var returnUrl = <?php echo wp_json_encode($returnUrl); ?>

            function poll() {
                fetch(ajaxUrl + '?action=lusopay_wallet_status&session=' + encodeURIComponent(session))
                    .then(function (r) { return r.json() })
                    .then(function (data) {
                        if (data.status === 'AUTHORIZED' || data.status === 'REJECTED') {
                            statusEl.textContent = data.status === 'AUTHORIZED' ? 'Pago, a voltar...' : 'Pagamento rejeitado.'
                            var sep = returnUrl.indexOf('?') === -1 ? '?' : '&'
                            window.location.href = returnUrl + sep + 'lusopay_session=' + encodeURIComponent(session)
                            return
                        }
                        setTimeout(poll, 2000)
                    })
                    .catch(function () { setTimeout(poll, 2000) })
            }

            poll()
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
        if (!$order || $order->is_paid()) {
            return;
        }

        $session = isset($_GET['lusopay_session']) ? sanitize_text_field(wp_unslash($_GET['lusopay_session'])) : '';
        if ($session === '') {
            return;
        }

        $response = wp_remote_get(add_query_arg('session', $session, $this->status_url), ['timeout' => 10]);
        if (is_wp_error($response)) {
            $order->update_status('failed', 'Não foi possível confirmar o pagamento LusoPay Wallet: ' . $response->get_error_message());
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $status = $data['status'] ?? null;

        if ($status === 'AUTHORIZED') {
            $order->payment_complete($data['transaction_id'] ?? '');
            $order->add_order_note('Pago via LusoPay Wallet (transação ' . ($data['transaction_id'] ?? '?') . ').');
        } elseif ($status === 'REJECTED') {
            $order->update_status('failed', 'Pagamento LusoPay Wallet rejeitado: ' . implode(', ', $data['errors'] ?? []));
        }
        // Any other status (e.g. still PENDING) is left as "on-hold" --
        // the buyer landed here before checkout.php actually resolved it.
    }
}
