/**
 * Agend Apps Shop — Add to Cart widget JS.
 *
 * Wires up quantity stepper controls and the Add to Cart button. On success,
 * stores any returned guestSessionToken and dispatches agend:cart:updated so
 * sibling widgets (e.g. cart header badge) can refresh.
 *
 * Depends on agend-apps-shop-cart-session.js (AgendCartSession global).
 */

/* global window, AgendCartSession */

document.addEventListener( 'DOMContentLoaded', function () {
	'use strict';

	// Bail in Elementor editor to avoid live API calls during editing.
	if ( window.elementorFrontend && window.elementorFrontend.isEditMode() ) {
		return;
	}

	/**
	 * Shows a message in the widget's message div.
	 *
	 * @param {HTMLElement} msgEl  The message container element.
	 * @param {string}      text   Message text.
	 * @param {string}      type   'success' or 'error'.
	 */
	function showMessage( msgEl, text, type ) {
		msgEl.textContent = text;
		msgEl.classList.remove( 'is-success', 'is-error' );
		msgEl.classList.add( 'is-' + type );
	}

	/**
	 * Hides the message div and clears its content.
	 *
	 * @param {HTMLElement} msgEl The message container element.
	 */
	function clearMessage( msgEl ) {
		msgEl.textContent = '';
		msgEl.classList.remove( 'is-success', 'is-error' );
	}

	/**
	 * Initialises a single Add to Cart widget instance.
	 *
	 * @param {HTMLElement} wrapper The .agend-apps-shop-add-to-cart element.
	 */
	function initWidget( wrapper ) {
		var qtyInput  = wrapper.querySelector( '.agend-shop-atc-qty-input' );
		var decBtn    = wrapper.querySelector( '.agend-shop-atc-qty-decrement' );
		var incBtn    = wrapper.querySelector( '.agend-shop-atc-qty-increment' );
		var addBtn    = wrapper.querySelector( '.agend-shop-add-to-cart-btn' );
		var msgEl     = wrapper.querySelector( '.agend-shop-atc-message' );

		if ( ! qtyInput || ! addBtn || ! msgEl ) {
			return;
		}

		// Decrement button.
		decBtn.addEventListener( 'click', function () {
			var current = parseInt( qtyInput.value, 10 ) || 1;
			var min     = parseInt( qtyInput.getAttribute( 'min' ), 10 ) || 1;
			if ( current > min ) {
				qtyInput.value = current - 1;
			}
		} );

		// Increment button.
		incBtn.addEventListener( 'click', function () {
			var current     = parseInt( qtyInput.value, 10 ) || 1;
			var maxAttr     = qtyInput.getAttribute( 'max' );
			var max         = maxAttr ? parseInt( maxAttr, 10 ) : Infinity;
			if ( current < max ) {
				qtyInput.value = current + 1;
			}
		} );

		// Add to Cart button.
		addBtn.addEventListener( 'click', function () {
			var productType  = addBtn.getAttribute( 'data-product-type' );
			var productId    = addBtn.getAttribute( 'data-product-id' );
			var maxQuantity  = parseInt( addBtn.getAttribute( 'data-max-quantity' ), 10 ) || 0;
			var quantity     = parseInt( qtyInput.value, 10 ) || 1;

			// Clamp to minimum.
			if ( quantity < 1 ) {
				quantity = 1;
				qtyInput.value = 1;
			}

			// Client-side max guard.
			if ( maxQuantity > 0 && quantity > maxQuantity ) {
				showMessage( msgEl, 'Maximum quantity is ' + maxQuantity + '.', 'error' );
				return;
			}

			// Disable button during request.
			addBtn.disabled = true;
			addBtn.setAttribute( 'aria-busy', 'true' );
			clearMessage( msgEl );

			var headers = AgendCartSession.getHeaders();
			headers[ 'Content-Type' ] = 'application/json';

			var restUrl = ( window.agendApps && window.agendApps.restUrl ) ? window.agendApps.restUrl : '';

			fetch( restUrl + 'cart/items', {
				method: 'POST',
				headers: headers,
				body: JSON.stringify( {
					productType: productType,
					productId: productId,
					quantity: quantity,
				} ),
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { status: response.status, data: data.data };
					} );
				} )
				.then( function ( result ) {
					console.log('123')
					if ( 200 === result.status ) {

						console.log(result.data && result.data['guestSessionToken'], result.data, result.data['guestSessionToken'])
						if ( result.data && result.data['guestSessionToken'] ) {
							AgendCartSession.setToken( result.data['guestSessionToken'] );
						}
						showMessage( msgEl, 'Added to cart!', 'success' );
						setTimeout( function () {
							clearMessage( msgEl );
						}, 3000 );
						document.dispatchEvent( new CustomEvent( 'agend:cart:updated' ) );
					} else {
						console.log('136', result)
						var message = ( result.data && result.data.body.error.message )
							? result.data.body.error.message
							: 'An unexpected error occurred. Please try again.';
						showMessage( msgEl, message, 'error' );
					}
				} )
				.catch( function () {
					console.log('144')
					showMessage( msgEl, 'An unexpected error occurred. Please try again.', 'error' );
				} )
				.finally( function () {
					addBtn.disabled = false;
					addBtn.removeAttribute( 'aria-busy' );
				} );
		} );
	}

	document.querySelectorAll( '.agend-apps-shop-add-to-cart' ).forEach( initWidget );
} );
