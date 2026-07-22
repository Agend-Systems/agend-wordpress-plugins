/**
 * Agend Apps Shop — Cart Header widget JS.
 *
 * Fetches the cart item count on load and refreshes the badge whenever the
 * agend:cart:updated event fires. Hides the badge when item_count is 0 or
 * when no cart session exists.
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

	var restUrl = ( window.agendApps && window.agendApps.restUrl ) ? window.agendApps.restUrl : '';

	/**
	 * Refreshes the badge count for a single cart header widget instance.
	 *
	 * @param {HTMLElement} wrapper The .agend-apps-shop-cart-header element.
	 * @param {int|null} total Optional passed total, if passed then will skip remote fetching.
	 */
	function refreshBadge( wrapper, total = null ) {
		var badge = wrapper.querySelector( '.agend-shop-cart-badge' );
		if ( ! badge ) {
			return;
		}
		const maybeUpdateBadge = (itemCount = 0) =>{
			if ( itemCount > 0 ) {
				badge.textContent = itemCount;
				badge.removeAttribute( 'hidden' );
			} else {
				badge.setAttribute( 'hidden', '' );
			}
		}
		if (total) {
			maybeUpdateBadge(total);
			return;
		}
		fetch( restUrl + 'cart', {
			method: 'GET',
			headers: AgendCartSession.getHeaders(),
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					// 400 (no session) or other errors — hide badge silently.
					badge.setAttribute( 'hidden', '' );
					return null;
				}
				return response.json();
			} )
			.then( function ( dataWrapper ) {
				if ( ! dataWrapper ) {
					return;
				}
				const data = dataWrapper.data
				if ( ! data ) {
					// An empty or just-cleared cart responds with data: null.
					maybeUpdateBadge( 0 );
					return;
				}
				// `GET /cart` returns the cart object directly on `data`; tolerate a
				// nested `data.cart` shape as well.
				var cart = ( data.cart ) || data;
				var itemCount = ( cart && cart.item_count ) ? parseInt( cart.item_count, 10 ) : 0;
				maybeUpdateBadge(itemCount)
			} )
			.catch( function () {
				badge.setAttribute( 'hidden', '' );
			} );
	}

	var widgets = document.querySelectorAll( '.agend-apps-shop-cart-header' );

	// Initial badge refresh for all instances.
	widgets.forEach( function ( wrapper ) {
		refreshBadge( wrapper );
	} );

	// Listen for cart changes from other widgets.
	document.addEventListener( 'agend:cart:updated', function (e) {
		widgets.forEach( function ( wrapper ) {
			refreshBadge( wrapper, e.detail?.count ?? null );
		} );
	} );
	// Total only event listener to prevent more widgets than necessary from updating.
	document.addEventListener( 'agend:cart:updated:total', function (e) {
		widgets.forEach( function ( wrapper ) {
			refreshBadge( wrapper, e.detail?.count ?? null );
		} );
	} );
} );
