<?php
// class-wc-gateway-lusopay-wallet.php
// WooCommerce payment gateway that redirects the buyer to LusoPay's
// hosted checkout.php (which asks the EUDI wallet to present the LusoPay
// Card and calls Cyclos to move the money), then re-verifies the outcome
// server-to-server on the order-received page before marking it paid --
// the redirect's own "status=" query param is never trusted on its own,
// since it's attacker-controlled in the browser.

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

        $returnUrl = add_query_arg('lusopay_order', $order_id, $this->get_return_url($order));

        $params = [
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'description' => sprintf('Encomenda #%s', $order->get_order_number()),
            'merchant' => get_bloginfo('name'),
            'merchant_public_id' => $this->public_id,
            'return_url' => $returnUrl,
        ];

        $order->update_status('on-hold', 'A aguardar confirmação via LusoPay Wallet.');

        return [
            'result' => 'success',
            'redirect' => $this->checkout_url . '?' . http_build_query($params),
        ];
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
