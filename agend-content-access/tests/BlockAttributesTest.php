<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Block_Attributes as Attributes;
use Agend_Content_Access_Conditions;
use Agend_Content_Access_Fragment_Rules as FragmentRules;
use Agend_Content_Access_Policy as Policy;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';

/**
 * The `agendAccess` block attribute as neutral fragment settings.
 *
 * The converter reshapes and never decides, so what is tested is that every
 * usable value arrives under the key the readers expect, and that every unusable
 * one arrives in a form those readers fail closed on rather than one that grants
 * access.
 */
#[CoversClass( Attributes::class )]
final class BlockAttributesTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';
	private const SEGMENT = '33333333-3333-3333-3333-333333333333';

	/** A parsed block, as `parse_blocks()` returns one. */
	private function block( array $access = array(), array $children = array(), string $name = 'core/group' ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => array() === $access ? array() : array( Attributes::ATTRIBUTE => $access ),
			'innerBlocks'  => $children,
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/** Registers a published synced pattern whose content is the given markup. */
	private function pattern( int $id, string $content, array $over = array() ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array_merge(
			array(
				'ID'           => $id,
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => $content,
			),
			$over
		);
	}

	private function synced( int $ref, array $access = array() ): array {
		$block                 = $this->block( $access, array(), 'core/block' );
		$block['attrs']['ref'] = $ref;

		return $block;
	}

	// -----------------------------------------------------------------
	// The attribute name
	// -----------------------------------------------------------------

	/** Later layers (the editor panel, enforcement) read the same name. */
	#[Test]
	public function the_attribute_is_named_agend_access(): void {
		$this->assertSame( 'agendAccess', Attributes::ATTRIBUTE );
	}

	// -----------------------------------------------------------------
	// to_settings(): every key
	// -----------------------------------------------------------------

	#[Test]
	public function an_empty_attribute_converts_to_no_settings(): void {
		$this->assertSame( array(), Attributes::to_settings( array() ) );
	}

	#[Test]
	public function every_key_lands_under_the_key_its_reader_expects(): void {
		$settings = Attributes::to_settings(
			array(
				'mode'              => 'selected_tiers',
				'tierIds'           => array( self::TIER_A, self::TIER_B ),
				'segmentIds'        => array( self::SEGMENT ),
				'entitlementKeys'   => array( 'gold.access' ),
				'conditionsEnabled' => true,
				'conditions'        => array( 'role:editor', 'segment:overdue' ),
				'conditionsMatch'   => 'all',
				'conditionsInvert'  => true,
				'fallbackId'        => 42,
			)
		);

		$this->assertSame(
			array(
				Policy::FRAGMENT_MODE_KEY       => 'selected_tiers',
				Policy::FRAGMENT_TIERS_KEY      => array( self::TIER_A, self::TIER_B ),
				'agend_access_segment_ids'      => array( self::SEGMENT ),
				'agend_access_entitlement_keys' => array( 'gold.access' ),
				FragmentRules::ENABLED_KEY      => 'yes',
				FragmentRules::CONDITIONS_KEY   => array( 'role:editor', 'segment:overdue' ),
				FragmentRules::MATCH_KEY        => 'all',
				FragmentRules::INVERT_KEY       => 'yes',
				FragmentRules::FALLBACK_KEY     => 42,
			),
			$settings
		);
	}

	/** The settings keys are the ones the two readers already use. */
	#[Test]
	public function the_settings_are_what_the_policy_reader_understands(): void {
		$settings = Attributes::to_settings( array( 'mode' => 'selected_tiers', 'tierIds' => array( self::TIER_A ) ) );

		$this->assertSame(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
			Policy::from_fragment_settings( $settings )
		);
		$this->assertSame(
			array( 'mode' => 'active_member' ),
			Policy::from_fragment_settings( Attributes::to_settings( array( 'mode' => 'active_member' ) ) )
		);
		$this->assertNull( Policy::from_fragment_settings( Attributes::to_settings( array( 'mode' => 'inherit' ) ) ) );
	}

	#[Test]
	public function a_list_is_reindexed_and_a_map_is_read_as_its_values(): void {
		$settings = Attributes::to_settings( array( 'tierIds' => array( 3 => self::TIER_A, 7 => self::TIER_B ) ) );

		$this->assertSame( array( self::TIER_A, self::TIER_B ), $settings[ Policy::FRAGMENT_TIERS_KEY ] );
	}

	// -----------------------------------------------------------------
	// Toggles
	// -----------------------------------------------------------------

	#[Test]
	public function booleans_become_yes_or_empty(): void {
		$on  = Attributes::to_settings( array( 'conditionsEnabled' => true, 'conditionsInvert' => true ) );
		$off = Attributes::to_settings( array( 'conditionsEnabled' => false, 'conditionsInvert' => false ) );

		$this->assertSame( 'yes', $on[ FragmentRules::ENABLED_KEY ] );
		$this->assertSame( 'yes', $on[ FragmentRules::INVERT_KEY ] );
		$this->assertSame( '', $off[ FragmentRules::ENABLED_KEY ] );
		$this->assertSame( '', $off[ FragmentRules::INVERT_KEY ] );
	}

	/**
	 * The contract says boolean. A string that merely looks truthy is not
	 * coerced into a switch that is on.
	 */
	#[Test]
	#[DataProvider( 'notTrue' )]
	public function only_the_boolean_true_switches_a_toggle_on( $value ): void {
		$settings = Attributes::to_settings( array( 'conditionsEnabled' => $value, 'conditionsInvert' => $value ) );

		$this->assertSame( '', $settings[ FragmentRules::ENABLED_KEY ] );
		$this->assertSame( '', $settings[ FragmentRules::INVERT_KEY ] );
		$this->assertFalse( FragmentRules::conditions_enabled( $settings ) );
	}

	public static function notTrue(): array {
		return array(
			'the string yes'   => array( 'yes' ),
			'the string true'  => array( 'true' ),
			'the string false' => array( 'false' ),
			'one'              => array( 1 ),
			'an array'         => array( array( true ) ),
		);
	}

	// -----------------------------------------------------------------
	// Mode: unusable values fail closed in the reader
	// -----------------------------------------------------------------

	#[Test]
	public function an_unrecognised_mode_reaches_the_policy_reader_unchanged(): void {
		$settings = Attributes::to_settings( array( 'mode' => 'everyone_but_bob' ) );

		$this->assertSame( 'everyone_but_bob', $settings[ Policy::FRAGMENT_MODE_KEY ] );
		$this->assertSame( array( 'mode' => 'active_member' ), Policy::from_fragment_settings( $settings ) );
	}

	/** Dropping a mode that is not a string would read as "no mode", which is inherit. */
	#[Test]
	#[DataProvider( 'garbageModes' )]
	public function a_mode_that_is_not_a_string_fails_closed( $mode ): void {
		$settings = Attributes::to_settings( array( 'mode' => $mode ) );

		$this->assertArrayHasKey( Policy::FRAGMENT_MODE_KEY, $settings );
		$this->assertSame( array( 'mode' => 'active_member' ), Policy::from_fragment_settings( $settings ) );
	}

	public static function garbageModes(): array {
		return array(
			'an integer' => array( 5 ),
			'a boolean'  => array( true ),
			'an array'   => array( array( 'active_member' ) ),
			'an object'  => array( array( 'mode' => 'public' ) ),
		);
	}

	#[Test]
	public function a_null_mode_is_absent(): void {
		$this->assertSame( array(), Attributes::to_settings( array( 'mode' => null ) ) );
	}

	// -----------------------------------------------------------------
	// Id lists: garbage only ever narrows
	// -----------------------------------------------------------------

	#[Test]
	public function non_string_ids_are_dropped_from_every_list(): void {
		$settings = Attributes::to_settings(
			array(
				'tierIds'         => array( self::TIER_A, 5, null, array( self::TIER_B ), true ),
				'segmentIds'      => array( 7, self::SEGMENT ),
				'entitlementKeys' => array( 'gold.access', 1.5, false ),
			)
		);

		$this->assertSame( array( self::TIER_A ), $settings[ Policy::FRAGMENT_TIERS_KEY ] );
		$this->assertSame( array( self::SEGMENT ), $settings['agend_access_segment_ids'] );
		$this->assertSame( array( 'gold.access' ), $settings['agend_access_entitlement_keys'] );
	}

	/**
	 * Emptied, not absent: "selected plans" with nothing selected is a
	 * restriction nobody satisfies, never "no restriction".
	 */
	#[Test]
	public function a_list_of_only_garbage_leaves_a_policy_nobody_satisfies(): void {
		$settings = Attributes::to_settings( array( 'mode' => 'selected_tiers', 'tierIds' => array( 5, null ) ) );

		$this->assertSame( array(), $settings[ Policy::FRAGMENT_TIERS_KEY ] );
		$this->assertSame(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array() ),
			Policy::from_fragment_settings( $settings )
		);
	}

	#[Test]
	public function a_list_that_is_not_a_list_is_dropped_and_still_fails_closed(): void {
		$settings = Attributes::to_settings( array( 'mode' => 'selected_tiers', 'tierIds' => self::TIER_A ) );

		$this->assertArrayNotHasKey( Policy::FRAGMENT_TIERS_KEY, $settings );
		$this->assertSame(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array() ),
			Policy::from_fragment_settings( $settings )
		);
	}

	// -----------------------------------------------------------------
	// Conditions: kept malformed so the engine denies
	// -----------------------------------------------------------------

	#[Test]
	public function a_non_string_condition_is_kept_so_the_engine_denies_it(): void {
		$settings = Attributes::to_settings(
			array( 'conditionsEnabled' => true, 'conditions' => array( 'role:editor', 7 ), 'conditionsMatch' => 'any' )
		);

		$this->assertSame( array( 'role:editor', 7 ), $settings[ FragmentRules::CONDITIONS_KEY ] );

		$result = Agend_Content_Access_Conditions::evaluate(
			$settings[ FragmentRules::CONDITIONS_KEY ],
			static fn( string $provider, string $value ): bool => true
		);

		$this->assertFalse( $result['passes'], 'dropping the entry would have shrunk the rule and passed it' );
	}

	#[Test]
	public function conditions_that_are_not_a_list_cannot_become_no_conditions(): void {
		$settings = Attributes::to_settings( array( 'conditionsEnabled' => true, 'conditions' => 'role:editor' ) );

		$result = Agend_Content_Access_Conditions::evaluate(
			$settings[ FragmentRules::CONDITIONS_KEY ],
			static fn( string $provider, string $value ): bool => true
		);

		$this->assertFalse( $result['passes'] );
	}

	#[Test]
	public function the_match_is_kept_when_it_is_a_string_and_dropped_otherwise(): void {
		$this->assertSame( 'all', Attributes::to_settings( array( 'conditionsMatch' => 'all' ) )[ FragmentRules::MATCH_KEY ] );
		$this->assertSame( array(), Attributes::to_settings( array( 'conditionsMatch' => 3 ) ) );
		$this->assertSame( array(), Attributes::to_settings( array( 'conditionsMatch' => array( 'all' ) ) ) );
	}

	// -----------------------------------------------------------------
	// Fallback
	// -----------------------------------------------------------------

	#[Test]
	#[DataProvider( 'noFallback' )]
	public function a_fallback_that_is_not_a_positive_integer_is_dropped( $value ): void {
		$this->assertSame( array(), Attributes::to_settings( array( 'fallbackId' => $value ) ) );
	}

	public static function noFallback(): array {
		return array(
			'zero means none' => array( 0 ),
			'negative'        => array( -4 ),
			'a numeric string' => array( '42' ),
			'a float'         => array( 42.5 ),
			'an array'        => array( array( 42 ) ),
		);
	}

	#[Test]
	public function a_positive_fallback_is_kept_as_an_integer(): void {
		$this->assertSame( 42, Attributes::to_settings( array( 'fallbackId' => 42 ) )[ FragmentRules::FALLBACK_KEY ] );
	}

	// -----------------------------------------------------------------
	// Reading a parsed block
	// -----------------------------------------------------------------

	#[Test]
	public function a_block_with_no_attribute_carries_nothing(): void {
		$block = $this->block();

		$this->assertSame( array(), Attributes::settings_for_block( $block ) );
		$this->assertFalse( Attributes::carries_policy( $block ) );
		$this->assertFalse( Attributes::carries_conditions( $block ) );
	}

	#[Test]
	public function an_attribute_that_is_not_an_object_is_dropped(): void {
		foreach ( array( 'active_member', 7, true ) as $garbage ) {
			$block = $this->block();

			$block['attrs'][ Attributes::ATTRIBUTE ] = $garbage;

			$this->assertSame( array(), Attributes::settings_for_block( $block ) );
			$this->assertFalse( Attributes::carries_policy( $block ) );
		}

		$this->assertSame( array(), Attributes::settings_for_block( array( 'blockName' => 'core/group', 'attrs' => 'nope' ) ) );
		$this->assertSame( array(), Attributes::settings_for_block( array() ) );
	}

	#[Test]
	public function inherit_and_an_empty_attribute_carry_no_policy(): void {
		$this->assertFalse( Attributes::carries_policy( $this->block( array( 'mode' => 'inherit' ) ) ) );
		$this->assertFalse( Attributes::carries_policy( $this->block( array( 'mode' => '' ) ) ) );
		$this->assertFalse( Attributes::carries_policy( $this->block( array( 'fallbackId' => 3 ) ) ) );
	}

	#[Test]
	public function a_restricting_mode_carries_a_policy(): void {
		$this->assertTrue( Attributes::carries_policy( $this->block( array( 'mode' => 'active_member' ) ) ) );
		$this->assertTrue( Attributes::carries_policy( $this->block( array( 'mode' => 'selected_tiers', 'tierIds' => array() ) ) ) );
	}

	/** An unreadable mode is a policy nobody was meant to skip past, so it counts. */
	#[Test]
	public function an_unrecognised_mode_counts_as_carrying_a_policy(): void {
		$this->assertTrue( Attributes::carries_policy( $this->block( array( 'mode' => 'bogus' ) ) ) );
		$this->assertTrue( Attributes::carries_policy( $this->block( array( 'mode' => 7 ) ) ) );
	}

	#[Test]
	public function conditions_are_carried_when_switched_on_even_with_none_chosen(): void {
		$this->assertTrue( Attributes::carries_conditions( $this->block( array( 'conditionsEnabled' => true ) ) ) );
		$this->assertFalse( Attributes::carries_conditions( $this->block( array( 'conditionsEnabled' => false, 'conditions' => array( 'role:editor' ) ) ) ) );
		$this->assertFalse( Attributes::carries_conditions( $this->block( array( 'conditions' => array( 'role:editor' ) ) ) ) );
	}

	/** Display conditions are not an audience policy, and must not count as one. */
	#[Test]
	public function conditions_do_not_make_a_policy(): void {
		$block = $this->block( array( 'conditionsEnabled' => true, 'conditions' => array( 'role:editor' ) ) );

		$this->assertFalse( Attributes::carries_policy( $block ) );
	}

	// -----------------------------------------------------------------
	// Subtrees
	// -----------------------------------------------------------------

	#[Test]
	public function a_subtree_is_found_to_carry_a_policy_at_any_depth(): void {
		$deep = $this->block(
			array(),
			array( $this->block( array(), array( $this->block( array(), array( $this->block( array( 'mode' => 'active_member' ) ) ) ) ) ) )
		);

		$this->assertTrue( Attributes::subtree_carries_policy( $deep ) );
		$this->assertTrue( Attributes::descendants_carry_policy( $deep ) );
	}

	#[Test]
	public function a_subtree_with_no_policy_carries_none(): void {
		$plain = $this->block( array(), array( $this->block(), $this->block( array( 'mode' => 'inherit' ) ) ) );

		$this->assertFalse( Attributes::subtree_carries_policy( $plain ) );
	}

	/** The split decision turns on this: a block's OWN policy is not "below" it. */
	#[Test]
	public function descendants_exclude_the_block_itself(): void {
		$restricted = $this->block( array( 'mode' => 'active_member' ), array( $this->block() ) );

		$this->assertTrue( Attributes::subtree_carries_policy( $restricted ) );
		$this->assertFalse( Attributes::descendants_carry_policy( $restricted ) );
	}

	#[Test]
	public function a_policy_inside_a_synced_pattern_is_found(): void {
		$this->pattern( 900, '<!-- wp:paragraph {"agendAccess":{"mode":"active_member"}} --><p>Members</p><!-- /wp:paragraph -->' );

		$this->assertTrue( Attributes::subtree_carries_policy( $this->synced( 900 ) ) );
		$this->assertTrue( Attributes::subtree_carries_policy( $this->block( array(), array( $this->synced( 900 ) ) ) ) );
	}

	#[Test]
	public function a_synced_pattern_without_a_policy_carries_none(): void {
		$this->pattern( 900, '<!-- wp:paragraph --><p>Everyone</p><!-- /wp:paragraph -->' );

		$this->assertFalse( Attributes::subtree_carries_policy( $this->synced( 900 ) ) );
	}

	/** A pattern that includes itself must end, not recurse forever. */
	#[Test]
	public function a_pattern_that_includes_itself_terminates(): void {
		$this->pattern( 900, '<!-- wp:block {"ref":900} /-->' );

		$this->assertFalse( Attributes::subtree_carries_policy( $this->synced( 900 ) ) );
	}

	#[Test]
	public function two_patterns_that_include_each_other_terminate(): void {
		$this->pattern( 900, '<!-- wp:block {"ref":901} /-->' );
		$this->pattern( 901, '<!-- wp:block {"ref":900} /-->' );

		$this->assertFalse( Attributes::subtree_carries_policy( $this->synced( 900 ) ) );
	}

	#[Test]
	public function a_chain_of_patterns_is_followed_to_the_depth_limit_and_no_further(): void {
		// 900 -> 901 -> ... -> 911. The tenth pattern in is the last one opened.
		for ( $id = 900; $id < 911; $id++ ) {
			$this->pattern( $id, '<!-- wp:block {"ref":' . ( $id + 1 ) . '} /-->' );
		}

		$restricted = '<!-- wp:paragraph {"agendAccess":{"mode":"active_member"}} --><p>Members</p><!-- /wp:paragraph -->';

		$this->pattern( 910, $restricted );
		$this->assertFalse( Attributes::subtree_carries_policy( $this->synced( 900 ) ), 'the eleventh nested pattern is past the limit' );

		$this->pattern( 909, $restricted );
		$this->assertTrue( Attributes::subtree_carries_policy( $this->synced( 900 ) ), 'the tenth is within it' );
	}

	// -----------------------------------------------------------------
	// Resolving a pattern
	// -----------------------------------------------------------------

	#[Test]
	#[DataProvider( 'unusablePatterns' )]
	public function a_pattern_that_wordpress_would_not_render_is_not_resolved( array $post ): void {
		$this->pattern( 900, '<!-- wp:paragraph {"agendAccess":{"mode":"active_member"}} --><p>Hidden</p><!-- /wp:paragraph -->', $post );

		$this->assertNull( Attributes::resolve_synced( 900 ) );
		$this->assertFalse( Attributes::subtree_carries_policy( $this->synced( 900 ) ) );
	}

	public static function unusablePatterns(): array {
		return array(
			'unpublished'        => array( array( 'post_status' => 'draft' ) ),
			'private'            => array( array( 'post_status' => 'private' ) ),
			'password protected' => array( array( 'post_password' => 'secret' ) ),
			'not a pattern'      => array( array( 'post_type' => 'page' ) ),
		);
	}

	#[Test]
	public function a_missing_pattern_is_not_resolved(): void {
		$this->assertNull( Attributes::resolve_synced( 404 ) );
		$this->assertNull( Attributes::resolve_synced( 0 ) );
		$this->assertNull( Attributes::resolve_synced( -3 ) );
	}

	#[Test]
	public function a_reference_is_read_from_an_integer_or_a_digit_string_only(): void {
		$this->assertSame( 12, Attributes::synced_ref( array( 'blockName' => 'core/block', 'attrs' => array( 'ref' => 12 ) ) ) );
		$this->assertSame( 12, Attributes::synced_ref( array( 'blockName' => 'core/block', 'attrs' => array( 'ref' => '12' ) ) ) );
		$this->assertSame( 0, Attributes::synced_ref( array( 'blockName' => 'core/block', 'attrs' => array( 'ref' => '12abc' ) ) ) );
		$this->assertSame( 0, Attributes::synced_ref( array( 'blockName' => 'core/block', 'attrs' => array( 'ref' => array( 12 ) ) ) ) );
		$this->assertSame( 0, Attributes::synced_ref( array( 'blockName' => 'core/block', 'attrs' => array() ) ) );
		$this->assertSame( 0, Attributes::synced_ref( array( 'blockName' => 'core/group', 'attrs' => array( 'ref' => 12 ) ) ) );
	}
}
