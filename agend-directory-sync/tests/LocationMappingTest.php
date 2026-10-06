<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Field_Map;
use Agend_Directory_Sync_Listing_Transformer;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-path-resolver.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-field-map.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-listing-transformer.php';

/**
 * The primary location plus a repeater of additional locations: field map
 * sanitising and migration, and how the transformer resolves, de-duplicates
 * and marks the primary location.
 */
#[CoversClass( Agend_Directory_Sync_Field_Map::class )]
#[CoversClass( Agend_Directory_Sync_Listing_Transformer::class )]
final class LocationMappingTest extends TestCase {

	private function map( array $primary = array(), array $additional = array() ): array {
		return array(
			'core'                 => array( 'external_id' => 'id' ),
			'custom_fields'        => array(),
			'primary_location'     => array_merge( Agend_Directory_Sync_Field_Map::default_primary_location(), $primary ),
			'additional_locations' => $additional,
		);
	}

	private function locations( array $contact, array $map ): array {
		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( array( $contact ), $map );
		return $result['listings'][0]['locations'] ?? array();
	}

	private function arrayRow( array $overrides = array() ): array {
		return array_merge(
			Agend_Directory_Sync_Field_Map::blank_location_row( Agend_Directory_Sync_Field_Map::LOCATION_ROW_ARRAY ),
			array( 'source' => 'addresses' ),
			$overrides
		);
	}

	private function singleRow( array $overrides = array() ): array {
		return array_merge( Agend_Directory_Sync_Field_Map::blank_location_row(), $overrides );
	}

	#[Test]
	public function the_primary_location_alone_is_sent_as_the_primary(): void {
		$locations = $this->locations(
			array( 'id' => '1', 'street' => '1 Main St', 'suburb' => 'Perth' ),
			$this->map( array( 'address_line_1' => 'street', 'city' => 'suburb', 'label' => 'HQ' ) )
		);

		$this->assertCount( 1, $locations );
		$this->assertTrue( $locations[0]['is_primary'] );
		$this->assertSame( 'HQ', $locations[0]['name'] );
	}

	#[Test]
	public function an_unconfigured_map_sends_no_locations(): void {
		$this->assertSame( array(), $this->locations( array( 'id' => '1' ), $this->map() ) );
	}

	#[Test]
	public function single_rows_follow_the_primary_and_are_not_primary(): void {
		$locations = $this->locations(
			array( 'id' => '1', 'a' => '1 Main St', 'b' => '2 Side St' ),
			$this->map(
				array( 'address_line_1' => 'a' ),
				array( $this->singleRow( array( 'address_line_1' => 'b', 'label' => 'Branch' ) ) )
			)
		);

		$this->assertSame( array( '1 Main St', '2 Side St' ), array_column( $locations, 'address_line_1' ) );
		$this->assertSame( array( true, false ), array_column( $locations, 'is_primary' ) );
		$this->assertSame( 'Branch', $locations[1]['name'] );
	}

	#[Test]
	public function an_array_row_makes_one_location_per_item_with_item_relative_fields(): void {
		$locations = $this->locations(
			array(
				'id'        => '1',
				'addresses' => array(
					array( 'street' => '1 A St', 'suburb' => 'Perth', 'lat' => '-31.95', 'site' => 'Perth office' ),
					array( 'street' => '2 B St', 'suburb' => 'Sydney', 'lat' => '-33.86', 'site' => 'Sydney office' ),
				),
			),
			$this->map(
				array(),
				array(
					$this->arrayRow(
						array(
							'address_line_1' => '{street}, {suburb}',
							'city'           => 'suburb',
							'latitude'       => 'lat',
							'label_source'   => 'site',
							'label'          => 'Office',
						)
					),
				)
			)
		);

		$this->assertSame( array( '1 A St, Perth', '2 B St, Sydney' ), array_column( $locations, 'address_line_1' ) );
		$this->assertSame( array( 'Perth office', 'Sydney office' ), array_column( $locations, 'name' ) );
		$this->assertSame( -31.95, $locations[0]['latitude'] );
		$this->assertSame( array( true, false ), array_column( $locations, 'is_primary' ) );
	}

	#[Test]
	public function an_array_row_falls_back_to_the_static_label_when_an_item_has_no_name(): void {
		$locations = $this->locations(
			array( 'id' => '1', 'addresses' => array( array( 'street' => '1 A St' ) ) ),
			$this->map( array(), array( $this->arrayRow( array( 'address_line_1' => 'street', 'label_source' => 'site', 'label' => 'Office' ) ) ) )
		);

		$this->assertSame( 'Office', $locations[0]['name'] );
	}

