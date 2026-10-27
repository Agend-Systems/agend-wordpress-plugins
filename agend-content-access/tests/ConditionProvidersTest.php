<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Condition_Providers as Providers;
use Agend_Content_Access_Conditions as Conditions;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
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

		$check = Providers::checker(
			array(
				Providers::WORDPRESS  => static fn(): array => array( 'logged_in' => true, 'roles' => array() ),
				Providers::MEMBERSHIP => static function () use ( &$memberCalls ): array {
					++$memberCalls;
					return array( 'is_member' => true, 'tier_ids' => array(), 'tier_slugs' => array() );
				},
				Providers::SEGMENTS   => static function () use ( &$segmentCalls ): array {
					++$segmentCalls;
					return array( 'segment_1' );
				},
			)
		);

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
		$check = Providers::checker(
			array(
				Providers::WORDPRESS  => static fn(): array => array( 'logged_in' => true, 'roles' => array() ),
				Providers::MEMBERSHIP => static fn() => null, // outage
				Providers::SEGMENTS   => static fn(): array => array(),
			)
		);

		$this->assertNull( $check( 'upbeat_is_member', 'is_member' ) );
	}

	#[Test]
	public function an_unreachable_segments_api_is_unknown(): void {
		$check = Providers::checker(
			array(
				Providers::WORDPRESS  => static fn(): array => array( 'logged_in' => true, 'roles' => array() ),
				Providers::MEMBERSHIP => static fn(): array => array( 'is_member' => true ),
				Providers::SEGMENTS   => static fn() => null, // outage
			)
		);

		$this->assertNull( $check( 'iugo_esac_segments', 'segment_1' ) );
	}

	#[Test]
	public function an_unknown_provider_is_unknown_and_costs_no_api_call(): void {
		$calls = 0;

		$check = Providers::checker(
			array(
				Providers::WORDPRESS  => static fn(): array => array( 'logged_in' => false, 'roles' => array() ),
				Providers::MEMBERSHIP => static function () use ( &$calls ) {
					++$calls;
					return array( 'is_member' => true );
				},
				Providers::SEGMENTS   => static fn(): array => array(),
			)
		);

		$this->assertNull( $check( 'company_access', 'company_administrator' ) );
		$this->assertSame( 0, $calls );
	}

	/**
	 * WordPress facts need no network, so an anonymous visitor on a page with
	 * only role conditions must not trigger a member lookup at all.
	 */
	#[Test]
	public function wordpress_conditions_never_reach_the_api(): void {
		$calls = 0;

		$check = Providers::checker(
			array(
				Providers::WORDPRESS  => static fn(): array => array( 'logged_in' => false, 'roles' => array() ),
				Providers::MEMBERSHIP => static function () use ( &$calls ) {
					++$calls;
					return array( 'is_member' => false );
				},
				Providers::SEGMENTS   => static fn(): array => array(),
			)
		);

		$this->assertTrue( $check( 'iugo_esac_wordpress', 'logged_out' ) );
		$this->assertSame( 0, $calls );
	}

	// -----------------------------------------------------------------
	// The registry: custom providers and the filter
	// -----------------------------------------------------------------

	/** A checker whose built-in resolvers must never be reached by a custom provider. */
	private function builtInChecker( ?int &$calls = null ): callable {
		$calls = 0;

		$count = static function () use ( &$calls ): array {
			++$calls;
			return array();
		};

		return Providers::checker(
			array(
				Providers::WORDPRESS  => $count,
				Providers::MEMBERSHIP => $count,
				Providers::SEGMENTS   => $count,
			)
		);
	}

	/**
	 * The filter the condition-sets docblock has always promised. Without it a
	 * site that registered a provider got conditions that always denied, with
	 * no way to answer them.
	 */
	#[Test]
	public function a_custom_provider_is_answered_through_the_filter(): void {
		$seen = array();

		Agend_Test_WP::$filters['agend_content_access_check_condition'] =
			static function ( $result, $provider, $value ) use ( &$seen ) {
				$seen[] = array( $provider, $value );

				return 'company_access' === $provider ? 'company_administrator' === $value : $result;
			};

		$check = $this->builtInChecker( $calls );

		$this->assertTrue( $check( 'company_access', 'company_administrator' ) );
		$this->assertFalse( $check( 'company_access', 'someone_else' ) );
		$this->assertSame(
			array( array( 'company_access', 'company_administrator' ), array( 'company_access', 'someone_else' ) ),
			$seen,
			'the filter receives the provider as written, and the value'
		);
		$this->assertSame( 0, $calls, 'a custom provider must not trigger a built-in fact lookup' );
	}

	/**
	 * Unknown denies. A provider nobody answers must read as UNKNOWN, which the
	 * engine denies and reports, never as true or false.
	 */
	#[Test]
	public function a_custom_provider_with_no_answer_is_unknown(): void {
		$check = $this->builtInChecker();

		$this->assertNull( $check( 'company_access', 'company_administrator' ) );

		$result = Conditions::evaluate(
			array( 'company_access:company_administrator' ),
			$check
		);

		$this->assertFalse( $result['passes'] );
		$this->assertSame( array( 'company_access:company_administrator' ), $result['unknown'] );
	}

	/**
	 * Only a real bool is an answer. A truthy string or integer from a careless
	 * callback must not become a pass.
	 */
	#[Test]
	public function a_non_boolean_filter_result_is_unknown(): void {
		$check = $this->builtInChecker();

		foreach ( array( 1, 0, 'yes', '', array( true ), new \stdClass() ) as $answer ) {
			Agend_Test_WP::$filters['agend_content_access_check_condition'] =
				static fn( $result, $provider, $value ) => $answer;

			$this->assertNull( $check( 'company_access', 'x' ), 'non-bool answer: ' . gettype( $answer ) );
		}
	}

	/**
	 * Built-ins and legacy aliases are explicit branches, not filter traffic. A
	 * site filter must not be able to override what `agend_membership` means.
	 */
	#[Test]
	public function built_in_providers_and_aliases_never_reach_the_filter(): void {
		$consulted = 0;

		Agend_Test_WP::$filters['agend_content_access_check_condition'] =
			static function ( $result ) use ( &$consulted ) {
				++$consulted;
				return true;
			};

		$check = Providers::checker(
			array(
				Providers::WORDPRESS  => static fn(): array => array( 'logged_in' => false, 'roles' => array() ),
				Providers::MEMBERSHIP => static fn(): array => array( 'is_member' => false, 'tier_ids' => array(), 'tier_slugs' => array() ),
				Providers::SEGMENTS   => static fn(): array => array(),
			)
		);

		$this->assertFalse( $check( 'agend_wordpress', 'logged_in' ) );
		$this->assertFalse( $check( 'iugo_esac_wordpress', 'logged_in' ) );
		$this->assertFalse( $check( 'agend_membership', 'is_member' ) );
		$this->assertFalse( $check( 'upbeat_is_member', 'is_member' ) );
		$this->assertFalse( $check( 'agend_segments', 'vic' ) );
		$this->assertFalse( $check( 'iugo_esac_segments', 'vic' ) );
		$this->assertSame( 0, $consulted );
	}

	/**
	 * An absent source is not evidence that the visitor holds nothing. Treating
	 * a missing WordPress resolver as an empty user would answer `logged_out`
	 * with true.
	 */
	#[Test]
	public function a_built_in_with_no_resolver_is_unknown_not_empty(): void {
		$check = Providers::checker( array() );

		$this->assertNull( $check( 'agend_wordpress', 'logged_out' ) );
		$this->assertNull( $check( 'agend_membership', 'is_member' ) );
		$this->assertNull( $check( 'agend_segments', 'vic' ) );
		$this->assertNull( $check( 'iugo_esac_wordpress', 'logged_out' ) );
	}

	#[Test]
	public function segment_conditions_match_by_slug(): void {
		$this->assertTrue( Providers::check_segments( 'vic-fellows', array( 'vic-fellows', 'trainees' ) ) );
		$this->assertFalse( Providers::check_segments( 'qld-fellows', array( 'vic-fellows' ) ) );
	}
}
