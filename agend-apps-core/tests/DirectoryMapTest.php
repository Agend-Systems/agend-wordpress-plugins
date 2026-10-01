<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Directory_REST_Controller;
use Agend_Apps_Records_Filter_Context;
use Agend_Apps_Records_Record_Context;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-map.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/directory-routes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/filters.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/query.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/filter.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-map.php';

/**
 * Location search, the Nearest sort, distances, the List / Map switch and the
 * Agend Map surface (the directory map phase), from the filter config down to
 * the gateway request.
 */
final class DirectoryMapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Records_Filter_Context::reset();
	}

	// -- Location and View filters ------------------------------------------

	#[Test]
	public function should_offer_location_and_view_filters_for_the_directory_only(): void {
		$registry = agend_apps_records_filter_registry();

		$this->assertSame( 'near', $registry['listing']['location']['state'] );
		$this->assertSame( 'location', $registry['listing']['location']['mode'] );
		$this->assertSame( 'view', $registry['listing']['view']['state'] );
		$this->assertArrayNotHasKey( 'location', $registry['event'] );
		$this->assertArrayNotHasKey( 'view', $registry['course'] );
	}

	#[Test]
	public function should_offer_nearest_among_the_directory_sorts(): void {
		$this->assertSame( 'Nearest', agend_apps_records_filter_static_values( 'listing_sort' )['distance'] );
	}

	#[Test]
	public function should_parse_radius_choices_in_order_without_duplicates_or_junk(): void {
		$this->assertSame( array( 5.0, 10.0, 12.5, 50.0 ), agend_apps_records_filter_radius_choices( '50, 10,abc,5,10,,12.5,0,-3,1001' ) );
	}

	#[Test]
	public function should_fall_back_to_the_default_radius_choices_when_none_are_usable(): void {
		$this->assertSame( array( 5.0, 10.0, 15.0, 20.0, 25.0, 50.0, 100.0 ), agend_apps_records_filter_radius_choices( 'none, 0' ) );
	}

	#[Test]
	public function should_snap_the_default_radius_to_the_nearest_choice(): void {
		$config = agend_apps_records_filter_location_config(
			array(
				'location_radius_choices' => '5,25,100',
				'location_radius_default' => 30,
			)
		);

		$this->assertSame( 25.0, $config['radius'] );
	}

	#[Test]
	public function should_keep_the_locate_button_when_the_place_box_is_off(): void {
		$config = agend_apps_records_filter_location_config(
			array(
				'location_show_search' => '',
				'location_show_locate' => '',
			)
		);

		$this->assertFalse( $config['search'] );
		$this->assertTrue( $config['locate'], 'a Location filter with no way in would be dead' );
	}

	#[Test]
	public function should_carry_location_settings_only_on_the_location_filter(): void {
		$location = agend_apps_records_filter_config( 'listing', 'location', array( 'location_region' => ' Australia ' ) );
		$search   = agend_apps_records_filter_config( 'listing', 'search', array() );

		$this->assertSame( 'location', $location['control'] );
		$this->assertSame( 'Australia', $location['location']['region'] );
		$this->assertArrayNotHasKey( 'location', $search, 'other filters keep the config they had before' );
		$this->assertArrayNotHasKey( 'view', $search );
	}

	#[Test]
	public function should_start_the_view_switch_on_the_chosen_view(): void {
		$this->assertSame( 'map', agend_apps_records_filter_config( 'listing', 'view', array( 'view_default' => 'map' ) )['view']['default'] );
		$this->assertSame( 'list', agend_apps_records_filter_config( 'listing', 'view', array( 'view_default' => 'bogus' ) )['view']['default'] );
	}

	#[Test]
	public function should_draw_the_location_stand_in_disabled_with_its_radius_choices(): void {
		Agend_Apps_Records_Filter_Context::set( 'listing' );

		$html = agend_apps_records_render_filter(
			array(
				'filter'                  => 'listing:location',
				'location_radius_choices' => '10,25',
				'location_radius_default' => 25,
			)
		);

		$this->assertStringContainsString( 'class="agend-filter__location agend-filter__placeholder"', $html );
		$this->assertStringContainsString( '<option value="25" selected>Within 25 km</option>', $html );
		$this->assertMatchesRegularExpression( '/agend-filter__location-search"[^>]* disabled/', $html );
		$this->assertStringContainsString( 'agend-filter__location-chip" hidden', $html );
	}

	#[Test]
	public function should_hide_the_radius_dropdown_when_there_is_one_choice(): void {
		$html = agend_apps_records_render_filter(
			array(
				'filter'                  => 'listing:location',
				'location_radius_choices' => '20',
			),
			array( 'preview' => true )
		);

		$this->assertStringNotContainsString( 'agend-filter__location-radius', $html );
		$this->assertStringNotContainsString( ' disabled', $html, 'a preview is drawn enabled' );
	}

	#[Test]
	public function should_draw_the_view_switch_with_the_starting_view_pressed(): void {
		Agend_Apps_Records_Filter_Context::set( 'listing' );

		$html = agend_apps_records_render_filter(
			array(
				'filter'         => 'listing:view',
				'view_default'   => 'map',
				'view_map_label' => 'Map view',
			)
		);

		$this->assertStringContainsString( 'data-agend-view="map" aria-pressed="true" disabled>Map view</button>', $html );
		$this->assertStringContainsString( 'data-agend-view="list" aria-pressed="false" disabled>List</button>', $html );
	}

	#[Test]
	public function should_join_the_view_switch_into_one_control_when_asked(): void {
		Agend_Apps_Records_Filter_Context::set( 'listing' );

		$joined   = agend_apps_records_render_filter( array( 'filter' => 'listing:view', 'view_layout' => 'joined' ) );
		$separate = agend_apps_records_render_filter( array( 'filter' => 'listing:view' ) );

		$this->assertStringContainsString( 'agend-filter__view--joined', $joined );
		$this->assertStringNotContainsString( 'agend-filter__view--joined', $separate );
	}

	#[Test]
	public function should_fill_unselected_buttons_only_when_a_background_is_set(): void {
		$set   = agend_apps_records_filter_style( array( 'button_background' => '#FFFFFF' ) );
		$unset = agend_apps_records_filter_style( array() );
		$bad   = agend_apps_records_filter_style( array( 'button_background' => 'red;x:y' ) );

		$this->assertContains( 'agend-filter--button-bg', $set['classes'] );
		$this->assertStringContainsString( '--agend-filter-button-bg:#FFFFFF', $set['style'] );
		$this->assertNotContains( 'agend-filter--button-bg', $unset['classes'] );
		$this->assertNotContains( 'agend-filter--button-bg', $bad['classes'] );
	}

	#[Test]
	public function should_align_buttons_only_to_a_known_side(): void {
		$this->assertContains( 'agend-filter--align-end', agend_apps_records_filter_style( array( 'button_align' => 'end' ) )['classes'] );
		$this->assertContains( 'agend-filter--align-center', agend_apps_records_filter_style( array( 'button_align' => 'center' ) )['classes'] );
		$this->assertSame( array(), agend_apps_records_filter_style( array( 'button_align' => 'sideways' ) )['classes'] );
	}

	// -- Query ---------------------------------------------------------------

	#[Test]
	public function should_ask_nearest_first_for_a_location_search_with_no_sort_choice(): void {
		$args = agend_apps_records_listings_list_args(
			array( 'pagination' => array( 'perPage' => 12 ) ),
			1,
			array( 'near' => array( 'lat' => -33.815, 'lng' => 151.0011, 'radius' => 15, 'label' => 'Parramatta' ) )
		);

		$this->assertSame( '-33.815', $args['lat'] );
		$this->assertSame( '151.0011', $args['lng'] );
		$this->assertSame( '15', $args['radius'] );
		$this->assertSame( 'distance', $args['sortBy'] );
		$this->assertSame( 'asc', $args['sortOrder'] );
	}

	#[Test]
	public function should_fall_back_to_relevance_for_nearest_without_a_location(): void {
		$args = agend_apps_records_listings_list_args( array(), 1, array( 'sortBy' => 'distance' ) );

		$this->assertSame( 'relevance', $args['sortBy'] );
		$this->assertSame( 'desc', $args['sortOrder'] );
		$this->assertArrayNotHasKey( 'lat', $args );
	}

	#[Test]
	public function should_keep_an_explicit_sort_during_a_location_search(): void {
		$args = agend_apps_records_listings_list_args( array(), 1, array( 'sortBy' => 'name', 'near' => array( 'lat' => 1, 'lng' => 2, 'radius' => 3 ) ) );

		$this->assertSame( 'name', $args['sortBy'] );
		$this->assertSame( 'asc', $args['sortOrder'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusable_points(): array {
		return array(
			'missing radius'    => array( array( 'lat' => 1, 'lng' => 2 ) ),
			'latitude too far'  => array( array( 'lat' => 91, 'lng' => 2, 'radius' => 5 ) ),
			'not a number'      => array( array( 'lat' => 'north', 'lng' => 2, 'radius' => 5 ) ),
			'infinite'          => array( array( 'lat' => INF, 'lng' => 2, 'radius' => 5 ) ),
			'zero radius'       => array( array( 'lat' => 1, 'lng' => 2, 'radius' => 0 ) ),
			'not even an array' => array( 'Parramatta' ),
		);
	}

	#[Test]
	#[DataProvider( 'unusable_points' )]
	public function should_ignore_a_point_that_cannot_be_searched( $near ): void {
		$this->assertNull( agend_apps_records_listings_near( array( 'near' => $near ) ) );
		$this->assertArrayNotHasKey( 'lat', agend_apps_records_listings_list_args( array(), 1, array( 'near' => $near ) ) );
	}

	#[Test]
	public function should_send_a_map_viewport_only_when_there_is_no_point(): void {
		$bbox = agend_apps_records_listings_list_args( array(), 1, array( 'bbox' => '-33.7,-33.9,151.3,151.0' ) );
		$both = agend_apps_records_listings_list_args( array(), 1, array( 'bbox' => '-33.7,-33.9,151.3,151.0', 'near' => array( 'lat' => 1, 'lng' => 2, 'radius' => 3 ) ) );

		$this->assertSame( '-33.7,-33.9,151.3,151.0', $bbox['bbox'] );
		$this->assertArrayNotHasKey( 'bbox', $both, 'the gateway rejects a viewport alongside a point' );
	}

	#[Test]
	public function should_forward_a_viewport_on_later_pages_too(): void {
		$this->assertContains( 'bbox', agend_apps_records_fragment_allowed_params( 'listing' ) );
	}

	// -- Distance ------------------------------------------------------------

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function distances(): array {
		return array(
			'metres under a kilometre' => array( 0.854, '854 m' ),
			'kilometres to one place'  => array( 12.349, '12.3 km' ),
			'exactly one kilometre'    => array( 1, '1.0 km' ),
			'numeric string'           => array( '20.14', '20.1 km' ),
			'none'                     => array( null, '' ),
			'empty'                    => array( '', '' ),
			'negative'                 => array( -1, '' ),
			'infinite'                 => array( INF, '' ),
		);
	}

	#[Test]
	#[DataProvider( 'distances' )]
	public function should_format_a_distance_as_a_visitor_reads_it( $km, string $expected ): void {
		$this->assertSame( $expected, agend_apps_records_format_distance( $km ) );
	}

	#[Test]
	public function should_offer_the_distance_as_a_card_template_field(): void {
		$this->assertSame( '20.1 km', agend_apps_records_record_listing_distance( array( 'distance_km' => 20.14 ) ) );
		$this->assertSame( '', agend_apps_records_record_listing_distance( array( 'distance_km' => null ) ) );
	}

	// -- Gateway requests ----------------------------------------------------

	#[Test]
	public function should_round_coordinates_so_near_identical_searches_share_a_cache_entry(): void {
		$this->assertSame(
			array( 'lat' => -33.815, 'lng' => 151.001, 'radius' => 15.3, 'bbox' => '-33.7123,-33.9,151.3457,151', 'q' => 'x' ),
			agend_apps_directory_round_coordinates(
				array( 'lat' => '-33.81498', 'lng' => 151.00110000001, 'radius' => '15.34', 'bbox' => '-33.712345, -33.9 ,151.345678,151', 'q' => 'x' )
			)
		);
	}

	#[Test]
	public function should_leave_a_malformed_viewport_for_the_gateway_to_reject(): void {
		$this->assertSame( '1,2,3', agend_apps_directory_round_coordinates( array( 'bbox' => '1,2,3' ) )['bbox'] );
	}

	#[Test]
	public function should_ask_the_markers_endpoint_with_rounded_coordinates(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array(), 'meta' => array( 'truncated' => false ) ) );

		agend_apps_directory_get_markers( '', array( 'lat' => -33.81498, 'lng' => 151.00111, 'radius' => 15, 'limit' => 500 ) );

		$url = Agend_Test_WP::$requests[0]['url'];
		$this->assertStringContainsString( '/directory/markers?', $url );
		$this->assertStringContainsString( 'lat=-33.815', $url );
		$this->assertStringContainsString( 'lng=151.001', $url );
		$this->assertStringContainsString( 'limit=500', $url );
	}

	#[Test]
	public function should_share_one_cached_lookup_between_spellings_of_a_place(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'latitude' => -33.8, 'longitude' => 151, 'display_name' => 'Parramatta' ) ) );

		$first  = agend_apps_directory_geocode( 'Parramatta,  NSW' );
		$second = agend_apps_directory_geocode( ' parramatta, nsw ' );

		$this->assertCount( 1, Agend_Test_WP::$requests, 'the second spelling should be served from the cache' );
		$this->assertSame( $first, $second );
		$this->assertStringContainsString( '/directory/geocode?q=Parramatta%2C%20NSW', Agend_Test_WP::$requests[0]['url'] );
	}

	#[Test]
	public function should_not_cache_a_place_that_was_not_found(): void {
		Agend_Test_WP::queue_response( 404, array( 'error' => array( 'message' => 'Place not found' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'latitude' => 1, 'longitude' => 2 ) ) );

		$this->assertInstanceOf( \WP_Error::class, agend_apps_directory_geocode( 'Nowhere' ) );
		$this->assertIsArray( agend_apps_directory_geocode( 'Nowhere' ) );
		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_read_the_map_defaults_from_the_map_settings_endpoint(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'geocoding_available' => true ) ) );

		$this->assertTrue( agend_apps_directory_get_map_settings()['data']['geocoding_available'] );
		$this->assertStringContainsString( '/directory/map-settings', Agend_Test_WP::$requests[0]['url'] );
	}

	// -- Proxy routes --------------------------------------------------------

	/**
	 * @return array<string, array>
	 */
	private function route_args( string $method ): array {
		$reflection = new ReflectionMethod( Agend_Apps_Directory_REST_Controller::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( new Agend_Apps_Directory_REST_Controller() );
	}

	#[Test]
	public function should_sanitise_every_search_argument_with_a_callback_that_accepts_what_wordpress_passes(): void {
		// WordPress calls a sanitise callback with ($value, $request, $param).
		// PHP's built-in floatval() rejects the extra two, which fatalled any
		// request carrying lat, lng, radius or rating.
		foreach ( array( 'search_args', 'markers_args' ) as $method ) {
			foreach ( $this->route_args( $method ) as $name => $arg ) {
				$callback = $arg['sanitize_callback'] ?? null;
				if ( null === $callback ) {
					continue;
				}
				$this->assertNotContains( $callback, array( 'floatval', 'intval', 'boolval', 'strval', 'trim' ), "{$method} {$name}" );
			}
		}
	}

	#[Test]
	public function should_let_a_map_ask_for_up_to_two_thousand_pins_without_paging(): void {
		$args = $this->route_args( 'markers_args' );

		$this->assertSame( 2000, $args['limit']['maximum'] );
		$this->assertArrayNotHasKey( 'page', $args );
		$this->assertArrayNotHasKey( 'sortBy', $args );
		$this->assertArrayHasKey( 'bbox', $args );
		$this->assertArrayHasKey( 'lat', $args );
	}

	#[Test]
	public function should_accept_a_viewport_on_search(): void {
		$this->assertArrayHasKey( 'bbox', $this->route_args( 'search_args' ) );
	}

	// -- Map surface ---------------------------------------------------------

	#[Test]
	public function should_draw_on_openstreetmap_tiles_until_the_site_has_a_carto_key(): void {
		$this->assertSame( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', agend_apps_records_directory_map_tiles()['url'] );

		update_option( AGEND_APPS_RECORDS_MAP_TILES_KEY_OPTION, 'abc123' );
		$tiles = agend_apps_records_directory_map_tiles();

		$this->assertSame( 'https://basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png?key=abc123', $tiles['url'] );
		$this->assertStringContainsString( 'CARTO', $tiles['attribution'] );
	}

	#[Test]
	public function should_keep_anything_but_key_characters_out_of_the_tile_url(): void {
		// Quotes, ampersands, equals signs, angle brackets and whitespace go;
		// letters stay, so what remains can only ever be one query value.
		$this->assertSame( 'abc-123_X.yonloadx', agend_apps_records_sanitize_map_tiles_key( ' abc-123_X.y"&onload=<x> ' . "\n" ) );
	}

	#[Test]
	public function should_build_the_map_config_with_safe_defaults(): void {
		$config = agend_apps_records_directory_map_build_config( array() );

		$this->assertFalse( $config['followView'] );
		$this->assertTrue( $config['cluster'] );
		$this->assertFalse( $config['updateOnMove'] );
		$this->assertFalse( $config['scrollZoom'], 'the page should scroll past a map by default' );
		$this->assertSame( 500, $config['limit'] );
		$this->assertSame( 'View details', $config['popup']['linkText'] );
	}

	#[Test]
	public function should_only_accept_a_marker_limit_the_gateway_allows(): void {
		$this->assertSame( 2000, agend_apps_records_directory_map_build_config( array( 'marker_limit' => '2000' ) )['limit'] );
		$this->assertSame( 500, agend_apps_records_directory_map_build_config( array( 'marker_limit' => '50000' ) )['limit'] );
	}

	#[Test]
	public function should_turn_style_settings_into_custom_properties_dropping_unsafe_values(): void {
		$style = agend_apps_records_directory_map_style(
			array(
				'height'        => '560',
				'height_mobile' => '9999',
				'pin_colour'    => '#0C5998',
				'link_colour'   => 'red;background:url(x)',
				'border_radius' => '4',
			)
		);

		$this->assertStringContainsString( '--agend-map-height:560px', $style );
		$this->assertStringContainsString( '--agend-map-height-mobile:360px', $style, 'an unknown height falls back' );
		$this->assertStringContainsString( '--agend-map-pin:#0C5998', $style );
		$this->assertStringNotContainsString( 'url(', $style );
		$this->assertStringContainsString( '--agend-map-corner:4px', $style );
	}

	#[Test]
	public function should_wait_for_the_view_switch_only_on_the_live_page(): void {
		$live    = agend_apps_records_render_directory_map( array( 'follow_view' => 'yes' ) );
		$preview = agend_apps_records_render_directory_map( array( 'follow_view' => 'yes' ), array( 'preview' => true ) );

		$this->assertStringContainsString( 'agend-directory-map--awaiting-view', $live );
		$this->assertStringNotContainsString( 'agend-directory-map--awaiting-view', $preview, 'a designer needs to see the map to size it' );
		$this->assertStringContainsString( 'role="region"', $live );
		$this->assertStringContainsString( 'data-agend-map-config=', $live );
	}

	#[Test]
	public function should_render_nothing_inside_a_card_template(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'slug' => 'x' ) );
		try {
			$this->assertSame( '', agend_apps_records_render_directory_map( array() ) );
		} finally {
			Agend_Apps_Records_Record_Context::pop();
		}
	}

	#[Test]
	public function should_declare_the_map_surface_schema(): void {
		$names = array();
		foreach ( agend_apps_records_surface_schema( 'directory-map' )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$names[] = $field['name'] ?? '';
			}
		}

		foreach ( array( 'height', 'follow_view', 'update_on_move', 'marker_limit', 'popup_link_text', 'pin_colour' ) as $name ) {
			$this->assertContains( $name, $names );
		}
	}
}
