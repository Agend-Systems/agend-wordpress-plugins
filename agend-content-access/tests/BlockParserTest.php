<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Block_Parser as BlockParser;
use Agend_Content_Access_Document_Parser;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Post;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/interface-agend-content-access-document-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-parser.php';

/**
 * Flattening a block editor document into ordered fragments.
 *
 * The properties that matter: a policy anywhere on a block's ancestor chain
 * reaches the fragment, because losing one produces a fragment Agend believes
 * is public; and nothing is rendered, because rendering a dynamic block pulls
 * another post's content into this document's fragment, to be served under this
 * document's policy.
 */
#[CoversClass( BlockParser::class )]
final class BlockParserTest extends TestCase {

	private const POST_ID = 321;
	private const TIER_A  = '11111111-1111-1111-1111-111111111111';
	private const TIER_B  = '22222222-2222-2222-2222-222222222222';
	private const TIER_C  = '33333333-3333-3333-3333-333333333333';

	// -----------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------

	/** An opening (or self-closing) block comment, as the editor serialises it. */
	private function open( string $name, array $attrs = array(), bool $void = false ): string {
		$name = 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		$json = array() === $attrs ? '' : ' ' . json_encode( $attrs );

		return '<!-- wp:' . $name . $json . ( $void ? ' /-->' : ' -->' );
	}

	private function close( string $name ): string {
		$name = 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;

		return '<!-- /wp:' . $name . ' -->';
	}

	private function attrs( array $access ): array {
		return array() === $access ? array() : array( 'agendAccess' => $access );
	}

	private function para( string $text, array $access = array() ): string {
		return $this->open( 'core/paragraph', $this->attrs( $access ) ) . '<p>' . $text . '</p>' . $this->close( 'core/paragraph' );
	}

	/** @param string[] $children Serialised child blocks. */
	private function group( array $children, array $access = array() ): string {
		return $this->open( 'core/group', $this->attrs( $access ) )
			. '<div class="wp-block-group">' . implode( '', $children ) . '</div>'
			. $this->close( 'core/group' );
	}

	private function synced( int $ref, array $access = array() ): string {
		return $this->open( 'core/block', array_merge( array( 'ref' => $ref ), $this->attrs( $access ) ), true );
	}

	/** @return array{mode: string} */
	private function members(): array {
		return array( 'mode' => 'active_member' );
	}

	/** @return array{mode: string, tierIds: string[]} */
	private function tiers( string ...$ids ): array {
		return array( 'mode' => 'selected_tiers', 'tierIds' => $ids );
	}

	/** Blocks as the editor saves them: one blank line between each. */
	private function page( string ...$blocks ): string {
		return implode( "\n\n", $blocks );
	}

	private function post( string $content ): WP_Post {
		return new WP_Post( array( 'ID' => self::POST_ID, 'post_content' => $content ) );
	}

	private function fragments( string $content ): array {
		return ( new BlockParser() )->fragments( $this->post( $content ) );
	}

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

	/** @return string[] */
	private function ids( array $fragments ): array {
		return array_column( $fragments, 'id' );
	}

	/** @return array<int, array{string, array}> id, policy */
	private function policies( array $fragments ): array {
		return array_column( $fragments, 'policy', 'id' );
	}

	// -----------------------------------------------------------------
	// The fixture parser
	// -----------------------------------------------------------------

	/**
	 * Everything below depends on `parse_blocks()` leaving each inner block as a
	 * `null` in its parent's `innerContent`, as WordPress does. A stub that does
	 * not would make the rebuilt-markup assertions pass or fail for the wrong
	 * reason.
	 */
	#[Test]
	public function the_fixture_parser_places_inner_blocks_where_wordpress_does(): void {
		$blocks = parse_blocks( $this->group( array( $this->para( 'One' ), $this->para( 'Two' ) ) ) );

		$this->assertCount( 1, $blocks );
		$this->assertSame( array( '<div class="wp-block-group">', null, null, '</div>' ), $blocks[0]['innerContent'] );
		$this->assertSame( '<div class="wp-block-group"></div>', $blocks[0]['innerHTML'] );
		$this->assertCount( 2, $blocks[0]['innerBlocks'] );
		$this->assertSame( array( '<p>One</p>' ), $blocks[0]['innerBlocks'][0]['innerContent'] );
	}

	// -----------------------------------------------------------------
	// Recognising a post
	// -----------------------------------------------------------------

	#[Test]
	public function it_implements_the_driver_contract(): void {
		$this->assertInstanceOf( Agend_Content_Access_Document_Parser::class, new BlockParser() );
	}

	#[Test]
	public function a_post_with_blocks_is_recognised(): void {
		$this->assertTrue( ( new BlockParser() )->recognises( $this->post( $this->para( 'Hello' ) ) ) );
	}

	#[Test]
	public function a_classic_post_is_not_recognised(): void {
		$parser = new BlockParser();

		$this->assertFalse( $parser->recognises( $this->post( '<p>Plain post</p>' ) ) );
		$this->assertFalse( $parser->recognises( $this->post( '' ) ) );
		$this->assertFalse( $parser->recognises( $this->post( "<!-- just a comment -->\n<p>Text</p>" ) ) );
	}

	/**
	 * Elementor keeps a copy of its output in `post_content`, and the Elementor
	 * driver owns the document whichever driver is asked first.
	 */
	#[Test]
	public function an_elementor_post_is_never_recognised_whatever_its_content_holds(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] = '[{"id":"a1","elType":"section"}]';

