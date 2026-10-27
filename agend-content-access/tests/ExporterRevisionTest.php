<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Exporter as Exporter;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use WP_Post;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-assets.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/interface-agend-content-access-document-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-exporter.php';

/**
 * The revision token Agend uses to decide whether a record needs syncing again.
 *
 * Two properties. A block page's revision must move when a synced pattern it
 * resolves is edited, because the pattern's blocks are not in the page's own
 * content and the fragments change all the same. And nothing else's revision
 * may move at all: an Elementor post and a classic post keep exactly the value
 * they had before block pages were exported.
 */
#[CoversClass( Exporter::class )]
final class ExporterRevisionTest extends TestCase {

	private const POST_ID = 321;

	private function post( string $content, array $over = array() ): WP_Post {
		return new WP_Post(
			array_merge(
				array(
					'ID'                => self::POST_ID,
					'post_content'      => $content,
					'post_modified_gmt' => '2026-10-01 00:00:00',
				),
				$over
			)
		);
	}

	private function pattern( int $id, string $content, array $over = array() ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array_merge(
			array(
				'ID'                => $id,
				'post_type'         => 'wp_block',
				'post_status'       => 'publish',
				'post_content'      => $content,
				'post_modified_gmt' => '2026-09-01 00:00:00',
			),
			$over
		);
	}

	private function para( string $text, string $attrs = '' ): string {
		return '<!-- wp:paragraph' . $attrs . ' --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	private function ref( int $id ): string {
		return '<!-- wp:block {"ref":' . $id . '} /-->';
	}

	private function members(): string {
		return ' {"agendAccess":{"mode":"active_member"}}';
	}

	/** The token as it was before block pages: five fields, joined. */
	private function legacyRevision( WP_Post $post, string $elementor = '', string $policy = 'null' ): string {
		return hash(
			'sha256',
			implode(
				'|',
				array(
					(string) $post->post_modified_gmt,
					(string) $post->post_status,
					$policy,
					$elementor,
					(string) $post->post_content,
				)
			)
		);
	}

	private function revision( string $content ): string {
		return Exporter::revision_of( $this->post( $content ) );
	}

	// -----------------------------------------------------------------
	// Nothing else moves
	// -----------------------------------------------------------------

	#[Test]
	public function a_classic_posts_revision_is_unchanged(): void {
		$post = $this->post( '<p>Plain post</p>' );

		$this->assertSame( $this->legacyRevision( $post ), Exporter::revision_of( $post ) );
	}

	/** Even where `post_content` holds block comments, which Elementor keeps a copy of. */
	#[Test]
	public function an_elementor_posts_revision_is_unchanged(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] = '[{"id":"a1","elType":"section"}]';

		$this->pattern( 900, $this->para( 'Pattern' ) );

		$post = $this->post( $this->ref( 900 ) );

