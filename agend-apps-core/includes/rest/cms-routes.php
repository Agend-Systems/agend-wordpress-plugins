<?php
/**
 * REST routes for CMS endpoints.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the CMS REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_cms_routes(): void {
	$controller = new Agend_Apps_CMS_REST_Controller();
	$controller->register_routes();
}

/**
 * REST controller for CMS endpoints.
 *
 * Read routes are publicly readable (no authentication required).
 * All routes are catalogue reads and are cached via the gateway.
 *
 * Exposes:
 * - `GET  /agend-apps/v1/cms/content`              — paginated content list.
 * - `GET  /agend-apps/v1/cms/content/{slug}`       — single content item by slug.
 */
class Agend_Apps_CMS_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for CMS routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'cms';

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/content',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_content' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'page'      => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'limit'     => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'collection' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'category'  => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'tag'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'language'  => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'sortBy'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'sortOrder' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'search'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/content/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_content_by_slug' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'slug'       => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'collection' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'language'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Returns a paginated list of CMS content.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_content( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'limit', 'collection', 'category', 'tag', 'language', 'sortBy', 'sortOrder', 'search' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_cms_get_content( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns a single CMS content item by slug.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_content_by_slug( WP_REST_Request $request ): WP_REST_Response {
		$slug = $request->get_param( 'slug' );

		// Forward optional query params if present.
		$allowed = array( 'collection', 'language' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_cms_get_content_by_slug( $slug, $query );
		return $this->prepare_api_response( $result );
	}
}
