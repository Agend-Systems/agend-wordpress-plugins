<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Header_Auth;
use Agend_Elementor_Schema_Controls;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-header-auth.php';
require_once __DIR__ . '/Content_Tab_Recording_Trait.php';

/**
 * Exposes the protected register_controls() so a test can run it directly.
 */
final class Header_Auth_Controls_Test_Harness extends Agend_Elementor_Header_Auth {
	public function run(): void {
		$this->register_controls();
	}
}

/**
 * Proves the schema-driven register_controls() registers exactly the same
 * Content-tab controls as the pre-refactor, hand-declared version (captured
 * in the fixture before Phase F2 changed the widget), including the
 * `login_url` URL control the adapter still declares itself.
 */
#[CoversClass( Agend_Elementor_Schema_Controls::class )]
final class HeaderAuthControlsTest extends TestCase {

	use Content_Tab_Recording_Trait;

	#[Test]
	public function should_register_the_same_content_tab_controls_as_the_pre_refactor_widget(): void {
		$fixture = json_decode(
			file_get_contents( __DIR__ . '/fixtures/header-auth-content-controls.json' ),
			true
		);

		$widget = new Header_Auth_Controls_Test_Harness();
		$widget->run();

		$this->assertEquals( $fixture, $this->contentTabRecordings( $widget->recordings ) );
	}
}