		$this->assertSame( $this->legacyRevision( $post, '[{"id":"a1","elType":"section"}]' ), Exporter::revision_of( $post ) );
	}

	#[Test]
	public function a_block_page_that_resolves_no_pattern_is_unchanged(): void {
		$post = $this->post( $this->para( 'One' ) . $this->ref( 404 ) );

		$this->assertSame( $this->legacyRevision( $post ), Exporter::revision_of( $post ) );
	}

	// -----------------------------------------------------------------
	// A pattern the page resolves
	// -----------------------------------------------------------------

	#[Test]
	public function a_resolved_pattern_is_part_of_the_revision(): void {
		$this->pattern( 900, $this->para( 'Pattern' ) );

		$post = $this->post( $this->ref( 900 ) );

		$this->assertNotSame( $this->legacyRevision( $post ), Exporter::revision_of( $post ) );
	}

	#[Test]
	public function an_unchanged_pattern_leaves_the_revision_alone(): void {
		$this->pattern( 900, $this->para( 'Pattern' ) );

		$this->assertSame( $this->revision( $this->ref( 900 ) ), $this->revision( $this->ref( 900 ) ) );
	}

	/** The case this exists for: a restriction is added to a pattern, and the page itself is untouched. */
	#[Test]
	public function editing_a_pattern_changes_the_pages_revision(): void {
		$this->pattern( 900, $this->para( 'Pattern' ) );

		$before = $this->revision( $this->ref( 900 ) );

		$this->pattern( 900, $this->para( 'Pattern', $this->members() ) );

		$this->assertNotSame( $before, $this->revision( $this->ref( 900 ) ) );
	}

	#[Test]
	public function saving_a_pattern_again_changes_the_revision_even_with_the_same_content(): void {
		$this->pattern( 900, $this->para( 'Pattern' ) );

		$before = $this->revision( $this->ref( 900 ) );

		$this->pattern( 900, $this->para( 'Pattern' ), array( 'post_modified_gmt' => '2026-10-02 09:00:00' ) );

		$this->assertNotSame( $before, $this->revision( $this->ref( 900 ) ) );
	}

	#[Test]
	public function a_pattern_losing_its_published_status_changes_the_revision(): void {
		$this->pattern( 900, $this->para( 'Pattern' ) );

		$before = $this->revision( $this->ref( 900 ) );

		$this->pattern( 900, $this->para( 'Pattern' ), array( 'post_status' => 'draft' ) );

		$this->assertNotSame( $before, $this->revision( $this->ref( 900 ) ), 'it is no longer resolved, so the fragments changed' );
	}

	#[Test]
	public function a_pattern_becoming_published_changes_the_revision(): void {
		$this->pattern( 900, $this->para( 'Pattern' ), array( 'post_status' => 'draft' ) );

		$before = $this->revision( $this->ref( 900 ) );

		$this->pattern( 900, $this->para( 'Pattern' ) );

		$this->assertNotSame( $before, $this->revision( $this->ref( 900 ) ) );
	}

	/** A pattern the page does not reference has no bearing on its fragments. */
	#[Test]
	public function editing_a_pattern_the_page_does_not_use_changes_nothing(): void {
		$this->pattern( 900, $this->para( 'Used' ) );
		$this->pattern( 901, $this->para( 'Unused' ) );

		$before = $this->revision( $this->ref( 900 ) );

		$this->pattern( 901, $this->para( 'Unused, edited', $this->members() ) );

		$this->assertSame( $before, $this->revision( $this->ref( 900 ) ) );
	}

	#[Test]
	public function a_draft_patterns_edits_change_nothing_while_it_is_not_resolved(): void {
		$this->pattern( 900, $this->para( 'Draft' ), array( 'post_status' => 'draft' ) );

		$before = $this->revision( $this->ref( 900 ) );

		$this->pattern( 900, $this->para( 'Draft, edited' ), array( 'post_status' => 'draft' ) );

		$this->assertSame( $before, $this->revision( $this->ref( 900 ) ) );
	}

	#[Test]
	public function a_pattern_nested_inside_another_is_followed(): void {
		$this->pattern( 900, $this->para( 'Outer' ) . $this->ref( 901 ) );
		$this->pattern( 901, $this->para( 'Inner' ) );

		$before = $this->revision( $this->ref( 900 ) );

		$this->pattern( 901, $this->para( 'Inner', $this->members() ) );

		$this->assertNotSame( $before, $this->revision( $this->ref( 900 ) ) );
	}

	#[Test]
	public function a_pattern_inside_another_block_is_followed(): void {
		$this->pattern( 900, $this->para( 'Pattern' ) );

		$content = '<!-- wp:group --><div>' . $this->ref( 900 ) . '</div><!-- /wp:group -->';
		$before  = $this->revision( $content );

		$this->pattern( 900, $this->para( 'Pattern, edited' ) );

		$this->assertNotSame( $before, $this->revision( $content ) );
	}

	// -----------------------------------------------------------------
	// An unreadable stored policy
	// -----------------------------------------------------------------

	private function storeUnreadablePolicy(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_agend_access_policy'] = array( 'mode' => 'authenticated' );
	}

	/** A block post is refused like any other: exported as null it would be read as public. */
	#[Test]
	public function a_block_post_with_an_unreadable_policy_is_refused(): void {
		$this->storeUnreadablePolicy();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'unreadable' );

		Exporter::record( $this->post( $this->para( 'Careful' ) ) );
	}

	#[Test]
	public function an_unreadable_policy_hashes_as_a_marker_for_every_kind_of_post(): void {
		$this->storeUnreadablePolicy();

		$classic = $this->post( '<p>Plain post</p>' );

		$this->assertSame( $this->legacyRevision( $classic, '', 'unreadable' ), Exporter::revision_of( $classic ) );

		$block = $this->post( $this->para( 'One' ) );

		$this->assertSame( $this->legacyRevision( $block, '', 'unreadable' ), Exporter::revision_of( $block ) );

		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] = '[{"id":"a1","elType":"section"}]';

		$this->assertSame(
			$this->legacyRevision( $block, '[{"id":"a1","elType":"section"}]', 'unreadable' ),
			Exporter::revision_of( $block )
		);
	}

	/** The listing walks every document, so the revision must never throw on one. */
	#[Test]
	public function a_block_page_with_an_unreadable_policy_and_a_pattern_still_yields_a_revision(): void {
		$this->storeUnreadablePolicy();
		$this->pattern( 900, $this->para( 'Pattern' ) );

		$post     = $this->post( $this->ref( 900 ) );
		$revision = Exporter::revision_of( $post );

		$this->assertNotSame( $this->legacyRevision( $post, '', 'unreadable' ), $revision, 'the pattern is still folded in' );
		$this->assertSame( $revision, Exporter::revision_of( $post ) );
	}

	// -----------------------------------------------------------------
	// Guards
	// -----------------------------------------------------------------

	#[Test]
	public function a_cycle_does_not_throw_and_the_revision_is_stable(): void {
		$this->pattern( 900, $this->para( 'One' ) . $this->ref( 901 ) );
		$this->pattern( 901, $this->para( 'Two' ) . $this->ref( 900 ) );

		$first  = $this->revision( $this->ref( 900 ) );
		$second = $this->revision( $this->ref( 900 ) );

		$this->assertSame( 64, strlen( $first ) );
		$this->assertSame( $first, $second );

		$this->pattern( 901, $this->para( 'Two, edited' ) . $this->ref( 900 ) );

		$this->assertNotSame( $first, $this->revision( $this->ref( 900 ) ) );
	}

	#[Test]
	public function a_self_referencing_pattern_does_not_throw(): void {
		$this->pattern( 900, $this->ref( 900 ) );

		$this->assertSame( $this->revision( $this->ref( 900 ) ), $this->revision( $this->ref( 900 ) ) );
	}

	#[Test]
	public function a_missing_reference_does_not_throw_and_the_revision_is_stable(): void {
		$content = $this->para( 'One' ) . $this->ref( 404 ) . '<!-- wp:block /-->' . '<!-- wp:block {"ref":"abc"} /-->';

		$this->assertSame( $this->revision( $content ), $this->revision( $content ) );
	}

	/** The revision watches the patterns the fragments were built from, no more and no fewer. */
	#[Test]
	public function the_revision_stops_watching_where_the_export_stops_resolving(): void {
		$references = '';

		for ( $i = 0; $i < 60; $i++ ) {
			$this->pattern( 1000 + $i, $this->para( 'Pattern ' . $i ) );

			$references .= $this->ref( 1000 + $i );
		}

		$before = $this->revision( $references );

		// The fiftieth reference is the last one the export resolves.
		$this->pattern( 1050, $this->para( 'Past the budget, edited' ) );
		$this->assertSame( $before, $this->revision( $references ), 'the fifty-first pattern is not in the fragments' );

		$this->pattern( 1049, $this->para( 'Within the budget, edited' ) );
		$this->assertNotSame( $before, $this->revision( $references ), 'the fiftieth is' );
	}
}
