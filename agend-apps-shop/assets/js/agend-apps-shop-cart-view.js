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

			// Attendee editor toggle for event-ticket lines that carry their event
			// slug (SPEC-CORE-20260721 US-5.3). Toggles the sibling panel row.
			var attendeesBtn = null;
			if ( isEventTicketWithEvent( item ) && ! isLocked ) {
				attendeesBtn = document.createElement( 'button' );
				attendeesBtn.className = 'agend-shop-btn-attendees';
				attendeesBtn.dataset.itemId = item.id;
				attendeesBtn.setAttribute( 'aria-expanded', 'false' );
				attendeesBtn.textContent = 'Attendees';
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
			if ( 'named' === attendee.beneficiary_type ) {
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
			}
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
					// Reduce the quantity; the retained attendees are already the
					// leading N so the update's truncation is a no-op.
					return putItemQuantity( item, newQty );
				} )
				.then( function ( data ) {
					item.quantity = newQty;
					item.metadata = item.metadata || {};
					item.metadata.attendees = retained;
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
			intro.textContent = 'Assign attendees for each ticket. Leave a seat blank to assign it to yourself.';
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

				if ( item.quantity <= 1 ) {
					if ( ! saved || window.confirm( 'Remove this ticket from your cart?' ) ) {
						removeItem( item.id );
					}
					return;
				}
				if ( saved ) {
					var who = attendee.beneficiary_name || 'this attendee';
					if ( ! window.confirm( 'Remove the ticket for ' + who + '? Their saved details will be discarded.' ) ) {
						return;
					}
				}
				var keep = [];
				for ( var k = 0; k < item.quantity; k++ ) {
					if ( k !== index ) {
						keep.push( k );
					}
				}
				applyRemoval( item, keep );
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

			var nameInput = document.createElement( 'input' );
			nameInput.type = 'text';
			nameInput.className = 'agend-shop-attendee-name';
			nameInput.placeholder = 'Full name';
			nameInput.value = attendee.beneficiary_name || '';
			wrap.appendChild( labelled( 'Name', nameInput ) );

			var emailInput = document.createElement( 'input' );
			emailInput.type = 'email';
			emailInput.className = 'agend-shop-attendee-email';
			emailInput.placeholder = 'Email';
			emailInput.value = attendee.beneficiary_email || '';
			wrap.appendChild( labelled( 'Email', emailInput ) );

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

			var attendee;
			if ( name ) {
				if ( ! email ) {
					return { attendee: null, error: 'Enter an email for ' + name + ', or clear the name.' };
				}
				attendee = { beneficiary_type: 'named', beneficiary_name: name, beneficiary_email: email };
			} else {
				attendee = { beneficiary_type: 'unnamed' };
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