		$this->assertFalse( ( new BlockParser() )->recognises( $this->post( $this->para( 'Hello' ) ) ) );
	}

	#[Test]
	public function a_blank_elementor_meta_does_not_stop_a_block_post_being_recognised(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] = "  \n ";

		$this->assertTrue( ( new BlockParser() )->recognises( $this->post( $this->para( 'Hello' ) ) ) );
	}

	// -----------------------------------------------------------------
	// No policy anywhere
	// -----------------------------------------------------------------

	#[Test]
	public function with_no_policy_each_top_level_block_is_one_inherit_fragment(): void {
		$fragments = $this->fragments(
			$this->page(
				$this->para( 'One' ),
				$this->group( array( $this->para( 'Two' ), $this->para( 'Three' ) ) ),
				$this->para( 'Four' )
			)
		);

		$this->assertCount( 3, $fragments );

		foreach ( $fragments as $fragment ) {
			$this->assertSame( array( 'mode' => 'inherit' ), $fragment['policy'] );
			$this->assertTrue( $fragment['payload']['extracted'] );
		}

		$this->assertSame( array( 'block:core/paragraph', 'block:core/group', 'block:core/paragraph' ), array_column( $fragments, 'type' ) );
		$this->assertSame( array( 0, 1, 2 ), array_column( $fragments, 'position' ) );
	}

	#[Test]
	public function a_fragment_has_exactly_the_canonical_shape(): void {
		$fragments = $this->fragments( $this->para( 'One' ) );

		$this->assertSame(
			array(
				array(
					'id'       => 'block-0',
					'position' => 0,
					'type'     => 'block:core/paragraph',
					'payload'  => array( 'html' => '<p>One</p>', 'extracted' => true ),
					'policy'   => array( 'mode' => 'inherit' ),
				),
			),
			$fragments
		);
	}

	/** The saved markup, with the inner blocks where the markup put them. */
	#[Test]
	public function a_static_blocks_html_is_rebuilt_from_its_saved_markup(): void {
		$fragments = $this->fragments( $this->group( array( $this->para( 'One' ), $this->para( 'Two' ) ) ) );

		$this->assertSame( '<div class="wp-block-group"><p>One</p><p>Two</p></div>', $fragments[0]['payload']['html'] );
	}

	/** Display conditions are not exported, as in the Elementor driver. */
	#[Test]
	public function display_conditions_are_not_exported(): void {
		$access    = array( 'conditionsEnabled' => true, 'conditions' => array( 'role:editor' ), 'fallbackId' => 9 );
		$fragments = $this->fragments( $this->para( 'Conditioned', $access ) );

		$this->assertSame( array( 'mode' => 'inherit' ), $fragments[0]['policy'] );
		$this->assertSame( array( 'id', 'position', 'type', 'payload', 'policy' ), array_keys( $fragments[0] ) );
		$this->assertStringNotContainsString( 'role:editor', (string) json_encode( $fragments ) );
	}

	#[Test]
	public function a_block_with_an_inherit_attribute_is_not_a_policy(): void {
		$fragments = $this->fragments( $this->page( $this->group( array( $this->para( 'A', array( 'mode' => 'inherit' ) ) ) ) ) );

		$this->assertCount( 1, $fragments, 'nothing below the group carries a policy, so it is exported whole' );
		$this->assertSame( 'block:core/group', $fragments[0]['type'] );
	}

	// -----------------------------------------------------------------
	// Splitting on a policy
	// -----------------------------------------------------------------

	/**
	 * The restricted block is two levels down. A fragment per top-level block
	 * would have shipped it inside the outer Group, under the Group's audience.
	 */
	#[Test]
	public function a_restricted_block_two_levels_down_splits_the_group_around_it(): void {
		$fragments = $this->fragments(
			$this->page(
				$this->group(
					array(
						$this->para( 'Before' ),
						$this->group( array( $this->para( 'Secret', $this->members() ) ) ),
						$this->para( 'After' ),
					)
				),
				$this->para( 'Sibling' )
			)
		);

		$this->assertSame( array( 'block-0-0', 'block-0-1-0', 'block-0-2', 'block-2' ), $this->ids( $fragments ) );
		$this->assertSame(
			array(
				'block-0-0'   => array( 'mode' => 'inherit' ),
				'block-0-1-0' => array( 'mode' => 'active_member' ),
				'block-0-2'   => array( 'mode' => 'inherit' ),
				'block-2'     => array( 'mode' => 'inherit' ),
			),
			$this->policies( $fragments )
		);
		$this->assertSame( array( 0, 1, 2, 3 ), array_column( $fragments, 'position' ) );
		$this->assertSame( '<p>Secret</p>', $fragments[1]['payload']['html'] );
	}

	/** The wrapper markup of a structural block is a fidelity gap, never a security one. */
	#[Test]
	public function a_structural_blocks_own_wrapper_is_not_exported(): void {
		$fragments = $this->fragments( $this->group( array( $this->para( 'Secret', $this->members() ), $this->para( 'Open' ) ) ) );

		$this->assertNotContains( 'block:core/group', array_column( $fragments, 'type' ) );
		$this->assertStringNotContainsString( 'wp-block-group', (string) json_encode( $fragments ) );
	}

	/**
	 * The one policy in the subtree is the block's own, and it folds onto the
	 * whole of it, so there is nothing to split.
	 */
	#[Test]
	public function a_block_whose_own_policy_is_the_only_one_is_exported_whole(): void {
		$fragments = $this->fragments( $this->group( array( $this->para( 'A' ), $this->para( 'B' ) ), $this->members() ) );

		$this->assertCount( 1, $fragments );
		$this->assertSame( 'block:core/group', $fragments[0]['type'] );
		$this->assertSame( array( 'mode' => 'active_member' ), $fragments[0]['policy'] );
		$this->assertSame( '<div class="wp-block-group"><p>A</p><p>B</p></div>', $fragments[0]['payload']['html'] );
	}

	#[Test]
	public function a_restricted_ancestor_policy_reaches_every_descendant_fragment(): void {
		$fragments = $this->fragments(
			$this->group(
				array(
					$this->para( 'Plain' ),
					$this->group( array( $this->para( 'Deeper' ), $this->para( 'Narrower', $this->tiers( self::TIER_A ) ) ) ),
				),
				$this->tiers( self::TIER_A, self::TIER_B )
			)
		);

		$this->assertSame( array( 'block-0-0', 'block-0-1-0', 'block-0-1-1' ), $this->ids( $fragments ) );

		foreach ( $this->policies( $fragments ) as $id => $policy ) {
			$expected = 'block-0-1-1' === $id ? array( self::TIER_A ) : array( self::TIER_A, self::TIER_B );

			$this->assertSame( 'selected_tiers', $policy['mode'], $id );
			$this->assertSame( $expected, $policy['tier_ids'], $id );
		}
	}

	// -----------------------------------------------------------------
	// Folding
	// -----------------------------------------------------------------

	/** The narrower of the two wins: plans are narrower than all active members. */
	#[Test]
	public function a_member_block_inside_a_tier_group_stays_with_the_tiers(): void {
		$fragments = $this->fragments( $this->group( array( $this->para( 'Child', $this->members() ), $this->para( 'Plain' ) ), $this->tiers( self::TIER_A ) ) );

		$this->assertSame(
			array(
				'block-0-0' => array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
				'block-0-1' => array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
			),
			$this->policies( $fragments )
		);
	}

	#[Test]
	public function a_tier_block_inside_a_member_group_is_narrowed_to_the_tiers(): void {
		$fragments = $this->fragments( $this->group( array( $this->para( 'Child', $this->tiers( self::TIER_A ) ) ), $this->members() ) );

		$this->assertSame( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ), $fragments[0]['policy'] );
	}

	#[Test]
	public function two_overlapping_tier_sets_intersect(): void {
		$fragments = $this->fragments(
			$this->group( array( $this->para( 'Child', $this->tiers( self::TIER_B, self::TIER_C ) ) ), $this->tiers( self::TIER_A, self::TIER_B ) )
		);

		$this->assertSame( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) ), $fragments[0]['policy'] );
	}

	/** "The group allows only A, this block allows only B" is unsatisfiable, not "pick one". */
	#[Test]
	public function two_disjoint_tier_sets_yield_an_empty_tier_list(): void {
		$fragments = $this->fragments(
			$this->group( array( $this->para( 'Child', $this->tiers( self::TIER_B ) ) ), $this->tiers( self::TIER_A ) )
		);

		$this->assertSame( array( 'mode' => 'selected_tiers', 'tier_ids' => array() ), $fragments[0]['policy'] );
	}

	// -----------------------------------------------------------------
	// Segments and entitlements
	// -----------------------------------------------------------------

	#[Test]
	public function a_segments_block_and_an_entitlements_block_export_their_policies(): void {
		$fragments = $this->fragments(
			$this->page(
				$this->para( 'Segment', array( 'mode' => 'segments', 'segmentIds' => array( '55555555-5555-5555-5555-555555555555' ) ) ),
				$this->para( 'Gold', array( 'mode' => 'selected_entitlements', 'entitlementKeys' => array( 'gold.access' ) ) )
			)
		);

		$this->assertSame(
			array( 'mode' => 'segments', 'segment_ids' => array( '55555555-5555-5555-5555-555555555555' ) ),
			$fragments[0]['policy']
		);
		$this->assertSame(
			array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'gold.access' ) ),
			$fragments[1]['policy']
		);
	}

	/** Unlike sets cannot be intersected, so the composition denies, as the gateway's does. */
	#[Test]
	public function a_segments_block_inside_a_tiers_group_composes_to_a_policy_nobody_satisfies(): void {
		$segment   = array( 'mode' => 'segments', 'segmentIds' => array( '55555555-5555-5555-5555-555555555555' ) );
		$fragments = $this->fragments( $this->group( array( $this->para( 'Narrower', $segment ) ), $this->tiers( self::TIER_A ) ) );

		$this->assertSame( array( 'mode' => 'selected_tiers', 'tier_ids' => array() ), $fragments[0]['policy'] );
	}

	// -----------------------------------------------------------------
	// Failing closed
	// -----------------------------------------------------------------

	#[Test]
	#[DataProvider( 'unreadableModes' )]
	public function an_unrecognised_mode_fails_closed( $mode ): void {
		$fragments = $this->fragments( $this->para( 'Careful', array( 'mode' => $mode ) ) );

		$this->assertSame( array( 'mode' => 'active_member' ), $fragments[0]['policy'], 'not inherit, and not public' );
	}

	public static function unreadableModes(): array {
		return array(
			'a made-up mode'                  => array( 'everyone_but_bob' ),
			'public, which a block cannot be' => array( 'public' ),
			'the wrong case'                  => array( 'Active_Member' ),
			'padded'                          => array( ' active_member' ),
			'an integer'                      => array( 5 ),
			'a list'                          => array( array( 'inherit' ) ),
		);
	}

	/**
	 * The attribute is present but is not an object. It is read as a restriction,
	 * so the block is its own fragment under a members-only policy and the Group
	 * around it, which has a policy below it, is structural.
	 */
	#[Test]
	#[DataProvider( 'attributesThatAreNotObjects' )]
	public function an_attribute_that_is_not_an_object_fails_closed_and_splits_its_parent( $attribute ): void {
		$bad = $this->open( 'core/paragraph', array( 'agendAccess' => $attribute ) ) . '<p>Careful</p>' . $this->close( 'core/paragraph' );

		$fragments = $this->fragments( $this->group( array( $this->para( 'Open' ), $bad ) ) );

		$this->assertSame( array( 'block-0-0', 'block-0-1' ), $this->ids( $fragments ) );
		$this->assertSame( array( 'mode' => 'inherit' ), $fragments[0]['policy'] );
		$this->assertSame( array( 'mode' => 'active_member' ), $fragments[1]['policy'], 'not inherit, and not public' );
		$this->assertNotContains( 'block:core/group', array_column( $fragments, 'type' ) );
	}

	public static function attributesThatAreNotObjects(): array {
		return array(
			'a string'  => array( 'members' ),
			'a number'  => array( 3 ),
			'true'      => array( true ),
			'false'     => array( false ),
			'a list'    => array( array( 'members' ) ),
		);
	}

	#[Test]
	public function an_attribute_that_is_null_is_no_attribute(): void {
		$content = $this->open( 'core/paragraph', array( 'agendAccess' => null ) ) . '<p>Open</p>' . $this->close( 'core/paragraph' );

		$this->assertSame( array( 'mode' => 'inherit' ), $this->fragments( $content )[0]['policy'] );
	}

	/** An unreadable mode must not be able to widen a group it sits inside. */
	#[Test]
	public function an_unrecognised_mode_never_widens_its_ancestors(): void {
		$fragments = $this->fragments( $this->group( array( $this->para( 'Careful', array( 'mode' => 'bogus' ) ) ), $this->tiers( self::TIER_A ) ) );

		$this->assertSame( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ), $fragments[0]['policy'] );
	}

	#[Test]
	public function selected_tiers_with_nothing_selected_is_a_policy_nobody_satisfies(): void {
		$fragments = $this->fragments( $this->para( 'Careful', array( 'mode' => 'selected_tiers' ) ) );

		$this->assertSame( array( 'mode' => 'selected_tiers', 'tier_ids' => array() ), $fragments[0]['policy'] );
	}

	// -----------------------------------------------------------------
	// Synced patterns
	// -----------------------------------------------------------------

	#[Test]
	public function a_synced_pattern_is_resolved_in_place(): void {
		$this->pattern( 900, $this->page( $this->para( 'From the pattern' ) ) );

		$fragments = $this->fragments( $this->page( $this->para( 'Before' ), $this->synced( 900 ), $this->para( 'After' ) ) );

		$this->assertSame( array( 'block-0', 'block-2', 'block-4' ), $this->ids( $fragments ) );
		$this->assertSame( 'block:core/block', $fragments[1]['type'] );
		$this->assertStringContainsString( 'From the pattern', $fragments[1]['payload']['html'] );
		$this->assertTrue( $fragments[1]['payload']['extracted'] );
		$this->assertSame( array( 'mode' => 'inherit' ), $fragments[1]['policy'] );
	}

	#[Test]
	public function a_policy_inside_a_synced_pattern_splits_it(): void {
		$this->pattern( 900, $this->para( 'Open' ) . $this->para( 'Members only', $this->members() ) );

		$fragments = $this->fragments( $this->page( $this->para( 'Before' ), $this->synced( 900 ) ) );

		$this->assertNotContains( 'block:core/block', array_column( $fragments, 'type' ), 'the reference itself is structural' );
		$this->assertSame( array( 'block-0', 'block-2-0', 'block-2-1' ), $this->ids( $fragments ) );
		$this->assertSame(
			array(
				'block-0'   => array( 'mode' => 'inherit' ),
				'block-2-0' => array( 'mode' => 'inherit' ),
				'block-2-1' => array( 'mode' => 'active_member' ),
			),
			$this->policies( $fragments )
		);
		$this->assertSame( '<p>Members only</p>', $fragments[2]['payload']['html'] );
	}

	/** A reference carrying a policy folds it into whatever its pattern holds. */
	#[Test]
	public function a_policy_on_the_reference_folds_into_the_patterns_blocks(): void {
		$this->pattern( 900, $this->para( 'Open' ) . $this->para( 'Narrower', $this->tiers( self::TIER_A ) ) );

		$fragments = $this->fragments( $this->synced( 900, $this->tiers( self::TIER_A, self::TIER_B ) ) );

		$this->assertSame(
			array(
				'block-0-0' => array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, self::TIER_B ) ),
				'block-0-1' => array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
			),
			$this->policies( $fragments )
		);
	}

	#[Test]
	public function a_restricted_reference_to_an_open_pattern_is_one_restricted_fragment(): void {
		$this->pattern( 900, $this->para( 'One' ) . $this->para( 'Two' ) );

		$fragments = $this->fragments( $this->synced( 900, $this->members() ) );

		$this->assertCount( 1, $fragments );
		$this->assertSame( '<p>One</p><p>Two</p>', $fragments[0]['payload']['html'] );
		$this->assertSame( array( 'mode' => 'active_member' ), $fragments[0]['policy'] );
	}

	/** Index paths continue through the pattern, so the same pattern twice repeats no id. */
	#[Test]
	public function the_same_pattern_used_twice_repeats_no_id(): void {
		$this->pattern( 900, $this->para( 'Open' ) . $this->para( 'Members only', $this->members() ) );

		$fragments = $this->fragments( $this->page( $this->synced( 900 ), $this->synced( 900 ) ) );

		$this->assertSame( array( 'block-0-0', 'block-0-1', 'block-2-0', 'block-2-1' ), $this->ids( $fragments ) );
		$this->assertSame( $this->ids( $fragments ), array_values( array_unique( $this->ids( $fragments ) ) ) );
	}

	#[Test]
	public function a_synced_pattern_inside_a_static_block_is_resolved_into_its_html(): void {
		$this->pattern( 900, $this->para( 'From the pattern' ) );

		$fragments = $this->fragments( $this->group( array( $this->para( 'Own' ), $this->synced( 900 ) ) ) );

		$this->assertCount( 1, $fragments );
		$this->assertSame( '<div class="wp-block-group"><p>Own</p><p>From the pattern</p></div>', $fragments[0]['payload']['html'] );
		$this->assertTrue( $fragments[0]['payload']['extracted'] );
	}

	/** A pattern with no policy of its own, that includes itself, ends at the guard. */
	#[Test]
	public function a_self_referencing_pattern_stops_at_the_cycle_guard(): void {
		$this->pattern( 900, $this->para( 'Before' ) . $this->synced( 900 ) );

		$fragments = $this->fragments( $this->synced( 900 ) );

		$this->assertCount( 1, $fragments );
		$this->assertSame( '<p>Before</p>', $fragments[0]['payload']['html'] );
		$this->assertFalse( $fragments[0]['payload']['extracted'], 'the repeat was not carried' );
	}

	/** Where the pattern is split, the repeat is a fragment of its own with nothing in it. */
	#[Test]
	public function a_cycle_in_a_split_pattern_yields_an_empty_non_extracted_fragment(): void {
		$this->pattern( 900, $this->para( 'Members only', $this->members() ) . $this->synced( 900 ) );

		$fragments = $this->fragments( $this->synced( 900 ) );

		$this->assertSame( array( 'block-0-0', 'block-0-1' ), $this->ids( $fragments ) );
		$this->assertSame( 'block:core/block', $fragments[1]['type'] );
		$this->assertSame( array( 'html' => '', 'extracted' => false ), $fragments[1]['payload'] );
		$this->assertSame( array( 'mode' => 'inherit' ), $fragments[1]['policy'] );
	}

	#[Test]
	public function two_patterns_that_include_each_other_end_at_the_guard(): void {
		$this->pattern( 900, $this->para( 'One' ) . $this->synced( 901 ) );
		$this->pattern( 901, $this->para( 'Two' ) . $this->synced( 900 ) );

		$fragments = $this->fragments( $this->synced( 900 ) );

		$this->assertCount( 1, $fragments );
		$this->assertSame( '<p>One</p><p>Two</p>', $fragments[0]['payload']['html'] );
		$this->assertFalse( $fragments[0]['payload']['extracted'] );
	}

	#[Test]
	public function patterns_nested_past_the_depth_limit_are_not_followed(): void {
		// 900 -> 901 -> ... -> 909 -> 910. 909 is the tenth open; 910 would be the eleventh.
		for ( $id = 900; $id < 909; $id++ ) {
			$this->pattern( $id, $this->synced( $id + 1 ) );
		}

		$this->pattern( 909, $this->para( 'Members only', $this->members() ) . $this->synced( 910 ) );
		$this->pattern( 910, $this->para( 'Too deep' ) );

		$fragments = $this->fragments( $this->synced( 900 ) );
		$last      = end( $fragments );

		$this->assertCount( 2, $fragments );
		$this->assertSame( array( 'mode' => 'active_member' ), $fragments[0]['policy'] );
		$this->assertSame( array( 'html' => '', 'extracted' => false ), $last['payload'] );
		$this->assertStringNotContainsString( 'Too deep', (string) json_encode( $fragments ) );
	}

	/** Fifty-seven references, fifty-three of them past the budget: no failure, no growth. */
	#[Test]
	public function references_past_the_budget_become_empty_non_extracted_fragments(): void {
		$this->pattern( 900, $this->para( 'From the pattern' ) );

		$references = array_fill( 0, 57, $this->synced( 900 ) );
		$fragments  = $this->fragments( $this->page( ...$references ) );

		$this->assertCount( 57, $fragments );

		foreach ( $fragments as $position => $fragment ) {
			$this->assertSame( 'block:core/block', $fragment['type'] );

			if ( $position < 50 ) {
				$this->assertSame( array( 'html' => '<p>From the pattern</p>', 'extracted' => true ), $fragment['payload'], (string) $position );
				continue;
			}

			$this->assertSame( array( 'html' => '', 'extracted' => false ), $fragment['payload'], (string) $position );
			$this->assertSame( array( 'mode' => 'inherit' ), $fragment['policy'] );
		}
	}

	/** A reference past the budget behaves as a cycle does, inside a split pattern too. */
	#[Test]
	public function a_reference_past_the_budget_inside_a_split_pattern_is_an_empty_fragment(): void {
		$this->pattern( 900, $this->para( 'Members only', $this->members() ) );
		$this->pattern( 901, $this->para( 'Open' ) );

		$filler    = array_fill( 0, 49, $this->synced( 901 ) );
		$fragments = $this->fragments( $this->page( ...array_merge( $filler, array( $this->synced( 900 ), $this->synced( 900 ) ) ) ) );

		// 49 filler references, then the first reference to 900 is the fiftieth
		// and resolves; the second is past the budget.
		$this->assertSame( 51, count( $fragments ) );
		$this->assertSame( array( 'mode' => 'active_member' ), $fragments[49]['policy'] );
		$this->assertSame( 'block:core/paragraph', $fragments[49]['type'] );
		$this->assertSame( 'block:core/block', $fragments[50]['type'] );
		$this->assertSame( array( 'html' => '', 'extracted' => false ), $fragments[50]['payload'] );
	}

	/** Ten fan-out, ten levels deep. Terminates, and says so in the payload. */
	#[Test]
	public function a_wide_tree_of_patterns_exports_a_bounded_fragment(): void {
		for ( $id = 900; $id < 910; $id++ ) {
			$this->pattern( $id, str_repeat( $this->synced( $id + 1 ), 10 ) );
		}

		$this->pattern( 910, $this->para( 'Leaf' ) );

		$fragments = $this->fragments( $this->synced( 900 ) );

		$this->assertCount( 1, $fragments );
		$this->assertFalse( $fragments[0]['payload']['extracted'] );
		$this->assertLessThanOrEqual( 50 * strlen( '<p>Leaf</p>' ), strlen( $fragments[0]['payload']['html'] ) );
		$this->assertSame( $fragments, $this->fragments( $this->synced( 900 ) ), 'the cut-off is deterministic' );
	}

	// -----------------------------------------------------------------
	// The patterns an export draws on
	// -----------------------------------------------------------------

	/** @return int[] */
	private function patternIds( string $content ): array {
		return array_map(
			static fn( WP_Post $post ): int => $post->ID,
			( new BlockParser() )->patterns( $this->post( $content ) )
		);
	}

	#[Test]
	public function the_patterns_are_listed_transitively_once_each_in_id_order(): void {
		$this->pattern( 902, $this->para( 'C' ) );
		$this->pattern( 901, $this->para( 'B' ) . $this->synced( 903 ) );
		$this->pattern( 903, $this->para( 'D' ) );

		$content = $this->page( $this->synced( 902 ), $this->synced( 901 ), $this->synced( 902 ) );

		$this->assertSame( array( 901, 902, 903 ), $this->patternIds( $content ) );
	}

	#[Test]
	public function a_pattern_that_cannot_be_used_is_not_listed(): void {
		$this->pattern( 900, $this->para( 'Draft' ), array( 'post_status' => 'draft' ) );
		$this->pattern( 901, $this->para( 'Fine' ) . $this->synced( 901 ) );

		$this->assertSame( array( 901 ), $this->patternIds( $this->page( $this->synced( 900 ), $this->synced( 901 ), $this->synced( 404 ) ) ) );
	}

	/** The fragments and the list are built from one expansion, so they cannot name different patterns. */
	#[Test]
	public function the_patterns_listed_are_exactly_the_ones_the_fragments_carry(): void {
		$references = array();

		for ( $i = 0; $i < 60; $i++ ) {
			$this->pattern( 1000 + $i, $this->para( 'PATTERN-' . $i ) );

			$references[] = $this->synced( 1000 + $i );
		}

		$content   = $this->page( ...$references );
		$fragments = $this->fragments( $content );
		$carried   = array();

		foreach ( $fragments as $fragment ) {
			if ( 1 === preg_match( '/PATTERN-(\d+)/', $fragment['payload']['html'], $m ) ) {
				$carried[] = 1000 + (int) $m[1];
			}
		}

		$this->assertCount( 50, $carried );
		$this->assertSame( $carried, $this->patternIds( $content ) );
	}

	#[Test]
	public function a_page_with_no_patterns_lists_none(): void {
		$this->assertSame( array(), $this->patternIds( $this->para( 'Plain' ) ) );
	}

	/**
	 * `core/block` has a render callback on a real site, which makes it a
	 * dynamic block. It is resolved, not skipped, and the callback is never run.
	 */
	#[Test]
	public function a_synced_reference_is_resolved_even_where_it_is_registered_as_dynamic(): void {
		$calls = array();

		register_block_type(
			'core/block',
			array(
				'render_callback' => static function () use ( &$calls ): string {
					$calls[] = 'core/block';

					return '<p>RENDERED</p>';
				},
			)
		);

		$this->pattern( 900, $this->para( 'From the pattern' ) );

		$fragments = $this->fragments( $this->synced( 900 ) );

		$this->assertSame( '<p>From the pattern</p>', $fragments[0]['payload']['html'] );
		$this->assertTrue( $fragments[0]['payload']['extracted'] );
		$this->assertSame( array(), $calls );
	}

	/**
	 * Each of these would otherwise be a way to export content WordPress would
	 * not show a visitor. None is a failure: the fragment is empty and keeps
	 * its policy.
	 */
	#[Test]
	#[DataProvider( 'unusableReferences' )]
	public function a_reference_that_cannot_be_used_yields_an_empty_non_extracted_fragment( string $reference, ?array $post ): void {
		if ( null !== $post ) {
			$this->pattern( 900, $this->para( 'SECRET PATTERN TEXT' ), $post );
		}

		$fragments = $this->fragments( $this->page( $this->para( 'Before' ), $reference ) );

		$this->assertCount( 2, $fragments );
		$this->assertSame( 'block-2', $fragments[1]['id'] );
		$this->assertSame( 'block:core/block', $fragments[1]['type'] );
		$this->assertSame( array( 'html' => '', 'extracted' => false ), $fragments[1]['payload'] );
		$this->assertSame( array( 'mode' => 'inherit' ), $fragments[1]['policy'] );
		$this->assertStringNotContainsString( 'SECRET PATTERN TEXT', (string) json_encode( $fragments ) );
	}

	public static function unusableReferences(): array {
		return array(
			'a post that does not exist'  => array( '<!-- wp:block {"ref":404} /-->', null ),
			'no ref at all'               => array( '<!-- wp:block /-->', null ),
			'a ref that is not a number'  => array( '<!-- wp:block {"ref":"abc"} /-->', null ),
			'an unpublished pattern'      => array( '<!-- wp:block {"ref":900} /-->', array( 'post_status' => 'draft' ) ),
			'a private pattern'           => array( '<!-- wp:block {"ref":900} /-->', array( 'post_status' => 'private' ) ),
			'a password protected one'    => array( '<!-- wp:block {"ref":900} /-->', array( 'post_password' => 'secret' ) ),
			'a post that is not a pattern' => array( '<!-- wp:block {"ref":900} /-->', array( 'post_type' => 'page' ) ),
		);
	}

	#[Test]
	public function an_unusable_reference_keeps_the_policy_folded_onto_it(): void {
		$fragments = $this->fragments( $this->group( array( $this->synced( 404 ), $this->para( 'Members only', $this->members() ) ) ) );

		$this->assertSame( array( 'block-0-0', 'block-0-1' ), $this->ids( $fragments ) );
		$this->assertSame( array( 'html' => '', 'extracted' => false ), $fragments[0]['payload'] );
	}

	// -----------------------------------------------------------------
	// Nothing is rendered
	// -----------------------------------------------------------------

	/** Registers a dynamic block that records every render and returns another post's text. */
	private function registerDynamic( string $name, array &$calls ): void {
		register_block_type(
			$name,
			array(
				'render_callback' => static function () use ( &$calls, $name ): string {
					$calls[] = $name;

					return '<p>OTHER POSTS RESTRICTED CONTENT</p>';
				},
			)
		);
	}

	#[Test]
	public function a_dynamic_block_yields_an_empty_non_extracted_payload(): void {
		$calls = array();

		$this->registerDynamic( 'test/dynamic', $calls );

		$fragments = $this->fragments( $this->open( 'test/dynamic', array(), true ) );

		$this->assertSame( 'block:test/dynamic', $fragments[0]['type'] );
		$this->assertSame( array( 'html' => '', 'extracted' => false ), $fragments[0]['payload'] );
		$this->assertSame( array(), $calls, 'a render callback must never run during an export' );
	}

	#[Test]
	public function a_dynamic_child_is_omitted_from_its_static_parents_html(): void {
		$calls = array();

		$this->registerDynamic( 'test/dynamic', $calls );

		$fragments = $this->fragments(
			$this->group( array( $this->para( 'Static' ), $this->open( 'test/dynamic', array(), true ), $this->para( 'Also static' ) ) )
		);

		$this->assertCount( 1, $fragments );
		$this->assertSame( '<div class="wp-block-group"><p>Static</p><p>Also static</p></div>', $fragments[0]['payload']['html'] );
		$this->assertFalse( $fragments[0]['payload']['extracted'], 'part of the subtree was not carried' );
		$this->assertStringNotContainsString( 'OTHER POSTS', (string) json_encode( $fragments ) );
		$this->assertSame( array(), $calls );
	}

	/**
	 * The spy is registered on a block that sits INSIDE a policy split, inside a
	 * synced pattern, inside a group: every path the walk takes.
	 */
	#[Test]
	public function no_render_callback_runs_on_any_path_through_the_walk(): void {
		$calls = array();

		$this->registerDynamic( 'test/dynamic', $calls );

		$this->pattern( 900, $this->para( 'Members only', $this->members() ) . $this->open( 'test/dynamic', array(), true ) );

		$fragments = $this->fragments(
			$this->page(
				$this->open( 'test/dynamic', array(), true ),
				$this->group( array( $this->synced( 900 ), $this->open( 'test/dynamic', array(), true ) ) ),
				$this->synced( 900, $this->members() )
			)
		);

		$this->assertNotEmpty( $fragments );
		$this->assertSame( array(), $calls );
		$this->assertStringNotContainsString( 'OTHER POSTS', (string) json_encode( $fragments ) );
	}

	/** A floor under the registry, which may not be populated when the connector asks. */
	#[Test]
	#[DataProvider( 'alwaysDynamic' )]
	public function the_core_blocks_that_pull_in_other_posts_are_dynamic_without_being_registered( string $name ): void {
		$content = $this->open( $name ) . '<div>markup that stands for other posts</div>' . $this->close( $name );

		$fragments = $this->fragments( $content );

		$this->assertSame( 'block:' . $name, $fragments[0]['type'] );
		$this->assertSame( array( 'html' => '', 'extracted' => false ), $fragments[0]['payload'] );
	}

	public static function alwaysDynamic(): array {
		return array(
			'query'         => array( 'core/query' ),
			'post-template' => array( 'core/post-template' ),
			'latest-posts'  => array( 'core/latest-posts' ),
			'post-content'  => array( 'core/post-content' ),
			'template-part' => array( 'core/template-part' ),
			'navigation'    => array( 'core/navigation' ),
		);
	}

	#[Test]
	public function a_block_that_is_not_registered_is_exported_from_its_saved_markup(): void {
		$content = $this->open( 'acme/callout' ) . '<aside>Saved markup</aside>' . $this->close( 'acme/callout' );

		$fragments = $this->fragments( $content );

		$this->assertSame( 'block:acme/callout', $fragments[0]['type'] );
		$this->assertSame( array( 'html' => '<aside>Saved markup</aside>', 'extracted' => true ), $fragments[0]['payload'] );
	}

	/** A restriction on a block inside a dynamic one is still seen, and still its own fragment. */
	#[Test]
	public function a_policy_inside_a_dynamic_blocks_inner_blocks_still_splits_it(): void {
		$content = $this->open( 'core/query' ) . '<div>' . $this->para( 'Members only', $this->members() ) . '</div>' . $this->close( 'core/query' );

		$fragments = $this->fragments( $content );

		$this->assertSame( array( 'block-0-0' ), $this->ids( $fragments ) );
		$this->assertSame( array( 'mode' => 'active_member' ), $fragments[0]['policy'] );
	}

	// -----------------------------------------------------------------
	// Freeform content
	// -----------------------------------------------------------------

	#[Test]
	public function blank_freeform_chunks_are_skipped(): void {
		$fragments = $this->fragments( "\n\n" . $this->page( $this->para( 'One' ), $this->para( 'Two' ) ) . "\n\n  \n" );

		$this->assertSame( array( 'block-1', 'block-3' ), $this->ids( $fragments ), 'positions in the document, not in the output' );
		$this->assertSame( array( 0, 1 ), array_column( $fragments, 'position' ) );
	}

	#[Test]
	public function non_blank_freeform_content_is_exported(): void {
		$fragments = $this->fragments( "Classic intro text\n\n" . $this->para( 'A block' ) . "\n\n<p>Classic outro</p>" );

		$this->assertSame( array( 'block:freeform', 'block:core/paragraph', 'block:freeform' ), array_column( $fragments, 'type' ) );
		$this->assertSame( 'Classic intro text' . "\n\n", $fragments[0]['payload']['html'] );
		$this->assertTrue( $fragments[0]['payload']['extracted'] );
		$this->assertSame( array( 'mode' => 'inherit' ), $fragments[0]['policy'] );
		$this->assertSame( "\n\n" . '<p>Classic outro</p>', $fragments[2]['payload']['html'] );
	}

	#[Test]
	public function freeform_content_beside_a_split_block_is_not_dropped(): void {
		$fragments = $this->fragments( "Intro\n\n" . $this->para( 'Members only', $this->members() ) );

		$this->assertSame( array( 'block:freeform', 'block:core/paragraph' ), array_column( $fragments, 'type' ) );
		$this->assertStringContainsString( 'Intro', $fragments[0]['payload']['html'] );
	}

	// -----------------------------------------------------------------
	// Ids and order
	// -----------------------------------------------------------------

	private function busyDocument(): string {
		$this->pattern( 900, $this->para( 'Open' ) . $this->para( 'Members only', $this->members() ) );

		return "Intro\n\n" . $this->page(
			$this->group(
				array(
					$this->para( 'A' ),
					$this->group( array( $this->para( 'B', $this->tiers( self::TIER_A ) ), $this->para( 'C' ) ) ),
					$this->synced( 900 ),
				)
			),
			$this->synced( 900 ),
			$this->para( 'D' ),
			$this->open( 'core/query', array(), true )
		);
	}

	#[Test]
	public function ids_are_deterministic_across_two_parses(): void {
		$content = $this->busyDocument();

		$this->assertSame( $this->fragments( $content ), $this->fragments( $content ) );
	}

	#[Test]
	public function ids_are_unique_within_a_document(): void {
		$ids = $this->ids( $this->fragments( $this->busyDocument() ) );

		$this->assertGreaterThan( 8, count( $ids ) );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	#[Test]
	public function ids_are_the_index_path_joined_by_dashes(): void {
		$fragments = $this->fragments(
			$this->page(
				$this->para( 'Zero' ),
				$this->para( 'One' ),
				$this->group( array( $this->para( 'Two-zero' ), $this->group( array( $this->para( 'Two-one-zero', $this->members() ) ) ) ) )
			)
		);

		$this->assertSame( array( 'block-0', 'block-2', 'block-4-0', 'block-4-1-0' ), $this->ids( $fragments ) );
	}

	#[Test]
	public function position_is_the_running_order_of_emitted_fragments(): void {
		$fragments = $this->fragments( $this->busyDocument() );

		$this->assertSame( range( 0, count( $fragments ) - 1 ), array_column( $fragments, 'position' ) );
	}

	// -----------------------------------------------------------------
	// Never throws
	// -----------------------------------------------------------------

	/**
	 * Block markup is user content. A document that is broken, odd or hostile
	 * still exports, as best it can, and never fails the sync.
	 */
	#[Test]
	#[DataProvider( 'awkwardContent' )]
	public function awkward_content_never_throws( string $content ): void {
		$fragments = $this->fragments( $content );

		$this->assertIsArray( $fragments );

		foreach ( $fragments as $fragment ) {
			$this->assertSame( array( 'id', 'position', 'type', 'payload', 'policy' ), array_keys( $fragment ) );
		}
	}

	public static function awkwardContent(): array {
		return array(
			'nothing at all'                 => array( '' ),
			'whitespace only'                => array( "\n\n  " ),
			'an unclosed block'              => array( '<!-- wp:group --><div><!-- wp:paragraph --><p>Open' ),
			'a closer with no opener'        => array( '<p>Text</p><!-- /wp:group -->' ),
			'attributes that are not JSON'   => array( '<!-- wp:paragraph {not json} --><p>Text</p><!-- /wp:paragraph -->' ),
			'a block name nobody registered' => array( '<!-- wp:vendor/never-heard-of-it {"a":1} /-->' ),
			'an attribute that is a string'  => array( '<!-- wp:paragraph {"agendAccess":"members"} --><p>Text</p><!-- /wp:paragraph -->' ),
			'an attribute that is a list'    => array( '<!-- wp:paragraph {"agendAccess":["members"]} --><p>Text</p><!-- /wp:paragraph -->' ),
			'a ref that is an object'        => array( '<!-- wp:block {"ref":{"a":1}} /-->' ),
			'a very deep nest'               => array( str_repeat( '<!-- wp:group -->', 200 ) . str_repeat( '<!-- /wp:group -->', 200 ) ),
		);
	}

	#[Test]
	public function a_document_with_no_blocks_yields_no_fragments_when_asked_anyway(): void {
		$this->assertSame( array(), $this->fragments( '' ) );
	}
}
