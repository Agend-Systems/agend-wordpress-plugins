<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Block_Template_Source;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Post;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-source.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/class-agend-apps-block-template-source.php';

/**
 * `Agend_Apps_Block_Template_Source`: the block-editor implementation of the
 * Agend Apps Core template picker source, including the `save_post_wp_block`
 * tagging predicate (Decision D2) and the `has_block()`-based surface
 * advisory (Decision D7).
 */
#[CoversClass( Agend_Apps_Block_Template_Source::class )]
final class BlockTemplateSourceTest extends TestCase {

	private Agend_Apps_Block_Template_Source $source;

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Block_Template_Source::flush_cache();
		$this->source = new Agend_Apps_Block_Template_Source();
	}

	protected function tearDown(): void {
		Agend_Apps_Block_Template_Source::flush_cache();
		parent::tearDown();
	}

	private function seedCandidates( array $candidates ): void {
		Agend_Test_WP::$filters['agend_apps_block_template_candidate_ids'] = static function () use ( $candidates ) {
			return $candidates;
		};
	}

	// -- label() --------------------------------------------------------

	#[Test]
	public function should_label_itself_blocks(): void {
		$this->assertSame( 'Blocks', $this->source->label() );
	}

	// -- templates() / map() (candidate filter escape hatch) -------------

	#[Test]
	public function should_list_qualifying_templates_without_a_placeholder(): void {
		$this->seedCandidates( array( 5 => 'Event card' ) );

		$this->assertSame( array( '5' => 'Event card' ), $this->source->templates() );
	}

	#[Test]
	public function should_preserve_candidate_ordering_and_cast_ids_to_string_keys(): void {
		$this->seedCandidates(
			array(
				34 => 'Zeta',
				12 => 'Alpha',
			)
		);

		$this->assertSame( array( '34' => 'Zeta', '12' => 'Alpha' ), $this->source->templates() );
	}

	#[Test]
	public function should_return_the_cached_map_without_reinvoking_the_candidate_filter_on_a_static_cache_hit(): void {
		$calls = 0;
		Agend_Test_WP::$filters['agend_apps_block_template_candidate_ids'] = static function () use ( &$calls ) {
			++$calls;
			return array( 5 => 'Event card' );
		};

		$first  = $this->source->templates();
		$second = $this->source->templates();

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $calls );
	}

	#[Test]
	public function should_reinvoke_the_candidate_filter_after_flush_cache_clears_the_cache(): void {
		$calls = 0;
		Agend_Test_WP::$filters['agend_apps_block_template_candidate_ids'] = static function () use ( &$calls ) {
			++$calls;
			return array( 5 => 'Event card' );
		};

		$this->source->templates();
		Agend_Apps_Block_Template_Source::flush_cache();
		$this->source->templates();

		$this->assertSame( 2, $calls );
	}

	// -- implied_type() / tag_post(): the D2 tagging predicate -----------

	#[Test]
	public function should_imply_event_from_a_top_level_record_field_block(): void {
		$content = '<!-- wp:agend-apps/record-field {"field":"event:name"} /-->';

		$this->assertSame( 'event', Agend_Apps_Block_Template_Source::implied_type( $content ) );
	}

	#[Test]
	public function should_imply_listing_from_a_record_field_block_nested_inside_a_wrapper(): void {
		$content = '<!-- wp:agend-apps/record-panel {"recordType":"listing"} -->'
			. '<!-- wp:core/group -->'
			. '<!-- wp:agend-apps/record-field {"field":"listing:name"} /-->'
			. '<!-- /wp:core/group -->'
			. '<!-- /wp:agend-apps/record-panel -->';

		$this->assertSame( 'listing', Agend_Apps_Block_Template_Source::implied_type( $content ) );
	}

	#[Test]
	public function should_imply_course_from_a_record_blocks_block_attribute(): void {
		$content = '<!-- wp:agend-apps/record-block {"block":"course_about"} /-->';

		$this->assertSame( 'course', Agend_Apps_Block_Template_Source::implied_type( $content ) );
	}

	#[Test]
	public function should_return_empty_string_when_content_has_no_agend_record_block(): void {
		$content = '<!-- wp:core/paragraph --><p>Just a paragraph.</p><!-- /wp:core/paragraph -->';

		$this->assertSame( '', Agend_Apps_Block_Template_Source::implied_type( $content ) );
	}

	#[Test]
	public function should_return_empty_string_when_the_only_record_field_is_a_common_field(): void {
		// 'common:' fields apply to every type and so name none
		// (agend_apps_records_type_from_key()); a template built from common
		// fields alone should not qualify.
		$content = '<!-- wp:agend-apps/record-field {"field":"common:title"} /-->';

		$this->assertSame( '', Agend_Apps_Block_Template_Source::implied_type( $content ) );
	}

	#[Test]
	public function should_return_empty_string_for_empty_content(): void {
		$this->assertSame( '', Agend_Apps_Block_Template_Source::implied_type( '' ) );
	}

	// -- tag_post(): the save_post_wp_block handler -----------------------

	private function makeWpBlockPost( int $id, string $content ): WP_Post {
		return new WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
	}

	#[Test]
	public function should_tag_a_qualifying_post_with_its_implied_record_type(): void {
		$post = $this->makeWpBlockPost( 30, '<!-- wp:agend-apps/record-field {"field":"event:name"} /-->' );

		Agend_Apps_Block_Template_Source::tag_post( 30, $post );

		$this->assertSame( 'event', get_post_meta( 30, Agend_Apps_Block_Template_Source::TYPE_META_KEY, true ) );
	}

	#[Test]
	public function should_untag_a_post_that_no_longer_qualifies(): void {
		$post = $this->makeWpBlockPost( 31, '<!-- wp:agend-apps/record-field {"field":"event:name"} /-->' );
		Agend_Apps_Block_Template_Source::tag_post( 31, $post );
		$this->assertSame( 'event', get_post_meta( 31, Agend_Apps_Block_Template_Source::TYPE_META_KEY, true ) );

		$edited = $this->makeWpBlockPost( 31, '<!-- wp:core/paragraph --><p>Nothing Agend here.</p><!-- /wp:core/paragraph -->' );
		Agend_Apps_Block_Template_Source::tag_post( 31, $edited );

		$this->assertSame( '', get_post_meta( 31, Agend_Apps_Block_Template_Source::TYPE_META_KEY, true ) );
	}

	/**
	 * A filters template holds `agend-apps/filter` blocks and no record block
	 * at all. The first version of this class tested the block name against an
	 * `agend-apps/record-` prefix, so a filters template was never tagged and
	 * could not be chosen in any picker: the feature was unusable, reported
	 * from the editor rather than caught here.
	 */
	#[Test]
	public function should_tag_a_filters_template_built_only_from_filter_blocks(): void {
		$post = $this->makeWpBlockPost( 40, '<!-- wp:agend-apps/filter {"filter":"event:category"} /-->' );

		Agend_Apps_Block_Template_Source::tag_post( 40, $post );

		$this->assertSame(
			'event',
			get_post_meta( 40, Agend_Apps_Block_Template_Source::TYPE_META_KEY, true ),
			"a filter's own setting keys its type the same way a field's does"
		);
	}

	/**
	 * Qualifying and typing are separate questions. The block editor omits an
	 * attribute equal to its default from the serialised markup, so a surface
	 * left at its defaults has no `attrs` at all. That is still a template.
	 */
	#[Test]
	public function should_tag_a_template_whose_surfaces_sit_at_their_defaults_as_type_unknown(): void {
		$post = $this->makeWpBlockPost( 41, '<!-- wp:agend-apps/record-field /-->' );

		Agend_Apps_Block_Template_Source::tag_post( 41, $post );

		$this->assertSame(
			Agend_Apps_Block_Template_Source::TYPE_UNKNOWN,
			get_post_meta( 41, Agend_Apps_Block_Template_Source::TYPE_META_KEY, true ),
			'a template with no inferable type must still be tagged, or no picker can offer it'
		);
	}

	#[Test]
	public function should_tag_an_all_common_field_template_as_type_unknown(): void {
		$post = $this->makeWpBlockPost( 42, '<!-- wp:agend-apps/record-field {"field":"common:title"} /-->' );

		Agend_Apps_Block_Template_Source::tag_post( 42, $post );

		$this->assertSame(
			Agend_Apps_Block_Template_Source::TYPE_UNKNOWN,
			get_post_meta( 42, Agend_Apps_Block_Template_Source::TYPE_META_KEY, true ),
			'a common: field names no type, but the pattern is plainly still a template'
		);
	}

	#[Test]
	public function should_tag_a_filters_template_left_at_its_defaults(): void {
		$post = $this->makeWpBlockPost( 43, '<!-- wp:agend-apps/filter /--><!-- wp:agend-apps/filter /-->' );

		Agend_Apps_Block_Template_Source::tag_post( 43, $post );

		$this->assertSame(
			Agend_Apps_Block_Template_Source::TYPE_UNKNOWN,
			get_post_meta( 43, Agend_Apps_Block_Template_Source::TYPE_META_KEY, true )
		);
	}

	#[Test]
	public function should_find_a_template_surface_nested_inside_wrapper_blocks(): void {
		$content = '<!-- wp:core/group --><!-- wp:core/columns --><!-- wp:agend-apps/filter {"filter":"listing:category"} /--><!-- /wp:core/columns --><!-- /wp:core/group -->';

		Agend_Apps_Block_Template_Source::tag_post( 44, $this->makeWpBlockPost( 44, $content ) );

		$this->assertSame( 'listing', get_post_meta( 44, Agend_Apps_Block_Template_Source::TYPE_META_KEY, true ) );
	}

	#[Test]
	public function should_not_tag_a_pattern_holding_no_agend_template_surface(): void {
		$content = '<!-- wp:agend-apps/events-catalogue /--><!-- wp:core/paragraph --><p>Hi</p><!-- /wp:core/paragraph -->';

		Agend_Apps_Block_Template_Source::tag_post( 45, $this->makeWpBlockPost( 45, $content ) );

		$this->assertSame(
			'',
			get_post_meta( 45, Agend_Apps_Block_Template_Source::TYPE_META_KEY, true ),
			'a catalogue block is a page-level surface, not something a card template is built from'
		);
	}

	#[Test]
	public function should_not_record_a_type_unknown_value_that_a_consumer_could_mistake_for_a_record_type(): void {
		$this->assertNotContains(
			Agend_Apps_Block_Template_Source::TYPE_UNKNOWN,
			array( 'event', 'course', 'listing' ),
			'TYPE_UNKNOWN must never collide with a real record type, or a preview would trust it'
		);
	}

	#[Test]
	public function should_ignore_a_post_that_is_not_a_wp_block(): void {
		$post = new WP_Post(
			array(
				'ID'           => 32,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:agend-apps/record-field {"field":"event:name"} /-->',
			)
		);

		Agend_Apps_Block_Template_Source::tag_post( 32, $post );

		$this->assertSame( '', get_post_meta( 32, Agend_Apps_Block_Template_Source::TYPE_META_KEY, true ) );
	}

	#[Test]
	public function should_flush_the_template_cache_when_a_post_is_tagged(): void {
		$calls = 0;
		Agend_Test_WP::$filters['agend_apps_block_template_candidate_ids'] = static function () use ( &$calls ) {
			++$calls;
			return array( 5 => 'Event card' );
		};

		$this->source->templates();
		$this->assertSame( 1, $calls );

		Agend_Apps_Block_Template_Source::tag_post( 30, $this->makeWpBlockPost( 30, '<!-- wp:agend-apps/record-field {"field":"event:name"} /--> ' ) );

		$this->source->templates();
		$this->assertSame( 2, $calls, 'tagging a post invalidates the cached template listing' );
	}

	// -- page_contains_surface() (Decision D7) -----------------------------

	private function seedPage( int $id, string $content ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => $content,
		);
	}

	#[Test]
	public function should_return_null_for_an_unmapped_surface(): void {
		$this->seedPage( 40, '' );

		$this->assertNull( $this->source->page_contains_surface( 40, 'no-such-surface' ) );
	}

	#[Test]
	public function should_return_null_when_the_page_does_not_exist(): void {
		$this->assertNull( $this->source->page_contains_surface( 999, 'events-catalogue' ) );
	}

	#[Test]
	public function should_return_true_when_the_page_contains_the_matching_catalogue_block(): void {
		$this->seedPage( 41, '<!-- wp:agend-apps/events-catalogue /-->' );

		$this->assertTrue( $this->source->page_contains_surface( 41, 'events-catalogue' ) );
	}

	#[Test]
	public function should_return_false_when_the_page_does_not_contain_the_matching_catalogue_block(): void {
		$this->seedPage( 42, '<!-- wp:core/paragraph --><p>No catalogue here.</p><!-- /wp:core/paragraph -->' );

		$this->assertFalse( $this->source->page_contains_surface( 42, 'events-catalogue' ) );
	}

	#[Test]
	public function should_return_false_for_a_surface_placed_only_inside_a_referenced_synced_pattern(): void {
		// Documented false-negative (Decision D7): the surface genuinely
		// exists on the site, inside a synced pattern (its own wp_block post,
		// id 999 here), but the HOST page's own content holds only a
		// reference to it, not the pattern's content, so has_block() cannot
		// see it from the page alone.
		$this->seedTemplateReferencedBySyncedPattern( 999, '<!-- wp:agend-apps/events-catalogue /-->' );
		$this->seedPage( 43, '<!-- wp:block {"ref":999} /-->' );

		$this->assertFalse( $this->source->page_contains_surface( 43, 'events-catalogue' ) );
	}

	private function seedTemplateReferencedBySyncedPattern( int $id, string $content ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_content' => $content,
		);
	}
}
