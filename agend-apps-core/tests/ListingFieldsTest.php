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
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/query.php';

/**
 * Directory listing fields, which must read both the card payload
 * (primary_category, primary_location) and the detail payload (categories,
 * locations).
 */
final class ListingFieldsTest extends TestCase {

	private function cardPayload(): array {
		return array(
			'slug'             => 'sarah-mitchell',
			'name'             => 'Sarah Mitchell',
			'short_description' => 'Full Stack Developer',
			'logo_url'         => 'https://example.test/logo.png',
			'is_featured'      => 1,
			'average_rating'   => null,
			'review_count'     => 0,
			'primary_category' => array( 'id' => 'c1', 'name' => 'Frontend Development' ),
			'primary_location' => array( 'city' => 'Sydney', 'state' => 'NSW' ),
			'badges'           => array(),
		);
	}

	#[Test]
	public function should_read_primary_category_from_the_card_payload(): void {
		$this->assertSame( 'Frontend Development', agend_apps_records_field_value( 'listing:category', 'listing', $this->cardPayload() ) );
	}

	#[Test]
	public function should_prefer_the_categories_array_when_the_detail_payload_supplies_one(): void {
		$record = array( 'categories' => array( array( 'name' => 'Backend' ) ), 'primary_category' => array( 'name' => 'Frontend' ) );

		$this->assertSame( 'Backend', agend_apps_records_field_value( 'listing:category', 'listing', $record ) );
	}

	#[Test]
	public function should_join_city_and_state_for_the_location_line(): void {
		$this->assertSame( 'Sydney, NSW', agend_apps_records_field_value( 'listing:location', 'listing', $this->cardPayload() ) );
	}

	#[Test]
	public function should_read_location_from_the_locations_list_when_primary_location_absent(): void {
		$record = array( 'locations' => array( array( 'city' => 'Melbourne', 'state' => 'VIC' ) ) );

		$this->assertSame( 'Melbourne, VIC', agend_apps_records_field_value( 'listing:location', 'listing', $record ) );
		$this->assertSame( 'Melbourne', agend_apps_records_field_value( 'listing:city', 'listing', $record ) );
	}

	#[Test]
	public function should_omit_the_missing_half_of_a_partial_location(): void {
		$this->assertSame( 'Sydney', agend_apps_records_field_value( 'listing:location', 'listing', array( 'primary_location' => array( 'city' => 'Sydney' ) ) ) );
	}

	#[Test]
	public function should_resolve_common_aliases_against_a_listing(): void {
		$record = $this->cardPayload();

		$this->assertSame( 'Sarah Mitchell', agend_apps_records_field_value( 'common:title', 'listing', $record ) );
		$this->assertSame( 'Full Stack Developer', agend_apps_records_field_value( 'common:excerpt', 'listing', $record ) );
		$this->assertSame( 'Frontend Development', agend_apps_records_field_value( 'common:category', 'listing', $record ) );
	}

	#[Test]
	public function should_not_offer_price_fields_to_a_listing(): void {
		$this->assertFalse( agend_apps_records_field_applies( 'common:price_from', 'listing' ) );
		$this->assertTrue( agend_apps_records_field_applies( 'common:title', 'listing' ) );
	}

	#[Test]
	public function should_read_a_custom_field_by_key_and_return_empty_when_not_entitled(): void {
		$record = array(
			'custom_fields' => array(
				array( 'key' => 'education_level', 'label' => 'Education Level', 'type' => 'dropdown', 'value' => "Master's Degree" ),
			),
		);

		$this->assertSame( "Master's Degree", agend_apps_records_record_custom_field( $record, 'education_level' ) );
		$this->assertSame( 'Education Level', agend_apps_records_record_custom_field_label( $record, 'education_level' ) );
		$this->assertSame( '', agend_apps_records_record_custom_field( $record, 'salary_band' ) );
		$this->assertSame( '', agend_apps_records_record_custom_field( array(), 'education_level' ) );
	}

	#[Test]
	public function should_join_a_multi_value_custom_field(): void {
		$record = array( 'custom_fields' => array( array( 'key' => 'skills', 'value' => array( 'React', 'TypeScript' ) ) ) );

		$this->assertSame( 'React, TypeScript', agend_apps_records_record_custom_field( $record, 'skills' ) );
	}

	#[Test]
	public function should_read_the_custom_field_key_from_the_render_context(): void {
		$record = array( 'custom_fields' => array( array( 'key' => 'education_level', 'value' => 'Masters' ) ) );

		$this->assertSame(
			'Masters',
			agend_apps_records_field_value( 'common:custom_field', 'listing', $record, array( 'custom_field_key' => 'education_level' ) )
		);
	}

	#[Test]
	public function should_build_listing_query_args_with_comma_joined_exclusions(): void {
		$config = array(
			'pagination' => array( 'perPage' => 12 ),
			'exclusions' => array( 'categories' => array( 'internal', 'staff' ), 'featured' => true ),
		);

		$args = agend_apps_records_listings_list_args( $config, 2, array( 'search' => 'sarah', 'categories' => array( 'frontend', 'backend' ) ) );

		$this->assertSame( 2, $args['page'] );
		$this->assertSame( 12, $args['limit'] );
		$this->assertSame( 'sarah', $args['search'] );
		$this->assertSame( 'frontend,backend', $args['category'] );
		$this->assertSame( 'internal,staff', $args['excludeCategories'] );
		$this->assertSame( 'true', $args['featured'] );
	}

