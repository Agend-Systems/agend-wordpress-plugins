<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';

/**
 * `agend_apps_records_preview_record()` / `agend_apps_records_preview_placeholder_record()`:
 * the editor-preview record a field widget renders against when it has no
 * {@see \Agend_Apps_Records_Record_Context} frame (i.e. edited directly in
 * Elementor, outside a catalogue or SSR detail render).
 *
 * None of `agend_apps_events_get_events()`, `agend_apps_lms_get_courses()` or
 * `agend_apps_directory_get_listings()` is required anywhere in this test
 * suite, so `function_exists()` on them stays false for the whole run and the
 * "wrapper unavailable" path is real, not simulated.
 */
#[CoversFunction( 'agend_apps_records_preview_record' )]
#[CoversFunction( 'agend_apps_records_preview_placeholder_record' )]
#[CoversFunction( 'agend_apps_records_preview_extra' )]
#[CoversFunction( 'agend_apps_records_fetch_preview_listing' )]
final class PreviewRecordsTest extends TestCase {

	#[Test]
	public function should_expose_the_documented_event_keys_on_the_placeholder_record(): void {
		$record = \agend_apps_records_preview_placeholder_record( 'event' );

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
		$record = \agend_apps_records_preview_placeholder_record( 'course' );

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
	public function should_expose_the_directory_listing_keys_the_detail_blocks_read(): void {
		$record = \agend_apps_records_preview_placeholder_record( 'listing' );

		// One key per Directory content block, so every block in a listing
		// template previews as a populated panel rather than as "this record
		// has nothing to show for this block".
		foreach ( array( 'description', 'categories', 'tags', 'phone', 'website', 'locations', 'business_hours', 'custom_fields', 'achievements', 'average_rating', 'review_count' ) as $key ) {
			$this->assertArrayHasKey( $key, $record, $key . ' is missing from the listing preview record' );
			$this->assertNotEmpty( $record[ $key ], $key . ' previews empty' );
		}

		$this->assertTrue( $record['_agend_preview_placeholder'] );
		$this->assertSame( 'sample-listing', $record['slug'] );
		$this->assertSame( 'Sample Listing', $record['name'] );
	}

	#[Test]
	public function should_return_the_placeholder_record_when_the_directory_wrapper_does_not_exist(): void {
		$this->assertFalse( function_exists( 'agend_apps_directory_get_listings' ) );

		$record = \agend_apps_records_preview_record( 'listing' );

		$this->assertTrue( $record['_agend_preview_placeholder'] );
		$this->assertSame( 'sample-listing', $record['slug'] );
	}

	#[Test]
	public function should_stand_in_for_the_reviews_call_a_placeholder_listing_cannot_make(): void {
		$record = \agend_apps_records_preview_placeholder_record( 'listing' );
		$extra  = \agend_apps_records_preview_extra( 'listing', $record );

		$this->assertSame( 'sample-listing', $extra['slug'] );
		$this->assertSame( '#', $extra['detail_url'] );
		$this->assertFalse( $extra['is_detail'] );
		$this->assertNotEmpty( $extra['reviews']['data'] );
	}

	#[Test]
	public function should_let_a_real_listing_fetch_its_own_reviews(): void {
		$extra = \agend_apps_records_preview_extra( 'listing', array( 'slug' => 'real-listing' ) );

		$this->assertSame( 'real-listing', $extra['slug'] );
		$this->assertArrayNotHasKey( 'reviews', $extra );
	}

	#[Test]
	public function should_not_stand_in_for_reviews_on_a_non_listing_record(): void {
		$extra = \agend_apps_records_preview_extra( 'event', \agend_apps_records_preview_placeholder_record( 'event' ) );

		$this->assertSame( 'sample-event', $extra['slug'] );
		$this->assertArrayNotHasKey( 'reviews', $extra );
	}

	#[Test]
	public function should_return_an_empty_array_for_an_unknown_type(): void {
		$this->assertSame( array(), \agend_apps_records_preview_placeholder_record( 'directory' ) );
	}

	#[Test]
	public function should_return_the_placeholder_record_when_the_events_wrapper_does_not_exist(): void {
		$this->assertFalse( function_exists( 'agend_apps_events_get_events' ) );

		$record = \agend_apps_records_preview_record( 'event' );

		$this->assertTrue( $record['_agend_preview_placeholder'] );
		$this->assertSame( 'sample-event', $record['slug'] );
	}

	#[Test]
	public function should_return_the_placeholder_record_when_the_courses_wrapper_does_not_exist(): void {
		$this->assertFalse( function_exists( 'agend_apps_lms_get_courses' ) );

		$record = \agend_apps_records_preview_record( 'course' );

		$this->assertTrue( $record['_agend_preview_placeholder'] );
		$this->assertSame( 'sample-course', $record['slug'] );
	}

	#[Test]
	public function should_return_an_empty_array_for_an_unknown_preview_type(): void {
		$this->assertSame( array(), \agend_apps_records_preview_record( 'directory' ) );
	}
}
