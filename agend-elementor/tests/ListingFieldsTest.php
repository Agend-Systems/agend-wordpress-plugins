<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-format.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-fields.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-query.php';

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
		$this->assertSame( 'Frontend Development', agend_elementor_field_value( 'listing:category', 'listing', $this->cardPayload() ) );
	}

	#[Test]
	public function should_prefer_the_categories_array_when_the_detail_payload_supplies_one(): void {
		$record = array( 'categories' => array( array( 'name' => 'Backend' ) ), 'primary_category' => array( 'name' => 'Frontend' ) );

		$this->assertSame( 'Backend', agend_elementor_field_value( 'listing:category', 'listing', $record ) );
	}

	#[Test]
	public function should_join_city_and_state_for_the_location_line(): void {
		$this->assertSame( 'Sydney, NSW', agend_elementor_field_value( 'listing:location', 'listing', $this->cardPayload() ) );
	}

	#[Test]
	public function should_read_location_from_the_locations_list_when_primary_location_absent(): void {
		$record = array( 'locations' => array( array( 'city' => 'Melbourne', 'state' => 'VIC' ) ) );

		$this->assertSame( 'Melbourne, VIC', agend_elementor_field_value( 'listing:location', 'listing', $record ) );
		$this->assertSame( 'Melbourne', agend_elementor_field_value( 'listing:city', 'listing', $record ) );
	}

	#[Test]
	public function should_omit_the_missing_half_of_a_partial_location(): void {
		$this->assertSame( 'Sydney', agend_elementor_field_value( 'listing:location', 'listing', array( 'primary_location' => array( 'city' => 'Sydney' ) ) ) );
	}

	#[Test]
	public function should_resolve_common_aliases_against_a_listing(): void {
		$record = $this->cardPayload();

		$this->assertSame( 'Sarah Mitchell', agend_elementor_field_value( 'common:title', 'listing', $record ) );
		$this->assertSame( 'Full Stack Developer', agend_elementor_field_value( 'common:excerpt', 'listing', $record ) );
		$this->assertSame( 'Frontend Development', agend_elementor_field_value( 'common:category', 'listing', $record ) );
	}

	#[Test]
	public function should_not_offer_price_fields_to_a_listing(): void {
		$this->assertFalse( agend_elementor_field_applies( 'common:price_from', 'listing' ) );
		$this->assertTrue( agend_elementor_field_applies( 'common:title', 'listing' ) );
	}

	#[Test]
	public function should_read_a_custom_field_by_key_and_return_empty_when_not_entitled(): void {
		$record = array(
			'custom_fields' => array(
				array( 'key' => 'education_level', 'label' => 'Education Level', 'type' => 'dropdown', 'value' => "Master's Degree" ),
			),
		);

		$this->assertSame( "Master's Degree", agend_elementor_record_custom_field( $record, 'education_level' ) );
		$this->assertSame( 'Education Level', agend_elementor_record_custom_field_label( $record, 'education_level' ) );
		$this->assertSame( '', agend_elementor_record_custom_field( $record, 'salary_band' ) );
		$this->assertSame( '', agend_elementor_record_custom_field( array(), 'education_level' ) );
	}

	#[Test]
	public function should_join_a_multi_value_custom_field(): void {
		$record = array( 'custom_fields' => array( array( 'key' => 'skills', 'value' => array( 'React', 'TypeScript' ) ) ) );

		$this->assertSame( 'React, TypeScript', agend_elementor_record_custom_field( $record, 'skills' ) );
	}

	#[Test]
	public function should_read_the_custom_field_key_from_the_render_context(): void {
		$record = array( 'custom_fields' => array( array( 'key' => 'education_level', 'value' => 'Masters' ) ) );

		$this->assertSame(
			'Masters',
			agend_elementor_field_value( 'common:custom_field', 'listing', $record, array( 'custom_field_key' => 'education_level' ) )
		);
	}

	#[Test]
	public function should_build_listing_query_args_with_comma_joined_exclusions(): void {
		$config = array(
			'pagination' => array( 'perPage' => 12 ),
			'exclusions' => array( 'categories' => array( 'internal', 'staff' ), 'featured' => true ),
		);

		$args = agend_elementor_listings_list_args( $config, 2, array( 'search' => 'sarah', 'categories' => array( 'frontend', 'backend' ) ) );

		$this->assertSame( 2, $args['page'] );
		$this->assertSame( 12, $args['limit'] );
		$this->assertSame( 'sarah', $args['search'] );
		$this->assertSame( 'frontend,backend', $args['category'] );
		$this->assertSame( 'internal,staff', $args['excludeCategories'] );
		$this->assertSame( 'true', $args['featured'] );
	}

	#[Test]
	public function should_map_tag_badge_and_featured_filter_state_to_query_params(): void {
		$args = agend_elementor_listings_list_args(
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
		$args = agend_elementor_listings_list_args(
			array( 'pagination' => array( 'perPage' => 12 ), 'exclusions' => array( 'featured' => true ) ),
			1
		);

		$this->assertSame( 'true', $args['featured'] );
	}

	#[Test]
	public function should_apply_a_chosen_sort_and_default_to_relevance(): void {
		$config = array( 'pagination' => array( 'perPage' => 12 ), 'exclusions' => array() );

		$default = agend_elementor_listings_list_args( $config, 1 );
		$rating  = agend_elementor_listings_list_args( $config, 1, array( 'sortBy' => 'rating' ) );
		$name    = agend_elementor_listings_list_args( $config, 1, array( 'sortBy' => 'name' ) );

		$this->assertSame( 'relevance', $default['sortBy'] );
		$this->assertSame( 'rating', $rating['sortBy'] );
		$this->assertSame( 'desc', $rating['sortOrder'] );
		$this->assertSame( 'asc', $name['sortOrder'], 'names read better ascending' );
	}

	#[Test]
	public function should_drop_unknown_params_from_a_listing_fragment_request(): void {
		$out = agend_elementor_fragment_query_args( array( 'rating' => 4, 'timeframe' => 'upcoming', 'template' => 9, 'difficulty' => 'x' ), 'listing' );

		$this->assertSame( array( 'rating' => 4 ), $out );
	}
}
