<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * Blocks that core renders without asking `pre_render_block` are judged anyway.
 *
 * Navigation, Navigation Submenu, Navigation Link and Widget Group render their
 * children by calling `$inner_block->render()` from their own render callback,
 * which never reaches `pre_render_block`. The `render_block` backstop is what
 * applies the decision to those. The stubs reproduce the shape that matters: a
 * container whose callback renders its children itself, through real
 * `WP_Block::render()` instances.
 */
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class BlockBackstopTest extends BlockEnforcementTestCase {

	private const FALLBACK = 4343;

	protected function setUp(): void {
		parent::setUp();

		$render_children = static function ( array $attributes, string $content, $block ): string {
			$html = '';

			foreach ( $block->inner_blocks as $inner_block ) {
				$html .= $inner_block->render();
			}

			return $html;
		};

		register_block_type(
			'core/navigation',
			array(
				'skip_inner_blocks' => true,
				'render_callback'   => static fn( array $a, string $c, $block ): string => '<nav>' . $render_children( $a, $c, $block ) . '</nav>',
			)
		);
		register_block_type(
			'core/navigation-submenu',
			array(
				'skip_inner_blocks' => true,
				'render_callback'   => static fn( array $a, string $c, $block ): string => '<li>' . ( $a['label'] ?? '' ) . '<ul>' . $render_children( $a, $c, $block ) . '</ul></li>',
			)
		);
		register_block_type(
			'core/navigation-link',
			array(
				'render_callback' => static fn( array $a ): string => '<li><a href="#">' . ( $a['label'] ?? '' ) . '</a></li>',
			)
		);
		register_block_type(
			'core/widget-group',
			array(
				'skip_inner_blocks' => true,
				'render_callback'   => static fn( array $a, string $c, $block ): string => '<section>' . $render_children( $a, $c, $block ) . '</section>',
			)
		);

		$this->inTheLoopOf( self::PUBLIC_PAGE );
	}

	private function link( string $label, ?array $access = null ): string {
		$attributes = array( 'label' => $label );

		if ( null !== $access ) {
			$attributes['agendAccess'] = $access;
		}

		return '<!-- wp:navigation-link ' . wp_json_encode( $attributes ) . ' /-->';
	}

	private function submenu( string $label, ?array $access, string ...$children ): string {
		$attributes = array( 'label' => $label );

		if ( null !== $access ) {
			$attributes['agendAccess'] = $access;
		}

		return '<!-- wp:navigation-submenu ' . wp_json_encode( $attributes ) . ' -->' . implode( '', $children ) . '<!-- /wp:navigation-submenu -->';
	}

	private function navigation( string ...$children ): string {
		return '<!-- wp:navigation -->' . implode( '', $children ) . '<!-- /wp:navigation -->';
	}

	private const MEMBERS = array( 'mode' => 'active_member' );

	// -----------------------------------------------------------------------
	// The leak, and that this is what closes it
	// -----------------------------------------------------------------------

	/** The premise: with only `pre_render_block` the restricted link is in the output. */
	#[Test]
	public function without_the_backstop_a_restricted_navigation_link_is_rendered(): void {
		remove_filter( 'render_block' );

		$html = do_blocks( $this->navigation( $this->link( 'Home' ), $this->link( 'MEMBERS LINK', self::MEMBERS ) ) );

		$this->assertStringContainsString( 'MEMBERS LINK', $html );
	}

	#[Test]
	public function a_restricted_navigation_link_is_removed_for_an_anonymous_visitor(): void {
		$html = do_blocks( $this->navigation( $this->link( 'Home' ), $this->link( 'MEMBERS LINK', self::MEMBERS ), $this->link( 'About' ) ) );

		$this->assertSame( '<nav><li><a href="#">Home</a></li><li><a href="#">About</a></li></nav>', $html );
	}

	#[Test]
	public function a_restricted_navigation_link_is_kept_for_an_entitled_member(): void {
		$this->asMember( self::TIER_A );

		$html = do_blocks( $this->navigation( $this->link( 'Home' ), $this->link( 'MEMBERS LINK', self::MEMBERS ) ) );

		$this->assertSame( '<nav><li><a href="#">Home</a></li><li><a href="#">MEMBERS LINK</a></li></nav>', $html );
	}

	#[Test]
	public function a_restricted_submenu_is_removed_with_everything_in_it(): void {
		$html = do_blocks(
			$this->navigation(
				$this->link( 'Home' ),
				$this->submenu( 'MEMBERS MENU', self::MEMBERS, $this->link( 'Public child' ) )
			)
		);

		$this->assertSame( '<nav><li><a href="#">Home</a></li></nav>', $html );
	}

	#[Test]
	public function a_restricted_submenu_is_kept_for_an_entitled_member(): void {
		$this->asMember( self::TIER_A );

		$html = do_blocks( $this->navigation( $this->submenu( 'MEMBERS MENU', self::MEMBERS, $this->link( 'Child' ) ) ) );

		$this->assertSame( '<nav><li>MEMBERS MENU<ul><li><a href="#">Child</a></li></ul></li></nav>', $html );
	}

	/** A restriction two direct renders down: the link inside an open submenu. */
	#[Test]
	public function a_restricted_link_inside_a_public_submenu_is_removed_alone(): void {
		$html = do_blocks( $this->navigation( $this->submenu( 'Menu', null, $this->link( 'Open' ), $this->link( 'MEMBERS CHILD', self::MEMBERS ) ) ) );

		$this->assertSame( '<nav><li>Menu<ul><li><a href="#">Open</a></li></ul></li></nav>', $html );
	}

	#[Test]
	public function a_restricted_block_in_a_widget_group_is_removed(): void {
		$html = do_blocks( '<!-- wp:widget-group --><!-- wp:navigation-link {"label":"MEMBERS WIDGET","agendAccess":{"mode":"active_member"}} /--><!-- wp:navigation-link {"label":"Open"} /--><!-- /wp:widget-group -->' );

		$this->assertSame( '<section><li><a href="#">Open</a></li></section>', $html );
	}

	/** A restricted block the front door DID judge is not rendered at all, whichever path the container takes. */
	#[Test]
	public function a_restricted_navigation_container_is_removed_before_it_renders_its_children(): void {
		$ran = 0;
		register_block_type(
			'core/navigation-link',
			array(
				'render_callback' => static function () use ( &$ran ): string {
					++$ran;

					return '<li>LINK</li>';
				},
			)
		);

		$html = do_blocks( '<!-- wp:navigation {"agendAccess":{"mode":"active_member"}} --><!-- wp:navigation-link {"label":"x"} /--><!-- /wp:navigation -->' );

		$this->assertSame( '', $html );
		$this->assertSame( 0, $ran, 'the main path still stops a denied container early' );
	}

	// -----------------------------------------------------------------------
	// Conditions, fallbacks, authors
	// -----------------------------------------------------------------------

	private function signedInOnly( array $over = array() ): array {
		return array_merge(
			array(
				'conditionsEnabled' => true,
				'conditions'        => array( 'agend_wordpress:logged_in' ),
			),
			$over
		);
	}

	#[Test]
	public function a_navigation_link_whose_conditions_fail_is_removed(): void {
		$html = do_blocks( $this->navigation( $this->link( 'Home' ), $this->link( 'Signed in only', $this->signedInOnly() ) ) );

		$this->assertSame( '<nav><li><a href="#">Home</a></li></nav>', $html );
	}

	#[Test]
	public function a_navigation_link_whose_conditions_pass_is_kept(): void {
		$GLOBALS['agend_test_current_user_id'] = 9;

		$html = do_blocks( $this->navigation( $this->link( 'Signed in only', $this->signedInOnly() ) ) );

		$this->assertSame( '<nav><li><a href="#">Signed in only</a></li></nav>', $html );
	}

	/** The same fallback rule as the main path: only content the visitor could read on its own. */
	#[Test]
	public function a_navigation_link_excluded_by_its_conditions_shows_a_public_fallback(): void {
		$this->theContentRunsBlocks();
		$this->registerPost( self::FALLBACK, 'FALLBACK TEXT' );

		$html = do_blocks( $this->navigation( $this->link( 'Signed in only', $this->signedInOnly( array( 'fallbackId' => self::FALLBACK ) ) ) ) );

		$this->assertSame( '<nav>FALLBACK TEXT</nav>', $html );
	}

	#[Test]
	public function a_restricted_fallback_is_not_shown_by_the_backstop_either(): void {
		$this->theContentRunsBlocks();
		$this->registerPost( self::FALLBACK, 'MEMBERS ONLY FALLBACK', self::MEMBERS );

		$html = do_blocks( $this->navigation( $this->link( 'Signed in only', $this->signedInOnly( array( 'fallbackId' => self::FALLBACK ) ) ) ) );

		$this->assertSame( '<nav></nav>', $html );
	}

	#[Test]
	public function an_author_sees_the_whole_navigation(): void {
		// Authoring, as the block editor's server-side render is: not a front-end editor.
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;

		$html = do_blocks( $this->navigation( $this->link( 'MEMBERS LINK', self::MEMBERS ) ) );

		$this->assertStringContainsString( 'MEMBERS LINK', $html );
		$this->assertSame( 0, $this->identity_lookups, 'an author is not judged' );
	}

	/** The response is per-viewer, as for a block the main path removed. */
	#[Test]
	public function the_backstop_marks_the_response_uncacheable(): void {
		do_blocks( $this->navigation( $this->link( 'MEMBERS LINK', self::MEMBERS ) ) );

		$this->assertSame( array( self::PUBLIC_PAGE ), $this->uncacheable );
	}

	// -----------------------------------------------------------------------
	// It does not decide twice, and does not touch what has no rule
	// -----------------------------------------------------------------------

	/** Counts the questions the condition engine is asked, which is one per decision. */
	private function countConditionChecks(): \ArrayObject {
		$checks = new \ArrayObject();

		Agend_Test_WP::$filters['agend_content_access_check_condition'] = static function ( $result, string $provider, string $value ) use ( $checks ) {
			$checks[] = $provider . ':' . $value;

			return true;
		};

		return $checks;
	}

	private function counted( array $extra = array() ): array {
		return array_merge( array( 'conditionsEnabled' => true, 'conditions' => array( 'acme:yes' ) ), $extra );
	}

	/** A block the main path judged and allowed is not judged again by the backstop. */
	#[Test]
	public function a_block_the_main_path_allowed_is_decided_once(): void {
		$checks = $this->countConditionChecks();

		$html = do_blocks( $this->paragraph( 'ONE', $this->counted() ) . $this->group( $this->counted(), $this->paragraph( 'TWO', $this->counted() ) ) );

		$this->assertSame( '<p>ONE</p><div class="wp-block-group"><p>TWO</p></div>', $html );
		$this->assertCount( 3, $checks, 'three ruled blocks, three decisions' );
	}

	/** A block that skipped the front door is decided by the backstop, once. */
	#[Test]
	public function a_block_that_skipped_the_main_path_is_decided_once(): void {
		$checks = $this->countConditionChecks();

		do_blocks( $this->navigation( $this->link( 'Home', $this->counted() ) ) );

		$this->assertCount( 1, $checks );
	}

	/** Identical blocks are accounted for one by one: N granted by the front door, N passed by the backstop. */
	#[Test]
	public function identical_blocks_are_each_decided_once(): void {
		$checks = $this->countConditionChecks();

		do_blocks( str_repeat( $this->paragraph( 'SAME', $this->counted() ), 4 ) . $this->navigation( $this->link( 'SAME', $this->counted() ), $this->link( 'SAME', $this->counted() ) ) );

		$this->assertCount( 6, $checks );
	}

	/**
	 * A grant is used up by the render it was for. One the main path recorded and
	 * whose render never reached the filter must not stand in for every identical
	 * block after it.
	 */
	#[Test]
	public function a_recorded_grant_covers_one_render_and_not_the_next(): void {
		$checks = $this->countConditionChecks();
		$block  = parse_blocks( $this->link( 'Home', $this->counted() ) )[0];

		$this->blocks->pre_render_block( null, $block );
		$this->assertCount( 1, $checks );

		$this->assertSame( 'FIRST', $this->blocks->render_block( 'FIRST', $block ) );
		$this->assertCount( 1, $checks, 'the recorded grant covered this render' );

		$this->assertSame( 'SECOND', $this->blocks->render_block( 'SECOND', $block ) );
		$this->assertCount( 2, $checks, 'and not the next one' );
	}

	/**
	 * What decides a block includes the post it is judged against. A grant given
	 * under one post is not a grant under another, whose own policy may narrow it.
	 */
	#[Test]
	public function a_grant_given_under_one_post_is_not_a_grant_under_another(): void {
		$this->asMember( self::TIER_A );
		$block = parse_blocks( $this->paragraph( 'SECRET', self::MEMBERS ) )[0];

		$this->assertNull( $this->blocks->pre_render_block( null, $block ), 'a member may see it on the public page' );

		$this->inTheLoopOf( self::TIER_B_POST );

		$this->assertSame( '', $this->blocks->render_block( '<p>SECRET</p>', $block ), 'but not on a post restricted to another plan' );
	}

	/** A block with no rule is never decided, and costs no identity lookup. */
	#[Test]
	public function a_block_with_no_rule_is_untouched_and_costs_nothing(): void {
		$html = do_blocks( $this->navigation( $this->link( 'Home' ), $this->link( 'About', array( 'mode' => 'inherit' ) ) ) );

		$this->assertSame( '<nav><li><a href="#">Home</a></li><li><a href="#">About</a></li></nav>', $html );
		$this->assertSame( 0, $this->identity_lookups );
		$this->assertSame( array(), $this->uncacheable );
	}

	/** A decision that cannot be made is a denial on this path too. */
	#[Test]
	public function a_failing_access_check_removes_the_navigation_link(): void {
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function (): string {
			throw new \RuntimeException( 'the gateway client fell over' );
		};

		$html = do_blocks( $this->navigation( $this->link( 'Home' ), $this->link( 'MEMBERS LINK', self::MEMBERS ) ) );

		$this->assertSame( '<nav><li><a href="#">Home</a></li></nav>', $html );
	}

	/** Output that is not a string, from a filter earlier in the chain, is passed through untouched. */
	#[Test]
	public function output_that_is_not_a_string_is_not_touched(): void {
		$block = parse_blocks( $this->link( 'MEMBERS LINK', self::MEMBERS ) )[0];

		$this->assertNull( $this->blocks->render_block( null, $block ) );
		$this->assertSame( 'x', $this->blocks->render_block( 'x', 'not a block' ) );
	}

	// -----------------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------------

	#[Test]
	public function the_backstop_runs_first_and_takes_the_block_and_the_instance(): void {
		$entry = Agend_Test_WP::$filter_registry['render_block'][0];

		$this->assertSame( PHP_INT_MIN, $entry['priority'] );
		$this->assertSame( 3, $entry['args'] );
		$this->assertSame( array( $this->blocks, 'render_block' ), $entry['callback'] );
		$this->assertCount( 1, Agend_Test_WP::$filter_registry['render_block'] );
	}
}
