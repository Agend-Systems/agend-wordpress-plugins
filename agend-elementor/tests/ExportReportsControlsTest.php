<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Export_Reports;
use Agend_Elementor_Schema_Controls;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-export-reports.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-export-reports.php';
require_once __DIR__ . '/Content_Tab_Recording_Trait.php';

/**
 * Exposes the protected register_controls() so a test can run it directly.
 */
final class Export_Reports_Controls_Test_Harness extends Agend_Elementor_Export_Reports {
	public function run(): void {
		$this->register_controls();
	}
}

/**
 * Proves the schema-driven register_controls() registers exactly the same
 * Content-tab controls as the pre-refactor, hand-declared version (captured
 * in the fixture before Phase F2 changed the widget), including the two
 * REPEATER controls, the note carrying `content_classes`, and the
 * conditionally-registered "no reports" notice the adapter still declares
 * itself.
 */
#[CoversClass( Agend_Elementor_Schema_Controls::class )]
final class ExportReportsControlsTest extends TestCase {

	use Content_Tab_Recording_Trait;

	protected function setUp(): void {
		parent::setUp();

		// The widget's `reports_unavailable` adapter control text branches on
		// the directory_export_reports optional feature (SPEC-CORE-20260908
		// scope-gated features): when another test in this process has loaded
		// the key-scopes/feature classes, it would otherwise show the
		// missing-scope notice instead of this fixture's generic "no reports"
		// text. Seeded as held so this fixture keeps asserting that generic
		// text; the gate itself is exercised in OptionalFeaturesTest.
		$this->set_held_scopes( array( 'directory.export_reports.browse' ) );
	}

	/**
	 * @param string[] $scopes
	 */
	private function set_held_scopes( array $scopes ): void {
		\Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => $scopes ) ) );
		\Agend_Apps_Key_Scopes::refresh();
		\Agend_Test_WP::$requests = array();
	}

	/**
	 * @return array<string, mixed>|null The recorded args of the named Content-tab control.
	 */
	private function control_args( Export_Reports_Controls_Test_Harness $widget, string $id ): ?array {
		foreach ( $this->contentTabRecordings( $widget->recordings )['sections'] as $section ) {
			foreach ( $section['controls'] as $control ) {
				if ( $id === $control['id'] ) {
					return $control['args'];
				}
			}
		}
		return null;
	}

	#[Test]
	public function should_register_the_same_content_tab_controls_as_the_pre_refactor_widget(): void {
		$fixture = json_decode(
			file_get_contents( __DIR__ . '/fixtures/export-reports-content-controls.json' ),
			true
		);

		$widget = new Export_Reports_Controls_Test_Harness();
		$widget->run();

		$this->assertEquals( $fixture, $this->contentTabRecordings( $widget->recordings ) );
	}

	#[Test]
	public function should_offer_every_published_report_with_its_audience_when_the_key_can_author(): void {
		$this->set_held_scopes( array( 'directory.export_reports.browse', 'directory.listings.manage' ) );
		\Agend_Test_WP::queue_response(
			200,
			array(
				'success' => true,
				'data'    => array(
					array( 'id' => 'rep-anyone', 'name' => 'Member directory', 'audience' => 'anonymous' ),
					array( 'id' => 'rep-members', 'name' => 'Committee contacts', 'audience' => 'members_only' ),
				),
			)
		);

		$widget = new Export_Reports_Controls_Test_Harness();
		$widget->run();

		$expected = array(
			''            => 'Select a report',
			'rep-anyone'  => 'Member directory',
			'rep-members' => 'Committee contacts (members only)',
		);
		$this->assertSame( $expected, $this->control_args( $widget, 'report' )['options'] );
		$this->assertSame( $expected, $this->control_args( $widget, 'reports' )['fields'][0]['options'] );
		$this->assertNull( $this->control_args( $widget, 'reports_unavailable' ), 'the "no reports" notice should not register when reports came back' );

		$listing = array_values( array_filter( \Agend_Test_WP::$requests, static fn( array $r ): bool => str_contains( $r['url'], '/directory/export-reports' ) ) );
		$this->assertCount( 1, $listing, 'one authoring request, served from cache for the second select' );
		$this->assertStringContainsString( 'scope=all', $listing[0]['url'] );
	}

	#[Test]
	public function should_mention_the_audience_and_manage_scope_when_no_reports_come_back(): void {
		\Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array() ) );

		$widget = new Export_Reports_Controls_Test_Harness();
		$widget->run();

		$notice = $this->control_args( $widget, 'reports_unavailable' );
		$this->assertNotNull( $notice );
		$this->assertStringContainsString( 'members only', $notice['raw'] );
		$this->assertStringContainsString( 'directory.listings.manage', $notice['raw'] );
	}
}
