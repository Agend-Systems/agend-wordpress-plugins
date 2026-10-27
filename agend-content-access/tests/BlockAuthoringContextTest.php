<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use Agend_Content_Access_Frontend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * Authors are never filtered, and nobody else can make a request look like one.
 *
 * The block editor renders server-side over REST, so its renders must be
 * recognised or an author cannot see, lay out or repair the content a policy
 * hides. The same recognition is the one place a request can talk its way out of
 * enforcement, so what is tested is as much what does NOT qualify.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class BlockAuthoringContextTest extends BlockEnforcementTestCase {

	private const RENDERER = '/wp/v2/block-renderer/core/latest-posts';

	/** The REST request WordPress is dispatching, as `rest_api_loaded()` records it. */
	private function restRequest( string $route ): void {
		$GLOBALS['agend_test_rest_request'] = true;
		$GLOBALS['wp']                      = (object) array( 'query_vars' => array( 'rest_route' => $route ) );
	}

	private function secret(): string {
		return $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) );
	}

	// -----------------------------------------------------------------------
	// What qualifies
	// -----------------------------------------------------------------------

	#[Test]
	public function an_editor_using_the_block_renderer_sees_everything(): void {
		$this->asEditor();
		$this->restRequest( self::RENDERER );

		$this->assertTrue( Agend_Content_Access_Frontend::is_block_editor_request() );
		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_context() );
		$this->assertSame( '<p>BEFORE</p><p>SECRET</p>', do_blocks( $this->paragraph( 'BEFORE' ) . $this->secret() ) );
	}

	/** No identity lookup is made for an author: they are not judged at all. */
	#[Test]
	public function an_authors_render_costs_no_identity_lookup(): void {
		$this->asEditor();
		$this->restRequest( self::RENDERER );

		do_blocks( $this->secret() );

		$this->assertSame( 0, $this->identity_lookups );
	}

	#[Test]
	public function an_editor_asking_for_the_edit_context_sees_everything(): void {
		$this->asEditor();
		$this->restRequest( '/wp/v2/posts/12' );
		$_GET['context'] = 'edit';

		$this->assertTrue( Agend_Content_Access_Frontend::is_block_editor_request() );
		$this->assertSame( '<p>SECRET</p>', do_blocks( $this->secret() ) );
	}

	#[Test]
	public function a_route_without_a_leading_slash_is_read_the_same_way(): void {
		$this->asEditor();
		$this->restRequest( 'wp/v2/block-renderer/core/latest-posts' );

		$this->assertTrue( Agend_Content_Access_Frontend::is_block_editor_request() );
	}

	/** Editing THIS post, as the editor does when it loads it. */
	#[Test]
	public function an_editor_loading_a_post_they_may_edit_sees_it_whole(): void {
		$this->asEditor();
		$this->restRequest( '/wp/v2/posts/300' );
		$_GET['context'] = 'edit';
		$this->registerPost( 300, $this->secret() );

		$this->assertSame( '<p>SECRET</p>', $this->renderPost( 300 ) );
	}

	// -----------------------------------------------------------------------
	// What does not
	// -----------------------------------------------------------------------

	/** The route is the block renderer's, but the user is a visitor. */
	#[Test]
	public function a_non_editor_using_the_block_renderer_gets_no_carve_out(): void {
		$this->asVisitor();
		$this->restRequest( self::RENDERER );

		$this->assertFalse( Agend_Content_Access_Frontend::is_block_editor_request() );
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
		$this->assertSame( '', do_blocks( $this->secret() ) );
	}

	/** A signed-in user without `edit_posts`, such as a subscriber who is not a member. */
	#[Test]
	public function a_signed_in_non_member_who_cannot_edit_is_not_let_in_by_asking_for_the_edit_context(): void {
		$this->asMember();
		$this->asVisitor();
		$this->restRequest( '/wp/v2/posts/12' );
		$_GET['context'] = 'edit';

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
		$this->assertSame( '', do_blocks( $this->secret() ) );
	}

	/** ...and a member who cannot edit is judged as the member they are, not as an author. */
	#[Test]
	public function a_member_who_cannot_edit_is_judged_as_a_member(): void {
		$this->asMember( self::TIER_A );
		$this->asVisitor();
		$this->restRequest( '/wp/v2/posts/12' );
		$_GET['context'] = 'edit';

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
		$this->assertSame( '<p>SECRET</p>', do_blocks( $this->secret() ) );
		$this->assertGreaterThan( 0, $this->identity_lookups, 'they were judged, not waved through' );
	}

	/** Unauthenticated: REST drops a request with no valid nonce to signed-out before dispatch. */
	#[Test]
	public function an_unauthenticated_rest_request_gets_no_carve_out(): void {
		$GLOBALS['agend_test_current_user_id'] = 0;
		$this->asVisitor();
		$this->restRequest( self::RENDERER );
		$_GET['context'] = 'edit';

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
		$this->assertSame( '', do_blocks( $this->secret() ) );
	}

	/**
	 * `?context=edit` on an ordinary page URL is a front-end request. It is a
	 * query arg anyone can add, and it must never be enough on its own, even for
	 * somebody who can edit: they are reading a page, not authoring it.
	 */
	#[Test]
	public function a_context_edit_query_arg_on_a_front_end_url_does_not_qualify(): void {
		$this->asVisitor();
		$_GET['context'] = 'edit';

		$this->assertFalse( Agend_Content_Access_Frontend::is_block_editor_request() );
		$this->assertSame( '', do_blocks( $this->secret() ) );
	}

	#[Test]
	public function a_context_edit_query_arg_does_not_qualify_even_for_an_editor_off_rest(): void {
		$this->asEditor();
		$_GET['context'] = 'edit';

		$this->assertFalse( Agend_Content_Access_Frontend::is_block_editor_request() );
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	/** A front-end URL that merely names the block renderer route is not a REST request. */
	#[Test]
	public function the_route_alone_does_not_qualify_without_a_rest_request(): void {
		$this->asEditor();
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => self::RENDERER ) );

		$this->assertFalse( Agend_Content_Access_Frontend::is_block_editor_request() );
	}

	/** A header alone, whatever it claims. */
	#[Test]
	public function a_header_alone_does_not_qualify(): void {
		$this->asVisitor();
		$_SERVER['HTTP_X_WP_NONCE']       = 'anything';
		$_SERVER['HTTP_ACCEPT']           = 'application/json';
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

		try {
			$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
			$this->assertSame( '', do_blocks( $this->secret() ) );
		} finally {
			unset( $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH'] );
		}
	}

	/** An editor on some other REST route, asking for the default context, is reading. */
	#[Test]
	public function an_editor_on_an_unrelated_rest_route_without_the_edit_context_gets_no_carve_out(): void {
		$this->asEditor();
		$this->restRequest( '/wp/v2/posts/12' );
		$_GET['context'] = 'view';

		$this->assertFalse( Agend_Content_Access_Frontend::is_block_editor_request() );
	}

	/** @return array<string, array{0: mixed}> */
	public static function other_contexts(): array {
		return array(
			'embed'      => array( 'embed' ),
			'upper case' => array( 'EDIT' ),
			'padded'     => array( ' edit' ),
			'a list'     => array( array( 'edit' ) ),
		);
	}

	#[Test]
	#[DataProvider( 'other_contexts' )]
	public function only_the_edit_context_itself_qualifies( $context ): void {
		$this->asEditor();
		$this->restRequest( '/wp/v2/posts/12' );
		$_GET['context'] = $context;

		$this->assertFalse( Agend_Content_Access_Frontend::is_block_editor_request() );
	}

	/** @return array<string, array{0: string}> */
	public static function other_routes(): array {
		return array(
			'the bare prefix'   => array( '/wp/v2/block-renderer' ),
			'a lookalike'       => array( '/wp/v2/block-renderers/core/x' ),
			'another namespace' => array( '/acme/v1/wp/v2/block-renderer/core/x' ),
			'the batch route'   => array( '/batch/v1' ),
			'no route'          => array( '' ),
		);
	}

	#[Test]
	#[DataProvider( 'other_routes' )]
	public function only_the_block_renderer_route_qualifies_without_the_edit_context( string $route ): void {
		$this->asEditor();
		$this->restRequest( $route );

		$this->assertFalse( Agend_Content_Access_Frontend::is_block_editor_request() );
	}

	/**
	 * `context=edit` on a collection lists posts the user cannot edit. Without the
	 * per-post check a contributor reads every restricted block in every public
	 * page through `/wp/v2/pages?context=edit`.
	 */
	#[Test]
	public function a_contributor_listing_other_peoples_posts_with_the_edit_context_is_not_authoring_them(): void {
		$this->asEditor();
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
		$this->restRequest( '/wp/v2/pages' );
		$_GET['context'] = 'edit';
		$this->registerPost( 301, $this->secret() );

		$this->inTheLoopOf( 301 );
		$this->assertFalse( Agend_Content_Access_Frontend::is_block_editor_request() );
		$this->assertSame( '', do_blocks( $this->secret() ) );
	}

	/**
	 * A block renderer call with no `post_id` renders a template part, a pattern or
	 * a block of any post's content. A contributor can `edit_posts` and may not read
	 * those, so with no post in the request it takes `edit_others_posts`.
	 */
	#[Test]
	public function a_contributor_using_the_block_renderer_with_no_post_is_not_authoring(): void {
		$this->asContributor();
		$this->restRequest( self::RENDERER );

		$this->assertFalse( Agend_Content_Access_Frontend::is_block_editor_request() );
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
		$this->assertSame( '', do_blocks( $this->secret() ) );
	}

	#[Test]
	public function an_editor_using_the_block_renderer_with_no_post_is_authoring(): void {
		$this->asEditor();
		$this->restRequest( self::RENDERER );

		$this->assertTrue( Agend_Content_Access_Frontend::is_block_editor_request() );
		$this->assertSame( '<p>SECRET</p>', do_blocks( $this->secret() ) );
	}

	/** The same rule for the edit context on a route that is not the renderer's, with no post. */
	#[Test]
	public function a_contributor_asking_for_the_edit_context_with_no_post_is_not_authoring(): void {
		$this->asContributor();
		$this->restRequest( '/wp/v2/pages' );
		$_GET['context'] = 'edit';

		$this->assertFalse( Agend_Content_Access_Frontend::is_block_editor_request() );
		$this->assertSame( '', do_blocks( $this->secret() ) );
	}

	/** With a post the per-post rule is the only one: a contributor editing their OWN post is authoring it. */
	#[Test]
	public function a_contributor_rendering_a_post_they_may_edit_is_authoring_it(): void {
		$this->asContributor();
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
		$this->restRequest( self::RENDERER );
		$this->registerPost( 302, $this->secret() );
		$this->inTheLoopOf( 302 );

		$this->assertTrue( Agend_Content_Access_Frontend::is_block_editor_request() );
		$this->assertSame( '<p>SECRET</p>', do_blocks( $this->secret() ) );
	}

	/** ...and an editor, who can edit others' posts, still needs the post to be one they may edit. */
	#[Test]
	public function an_editor_who_cannot_edit_the_post_in_the_request_is_not_authoring_it(): void {
		$this->asEditor();
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
		$this->restRequest( self::RENDERER );
		$this->registerPost( 303, $this->secret() );
		$this->inTheLoopOf( 303 );

		$this->assertFalse( Agend_Content_Access_Frontend::is_block_editor_request() );
	}

	// -----------------------------------------------------------------------
	// The existing rules, and the render the loop gates share
	// -----------------------------------------------------------------------

	#[Test]
	public function the_existing_admin_rule_is_unchanged(): void {
		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	#[Test]
	public function a_front_end_request_is_not_authoring(): void {
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	/** An editor on a wp-admin screen is authoring, and sees the block. */
	#[Test]
	public function an_editor_on_a_wp_admin_screen_sees_everything(): void {
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertSame( '<p>SECRET</p>', do_blocks( $this->secret() ) );
	}

	/**
	 * `is_authoring_context()` calls any wp-admin request authoring, and a
	 * subscriber on a dashboard screen is not authoring anything.
	 */
	#[Test]
	public function a_user_who_cannot_edit_on_a_wp_admin_screen_is_still_filtered(): void {
		$this->asVisitor();
		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_context() );
		$this->assertSame( '', do_blocks( $this->secret() ) );
	}

	/**
	 * WordPress reports `is_admin()` for admin-ajax.php, and "load more" buttons
	 * and live filters print blocks to ANONYMOUS visitors through it. That is a
	 * visitor's render, and it is judged as one, even for somebody who can edit.
	 */
	#[Test]
	public function an_admin_ajax_render_is_a_visitors_render(): void {
		$GLOBALS['agend_test_is_admin']   = true;
		$GLOBALS['agend_test_doing_ajax'] = true;

		$this->assertSame( '', do_blocks( $this->secret() ) );

		$this->asEditor();

		$this->assertSame( '', do_blocks( $this->secret() ) );
	}
}
