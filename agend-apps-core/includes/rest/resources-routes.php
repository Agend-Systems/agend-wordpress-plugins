<?php
/**
 * REST routes behind the Resources surfaces (collections, search, Ask).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../resources-throttle.php';
require_once __DIR__ . '/../records/render/resources-common.php';
require_once __DIR__ . '/../records/render/resources-collections.php';
require_once __DIR__ . '/../records/render/resources-search.php';
require_once __DIR__ . '/../records/render/resources-ask.php';

/**
 * Registers the Resources REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_resources_routes(): void {
	$controller = new Agend_Apps_Resources_REST_Controller();
	$controller->register_routes();
}

/**
 * The gateway hits asked for per search. A page is split into chunks and
 * several can match, so more are asked for than are shown, then reduced to one
 * result per page. One fixed number for every widget keeps one cached answer
 * per query, whatever number of results a widget shows.
 *
 * @var int
 */
const AGEND_APPS_RESOURCES_SEARCH_FETCH = 30;

/**
 * The results of a search, one per page, ready to draw.
 *
 * Each result carries its text already escaped (with the matched words
 * marked) and its link already decided, so the script only draws what it is
 * given. A page that has no reader (a source document) is listed without a
 * link and without a sign-in note.
 *
 * @param array  $rows   The gateway's search hits (`data`).
 * @param string $q      The words searched for.
 * @param int    $limit  Most results to return.
 * @param bool   $member True when the hits came through a member's view.
 * @return array<int, array{id: string, title: string, excerpt_html: string, url: string, sign_in: bool}>
 */
function agend_apps_resources_search_results( array $rows, string $q, int $limit, bool $member ): array {
	$results = array();
	$seen    = array();

	foreach ( $rows as $hit ) {
		if ( ! is_array( $hit ) ) {
			continue;
		}

		$id    = is_scalar( $hit['document_id'] ?? null ) ? (string) $hit['document_id'] : '';
		$title = is_scalar( $hit['document_title'] ?? null ) ? trim( wp_strip_all_tags( (string) $hit['document_title'] ) ) : '';

		if ( '' === $id || '' === $title || isset( $seen[ $id ] ) ) {
			continue;
		}

		$seen[ $id ] = true;
		$is_page     = 'page' === ( $hit['document_kind'] ?? '' );
		$link        = $is_page ? agend_apps_atlas_page_link( $id, ! $member ) : array(
			'url' => '',
			'via' => '',
		);

		$results[] = array(
			'id'           => $id,
			'title'        => $title,
			'excerpt_html' => agend_apps_records_resources_highlight( agend_apps_records_resources_excerpt( $hit['excerpt'] ?? '', 220 ), $q ),
			'url'          => $link['url'],
			// A member's page with no working link says so, once, in the list.
			'sign_in'      => $member && $is_page && '' === $link['url'],
		);

		if ( count( $results ) >= $limit ) {
			break;
		}
	}

	return $results;
}

/**
 * The sources of an answer, ready to draw: each with its marker, title and
 * link decided.
 *
 * @param array $citations The answer's `citations`.
 * @return array<int, array{marker: int, title: string, url: string, sign_in: bool}>
 */
function agend_apps_resources_ask_sources( array $citations ): array {
	$sources = array();

	foreach ( $citations as $citation ) {
		if ( ! is_array( $citation ) ) {
			continue;
		}

		$title = is_scalar( $citation['document_title'] ?? null ) ? trim( wp_strip_all_tags( (string) $citation['document_title'] ) ) : '';
		$id    = is_scalar( $citation['document_id'] ?? null ) ? (string) $citation['document_id'] : '';

		if ( '' === $title ) {
			continue;
		}

		// An answer only ever cites pages the asking member can see: the link
		// is the member's, never a direct one.
		$is_page = 'page' === ( $citation['document_kind'] ?? 'page' );
		$link    = $is_page && '' !== $id ? agend_apps_atlas_page_link( $id, false ) : array( 'url' => '' );

		$sources[] = array(
			'marker'  => max( 1, (int) ( $citation['marker'] ?? count( $sources ) + 1 ) ),
			'title'   => $title,
			'url'     => $link['url'],
			'sign_in' => $is_page && '' !== $id && '' === $link['url'],
		);
	}

	return $sources;
}

