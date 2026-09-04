<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/class-agend-elementor-preview-records.php';

/**
 * `agend_elementor_preview_record()` / `agend_elementor_preview_placeholder_record()`:
 * the editor-preview record a field widget renders against when it has no
 * {@see \Agend_Elementor_Record_Context} frame (i.e. edited directly in
 * Elementor, outside a catalogue or SSR detail render).
 *
 * Neither `agend_apps_events_get_events()` nor `agend_apps_lms_get_courses()`
 * is required anywhere in this test suite, so `function_exists()` on them
 * stays false for the whole run and the "wrapper unavailable" path is real,
 * not simulated.
 */
#[CoversFunction( 'agend_elementor_preview_record' )]
#[CoversFunction( 'agend_elementor_preview_placeholder_record' )]
final class PreviewRecordsTest extends TestCase {

	#[Test]
	public function should_expose_the_documented_event_keys_on_the_placeholder_record(): void {
		$record = \agend_elementor_preview_placeholder_record( 'event' );

		$this->assertSame(
			array(
				'_agend_preview_placeholder',
				'slug',
				'name',
				'hero_image_url',
				'start_date',
				'end_date',
				'timezone',
				'venue_type',
				'venue_name',
				'venue_city',
				'category',
				'categories',
				'short_description',
				'description',
				'price_summary',
				'sold_out',
				'sponsors',
			),
			array_keys( $record )
		);
		$this->assertTrue( $record['_agend_preview_placeholder'] );
		$this->assertArrayHasKey( 'member_from', $record['price_summary'] );
		$this->assertArrayHasKey( 'non_member_from', $record['price_summary'] );
	}

	#[Test]
	public function should_expose_the_documented_course_keys_on_the_placeholder_record(): void {
		$record = \agend_elementor_preview_placeholder_record( 'course' );

		$this->assertSame(
			array(
				'_agend_preview_placeholder',
				'slug',
				'title',
				'image_url',
				'category',
				'difficulty',
				'delivery_mode',
				'description',
				'total_duration_minutes',
				'lessons_count',
				'base_price',
				'is_free',
				'instructor_name',
				'learning_outcomes',
			),
			array_keys( $record )
		);
		$this->assertTrue( $record['_agend_preview_placeholder'] );
		$this->assertCount( 3, $record['learning_outcomes'] );
	}

	#[Test]
	public function should_return_an_empty_array_for_an_unknown_type(): void {
		$this->assertSame( array(), \agend_elementor_preview_placeholder_record( 'directory' ) );
	}

	#[Test]
	public function should_return_the_placeholder_record_when_the_events_wrapper_does_not_exist(): void {
		$this->assertFalse( function_exists( 'agend_apps_events_get_events' ) );

		$record = \agend_elementor_preview_record( 'event' );

		$this->assertTrue( $record['_agend_preview_placeholder'] );
		$this->assertSame( 'sample-event', $record['slug'] );
	}

	#[Test]
	public function should_return_the_placeholder_record_when_the_courses_wrapper_does_not_exist(): void {
		$this->assertFalse( function_exists( 'agend_apps_lms_get_courses' ) );

		$record = \agend_elementor_preview_record( 'course' );

		$this->assertTrue( $record['_agend_preview_placeholder'] );
		$this->assertSame( 'sample-course', $record['slug'] );
	}

	#[Test]
	public function should_return_an_empty_array_for_an_unknown_preview_type(): void {
		$this->assertSame( array(), \agend_elementor_preview_record( 'directory' ) );
	}
}
