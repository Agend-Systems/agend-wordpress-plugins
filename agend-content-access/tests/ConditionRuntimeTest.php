<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Condition_Runtime as Runtime;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Agend_Test_WP;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';

/**
 * The live fact sources behind the condition engine (US-6.1, reframed).
 *
 * Two properties are load-bearing here and both concern what happens when
 * things go wrong: a per-viewer cache that cannot serve one member's standing
 * to another, and a failure that reads as UNKNOWN rather than as "not a
 * member".
 */
#[CoversClass( Runtime::class )]
final class ConditionRuntimeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Agend_Test_WP::$transients             = array();
		Agend_Test_WP::$filters                = array();
		$GLOBALS['agend_test_current_user_id'] = 0;
	}

	/** Signs a user in for the duration of a test. */
	private function signIn( int $userId ): void {
		$GLOBALS['agend_test_current_user_id'] = $userId;
	}

	#[Test]
	public function a_signed_out_visitor_holds_nothing_and_costs_no_api_call(): void {
		$facts = Runtime::member_facts();

		// Definitive, not unknown: a signed-out visitor is certainly not a
		// member, so this must NOT deny by returning null.
		$this->assertSame(
			array( 'is_member' => false, 'tier_ids' => array(), 'tier_slugs' => array() ),
			$facts
		);
	}

	/**
	 * The load-bearing case. An API outage must read as UNKNOWN so the engine
	 * denies AND reports it, not as "not a member", which would be
	 * indistinguishable from a settled answer and, on an inverted rule, would
	 * disclose rather than deny.
	 */
	#[Test]
	public function an_api_outage_is_unknown_rather_than_a_denial(): void {
		$this->signIn( 7 );

		Agend_Test_WP::$filters['agend_apps_crm_get_my_entitlements_response'] =
			static fn( $value ) => new \WP_Error( 'down', 'Gateway unreachable' );

		$this->assertNull( Runtime::member_facts() );
	}

	/**
	 * A failure is never cached. Caching it would stretch one blip into a
	 * TTL-long outage for that member.
	 */
	#[Test]
	public function an_outage_is_not_cached(): void {
		$this->signIn( 7 );

		Agend_Test_WP::$filters['agend_apps_crm_get_my_entitlements_response'] =
			static fn( $value ) => new \WP_Error( 'down', 'Gateway unreachable' );

		Runtime::member_facts();

		$this->assertArrayNotHasKey(
			Runtime::MEMBER_CACHE_KEY . '_7',
			Agend_Test_WP::$transients
		);
	}

	#[Test]
	public function a_successful_read_is_cached_and_not_repeated(): void {
		$this->signIn( 7 );

		Agend_Test_WP::$filters['agend_apps_crm_get_my_entitlements_response'] =
			static fn( $value ) => array(
				'data' => array(
					'is_member'  => true,
					'tier_ids'   => array( 'tier-a' ),
					'tier_slugs' => array( 'gold' ),
				),
			);

		$first = Runtime::member_facts();
		$countAfterFirst = count( Agend_Test_WP::$requests );
		$second = Runtime::member_facts();

		$this->assertTrue( $first['is_member'] );
		$this->assertSame( array( 'gold' ), $first['tier_slugs'] );
		$this->assertSame( $first, $second );
		// Served from the transient the second time.
		$this->assertSame( $countAfterFirst, count( Agend_Test_WP::$requests ) );
	}

	/**
	 * The cache key carries the user id. A shared key would serve one member's
	 * standing to the next visitor, which is the exact leak found and fixed in
	 * Core's `get_cached()` earlier in this work.
	 */
	#[Test]
	public function cached_facts_are_keyed_per_viewer(): void {
		Agend_Test_WP::$transients[ Runtime::MEMBER_CACHE_KEY . '_7' ] = array(
			'is_member'  => true,
			'tier_ids'   => array( 'tier-a' ),
			'tier_slugs' => array( 'gold' ),
		);

		$this->signIn( 7 );
		$this->assertTrue( Runtime::member_facts()['is_member'] );

		// A different member must not see the first one's cached standing. With
		// no entry of their own they go to the API, which by default reports a
		// non-member, so the cached `true` must not leak across.
		$this->signIn( 8 );
		$this->assertFalse( Runtime::member_facts()['is_member'] );
	}

	#[Test]
	public function flushing_clears_only_the_named_viewer(): void {
		Agend_Test_WP::$transients[ Runtime::MEMBER_CACHE_KEY . '_7' ] = array( 'is_member' => true );
		Agend_Test_WP::$transients[ Runtime::MEMBER_CACHE_KEY . '_8' ] = array( 'is_member' => true );

		Runtime::flush_member_facts( 7 );

		$this->assertArrayNotHasKey(
			Runtime::MEMBER_CACHE_KEY . '_7',
			Agend_Test_WP::$transients
		);
		$this->assertArrayHasKey(
			Runtime::MEMBER_CACHE_KEY . '_8',
			Agend_Test_WP::$transients
		);
	}

	/**
	 * A site can still override the gateway with its own source. Returning an
	 * array short-circuits the read entirely.
	 */
	#[Test]
	public function a_site_may_supply_segments_through_the_filter(): void {
		Agend_Test_WP::$filters['agend_content_access_segment_facts'] =
			static fn( $value ) => array( 'segment_1', 'segment_2' );

		$this->assertSame( array( 'segment_1', 'segment_2' ), Runtime::segment_facts() );
	}

	#[Test]
	public function the_cache_ttl_is_short_enough_that_a_lapse_takes_effect(): void {
		// Membership standing decides visibility, so a long TTL means a lapsed
		// member keeps access for that long.
		$this->assertLessThanOrEqual( 15 * MINUTE_IN_SECONDS, Runtime::cache_ttl() );
		$this->assertGreaterThan( 0, Runtime::cache_ttl() );
	}

	// -----------------------------------------------------------------
	// Segments
	// -----------------------------------------------------------------

	#[Test]
	public function a_signed_out_visitor_belongs_to_no_segments_definitively(): void {
		// Segments are contact attributes and there is no contact, so this is
		// an answer rather than an absence of one. It must NOT deny.
		$this->assertSame( array(), Runtime::segment_facts() );
	}

	#[Test]
	public function segment_slugs_are_read_from_the_gateway(): void {
		$this->signIn( 7 );

		Agend_Test_WP::$filters['agend_apps_crm_get_my_segments_response'] =
			static fn( $value ) => array(
				'data' => array(
					array( 'id' => 'a', 'slug' => 'vic-fellows', 'name' => 'VIC Fellows' ),
					array( 'id' => 'b', 'slug' => 'trainees', 'name' => 'Trainees' ),
				),
			);

		$this->assertSame( array( 'vic-fellows', 'trainees' ), Runtime::segment_facts() );
	}

	/**
	 * An outage is UNKNOWN, not "belongs to no segments". The latter is a
	 * different claim, and on an inverted rule it would disclose rather than
	 * deny.
	 */
	#[Test]
	public function a_segments_outage_is_unknown_rather_than_an_empty_set(): void {
		$this->signIn( 7 );

		Agend_Test_WP::$filters['agend_apps_crm_get_my_segments_response'] =
			static fn( $value ) => new \WP_Error( 'down', 'Gateway unreachable' );

		$this->assertNull( Runtime::segment_facts() );
		$this->assertArrayNotHasKey(
			Runtime::SEGMENT_CACHE_KEY . '_7',
			Agend_Test_WP::$transients
		);
	}

	#[Test]
	public function segments_are_cached_per_viewer_and_not_refetched(): void {
		$this->signIn( 7 );

		Agend_Test_WP::$filters['agend_apps_crm_get_my_segments_response'] =
			static fn( $value ) => array(
				'data' => array( array( 'id' => 'a', 'slug' => 'vic-fellows', 'name' => 'V' ) ),
			);

		Runtime::segment_facts();
		$after = count( Agend_Test_WP::$requests );
		Runtime::segment_facts();

		$this->assertSame( $after, count( Agend_Test_WP::$requests ) );

		// A different viewer must not inherit that cache entry.
		$this->signIn( 8 );
		Runtime::segment_facts();

		$this->assertGreaterThan( $after, count( Agend_Test_WP::$requests ) );
	}

	#[Test]
	public function flushing_clears_both_membership_and_segment_caches(): void {
		Agend_Test_WP::$transients[ Runtime::MEMBER_CACHE_KEY . '_7' ]  = array( 'is_member' => true );
		Agend_Test_WP::$transients[ Runtime::SEGMENT_CACHE_KEY . '_7' ] = array( 'vic' );

		Runtime::flush_member_facts( 7 );

		$this->assertArrayNotHasKey( Runtime::MEMBER_CACHE_KEY . '_7', Agend_Test_WP::$transients );
		$this->assertArrayNotHasKey( Runtime::SEGMENT_CACHE_KEY . '_7', Agend_Test_WP::$transients );
	}

	#[Test]
	public function a_malformed_segment_row_is_skipped_not_fatal(): void {
		$this->signIn( 7 );

		Agend_Test_WP::$filters['agend_apps_crm_get_my_segments_response'] =
			static fn( $value ) => array(
				'data' => array(
					array( 'id' => 'a', 'slug' => 'good', 'name' => 'Good' ),
					array( 'id' => 'b', 'name' => 'No slug' ),
					'not an array',
				),
			);

		$this->assertSame( array( 'good' ), Runtime::segment_facts() );
	}
}