	#[Test]
	public function an_array_source_holding_one_object_is_treated_as_one_item(): void {
		$locations = $this->locations(
			array( 'id' => '1', 'addresses' => array( 'street' => '1 A St' ) ),
			$this->map( array(), array( $this->arrayRow( array( 'address_line_1' => 'street' ) ) ) )
		);

		$this->assertCount( 1, $locations );
	}

	#[Test]
	public function an_array_row_whose_source_is_missing_or_not_a_list_contributes_nothing(): void {
		$map = $this->map( array(), array( $this->arrayRow( array( 'address_line_1' => 'street' ) ) ) );

		$this->assertSame( array(), $this->locations( array( 'id' => '1' ), $map ) );
		$this->assertSame( array(), $this->locations( array( 'id' => '1', 'addresses' => 'nope' ), $map ) );
		$this->assertSame( array(), $this->locations( array( 'id' => '1', 'addresses' => array() ), $map ) );
	}

	#[Test]
	public function an_additional_location_repeating_the_primary_is_dropped(): void {
		$locations = $this->locations(
			array(
				'id'        => '1',
				'street'    => '1 Main St.',
				'suburb'    => 'Perth',
				'addresses' => array(
					array( 'street' => '1 main st', 'suburb' => 'PERTH' ),
					array( 'street' => '2 Side St', 'suburb' => 'Perth' ),
					array( 'street' => '2 side st', 'suburb' => 'Perth' ),
				),
			),
			$this->map(
				array( 'address_line_1' => 'street', 'city' => 'suburb' ),
				array( $this->arrayRow( array( 'address_line_1' => 'street', 'city' => 'suburb' ) ) )
			)
		);

		$this->assertSame( array( '1 Main St.', '2 Side St' ), array_column( $locations, 'address_line_1' ) );
		$this->assertSame( array( true, false ), array_column( $locations, 'is_primary' ) );
	}

	#[Test]
	public function coordinate_only_locations_dedupe_on_rounded_coordinates(): void {
		$locations = $this->locations(
			array( 'id' => '1', 'la' => '-31.950001', 'lo' => '115.86', 'la2' => '-31.950002', 'lo2' => '115.860001' ),
			$this->map(
				array( 'latitude' => 'la', 'longitude' => 'lo' ),
				array( $this->singleRow( array( 'latitude' => 'la2', 'longitude' => 'lo2' ) ) )
			)
		);

		$this->assertCount( 1, $locations );
	}

	#[Test]
	public function the_first_additional_location_becomes_primary_when_the_primary_resolves_nothing(): void {
		$locations = $this->locations(
			array( 'id' => '1', 'b' => '2 Side St' ),
			$this->map(
				array( 'address_line_1' => 'missing' ),
				array( $this->singleRow( array( 'address_line_1' => 'b' ) ) )
			)
		);

		$this->assertCount( 1, $locations );
		$this->assertTrue( $locations[0]['is_primary'] );
	}

	#[Test]
	public function exactly_one_location_is_primary(): void {
		$addresses = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$addresses[] = array( 'street' => $i . ' Road' );
		}
		$locations = $this->locations(
			array( 'id' => '1', 'addresses' => $addresses ),
			$this->map( array(), array( $this->arrayRow( array( 'address_line_1' => 'street' ) ) ) )
		);

