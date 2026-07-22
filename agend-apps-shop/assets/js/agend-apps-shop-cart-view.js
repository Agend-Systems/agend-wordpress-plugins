/**
 * Agend Apps Shop — Cart View widget JS.
 *
 * Loads and renders the full cart. Handles quantity updates, item removal,
 * cart clearing (with confirmation), checkout initiation, and the testing
 * complete/cancel checkout flows.
 *
 * Depends on agend-apps-shop-cart-session.js (AgendCartSession global).
 * Reads checkout URLs from window.agendAppsShop (localised by PHP).
 */

/* global window, AgendCartSession */

document.addEventListener( 'DOMContentLoaded', function () {
	'use strict';

	// Bail in Elementor editor to avoid live API calls during editing.
	if ( window.elementorFrontend && window.elementorFrontend.isEditMode() ) {
		return;
	}

	var restUrl     = ( window.agendApps && window.agendApps.restUrl ) ? window.agendApps.restUrl : '';
	var shopConfig  = window.agendAppsShop || {};

	/**
	 * Formats a monetary amount using Intl.NumberFormat when available.
	 *
	 * @param {number} amount   Amount in minor currency units.
	 * @param {string} currency ISO 4217 currency code.
	 * @return {string} Formatted currency string.
	 */
	function formatCurrency( amount, currency ) {
		if ( typeof Intl !== 'undefined' && Intl.NumberFormat ) {
			try {
				return new Intl.NumberFormat( undefined, {
					style: 'currency',
					currency: currency,
				} ).format( amount/100 );
			} catch ( e ) {
				// Fallback below.
			}
		}
		return currency + ' ' + parseFloat( amount/100 ).toFixed( 2 );
	}

	/**
	 * Normalises a site-config colour token to a usable CSS colour.
	 *
	 * Tokens are either hex (`#RRGGBB`) or shadcn-style HSL triplets
	 * (`230 37% 16%`); the latter is wrapped in `hsl()`.
	 *
	 * @param {string} value Raw colour token.
	 * @return {string|null} CSS colour value, or null when unusable.
	 */
	function normaliseColour( value ) {
		if ( typeof value !== 'string' || ! value ) {
			return null;
		}
		if ( '#' === value.charAt( 0 ) || -1 !== value.indexOf( '(' ) ) {
			return value;
		}
		if ( /^\d/.test( value ) && -1 !== value.indexOf( '%' ) ) {
			return 'hsl(' + value + ')';
		}
		return value;
	}

	/**
	 * Applies the connected account's published theme (colours and fonts) to
	 * every cart-view widget, mirroring how the Agend Elementor catalogue
	 * widgets inherit the site theme, so the cart page tracks the same palette
	 * and typography. Fire-and-forget: the CSS custom properties update once the
	 * config resolves; on failure the default palette stays in place.
	 */
	function applyTheme() {
		var wrappers = document.querySelectorAll( '.agend-apps-shop-cart-view' );
		if ( ! wrappers.length || ! restUrl ) {
			return;
		}

		var headers = {};
		if ( window.agendApps && window.agendApps.nonce ) {
			headers[ 'X-WP-Nonce' ] = window.agendApps.nonce;
		}

		fetch( restUrl.replace( /\/$/, '' ) + '/sites/config', { headers: headers } )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( body ) {
				var config = ( body && body.data && ! Array.isArray( body.data ) ) ? body.data : body;
				var theme  = ( config && config.theme ) || {};
				var colors = theme.colors || {};
				var fonts  = theme.fonts || {};

				var heading    = normaliseColour( colors.primary || colors.navy || colors.foreground );
				var bodyColour = normaliseColour( colors.foreground || colors.body );
				var accent     = normaliseColour( colors.accent || colors.coral || colors.ring );

				wrappers.forEach( function ( wrapper ) {
					if ( heading ) {
						wrapper.style.setProperty( '--agend-shop-heading', heading );
					}
					if ( bodyColour ) {
						wrapper.style.setProperty( '--agend-shop-body', bodyColour );
					}
					if ( accent ) {
						wrapper.style.setProperty( '--agend-shop-accent', accent );
						wrapper.style.setProperty( '--agend-shop-button', accent );
					}
					if ( fonts.heading ) {
						wrapper.style.setProperty( '--agend-shop-font-heading', '"' + fonts.heading + '", sans-serif' );
					}
					if ( fonts.body ) {
						wrapper.style.setProperty( '--agend-shop-font-body', '"' + fonts.body + '", sans-serif' );
					}
				} );
			} )
			.catch( function () {
				/* site config unavailable — keep the default palette */
			} );
	}

	/**
	 * Initialises a single Cart View widget instance.
	 *
	 * @param {HTMLElement} wrapper The .agend-apps-shop-cart-view element.
	 */
	function initWidget( wrapper ) {
		var loadingEl        = wrapper.querySelector( '.agend-shop-cart-loading' );
		var emptyEl          = wrapper.querySelector( '.agend-shop-cart-empty' );
		var lockedNoticeEl   = wrapper.querySelector( '.agend-shop-cart-locked-notice' );
		var contentEl        = wrapper.querySelector( '.agend-shop-cart-content' );
		var tbodyEl          = wrapper.querySelector( '.agend-shop-cart-items' );
		var totalAmountEl    = wrapper.querySelector( '.agend-shop-cart-total-amount' );
		var clearBtn         = wrapper.querySelector( '.agend-shop-btn-clear-cart' );
		var checkoutBtn      = wrapper.querySelector( '.agend-shop-btn-proceed-checkout' );
		var completeBtn      = wrapper.querySelector( '.agend-shop-btn-complete-checkout' );
		var cancelBtn        = wrapper.querySelector( '.agend-shop-btn-cancel-checkout' );
		var messageEl        = wrapper.querySelector( '.agend-shop-cart-message' );
		var confirmModal     = wrapper.querySelector( '.agend-shop-confirm-modal' );
		var confirmYesBtn    = wrapper.querySelector( '.agend-shop-btn-confirm-yes' );
		var confirmNoBtn     = wrapper.querySelector( '.agend-shop-btn-confirm-no' );

		/**
		 * Shows all loading state and hides everything else.
		 */
		function showLoading() {
			loadingEl.removeAttribute( 'hidden' );
			emptyEl.setAttribute( 'hidden', '' );
			lockedNoticeEl.setAttribute( 'hidden', '' );
			contentEl.setAttribute( 'hidden', '' );
			clearMessage();
		}

		/**
		 * Shows a message in the cart message div.
		 *
		 * @param {string} text Message text.
		 * @param {string} type 'success' or 'error'.
		 */
		function showMessage( text, type ) {
			messageEl.textContent = text;
			messageEl.classList.remove( 'is-success', 'is-error' );
			messageEl.classList.add( 'is-' + type );
		}

		/**
		 * Clears the cart message div.
		 */
		function clearMessage() {
			messageEl.textContent = '';
			messageEl.classList.remove( 'is-success', 'is-error' );
		}

		/**
		 * Sets all edit controls to disabled or enabled.
		 *
		 * @param {boolean} disabled Whether to disable the controls.
		 */
		function setEditControlsDisabled( disabled ) {
			var controls = contentEl.querySelectorAll(
				'.agend-shop-atc-qty-input, .agend-shop-qty-input, .agend-shop-btn-remove-item, .agend-shop-btn-clear-cart, .agend-shop-btn-proceed-checkout'
			);
			controls.forEach( function ( el ) {
				el.disabled = disabled;
			} );
		}
		function lockInputs(id) {
			document.querySelectorAll(`[data--item-id="${id}"]`).forEach( el => {
				el.disabled = true;
			})
		}
		function unlockInputs(id) {
			document.querySelectorAll(`[data--item-id="${id}"]`).forEach( el => {
				el.disabled = false;
			})
		}
		function lockAllInputs() {
			document.querySelectorAll('.agend-shop-qty-decrement, .agend-shop-qty-input, .agend-shop-qty-increment, .agend-shop-btn-remove-item').forEach( el => {
				el.disabled = true;
			})
		}
		function unlockAllInputs() {
			document.querySelectorAll('.agend-shop-qty-decrement, .agend-shop-qty-input, .agend-shop-qty-increment, .agend-shop-btn-remove-item').forEach( el => {
				el.disabled = false;
			})
		}
		/**
		 * Loads the cart from the REST API and renders it.
		 */
		function loadCart(liveUpdate = false) {
			if (!liveUpdate) {
				showLoading();
			}

			fetch( restUrl + 'cart', {
				method: 'GET',
				headers: AgendCartSession.getHeaders(),
			} )
				.then( function ( response ) {
					if ( 400 === response.status ) {
						loadingEl.setAttribute( 'hidden', '' );
						emptyEl.removeAttribute( 'hidden' );
						return null;
					}
					if ( ! response.ok ) {
						return response.json().then( function ( data ) {
							loadingEl.setAttribute( 'hidden', '' );
							var message = ( data && data.message ) ? data.message : 'An unexpected error occurred. Please try again.';
							showMessage( message, 'error' );
							return null;
						} );
					}
					return response.json();
				} )
				.then( function ( data ) {
					// The 400 / error branches above already updated the UI and
					// resolved to null; nothing more to do.
					if ( ! data ) {
						return;
					}
					if ( data.data ) {
						renderCart( data.data );
						return;
					}
					// An empty or just-cleared cart responds with
					// { success: true, data: null }. Show the empty state instead
					// of leaving the loading indicator visible.
					loadingEl.setAttribute( 'hidden', '' );
					contentEl.setAttribute( 'hidden', '' );
					lockedNoticeEl.setAttribute( 'hidden', '' );
					emptyEl.removeAttribute( 'hidden' );
				} )
				.catch( function () {
					loadingEl.setAttribute( 'hidden', '' );
					showMessage( 'An unexpected error occurred. Please try again.', 'error' );
					unlockAllInputs()
				} )
		}

		/**
		 * Renders cart data into the widget.
		 *
		 * Accepts either the cart object directly (the shape returned by
		 * `GET /cart`, where the cart fields and `items` sit on the payload) or a
		 * payload that nests the cart under a `cart` key (the shape returned by
		 * `POST /cart/items`), so the same renderer works for both responses.
		 *
		 * @param {Object} data Cart object, or a wrapper with a `cart` property.
		 */
		function renderCart( data ) {
			loadingEl.setAttribute( 'hidden', '' );
			clearMessage();

			var cart  = ( data && data.cart ) || data || {};
			var items = ( cart && cart.items ) || [];

			// Handle terminal statuses.
			if ( 'complete' === cart.status ) {
				emptyEl.querySelector( 'p' ).textContent = 'Your order is complete.';
				emptyEl.removeAttribute( 'hidden' );
				return;
			}

			if ( 'abandoned' === cart.status ) {
				emptyEl.querySelector( 'p' ).textContent = 'Your cart has been abandoned.';
				emptyEl.removeAttribute( 'hidden' );
				return;
			}

			if ( 0 === items.length ) {
				emptyEl.removeAttribute( 'hidden' );
				return;
			}

			contentEl.removeAttribute( 'hidden' );

			var isLocked = 'locked' === cart.status;

			if ( isLocked ) {
				lockedNoticeEl.removeAttribute( 'hidden' );
				setEditControlsDisabled( true );
				if ( cancelBtn ) {
					cancelBtn.disabled = false;
				}
			} else {
				lockedNoticeEl.setAttribute( 'hidden', '' );
				setEditControlsDisabled( false );
			}

			// Render rows.
			tbodyEl.innerHTML = '';
			items.forEach( function ( item ) {
				tbodyEl.appendChild( buildItemRow( item, isLocked ) );
			} );

			// Render total.
			if ( totalAmountEl ) {
				totalAmountEl.textContent = formatCurrency( cart.total_amount, cart.currency );
			}
		}

		/**
		 * Replaces a single cart item row in place and updates the cart total,
		 * from a `PUT /cart/item/update` response.
		 *
		 * The updated item is returned on `response.data`, with the recalculated
		 * totals under `response.meta.totals` (`newTotalAmount`, `newTotalCount`,
		 * `currency`). Tolerant of a raw item object being passed directly.
		 *
		 * @param {Object} response Update response, or the updated item object.
		 */
		function replaceItemRow( response ) {
			var item   = ( response && response.data ) || response || {};
			var totals = ( response && response.meta && response.meta.totals ) || {};

			var row = document.querySelector( `tr[data--item-id="${item.id}"]` );
			if ( row ) {
				row.replaceWith( buildItemRow( item ) );
			}

			var totalAmount = ( undefined !== totals.newTotalAmount ) ? totals.newTotalAmount : totals.newTotalAmmount;
			if ( totalAmountEl && undefined !== totalAmount ) {
				totalAmountEl.textContent = formatCurrency( totalAmount, totals.currency || item.currency );
			}

			document.dispatchEvent( new CustomEvent( 'agend:cart:updated:total', {
				detail: {
					count: totals.newTotalCount,
					amount: totalAmount,
				},
			} ) );
		}
		/**
		 * Builds a table row for a cart item.
		 *
		 * @param {Object}  item     Cart item object.
		 * @param {boolean} isLocked Whether the cart is locked.
		 * @return {HTMLElement} A <tr> element.
		 */
		function buildItemRow( item, isLocked ) {
			var tr = document.createElement( 'tr' );
			tr.dataset.ItemId = item.id

			var subtotal = item.unit_amount * item.quantity;

			// Name cell.
			var tdName = document.createElement( 'td' );
			tdName.dataset.label = 'Item';
			tdName.textContent = item.name;

			// Unit price cell.
			var tdUnit = document.createElement( 'td' );
			tdUnit.dataset.label = 'Unit Price';
			tdUnit.textContent = formatCurrency( item.unit_amount, item.currency );

			// Quantity stepper cell.
			var tdQty = document.createElement( 'td' );
			tdQty.dataset.label = 'Quantity';
			var qtyWrapper = document.createElement( 'div' );
			qtyWrapper.className = 'agend-shop-atc-quantity';

			var decBtn = document.createElement( 'button' );
			decBtn.className = 'agend-shop-atc-qty-btn agend-shop-qty-decrement';
			decBtn.dataset.ItemId = item.id
			decBtn.setAttribute( 'aria-label', 'Decrease quantity' );
			decBtn.textContent = '\u2212';

			var qtyInput = document.createElement( 'input' );
			qtyInput.className = 'agend-shop-qty-input agend-shop-atc-qty-input';
			qtyInput.dataset.ItemId = item.id
			qtyInput.type = 'number';
			qtyInput.min = '1';
			qtyInput.name = 'agend-shop-cart-view-qty-' + item.id;
			qtyInput.value = item.quantity;
			qtyInput.setAttribute( 'data-prev-value', item.quantity );

			var incBtn = document.createElement( 'button' );
			incBtn.className = 'agend-shop-atc-qty-btn agend-shop-qty-increment';
			incBtn.dataset.ItemId = item.id
			incBtn.setAttribute( 'aria-label', 'Increase quantity' );
			incBtn.textContent = '+';

			qtyWrapper.appendChild( decBtn );
			qtyWrapper.appendChild( qtyInput );
			qtyWrapper.appendChild( incBtn );
			tdQty.appendChild( qtyWrapper );

			// Subtotal cell.
			var tdSubtotal = document.createElement( 'td' );
			tdSubtotal.dataset.label = 'Subtotal';
			tdSubtotal.textContent = formatCurrency( subtotal, item.currency );

			// Actions cell. Empty label so no header prefix shows in the stacked
			// mobile layout.
			var tdActions = document.createElement( 'td' );
			tdActions.dataset.label = '';
			var removeBtn = document.createElement( 'button' );
			removeBtn.className = 'agend-shop-btn-remove-item';
			removeBtn.dataset.ItemId = item.id
			removeBtn.textContent = 'Remove';
			tdActions.appendChild( removeBtn );

			if ( isLocked ) {
				decBtn.disabled = true;
				incBtn.disabled = true;
				qtyInput.disabled = true;
				removeBtn.disabled = true;
			}

			tr.appendChild( tdName );
			tr.appendChild( tdUnit );
			tr.appendChild( tdQty );
			tr.appendChild( tdSubtotal );
			tr.appendChild( tdActions );

			// Debounced quantity update.
			var qtyTimer = null;
			function onQtyChange() {
				clearTimeout( qtyTimer );
				var newQty = parseInt( qtyInput.value, 10 );
				if ( isNaN( newQty ) || newQty < 1 ) {
					newQty = 1;
					qtyInput.value = 1;
				}
				qtyTimer = setTimeout( function () {
					updateItemQty( item, newQty, qtyInput );
				}, 500 );
			}

			decBtn.addEventListener( 'click', function () {
				var current = parseInt( qtyInput.value, 10 ) || 1;
				if ( current > 1 ) {
					qtyInput.value = current - 1;
					onQtyChange();
				}
			} );

			incBtn.addEventListener( 'click', function () {
				var current = parseInt( qtyInput.value, 10 ) || 1;
				qtyInput.value = current + 1;
				onQtyChange();
			} );

			qtyInput.addEventListener( 'change', onQtyChange );

			removeBtn.addEventListener( 'click', function () {
				removeItem( item.id );
			} );

			return tr;
		}

		/**
		 * Sends a PUT request to update the quantity of a cart item.
		 *
		 * @param {Object}      item   Cart item.
		 * @param {number}      quantity New quantity value.
		 * @param {HTMLElement} input    The quantity input element (for revert on error).
		 */
		function updateItemQty( item, quantity, input ) {
			lockInputs(item.id)
			document.querySelector(`tr[data--item-id="${item.id}"]`).classList.add('agend-cart-row-loading')
			var prevValue = input.getAttribute( 'data-prev-value' );
			input.setAttribute( 'data-prev-value', quantity );

			var headers = AgendCartSession.getHeaders();
			headers[ 'Content-Type' ] = 'application/json';

			fetch( restUrl + 'cart/item/update', {
				method: 'PUT',
				headers: headers,
				body: JSON.stringify( { quantity: quantity, itemId: item.id } ),
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						return response.json().then( function ( data ) {
							input.value = prevValue;
							input.setAttribute( 'data-prev-value', prevValue );
							var message = ( data && data.message ) ? data.message : 'An unexpected error occurred. Please try again.';
							showMessage( message, 'error' );
						} );
					}
					response.json().then( data => {
						replaceItemRow( data );
					})
					return null;
				} )
				.catch( function () {
					input.value = prevValue;
					input.setAttribute( 'data-prev-value', prevValue );
					showMessage( 'An unexpected error occurred. Please try again.', 'error' );
				} )
				.finally( function () {
					unlockInputs(item.id)

					document.querySelector(`tr[data--item-id="${item.id}"]`).classList.remove('agend-cart-row-loading')
				});
		}

		/**
		 * Sends a DELETE request to remove a single item from the cart.
		 *
		 * @param {string} itemId Cart item ID.
		 */
		function removeItem( itemId ) {
			lockInputs(itemId)
			fetch( restUrl + 'cart/item/delete/' + encodeURIComponent(itemId), {
				method: 'DELETE',
				headers: AgendCartSession.getHeaders(),
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						return response.json().then( function ( data ) {
							var message = ( data && data.message ) ? data.message : 'An unexpected error occurred. Please try again.';
							showMessage( message, 'error' );
						} );
					}
					loadCart(true);
					document.dispatchEvent( new CustomEvent( 'agend:cart:updated' ) );
					return null;
				} )
				.catch( function () {
					showMessage( 'An unexpected error occurred. Please try again.', 'error' );
				} );
		}

		/**
		 * Sends a DELETE request to clear all items from the cart.
		 */
		function clearCart() {
			lockAllInputs()
			fetch( restUrl + 'cart/clear', {
				method: 'DELETE',
				headers: AgendCartSession.getHeaders(),
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						return response.json().then( function ( data ) {
							var message = ( data && data.message ) ? data.message : 'An unexpected error occurred. Please try again.';
							showMessage( message, 'error' );
						} );
					}
					loadCart();
					document.dispatchEvent( new CustomEvent( 'agend:cart:updated' ) );
					return null;
				} )
				.catch( function () {
					showMessage( 'An unexpected error occurred. Please try again.', 'error' );
				} );
		}

		// Clear cart — show modal first.
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				confirmModal.removeAttribute( 'hidden' );
			} );
		}

		if ( confirmYesBtn ) {
			confirmYesBtn.addEventListener( 'click', function () {
				confirmModal.setAttribute( 'hidden', '' );
				clearCart();
			} );
		}

		if ( confirmNoBtn ) {
			confirmNoBtn.addEventListener( 'click', function () {
				confirmModal.setAttribute( 'hidden', '' );
			} );
		}

		// Proceed to Checkout.
		if ( checkoutBtn ) {
			checkoutBtn.addEventListener( 'click', function () {
				checkoutBtn.disabled = true;
				clearMessage();

				var headers = AgendCartSession.getHeaders();
				headers[ 'Content-Type' ] = 'application/json';

				fetch( restUrl + 'cart/checkout', {
					method: 'POST',
					headers: headers,
					body: JSON.stringify( {
						successUrl: shopConfig.checkoutSuccessUrl || '',
						cancelUrl: shopConfig.checkoutCancelUrl || '',
					} ),
				} )
					.then( function ( response ) {
						return response.json().then( function ( data ) {
							return { status: response.status, data: data };
						} );
					} )
					.then( function ( result ) {
						if ( 200 === result.status && result.data.data && result.data.data.checkoutUrl ) {
							window.location.href = result.data.data.checkoutUrl;
						} else {
							var message = ( result.data.data && result.data.data.message ) ? result.data.data.message : 'An unexpected error occurred. Please try again.';
							showMessage( message, 'error' );
							checkoutBtn.disabled = false;
						}
					} )
					.catch( function () {
						showMessage( 'An unexpected error occurred. Please try again.', 'error' );
						checkoutBtn.disabled = false;
					} );
			} );
		}

		// Complete Checkout (test button).
		if ( completeBtn ) {
			completeBtn.addEventListener( 'click', function () {
				completeBtn.disabled = true;
				clearMessage();

				fetch( restUrl + 'cart/checkout/complete', {
					method: 'POST',
					headers: AgendCartSession.getHeaders(),
				} )
					.then( function ( response ) {
						return response.json().then( function ( data ) {
							return { status: response.status, data: data };
						} );
					} )
					.then( function ( result ) {
						if ( 200 === result.status ) {
							showMessage( 'Checkout complete!', 'success' );
							document.dispatchEvent( new CustomEvent( 'agend:cart:updated' ) );
							loadCart();
						} else {
							var message = ( result.data && result.data.message ) ? result.data.message : 'An unexpected error occurred. Please try again.';
							showMessage( message, 'error' );
						}
					} )
					.catch( function () {
						showMessage( 'An unexpected error occurred. Please try again.', 'error' );
					} )
					.finally( function () {
						completeBtn.disabled = false;
					} );
			} );
		}

		// Cancel Checkout (test button).
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				cancelBtn.disabled = true;
				clearMessage();

				fetch( restUrl + 'cart/checkout/cancel', {
					method: 'POST',
					headers: AgendCartSession.getHeaders(),
				} )
					.then( function ( response ) {
						return response.json().then( function ( data ) {
							return { status: response.status, data: data };
						} );
					} )
					.then( function ( result ) {
						if ( 200 === result.status ) {
							document.dispatchEvent( new CustomEvent( 'agend:cart:updated' ) );
							window.location.href = shopConfig.cartPageUrl || '';
						} else {
							var message = ( result.data && result.data.message ) ? result.data.message : 'An unexpected error occurred. Please try again.';
							showMessage( message, 'error' );
							cancelBtn.disabled = false;
						}
					} )
					.catch( function () {
						showMessage( 'An unexpected error occurred. Please try again.', 'error' );
						cancelBtn.disabled = false;
					} );
			} );
		}

		// Listen for cart updates from other widgets on the same page.
		document.addEventListener( 'agend:cart:updated', function () {
			loadCart();
		} );

		// Initial load.
		loadCart();
	}

	applyTheme();
	document.querySelectorAll( '.agend-apps-shop-cart-view' ).forEach( initWidget );
} );
