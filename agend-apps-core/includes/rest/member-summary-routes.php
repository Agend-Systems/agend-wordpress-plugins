<?php
/**
 * REST route behind the Member Summary surface.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../records/render/member-summary.php';

/**
 * Registers the member summary route.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_member_summary_routes(): void {
	$controller = new Agend_Apps_Member_Summary_REST_Controller();
	$controller->register_routes();
}

/**
 * REST controller behind the Member Summary surface.
 *
 * Exposes `POST /agend-apps/v1/member/summary`: the signed-in member's own
 * cards, drawn on the server and returned as one fragment of markup.
 *
 * - It is a POST so that nothing about it is ever served from a shared cache
 *   and the member's settings travel in a body, not an address. Nothing is
 *   changed by it.
 * - Who the member is comes from the WordPress session alone. The body names a
 *   page's content settings and where to come back to, never a member.
 * - It always answers 200 with a state in the body, however the visit went
 *   (signed out, connecting, no member found, busy): those are ordinary
 *   outcomes and an error status would put a red line in every console for
 *   each of them. Only a request the browser cannot make sense of, and the
 *   gate that stands in front of every surface route, answer otherwise.
 * - Every answer is `no-store` and varies by cookie.
 *
 * The settings the page sends back are not trusted: they go through the same
 * builder the page used, which decides what a value may be, and only the known
 * keys are read.
 */
class Agend_Apps_Member_Summary_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base.
	 *
	 * @var string
	 */
	protected $rest_base = 'member/summary';

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
					'callback'            => array( $this, 'summary' ),
					// Open on purpose: a visitor whose session is not recognised
					// (no nonce, or signed out) is answered with the sign-in card,
					// which is exactly what the page would have shown. Nothing
					// personal is in that answer.
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Answers with the member's cards, or with why there are none.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function summary( WP_REST_Request $request ): WP_REST_Response {
		$body     = $request->get_json_params();
		$body     = is_array( $body ) ? $body : array();
		$settings = isset( $body['settings'] ) && is_array( $body['settings'] ) ? $body['settings'] : array();
		$config   = agend_apps_records_member_summary_content_config( $settings );
		$settled  = ! empty( $body['settled'] );

		// Where to come back to is the page the member is on, and only that:
		// an address on this site.
		$return = isset( $body['return_url'] ) && is_string( $body['return_url'] ) ? wp_validate_redirect( esc_url_raw( $body['return_url'] ), home_url( '/' ) ) : home_url( '/' );

		return $this->answer( $this->resolve( $config, $return, $settled ) );
	}

	/**
	 * Works out the answer: the state, how long to wait before asking again, and
	 * the markup.
	 *
	 * @param array  $config  The content config.
	 * @param string $return  Where signing in or linking comes back to.
	 * @param bool   $settled Whether the page has waited as long as it will for
	 *                        a connection to be made.
	 * @return array{state: string, retry_after: int, html: string}
	 */
	private function resolve( array $config, string $return, bool $settled ): array {
		$render_config = $config + array(
			'heading'    => array( 'show' => false, 'title' => '' ),
			'cardRadius' => 10,
			'colours'    => array(),
		);

		$access = agend_apps_member_access_state( array( 'return_url' => $return ) );

		if ( 'signed_out' === $access['state'] ) {
			return array(
				'state'       => 'signed_out',
				'retry_after' => 0,
				'html'        => $config['hideWhenSignedOut'] ? '' : agend_apps_records_member_summary_render_signed_out( $render_config, (string) $access['sign_in_url'] ),
			);
		}

		if ( 'ready' !== $access['state'] ) {
			return array(
				'state'       => (string) $access['state'],
				'retry_after' => (int) $access['retry_after'],
				'html'        => agend_apps_records_member_summary_render_notice( $render_config, $access, array( 'settled' => $settled ) ),
			);
		}

		$guard = agend_apps_member_summary_guard( (int) get_current_user_id() );

		if ( null !== $guard ) {
			// The same answer a refusal from Agend would give, so the member is
			// told the same thing whoever refused.
			$access = agend_apps_member_access_from_error(
				new WP_Error(
					'agend_apps_rate_limited',
					'Held back by the site.',
					array(
						'status_code' => 429,
						'reset'       => (string) ( time() + (int) $guard['retry_after'] ),
					)
				),
				$access
			);

			return array(
				'state'       => (string) $access['state'],
				'retry_after' => (int) $access['retry_after'],
				'html'        => agend_apps_records_member_summary_render_notice( $render_config, $access ),
			);
		}

		$result = agend_apps_member_summary_fetch(
			array(
				'sections' => array_keys( array_filter( $config['sections'] ) ),
				'greeting' => $config['greeting'],
			),
			$access,
			array(
				'events'       => $config['limits']['events'],
				'certificates' => $config['limits']['certificates'],
			)
		);

		return array(
			'state'       => (string) $result['access']['state'],
			'retry_after' => (int) $result['access']['retry_after'],
			'html'        => agend_apps_records_member_summary_render_body( $render_config, $result, array( 'settled' => $settled ) ),
		);
	}

	/**
	 * The answer as a response: always 200, never cached, varying by cookie.
	 *
	 * `Cache-Control` and `Vary` are set explicitly even though a POST is not
	 * cached, because the safety of putting a member's cards behind a script
	 * rests on this answer never being reused for anyone else, and saying so in
	 * the headers costs nothing and catches a proxy that ignores the rules.
	 *
	 * @param array $answer State, wait and markup.
	 * @return WP_REST_Response
	 */
	private function answer( array $answer ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'success'     => true,
				'state'       => $answer['state'],
				'retry_after' => $answer['retry_after'],
				'html'        => $answer['html'],
			),
			200
		);

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Vary', 'Cookie' );

		return $response;
	}
}