	#[Test]
	public function should_map_tag_badge_and_featured_filter_state_to_query_params(): void {
		$args = agend_apps_records_listings_list_args(
			array( 'pagination' => array( 'perPage' => 12 ), 'exclusions' => array() ),
			1,
			array( 'tag_ids' => array( 't1', 't2' ), 'badge_ids' => array( 'b1' ), 'featured' => '1' )
		);

		$this->assertSame( 't1,t2', $args['tag_ids'] );
		$this->assertSame( 'b1', $args['badge_ids'] );
		$this->assertSame( 'true', $args['featured'] );
	}

	#[Test]
	public function should_keep_the_widget_featured_floor_when_no_visitor_filter_is_set(): void {
		$args = agend_apps_records_listings_list_args(
			array( 'pagination' => array( 'perPage' => 12 ), 'exclusions' => array( 'featured' => true ) ),
			1
		);

		$this->assertSame( 'true', $args['featured'] );
	}

	#[Test]
	public function should_apply_a_chosen_sort_and_default_to_relevance(): void {
		$config = array( 'pagination' => array( 'perPage' => 12 ), 'exclusions' => array() );

		$default = agend_apps_records_listings_list_args( $config, 1 );
		$rating  = agend_apps_records_listings_list_args( $config, 1, array( 'sortBy' => 'rating' ) );
		$name    = agend_apps_records_listings_list_args( $config, 1, array( 'sortBy' => 'name' ) );

		$this->assertSame( 'relevance', $default['sortBy'] );
		$this->assertSame( 'rating', $rating['sortBy'] );
		$this->assertSame( 'desc', $rating['sortOrder'] );
		$this->assertSame( 'asc', $name['sortOrder'], 'names read better ascending' );
	}

	#[Test]
	public function should_send_custom_field_filters_as_values_and_bounds(): void {
		$args = agend_apps_records_listings_list_args(
			array( 'pagination' => array( 'perPage' => 12 ), 'exclusions' => array() ),
			1,
			array(
				'custom_fields' => array(
					'education_level'  => array( 'masters', 'phd' ),
					'years_experience' => array( 'min' => '5', 'max' => '10' ),
					'ignored'          => array(),
					''                 => array( 'x' ),
				),
			)
		);

		$this->assertSame( 'masters,phd', $args['custom_fields']['education_level'] );
		$this->assertSame( array( 'min' => '5', 'max' => '10' ), $args['custom_fields']['years_experience'] );
		$this->assertArrayNotHasKey( 'ignored', $args['custom_fields'], 'an untouched filter contributes nothing' );
		$this->assertArrayNotHasKey( '', $args['custom_fields'] );
	}

	#[Test]
	public function should_keep_only_the_bound_that_was_set(): void {
		$out = agend_apps_records_custom_field_filters( array( 'years' => array( 'min' => '5', 'max' => '' ) ) );

		$this->assertSame( array( 'years' => array( 'min' => '5' ) ), $out );
	}

	#[Test]
	public function should_carry_custom_fields_through_a_fragment_request(): void {
		$out = agend_apps_records_fragment_query_args(
			array( 'custom_fields' => array( 'education_level' => array( 'masters' ) ), 'difficulty' => 'x' ),
			'listing'
		);

		$this->assertSame( array( 'custom_fields' => array( 'education_level' => 'masters' ) ), $out );
	}

	#[Test]
	public function should_drop_unknown_params_from_a_listing_fragment_request(): void {
		$out = agend_apps_records_fragment_query_args( array( 'rating' => 4, 'timeframe' => 'upcoming', 'template' => 9, 'difficulty' => 'x' ), 'listing' );

		$this->assertSame( array( 'rating' => 4 ), $out );
	}

	#[Test]
	public function should_read_the_postcode_from_the_primary_location(): void {
		$record = array( 'primary_location' => array( 'city' => 'Bondi Junction', 'state' => 'NSW', 'postcode' => '2022' ) );

		$this->assertSame( '2022', agend_apps_records_field_value( 'listing:postcode', 'listing', $record ) );
	}

	#[Test]
	public function should_join_both_address_lines_for_the_street(): void {
		$record = array( 'locations' => array( array( 'address_line_1' => '500 Oxford Street', 'address_line_2' => 'Level 2' ) ) );

		$this->assertSame( '500 Oxford Street, Level 2', agend_apps_records_field_value( 'listing:street', 'listing', $record ) );
	}

	#[Test]
	public function should_build_the_one_line_address_from_street_and_locality(): void {
		$record = array( 'locations' => array( array( 'address_line_1' => '500 Oxford Street', 'city' => 'Bondi Junction', 'state' => 'NSW', 'postcode' => '2022' ) ) );

		$this->assertSame( '500 Oxford Street, Bondi Junction NSW 2022', agend_apps_records_field_value( 'listing:address', 'listing', $record ) );
	}

	#[Test]
	public function should_omit_missing_address_parts_without_stray_separators(): void {
		$this->assertSame( 'Sydney NSW', agend_apps_records_field_value( 'listing:address', 'listing', $this->cardPayload() ) );
		$this->assertSame( '15 Lake Street', agend_apps_records_field_value( 'listing:address', 'listing', array( 'locations' => array( array( 'address_line_1' => '15 Lake Street' ) ) ) ) );
		$this->assertEmpty( agend_apps_records_field_value( 'listing:address', 'listing', array() ) );
	}

	#[Test]
	public function should_expose_updated_at_as_a_date_field(): void {
		$this->assertSame( 'date', agend_apps_records_field_kind( 'listing:updated_at' ) );
		$this->assertSame( '2026-09-02T04:41:29+00:00', agend_apps_records_field_value( 'listing:updated_at', 'listing', array( 'updated_at' => '2026-09-02T04:41:29+00:00' ) ) );
	}
}
