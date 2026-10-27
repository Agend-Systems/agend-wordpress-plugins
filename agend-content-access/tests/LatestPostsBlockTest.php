<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * The Latest Posts block prints raw post content in its "full post" display.
 *
 * Its render callback outputs `wp_kses_post( $post->post_content )` for each post
 * it lists, from the database row, so no content filter ever sees the body. It
 * leaked every restricted post it listed to an anonymous visitor.
 *
 * The block's render callback is stood in for by a copy of the one in core
 * (`block_core_latest_posts_render_latest_posts()`, WordPress 6.7): per listed
 * post, `get_the_excerpt()` in excerpt mode and the raw `post_content` in full
 * post mode. The pipeline is core's too: `render_block_data` first, then render.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class LatestPostsBlockTest extends BlockEnforcementTestCase {

	private const MEMBERS_ONLY = 71;
	private const PUBLIC_POST  = 72;
	private const SECTION_RULE = 73;
	private const BLOCK_RULE   = 74;

	private const FULL = array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' );

	protected function setUp(): void {
		parent::setUp();

		$this->registerPost( self::MEMBERS_ONLY, 'THE MEMBERS BODY', array( 'mode' => 'active_member' ) );
		$this->registerPost( self::PUBLIC_POST, 'THE PUBLIC BODY', array( 'mode' => 'public' ) );
		$this->registerPost( self::SECTION_RULE, 'PUBLIC OPENING AND THE SECTION SECRET' );
		$this->registerPost(
			self::BLOCK_RULE,
			$this->paragraph( 'PUBLIC BLOCK OPENING' ) . $this->paragraph( 'THE BLOCK SECRET', array( 'mode' => 'active_member' ) )
		);

		$GLOBALS['agend_test_post_meta'][ self::SECTION_RULE ]['_elementor_data'] = (string) wp_json_encode(
			array(
				array(
					'id'       => 'a',
					'elType'   => 'section',
					'settings' => array( 'agend_access_mode' => 'active_member' ),
					'elements' => array(),
				),
			)
		);

		foreach ( array( self::MEMBERS_ONLY, self::PUBLIC_POST, self::SECTION_RULE, self::BLOCK_RULE ) as $id ) {
			$GLOBALS['agend_test_posts'][ $id ]['post_excerpt'] = '';
		}

		new Agend_Content_Access_Frontend();

		// `wp_trim_excerpt()` at priority 10: derive an excerpt from the body.
		add_filter(
			'get_the_excerpt',
			static function ( $excerpt, $post = null ) {
				return '' !== trim( (string) $excerpt ) || ! $post instanceof \WP_Post ? $excerpt : $post->post_content;
			},
			10,
			2
		);

		register_block_type( 'core/latest-posts', array( 'render_callback' => array( $this, 'coreLatestPosts' ) ) );
	}

	/** Core's render callback for `core/latest-posts`, reduced to the part that matters. */
	public function coreLatestPosts( array $attributes ): string {
		$markup = '';

		foreach ( array( self::MEMBERS_ONLY, self::PUBLIC_POST, self::SECTION_RULE, self::BLOCK_RULE ) as $id ) {
			$post    = get_post( $id );
			$markup .= '<li><a href="#">' . $post->post_title . '</a>';

			if ( ! empty( $attributes['displayPostContent'] ) && isset( $attributes['displayPostContentRadio'] ) && 'excerpt' === $attributes['displayPostContentRadio'] ) {
				$markup .= '<div class="wp-block-latest-posts__post-excerpt">' . Agend_Test_WP::apply_registered( 'get_the_excerpt', (string) $post->post_excerpt, $post ) . '</div>';
			}

			if ( ! empty( $attributes['displayPostContent'] ) && isset( $attributes['displayPostContentRadio'] ) && 'full_post' === $attributes['displayPostContentRadio'] ) {
				$markup .= '<div class="wp-block-latest-posts__post-full-content">' . wp_kses_post( $post->post_content ) . '</div>';
			}

			$markup .= '</li>';
		}

		return '<ul class="wp-block-latest-posts">' . $markup . '</ul>';
	}

	private function block( array $attributes, string $name = 'core/latest-posts' ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attributes,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/** The pipeline `render_block()` runs in core: the data filter, then the render. */
	private function render( array $block ): string {
		$block = Agend_Test_WP::apply_registered( 'render_block_data', $block, $block, null );

		return render_block( $block );
	}

	private function filtered( array $block ): array {
		return Agend_Test_WP::apply_registered( 'render_block_data', $block, $block, null );
	}

	// -----------------------------------------------------------------------
	// The leak
	// -----------------------------------------------------------------------

	#[Test]
	public function the_stand_in_really_leaks_when_nothing_withholds_it(): void {
		$html = $this->coreLatestPosts( self::FULL );

		$this->assertStringContainsString( 'THE MEMBERS BODY', $html, 'the defect this test class exists for' );
	}

	#[Test]
	public function a_restricted_posts_body_never_appears_in_full_post_mode(): void {
		$html = $this->render( $this->block( self::FULL ) );

		$this->assertStringNotContainsString( 'THE MEMBERS BODY', $html );
		$this->assertStringNotContainsString( 'THE SECTION SECRET', $html, 'nor the body of a public post carrying section rules' );
		$this->assertStringNotContainsString( 'THE BLOCK SECRET', $html, 'nor the body of a public post carrying a block rule' );
		$this->assertStringNotContainsString( 'wp-block-latest-posts__post-full-content', $html );
	}

	#[Test]
	public function a_public_post_still_renders(): void {
		$html = $this->render( $this->block( self::FULL ) );

		$this->assertStringContainsString( '<a href="#">Post ' . self::PUBLIC_POST . '</a>', $html );
		$this->assertStringContainsString( 'THE PUBLIC BODY', $html, 'as its excerpt' );
		$this->assertStringContainsString( 'wp-block-latest-posts__post-excerpt', $html );
	}

	#[Test]
	public function the_titles_of_restricted_posts_still_list(): void {
		$html = $this->render( $this->block( self::FULL ) );

		$this->assertStringContainsString( 'Post ' . self::MEMBERS_ONLY, $html, 'titles are public, as they are in every other listing' );
	}

	#[Test]
	public function a_manual_excerpt_is_the_only_teaser_a_restricted_post_shows(): void {
		$GLOBALS['agend_test_posts'][ self::MEMBERS_ONLY ]['post_excerpt'] = 'A public summary.';

		$html = $this->render( $this->block( self::FULL ) );

		$this->assertStringContainsString( 'A public summary.', $html );
		$this->assertStringNotContainsString( 'THE MEMBERS BODY', $html );
	}

	/** The excerpt is the gated path, so the same answers apply as everywhere else. */
	#[Test]
	public function a_member_gets_the_excerpt_their_plan_allows_not_a_full_post(): void {
		$this->asMember( self::TIER_A );

		$html = $this->render( $this->block( self::FULL ) );

		$this->assertStringContainsString( 'wp-block-latest-posts__post-excerpt', $html );
		$this->assertStringNotContainsString( 'wp-block-latest-posts__post-full-content', $html, 'the fidelity cost: full post becomes an excerpt for everyone' );
	}

	/** @return array<string, array{0: mixed}> */
	public static function modesCoreDoesNotKnow(): array {
		return array(
			'a mode a later core adds' => array( 'whole_article' ),
			'an empty string'          => array( '' ),
			'a number'                 => array( 1 ),
		);
	}

	#[Test]
	#[DataProvider( 'modesCoreDoesNotKnow' )]
	public function any_mode_other_than_excerpt_is_withheld( $mode ): void {
		$block = $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => $mode ) );

		$this->assertSame( 'excerpt', $this->filtered( $block )['attrs']['displayPostContentRadio'] );
	}

	// -----------------------------------------------------------------------
	// The block driver
	// -----------------------------------------------------------------------

	/** A Latest Posts block with a rule of its own is still removed for a visitor who fails it. */
	#[Test]
	public function a_latest_posts_block_with_its_own_restriction_is_still_removed_for_a_visitor(): void {
		$block = $this->block( self::FULL + array( 'agendAccess' => array( 'mode' => 'active_member' ) ) );

		$this->assertSame( '', $this->render( $block ) );
	}

	#[Test]
	public function a_member_who_passes_the_blocks_own_restriction_gets_the_excerpt_list(): void {
		$this->asMember( self::TIER_A );
		$block = $this->block( self::FULL + array( 'agendAccess' => array( 'mode' => 'active_member' ) ) );

		$html = $this->render( $block );

		$this->assertStringContainsString( 'wp-block-latest-posts__post-excerpt', $html );
		$this->assertStringNotContainsString( 'wp-block-latest-posts__post-full-content', $html );
		$this->assertStringNotContainsString( 'THE BLOCK SECRET', $html, 'the excerpt of a ruled document is the same for a member' );
	}

	/** The mode is switched, the attribute the driver judges is not. */
	#[Test]
	public function only_the_display_mode_is_touched(): void {
		$block    = $this->block( self::FULL + array( 'agendAccess' => array( 'mode' => 'active_member' ), 'postsToShow' => 9 ) );
		$filtered = $this->filtered( $block );

		$this->assertSame( $block['attrs']['agendAccess'], $filtered['attrs']['agendAccess'] );
		$this->assertSame( 9, $filtered['attrs']['postsToShow'] );
	}

	/** The backstop judges the block as it stands after the data filter, and is not confused by the switch. */
	#[Test]
	public function the_backstop_judges_the_switched_block_the_same_way(): void {
		$block    = $this->block( self::FULL + array( 'agendAccess' => array( 'mode' => 'active_member' ) ) );
		$filtered = $this->filtered( $block );

		$this->assertSame( '', $this->blocks->render_block( '<ul>X</ul>', $filtered, null ) );
	}

	// -----------------------------------------------------------------------
	// Left alone
	// -----------------------------------------------------------------------

	#[Test]
	public function a_block_already_in_excerpt_mode_is_unchanged(): void {
		$block = $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'excerpt', 'excerptLength' => 20 ) );

		$this->assertSame( $block, $this->filtered( $block ) );
	}

	#[Test]
	public function a_block_that_shows_no_content_is_unchanged(): void {
		$off     = $this->block( array( 'displayPostContent' => false, 'displayPostContentRadio' => 'full_post' ) );
		$missing = $this->block( array( 'postsToShow' => 3 ) );

		$this->assertSame( $off, $this->filtered( $off ) );
		$this->assertSame( $missing, $this->filtered( $missing ) );
	}

	#[Test]
	public function other_blocks_are_unchanged(): void {
		$block = $this->block( self::FULL, 'core/paragraph' );

		$this->assertSame( $block, $this->filtered( $block ) );
	}

	#[Test]
	public function the_rest_of_the_block_survives_the_switch(): void {
		$block                 = $this->block( self::FULL + array( 'postsToShow' => 9 ) );
		$block['innerContent'] = array( 'x' );
		$filtered              = $this->filtered( $block );

		$this->assertSame( 9, $filtered['attrs']['postsToShow'] );
		$this->assertTrue( $filtered['attrs']['displayPostContent'] );
		$this->assertSame( array( 'x' ), $filtered['innerContent'] );
	}

	#[Test]
	public function an_editor_building_a_page_in_elementor_sees_the_block_as_configured(): void {
		$this->asEditor();
		do_action( 'elementor/loaded' );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$html = $this->render( $this->block( self::FULL ) );

		$this->assertStringContainsString( 'wp-block-latest-posts__post-full-content', $html );
	}

	#[Test]
	public function a_wp_admin_screen_is_left_alone_for_a_user_who_may_edit_others_posts(): void {
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;

		$html = $this->render( $this->block( self::FULL ) );

		$this->assertStringContainsString( 'wp-block-latest-posts__post-full-content', $html );
	}

	/** The block editor's own server-side render shows the author the block as they configured it. */
	#[Test]
	public function the_block_editors_own_render_is_left_alone(): void {
		$this->asEditor();
		$GLOBALS['agend_test_rest_request'] = true;
		$GLOBALS['wp']                      = (object) array( 'query_vars' => array( 'rest_route' => '/wp/v2/block-renderer/core/latest-posts' ) );
		$this->registerPost( 75, '' );
		$this->inTheLoopOf( 75 );

		$html = $this->render( $this->block( self::FULL ) );

		$this->assertStringContainsString( 'wp-block-latest-posts__post-full-content', $html );
	}

	/** A "load more" widget renders blocks for anonymous visitors through admin-ajax. */
	#[Test]
	public function an_admin_ajax_render_is_still_withheld(): void {
		$GLOBALS['agend_test_is_admin']   = true;
		$GLOBALS['agend_test_doing_ajax'] = true;

		$html = $this->render( $this->block( self::FULL ) );

		$this->assertStringNotContainsString( 'THE MEMBERS BODY', $html );
	}

	// -----------------------------------------------------------------------
	// The render callback
	// -----------------------------------------------------------------------

	/**
	 * `render_block_data` runs inside `render_block()`, and some paths render a
	 * block without it: the children of a navigation read from a `ref` post, and
	 * the children of a widget group once a third party has short-circuited the
	 * render. The callback itself withholds the full post.
	 */
	#[Test]
	public function a_block_that_skipped_the_data_filter_is_still_withheld(): void {
		$html = render_block( $this->block( self::FULL ) );

		$this->assertStringNotContainsString( 'THE MEMBERS BODY', $html );
		$this->assertStringNotContainsString( 'THE BLOCK SECRET', $html );
		$this->assertStringNotContainsString( 'wp-block-latest-posts__post-full-content', $html );
		$this->assertStringContainsString( 'wp-block-latest-posts__post-excerpt', $html );
	}

	/** Not even `render_block()`: the block object rendered directly, as a parent block renders its children. */
	#[Test]
	public function a_block_object_rendered_directly_is_still_withheld(): void {
		$html = ( new \WP_Block( $this->block( self::FULL ) ) )->render();

		$this->assertStringNotContainsString( 'THE MEMBERS BODY', $html );
		$this->assertStringNotContainsString( 'wp-block-latest-posts__post-full-content', $html );
	}

	/** @return array<string, array{0: mixed}> */
	public static function modesOnTheCallbackPath(): array {
		return self::modesCoreDoesNotKnow() + array( 'full post' => array( 'full_post' ) );
	}

	#[Test]
	#[DataProvider( 'modesOnTheCallbackPath' )]
	public function any_mode_other_than_excerpt_is_withheld_in_the_callback_too( $mode ): void {
		$html = render_block( $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => $mode ) ) );

		$this->assertStringNotContainsString( 'wp-block-latest-posts__post-full-content', $html );
		$this->assertStringNotContainsString( 'THE MEMBERS BODY', $html );
	}

	/** The wrapper changes the display mode and nothing the callback was handed. */
	#[Test]
	public function the_wrapper_passes_everything_else_through(): void {
		$seen = array();

		register_block_type(
			'core/latest-posts',
			array(
				'render_callback' => static function ( array $attributes, string $content = '', $block = null ) use ( &$seen ): string {
					$seen = array( $attributes, $content, $block );

					return 'rendered';
				},
			)
		);

		$block = $this->block( self::FULL + array( 'postsToShow' => 9 ) );
		$html  = render_block( $block );

		$this->assertSame( 'rendered', $html );
		$this->assertSame( 'excerpt', $seen[0]['displayPostContentRadio'] );
		$this->assertSame( 9, $seen[0]['postsToShow'] );
		$this->assertTrue( $seen[0]['displayPostContent'] );
		$this->assertInstanceOf( \WP_Block::class, $seen[2], 'core passes the block as the third argument' );
	}

	#[Test]
	public function other_blocks_keep_their_own_callback_untouched(): void {
		$callback = static fn( array $attributes ): string => 'mine:' . ( $attributes['displayPostContentRadio'] ?? '' );

		register_block_type( 'core/query', array( 'render_callback' => $callback ) );

		$this->assertSame( 'mine:full_post', render_block( $this->block( self::FULL, 'core/query' ) ) );
		$this->assertSame(
			$callback,
			\WP_Block_Type_Registry::get_instance()->get_registered( 'core/query' )->render_callback
		);
	}

	/** Wrapping a callback that cannot be called would only move the failure. */
	#[Test]
	public function a_callback_that_cannot_be_called_is_left_as_it_was(): void {
		$frontend = new Agend_Content_Access_Frontend();

		$this->assertSame(
			array( 'render_callback' => 'no_such_function_anywhere' ),
			$frontend->wrap_latest_posts_render( array( 'render_callback' => 'no_such_function_anywhere' ), 'core/latest-posts' )
		);
		$this->assertSame( array(), $frontend->wrap_latest_posts_render( array(), 'core/latest-posts' ) );
		$this->assertSame( 'x', $frontend->wrap_latest_posts_render( 'x', 'core/latest-posts' ) );
	}

	#[Test]
	public function an_author_gets_the_block_as_configured_from_the_callback_too(): void {
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;

		$html = render_block( $this->block( self::FULL ) );

		$this->assertStringContainsString( 'wp-block-latest-posts__post-full-content', $html );
	}

	// -----------------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------------

	/** Last, so no other filter can put the mode back; with the arguments core passes. */
	#[Test]
	public function the_filter_runs_last_with_the_arguments_core_passes(): void {
		$entry = Agend_Test_WP::$filter_registry['render_block_data'][0];

		$this->assertSame( PHP_INT_MAX, $entry['priority'] );
		$this->assertSame( 3, $entry['args'] );
	}

	/** Last, so nothing registered after it can unwrap the callback. */
	#[Test]
	public function the_callback_wrapper_is_registered_last_with_the_arguments_core_passes(): void {
		$entries = array_values(
			array_filter(
				Agend_Test_WP::$filter_registry['register_block_type_args'] ?? array(),
				static fn( array $entry ): bool => is_array( $entry['callback'] ) && 'wrap_latest_posts_render' === $entry['callback'][1]
			)
		);

		$this->assertCount( 1, $entries );
		$this->assertSame( PHP_INT_MAX, $entries[0]['priority'] );
		$this->assertSame( 2, $entries[0]['args'] );
	}

	#[Test]
	public function a_non_array_is_passed_through_untouched(): void {
		$this->assertSame( 'x', ( new Agend_Content_Access_Frontend() )->withhold_latest_posts_content( 'x' ) );
	}
}
