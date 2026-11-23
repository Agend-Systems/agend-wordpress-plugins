<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Directory_Count;
use Agend_Elementor_Schema_Controls;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-count.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-directory-count.php';
require_once __DIR__ . '/Content_Tab_Recording_Trait.php';

/**
 * Exposes the protected methods a test drives directly.
 */
final class Directory_Count_Test_Harness extends Agend_Elementor_Directory_Count {
	public function run(): void {
		$this->register_controls();
	}

	public function run_render(): string {
		ob_start();
		$this->render();

		return (string) ob_get_clean();
	}
}

/**
 * The Agend Directory Count widget: Content controls come from the core
 * schema (pinned by the fixture), the Style tab is declared here, and the
 * markup is the core renderer's.
 *
 * Runs in separate processes so the gateway stubs it needs never reach a test
 * that relies on them being absent.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass( Agend_Elementor_Schema_Controls::class )]
final class DirectoryCountWidgetTest extends TestCase {

	use Content_Tab_Recording_Trait;

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/directory-widgets-stub.php';
	}

	private function controls(): Directory_Count_Test_Harness {
		$widget = new Directory_Count_Test_Harness();
		$widget->run();

		return $widget;
	}

	#[Test]
	public function should_register_the_content_tab_controls_the_surface_schema_declares(): void {
		$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/directory-count-content-controls.json' ), true );

		$this->assertEquals( $fixture, $this->contentTabRecordings( $this->controls()->recordings ) );
	}

	#[Test]
	public function should_be_named_for_the_operator_to_place(): void {
		$widget = new Agend_Elementor_Directory_Count();

		$this->assertSame( 'agend-directory-count', $widget->get_name() );
		$this->assertSame( 'Agend Directory Count', $widget->get_title() );
	}

	#[Test]
	public function should_style_the_number_and_the_label_with_typography_and_colour(): void {
		$recordings = $this->controls()->recordings;

		$this->assertSame( '{{WRAPPER}} .agend-dc__number', $this->recordedArgs( $recordings, 'number_typography' )['selector'] );
		$this->assertSame( array( '{{WRAPPER}} .agend-dc__number' => 'color: {{VALUE}};' ), $this->recordedArgs( $recordings, 'number_colour' )['selectors'] );
		$this->assertSame( '{{WRAPPER}} .agend-dc__label', $this->recordedArgs( $recordings, 'label_typography' )['selector'] );
		$this->assertSame( array( '{{WRAPPER}} .agend-dc__label' => 'color: {{VALUE}};' ), $this->recordedArgs( $recordings, 'label_colour' )['selectors'] );
	}

	#[Test]
	public function should_render_what_the_core_renderer_renders(): void {
		$GLOBALS['agend_test_dw_search'] = array( 'data' => array(), 'meta' => array( 'pagination' => array( 'total' => 321 ) ) );
		$settings                        = array( 'label' => 'specialists', 'layout' => 'inline' );
		$widget                          = new Directory_Count_Test_Harness( array(), null, $settings );

		$this->assertSame( agend_apps_records_render_directory_count( $settings ), $widget->run_render() );
		$this->assertStringContainsString( '321', $widget->run_render() );
	}
}
