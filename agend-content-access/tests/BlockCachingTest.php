<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * A response that depends on who is looking must never be stored by a page
 * cache.
 *
 * A cache keys on the URL, not on the viewer, and an Agend member is routinely
 * not signed in to WordPress at all, so the cache cannot tell them from an
 * anonymous visitor. A page with a restricted or conditioned block is therefore
 * per-viewer for everybody, and is marked whichever copy is being rendered.
 */
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class BlockCachingTest extends BlockEnforcementTestCase {

	/** Makes `$id` the queried singular, as WordPress does before `template_redirect`. */
	private function visit( int $id ): void {
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( $id );
	}

	private function secret( string $text = 'SECRET' ): string {
		return $this->paragraph( $text, array( 'mode' => 'active_member' ) );
	}

	// -----------------------------------------------------------------------
	// template_redirect: the early decision, from the tree
	// -----------------------------------------------------------------------

	#[Test]
	public function a_page_holding_a_restricted_block_is_marked_before_it_renders(): void {
		$this->registerPost( 400, $this->paragraph( 'PUBLIC' ) . $this->secret() );
		$this->visit( 400 );

		do_action( 'template_redirect' );

		$this->assertSame( array( 400 ), $this->uncacheable );
	}

	#[Test]
	public function a_restricted_block_nested_deep_in_the_tree_marks_the_page(): void {
		$this->registerPost( 401, $this->group( null, $this->group( null, $this->paragraph( 'PUBLIC' ), $this->secret() ) ) );
		$this->visit( 401 );

		do_action( 'template_redirect' );

		$this->assertSame( array( 401 ), $this->uncacheable );
	}

	/** Display conditions personalise a page just as a policy restricts it. */
	#[Test]
	public function a_page_holding_a_conditioned_block_is_marked(): void {
		$this->registerPost( 402, $this->paragraph( 'NOTE', array( 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in' ) ) ) );
		$this->visit( 402 );

		do_action( 'template_redirect' );

		$this->assertSame( array( 402 ), $this->uncacheable );
	}

	/**
	 * Changed from "switched on with none chosen still takes it out of a shared cache". Switched on
	 * with nothing chosen is no rules at all: the evaluator admits everybody, so every visitor is
	 * served the same page and there is nothing a cache could serve to the wrong audience.
	 */
	#[Test]
	public function conditions_switched_on_with_none_chosen_are_no_rules_and_do_not_mark_the_page(): void {
		$this->registerPost( 403, $this->paragraph( 'NOTE', array( 'conditionsEnabled' => true ) ) );
		$this->visit( 403 );

		do_action( 'template_redirect' );

		$this->assertSame( array(), $this->uncacheable );
	}

	/** The reverse of nothing is nobody, which is a rule, so it still marks the page. */
	#[Test]
	public function a_reverse_over_no_conditions_is_a_rule_and_marks_the_page(): void {
		$this->registerPost( 403, $this->paragraph( 'NOTE', array( 'conditionsEnabled' => true, 'conditionsInvert' => true ) ) );
		$this->visit( 403 );

		do_action( 'template_redirect' );

		$this->assertSame( array( 403 ), $this->uncacheable );
	}

	/** A restriction the editor wrote and the reader cannot read is still a restriction. */
	#[Test]
	public function a_block_with_an_unreadable_attribute_marks_the_page(): void {
		$this->registerPost( 404, $this->rawAttribute( '{"agendAccess":"members"}', 'SECRET' ) );
		$this->visit( 404 );

		do_action( 'template_redirect' );

		$this->assertSame( array( 404 ), $this->uncacheable );
	}

	/**
	 * The restriction is in a synced pattern, which is not in the page's own
	 * content at all: the page holds only a reference.
	 */
	#[Test]
	public function a_restricted_block_inside_a_synced_pattern_marks_the_page(): void {
		$this->registerPattern( 910, $this->paragraph( 'PUBLIC' ) . $this->secret() );
		$this->registerPost( 405, $this->paragraph( 'BEFORE' ) . $this->reference( 910 ) );
		$this->visit( 405 );

		do_action( 'template_redirect' );

		$this->assertSame( array( 405 ), $this->uncacheable );
	}

	#[Test]
	public function a_restriction_two_patterns_deep_marks_the_page(): void {
		$this->registerPattern( 911, $this->secret() );
		$this->registerPattern( 912, $this->reference( 911 ) );
		$this->registerPost( 406, $this->reference( 912 ) );
		$this->visit( 406 );

		do_action( 'template_redirect' );

		$this->assertSame( array( 406 ), $this->uncacheable );
	}

	#[Test]
	public function a_pattern_that_references_itself_ends_and_marks_nothing_it_did_not_find(): void {
		$this->registerPattern( 913, $this->reference( 913 ) . $this->paragraph( 'PLAIN' ) );
		$this->registerPost( 407, $this->reference( 913 ) );
		$this->visit( 407 );

		do_action( 'template_redirect' );

		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function a_page_with_no_restricted_block_is_not_marked(): void {
		$this->registerPost(
			408,
			$this->paragraph( 'PLAIN' )
			. $this->group( null, $this->paragraph( 'NESTED' ) )
			. $this->paragraph( 'INHERITS', array( 'mode' => 'inherit' ) )
			. $this->paragraph( 'EMPTY', array() )
			. $this->paragraph( 'CONDITIONS OFF', array( 'conditionsEnabled' => false, 'conditions' => array( 'agend_wordpress:logged_in' ) ) )
		);
		$this->visit( 408 );

		do_action( 'template_redirect' );

		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function a_pattern_with_no_restricted_block_does_not_mark_the_page(): void {
		$this->registerPattern( 914, $this->paragraph( 'PLAIN' ) );
		$this->registerPost( 409, $this->reference( 914 ) );
		$this->visit( 409 );

		do_action( 'template_redirect' );

		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function classic_content_is_not_marked(): void {
		$this->registerPost( 410, '<p>Just some classic content, and a mention of wp: in passing.</p>' );
		$this->visit( 410 );

		do_action( 'template_redirect' );

		$this->assertSame( array(), $this->uncacheable );
	}

	/** The delimiter the parser accepts that `has_blocks()` does not: two spaces after the opener. */
	#[Test]
	public function a_delimiter_with_unusual_spacing_is_still_scanned(): void {
		$this->registerPost( 411, "<!--  wp:paragraph {\"agendAccess\":{\"mode\":\"active_member\"}} --><p>SECRET</p><!-- /wp:paragraph -->" );
		$this->visit( 411 );

		do_action( 'template_redirect' );

		$this->assertSame( array( 411 ), $this->uncacheable );
	}

	#[Test]
	public function a_request_that_is_not_singular_is_not_scanned(): void {
		$this->registerPost( 412, $this->secret() );
		$GLOBALS['agend_test_queried_object'] = get_post( 412 );

		do_action( 'template_redirect' );

		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function a_singular_request_with_no_queried_post_is_not_scanned(): void {
		$GLOBALS['agend_test_is_singular'] = true;

		do_action( 'template_redirect' );

		$this->assertSame( array(), $this->uncacheable );
	}

	/**
	 * The scan reads the live tree, never the saved search flag: a pattern edited
	 * after the page was saved changes what the page holds without touching it.
	 */
	#[Test]
	public function the_decision_does_not_trust_the_saved_flag(): void {
		$this->registerPattern( 915, $this->secret() );
		$this->registerPost( 413, $this->reference( 915 ) );
		$GLOBALS['agend_test_post_meta'][ 413 ]['_agend_access_fragment_rules'] = '';
		$this->visit( 413 );

		do_action( 'template_redirect' );

		$this->assertSame( array( 413 ), $this->uncacheable );
	}

	/** After the page gate (priority 1), which may end the request. */
	#[Test]
	public function the_early_marking_runs_after_the_page_gate(): void {
		$this->assertContains( array( $this->blocks, 'mark_page_uncacheable' ), Agend_Test_WP::$actions['template_redirect'] );

		$index = array_search( array( $this->blocks, 'mark_page_uncacheable' ), Agend_Test_WP::$actions['template_redirect'], true );

		$this->assertSame( 2, Agend_Test_WP::$action_priorities['template_redirect'][ $index ] );
	}

	// -----------------------------------------------------------------------
	// Render time: loops, template parts, widgets
	// -----------------------------------------------------------------------

	/** A restricted block in a loop is in no queried post's content, so only the render can see it. */
	#[Test]
	public function rendering_a_restricted_block_in_a_loop_marks_the_post_it_belongs_to(): void {
		$this->registerPost( 420, $this->paragraph( 'ONE' ) );
		$this->registerPost( 421, $this->secret() );
		$this->registerPost( 422, $this->secret( 'OTHER SECRET' ) );

		$this->renderPost( 420 );
		$this->assertSame( array(), $this->uncacheable, 'a plain post is not marked' );

		$this->renderPost( 421 );
		$this->renderPost( 422 );

		$this->assertSame( array( 421, 422 ), $this->uncacheable );
	}

	/** Anonymous renders are marked too: the page is per-viewer for everybody. */
	#[Test]
	public function an_anonymous_render_is_marked(): void {
		$this->registerPost( 423, $this->secret() );

		$html = $this->renderPost( 423 );

		$this->assertSame( '', $html, 'denied to the visitor' );
		$this->assertSame( array( 423 ), $this->uncacheable );
	}

	#[Test]
	public function a_members_render_is_marked_too(): void {
		$this->asMember( self::TIER_A );
		$this->registerPost( 424, $this->secret() );

		$html = $this->renderPost( 424 );

		$this->assertSame( '<p>SECRET</p>', $html );
		$this->assertSame( array( 424 ), $this->uncacheable );
	}

	#[Test]
	public function a_conditioned_block_marks_at_render_time(): void {
		$this->registerPost( 425, $this->paragraph( 'NOTE', array( 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in' ) ) ) );

		$this->renderPost( 425 );

		$this->assertSame( array( 425 ), $this->uncacheable );
	}

	/** The action cache integrations listen on fires once per post, not once per block. */
	#[Test]
	public function many_restricted_blocks_in_one_post_mark_it_once(): void {
		$this->registerPost( 426, $this->secret( 'A' ) . $this->secret( 'B' ) . $this->secret( 'C' ) );

		$this->renderPost( 426 );

		$this->assertSame( array( 426 ), $this->uncacheable );
	}

	/** A block in a widget or bare template part belongs to no post, and still marks the page. */
	#[Test]
	public function a_restricted_block_outside_any_post_is_marked(): void {
		unset( $GLOBALS['post'] );

		do_blocks( $this->secret() );

		$this->assertSame( array( 0 ), $this->uncacheable );
	}

	#[Test]
	public function rendering_only_plain_blocks_marks_nothing(): void {
		$this->registerPost( 427, $this->paragraph( 'PLAIN' ) . $this->group( null, $this->paragraph( 'NESTED' ) ) );

		$this->renderPost( 427 );

		$this->assertSame( array(), $this->uncacheable );
	}

	/** An author's render is not a response a cache will ever hold. */
	#[Test]
	public function an_authors_render_is_not_marked(): void {
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;
		$this->registerPost( 428, $this->secret() );

		$this->renderPost( 428 );

		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function the_early_and_render_time_marks_agree_on_the_same_post(): void {
		$this->registerPost( 429, $this->secret() );
		$this->visit( 429 );

		do_action( 'template_redirect' );
		$this->renderPost( 429 );

		$this->assertSame( array( 429 ), $this->uncacheable, 'one post, one mark' );
	}

	// -----------------------------------------------------------------------
	// The constant
	// -----------------------------------------------------------------------

	/**
	 * `define()` cannot be undone, so the constant is asserted in a process of its
	 * own. Every other test here asserts the same fact through the action the
	 * marker fires, which can be reset.
	 */
	#[Test]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function the_early_marking_defines_donotcachepage(): void {
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ), 'a clean process starts without it' );

		$this->registerPost( 430, $this->secret() );
		$this->visit( 430 );

		do_action( 'template_redirect' );

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
		$this->assertTrue( DONOTCACHEPAGE );
	}

	#[Test]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function an_anonymous_render_time_mark_defines_donotcachepage(): void {
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );

		$this->registerPost( 431, $this->secret() );
		$this->renderPost( 431 );

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	#[Test]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function a_page_with_no_restricted_block_never_defines_donotcachepage(): void {
		$this->registerPost( 432, $this->paragraph( 'PLAIN' ) );
		$this->visit( 432 );

		do_action( 'template_redirect' );
		$this->renderPost( 432 );

		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );
	}
}
