<?php
/**
 * REST route behind the My Communities surface.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../records/render/loop-my-communities.php';

/**
 * Registers the My Communities route.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_loop_my_communities_routes(): void {
	$controller = new Agend_Apps_Loop_My_Communities_REST_Controller();
	$controller->register_routes();
}

/**
 * REST controller behind the My Communities surface.
 *
 * Exposes `POST /agend-apps/v1/member/communities`: the signed-in member's own
 * communities, drawn on the server and returned as one fragment of markup.
 *
 * - It is a POST so nothing about it is ever served from a shared cache and the
 *   page's content settings travel in a body, not an address. Nothing is
 *   changed by it.
 * - It needs a signed-in member. WordPress cookie authentication ignores the
 *   session when the request has no valid `X-WP-Nonce`, so a visitor, or a page
 *   left open past its nonce, is refused.
 * - Who the member is comes from the WordPress session alone. The body names a
 *   page's content settings, never a member.
 * - Every answer is `no-store` and varies by cookie.
 *
 * The settings the page sends back are not trusted: they go through the same
 * builder the page used, which decides what a value may be.
 */
class Agend_Apps_Loop_My_Communities_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base.
	 *
	 * @var string
	 */
	protected $rest_base = 'member/communities';

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'communities' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Whether the request is from a signed-in member.
	 *
	 * @return bool
	 */
	public function permissions_check(): bool {
		return is_user_logged_in() && get_current_user_id() > 0;
	}

	/**
	 * Answers with the member's communities, or with why there are none.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function communities( WP_REST_Request $request ): WP_REST_Response {
		$body     = $request->get_json_params();
		$body     = is_array( $body ) ? $body : array();
		$settings = isset( $body['settings'] ) && is_array( $body['settings'] ) ? $body['settings'] : array();
		$config   = agend_apps_records_my_communities_content_config( $settings );
		$result   = Agend_Apps_Loop_My_Communities::for_current_user();

		$response = new WP_REST_Response(
			array(
				'success' => true,
				'state'   => (string) $result['state'],
				'html'    => agend_apps_records_my_communities_render_body( $config, $result, agend_apps_records_my_communities_viewer_is_admin() ),
			),
			200
		);

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Vary', 'Cookie' );

		return $response;
	}
}
