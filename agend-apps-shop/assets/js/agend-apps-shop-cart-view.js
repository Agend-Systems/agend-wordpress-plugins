/**
 * Agend Apps Shop — Cart View widget JS.
 *
 * Loads and renders the full cart. Handles quantity updates, item removal,
 * cart clearing (with confirmation), checkout initiation, and the testing
 * complete/cancel checkout flows. For a signed-in member it also offers Pay on
 * Invoice when the shop setting and the organisation's invoice policy allow
 * it, and shows the placed order. For a signed-out visitor on a site that
 * requires sign-in, it keeps the cart and remembers the way back after
 * sign-in.
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
		var buyerName        = wrapper.querySelector( '.agend-shop-buyer-name' );
		var buyerEmail       = wrapper.querySelector( '.agend-shop-buyer-email' );
		var checkoutBtn      = wrapper.querySelector( '.agend-shop-btn-proceed-checkout' );
		var completeBtn      = wrapper.querySelector( '.agend-shop-btn-complete-checkout' );
		var cancelBtn        = wrapper.querySelector( '.agend-shop-btn-cancel-checkout' );
		var messageEl        = wrapper.querySelector( '.agend-shop-cart-message' );
		var confirmModal     = wrapper.querySelector( '.agend-shop-confirm-modal' );
		var confirmYesBtn    = wrapper.querySelector( '.agend-shop-btn-confirm-yes' );
		var confirmNoBtn     = wrapper.querySelector( '.agend-shop-btn-confirm-no' );
		var paymentEl        = wrapper.querySelector( '.agend-shop-cart-payment' );
		var confirmationEl   = wrapper.querySelector( '.agend-shop-invoice-confirmation' );
		var signInLink       = wrapper.querySelector( '.agend-shop-cart-signin-link' );

		var signedIn = 'true' === wrapper.dataset.memberSignedIn;
		var eventTicketsOnly = false;
		var hasEventTickets  = false;
		// Pay on Invoice state: the platform's invoice option for this cart,
		// the buyer's choice, their entries (kept across re-renders) and the
		// idempotency key of the current attempt.
		var invoiceOption    = null;
		var paymentMethod    = 'card';
		var invoiceEntries   = { po: '', companyId: '' };
		var invoiceKey       = null;
		var orderPlaced      = false;
		var cartTotal        = null;
		var cartCurrency     = '';
		var optionsRequest   = 0;
		var optionsTimer     = null;
		var cartLocked       = false;
		function updateCheckoutLabel( amount, currency ) {
			if ( Number.isFinite( Number( amount ) ) && currency ) {
				cartTotal    = Number( amount );
				cartCurrency = currency;
			}
			if ( checkoutBtn && Number.isFinite( Number( amount ) ) && currency ) {
				if ( 'invoice' === paymentMethod ) {
					checkoutBtn.textContent = 'Place order on invoice — ' + formatCurrency( Number( amount ), currency );
					return;
				}
				checkoutBtn.textContent = Number( amount ) === 0 && eventTicketsOnly
					? 'Confirm free booking'
					: 'Proceed to Checkout — ' + formatCurrency( Number( amount ), currency );
			}
		}
		function safeContinueUrl( value ) {
			if ( ! value ) return null;
			try {
				var url = new URL( value, window.location.href );
				if ( url.origin === window.location.origin && /^https?:$/.test( url.protocol ) ) return url.href;
			} catch ( e ) { /* Use the next fallback. */ }
			return null;
		}
		var continueUrl = safeContinueUrl( new URL( window.location.href ).searchParams.get( 'continue' ) || '' )
			|| safeContinueUrl( shopConfig.continueShoppingUrl || '' );
		if ( continueUrl ) {
			wrapper.querySelectorAll( '.agend-shop-keep-browsing' ).forEach( function ( link ) {
				link.href = continueUrl;
			} );
		}

		// Callback invoked when the shared confirmation modal is confirmed.
		var pendingConfirm   = null;

		/**
		 * Shows the shared confirmation modal with a message, button labels, and a
		 * confirm callback (a reusable replacement for window.confirm). Falls back
		 * to window.confirm when the modal markup is not present.
		 *
		 * @param {Object} options { message, confirmLabel, cancelLabel, onConfirm }.
		 */
		function showConfirm( options ) {
			var onConfirm = options.onConfirm || function () {};
			if ( ! confirmModal ) {
				if ( window.confirm( options.message ) ) {
					onConfirm();
				}
				return;
			}
			var titleEl = confirmModal.querySelector( '.agend-shop-confirm-modal-inner p' );
			if ( titleEl ) {
				titleEl.textContent = options.message;
			}
			if ( confirmYesBtn ) {
				confirmYesBtn.textContent = options.confirmLabel || 'Confirm';
			}
			if ( confirmNoBtn ) {
				confirmNoBtn.textContent = options.cancelLabel || 'Cancel';
			}
			pendingConfirm = onConfirm;
			confirmModal.removeAttribute( 'hidden' );
			if ( confirmYesBtn ) {
				confirmYesBtn.focus();
			}
		}

		/**
		 * Hides the shared confirmation modal and clears any pending callback.
		 */
		function hideConfirm() {
			pendingConfirm = null;
			if ( confirmModal ) {
				confirmModal.setAttribute( 'hidden', '' );
			}
		}

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
			// After an order on invoice the confirmation stays on screen; the
			// now-empty cart is not shown over it.
			if ( orderPlaced ) {
				return;
			}
			if ( ! AgendCartSession.getToken() && 'true' !== wrapper.dataset.memberSignedIn ) {
				loadingEl.setAttribute( 'hidden', '' );
				contentEl.setAttribute( 'hidden', '' );
				lockedNoticeEl.setAttribute( 'hidden', '' );
				emptyEl.removeAttribute( 'hidden' );
				clearMessage();
				return;
			}
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
			var isLocked = 'locked' === cart.status;
			cartLocked = isLocked;
			// Invalidate options for the previous cart, including empty/terminal carts.
			clearTimeout( optionsTimer );
			++optionsRequest;
			if ( cancelBtn ) {
				cancelBtn.hidden = ! isLocked;
				cancelBtn.disabled = ! isLocked;
			}

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

			if ( isLocked ) {
				lockedNoticeEl.removeAttribute( 'hidden' );
				setEditControlsDisabled( true );
			} else {
				lockedNoticeEl.setAttribute( 'hidden', '' );
				setEditControlsDisabled( false );
			}

			// Render rows. Event-ticket lines that carry their event slug get a
			// collapsible per-seat attendee editor row directly beneath them
			// (SPEC-CORE-20260721 US-5.3).
			tbodyEl.innerHTML = '';
			items.forEach( function ( item ) {
				tbodyEl.appendChild( buildItemRow( item, isLocked ) );
				if ( isEventTicketWithEvent( item ) && ! isLocked ) {
					tbodyEl.appendChild( buildAttendeePanelRow( item ) );
				}
			} );

			// Render total.
			if ( totalAmountEl ) {
				totalAmountEl.textContent = formatCurrency( cart.total_amount, cart.currency );
			}
			eventTicketsOnly = cart.items.length > 0 && cart.items.every( function ( item ) { return 'event_tickets' === item.product_type; } );
			hasEventTickets  = cart.items.some( function ( item ) { return 'event_tickets' === item.product_type; } );
			// A changed cart is a new attempt.
			invoiceKey = null;
			updateCheckoutLabel( cart.total_amount, cart.currency );
			loadCheckoutOptions( isLocked || 0 === Number( cart.total_amount ) );
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

			// Non-event lines only: event-ticket quantity changes update the row and
			// attendee form in place (see applyRowTotals) rather than rebuilding here.
			var row = document.querySelector( `tr[data--item-id="${item.id}"]` );
			if ( row ) {
				row.replaceWith( buildItemRow( item ) );
			}

			var totalAmount = ( undefined !== totals.newTotalAmount ) ? totals.newTotalAmount : totals.newTotalAmmount;
			if ( totalAmountEl && undefined !== totalAmount ) {
				totalAmountEl.textContent = formatCurrency( totalAmount, totals.currency || item.currency );
				updateCheckoutLabel( totalAmount, totals.currency || item.currency );
			}

			scheduleCheckoutOptions();
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
			if ( isEventTicketWithEvent( item ) ) {
				var summary = document.createElement( 'div' );
				summary.className = 'agend-shop-attendee-summary';
				summary.textContent = attendeeSummary( item );
				tdName.appendChild( summary );
			}

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

			// Attendee editor toggle for event-ticket lines that carry their event
			// slug (SPEC-CORE-20260721 US-5.3). Toggles the sibling panel row.
			var attendeesBtn = null;
			if ( isEventTicketWithEvent( item ) && ! isLocked ) {
				attendeesBtn = document.createElement( 'button' );
				attendeesBtn.className = 'agend-shop-btn-attendees';
				attendeesBtn.dataset.itemId = item.id;
				attendeesBtn.setAttribute( 'aria-expanded', 'false' );
				attendeesBtn.textContent = 'Change attendees';
				tdActions.appendChild( attendeesBtn );
			}

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

			// Event-ticket quantity is managed through the attendee editor so that
			// removals honour saved attendee details (SPEC-CORE-20260721 US-5.3):
			// direct typing is disabled and the steppers route through the
			// attendee-aware add/remove flows.
			var isEventLine = isEventTicketWithEvent( item );
			if ( isEventLine ) {
				qtyInput.readOnly = true;
			}

			decBtn.addEventListener( 'click', function () {
				if ( isEventLine ) {
					handleTicketDecrement( item );
					return;
				}
				var current = parseInt( qtyInput.value, 10 ) || 1;
				if ( current > 1 ) {
					qtyInput.value = current - 1;
					onQtyChange();
				}
			} );

			incBtn.addEventListener( 'click', function () {
				if ( isEventLine ) {
					changeEventQty( item, item.quantity + 1 );
					return;
				}
				var current = parseInt( qtyInput.value, 10 ) || 1;
				qtyInput.value = current + 1;
				onQtyChange();
			} );

			qtyInput.addEventListener( 'change', onQtyChange );

			removeBtn.addEventListener( 'click', function () {
				removeItem( item.id );
			} );

			if ( attendeesBtn ) {
				attendeesBtn.addEventListener( 'click', function () {
					toggleAttendeePanel( item, attendeesBtn );
				} );
			}

			return tr;
		}

		// ---------------------------------------------------------------------
		// Per-seat attendee editor (SPEC-CORE-20260721 US-5.3)
		// ---------------------------------------------------------------------

		// Attendee-field definitions are fetched once per event slug and reused
		// across every ticket line for that event.
		var attendeeFieldsCache = {};

		/**
		 * Whether a cart item is an event ticket that carries its event slug, and
		 * so can host the per-seat attendee editor.
		 *
		 * @param {Object} item Cart item.
		 * @return {boolean} True when the editor applies.
		 */
		function isEventTicketWithEvent( item ) {
			return !! (
				item &&
				'event_tickets' === item.product_type &&
				item.metadata &&
				item.metadata.event_slug
			);
		}

		/**
		 * Reads the persisted attendees array off a cart item's metadata.
		 *
		 * @param {Object} item Cart item.
		 * @return {Array} Attendee objects, or an empty array.
		 */
		function getItemAttendees( item ) {
			return ( item && item.metadata && Array.isArray( item.metadata.attendees ) )
				? item.metadata.attendees
				: [];
		}

		function attendeeSummary( item ) {
			var attendees = getItemAttendees( item );
			return Array.from( { length: item.quantity }, function ( _, index ) {
				var attendee = attendees[ index ] || {};
				if ( 'self' === attendee.beneficiary_type ) return 'You';
				if ( 'named' === attendee.beneficiary_type && attendee.beneficiary_name ) return attendee.beneficiary_name;
				return signedIn ? 'Defaults to you' : 'Defaults to buyer';
			} ).join( ', ' );
		}
		function refreshAttendeeSummary( item ) {
			var row = Array.from( tbodyEl.querySelectorAll( 'tr[data--item-id]' ) ).find( function ( el ) { return el.getAttribute( 'data--item-id' ) === String( item.id ); } );
			var summary = row && row.querySelector( '.agend-shop-attendee-summary' );
			if ( summary ) summary.textContent = attendeeSummary( item );
		}

		/**
		 * Fetches (and caches) the attendee-field definitions for an event.
		 *
		 * @param {string} slug Event slug.
		 * @return {Promise<Array>} Resolves with the field definitions array.
		 */
		function fetchAttendeeFields( slug ) {
			if ( attendeeFieldsCache[ slug ] ) {
				return Promise.resolve( attendeeFieldsCache[ slug ] );
			}
			return fetch( restUrl + 'events/' + encodeURIComponent( slug ) + '/attendee-fields', {
				method: 'GET',
				headers: AgendCartSession.getHeaders(),
			} )
				.then( function ( res ) {
					return res.ok ? res.json() : { data: [] };
				} )
				.then( function ( body ) {
					var fields = ( body && body.data ) || [];
					attendeeFieldsCache[ slug ] = fields;
					return fields;
				} )
				.catch( function () {
					return [];
				} );
		}

		/**
		 * Builds the collapsible panel row that holds the attendee editor for an
		 * event-ticket line. The editor is rendered lazily on first expand.
		 *
		 * @param {Object} item Cart item.
		 * @return {HTMLElement} A hidden <tr> element.
		 */
		function buildAttendeePanelRow( item ) {
			var tr = document.createElement( 'tr' );
			tr.className = 'agend-shop-attendee-panel';
			tr.dataset.itemId = item.id;
			tr.hidden = true;

			var td = document.createElement( 'td' );
			td.colSpan = 5;
			var body = document.createElement( 'div' );
			body.className = 'agend-shop-attendee-panel__body';
			td.appendChild( body );
			tr.appendChild( td );
			return tr;
		}

		/**
		 * Expands or collapses the attendee editor for an item, rendering the seat
		 * editor on first expand.
		 *
		 * @param {Object}      item Cart item.
		 * @param {HTMLElement} btn  The toggle button.
		 */
		function toggleAttendeePanel( item, btn ) {
			var panel = tbodyEl.querySelector(
				'tr.agend-shop-attendee-panel[data-item-id="' + item.id + '"]'
			);
			if ( ! panel ) {
				return;
			}
			if ( panel.hidden ) {
				openAttendeePanel( item, panel, btn );
			} else {
				panel.hidden = true;
				if ( btn ) {
					btn.setAttribute( 'aria-expanded', 'false' );
				}
			}
		}

		/**
		 * Opens (and lazily renders) an attendee panel for an item.
		 *
		 * @param {Object}      item  Cart item.
		 * @param {HTMLElement} panel The panel <tr>.
		 * @param {HTMLElement} btn   The toggle button (optional).
		 */
		function openAttendeePanel( item, panel, btn ) {
			panel.hidden = false;
			if ( btn ) {
				btn.setAttribute( 'aria-expanded', 'true' );
			}
			var body = panel.querySelector( '.agend-shop-attendee-panel__body' );
			if ( ! body.dataset.rendered ) {
				body.dataset.rendered = '1';
				body.textContent = 'Loading attendee details…';
				fetchAttendeeFields( item.metadata.event_slug ).then( function ( fields ) {
					renderAttendeeSeats( body, item, fields );
				} );
			}
		}

		/**
		 * Returns the persisted attendee at a seat index, or null when unset.
		 *
		 * @param {Object} item  Cart item.
		 * @param {number} index Seat index.
		 * @return {Object|null} Attendee object or null.
		 */
		function attendeeAt( item, index ) {
			var attendees = getItemAttendees( item );
			return attendees[ index ] || null;
		}

		/**
		 * Whether a seat has meaningful saved attendee details (a named attendee,
		 * or any custom-field values). An unnamed placeholder counts as unset.
		 *
		 * @param {Object|null} attendee Attendee object.
		 * @return {boolean} True when the seat is saved.
		 */
		function seatIsSaved( attendee ) {
			if ( ! attendee ) {
				return false;
			}
			if ( 'named' === attendee.beneficiary_type || 'self' === attendee.beneficiary_type ) {
				return true;
			}
			return !! ( attendee.custom_fields && Object.keys( attendee.custom_fields ).length );
		}

		/**
		 * PUTs a new quantity for a line and resolves with the parsed response.
		 * Does no DOM work and does not unlock — callers own the UI update so the
		 * open attendee form is adjusted in place rather than rebuilt.
		 *
		 * @param {Object} item     Cart item.
		 * @param {number} quantity New quantity.
		 * @return {Promise<Object>} Resolves with the update response payload.
		 */
		function putItemQuantity( item, quantity ) {
			var headers = AgendCartSession.getHeaders();
			headers[ 'Content-Type' ] = 'application/json';

			return fetch( restUrl + 'cart/item/update', {
				method: 'PUT',
				headers: headers,
				body: JSON.stringify( { quantity: quantity, itemId: item.id } ),
			} ).then( function ( response ) {
				if ( ! response.ok ) {
					return response.json().then( function ( data ) {
						throw new Error( ( data && data.message ) || 'Unable to update tickets.' );
					} );
				}
				return response.json();
			} );
		}

		/**
		 * Updates an existing row's quantity input, subtotal, and the cart total in
		 * place from an update response, without rebuilding the row or the attendee
		 * form (SPEC-CORE-20260721 US-5.3).
		 *
		 * @param {Object} item     Cart item.
		 * @param {number} quantity New quantity.
		 * @param {Object} data     Update response payload (for `meta.totals`).
		 */
		function applyRowTotals( item, quantity, data ) {
			var row = document.querySelector( 'tr[data--item-id="' + item.id + '"]' );
			if ( row ) {
				var qtyInput = row.querySelector( '.agend-shop-atc-qty-input' );
				if ( qtyInput ) {
					qtyInput.value = quantity;
					qtyInput.setAttribute( 'data-prev-value', quantity );
				}
				var subtotal = row.querySelector( 'td[data-label="Subtotal"]' );
				if ( subtotal ) {
					subtotal.textContent = formatCurrency( item.unit_amount * quantity, item.currency );
				}
			}

			var totals = ( data && data.meta && data.meta.totals ) || {};
			var totalAmount = ( undefined !== totals.newTotalAmount ) ? totals.newTotalAmount : totals.newTotalAmmount;
			if ( totalAmountEl && undefined !== totalAmount ) {
				totalAmountEl.textContent = formatCurrency( totalAmount, totals.currency || item.currency );
				updateCheckoutLabel( totalAmount, totals.currency || item.currency );
			}
			scheduleCheckoutOptions();
			document.dispatchEvent( new CustomEvent( 'agend:cart:updated:total', {
				detail: { count: totals.newTotalCount, amount: totalAmount },
			} ) );
		}

		/**
		 * Returns the live editor state for an open, rendered attendee panel, or
		 * null when the panel is closed or not yet rendered.
		 *
		 * @param {string} itemId Cart item id.
		 * @return {Object|null} Editor state or null.
		 */
		function getOpenEditor( itemId ) {
			var panel = tbodyEl.querySelector(
				'tr.agend-shop-attendee-panel[data-item-id="' + itemId + '"]'
			);
			if ( ! panel || panel.hidden ) {
				return null;
			}
			var body = panel.querySelector( '.agend-shop-attendee-panel__body' );
			return ( body && body._editor ) ? body._editor : null;
		}

		/**
		 * Forces a closed panel to re-render from fresh item state the next time it
		 * is opened (the item object is mutated in place by the quantity flows).
		 *
		 * @param {string} itemId Cart item id.
		 */
		function invalidateClosedPanel( itemId ) {
			var panel = tbodyEl.querySelector(
				'tr.agend-shop-attendee-panel[data-item-id="' + itemId + '"]'
			);
			if ( ! panel ) {
				return;
			}
			var body = panel.querySelector( '.agend-shop-attendee-panel__body' );
			if ( body ) {
				delete body.dataset.rendered;
				body._editor = null;
			}
		}

		/**
		 * Appends one empty seat form to an open editor, preserving every existing
		 * seat's current (possibly unsaved) input.
		 *
		 * @param {Object} editor Open editor state.
		 */
		function appendEditorSeat( editor ) {
			var index = editor.seatEls.length;
			var seat = buildSeat( index, {}, editor.fields, false, editor.onRemoveSeat );
			editor.seatsContainer.appendChild( seat.el );
			editor.seatEls.push( seat.refs );
		}

		/**
		 * Grows an open editor's seat forms to match a target count (used on
		 * increment). Existing seat input is left untouched.
		 *
		 * @param {Object} editor      Open editor state.
		 * @param {number} targetCount Desired number of seats.
		 */
		function growEditorSeats( editor, targetCount ) {
			while ( editor.seatEls.length < targetCount ) {
				appendEditorSeat( editor );
			}
		}

		/**
		 * Removes the dropped seats from an open editor in place (preserving the
		 * retained seats' current input) and renumbers the remaining seats. The
		 * seatEls array is mutated in place so captured references stay valid.
		 *
		 * @param {Object}   editor      Open editor state.
		 * @param {number[]} keepIndices Old seat indices that survive.
		 */
		function shrinkEditorSeats( editor, keepIndices ) {
			var keepSet = {};
			keepIndices.forEach( function ( i ) {
				keepSet[ i ] = true;
			} );

			var retainedRefs = [];
			editor.seatEls.forEach( function ( refs, i ) {
				if ( keepSet[ i ] ) {
					retainedRefs.push( refs );
				} else if ( refs.el && refs.el.parentNode ) {
					refs.el.parentNode.removeChild( refs.el );
				}
			} );

			editor.seatEls.length = 0;
			Array.prototype.push.apply( editor.seatEls, retainedRefs );

			editor.seatEls.forEach( function ( refs, i ) {
				if ( refs.legend ) {
					refs.legend.textContent = 'Attendee ' + ( i + 1 );
				}
			} );
		}

		/**
		 * Inserts the "choose which ticket to remove" hint into an open editor once.
		 *
		 * @param {Object} editor Open editor state.
		 */
		function showRemovalHint( editor ) {
			if ( editor.body.querySelector( '.agend-shop-attendee-panel__removal-hint' ) ) {
				return;
			}
			editor.body.insertBefore( buildRemovalHint(), editor.seatsContainer );
		}

		/**
		 * Builds the removal-hint paragraph shown when every seat is already saved.
		 *
		 * @return {HTMLElement} The hint element.
		 */
		function buildRemovalHint() {
			var hint = document.createElement( 'p' );
			hint.className = 'agend-shop-attendee-panel__removal-hint';
			hint.textContent = 'Every ticket has attendee details. Use “Remove ticket” to choose which one to remove.';
			return hint;
		}

		/**
		 * Increases an event-ticket line's quantity: persists the new quantity,
		 * updates the row total in place, and appends a seat form to the open editor
		 * (a new empty entry) without rebuilding the row or form.
		 *
		 * @param {Object} item     Cart item.
		 * @param {number} quantity New quantity.
		 */
		function changeEventQty( item, quantity ) {
			lockInputs( item.id );
			putItemQuantity( item, quantity )
				.then( function ( data ) {
					item.quantity = quantity;
					refreshAttendeeSummary( item );
					applyRowTotals( item, quantity, data );
					var editor = getOpenEditor( item.id );
					if ( editor ) {
						growEditorSeats( editor, quantity );
					} else {
						invalidateClosedPanel( item.id );
					}
					// Note: do NOT dispatch 'agend:cart:updated' — the cart-view
					// self-listens on it and reloads the whole widget, which would
					// clobber this in-place update. applyRowTotals already fired
					// 'agend:cart:updated:total' to refresh the header badge.
				} )
				.catch( function ( err ) {
					showMessage( ( err && err.message ) || 'Unable to update tickets. Please try again.', 'error' );
				} )
				.finally( function () {
					unlockInputs( item.id );
				} );
		}

		/**
		 * Handles a decrement on an event-ticket line. If any seat is still unset,
		 * one unset seat is dropped automatically. If every seat has saved attendee
		 * details, the per-seat Remove controls are surfaced (without discarding any
		 * unsaved input in an open form) so the user chooses which ticket to remove
		 * (SPEC-CORE-20260721 US-5.3).
		 *
		 * @param {Object} item Cart item.
		 */
		function handleTicketDecrement( item ) {
			if ( item.quantity <= 1 ) {
				removeItem( item.id );
				return;
			}

			var unsetIndex = -1;
			for ( var i = 0; i < item.quantity; i++ ) {
				if ( ! seatIsSaved( attendeeAt( item, i ) ) ) {
					unsetIndex = i;
					break;
				}
			}

			if ( unsetIndex !== -1 ) {
				// Default path: drop an unset seat, keeping every saved seat.
				var keep = [];
				for ( var j = 0; j < item.quantity; j++ ) {
					if ( j !== unsetIndex ) {
						keep.push( j );
					}
				}
				applyRemoval( item, keep );
				return;
			}

			// All seats saved: reveal the per-seat Remove controls. If the editor is
			// already open, add the hint in place (never re-render — that would drop
			// unsaved input); otherwise open it (which renders fresh) with the hint.
			var openEditor = getOpenEditor( item.id );
			if ( openEditor ) {
				showRemovalHint( openEditor );
				openEditor.body.parentNode.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
				return;
			}

			var panel = tbodyEl.querySelector(
				'tr.agend-shop-attendee-panel[data-item-id="' + item.id + '"]'
			);
			var btn = document.querySelector(
				'.agend-shop-btn-attendees[data-item-id="' + item.id + '"]'
			);
			if ( panel ) {
				var body = panel.querySelector( '.agend-shop-attendee-panel__body' );
				if ( body ) {
					body.dataset.removalHint = '1';
				}
				openAttendeePanel( item, panel, btn );
				panel.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
			}
		}

		/**
		 * Removes seats from an event-ticket line, honouring saved attendee details.
		 *
		 * The retained seats' attendees are written first (POST /cart/items/attendees)
		 * and the quantity is then reduced (PUT /cart/item/update, which keeps the
		 * leading N attendees), so the correct seats survive rather than whichever
		 * happened to be trailing. The row total and the open form are then updated
		 * in place — the dropped seat forms are removed and the rest renumbered,
		 * with no widget or form rebuild. Removing every seat deletes the line.
		 *
		 * @param {Object}   item        Cart item.
		 * @param {number[]} keepIndices Seat indices to retain, in order.
		 */
		function applyRemoval( item, keepIndices ) {
			if ( ! keepIndices.length ) {
				removeItem( item.id );
				return;
			}

			var retained = keepIndices.map( function ( index ) {
				var attendee = attendeeAt( item, index );
				return seatIsSaved( attendee ) ? attendee : { beneficiary_type: 'unnamed' };
			} );
			var newQty = keepIndices.length;

			lockInputs( item.id );

			var headers = AgendCartSession.getHeaders();
			headers[ 'Content-Type' ] = 'application/json';

			fetch( restUrl + 'cart/items/attendees', {
				method: 'POST',
				headers: headers,
				body: JSON.stringify( { itemId: item.id, attendees: retained } ),
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						return response.json().then( function ( data ) {
							throw new Error( ( data && data.message ) || 'Unable to update tickets.' );
						} );
					}
					scheduleCheckoutOptions();
					// Reduce the quantity; the retained attendees are already the
					// leading N so the update's truncation is a no-op.
					return putItemQuantity( item, newQty );
				} )
				.then( function ( data ) {
					item.quantity = newQty;
					item.metadata = item.metadata || {};
					item.metadata.attendees = retained;
					refreshAttendeeSummary( item );
					applyRowTotals( item, newQty, data );
					var editor = getOpenEditor( item.id );
					if ( editor ) {
						shrinkEditorSeats( editor, keepIndices );
					} else {
						invalidateClosedPanel( item.id );
					}
					// Note: do NOT dispatch 'agend:cart:updated' — the cart-view
					// self-listens on it and reloads the whole widget, which would
					// clobber this in-place update. applyRowTotals already fired
					// 'agend:cart:updated:total' to refresh the header badge.
				} )
				.catch( function ( err ) {
					showMessage( ( err && err.message ) || 'Unable to update tickets. Please try again.', 'error' );
				} )
				.finally( function () {
					unlockInputs( item.id );
				} );
		}

		/**
		 * Renders one editable block per seat (item quantity) plus a Save button.
		 *
		 * @param {HTMLElement} body   Panel body container.
		 * @param {Object}      item   Cart item.
		 * @param {Array}       fields Attendee-field definitions for the event.
		 */
		function renderAttendeeSeats( body, item, fields ) {
			body.textContent = '';

			var intro = document.createElement( 'p' );
			intro.className = 'agend-shop-attendee-panel__intro';
			intro.textContent = 'Choose who each ticket is for and save the attendee details. Unassigned tickets default to the buyer.';
			body.appendChild( intro );

			var seatsContainer = document.createElement( 'div' );
			seatsContainer.className = 'agend-shop-attendee-seats';

			// Live editor state, kept in sync as seats are added/removed in place so
			// a quantity change never rebuilds the form (SPEC-CORE-20260721 US-5.3).
			var editor = {
				item: item,
				fields: fields,
				body: body,
				seatsContainer: seatsContainer,
				seatEls: [],
			};

			// The seat's metadata index is its current DOM position, derived at click
			// time so it stays correct after seats are added or removed.
			editor.onRemoveSeat = function ( seatEl ) {
				var index = Array.prototype.indexOf.call( seatsContainer.children, seatEl );
				if ( index < 0 ) {
					return;
				}
				var attendee = getItemAttendees( item )[ index ] || null;
				var saved = seatIsSaved( attendee );

				// Perform the actual removal (line delete when it is the last seat).
				function doRemove() {
					if ( item.quantity <= 1 ) {
						removeItem( item.id );
						return;
					}
					var keep = [];
					for ( var k = 0; k < item.quantity; k++ ) {
						if ( k !== index ) {
							keep.push( k );
						}
					}
					applyRemoval( item, keep );
				}

				// Only a seat with saved details needs confirming; unset seats are
				// removed immediately.
				if ( ! saved ) {
					doRemove();
					return;
				}

				var who = attendee.beneficiary_name || 'this attendee';
				showConfirm( {
					message: ( item.quantity <= 1 )
						? 'Remove this ticket from your cart? ' + who + '’s saved details will be discarded.'
						: 'Remove the ticket for ' + who + '? Their saved details will be discarded.',
					confirmLabel: 'Remove ticket',
					cancelLabel: 'Cancel',
					onConfirm: doRemove,
				} );
			};

			// A decrement on a fully-assigned line asks the user to choose which
			// ticket to remove here, via the per-seat Remove controls.
			if ( body.dataset.removalHint ) {
				delete body.dataset.removalHint;
				body.appendChild( buildRemovalHint() );
			}

			body.appendChild( seatsContainer );

			var existing = getItemAttendees( item );
			for ( var i = 0; i < item.quantity; i++ ) {
				var seat = buildSeat( i, existing[ i ] || {}, fields, seatIsSaved( existing[ i ] || null ), editor.onRemoveSeat );
				editor.seatEls.push( seat.refs );
				seatsContainer.appendChild( seat.el );
			}

			var actions = document.createElement( 'div' );
			actions.className = 'agend-shop-attendee-panel__actions';
			var saveBtn = document.createElement( 'button' );
			saveBtn.className = 'agend-shop-btn-attendees-save';
			saveBtn.textContent = 'Save attendees';
			var status = document.createElement( 'span' );
			status.className = 'agend-shop-attendee-panel__status';
			actions.appendChild( saveBtn );
			actions.appendChild( status );
			body.appendChild( actions );

			saveBtn.addEventListener( 'click', function () {
				saveAttendees( item, editor.seatEls, fields, saveBtn, status );
			} );

			body._editor = editor;
		}

		/**
		 * Sets a seat's saved/unset status badge.
		 *
		 * @param {HTMLElement} badge The badge element.
		 * @param {boolean}     saved Whether the seat has saved details.
		 */
		function setSeatBadge( badge, saved ) {
			badge.className = 'agend-shop-attendee-seat__badge ' + ( saved ? 'is-saved' : 'is-unset' );
			badge.textContent = saved ? 'Saved' : 'Not set';
		}

		/**
		 * Builds a single seat block (name, email, and custom-field inputs).
		 *
		 * @param {number} index    Zero-based seat index.
		 * @param {Object} attendee Existing attendee data for this seat.
		 * @param {Array}  fields   Attendee-field definitions.
		 * @return {{el: HTMLElement, refs: Object}} The block and its input refs.
		 */
		function buildSeat( index, attendee, fields, saved, onRemove ) {
			var wrap = document.createElement( 'fieldset' );
			wrap.className = 'agend-shop-attendee-seat';

			var header = document.createElement( 'div' );
			header.className = 'agend-shop-attendee-seat__header';
			var legend = document.createElement( 'legend' );
			legend.textContent = 'Attendee ' + ( index + 1 );
			header.appendChild( legend );

			var badge = document.createElement( 'span' );
			setSeatBadge( badge, saved );
			header.appendChild( badge );

			var removeSeatBtn = document.createElement( 'button' );
			removeSeatBtn.type = 'button';
			removeSeatBtn.className = 'agend-shop-btn-remove-seat';
			removeSeatBtn.textContent = 'Remove ticket';
			removeSeatBtn.addEventListener( 'click', function () {
				onRemove( wrap );
			} );
			header.appendChild( removeSeatBtn );

			wrap.appendChild( header );

			var choice = null;
			if ( signedIn ) {
				choice = document.createElement( 'select' );
				choice.className = 'agend-shop-attendee-beneficiary';
				[ [ '', 'Default to buyer' ], [ 'self', 'This ticket is for me' ], [ 'named', 'Someone else' ] ].forEach( function ( option ) {
					var el = document.createElement( 'option' ); el.value = option[ 0 ]; el.textContent = option[ 1 ]; choice.appendChild( el );
				} );
				choice.value = attendee.beneficiary_type === 'self' ? 'self' : ( attendee.beneficiary_type === 'named' ? 'named' : '' );
				wrap.appendChild( labelled( 'Ticket holder', choice ) );
			}

			var nameInput = document.createElement( 'input' );
			nameInput.type = 'text';
			nameInput.className = 'agend-shop-attendee-name';
			nameInput.placeholder = 'Full name';
			nameInput.value = attendee.beneficiary_name || '';
			var nameLabel = labelled( 'Name', nameInput );
			wrap.appendChild( nameLabel );

			var emailInput = document.createElement( 'input' );
			emailInput.type = 'email';
			emailInput.className = 'agend-shop-attendee-email';
			emailInput.placeholder = 'Email';
			emailInput.value = attendee.beneficiary_email || '';
			var emailLabel = labelled( 'Email', emailInput );
			wrap.appendChild( emailLabel );
			if ( choice ) {
				var profileNote = document.createElement( 'p' );
				profileNote.textContent = 'We’ll use your member details for this ticket.';
				wrap.appendChild( profileNote );
				var syncChoice = function () {
					nameLabel.hidden = emailLabel.hidden = choice.value !== 'named';
					profileNote.hidden = choice.value !== 'self';
				};
				choice.addEventListener( 'change', syncChoice ); syncChoice();
			} else if ( buyerName && buyerEmail ) {
				var useBuyer = document.createElement( 'button' );
				useBuyer.type = 'button'; useBuyer.className = 'agend-shop-use-for-buyer'; useBuyer.textContent = 'Use for buyer';
				useBuyer.addEventListener( 'click', function () { buyerName.value = nameInput.value; buyerEmail.value = emailInput.value; } );
				wrap.appendChild( useBuyer );
			}

			var customValues = ( attendee && attendee.custom_fields ) || {};
			var fieldRefs = [];
			fields.forEach( function ( field ) {
				var input = buildFieldInput( field, customValues[ field.field_key ] );
				fieldRefs.push( { field: field, input: input } );
				wrap.appendChild(
					labelled( field.name + ( field.is_required ? ' *' : '' ), input )
				);
			} );

			return {
				el: wrap,
				refs: {
					el: wrap,
					legend: legend,
					nameInput: nameInput,
					choice: choice,
					emailInput: emailInput,
					fieldRefs: fieldRefs,
					badge: badge,
				},
			};
		}

		/**
		 * Wraps a control in a labelled container.
		 *
		 * @param {string}      text    Label text.
		 * @param {HTMLElement} control The form control.
		 * @return {HTMLElement} A labelled <label> element.
		 */
		function labelled( text, control ) {
			var label = document.createElement( 'label' );
			label.className = 'agend-shop-attendee-field';
			var span = document.createElement( 'span' );
			span.className = 'agend-shop-attendee-field__label';
			span.textContent = text;
			label.appendChild( span );
			label.appendChild( control );
			return label;
		}

		/**
		 * Builds a form control for an attendee custom field, honouring the
		 * canonical @agend/custom-fields type vocabulary.
		 *
		 * @param {Object} field Field definition.
		 * @param {*}      value Existing value for this field.
		 * @return {HTMLElement} The form control.
		 */
		function buildFieldInput( field, value ) {
			var options = Array.isArray( field.options ) ? field.options : [];
			if ( 'select' === field.field_type || 'radio' === field.field_type ) {
				var select = document.createElement( 'select' );
				var blank = document.createElement( 'option' );
				blank.value = '';
				blank.textContent = '—';
				select.appendChild( blank );
				options.forEach( function ( opt ) {
					var o = document.createElement( 'option' );
					o.value = opt.value;
					o.textContent = opt.label;
					if ( value === opt.value ) {
						o.selected = true;
					}
					select.appendChild( o );
				} );
				return select;
			}
			if ( 'toggle' === field.field_type ) {
				var checkbox = document.createElement( 'input' );
				checkbox.type = 'checkbox';
				checkbox.checked = true === value || 'true' === value;
				return checkbox;
			}
			if ( 'paragraph' === field.field_type ) {
				var textarea = document.createElement( 'textarea' );
				textarea.value = ( value === undefined || value === null ) ? '' : String( value );
				return textarea;
			}
			var input = document.createElement( 'input' );
			input.type = ( 'number' === field.field_type ) ? 'number'
				: ( 'date' === field.field_type ) ? 'date'
				: ( 'email' === field.field_type ) ? 'email'
				: 'text';
			input.value = ( value === undefined || value === null ) ? '' : String( value );
			return input;
		}

		/**
		 * Reads a single seat's field refs into a cart attendee object.
		 *
		 * A seat with a name becomes a `named` attendee (an email is required for
		 * a named attendee by the cart schema); an empty seat is `unnamed` and
		 * defaults to the buyer at fulfilment. Custom-field values attach to
		 * either type.
		 *
		 * @param {Object} refs Seat input refs.
		 * @return {{attendee: Object|null, error: string|null}} Result.
		 */
		function readSeat( refs ) {
			var name = refs.nameInput.value.trim();
			var email = refs.emailInput.value.trim();

			var customFields = {};
			refs.fieldRefs.forEach( function ( ref ) {
				var control = ref.input;
				var val;
				if ( 'checkbox' === control.type ) {
					val = control.checked ? true : undefined;
				} else {
					val = control.value.trim ? control.value.trim() : control.value;
					if ( '' === val ) {
						val = undefined;
					}
				}
				if ( undefined !== val ) {
					customFields[ ref.field.field_key ] = val;
				}
			} );

			var attendee = { beneficiary_type: 'unnamed' };
			var choice = refs.choice ? refs.choice.value : ( name || email ? 'named' : '' );
			if ( 'self' === choice ) {
				attendee = { beneficiary_type: 'self' };
			} else if ( 'named' === choice ) {
				if ( ! name || ! email || ! refs.emailInput.validity.valid ) {
					return { attendee: null, error: 'Enter a name and valid email for this attendee.' };
				}
				attendee = { beneficiary_type: 'named', beneficiary_name: name, beneficiary_email: email };
			}
			if ( Object.keys( customFields ).length ) {
				attendee.custom_fields = customFields;
			}
			return { attendee: attendee, error: null };
		}

		/**
		 * Collects every seat and persists the attendees for a line via
		 * `POST /cart/items/attendees`.
		 *
		 * @param {Object}      item    Cart item.
		 * @param {Array}       seatEls Per-seat input refs.
		 * @param {Array}       fields  Attendee-field definitions (unused here; kept for symmetry).
		 * @param {HTMLElement} saveBtn Save button.
		 * @param {HTMLElement} status  Status text element.
		 */
		function saveAttendees( item, seatEls, fields, saveBtn, status ) {
			var attendees = [];
			for ( var i = 0; i < seatEls.length; i++ ) {
				var result = readSeat( seatEls[ i ] );
				if ( result.error ) {
					status.className = 'agend-shop-attendee-panel__status is-error';
					status.textContent = result.error;
					return;
				}
				attendees.push( result.attendee );
			}

			saveBtn.disabled = true;
			status.className = 'agend-shop-attendee-panel__status';
			status.textContent = 'Saving…';

			var headers = AgendCartSession.getHeaders();
			headers[ 'Content-Type' ] = 'application/json';

			fetch( restUrl + 'cart/items/attendees', {
				method: 'POST',
				headers: headers,
				body: JSON.stringify( { itemId: item.id, attendees: attendees } ),
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						return response.json().then( function ( data ) {
							var message = ( data && data.message ) ? data.message : 'Unable to save attendees. Please try again.';
							status.className = 'agend-shop-attendee-panel__status is-error';
							status.textContent = message;
						} );
					}
					status.className = 'agend-shop-attendee-panel__status is-success';
					status.textContent = 'Attendees saved.';
					// Keep the item's local copy in sync so a re-open shows the saved data.
					item.metadata = item.metadata || {};
					item.metadata.attendees = attendees;
					refreshAttendeeSummary( item );
					scheduleCheckoutOptions();

					// Refresh each seat's saved/unset badge to reflect what was stored.
					for ( var b = 0; b < seatEls.length; b++ ) {
						if ( seatEls[ b ].badge ) {
							setSeatBadge( seatEls[ b ].badge, seatIsSaved( attendees[ b ] ) );
						}
					}
					return null;
				} )
				.catch( function () {
					status.className = 'agend-shop-attendee-panel__status is-error';
					status.textContent = 'Unable to save attendees. Please try again.';
				} )
				.finally( function () {
					saveBtn.disabled = false;
				} );
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

		// Clear cart — reuse the shared confirmation modal.
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				showConfirm( {
					message: 'Are you sure you want to clear your cart? This cannot be undone.',
					confirmLabel: 'Yes, clear cart',
					cancelLabel: 'Cancel',
					onConfirm: clearCart,
				} );
			} );
		}

		// Shared confirmation modal (replaces window.confirm). The Yes/No buttons
		// are bound once and dispatch to the pending callback set by showConfirm,
		// so any flow can raise a themed confirmation.
		if ( confirmYesBtn ) {
			confirmYesBtn.addEventListener( 'click', function () {
				var cb = pendingConfirm;
				hideConfirm();
				if ( cb ) {
					cb();
				}
			} );
		}
		if ( confirmNoBtn ) {
			confirmNoBtn.addEventListener( 'click', hideConfirm );
		}
		if ( confirmModal ) {
			// Cancel on overlay click (outside the inner box) or Escape.
			confirmModal.addEventListener( 'click', function ( e ) {
				if ( e.target === confirmModal ) {
					hideConfirm();
				}
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && ! confirmModal.hasAttribute( 'hidden' ) ) {
					hideConfirm();
				}
			} );
		}

		/**
		 * Builds a text element.
		 *
		 * @param {string} tag       Tag name.
		 * @param {string} className Class name.
		 * @param {string} text      Text content.
		 * @return {HTMLElement} The element.
		 */
		function textEl( tag, className, text ) {
			var node = document.createElement( tag );
			if ( className ) node.className = className;
			if ( undefined !== text && null !== text ) node.textContent = String( text );
			return node;
		}

		/**
		 * Formats a `YYYY-MM-DD` calendar date for display, without shifting
		 * it through the visitor's timezone.
		 *
		 * @param {string} value Calendar date.
		 * @return {string} Formatted date, or '' when unusable.
		 */
		function formatLocalDate( value ) {
			var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( value || '' );
			if ( ! match ) return '';
			var date = new Date( Date.UTC( Number( match[ 1 ] ), Number( match[ 2 ] ) - 1, Number( match[ 3 ] ) ) );
			try {
				return date.toLocaleDateString( undefined, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' } );
			} catch ( e ) {
				return value;
			}
		}

		/**
		 * A fresh idempotency key for one Pay on Invoice attempt.
		 *
		 * @return {string} Key.
		 */
		function newInvoiceKey() {
			if ( window.crypto && 'function' === typeof window.crypto.randomUUID ) {
				return 'wp-cart-' + window.crypto.randomUUID();
			}
			return 'wp-cart-' + Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2 );
		}

		/**
		 * The error text to show for a failed cart request: field messages
		 * from the platform first, then its message.
		 *
		 * @param {Object} body Response body.
		 * @return {string} Message.
		 */
		function errorMessage( body ) {
			var fields = body && body.data && body.data.body && body.data.body.error && body.data.body.error.details && body.data.body.error.details.fields;
			var messages = fields ? Object.keys( fields ).reduce( function ( all, key ) {
				return all.concat( fields[ key ] );
			}, [] ) : [];
			return messages.length ? messages.join( ' ' ) : ( ( body && body.message ) || 'An unexpected error occurred. Please try again.' );
		}

		/**
		 * Whether Pay on Invoice is on for this site. The WordPress route
		 * enforces the same setting.
		 *
		 * @return {boolean}
		 */
		function invoiceOffered() {
			var value = shopConfig.payOnInvoice;
			return signedIn && !! paymentEl && !! value && '0' !== value;
		}

		/**
		 * Refreshes only payment options after a successful cart mutation. Multiple
		 * writes (such as attendee removal followed by quantity) share one refresh;
		 * the open attendee editor is never re-rendered by this request.
		 */
		function scheduleCheckoutOptions() {
			invoiceKey = null;
			++optionsRequest;
			clearTimeout( optionsTimer );
			if ( ! invoiceOffered() || orderPlaced ) return;
			optionsTimer = setTimeout( function () {
				loadCheckoutOptions( cartLocked || 0 === cartTotal );
			}, 150 );
		}

		/**
		 * Fetches the payment options for the cart and shows the payment
		 * choice when Pay on Invoice is available. Card stays the only way to
		 * pay when it is not, or when the options cannot be loaded.
		 *
		 * @param {boolean} skip True when there is nothing to choose (a locked or free cart).
		 */
		function loadCheckoutOptions( skip ) {
			clearTimeout( optionsTimer );
			if ( ! invoiceOffered() ) {
				return;
			}
			var request = ++optionsRequest;
			if ( skip ) {
				renderPaymentChoice( null );
				return;
			}
			fetch( restUrl + 'cart/checkout-options', {
				method: 'GET',
				headers: AgendCartSession.getHeaders(),
			} )
				.then( function ( response ) {
					return response.ok ? response.json() : null;
				} )
				.then( function ( body ) {
					if ( request === optionsRequest && ! orderPlaced ) {
						renderPaymentChoice( body && body.data ? body.data.invoice : null );
					}
				} )
				.catch( function () {
					if ( request === optionsRequest && ! orderPlaced ) {
						renderPaymentChoice( null );
					}
				} );
		}

		/**
		 * The companies the buyer may invoice, with names where known.
		 *
		 * @param {Object} invoice Invoice option.
		 * @return {Array<{id: string, name: string}>}
		 */
		function invoiceCompanies( invoice ) {
			if ( Array.isArray( invoice.approvedCompanies ) ) {
				return invoice.approvedCompanies;
			}
			return ( invoice.approvedCompanyIds || [] ).map( function ( id ) {
				return { id: id, name: '' };
			} );
		}

		/**
		 * Renders the card / Pay on Invoice choice and the invoice details.
		 *
		 * @param {Object|null} invoice Invoice option from checkout-options, or null.
		 */
		function renderPaymentChoice( invoice ) {
			// Read the live entries before replacing just this payment section.
			var currentPo = paymentEl.querySelector( '.agend-shop-invoice-po' );
			var currentCompany = paymentEl.querySelector( '.agend-shop-invoice-company' );
			if ( currentPo ) invoiceEntries.po = currentPo.value;
			if ( currentCompany ) invoiceEntries.companyId = currentCompany.value;
			invoiceOption = invoice && invoice.available ? invoice : null;
			paymentEl.innerHTML = '';

			var helpfulReasons = [ 'ticket_capacity_unavailable', 'ticket_reservation_window_closed', 'event_unavailable', 'invoice_unavailable' ];
			if ( ! invoiceOption ) {
				paymentMethod = 'card';
				if ( invoice && invoice.reasonMessage && -1 !== helpfulReasons.indexOf( invoice.reason ) ) {
					paymentEl.appendChild( textEl( 'p', 'agend-shop-invoice-unavailable', 'Pay on Invoice is not available for this order. ' + invoice.reasonMessage ) );
					paymentEl.hidden = false;
				} else {
					paymentEl.hidden = true;
				}
				updateCheckoutLabel( cartTotal, cartCurrency );
				return;
			}

			var fieldset = document.createElement( 'fieldset' );
			fieldset.className = 'agend-shop-payment-choice';
			fieldset.appendChild( textEl( 'legend', '', 'How would you like to pay?' ) );
			[ [ 'card', 'Pay now by card' ], [ 'invoice', 'Pay on Invoice' ] ].forEach( function ( option ) {
				var label = document.createElement( 'label' );
				var radio = document.createElement( 'input' );
				radio.type = 'radio';
				radio.name = 'agend-shop-payment-method';
				radio.className = 'agend-shop-payment-method';
				radio.value = option[ 0 ];
				radio.checked = option[ 0 ] === paymentMethod;
				radio.addEventListener( 'change', function () {
					if ( radio.checked ) {
						paymentMethod = radio.value;
						details.hidden = 'invoice' !== paymentMethod;
						clearMessage();
						updateCheckoutLabel( cartTotal, cartCurrency );
					}
				} );
				label.appendChild( radio );
				label.appendChild( document.createTextNode( ' ' + option[ 1 ] ) );
				fieldset.appendChild( label );
			} );
			paymentEl.appendChild( fieldset );

			var details = document.createElement( 'div' );
			details.className = 'agend-shop-invoice-details';
			details.hidden = 'invoice' !== paymentMethod;

			var poLabel = textEl( 'label', 'agend-shop-invoice-field', invoiceOption.purchaseOrderRequired ? 'Purchase order number (required)' : 'Purchase order number (optional)' );
			var po = document.createElement( 'input' );
			po.type = 'text';
			po.className = 'agend-shop-invoice-po';
			po.maxLength = 100;
			po.autocomplete = 'off';
			po.required = !! invoiceOption.purchaseOrderRequired;
			po.value = invoiceEntries.po;
			po.addEventListener( 'input', function () {
				invoiceEntries.po = po.value;
			} );
			poLabel.appendChild( po );
			details.appendChild( poLabel );

			var companies = invoiceCompanies( invoiceOption );
			if ( companies.length > 1 ) {
				var companyLabel = textEl( 'label', 'agend-shop-invoice-field', 'Company to invoice' );
				var select = document.createElement( 'select' );
				select.className = 'agend-shop-invoice-company';
				select.required = true;
				select.appendChild( new Option( 'Choose a company', '' ) );
				companies.forEach( function ( company, index ) {
					select.appendChild( new Option( company.name || 'Company ' + ( index + 1 ), company.id ) );
				} );
				if ( ! companies.some( function ( company ) { return company.id === invoiceEntries.companyId; } ) ) {
					invoiceEntries.companyId = '';
				}
				select.value = invoiceEntries.companyId;
				select.addEventListener( 'change', function () {
					invoiceEntries.companyId = select.value;
				} );
				companyLabel.appendChild( select );
				details.appendChild( companyLabel );
			} else if ( 1 === companies.length ) {
				invoiceEntries.companyId = companies[ 0 ].id;
				details.appendChild( textEl( 'p', 'agend-shop-invoice-company-name', 'Company to invoice: ' + ( companies[ 0 ].name || 'Company 1' ) ) );
			} else {
				invoiceEntries.companyId = '';
			}

			var due = formatLocalDate( invoiceOption.dueLocalDate );
			var terms = '';
			if ( Number.isFinite( Number( invoiceOption.termsDays ) ) && null !== invoiceOption.termsDays ) {
				terms = 'Payment terms: ' + Number( invoiceOption.termsDays ) + ' days' + ( due ? ', due ' + due : '' ) + '.';
			} else if ( due ) {
				terms = 'Payment is due ' + due + '.';
			}
			if ( terms ) {
				details.appendChild( textEl( 'p', 'agend-shop-invoice-terms', terms ) );
			}
			if ( invoiceOption.paymentInstructions ) {
				details.appendChild( textEl( 'p', 'agend-shop-invoice-instructions', invoiceOption.paymentInstructions ) );
			}
			var holdsTickets = true === invoiceOption.hasReservedTickets
				|| ( hasEventTickets && invoiceOption.releasePolicies && 'payment_confirmed' === invoiceOption.releasePolicies.event_tickets );
			if ( holdsTickets ) {
				details.appendChild( textEl( 'p', 'agend-shop-invoice-tickets', 'Your event tickets are held for you until the invoice is paid.' ) );
			}
			paymentEl.appendChild( details );
			paymentEl.hidden = false;
			updateCheckoutLabel( cartTotal, cartCurrency );
		}

		/**
		 * Places the cart as an order on invoice, then shows the confirmation.
		 * The idempotency key is kept when the outcome is unknown (a network
		 * failure or a server error), so a retry cannot raise a second order,
		 * and replaced after a refusal.
		 */
		function submitInvoice() {
			var po      = paymentEl.querySelector( '.agend-shop-invoice-po' );
			var company = paymentEl.querySelector( '.agend-shop-invoice-company' );
			if ( po ) {
				po.value = po.value.trim();
				invoiceEntries.po = po.value;
				if ( ! po.reportValidity() ) return;
			}
			if ( company && ! company.reportValidity() ) return;

			invoiceKey = invoiceKey || newInvoiceKey();
			var payload = { paymentMethod: 'invoice', idempotencyKey: invoiceKey };
			if ( po && po.value ) payload.purchaseOrderNumber = po.value;
			var companies = invoiceCompanies( invoiceOption );
			if ( 1 === companies.length ) {
				payload.companyId = companies[ 0 ].id;
			} else if ( company && company.value ) {
				payload.companyId = company.value;
			}

			checkoutBtn.disabled = true;
			clearMessage();
			var headers = AgendCartSession.getHeaders();
			headers[ 'Content-Type' ] = 'application/json';

			fetch( restUrl + 'cart/checkout/invoice', {
				method: 'POST',
				headers: headers,
				body: JSON.stringify( payload ),
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { status: response.status, data: data };
					} );
				} )
				.then( function ( result ) {
					var order = result.data && result.data.data;
					if ( 200 === result.status && order && order.orderId ) {
						showInvoiceConfirmation( order );
						return;
					}
					if ( result.status >= 400 && result.status < 500 ) {
						invoiceKey = null;
					}
					showMessage( errorMessage( result.data ), 'error' );
					checkoutBtn.disabled = false;
				} )
				.catch( function () {
					showMessage( 'An unexpected error occurred. Please try again.', 'error' );
					checkoutBtn.disabled = false;
				} );
		}

		/**
		 * Replaces the cart with the placed order: reference, amount, due date,
		 * purchase order number, how to pay, and the invoice in the member
		 * portal when the site knows its address.
		 *
		 * @param {Object} order Invoice acceptance response.
		 */
		function showInvoiceConfirmation( order ) {
			orderPlaced = true;
			contentEl.setAttribute( 'hidden', '' );
			lockedNoticeEl.setAttribute( 'hidden', '' );
			emptyEl.setAttribute( 'hidden', '' );
			if ( ! confirmationEl ) {
				return;
			}
			confirmationEl.innerHTML = '';
			confirmationEl.appendChild( textEl( 'h2', 'agend-shop-invoice-confirmation__title', 'Order placed' ) );
			confirmationEl.appendChild( textEl( 'p', '', 'Your order is placed and awaiting payment.' ) );

			var list = document.createElement( 'dl' );
			list.className = 'agend-shop-invoice-summary';
			var due = formatLocalDate( order.dueLocalDate );
			[
				[ 'Order reference', order.orderNumber ],
				[ 'Amount', Number.isFinite( Number( order.totalCents ) ) && order.currency ? formatCurrency( Number( order.totalCents ), order.currency ) : '' ],
				[ 'Payment due', due ],
				[ 'Purchase order number', order.purchaseOrderNumber ],
			].forEach( function ( row ) {
				if ( ! row[ 1 ] ) return;
				list.appendChild( textEl( 'dt', '', row[ 0 ] ) );
				list.appendChild( textEl( 'dd', '', row[ 1 ] ) );
			} );
			confirmationEl.appendChild( list );

			if ( order.paymentInstructions ) {
				confirmationEl.appendChild( textEl( 'h3', '', 'How to pay' ) );
				confirmationEl.appendChild( textEl( 'p', 'agend-shop-invoice-instructions', order.paymentInstructions ) );
			}
			var holdsTickets = ( order.lines || [] ).some( function ( line ) {
				return 'event_tickets' === line.productType && 'payment_confirmed' === line.releasePolicy;
			} );
			if ( holdsTickets ) {
				confirmationEl.appendChild( textEl( 'p', 'agend-shop-invoice-tickets', 'Your event tickets are held for you until the invoice is paid.' ) );
			}

			var actions = textEl( 'p', 'agend-shop-invoice-confirmation__actions' );
			var portal  = shopConfig.portalHomeUrl ? String( shopConfig.portalHomeUrl ).replace( /\/$/, '' ) : '';
			if ( portal && /^[0-9a-f-]{36}$/i.test( order.orderId ) ) {
				var invoiceLink = textEl( 'a', 'agend-shop-invoice-link', 'View your invoice' );
				invoiceLink.href = portal + '/invoices/' + encodeURIComponent( order.orderId );
				actions.appendChild( invoiceLink );
			}
			var browse = textEl( 'a', 'agend-shop-keep-browsing', 'Keep browsing' );
			browse.href = continueUrl || ( wrapper.querySelector( '.agend-shop-keep-browsing' ) || {} ).href || '/';
			actions.appendChild( browse );
			confirmationEl.appendChild( actions );

			confirmationEl.removeAttribute( 'hidden' );
			confirmationEl.focus();
			document.dispatchEvent( new CustomEvent( 'agend:cart:updated' ) );
		}

		// Remember the way back to this cart for the member login page.
		if ( signInLink ) {
			signInLink.addEventListener( 'click', function () {
				if ( -1 === signInLink.href.indexOf( 'agend_cart_signin=1' ) ) {
					return;
				}
				try {
					window.sessionStorage.setItem( 'agend_cart_signin_return', JSON.stringify( {
						target: window.location.href,
						expires: Date.now() + 30 * 60 * 1000,
					} ) );
				} catch ( e ) {
					// Restricted storage still permits sign-in.
				}
			} );
		}

		// Proceed to Checkout.
		if ( checkoutBtn ) {
			checkoutBtn.addEventListener( 'click', function () {
				if ( 'invoice' === paymentMethod && invoiceOption ) {
					submitInvoice();
					return;
				}
				var cartUrl = shopConfig.cartPageUrl || window.location.href;
				var successUrl = new URL( cartUrl, window.location.href );
				successUrl.searchParams.set( 'checkout', 'success' );
				var payload = {
					successUrl: shopConfig.checkoutSuccessUrl || successUrl.href,
					cancelUrl: shopConfig.checkoutCancelUrl || cartUrl,
				};
				if ( buyerName && buyerEmail ) {
					buyerName.value = buyerName.value.trim();
					buyerEmail.value = buyerEmail.value.trim();
					if ( ! buyerName.reportValidity() || ! buyerEmail.reportValidity() ) {
						return;
					}
					payload.guest_buyer = { name: buyerName.value, email: buyerEmail.value };
				}
				checkoutBtn.disabled = true;
				clearMessage();

				var headers = AgendCartSession.getHeaders();
				headers[ 'Content-Type' ] = 'application/json';

				fetch( restUrl + 'cart/checkout', {
					method: 'POST',
					headers: headers,
					body: JSON.stringify( payload ),
				} )
					.then( function ( response ) {
						return response.json().then( function ( data ) {
							return { status: response.status, data: data };
						} );
					} )
					.then( function ( result ) {
						var checkoutData = result.data.data;
						var checkoutUrl = checkoutData && ( checkoutData.checkoutUrl || ( checkoutData.groups && checkoutData.groups[ 0 ] && checkoutData.groups[ 0 ].checkoutUrl ) );
						if ( 200 === result.status && checkoutUrl ) {
							window.location.href = checkoutUrl;
						} else {
							showMessage( errorMessage( result.data ), 'error' );
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

		// Cancel checkout and unlock the cart for editing.
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
