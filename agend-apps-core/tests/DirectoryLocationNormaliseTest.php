<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;

#[CoversFunction( 'agend_apps_directory_normalize_locations' )]
final class DirectoryLocationNormaliseTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-locations.php';
	}

	#[Test]
	public function should_mark_the_first_location_primary_when_none_is(): void {
		$result = agend_apps_directory_normalize_locations(
			array( array( 'address_line_1' => '1 A St' ), array( 'address_line_1' => '2 B St' ) )
		);

		self::assertSame( array( true, false ), array_column( $result, 'is_primary' ) );
	}

	#[Test]
	public function should_keep_only_one_primary_when_several_are_marked(): void {
		$result = agend_apps_directory_normalize_locations(
			array(
				array( 'address_line_1' => '1 A St', 'is_primary' => true ),
				array( 'address_line_1' => '2 B St', 'is_primary' => true ),
			)
		);

		self::assertSame( array( true, false ), array_column( $result, 'is_primary' ) );
	}

	#[Test]
	public function should_honour_a_primary_that_is_not_first(): void {
		$result = agend_apps_directory_normalize_locations(
			array(
				array( 'address_line_1' => '1 A St' ),
				array( 'address_line_1' => '2 B St', 'is_primary' => true ),
			)
		);

		self::assertSame( array( false, true ), array_column( $result, 'is_primary' ) );
	}

	#[Test]
	public function should_drop_an_address_repeating_an_earlier_one_ignoring_case_and_punctuation(): void {
		$result = agend_apps_directory_normalize_locations(
			array(
				array( 'address_line_1' => '1 Main St.', 'city' => 'Perth', 'postcode' => '6000' ),
				array( 'address_line_1' => '1 main st', 'city' => 'PERTH', 'postcode' => '6000' ),
				array( 'address_line_1' => '1 Main St', 'city' => 'Sydney', 'postcode' => '2000' ),
			)
		);

		self::assertSame( array( '1 Main St.', '1 Main St' ), array_column( $result, 'address_line_1' ) );
	}

	#[Test]
	public function should_move_primary_to_the_kept_entry_when_the_dropped_duplicate_was_primary(): void {
		$result = agend_apps_directory_normalize_locations(
			array(
				array( 'address_line_1' => '1 A St' ),
				array( 'address_line_1' => '2 B St' ),
				array( 'address_line_1' => '2 b st', 'is_primary' => true ),
			)
		);

		self::assertSame( array( false, true ), array_column( $result, 'is_primary' ) );
	}

	#[Test]
	public function should_identify_coordinate_only_locations_by_rounded_coordinates(): void {
		$result = agend_apps_directory_normalize_locations(
			array(
				array( 'latitude' => -31.950001, 'longitude' => 115.86 ),
				array( 'latitude' => -31.950002, 'longitude' => 115.860001 ),
				array( 'latitude' => -33.86, 'longitude' => 151.2 ),
			)
		);

		self::assertCount( 2, $result );
	}

	#[Test]
	public function should_keep_entries_with_no_address_and_no_coordinates(): void {
		$result = agend_apps_directory_normalize_locations( array( array( 'name' => 'A' ), array( 'name' => 'B' ) ) );

		self::assertCount( 2, $result );
	}

	#[Test]
	public function should_drop_entries_that_are_not_arrays(): void {
		$result = agend_apps_directory_normalize_locations( array( 'nope', array( 'address_line_1' => '1 A St' ), null ) );

		self::assertCount( 1, $result );
	}

	#[Test]
	public function should_cap_the_list_at_the_gateway_limit_and_still_have_one_primary(): void {
		$locations = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$locations[] = array( 'address_line_1' => $i . ' Road', 'is_primary' => 60 === $i );
		}

		$result = agend_apps_directory_normalize_locations( $locations );

		self::assertCount( 50, $result );
		self::assertCount( 1, array_filter( array_column( $result, 'is_primary' ) ) );
		self::assertTrue( $result[0]['is_primary'] );
	}

	#[Test]
	public function should_preserve_other_keys_and_return_an_empty_list_unchanged(): void {
		$result = agend_apps_directory_normalize_locations( array( array( 'address_line_1' => '1 A St', 'name' => 'HQ', 'phone' => '123' ) ) );

		self::assertSame( 'HQ', $result[0]['name'] );
		self::assertSame( '123', $result[0]['phone'] );
		self::assertSame( array(), agend_apps_directory_normalize_locations( array() ) );
	}
}
