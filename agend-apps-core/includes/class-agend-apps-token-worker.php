<?php
/**
 * SSO token worker.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the logged-in WordPress member's Supabase bearer token to the API
 * client (addendum E-11, first consumer of the `agend_apps_bearer_token`
 * filter).
 *
 * The worker resolves the member's external id, mints a short-lived access
 * token via `POST /v1/sso/tokens` (resolve-only: the member must already be
 * linked in `sso_identities`), caches it in user meta keyed with its expiry,
 * and re-mints lazily on expiry (D-5: no refresh token in v1 by design).
 *
 * Failure never breaks a request: any miss (logged out, no external id, not
 * linked, mint error) returns an empty string, which leaves the gateway call
 * unattended (API-key only) exactly as before the worker existed. Unlinked
 * and errored mints are negative-cached so the gateway is not hammered once
 * per proxy call.
 */
class Agend_Apps_Token_Worker {

	/**
	 * User-meta key storing the minted token envelope. Underscore-prefixed so
	 * it is hidden from the custom-fields UI; server-side only, never sent to
	 * the browser.
	 *
	 * @var string
	 */
	const META_KEY = '_agend_apps_sso_token';

	/**
	 * Transient prefix for the per-user negative cache.
	 *
	 * @var string
	 */
	const NEGATIVE_PREFIX = 'agend_apps_sso_no_token_';

	/**
	 * Seconds before the recorded expiry at which the token is considered
	 * stale, so an in-flight request never carries a token that expires
	 * mid-call.
	 *
	 * @var int
	 */
	const EXPIRY_BUFFER = 60;

	/**
	 * Negative-cache TTL after a 404 (member not linked). Kept short so a
	 * member who links via SSO gains their bearer promptly; the account-link
	 * status route also clears it explicitly on a linked result.
	 *
	 * @var int
	 */
	const NEGATIVE_TTL_UNLINKED = 300;

	/**
	 * Negative-cache TTL after any other mint failure (transport, 5xx).
	 *
	 * @var int
	 */
	const NEGATIVE_TTL_ERROR = 60;

	/**
	 * Re-entrancy guard: the mint request itself flows through the API client,
	 * which consults the bearer filter; without the guard the worker would
	 * recurse.
	 *
	 * @var bool
	 */
	private static $resolving = false;

	/**
	 * Hooks the worker into the bearer-token filter.
	 */
	public function __construct() {
		add_filter( 'agend_apps_bearer_token', array( $this, 'provide_token' ) );
	}

	/**
	 * Resolves the current user's bearer token for an outbound gateway call.
	 *
	 * @param mixed $token The token from an earlier filter (usually '').
	 * @return string The bearer token, or an empty string when unavailable.
	 */
	public function provide_token( $token ): string {
		// Another provider already supplied a token — it wins.
		if ( is_string( $token ) && '' !== $token ) {
			return $token;
		}

		// The mint call itself must go out unattended.
		if ( self::$resolving ) {
			return '';
		}

		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return '';
		}

		$external_id = agend_apps_current_user_external_id();

		if ( '' === $external_id ) {
			return '';
		}

		// Valid cached token for the same external id.
		$cached = get_user_meta( $user_id, self::META_KEY, true );

		if (
			is_array( $cached )
			&& isset( $cached['access_token'], $cached['expires_at'], $cached['external_id'] )
			&& $cached['external_id'] === $external_id
			&& ( (int) $cached['expires_at'] - self::EXPIRY_BUFFER ) > time()
		) {
			return (string) $cached['access_token'];
		}

		if ( false !== get_transient( self::NEGATIVE_PREFIX . $user_id ) ) {
			return '';
		}

		self::$resolving = true;
		$response        = agend_apps_sso_mint_token( agend_apps_idp_entity_id(), $external_id );
		self::$resolving = false;

		if ( is_wp_error( $response ) ) {
			$data        = $response->get_error_data();
			$status_code = ( is_array( $data ) && isset( $data['status_code'] ) ) ? (int) $data['status_code'] : 0;
			$ttl         = ( 404 === $status_code ) ? self::NEGATIVE_TTL_UNLINKED : self::NEGATIVE_TTL_ERROR;
			set_transient( self::NEGATIVE_PREFIX . $user_id, 1, $ttl );
			return '';
		}

		$minted = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;

		if ( empty( $minted['access_token'] ) || empty( $minted['expires_at'] ) ) {
			set_transient( self::NEGATIVE_PREFIX . $user_id, 1, self::NEGATIVE_TTL_ERROR );
			return '';
		}

		update_user_meta(
			$user_id,
			self::META_KEY,
			array(
				'access_token' => (string) $minted['access_token'],
				'expires_at'   => (int) $minted['expires_at'],
				'external_id'  => $external_id,
				'minted_at'    => time(),
			)
		);

		return (string) $minted['access_token'];
	}

	/**
	 * Clears the negative cache for a user.
	 *
	 * Called by the account-link status route when it observes a linked
	 * result, so a freshly linked member gains their bearer on the next call
	 * instead of waiting out the negative TTL.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function clear_negative_cache( int $user_id ): void {
		delete_transient( self::NEGATIVE_PREFIX . $user_id );
	}

	/**
	 * Discards a user's cached token (e.g. when the external id mapping
	 * changes or an admin needs to force a re-mint).
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function clear_token( int $user_id ): void {
		delete_user_meta( $user_id, self::META_KEY );
	}
}
