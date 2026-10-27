<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Block_Parser;
use Agend_Content_Access_Document_Parser;
use Agend_Content_Access_Elementor_Parser;
use Agend_Content_Access_Exporter as Exporter;
use Agend_Test_WP;
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
 * Which driver turns a post into fragments.
 *
 * The exporter used to sniff `_elementor_data` and call the Elementor parser by
 * name. It now asks a list of drivers, first match wins, with the single `body`
 * fragment as the fallback. What must not change is the output for an Elementor
 * page and for a classic post; a block editor page is the third case, handed to
 * the block driver.
 */
#[CoversClass( Exporter::class )]
#[CoversClass( Agend_Content_Access_Elementor_Parser::class )]
#[CoversClass( Agend_Content_Access_Block_Parser::class )]
final class ExporterDispatchTest extends TestCase {

	private const POST_ID = 321;

	private function post( string $content = '<p>Body</p>' ): WP_Post {
		return new WP_Post( array( 'ID' => self::POST_ID, 'post_content' => $content ) );
	}

	private function storeElementor( array $document ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] = (string) json_encode( $document );
	}

	private function widget( string $id, string $text ): array {
		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'settings'   => array( 'editor' => $text ),
		);
	}

	/** A driver that records what it was asked and answers as configured. */
	private function driver( bool $recognises, string $marker ): Agend_Content_Access_Document_Parser {
		return new class( $recognises, $marker ) implements Agend_Content_Access_Document_Parser {
			public int $asked = 0;

			private bool $recognises;
			private string $marker;

			public function __construct( bool $recognises, string $marker ) {
				$this->recognises = $recognises;
				$this->marker     = $marker;
			}

			public function recognises( $post ): bool {
				++$this->asked;

				return $this->recognises;
			}

			public function fragments( $post ): array {
				return array(
					array(
						'id'       => $this->marker,
						'position' => 0,
						'type'     => 'test:' . $this->marker,
						'payload'  => array( 'html' => '' ),
						'policy'   => array( 'mode' => 'inherit' ),
					),
				);
			}
		};
	}

	// -----------------------------------------------------------------
	// The two cases that exist today
	// -----------------------------------------------------------------

	#[Test]
	public function an_elementor_post_is_parsed_by_the_elementor_driver(): void {
		$this->storeElementor(
			array(
				array(
					'id'       => 'sec0001',
					'elType'   => 'section',
					'settings' => array( 'agend_access_mode' => 'active_member' ),
					'elements' => array( $this->widget( 'wid0001', 'Members only text' ) ),
				),
			)
		);

		$fragments = Exporter::fragments_of( $this->post(), null );

		$this->assertCount( 1, $fragments );
		$this->assertSame( 'wid0001', $fragments[0]['id'] );
		$this->assertSame( 'elementor:text-editor', $fragments[0]['type'] );
		$this->assertSame( 'Members only text', $fragments[0]['payload']['html'] );
		$this->assertSame( array( 'mode' => 'active_member' ), $fragments[0]['policy'], 'the folded ancestor policy must reach the fragment' );
	}

	#[Test]
	public function the_elementor_driver_is_the_first_driver_and_the_block_driver_the_second(): void {
		$parsers = Exporter::parsers();

		$this->assertCount( 2, $parsers );
		$this->assertInstanceOf( Agend_Content_Access_Elementor_Parser::class, $parsers[0] );
		$this->assertInstanceOf( Agend_Content_Access_Block_Parser::class, $parsers[1] );
	}

	// -----------------------------------------------------------------
	// The block editor
	// -----------------------------------------------------------------

	#[Test]
	public function a_block_post_is_parsed_by_the_block_driver(): void {
		$content = '<!-- wp:paragraph --><p>Open</p><!-- /wp:paragraph -->' . "\n\n"
			. '<!-- wp:paragraph {"agendAccess":{"mode":"active_member"}} --><p>Members only</p><!-- /wp:paragraph -->';

		$fragments = Exporter::fragments_of( $this->post( $content ), null );

		$this->assertSame( array( 'block-0', 'block-2' ), array_column( $fragments, 'id' ) );
		$this->assertSame( 'block:core/paragraph', $fragments[1]['type'] );
		$this->assertSame( array( 'html' => '<p>Members only</p>', 'extracted' => true ), $fragments[1]['payload'] );
		$this->assertSame( array( 'mode' => 'inherit' ), $fragments[0]['policy'] );
		$this->assertSame( array( 'mode' => 'active_member' ), $fragments[1]['policy'] );
	}

	/** Elementor keeps a copy of its output in `post_content`, which may hold block comments. */
	#[Test]
	public function an_elementor_post_is_still_parsed_by_the_elementor_driver_when_its_content_holds_blocks(): void {
		$this->storeElementor( array( $this->widget( 'wid0001', 'Elementor text' ) ) );

		$fragments = Exporter::fragments_of( $this->post( '<!-- wp:paragraph --><p>Copy</p><!-- /wp:paragraph -->' ), null );

		$this->assertSame( array( 'wid0001' ), array_column( $fragments, 'id' ) );
		$this->assertSame( 'elementor:text-editor', $fragments[0]['type'] );
	}

	/** The Elementor rule outranks the filter, so a list holding only the block driver cannot take an Elementor post. */
	#[Test]
	public function an_elementor_post_stays_with_the_elementor_driver_when_the_filter_keeps_only_the_block_driver(): void {
		$this->storeElementor( array( $this->widget( 'wid0001', 'Elementor text' ) ) );

		Agend_Test_WP::$filters['agend_content_access_document_parsers'] =
			static fn( array $parsers ): array => array( $parsers[1] );

		$fragments = Exporter::fragments_of( $this->post( '<!-- wp:paragraph --><p>Copy</p><!-- /wp:paragraph -->' ), null );

		$this->assertSame( array( 'wid0001' ), array_column( $fragments, 'id' ) );
	}

	/** A block post is offered to the filtered list like any other, so a site can still replace its driver. */
	#[Test]
	public function a_block_post_follows_the_filtered_list(): void {
		$custom = $this->driver( true, 'custom' );

		Agend_Test_WP::$filters['agend_content_access_document_parsers'] =
			static fn( array $parsers ): array => array_merge( array( $custom ), $parsers );

		$block = Exporter::fragments_of( $this->post( '<!-- wp:paragraph --><p>Block</p><!-- /wp:paragraph -->' ), null );

		$this->assertSame( 'custom', $block[0]['id'] );

		Agend_Test_WP::$filters['agend_content_access_document_parsers'] =
			static fn( array $parsers ): array => array();

		$none = Exporter::fragments_of( $this->post( '<!-- wp:paragraph --><p>Block</p><!-- /wp:paragraph -->' ), null );

		$this->assertSame( 'body', $none[0]['id'] );
	}

	#[Test]
	public function a_classic_post_with_a_stray_comment_still_falls_back_to_the_body_fragment(): void {
		$fragments = Exporter::fragments_of( $this->post( "<!-- more -->\n<p>Classic</p>" ), null );

		$this->assertSame( array( 'body' ), array_column( $fragments, 'id' ) );
	}

	/** The block driver sits ahead of the fallback, so only a block post reaches it. */
	#[Test]
	public function the_body_fallback_is_reached_only_by_posts_no_driver_recognises(): void {
		$block   = Exporter::fragments_of( $this->post( '<!-- wp:paragraph --><p>Block</p><!-- /wp:paragraph -->' ), null );
		$classic = Exporter::fragments_of( $this->post( '<p>Classic</p>' ), null );

		$this->assertNotSame( 'body', $block[0]['id'] );
		$this->assertSame( 'body', $classic[0]['id'] );
	}

	#[Test]
	public function a_plain_post_still_yields_the_single_body_fragment(): void {
		$fragments = Exporter::fragments_of( $this->post( '<p>Plain post</p>' ), null );

		$this->assertSame(
			array(
				array(
					'id'       => 'body',
					'position' => 0,
					'type'     => 'html',
					'payload'  => array( 'html' => '<p>Plain post</p>' ),
					'policy'   => array( 'mode' => 'inherit' ),
				),
			),
			$fragments
		);
	}

	#[Test]
	public function a_blank_elementor_meta_is_not_an_elementor_document(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] = "  \n ";

		$fragments = Exporter::fragments_of( $this->post(), null );

		$this->assertSame( 'body', $fragments[0]['id'] );
	}

	/**
	 * The connector turns this into a 422 and Agend keeps the revision it holds.
	 * The type and the message are part of that contract.
	 */
	#[Test]
	public function an_unparseable_elementor_document_still_throws(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] = 'not json at all';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Elementor document could not be parsed: Elementor document is not valid JSON.' );

		Exporter::fragments_of( $this->post(), null );
	}

	#[Test]
	public function an_unsupported_elementor_node_still_fails_the_revision(): void {
		$this->storeElementor( array( array( 'id' => 'x1', 'elType' => 'e-div-block', 'settings' => array() ) ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Unsupported Elementor node type "e-div-block"' );

		Exporter::fragments_of( $this->post(), null );
	}

	// -----------------------------------------------------------------
	// The driver list
	// -----------------------------------------------------------------

	/**
	 * A driver placed ahead of Elementor used to take precedence over it, so one
	 * that claimed an Elementor post could ship its plain text. An Elementor post
	 * now always goes to the Elementor parser; the list decides only the rest.
	 */
	#[Test]
	public function a_driver_ahead_of_elementor_cannot_claim_an_elementor_post(): void {
		$this->storeElementor( array( $this->widget( 'wid0001', 'Elementor text' ) ) );

		$custom = $this->driver( true, 'custom' );

		Agend_Test_WP::$filters['agend_content_access_document_parsers'] =
			static fn( array $parsers ): array => array_merge( array( $custom ), $parsers );

		$fragments = Exporter::fragments_of( $this->post(), null );

		$this->assertSame( 'wid0001', $fragments[0]['id'] );
		$this->assertSame( 0, $custom->asked, 'an Elementor post is never offered to the list' );
	}

	/** A filtered-in driver still wins over every OTHER driver ahead of the fallback, in order. */
	#[Test]
	public function a_filtered_in_driver_wins_for_a_post_that_is_not_elementor(): void {
		$custom = $this->driver( true, 'custom' );

		Agend_Test_WP::$filters['agend_content_access_document_parsers'] =
			static fn( array $parsers ): array => array_merge( array( $custom ), $parsers );

		$fragments = Exporter::fragments_of( $this->post(), null );

		$this->assertSame( 'custom', $fragments[0]['id'] );
	}

	/** A driver appended after Elementor still beats the body fallback. */
	#[Test]
	public function a_filtered_in_driver_replaces_the_body_fallback_for_a_post_it_recognises(): void {
		$custom = $this->driver( true, 'custom' );

		Agend_Test_WP::$filters['agend_content_access_document_parsers'] =
			static fn( array $parsers ): array => array_merge( $parsers, array( $custom ) );

		$fragments = Exporter::fragments_of( $this->post(), null );

		$this->assertSame( 'custom', $fragments[0]['id'] );
	}

	#[Test]
	public function a_driver_that_does_not_recognise_the_post_is_skipped(): void {
		$custom = $this->driver( false, 'custom' );

		Agend_Test_WP::$filters['agend_content_access_document_parsers'] =
			static fn( array $parsers ): array => array_merge( array( $custom ), $parsers );

		$fragments = Exporter::fragments_of( $this->post(), null );

		$this->assertSame( 1, $custom->asked );
		$this->assertSame( 'body', $fragments[0]['id'], 'nothing recognised the post, so it is the fallback' );
	}

	#[Test]
	public function the_first_matching_driver_stops_the_search(): void {
		$first  = $this->driver( true, 'first' );
		$second = $this->driver( true, 'second' );

		Agend_Test_WP::$filters['agend_content_access_document_parsers'] =
			static fn( array $parsers ): array => array( $first, $second );

		$fragments = Exporter::fragments_of( $this->post(), null );

		$this->assertSame( 'first', $fragments[0]['id'] );
		$this->assertSame( 0, $second->asked );
	}

	/**
	 * This runs on the connector path, where a fatal would fail the sync for
	 * every post. Anything that is not a driver is dropped, and a filter that
	 * returns a non-array leaves the built-in list in place.
	 */
	#[Test]
	public function a_filtered_list_is_defended_against_non_drivers(): void {
		Agend_Test_WP::$filters['agend_content_access_document_parsers'] =
			static fn( array $parsers ): array => array( 'not a driver', new \stdClass(), null, $parsers[0] );

		$parsers = Exporter::parsers();

		$this->assertCount( 1, $parsers );
		$this->assertInstanceOf( Agend_Content_Access_Elementor_Parser::class, $parsers[0] );

		Agend_Test_WP::$filters['agend_content_access_document_parsers'] =
			static fn( array $parsers ) => 'nonsense';

		$this->assertCount( 2, Exporter::parsers(), 'the built-in drivers stay in place' );
		$this->assertSame( 'body', Exporter::fragments_of( $this->post(), null )[0]['id'] );
	}

	// -----------------------------------------------------------------
	// An Elementor post is never decided by the filter
	// -----------------------------------------------------------------

	private function restrictedWidgetDocument(): array {
		return array(
			array(
				'id'         => 'w1',
				'elType'     => 'widget',
				'widgetType' => 'text-editor',
				'settings'   => array( 'editor' => 'secret', 'agend_access_mode' => 'active_member' ),
			),
		);
	}

	/**
	 * Review finding F5, ported. A filter returning a list without the Elementor
	 * driver left nothing to recognise the document, and `post_content` (which
	 * holds every widget's plain text) shipped as one `inherit` body fragment. The
	 * current wrong behaviour asserted exactly that.
	 */
	#[Test]
	public function a_filter_that_drops_the_elementor_driver_cannot_make_an_elementor_post_export_its_body(): void {
		$this->storeElementor( $this->restrictedWidgetDocument() );

		$other = $this->driver( false, 'other' );
		Agend_Test_WP::set_filter( 'agend_content_access_document_parsers', array( $other ) );

		$fragments = Exporter::fragments_of( $this->post( 'secret' ), null );

		$this->assertCount( 1, $fragments );
		$this->assertSame( 'w1', $fragments[0]['id'] );
		$this->assertSame( 'elementor:text-editor', $fragments[0]['type'] );
		$this->assertSame( array( 'mode' => 'active_member' ), $fragments[0]['policy'], 'the restriction must reach the export' );
		$this->assertNotSame( 'body', $fragments[0]['id'] );
	}

	#[Test]
	public function an_empty_filtered_list_cannot_make_an_elementor_post_export_its_body(): void {
		$this->storeElementor( $this->restrictedWidgetDocument() );

		Agend_Test_WP::set_filter( 'agend_content_access_document_parsers', array() );

		$this->assertSame( 'w1', Exporter::fragments_of( $this->post( 'secret' ), null )[0]['id'] );

		Agend_Test_WP::set_filter( 'agend_content_access_document_parsers', 'nonsense' );

		$this->assertSame( 'w1', Exporter::fragments_of( $this->post( 'secret' ), null )[0]['id'] );
	}

	/**
	 * The case that must still fail the revision: a document that cannot be parsed
	 * is a 422 whether or not the filter kept the Elementor driver.
	 */
	#[Test]
	public function an_unparseable_elementor_document_still_throws_when_the_filter_drops_the_driver(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] = 'not json at all';

		Agend_Test_WP::set_filter( 'agend_content_access_document_parsers', array( $this->driver( false, 'other' ) ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Elementor document could not be parsed' );

		Exporter::fragments_of( $this->post( 'secret' ), null );
	}

	#[Test]
	public function the_built_in_elementor_parser_is_used_even_when_the_filter_supplies_its_own_for_elementor(): void {
		$this->storeElementor( array( $this->widget( 'wid0001', 'Elementor text' ) ) );

		// A look-alike that claims the post and returns something else entirely.
		$impostor = $this->driver( true, 'impostor' );
		Agend_Test_WP::set_filter( 'agend_content_access_document_parsers', array( $impostor ) );

		$fragments = Exporter::fragments_of( $this->post( 'Elementor text' ), null );

		$this->assertSame( 'wid0001', $fragments[0]['id'] );
		$this->assertSame( 0, $impostor->asked );
	}

	/** A blank `_elementor_data` is not an Elementor document, so the list decides. */
	#[Test]
	public function a_blank_elementor_meta_leaves_the_decision_to_the_filtered_list(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] = "  \n ";

		$custom = $this->driver( true, 'custom' );
		Agend_Test_WP::set_filter( 'agend_content_access_document_parsers', array( $custom ) );

		$this->assertSame( 'custom', Exporter::fragments_of( $this->post(), null )[0]['id'] );
	}
}
