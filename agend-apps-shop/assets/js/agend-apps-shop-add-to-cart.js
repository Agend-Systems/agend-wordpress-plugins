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
	 * AgendCartSession.describeError(). An older cart session script without it
	 * (never shipped alongside this file) gets the previous behaviour: the
	 * response's own message, else the fallback.
	 *
	 * @param {number} status   HTTP status.
	 * @param {Object} body     Decoded response body.
	 * @param {string} fallback Text for an error with nothing more specific.
	 * @return {{message: string, locked: boolean}}
	 */
	function describeCartError( status, body, fallback ) {
		if ( window.AgendCartSession && 'function' === typeof window.AgendCartSession.describeError ) {
			return window.AgendCartSession.describeError( status, body, fallback );
		}
		return { message: ( body && 'string' === typeof body.message && body.message ) || fallback, locked: false };
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
	 * Shows a failed add, with a link to the cart page when the cart is held by
	 * a checkout in progress (the cart page is where that checkout can be
	 * finished or cancelled).
	 *
	 * @param {HTMLElement} msgEl    The message container element.
	 * @param {number}      status   HTTP status.
	 * @param {Object}      body     Decoded response body.
	 */
	function showFailure( msgEl, status, body ) {
		var described = describeCartError( status, body, 'We couldn\'t add this to your cart. Please try again.' );
		showMessage( msgEl, described.message, 'error' );
		var cartUrl = ( window.agendAppsShop && window.agendAppsShop.cartPageUrl ) || '';
		if ( described.locked && cartUrl ) {
			msgEl.appendChild( document.createTextNode( ' ' ) );
			var link = document.createElement( 'a' );
			link.className = 'agend-shop-atc-cart-link';
			link.href = cartUrl;
			link.textContent = 'Go to your cart';
			msgEl.appendChild( link );
		}
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
					return response.json().catch( function () {
						return null;
					} ).then( function ( body ) {
						return { status: response.status, body: body, data: body && body.data };
					} );
				} )
				.then( function ( result ) {
					if ( 200 === result.status ) {
						if ( result.data && result.data.guestSessionToken ) {
							AgendCartSession.setToken( result.data.guestSessionToken );
						}
						showMessage( msgEl, 'Added to cart!', 'success' );
						setTimeout( function () {
							clearMessage( msgEl );
						}, 3000 );
						document.dispatchEvent( new CustomEvent( 'agend:cart:updated' ) );
					} else {
						showFailure( msgEl, result.status, result.body );
					}
				} )
				.catch( function () {
					showMessage( msgEl, 'We couldn\'t add this to your cart. Check your connection and try again.', 'error' );
				} )
				.finally( function () {
					addBtn.disabled = false;
					addBtn.removeAttribute( 'aria-busy' );
				} );
		} );
	}

	document.querySelectorAll( '.agend-apps-shop-add-to-cart' ).forEach( initWidget );
} );
