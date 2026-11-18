<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-field.php';

/**
 * The Agend Field Text case option: a value stored entirely in capitals, such
 * as a suburb from a source system, shows in title case; nothing else changes.
 */
final class TextCaseTest extends TestCase {

	/** @return array<string, array{string, string}> */
	public static function values(): array {
		return array(
			'one word'                  => array( 'MELBOURNE', 'Melbourne' ),
			'several words'             => array( 'NORTH CURL CURL', 'North Curl Curl' ),
			'hyphenated'                => array( 'WEST-END', 'West-End' ),
			'initial before apostrophe' => array( "O'BRIEN", "O'Brien" ),
			'possessive'                => array( "ST KILDA'S", "St Kilda's" ),
			'typographic apostrophe'    => array( "D\u{2019}ARCY", "D\u{2019}Arcy" ),
			'accented'                  => array( 'CAFÉ ÉCOLE', 'Café École' ),
			'digits kept'               => array( 'UNIT 5 MAIN ST', 'Unit 5 Main St' ),
			'already title case'        => array( 'Melbourne', 'Melbourne' ),
			'mixed case left alone'     => array( 'Mount Eliza VIC', 'Mount Eliza VIC' ),
			'camel case left alone'     => array( 'McDonald', 'McDonald' ),
			'all lower case left alone' => array( 'melbourne', 'melbourne' ),
			'digits only left alone'    => array( '2000', '2000' ),
			'empty'                     => array( '', '' ),
		);
	}

	#[Test]
	#[DataProvider( 'values' )]
	public function should_title_case_a_value_only_when_it_is_entirely_capitals( string $value, string $expected ): void {
		self::assertSame( $expected, agend_apps_records_title_case_if_upper( $value ) );
	}

	#[Test]
	public function should_leave_a_text_field_alone_by_default(): void {
		self::assertSame( 'MELBOURNE', agend_apps_records_format_field( 'MELBOURNE', 'text', array() ) );
		self::assertSame( 'MELBOURNE', agend_apps_records_format_field( 'MELBOURNE', 'text', array( 'text_case' => '' ) ) );
	}

	#[Test]
	public function should_title_case_a_text_field_when_the_option_is_on(): void {
		self::assertSame( 'Melbourne', agend_apps_records_format_field( 'MELBOURNE', 'text', array( 'text_case' => 'title_upper' ) ) );
		self::assertSame( 'Mount Eliza VIC', agend_apps_records_format_field( 'Mount Eliza VIC', 'text', array( 'text_case' => 'title_upper' ) ) );
	}

	#[Test]
	public function should_title_case_each_item_of_a_list_field_before_the_maximum_is_applied(): void {
		$options = array( 'text_case' => 'title_upper', 'list_separator' => ', ', 'list_max' => 2 );

		self::assertSame( 'Sydney, Port Macquarie', agend_apps_records_format_field( array( 'SYDNEY', 'PORT MACQUARIE', 'NEWCASTLE' ), 'list', $options ) );
		self::assertSame(
			'<ul class="agend-field__list"><li>Sydney</li><li>hobart</li></ul>',
			agend_apps_records_format_field( array( 'SYDNEY', 'hobart' ), 'list', array( 'text_case' => 'title_upper', 'list_display' => 'bullets' ) )
		);
	}

	#[Test]
	public function should_not_change_the_kinds_where_capitals_mean_something_else(): void {
		$options = array( 'text_case' => 'title_upper' );

		self::assertSame( 'ABN 12 345', agend_apps_records_format_field( 'ABN 12 345', 'phone', $options ) );
		self::assertSame( 'https://example.test/A', agend_apps_records_format_field( 'https://example.test/A', 'url', $options ) );
		self::assertSame( '<p>NOTICE</p>', agend_apps_records_format_field( '<p>NOTICE</p>', 'html', $options ) );
	}

	#[Test]
	public function should_escape_the_title_cased_result(): void {
		self::assertSame( 'Tom &amp; Jerry', agend_apps_records_format_field( 'TOM & JERRY', 'text', array( 'text_case' => 'title_upper' ) ) );
	}

	#[Test]
	public function should_read_the_option_from_the_surface_settings_only_when_it_is_the_known_value(): void {
		self::assertSame( 'title_upper', agend_apps_records_record_field_format_options( array( 'text_case' => 'title_upper' ) )['text_case'] );
		self::assertSame( '', agend_apps_records_record_field_format_options( array( 'text_case' => 'shout' ) )['text_case'] );
		self::assertSame( '', agend_apps_records_record_field_format_options( array() )['text_case'] );
	}

	#[Test]
	public function should_offer_the_option_in_the_record_field_schema_with_none_as_the_default(): void {
		$fields = array();
		foreach ( agend_apps_records_surface_schema( 'record-field' )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$fields[ $field['name'] ] = $field;
			}
		}

		self::assertSame( '', $fields['text_case']['default'] );
		self::assertSame( array( '', 'title_upper' ), array_keys( $fields['text_case']['options'] ) );
	}
}
