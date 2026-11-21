/**
 * Agend Apps Shop — Cart Session Utility.
 *
 * Exposes AgendCartSession with methods for reading and writing the anonymous
 * cart session cookie, and for building authenticated request headers.
 *
 * All other shop JS files depend on this script and call AgendCartSession
 * before making any REST requests.
 */

/* global window */

( function () {
	'use strict';

	let COOKIE_NAME = 'agend_cart_session';
	let COOKIE_DAYS = 30;

	/**
	 * Reads a cookie value by name.
	 *
	 * @param {string} name Cookie name.
	 * @return {string|null} Cookie value or null if not found.
	 */
	function readCookie( name ) {
		let match = document.cookie.match(
			new RegExp( '(?:^|;\\s*)' + name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '=([^;]*)' )
		);
		return match ? decodeURIComponent( match[ 1 ] ) : null;
	}

	/**
	 * Writes a cookie with a given name, value, and expiry in days.
	 *
	 * @param {string} name  Cookie name.
	 * @param {string} value Cookie value.
	 * @param {number} days  Number of days until expiry.
	 */
	function writeCookie( name, value, days ) {
		let expires = '';
		if ( days ) {
			let date = new Date();
			date.setTime( date.getTime() + days * 24 * 60 * 60 * 1000 );
			expires = '; expires=' + date.toUTCString();
		}
		document.cookie = `${name}=${encodeURIComponent(value)}${expires}; path=/; SameSite=Lax`;
	}

	/**
	 * Plain-English text for a failed cart request. The platform's own message
	 * is written for developers ("Cart 49f9… is not mutable (status: locked)"),
	 * so it is shown only for a request the visitor can correct (a 400 or 422
	 * naming what is wrong); everything else gets wording a visitor can act on.
	 * Mirrored by cartErrorFallback() in Agend Apps Core's events-catalogue.js
	 * for a page without this script.
	 *
	 * @param {number} status   HTTP status of the WordPress REST response.
	 * @param {Object} body     Decoded REST response body.
	 * @param {string} fallback Text for an error with nothing more specific.
	 * @return {{message: string, locked: boolean}} What to show, and whether the
	 *         cart is held by a checkout in progress (so a link to the cart page,
	 *         where that checkout can be finished or cancelled, helps).
	 */
	let VISITOR_CODES = [ 'agend_apps_sign_in_required', 'agend_apps_invoice_disabled', 'agend_apps_invalid_purchase_order' ];

	function describeError( status, body, fallback ) {
		let generic  = fallback || 'Something went wrong with your cart. Please try again.';
		let platform = body && body.data && body.data.body && body.data.body.error;
		let code     = ( platform && platform.code ) || '';
		let raw      = ( platform && platform.message ) || ( body && body.message ) || '';

		if ( 'CHECKOUT_ALREADY_INITIATED' === code || 'CART_LOCKED' === code || /not mutable|status: locked/i.test( raw ) ) {
			return {
				message: 'Your cart is being checked out, so it can\'t be changed right now. Go to your cart to finish paying, or cancel that checkout to make changes.',
				locked: true,
			};
		}
		// WordPress's own refusals that are written for visitors.
		if ( body && 'string' === typeof body.code && -1 !== VISITOR_CODES.indexOf( body.code ) && 'string' === typeof body.message && '' !== body.message ) {
			return { message: body.message, locked: false };
		}
		if ( 401 === status ) {
			return { message: 'Your session has ended. Sign in again, then try again.', locked: false };
		}
		if ( 429 === status ) {
			return { message: 'Too many attempts. Wait a moment, then try again.', locked: false };
		}

		let fields = platform && platform.details && platform.details.fields;
		let fieldMessages = fields && 'object' === typeof fields ? Object.keys( fields ).reduce( function ( all, key ) {
			return all.concat( fields[ key ] );
		}, [] ).filter( function ( text ) {
			return 'string' === typeof text && '' !== text;
		} ) : [];
		if ( fieldMessages.length ) {
			return { message: fieldMessages.join( ' ' ), locked: false };
		}
		if ( ( 400 === status || 422 === status ) && 'string' === typeof raw && '' !== raw ) {
			return { message: raw, locked: false };
		}

		return { message: generic, locked: false };
	}

	/**
	 * Cart session utility object.
	 *
	 * @namespace AgendCartSession
	 */
	window.AgendCartSession = {

		describeError: describeError,

		/**
		 * Returns the current cart session token from the cookie.
		 *
		 * @return {string|null} Session token or null.
		 */
		getToken: function () {
			return readCookie( COOKIE_NAME );
		},

		/**
		 * Stores the cart session token in a persistent cookie.
		 *
		 * @param {string} token Session token returned by the API.
		 */
		setToken: function ( token ) {
			writeCookie( COOKIE_NAME, token, COOKIE_DAYS );
		},

		/**
		 * Returns a plain object of request headers for authenticated REST calls.
		 *
		 * Reads the WP REST nonce from window.agendApps.nonce (set by agend-apps-core).
		 * Includes X-Cart-Session only when a token is present.
		 *
		 * @return {Object} Headers object ready for use with fetch().
		 */
		getHeaders: function () {
			let headers = {};

			if ( window.agendApps && window.agendApps.nonce ) {
				headers[ 'X-WP-Nonce' ] = window.agendApps.nonce;
			}

			let token = this.getToken();
			if ( token ) {
				headers[ 'X-Cart-Session' ] = token;
			}

			return headers;
		},
	};
}() );
