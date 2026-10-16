<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Record_Pills;
use Agend_Elementor_Schema_Controls;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-record-pills.php';
require_once __DIR__ . '/Content_Tab_Recording_Trait.php';

/**
 * Exposes the protected register_controls() so a test can run it directly.
 */
final class Record_Pills_Controls_Test_Harness extends Agend_Elementor_Record_Pills {
	public function run(): void {
		$this->register_controls();
	}
}

/**
 * Snapshot of the Content-tab controls this widget registers from its schema.
 *
 * It began as a fidelity fixture, pinning the schema-driven controls to the
 * pre-refactor hand-declared ones. The controls have since changed on
 * purpose (the record-type control removed, labels added, panels split from
 * fields), so what it pins now is the intended set: a schema edit that
 * changes a control an author sees has to change this fixture too, in the
 * same commit, where a reviewer can see it.
 */
#[CoversClass( Agend_Elementor_Schema_Controls::class )]
final class RecordPillsControlsTest extends TestCase {

	use Content_Tab_Recording_Trait;

	#[Test]
	public function should_register_the_content_tab_controls_the_fixture_pins(): void {
		$fixture = json_decode(
			file_get_contents( __DIR__ . '/fixtures/record-pills-content-controls.json' ),
			true
		);

		$widget = new Record_Pills_Controls_Test_Harness();
		$widget->run();

		$this->assertEquals( $fixture, $this->contentTabRecordings( $widget->recordings ) );
	}

	#[Test]
	public function should_hand_the_pill_values_to_a_pill_style_show_the_rest_control(): void {
		$widget = new Record_Pills_Controls_Test_Harness();
		$widget->run();
		$toggle = '{{WRAPPER}} .agend-pills button.agend-rest-toggle--pill.agend-rest-toggle';

		$this->assertSame( '--agend-pill-colour: {{VALUE}};', $this->recordedArgs( $widget->recordings, 'pill_colour' )['selectors']['{{WRAPPER}}'] );
		$this->assertSame( '--agend-pill-bg: {{VALUE}};', $this->recordedArgs( $widget->recordings, 'pill_background' )['selectors']['{{WRAPPER}}'] );
		$this->assertSame( '--agend-pill-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};', $this->recordedArgs( $widget->recordings, 'pill_padding' )['selectors']['{{WRAPPER}}'] );
		$this->assertSame( '--agend-pill-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};', $this->recordedArgs( $widget->recordings, 'pill_radius' )['selectors']['{{WRAPPER}}'] );
		$this->assertStringContainsString( $toggle, $this->recordedArgs( $widget->recordings, 'typography' )['selector'] );
		$this->assertStringContainsString( $toggle, $this->recordedArgs( $widget->recordings, 'pill_border' )['selector'] );
		$this->assertSame( '{{WRAPPER}} .agend-pills button.agend-rest-toggle.agend-rest-toggle', $this->recordedArgs( $widget->recordings, 'rest_typography' )['selector'] );
	}
}
