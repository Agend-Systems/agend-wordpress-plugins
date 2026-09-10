<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Condition_Providers as Providers;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Agend_Test_WP;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';

/**
 * Condition providers (US-6.1, reframed as ESAC feature parity).
 *
 * The distinction these tests exist to protect is NULL versus FALSE. False
 * means the visitor does not qualify; null means the provider could not tell.
 * Collapsing them turns an API outage into a silent mass-denial with no
 * diagnostic, or, on an inverted rule, into a mass-disclosure.
 */
#[CoversClass( Providers::class )]
final class ConditionProvidersTest extends TestCase {

	private const TIER_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

	// -----------------------------------------------------------------
	// Legacy aliases
	// -----------------------------------------------------------------

	/**
	 * 1,106 conditions across nine sites are stored with these prefixes.
	 * Rewriting that data would be a migration with nothing to gain and a real
	 * risk of silent mis-conversion, so the prefixes are accepted as written.
	 */
	#[Test]
	public function legacy_esac_prefixes_resolve_to_canonical_providers(): void {
		$this->assertSame( Providers::WORDPRESS, Providers::canonical( 'iugo_esac_wordpress' ) );
		$this->assertSame( Providers::SEGMENTS, Providers::canonical( 'iugo_esac_segments' ) );
		$this->assertSame( Providers::MEMBERSHIP, Providers::canonical( 'upbeat_is_member' ) );
		$this->assertSame( Providers::MEMBERSHIP, Providers::canonical( 'upbeat_member_entitlement' ) );
		$this->assertSame( Providers::MEMBERSHIP, Providers::canonical( 'upbeat_member_grades' ) );
	}

	#[Test]
	public function canonical_keys_resolve_to_themselves(): void {
		$this->assertSame( Providers::WORDPRESS, Providers::canonical( Providers::WORDPRESS ) );
	}

	/**
	 * A provider with no implementation must stay unrecognised, so the engine
	 * denies. `company_access` and `iugo_marketplace` appear in real data and
	 * are deliberately NOT aliased: silently mapping them onto membership would
	 * change what those rules mean.
	 */
	#[Test]
	public function an_unimplemented_legacy_provider_stays_unrecognised(): void {
		$this->assertNull( Providers::canonical( 'company_access' ) );
		$this->assertNull( Providers::canonical( 'iugo_marketplace' ) );
		$this->assertNull( Providers::canonical( 'organisation_member' ) );
		$this->assertNull( Providers::canonical( 'agend_callables' ) );
	}

	// -----------------------------------------------------------------
	// WordPress
	// -----------------------------------------------------------------

	#[Test]
	public function login_state_conditions_read_the_current_user(): void {
		$in  = array( 'logged_in' => true, 'roles' => array( 'subscriber' ) );
		$out = array( 'logged_in' => false, 'roles' => array() );

		$this->assertTrue( Providers::check_wordpress( 'logged_in', $in ) );
		$this->assertFalse( Providers::check_wordpress( 'logged_out', $in ) );
		$this->assertFalse( Providers::check_wordpress( 'logged_in', $out ) );
		$this->assertTrue( Providers::check_wordpress( 'logged_out', $out ) );
	}

	#[Test]
	public function role_conditions_match_the_users_roles(): void {
		$facts = array( 'logged_in' => true, 'roles' => array( 'editor', 'shop_manager' ) );

		$this->assertTrue( Providers::check_wordpress( 'role_editor', $facts ) );
		$this->assertTrue( Providers::check_wordpress( 'role_shop_manager', $facts ) );
		$this->assertFalse( Providers::check_wordpress( 'role_administrator', $facts ) );
	}

	/**
	 * A value this provider does not define is UNKNOWN, not false. A typo in a
	 * condition would otherwise read as "the visitor lacks it", producing a
	 * rule that looks fine and never matches.
	 */
	#[Test]
	public function an_undefined_wordpress_value_is_unknown_not_false(): void {
		$this->assertNull(
			Providers::check_wordpress( 'loggedin', array( 'logged_in' => true, 'roles' => array() ) )
		);
	}

	// -----------------------------------------------------------------
	// Membership
	// -----------------------------------------------------------------

