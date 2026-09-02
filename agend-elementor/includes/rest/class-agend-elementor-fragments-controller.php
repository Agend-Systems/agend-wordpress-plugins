<?php
/**
 * REST endpoint returning template-rendered catalogue cards.
 *
 * `GET /agend-elementor/v1/cards/events` and `/cards/courses` take the same
 * list filters the Agend Apps Core proxy takes, plus the card template id,
 * and return one rendered HTML fragment per record with the gateway's
 * pagination passed through untouched.
 *
 * Public, like the proxy list routes it mirrors: a signed-in member's session
 * still resolves server-side (agend_apps_get_bearer_token()), so cards carry
 * viewer pricing and registration state exactly as the proxy would return
 * them. Fragments never carry CSS unless `with_css` is set, because the host
 * page enqueues the template stylesheet whenever a card template is
 * configured.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Card fragments controller.
 */
class Agend_Elementor_Fragments_Controller {

	const NAMESPACE = 'agend-elementor/v1';

	/**
	 * Registers the two routes.
	 */
	public function register_routes(): void {
		foreach ( array( 'events' => 'event', 'courses' => 'course' ) as $segment => $type ) {
			register_rest_route(
				self::NAMESPACE,
				'/cards/' . $segment,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function ( WP_REST_Request $request ) use ( $type ) {
							return $this->get_cards( $request, $type );
						},
						'permission_callback' => '__return_true',
						'args'                => $this->args(),
					),
				)
			);
		}
	}

	/**
	 * Our own parameters. The gateway filters are deliberately not declared:
	 * they are allow-listed by agend_elementor_fragment_query_args() so the
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
		if ( ! Agend_Elementor_Template_Renderer::is_valid_template( $template_id ) ) {
			return new WP_Error( 'agend_elementor_invalid_template', __( 'The card template does not exist or is not published.', 'agend-elementor' ), array( 'status' => 400 ) );
		}

		$fetch = 'course' === $type ? 'agend_apps_lms_get_courses' : 'agend_apps_events_get_events';
		if ( ! function_exists( $fetch ) ) {
			return new WP_Error( 'agend_elementor_unavailable', __( 'Agend Apps Core is not available.', 'agend-elementor' ), array( 'status' => 503 ) );
		}

		$query    = agend_elementor_fragment_query_args( $request->get_params(), $type );
		$response = $fetch( $query );
		if ( is_wp_error( $response ) ) {
			$status = (int) ( $response->get_error_data()['status'] ?? 502 );
			return new WP_Error( $response->get_error_code(), $response->get_error_message(), array( 'status' => $status > 0 ? $status : 502 ) );
		}

		$list  = agend_elementor_unwrap_list( $response );
		$cards = agend_elementor_render_cards(
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
