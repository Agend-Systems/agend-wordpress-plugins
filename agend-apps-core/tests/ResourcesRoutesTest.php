<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Resources_REST_Controller;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/fixtures/resources-fixtures.php';

/**
 * The routes behind the Resources surfaces: who may call each, what they ask
 * the gateway for, what comes back, and above all what is refused before the
 * gateway is called at all.
 */
#[CoversClass( Agend_Apps_Resources_REST_Controller::class )]
final class ResourcesRoutesTest extends TestCase {

	private const A = '09600000-0000-0000-0004-000000000001';

	protected function setUp(): void {
		parent::setUp();
		agend_res_test_reset();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	private function controller(): Agend_Apps_Resources_REST_Controller {
		return new Agend_Apps_Resources_REST_Controller();
	}

	/** Signs a member in: a WordPress user with a token for Agend. */
	private function member( int $id = 7 ): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		wp_set_current_user( $id );
	}

	private function request( string $method, array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/agend-apps/v1/resources/x' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	private function mineParams( array $over = array() ): array {
		return $over + array( 'topics' => true, 'description' => true, 'excerpt' => 140, 'pages' => 4, 'level' => 4 );
	}

	/** The body of a response. */
	private function body( WP_REST_Response $response ): array {
		return (array) $response->get_data();
	}