/**
 * REST controller behind the Resources surfaces.
 *
 * Exposes:
 * - `GET  /agend-apps/v1/resources/collections/mine` : the signed-in member's own collections, as the card grid the page draws.
 * - `GET  /agend-apps/v1/resources/search`           : search, for a signed-in member (their own view) or, when a page offers it, a visitor.
 * - `POST /agend-apps/v1/resources/ask`              : a signed-in member's question.
 *
 * Every answer is `{ success, data }` or `{ success: false, code, message }`,
 * with `no-store`, so no shared cache holds anything one viewer asked for. The
 * browser never names a member: the site resolves them from the WordPress
 * session and sends their own token to the gateway.
 *
 * Every route refuses over a limit before it calls the gateway, because the
 * gateway gives the whole site one request bucket and a flood here would stop
 * the other widgets with it.
 */
class Agend_Apps_Resources_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base.
	 *
	 * @var string
	 */
	protected $rest_base = 'resources';

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/collections/mine',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'my_collections' ),
					'permission_callback' => array( $this, 'signed_in' ),
					'args'                => array(
						'topics'      => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'description' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'excerpt'     => array(
							'type'    => 'integer',
							'minimum' => 20,
							'maximum' => 400,
							'default' => 140,
						),
						'pages'       => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 12,
							'default' => 4,
						),
						'level'       => array(
							'type'    => 'integer',
							'minimum' => 3,
							'maximum' => 4,
							'default' => 4,
						),
					),
				),
			)
		);

		// Open to visitors at the route level: whether a visitor may search is
		// decided inside, by the proof the page carries.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/search',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'q'     => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 200,
						),
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => AGEND_APPS_RECORDS_RESOURCES_SEARCH_RESULTS_MAX,
							'default' => 6,
						),
						'token' => array(
							'type'      => 'string',
							'maxLength' => 64,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/ask',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'ask' ),
					'permission_callback' => array( $this, 'signed_in' ),
					'args'                => array(
						'question' => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => AGEND_APPS_RECORDS_RESOURCES_ASK_MAX_CHARS,
						),
					),
				),
			)
		);
	}

	/**
	 * Permission: a signed-in WordPress user. WordPress has already checked the
	 * nonce for a cookie request, and treats one without a valid nonce as
	 * signed out.
	 *
	 * @return bool
	 */
	public function signed_in(): bool {
		return is_user_logged_in();
	}

	/**
	 * A failure answer.
	 *
	 * @param string $code    Stable code the script reads.
	 * @param string $message The site's own wording, safe to show.
	 * @param int    $status  HTTP status.
	 * @param int    $retry   Seconds until a refused request may be tried again; 0 for none.
	 * @return WP_REST_Response
	 */
	private function fail( string $code, string $message, int $status, int $retry = 0 ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'success' => false,
				'code'    => $code,
				'message' => $message,
			),
			$status
		);

		if ( $retry > 0 ) {
			$response->header( 'Retry-After', (string) $retry );
		}

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * A success answer.
	 *
	 * @param array $data The payload.
	 * @return WP_REST_Response
	 */
	private function done( array $data ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			200
		);

		// What one viewer asked for belongs to them: no shared cache may keep it.
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The refusal for a request over a limit.
	 *
	 * @param int    $retry   Seconds until it may be tried again.
	 * @param string $message What to tell the person.
	 * @return WP_REST_Response
	 */
	private function busy( int $retry, string $message ): WP_REST_Response {
		return $this->fail( 'agend_apps_resources_busy', $message, 429, $retry );
	}

	/**
	 * What an error from the gateway becomes. The gateway's own wording is
	 * never passed on: it is not written for a visitor and can say more than a
	 * visitor should read.
	 *
	 * @param WP_Error $error The error.
	 * @param string   $busy  What to say for a rate limit.
	 * @return WP_REST_Response
	 */
	private function gateway_failure( WP_Error $error, string $busy ): WP_REST_Response {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status_code'] ) ? (int) $data['status_code'] : 0;

		if ( 429 === $status || 'agend_apps_rate_limited' === $error->get_error_code() ) {
			return $this->busy( 30, $busy );
		}

		if ( 401 === $status || 'agend_apps_atlas_not_signed_in' === $error->get_error_code() ) {
			return $this->fail( 'agend_apps_resources_no_member', __( 'We could not find your member details. Sign in again to continue.', 'agend-apps-core' ), 403 );
		}

		return $this->fail( 'agend_apps_resources_unavailable', __( 'This is not available right now. Please try again in a moment.', 'agend-apps-core' ), 502 );
	}

	/**
	 * Whether the current WordPress user has a member identity at Agend to act
	 * as: a signed-in user with no linked member has no token.
	 *
	 * @return bool
	 */
	private function has_member(): bool {
		return '' !== agend_apps_get_bearer_token();
	}

	/**
	 * The refusal for a signed-in user who is not a linked member.
	 *
	 * @return WP_REST_Response
	 */
	private function no_member(): WP_REST_Response {
		return $this->fail( 'agend_apps_resources_no_member', __( 'We could not find your member details. Sign in again to continue.', 'agend-apps-core' ), 403 );
	}

	/**
	 * Counts a member's gateway call against their own limit and the site's.
	 *
	 * @param string $scope The member scope: `member_collections`, `member_search`.
	 * @return WP_REST_Response|null The refusal, or null to go ahead.
	 */
	private function member_gate( string $scope ): ?WP_REST_Response {
		$retry = agend_apps_resources_member_throttle( $scope, get_current_user_id(), AGEND_APPS_RESOURCES_MEMBER_SEARCH_LIMIT, AGEND_APPS_RESOURCES_MEMBER_SEARCH_WINDOW );

		if ( $retry > 0 ) {
			return $this->busy( $retry, __( 'You are doing that quickly. Wait a moment, then try again.', 'agend-apps-core' ) );
		}

		return $this->site_gate();
	}

	/**
	 * Counts a gateway call against the cap on all of the Resources proxies
	 * together.
	 *
	 * @return WP_REST_Response|null The refusal, or null to go ahead.
	 */
	private function site_gate(): ?WP_REST_Response {
		$retry = agend_apps_resources_site_throttle( 'site_calls', AGEND_APPS_RESOURCES_SITE_CALL_LIMIT, AGEND_APPS_RESOURCES_SITE_CALL_WINDOW );

		if ( $retry > 0 ) {
			return $this->busy( $retry, __( 'This is busy right now. Please try again in a moment.', 'agend-apps-core' ) );
		}

		return null;
	}

	/**
	 * The signed-in member's collections, drawn as the page's own card grid.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function my_collections( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->has_member() ) {
			return $this->no_member();
		}

		$refusal = $this->member_gate( 'member_collections' );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$read = agend_apps_atlas_get_my_collections();

		if ( is_wp_error( $read ) ) {
			return $this->gateway_failure( $read, __( 'This is busy right now. Please try again in a moment.', 'agend-apps-core' ) );
		}

		$mine = agend_apps_records_unwrap_list( $read );

		// The member's list has no descriptions: they come from the shared list
		// (held in the shared cache), matched by id. A failure there only means
		// the cards have no description.
		$descriptions = array();
		$shared       = agend_apps_records_unwrap_list( agend_apps_atlas_get_collections( array( 'limit' => 100 ) ) );

		foreach ( $shared['items'] as $row ) {
			if ( isset( $row['id'] ) && is_scalar( $row['description'] ?? null ) ) {
				$descriptions[ (string) $row['id'] ] = (string) $row['description'];
			}
		}

		$drawn = agend_apps_records_resources_collections_member_html(
			$mine['items'],
			$descriptions,
			array(
				'card'  => array(
					'description'   => (bool) $request->get_param( 'description' ),
					'excerptLength' => max( 20, min( 400, (int) $request->get_param( 'excerpt' ) ) ),
					'topics'        => (bool) $request->get_param( 'topics' ),
				),
				'pages' => max( 0, min( 12, (int) $request->get_param( 'pages' ) ) ),
				'level' => 3 === (int) $request->get_param( 'level' ) ? 'h3' : 'h4',
			)
		);

		return $this->done( $drawn );
	}

	/**
	 * Searches Atlas for a signed-in member, or for a visitor on a page that
	 * offers live search.
	 *
	 * A member's search uses their own token and sees what their entitlements
	 * allow. A visitor's is the public view, runs only with the proof a page
	 * set to live search carries, and costs the association an embedding for
	 * each query that is not already held, so it is limited hardest.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$q = agend_apps_atlas_normalise_query( (string) $request->get_param( 'q' ) );

		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $q ) : strlen( $q ) ) < AGEND_APPS_RECORDS_RESOURCES_SEARCH_MIN_CHARS ) {
			return $this->fail( 'agend_apps_resources_too_short', __( 'Type a little more to search.', 'agend-apps-core' ), 400 );
		}

		$limit  = max( 1, min( AGEND_APPS_RECORDS_RESOURCES_SEARCH_RESULTS_MAX, (int) $request->get_param( 'limit' ) ) );
		$member = is_user_logged_in() && $this->has_member();

		if ( $member ) {
			$refusal = $this->member_gate( 'member_search' );
		} else {
			$token = (string) $request->get_param( 'token' );

			// A signed-in user with no member identity is held to the same proof
			// a visitor is: they have no view of their own to search.
			if ( '' === $token || ! hash_equals( agend_apps_records_resources_visitor_token(), $token ) || ! apply_filters( 'agend_apps_resources_visitor_search_enabled', true ) ) {
				return is_user_logged_in() ? $this->no_member() : $this->fail( 'agend_apps_resources_signed_out', __( 'Sign in to search as you type.', 'agend-apps-core' ), 403 );
			}

			$retry = agend_apps_resources_visitor_throttle( 'visitor_search', AGEND_APPS_RESOURCES_VISITOR_SEARCH_LIMIT, AGEND_APPS_RESOURCES_VISITOR_SEARCH_WINDOW );

			$refusal = $retry > 0
				? $this->busy( $retry, __( 'You are searching quickly. Wait a moment, then try again.', 'agend-apps-core' ) )
				: ( agend_apps_atlas_visitor_search_is_cached( $q, AGEND_APPS_RESOURCES_SEARCH_FETCH ) ? null : $this->site_gate() );
		}

		if ( null !== $refusal ) {
			return $refusal;
		}

		$response = agend_apps_atlas_search( $q, AGEND_APPS_RESOURCES_SEARCH_FETCH, $member );

		if ( is_wp_error( $response ) ) {
			return $this->gateway_failure( $response, __( 'This is busy right now. Please try again in a moment.', 'agend-apps-core' ) );
		}

		$rows = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

		return $this->done(
			array(
				'query'   => $q,
				'scope'   => $member ? 'member' : 'visitor',
				'results' => agend_apps_resources_search_results( $rows, $q, $limit, $member ),
			)
		);
	}

	/**
	 * Asks Atlas a question for the signed-in member.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function ask( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->has_member() ) {
			return $this->no_member();
		}

		$question = trim( (string) preg_replace( '/\s+/u', ' ', (string) $request->get_param( 'question' ) ) );

		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $question ) : strlen( $question ) ) < 3 ) {
			return $this->fail( 'agend_apps_resources_too_short', __( 'Type a question first.', 'agend-apps-core' ), 400 );
		}

		// The account is known not to answer: say so without spending the
		// member's turn or a gateway call.
		if ( 'not_entitled' === agend_apps_atlas_ask_state() ) {
			return $this->done( $this->not_entitled() );
		}

		$retry = agend_apps_resources_member_throttle( 'ask_member', get_current_user_id(), AGEND_APPS_RESOURCES_ASK_MEMBER_LIMIT, AGEND_APPS_RESOURCES_ASK_MEMBER_WINDOW );

		if ( $retry > 0 ) {
			return $this->busy( $retry, __( 'You have asked a few questions in a short time. Wait a little, then ask again.', 'agend-apps-core' ) );
		}

		$retry = agend_apps_resources_site_throttle( 'ask_site', AGEND_APPS_RESOURCES_ASK_SITE_LIMIT, AGEND_APPS_RESOURCES_ASK_SITE_WINDOW );

		if ( $retry > 0 ) {
			return $this->busy( $retry, __( 'Questions have reached today\'s limit. Please try again tomorrow.', 'agend-apps-core' ) );
		}

		$refusal = $this->site_gate();

		if ( null !== $refusal ) {
			return $refusal;
		}

		$response = agend_apps_atlas_ask( $question );

		if ( is_wp_error( $response ) ) {
			return $this->gateway_failure( $response, __( 'You have asked a few questions in a short time. Wait a little, then ask again.', 'agend-apps-core' ) );
		}

		$result = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

		switch ( (string) ( $result['kind'] ?? '' ) ) {
			case 'answer':
				return $this->done(
					array(
						'kind'       => 'answer',
						'answer'     => is_scalar( $result['answer'] ?? null ) ? (string) $result['answer'] : '',
						'confidence' => 'low' === ( $result['confidence'] ?? '' ) ? 'low' : 'high',
						'disclaimer' => is_scalar( $result['disclaimer'] ?? null ) ? trim( wp_strip_all_tags( (string) $result['disclaimer'] ) ) : '',
						'sources'    => agend_apps_resources_ask_sources( (array) ( $result['citations'] ?? array() ) ),
					)
				);

			case 'refusal':
				return $this->done(
					array(
						'kind'       => 'refusal',
						'message'    => is_scalar( $result['message'] ?? null ) ? trim( wp_strip_all_tags( (string) $result['message'] ) ) : '',
						'disclaimer' => is_scalar( $result['disclaimer'] ?? null ) ? trim( wp_strip_all_tags( (string) $result['disclaimer'] ) ) : '',
					)
				);

			case 'degraded':
				$matches = array();

				foreach ( (array) ( $result['search_results'] ?? array() ) as $hit ) {
					if ( is_array( $hit ) && is_scalar( $hit['document_title'] ?? null ) ) {
						$hit['excerpt'] = $hit['snippet'] ?? '';
						$matches[]      = $hit;
					}
				}

				return $this->done(
					array(
						'kind'    => 'degraded',
						'message' => is_scalar( $result['message'] ?? null ) ? trim( wp_strip_all_tags( (string) $result['message'] ) ) : '',
						'matches' => agend_apps_resources_search_results( $matches, '', 4, true ),
						'contact' => is_scalar( $result['contact_path'] ?? null ) ? trim( wp_strip_all_tags( (string) $result['contact_path'] ) ) : '',
					)
				);

			case 'not_entitled':
				return $this->done( $this->not_entitled() );
		}

		return $this->fail( 'agend_apps_resources_unavailable', __( 'We could not get an answer just now. Please try again in a moment.', 'agend-apps-core' ), 502 );
	}

	/**
	 * The answer for an account that is not entitled to Ask. The plan and the
	 * reason Atlas gave are never passed on; an editor gets a plain
	 * explanation.
	 *
	 * @return array
	 */
	private function not_entitled(): array {
		$data = array( 'kind' => 'not_entitled' );

		if ( current_user_can( 'edit_posts' ) ) {
			$data['editor_note'] = __( 'Ask is not part of your Agend plan yet, so members cannot ask questions. Ask your Agend contact about adding it.', 'agend-apps-core' );
		}

		return $data;
	}
}