	#[Test]
	public function is_member_reads_member_standing(): void {
		$this->assertTrue(
			Providers::check_membership( 'is_member', array( 'is_member' => true ) )
		);
		$this->assertFalse(
			Providers::check_membership( 'is_member', array( 'is_member' => false ) )
		);
	}

	/**
	 * Legacy conditions name a tier SLUG; anything authored against Agend names
	 * a UUID. Both are matched, which is not a guess: the namespaces are exact
	 * and a slug cannot collide with a UUID.
	 */
	#[Test]
	public function a_tier_matches_by_slug_or_by_id(): void {
		$facts = array(
			'is_member'  => true,
			'tier_ids'   => array( self::TIER_ID ),
			'tier_slugs' => array( 'trainee_advanced' ),
		);

		$this->assertTrue( Providers::check_membership( 'trainee_advanced', $facts ) );
		$this->assertTrue( Providers::check_membership( self::TIER_ID, $facts ) );
		$this->assertFalse( Providers::check_membership( 'some_other_tier', $facts ) );
	}

	// -----------------------------------------------------------------
	// The checker: laziness, reuse, and unknown-on-outage
	// -----------------------------------------------------------------

	/**
	 * A page with thirty conditioned sections must not make thirty API calls,
	 * and every section must be judged against the SAME answer. A membership
	 * expiring mid-render would otherwise show half a page as a member.
	 */
	#[Test]
	public function api_facts_are_resolved_once_and_reused(): void {
		$memberCalls  = 0;
		$segmentCalls = 0;

		$check = Providers::checker( array(
			Providers::MEMBERSHIP => static function () use ( &$memberCalls ): array {
				++$memberCalls;
				return array( 'is_member' => true, 'tier_ids' => array(), 'tier_slugs' => array() );
			},
			Providers::SEGMENTS   => static function () use ( &$segmentCalls ): array {
				++$segmentCalls;
				return array( 'segment_1' );
			},
		) );

		$check( 'upbeat_is_member', 'is_member' );
		$check( 'upbeat_is_member', 'is_member' );
		$check( 'iugo_esac_segments', 'segment_1' );
		$check( 'iugo_esac_segments', 'segment_2' );

		$this->assertSame( 1, $memberCalls );
		$this->assertSame( 1, $segmentCalls );
	}

	/**
	 * The load-bearing distinction. An unreachable API is UNKNOWN, so the
	 * engine denies and reports it, rather than answering "not a member" as
	 * though the question had been settled.
	 */
	#[Test]
	public function an_unreachable_membership_api_is_unknown_not_a_denial(): void {
		$check = Providers::checker( array(
			Providers::MEMBERSHIP => static fn() => null, // outage
			Providers::SEGMENTS   => static fn(): array => array(),
		) );

		$this->assertNull( $check( 'upbeat_is_member', 'is_member' ) );
	}

	#[Test]
	public function an_unreachable_segments_api_is_unknown(): void {
		$check = Providers::checker( array(
			Providers::MEMBERSHIP => static fn(): array => array( 'is_member' => true ),
			Providers::SEGMENTS   => static fn() => null, // outage
		) );

		$this->assertNull( $check( 'iugo_esac_segments', 'segment_1' ) );
	}

	#[Test]
	public function an_unknown_provider_is_unknown_and_costs_no_api_call(): void {
		$calls = 0;

		$check = Providers::checker( array(
			Providers::MEMBERSHIP => static function () use ( &$calls ) {
				++$calls;
				return array( 'is_member' => true );
			},
			Providers::SEGMENTS   => static fn(): array => array(),
		) );

		$this->assertNull( $check( 'company_access', 'company_administrator' ) );
		$this->assertSame( 0, $calls );
	}

	/**
	 * WordPress facts need no network, so an anonymous visitor on a page with
	 * only role conditions must not trigger a member lookup at all.
	 *
	 * The WordPress facts are injected rather than left to the registry's
	 * default, which reads the current user from global state. This assertion
	 * is about which sources get CALLED, so letting it depend on whichever
	 * user a previously-run suite happened to leave signed in would make it
	 * fail on test order rather than on the behaviour it exists to pin.
	 */
	#[Test]
	public function wordpress_conditions_never_reach_the_api(): void {
		$calls = 0;

		$check = Providers::checker( array(
			Providers::WORDPRESS  => static fn(): array => array(
				'logged_in' => false,
				'roles'     => array(),
			),
			Providers::MEMBERSHIP => static function () use ( &$calls ) {
				++$calls;
				return array( 'is_member' => false );
			},
			Providers::SEGMENTS   => static fn(): array => array(),
		) );

		$this->assertTrue( $check( 'iugo_esac_wordpress', 'logged_out' ) );
		$this->assertSame( 0, $calls );
	}

