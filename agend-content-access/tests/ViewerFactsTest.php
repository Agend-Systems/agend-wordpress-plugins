<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Condition_Runtime as Runtime;
use Agend_Content_Access_Conditions as Conditions;
use Agend_Apps_Cache;
use Agend_Content_Access_Decision as Decision;
use Agend_Content_Access_Policy as Policy;
use Agend_Content_Access_Viewer_Facts as ViewerFacts;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';

/**
 * The single source of member facts behind the document policy path and the
 * display condition path.
 *
 * What these tests protect, in order of how badly it goes wrong: a failure that
 * gets cached (one blip becomes a TTL-long outage), a cache entry that is shared
 * between visitors (one member's standing served to another), and a second
 * gateway call per request (the duplication this class exists to remove).
 */
#[CoversClass( ViewerFacts::class )]
#[CoversClass( Decision::class )]
#[CoversClass( Runtime::class )]
final class ViewerFactsTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const POST   = 501;

	/** The bearer a signed-in member presents. Cache entries are bound to it. */
	private const BEARER = 'member-token';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_id'] = 0;
	}

	/**
	 * Signs a user in. A member session carries a bearer, and facts are cached
	 * only under one, so the bearer is set too unless a test is about its absence.
	 */
	private function signIn( int $userId, bool $withBearer = true ): void {
		$GLOBALS['agend_test_current_user_id'] = $userId;

		if ( $withBearer ) {
			$this->bearer();
		}
	}

	private function bearer( string $token = self::BEARER ): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', $token );
	}

	/** The transient name the membership facts of a user are stored under. */
	private function memberKey( int $userId, string $bearer = self::BEARER ): string {
		return ViewerFacts::member_cache_key( $userId, $bearer );
	}

	/** The transient name the segment facts of a user are stored under. */
	private function segmentKey( int $userId, string $bearer = self::BEARER ): string {
		return ViewerFacts::segment_cache_key( $userId, $bearer );
	}

	/** Makes the gateway answer with a `data` envelope. */
	private function gatewayReturns( array $data ): void {
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', array( 'data' => $data ) );
	}

	private function gatewayFails(): void {
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			new WP_Error( 'down', 'Gateway unreachable' )
		);
	}

	/** How many times the entitlements endpoint was called. */
	private function gatewayCalls(): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => '/crm/me/entitlements' === $request['url']
			)
		);
	}

	/** Makes the segments endpoint answer with a `data` envelope of rows. */
	private function segmentsReturn( array $rows ): void {
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_segments_response', array( 'data' => $rows ) );
	}

	private function segmentsFail(): void {
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_segments_response',
			new WP_Error( 'down', 'Gateway unreachable' )
		);
	}

	/** How many times the segments endpoint was called. */
	private function segmentCalls(): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => '/crm/me/segments' === $request['url']
			)
		);
	}

	/** Simulates the next request: the memos go, the transients and options stay. */
	private function nextRequest(): void {
		Decision::reset_cache();
		ViewerFacts::reset();
	}

	private function memberData(): array {
		return array(
			'is_member'  => true,
			'tier_ids'   => array( self::TIER_A ),
			'tier_slugs' => array( 'gold' ),
		);
	}

	// -----------------------------------------------------------------
	// Caching across requests
	// -----------------------------------------------------------------

	#[Test]
	public function a_success_is_cached_and_reused_by_the_next_request(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );

		$first = ViewerFacts::member();

		$this->assertSame( 1, $this->gatewayCalls() );
		$this->assertArrayHasKey( $this->memberKey( 7 ), Agend_Test_WP::$transients );

		$this->nextRequest();

		// Were the gateway asked again it would now say something else.
		$this->gatewayReturns( array( 'is_member' => false, 'tier_ids' => array() ) );

		$this->assertSame( $first, ViewerFacts::member() );
		$this->assertSame( 1, $this->gatewayCalls(), 'the second request must be served from the transient' );
	}

	#[Test]
	public function a_failure_is_not_cached_and_the_next_request_asks_again(): void {
		$this->signIn( 7 );
		$this->gatewayFails();

		$this->assertNull( ViewerFacts::member() );
		$this->assertSame( array(), Agend_Test_WP::$transients, 'a failure must write nothing' );

		$this->nextRequest();
		$this->gatewayReturns( $this->memberData() );

		$this->assertTrue( ViewerFacts::member()['is_member'] );
		$this->assertSame( 2, $this->gatewayCalls() );
	}

	#[Test]
	public function a_non_array_response_is_a_failure_and_is_not_cached(): void {
		$this->signIn( 7 );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', 'garbage' );

		$this->assertNull( ViewerFacts::member() );
		$this->assertSame( array(), Agend_Test_WP::$transients );
	}

	/** A failure is remembered for the request, so one outage is one call. */
	#[Test]
	public function a_failure_is_memoised_for_the_request(): void {
		$this->signIn( 7 );
		$this->bearer();
		$this->gatewayFails();

		$this->assertNull( ViewerFacts::member() );
		$this->assertNull( Runtime::member_facts() );
		$this->assertTrue( Decision::viewer()['degraded'] );

		$this->assertSame( 1, $this->gatewayCalls() );
	}

	#[Test]
	public function cached_facts_belong_to_one_member_only(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );
		ViewerFacts::member();

		$this->nextRequest();
		$this->signIn( 8 );
		$this->gatewayReturns( array( 'is_member' => false, 'tier_ids' => array() ) );

		$this->assertFalse( ViewerFacts::member()['is_member'], 'member 8 must not inherit member 7\'s entry' );
		$this->assertSame( 2, $this->gatewayCalls() );
	}

	#[Test]
	public function the_memo_does_not_carry_one_user_into_another_within_a_request(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );
		$this->assertTrue( ViewerFacts::member()['is_member'] );

		// A login completing mid-request, or an impersonation that restores.
		$this->signIn( 8 );
		$this->gatewayReturns( array( 'is_member' => false, 'tier_ids' => array() ) );

		$this->assertFalse( ViewerFacts::member()['is_member'] );
	}

	// -----------------------------------------------------------------
	// User id 0
	// -----------------------------------------------------------------

	/**
	 * A bearer can arrive through the `agend_apps_bearer_token` filter without a
	 * WordPress login. A user-0 key would then be one entry shared by every such
	 * visitor, so nothing is written for them.
	 */
	#[Test]
	public function user_zero_is_never_written_to_the_transient_store(): void {
		$this->bearer();
		$this->gatewayReturns( $this->memberData() );

		$viewer = Decision::viewer();

		$this->assertTrue( $viewer['identified'] );
		$this->assertSame( array( self::TIER_A ), $viewer['tier_ids'] );
		$this->assertSame( array(), Agend_Test_WP::$transients );
		$this->assertSame( array(), Agend_Test_WP::$transient_ttls );

		$this->assertTrue( ViewerFacts::member()['is_member'] );
		$this->assertSame( 1, $this->gatewayCalls(), 'still memoised for the request' );
	}

	#[Test]
	public function user_zero_is_asked_again_on_the_next_request(): void {
		$this->bearer();
		$this->gatewayReturns( $this->memberData() );
		Decision::viewer();

		$this->nextRequest();
		$this->gatewayReturns( array( 'is_member' => false, 'tier_ids' => array() ) );

		$this->assertSame( array(), Decision::viewer()['tier_ids'], 'a different visitor must not inherit the first' );
		$this->assertSame( 2, $this->gatewayCalls() );
		$this->assertSame( array(), Agend_Test_WP::$transients );
	}

	// -----------------------------------------------------------------
	// Bound to the bearer
	// -----------------------------------------------------------------

	/**
	 * Review finding F2, ported. Facts were cached by WordPress user id alone, so a
	 * user presenting a different bearer (a re-link, or a provider not tied to the
	 * WordPress user) was served the facts fetched with the first one until the
	 * TTL ended. The current wrong behaviour was GRANTED here.
	 */
	#[Test]
	public function cached_facts_do_not_follow_the_user_to_a_different_bearer(): void {
		$GLOBALS['agend_test_current_user_id']               = 5;
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Policy::META_KEY ] = array(
			'mode'     => 'selected_tiers',
			'tier_ids' => array( self::TIER_A ),
		);

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'bearer-for-member-A' );
		$this->gatewayReturns( array( 'is_member' => true, 'tier_ids' => array( self::TIER_A ), 'capabilities' => array() ) );
		$this->assertSame( Decision::STATE_GRANTED, Decision::for_post( self::POST )['state'] );

		// Next request: same WordPress user, a different member's bearer. The
		// gateway says "holds nothing" for it.
		$this->nextRequest();
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'bearer-for-member-B' );
		$this->gatewayReturns( array( 'is_member' => false, 'tier_ids' => array(), 'capabilities' => array() ) );

		$this->assertSame( Decision::STATE_MEMBERSHIP, Decision::for_post( self::POST )['state'] );
		$this->assertSame( 2, $this->gatewayCalls(), 'the second bearer must ask the gateway' );
	}

	#[Test]
	public function a_bearer_change_between_requests_misses_and_each_bearer_keeps_its_own_entry(): void {
		$this->signIn( 7, false );

		$this->bearer( 'bearer-a' );
		$this->gatewayReturns( $this->memberData() );
		$this->assertTrue( ViewerFacts::member()['is_member'] );

		$this->nextRequest();
		$this->bearer( 'bearer-b' );
		$this->gatewayReturns( array( 'is_member' => false, 'tier_ids' => array() ) );
		$this->assertFalse( ViewerFacts::member()['is_member'] );
		$this->assertSame( 2, $this->gatewayCalls() );

		// Back to the first bearer: its own entry is still there.
		$this->nextRequest();
		$this->bearer( 'bearer-a' );
		$this->gatewayReturns( array( 'is_member' => false, 'tier_ids' => array() ) );
		$this->assertTrue( ViewerFacts::member()['is_member'] );
		$this->assertSame( 2, $this->gatewayCalls() );
	}

	#[Test]
	public function a_segment_entry_is_bound_to_the_bearer_too(): void {
		$this->signIn( 7, false );

		$this->bearer( 'bearer-a' );
		$this->segmentsReturn( array( array( 'id' => 'seg-1', 'slug' => 'vic' ) ) );
		$this->assertSame( array( 'seg-1' ), ViewerFacts::segments()['segment_ids'] );

		$this->nextRequest();
		$this->bearer( 'bearer-b' );
		$this->segmentsReturn( array() );

		$this->assertSame( array(), ViewerFacts::segments()['segment_ids'] );
		$this->assertSame( 2, $this->segmentCalls() );
	}

	/**
	 * A logged-in user with no bearer has no identity to key an entry on. Reading
	 * one written under an earlier bearer would serve stale facts, so no transient
	 * is read or written. The lookup itself and its failure semantics are as before.
	 */
	#[Test]
	public function a_user_without_a_bearer_never_reads_or_writes_the_transient_store(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );
		ViewerFacts::member();
		ViewerFacts::segments();
		$this->assertCount( 2, Agend_Test_WP::$transients, 'written while the member had a bearer' );
		$written = Agend_Test_WP::$transients;

		// The session is gone: still logged in to WordPress, no bearer.
		$this->nextRequest();
		Agend_Test_WP::$filters = array();
		$this->gatewayReturns( array( 'is_member' => false, 'tier_ids' => array() ) );
		$this->segmentsReturn( array() );

		$this->assertFalse( ViewerFacts::member()['is_member'], 'must not read the entry the bearer wrote' );
		$this->assertSame( array(), ViewerFacts::segments()['segment_ids'] );
		$this->assertSame( $written, Agend_Test_WP::$transients, 'and must write nothing' );
		$this->assertSame( 2, $this->gatewayCalls() );
		$this->assertSame( 2, $this->segmentCalls() );
	}

	#[Test]
	public function a_logged_in_user_without_a_bearer_still_gets_unknown_on_failure_and_is_memoised(): void {
		$this->signIn( 7, false );
		$this->gatewayFails();

		$this->assertNull( ViewerFacts::member() );
		$this->assertNull( ViewerFacts::member() );
		$this->assertSame( 1, $this->gatewayCalls() );
		$this->assertSame( array(), Agend_Test_WP::$transients );
	}

	/** The filter chain behind the bearer can read user meta and refresh a token. */
	#[Test]
	public function the_bearer_is_asked_once_per_user_per_request(): void {
		$asked = 0;

		$this->signIn( 7, false );
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function () use ( &$asked ) {
			++$asked;

			return 'member-token';
		};
		$this->gatewayReturns( $this->memberData() );
		$this->segmentsReturn( array() );

		ViewerFacts::member();
		ViewerFacts::segments();
		Decision::viewer();
		Runtime::member_facts();

		$this->assertSame( 1, $asked );

		// A different user in the same request is a different question.
		$this->signIn( 8, false );
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function () use ( &$asked ) {
			++$asked;

			return 'other-token';
		};
		ViewerFacts::member();

		$this->assertSame( 2, $asked );
	}

	#[Test]
	public function the_user_generation_is_read_once_per_user_per_request(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );
		$GLOBALS['agend_test_user_meta'][7][ ViewerFacts::USER_GENERATION_META ] = 3;

		ViewerFacts::member();
		$key = $this->memberKey( 7 );

		// Moved behind this request's back, by another process.
		$GLOBALS['agend_test_user_meta'][7][ ViewerFacts::USER_GENERATION_META ] = 4;

		$this->assertSame( $key, $this->memberKey( 7 ), 'memoised for the request' );

		$this->nextRequest();
		$this->assertNotSame( $key, $this->memberKey( 7 ), 'the next request reads the new number' );
	}

	/**
	 * The mid-request guarantee, now with two generations. The key is fixed before
	 * the gateway is asked, so a bump or a flush that lands while the call is in
	 * flight leaves the (possibly pre-change) answer under a key nobody will ever
	 * read, rather than under the new one.
	 */
	#[Test]
	public function the_key_is_built_before_the_gateway_call_and_survives_a_mid_request_bump(): void {
		$this->signIn( 7 );
		$before = $this->memberKey( 7 );

		Agend_Test_WP::$filters['agend_apps_crm_get_my_entitlements_response'] = function () {
			ViewerFacts::bump_generation();

			return array( 'data' => $this->memberData() );
		};

		ViewerFacts::member();

		$this->assertSame( array( $before ), array_keys( Agend_Test_WP::$transients ), 'stored under the key from before the call' );
		$this->assertArrayNotHasKey( $this->memberKey( 7 ), Agend_Test_WP::$transients, 'and not under the new generation' );
	}

	#[Test]
	public function the_key_is_built_before_the_gateway_call_and_survives_a_mid_request_flush(): void {
		$this->signIn( 7 );
		$before = $this->memberKey( 7 );

		Agend_Test_WP::$filters['agend_apps_crm_get_my_entitlements_response'] = function () {
			ViewerFacts::flush( 7 );

			return array( 'data' => $this->memberData() );
		};

		ViewerFacts::member();

		$this->assertSame( array( $before ), array_keys( Agend_Test_WP::$transients ) );
		$this->assertArrayNotHasKey( $this->memberKey( 7 ), Agend_Test_WP::$transients );
	}

	#[Test]
	public function the_segment_key_is_built_before_the_gateway_call_too(): void {
		$this->signIn( 7 );
		$before = $this->segmentKey( 7 );

		Agend_Test_WP::$filters['agend_apps_crm_get_my_segments_response'] = static function () {
			ViewerFacts::bump_generation();

			return array( 'data' => array( array( 'id' => 'seg-1', 'slug' => 'vic' ) ) );
		};

		ViewerFacts::segments();

		$this->assertSame( array( $before ), array_keys( Agend_Test_WP::$transients ) );
	}

	// -----------------------------------------------------------------
	// The decision layer's own memo
	// -----------------------------------------------------------------

	/**
	 * The derived viewer was memoised under no key, so a request that switched
	 * user was answered with the first user's standing.
	 */
	#[Test]
	public function the_decision_memo_does_not_carry_one_user_into_another_within_a_request(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );
		$this->assertSame( array( self::TIER_A ), Decision::viewer()['tier_ids'] );

		$this->signIn( 8 );
		$this->gatewayReturns( array( 'is_member' => false, 'tier_ids' => array() ) );
		$second = Decision::viewer();

		$this->assertTrue( $second['identified'] );
		$this->assertSame( array(), $second['tier_ids'], 'user 8 must not inherit user 7\'s tiers' );
	}

	/** Same user, a different bearer: a different identity as far as the gateway knows. */
	#[Test]
	public function the_decision_memo_is_bound_to_the_bearer_too(): void {
		$this->signIn( 7, false );
		$this->bearer( 'bearer-a' );
		$this->gatewayReturns( $this->memberData() );
		$this->assertSame( array( self::TIER_A ), Decision::viewer()['tier_ids'] );

		// The bearer behind the same user changes mid-request (a token re-linked
		// or replaced). Only the facts layer is reset, so a hit here could only
		// come from the decision memo.
		ViewerFacts::reset();
		$this->bearer( 'bearer-b' );
		$this->gatewayReturns( array( 'is_member' => false, 'tier_ids' => array() ) );

		$this->assertSame( array(), Decision::viewer()['tier_ids'] );
	}

	#[Test]
	public function the_decision_memo_still_answers_the_same_visitor_from_memory(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );

		$first = Decision::viewer();
		$this->gatewayReturns( array( 'is_member' => false, 'tier_ids' => array() ) );

		$this->assertSame( $first, Decision::viewer() );
		$this->assertSame( 1, $this->gatewayCalls() );
	}

	#[Test]
	public function a_visitor_without_a_bearer_has_their_own_memo_entry(): void {
		$this->signIn( 7, false );
		$this->assertFalse( Decision::viewer()['identified'] );

		$this->bearer();
		ViewerFacts::reset();
		$this->gatewayReturns( $this->memberData() );

		$this->assertTrue( Decision::viewer()['identified'], 'gaining a bearer must not be answered from the bearer-less entry' );
	}

	// -----------------------------------------------------------------
	// Key shape: Core's cache prefix
	// -----------------------------------------------------------------

	/**
	 * Core's Cache tab "Clear" button deletes transients by the prefix
	 * `agend_apps_crm_me_entitlements`. Building the key through Core's builder is
	 * what puts these entries under it.
	 */
	#[Test]
	public function the_key_is_built_by_core_when_core_can_build_it(): void {
		$this->assertTrue( method_exists( 'Agend_Apps_Cache', 'build_key' ) );

		$expected = 'agend_apps_' . Agend_Apps_Cache::build_key(
			'crm_me_entitlements',
			array(
				'fact'     => 'member',
				'user'     => 7,
				'bearer'   => ViewerFacts::bearer_digest( self::BEARER ),
				'gen'      => 0,
				'user_gen' => 0,
			)
		);

		$this->assertSame( $expected, $this->memberKey( 7 ) );
		$this->assertStringStartsWith( 'agend_apps_crm_me_entitlements', $this->memberKey( 7 ) );
		$this->assertStringStartsWith( 'agend_apps_crm_me_entitlements', $this->segmentKey( 7 ) );

		// Not the local shape: it differs from the test double's, so equality above
		// can only come from asking Core.
		$this->assertNotSame( $this->fallbackKey( 'member', 7, self::BEARER ), $this->memberKey( 7 ) );
	}

	/** An older Core without the builder gets an equivalent key under the same prefix. */
	#[Test]
	public function the_fallback_key_has_core_s_prefix_and_shape(): void {
		$key = $this->fallbackKey( 'member', 7, self::BEARER );

		$this->assertMatchesRegularExpression( '/^agend_apps_crm_me_entitlements_[0-9a-f]{32}$/', $key );
		$this->assertNotSame( $key, $this->fallbackKey( 'segments', 7, self::BEARER ) );
		$this->assertNotSame( $key, $this->fallbackKey( 'member', 8, self::BEARER ) );
		$this->assertNotSame( $key, $this->fallbackKey( 'member', 7, 'another-bearer' ) );
		$this->assertLessThanOrEqual( 172 - strlen( '_transient_timeout_' ) + strlen( '_transient_' ), strlen( $key ) );
	}

	/** The fallback builder is private: it is only reached when Core has no builder. */
	private function fallbackKey( string $fact, int $userId, string $bearer ): string {
		$method = new \ReflectionMethod( ViewerFacts::class, 'fallback_key' );

		return $method->invoke(
			null,
			array(
				'fact'     => $fact,
				'user'     => $userId,
				'bearer'   => ViewerFacts::bearer_digest( $bearer ),
				'gen'      => 0,
				'user_gen' => 0,
			)
		);
	}

	// -----------------------------------------------------------------
	// Generation and flush
	// -----------------------------------------------------------------

	#[Test]
	public function the_generation_starts_at_zero(): void {
		$this->assertSame( 0, ViewerFacts::generation() );
	}

	/**
	 * Every input that decides who may read an entry must change the key: the
	 * fact, the user, the bearer, and both generations. Each is varied alone, so
	 * a missing input shows up as a collision.
	 */
	#[Test]
	public function every_input_that_decides_who_may_read_an_entry_is_in_the_key(): void {
		$this->signIn( 7 );

		$base  = $this->memberKey( 7 );
		$eight = $this->memberKey( 8 );

		$this->assertNotSame( $base, $this->segmentKey( 7 ), 'membership and segments are separate entries' );
		$this->assertNotSame( $base, $this->memberKey( 8 ), 'another user' );
		$this->assertNotSame( $base, $this->memberKey( 7, 'another-bearer' ), 'another bearer' );

		ViewerFacts::flush( 7 );
		$after_flush = $this->memberKey( 7 );
		$this->assertNotSame( $base, $after_flush, 'the user generation' );
		$this->assertSame( $eight, $this->memberKey( 8 ), 'a flush of 7 leaves 8\'s key alone' );

		ViewerFacts::bump_generation();
		$this->assertNotSame( $after_flush, $this->memberKey( 7 ), 'the global generation' );
	}

	/** The same inputs always give the same key, or nothing would ever be a hit. */
	#[Test]
	public function the_key_is_stable_for_the_same_inputs(): void {
		$this->assertSame( $this->memberKey( 7 ), $this->memberKey( 7 ) );
		$this->assertSame( $this->segmentKey( 7 ), $this->segmentKey( 7 ) );
	}

	#[Test]
	public function the_bearer_never_appears_in_the_key(): void {
		$this->assertStringNotContainsString( self::BEARER, $this->memberKey( 7 ) );
		$this->assertSame( 16, strlen( ViewerFacts::bearer_digest( self::BEARER ) ) );
		$this->assertSame( substr( hash( 'sha256', self::BEARER ), 0, 16 ), ViewerFacts::bearer_digest( self::BEARER ) );
		$this->assertSame( '', ViewerFacts::bearer_digest( '' ) );
	}

	#[Test]
	public function bumping_the_generation_invalidates_every_member_at_once(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );
		ViewerFacts::member();

		$this->nextRequest();
		$this->signIn( 8 );
		ViewerFacts::member();

		$this->assertSame( 2, $this->gatewayCalls() );

		$this->assertSame( 1, ViewerFacts::bump_generation() );
		$this->assertSame( 1, ViewerFacts::generation() );
		$this->assertSame( 1, (int) Agend_Test_WP::$options[ ViewerFacts::GENERATION_OPTION ] );

		$this->nextRequest();

		$this->signIn( 7 );
		ViewerFacts::member();
		$this->signIn( 8 );
		ViewerFacts::member();

		$this->assertSame( 4, $this->gatewayCalls(), 'neither member may be served from the old generation' );
		$this->assertArrayHasKey( $this->memberKey( 7 ), Agend_Test_WP::$transients );
	}

	#[Test]
	public function bumping_the_generation_drops_the_request_memo_too(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );
		ViewerFacts::member();

		ViewerFacts::bump_generation();
		ViewerFacts::member();

		$this->assertSame( 2, $this->gatewayCalls() );
	}

	#[Test]
	public function each_bump_is_a_new_generation(): void {
		$this->assertSame( 1, ViewerFacts::bump_generation() );
		$this->assertSame( 2, ViewerFacts::bump_generation() );
	}

	#[Test]
	public function a_junk_generation_option_reads_as_zero(): void {
		Agend_Test_WP::$options[ ViewerFacts::GENERATION_OPTION ] = 'banana';
		$this->assertSame( 0, ViewerFacts::generation() );

		Agend_Test_WP::$options[ ViewerFacts::GENERATION_OPTION ] = -4;
		$this->assertSame( 0, ViewerFacts::generation() );
	}

	#[Test]
	public function flushing_one_member_makes_their_entries_unreachable_and_drops_the_request_memo(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );
		ViewerFacts::member();
		$this->segmentsReturn( array( array( 'id' => 'seg-1', 'slug' => 'vic' ) ) );
		ViewerFacts::segments();

		Agend_Test_WP::$transients[ $this->memberKey( 8 ) ] = array( 'is_member' => true );

		Runtime::flush_member_facts( 7 );

		// The member's own generation moved, which is what every key carries.
		$this->assertSame( 1, (int) $GLOBALS['agend_test_user_meta'][7][ ViewerFacts::USER_GENERATION_META ] );
		$this->assertArrayNotHasKey( $this->memberKey( 7 ), Agend_Test_WP::$transients );
		$this->assertArrayNotHasKey( $this->segmentKey( 7 ), Agend_Test_WP::$transients );
		$this->assertArrayHasKey( $this->memberKey( 8 ), Agend_Test_WP::$transients, 'another member is untouched' );

		// Same request: the memos are gone as well, so both read fresh.
		ViewerFacts::member();
		ViewerFacts::segments();
		$this->assertSame( 2, $this->gatewayCalls() );
		$this->assertSame( 2, $this->segmentCalls() );
	}

	/**
	 * A webhook has no bearer, and the bearer is in every key, so a flush cannot
	 * name the entries to delete. It must still reach every one of them.
	 */
	#[Test]
	public function flushing_a_member_invalidates_their_entry_under_every_bearer(): void {
		$this->signIn( 7, false );

		foreach ( array( 'bearer-a', 'bearer-b' ) as $bearer ) {
			$this->nextRequest();
			$this->bearer( $bearer );
			$this->gatewayReturns( $this->memberData() );
			ViewerFacts::member();
			ViewerFacts::segments();
		}

		$this->assertSame( 2, $this->gatewayCalls() );
		$this->assertCount( 4, Agend_Test_WP::$transients, 'two bearers, two facts each' );

		// No bearer is in scope for the caller: that is what a webhook is.
		$GLOBALS['agend_test_current_user_id'] = 0;
		Agend_Test_WP::$filters               = array();
		ViewerFacts::flush( 7 );

		$GLOBALS['agend_test_current_user_id'] = 7;

		foreach ( array( 'bearer-a', 'bearer-b' ) as $bearer ) {
			$this->nextRequest();
			$this->bearer( $bearer );
			$this->gatewayReturns( array( 'is_member' => false, 'tier_ids' => array() ) );
			$this->segmentsReturn( array() );

			$this->assertFalse( ViewerFacts::member()['is_member'], $bearer . ' must read fresh after the flush' );
			$this->assertSame( array(), ViewerFacts::segments()['segment_ids'], $bearer . ' segments must read fresh' );
		}

		$this->assertSame( 4, $this->gatewayCalls() );
	}

	#[Test]
	public function a_flush_of_user_zero_writes_nothing_and_drops_the_memos(): void {
		$this->bearer();
		$this->gatewayReturns( $this->memberData() );
		ViewerFacts::member();

		ViewerFacts::flush( 0 );

		$this->assertSame( array(), $GLOBALS['agend_test_user_meta'] );
		ViewerFacts::member();
		$this->assertSame( 2, $this->gatewayCalls() );
	}

	#[Test]
	public function each_flush_is_a_new_user_generation_and_junk_reads_as_zero(): void {
		$this->signIn( 7 );

		ViewerFacts::flush( 7 );
		ViewerFacts::flush( 7 );
		$this->assertSame( 2, (int) $GLOBALS['agend_test_user_meta'][7][ ViewerFacts::USER_GENERATION_META ] );

		$GLOBALS['agend_test_user_meta'][7][ ViewerFacts::USER_GENERATION_META ] = 'banana';
		ViewerFacts::flush( 7 );
		$this->assertSame( 1, (int) $GLOBALS['agend_test_user_meta'][7][ ViewerFacts::USER_GENERATION_META ], 'junk reads as 0, so the next is 1' );
	}

	/** The global bump still reaches everyone, with no per-user write. */
	#[Test]
	public function the_global_bump_invalidates_every_bearer_of_every_user_without_touching_user_meta(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );
		ViewerFacts::member();

		ViewerFacts::bump_generation();

		$this->assertSame( array(), $GLOBALS['agend_test_user_meta'] );
		$this->assertArrayNotHasKey( $this->memberKey( 7 ), Agend_Test_WP::$transients );
	}

	#[Test]
	public function a_segment_entry_written_under_an_old_generation_is_not_read(): void {
		$this->signIn( 7 );
		Agend_Test_WP::$transients[ $this->segmentKey( 7 ) ] = array(
			'segment_ids'   => array( 'old-id' ),
			'segment_slugs' => array( 'old-segment' ),
		);

		$this->assertSame( array( 'old-segment' ), Runtime::segment_facts() );

		ViewerFacts::bump_generation();
		Agend_Test_WP::$filters['agend_apps_crm_get_my_segments_response'] =
			static fn( $value ) => array( 'data' => array( array( 'id' => 'a', 'slug' => 'fresh', 'name' => 'Fresh' ) ) );

		$this->assertSame( array( 'fresh' ), Runtime::segment_facts() );
	}

	// -----------------------------------------------------------------
	// One source for both paths
	// -----------------------------------------------------------------

	#[Test]
	public function one_gateway_call_serves_the_document_path_and_the_conditions_path(): void {
		$this->signIn( 7 );
		$this->bearer();
		$this->gatewayReturns( $this->memberData() );

		$viewer = Decision::viewer();
		$facts  = Runtime::member_facts();
		$check  = Runtime::checker();

		$this->assertTrue( $viewer['identified'] );
		$this->assertSame( array( self::TIER_A ), $viewer['tier_ids'] );
		$this->assertSame(
			array( 'is_member' => true, 'tier_ids' => array( self::TIER_A ), 'tier_slugs' => array( 'gold' ) ),
			$facts
		);
		$this->assertTrue( $check( 'agend_membership', 'gold' ) );
		$this->assertSame( 1, $this->gatewayCalls() );
	}

	#[Test]
	public function the_order_of_the_two_paths_does_not_matter(): void {
		$this->signIn( 7 );
		$this->bearer();
		$this->gatewayReturns( $this->memberData() );

		Runtime::member_facts();
		Decision::viewer();

		$this->assertSame( 1, $this->gatewayCalls() );
	}

	/**
	 * The two paths keep their own signed-out rules. The document path keys on a
	 * bearer being present; the condition path treats a visitor who is not
	 * logged in as definitively holding nothing.
	 */
	#[Test]
	public function the_document_path_keys_on_the_bearer(): void {
		$this->signIn( 7, false );
		$this->gatewayReturns( $this->memberData() );

		$viewer = Decision::viewer();

		$this->assertFalse( $viewer['identified'], 'logged in with no bearer is anonymous to the document path' );
		$this->assertSame( 0, $this->gatewayCalls() );
	}

	#[Test]
	public function the_conditions_path_keys_on_the_login(): void {
		$this->bearer();
		$this->gatewayReturns( $this->memberData() );

		$this->assertSame(
			array( 'is_member' => false, 'tier_ids' => array(), 'tier_slugs' => array() ),
			Runtime::member_facts(),
			'a bearer with no login holds nothing to the conditions path'
		);
		$this->assertSame( 0, $this->gatewayCalls() );
	}

	#[Test]
	public function a_failure_reads_as_each_paths_own_unknown(): void {
		$this->signIn( 7 );
		$this->bearer();
		$this->gatewayFails();

		$viewer = Decision::viewer();

		$this->assertFalse( $viewer['identified'] );
		$this->assertTrue( $viewer['degraded'] );
		$this->assertSame( array(), $viewer['tier_ids'] );
		$this->assertNull( Runtime::member_facts() );
	}

	// -----------------------------------------------------------------
	// Normalisation
	// -----------------------------------------------------------------

	#[Test]
	public function the_facts_carry_every_field_both_paths_and_the_next_layer_need(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );

		$this->assertSame(
			array(
				'is_member'              => true,
				'tier_ids'               => array( self::TIER_A ),
				'tier_slugs'             => array( 'gold' ),
				'entitlement_keys'       => array(),
				'entitlement_expires_at' => null,
			),
			ViewerFacts::member()
		);
	}

	/**
	 * Core hands back the decoded body as it is, so a body with no `data` envelope
	 * is not the documented answer. Reading it as though it were the data is how an
	 * empty body (Core's `array()` for a 204) would pass as "holds nothing".
	 */
	#[Test]
	public function a_response_without_the_data_envelope_is_unknown_and_not_cached(): void {
		$this->signIn( 7 );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', $this->memberData() );

		$this->assertNull( ViewerFacts::member() );
		$this->assertSame( array(), Agend_Test_WP::$transients );
	}

	#[Test]
	public function malformed_tier_fields_hold_nothing(): void {
		$this->signIn( 7 );
		$this->gatewayReturns(
			array(
				'is_member'  => false,
				'tier_ids'   => array( self::TIER_A, 123, null, '', array( 'x' ) ),
				'tier_slugs' => 'not-an-array',
			)
		);

		$facts = ViewerFacts::member();

		$this->assertSame( array( self::TIER_A ), $facts['tier_ids'] );
		$this->assertSame( array(), $facts['tier_slugs'] );
	}

	#[Test]
	public function entitlement_keys_are_read_from_the_capabilities(): void {
		$this->signIn( 7 );
		$this->gatewayReturns(
			array(
				'is_member'    => true,
				'tier_ids'     => array(),
				'capabilities' => array(
					array( 'gate_key' => 'events.early_access' ),
					array( 'gate_key' => 'library_archive' ),
					array( 'gate_key' => 'events.early_access' ),
				),
			)
		);

		$facts = ViewerFacts::member();

		$this->assertSame( array( 'events.early_access', 'library_archive' ), $facts['entitlement_keys'] );
		$this->assertSame( $facts, Agend_Test_WP::$transients[ $this->memberKey( 7 ) ], 'they are cached with the rest' );
	}

	#[Test]
	public function an_entitlement_key_that_is_not_a_gate_key_is_dropped(): void {
		$this->signIn( 7 );
		$this->gatewayReturns(
			array(
				'tier_ids'     => array(),
				'capabilities' => array(
					array( 'gate_key' => 'ok.key' ),
					array( 'gate_key' => 'ab' ),                       // too short.
					array( 'gate_key' => 'abc' ),                      // shortest valid.
					array( 'gate_key' => 'Upper.case' ),
					array( 'gate_key' => '1starts.with.digit' ),
					array( 'gate_key' => 'ends.with.dot.' ),
					array( 'gate_key' => 'has-hyphen' ),
					array( 'gate_key' => 'has space' ),
					array( 'gate_key' => "trailing.newline\n" ),
					array( 'gate_key' => 'a' . str_repeat( 'b', 62 ) . 'c' ),        // 64 chars, longest valid.
					array( 'gate_key' => 'a' . str_repeat( 'b', 63 ) . 'c' ),        // 65 chars.
					array( 'gate_key' => 123 ),
					array( 'gate_key' => null ),
					array( 'gate_key' => array( 'x' ) ),
					array( 'name' => 'no key at all' ),
					'not a row',
				),
			)
		);

		$this->assertSame(
			array( 'ok.key', 'abc', 'a' . str_repeat( 'b', 62 ) . 'c' ),
			ViewerFacts::member()['entitlement_keys']
		);
	}

	#[Test]
	public function a_capabilities_field_that_is_not_a_list_holds_nothing(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( array( 'tier_ids' => array(), 'capabilities' => 'events.early_access' ) );

		$this->assertSame( array(), ViewerFacts::member()['entitlement_keys'] );
	}

	#[Test]
	public function the_earliest_capability_expiry_is_kept_as_utc(): void {
		$this->signIn( 7 );
		$this->gatewayReturns(
			array(
				'tier_ids'     => array(),
				'capabilities' => array(
					array( 'gate_key' => 'first.key', 'expires_at' => '2099-01-01T00:00:00Z' ),
					// 2098-06-01 00:30 UTC, written in +10:00. The earliest of the three.
					array( 'gate_key' => 'second.key', 'expires_at' => '2098-06-01T10:30:00+10:00' ),
					array( 'gate_key' => 'third.key', 'expires_at' => '2098-09-01T00:00:00Z' ),
					array( 'gate_key' => 'no.expiry' ),
				),
			)
		);

		$this->assertSame( '2098-06-01T00:30:00Z', ViewerFacts::member()['entitlement_expires_at'] );
	}

	#[Test]
	public function an_expiry_that_is_not_an_iso_date_time_is_ignored(): void {
		$this->signIn( 7 );
		$this->gatewayReturns(
			array(
				'tier_ids'     => array(),
				'capabilities' => array(
					array( 'gate_key' => 'one.key', 'expires_at' => 'tomorrow' ),
					array( 'gate_key' => 'two.key', 'expires_at' => 1893456000 ),
					array( 'gate_key' => 'three.key', 'expires_at' => '' ),
					array( 'gate_key' => 'four.key', 'expires_at' => array( 'x' ) ),
				),
			)
		);

		$facts = ViewerFacts::member();

		$this->assertNull( $facts['entitlement_expires_at'] );
		$this->assertCount( 4, $facts['entitlement_keys'] );
	}

	// -----------------------------------------------------------------
	// A 2xx that is not the documented answer
	// -----------------------------------------------------------------

	/**
	 * Review finding F4, ported. A 2xx body that was not the documented envelope
	 * was normalised to "holds nothing" and cached, so an inverted condition
	 * passed for the whole TTL, and the next real answer lost to the cached one.
	 * It is now unknown, never cached, and a condition on it denies.
	 */
	#[Test]
	public function an_unexpected_envelope_is_unknown_not_cached_and_denies_an_inverted_rule(): void {
		$this->signIn( 9 );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array(
				'success'     => false,
				'error'       => array( 'code' => 'X' ),
				'status_code' => 200,
			)
		);

		$this->assertNull( ViewerFacts::member() );
		$this->assertSame( array(), Agend_Test_WP::$transients, 'nothing may be cached' );

		$result = Conditions::evaluate( array( 'agend_membership:is_member' ), Runtime::checker(), Conditions::MATCH_ANY, true );
		$this->assertFalse( $result['passes'], 'an inverted rule must deny on unknown, not pass' );
		$this->assertSame( array( 'agend_membership:is_member' ), $result['unknown'] );

		// The gateway recovers: the next request sees the real answer, not a
		// cached "holds nothing".
		$this->nextRequest();
		$this->gatewayReturns( array( 'is_member' => true, 'tier_ids' => array( self::TIER_A ) ) );
		$this->assertTrue( ViewerFacts::member()['is_member'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function unexpected_entitlement_bodies(): array {
		return array(
			'success false'              => array( array( 'success' => false, 'data' => array( 'tier_ids' => array(), 'is_member' => false ) ) ),
			'success not true'           => array( array( 'success' => 'yes', 'data' => array( 'tier_ids' => array() ) ) ),
			'empty array (a 204)'        => array( array() ),
			'status code only'           => array( array( 'status_code' => 204 ) ),
			'data null'                  => array( array( 'success' => true, 'data' => null ) ),
			'data a string'              => array( array( 'data' => 'ok' ) ),
			'data empty'                 => array( array( 'data' => array() ) ),
			'data without tier_ids'      => array( array( 'data' => array( 'is_member' => false ) ) ),
			'tier_ids not an array'      => array( array( 'data' => array( 'tier_ids' => 'none', 'is_member' => false ) ) ),
			'tier_ids null'              => array( array( 'data' => array( 'tier_ids' => null ) ) ),
			'a bare string'              => array( 'garbage' ),
			'null'                       => array( null ),
			'false'                      => array( false ),
		);
	}

	#[Test]
	#[DataProvider( 'unexpected_entitlement_bodies' )]
	public function an_unexpected_entitlements_body_is_a_failure( $body ): void {
		$this->signIn( 7 );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', $body );

		$this->assertNull( ViewerFacts::member() );
		$this->assertSame( array(), Agend_Test_WP::$transients );
		$this->assertTrue( Decision::viewer()['degraded'] );
		$this->assertNull( Runtime::member_facts() );
	}

	#[Test]
	public function a_success_flag_of_true_and_an_empty_tier_list_is_a_genuine_non_member(): void {
		$this->signIn( 7 );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'success' => true, 'data' => array( 'is_member' => false, 'tier_ids' => array() ), 'status_code' => 200 )
		);

		$this->assertFalse( ViewerFacts::member()['is_member'] );
		$this->assertArrayHasKey( $this->memberKey( 7 ), Agend_Test_WP::$transients, 'a real answer, including "holds nothing", is cached' );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function unexpected_segment_bodies(): array {
		return array(
			'success false'     => array( array( 'success' => false, 'data' => array() ) ),
			'empty array (204)' => array( array() ),
			'data null'         => array( array( 'success' => true, 'data' => null, 'status_code' => 200 ) ),
			'data a string'     => array( array( 'data' => 'ok' ) ),
			'data an object'    => array( array( 'data' => array( 'segments' => array() ) ) ),
			'data not a list'   => array( array( 'data' => array( 1 => array( 'id' => 'a', 'slug' => 'b' ) ) ) ),
			'a bare list'       => array( array( array( 'id' => 'a', 'slug' => 'b' ) ) ),
			'a bare string'     => array( 'garbage' ),
		);
	}

	/** The reviewer's segments case: `data` null read as "in no segments", cached. */
	#[Test]
	#[DataProvider( 'unexpected_segment_bodies' )]
	public function an_unexpected_segments_body_is_a_failure( $body ): void {
		$this->signIn( 9 );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_segments_response', $body );

		$this->assertNull( ViewerFacts::segments() );
		$this->assertSame( array(), Agend_Test_WP::$transients );
		$this->assertNull( Runtime::segment_facts() );
	}

	#[Test]
	public function an_empty_segment_list_is_a_genuine_answer_and_is_cached(): void {
		$this->signIn( 7 );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_segments_response', array( 'success' => true, 'data' => array() ) );

		$this->assertSame( array( 'segment_ids' => array(), 'segment_slugs' => array() ), ViewerFacts::segments() );
		$this->assertArrayHasKey( $this->segmentKey( 7 ), Agend_Test_WP::$transients );
	}

	// -----------------------------------------------------------------
	// Lifetime
	// -----------------------------------------------------------------

	#[Test]
	public function the_lifetime_is_five_minutes_with_no_expiry(): void {
		$this->assertSame( 5 * MINUTE_IN_SECONDS, ViewerFacts::cache_ttl() );
		$this->assertSame( ViewerFacts::cache_ttl(), Runtime::cache_ttl() );
	}

	#[Test]
	public function a_distant_expiry_does_not_shorten_the_lifetime(): void {
		$this->assertSame( ViewerFacts::cache_ttl(), ViewerFacts::cache_ttl( '2099-01-01T00:00:00Z' ) );
	}

	#[Test]
	public function the_lifetime_is_capped_so_the_entry_expires_no_later_than_the_entitlement(): void {
		$ttl = ViewerFacts::cache_ttl( gmdate( 'Y-m-d\TH:i:s\Z', time() + 100 ) );

		$this->assertLessThanOrEqual( 100, $ttl );
		$this->assertGreaterThanOrEqual( 97, $ttl );
	}

	#[Test]
	public function the_cap_never_goes_below_the_floor(): void {
		$this->assertSame( 30, ViewerFacts::cache_ttl( gmdate( 'Y-m-d\TH:i:s\Z', time() + 5 ) ) );
		$this->assertSame( 30, ViewerFacts::cache_ttl( '2001-01-01T00:00:00Z' ), 'an expiry already past still holds the floor' );
	}

	#[Test]
	public function an_unreadable_expiry_leaves_the_lifetime_alone(): void {
		$this->assertSame( ViewerFacts::cache_ttl(), ViewerFacts::cache_ttl( 'next thursday' ) );
	}

	#[Test]
	public function the_entry_is_written_with_the_capped_lifetime(): void {
		$this->signIn( 7 );
		$this->gatewayReturns(
			array(
				'tier_ids'     => array(),
				'capabilities' => array(
					array( 'gate_key' => 'soon.key', 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 90 ) ),
				),
			)
		);

		ViewerFacts::member();

		$ttl = Agend_Test_WP::$transient_ttls[ $this->memberKey( 7 ) ];

		$this->assertLessThanOrEqual( 90, $ttl );
		$this->assertGreaterThanOrEqual( 30, $ttl );
	}

	#[Test]
	public function an_entry_with_no_expiry_is_written_with_the_standard_lifetime(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );

		ViewerFacts::member();

		$this->assertSame(
			ViewerFacts::cache_ttl(),
			Agend_Test_WP::$transient_ttls[ $this->memberKey( 7 ) ]
		);
	}

	/** An entry written by something other than this class still reads as a full shape. */
	#[Test]
	public function a_cached_entry_with_fewer_fields_is_completed(): void {
		$this->signIn( 7 );
		Agend_Test_WP::$transients[ $this->memberKey( 7 ) ] = array( 'is_member' => true, 'tier_ids' => array( self::TIER_A ) );

		$facts = ViewerFacts::member();

		$this->assertSame( array(), $facts['entitlement_keys'] );
		$this->assertNull( $facts['entitlement_expires_at'] );
		$this->assertSame( 0, $this->gatewayCalls() );
	}

	// -----------------------------------------------------------------
	// Segments: ids and slugs, on demand
	// -----------------------------------------------------------------

	#[Test]
	public function segments_carry_ids_and_slugs(): void {
		$this->signIn( 7 );
		$this->segmentsReturn(
			array(
				array( 'id' => 'seg-1', 'slug' => 'vic-fellows', 'name' => 'Vic Fellows' ),
				array( 'id' => 'seg-2', 'slug' => 'trainees', 'name' => 'Trainees' ),
			)
		);

		$this->assertSame(
			array(
				'segment_ids'   => array( 'seg-1', 'seg-2' ),
				'segment_slugs' => array( 'vic-fellows', 'trainees' ),
			),
			ViewerFacts::segments()
		);
	}

	/** Nothing in the membership path may fetch segments as a side effect. */
	#[Test]
	public function the_member_lookup_never_fetches_segments(): void {
		$this->signIn( 7 );
		$this->bearer();
		$this->gatewayReturns( $this->memberData() );

		ViewerFacts::member();
		Decision::viewer();
		Runtime::member_facts();

		$this->assertSame( 0, $this->segmentCalls() );
	}

	#[Test]
	public function a_malformed_segment_row_contributes_only_what_is_valid(): void {
		$this->signIn( 7 );
		$this->segmentsReturn(
			array(
				array( 'id' => 'seg-1', 'slug' => 'good' ),
				array( 'id' => 'seg-2' ),
				array( 'slug' => 'slug-only' ),
				array( 'id' => 42, 'slug' => '' ),
				'not an array',
			)
		);

		$this->assertSame(
			array(
				'segment_ids'   => array( 'seg-1', 'seg-2' ),
				'segment_slugs' => array( 'good', 'slug-only' ),
			),
			ViewerFacts::segments()
		);
	}

	#[Test]
	public function a_segment_success_is_cached_and_reused_by_the_next_request(): void {
		$this->signIn( 7 );
		$this->segmentsReturn( array( array( 'id' => 'seg-1', 'slug' => 'vic' ) ) );

		$first = ViewerFacts::segments();

		$this->assertSame( 1, $this->segmentCalls() );
		$this->assertArrayHasKey( $this->segmentKey( 7 ), Agend_Test_WP::$transients );
		$this->assertSame(
			ViewerFacts::cache_ttl(),
			Agend_Test_WP::$transient_ttls[ $this->segmentKey( 7 ) ]
		);

		$this->nextRequest();
		$this->segmentsReturn( array() );

		$this->assertSame( $first, ViewerFacts::segments() );
		$this->assertSame( 1, $this->segmentCalls(), 'the second request must be served from the transient' );
	}

	#[Test]
	public function a_segment_failure_is_unknown_not_cached_and_memoised_for_the_request(): void {
		$this->signIn( 7 );
		$this->segmentsFail();

		$this->assertNull( ViewerFacts::segments() );
		$this->assertNull( ViewerFacts::segments() );
		$this->assertSame( 1, $this->segmentCalls(), 'one outage is one call' );
		$this->assertSame( array(), Agend_Test_WP::$transients, 'a failure must write nothing' );

		$this->nextRequest();
		$this->segmentsReturn( array( array( 'id' => 'seg-1', 'slug' => 'vic' ) ) );

		$this->assertSame( array( 'seg-1' ), ViewerFacts::segments()['segment_ids'] );
		$this->assertSame( 2, $this->segmentCalls() );
	}

	#[Test]
	public function a_non_array_segment_response_is_a_failure_and_is_not_cached(): void {
		$this->signIn( 7 );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_segments_response', 'garbage' );

		$this->assertNull( ViewerFacts::segments() );
		$this->assertSame( array(), Agend_Test_WP::$transients );
	}

	#[Test]
	public function user_zero_segments_are_never_written_to_the_transient_store(): void {
		$this->segmentsReturn( array( array( 'id' => 'seg-1', 'slug' => 'vic' ) ) );

		$this->assertSame( array( 'seg-1' ), ViewerFacts::segments()['segment_ids'] );
		$this->assertSame( array(), Agend_Test_WP::$transients );
		$this->assertSame( array(), Agend_Test_WP::$transient_ttls );

		ViewerFacts::segments();
		$this->assertSame( 1, $this->segmentCalls(), 'still memoised for the request' );

		$this->nextRequest();
		$this->segmentsReturn( array() );

		$this->assertSame( array(), ViewerFacts::segments()['segment_ids'], 'a different visitor must not inherit the first' );
	}

	#[Test]
	public function cached_segments_belong_to_one_member_only(): void {
		$this->signIn( 7 );
		$this->segmentsReturn( array( array( 'id' => 'seg-1', 'slug' => 'vic' ) ) );
		ViewerFacts::segments();

		$this->nextRequest();
		$this->signIn( 8 );
		$this->segmentsReturn( array() );

		$this->assertSame( array(), ViewerFacts::segments()['segment_ids'], 'member 8 must not inherit member 7\'s entry' );
		$this->assertSame( 2, $this->segmentCalls() );
	}

	#[Test]
	public function the_segment_memo_does_not_carry_one_user_into_another_within_a_request(): void {
		$this->signIn( 7 );
		$this->segmentsReturn( array( array( 'id' => 'seg-1', 'slug' => 'vic' ) ) );
		$this->assertSame( array( 'seg-1' ), ViewerFacts::segments()['segment_ids'] );

		$this->signIn( 8 );
		$this->segmentsReturn( array() );

		$this->assertSame( array(), ViewerFacts::segments()['segment_ids'] );
	}

	/**
	 * Before this layer the entry held a bare list of slugs. One written before
	 * an upgrade has no ids, and reading it as "no ids" would deny a visitor a
	 * segments policy admits for the whole cache lifetime.
	 */
	#[Test]
	public function an_entry_in_the_old_slug_list_shape_is_a_miss(): void {
		$this->signIn( 7 );
		Agend_Test_WP::$transients[ $this->segmentKey( 7 ) ] = array( 'old-slug' );
		$this->segmentsReturn( array( array( 'id' => 'seg-1', 'slug' => 'fresh' ) ) );

		$this->assertSame(
			array( 'segment_ids' => array( 'seg-1' ), 'segment_slugs' => array( 'fresh' ) ),
			ViewerFacts::segments()
		);
		$this->assertSame( 1, $this->segmentCalls() );
	}

	#[Test]
	public function flushing_a_member_drops_their_segment_memo_too(): void {
		$this->signIn( 7 );
		$this->segmentsReturn( array( array( 'id' => 'seg-1', 'slug' => 'vic' ) ) );
		ViewerFacts::segments();

		ViewerFacts::flush( 7 );
		$this->segmentsReturn( array( array( 'id' => 'seg-2', 'slug' => 'new' ) ) );

		$this->assertSame( array( 'seg-2' ), ViewerFacts::segments()['segment_ids'] );
		$this->assertSame( 2, $this->segmentCalls() );
	}

	#[Test]
	public function bumping_the_generation_drops_the_segment_memo_and_entries(): void {
		$this->signIn( 7 );
		$this->segmentsReturn( array( array( 'id' => 'seg-1', 'slug' => 'vic' ) ) );
		ViewerFacts::segments();

		ViewerFacts::bump_generation();
		$this->segmentsReturn( array( array( 'id' => 'seg-2', 'slug' => 'new' ) ) );

		$this->assertSame( array( 'seg-2' ), ViewerFacts::segments()['segment_ids'] );
	}
}
