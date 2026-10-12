<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Records;

use Agend\Tests\TestCase;
use Agend_Apps_Key_Scopes;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The widgets' gateway-backed option lists feed controls that Elementor also
 * registers on every front-end render, so each is resolved only for an editor.
 * The key holds every scope involved, so what separates the two cases is the
 * request, not a gated surface.
 *
 * Runs in separate processes so the gateway stub functions never reach another
 * test, which relies on them being absent.
 */
#[RunTestsInSeparateProcesses]
final class EditorOnlyOptionListsTest extends TestCase {

	private const REPORTS = array(
		array(
			'id'         => 'rep-a',
			'name'       => 'Member directory',
			'audience'   => 'anonymous',
			'parameters' => array( array( 'name' => 'c1', 'field' => 'location.state' ) ),
		),
	);

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/surface-gate.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-export-reports.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/export-reports.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/events-catalogue.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/courses-catalogue.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/directory-catalogue.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/gateway-option-lists-stub.php';

		$GLOBALS['agend_test_gateway_calls'] = array();

		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'scopes' => array(
						'directory.export_reports.browse',
						'directory.export_reports.run',
						'directory.listings.search',
						'events.events.browse',
						'lms.courses.browse',
					),
				),
			)
		);
		Agend_Apps_Key_Scopes::refresh();
		Agend_Test_WP::$requests = array();

		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => self::REPORTS ) );
	}

	/** @return array<string, array{string, array<int|string, mixed>, array<int|string, mixed>}> List callable, visitor result, editor result. */
	public static function lists(): array {
		return array(
			'directory catalogue categories' => array(
				'agend_apps_records_directory_catalogue_category_options',
				array(),
				array( 'cat-1' => 'Retail', 'cat-2' => 'Health' ),
			),
			'events catalogue categories'    => array(
				'agend_apps_records_event_category_options',
				array(),
				array( '1' => 'Conferences', '2' => 'Workshops' ),
			),
			'events catalogue cities'        => array(
				'agend_apps_records_event_city_options',
				array(),
				array( 'Sydney' => 'Sydney', 'Melbourne' => 'Melbourne' ),
			),
			'courses catalogue categories'   => array(
				'agend_apps_records_courses_catalogue_category_options',
				array(),
				array( 'Safety' => 'Safety', 'Leadership' => 'Leadership' ),
			),
			'export report choices'          => array(
				'agend_apps_records_export_reports_report_options',
				array( '' => 'Select a report' ),
				array( '' => 'Select a report', 'rep-a' => 'Member directory' ),
			),
			'export report parameters'       => array(
				'agend_apps_records_export_reports_declared_parameters',
				array(),
				array( array( 'id' => 'rep-a', 'name' => 'Member directory', 'fields' => array( 'location.state' ) ) ),
			),
		);
	}

	#[Test]
	#[DataProvider( 'lists' )]
	public function should_make_no_gateway_request_and_write_no_transient_when_no_editor_is_behind_the_request( string $list, array $visitor_result ): void {
		$transients = Agend_Test_WP::$transients;

		self::assertSame( $visitor_result, $list() );
		self::assertSame( array(), $GLOBALS['agend_test_gateway_calls'] );
		self::assertSame( array(), Agend_Test_WP::$requests );
		self::assertSame( $transients, Agend_Test_WP::$transients );
	}

	#[Test]
	#[DataProvider( 'lists' )]
	public function should_resolve_the_list_when_an_editor_is_behind_the_request( string $list, array $visitor_result, array $editor_result ): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		self::assertSame( $editor_result, $list() );
	}

	#[Test]
	public function should_still_read_the_report_listing_for_a_visitors_sign_in_prompts(): void {
		self::assertSame( self::REPORTS, agend_apps_records_export_reports_authoring_listing()['data'] );
	}
}
