<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Logger;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-http-batch.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/account-link-state.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/atlas.php';
require_once __DIR__ . '/fixtures/memory-log-store.php';
require_once __DIR__ . '/fixtures/real-cache.php';

/**
 * The Atlas wrappers the Resources surfaces read, the links into Atlas, and
 * what the site learns about Ask.
 *
 * The properties that matter: a visitor's reads are anonymous and shared, a
 * member's are never stored and need their token, and nothing is asked of the
 * gateway when there is no member to ask for.
 */
final class ResourcesAtlasApiTest extends TestCase {

	private const SLUG = 'wdaa';
	private const ID   = '09600000-0000-0000-0004-000000000001';

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		// The settings class is a double whose root URL is this option; the real
		// environment switch has its own tests.
		update_option( 'agend_apps_root_url_for_tests', 'https://api.agend.info' );
		update_option( 'agend_apps_account_slug', self::SLUG );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	private function asMember(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
	}

	private function authorisationOf( int $request_index ): ?string {
		return Agend_Test_WP::$requests[ $request_index ]['headers']['Authorization'] ?? null;
	}

	// -----------------------------------------------------------------
	// Where Atlas lives
	// -----------------------------------------------------------------

	/** @return array<string, array{0: string, 1: string}> */
	public static function apiRoots(): array {
		return array(
			'production'            => array( 'https://api.agend.com.au', 'https://atlas.agend.com.au' ),
			'staging'               => array( 'https://api.agend.info', 'https://atlas.agend.info' ),
			'trailing slash'        => array( 'https://api.agend.info/', 'https://atlas.agend.info' ),
			'local gateway port'    => array( 'http://localhost:3072', 'http://localhost:3066' ),
			'custom api host'       => array( 'https://api.example.test', 'https://atlas.example.test' ),
			'host without api.'     => array( 'https://gateway.example.test', '' ),
			'nothing'               => array( '', '' ),
		);
	}

	#[Test]
	#[DataProvider( 'apiRoots' )]
	public function should_work_out_the_atlas_address_from_the_api_root( string $root, string $expected ): void {
		self::assertSame( $expected, agend_apps_app_derive_url( 'atlas', $root ) );
	}

	#[Test]
	public function should_follow_the_api_root_the_site_is_set_to(): void {
		self::assertSame( 'https://atlas.agend.info', agend_apps_atlas_app_url() );

		update_option( 'agend_apps_root_url_for_tests', 'https://api.agend.com.au' );
		self::assertSame( 'https://atlas.agend.com.au', agend_apps_atlas_app_url() );

		update_option( 'agend_apps_root_url_for_tests', 'http://localhost:3072' );
		self::assertSame( 'http://localhost:3066', agend_apps_atlas_app_url() );
	}

	#[Test]
	public function should_let_an_explicit_option_win_over_the_derived_address(): void {
		update_option( 'agend_apps_atlas_url', 'https://library.example.test/' );

		self::assertSame( 'https://library.example.test', agend_apps_atlas_app_url() );
	}

	#[Test]
	public function should_ignore_an_explicit_option_that_is_not_a_web_address(): void {
		update_option( 'agend_apps_atlas_url', 'javascript:alert(1)' );

		self::assertSame( 'https://atlas.agend.info', agend_apps_atlas_app_url() );
	}

	#[Test]
	public function should_let_the_filter_have_the_last_word_and_say_which_app(): void {
		$seen = array();
		add_filter(
			'agend_apps_app_url',
			static function ( string $url, string $app ) use ( &$seen ): string {
				$seen = array( $url, $app );
				return 'https://override.example.test/';
			},
			10,
			2
		);

		self::assertSame( 'https://override.example.test', agend_apps_atlas_app_url() );
		self::assertSame( array( 'https://atlas.agend.info', 'atlas' ), $seen );
	}

	// -----------------------------------------------------------------
	// Links into Atlas
	// -----------------------------------------------------------------

	#[Test]
	public function should_build_the_public_reader_address_from_the_account_and_the_lineage_id(): void {
		self::assertSame( 'https://atlas.agend.info/read/wdaa/' . self::ID, agend_apps_atlas_read_url( self::ID ) );
	}

