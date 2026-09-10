<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Policy;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';

/**
 * The policy shape rules, as WordPress enforces them.
 *
 * These must stay in lockstep with the database CHECK and with
 * `parseAccessPolicy()` in `@agend/cms`. Three implementations of one rule set
 * is a real duplication risk, accepted because each sits on a different side of
 * a trust boundary. Where they disagree, the database is right.
 */
#[CoversClass( Agend_Content_Access_Policy::class )]
final class PolicyTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';

	#[Test]
	public function the_two_no_argument_modes_round_trip(): void {
		$this->assertSame(
			array( 'mode' => 'public' ),
			Agend_Content_Access_Policy::parse( array( 'mode' => 'public' ) )
		);
		$this->assertSame(
			array( 'mode' => 'active_member' ),
			Agend_Content_Access_Policy::parse( array( 'mode' => 'active_member' ) )
		);
	}

	#[Test]
	public function selected_tiers_round_trips_and_keeps_a_list(): void {
		$parsed = Agend_Content_Access_Policy::parse(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, self::TIER_B ) )
		);

		$this->assertSame( 'selected_tiers', $parsed['mode'] );
		$this->assertSame( array( self::TIER_A, self::TIER_B ), $parsed['tier_ids'] );
		// A list, not a map. json_encode on a map emits an object and the
		// gateway rejects the shape.
		$this->assertStringStartsWith( '[', (string) json_encode( $parsed['tier_ids'] ) );
	}

	/** Each of these, if accepted, would reach the gateway and be rejected there. */
	#[Test]
	#[DataProvider( 'invalidPolicies' )]
	public function invalid_shapes_are_rejected( $value ): void {
		$this->assertNull( Agend_Content_Access_Policy::parse( $value ) );
	}

	public static function invalidPolicies(): array {
		return array(
			'unknown mode'            => array( array( 'mode' => 'everyone' ) ),
			'extra top-level key'     => array( array( 'mode' => 'public', 'also' => 'sneaky' ) ),
			'tiers with extra key'    => array( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ), 'x' => 1 ) ),
			'empty tier list'         => array( array( 'mode' => 'selected_tiers', 'tier_ids' => array() ) ),
			'missing tier list'       => array( array( 'mode' => 'selected_tiers' ) ),
			'tier list is a map'      => array( array( 'mode' => 'selected_tiers', 'tier_ids' => array( 'a' => self::TIER_A ) ) ),
			'non-uuid entry'          => array( array( 'mode' => 'selected_tiers', 'tier_ids' => array( 'nope' ) ) ),
			'numeric entry'           => array( array( 'mode' => 'selected_tiers', 'tier_ids' => array( 123 ) ) ),
			'null entry'              => array( array( 'mode' => 'selected_tiers', 'tier_ids' => array( null ) ) ),
			'empty array'             => array( array() ),
			'a string'                => array( 'public' ),
			'null'                    => array( null ),
			'no mode key'             => array( array( 'tier_ids' => array( self::TIER_A ) ) ),
		);
	}

	#[Test]
	public function uppercase_uuids_are_accepted(): void {
		$parsed = Agend_Content_Access_Policy::parse(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE' ) )
		);

		$this->assertNotNull( $parsed );
	}

	// -----------------------------------------------------------------------
	// Editor input
	// -----------------------------------------------------------------------

	#[Test]
	public function editor_input_builds_the_simple_modes(): void {
		foreach ( array( 'public', 'active_member' ) as $mode ) {
			$result = Agend_Content_Access_Policy::from_input( $mode, array() );

			$this->assertNull( $result['error'] );
			$this->assertSame( array( 'mode' => $mode ), $result['policy'] );
		}
	}

	/**
	 * The most dangerous input this panel can receive. Read charitably it means
	 * "restrict to nobody"; read carelessly it becomes "restrict to nothing",
	 * which is public. It must be refused, and the previous policy kept.
	 */
	#[Test]
	public function selected_plans_with_nothing_selected_is_refused(): void {
		$result = Agend_Content_Access_Policy::from_input( 'selected_tiers', array() );

		$this->assertNull( $result['policy'] );
		$this->assertStringContainsString( 'at least one membership plan', (string) $result['error'] );
		$this->assertStringContainsString( 'previous setting has been kept', (string) $result['error'] );
	}

	#[Test]
	public function selected_plans_with_only_junk_is_refused_not_silently_emptied(): void {
		$result = Agend_Content_Access_Policy::from_input(
			'selected_tiers',
			array( '', 'not-a-uuid', '   ' )
		);

		$this->assertNull( $result['policy'] );
		$this->assertNotNull( $result['error'] );
	}

	#[Test]
	public function editor_input_drops_junk_but_keeps_valid_ids(): void {
		$result = Agend_Content_Access_Policy::from_input(
			'selected_tiers',
			array( self::TIER_A, 'not-a-uuid', '', self::TIER_B )
		);

		$this->assertNull( $result['error'] );
		$this->assertSame( array( self::TIER_A, self::TIER_B ), $result['policy']['tier_ids'] );
	}

	#[Test]
	public function editor_input_deduplicates_and_trims(): void {
		$result = Agend_Content_Access_Policy::from_input(
			'selected_tiers',
			array( self::TIER_A, '  ' . self::TIER_A . '  ', self::TIER_A )
		);

		$this->assertSame( array( self::TIER_A ), $result['policy']['tier_ids'] );
	}

	#[Test]
	public function an_unrecognised_mode_is_refused(): void {
		$result = Agend_Content_Access_Policy::from_input( 'everyone', array() );

		$this->assertNull( $result['policy'] );
		$this->assertNotNull( $result['error'] );
	}

	#[Test]
	public function the_stored_value_contains_tier_ids_only_no_names(): void {
		$result = Agend_Content_Access_Policy::from_input( 'selected_tiers', array( self::TIER_A ) );

		// Renaming a plan in Agend must not change any stored policy, which
		// only holds if nothing but the id is persisted.
		$this->assertSame( array( 'mode', 'tier_ids' ), array_keys( $result['policy'] ) );
	}

	#[Test]
	public function tier_ids_returns_an_empty_list_for_non_tier_modes(): void {
		$this->assertSame( array(), Agend_Content_Access_Policy::tier_ids( array( 'mode' => 'public' ) ) );
		$this->assertSame( array(), Agend_Content_Access_Policy::tier_ids( null ) );
		$this->assertSame(
			array( self::TIER_A ),
			Agend_Content_Access_Policy::tier_ids(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) )
			)
		);
	}

	// -----------------------------------------------------------------------
	// Fragment values (editor-neutral seam)
	// -----------------------------------------------------------------------

	#[Test]
	public function fragment_values_with_no_mode_or_inherit_defer_to_the_document(): void {
		$this->assertNull( Agend_Content_Access_Policy::from_fragment_values( '', array() ) );
		$this->assertNull( Agend_Content_Access_Policy::from_fragment_values( 'inherit', array() ) );
	}

	#[Test]
	public function fragment_values_all_active_members_reads_as_a_members_policy(): void {
		$this->assertSame(
			array( 'mode' => 'active_member' ),
			Agend_Content_Access_Policy::from_fragment_values( 'active_member', array() )
		);
	}

	#[Test]
	public function fragment_values_selected_tiers_filters_trims_dedupes_and_reindexes(): void {
		$policy = Agend_Content_Access_Policy::from_fragment_values(
			'selected_tiers',
			array( ' ' . self::TIER_A . ' ', 'not-a-uuid', 123, self::TIER_A, self::TIER_B )
		);

		$this->assertSame( 'selected_tiers', $policy['mode'] );
		$this->assertSame( array( self::TIER_A, self::TIER_B ), $policy['tier_ids'] );
		$this->assertSame( array( 0, 1 ), array_keys( $policy['tier_ids'] ) );
	}

	/**
	 * The exact case the previous implementation got wrong. "Selected plans"
	 * with nothing selected is a restriction nobody satisfies, not an absent
	 * one.
	 */
	#[Test]
	public function fragment_values_selected_tiers_with_nothing_selected_still_restricts(): void {
		$policy = Agend_Content_Access_Policy::from_fragment_values( 'selected_tiers', array() );

		$this->assertNotNull( $policy, 'an empty selection must not read as "no policy"' );
		$this->assertSame(
			array(
				'mode'     => 'selected_tiers',
				'tier_ids' => array(),
			),
			$policy
		);
	}

	#[Test]
	public function fragment_values_an_unrecognised_mode_restricts_rather_than_being_ignored(): void {
		$policy = Agend_Content_Access_Policy::from_fragment_values( 'everyone', array() );

		$this->assertSame( array( 'mode' => 'active_member' ), $policy );
	}

	#[Test]
	public function every_mode_has_a_label_and_an_unknown_mode_does_not_read_as_public(): void {
		$this->assertSame( 'Public', Agend_Content_Access_Policy::label( 'public' ) );
		$this->assertSame( 'All active members', Agend_Content_Access_Policy::label( 'active_member' ) );
		$this->assertSame( 'Selected membership plans', Agend_Content_Access_Policy::label( 'selected_tiers' ) );
		$this->assertSame( 'Not set', Agend_Content_Access_Policy::label( 'whatever' ) );
	}
}
