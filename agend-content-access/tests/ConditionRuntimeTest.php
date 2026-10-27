<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Condition_Runtime as Runtime;
use Agend_Content_Access_Viewer_Facts as ViewerFacts;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Agend_Test_WP;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
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

	/** The bearer a signed-in member presents. Cache entries are bound to it. */
	private const BEARER = 'member-token';

	protected function setUp(): void {
		parent::setUp();

		Agend_Test_WP::$transients             = array();
		Agend_Test_WP::$filters                = array();
		$GLOBALS['agend_test_current_user_id'] = 0;
	}

	/**
	 * Signs a user in for the duration of a test, with the bearer that makes them
	 * somebody to Agend. A WordPress login alone is not an Agend identity: nothing
	 * about a visitor with no bearer is looked up.
	 */
	private function signIn( int $userId ): void {
		$GLOBALS['agend_test_current_user_id'] = $userId;

		$this->bearer();
	}

	/** Gives the signed-in user a bearer, which is what makes facts cacheable. */
	private function bearer( string $token = self::BEARER ): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', $token );
	}

	/** The segment slugs the runtime reports, or null when it cannot tell: the part of `segment_sets()` display conditions match on. */
	private function slugs(): ?array {
		$sets = Runtime::segment_sets();

		return null === $sets ? null : $sets['segment_slugs'];
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
		$this->bearer();

		Agend_Test_WP::$filters['agend_apps_crm_get_my_entitlements_response'] =
			static fn( $value ) => new \WP_Error( 'down', 'Gateway unreachable' );

		Runtime::member_facts();

		$this->assertArrayNotHasKey(
			ViewerFacts::member_cache_key( 7, self::BEARER ),
			Agend_Test_WP::$transients
		);
		$this->assertSame( array(), Agend_Test_WP::$transients, 'a failure must write nothing under any key' );
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
		$this->bearer();
		Agend_Test_WP::$transients[ ViewerFacts::member_cache_key( 7, self::BEARER ) ] = array(
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
		// A member holds a plan as well as the flag: see `member_facts()`.
		Agend_Test_WP::$transients[ ViewerFacts::member_cache_key( 7, self::BEARER ) ] = array( 'is_member' => true, 'tier_ids' => array( 'tier-a' ) );
		Agend_Test_WP::$transients[ ViewerFacts::member_cache_key( 8, self::BEARER ) ] = array( 'is_member' => true, 'tier_ids' => array( 'tier-a' ) );

		Runtime::flush_member_facts( 7 );

		// A flush makes the user's entries unreachable (a new key) rather than
		// deleting them by name, which a caller without a bearer could not do.
		$this->signIn( 8 );
		$this->bearer();
		$this->assertTrue( Runtime::member_facts()['is_member'], 'another member is untouched' );

		$this->signIn( 7 );
		$this->assertFalse( Runtime::member_facts()['is_member'], 'the flushed member reads fresh' );
	}

	/**
	 * A site can still override the gateway with its own source. Returning an
	 * array short-circuits the read entirely.
	 */
	#[Test]
	public function a_site_may_supply_segments_through_the_filter(): void {
		Agend_Test_WP::$filters['agend_content_access_segment_facts'] =
			static fn( $value ) => array( 'segment_1', 'segment_2' );

		$this->assertSame( array( 'segment_1', 'segment_2' ), $this->slugs() );
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
		$this->assertSame( array(), $this->slugs() );
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

		$this->assertSame( array( 'vic-fellows', 'trainees' ), $this->slugs() );
	}

	/**
	 * An outage is UNKNOWN, not "belongs to no segments". The latter is a
	 * different claim, and on an inverted rule it would disclose rather than
	 * deny.
	 */
	#[Test]
	public function a_segments_outage_is_unknown_rather_than_an_empty_set(): void {
		$this->signIn( 7 );
		$this->bearer();

		Agend_Test_WP::$filters['agend_apps_crm_get_my_segments_response'] =
			static fn( $value ) => new \WP_Error( 'down', 'Gateway unreachable' );

		$this->assertNull( $this->slugs() );
		$this->assertArrayNotHasKey(
			ViewerFacts::segment_cache_key( 7, self::BEARER ),
			Agend_Test_WP::$transients
		);
		$this->assertSame( array(), Agend_Test_WP::$transients, 'a failure must write nothing under any key' );
	}

	#[Test]
	public function segments_are_cached_per_viewer_and_not_refetched(): void {
		$this->signIn( 7 );

		Agend_Test_WP::$filters['agend_apps_crm_get_my_segments_response'] =
			static fn( $value ) => array(
				'data' => array( array( 'id' => 'a', 'slug' => 'vic-fellows', 'name' => 'V' ) ),
			);

		$this->slugs();
		$after = count( Agend_Test_WP::$requests );
		$this->slugs();

		$this->assertSame( $after, count( Agend_Test_WP::$requests ) );

		// A different viewer must not inherit that cache entry.
		$this->signIn( 8 );
		$this->slugs();

		$this->assertGreaterThan( $after, count( Agend_Test_WP::$requests ) );
	}

	#[Test]
	public function flushing_clears_both_membership_and_segment_caches(): void {
		$this->signIn( 7 );
		$this->bearer();

		Agend_Test_WP::$transients[ ViewerFacts::member_cache_key( 7, self::BEARER ) ]  = array( 'is_member' => true, 'tier_ids' => array( 'tier-a' ) );
		Agend_Test_WP::$transients[ ViewerFacts::segment_cache_key( 7, self::BEARER ) ] = array(
			'segment_ids'   => array( 'seg-old' ),
			'segment_slugs' => array( 'vic' ),
		);

		$this->assertTrue( Runtime::member_facts()['is_member'] );
		$this->assertSame( array( 'vic' ), $this->slugs() );

		Runtime::flush_member_facts( 7 );

		$this->assertNotSame( array( 'vic' ), $this->slugs() );
		$this->assertFalse( Runtime::member_facts()['is_member'] );
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

		$this->assertSame( array( 'good' ), $this->slugs() );
	}

	// -----------------------------------------------------------------
	// Entitlements
	// -----------------------------------------------------------------

	private function entitlementsAre( array $gate_keys ): void {
		Agend_Test_WP::$filters['agend_apps_crm_get_my_entitlements_response'] =
			static fn( $value ) => array(
				'data' => array(
					'is_member'    => true,
					'tier_ids'     => array( 'tier-a' ),
					'tier_slugs'   => array( 'gold' ),
					'capabilities' => array_map(
						static fn( string $key ): array => array( 'gate_key' => $key ),
						$gate_keys
					),
				),
			);
	}

	private function entitlementCalls(): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => '/crm/me/entitlements' === $request['url']
			)
		);
	}

	/** Not an outage: a signed-out visitor definitively holds nothing, so no lookup. */
	#[Test]
	public function a_signed_out_visitor_holds_no_entitlements_and_costs_no_call(): void {
		$this->assertSame( array(), Runtime::entitlement_facts() );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function entitlement_keys_are_read_from_the_member_facts(): void {
		$this->signIn( 7 );
		$this->entitlementsAre( array( 'directory.access', 'Not A Key', 'journal' ) );

		$this->assertSame( array( 'directory.access', 'journal' ), Runtime::entitlement_facts() );
	}

	/** An outage is UNKNOWN, never "holds none", which on an inverted rule would disclose. */
	#[Test]
	public function an_entitlement_outage_is_unknown_and_not_cached(): void {
		$this->signIn( 7 );
		Agend_Test_WP::$filters['agend_apps_crm_get_my_entitlements_response'] =
			static fn( $value ) => new \WP_Error( 'down', 'Gateway unreachable' );

		$this->assertNull( Runtime::entitlement_facts() );
		$this->assertSame( array(), Agend_Test_WP::$transients );
	}

	/** Entitlements and membership come from one response, so conditions on both are one call. */
	#[Test]
	public function entitlements_and_membership_share_one_gateway_call(): void {
		$this->signIn( 7 );
		$this->entitlementsAre( array( 'directory.access' ) );

		$check = Runtime::checker();

		$this->assertTrue( $check( 'agend_entitlements', 'directory.access' ) );
		$this->assertFalse( $check( 'agend_entitlements', 'journal' ) );
		$this->assertTrue( $check( 'agend_membership', 'gold' ) );
		$this->assertSame( 1, $this->entitlementCalls() );
	}

	#[Test]
	public function the_live_checker_answers_an_entitlement_condition_end_to_end(): void {
		$this->signIn( 7 );
		$this->entitlementsAre( array( 'directory.access' ) );

		$holds = \Agend_Content_Access_Conditions::evaluate( array( 'agend_entitlements:directory.access' ), Runtime::checker() );
		$lacks = \Agend_Content_Access_Conditions::evaluate( array( 'agend_entitlements:journal' ), Runtime::checker() );

		$this->assertTrue( $holds['passes'] );
		$this->assertFalse( $lacks['passes'] );
		$this->assertSame( array(), $lacks['unknown'], 'not holding it is a settled answer, not an unknown one' );
	}

	#[Test]
	public function the_live_checker_denies_a_signed_out_visitor_without_a_call(): void {
		$check = Runtime::checker();

		$this->assertFalse( $check( 'agend_entitlements', 'directory.access' ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function the_live_checker_reports_an_outage_as_unknown(): void {
		$this->signIn( 7 );
		Agend_Test_WP::$filters['agend_apps_crm_get_my_entitlements_response'] =
			static fn( $value ) => new \WP_Error( 'down', 'Gateway unreachable' );

		$this->assertNull( Runtime::checker()( 'agend_entitlements', 'directory.access' ) );
	}
}
