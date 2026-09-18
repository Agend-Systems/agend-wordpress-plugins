<?php
/**
 * REST routes for the WordPress-as-IdP SAML link trigger.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the identity-link REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap, only in
 * `wordpress` sign-in mode (see `agend-apps-core.php`) -- the callback below
 * is `includes/wp-idp-saml-link.php`'s, which only exists in that mode.
 */
function agend_apps_register_identity_link_routes(): void {
	$controller = new Agend_Apps_Identity_Link_REST_Controller();
	$controller->register_routes();
}

/**
 * REST controller for the identity-link trigger's background endpoint.
 *
 * Exposes:
 * - `GET /agend-apps/v1/identity-link/sso-url` -- the ONLY place a per-user
 *   nonce'd IdP-initiated SAML URL is minted (see
 *   {@see agend_apps_saml_link_issue_url()}). The `wp_footer` placeholder
 *   markup itself carries no nonce and nothing session-bound, so it is safe
 *   in cached HTML; this response, which always runs uncached, is what makes
 *   that possible.
 */
class Agend_Apps_Identity_Link_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for identity-link routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'identity-link';

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sso-url',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_sso_url' ),
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
	 * Resolves the current member's nonce'd IdP-initiated SSO URL, or reports
	 * why none was issued.
	 *
	 * Always a 200, whether or not the member turns out to be eligible: an
	 * ineligible member (already linked, throttled, capped, whatever) is a
	 * completely ordinary outcome that happens on most page views, and an
	 * error status here would show up in every visitor's browser console on
	 * every page load. The browser must not be able to tell "eligible but the
	 * server chose not to link you yet" apart from "something is broken" --
	 * both look like an empty `url` and a `reason`.
	 *
	 * `Cache-Control`/`Pragma` are set explicitly even though this route is
	 * never served from a page cache (REST responses are not templates a
	 * cache plugin serves), because the whole cache-safety argument for the
	 * footer placeholder rests on this response never being reused across
	 * members -- stating that plainly in the response headers costs nothing
	 * and catches a misconfigured reverse proxy that ignores the usual rules.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_sso_url( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		try {
			$issued = agend_apps_saml_link_issue_url( get_current_user_id(), '', true );

			$response = new WP_REST_Response(
				array(
					'url'    => $issued['url'],
					'reason' => $issued['reason'],
				),
				200
			);
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Agend Apps] identity-link sso-url endpoint failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}

			$response = new WP_REST_Response(
				array(
					'url'    => '',
					'reason' => 'error',
				),
				200
			);
		}

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}
}
