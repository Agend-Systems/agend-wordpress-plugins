<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';

/**
 * The pure value formatters shared by the SSR catalogue detail pages and the
 * Elementor "field" widgets (format.php).
 */
final class FormatTest extends TestCase {

	// -------------------------------------------------------------------
	// agend_apps_records_ssr_ev_format_price()
	// -------------------------------------------------------------------

	#[Test]
	public function should_format_zero_price_as_free_label(): void {
		$this->assertSame( 'FREE', \agend_apps_records_ssr_ev_format_price( 0 ) );
	}

	#[Test]
	public function should_return_null_price_when_value_is_not_numeric(): void {
		$this->assertNull( \agend_apps_records_ssr_ev_format_price( null ) );
		$this->assertNull( \agend_apps_records_ssr_ev_format_price( '' ) );
		$this->assertNull( \agend_apps_records_ssr_ev_format_price( 'not-a-number' ) );
	}

	#[Test]
	public function should_format_decimal_price_with_two_places(): void {
		$this->assertSame( '$12.50', \agend_apps_records_ssr_ev_format_price( 12.5 ) );
	}

	// -------------------------------------------------------------------
	// agend_apps_records_ssr_ev_date_range()
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_single_date_when_start_and_end_are_the_same_day(): void {
		$range = \agend_apps_records_ssr_ev_date_range( '2026-07-06T09:00:00+00:00', '2026-07-06T17:00:00+00:00', new \DateTimeZone( 'UTC' ) );

		$this->assertSame( '6 Jul 2026', $range );
	}

	#[Test]
	public function should_render_date_range_when_start_and_end_are_different_days(): void {
		$range = \agend_apps_records_ssr_ev_date_range( '2026-07-06T09:00:00+00:00', '2026-07-08T17:00:00+00:00', new \DateTimeZone( 'UTC' ) );

		$this->assertSame( '6 Jul 2026 – 8 Jul 2026', $range );
	}

	#[Test]
	public function should_return_empty_string_when_start_date_is_invalid(): void {
		$this->assertSame( '', \agend_apps_records_ssr_ev_date_range( 'not-a-date', '', new \DateTimeZone( 'UTC' ) ) );
	}

	// -------------------------------------------------------------------
	// agend_apps_records_record_timezone()
	// -------------------------------------------------------------------

	#[Test]
	public function should_resolve_valid_record_timezone(): void {
		$tz = \agend_apps_records_record_timezone( array( 'timezone' => 'Australia/Sydney' ) );

		$this->assertSame( 'Australia/Sydney', $tz->getName() );
	}

	#[Test]
	public function should_fall_back_to_site_timezone_when_record_timezone_is_invalid(): void {
		$tz = \agend_apps_records_record_timezone( array( 'timezone' => 'Not/A_Real_Zone' ) );

		$this->assertSame( 'UTC', $tz->getName() );
	}

	#[Test]
	public function should_fall_back_to_site_timezone_when_record_has_no_timezone(): void {
		$tz = \agend_apps_records_record_timezone( array() );

		$this->assertSame( 'UTC', $tz->getName() );
	}

	// -------------------------------------------------------------------
	// agend_apps_records_ssr_lms_duration()
	// -------------------------------------------------------------------

	#[Test]
	public function should_format_duration_as_hours_and_minutes_when_both_present(): void {
		$this->assertSame( '1h 30m', \agend_apps_records_ssr_lms_duration( 90 ) );
	}

	#[Test]
	public function should_format_duration_as_minutes_only_when_under_an_hour(): void {
		$this->assertSame( '45m', \agend_apps_records_ssr_lms_duration( 45 ) );
	}

	#[Test]
	public function should_format_duration_as_hours_only_when_no_remainder_minutes(): void {
		$this->assertSame( '2h', \agend_apps_records_ssr_lms_duration( 120 ) );
	}

	#[Test]
	public function should_format_duration_as_self_paced_when_minutes_is_zero_or_missing(): void {
		$this->assertSame( 'Self-paced', \agend_apps_records_ssr_lms_duration( 0 ) );
		$this->assertSame( 'Self-paced', \agend_apps_records_ssr_lms_duration( null ) );
	}