	private function assertNoStore( WP_REST_Response $response ): void {
		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? null );
	}

	private function authorisationOf( int $index ): ?string {
		return Agend_Test_WP::$requests[ $index ]['headers']['Authorization'] ?? null;
	}

	// -----------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------

	#[Test]
	public function should_register_the_three_routes_with_the_right_methods_and_permissions(): void {
		agend_apps_register_resources_routes();

		$routes = array();
		foreach ( Agend_Test_WP::$rest_routes as $route ) {
			$routes[ $route['route'] ] = $route;
			self::assertSame( 'agend-apps/v1', $route['namespace'] );
		}

		self::assertSame( array( '/resources/collections/mine', '/resources/search', '/resources/ask' ), array_keys( $routes ) );

		self::assertSame( 'GET', $routes['/resources/collections/mine']['args'][0]['methods'] );
		self::assertSame( 'POST', $routes['/resources/ask']['args'][0]['methods'] );
		self::assertSame( 'GET', $routes['/resources/search']['args'][0]['methods'] );

		self::assertSame( '__return_true', $routes['/resources/search']['args'][0]['permission_callback'], 'open at the route, decided inside' );
		self::assertIsArray( $routes['/resources/ask']['args'][0]['permission_callback'] );
		self::assertSame( 'signed_in', $routes['/resources/ask']['args'][0]['permission_callback'][1] );
		self::assertSame( 'signed_in', $routes['/resources/collections/mine']['args'][0]['permission_callback'][1] );
	}

	#[Test]
	public function should_bound_the_arguments_each_route_takes(): void {
		agend_apps_register_resources_routes();

		$by = array();
		foreach ( Agend_Test_WP::$rest_routes as $route ) {
			$by[ $route['route'] ] = $route['args'][0]['args'];
		}

		self::assertSame( 200, $by['/resources/search']['q']['maxLength'] );
		self::assertSame( AGEND_APPS_RECORDS_RESOURCES_SEARCH_RESULTS_MAX, $by['/resources/search']['limit']['maximum'] );
		self::assertSame( AGEND_APPS_RECORDS_RESOURCES_ASK_MAX_CHARS, $by['/resources/ask']['question']['maxLength'] );
		self::assertTrue( $by['/resources/ask']['question']['required'] );
		self::assertSame( 12, $by['/resources/collections/mine']['pages']['maximum'] );
	}

	#[Test]
	public function should_let_only_a_signed_in_user_through_the_member_permission(): void {
		self::assertFalse( $this->controller()->signed_in() );

		wp_set_current_user( 7 );
		self::assertTrue( $this->controller()->signed_in() );
	}

	// -----------------------------------------------------------------
	// A member's collections
	// -----------------------------------------------------------------

	#[Test]
	public function should_refuse_a_signed_in_user_with_no_member_identity_and_call_nothing(): void {
		wp_set_current_user( 7 );

		$response = $this->controller()->my_collections( $this->request( 'GET', $this->mineParams() ) );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'agend_apps_resources_no_member', $this->body( $response )['code'] );
		self::assertFalse( $this->body( $response )['success'] );
		self::assertSame( array(), Agend_Test_WP::$requests );
		$this->assertNoStore( $response );
	}

	#[Test]
	public function should_draw_a_members_collections_with_descriptions_from_the_shared_list(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$this->member();
		agend_res_test_queue_data( array( agend_res_test_member_collection( self::A, 'Starter', array( 'Code of conduct' ) ) ) );
		agend_res_test_queue_collections( array( agend_res_test_collection( self::A, array( 'description' => 'Five practical pages.' ) ) ) );

		$response = $this->controller()->my_collections( $this->request( 'GET', $this->mineParams() ) );
		$data     = $this->body( $response )['data'];

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 1, $data['count'] );
		self::assertStringContainsString( 'Starter', $data['html'] );
		self::assertStringContainsString( 'Five practical pages.', $data['html'] );
		self::assertStringContainsString( 'Code of conduct', $data['html'] );
		self::assertStringContainsString( '/atlas/me/collections', Agend_Test_WP::$requests[0]['url'] );
		self::assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
		self::assertNull( $this->authorisationOf( 1 ), 'the shared list is read as an anonymous visitor' );
		$this->assertNoStore( $response );
	}

	#[Test]
	public function should_follow_the_card_settings_it_is_given(): void {
		$this->member();
		agend_res_test_queue_data( array( agend_res_test_member_collection( self::A, 'Starter', array( 'One', 'Two', 'Three' ) ) ) );
		agend_res_test_queue_collections( array() );

		$response = $this->controller()->my_collections( $this->request( 'GET', $this->mineParams( array( 'pages' => 1, 'level' => 3, 'topics' => false, 'description' => false ) ) ) );
		$html     = $this->body( $response )['data']['html'];

		self::assertStringContainsString( '<h3 class="agend-resources-collections__name">', $html );
		self::assertStringContainsString( 'and 2 more pages', $html );
	}

	#[Test]
	public function should_still_answer_when_only_the_descriptions_could_not_be_read(): void {
		$this->member();
		agend_res_test_queue_data( array( agend_res_test_member_collection( self::A, 'Starter' ) ) );
		Agend_Test_WP::queue_response( 502, array( 'error' => array( 'message' => 'Bad gateway' ) ) );

		$response = $this->controller()->my_collections( $this->request( 'GET', $this->mineParams() ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 1, $this->body( $response )['data']['count'] );
	}

	#[Test]
	public function should_say_so_without_the_gateways_words_when_a_members_collections_cannot_be_read(): void {
		$this->member();
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'code' => 'INTERNAL', 'message' => 'relation "kb_secret" does not exist' ) ) );

		$response = $this->controller()->my_collections( $this->request( 'GET', $this->mineParams() ) );

		self::assertSame( 502, $response->get_status() );
		self::assertSame( 'agend_apps_resources_unavailable', $this->body( $response )['code'] );
		self::assertStringNotContainsString( 'kb_secret', (string) wp_json_encode( $this->body( $response ) ) );
	}

	#[Test]
	public function should_limit_a_member_to_thirty_calls_a_minute_and_refuse_before_the_gateway(): void {
		$this->member();

		for ( $i = 0; $i < 30; $i++ ) {
			agend_res_test_queue_data( array() );
			agend_res_test_queue_collections( array() );
			self::assertSame( 200, $this->controller()->my_collections( $this->request( 'GET', $this->mineParams() ) )->get_status(), 'call ' . ( $i + 1 ) );
			// The site-wide cap is a separate limit; keep it out of this one.
			Agend_Test_WP::$transients = array_filter( Agend_Test_WP::$transients, static fn( string $k ): bool => false === strpos( $k, 'rthrottle_site_calls' ), ARRAY_FILTER_USE_KEY );
		}

		$before   = count( Agend_Test_WP::$requests );
		$response = $this->controller()->my_collections( $this->request( 'GET', $this->mineParams() ) );

		self::assertSame( 429, $response->get_status() );
		self::assertGreaterThan( 0, (int) $response->get_headers()['Retry-After'] );
		self::assertSame( 'agend_apps_resources_busy', $this->body( $response )['code'] );
		self::assertCount( $before, Agend_Test_WP::$requests, 'refused before the gateway' );
		$this->assertNoStore( $response );
	}

	// -----------------------------------------------------------------
	// Search: members
	// -----------------------------------------------------------------

	#[Test]
	public function should_ask_for_at_least_three_characters(): void {
		$this->member();

		$response = $this->controller()->search( $this->request( 'GET', array( 'q' => 'ab ', 'limit' => 6 ) ) );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'agend_apps_resources_too_short', $this->body( $response )['code'] );
		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_search_with_the_members_token_and_never_hold_the_answer(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$this->member();
		agend_res_test_queue_data( array( agend_res_test_hit( agend_res_test_id( 1 ), 'Code of Conduct', 'A complaint about conduct.' ) ) );
		agend_res_test_queue_data( array() );

		$first = $this->controller()->search( $this->request( 'GET', array( 'q' => 'Complaint', 'limit' => 6 ) ) );
		$this->controller()->search( $this->request( 'GET', array( 'q' => 'Complaint', 'limit' => 6 ) ) );

		self::assertSame( 200, $first->get_status() );
		self::assertSame( 'member', $this->body( $first )['data']['scope'] );
		self::assertSame( 'Complaint', $this->body( $first )['data']['query'] );
		self::assertCount( 2, Agend_Test_WP::$requests, 'a member view is never served from a shared entry' );
		self::assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
		self::assertStringContainsString( '/atlas/search?q=Complaint&limit=30', Agend_Test_WP::$requests[0]['url'] );
		$this->assertNoStore( $first );
	}

	#[Test]
	public function should_shape_each_result_with_one_per_page_the_text_marked_and_the_link_decided(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$this->member();
		agend_res_test_queue_data(
			array(
				agend_res_test_hit( agend_res_test_id( 1 ), 'Code of Conduct', 'A <b>complaint</b> about conduct & more.' ),
				agend_res_test_hit( agend_res_test_id( 1 ), 'Code of Conduct', 'A second chunk of the same page.', array( 'chunk_id' => 'c2' ) ),
				agend_res_test_hit( agend_res_test_id( 2 ), 'Fee schedule', 'Fees.', array( 'document_kind' => 'source_doc' ) ),
				agend_res_test_hit( agend_res_test_id( 3 ), 'Benefits', 'Benefits.' ),
			)
		);

		$results = $this->body( $this->controller()->search( $this->request( 'GET', array( 'q' => 'complaint', 'limit' => 6 ) ) ) )['data']['results'];

		self::assertSame( array( 'Code of Conduct', 'Fee schedule', 'Benefits' ), array_column( $results, 'title' ), 'a page that matched twice is listed once' );
		self::assertSame( 'A complaint about conduct &amp; more.', str_replace( array( '<mark>', '</mark>' ), '', $results[0]['excerpt_html'] ), 'markup is stripped and escaped' );
		self::assertStringContainsString( '<mark>complaint</mark>', $results[0]['excerpt_html'] );
		self::assertStringStartsWith( 'https://api.agend.info/api/auth/sso/wdaa/initiate?relayState=', $results[0]['url'], 'a member of a site that is its own identity provider goes through the sign-in link' );
		self::assertFalse( $results[0]['sign_in'] );
		self::assertSame( '', $results[1]['url'], 'a source document has no reader' );
		self::assertFalse( $results[1]['sign_in'], 'and no sign-in note either' );
	}

	#[Test]
	public function should_list_a_members_pages_without_links_and_flag_the_note_on_any_other_sign_in_mode(): void {
		update_option( 'agend_apps_member_auth_mode', 'credentials' );
		$this->member();
		agend_res_test_queue_data( array( agend_res_test_hit( agend_res_test_id( 1 ), 'Code of Conduct' ) ) );

		$results = $this->body( $this->controller()->search( $this->request( 'GET', array( 'q' => 'conduct', 'limit' => 6 ) ) ) )['data']['results'];

		self::assertSame( '', $results[0]['url'] );
		self::assertTrue( $results[0]['sign_in'] );
	}

	#[Test]
	public function should_hold_the_results_to_the_number_asked_for(): void {
		$this->member();
		$hits = array();

		for ( $i = 1; $i <= 12; $i++ ) {
			$hits[] = agend_res_test_hit( agend_res_test_id( $i ), 'Page ' . $i );
		}
		agend_res_test_queue_data( $hits );

		$results = $this->body( $this->controller()->search( $this->request( 'GET', array( 'q' => 'page', 'limit' => 4 ) ) ) )['data']['results'];

		self::assertCount( 4, $results );
	}

	#[Test]
	public function should_limit_a_member_to_thirty_searches_a_minute_and_refuse_before_the_gateway(): void {
		$this->member();

		for ( $i = 0; $i < 30; $i++ ) {
			agend_res_test_queue_data( array() );
			self::assertSame( 200, $this->controller()->search( $this->request( 'GET', array( 'q' => 'query ' . $i, 'limit' => 6 ) ) )->get_status(), 'search ' . ( $i + 1 ) );
			// The site-wide cap is a separate limit; keep it out of this one.
			Agend_Test_WP::$transients = array_filter( Agend_Test_WP::$transients, static fn( string $k ): bool => false === strpos( $k, 'rthrottle_site_calls' ), ARRAY_FILTER_USE_KEY );
		}

		$before   = count( Agend_Test_WP::$requests );
		$response = $this->controller()->search( $this->request( 'GET', array( 'q' => 'conduct', 'limit' => 6 ) ) );

		self::assertSame( 429, $response->get_status() );
		self::assertCount( $before, Agend_Test_WP::$requests, 'refused before the gateway' );
	}

	#[Test]
	public function should_count_each_member_search_against_the_site_wide_cap_because_none_is_ever_held(): void {
		for ( $member = 1; $member <= 20; $member++ ) {
			$this->member( 300 + $member );
			agend_res_test_queue_data( array() );
			self::assertSame( 200, $this->controller()->search( $this->request( 'GET', array( 'q' => 'query ' . $member, 'limit' => 6 ) ) )->get_status(), 'search ' . $member );
		}

		$this->member( 400 );
		$before = count( Agend_Test_WP::$requests );

		self::assertSame( 429, $this->controller()->search( $this->request( 'GET', array( 'q' => 'query 1', 'limit' => 6 ) ) )->get_status() );
		self::assertCount( $before, Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// Search: visitors
	// -----------------------------------------------------------------

	#[Test]
	public function should_refuse_a_visitor_with_no_proof_a_page_offered_live_search_and_call_nothing(): void {
		$none  = $this->controller()->search( $this->request( 'GET', array( 'q' => 'conduct', 'limit' => 6 ) ) );
		$wrong = $this->controller()->search( $this->request( 'GET', array( 'q' => 'conduct', 'limit' => 6, 'token' => 'guess' ) ) );

		foreach ( array( $none, $wrong ) as $response ) {
			self::assertSame( 403, $response->get_status() );
			self::assertSame( 'agend_apps_resources_signed_out', $this->body( $response )['code'] );
			$this->assertNoStore( $response );
		}

		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_search_for_a_visitor_with_the_proof_as_an_anonymous_visitor_and_link_public_pages_direct(): void {
		agend_res_test_queue_data( array( agend_res_test_hit( agend_res_test_id( 1 ), 'Code of Conduct', 'About conduct.' ) ) );

		$response = $this->controller()->search( $this->request( 'GET', array( 'q' => 'conduct', 'limit' => 6, 'token' => agend_apps_records_resources_visitor_token() ) ) );
		$data     = $this->body( $response )['data'];

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'visitor', $data['scope'] );
		self::assertSame( 'https://atlas.agend.info/read/wdaa/' . agend_res_test_id( 1 ), $data['results'][0]['url'] );
		self::assertFalse( $data['results'][0]['sign_in'] );
		self::assertNull( $this->authorisationOf( 0 ), 'no member token, so only public pages come back' );
	}

	#[Test]
	public function should_hold_a_visitors_search_per_query_so_a_repeat_makes_no_gateway_call(): void {
		agend_res_test_queue_data( array( agend_res_test_hit( agend_res_test_id( 1 ), 'Code of Conduct' ) ) );
		$params = array( 'q' => 'conduct', 'limit' => 6, 'token' => agend_apps_records_resources_visitor_token() );

		$this->controller()->search( $this->request( 'GET', $params ) );
		$again = $this->controller()->search( $this->request( 'GET', $params ) );

		self::assertSame( 200, $again->get_status() );
		self::assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_treat_a_signed_in_user_with_no_member_identity_as_a_visitor_for_search(): void {
		wp_set_current_user( 7 );

		$without = $this->controller()->search( $this->request( 'GET', array( 'q' => 'conduct', 'limit' => 6 ) ) );

		self::assertSame( 403, $without->get_status() );
		self::assertSame( 'agend_apps_resources_no_member', $this->body( $without )['code'] );
		self::assertSame( array(), Agend_Test_WP::$requests );

		agend_res_test_queue_data( array() );
		$with = $this->controller()->search( $this->request( 'GET', array( 'q' => 'conduct', 'limit' => 6, 'token' => agend_apps_records_resources_visitor_token() ) ) );

		self::assertSame( 'visitor', $this->body( $with )['data']['scope'] );
	}

	#[Test]
	public function should_let_a_site_turn_visitor_search_off_everywhere_with_a_filter(): void {
		add_filter( 'agend_apps_resources_visitor_search_enabled', static fn(): bool => false );

		$response = $this->controller()->search( $this->request( 'GET', array( 'q' => 'conduct', 'limit' => 6, 'token' => agend_apps_records_resources_visitor_token() ) ) );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_limit_a_visitor_address_to_ten_searches_a_minute_and_refuse_before_the_gateway(): void {
		$token = agend_apps_records_resources_visitor_token();

		for ( $i = 0; $i < 10; $i++ ) {
			agend_res_test_queue_data( array() );
			self::assertSame( 200, $this->controller()->search( $this->request( 'GET', array( 'q' => 'query ' . $i, 'limit' => 6, 'token' => $token ) ) )->get_status(), 'search ' . ( $i + 1 ) );
		}

		$before   = count( Agend_Test_WP::$requests );
		$response = $this->controller()->search( $this->request( 'GET', array( 'q' => 'one more', 'limit' => 6, 'token' => $token ) ) );

		self::assertSame( 429, $response->get_status() );
		self::assertGreaterThan( 0, (int) $response->get_headers()['Retry-After'] );
		self::assertSame( 'agend_apps_resources_busy', $this->body( $response )['code'] );
		self::assertCount( $before, Agend_Test_WP::$requests );
		$this->assertNoStore( $response );
	}

	#[Test]
	public function should_count_every_visitor_search_even_a_held_one_against_the_address(): void {
		$token = agend_apps_records_resources_visitor_token();
		agend_res_test_queue_data( array() );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->controller()->search( $this->request( 'GET', array( 'q' => 'same words', 'limit' => 6, 'token' => $token ) ) );
		}

		self::assertSame( 429, $this->controller()->search( $this->request( 'GET', array( 'q' => 'same words', 'limit' => 6, 'token' => $token ) ) )->get_status() );
		self::assertCount( 1, Agend_Test_WP::$requests, 'the route cannot be used to probe the cache without limit' );
	}

	#[Test]
	public function should_cap_the_gateway_calls_all_visitors_make_together_but_not_held_answers(): void {
		$token = agend_apps_records_resources_visitor_token();

		for ( $i = 0; $i < 20; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( $i + 1 );
			agend_res_test_queue_data( array() );
			self::assertSame( 200, $this->controller()->search( $this->request( 'GET', array( 'q' => 'query ' . $i, 'limit' => 6, 'token' => $token ) ) )->get_status(), 'call ' . ( $i + 1 ) );
		}

		$_SERVER['REMOTE_ADDR'] = '203.0.113.200';
		$before                 = count( Agend_Test_WP::$requests );

		$new = $this->controller()->search( $this->request( 'GET', array( 'q' => 'a brand new query', 'limit' => 6, 'token' => $token ) ) );
		self::assertSame( 429, $new->get_status(), 'the 21st uncached call in a minute is refused' );
		self::assertCount( $before, Agend_Test_WP::$requests );

		$held = $this->controller()->search( $this->request( 'GET', array( 'q' => 'query 3', 'limit' => 6, 'token' => $token ) ) );
		self::assertSame( 200, $held->get_status(), 'a held answer costs the gateway nothing, so it is not counted' );
		self::assertCount( $before, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_say_so_without_the_gateways_words_when_search_fails_and_say_busy_when_it_is_rate_limited(): void {
		$this->member();
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'relation "kb_chunks" does not exist' ) ) );

		$failed = $this->controller()->search( $this->request( 'GET', array( 'q' => 'conduct', 'limit' => 6 ) ) );

		self::assertSame( 502, $failed->get_status() );
		self::assertSame( 'agend_apps_resources_unavailable', $this->body( $failed )['code'] );
		self::assertStringNotContainsString( 'kb_chunks', (string) wp_json_encode( $this->body( $failed ) ) );

		Agend_Test_WP::queue_response( 429, array( 'error' => array( 'message' => 'Too many requests' ) ) );
		$limited = $this->controller()->search( $this->request( 'GET', array( 'q' => 'another', 'limit' => 6 ) ) );

		self::assertSame( 429, $limited->get_status() );
		self::assertSame( 'agend_apps_resources_busy', $this->body( $limited )['code'] );
	}

	// -----------------------------------------------------------------
	// Ask
	// -----------------------------------------------------------------

	private function ask( string $question = 'How do I make a complaint?' ): WP_REST_Response {
		return $this->controller()->ask( $this->request( 'POST', array( 'question' => $question ) ) );
	}

	#[Test]
	public function should_refuse_a_question_from_a_user_with_no_member_identity_and_call_nothing(): void {
		wp_set_current_user( 7 );

		$response = $this->ask();

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'agend_apps_resources_no_member', $this->body( $response )['code'] );
		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_ask_for_a_question_of_at_least_three_characters(): void {
		$this->member();

		self::assertSame( 400, $this->ask( ' a ' )->get_status() );
		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_send_the_members_question_tidied_with_their_token_and_remember_that_ask_works(): void {
		$this->member();
		agend_res_test_queue_data( array( 'kind' => 'refusal', 'message' => 'Nothing on that.', 'disclaimer' => 'Check it.' ) );

		$response = $this->ask( "  How do I\n make   a complaint? " );

		self::assertSame( 200, $response->get_status() );
		self::assertStringEndsWith( '/atlas/me/ask', Agend_Test_WP::$requests[0]['url'] );
		self::assertSame( 'How do I make a complaint?', json_decode( (string) Agend_Test_WP::$requests[0]['body'], true )['question'] );
		self::assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
		self::assertSame( 'available', agend_apps_atlas_ask_state() );
		$this->assertNoStore( $response );
	}

	#[Test]
	public function should_shape_an_answer_with_its_sources_linked_for_a_member_of_a_site_that_is_its_own_identity_provider(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$this->member();
		agend_res_test_queue_data(
			array(
				'kind'       => 'answer',
				'answer'     => "Write to the Conduct Officer [1].\n\nThe committee reviews it [2].",
				'confidence' => 'high',
				'disclaimer' => 'Answers may not cover every situation.',
				'citations'  => array(
					array( 'marker' => 1, 'document_id' => agend_res_test_id( 1 ), 'document_title' => 'Code of Conduct', 'document_kind' => 'page', 'chunk_id' => 'x', 'document_version_id' => 'v' ),
					array( 'marker' => 2, 'document_id' => agend_res_test_id( 2 ), 'document_title' => 'Fee schedule', 'document_kind' => 'source_doc' ),
				),
			)
		);

		$data = $this->body( $this->ask() )['data'];

		self::assertSame( 'answer', $data['kind'] );
		self::assertStringContainsString( 'Write to the Conduct Officer [1].', $data['answer'] );
		self::assertSame( 'high', $data['confidence'] );
		self::assertSame( 'Answers may not cover every situation.', $data['disclaimer'] );
		self::assertSame( array( 1, 2 ), array_column( $data['sources'], 'marker' ) );
		self::assertStringStartsWith( 'https://api.agend.info/api/auth/sso/wdaa/initiate?relayState=', $data['sources'][0]['url'] );
		self::assertSame( '', $data['sources'][1]['url'], 'a source document has no reader' );
		self::assertFalse( $data['sources'][1]['sign_in'] );
	}

	#[Test]
	public function should_flag_the_sign_in_note_for_sources_on_any_other_sign_in_mode_and_mark_a_low_confidence_answer(): void {
		update_option( 'agend_apps_member_auth_mode', 'credentials' );
		$this->member();
		agend_res_test_queue_data(
			array(
				'kind'       => 'answer',
				'answer'     => 'Maybe [1].',
				'confidence' => 'low',
				'disclaimer' => '',
				'citations'  => array( array( 'marker' => 1, 'document_id' => agend_res_test_id( 1 ), 'document_title' => 'Benefits', 'document_kind' => 'page' ) ),
			)
		);

		$data = $this->body( $this->ask() )['data'];

		self::assertSame( 'low', $data['confidence'] );
		self::assertSame( '', $data['sources'][0]['url'] );
		self::assertTrue( $data['sources'][0]['sign_in'] );
	}

	#[Test]
	public function should_never_pass_on_the_plan_the_reason_or_anything_the_gateway_did_not_mean_to_publish(): void {
		$this->member();
		agend_res_test_queue_data(
			array(
				'kind'       => 'answer',
				'answer'     => 'Yes.',
				'confidence' => 'high',
				'disclaimer' => 'Check.',
				'tier'       => 'enterprise',
				'reason'     => 'secret',
				'citations'  => array( array( 'marker' => 1, 'document_id' => agend_res_test_id( 1 ), 'document_title' => 'Benefits', 'chunk_id' => 'chunk-secret', 'document_version_id' => 'version-secret', 'char_start' => 1234 ) ),
			)
		);

		$json = (string) wp_json_encode( $this->body( $this->ask() ) );

		foreach ( array( 'enterprise', 'secret', 'chunk-secret', 'version-secret', '1234' ) as $leak ) {
			self::assertStringNotContainsString( $leak, $json, $leak );
		}
	}

	#[Test]
	public function should_pass_on_a_refusal_with_its_message_and_disclaimer(): void {
		$this->member();
		agend_res_test_queue_data( array( 'kind' => 'refusal', 'message' => '<b>Nothing</b> on that.', 'disclaimer' => 'Check it.' ) );

		$data = $this->body( $this->ask() )['data'];

		self::assertSame( array( 'kind' => 'refusal', 'message' => 'Nothing on that.', 'disclaimer' => 'Check it.' ), $data );
	}

	#[Test]
	public function should_pass_on_a_degraded_answer_with_the_pages_that_may_help_and_a_contact(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$this->member();
		agend_res_test_queue_data(
			array(
				'kind'           => 'degraded',
				'message'        => 'Answers are paused.',
				'search_results' => array(
					array(
						'chunk_id'            => 'c1',
						'document_id'         => agend_res_test_id( 1 ),
						'document_version_id' => 'v1',
						'document_title'      => 'Code of Conduct',
						'document_kind'       => 'page',
						'snippet'             => 'A complaint can be raised in writing.',
					),
				),
				'contact_path'   => 'https://example.test/contact/',
			)
		);

		$data = $this->body( $this->ask() )['data'];

		self::assertSame( 'degraded', $data['kind'] );
		self::assertSame( 'Answers are paused.', $data['message'] );
		self::assertSame( 'Code of Conduct', $data['matches'][0]['title'] );
		self::assertStringContainsString( 'A complaint can be raised in writing.', $data['matches'][0]['excerpt_html'] );
		self::assertSame( 'https://example.test/contact/', $data['contact'] );
		self::assertSame( 'available', agend_apps_atlas_ask_state() );
	}

	#[Test]
	public function should_remember_a_not_entitled_verdict_and_say_nothing_of_the_plan_to_a_member(): void {
		$this->member();
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = false;
		agend_res_test_queue_data( array( 'kind' => 'not_entitled', 'tier' => 'free', 'reason' => 'no_subscription' ) );

		$data = $this->body( $this->ask() )['data'];

		self::assertSame( array( 'kind' => 'not_entitled' ), $data );
		self::assertSame( 'not_entitled', agend_apps_atlas_ask_state() );
	}

	#[Test]
	public function should_explain_it_to_an_editor_who_gets_the_verdict(): void {
		$this->member();
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = true;
		agend_res_test_queue_data( array( 'kind' => 'not_entitled', 'tier' => 'free', 'reason' => 'no_subscription' ) );

		$data = $this->body( $this->ask() )['data'];

		self::assertSame( 'not_entitled', $data['kind'] );
		self::assertStringContainsString( 'Ask is not part of your Agend plan yet', $data['editor_note'] );
		self::assertStringNotContainsString( 'free', (string) wp_json_encode( $data ) );
	}

	#[Test]
	public function should_answer_not_entitled_without_a_gateway_call_or_using_up_a_turn_once_it_is_known(): void {
		$this->member();
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = false;
		agend_apps_atlas_record_ask_verdict( 'not_entitled' );

		for ( $i = 0; $i < 6; $i++ ) {
			self::assertSame( array( 'kind' => 'not_entitled' ), $this->body( $this->ask() )['data'] );
		}

		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_limit_a_member_to_three_questions_in_ten_minutes_and_refuse_before_the_gateway(): void {
		$this->member();

		for ( $i = 0; $i < 3; $i++ ) {
			agend_res_test_queue_data( array( 'kind' => 'refusal', 'message' => 'No.', 'disclaimer' => '' ) );
			// The site-wide cap is a separate limit; keep it out of this one.
			self::assertSame( 200, $this->ask()->get_status(), 'question ' . ( $i + 1 ) );
			Agend_Test_WP::$transients = array_filter( Agend_Test_WP::$transients, static fn( string $k ): bool => false === strpos( $k, 'rthrottle_site_calls' ), ARRAY_FILTER_USE_KEY );
		}

		$before   = count( Agend_Test_WP::$requests );
		$response = $this->ask();

		self::assertSame( 429, $response->get_status() );
		self::assertGreaterThan( 0, (int) $response->get_headers()['Retry-After'] );
		self::assertSame( 'agend_apps_resources_busy', $this->body( $response )['code'] );
		self::assertCount( $before, Agend_Test_WP::$requests );
		$this->assertNoStore( $response );

		$this->member( 8 );
		agend_res_test_queue_data( array( 'kind' => 'refusal', 'message' => 'No.', 'disclaimer' => '' ) );
		self::assertSame( 200, $this->ask()->get_status(), 'another member has their own three' );
	}

	#[Test]
	public function should_stop_the_site_at_thirty_questions_a_day_whoever_asks(): void {
		for ( $member = 1; $member <= 30; $member++ ) {
			$this->member( 100 + $member );
			agend_res_test_queue_data( array( 'kind' => 'refusal', 'message' => 'No.', 'disclaimer' => '' ) );
			self::assertSame( 200, $this->ask()->get_status(), 'question ' . $member );
			Agend_Test_WP::$transients = array_filter( Agend_Test_WP::$transients, static fn( string $k ): bool => false === strpos( $k, 'rthrottle_site_calls' ), ARRAY_FILTER_USE_KEY );
		}

		$this->member( 200 );
		$before   = count( Agend_Test_WP::$requests );
		$response = $this->ask();

		self::assertSame( 429, $response->get_status() );
		self::assertStringContainsString( 'today', $this->body( $response )['message'] );
		self::assertCount( $before, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_say_so_without_the_gateways_words_when_ask_fails_or_returns_something_unknown(): void {
		$this->member();
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'OpenAI quota exceeded for org-secret' ) ) );

		$failed = $this->ask();

		self::assertSame( 502, $failed->get_status() );
		self::assertStringNotContainsString( 'org-secret', (string) wp_json_encode( $this->body( $failed ) ) );
		self::assertSame( 'unknown', agend_apps_atlas_ask_state(), 'an outage says nothing about the plan' );

		$this->member( 8 );
		agend_res_test_queue_data( array( 'kind' => 'something_new' ) );
		self::assertSame( 502, $this->ask()->get_status() );
	}

	#[Test]
	public function should_say_busy_when_the_gateway_rate_limits_a_question(): void {
		$this->member();
		Agend_Test_WP::queue_response( 429, array( 'error' => array( 'message' => 'Too many requests' ) ) );

		$response = $this->ask();

		self::assertSame( 429, $response->get_status() );
		self::assertSame( 'agend_apps_resources_busy', $this->body( $response )['code'] );
	}

	// -----------------------------------------------------------------
	// Helpers the routes use
	// -----------------------------------------------------------------

	/** @return array<string, array{array, int}> */
	public static function malformedHits(): array {
		return array(
			'no id'           => array( array( array( 'document_title' => 'T', 'excerpt' => 'x' ) ), 0 ),
			'no title'        => array( array( array( 'document_id' => 'abc12345', 'document_title' => '', 'excerpt' => 'x' ) ), 0 ),
			'not an array'    => array( array( 'junk', null ), 0 ),
			'one good one'    => array( array( array( 'document_id' => 'abc12345', 'document_title' => 'T', 'excerpt' => 'x' ), 'junk' ), 1 ),
		);
	}

	#[Test]
	#[DataProvider( 'malformedHits' )]
	public function should_skip_a_search_hit_it_cannot_draw( array $hits, int $expected ): void {
		self::assertCount( $expected, agend_apps_resources_search_results( $hits, 'x', 6, true ) );
	}

	#[Test]
	public function should_skip_a_source_with_no_title_and_give_a_missing_marker_the_next_number(): void {
		$sources = agend_apps_resources_ask_sources(
			array(
				'junk',
				array( 'document_id' => agend_res_test_id( 1 ), 'document_title' => '' ),
				array( 'document_id' => agend_res_test_id( 2 ), 'document_title' => 'Kept' ),
			)
		);

		self::assertCount( 1, $sources );
		self::assertSame( 'Kept', $sources[0]['title'] );
		self::assertSame( 1, $sources[0]['marker'] );
	}
}
