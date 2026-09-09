<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Records;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';

/**
 * `agend_apps_records_type_from_key()`: the record type a widget's field or
 * content-block setting names, which is what lets the editor preview an
 * "Auto" widget against the right record without the author setting
 * `record_type` by hand.
 */
#[CoversFunction( 'agend_apps_records_type_from_key' )]
final class TypeFromKeyTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function keys(): array {
		return array(
			'listing field'        => array( 'listing:name', 'listing' ),
			'listing block'        => array( 'listing_business_hours', 'listing' ),
			'event field'          => array( 'event:start_date', 'event' ),
			'event block'          => array( 'event_facts', 'event' ),
			'course field'         => array( 'course:title', 'course' ),
			'course block'         => array( 'course_outcomes', 'course' ),
			'common field'         => array( 'common:title', '' ),
			'empty'                => array( '', '' ),
			'unknown prefix'       => array( 'member:name', '' ),
			'prefix without a key' => array( 'listing', 'listing' ),
		);
	}

	#[Test]
	#[DataProvider( 'keys' )]
	public function should_name_the_record_type_its_prefix_declares( string $key, string $expected ): void {
		$this->assertSame( $expected, \agend_apps_records_type_from_key( $key ) );
	}

	#[Test]
	public function should_agree_with_the_field_registry_on_every_field_key(): void {
		foreach ( \agend_apps_records_field_registry() as $key => $descriptor ) {
			$type = \agend_apps_records_type_from_key( (string) $key );

			if ( '' === $type ) {
				// A key naming no type is a `common:` field, which by
				// definition applies to more than one record type.
				$this->assertGreaterThan( 1, count( $descriptor['types'] ), $key . ' names no type but applies to only one' );
				continue;
			}

			$this->assertSame( array( $type ), $descriptor['types'], $key . ' is registered against a different type than its prefix names' );
		}
	}

	#[Test]
	public function should_name_a_type_for_every_panel_key(): void {
		$groups = \agend_apps_records_block_options();

		$this->assertNotEmpty( $groups );

		foreach ( $groups as $group ) {
			foreach ( array_keys( $group['options'] ) as $key ) {
				$this->assertNotSame( '', \agend_apps_records_type_from_key( (string) $key ), $key . ' names no record type' );
			}
		}
	}

	#[Test]
	public function should_name_a_type_for_every_retired_panel_key(): void {
		// The keys the picker no longer offers still render, so they still
		// have to resolve a preview type in the editor.
		foreach ( array( 'listing_about', 'listing_categories', 'listing_tags', 'listing_hours', 'listing_custom_fields' ) as $key ) {
			$this->assertSame( 'listing', \agend_apps_records_type_from_key( $key ) );
			$this->assertNotSame( '', \agend_apps_records_retired_block_field( $key ), $key . ' names no replacement field' );
		}
	}
}