	// -----------------------------------------------------------------
	// Segments as an explicit branch, not a fallthrough
	// -----------------------------------------------------------------

	#[Test]
	public function segment_values_match_the_resolved_slugs(): void {
		$this->assertTrue( Providers::check_segments( 'vic-fellows', array( 'vic-fellows', 'trainees' ) ) );
		$this->assertFalse( Providers::check_segments( 'other', array( 'vic-fellows' ) ) );
	}

	// -----------------------------------------------------------------
	// The provider registry and its extension filter
	// -----------------------------------------------------------------

	/**
	 * The registry is exactly the three built-ins until a site adds to it.
	 */
	#[Test]
	public function the_default_registry_holds_only_the_three_built_ins(): void {
		$registry = Providers::registry();

		$this->assertSame(
			array( Providers::WORDPRESS, Providers::MEMBERSHIP, Providers::SEGMENTS ),
			array_keys( $registry )
		);
	}

	/**
	 * A site adds a fourth family entirely through the filter: no method on
	 * this class changes to accommodate it, and its own `facts` callable is
	 * used as-is because the registry, not the caller of `checker()`, owns it.
	 */
	#[Test]
	public function a_site_registered_provider_is_dispatched_through_the_filter(): void {
		Agend_Test_WP::$filters['agend_content_access_condition_providers'] = static function ( $providers ) {
			$providers['agend_entitlements'] = array(
				'facts' => static fn(): array => array( 'active_skus' => array( 'ce-2026' ) ),
				'check' => static fn( string $value, array $facts ): ?bool =>
					in_array( $value, $facts['active_skus'], true ),
			);
			return $providers;
		};

		$check = Providers::checker();

		$this->assertTrue( $check( 'agend_entitlements', 'ce-2026' ) );
		$this->assertFalse( $check( 'agend_entitlements', 'ce-2027' ) );
	}

	/**
	 * The defect this registry replaces: adding a set to the editor vocabulary
	 * without a matching provider must still deny, loudly, rather than being
	 * silently accepted or, worse, silently passing.
	 */
	#[Test]
	public function a_provider_with_no_registry_entry_stays_unknown(): void {
		$check = Providers::checker();

		$this->assertNull( $check( 'agend_entitlements', 'ce-2026' ) );
	}

	/**
	 * A site-registered provider's own outage denies exactly like a built-in's:
	 * `facts` returning null is unknown, not "the visitor lacks this".
	 */
	#[Test]
	public function a_site_registered_providers_outage_is_unknown_not_a_denial(): void {
		Agend_Test_WP::$filters['agend_content_access_condition_providers'] = static function ( $providers ) {
			$providers['agend_entitlements'] = array(
				'facts' => static fn() => null, // outage
				'check' => static fn( string $value, array $facts ): ?bool => true,
			);
			return $providers;
		};

		$check = Providers::checker();

		$this->assertNull( $check( 'agend_entitlements', 'ce-2026' ) );
	}

	/**
	 * A fourth provider's facts are memoised exactly like the built-ins':
	 * once per checker instance, however many conditions reference it.
	 */
	#[Test]
	public function a_site_registered_providers_facts_are_resolved_once(): void {
		$calls = 0;

		Agend_Test_WP::$filters['agend_content_access_condition_providers'] = static function ( $providers ) use ( &$calls ) {
			$providers['agend_entitlements'] = array(
				'facts' => static function () use ( &$calls ): array {
					++$calls;
					return array( 'active_skus' => array( 'ce-2026' ) );
				},
				'check' => static fn( string $value, array $facts ): ?bool =>
					in_array( $value, $facts['active_skus'], true ),
			);
			return $providers;
		};

		$check = Providers::checker();

		$check( 'agend_entitlements', 'ce-2026' );
		$check( 'agend_entitlements', 'ce-2027' );

		$this->assertSame( 1, $calls );
	}
}
