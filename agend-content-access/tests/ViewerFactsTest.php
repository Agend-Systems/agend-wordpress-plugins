<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Condition_Runtime as Runtime;
use Agend_Content_Access_Decision as Decision;
use Agend_Content_Access_Viewer_Facts as ViewerFacts;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
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

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_id'] = 0;
	}

	private function signIn( int $userId ): void {
		$GLOBALS['agend_test_current_user_id'] = $userId;
	}

	private function bearer( string $token = 'member-token' ): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', $token );
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
		$this->assertArrayHasKey( ViewerFacts::member_cache_key( 7 ), Agend_Test_WP::$transients );

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
	// Generation and flush
	// -----------------------------------------------------------------

	#[Test]
	public function the_generation_starts_at_zero_and_is_part_of_the_key(): void {
		$this->assertSame( 0, ViewerFacts::generation() );
		$this->assertSame( 'agend_content_access_member_facts_g0_u7', ViewerFacts::member_cache_key( 7 ) );
		$this->assertSame( 'agend_content_access_segment_facts_g0_u7', ViewerFacts::segment_cache_key( 7 ) );
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
		$this->assertStringContainsString( '_g1_', ViewerFacts::member_cache_key( 7 ) );
		$this->assertArrayHasKey( ViewerFacts::member_cache_key( 7 ), Agend_Test_WP::$transients );
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
	public function flushing_one_member_clears_the_entry_and_the_request_memo(): void {
		$this->signIn( 7 );
		$this->gatewayReturns( $this->memberData() );
		ViewerFacts::member();
		Agend_Test_WP::$transients[ ViewerFacts::segment_cache_key( 7 ) ] = array( 'vic' );
		Agend_Test_WP::$transients[ ViewerFacts::member_cache_key( 8 ) ]  = array( 'is_member' => true );

		Runtime::flush_member_facts( 7 );

		$this->assertArrayNotHasKey( ViewerFacts::member_cache_key( 7 ), Agend_Test_WP::$transients );
		$this->assertArrayNotHasKey( ViewerFacts::segment_cache_key( 7 ), Agend_Test_WP::$transients );
		$this->assertArrayHasKey( ViewerFacts::member_cache_key( 8 ), Agend_Test_WP::$transients, 'another member is untouched' );

		// Same request: the memo is gone as well, so this reads fresh.
		ViewerFacts::member();
		$this->assertSame( 2, $this->gatewayCalls() );
	}

	#[Test]
	public function a_segment_entry_written_under_an_old_generation_is_not_read(): void {
		$this->signIn( 7 );
		Agend_Test_WP::$transients[ ViewerFacts::segment_cache_key( 7 ) ] = array(
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
		$this->signIn( 7 );
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

	#[Test]
	public function a_response_without_the_data_envelope_is_read_as_the_data(): void {
		$this->signIn( 7 );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', $this->memberData() );

		$this->assertSame( array( self::TIER_A ), ViewerFacts::member()['tier_ids'] );
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
		$this->assertSame( $facts, Agend_Test_WP::$transients[ ViewerFacts::member_cache_key( 7 ) ], 'they are cached with the rest' );
	}

	#[Test]
	public function an_entitlement_key_that_is_not_a_gate_key_is_dropped(): void {
		$this->signIn( 7 );
		$this->gatewayReturns(
			array(
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
		$this->gatewayReturns( array( 'capabilities' => 'events.early_access' ) );

		$this->assertSame( array(), ViewerFacts::member()['entitlement_keys'] );
	}

	#[Test]
	public function the_earliest_capability_expiry_is_kept_as_utc(): void {
		$this->signIn( 7 );
		$this->gatewayReturns(
			array(
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
				'capabilities' => array(
					array( 'gate_key' => 'soon.key', 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 90 ) ),
				),
			)
		);

		ViewerFacts::member();

		$ttl = Agend_Test_WP::$transient_ttls[ ViewerFacts::member_cache_key( 7 ) ];

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
			Agend_Test_WP::$transient_ttls[ ViewerFacts::member_cache_key( 7 ) ]
		);
	}

	/** An entry written by something other than this class still reads as a full shape. */
	#[Test]
	public function a_cached_entry_with_fewer_fields_is_completed(): void {
		$this->signIn( 7 );
		Agend_Test_WP::$transients[ ViewerFacts::member_cache_key( 7 ) ] = array( 'is_member' => true, 'tier_ids' => array( self::TIER_A ) );

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
		$this->assertArrayHasKey( ViewerFacts::segment_cache_key( 7 ), Agend_Test_WP::$transients );
		$this->assertSame(
			ViewerFacts::cache_ttl(),
			Agend_Test_WP::$transient_ttls[ ViewerFacts::segment_cache_key( 7 ) ]
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
		Agend_Test_WP::$transients[ ViewerFacts::segment_cache_key( 7 ) ] = array( 'old-slug' );
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
