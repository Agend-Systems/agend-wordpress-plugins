<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';

/**
 * The field registry: reading raw values from event and course records.
 */
final class FieldValueTest extends TestCase {

	#[Test]
	public function should_prefer_categories_array_over_category_object_when_both_present(): void {
		$record = array(
			'categories' => array( array( 'name' => 'Workshops' ) ),
			'category'   => array( 'name' => 'Legacy' ),
		);

		$this->assertSame( 'Workshops', agend_apps_records_field_value( 'event:category', 'event', $record ) );
	}

	#[Test]
	public function should_read_category_string_when_course_category_is_scalar(): void {
		$this->assertSame( 'Leadership', agend_apps_records_field_value( 'course:category', 'course', array( 'category' => 'Leadership' ) ) );
	}

	#[Test]
	public function should_return_null_when_category_absent(): void {
		$this->assertNull( agend_apps_records_field_value( 'event:category', 'event', array() ) );
	}

	#[Test]
	public function should_resolve_common_title_to_name_for_events_and_title_for_courses(): void {
		$this->assertSame( 'Gala', agend_apps_records_field_value( 'common:title', 'event', array( 'name' => 'Gala' ) ) );
		$this->assertSame( 'Intro', agend_apps_records_field_value( 'common:title', 'course', array( 'title' => 'Intro' ) ) );
	}

	#[Test]
	public function should_pick_member_price_when_viewer_is_member(): void {
		$record = array(
			'viewer_price_group' => 'member',
			'price_summary'      => array( 'member_from' => 50, 'non_member_from' => 80 ),
		);

		$this->assertSame( 50, agend_apps_records_field_value( 'event:price_from', 'event', $record ) );
	}

	#[Test]
	public function should_pick_non_member_price_when_viewer_is_anonymous(): void {
		$record = array( 'price_summary' => array( 'member_from' => 50, 'non_member_from' => 80 ) );

		$this->assertSame( 80, agend_apps_records_field_value( 'event:price_from', 'event', $record ) );
	}

	#[Test]
	public function should_return_null_when_field_does_not_apply_to_record_type(): void {
		$this->assertNull( agend_apps_records_field_value( 'course:title', 'event', array( 'title' => 'x' ) ) );
		$this->assertNull( agend_apps_records_field_value( 'nonsense:key', 'event', array() ) );
	}

	#[Test]
	public function should_not_warn_when_record_values_have_wrong_types(): void {
		$record = array(
			'start_date'    => null,
			'categories'    => 'oops',
			'price_summary' => 'not-an-array',
			'sponsors'      => 42,
		);

		$this->assertNull( agend_apps_records_field_value( 'event:start_date', 'event', $record ) );
		$this->assertNull( agend_apps_records_field_value( 'event:category', 'event', $record ) );
		$this->assertNull( agend_apps_records_field_value( 'event:price_member_from', 'event', $record ) );
	}

	#[Test]
	public function should_read_progress_percentage_and_default_to_zero_when_absent(): void {
		$enrolled = array( 'my_enrollment' => array( 'progress' => array( 'percentage' => 42.5 ) ) );

		$this->assertSame( 42.5, agend_apps_records_field_value( 'course:progress_percent', 'course', $enrolled ) );
		$this->assertSame( 0, agend_apps_records_field_value( 'course:progress_percent', 'course', array() ) );
	}

	#[Test]
	public function should_build_excerpt_from_stripped_description_when_no_short_description(): void {
		$record = array( 'description' => "<p>Hello   <strong>world</strong></p>\n<p>again</p>" );

		$this->assertSame( 'Hello world again', agend_apps_records_field_value( 'common:excerpt', 'event', $record ) );
	}

	#[Test]
	public function should_use_venue_name_for_location_and_fall_back_by_venue_type(): void {
		$this->assertSame( 'Sydney Town Hall', agend_apps_records_field_value( 'event:location', 'event', array( 'venue_name' => 'Sydney Town Hall', 'venue_type' => 'physical' ) ) );
		$this->assertSame( 'Online', agend_apps_records_field_value( 'event:location', 'event', array( 'venue_name' => null, 'venue_type' => 'virtual' ) ) );
		$this->assertSame( 'TBA', agend_apps_records_field_value( 'event:location', 'event', array( 'venue_type' => 'physical' ) ) );
	}

	#[Test]
	public function should_map_venue_type_to_label(): void {
		$this->assertSame( 'In-Person', agend_apps_records_field_value( 'event:venue_type', 'event', array( 'venue_type' => 'physical' ) ) );
	}

	#[Test]
	public function should_treat_free_course_as_zero_price(): void {
		$this->assertSame( 0, agend_apps_records_field_value( 'course:price', 'course', array( 'is_free' => true, 'base_price' => 120 ) ) );
		$this->assertSame( 120, agend_apps_records_field_value( 'common:price_from', 'course', array( 'base_price' => 120 ) ) );
	}

	#[Test]
	public function should_use_detail_url_from_extra_when_provided(): void {
		$value = agend_apps_records_field_value( 'common:detail_url', 'event', array( 'slug' => 'x' ), array( 'detail_url' => 'https://example.test/events/event/x/' ) );

		$this->assertSame( 'https://example.test/events/event/x/', $value );
	}

	#[Test]
	public function should_group_picker_options_and_restrict_by_kind(): void {
		$all    = agend_apps_records_field_options();
		$labels = array_column( $all, 'label' );
		$urls   = agend_apps_records_field_options( array( 'url' ) );

		$this->assertContains( 'Event', $labels );
		$this->assertContains( 'Course', $labels );
		foreach ( $urls as $group ) {
			foreach ( array_keys( $group['options'] ) as $key ) {
				$this->assertSame( 'url', agend_apps_records_field_kind( $key ) );
			}
		}
	}

	#[Test]
	public function should_declare_a_known_kind_for_every_field(): void {
		foreach ( agend_apps_records_field_registry() as $key => $descriptor ) {
			$this->assertContains( $descriptor['kind'], AGEND_APPS_RECORDS_FIELD_KINDS, $key );
			$this->assertNotEmpty( $descriptor['types'], $key );
		}
	}
}
