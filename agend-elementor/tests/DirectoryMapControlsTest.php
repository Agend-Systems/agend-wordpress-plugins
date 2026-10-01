<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Directory_Map;
use Agend_Elementor_Schema_Controls;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/filters.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-directory-map.php';
require_once __DIR__ . '/Content_Tab_Recording_Trait.php';

/**
 * Exposes the protected register_controls() so a test can run it directly.
 */
final class Directory_Map_Controls_Test_Harness extends Agend_Elementor_Directory_Map {
	public function run(): void {
		$this->register_controls();
	}
}

/**
 * The Agend Map widget declares no controls of its own: everything comes from
 * the directory-map surface schema, so the block editor offers the same
 * settings. The fixture pins the Content-tab controls that schema produces.
 */
#[CoversClass( Agend_Elementor_Schema_Controls::class )]
final class DirectoryMapControlsTest extends TestCase {

	use Content_Tab_Recording_Trait;

	#[Test]
	public function should_register_the_content_tab_controls_the_surface_schema_declares(): void {
		$fixture = json_decode(
			file_get_contents( __DIR__ . '/fixtures/directory-map-content-controls.json' ),
			true
		);

		$widget = new Directory_Map_Controls_Test_Harness();
		$widget->run();

		$this->assertEquals( $fixture, $this->contentTabRecordings( $widget->recordings ) );
	}
}
