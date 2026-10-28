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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * An editing session is authoring for the posts the user may edit, and no others.
 *
 * A wp-admin screen, the block editor's own REST render and Elementor's edit mode
 * are shapes of request, not capabilities. The block editor check used to test
 * `edit_post` on the GLOBAL post only, and every other check let any wp-admin
 * request through, so a contributor who named their own draft as the post, or
 * opened the REST edit context on it, or simply opened a wp-admin list screen,
 * was authoring every post the request went on to render. A Latest Posts block
 * inside that draft then printed the body of every members-only post it listed,
 * and a list screen printed their generated excerpts.
 *
 * Ported from the reviewer's scratch test with the assertions flipped: that test
 * proved the contributor read all of it.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class AuthoringPerPostTest extends BlockEnforcementTestCase {

	private const OWN     = 42;
	private const MEMBERS = 71;
	private const RULED   = 74;

	private const RENDERER = '/wp/v2/block-renderer/core/latest-posts';

	protected function tearDown(): void {
		unset( $_GET['context'] );

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	/** A contributor, who may edit the one post they wrote and nobody else's. */
	private function contributorOwning( int $id ): void {
		$this->asContributor();
		$GLOBALS['agend_test_current_user_can_for']['edit_post'][ $id ] = true;
	}

	private function restRequest( string $route ): void {
		$GLOBALS['agend_test_rest_request'] = true;
		$GLOBALS['wp']                      = (object) array( 'query_vars' => array( 'rest_route' => $route ) );
	}

	private function elementorEditing(): void {
		Agend_Test_WP::$did_action['elementor/loaded']     = 1;
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
	}

	/**
	 * The contributor's draft, and two posts that are not theirs: one members only
	 * as a whole, one public with a members-only block. A Latest Posts block lists
	 * the last two, as a stand-in for core's callback.
	 */
	private function latestPostsFixture(): void {
		$this->registerPost( self::OWN, '' );
		$this->registerPost( self::MEMBERS, 'THE MEMBERS BODY', array( 'mode' => 'active_member' ) );
		$this->registerPost(
			self::RULED,
			$this->paragraph( 'PUBLIC OPENING' ) . $this->group( array( 'mode' => 'active_member' ), $this->paragraph( 'THE BLOCK SECRET' ) )
		);

		foreach ( array( self::OWN, self::MEMBERS, self::RULED ) as $id ) {
			$GLOBALS['agend_test_posts'][ $id ]['post_excerpt'] = '';
		}

		new Agend_Content_Access_Frontend();

		// `wp_trim_excerpt()` at priority 10, which is what builds the excerpt of a
		// post that has none out of its body.
		add_filter(
			'get_the_excerpt',
			static function ( $excerpt, $post = null ) {
				return '' !== trim( (string) $excerpt ) || ! $post instanceof \WP_Post ? $excerpt : $post->post_content;
			},
			10,
			2
		);

		register_block_type(
			'core/latest-posts',
			array(
				'render_callback' => static function ( array $attributes ): string {
					$markup = '';

					foreach ( array( self::MEMBERS, self::RULED ) as $id ) {
						$post = get_post( $id );

						if ( ! empty( $attributes['displayPostContent'] ) && 'excerpt' === ( $attributes['displayPostContentRadio'] ?? '' ) ) {
							$markup .= '<div class="excerpt">' . Agend_Test_WP::apply_registered( 'get_the_excerpt', (string) $post->post_excerpt, $post ) . '</div>';
						}

						if ( ! empty( $attributes['displayPostContent'] ) && 'full_post' === ( $attributes['displayPostContentRadio'] ?? '' ) ) {
							$markup .= '<div class="full">' . wp_kses_post( $post->post_content ) . '</div>';
						}
					}

					return '<ul>' . $markup . '</ul>';
				},
			)
		);
	}

	/** The pipeline core runs for a block: the data filter, then the render. */
	private function renderLatest( string $mode ): string {
		$block = array(
			'blockName'    => 'core/latest-posts',
			'attrs'        => array( 'displayPostContent' => true, 'displayPostContentRadio' => $mode ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		$block = Agend_Test_WP::apply_registered( 'render_block_data', $block, $block, null );

		return render_block( $block );
	}

	private function assertNothingLeaked( string $html ): void {
		$this->assertStringNotContainsString( 'THE MEMBERS BODY', $html );
		$this->assertStringNotContainsString( 'THE BLOCK SECRET', $html );
	}

	// -----------------------------------------------------------------------
	// The block renderer, with a post the user owns
	// -----------------------------------------------------------------------

	#[Test]
	public function the_block_renderer_with_their_own_post_id_does_not_print_other_posts_bodies(): void {
		$this->latestPostsFixture();
		$this->contributorOwning( self::OWN );
		$this->restRequest( self::RENDERER );
		$this->inTheLoopOf( self::OWN ); // The controller sets the global post from `post_id`.

		$this->assertNothingLeaked( $this->renderLatest( 'full_post' ) );
		$this->assertNothingLeaked( $this->renderLatest( 'excerpt' ) );
	}

	/** The same thing through the editor's own load of the contributor's post. */
	#[Test]
	public function the_edit_context_of_their_own_post_does_not_print_other_posts_bodies(): void {
		$this->latestPostsFixture();
		$this->contributorOwning( self::OWN );
		$this->restRequest( '/wp/v2/posts/42' );
		$_GET['context'] = 'edit';
		$this->inTheLoopOf( self::OWN );

		$this->assertNothingLeaked( $this->renderLatest( 'full_post' ) );
		$this->assertNothingLeaked( $this->renderLatest( 'excerpt' ) );
	}

	/** The control: the same contributor on the front end is filtered. */
	#[Test]
	public function the_front_end_contributor_is_filtered(): void {
		$this->latestPostsFixture();
		$this->contributorOwning( self::OWN );
		$this->inTheLoopOf( self::OWN );

		$this->assertNothingLeaked( $this->renderLatest( 'full_post' ) );
	}

	/** What the contributor may see is still theirs: the carve-out is per post, not removed. */
	#[Test]
	public function the_contributors_own_post_still_renders_whole_in_the_block_renderer(): void {
		$this->latestPostsFixture();
		$this->contributorOwning( self::OWN );
		$this->restRequest( self::RENDERER );
		$GLOBALS['agend_test_posts'][ self::OWN ]['post_content'] = $this->paragraph( 'MY OWN SECRET', array( 'mode' => 'active_member' ) );
		$this->inTheLoopOf( self::OWN );

		$this->assertSame( '<p>MY OWN SECRET</p>', do_blocks( (string) get_post( self::OWN )->post_content ) );
		$this->assertNotSame( '', get_the_excerpt( get_post( self::OWN ) ) );
	}

	/** An editor, who may edit every post, is authoring them all, so the preview shows what they configured. */
	#[Test]
	public function an_editor_in_the_block_renderer_is_authoring_the_posts_they_may_edit(): void {
		$this->latestPostsFixture();
		$this->asEditor();
		$this->restRequest( self::RENDERER );
		$this->inTheLoopOf( self::OWN );

		$html = $this->renderLatest( 'full_post' );

		$this->assertStringContainsString( 'THE MEMBERS BODY', $html );
		$this->assertStringContainsString( '<div class="full">', $html );
	}

	// -----------------------------------------------------------------------
	// A wp-admin screen
	// -----------------------------------------------------------------------

	/** A list screen in extended view prints the excerpt of every post it lists. */
	#[Test]
	public function a_wp_admin_list_screen_does_not_print_a_contributors_view_of_other_posts_excerpts(): void {
		$this->latestPostsFixture();
		$this->contributorOwning( self::OWN );
		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertNothingLeaked( get_the_excerpt( get_post( self::MEMBERS ) ) );
		$this->assertNothingLeaked( get_the_excerpt( get_post( self::RULED ) ) );
	}

	#[Test]
	public function a_wp_admin_list_screen_still_prints_the_contributors_own_excerpt(): void {
		$this->latestPostsFixture();
		$this->contributorOwning( self::OWN );
		$GLOBALS['agend_test_posts'][ self::OWN ]['post_content'] = 'MY OWN DRAFT';
		$GLOBALS['agend_test_is_admin']                           = true;

		$this->assertSame( 'MY OWN DRAFT', get_the_excerpt( get_post( self::OWN ) ) );
	}

	#[Test]
	public function an_editor_on_a_wp_admin_list_screen_reads_every_excerpt(): void {
		$this->latestPostsFixture();
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertStringContainsString( 'THE MEMBERS BODY', get_the_excerpt( get_post( self::MEMBERS ) ) );
	}

	/** The Latest Posts block on a wp-admin screen is switched for the contributor, whose list is then judged per post. */
	#[Test]
	public function a_contributor_on_a_wp_admin_screen_gets_the_latest_posts_block_in_excerpt_mode(): void {
		$this->latestPostsFixture();
		$this->contributorOwning( self::OWN );
		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertNothingLeaked( $this->renderLatest( 'full_post' ) );
	}

	// -----------------------------------------------------------------------
	// The question, directly
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: string}> The kinds of editing session. */
	public static function editingSessions(): array {
		return array(
			'a wp-admin screen'    => array( 'admin' ),
			'a block editor render' => array( 'block_renderer' ),
			'the edit context'     => array( 'edit_context' ),
			'elementors edit mode' => array( 'elementor' ),
		);
	}

	private function beginSession( string $kind ): void {
		switch ( $kind ) {
			case 'admin':
				$GLOBALS['agend_test_is_admin'] = true;
				break;
			case 'block_renderer':
				$this->restRequest( self::RENDERER );
				break;
			case 'edit_context':
				$this->restRequest( '/wp/v2/posts/42' );
				$_GET['context'] = 'edit';
				break;
			case 'elementor':
				$this->elementorEditing();
				break;
		}
	}

	#[Test]
	#[DataProvider( 'editingSessions' )]
	public function a_session_is_authoring_only_for_the_posts_the_user_may_edit( string $kind ): void {
		$this->registerPost( self::OWN, '' );
		$this->registerPost( self::MEMBERS, '' );
		$this->contributorOwning( self::OWN );
		$this->beginSession( $kind );
		// The post the request names is the user's own, which is what made every other post look authored.
		$this->inTheLoopOf( self::OWN );

		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_for_post( self::OWN ), 'their own post' );
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_for_post( self::MEMBERS ), 'a post that is not theirs' );
		$this->assertFalse( Agend_Content_Access_Frontend::renders_for_visitors( self::OWN ) );
		$this->assertTrue( Agend_Content_Access_Frontend::renders_for_visitors( self::MEMBERS ) );
	}

	#[Test]
	#[DataProvider( 'editingSessions' )]
	public function a_post_that_is_not_known_needs_the_capability_to_edit_anyones( string $kind ): void {
		$this->contributorOwning( self::OWN );
		$this->beginSession( $kind );

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_for_post( 0 ) );
		$this->assertTrue( Agend_Content_Access_Frontend::renders_for_visitors() );

		$GLOBALS['agend_test_current_user_can']['edit_others_posts'] = true;

		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_for_post( 0 ) );
		$this->assertFalse( Agend_Content_Access_Frontend::renders_for_visitors() );
	}

	#[Test]
	#[DataProvider( 'editingSessions' )]
	public function a_user_who_may_edit_nothing_is_never_authoring( string $kind ): void {
		$this->registerPost( self::OWN, '' );
		$this->asVisitor();
		$this->beginSession( $kind );

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_for_post( self::OWN ) );
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_for_post( 0 ) );
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	/** A request with no editing session grants nothing, whatever the user may edit. */
	#[Test]
	public function a_front_end_request_is_authoring_for_nothing(): void {
		$this->registerPost( self::OWN, '' );
		$this->asEditor();

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_for_post( self::OWN ) );
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_for_post( 0 ) );
	}

	/** The page gate asks about the page the request is for, so it follows the same rule. */
	#[Test]
	public function the_page_gate_is_asked_about_the_requested_page(): void {
		$this->registerPost( self::OWN, '' );
		$this->registerPost( self::MEMBERS, '' );
		$this->contributorOwning( self::OWN );
		$this->elementorEditing();

		Agend_Test_WP::$queried_object_id = self::MEMBERS;
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context(), 'the editor is open on their draft, the request is for another page' );

		Agend_Test_WP::$queried_object_id = self::OWN;
		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_context() );
	}
}
