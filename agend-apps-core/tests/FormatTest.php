<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/class-agend-elementor-format.php';

/**
 * The pure value formatters shared by the SSR catalogue detail pages and the
 * Elementor "field" widgets (class-agend-elementor-format.php).
 */
final class FormatTest extends TestCase {

	// -------------------------------------------------------------------
	// agend_elementor_ssr_ev_format_price()
	// -------------------------------------------------------------------

	#[Test]
	public function should_format_zero_price_as_free_label(): void {
		$this->assertSame( 'FREE', \agend_elementor_ssr_ev_format_price( 0 ) );
	}

	#[Test]
	public function should_return_null_price_when_value_is_not_numeric(): void {
		$this->assertNull( \agend_elementor_ssr_ev_format_price( null ) );
		$this->assertNull( \agend_elementor_ssr_ev_format_price( '' ) );
		$this->assertNull( \agend_elementor_ssr_ev_format_price( 'not-a-number' ) );
	}

	#[Test]
	public function should_format_decimal_price_with_two_places(): void {
		$this->assertSame( '$12.50', \agend_elementor_ssr_ev_format_price( 12.5 ) );
	}

	// -------------------------------------------------------------------
	// agend_elementor_ssr_ev_date_range()
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_single_date_when_start_and_end_are_the_same_day(): void {
		$range = \agend_elementor_ssr_ev_date_range( '2026-07-06T09:00:00+00:00', '2026-07-06T17:00:00+00:00', new \DateTimeZone( 'UTC' ) );

		$this->assertSame( '6 Jul 2026', $range );
	}

	#[Test]
	public function should_render_date_range_when_start_and_end_are_different_days(): void {
		$range = \agend_elementor_ssr_ev_date_range( '2026-07-06T09:00:00+00:00', '2026-07-08T17:00:00+00:00', new \DateTimeZone( 'UTC' ) );

		$this->assertSame( '6 Jul 2026 – 8 Jul 2026', $range );
	}

	#[Test]
	public function should_return_empty_string_when_start_date_is_invalid(): void {
		$this->assertSame( '', \agend_elementor_ssr_ev_date_range( 'not-a-date', '', new \DateTimeZone( 'UTC' ) ) );
	}

	// -------------------------------------------------------------------
	// agend_elementor_record_timezone()
	// -------------------------------------------------------------------

	#[Test]
	public function should_resolve_valid_record_timezone(): void {
		$tz = \agend_elementor_record_timezone( array( 'timezone' => 'Australia/Sydney' ) );

		$this->assertSame( 'Australia/Sydney', $tz->getName() );
	}

	#[Test]
	public function should_fall_back_to_site_timezone_when_record_timezone_is_invalid(): void {
		$tz = \agend_elementor_record_timezone( array( 'timezone' => 'Not/A_Real_Zone' ) );

		$this->assertSame( 'UTC', $tz->getName() );
	}

	#[Test]
	public function should_fall_back_to_site_timezone_when_record_has_no_timezone(): void {
		$tz = \agend_elementor_record_timezone( array() );

		$this->assertSame( 'UTC', $tz->getName() );
	}

	// -------------------------------------------------------------------
	// agend_elementor_ssr_lms_duration()
	// -------------------------------------------------------------------

	#[Test]
	public function should_format_duration_as_hours_and_minutes_when_both_present(): void {
		$this->assertSame( '1h 30m', \agend_elementor_ssr_lms_duration( 90 ) );
	}

	#[Test]
	public function should_format_duration_as_minutes_only_when_under_an_hour(): void {
		$this->assertSame( '45m', \agend_elementor_ssr_lms_duration( 45 ) );
	}

	#[Test]
	public function should_format_duration_as_hours_only_when_no_remainder_minutes(): void {
		$this->assertSame( '2h', \agend_elementor_ssr_lms_duration( 120 ) );
	}

	#[Test]
	public function should_format_duration_as_self_paced_when_minutes_is_zero_or_missing(): void {
		$this->assertSame( 'Self-paced', \agend_elementor_ssr_lms_duration( 0 ) );
		$this->assertSame( 'Self-paced', \agend_elementor_ssr_lms_duration( null ) );
	}

	// -------------------------------------------------------------------
	// agend_elementor_ssr_lms_difficulty() / agend_elementor_ssr_lms_mode()
	// -------------------------------------------------------------------

	#[Test]
	public function should_map_known_difficulty_value_to_its_label(): void {
		$this->assertSame( 'Intermediate', \agend_elementor_ssr_lms_difficulty( 'intermediate' ) );
	}

	#[Test]
	public function should_return_raw_value_when_difficulty_is_unrecognised(): void {
		$this->assertSame( 'expert-plus', \agend_elementor_ssr_lms_difficulty( 'expert-plus' ) );
	}

	#[Test]
	public function should_map_known_delivery_mode_to_its_label(): void {
		$this->assertSame( 'Live Online', \agend_elementor_ssr_lms_mode( 'live_online' ) );
	}

	#[Test]
	public function should_return_empty_label_when_delivery_mode_is_unrecognised(): void {
		$this->assertSame( '', \agend_elementor_ssr_lms_mode( 'unknown-mode' ) );
	}

	// -------------------------------------------------------------------
	// agend_elementor_ssr_ev_type_label()
	// -------------------------------------------------------------------

	#[Test]
	public function should_map_physical_venue_type_to_in_person_label(): void {
		$this->assertSame( 'In-Person', \agend_elementor_ssr_ev_type_label( 'physical' ) );
	}

	#[Test]
	public function should_map_virtual_venue_type_to_online_label(): void {
		$this->assertSame( 'Online', \agend_elementor_ssr_ev_type_label( 'virtual' ) );
	}

	#[Test]
	public function should_return_raw_value_when_venue_type_is_unrecognised(): void {
		$this->assertSame( 'pop-up', \agend_elementor_ssr_ev_type_label( 'pop-up' ) );
	}
}
