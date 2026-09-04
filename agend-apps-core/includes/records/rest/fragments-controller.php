<?php
/**
 * REST endpoint returning template-rendered catalogue cards.
 *
 * `GET /agend-apps/v1/cards/events` and `/cards/courses` take the same list
 * filters the Agend Apps Core proxy takes, plus the card template id, and
 * return one rendered HTML fragment per record with the gateway's pagination
 * passed through untouched. Also served under the pre-rename
 * `agend-elementor/v1` namespace for one release, for a page cached before
 * this deploy.
 *
 * Public, like the proxy list routes it mirrors: a signed-in member's session
 * still resolves server-side (agend_apps_get_bearer_token()), so cards carry
 * viewer pricing and registration state exactly as the proxy would return
 * them. Fragments never carry CSS unless `with_css` is set, because the host
 * page enqueues the template stylesheet whenever a card template is
 * configured.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Card fragments controller.
 */
class Agend_Apps_Records_Fragments_Controller {

	const NAMESPACE = 'agend-apps/v1';

	/**
	 * The pre-rename namespace. A page cached before this deploy still requests
	 * this path, so it is served for one release alongside the current
	 * namespace and then dropped.
	 */
	const LEGACY_NAMESPACE = 'agend-elementor/v1';

	/**
	 * Registers the routes under both the current and legacy namespaces.
	 */
	public function register_routes(): void {
		foreach ( array( 'events' => 'event', 'courses' => 'course', 'listings' => 'listing' ) as $segment => $type ) {
			$route = array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => function ( WP_REST_Request $request ) use ( $type ) {
						return $this->get_cards( $request, $type );
					},
					'permission_callback' => '__return_true',
					'args'                => $this->args(),
				),
			);

			register_rest_route( self::NAMESPACE, '/cards/' . $segment, $route );
			register_rest_route( self::LEGACY_NAMESPACE, '/cards/' . $segment, $route );
		}
	}

	/**
	 * Our own parameters. The gateway filters are deliberately not declared:
	 * they are allow-listed by agend_apps_records_fragment_query_args() so the
	 * list stays in one place.
	 *
	 * @return array
	 */
	private function args(): array {
		return array(
			'template'        => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'detail_page'     => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'card_link_whole' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'with_css'        => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/**
	 * Fetches a page of records and renders each through the card template.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @param string          $type    'event' or 'course'.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_cards( WP_REST_Request $request, string $type ) {
		$template_id = (int) $request->get_param( 'template' );
		if ( ! Agend_Apps_Templates::is_valid_template( $template_id ) ) {
			return new WP_Error( 'agend_apps_records_invalid_template', __( 'The card template does not exist or is not published.', 'agend-apps-core' ), array( 'status' => 400 ) );
		}

		$query    = agend_apps_records_fragment_query_args( $request->get_params(), $type );
		$response = agend_apps_records_fetch_list( $type, $query );
		if ( null === $response ) {
			return new WP_Error( 'agend_apps_records_unavailable', __( 'Agend Apps Core is not available.', 'agend-apps-core' ), array( 'status' => 503 ) );
		}
		if ( is_wp_error( $response ) ) {
			$status = (int) ( $response->get_error_data()['status'] ?? 502 );
			return new WP_Error( $response->get_error_code(), $response->get_error_message(), array( 'status' => $status > 0 ? $status : 502 ) );
		}

		$list  = agend_apps_records_unwrap_list( $response );
		$cards = agend_apps_records_render_cards(
			$type,
			$template_id,
			$list['items'],
			array(
				'card_link_whole' => rest_sanitize_boolean( $request->get_param( 'card_link_whole' ) ),
				'host_page_id'    => (int) $request->get_param( 'detail_page' ),
				'with_css'        => rest_sanitize_boolean( $request->get_param( 'with_css' ) ),
			)
		);

		$result = new WP_REST_Response(
			array(
				'cards' => $cards,
				'meta'  => array( 'pagination' => $list['pagination'] ),
			)
		);
		// Identity-specific when a member is signed in, so never shared-cacheable.
		$result->header( 'Cache-Control', 'private, no-store' );
		return $result;
	}
}
