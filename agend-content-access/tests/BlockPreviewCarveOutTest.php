<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use Agend_Content_Access_Frontend;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * Elementor's preview flag, and what it does to blocks.
 *
 * `?elementor-preview=<id>` is a query-string value anyone can append to any URL,
 * and Elementor only checks that the user may edit the id it carries. The block
 * driver stands down for an author, so a flag it honoured on any page would let a
 * contributor read every restricted block of a page by appending the id of their
 * own draft. It is honoured on the page it names, and for the blocks of that page.
 *
 * And for those only. Anybody who can edit one post can put a Query Loop, a
 * template or a shortcode in it that renders restricted posts they cannot read,
 * open its preview, and have the block driver stand down for every block that
 * request renders. A block is judged against the post it belongs to, so the
 * previewed post's own blocks render for its author and the blocks of every other
 * post render as a visitor's would.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class BlockPreviewCarveOutTest extends BlockEnforcementTestCase {

	private const OWN        = 42;
	private const RESTRICTED = 300;

	/** @var object Preview double the suite installed, put back after each test. */
	private $original_preview;

	protected function setUp(): void {
		parent::setUp();

		$this->original_preview = \Elementor\Plugin::$instance->preview;

		Agend_Test_WP::$did_action['elementor/loaded'] = 1;

		// Elementor, which has checked the edit right on the id it was handed and
		// compared it with the query string, as the real one does.
		\Elementor\Plugin::$instance->preview = new class() {
			public bool $is_preview_mode = false;

			public function is_preview_mode( $post_id = 0 ): bool {
				return isset( $_GET['elementor-preview'] )
					&& 42 === (int) $post_id
					&& 42 === (int) $_GET['elementor-preview'];
			}
		};

		$this->registerPost( self::OWN, $this->secret() );
		$this->registerPost( self::RESTRICTED, $this->secret() );

		$this->asContributor();
	}

	protected function tearDown(): void {
		unset( $_GET['elementor-preview'] );

		\Elementor\Plugin::$instance->preview = $this->original_preview;

		parent::tearDown();
	}

	private function secret(): string {
		return $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) );
	}

	/** The request is for this page, singular, the way `template_redirect` sees it. */
	private function requestPage( int $id ): void {
		Agend_Test_WP::$queried_object_id     = $id;
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( $id );
	}

	// -----------------------------------------------------------------------
	// The flag on a page it does not name
	// -----------------------------------------------------------------------

	#[Test]
	public function a_preview_id_for_another_post_does_not_stand_the_block_driver_down(): void {
		$this->requestPage( self::RESTRICTED );
		$_GET['elementor-preview'] = (string) self::OWN;

		$this->assertSame( '', $this->renderPost( self::RESTRICTED ) );
	}

	#[Test]
	public function the_same_flag_on_the_page_it_names_still_shows_the_author_their_blocks(): void {
		$this->requestPage( self::OWN );
		$_GET['elementor-preview'] = (string) self::OWN;

		$this->assertSame( '<p>SECRET</p>', $this->renderPost( self::OWN ) );
	}

	#[Test]
	public function without_the_flag_the_authors_own_page_is_filtered_like_any_other(): void {
		$this->requestPage( self::OWN );

		$this->assertSame( '', $this->renderPost( self::OWN ) );
	}

	// -----------------------------------------------------------------------
	// A preview covers the previewed post and no other
	// -----------------------------------------------------------------------

	private function previewOwnPost(): void {
		$this->requestPage( self::OWN );
		$_GET['elementor-preview'] = (string) self::OWN;
	}

	#[Test]
	public function a_restricted_block_of_another_post_listed_inside_the_preview_is_a_visitors(): void {
		$this->previewOwnPost();

		$this->assertSame( '', $this->renderPost( self::RESTRICTED ), 'a loop in the previewed post listing a restricted post' );
		$this->assertSame( '<p>SECRET</p>', $this->renderPost( self::OWN ), 'while the previewed post itself still renders' );
	}

	/** A custom query's loop leaves `in_the_loop()` false: its post is still the post the block belongs to. */
	#[Test]
	public function the_item_of_a_custom_query_loop_inside_the_preview_is_judged_as_its_own_post(): void {
		$this->previewOwnPost();

		$own                     = get_post( self::OWN );
		$GLOBALS['wp_the_query'] = (object) array( 'post' => $own, 'posts' => array( $own ) );
		$GLOBALS['wp_query']     = $GLOBALS['wp_the_query'];
		$GLOBALS['post']         = get_post( self::RESTRICTED );

		try {
			$this->assertSame( '', do_blocks( $this->secret() ) );
		} finally {
			unset( $GLOBALS['wp_the_query'], $GLOBALS['wp_query'] );
		}
	}

	/** Outside any loop a block belongs to the queried post, which in a preview is the previewed one. */
	#[Test]
	public function a_block_outside_a_loop_belongs_to_the_previewed_post(): void {
		$this->previewOwnPost();

		$this->assertSame( '<p>SECRET</p>', do_blocks( $this->secret() ) );
	}

	/** A request that is not for one post names no previewed post, so there is nothing to carve out. */
	#[Test]
	public function a_block_on_a_request_that_is_not_for_a_post_is_a_visitors(): void {
		$this->previewOwnPost();
		$GLOBALS['agend_test_is_singular']    = false;
		$GLOBALS['agend_test_queried_object'] = null;
		Agend_Test_WP::$queried_object_id     = 0;

		$this->assertSame( '', do_blocks( $this->secret() ) );
	}

	/** Not only `pre_render_block`: the backstop is asked about the same post. */
	#[Test]
	public function the_backstop_judges_a_block_of_another_post_as_a_visitors(): void {
		$this->previewOwnPost();
		$block = array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array( 'agendAccess' => array( 'mode' => 'active_member' ) ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<p>SECRET</p>',
			'innerContent' => array( '<p>SECRET</p>' ),
		);

		$this->inTheLoopOf( self::RESTRICTED );
		$this->assertSame( '', $this->blocks->render_block( '<p>SECRET</p>', $block, null ), 'a block of another post that skipped the front door' );

		$this->inTheLoopOf( self::OWN );
		$this->assertSame( '<p>SECRET</p>', $this->blocks->render_block( '<p>SECRET</p>', $block, null ), 'the previewed post\'s own' );
	}

	/** A block holding a display condition is a block like any other. */
	#[Test]
	public function a_conditioned_block_of_another_post_is_judged_as_a_visitors_too(): void {
		$this->previewOwnPost();
		$note = $this->paragraph( 'NOTE', array( 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in' ) ) );
		$this->registerPost( 301, $note );

		$this->assertSame( '', $this->renderPost( 301 ) );
		$GLOBALS['agend_test_posts'][ self::OWN ]['post_content'] = $note;
		$this->assertSame( '<p>NOTE</p>', $this->renderPost( self::OWN ), 'the author sees the previewed post as they are building it' );
	}

	/** The note of a restricted block of another post is not the author's to read either. */
	#[Test]
	public function the_footnotes_of_another_post_are_filtered_inside_the_preview(): void {
		$this->previewOwnPost();
		$note_id = 'aaaaaaaa-0000-4000-8000-00000000000a';
		$anchor  = '<sup data-fn="' . $note_id . '" class="fn"><a href="#' . $note_id . '" id="' . $note_id . '-link">1</a></sup>';
		$notes   = (string) wp_json_encode( array( array( 'id' => $note_id, 'content' => 'SECRET NOTE' ) ) );

		$this->registerPost( 302, $this->paragraph( 'Cited' . $anchor, array( 'mode' => 'active_member' ) ) );
		$GLOBALS['agend_test_post_meta'][ 302 ]['footnotes'] = $notes;
		$GLOBALS['agend_test_posts'][ self::OWN ]['post_content'] = $this->paragraph( 'Cited' . $anchor, array( 'mode' => 'active_member' ) );
		$GLOBALS['agend_test_post_meta'][ self::OWN ]['footnotes'] = $notes;

		$this->assertSame( '[]', get_post_meta( 302, 'footnotes', true ), 'another post' );
		$this->assertSame( $notes, get_post_meta( self::OWN, 'footnotes', true ), 'the previewed post' );
	}

	/** The flag means nothing to somebody who may not edit: Elementor says yes to the id, the block driver still wants `edit_posts`. */
	#[Test]
	public function a_user_who_cannot_edit_is_not_let_in_by_a_preview_of_the_post(): void {
		$this->asVisitor();
		$this->previewOwnPost();

		$this->assertSame( '', $this->renderPost( self::OWN ) );
	}

	// -----------------------------------------------------------------------
	// Where authoring still covers every post
	// -----------------------------------------------------------------------

	/** Every post the user may edit: an editor, who may edit them all. See `AuthoringPerPostTest` for the user who may not. */
	#[Test]
	public function elementors_editor_is_authoring_for_every_post_the_user_may_edit(): void {
		$this->asEditor();
		$this->requestPage( self::OWN );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$this->assertSame( '<p>SECRET</p>', $this->renderPost( self::RESTRICTED ) );
	}

	#[Test]
	public function a_wp_admin_screen_is_authoring_for_every_post_the_user_may_edit(): void {
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertSame( '<p>SECRET</p>', $this->renderPost( self::RESTRICTED ) );
	}

	/** The block editor's own render is per post already, and a preview does not change that. */
	#[Test]
	public function the_block_editors_render_is_authoring_for_the_posts_the_user_may_edit(): void {
		$this->asEditor();
		$GLOBALS['agend_test_rest_request'] = true;
		$GLOBALS['wp']                      = (object) array( 'query_vars' => array( 'rest_route' => '/wp/v2/block-renderer/core/paragraph' ) );

		$this->assertSame( '<p>SECRET</p>', $this->renderPost( self::RESTRICTED ) );
	}

	/** The control: with no flag the author's own page is a visitor's, and so is the other one. */
	#[Test]
	public function without_the_flag_neither_post_is_authored(): void {
		$this->requestPage( self::OWN );

		$this->assertSame( '', $this->renderPost( self::RESTRICTED ) );
		$this->assertSame( '', $this->renderPost( self::OWN ) );
	}

	/** `absint()` turns any non-empty array into 1, so an array would read as the id of post 1. */
	#[Test]
	public function an_array_is_not_a_preview_id(): void {
		\Elementor\Plugin::$instance->preview = new class() {
			public bool $is_preview_mode = false;

			public function is_preview_mode( $post_id = 0 ): bool {
				return true;
			}
		};
		$this->registerPost( 1, $this->secret() );
		$this->requestPage( 1 );
		$_GET['elementor-preview'] = array( 'x' );

		$this->assertSame( '', $this->renderPost( 1 ) );
	}
}