		$this->assertCount( 5, $locations );
		$this->assertCount( 1, array_filter( array_column( $locations, 'is_primary' ) ) );
	}

	#[Test]
	public function locations_are_capped_at_the_gateway_limit(): void {
		$addresses = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$addresses[] = array( 'street' => $i . ' Road' );
		}
		$locations = $this->locations(
			array( 'id' => '1', 'addresses' => $addresses ),
			$this->map( array(), array( $this->arrayRow( array( 'address_line_1' => 'street' ) ) ) )
		);

		$this->assertCount( Agend_Directory_Sync_Listing_Transformer::MAX_LOCATIONS_PER_LISTING, $locations );
	}

	#[Test]
	public function a_legacy_slot_map_passed_straight_to_the_transformer_still_works(): void {
		$map = array(
			'core'          => array( 'external_id' => 'id' ),
			'custom_fields' => array(),
			'locations'     => array(
				array_merge( Agend_Directory_Sync_Field_Map::default_primary_location(), array( 'address_line_1' => 'a' ) ),
				array_merge( Agend_Directory_Sync_Field_Map::default_primary_location(), array( 'address_line_1' => 'b' ) ),
			),
		);

		$locations = $this->locations( array( 'id' => '1', 'a' => '1 Main St', 'b' => '2 Side St' ), $map );

		$this->assertSame( array( '1 Main St', '2 Side St' ), array_column( $locations, 'address_line_1' ) );
		$this->assertSame( array( true, false ), array_column( $locations, 'is_primary' ) );
	}

	#[Test]
	public function a_saved_legacy_map_resolves_with_slot_one_as_primary_and_a_mapped_slot_two_as_a_row(): void {
		$slot_one = array_merge( Agend_Directory_Sync_Field_Map::default_primary_location(), array( 'address_line_1' => '{a} {b}', 'city' => 'suburb' ) );
		$slot_two = array_merge( Agend_Directory_Sync_Field_Map::default_primary_location(), array( 'address_line_1' => 'other', 'label' => 'Postal' ) );

		\Agend_Test_WP::$options[ Agend_Directory_Sync_Field_Map::OPTION_FIELD_MAP ] = array(
			'core'          => array(),
			'custom_fields' => array(),
			'locations'     => array( $slot_one, $slot_two ),
		);

		$resolved = Agend_Directory_Sync_Field_Map::resolve();

		$this->assertSame( '{a} {b}', $resolved['primary_location']['address_line_1'] );
		$this->assertSame( 'suburb', $resolved['primary_location']['city'] );
		$this->assertCount( 1, $resolved['additional_locations'] );
		$this->assertSame( 'single', $resolved['additional_locations'][0]['type'] );
		$this->assertSame( 'other', $resolved['additional_locations'][0]['address_line_1'] );
		$this->assertSame( 'Postal', $resolved['additional_locations'][0]['label'] );
	}

	#[Test]
	public function an_empty_legacy_second_slot_does_not_become_a_row(): void {
		$empty = Agend_Directory_Sync_Field_Map::default_primary_location();

		\Agend_Test_WP::$options[ Agend_Directory_Sync_Field_Map::OPTION_FIELD_MAP ] = array(
			'locations' => array( array_merge( $empty, array( 'city' => 'suburb' ) ), $empty ),
		);

		$this->assertSame( array(), Agend_Directory_Sync_Field_Map::resolve()['additional_locations'] );
	}

	#[Test]
	public function save_then_resolve_round_trips_the_new_shape(): void {
		Agend_Directory_Sync_Field_Map::save(
			array(),
			'',
			array( 'city' => 'suburb', 'label' => 'HQ' ),
			array(
				array( 'type' => 'array', 'source' => 'addresses', 'address_line_1' => 'street', 'label_source' => 'site' ),
				array( 'type' => 'single', 'address_line_1' => 'postal' ),
			)
		);

		$resolved = Agend_Directory_Sync_Field_Map::resolve();

		$this->assertSame( 'suburb', $resolved['primary_location']['city'] );
		$this->assertSame( 'array', $resolved['additional_locations'][0]['type'] );
		$this->assertSame( 'addresses', $resolved['additional_locations'][0]['source'] );
		$this->assertSame( 'site', $resolved['additional_locations'][0]['label_source'] );
		$this->assertSame( 'single', $resolved['additional_locations'][1]['type'] );
		$this->assertArrayNotHasKey( 'locations', get_option( Agend_Directory_Sync_Field_Map::OPTION_FIELD_MAP ) );
	}

	#[Test]
	public function sanitising_drops_empty_rows_and_array_rows_without_a_source_and_reindexes(): void {
		$rows = Agend_Directory_Sync_Field_Map::sanitize_additional_locations(
			array(
				5 => array( 'type' => 'single' ),
				7 => array( 'type' => 'array', 'address_line_1' => 'street' ),
				9 => array( 'type' => 'bogus', 'city' => 'suburb' ),
				11 => 'not-an-array',
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertArrayHasKey( 0, $rows );
		$this->assertSame( 'single', $rows[0]['type'] );
	}

	#[Test]
	public function an_array_row_source_cannot_be_a_template(): void {
		$rows = Agend_Directory_Sync_Field_Map::sanitize_additional_locations(
			array( array( 'type' => 'array', 'source' => '{addresses}', 'city' => 'suburb' ) )
		);

		$this->assertSame( 'addresses', $rows[0]['source'] );
	}

	#[Test]
	public function the_number_of_rows_is_capped(): void {
		$posted = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$posted[] = array( 'type' => 'single', 'city' => 'suburb' );
		}

		$this->assertCount(
			Agend_Directory_Sync_Field_Map::MAX_ADDITIONAL_LOCATION_ROWS,
			Agend_Directory_Sync_Field_Map::sanitize_additional_locations( $posted )
		);
	}
}
