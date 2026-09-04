<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Test_WP;
use Agend\Tests\TestCase;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/class-agend-elementor-format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/class-agend-elementor-fields.php';

/**
 * agend_elementor_format_field(): the escaping and formatting contract every
 * template widget relies on.
 */
final class FormatFieldTest extends TestCase {

	#[Test]
	public function should_escape_markup_when_kind_is_text(): void {
		$this->assertSame( '&lt;script&gt;x&lt;/script&gt;', agend_elementor_format_field( '<script>x</script>', 'text' ) );
	}

	#[Test]
	public function should_keep_allowed_tags_and_drop_script_when_kind_is_html(): void {
		$out = agend_elementor_format_field( '<p><strong>Hi</strong><script>bad()</script></p>', 'html' );

		$this->assertStringContainsString( '<strong>Hi</strong>', $out );
		$this->assertStringNotContainsString( '<script>', $out );
	}

	#[Test]
	public function should_strip_tags_and_truncate_when_html_has_truncate_option(): void {
		$out = agend_elementor_format_field( '<p>The quick brown fox jumps over the lazy dog</p>', 'html', array( 'truncate' => 20 ) );

		$this->assertSame( 'The quick brown fox…', $out );
	}

	#[Test]
	public function should_use_record_timezone_when_formatting_dates(): void {
		$out = agend_elementor_format_field( '2026-07-06T00:30:00Z', 'date', array( 'date_format' => 'j M Y H:i', 'timezone' => new DateTimeZone( 'Australia/Sydney' ) ) );

		$this->assertSame( '6 Jul 2026 10:30', $out );
	}

	#[Test]
	public function should_fall_back_to_site_date_format_when_none_given(): void {
		Agend_Test_WP::$options['date_format'] = 'Y-m-d';

		$this->assertSame( '2026-07-06', agend_elementor_format_field( '2026-07-06T09:00:00Z', 'date' ) );
	}

	#[Test]
	public function should_return_empty_when_date_is_unparseable(): void {
		$this->assertSame( '', agend_elementor_format_field( 'not a date', 'date' ) );
	}

	#[Test]
	public function should_fall_back_to_site_timezone_when_record_zone_is_garbage(): void {
		$tz = agend_elementor_record_timezone( array( 'timezone' => 'Bogus/Zone' ) );

		$this->assertSame( 'UTC', $tz->getName() );
	}

	#[Test]
	public function should_render_free_label_prefix_and_two_decimals_for_prices(): void {
		$this->assertSame( 'Free', agend_elementor_format_field( 0, 'price' ) );
		$this->assertSame( 'No charge', agend_elementor_format_field( '0', 'price', array( 'price_free_label' => 'No charge' ) ) );
		$this->assertSame( 'From $120.50', agend_elementor_format_field( 120.5, 'price', array( 'price_prefix' => 'From ' ) ) );
		$this->assertSame( '', agend_elementor_format_field( null, 'price' ) );
		$this->assertSame( '', agend_elementor_format_field( 'abc', 'price' ) );
	}

	#[Test]
	public function should_join_lists_with_separator_and_respect_max(): void {
		$items = array( 'A<b>', 'B', 'C' );

		$this->assertSame( 'A&lt;b&gt; | B', agend_elementor_format_field( $items, 'list', array( 'list_separator' => ' | ', 'list_max' => 2 ) ) );
		$this->assertSame( 'A&lt;b&gt;, B, C', agend_elementor_format_field( $items, 'list' ) );
		$this->assertSame( '', agend_elementor_format_field( array(), 'list' ) );
	}

	#[Test]
	public function should_render_bool_texts_and_hide_when_false_text_empty(): void {
		$this->assertSame( 'Yes', agend_elementor_format_field( true, 'bool' ) );
		$this->assertSame( 'Sold out', agend_elementor_format_field( true, 'bool', array( 'bool_true' => 'Sold out' ) ) );
		$this->assertSame( '', agend_elementor_format_field( false, 'bool' ) );
		$this->assertSame( 'Available', agend_elementor_format_field( false, 'bool', array( 'bool_false' => 'Available' ) ) );
	}

	#[Test]
	public function should_format_numbers_with_suffix_and_no_decimals_for_integers(): void {
		$this->assertSame( '1,200 min', agend_elementor_format_field( 1200, 'number', array( 'number_suffix' => ' min' ) ) );
		$this->assertSame( '42.50', agend_elementor_format_field( 42.5, 'number' ) );
	}

	#[Test]
	public function should_escape_url_kind(): void {
		$this->assertSame( 'https://example.test/a?b=1', agend_elementor_format_field( 'https://example.test/a?b=1', 'url' ) );
	}

	#[Test]
	public function should_truncate_text_on_word_boundary(): void {
		$this->assertSame( 'The quick brown…', agend_elementor_truncate_text( 'The quick brown fox jumps', 18 ) );
		$this->assertSame( 'short', agend_elementor_truncate_text( 'short', 18 ) );
	}

	#[Test]
	public function should_render_field_end_to_end_with_record_timezone(): void {
		$record = array( 'start_date' => '2026-07-06T00:30:00Z', 'timezone' => 'Australia/Sydney' );

		$this->assertSame( '6 Jul 2026 10:30', agend_elementor_render_field( 'event:start_date', 'event', $record, array(), array( 'date_format' => 'j M Y H:i' ) ) );
		$this->assertSame( '6 Jul 2026', agend_elementor_render_field( 'event:date_range', 'event', $record ) );
	}
}
