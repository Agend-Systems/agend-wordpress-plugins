<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Filter_Context;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-map.php';
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
 * The data source credits: Amazon Location Service for the pins on an Agend
 * Map, and OpenStreetMap for the Location filter's place search.
 */
final class DataSourceCreditsTest extends TestCase {

	private const AMAZON_CREDIT = '<a href="https://docs.aws.amazon.com/location/latest/developerguide/data-attribution.html">Locations: Amazon Location Service</a>';

	private const OSM_TILE_CREDIT = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

	private const PLACE_SEARCH_CREDIT = '<p class="agend-filter__location-credit">Place search © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a></p>';

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Records_Filter_Context::reset();
	}

	// -- Agend Map -------------------------------------------------------------

	#[Test]
	public function should_credit_amazon_after_openstreetmap_when_the_site_has_no_carto_key(): void {
		$attribution = agend_apps_records_directory_map_tiles()['attribution'];

		$this->assertSame( self::OSM_TILE_CREDIT . ' | ' . self::AMAZON_CREDIT, $attribution );
		$this->assertStringNotContainsString( 'CARTO', $attribution );
	}

	#[Test]
	public function should_credit_amazon_after_openstreetmap_and_carto_when_the_site_has_a_carto_key(): void {
		update_option( AGEND_APPS_RECORDS_MAP_TILES_KEY_OPTION, 'abc123' );

		$this->assertSame(
			self::OSM_TILE_CREDIT . ' &copy; <a href="https://carto.com/attributions">CARTO</a> | ' . self::AMAZON_CREDIT,
			agend_apps_records_directory_map_tiles()['attribution']
		);
	}

	#[Test]
	public function should_keep_the_amazon_credit_when_a_site_swaps_its_tiles(): void {
		add_filter(
			'agend_apps_records_directory_map_tiles',
			static fn() => array(
				'url'         => 'https://tiles.example.test/{z}/{x}/{y}.png',
				'attribution' => 'Example tiles',
			)
		);

		$this->assertSame( 'Example tiles | ' . self::AMAZON_CREDIT, agend_apps_records_directory_map_tiles()['attribution'] );
	}

	#[Test]
	public function should_show_only_the_amazon_credit_when_a_site_clears_the_tile_attribution(): void {
		add_filter(
			'agend_apps_records_directory_map_tiles',
			static fn( array $tiles ) => array( 'attribution' => '' ) + $tiles
		);

		$this->assertSame( self::AMAZON_CREDIT, agend_apps_records_directory_map_tiles()['attribution'] );
	}

	#[Test]
	public function should_hand_the_credit_to_the_map_script_when_the_map_config_is_built(): void {
		$config = agend_apps_records_directory_map_build_config( array() );

		$this->assertStringEndsWith( ' | ' . self::AMAZON_CREDIT, $config['tiles']['attribution'] );
	}

	// -- Location filter -------------------------------------------------------

	#[Test]
	public function should_credit_openstreetmap_beneath_the_place_box_when_the_live_filter_is_drawn(): void {
		Agend_Apps_Records_Filter_Context::set( 'listing' );

		$html = agend_apps_records_render_filter( array( 'filter' => 'listing:location' ) );

		$this->assertStringContainsString( self::PLACE_SEARCH_CREDIT, $html );
		$this->assertGreaterThan(
			strpos( $html, 'agend-filter__location-row--search' ),
			strpos( $html, 'agend-filter__location-credit' ),
			'the credit sits beneath the place box'
		);
	}

	#[Test]
	public function should_credit_openstreetmap_when_the_editor_preview_is_drawn(): void {
		$html = agend_apps_records_render_filter( array( 'filter' => 'listing:location' ), array( 'preview' => true ) );

		$this->assertStringContainsString( self::PLACE_SEARCH_CREDIT, $html );
	}

	#[Test]
	public function should_credit_openstreetmap_beneath_the_one_row_line_when_the_row_layout_is_chosen(): void {
		Agend_Apps_Records_Filter_Context::set( 'listing' );

		$html = agend_apps_records_render_filter( array( 'filter' => 'listing:location', 'location_layout' => 'row' ) );

		$this->assertStringContainsString( self::PLACE_SEARCH_CREDIT, $html );
		$this->assertGreaterThan(
			strpos( $html, '<p class="agend-filter__location-status"' ),
			strpos( $html, 'agend-filter__location-credit' ),
			'the credit is outside the one-row line, beneath it'
		);
	}

	#[Test]
	public function should_draw_no_place_search_credit_when_the_place_box_is_turned_off(): void {
		Agend_Apps_Records_Filter_Context::set( 'listing' );

		$html = agend_apps_records_render_filter(
			array(
				'filter'               => 'listing:location',
				'location_show_search' => 'no',
			)
		);

		$this->assertStringNotContainsString( 'agend-filter__location-row--search', $html );
		$this->assertStringNotContainsString( 'agend-filter__location-credit', $html );
		$this->assertStringNotContainsString( 'OpenStreetMap', $html );
	}
}
