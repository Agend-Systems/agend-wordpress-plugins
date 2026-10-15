<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
	public function should_comma_join_a_multi_value_location_selection(): void {
		$args = agend_apps_records_listings_list_args(
			array( 'pagination' => array( 'perPage' => 12 ), 'exclusions' => array() ),
			1,
			array(
				'location_city'     => array( 'Sydney', 'Melbourne' ),
				'location_state'    => array( 'NSW', 'VIC' ),
				'location_postcode' => array( '2000' ),
				'location_country'  => array( 'AU' ),
			)
		);

		// The gateway 422s on a bracket-encoded array (state[0]=NSW), so a
		// multi-value selection must reach it as one comma-joined string.
		$this->assertSame( 'Sydney,Melbourne', $args['city'] );
		$this->assertSame( 'NSW,VIC', $args['state'] );
		$this->assertSame( '2000', $args['postcode'] );
		$this->assertSame( 'AU', $args['country'] );
	}

	#[Test]
	public function should_omit_a_location_param_entirely_when_untouched(): void {
		$args = agend_apps_records_listings_list_args(
			array( 'pagination' => array( 'perPage' => 12 ), 'exclusions' => array() ),
			1
		);

		$this->assertArrayNotHasKey( 'city', $args );
		$this->assertArrayNotHasKey( 'state', $args );
		$this->assertArrayNotHasKey( 'postcode', $args );
		$this->assertArrayNotHasKey( 'country', $args );
	}

	// -- Organisation filter (location name) ----------------------------------

	/**
	 * Every case of tests/fixtures/directory-location-name-params.json: a filter
	 * state and the locationName both parameter builders must send for it. The
	 * catalogue script's queryParams() is held to the same file by
	 * tests/js/directory-query-params.test.mjs.
	 *
	 * @return array<string, array{array<string, mixed>, string|null}>
	 */
	public static function location_name_cases(): array {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/directory-location-name-params.json' ), true );
		$cases   = array();
		foreach ( $fixture['cases'] as $case ) {
			$cases[ $case['name'] ] = array( $case['state'], $case['locationName'] );
		}

		return $cases;
	}

	/**
	 * @param array<string, mixed> $state        The catalogue filter state.
	 * @param string|null          $location_name What the browser script sends for it, or null for no parameter.
	 */
	#[DataProvider( 'location_name_cases' )]
	#[Test]
	public function should_send_the_location_name_the_browser_script_sends_when_given_the_same_state( array $state, ?string $location_name ): void {
		$args = agend_apps_records_listings_list_args( array( 'pagination' => array( 'perPage' => 12 ), 'exclusions' => array() ), 1, $state );

		if ( null === $location_name ) {
			$this->assertArrayNotHasKey( 'locationName', $args );
			return;
		}
		$this->assertSame( $location_name, $args['locationName'] );
	}

	#[Test]
	public function should_unslash_the_location_name_when_it_arrives_slashed(): void {
		$args = agend_apps_records_listings_list_args( array(), 1, array( 'location_name' => "St Vincent\\'s" ) );

		$this->assertSame( "St Vincent's", $args['locationName'] );
	}

	#[Test]
	public function should_remove_every_backslash_from_a_location_name_when_it_arrives_slashed(): void {
		// The PHP builder reads WordPress's slashed form, so a slashed backslash
		// ("Smith\\Jones") comes back as one. This deliberately differs from the
		// browser script, which keeps a backslash as typed, and is why the shared
		// fixture holds no backslash case.
		$slashed = agend_apps_records_listings_list_args( array(), 1, array( 'location_name' => 'Smith\\\\Jones' ) );
		$bare    = agend_apps_records_listings_list_args( array(), 1, array( 'location_name' => 'Smith\\Jones' ) );

		$this->assertSame( 'Smith\\Jones', $slashed['locationName'] );
		$this->assertSame( 'SmithJones', $bare['locationName'], 'an unslashed value must not be passed in: its backslash is lost' );
	}

	#[Test]
	public function should_send_no_location_name_when_the_state_is_untouched(): void {
		$args = agend_apps_records_listings_list_args( array( 'pagination' => array( 'perPage' => 12 ), 'exclusions' => array() ), 1 );

		$this->assertArrayNotHasKey( 'locationName', $args );
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

	// -- Selected location and every location ---------------------------------

	/**
	 * A search card for a listing with three locations whose primary location
	 * is not first, as the search returns it.
	 */
	private function threeLocationCard( ?string $selected_location_id ): array {
		return array(
			'primary_location'     => array( 'city' => 'Newcastle', 'state' => 'NSW' ),
			'selected_location_id' => $selected_location_id,
			'locations'            => array(
				array(
					'id'             => 'loc-a',
					'name'           => 'Harbour Office',
					'address_line_1' => '1 Wharf Road',
					'city'           => 'Sydney',
					'state'          => 'NSW',
					'postcode'       => '2000',
					'is_primary'     => false,
				),
				array(
					'id'             => 'loc-b',
					'name'           => 'Hunter Branch',
					'address_line_1' => '9 Hunter Street',
					'address_line_2' => 'Suite 4',
					'city'           => 'Newcastle',
					'state'          => 'NSW',
					'postcode'       => '2300',
					'is_primary'     => true,
				),
				array(
					'id'             => 'loc-c',
					'name'           => '',
					'address_line_1' => '',
					'city'           => 'Parramatta',
					'state'          => 'NSW',
					'postcode'       => '2150',
					'is_primary'     => false,
				),
			),
		);
	}

	#[Test]
	public function should_offer_a_location_name_field_reading_the_selected_location_when_the_search_selected_one(): void {
		$this->assertSame( 'Location name', agend_apps_records_field_label( 'listing:location_name' ) );
		$this->assertTrue( agend_apps_records_field_applies( 'listing:location_name', 'listing' ) );
		$this->assertSame( 'Harbour Office', agend_apps_records_field_value( 'listing:location_name', 'listing', $this->threeLocationCard( 'loc-a' ) ) );
	}

	#[Test]
	public function should_read_the_primary_location_when_the_selected_location_names_none_of_the_locations(): void {
		$this->assertSame( 'Hunter Branch', agend_apps_records_field_value( 'listing:location_name', 'listing', $this->threeLocationCard( 'loc-missing' ) ) );
		$this->assertSame( 'Hunter Branch', agend_apps_records_field_value( 'listing:location_name', 'listing', $this->threeLocationCard( null ) ) );
	}

	#[Test]
	public function should_read_the_first_location_when_none_is_selected_or_primary(): void {
		$record = $this->threeLocationCard( null );
		foreach ( $record['locations'] as $index => $location ) {
			$record['locations'][ $index ]['is_primary'] = false;
		}

		$this->assertSame( 'Harbour Office', agend_apps_records_field_value( 'listing:location_name', 'listing', $record ) );
	}

	#[Test]
	public function should_render_every_address_field_from_one_location_when_the_primary_location_is_not_first(): void {
		$record = $this->threeLocationCard( null );

		$this->assertSame( '9 Hunter Street, Suite 4, Newcastle NSW 2300', agend_apps_records_field_value( 'listing:address', 'listing', $record ) );
		$this->assertSame( '9 Hunter Street, Suite 4', agend_apps_records_field_value( 'listing:street', 'listing', $record ) );
		$this->assertSame( 'Newcastle', agend_apps_records_field_value( 'listing:city', 'listing', $record ) );
		$this->assertSame( 'NSW', agend_apps_records_field_value( 'listing:state', 'listing', $record ) );
		$this->assertSame( '2300', agend_apps_records_field_value( 'listing:postcode', 'listing', $record ) );
		$this->assertSame( 'Newcastle, NSW', agend_apps_records_field_value( 'listing:location', 'listing', $record ) );
	}

	#[Test]
	public function should_render_every_address_field_from_the_selected_location_when_the_search_selected_one(): void {
		$record = $this->threeLocationCard( 'loc-a' );

		$this->assertSame( '1 Wharf Road, Sydney NSW 2000', agend_apps_records_field_value( 'listing:address', 'listing', $record ) );
		$this->assertSame( 'Sydney, NSW', agend_apps_records_field_value( 'listing:location', 'listing', $record ) );
		$this->assertSame( '2000', agend_apps_records_field_value( 'listing:postcode', 'listing', $record ) );
	}

	#[Test]
	public function should_render_the_location_name_field_empty_when_the_location_has_no_name(): void {
		$record = $this->threeLocationCard( 'loc-c' );

		$this->assertNull( agend_apps_records_field_value( 'listing:location_name', 'listing', $record ) );
		$this->assertSame( '', agend_apps_records_render_field( 'listing:location_name', 'listing', $record ) );
	}

	#[Test]
	public function should_offer_an_all_locations_list_field_with_one_entry_per_location(): void {
		$this->assertSame( 'All locations', agend_apps_records_field_label( 'listing:locations' ) );
		$this->assertSame( 'list', agend_apps_records_field_kind( 'listing:locations' ) );
		$this->assertCount( 3, agend_apps_records_field_value( 'listing:locations', 'listing', $this->threeLocationCard( null ) ) );
	}

	#[Test]
	public function should_list_each_location_as_its_name_then_its_one_line_address_omitting_an_empty_half(): void {
		$this->assertSame(
			array(
				'Harbour Office, 1 Wharf Road, Sydney NSW 2000',
				'Hunter Branch, 9 Hunter Street, Suite 4, Newcastle NSW 2300',
				'Parramatta NSW 2150',
			),
			agend_apps_records_field_value( 'listing:locations', 'listing', $this->threeLocationCard( 'loc-a' ) )
		);
	}

	#[Test]
	public function should_skip_a_location_with_neither_a_name_nor_an_address_when_listing_all_locations(): void {
		$record                = $this->threeLocationCard( null );
		$record['locations'][] = array( 'id' => 'loc-empty', 'name' => ' ', 'city' => '', 'is_primary' => false );

		$this->assertCount( 3, agend_apps_records_field_value( 'listing:locations', 'listing', $record ) );
	}

	#[Test]
	public function should_list_the_selected_location_first_and_the_rest_in_payload_order(): void {
		$this->assertSame(
			array(
				'Parramatta NSW 2150',
				'Harbour Office, 1 Wharf Road, Sydney NSW 2000',
				'Hunter Branch, 9 Hunter Street, Suite 4, Newcastle NSW 2300',
			),
			agend_apps_records_field_value( 'listing:locations', 'listing', $this->threeLocationCard( 'loc-c' ) )
		);
	}

	#[Test]
	public function should_list_the_primary_location_first_when_the_search_selected_none(): void {
		$entries = agend_apps_records_field_value( 'listing:locations', 'listing', $this->threeLocationCard( null ) );

		$this->assertSame( 'Hunter Branch, 9 Hunter Street, Suite 4, Newcastle NSW 2300', $entries[0] );
		$this->assertSame( 'Harbour Office, 1 Wharf Road, Sydney NSW 2000', $entries[1] );
	}

	#[Test]
	public function should_list_every_category_of_a_search_card_in_the_order_the_api_returns_them(): void {
		$record = array(
			'primary_category' => array( 'id' => 'c2', 'name' => 'Bakeries' ),
			'categories'       => array(
				array( 'id' => 'c2', 'name' => 'Bakeries', 'slug' => 'bakeries', 'parent_id' => null ),
				array( 'id' => 'c9', 'name' => 'Florists', 'slug' => 'florists', 'parent_id' => 'c1' ),
				array( 'id' => 'c4', 'name' => 'Delis', 'slug' => 'delis', 'parent_id' => null ),
			),
		);

		$this->assertSame( array( 'Bakeries', 'Florists', 'Delis' ), agend_apps_records_field_value( 'listing:categories', 'listing', $record ) );
	}

	// -- Card custom fields from the search payload ------------------------------

	/**
	 * A search card as the directory search returns it: `custom_fields` holds
	 * the fields the organisation flagged Show on Card.
	 */
	private function searchCardWithPaymentOptions( array $custom_fields ): array {
		return array(
			'id'                   => 'l1',
			'slug'                 => 'sample-listing',
			'name'                 => 'Sample Listing',
			'primary_location'     => array( 'city' => 'Sydney', 'state' => 'NSW' ),
			'locations'            => array(),
			'selected_location_id' => null,
			'custom_fields'        => $custom_fields,
		);
	}

	#[Test]
	public function should_render_a_card_custom_field_from_the_search_payload_when_the_template_places_it(): void {
		$record = $this->searchCardWithPaymentOptions(
			array( array( 'key' => 'billing', 'label' => 'Billing', 'type' => 'text', 'value' => 'Monthly account' ) )
		);

		$this->assertSame( 'Monthly account', agend_apps_records_render_field( 'common:custom_field', 'listing', $record, array( 'custom_field_key' => 'billing' ) ) );
	}

	#[Test]
	public function should_render_every_selected_option_label_when_a_card_custom_field_is_a_multi_select(): void {
		$record = $this->searchCardWithPaymentOptions(
			array(
				array(
					'key'         => 'payment_options',
					'label'       => 'Payment options',
					'type'        => 'multi_select',
					'value'       => array( 'cash', 'card', 'invoice' ),
					'value_label' => array( 'Cash', 'Card', 'Invoice' ),
				),
			)
		);

		$this->assertSame( 'Cash, Card, Invoice', agend_apps_records_render_field( 'common:custom_field', 'listing', $record, array( 'custom_field_key' => 'payment_options' ) ) );
	}

	#[Test]
	public function should_render_a_card_custom_field_empty_when_the_listing_has_no_value_for_it(): void {
		$record = $this->searchCardWithPaymentOptions( array() );

		$this->assertSame( '', agend_apps_records_render_field( 'common:custom_field', 'listing', $record, array( 'custom_field_key' => 'payment_options' ) ) );
	}

	#[Test]
	public function should_expose_updated_at_as_a_date_field(): void {
		$this->assertSame( 'date', agend_apps_records_field_kind( 'listing:updated_at' ) );
		$this->assertSame( '2026-09-02T04:41:29+00:00', agend_apps_records_field_value( 'listing:updated_at', 'listing', array( 'updated_at' => '2026-09-02T04:41:29+00:00' ) ) );
	}
}
