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
	private const TIER_C = '33333333-3333-3333-3333-333333333333';
	private const SEG_1  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const SEG_2  = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';
	private const SEG_3  = 'cccccccc-cccc-4ccc-8ccc-ccccccccccc3';
	private const ENT_A  = 'directory.access';
	private const ENT_B  = 'journal';
	private const ENT_C  = 'events.early_bird';

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
	// Segments and selected entitlements: shape
	// -----------------------------------------------------------------------

	#[Test]
	public function segments_and_entitlements_round_trip_as_lists(): void {
		$segments = Agend_Content_Access_Policy::parse(
			array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1, self::SEG_2 ) )
		);
		$keys     = Agend_Content_Access_Policy::parse(
			array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A, self::ENT_B ) )
		);

		$this->assertSame( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1, self::SEG_2 ) ), $segments );
		$this->assertSame( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A, self::ENT_B ) ), $keys );
		$this->assertStringStartsWith( '[', (string) json_encode( $segments['segment_ids'] ) );
		$this->assertStringStartsWith( '[', (string) json_encode( $keys['entitlement_keys'] ) );
	}

	/** Each of these, if accepted, would reach the gateway and be rejected there. */
	#[Test]
	#[DataProvider( 'invalidSetPolicies' )]
	public function invalid_segment_and_entitlement_shapes_are_rejected( $value ): void {
		$this->assertNull( Agend_Content_Access_Policy::parse( $value ) );
	}

	public static function invalidSetPolicies(): array {
		return array(
			'segments: empty list'                   => array( array( 'mode' => 'segments', 'segment_ids' => array() ) ),
			'segments: missing list'                 => array( array( 'mode' => 'segments' ) ),
			'segments: list is a map'                => array( array( 'mode' => 'segments', 'segment_ids' => array( 'a' => self::SEG_1 ) ) ),
			'segments: sparse list'                  => array( array( 'mode' => 'segments', 'segment_ids' => array( 1 => self::SEG_1 ) ) ),
			'segments: extra key'                    => array( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1 ), 'x' => 1 ) ),
			'segments: the tier key instead'         => array( array( 'mode' => 'segments', 'tier_ids' => array( self::SEG_1 ) ) ),
			'segments: both list keys'               => array( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1 ), 'tier_ids' => array( self::TIER_A ) ) ),
			'segments: bad uuid'                     => array( array( 'mode' => 'segments', 'segment_ids' => array( 'vic-fellows' ) ) ),
			'segments: one bad among good'           => array( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1, 'nope' ) ) ),
			'segments: uuid with trailing newline'   => array( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1 . "\n" ) ) ),
			'segments: numeric entry'                => array( array( 'mode' => 'segments', 'segment_ids' => array( 123 ) ) ),
			'segments: null entry'                   => array( array( 'mode' => 'segments', 'segment_ids' => array( null ) ) ),
			'segments: a string, not a list'         => array( array( 'mode' => 'segments', 'segment_ids' => self::SEG_1 ) ),
			'entitlements: empty list'               => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array() ) ),
			'entitlements: missing list'             => array( array( 'mode' => 'selected_entitlements' ) ),
			'entitlements: list is a map'            => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'a' => self::ENT_A ) ) ),
			'entitlements: extra key'                => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A ), 'x' => 1 ) ),
			'entitlements: the segment key instead'  => array( array( 'mode' => 'selected_entitlements', 'segment_ids' => array( self::ENT_A ) ) ),
			'entitlements: uppercase'                => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'Directory.Access' ) ) ),
			'entitlements: hyphen'                   => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'directory-access' ) ) ),
			'entitlements: trailing newline'         => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A . "\n" ) ) ),
			'entitlements: leading digit'            => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( '1directory' ) ) ),
			'entitlements: trailing dot'             => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'directory.' ) ) ),
			'entitlements: two characters'           => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'ab' ) ) ),
			'entitlements: 65 characters'            => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'a' . str_repeat( 'b', 63 ) . 'c' ) ) ),
			'entitlements: one bad among good'       => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A, 'No Good' ) ) ),
			'entitlements: numeric entry'            => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 123 ) ) ),
			'entitlements: a uuid is not a key'      => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::SEG_1 ) ) ),
			'unmodelled authenticated arm'           => array( array( 'mode' => 'authenticated' ) ),
		);
	}

	/** The gate key shape's boundaries: 3 to 64 characters, lowercase, dots and underscores inside. */
	#[Test]
	#[DataProvider( 'validGateKeys' )]
	public function boundary_gate_keys_are_accepted( string $key ): void {
		$this->assertNotNull(
			Agend_Content_Access_Policy::parse( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( $key ) ) )
		);
	}

	public static function validGateKeys(): array {
		return array(
			'shortest, three characters' => array( 'abc' ),
			'longest, 64 characters'     => array( 'a' . str_repeat( 'b', 62 ) . 'c' ),
			'dots and underscores'       => array( 'dir_ectory.access_2' ),
			'digits inside'              => array( 'tier2.access' ),
			'ends in a digit'            => array( 'level9' ),
		);
	}

	/** A trailing newline passes an unanchored `$`, so the tier arm is pinned too. */
	#[Test]
	public function a_tier_id_with_a_trailing_newline_is_rejected(): void {
		$this->assertNull(
			Agend_Content_Access_Policy::parse( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A . "\n" ) ) )
		);
	}

	#[Test]
	public function the_entitlement_key_pattern_matches_the_one_the_facts_source_uses(): void {
		require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';

		// Two copies of one rule: a key the facts source keeps that no policy
		// can name is harmless, but a key a policy can name that the facts
		// source drops would deny a holder silently.
		$this->assertSame(
			Agend_Content_Access_Policy::ENTITLEMENT_KEY_PATTERN,
			\Agend_Content_Access_Viewer_Facts::ENTITLEMENT_KEY_PATTERN
		);
	}

	#[Test]
	public function the_modes_are_in_the_order_the_editor_presents_them(): void {
		$this->assertSame(
			array( 'public', 'active_member', 'selected_tiers', 'segments', 'selected_entitlements' ),
			Agend_Content_Access_Policy::MODES
		);
	}

	#[Test]
	public function the_new_modes_have_labels(): void {
		$this->assertSame( 'Selected segments', Agend_Content_Access_Policy::label( 'segments' ) );
		$this->assertSame( 'Selected entitlements', Agend_Content_Access_Policy::label( 'selected_entitlements' ) );
	}

	#[Test]
	public function the_accessors_return_only_their_own_mode_list(): void {
		$segments = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1 ) );
		$keys     = array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A ) );
		$tiers    = array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) );

		$this->assertSame( array( self::SEG_1 ), Agend_Content_Access_Policy::segment_ids( $segments ) );
		$this->assertSame( array( self::ENT_A ), Agend_Content_Access_Policy::entitlement_keys( $keys ) );

		foreach ( array( $keys, $tiers, array( 'mode' => 'public' ), null ) as $other ) {
			$this->assertSame( array(), Agend_Content_Access_Policy::segment_ids( $other ) );
		}
		foreach ( array( $segments, $tiers, array( 'mode' => 'active_member' ), null ) as $other ) {
			$this->assertSame( array(), Agend_Content_Access_Policy::entitlement_keys( $other ) );
		}
		foreach ( array( $segments, $keys ) as $other ) {
			$this->assertSame( array(), Agend_Content_Access_Policy::tier_ids( $other ) );
		}
	}

	#[Test]
	public function only_a_segments_policy_with_something_to_match_needs_segments(): void {
		$this->assertTrue( Agend_Content_Access_Policy::needs_segments( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1 ) ) ) );
		// A disjoint intersection can never be satisfied, so nothing is worth asking.
		$this->assertFalse( Agend_Content_Access_Policy::needs_segments( array( 'mode' => 'segments', 'segment_ids' => array() ) ) );
		$this->assertFalse( Agend_Content_Access_Policy::needs_segments( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ) ) );
		$this->assertFalse( Agend_Content_Access_Policy::needs_segments( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A ) ) ) );
		$this->assertFalse( Agend_Content_Access_Policy::needs_segments( array( 'mode' => 'active_member' ) ) );
		$this->assertFalse( Agend_Content_Access_Policy::needs_segments( null ) );
	}

	// -----------------------------------------------------------------------
	// Segments and selected entitlements: editor input
	// -----------------------------------------------------------------------

	#[Test]
	public function the_two_argument_call_still_builds_every_older_mode(): void {
		$result = Agend_Content_Access_Policy::from_input( 'selected_tiers', array( self::TIER_A ) );

		$this->assertSame( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ), $result['policy'] );
	}

	#[Test]
	public function editor_input_builds_segments_and_entitlements_from_the_third_argument(): void {
		$segments = Agend_Content_Access_Policy::from_input(
			'segments',
			array(),
			array( 'segment_ids' => array( self::SEG_1, 'junk', '  ' . self::SEG_2 . ' ', self::SEG_1 ) )
		);
		$keys     = Agend_Content_Access_Policy::from_input(
			'selected_entitlements',
			array(),
			array( 'entitlement_keys' => array( self::ENT_A, 'Not A Key', self::ENT_B, self::ENT_A ) )
		);

		$this->assertNull( $segments['error'] );
		$this->assertSame( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1, self::SEG_2 ) ), $segments['policy'] );
		$this->assertNull( $keys['error'] );
		$this->assertSame( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A, self::ENT_B ) ), $keys['policy'] );
	}

	#[Test]
	public function each_selection_is_read_only_for_its_own_mode(): void {
		// Tiers submitted alongside the segments mode must not satisfy it, or a
		// stale tier selection would silently become a segment policy.
		$result = Agend_Content_Access_Policy::from_input( 'segments', array( self::TIER_A ), array() );

		$this->assertNull( $result['policy'] );
		$this->assertNotNull( $result['error'] );

		$result = Agend_Content_Access_Policy::from_input(
			'selected_entitlements',
			array( self::TIER_A ),
			array( 'segment_ids' => array( self::SEG_1 ) )
		);

		$this->assertNull( $result['policy'] );
	}

	#[Test]
	#[DataProvider( 'emptySelections' )]
	public function a_set_mode_with_nothing_selected_is_refused_with_mode_specific_copy( string $mode, array $selections, string $expected ): void {
		$result = Agend_Content_Access_Policy::from_input( $mode, array(), $selections );

		$this->assertNull( $result['policy'] );
		$this->assertStringContainsString( $expected, (string) $result['error'] );
		$this->assertStringContainsString( 'previous setting has been kept', (string) $result['error'] );
	}

	public static function emptySelections(): array {
		return array(
			'segments, no key'            => array( 'segments', array(), 'at least one segment' ),
			'segments, empty'             => array( 'segments', array( 'segment_ids' => array() ), 'at least one segment' ),
			'segments, only junk'         => array( 'segments', array( 'segment_ids' => array( '', 'vic-fellows' ) ), 'at least one segment' ),
			'segments, not a list'        => array( 'segments', array( 'segment_ids' => self::SEG_1 ), 'at least one segment' ),
			'entitlements, no key'        => array( 'selected_entitlements', array(), 'at least one entitlement' ),
			'entitlements, empty'         => array( 'selected_entitlements', array( 'entitlement_keys' => array() ), 'at least one entitlement' ),
			'entitlements, only junk'     => array( 'selected_entitlements', array( 'entitlement_keys' => array( 'Bad Key', '' ) ), 'at least one entitlement' ),
		);
	}

	// -----------------------------------------------------------------------
	// Segments and selected entitlements: fragment settings
	// -----------------------------------------------------------------------

	#[Test]
	public function fragment_settings_read_segments_and_entitlements(): void {
		$this->assertSame(
			array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1, self::SEG_2 ) ),
			Agend_Content_Access_Policy::from_fragment_settings(
				array(
					'agend_access_mode'        => 'segments',
					'agend_access_segment_ids' => array( self::SEG_1, 'junk', self::SEG_2, self::SEG_1 ),
				)
			)
		);
		$this->assertSame(
			array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A, self::ENT_B ) ),
			Agend_Content_Access_Policy::from_fragment_settings(
				array(
					'agend_access_mode'             => 'selected_entitlements',
					'agend_access_entitlement_keys' => array( self::ENT_A, 'Junk Key', self::ENT_B ),
				)
			)
		);
	}

	/** An editor who chose the mode and selected nothing valid restricted the element. Never "no restriction". */
	#[Test]
	public function a_chosen_set_mode_with_nothing_valid_selected_is_a_policy_nobody_satisfies(): void {
		$cases = array(
			array( 'segments', 'segment_ids', 'agend_access_segment_ids' ),
			array( 'selected_entitlements', 'entitlement_keys', 'agend_access_entitlement_keys' ),
		);

		foreach ( $cases as list( $mode, $list_key, $settings_key ) ) {
			$empty = array( 'mode' => $mode, $list_key => array() );

			$this->assertSame( $empty, Agend_Content_Access_Policy::from_fragment_settings( array( 'agend_access_mode' => $mode ) ), $mode . ': key missing' );
			$this->assertSame( $empty, Agend_Content_Access_Policy::from_fragment_settings( array( 'agend_access_mode' => $mode, $settings_key => array() ) ), $mode . ': empty' );
			$this->assertSame( $empty, Agend_Content_Access_Policy::from_fragment_settings( array( 'agend_access_mode' => $mode, $settings_key => array( 'Not Valid', '' ) ) ), $mode . ': junk' );
			$this->assertSame( $empty, Agend_Content_Access_Policy::from_fragment_settings( array( 'agend_access_mode' => $mode, $settings_key => 'not-a-list' ) ), $mode . ': not a list' );
		}
	}

	#[Test]
	public function the_tier_list_does_not_satisfy_a_segments_fragment(): void {
		$this->assertSame(
			array( 'mode' => 'segments', 'segment_ids' => array() ),
			Agend_Content_Access_Policy::from_fragment_settings(
				array(
					'agend_access_mode'      => 'segments',
					'agend_access_tier_ids'  => array( self::TIER_A ),
				)
			)
		);
	}

	// -----------------------------------------------------------------------
	// Composition: fragment against document
	// -----------------------------------------------------------------------

	/**
	 * Every ordered (document, fragment) pair of {null, public, active_member,
	 * selected_tiers, segments, selected_entitlements}, with the expected value
	 * written out from `effectivePolicy()` in `@agend/cms` `policy/fragments.ts`
	 * and NOT derived from the implementation under test.
	 *
	 * Reading the gateway's rules, with ranks public 0, active_member 1 and the
	 * three set-modes 2:
	 *   1. a fragment that inherits yields the document;
	 *   2. the same set-mode on both sides intersects, fragment order kept;
	 *   3. two different set-modes, or active_member against segments or
	 *      entitlements either way, collapse to empty selected_tiers;
	 *   4. otherwise the higher rank wins and a tie keeps the document.
	 * A null document is the public document, so a fragment simply applies.
	 */
	#[Test]
	#[DataProvider( 'composition' )]
	public function effective_policy_matches_the_gateway( ?array $document, ?array $fragment, ?array $expected ): void {
		$this->assertSame( $expected, Agend_Content_Access_Policy::effective_policy( $document, $fragment ) );
	}

	public static function composition(): array {
		$public   = array( 'mode' => 'public' );
		$members  = array( 'mode' => 'active_member' );
		$tiers    = array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, self::TIER_B ) );
		$segments = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1, self::SEG_2 ) );
		$keys     = array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A, self::ENT_B ) );
		$nobody   = array( 'mode' => 'selected_tiers', 'tier_ids' => array() );

		// One row per ordered pair. Rows with identical lists on both sides
		// check the identity case; the loop below adds the intersecting ones.
		$cases = array(
			'null, null'                       => array( null, null, null ),
			'null, public'                     => array( null, $public, $public ),
			'null, members'                    => array( null, $members, $members ),
			'null, tiers'                      => array( null, $tiers, $tiers ),
			'null, segments'                   => array( null, $segments, $segments ),
			'null, entitlements'               => array( null, $keys, $keys ),

			'public, null'                     => array( $public, null, $public ),
			'public, public'                   => array( $public, $public, $public ),
			'public, members'                  => array( $public, $members, $members ),
			'public, tiers'                    => array( $public, $tiers, $tiers ),
			'public, segments'                 => array( $public, $segments, $segments ),
			'public, entitlements'             => array( $public, $keys, $keys ),

			'members, null'                    => array( $members, null, $members ),
			'members, public'                  => array( $members, $public, $members ),
			'members, members'                 => array( $members, $members, $members ),
			'members, tiers'                   => array( $members, $tiers, $tiers ),
			'members, segments'                => array( $members, $segments, $nobody ),
			'members, entitlements'            => array( $members, $keys, $nobody ),

			'tiers, null'                      => array( $tiers, null, $tiers ),
			'tiers, public'                    => array( $tiers, $public, $tiers ),
			'tiers, members'                   => array( $tiers, $members, $tiers ),
			'tiers, tiers (identical)'         => array( $tiers, $tiers, $tiers ),
			'tiers, segments'                  => array( $tiers, $segments, $nobody ),
			'tiers, entitlements'              => array( $tiers, $keys, $nobody ),

			'segments, null'                   => array( $segments, null, $segments ),
			'segments, public'                 => array( $segments, $public, $segments ),
			'segments, members'                => array( $segments, $members, $nobody ),
			'segments, tiers'                  => array( $segments, $tiers, $nobody ),
			'segments, segments (identical)'   => array( $segments, $segments, $segments ),
			'segments, entitlements'           => array( $segments, $keys, $nobody ),

			'entitlements, null'               => array( $keys, null, $keys ),
			'entitlements, public'             => array( $keys, $public, $keys ),
			'entitlements, members'            => array( $keys, $members, $nobody ),
			'entitlements, tiers'              => array( $keys, $tiers, $nobody ),
			'entitlements, segments'           => array( $keys, $segments, $nobody ),
			'entitlements, entitlements (identical)' => array( $keys, $keys, $keys ),
		);

		// Same-arm pairs: one intersecting and one disjoint case each, plus a
		// subset and a fragment-order case. The gateway filters the FRAGMENT's
		// list by the document's, so the fragment's order is the result's.
		$arms = array(
			array( 'selected_tiers', 'tier_ids', array( self::TIER_A, self::TIER_B ), array( self::TIER_B, self::TIER_C ), array( self::TIER_C ) ),
			array( 'segments', 'segment_ids', array( self::SEG_1, self::SEG_2 ), array( self::SEG_2, self::SEG_3 ), array( self::SEG_3 ) ),
			array( 'selected_entitlements', 'entitlement_keys', array( self::ENT_A, self::ENT_B ), array( self::ENT_B, self::ENT_C ), array( self::ENT_C ) ),
		);

		foreach ( $arms as list( $mode, $key, $both, $overlap, $outside ) ) {
			$document = array( 'mode' => $mode, $key => $both );

			$cases[ $mode . ': overlapping lists keep the shared entry' ] = array(
				$document,
				array( 'mode' => $mode, $key => $overlap ),
				array( 'mode' => $mode, $key => array( $both[1] ) ),
			);
			$cases[ $mode . ': disjoint lists collapse to an empty list of the same mode' ] = array(
				$document,
				array( 'mode' => $mode, $key => $outside ),
				array( 'mode' => $mode, $key => array() ),
			);
			$cases[ $mode . ': a fragment subset narrows the document' ] = array(
				$document,
				array( 'mode' => $mode, $key => array( $both[0] ) ),
				array( 'mode' => $mode, $key => array( $both[0] ) ),
			);
			$cases[ $mode . ': a fragment superset cannot broaden the document' ] = array(
				$document,
				array( 'mode' => $mode, $key => array( $both[0], $both[1], $outside[0] ) ),
				array( 'mode' => $mode, $key => array( $both[0], $both[1] ) ),
			);
			$cases[ $mode . ': the fragment order is kept' ] = array(
				$document,
				array( 'mode' => $mode, $key => array( $both[1], $both[0] ) ),
				array( 'mode' => $mode, $key => array( $both[1], $both[0] ) ),
			);
		}

		return $cases;
	}

	/** Intersecting two lists of one set-mode must not leave the arm, so a segments pair never becomes tiers. */
	#[Test]
	public function a_disjoint_same_arm_intersection_stays_in_its_own_arm(): void {
		$result = Agend_Content_Access_Policy::effective_policy(
			array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1 ) ),
			array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_2 ) )
		);

		$this->assertSame( array( 'mode' => 'segments', 'segment_ids' => array() ), $result );
		$this->assertFalse( Agend_Content_Access_Policy::needs_segments( $result ) );
	}

	/** The inherit mode on a fragment hands back the document untouched, new arms included. */
	#[Test]
	public function an_inheriting_fragment_yields_a_new_arm_document_unchanged(): void {
		$document = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1 ) );

		$this->assertSame( $document, Agend_Content_Access_Policy::effective_policy( $document, array( 'mode' => 'inherit' ) ) );
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
	 * `segments` and `selected_entitlements` appear only in shapes `parse()`
	 * refuses. A well formed one is readable here and is NOT in this list.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unreadableValues(): array {
		return array(
			'a mode the gateway has and this plugin does not' => array( array( 'mode' => 'authenticated' ) ),
			'segments with nothing selected'                  => array( array( 'mode' => 'segments', 'segment_ids' => array() ) ),
			'a segment id that is not a uuid'                 => array(
				array( 'mode' => 'segments', 'segment_ids' => array( 'not-a-uuid' ) ),
			),
			'segments carrying the entitlements list key'     => array(
				array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1 ), 'entitlement_keys' => array( self::ENT_A ) ),
			),
			'selected entitlements with nothing selected'     => array(
				array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array() ),
			),
			'an entitlement key in upper case'                => array(
				array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'Journal' ) ),
			),
			'an entitlement list under the wrong key'         => array(
				array( 'mode' => 'selected_entitlements', 'entitlement_ids' => array( self::ENT_A ) ),
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

	/**
	 * This version reads and enforces the two newer modes, so a well formed
	 * policy in either is VALID and is enforced as itself. Treating it as
	 * unreadable would hide every page using it from everybody, which is the
	 * opposite failure from the one `enforced_for_post()` exists to prevent.
	 */
	#[Test]
	public function a_readable_segments_or_entitlements_policy_is_valid_and_enforced_as_stored(): void {
		$segments     = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1, self::SEG_2 ) );
		$entitlements = array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A, self::ENT_B ) );

		$this->store( 7, $segments );
		$this->store( 8, $entitlements );

		$this->assertSame( Agend_Content_Access_Policy::STORED_VALID, Agend_Content_Access_Policy::stored_state( 7 ) );
		$this->assertSame( $segments, Agend_Content_Access_Policy::enforced_for_post( 7 ) );
		$this->assertNotSame( Agend_Content_Access_Policy::UNSATISFIABLE, Agend_Content_Access_Policy::enforced_for_post( 7 ) );
		$this->assertTrue( Agend_Content_Access_Policy::needs_segments( Agend_Content_Access_Policy::enforced_for_post( 7 ) ) );

		$this->assertSame( Agend_Content_Access_Policy::STORED_VALID, Agend_Content_Access_Policy::stored_state( 8 ) );
		$this->assertSame( $entitlements, Agend_Content_Access_Policy::enforced_for_post( 8 ) );
		$this->assertNotSame( Agend_Content_Access_Policy::UNSATISFIABLE, Agend_Content_Access_Policy::enforced_for_post( 8 ) );
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

	/** Two representations of "nobody" in one plugin would drift, so a collapsed pair IS this constant. */
	#[Test]
	public function an_unintersectable_pair_collapses_to_the_unsatisfiable_policy(): void {
		$this->assertSame(
			Agend_Content_Access_Policy::UNSATISFIABLE,
			Agend_Content_Access_Policy::effective_policy(
				array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_1 ) ),
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) )
			)
		);
	}
}
