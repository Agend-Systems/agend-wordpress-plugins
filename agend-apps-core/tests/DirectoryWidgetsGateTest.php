<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Key_Scopes;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The three directory widgets that sit beside a catalogue are gated on the API
 * key's directory search scope, like the catalogue itself: a key without it
 * gets nothing drawn, and no gateway read is spent.
 */
#[RunTestsInSeparateProcesses]
final class DirectoryWidgetsGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/directory-widgets-stub.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/query.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-search.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/filter-shortcuts.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-count.php';
		$GLOBALS['agend_test_dw_requests'] = array();
	}

	/** @return array<string, array{string}> */
	public static function surfaces(): array {
		return array(
			'directory search' => array( 'directory-search' ),
			'filter shortcuts' => array( 'filter-shortcuts' ),
			'directory count'  => array( 'directory-count' ),
		);
	}

	private function render( string $surface ): string {
		switch ( $surface ) {
			case 'directory-search':
				return agend_apps_records_render_directory_search( array() );
			case 'filter-shortcuts':
				return agend_apps_records_render_filter_shortcuts( array( 'filter' => 'state', 'items' => array( array( 'label' => 'A', 'value' => 'A' ) ) ) );
		}

		$GLOBALS['agend_test_dw_search'] = array( 'data' => array(), 'meta' => array( 'pagination' => array( 'total' => 9 ) ) );
		return agend_apps_records_render_directory_count( array() );
	}

	#[Test]
	#[DataProvider( 'surfaces' )]
	public function should_require_the_directory_search_scope( string $surface ): void {
		$needs = agend_apps_records_surface_requirements( $surface );

		self::assertSame( array( 'directory.listings.search' ), $needs['scopes'] );
	}

	#[Test]
	#[DataProvider( 'surfaces' )]
	public function should_render_nothing_for_a_visitor_when_the_key_lacks_the_scope( string $surface ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'events.events.browse' ) ) ) );

		self::assertSame( '', $this->render( $surface ) );
		self::assertSame( array(), $GLOBALS['agend_test_dw_requests'] );
	}

	#[Test]
	#[DataProvider( 'surfaces' )]
	public function should_render_when_the_key_holds_the_scope( string $surface ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'directory.listings.search' ) ) ) );

		self::assertNotSame( '', $this->render( $surface ) );
	}
}
