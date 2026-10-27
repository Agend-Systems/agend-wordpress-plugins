<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision;
use Agend_Content_Access_Fragment_Rules;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';

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
final class LatestPostsBlockTest extends TestCase {

	private const MEMBERS_ONLY = 71;
	private const PUBLIC_POST  = 72;
	private const SECTION_RULE = 73;

	protected function setUp(): void {
		parent::setUp();

		$this->post( self::MEMBERS_ONLY, 'THE MEMBERS BODY', array( 'mode' => 'active_member' ) );
		$this->post( self::PUBLIC_POST, 'THE PUBLIC BODY', array( 'mode' => 'public' ) );
		$this->post( self::SECTION_RULE, 'PUBLIC OPENING AND THE SECTION SECRET', null );

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

		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );

		( new Agend_Content_Access_Frontend() );
		Agend_Content_Access_Fragment_Rules::init();

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

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_is_admin'], $GLOBALS['agend_test_doing_ajax'] );

		parent::tearDown();
	}

	private function post( int $id, string $body, ?array $policy ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_title'   => 'Post ' . $id,
			'post_content' => $body,
			'post_excerpt' => '',
		);

		if ( null !== $policy ) {
			$GLOBALS['agend_test_post_meta'][ $id ][ Agend_Content_Access_Policy::META_KEY ] = $policy;
		}
	}

	/** Core's render callback for `core/latest-posts`, reduced to the part that matters. */
	public function coreLatestPosts( array $attributes ): string {
		$markup = '';

		foreach ( array( self::MEMBERS_ONLY, self::PUBLIC_POST, self::SECTION_RULE ) as $id ) {
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

	private function asMember( string ...$tiers ): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => $tiers ) )
		);
	}

	// -----------------------------------------------------------------------
	// The leak
	// -----------------------------------------------------------------------

	#[Test]
	public function the_stand_in_really_leaks_without_the_filter(): void {
		$html = render_block( $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' ) ) );

		$this->assertStringContainsString( 'THE MEMBERS BODY', $html, 'the defect this test class exists for' );
	}

	#[Test]
	public function a_restricted_posts_body_never_appears_in_full_post_mode(): void {
		$html = $this->render( $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' ) ) );

		$this->assertStringNotContainsString( 'THE MEMBERS BODY', $html );
		$this->assertStringNotContainsString( 'THE SECTION SECRET', $html, 'nor the body of a public post carrying section rules' );
		$this->assertStringNotContainsString( 'wp-block-latest-posts__post-full-content', $html );
	}

	#[Test]
	public function a_public_post_still_renders(): void {
		$html = $this->render( $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' ) ) );

		$this->assertStringContainsString( '<a href="#">Post ' . self::PUBLIC_POST . '</a>', $html );
		$this->assertStringContainsString( 'THE PUBLIC BODY', $html, 'as its excerpt' );
		$this->assertStringContainsString( 'wp-block-latest-posts__post-excerpt', $html );
	}

	#[Test]
	public function the_titles_of_restricted_posts_still_list(): void {
		$html = $this->render( $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' ) ) );

		$this->assertStringContainsString( 'Post ' . self::MEMBERS_ONLY, $html, 'titles are public, as they are in every other listing' );
	}

	#[Test]
	public function a_manual_excerpt_is_the_only_teaser_a_restricted_post_shows(): void {
		$GLOBALS['agend_test_posts'][ self::MEMBERS_ONLY ]['post_excerpt'] = 'A public summary.';

		$html = $this->render( $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' ) ) );

		$this->assertStringContainsString( 'A public summary.', $html );
		$this->assertStringNotContainsString( 'THE MEMBERS BODY', $html );
	}

	/** The excerpt is the gated path, so the same answers apply as everywhere else. */
	#[Test]
	public function a_member_gets_the_excerpt_their_plan_allows_not_a_full_post(): void {
		$this->asMember( '11111111-1111-1111-1111-111111111111' );

		$html = $this->render( $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' ) ) );

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

		$filtered = Agend_Test_WP::apply_registered( 'render_block_data', $block, $block, null );

		$this->assertSame( 'excerpt', $filtered['attrs']['displayPostContentRadio'] );
	}

	// -----------------------------------------------------------------------
	// Left alone
	// -----------------------------------------------------------------------

	#[Test]
	public function a_block_already_in_excerpt_mode_is_unchanged(): void {
		$block = $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'excerpt', 'excerptLength' => 20 ) );

		$this->assertSame( $block, Agend_Test_WP::apply_registered( 'render_block_data', $block, $block, null ) );
	}

	#[Test]
	public function a_block_that_shows_no_content_is_unchanged(): void {
		$off     = $this->block( array( 'displayPostContent' => false, 'displayPostContentRadio' => 'full_post' ) );
		$missing = $this->block( array( 'postsToShow' => 3 ) );

		$this->assertSame( $off, Agend_Test_WP::apply_registered( 'render_block_data', $off, $off, null ) );
		$this->assertSame( $missing, Agend_Test_WP::apply_registered( 'render_block_data', $missing, $missing, null ) );
	}

	#[Test]
	public function other_blocks_are_unchanged(): void {
		$block = $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' ), 'core/paragraph' );

		$this->assertSame( $block, Agend_Test_WP::apply_registered( 'render_block_data', $block, $block, null ) );
	}

	#[Test]
	public function the_rest_of_the_block_survives_the_switch(): void {
		$block                  = $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post', 'postsToShow' => 9 ) );
		$block['innerContent']  = array( 'x' );
		$filtered               = Agend_Test_WP::apply_registered( 'render_block_data', $block, $block, null );

		$this->assertSame( 9, $filtered['attrs']['postsToShow'] );
		$this->assertTrue( $filtered['attrs']['displayPostContent'] );
		$this->assertSame( array( 'x' ), $filtered['innerContent'] );
	}

	#[Test]
	public function an_editor_building_a_page_in_elementor_sees_the_block_as_configured(): void {
		do_action( 'elementor/loaded' );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$html = $this->render( $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' ) ) );

		$this->assertStringContainsString( 'wp-block-latest-posts__post-full-content', $html );
	}

	#[Test]
	public function a_wp_admin_screen_is_left_alone(): void {
		$GLOBALS['agend_test_is_admin'] = true;

		$html = $this->render( $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' ) ) );

		$this->assertStringContainsString( 'wp-block-latest-posts__post-full-content', $html );
	}

	/** A "load more" widget renders blocks for anonymous visitors through admin-ajax. */
	#[Test]
	public function an_admin_ajax_render_is_still_withheld(): void {
		$GLOBALS['agend_test_is_admin']   = true;
		$GLOBALS['agend_test_doing_ajax'] = true;

		$html = $this->render( $this->block( array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' ) ) );

		$this->assertStringNotContainsString( 'THE MEMBERS BODY', $html );
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

	#[Test]
	public function a_non_array_is_passed_through_untouched(): void {
		$this->assertSame( 'x', ( new Agend_Content_Access_Frontend() )->withhold_latest_posts_content( 'x' ) );
	}
}