	#[Test]
	public function should_build_no_reader_address_without_an_account_an_app_address_or_a_plain_id(): void {
		self::assertSame( '', agend_apps_atlas_read_url( '' ), 'no id' );
		self::assertSame( '', agend_apps_atlas_read_url( '../admin' ), 'not an id' );
		self::assertSame( '', agend_apps_atlas_read_url( 'abc' ), 'too short to be one' );

		update_option( 'agend_apps_account_slug', '' );
		self::assertSame( '', agend_apps_atlas_read_url( self::ID ), 'no account' );
		self::assertFalse( agend_apps_atlas_linkable() );

		update_option( 'agend_apps_account_slug', self::SLUG );
		update_option( 'agend_apps_root_url_for_tests', 'https://gateway.example.test' );
		self::assertSame( '', agend_apps_atlas_read_url( self::ID ), 'no app address to derive' );
	}

	#[Test]
	public function should_encode_the_account_in_the_address(): void {
		update_option( 'agend_apps_account_slug', 'a b/c' );

		self::assertSame( 'https://atlas.agend.info/read/a%20b%2Fc/' . self::ID, agend_apps_atlas_read_url( self::ID ) );
	}

	#[Test]
	public function should_point_search_at_the_public_search_page_with_or_without_words(): void {
		self::assertSame( 'https://atlas.agend.info/knowledge/wdaa/search', agend_apps_atlas_public_search_url() );
		self::assertSame( 'https://atlas.agend.info/knowledge/wdaa/search?q=code%20of%20conduct%20%26%20more', agend_apps_atlas_public_search_url( 'code of conduct & more' ) );

		update_option( 'agend_apps_account_slug', '' );
		self::assertSame( '', agend_apps_atlas_public_search_url() );
	}

	#[Test]
	public function should_link_a_page_a_visitor_found_straight_to_the_reader(): void {
		self::assertSame(
			array(
				'url' => 'https://atlas.agend.info/read/wdaa/' . self::ID,
				'via' => 'reader',
			),
			agend_apps_atlas_page_link( self::ID, true )
		);
	}

	#[Test]
	public function should_wrap_a_members_page_in_the_sign_in_link_when_this_site_is_the_identity_provider(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );

		$link = agend_apps_atlas_page_link( self::ID, false );

