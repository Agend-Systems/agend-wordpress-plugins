<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

/**
 * Agend Directory Count: the total comes from the gateway's pagination meta on
 * the server, whatever else is on the page.
 *
 * Runs in separate processes so the gateway stubs never reach a test that
 * relies on them being absent.
 */
#[RunTestsInSeparateProcesses]
final class DirectoryCountRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/directory-widgets-stub.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-count.php';
		$GLOBALS['agend_test_dw_requests'] = array();
	}

	private function total( int $total ): void {
		$GLOBALS['agend_test_dw_search'] = array(
			'data' => array( array( 'id' => 'l1' ) ),
			'meta' => array( 'pagination' => array( 'total' => $total, 'page' => 1, 'limit' => 1 ) ),
		);
	}

	/** Reads the config off the data attribute, as the script does. */
	private function config_from( string $html ): array {
		self::assertSame( 1, preg_match( '/data-agend-dc-config="([^"]*)"/', $html, $match ) );
		return (array) json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
	}

	#[Test]
	public function should_show_the_pagination_total_with_thousands_separated_and_the_label(): void {
		$this->total( 1234 );

		self::assertSame(
			'<div class="agend-dc agend-dc--stacked" data-agend-dc-config="' . esc_attr( (string) wp_json_encode( array( 'follow' => false, 'one' => 'listing', 'other' => 'listings' ) ) ) . '">'
			. '<span class="agend-dc__number">1,234</span> <span class="agend-dc__label">listings</span></div>',
			agend_apps_records_render_directory_count( array() )
		);
	}

	#[Test]
	public function should_ask_for_a_single_listing_so_the_read_stays_small_and_cacheable(): void {
		$this->total( 50 );

		agend_apps_records_render_directory_count( array() );

		self::assertSame( array( array( 'search', array( 'page' => 1, 'limit' => 1 ) ) ), $GLOBALS['agend_test_dw_requests'] );
	}

	#[Test]
	public function should_use_the_label_for_one_when_the_total_is_one(): void {
		$this->total( 1 );

		$html = agend_apps_records_render_directory_count( array( 'label' => 'specialists', 'label_singular' => 'specialist' ) );

		self::assertStringContainsString( '<span class="agend-dc__label">specialist</span>', $html );
	}

	#[Test]
	public function should_use_the_plural_label_for_one_when_no_singular_is_set(): void {
		$this->total( 1 );

		$html = agend_apps_records_render_directory_count( array( 'label' => 'specialists', 'label_singular' => '' ) );

		self::assertStringContainsString( '<span class="agend-dc__label">specialists</span>', $html );
	}

	#[Test]
	public function should_show_zero_when_the_directory_is_empty(): void {
		$this->total( 0 );

		self::assertStringContainsString( '<span class="agend-dc__number">0</span>', agend_apps_records_render_directory_count( array() ) );
	}

	#[Test]
	public function should_lay_the_number_beside_the_label_when_inline_is_chosen(): void {
		$this->total( 5 );

		self::assertStringContainsString( 'class="agend-dc agend-dc--inline"', agend_apps_records_render_directory_count( array( 'layout' => 'inline' ) ) );
		self::assertStringContainsString( 'class="agend-dc agend-dc--stacked"', agend_apps_records_render_directory_count( array( 'layout' => 'sideways' ) ) );
	}

	#[Test]
	public function should_tell_the_script_whether_to_follow_the_catalogue_and_both_labels(): void {
		$this->total( 5 );

		$config = $this->config_from( agend_apps_records_render_directory_count( array( 'follow_catalogue' => 'yes', 'label' => 'people', 'label_singular' => 'person' ) ) );

		self::assertSame( array( 'follow' => true, 'one' => 'person', 'other' => 'people' ), $config );
	}

	#[Test]
	public function should_render_nothing_for_a_visitor_when_the_gateway_cannot_be_read(): void {
		$GLOBALS['agend_test_dw_search'] = new WP_Error( 'agend_api_error', 'down' );

		self::assertSame( '', agend_apps_records_render_directory_count( array() ) );
	}

	#[Test]
	public function should_render_nothing_when_the_answer_has_no_total(): void {
		$GLOBALS['agend_test_dw_search'] = array( 'data' => array() );

		self::assertSame( '', agend_apps_records_render_directory_count( array() ) );
	}

	#[Test]
	public function should_show_a_sample_number_in_an_editor_preview_when_the_gateway_cannot_be_read(): void {
		$GLOBALS['agend_test_dw_search'] = new WP_Error( 'agend_api_error', 'down' );

		self::assertStringContainsString( '<span class="agend-dc__number">1,234</span>', agend_apps_records_render_directory_count( array(), array( 'preview' => true ) ) );
	}

	#[Test]
	public function should_escape_the_label(): void {
		$this->total( 5 );

		$html = agend_apps_records_render_directory_count( array( 'label' => '<script>x</script>' ) );

		self::assertStringNotContainsString( '<script>', $html );
	}
}
