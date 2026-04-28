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
					if ( data.data ) {
						renderCart( data.data );
					}
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
		 * @param {Object} data API response with `cart` and `items` properties.
		 */
		function renderCart( data ) {
			loadingEl.setAttribute( 'hidden', '' );
			clearMessage();

			var cart  = data.cart || {};
			var items = data.cart?.items || [];

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
		 * Updates a table row for a cart item. Updates totals
		 *
		 * @param {Object}  data     Cart item object.
		 */
		function replaceItemRow(data){
			document.querySelector(`tr[data--item-id="${data.item.id}"]`).replaceWith(buildItemRow(data.item))
			totalAmountEl.textContent = formatCurrency( data.newTotals.newTotalAmmount, data.newTotals.currency );

			document.dispatchEvent( new CustomEvent( 'agend:cart:updated:total', {
				detail: {
					conut: data.newTotals?.newTotalCount,
					ammount: data.newTotals.newTotalAmmount
				}
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
			tdName.textContent = item.name;

			// Unit price cell.
			var tdUnit = document.createElement( 'td' );
			tdUnit.textContent = formatCurrency( item.unit_amount, item.currency );

			// Quantity stepper cell.
			var tdQty = document.createElement( 'td' );
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
			tdSubtotal.textContent = formatCurrency( subtotal, item.currency );

			// Actions cell.
			var tdActions = document.createElement( 'td' );
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
						replaceItemRow(data.data);
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

	document.querySelectorAll( '.agend-apps-shop-cart-view' ).forEach( initWidget );
} );
