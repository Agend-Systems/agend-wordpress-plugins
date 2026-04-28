<?php
/**
 * REST routes for health endpoints.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the health REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_health_routes(): void {
	$controller = new Agend_Apps_Health_REST_Controller();
	$controller->register_routes();
}

/**
 * REST controller for health endpoints.
 *
 * Exposes:
 * - `GET /agend-apps/v1/health/service` — unauthenticated gateway ping.
 * - `GET /agend-apps/v1/health/api-key`  — authenticated key verification.
 */
class Agend_Apps_Health_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for health routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'health';

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/service',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_service_health' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/api-key',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_api_key_status' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Returns the Agend service health status.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_service_health( WP_REST_Request $request ): WP_REST_Response {
		$result = agend_apps_service_health();
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the API key verification status and scopes.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_api_key_status( WP_REST_Request $request ): WP_REST_Response {
		$result = agend_apps_verify_api_key();
		return $this->prepare_api_response( $result );
	}

	/**
	 * Checks that the current user has the `manage_options` capability.
	 *
	 * @return bool True if the user can manage options, false otherwise.
	 */
	public function admin_permissions_check(): bool {
		return current_user_can( 'manage_options' );
	}
}
