<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';

/**
 * The one thing about the Agend Content Block's colours section that is this
 * surface's own decision rather than the shared helper's behaviour.
 *
 * Everything else about the section (field names against
 * AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS, defaults against
 * AGEND_APPS_RECORDS_COLOUR_DEFAULTS, the tab, the conditions) now comes from
 * agend_apps_records_schema_colour_fields() and is covered once, for every
 * surface, in ColourFieldsSchemaTest.
 */
final class RecordBlockSchemaTest extends TestCase {

	#[Test]
	public function should_default_inherit_colours_to_off_unlike_the_catalogue_surfaces(): void {
		$schema = agend_apps_records_surface_schema( 'record-block' );

		$inherit = null;
		foreach ( $schema['sections'] as $section ) {
			if ( 'section_style_colours' !== $section['id'] ) {
				continue;
			}
			foreach ( $section['fields'] as $field ) {
				if ( 'inherit_colours' === $field['name'] ) {
					$inherit = $field;
				}
			}
		}

		self::assertNotNull( $inherit, 'record-block has no inherit_colours field' );

		// A block renders the built-in fragment markup directly, and its colour
		// fields default to the plugin palette, so leaving inheritance off means
		// an untouched block emits exactly what it emitted before it had any
		// colour controls. Defaulting it on (as the catalogue surfaces do) would
		// have silently repainted every existing block from the theme palette.
		self::assertFalse( $inherit['default'] );
	}
}
