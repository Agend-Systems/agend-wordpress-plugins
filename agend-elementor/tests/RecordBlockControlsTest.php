<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Record_Block;
use Agend_Elementor_Schema_Controls;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-record-block.php';
require_once __DIR__ . '/Content_Tab_Recording_Trait.php';

/**
 * Exposes the protected register_controls() so a test can run it directly.
 */
final class Record_Block_Controls_Test_Harness extends Agend_Elementor_Record_Block {
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
final class RecordBlockControlsTest extends TestCase {

	use Content_Tab_Recording_Trait;

	#[Test]
	public function should_register_the_content_tab_controls_the_fixture_pins(): void {
		$fixture = json_decode(
			file_get_contents( __DIR__ . '/fixtures/record-block-content-controls.json' ),
			true
		);

		$widget = new Record_Block_Controls_Test_Harness();
		$widget->run();

		$this->assertEquals( $fixture, $this->contentTabRecordings( $widget->recordings ) );
	}
}
