<?php
/**
 * REST routes for account-link status.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the account-link REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_account_link_routes(): void {
	$controller = new Agend_Apps_Account_Link_REST_Controller();
	$controller->register_routes();
}

/**
 * Builds the browser SSO initiate URL that links the current visitor.
 *
 * A not-yet-linked WordPress member has no Agend session, so the link is
 * established by a normal SSO login: the ACS handler provisions the member and
 * writes the `sso_identities` row. The URL targets the account's SSO connection
 * on the API host and returns the visitor to the page hosting the widget via
 * RelayState. That return only lands back on WordPress when the WordPress
 * origin is an allow-listed gateway origin; otherwise the gateway falls back to
 * the connection's landing path and the widget reflects the link on the
 * member's next visit.
 *
 * @param WP_REST_Request $request Current request (used to resolve the return URL).
 * @return string The initiate URL, or an empty string when no account slug is configured.
 */
function agend_apps_account_link_initiate_url( WP_REST_Request $request ): string {
	$slug = Agend_Apps_Settings::get_account_slug();

	if ( '' === $slug ) {
		return '';
	}

	$return_url = wp_get_referer();

	if ( ! $return_url ) {
		$return_url = home_url( '/' );
	}

	$initiate = Agend_Apps_Settings::get_root_url() . '/api/auth/sso/' . rawurlencode( $slug ) . '/initiate';

	return add_query_arg( 'relayState', rawurlencode( $return_url ), $initiate );
}

/**
 * REST controller for account-link endpoints.
 *
 * Exposes:
 * - `GET /agend-apps/v1/account-link/status` — whether the current WordPress
 *   user is linked to a member in the connected Agend account, plus the SSO
 *   initiate URL to establish the link when they are not. Nonce-gated: it runs
 *   server-side as the current visitor and resolves their external id there,
 *   so the browser never sees or supplies the identity.
 */
class Agend_Apps_Account_Link_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for account-link routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'account-link';

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);
	}

	/**
	 * Validates the WordPress REST nonce for the current request.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool True if the nonce is valid, false otherwise.
	 */
	public function nonce_check( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		return false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Returns the current user's Agend link status.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		$user = wp_get_current_user();

		if ( ! $user->exists() ) {
			return new WP_REST_Response(
				array(
					'logged_in' => false,
					'linked'    => false,
				),
				200
			);
		}

		$external_id = agend_apps_current_user_external_id();

		if ( '' === $external_id ) {
			return new WP_REST_Response(
				array(
					'logged_in' => true,
					'linked'    => false,
					'reason'    => 'no_external_id',
				),
				200
			);
		}

		$result = agend_apps_sso_get_link_status(
			agend_apps_idp_entity_id(),
			$external_id
		);

		if ( is_wp_error( $result ) ) {
			return $this->error_to_response( $result );
		}

		// The gateway returns the canonical { success, data:{ linked } } envelope.
		$linked = false;
		if ( isset( $result['data']['linked'] ) ) {
			$linked = (bool) $result['data']['linked'];
		} elseif ( isset( $result['linked'] ) ) {
			$linked = (bool) $result['linked'];
		}

		// A freshly linked member should gain their bearer token on the next
		// gateway call rather than waiting out the worker's negative cache.
		if ( $linked ) {
			Agend_Apps_Token_Worker::clear_negative_cache( $user->ID );
		}

		return new WP_REST_Response(
			array(
				'logged_in'    => true,
				'linked'       => $linked,
				'initiate_url' => $linked ? '' : agend_apps_account_link_initiate_url( $request ),
				'portal_url'   => Agend_Apps_Settings::get_portal_home_url(),
			),
			200
		);
	}
}
