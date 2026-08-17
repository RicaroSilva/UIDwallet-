// lusopay-wallet-blocks.js
// WooCommerce Blocks (Cart & Checkout blocks) integration.
//
// Unlike the classic checkout flow (class-wc-gateway-lusopay-wallet.php's
// render_qr_receipt(), which shows the QR on the "Pay for order" page
// *after* the WooCommerce order already exists), Blocks lets a payment
// method's onPaymentSetup() callback hold up order placement itself --
// so here we open the wallet popup and wait for the outcome *before*
// WooCommerce ever creates the order. The buyer never leaves the
// checkout page: on success WooCommerce creates the (now-paid) order and
// moves on to the thank-you page as usual; on failure/rejection
// onPaymentSetup() resolves as an error and WooCommerce shows it right
// there on checkout, letting the buyer pick another payment method --
// no order was ever created for the failed attempt.
//
// Once the wallet has actually authorized the payment, we only hand its
// session id to WooCommerce as payment_data -- the gateway's
// process_payment() (server-to-server, via checkout-status.php) is what
// actually verifies and trusts that outcome, never the browser's own
// say-so.
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry
	const { getSetting } = window.wc.wcSettings
	const { createElement, useEffect, useState } = window.wp.element

	const settings = getSetting( 'lusopay_wallet_data', {} )
	const label = settings.title || 'LusoPay Wallet'

	function randomSessionId() {
		const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
		let id = ''
		for ( let i = 0; i < 24; i++ ) {
			id += chars.charAt( Math.floor( Math.random() * chars.length ) )
		}
		return id
	}

	// Polls our own admin-ajax proxy (never the LusoPay domain directly,
	// to avoid needing CORS there) until the session resolves, or until
	// the popup window is closed by the buyer without finishing.
	function waitForOutcome( session, popup ) {
		return new Promise( function ( resolve ) {
			const url = settings.ajaxUrl
				+ '?action=lusopay_wallet_checkout_status'
				+ '&session=' + encodeURIComponent( session )
				+ '&nonce=' + encodeURIComponent( settings.nonce )

			const closedCheck = setInterval( function () {
				if ( popup && popup.closed ) {
					clearInterval( closedCheck )
					clearTimeout( timeoutHandle )
					resolve( { status: 'CANCELLED' } )
				}
			}, 1000 )

			const timeoutHandle = setTimeout( function () {
				clearInterval( closedCheck )
				resolve( { status: 'TIMEOUT' } )
			}, 5 * 60 * 1000 )

			function poll() {
				fetch( url )
					.then( function ( r ) { return r.json() } )
					.then( function ( data ) {
						if ( data && data.status && data.status !== 'PENDING' ) {
							clearInterval( closedCheck )
							clearTimeout( timeoutHandle )
							resolve( data )
						} else {
							setTimeout( poll, 2000 )
						}
					} )
					.catch( function () { setTimeout( poll, 2000 ) } )
			}
			poll()
		} )
	}

	const Content = ( props ) => {
		const { eventRegistration, emitResponse, billing } = props
		const { onPaymentSetup } = eventRegistration
		const [ statusText, setStatusText ] = useState( '' )

		useEffect( function () {
			const unsubscribe = onPaymentSetup( function () {
				if ( ! settings.checkoutUrl || ! settings.merchantPublicId ) {
					return Promise.resolve( {
						type: emitResponse.responseTypes.ERROR,
						message: 'O método LusoPay Wallet não está configurado (falta o Public ID ou a URL do checkout nas definições).',
					} )
				}

				const session = randomSessionId()
				const cartTotalMinor = ( billing && billing.cartTotal && billing.cartTotal.value ) || 0
				const minorUnit = ( billing && billing.currency && typeof billing.currency.minorUnit === 'number' ) ? billing.currency.minorUnit : 2
				const amount = ( cartTotalMinor / Math.pow( 10, minorUnit ) ).toFixed( 2 )
				const currency = ( billing && billing.currency && billing.currency.code ) || 'EUR'

				const params = new URLSearchParams( {
					amount: amount,
					currency: currency,
					description: settings.merchant ? ( 'Compra em ' + settings.merchant ) : 'Compra online',
					merchant: settings.merchant || '',
					merchant_public_id: settings.merchantPublicId,
					return_url: window.location.href,
					session: session,
				} )
				const popupUrl = settings.checkoutUrl + '?' + params.toString()
				const popup = window.open( popupUrl, 'lusopay_wallet_checkout', 'width=440,height=760' )
				setStatusText( 'A aguardar confirmação na Carteira Digital...' )

				return waitForOutcome( session, popup ).then( function ( data ) {
					if ( popup && ! popup.closed ) { popup.close() }
					setStatusText( '' )

					if ( data.status === 'AUTHORIZED' ) {
						return {
							type: emitResponse.responseTypes.SUCCESS,
							meta: {
								paymentMethodData: {
									lusopay_wallet_session: session,
								},
							},
						}
					}
					if ( data.status === 'CANCELLED' ) {
						return {
							type: emitResponse.responseTypes.ERROR,
							message: 'A janela de pagamento foi fechada antes de confirmares. Tenta novamente.',
						}
					}
					if ( data.status === 'TIMEOUT' ) {
						return {
							type: emitResponse.responseTypes.ERROR,
							message: 'Não recebemos confirmação da Carteira Digital a tempo. Tenta novamente.',
						}
					}
					return {
						type: emitResponse.responseTypes.ERROR,
						message: 'O pagamento na Carteira Digital foi rejeitado. Tenta novamente ou escolhe outro método de pagamento.',
					}
				} )
			} )

			return unsubscribe
		}, [ onPaymentSetup, emitResponse, billing ] )

		return createElement(
			'div',
			{},
			settings.description || '',
			statusText ? createElement( 'p', { style: { marginTop: '8px' } }, statusText ) : null
		)
	}

	registerPaymentMethod( {
		name: 'lusopay_wallet',
		label: label,
		ariaLabel: label,
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: () => true,
		supports: {
			features: [ 'products' ],
		},
	} )
} )()
