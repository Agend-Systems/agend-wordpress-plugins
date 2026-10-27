<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Policy as Policy;
use Agend_Content_Access_Rules as Rules;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';

/**
 * The rule list: the one thing a block, an Elementor section and a page are read into.
 *
 * Three states matter above everything else, and this file is mostly about keeping
 * them apart: NO RULES restricts nothing, RULES are judged, and UNREADABLE denies
 * everyone. A value this version cannot read must never come back as no rules, because
 * that publishes whatever it was guarding.
 */
#[CoversClass( Rules::class )]
final class RulesTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';
	private const SEG_A  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const SEG_B  = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';
	private const ENT_A  = 'directory.access';
	private const ENT_B  = 'journal';
	private const POST   = 77;

	private const MEMBER = 'agend_membership:is_member';

	/** A parsed block carrying the attribute. */
	private function block( $attribute = null ): array {
		$attrs = null === $attribute ? array() : array( 'agendAccess' => $attribute );

		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '<p>x</p>',
			'innerContent' => array( '<p>x</p>' ),
		);
	}

	private function storeRules( $value ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Rules::META_KEY ] = $value;
	}

	private function storePolicy( $value ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Policy::META_KEY ] = $value;
	}

	// -----------------------------------------------------------------------
	// The three states
	// -----------------------------------------------------------------------

	#[Test]
	public function no_rules_is_empty_and_is_not_unreadable(): void {
		$rules = Rules::none();

		$this->assertTrue( $rules->is_empty() );
		$this->assertFalse( $rules->is_unreadable() );
		$this->assertSame( array( 'rules' => array(), 'match' => 'any', 'invert' => false ), $rules->to_array() );
		$this->assertSame( array( $rules ), $rules->leaves(), 'a plain set is its own part' );
	}

	#[Test]
	public function unreadable_rules_are_neither_empty_nor_readable(): void {
		$rules = Rules::unreadable();

		$this->assertFalse( $rules->is_empty(), 'unreadable is not "no rules"' );
		$this->assertTrue( $rules->is_unreadable() );
		$this->assertSame( array(), $rules->leaves(), 'it is never evaluated, only denied' );
	}

	/** The nobody-set an unreadable set is written as reads back as something that still denies everyone. */
	#[Test]
	public function an_unreadable_set_written_as_an_array_reads_back_as_nobody_not_as_no_rules(): void {
		$back = Rules::from_array( Rules::unreadable()->to_array() );

		$this->assertFalse( $back->is_empty() );
		$this->assertSame( array(), $back->rules() );
		$this->assertTrue( $back->is_inverted() );
	}

	#[Test]
	public function a_reverse_over_no_rules_is_not_empty(): void {
		$rules = Rules::from_array( array( 'rules' => array(), 'invert' => true ) );

		$this->assertFalse( $rules->is_empty() );
		$this->assertFalse( $rules->is_unreadable() );
	}

	// -----------------------------------------------------------------------
	// The new shape, read strictly
	// -----------------------------------------------------------------------

	#[Test]
	public function a_well_formed_set_reads_as_written(): void {
		$rules = Rules::from_array(
			array( 'rules' => array( self::MEMBER, 'agend_segments:' . self::SEG_A ), 'match' => 'all', 'invert' => true )
		);

		$this->assertFalse( $rules->is_empty() );
		$this->assertSame( array( self::MEMBER, 'agend_segments:' . self::SEG_A ), $rules->rules() );
		$this->assertSame( 'all', $rules->match() );
		$this->assertTrue( $rules->is_inverted() );
		$this->assertSame(
			array( 'rules' => array( self::MEMBER, 'agend_segments:' . self::SEG_A ), 'match' => 'all', 'invert' => true ),
			$rules->to_array()
		);
	}

	#[Test]
	public function match_defaults_to_any_and_invert_to_off(): void {
		$rules = Rules::from_array( array( 'rules' => array( self::MEMBER ) ) );

		$this->assertSame( 'any', $rules->match() );
		$this->assertFalse( $rules->is_inverted() );
	}

	/** Null is as good as absent: JSON has no other way to say "not provided". */
	#[Test]
	public function null_fields_are_absent(): void {
		$rules = Rules::from_array( array( 'rules' => array( self::MEMBER ), 'match' => null, 'invert' => null ) );

		$this->assertSame( 'any', $rules->match() );
		$this->assertFalse( $rules->is_inverted() );
	}

	#[Test]
	public function an_empty_array_and_an_empty_list_are_no_rules(): void {
		$this->assertTrue( Rules::from_array( array() )->is_empty() );
		$this->assertTrue( Rules::from_array( array( 'rules' => array() ) )->is_empty() );
		$this->assertTrue( Rules::from_array( array( 'rules' => array(), 'match' => 'all' ) )->is_empty() );
	}

	/** A blank was never a rule: the engine skips it, and so does the reader. Repeats add nothing. */
	#[Test]
	public function blank_rules_and_repeats_are_dropped_and_the_rest_trimmed(): void {
		$rules = Rules::from_array( array( 'rules' => array( '', '  ', ' ' . self::MEMBER . ' ', self::MEMBER ) ) );

		$this->assertSame( array( self::MEMBER ), $rules->rules() );
		$this->assertTrue( Rules::from_array( array( 'rules' => array( '', ' ' ) ) )->is_empty() );
	}

	/**
	 * Everything that is not what the contract says is unreadable: it denies everyone, and is
	 * never repaired into a guess about what the author meant.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformed(): array {
		return array(
			'a string'                       => array( 'agend_membership:is_member' ),
			'a number'                       => array( 7 ),
			'true'                           => array( true ),
			'false'                          => array( false ),
			'null'                           => array( null ),
			'an object'                      => array( new stdClass() ),
			'a list of rule strings'         => array( array( self::MEMBER ) ),
			'rules that is a string'         => array( array( 'rules' => self::MEMBER ) ),
			'rules that is a number'         => array( array( 'rules' => 5 ) ),
			'rules that is a map'            => array( array( 'rules' => array( 'a' => self::MEMBER ) ) ),
			'rules with a gap in its keys'   => array( array( 'rules' => array( 0 => self::MEMBER, 2 => self::MEMBER ) ) ),
			'a rule that is a number'        => array( array( 'rules' => array( self::MEMBER, 7 ) ) ),
			'a rule that is null'            => array( array( 'rules' => array( null ) ) ),
			'a rule that is an array'        => array( array( 'rules' => array( array( self::MEMBER ) ) ) ),
			'a rule that is a boolean'       => array( array( 'rules' => array( true ) ) ),
			'a match that is not any or all' => array( array( 'rules' => array( self::MEMBER ), 'match' => 'some' ) ),
			'a match in the wrong case'      => array( array( 'rules' => array( self::MEMBER ), 'match' => 'ALL' ) ),
			'a match that is empty'          => array( array( 'rules' => array( self::MEMBER ), 'match' => '' ) ),
			'a match that is a number'       => array( array( 'rules' => array( self::MEMBER ), 'match' => 1 ) ),
			'an invert that is a string'     => array( array( 'rules' => array( self::MEMBER ), 'invert' => 'true' ) ),
			'an invert that is yes'          => array( array( 'rules' => array( self::MEMBER ), 'invert' => 'yes' ) ),
			'an invert that is one'          => array( array( 'rules' => array( self::MEMBER ), 'invert' => 1 ) ),
			'an invert that is an array'     => array( array( 'rules' => array( self::MEMBER ), 'invert' => array( true ) ) ),
			'all_of that is a string'        => array( array( 'rules' => array( self::MEMBER ), 'all_of' => 'x' ) ),
			'all_of holding garbage'         => array( array( 'rules' => array( self::MEMBER ), 'all_of' => array( 'x' ) ) ),
		);
	}

	#[Test]
	#[DataProvider( 'malformed' )]
	public function malformed_input_is_unreadable_and_never_no_rules( $value ): void {
		$rules = Rules::from_array( $value );

		$this->assertTrue( $rules->is_unreadable() );
		$this->assertFalse( $rules->is_empty() );
	}

	/** Never throws, whatever it is handed. */
	#[Test]
	#[DataProvider( 'malformed' )]
	public function malformed_input_never_throws( $value ): void {
		$this->assertInstanceOf( Rules::class, Rules::from_array( $value ) );
		$this->assertSame( Policy::UNSATISFIABLE, Rules::from_array( $value )->to_policy() );
	}

	// -----------------------------------------------------------------------
	// A page
	// -----------------------------------------------------------------------

	#[Test]
	public function a_page_with_nothing_stored_has_no_rules(): void {
		$this->assertTrue( Rules::from_post( self::POST )->is_empty() );
		$this->assertTrue( Rules::from_post( 0 )->is_empty() );
		$this->assertTrue( Rules::from_post( -3 )->is_empty() );
	}

	#[Test]
	public function a_page_reads_its_new_rules(): void {
		$this->storeRules(
			array(
				'rules'  => array( self::MEMBER ),
				'match'  => 'all',
				'invert' => false,
				'denied' => array( 'action' => 'redirect', 'target_id' => 12, 'fallback_id' => 13 ),
			)
		);

		$rules = Rules::from_post( self::POST );

		$this->assertSame( array( self::MEMBER ), $rules->rules() );
		$this->assertSame( 'all', $rules->match() );
		$this->assertSame( array( 'action' => 'redirect', 'target_id' => 12, 'fallback_id' => 13 ), $rules->denied() );
	}

	/** The denial settings are exposed for the next layer, and nothing here acts on them. */
	#[Test]
	public function the_denial_settings_default_to_the_placeholder_and_never_change_what_is_admitted(): void {
		$this->storeRules( array( 'rules' => array( self::MEMBER ) ) );

		$this->assertSame( array( 'action' => 'placeholder', 'target_id' => 0, 'fallback_id' => 0 ), Rules::from_post( self::POST )->denied() );
		$this->assertSame( array( 'action' => 'placeholder', 'target_id' => 0, 'fallback_id' => 0 ), Rules::none()->denied() );

		foreach ( array( 'teleport', 7, null, array() ) as $action ) {
			$this->storeRules( array( 'rules' => array( self::MEMBER ), 'denied' => array( 'action' => $action, 'target_id' => -4, 'fallback_id' => '9' ) ) );

			$rules = Rules::from_post( self::POST );

			$this->assertSame( array( self::MEMBER ), $rules->rules(), 'a bad denied block does not touch the rules' );
			$this->assertFalse( $rules->is_unreadable() );
			$this->assertSame( array( 'action' => 'placeholder', 'target_id' => 0, 'fallback_id' => 0 ), $rules->denied(), 'it falls back to the safe action' );
		}
	}

	#[Test]
	public function a_page_with_no_rules_in_the_new_meta_is_public(): void {
		$this->storeRules( array( 'rules' => array(), 'match' => 'any', 'invert' => false, 'denied' => array( 'action' => 'back' ) ) );

		$rules = Rules::from_post( self::POST );

		$this->assertTrue( $rules->is_empty() );
		$this->assertSame( 'back', $rules->denied()['action'] );
	}

	/** @return array<string, array{0: mixed}> */
	public static function unreadableStoredRules(): array {
		return array(
			'a string'       => array( 'members' ),
			'a number'       => array( 1 ),
			'false'          => array( false ),
			'a list'         => array( array( self::MEMBER ) ),
			'a bad match'    => array( array( 'rules' => array( self::MEMBER ), 'match' => 'some' ) ),
			'a bad invert'   => array( array( 'rules' => array( self::MEMBER ), 'invert' => 'yes' ) ),
			'a bad rule'     => array( array( 'rules' => array( 5 ) ) ),
			'bad rules'      => array( array( 'rules' => 'agend_membership:is_member' ) ),
		);
	}

	/** The defect every release since 0.1.0 has had to avoid: a stored value we cannot read must not publish the page. */
	#[Test]
	#[DataProvider( 'unreadableStoredRules' )]
	public function a_page_whose_stored_rules_cannot_be_read_denies_everyone( $value ): void {
		$this->storeRules( $value );

		$rules = Rules::from_post( self::POST );

		$this->assertTrue( $rules->is_unreadable() );
		$this->assertFalse( $rules->is_empty() );
	}

	/** Stored rules that cannot be read are not rescued by an old policy sitting beside them. */
	#[Test]
	public function unreadable_new_rules_are_not_replaced_by_a_readable_old_policy(): void {
		$this->storeRules( 'members' );
		$this->storePolicy( array( 'mode' => 'public' ) );

		$this->assertTrue( Rules::from_post( self::POST )->is_unreadable() );
	}

	/** WordPress reports a cleared value as an empty string. */
	#[Test]
	public function a_cleared_value_is_absent_and_the_old_policy_is_read(): void {
		$this->storeRules( '' );
		$this->storePolicy( array( 'mode' => 'active_member' ) );

		$this->assertSame( array( self::MEMBER ), Rules::from_post( self::POST )->rules() );
	}

	/** An author who chose "no rules" in the new panel has changed their mind about the old policy. */
	#[Test]
	public function the_new_meta_wins_over_the_old_policy(): void {
		$this->storePolicy( array( 'mode' => 'active_member' ) );
		$this->storeRules( array( 'rules' => array() ) );

		$this->assertTrue( Rules::from_post( self::POST )->is_empty() );

		$this->storeRules( array( 'rules' => array( 'agend_segments:' . self::SEG_A ) ) );

		$this->assertSame( array( 'agend_segments:' . self::SEG_A ), Rules::from_post( self::POST )->rules() );
	}

	#[Test]
	public function a_page_falls_back_to_its_old_policy_converted(): void {
		$this->storePolicy( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, self::TIER_B ) ) );

		$rules = Rules::from_post( self::POST );

		$this->assertSame( array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::TIER_B ), $rules->rules() );
		$this->assertSame( 'any', $rules->match() );
		$this->assertFalse( $rules->is_inverted() );
	}

	#[Test]
	public function an_old_policy_that_cannot_be_read_denies_everyone(): void {
		foreach ( array( array( 'mode' => 'authenticated' ), array( 'mode' => 'segments', 'segment_ids' => array() ), 'members', 7, array( 'mode' => 'active_member', 'note' => 'x' ) ) as $value ) {
			$this->storePolicy( $value );

			$this->assertTrue( Rules::from_post( self::POST )->is_unreadable(), (string) wp_json_encode( $value ) );
		}
	}

	#[Test]
	public function an_old_policy_that_is_absent_or_cleared_is_no_rules(): void {
		$this->assertTrue( Rules::from_post( self::POST )->is_empty() );

		$this->storePolicy( '' );

		$this->assertTrue( Rules::from_post( self::POST )->is_empty() );
	}

	// -----------------------------------------------------------------------
	// The compatibility conversion
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: array|null, 1: string[]}> */
	public static function conversions(): array {
		return array(
			'active_member becomes is_member'      => array( array( 'mode' => 'active_member' ), array( self::MEMBER ) ),
			'one plan'                             => array( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ), array( 'agend_membership:' . self::TIER_A ) ),
			'a plan per tier'                      => array(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, self::TIER_B ) ),
				array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::TIER_B ),
			),
			'a rule per segment'                   => array(
				array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A, self::SEG_B ) ),
				array( 'agend_segments:' . self::SEG_A, 'agend_segments:' . self::SEG_B ),
			),
			'a rule per entitlement'               => array(
				array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A, self::ENT_B ) ),
				array( 'agend_entitlements:' . self::ENT_A, 'agend_entitlements:' . self::ENT_B ),
			),
			'public is no rules'                   => array( array( 'mode' => 'public' ), array() ),
			'inherit is no rules'                  => array( array( 'mode' => 'inherit' ), array() ),
			'null is no rules'                     => array( null, array() ),
		);
	}

	#[Test]
	#[DataProvider( 'conversions' )]
	public function an_old_policy_converts_to_the_agreed_rules( ?array $policy, array $expected ): void {
		$rules = Rules::from_policy( $policy );

		$this->assertSame( $expected, $rules->rules() );
		$this->assertSame( array() === $expected, $rules->is_empty() );
		$this->assertSame( 'any', $rules->match(), 'a plan or segment per entry, matched as any' );
		$this->assertFalse( $rules->is_inverted() );
		$this->assertFalse( $rules->is_unreadable() );
	}

	/** An empty list is "no rules" to the reader, and "nobody" to what asked for plans and chose none. */
	#[Test]
	public function a_set_mode_that_names_nothing_is_unreadable_not_empty(): void {
		foreach ( array(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array() ),
			array( 'mode' => 'segments', 'segment_ids' => array() ),
			array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array() ),
			Policy::UNSATISFIABLE,
		) as $policy ) {
			$this->assertTrue( Rules::from_policy( $policy )->is_unreadable(), (string) wp_json_encode( $policy ) );
		}
	}

	/** A rule built from a malformed id would be read as a slug, so such a policy is unreadable. */
	#[Test]
	public function a_policy_entry_that_is_not_what_its_mode_names_is_unreadable(): void {
		foreach ( array(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( 'gold' ) ),
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, 5 ) ),
			array( 'mode' => 'segments', 'segment_ids' => array( 'vic-fellows' ) ),
			array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'Journal' ) ),
			array( 'mode' => 'selected_tiers' ),
			array( 'mode' => 'authenticated' ),
			array( 'mode' => 7 ),
			array(),
		) as $policy ) {
			$this->assertTrue( Rules::from_policy( $policy )->is_unreadable(), (string) wp_json_encode( $policy ) );
		}
	}

	// -----------------------------------------------------------------------
	// A block
	// -----------------------------------------------------------------------

	#[Test]
	public function a_block_with_no_attribute_has_no_rules(): void {
		$this->assertTrue( Rules::from_block( $this->block() )->is_empty() );
		$this->assertTrue( Rules::from_block( $this->block( null ) )->is_empty() );
		$this->assertTrue( Rules::from_block( array() )->is_empty() );
		$this->assertTrue( Rules::from_block( array( 'attrs' => 'nope' ) )->is_empty() );
		$this->assertTrue( Rules::from_block( $this->block( array() ) )->is_empty(), '{} is the documented "nothing"' );
	}

	#[Test]
	public function a_block_reads_the_new_shape(): void {
		$rules = Rules::from_block(
			$this->block( array( 'rules' => array( self::MEMBER, 'agend_wordpress:role_editor' ), 'match' => 'all', 'invert' => true, 'fallbackId' => 9 ) )
		);

		$this->assertSame( array( self::MEMBER, 'agend_wordpress:role_editor' ), $rules->rules() );
		$this->assertSame( 'all', $rules->match() );
		$this->assertTrue( $rules->is_inverted() );
		$this->assertSame( 9, $rules->fallback_id() );
	}

	#[Test]
	public function a_block_new_shape_that_cannot_be_read_denies_everyone(): void {
		foreach ( array(
			array( 'rules' => 'agend_membership:is_member' ),
			array( 'rules' => array( 5 ) ),
			array( 'rules' => array( self::MEMBER ), 'match' => 'some' ),
			array( 'rules' => array( self::MEMBER ), 'invert' => 'true' ),
			array( 'match' => 'nope' ),
			array( 'invert' => 'yes' ),
		) as $attribute ) {
			$rules = Rules::from_block( $this->block( $attribute ) );

			$this->assertTrue( $rules->is_unreadable(), (string) wp_json_encode( $attribute ) );
		}
	}

	/**
	 * Each v1 key, converted the way the plan agreed. The editor panel still writes this shape.
	 *
	 * @return array<string, array{0: array, 1: string[], 2: string, 3: bool}> Attribute, rules, match, invert.
	 */
	public static function v1Blocks(): array {
		return array(
			'all active members'      => array( array( 'mode' => 'active_member' ), array( self::MEMBER ), 'any', false ),
			'selected plans'          => array(
				array( 'mode' => 'selected_tiers', 'tierIds' => array( self::TIER_A, self::TIER_B ) ),
				array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::TIER_B ),
				'any',
				false,
			),
			'selected segments'       => array(
				array( 'mode' => 'segments', 'segmentIds' => array( self::SEG_A ) ),
				array( 'agend_segments:' . self::SEG_A ),
				'any',
				false,
			),
			'selected entitlements'   => array(
				array( 'mode' => 'selected_entitlements', 'entitlementKeys' => array( self::ENT_A ) ),
				array( 'agend_entitlements:' . self::ENT_A ),
				'any',
				false,
			),
			'display conditions'      => array(
				array( 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in', 'role:editor' ) ),
				array( 'agend_wordpress:logged_in', 'role:editor' ),
				'any',
				false,
			),
			'conditions, all'         => array(
				array( 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in' ), 'conditionsMatch' => 'all' ),
				array( 'agend_wordpress:logged_in' ),
				'all',
				false,
			),
			'conditions, reversed'    => array(
				array( 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in' ), 'conditionsInvert' => true ),
				array( 'agend_wordpress:logged_in' ),
				'any',
				true,
			),
			'a reverse over nothing'  => array(
				array( 'conditionsEnabled' => true, 'conditionsInvert' => true ),
				array(),
				'any',
				true,
			),
		);
	}

	#[Test]
	#[DataProvider( 'v1Blocks' )]
	public function a_block_reads_the_v1_shape( array $attribute, array $rules, string $match, bool $invert ): void {
		$read = Rules::from_block( $this->block( $attribute ) );

		$this->assertFalse( $read->is_unreadable() );
		$this->assertFalse( $read->is_empty() );
		$this->assertSame( $rules, $read->rules() );
		$this->assertSame( $match, $read->match() );
		$this->assertSame( $invert, $read->is_inverted() );
	}

	#[Test]
	public function v1_conditions_that_are_off_or_empty_are_no_rules(): void {
		$this->assertTrue( Rules::from_block( $this->block( array( 'conditionsEnabled' => false, 'conditions' => array( 'agend_wordpress:logged_in' ) ) ) )->is_empty(), 'off' );
		$this->assertTrue( Rules::from_block( $this->block( array( 'conditions' => array( 'agend_wordpress:logged_in' ) ) ) )->is_empty(), 'never switched on' );
		$this->assertTrue( Rules::from_block( $this->block( array( 'conditionsEnabled' => true ) ) )->is_empty(), 'on with none chosen' );
		$this->assertTrue( Rules::from_block( $this->block( array( 'mode' => 'inherit' ) ) )->is_empty() );
		$this->assertTrue( Rules::from_block( $this->block( array( 'mode' => '' ) ) )->is_empty() );
		$this->assertTrue( Rules::from_block( $this->block( array( 'fallbackId' => 3 ) ) )->is_empty(), 'a fallback with nothing to fail is no rules' );
	}

	/** An attribute that is present and is not an object has always read as members only. */
	#[Test]
	public function a_block_attribute_that_is_not_an_object_reads_as_members_only(): void {
		foreach ( array( 'members', '', 7, 0, 1.5, true, false, array( 'active_member' ), array( array( 'mode' => 'public' ) ) ) as $garbage ) {
			$rules = Rules::from_block( $this->block( $garbage ) );

			$this->assertSame( array( self::MEMBER ), $rules->rules(), (string) wp_json_encode( $garbage ) );
			$this->assertFalse( $rules->is_empty() );
		}
	}

	#[Test]
	public function a_block_v1_mode_this_version_does_not_know_reads_as_members_only(): void {
		foreach ( array( 'everyone_but_bob', 'public', 'Active_Member', ' active_member', 5, array( 'inherit' ) ) as $mode ) {
			$this->assertSame( array( self::MEMBER ), Rules::from_block( $this->block( array( 'mode' => $mode ) ) )->rules(), (string) wp_json_encode( $mode ) );
		}
	}

	#[Test]
	public function a_block_v1_set_mode_with_nothing_valid_selected_denies_everyone(): void {
		foreach ( array(
			array( 'mode' => 'selected_tiers' ),
			array( 'mode' => 'selected_tiers', 'tierIds' => array() ),
			array( 'mode' => 'selected_tiers', 'tierIds' => array( 'gold', 7 ) ),
			array( 'mode' => 'segments', 'segmentIds' => array() ),
			array( 'mode' => 'selected_entitlements', 'entitlementKeys' => array( 'Journal' ) ),
		) as $attribute ) {
			$this->assertTrue( Rules::from_block( $this->block( $attribute ) )->is_unreadable(), (string) wp_json_encode( $attribute ) );
		}
	}

	/** A condition that is not a string denies, as the engine reported it malformed and denied, however it was reversed. */
	#[Test]
	public function a_v1_condition_that_is_not_a_string_denies_everyone(): void {
		foreach ( array(
			array( 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in', 7 ) ),
			array( 'conditionsEnabled' => true, 'conditions' => 'agend_wordpress:logged_in' ),
			array( 'conditionsEnabled' => true, 'conditions' => array( null ), 'conditionsInvert' => true ),
		) as $attribute ) {
			$this->assertTrue( Rules::from_block( $this->block( $attribute ) )->is_unreadable(), (string) wp_json_encode( $attribute ) );
		}
	}

	/** A v1 attribute with BOTH an audience and conditions has to satisfy both: nothing widens. */
	#[Test]
	public function a_v1_block_with_a_policy_and_conditions_is_the_intersection_of_both(): void {
		$rules = Rules::from_block(
			$this->block( array( 'mode' => 'active_member', 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:role_editor' ), 'conditionsInvert' => true ) )
		);

		$this->assertFalse( $rules->is_empty() );
		$this->assertCount( 2, $rules->leaves() );
		$this->assertSame( array( self::MEMBER ), $rules->leaves()[0]->rules() );
		$this->assertFalse( $rules->leaves()[0]->is_inverted() );
		$this->assertSame( array( 'agend_wordpress:role_editor' ), $rules->leaves()[1]->rules() );
		$this->assertTrue( $rules->leaves()[1]->is_inverted(), 'the reverse belongs to the conditions, not to the audience' );
	}

	#[Test]
	public function a_block_with_the_new_shape_and_v1_keys_has_to_satisfy_both(): void {
		$rules = Rules::from_block( $this->block( array( 'rules' => array( 'agend_segments:' . self::SEG_A ), 'mode' => 'active_member' ) ) );

		$this->assertCount( 2, $rules->leaves() );
		$this->assertSame( array( 'agend_segments:' . self::SEG_A ), $rules->leaves()[0]->rules() );
		$this->assertSame( array( self::MEMBER ), $rules->leaves()[1]->rules() );
	}

	/** A new-shape set that cannot be read makes the whole block unreadable, whatever else it says. */
	#[Test]
	public function an_unreadable_new_shape_is_not_rescued_by_readable_v1_keys(): void {
		$this->assertTrue( Rules::from_block( $this->block( array( 'rules' => 5, 'mode' => 'active_member' ) ) )->is_unreadable() );
	}

	/** Replacement content belongs to the part that was written with it. */
	#[Test]
	public function the_replacement_content_stays_with_the_conditions_it_came_with(): void {
		$rules = Rules::from_block(
			$this->block( array( 'mode' => 'active_member', 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in' ), 'fallbackId' => 9 ) )
		);

		$this->assertSame( 9, $rules->fallback_id() );
		$this->assertSame( array( 'agend_wordpress:logged_in' ), $rules->fallback_owner()->rules(), 'the conditions, not the audience' );

		$audience_only = Rules::from_block( $this->block( array( 'mode' => 'active_member', 'fallbackId' => 9 ) ) );

		$this->assertNull( $audience_only->fallback_owner(), 'an audience has never had replacement content' );
		$this->assertSame( 0, $audience_only->fallback_id() );
	}

	#[Test]
	public function the_replacement_content_goes_to_the_new_shape_when_there_is_one(): void {
		$rules = Rules::from_block(
			$this->block( array( 'rules' => array( self::MEMBER ), 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in' ), 'fallbackId' => 9 ) )
		);

		$this->assertSame( array( self::MEMBER ), $rules->fallback_owner()->rules() );
	}

	#[Test]
	public function a_replacement_that_is_not_a_positive_integer_is_none(): void {
		foreach ( array( 0, -2, '9', 9.5, true, null ) as $fallback ) {
			$rules = Rules::from_block( $this->block( array( 'rules' => array( self::MEMBER ), 'fallbackId' => $fallback ) ) );

			$this->assertSame( 0, $rules->fallback_id(), (string) wp_json_encode( $fallback ) );
		}
	}

	// -----------------------------------------------------------------------
	// An Elementor element
	// -----------------------------------------------------------------------

	#[Test]
	public function an_element_with_no_settings_has_no_rules(): void {
		$this->assertTrue( Rules::from_elementor_settings( array() )->is_empty() );
		$this->assertTrue( Rules::from_elementor_settings( array( 'agend_access_mode' => 'inherit', 'agend_conditions_enabled' => '', 'agend_access_rules' => array(), 'agend_access_match' => 'any', 'agend_access_invert' => '' ) )->is_empty() );
	}

	#[Test]
	public function an_element_reads_the_new_keys(): void {
		$rules = Rules::from_elementor_settings(
			array(
				'agend_access_rules'          => array( self::MEMBER, 'agend_entitlements:' . self::ENT_A ),
				'agend_access_match'          => 'all',
				'agend_access_invert'         => 'yes',
				'agend_conditions_fallback_id' => '12',
			)
		);

		$this->assertSame( array( self::MEMBER, 'agend_entitlements:' . self::ENT_A ), $rules->rules() );
		$this->assertSame( 'all', $rules->match() );
		$this->assertTrue( $rules->is_inverted() );
		$this->assertSame( 12, $rules->fallback_id() );
	}

	#[Test]
	public function an_element_reads_the_old_audience_keys(): void {
		$this->assertSame( array( self::MEMBER ), Rules::from_elementor_settings( array( 'agend_access_mode' => 'active_member' ) )->rules() );
		$this->assertSame(
			array( 'agend_membership:' . self::TIER_A ),
			Rules::from_elementor_settings( array( 'agend_access_mode' => 'selected_tiers', 'agend_access_tier_ids' => array( self::TIER_A ) ) )->rules()
		);
		$this->assertSame(
			array( 'agend_segments:' . self::SEG_A ),
			Rules::from_elementor_settings( array( 'agend_access_mode' => 'segments', 'agend_access_segment_ids' => array( self::SEG_A ) ) )->rules()
		);
		$this->assertSame(
			array( 'agend_entitlements:' . self::ENT_A ),
			Rules::from_elementor_settings( array( 'agend_access_mode' => 'selected_entitlements', 'agend_access_entitlement_keys' => array( self::ENT_A ) ) )->rules()
		);
	}

	/** An element that asked for plans and chose none is hidden from everybody, as it has always been. */
	#[Test]
	public function an_element_that_asked_for_a_set_and_chose_nothing_denies_everyone(): void {
		foreach ( array( 'selected_tiers', 'segments', 'selected_entitlements' ) as $mode ) {
			$this->assertTrue( Rules::from_elementor_settings( array( 'agend_access_mode' => $mode ) )->is_unreadable(), $mode );
			$this->assertTrue( Rules::from_elementor_settings( array( 'agend_access_mode' => $mode, 'agend_access_tier_ids' => array( 'junk' ) ) )->is_unreadable(), $mode . ' with junk' );
		}
	}

	#[Test]
	public function an_element_mode_this_version_does_not_know_reads_as_members_only(): void {
		$this->assertSame( array( self::MEMBER ), Rules::from_elementor_settings( array( 'agend_access_mode' => 'bogus' ) )->rules() );
	}

	#[Test]
	public function an_element_reads_the_old_condition_keys(): void {
		$rules = Rules::from_elementor_settings(
			array(
				'agend_conditions_enabled' => 'yes',
				'agend_conditions'         => array( 'agend_wordpress:logged_out', ' role:editor ' ),
				'agend_conditions_match'   => 'all',
				'agend_conditions_invert'  => 'yes',
			)
		);

		$this->assertSame( array( 'agend_wordpress:logged_out', 'role:editor' ), $rules->rules() );
		$this->assertSame( 'all', $rules->match() );
		$this->assertTrue( $rules->is_inverted() );
	}

	#[Test]
	public function old_conditions_that_are_switched_off_are_not_rules(): void {
		foreach ( array( '', 'no', true, 1 ) as $toggle ) {
			$this->assertTrue(
				Rules::from_elementor_settings( array( 'agend_conditions_enabled' => $toggle, 'agend_conditions' => array( 'agend_wordpress:logged_in' ) ) )->is_empty(),
				(string) wp_json_encode( $toggle )
			);
		}
	}

	#[Test]
	public function old_conditions_that_cannot_be_read_deny_everyone(): void {
		$this->assertTrue( Rules::from_elementor_settings( array( 'agend_conditions_enabled' => 'yes', 'agend_conditions' => array( 'agend_wordpress:logged_in', 4 ) ) )->is_unreadable() );
		$this->assertTrue( Rules::from_elementor_settings( array( 'agend_conditions_enabled' => 'yes', 'agend_conditions' => 'agend_wordpress:logged_in' ) )->is_unreadable() );
	}

	/** A match that is not `all` is `any`, and only `yes` reverses: the old reading, kept for the old keys. */
	#[Test]
	public function the_old_keys_keep_their_old_leniency(): void {
		$rules = Rules::from_elementor_settings(
			array( 'agend_conditions_enabled' => 'yes', 'agend_conditions' => array( 'agend_wordpress:logged_in' ), 'agend_conditions_match' => 'ALL', 'agend_conditions_invert' => 'true' )
		);

		$this->assertSame( 'any', $rules->match() );
		$this->assertFalse( $rules->is_inverted() );
	}

	/** The new keys have no leniency: a switch that is not a switch is unreadable. */
	#[Test]
	public function the_new_keys_are_strict(): void {
		foreach ( array(
			array( 'agend_access_rules' => 'agend_membership:is_member' ),
			array( 'agend_access_rules' => array( 4 ) ),
			array( 'agend_access_rules' => array( self::MEMBER ), 'agend_access_match' => 'ALL' ),
			array( 'agend_access_rules' => array( self::MEMBER ), 'agend_access_invert' => 'no' ),
			array( 'agend_access_rules' => array( self::MEMBER ), 'agend_access_invert' => 'true' ),
			array( 'agend_access_rules' => array( self::MEMBER ), 'agend_access_invert' => 1 ),
		) as $settings ) {
			$this->assertTrue( Rules::from_elementor_settings( $settings )->is_unreadable(), (string) wp_json_encode( $settings ) );
		}
	}

	#[Test]
	public function an_element_with_an_old_policy_and_old_conditions_is_the_intersection_of_both(): void {
		$rules = Rules::from_elementor_settings(
			array(
				'agend_access_mode'            => 'selected_tiers',
				'agend_access_tier_ids'        => array( self::TIER_A ),
				'agend_conditions_enabled'     => 'yes',
				'agend_conditions'             => array( 'agend_wordpress:logged_in' ),
				'agend_conditions_fallback_id' => '5',
			)
		);

		$this->assertCount( 2, $rules->leaves() );
		$this->assertSame( array( 'agend_membership:' . self::TIER_A ), $rules->leaves()[0]->rules() );
		$this->assertSame( array( 'agend_wordpress:logged_in' ), $rules->leaves()[1]->rules() );
		$this->assertSame( array( 'agend_wordpress:logged_in' ), $rules->fallback_owner()->rules() );
		$this->assertSame( 5, $rules->fallback_id() );
	}

	/**
	 * Elementor hands the reader the control defaults too: an empty rule list for a
	 * control nobody touched. That is not the new keys being present. Read as present,
	 * it would take the replacement content for an empty set that is then dropped, and
	 * an element that only has old display conditions would lose its "Show instead".
	 */
	#[Test]
	public function the_default_new_keys_do_not_take_the_replacement_content_from_old_conditions(): void {
		$rules = Rules::from_elementor_settings(
			array(
				'agend_access_rules'           => array(),
				'agend_access_match'           => 'any',
				'agend_access_invert'          => '',
				'agend_conditions_enabled'     => 'yes',
				'agend_conditions'             => array( 'agend_wordpress:logged_in' ),
				'agend_conditions_fallback_id' => '5',
			)
		);

		$this->assertSame( array( 'agend_wordpress:logged_in' ), $rules->rules() );
		$this->assertSame( 5, $rules->fallback_id() );
		$this->assertSame( array( 'agend_wordpress:logged_in' ), $rules->fallback_owner()->rules() );
	}

	/** An unreadable new key stays unreadable: only a set that says nothing is treated as absent. */
	#[Test]
	public function new_keys_that_say_something_still_win_the_replacement_content(): void {
		$rules = Rules::from_elementor_settings(
			array(
				'agend_access_rules'           => array( self::MEMBER ),
				'agend_conditions_enabled'     => 'yes',
				'agend_conditions'             => array( 'agend_wordpress:logged_in' ),
				'agend_conditions_fallback_id' => '5',
			)
		);

		$this->assertSame( array( self::MEMBER ), $rules->fallback_owner()->rules() );

		// Reversing nothing is a rule set that says something: nobody.
		$reversed = Rules::from_elementor_settings( array( 'agend_access_rules' => array(), 'agend_access_invert' => 'yes', 'agend_conditions_fallback_id' => '5' ) );

		$this->assertFalse( $reversed->is_empty() );
		$this->assertSame( 5, $reversed->fallback_id() );
		$this->assertTrue( Rules::from_elementor_settings( array( 'agend_access_rules' => 'junk' ) )->is_unreadable() );
	}

	// -----------------------------------------------------------------------
	// The intersection
	// -----------------------------------------------------------------------

	#[Test]
	public function an_intersection_drops_empty_sets_and_unwraps_a_single_one(): void {
		$a = Rules::from_array( array( 'rules' => array( self::MEMBER ) ) );

		$this->assertTrue( Rules::all_of()->is_empty() );
		$this->assertTrue( Rules::all_of( Rules::none(), Rules::none() )->is_empty() );
		$this->assertSame( array( self::MEMBER ), Rules::all_of( Rules::none(), $a, Rules::none() )->rules() );
		$this->assertCount( 1, Rules::all_of( Rules::none(), $a )->leaves() );
	}

	#[Test]
	public function an_intersection_keeps_every_part(): void {
		$a = Rules::from_array( array( 'rules' => array( self::MEMBER ) ) );
		$b = Rules::from_array( array( 'rules' => array( 'agend_segments:' . self::SEG_A ), 'invert' => true ) );

		$both = Rules::all_of( $a, $b );

		$this->assertFalse( $both->is_empty() );
		$this->assertCount( 2, $both->leaves() );
	}

	/** One unreadable part makes the whole unreadable: no other part can rescue it. */
	#[Test]
	public function an_unreadable_part_makes_an_intersection_unreadable(): void {
		$a = Rules::from_array( array( 'rules' => array( self::MEMBER ) ) );

		$this->assertTrue( Rules::all_of( $a, Rules::unreadable() )->is_unreadable() );
		$this->assertTrue( Rules::all_of( Rules::unreadable(), $a )->is_unreadable() );
		$this->assertTrue( Rules::all_of( Rules::unreadable(), Rules::none() )->is_unreadable() );
	}

	/** A composition is never a composition of compositions: it is flat, and only ever narrows. */
	#[Test]
	public function an_intersection_is_flat(): void {
		$a = Rules::from_array( array( 'rules' => array( self::MEMBER ) ) );
		$b = Rules::from_array( array( 'rules' => array( 'agend_segments:' . self::SEG_A ) ) );
		$c = Rules::from_array( array( 'rules' => array( 'agend_entitlements:' . self::ENT_A ) ) );

		$all = Rules::all_of( Rules::all_of( $a, $b ), $c );

		$this->assertCount( 3, $all->leaves() );

		foreach ( $all->leaves() as $leaf ) {
			$this->assertCount( 1, $leaf->leaves() );
			$this->assertSame( $leaf, $leaf->leaves()[0] );
		}
	}

	#[Test]
	public function an_intersection_reads_back_from_its_array(): void {
		$both = Rules::all_of(
			Rules::from_array( array( 'rules' => array( self::MEMBER ) ) ),
			Rules::from_array( array( 'rules' => array( 'agend_segments:' . self::SEG_A ), 'match' => 'all', 'invert' => true ) )
		);

		$back = Rules::from_array( $both->to_array() );

		$this->assertCount( 2, $back->leaves() );
		$this->assertSame( $both->leaves()[1]->to_array(), $back->leaves()[1]->to_array() );
	}

	// -----------------------------------------------------------------------
	// Export: the rules as an `@agend/access-policy` arm
	// -----------------------------------------------------------------------

	/**
	 * What Agend may be told, and what it is told when the rules cannot be said. The unsatisfiable
	 * policy hides the content from everyone in the projection: fail closed and invisible.
	 *
	 * @return array<string, array{0: array, 1: array|null}> Rules, policy.
	 */
	public static function policies(): array {
		$none = Policy::UNSATISFIABLE;

		return array(
			// Expressible.
			'is_member alone'                       => array( array( 'rules' => array( self::MEMBER ) ), array( 'mode' => 'active_member' ) ),
			'is_member alone, match all'            => array( array( 'rules' => array( self::MEMBER ), 'match' => 'all' ), array( 'mode' => 'active_member' ) ),
			'one plan'                              => array( array( 'rules' => array( 'agend_membership:' . self::TIER_A ) ), array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ) ),
			'one plan, match all'                   => array( array( 'rules' => array( 'agend_membership:' . self::TIER_A ), 'match' => 'all' ), array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ) ),
			'plans, match any'                      => array(
				array( 'rules' => array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::TIER_B ) ),
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, self::TIER_B ) ),
			),
			'repeated plans'                        => array(
				array( 'rules' => array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::TIER_A ), 'match' => 'all' ),
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
			),
			'segments'                              => array(
				array( 'rules' => array( 'agend_segments:' . self::SEG_A, 'agend_segments:' . self::SEG_B ) ),
				array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A, self::SEG_B ) ),
			),
			'entitlements'                          => array(
				array( 'rules' => array( 'agend_entitlements:' . self::ENT_A ) ),
				array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A ) ),
			),
			// Not expressible: fail closed.
			'no rules are not hidden'               => array( array(), null ),
			'a reverse'                             => array( array( 'rules' => array( self::MEMBER ), 'invert' => true ), $none ),
			'a reverse over nothing'                => array( array( 'rules' => array(), 'invert' => true ), $none ),
			'a role'                                => array( array( 'rules' => array( 'agend_wordpress:role_editor' ) ), $none ),
			'logged in'                             => array( array( 'rules' => array( 'agend_wordpress:logged_in' ) ), $none ),
			'a provider a site registered'          => array( array( 'rules' => array( 'company:acme' ) ), $none ),
			'a legacy provider name'                => array( array( 'rules' => array( 'upbeat_is_member:yes' ) ), $none ),
			'a plan slug, not an id'                => array( array( 'rules' => array( 'agend_membership:gold' ) ), $none ),
			'a segment slug, not an id'             => array( array( 'rules' => array( 'agend_segments:vic-fellows' ) ), $none ),
			'an entitlement that is not a gate key' => array( array( 'rules' => array( 'agend_entitlements:Journal' ) ), $none ),
			'is_member beside a plan'               => array( array( 'rules' => array( self::MEMBER, 'agend_membership:' . self::TIER_A ) ), $none ),
			'two kinds'                             => array( array( 'rules' => array( 'agend_membership:' . self::TIER_A, 'agend_segments:' . self::SEG_A ) ), $none ),
			'all of two plans'                      => array( array( 'rules' => array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::TIER_B ), 'match' => 'all' ), $none ),
			'all of two segments'                   => array( array( 'rules' => array( 'agend_segments:' . self::SEG_A, 'agend_segments:' . self::SEG_B ), 'match' => 'all' ), $none ),
			'a malformed rule'                      => array( array( 'rules' => array( 'agend_membership' ) ), $none ),
			'a rule with no value'                  => array( array( 'rules' => array( 'agend_membership:' ) ), $none ),
			'a rule with no provider'               => array( array( 'rules' => array( ':is_member' ) ), $none ),
			'unreadable'                            => array( array( 'rules' => 'x' ), $none ),
			'a plan beside a role'                  => array( array( 'rules' => array( 'agend_membership:' . self::TIER_A, 'agend_wordpress:role_editor' ) ), $none ),
		);
	}

	#[Test]
	#[DataProvider( 'policies' )]
	public function rules_export_as_the_policy_that_says_them_or_as_nobody( array $rules, ?array $policy ): void {
		$this->assertSame( $policy, Rules::from_array( $rules )->to_policy() );
	}

	/** Whatever is exported is either a policy the gateway will accept or the unsatisfiable one. */
	#[Test]
	#[DataProvider( 'policies' )]
	public function an_exported_policy_is_always_one_the_parser_accepts_or_the_unsatisfiable_one( array $rules ): void {
		$policy = Rules::from_array( $rules )->to_policy();

		$this->assertTrue( null === $policy || Policy::UNSATISFIABLE === $policy || null !== Policy::parse( $policy ) );
	}

	#[Test]
	public function unreadable_rules_export_as_nobody(): void {
		$this->assertSame( Policy::UNSATISFIABLE, Rules::unreadable()->to_policy() );
		$this->assertNull( Rules::none()->to_policy() );
	}

	/** Every old policy comes back out as itself: the conversion and the export are inverses where both are defined. */
	#[Test]
	#[DataProvider( 'conversions' )]
	public function an_old_policy_exports_as_itself( ?array $policy ): void {
		$expected = null === $policy || in_array( $policy['mode'], array( 'public', 'inherit' ), true ) ? null : $policy;

		$this->assertSame( $expected, Rules::from_policy( $policy )->to_policy() );
	}

	/** An intersection is folded as a fragment is folded into its document, and never to something wider. */
	#[Test]
	public function an_intersection_exports_folded_and_fails_closed_when_any_part_cannot_be_said(): void {
		$members = Rules::from_array( array( 'rules' => array( self::MEMBER ) ) );
		$plans   = Rules::from_array( array( 'rules' => array( 'agend_membership:' . self::TIER_A ) ) );
		$other   = Rules::from_array( array( 'rules' => array( 'agend_membership:' . self::TIER_B ) ) );
		$segment = Rules::from_array( array( 'rules' => array( 'agend_segments:' . self::SEG_A ) ) );
		$role    = Rules::from_array( array( 'rules' => array( 'agend_wordpress:role_editor' ) ) );

		$this->assertSame( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ), Rules::all_of( $members, $plans )->to_policy(), 'a plan narrows members' );
		$this->assertSame( array( 'mode' => 'selected_tiers', 'tier_ids' => array() ), Rules::all_of( $plans, $other )->to_policy(), 'two disjoint plan lists intersect to nobody' );
		$this->assertSame( Policy::UNSATISFIABLE, Rules::all_of( $plans, $segment )->to_policy(), 'unlike sets are nobody' );
		$this->assertSame( Policy::UNSATISFIABLE, Rules::all_of( $members, $role )->to_policy(), 'a role cannot be said' );
		$this->assertSame( Policy::UNSATISFIABLE, Rules::all_of( $role, $members )->to_policy() );
	}

	/** Display conditions on top of an old policy cannot be exported, so the element is hidden in the projection. */
	#[Test]
	public function a_v1_block_with_a_policy_and_conditions_exports_as_nobody(): void {
		$rules = Rules::from_block( $this->block( array( 'mode' => 'active_member', 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in' ) ) ) );

		$this->assertSame( Policy::UNSATISFIABLE, $rules->to_policy() );
	}
}
