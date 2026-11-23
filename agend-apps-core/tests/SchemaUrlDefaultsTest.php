<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';

/**
 * Elementor's URL control merges its default into an array, so a string
 * default is a fatal TypeError the moment the widget's controls load (the
 * front end included). The adapter passes a schema default through as is.
 */
final class SchemaUrlDefaultsTest extends TestCase {

	#[Test]
	public function should_give_every_url_field_an_array_default_or_none_when_a_surface_declares_one(): void {
		$offenders = array();

		foreach ( get_defined_functions()['user'] as $function ) {
			if ( 0 !== strpos( $function, 'agend_apps_records_schema_' ) ) {
				continue;
			}
			$schema = $function();
			foreach ( $schema['sections'] ?? array() as $section ) {
				foreach ( $section['fields'] ?? array() as $field ) {
					if ( 'url' === ( $field['type'] ?? '' ) && array_key_exists( 'default', $field ) && ! is_array( $field['default'] ) ) {
						$offenders[] = $function . ':' . ( $field['name'] ?? '?' );
					}
				}
			}
		}

		$this->assertSame( array(), $offenders );
	}
}
