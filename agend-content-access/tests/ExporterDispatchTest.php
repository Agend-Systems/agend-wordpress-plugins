<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

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
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-assets.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/interface-agend-content-access-document-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-exporter.php';

/**
 * Which driver turns a post into fragments.
 *
 * The exporter used to sniff `_elementor_data` and call the Elementor parser by
 * name. It now asks a list of drivers, first match wins, with the single `body`
 * fragment as the fallback. What must not change is the output for the two
 * cases that exist today: an Elementor page and everything else.
 */
#[CoversClass( Exporter::class )]
#[CoversClass( Agend_Content_Access_Elementor_Parser::class )]
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
	public function the_elementor_driver_is_the_first_driver(): void {
		$parsers = Exporter::parsers();

		$this->assertInstanceOf( Agend_Content_Access_Elementor_Parser::class, $parsers[0] );
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

	/** A driver placed ahead of Elementor takes precedence over it. */
	#[Test]
	public function a_filtered_in_driver_wins_when_it_recognises_the_post(): void {
		$this->storeElementor( array( $this->widget( 'wid0001', 'Elementor text' ) ) );

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

		$this->storeElementor( array( $this->widget( 'wid0001', 'Elementor text' ) ) );

		$fragments = Exporter::fragments_of( $this->post(), null );

		$this->assertSame( 1, $custom->asked );
		$this->assertSame( 'wid0001', $fragments[0]['id'] );
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

		$this->assertCount( 1, Exporter::parsers() );
		$this->assertSame( 'body', Exporter::fragments_of( $this->post(), null )[0]['id'] );
	}
}
