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

	#[Test]
	public function every_mode_has_a_label_and_an_unknown_mode_does_not_read_as_public(): void {
		$this->assertSame( 'Public', Agend_Content_Access_Policy::label( 'public' ) );
		$this->assertSame( 'All active members', Agend_Content_Access_Policy::label( 'active_member' ) );
		$this->assertSame( 'Selected membership plans', Agend_Content_Access_Policy::label( 'selected_tiers' ) );
		$this->assertSame( 'Not set', Agend_Content_Access_Policy::label( 'whatever' ) );
	}

	// -----------------------------------------------------------------------
	// Stored state: unset is public, unreadable is not
	// -----------------------------------------------------------------------

	private function store( int $post_id, $value ): void {
		$GLOBALS['agend_test_post_meta'][ $post_id ] = array( Agend_Content_Access_Policy::META_KEY => $value );
	}

	/**
	 * Stored values this version cannot read. Each one was served as PUBLIC by
	 * 0.1.0, because the reader returned null for it exactly as it does for a
	 * post with nothing stored.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unreadableValues(): array {
		return array(
			'a mode the gateway has and this plugin does not' => array( array( 'mode' => 'authenticated' ) ),
			'segments, written by 0.2.0'                      => array(
				array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) ),
			),
			'selected entitlements, written by 0.2.0'         => array(
				array( 'mode' => 'selected_entitlements', 'entitlement_ids' => array( self::TIER_A ) ),
			),
			'a plan id that is not a uuid'                    => array(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( 'not-a-uuid' ) ),
			),
			'plans with nothing selected'                     => array(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array() ),
			),
			'an extra top level key on a valid mode'          => array(
				array( 'mode' => 'public', 'note' => 'smuggled' ),
			),
			'an extra top level key on selected plans'        => array(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ), 'extra' => true ),
			),
			'an empty array'                                  => array( array() ),
			'json text instead of an array'                   => array( '{"mode":"public"}' ),
			'a bare string'                                   => array( 'public' ),
		);
	}

	#[Test]
	public function a_post_with_nothing_stored_is_absent_and_enforced_as_public(): void {
		$this->assertSame( Agend_Content_Access_Policy::STORED_ABSENT, Agend_Content_Access_Policy::stored_state( 7 ) );
		$this->assertNull( Agend_Content_Access_Policy::enforced_for_post( 7 ) );
	}

	/** WordPress reports a cleared value as an empty string; that is still unset. */
	#[Test]
	public function an_empty_string_is_absent_not_invalid(): void {
		$this->store( 7, '' );

		$this->assertSame( Agend_Content_Access_Policy::STORED_ABSENT, Agend_Content_Access_Policy::stored_state( 7 ) );
		$this->assertNull( Agend_Content_Access_Policy::enforced_for_post( 7 ) );
	}

	#[Test]
	public function a_readable_policy_is_valid_and_enforced_as_stored(): void {
		$policy = array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) );
		$this->store( 7, $policy );

		$this->assertSame( Agend_Content_Access_Policy::STORED_VALID, Agend_Content_Access_Policy::stored_state( 7 ) );
		$this->assertSame( $policy, Agend_Content_Access_Policy::enforced_for_post( 7 ) );

		$this->store( 8, array( 'mode' => 'public' ) );

		$this->assertSame( Agend_Content_Access_Policy::STORED_VALID, Agend_Content_Access_Policy::stored_state( 8 ) );
		$this->assertSame( array( 'mode' => 'public' ), Agend_Content_Access_Policy::enforced_for_post( 8 ) );
	}

	#[Test]
	#[DataProvider( 'unreadableValues' )]
	public function an_unreadable_stored_policy_is_invalid_and_enforced_as_nobody( $value ): void {
		$this->store( 7, $value );

		$this->assertSame( Agend_Content_Access_Policy::STORED_INVALID, Agend_Content_Access_Policy::stored_state( 7 ) );
		$this->assertSame( Agend_Content_Access_Policy::UNSATISFIABLE, Agend_Content_Access_Policy::enforced_for_post( 7 ) );
	}

	/**
	 * The editor depends on this: it shows "not set" for an unreadable policy so
	 * an audience can be chosen again. The fix must not change that reading, only
	 * stop anything else from using it.
	 */
	#[Test]
	#[DataProvider( 'unreadableValues' )]
	public function the_editor_reading_of_an_unreadable_policy_is_still_null( $value ): void {
		$this->store( 7, $value );

		$this->assertNull( Agend_Content_Access_Policy::get_for_post( 7 ) );
	}

	/** The nobody policy must not be something the editor can save by accident. */
	#[Test]
	public function the_unsatisfiable_policy_is_not_itself_a_savable_policy(): void {
		$this->assertNull( Agend_Content_Access_Policy::parse( Agend_Content_Access_Policy::UNSATISFIABLE ) );
		$this->assertFalse( Agend_Content_Access_Policy::save_for_post( 7, Agend_Content_Access_Policy::UNSATISFIABLE ) );
		$this->assertSame( Agend_Content_Access_Policy::STORED_ABSENT, Agend_Content_Access_Policy::stored_state( 7 ) );
	}

	#[Test]
	public function the_unsatisfiable_policy_is_the_gateways_representation_of_nobody(): void {
		$this->assertSame(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array() ),
			Agend_Content_Access_Policy::UNSATISFIABLE
		);
	}
}