		self::assertSame( 'sign_in', $link['via'] );
		self::assertStringStartsWith( 'https://api.agend.info/api/auth/sso/wdaa/initiate?relayState=', $link['url'] );
		// Whether the return address is encoded once or twice is the sign-in
		// helper's own business: it must be the reader address once decoded.
		self::assertStringContainsString( 'https://atlas.agend.info/read/wdaa/' . self::ID, rawurldecode( rawurldecode( $link['url'] ) ) );
	}

	#[Test]
	#[DataProvider( 'otherSignInModes' )]
	public function should_give_a_members_page_no_link_on_any_other_sign_in_mode( string $mode ): void {
		update_option( 'agend_apps_member_auth_mode', $mode );

		self::assertSame(
			array(
				'url' => '',
				'via' => '',
			),
			agend_apps_atlas_page_link( self::ID, false ),
			'a direct link would be a not-found page until Atlas has a session'
		);
	}

	/** @return array<string, array{0: string}> */
	public static function otherSignInModes(): array {
		return array(
			'credentials' => array( 'credentials' ),
			'sso'         => array( 'sso' ),
		);
	}

	// -----------------------------------------------------------------
	// Collections: anonymous, shared
	// -----------------------------------------------------------------

	#[Test]
	public function should_read_the_collection_list_as_an_anonymous_visitor_and_share_the_answer(): void {
		$this->asMember();
		Agend_Test_WP::queue_response( 200, array( 'data' => array( array( 'id' => self::ID ) ) ) );

		$first = agend_apps_atlas_get_collections( array( 'limit' => 100 ) );
		agend_apps_atlas_get_collections( array( 'limit' => 100 ) );

		self::assertSame( self::ID, $first['data'][0]['id'] );
		self::assertCount( 1, Agend_Test_WP::$requests, 'the second read is served from the shared entry' );
		self::assertNull( $this->authorisationOf( 0 ), 'a signed-in visitor is read as an anonymous one' );
		self::assertStringContainsString( '/atlas/collections?limit=100', Agend_Test_WP::$requests[0]['url'] );
		self::assertCount( 1, array_filter( array_keys( Agend_Test_WP::$transients ), static fn( string $k ): bool => 0 === strpos( $k, 'agend_apps_atlas_collections' ) ) );
	}

	#[Test]
	public function should_keep_the_collection_reads_for_different_queries_apart(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		agend_apps_atlas_get_collections( array( 'limit' => 100 ) );
		agend_apps_atlas_get_collections( array( 'limit' => 10 ) );

		self::assertCount( 2, Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// Search
	// -----------------------------------------------------------------

	#[Test]
	public function should_search_as_an_anonymous_visitor_and_hold_the_answer_per_query(): void {
		$this->asMember();
		Agend_Test_WP::queue_response( 200, array( 'data' => array( array( 'document_id' => self::ID ) ) ) );

		agend_apps_atlas_search( 'Code of  conduct', 30 );
		agend_apps_atlas_search( ' code of conduct ', 30 );

		self::assertCount( 1, Agend_Test_WP::$requests, 'the same words, spaced or cased differently, are one search' );
		self::assertNull( $this->authorisationOf( 0 ) );
		self::assertStringContainsString( '/atlas/search?q=Code%20of%20conduct&limit=30', Agend_Test_WP::$requests[0]['url'] );
	}

	#[Test]
	public function should_hold_searches_for_different_words_or_sizes_apart(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		agend_apps_atlas_search( 'conduct', 30 );
		agend_apps_atlas_search( 'complaints', 30 );
		agend_apps_atlas_search( 'conduct', 10 );

		self::assertCount( 3, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_search_with_the_members_token_and_store_nothing_for_a_member(): void {
		$this->asMember();
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		agend_apps_atlas_search( 'conduct', 30, true );
		agend_apps_atlas_search( 'conduct', 30, true );

		self::assertCount( 2, Agend_Test_WP::$requests, 'a member view is never served from a shared entry' );
		self::assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
		self::assertSame( array(), array_values( preg_grep( '/^agend_apps_atlas_search/', array_keys( Agend_Test_WP::$transients ) ) ) );
	}

	#[Test]
	public function should_report_whether_a_visitor_search_is_already_held(): void {
		self::assertFalse( agend_apps_atlas_visitor_search_is_cached( 'conduct', 30 ) );

		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );
		agend_apps_atlas_search( 'conduct', 30 );

		self::assertTrue( agend_apps_atlas_visitor_search_is_cached( ' Conduct ', 30 ) );
		self::assertFalse( agend_apps_atlas_visitor_search_is_cached( 'conduct', 10 ) );
		self::assertFalse( agend_apps_atlas_visitor_search_is_cached( '', 30 ) );
	}

	#[Test]
	public function should_ask_nothing_of_the_gateway_for_empty_words(): void {
		$result = agend_apps_atlas_search( "  \n ", 30 );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'agend_apps_atlas_empty_query', $result->get_error_code() );
		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_hold_the_words_to_200_characters_and_the_hits_to_100(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		agend_apps_atlas_search( str_repeat( 'a', 300 ), 500 );

		self::assertStringContainsString( 'q=' . str_repeat( 'a', 200 ) . '&limit=100', Agend_Test_WP::$requests[0]['url'] );
	}

	#[Test]
	public function should_run_the_search_filter_over_the_request_args(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );
		$seen = null;
		add_filter(
			'agend_apps_atlas_search_args',
			static function ( array $args, array $query, bool $as_member ) use ( &$seen ): array {
				$seen = array( $query, $as_member );
				return $args;
			},
			10,
			3
		);

		agend_apps_atlas_search( 'conduct', 30 );

		self::assertSame( array( array( 'q' => 'conduct', 'limit' => 30 ), false ), $seen );
	}

	// -----------------------------------------------------------------
	// A member's own collections
	// -----------------------------------------------------------------

	#[Test]
	public function should_ask_for_a_members_collections_only_with_their_token(): void {
		$result = agend_apps_atlas_get_my_collections();

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'agend_apps_atlas_not_signed_in', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status_code'] );
		self::assertSame( array(), Agend_Test_WP::$requests, 'no member, no call' );
	}

	#[Test]
	public function should_read_a_members_collections_with_their_token_and_store_nothing(): void {
		$this->asMember();
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( array( 'id' => self::ID, 'items' => array() ) ) ) );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array() ) );

		$first = agend_apps_atlas_get_my_collections();
		agend_apps_atlas_get_my_collections();

		self::assertSame( self::ID, $first['data'][0]['id'] );
		self::assertCount( 2, Agend_Test_WP::$requests );
		self::assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
		self::assertStringContainsString( '/atlas/me/collections', Agend_Test_WP::$requests[0]['url'] );
		self::assertSame( array(), array_values( preg_grep( '/atlas/', array_keys( Agend_Test_WP::$transients ) ) ) );
	}

	// -----------------------------------------------------------------
	// Ask
	// -----------------------------------------------------------------

	#[Test]
	public function should_ask_nothing_of_the_gateway_without_a_member_or_a_question(): void {
		$empty = agend_apps_atlas_ask( '   ' );
		self::assertSame( 'agend_apps_atlas_empty_question', $empty->get_error_code() );

		$anonymous = agend_apps_atlas_ask( 'How do I complain?' );
		self::assertSame( 'agend_apps_atlas_not_signed_in', $anonymous->get_error_code() );

		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_post_a_members_question_to_their_own_ask_with_a_long_timeout(): void {
		$this->asMember();
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'kind' => 'refusal', 'message' => 'No sources.', 'disclaimer' => 'Check it.' ) ) );

		$result = agend_apps_atlas_ask( '  How do I complain? ' );

		self::assertSame( 'refusal', $result['data']['kind'] );
		self::assertSame( 'POST', Agend_Test_WP::$requests[0]['method'] );
		self::assertStringEndsWith( '/atlas/me/ask', Agend_Test_WP::$requests[0]['url'] );
		self::assertSame( array( 'question' => 'How do I complain?' ), json_decode( (string) Agend_Test_WP::$requests[0]['body'], true ) );
		self::assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
		self::assertSame( 45, Agend_Test_WP::$requests[0]['timeout'] );
	}

	// -----------------------------------------------------------------
	// What the site has learned about Ask
	// -----------------------------------------------------------------

	#[Test]
	public function should_know_nothing_about_ask_until_a_verdict_comes_back(): void {
		self::assertSame( 'unknown', agend_apps_atlas_ask_state() );
	}

	#[Test]
	public function should_remember_a_not_entitled_verdict_from_a_real_ask(): void {
		$this->asMember();
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'kind' => 'not_entitled', 'tier' => 'free', 'reason' => 'no_subscription' ) ) );

		agend_apps_atlas_ask( 'How do I complain?' );

		self::assertSame( 'not_entitled', agend_apps_atlas_ask_state() );
	}

	#[Test]
	#[DataProvider( 'answeringVerdicts' )]
	public function should_remember_that_ask_is_available_after_any_verdict_that_means_it_works( string $kind ): void {
		agend_apps_atlas_record_ask_verdict( $kind );

		self::assertSame( 'available', agend_apps_atlas_ask_state() );
	}

	/** @return array<string, array{0: string}> */
	public static function answeringVerdicts(): array {
		return array(
			'answer'   => array( 'answer' ),
			'refusal'  => array( 'refusal' ),
			'degraded' => array( 'degraded' ),
		);
	}

	#[Test]
	public function should_remember_nothing_from_a_failure_or_an_unknown_kind(): void {
		agend_apps_atlas_record_ask_verdict( '' );
		agend_apps_atlas_record_ask_verdict( 'something_new' );

		self::assertSame( 'unknown', agend_apps_atlas_ask_state() );

		$this->asMember();
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'Boom' ) ) );
		agend_apps_atlas_ask( 'How do I complain?' );

		self::assertSame( 'unknown', agend_apps_atlas_ask_state(), 'an outage says nothing about the plan' );
	}

	#[Test]
	public function should_believe_a_not_entitled_verdict_for_six_hours_and_an_available_one_for_one(): void {
		$set = static function ( string $state, int $age ): void {
			update_option(
				AGEND_APPS_ATLAS_ASK_STATE_OPTION,
				array(
					'state'      => $state,
					'checked_at' => time() - $age,
				)
			);
		};

		$set( 'not_entitled', 6 * 3600 - 60 );
		self::assertSame( 'not_entitled', agend_apps_atlas_ask_state() );
		$set( 'not_entitled', 6 * 3600 + 60 );
		self::assertSame( 'unknown', agend_apps_atlas_ask_state(), 'forgotten, so a plan change is noticed' );

		$set( 'available', 3600 - 60 );
		self::assertSame( 'available', agend_apps_atlas_ask_state() );
		$set( 'available', 3600 + 60 );
		self::assertSame( 'unknown', agend_apps_atlas_ask_state() );

		$set( 'available', -600 );
		self::assertSame( 'unknown', agend_apps_atlas_ask_state(), 'a stamp from the future is not believed' );
	}

	#[Test]
	public function should_ignore_a_malformed_stored_state(): void {
		update_option( AGEND_APPS_ATLAS_ASK_STATE_OPTION, 'not_entitled' );
		self::assertSame( 'unknown', agend_apps_atlas_ask_state() );

		update_option( AGEND_APPS_ATLAS_ASK_STATE_OPTION, array( 'state' => 'not_entitled' ) );
		self::assertSame( 'unknown', agend_apps_atlas_ask_state() );
	}

	#[Test]
	public function should_check_ask_as_the_site_with_no_member_token_and_learn_from_it(): void {
		$this->asMember();
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'kind' => 'not_entitled', 'tier' => 'free', 'reason' => 'no_subscription' ) ) );

		$state = agend_apps_atlas_check_ask();

		self::assertSame( 'not_entitled', $state );
		self::assertStringEndsWith( '/atlas/ask', Agend_Test_WP::$requests[0]['url'] );
		self::assertNull( $this->authorisationOf( 0 ), 'the check is the site asking, not a member' );
	}

	#[Test]
	public function should_stay_unknown_when_the_check_itself_fails(): void {
		Agend_Test_WP::queue_transport_error( 'http_request_failed', 'timeout' );

		self::assertSame( 'unknown', agend_apps_atlas_check_ask() );
	}

	// -----------------------------------------------------------------
	// Allowance
	// -----------------------------------------------------------------

	#[Test]
	public function should_read_the_allowance_anonymously_and_briefly_hold_it(): void {
		$this->asMember();
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'remaining_tokens' => 40000, 'exhausted' => false ) ) );

		$first = agend_apps_atlas_get_ask_budget();
		agend_apps_atlas_get_ask_budget();

		self::assertSame( 40000, $first['data']['remaining_tokens'] );
		self::assertCount( 1, Agend_Test_WP::$requests );
		self::assertNull( $this->authorisationOf( 0 ) );
		self::assertStringContainsString( '/atlas/ask/budget', Agend_Test_WP::$requests[0]['url'] );
	}

	// -----------------------------------------------------------------
	// The key guard
	// -----------------------------------------------------------------

	#[Test]
	public function should_know_the_scope_every_atlas_call_needs(): void {
		foreach (
			array(
				array( 'GET', '/atlas/collections', 'atlas.collections.browse' ),
				array( 'GET', '/atlas/me/collections', 'atlas.collections.browse' ),
				array( 'GET', '/atlas/search', 'atlas.pages.search' ),
				array( 'POST', '/atlas/me/ask', 'atlas.questions.create' ),
				array( 'POST', '/atlas/ask', 'atlas.questions.create' ),
				array( 'GET', '/atlas/ask/budget', 'atlas.questions.browse' ),
			) as list( $method, $path, $scope )
		) {
			$entry = Agend_Apps_Endpoint_Scopes::lookup( $method, $path );

			self::assertNotNull( $entry, $method . ' ' . $path );
			self::assertSame( array( array( $scope ) ), $entry['scopes'], $method . ' ' . $path );
		}
	}

	#[Test]
	public function should_list_the_atlas_reads_in_the_real_cache_map_with_a_label_and_a_default(): void {
		agend_test_load_real_cache();

		$keys = \Agend_Test_Real_Cache::get_all_keys();

		foreach ( array( 'atlas_collections', 'atlas_search', 'atlas_ask_budget' ) as $key ) {
			self::assertArrayHasKey( $key, $keys, $key );
			self::assertNotSame( '', $keys[ $key ]['label'], $key );
			self::assertGreaterThan( 0, $keys[ $key ]['default_ttl'], $key );
		}
	}
}
