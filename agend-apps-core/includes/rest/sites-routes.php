<?php
/**
 * REST routes for sites (site config) endpoints.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the sites REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_sites_routes(): void {
	$controller = new Agend_Apps_Sites_REST_Controller();
	$controller->register_routes();
}

/**
 * REST controller for sites endpoints.
 *
 * Exposes:
 * - `GET /agend-apps/v1/sites/config` — the connected account's published
 *   brand, theme, and enabled-apps config. Openly readable (no auth); the
 *   gateway returns a safe subset of published config only.
 */
class Agend_Apps_Sites_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for sites routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'sites';

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/config',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Returns the published site config.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_config( WP_REST_Request $request ): WP_REST_Response {
		$result = agend_apps_sites_get_config();
		return $this->prepare_api_response( $result );
	}
}
