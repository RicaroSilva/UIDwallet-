<?php
// class-wc-gateway-lusopay-wallet.php
// WooCommerce payment gateway backed by LusoPay's hosted checkout.php
// (which asks the EUDI wallet to present the LusoPay Card and calls
// Cyclos to move the money). render_qr_receipt() opens checkout.php in a
// popup (PayPal-style) as soon as WooCommerce's own "Pay for order" page
// loads, and the buyer's own checkout/cart page is left alone until the
// outcome is known. It re-verifies that outcome itself (server-to-server,
// via checkout-status.php) before marking the order paid -- never
// trusting a browser-supplied "status=" query param on its own, since
// that's attacker-controlled.

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
        $this->connect_url = $this->get_option('connect_url');
        $this->connect_result_url = $this->get_option('connect_result_url');

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
                'description' => 'O identificador LusoPay/Cyclos desta loja -- é para esta conta que o dinheiro é enviado em cada pagamento. Podes preenchê-lo à mão ou usar "Ligar com a Carteira Digital" abaixo.',
                'desc_tip' => true,
            ],
            'connect' => [
                'title' => 'Ligar a conta LusoPay',
                'type' => 'connect',
            ],
            'connect_url' => [
                'title' => 'URL para ligar a carteira',
                'type' => 'text',
                'default' => 'https://pay.lusopay.com/uidwallettest/pay-with-card.php',
                'description' => 'Página que pede o LusoPay Card de quem liga a conta (usada pelo botão "Ligar com a Carteira Digital").',
                'desc_tip' => true,
            ],
            'connect_result_url' => [
                'title' => 'URL do resultado da ligação',
                'type' => 'text',
                'default' => 'https://pay.lusopay.com/uidwallettest/pay-with-card-result.php',
                'desc_tip' => true,
            ],
        ];
    }

    /**
     * Custom WooCommerce settings field type: a button that opens
     * connect_url in a popup (the same LusoPay Card OpenID4VP flow
     * pay-with-card.php already serves for other purposes) so the store
     * owner can associate their own account's Public ID by scanning it
     * with their wallet, instead of typing it in by hand. Pre-generates
     * the session id here so the JS can poll for it without needing to
     * scrape it out of the popup's page.
     *
     * The raw "public_id" text field still exists (it's what actually
     * gets saved/read as the option) but is hidden from view here --
     * this row is the only thing the store owner needs to see, and it
     * always reflects whatever is currently saved, not just this page
     * load's connect attempt.
     */
    public function generate_connect_html(): string
    {
        $session = wp_generate_password(24, false, false);
        $connectUrl = $this->connect_url . '?session=' . rawurlencode($session);
        $connected = $this->public_id !== '';
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">Conta LusoPay</th>
            <td class="forminp">
                <p id="lusopay-wallet-connect-status" style="margin:0 0 10px;">
                    <?php if ($connected): ?>
                        ✅ Ligado como <strong><?php echo esc_html($this->public_id); ?></strong>
                    <?php else: ?>
                        ⚠️ Conta ainda não ligada.
                    <?php endif; ?>
                </p>
                <button type="button" class="button" id="lusopay-wallet-connect-btn"><?php echo $connected ? 'Ligar a outra conta' : 'Ligar com a Carteira Digital'; ?></button>
                <script>
                (function () {
                    var btn = document.getElementById('lusopay-wallet-connect-btn');
                    var statusEl = document.getElementById('lusopay-wallet-connect-status');
                    var publicIdField = document.getElementById('woocommerce_lusopay_wallet_public_id');
                    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    var nonce = <?php echo wp_json_encode(wp_create_nonce('lusopay_wallet_connect')); ?>;
                    var session = <?php echo wp_json_encode($session); ?>;
                    var connectUrl = <?php echo wp_json_encode($connectUrl); ?>;
                    var polling = null;
                    var popupRef = null;

                    // The Public ID is managed entirely through the button
                    // above -- the store owner never needs to see or type
                    // the raw value, only the row still has to exist so the
                    // value round-trips through WooCommerce's normal
                    // settings save/read.
                    if (publicIdField) {
                        var row = publicIdField.closest('tr');
                        if (row) { row.style.display = 'none'; }
                    }

                    btn.addEventListener('click', function () {
                        popupRef = window.open(connectUrl, 'lusopay_wallet_connect', 'width=440,height=760');
                        statusEl.textContent = 'A aguardar leitura do cartão...';
                        clearInterval(polling);
                        polling = setInterval(poll, 2000);
                    });

                    function poll() {
                        var url = ajaxUrl + '?action=lusopay_wallet_connect_status&session=' + encodeURIComponent(session) + '&nonce=' + encodeURIComponent(nonce);
                        fetch(url)
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                if (data.status === 'OK' && data.lusopay_id) {
                                    clearInterval(polling);
                                    // The parent page (here) is the one that
                                    // knows the outcome -- close the wallet
                                    // popup itself instead of leaving it up
                                    // to that page to close on its own.
                                    if (popupRef && !popupRef.closed) { popupRef.close(); }
                                    if (publicIdField) {
                                        publicIdField.value = data.lusopay_id;
                                        // Setting .value directly doesn't fire
                                        // input/change, so anything watching
                                        // the form for "unsaved changes"
                                        // (including the Guardar alterações
                                        // button) never notices -- dispatch
                                        // both explicitly.
                                        publicIdField.dispatchEvent(new Event('input', { bubbles: true }));
                                        publicIdField.dispatchEvent(new Event('change', { bubbles: true }));
                                    }
                                    statusEl.innerHTML = '✅ Ligado como <strong>' + data.lusopay_id + '</strong>' + (data.name ? ' (' + data.name + ')' : '') + ' -- clica em "Guardar alterações" para confirmar.';
                                    btn.textContent = 'Ligar a outra conta';
                                } else if (data.status && data.status !== 'PENDING') {
                                    clearInterval(polling);
                                    if (popupRef && !popupRef.closed) { popupRef.close(); }
                                    statusEl.textContent = '❌ Não foi possível ler o cartão. Tenta novamente.';
                                }
                            })
                            .catch(function () {});
                    }
                })();
                </script>
            </td>
        </tr>
        <?php
        return ob_get_clean();
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
     * Opens checkout_url in a popup (PayPal-style) as soon as this page
     * loads. checkout.php's own existing QR + polling + auto-redirect
     * runs unmodified inside that window; once it reaches return_url it's
     * on this same WordPress site, where verify_payment_on_thankyou()
     * writes the outcome to localStorage. This page listens for that via
     * the "storage" event -- NOT window.opener, which modern browsers'
     * Cross-Origin-Opener-Policy can sever even between same-site windows
     * once a cross-origin hop (to pay.lusopay.com) happens in between;
     * "storage" fires for any same-origin window/tab regardless of any
     * opener relationship, so it isn't affected by that.
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
            <p><a href="<?php echo esc_url($checkoutPageUrl); ?>" target="_blank">Clica aqui se a janela de pagamento não abriu</a></p>
        </div>
        <script>
        (function () {
            var orderId = <?php echo (int) $order_id; ?>;
            var url = <?php echo wp_json_encode($checkoutPageUrl); ?>;

            window.open(url, 'lusopay_wallet_popup', 'width=440,height=760')

            window.addEventListener('storage', function (e) {
                if (e.key !== 'lusopay_wallet_result' || !e.newValue) {
                    return
                }
                var data = JSON.parse(e.newValue)
                if (data.order_id !== orderId) {
                    return
                }
                if (data.status === 'AUTHORIZED') {
                    window.location.href = data.url
                }
                // REJECTED: leave this page as-is so the buyer can retry.
            })
        })()
        </script>
        <?php
    }

    /**
     * Runs when the buyer lands back on WooCommerce's order-received page
     * -- either the wallet's own device (if they scanned the QR there) or
     * the popup render_qr_receipt() opened, once checkout.php's own JS
     * redirects it here. checkout.php appends "lusopay_session" to that
     * redirect -- the actual authorization decision comes from calling
     * checkout-status.php ourselves, never from a browser-supplied
     * "status=" query param on its own.
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

        $resultStatus = $order->is_paid() ? 'AUTHORIZED' : 'REJECTED';
        ?>
        <script>
        try {
            localStorage.setItem('lusopay_wallet_result', JSON.stringify({
                order_id: <?php echo (int) $order_id; ?>,
                status: <?php echo wp_json_encode($resultStatus); ?>,
                url: <?php echo wp_json_encode($this->get_return_url($order)); ?>,
                ts: Date.now()
            }))
        } catch (e) {}
        window.close()
        </script>
        <?php
    }
}
