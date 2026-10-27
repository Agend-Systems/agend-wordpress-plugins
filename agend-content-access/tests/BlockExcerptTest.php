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
use ReflectionMethod;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * A generated excerpt must not carry text from a restricted block.
 *
 * Core builds an excerpt by rendering the body through `excerpt_remove_blocks()`,
 * which renders the LEAF children of a Group, Columns or Column and never the
 * wrapper. A restriction on the wrapper therefore never reaches
 * `pre_render_block`, and its text reached every archive, search result, feed
 * description and `excerpt.rendered` in REST. These tests run core's own
 * functions (the stubs hold them verbatim), so they fail for the reason the leak
 * exists and not because a stand-in forgot to be wrong.
 *
 * Every assertion about text is made for an anonymous visitor AND a member: the
 * excerpt is the same for everybody, which is what makes it safe to cache.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class BlockExcerptTest extends BlockEnforcementTestCase {

	private const POST = 600;

	private Agend_Content_Access_Frontend $frontend;

	protected function setUp(): void {
		parent::setUp();

		// Core's default filter, which is what builds the excerpt.
		add_filter( 'get_the_excerpt', 'wp_trim_excerpt', 10, 2 );

		$this->frontend = new Agend_Content_Access_Frontend();
	}

	private function secretGroup( string $text = 'MEMBERS-ONLY SECRET' ): string {
		return $this->group( array( 'mode' => 'active_member' ), $this->paragraph( $text ) );
	}

	/** Registers the post and returns the excerpt the way `get_the_excerpt()` produces it. */
	private function excerptOf( string $content, array $fields = array(), int $id = self::POST ): string {
		$this->registerPost( $id, $content );
		$GLOBALS['agend_test_posts'][ $id ] = array_merge( $GLOBALS['agend_test_posts'][ $id ], $fields );

		return get_the_excerpt( get_post( $id ) );
	}

	/** The REST posts controller's own line for `excerpt.rendered`. */
	private function restExcerpt( \WP_Post $post ): string {
		return (string) Agend_Test_WP::apply_registered( 'get_the_excerpt', $post->post_excerpt, $post );
	}

	// -----------------------------------------------------------------------
	// The finding, as the reviewer reproduced it
	// -----------------------------------------------------------------------

	/** The premise: core's own excerpt reaches the wrapper's child. Without it nothing below proves anything. */
	#[Test]
	public function core_alone_does_leak_a_restricted_wrappers_text_into_the_excerpt(): void {
		$content = $this->paragraph( 'PUBLIC' ) . $this->secretGroup();

		$this->inTheLoopOf( self::PUBLIC_PAGE );

		$this->assertSame( '<p>PUBLIC</p>', do_blocks( $content ), 'the normal render hides it' );
		$this->assertStringContainsString( 'MEMBERS-ONLY SECRET', excerpt_remove_blocks( $content ), 'core\'s excerpt source does not' );
	}

	#[Test]
	public function an_anonymous_visitors_excerpt_of_a_restricted_group_leaks_nothing(): void {
		$excerpt = $this->excerptOf( $this->paragraph( 'PUBLIC' ) . $this->secretGroup() );

		$this->assertStringContainsString( 'PUBLIC', $excerpt );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	/** The excerpt is not personalised: an entitled member gets the same one. */
	#[Test]
	public function a_members_excerpt_is_the_same_one(): void {
		$content = $this->paragraph( 'PUBLIC' ) . $this->secretGroup();
		$visitor = $this->excerptOf( $content );

		$this->asMember( self::TIER_A );
		Agend_Test_WP::$filter_registry = array();
		add_filter( 'get_the_excerpt', 'wp_trim_excerpt', 10, 2 );
		$this->frontend = new Agend_Content_Access_Frontend();
		$this->blocks   = new Agend_Content_Access_Blocks();

		$this->assertSame( $visitor, get_the_excerpt( get_post( self::POST ) ) );
		$this->assertStringNotContainsString( 'SECRET', $visitor );
	}

	/** REST `excerpt.rendered`: the controller filters `get_the_excerpt` with the post. */
	#[Test]
	public function the_rest_excerpt_leaks_nothing(): void {
		$this->registerPost( self::POST, $this->paragraph( 'PUBLIC' ) . $this->secretGroup() );

		$excerpt = $this->restExcerpt( get_post( self::POST ) );

		$this->assertStringContainsString( 'PUBLIC', $excerpt );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	/** The RSS description is `the_excerpt_rss` over `get_the_excerpt()` of the global post. */
	#[Test]
	public function the_rss_description_leaks_nothing(): void {
		$this->registerPost( self::POST, $this->paragraph( 'PUBLIC' ) . $this->secretGroup() );
		$this->inTheLoopOf( self::POST );
		$GLOBALS['agend_test_is_feed'] = true;

		$description = (string) Agend_Test_WP::apply_registered( 'the_excerpt_rss', get_the_excerpt() );

		$this->assertStringContainsString( 'PUBLIC', $description );
		$this->assertStringNotContainsString( 'SECRET', $description );
	}

	// -----------------------------------------------------------------------
	// What is taken out
	// -----------------------------------------------------------------------

	#[Test]
	public function a_restricted_wrapper_nested_in_columns_is_taken_out(): void {
		$columns = '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column --><div class="wp-block-column">' . $this->paragraph( 'LEFT' ) . '</div><!-- /wp:column -->'
			. '<!-- wp:column --><div class="wp-block-column">' . $this->secretGroup() . '</div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';

		$excerpt = $this->excerptOf( $this->paragraph( 'PUBLIC' ) . $columns );

		$this->assertStringContainsString( 'LEFT', $excerpt );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	/** A rule on a column is a rule on all of it. */
	#[Test]
	public function a_restricted_column_is_taken_out_with_what_it_holds(): void {
		$column = '<!-- wp:column {"agendAccess":{"mode":"active_member"}} --><div class="wp-block-column">' . $this->paragraph( 'COLUMN SECRET' ) . '</div><!-- /wp:column -->';

		$excerpt = $this->excerptOf( '<!-- wp:columns --><div class="wp-block-columns">' . $column . '</div><!-- /wp:columns -->' . $this->paragraph( 'PUBLIC' ) );

		$this->assertStringContainsString( 'PUBLIC', $excerpt );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	#[Test]
	public function a_restricted_leaf_inside_a_plain_group_is_taken_out_and_its_sibling_stays(): void {
		$excerpt = $this->excerptOf(
			$this->group( null, $this->paragraph( 'KEPT' ), $this->paragraph( 'LEAF SECRET', array( 'mode' => 'active_member' ) ), $this->paragraph( 'ALSO KEPT' ) )
		);

		$this->assertStringContainsString( 'KEPT', $excerpt );
		$this->assertStringContainsString( 'ALSO KEPT', $excerpt );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	/** Display conditions personalise a block as much as a policy does. */
	#[Test]
	public function a_block_with_display_conditions_is_taken_out(): void {
		$excerpt = $this->excerptOf(
			$this->paragraph( 'PUBLIC' )
			. $this->group( array( 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in' ) ), $this->paragraph( 'CONDITIONED' ) )
		);

		$this->assertStringContainsString( 'PUBLIC', $excerpt );
		$this->assertStringNotContainsString( 'CONDITIONED', $excerpt );
	}

	/** A restriction nobody can read is still a restriction. */
	#[Test]
	public function a_block_with_an_unreadable_attribute_is_taken_out(): void {
		$excerpt = $this->excerptOf( $this->paragraph( 'PUBLIC' ) . $this->rawAttribute( '{"agendAccess":"members"}', 'UNREADABLE SECRET' ) );

		$this->assertStringContainsString( 'PUBLIC', $excerpt );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	/** A ruled block deep in a group of groups. */
	#[Test]
	public function a_restriction_four_levels_down_is_taken_out(): void {
		$excerpt = $this->excerptOf(
			$this->paragraph( 'PUBLIC' ) . $this->group( null, $this->group( null, $this->group( null, $this->secretGroup( 'DEEP SECRET' ) ) ) )
		);

		$this->assertStringContainsString( 'PUBLIC', $excerpt );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	/** The pattern's blocks are not in the page, but a rule in one is a rule on the page. */
	#[Test]
	public function a_restriction_inside_a_synced_pattern_keeps_the_pattern_out_of_the_excerpt(): void {
		$this->registerPattern( 930, $this->secretGroup( 'PATTERN SECRET' ) );

		$excerpt = $this->excerptOf( $this->paragraph( 'PUBLIC' ) . $this->reference( 930 ) );

		$this->assertStringContainsString( 'PUBLIC', $excerpt );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	/**
	 * Core does not render a synced pattern into an excerpt, and a site that
	 * allows it must still not get a rule through it.
	 */
	#[Test]
	public function a_site_that_allows_synced_patterns_in_excerpts_still_gets_no_restricted_text(): void {
		// A member, who WOULD be shown the pattern's restricted group if the
		// reference reached the render, so the pattern is what is being tested.
		$this->asMember( self::TIER_A );
		$this->registerPattern( 931, $this->secretGroup( 'ALLOWED PATTERN SECRET' ) );
		Agend_Test_WP::$filters['excerpt_allowed_blocks'] = static fn( array $allowed ): array => array_merge( $allowed, array( 'core/block' ) );

		$excerpt = $this->excerptOf( $this->paragraph( 'PUBLIC' ) . $this->reference( 931 ) );

		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	// -----------------------------------------------------------------------
	// What is left alone
	// -----------------------------------------------------------------------

	/** A document with no rule gets the excerpt core builds, wrappers and all. */
	#[Test]
	public function a_document_with_no_rule_gets_core_s_excerpt_unchanged(): void {
		$this->registerPost( self::POST, $this->paragraph( 'PUBLIC' ) . $this->group( null, $this->paragraph( 'WRAPPED' ) ) . $this->paragraph( 'INHERITS', array( 'mode' => 'inherit' ) ) );

		$core = wp_trim_excerpt( '', get_post( self::POST ) );

		$this->assertSame( $core, get_the_excerpt( get_post( self::POST ) ) );
		$this->assertStringContainsString( 'WRAPPED', $core );
	}

	/** The editor's own words are the deliberate public text. */
	#[Test]
	public function a_manual_excerpt_is_left_as_it_was_written(): void {
		$excerpt = $this->excerptOf( $this->paragraph( 'PUBLIC' ) . $this->secretGroup(), array( 'post_excerpt' => 'Written by hand.' ) );

		$this->assertSame( 'Written by hand.', $excerpt );
	}

	/** Core calls a whitespace-only excerpt empty and generates over it, so this must too. */
	#[Test]
	public function a_whitespace_only_excerpt_is_generated_over_safely(): void {
		$excerpt = $this->excerptOf( $this->paragraph( 'PUBLIC' ) . $this->secretGroup(), array( 'post_excerpt' => "  \n " ) );

		$this->assertStringContainsString( 'PUBLIC', $excerpt );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	/** An author sees the whole thing: the editor and its previews are not filtered. */
	#[Test]
	public function an_authors_excerpt_is_not_filtered(): void {
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;

		$excerpt = $this->excerptOf( $this->paragraph( 'PUBLIC' ) . $this->secretGroup() );

		$this->assertStringContainsString( 'MEMBERS-ONLY SECRET', $excerpt );
	}

	// -----------------------------------------------------------------------
	// How the safe text is found
	// -----------------------------------------------------------------------

	/**
	 * A post saved before the flag existed has none, and a pattern edited after the
	 * page was saved changes the page without touching its flag. Neither may leak.
	 */
	#[Test]
	public function a_post_with_no_flag_is_still_made_safe(): void {
		$excerpt = $this->excerptOf( $this->paragraph( 'PUBLIC' ) . $this->secretGroup() );

		$this->assertFalse( metadata_exists( 'post', self::POST, Agend_Content_Access_Frontend::FRAGMENT_RULES_META_KEY ) );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	/**
	 * The saved flag is honoured, and it is what covers a document the live block
	 * scan cannot read: an Elementor document whose data no longer shows the rule
	 * the flag was set for is still not trusted until it is saved again.
	 */
	#[Test]
	public function a_flagged_post_is_made_safe_whatever_the_live_scan_says(): void {
		$this->registerPost( self::POST, '' );
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Agend_Content_Access_Frontend::FRAGMENT_RULES_META_KEY ] = '1';

		$this->assertSame( '', $this->excerptWithElementorData( $this->elementorData() ) );
	}

	/**
	 * More references than the expansion budget allows: the last is not expanded,
	 * so nothing says what is in it. A site that lets patterns into excerpts would
	 * render it for the viewer, and a member would read the restricted block.
	 */
	#[Test]
	public function a_pattern_reference_that_could_not_be_expanded_is_kept_out(): void {
		$this->asMember( self::TIER_A );
		$this->registerPattern( 940, $this->paragraph( 'FILLER' ) );
		$this->registerPattern( 941, $this->secretGroup( 'OVER BUDGET SECRET' ) );
		Agend_Test_WP::$filters['excerpt_allowed_blocks'] = static fn( array $allowed ): array => array_merge( $allowed, array( 'core/block' ) );
		Agend_Test_WP::$filters['excerpt_length']         = static fn(): int => 500;

		$content = str_repeat( $this->reference( 940 ), 50 ) . $this->reference( 941 ) . $this->paragraph( 'PUBLIC' );
		$this->registerPost( self::POST, $content );
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Agend_Content_Access_Frontend::FRAGMENT_RULES_META_KEY ] = '1';

		$excerpt = get_the_excerpt( get_post( self::POST ) );

		$this->assertStringContainsString( 'PUBLIC', $excerpt );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	/** A block whose placeholders do not match its children cannot be rebuilt, so it goes. */
	#[Test]
	public function a_block_that_cannot_be_rebuilt_reliably_is_removed(): void {
		$rebuild = new ReflectionMethod( Agend_Content_Access_Blocks::class, 'without_ruled_block' );
		$rebuild->setAccessible( true );

		$child  = parse_blocks( $this->paragraph( 'CHILD' ) )[0];
		$broken = array(
			'blockName'    => 'core/group',
			'attrs'        => array(),
			'innerBlocks'  => array( $child ),
			'innerHTML'    => '<div></div>',
			'innerContent' => array( '<div></div>' ),
		);

		$this->assertNull( $rebuild->invoke( null, $broken ) );
	}

	/** Reads a post through a context other than `raw`, as `get_post( $id, OBJECT, 'display' )` does. */
	#[Test]
	public function a_post_read_in_another_filter_context_is_still_made_safe(): void {
		$this->registerPost( self::POST, $this->paragraph( 'PUBLIC' ) . $this->secretGroup() );
		$post         = get_post( self::POST );
		$post->filter = 'display';

		$excerpt = $this->restExcerpt( $post );

		$this->assertStringContainsString( 'PUBLIC', $excerpt );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	// -----------------------------------------------------------------------
	// Elementor
	// -----------------------------------------------------------------------

	/** An Elementor document, as `_elementor_data` holds it. */
	private function elementorData( array $widget_settings = array() ): string {
		return (string) wp_json_encode(
			array(
				array(
					'id'       => 's1',
					'elType'   => 'section',
					'settings' => array(),
					'elements' => array(
						array(
							'id'         => 'w1',
							'elType'     => 'widget',
							'widgetType' => 'text-editor',
							'settings'   => $widget_settings,
							'elements'   => array(),
						),
					),
				),
			)
		);
	}

	/** An Elementor document, whose `post_content` is the flattened text of what it rendered. */
	private function excerptWithElementorData( string $data ): string {
		$GLOBALS['agend_test_posts'][ self::POST ]['post_content'] = 'ELEMENTOR FLATTENED SECRET TEXT';
		$GLOBALS['agend_test_post_meta'][ self::POST ]['_elementor_data'] = $data;

		return get_the_excerpt( get_post( self::POST ) );
	}

	#[Test]
	public function an_elementor_document_with_a_restricted_widget_has_no_generated_excerpt(): void {
		$this->registerPost( self::POST, '' );

		$excerpt = $this->excerptWithElementorData( $this->elementorData( array( 'agend_access_mode' => 'active_member' ) ) );

		$this->assertSame( '', $excerpt );
	}

	#[Test]
	public function an_elementor_document_with_display_conditions_has_no_generated_excerpt(): void {
		$this->registerPost( self::POST, '' );

		$this->assertSame( '', $this->excerptWithElementorData( $this->elementorData( array( 'agend_conditions_enabled' => 'yes' ) ) ) );
	}

	/** Nothing to hide, so the excerpt is the one core builds from what Elementor stored. */
	#[Test]
	public function an_elementor_document_with_no_rule_keeps_its_excerpt(): void {
		$this->registerPost( self::POST, '' );

		$this->assertStringContainsString( 'ELEMENTOR FLATTENED', $this->excerptWithElementorData( $this->elementorData() ) );
	}

	#[Test]
	public function an_elementor_document_that_does_not_decode_has_no_generated_excerpt(): void {
		$this->registerPost( self::POST, '' );

		$this->assertSame( '', $this->excerptWithElementorData( '{not json' ) );
	}

	/** The rest of the Elementor document's own `post_content` is never parsed as blocks. */
	#[Test]
	public function an_elementor_document_is_not_rebuilt_from_its_post_content(): void {
		$this->registerPost( self::POST, $this->paragraph( 'BLOCK LOOKING TEXT' ) );
		$GLOBALS['agend_test_post_meta'][ self::POST ]['_elementor_data'] = $this->elementorData( array( 'agend_access_mode' => 'active_member' ) );

		$this->assertSame( '', $this->blocks->safe_generated_excerpt( '', get_post( self::POST ) ) );
	}

	// -----------------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------------

	private function registrationsOn( string $hook ): array {
		return array_map(
			static fn( array $entry ): string => ( is_array( $entry['callback'] ) ? $entry['callback'][1] : (string) $entry['callback'] ) . '@' . $entry['priority'],
			Agend_Test_WP::$filter_registry[ $hook ] ?? array()
		);
	}

	/** After `wp_trim_excerpt` (10), which builds the excerpt, and before the gate, which may still replace it. */
	#[Test]
	public function the_generated_excerpt_filter_runs_between_core_and_the_gate(): void {
		$registered = $this->registrationsOn( 'get_the_excerpt' );

		$this->assertContains( 'filter_generated_excerpt@11', $registered );
		$this->assertContains( 'filter_excerpt@' . PHP_INT_MAX, $registered );

		foreach ( Agend_Test_WP::$filter_registry['get_the_excerpt'] as $entry ) {
			if ( is_array( $entry['callback'] ) && 'filter_generated_excerpt' === $entry['callback'][1] ) {
				$this->assertSame( 2, $entry['args'], 'it needs the post' );
			}
		}
	}

	/** One method, one registration: wiring the same instance again does not add a second. */
	#[Test]
	public function the_generated_excerpt_filter_is_registered_only_once(): void {
		$wire = new ReflectionMethod( $this->frontend, 'register_render_filters' );
		$wire->setAccessible( true );
		$wire->invoke( $this->frontend );

		$this->assertCount(
			1,
			array_filter(
				$this->registrationsOn( 'get_the_excerpt' ),
				static fn( string $entry ): bool => 0 === strpos( $entry, 'filter_generated_excerpt@' )
			)
		);
	}

	#[Test]
	public function the_block_half_is_registered_on_the_shared_filter(): void {
		$entry = Agend_Test_WP::$filter_registry['agend_content_access_safe_generated_excerpt'][0];

		$this->assertSame( array( $this->blocks, 'safe_generated_excerpt' ), $entry['callback'] );
		$this->assertSame( 2, $entry['args'] );
	}

	/** Left alone the shared filter answers '', which is what keeps a document nothing can rebuild blank. */
	#[Test]
	public function with_no_block_half_the_safe_excerpt_is_empty(): void {
		unset( Agend_Test_WP::$filters['agend_content_access_safe_generated_excerpt'] );
		$this->registerPost( self::POST, $this->paragraph( 'PUBLIC' ) . $this->secretGroup() );

		$this->assertSame( '', get_the_excerpt( get_post( self::POST ) ) );
	}

	#[Test]
	public function what_is_not_a_post_is_passed_through(): void {
		$this->assertSame( 'text', $this->frontend->filter_generated_excerpt( 'text' ) );
		$this->assertSame( 'text', $this->frontend->filter_generated_excerpt( 'text', 12 ) );
		$this->assertSame( 'kept', $this->blocks->safe_generated_excerpt( 'kept' ) );
	}
}
