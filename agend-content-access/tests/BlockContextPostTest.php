<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Post;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * Which post a block is judged against, inside and outside a loop.
 *
 * WordPress sets the global post to the first post of the MAIN query before any
 * template runs, and leaves it at the last once a loop has ended. A header or
 * footer template part on an archive therefore saw an unrelated post as "its"
 * post and was narrowed to that post's plan. Outside a loop a block belongs to the
 * queried post for a singular request and to nothing otherwise. Inside a loop,
 * the main one or a custom query's, it belongs to the item being rendered.
 */
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class BlockContextPostTest extends BlockEnforcementTestCase {

	private const MEMBERS = array( 'mode' => 'active_member' );

	private const FIRST  = 800;
	private const SECOND = 801;

	/** The first post is restricted to plan B, the second is public. */
	private function archive(): void {
		$this->registerPost( self::FIRST, '', array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) ) );
		$this->registerPost( self::SECOND, '' );

		$first  = get_post( self::FIRST );
		$second = get_post( self::SECOND );

		// What `WP::register_globals()` does: the global post is the main query's first post.
		$GLOBALS['wp_the_query'] = (object) array( 'post' => $first, 'posts' => array( $first, $second ) );
		$GLOBALS['wp_query']     = $GLOBALS['wp_the_query'];
		$GLOBALS['post']         = $first;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_the_query'], $GLOBALS['wp_query'], $GLOBALS['agend_test_in_the_loop'] );

		parent::tearDown();
	}

	private function header(): string {
		return $this->paragraph( 'MEMBERS HEADER', self::MEMBERS );
	}

	// -----------------------------------------------------------------------
	// Outside a loop
	// -----------------------------------------------------------------------

	/** The finding: a member-only header on an archive whose first post is restricted to another plan. */
	#[Test]
	public function a_member_only_header_on_an_archive_is_shown_to_an_entitled_member(): void {
		$this->asMember( self::TIER_A );
		$this->archive();

		$this->assertSame( '<p>MEMBERS HEADER</p>', do_blocks( $this->header() ) );
	}

	/** It is still the block's own policy that decides, so a visitor does not see it. */
	#[Test]
	public function the_same_header_is_still_hidden_from_a_visitor(): void {
		$this->archive();

		$this->assertSame( '', do_blocks( $this->header() ) );
	}

	/** After a loop the global post is the LAST post of the main query, and is no more the block's post. */
	#[Test]
	public function a_footer_after_the_loop_is_not_judged_against_the_last_post(): void {
		$this->asMember( self::TIER_A );
		$this->archive();
		$this->registerPost( self::SECOND, '', array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) ) );
		$last                = get_post( self::SECOND );
		$GLOBALS['wp_the_query']->posts[1] = $last;
		$GLOBALS['post']     = $last;

		$this->assertSame( '<p>MEMBERS HEADER</p>', do_blocks( $this->header() ) );
	}

	/** A singular request: the queried post is the document, and its policy narrows the block. */
	#[Test]
	public function on_a_singular_request_the_block_is_judged_against_the_queried_post(): void {
		$this->asMember( self::TIER_A );
		$this->archive();
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( self::FIRST );

		$this->assertSame( '', do_blocks( $this->header() ), 'its plan is B, and the member holds A' );
	}

	#[Test]
	public function on_a_singular_public_request_the_member_sees_the_block(): void {
		$this->asMember( self::TIER_A );
		$this->archive();
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( self::SECOND );

		$this->assertSame( '<p>MEMBERS HEADER</p>', do_blocks( $this->header() ) );
	}

	/** A request with no post at all, a widget or a bare template part, has no document to narrow with. */
	#[Test]
	public function with_no_global_post_and_no_singular_request_the_block_has_no_document(): void {
		$this->asMember( self::TIER_A );
		unset( $GLOBALS['post'] );

		$this->assertSame( '<p>MEMBERS HEADER</p>', do_blocks( $this->header() ) );
		$this->assertSame( array( 0 ), $this->uncacheable, 'marked, for no post' );
	}

	// -----------------------------------------------------------------------
	// Inside a loop
	// -----------------------------------------------------------------------

	/** The main loop, on its first item: the global post is also the main query's first post, and is the block's. */
	#[Test]
	public function in_the_main_loop_the_block_is_judged_against_the_item(): void {
		$this->asMember( self::TIER_A );
		$this->archive();
		$GLOBALS['agend_test_in_the_loop'] = true;

		$this->assertSame( '', do_blocks( $this->header() ), 'the first item is restricted to plan B' );
	}

	#[Test]
	public function in_the_main_loop_a_public_item_does_not_narrow_the_block(): void {
		$this->asMember( self::TIER_A );
		$this->archive();
		$GLOBALS['post']                   = get_post( self::SECOND );
		$GLOBALS['wp_the_query']->posts[1] = $GLOBALS['post'];
		$GLOBALS['agend_test_in_the_loop'] = true;

		$this->assertSame( '<p>MEMBERS HEADER</p>', do_blocks( $this->header() ) );
	}

	/**
	 * A custom `WP_Query` loop, which is what a Query Loop block with its own query
	 * runs: `in_the_loop()` is false and the global post is the item. It is a
	 * DIFFERENT object from the main query's, even for the same post.
	 */
	#[Test]
	public function in_a_custom_query_loop_the_block_is_judged_against_the_item(): void {
		$this->asMember( self::TIER_A );
		$this->archive();
		$this->registerPost( 802, '', array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) ) );
		$GLOBALS['post'] = get_post( 802 );

		$this->assertFalse( in_the_loop() );
		$this->assertSame( '', do_blocks( $this->header() ) );
	}

	/** The same post as the main query's first, read again by another query, is that query's item. */
	#[Test]
	public function in_a_custom_query_loop_the_same_post_as_the_main_querys_first_is_still_the_item(): void {
		$this->asMember( self::TIER_A );
		$this->archive();
		$GLOBALS['post'] = get_post( self::FIRST );

		$this->assertNotSame( $GLOBALS['wp_the_query']->post, $GLOBALS['post'] );
		$this->assertSame( '', do_blocks( $this->header() ) );
	}

	/** A REST controller sets the global post to the item, and the main query holds none. */
	#[Test]
	public function a_rest_render_is_judged_against_the_item(): void {
		$this->asMember( self::TIER_A );
		$this->registerPost( 803, '', array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) ) );
		$GLOBALS['wp_the_query'] = (object) array( 'post' => null, 'posts' => array() );
		$GLOBALS['wp_query']     = $GLOBALS['wp_the_query'];
		$GLOBALS['post']         = get_post( 803 );

		$this->assertSame( '', do_blocks( $this->header() ) );
	}

	/** A theme can point `$wp_query` at another query, and the real main query is still `$wp_the_query`. */
	#[Test]
	public function the_real_main_query_is_recognised_when_wp_query_points_elsewhere(): void {
		$this->asMember( self::TIER_A );
		$this->archive();
		$GLOBALS['wp_query'] = (object) array( 'post' => null, 'posts' => array() );

		$this->assertSame( '<p>MEMBERS HEADER</p>', do_blocks( $this->header() ) );
	}

	#[Test]
	public function the_leftover_is_recognised_through_wp_query_alone(): void {
		$this->asMember( self::TIER_A );
		$this->archive();
		unset( $GLOBALS['wp_the_query'] );

		$this->assertSame( '<p>MEMBERS HEADER</p>', do_blocks( $this->header() ) );
	}

	/** What is marked uncacheable is the post the block was judged against. */
	#[Test]
	public function the_response_is_marked_for_the_post_the_block_was_judged_against(): void {
		$this->archive();
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( self::SECOND );

		do_blocks( $this->header() );

		$this->assertSame( array( self::SECOND ), $this->uncacheable );
	}
}