	// -------------------------------------------------------------------
	// agend_apps_records_ssr_lms_difficulty() / agend_apps_records_ssr_lms_mode()
	// -------------------------------------------------------------------

	#[Test]
	public function should_map_known_difficulty_value_to_its_label(): void {
		$this->assertSame( 'Intermediate', \agend_apps_records_ssr_lms_difficulty( 'intermediate' ) );
	}

	#[Test]
	public function should_return_raw_value_when_difficulty_is_unrecognised(): void {
		$this->assertSame( 'expert-plus', \agend_apps_records_ssr_lms_difficulty( 'expert-plus' ) );
	}

	#[Test]
	public function should_map_known_delivery_mode_to_its_label(): void {
		$this->assertSame( 'Live Online', \agend_apps_records_ssr_lms_mode( 'live_online' ) );
	}

	#[Test]
	public function should_return_empty_label_when_delivery_mode_is_unrecognised(): void {
		$this->assertSame( '', \agend_apps_records_ssr_lms_mode( 'unknown-mode' ) );
	}

	// -------------------------------------------------------------------
	// agend_apps_records_ssr_ev_type_label()
	// -------------------------------------------------------------------

	#[Test]
	public function should_map_physical_venue_type_to_in_person_label(): void {
		$this->assertSame( 'In-Person', \agend_apps_records_ssr_ev_type_label( 'physical' ) );
	}

	#[Test]
	public function should_map_virtual_venue_type_to_online_label(): void {
		$this->assertSame( 'Online', \agend_apps_records_ssr_ev_type_label( 'virtual' ) );
	}

	#[Test]
	public function should_return_raw_value_when_venue_type_is_unrecognised(): void {
		$this->assertSame( 'pop-up', \agend_apps_records_ssr_ev_type_label( 'pop-up' ) );
	}

	// -------------------------------------------------------------------
	// agend_apps_records_tel_href()
	// -------------------------------------------------------------------

	#[Test]
	public function should_keep_only_digits_and_plus_in_the_tel_href(): void {
		$this->assertSame( 'tel:+61398765432', \agend_apps_records_tel_href( '+61 3 9876 5432' ) );
		$this->assertSame( 'tel:0398765432', \agend_apps_records_tel_href( '(03) 9876-5432' ) );
	}

	#[Test]
	public function should_dial_only_the_first_number_without_its_extension(): void {
		$this->assertSame( 'tel:0398765432', \agend_apps_records_tel_href( '03 9876 5432 ext 12' ) );
		$this->assertSame( 'tel:0398765432', \agend_apps_records_tel_href( '03 9876 5432 x12' ) );
		$this->assertSame( 'tel:0398765432', \agend_apps_records_tel_href( '03 9876 5432 / 0400 111 222' ) );
		$this->assertSame( 'tel:0398765432', \agend_apps_records_tel_href( '03 9876 5432, 0400 111 222' ) );
	}

	#[Test]
	public function should_dial_the_number_after_a_label_that_contains_a_separator(): void {
		$this->assertSame( 'tel:0398765432', \agend_apps_records_tel_href( 'Phone/Fax: 03 9876 5432' ) );
		$this->assertSame( 'tel:0212345678', \agend_apps_records_tel_href( 'Ph/Fax 02 1234 5678' ) );
		$this->assertSame( 'tel:0400111222', \agend_apps_records_tel_href( 'Text or call 0400 111 222' ) );
	}

	#[Test]
	public function should_drop_the_bracketed_trunk_zero_after_a_country_code(): void {
		$this->assertSame( 'tel:+61398765432', \agend_apps_records_tel_href( '+61 (0)3 9876 5432' ) );
	}

	#[Test]
	public function should_return_no_tel_href_when_no_digit_is_left_to_dial(): void {
		$this->assertSame( '', \agend_apps_records_tel_href( 'On request' ) );
		$this->assertSame( '', \agend_apps_records_tel_href( '+' ) );
		$this->assertSame( '', \agend_apps_records_tel_href( '' ) );
	}
}
