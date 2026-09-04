<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Apps_Templates;
use Agend_Elementor_Events_Catalogue;
use Agend_Elementor_Schema_Controls;
use Agend\Tests\AppsCore\Template_Registry_Test_Source;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-renderer.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-source.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/class-agend-apps-templates.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-events-catalogue.php';

/**
 * Exposes the protected register_controls() so a test can run it directly.
 */
final class Events_Catalogue_Controls_Test_Harness extends Agend_Elementor_Events_Catalogue {
	public function run(): void {
		$this->register_controls();
	}
}

/**
 * Proves the schema-driven `register_content_controls()` registers exactly
 * the same Content-tab sections and controls as the pre-refactor,
 * hand-declared version (captured in the fixture before Phase F1 changed the
 * widget), plus the `control_args()` toggle mapping the schema relies on.
 */
#[CoversClass( Agend_Elementor_Schema_Controls::class )]
final class EventsCatalogueControlsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Templates::reset();
		Agend_Apps_Templates::register_source(
			new Template_Registry_Test_Source(
				'Elementor',
				array(
					'101' => 'Event card',
					'102' => 'Event filter bar',
				)
			)
		);
	}

	protected function tearDown(): void {
		Agend_Apps_Templates::reset();
		parent::tearDown();
	}

	/**
	 * Reduces a widget's full recording list to its Content-tab sections and
	 * their controls, matching the shape of the committed fixture.
	 *
	 * @param array<int, array<string, mixed>> $recordings Widget_Base::$recordings.
	 * @return array{sections: array<int, array<string, mixed>>}
	 */
	private function contentTabRecordings( array $recordings ): array {
		$sections   = array();
		$current    = null;
		$in_content = false;

		foreach ( $recordings as $entry ) {
			if ( 'start_controls_section' === $entry['method'] ) {
				$in_content = ( \Elementor\Controls_Manager::TAB_CONTENT === ( $entry['args']['tab'] ?? null ) );
				if ( $in_content ) {
					$current = array(
						'id'       => $entry['id'],
						'args'     => $entry['args'],
						'controls' => array(),
					);
				}
				continue;
			}
			if ( 'end_controls_section' === $entry['method'] ) {
				if ( $in_content && null !== $current ) {
					$sections[] = $current;
				}
				$current    = null;
				$in_content = false;
				continue;
			}
			if ( $in_content && null !== $current && 'add_control' === $entry['method'] ) {
				$current['controls'][] = array(
					'id'   => $entry['id'],
					'args' => $entry['args'],
				);
			}
		}

		return array( 'sections' => $sections );
	}

	#[Test]
	public function should_register_the_same_content_tab_controls_as_the_pre_refactor_widget(): void {
		$fixture = json_decode(
			file_get_contents( __DIR__ . '/fixtures/events-catalogue-content-controls.json' ),
			true
		);

		$widget = new Events_Catalogue_Controls_Test_Harness();
		$widget->run();

		$this->assertEquals( $fixture, $this->contentTabRecordings( $widget->recordings ) );
	}

	#[Test]
	public function should_map_a_toggle_field_default_to_the_elementor_yes_or_empty_string_convention(): void {
		$on  = Agend_Elementor_Schema_Controls::control_args(
			array(
				'name'    => 'show_heading',
				'label'   => 'Show heading block',
				'type'    => 'toggle',
				'default' => true,
			)
		);
		$off = Agend_Elementor_Schema_Controls::control_args(
			array(
				'name'    => 'show_heading',
				'label'   => 'Show heading block',
				'type'    => 'toggle',
				'default' => false,
			)
		);

		$this->assertSame( 'yes', $on['default'] );
		$this->assertSame( '', $off['default'] );
	}
}
