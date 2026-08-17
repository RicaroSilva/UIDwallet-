// lusopay-wallet-blocks.js
// Minimal registration for the WooCommerce Blocks (Cart & Checkout
// blocks) checkout. This gateway has no on-page fields -- it just
// redirects to checkout.php -- so the block content is a plain
// description line, no form.
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry
	const { getSetting } = window.wc.wcSettings
	const { createElement } = window.wp.element

	const settings = getSetting( 'lusopay_wallet_data', {} )
	const label = settings.title || 'LusoPay Wallet'

	const Content = () => createElement( 'div', {}, settings.description || '' )

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
