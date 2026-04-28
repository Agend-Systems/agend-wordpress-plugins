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
	 * Cart session utility object.
	 *
	 * @namespace AgendCartSession
	 */
	window.AgendCartSession = {

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
