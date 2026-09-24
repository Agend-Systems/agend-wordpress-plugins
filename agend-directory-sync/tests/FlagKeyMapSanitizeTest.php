<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Field_Map;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Agend_Test_WP;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-path-resolver.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-field-map.php';

/**
 * Flag key map feature: `sanitize_flags()` validation of the new mode/map/
 * map_default keys, and the offered-outcomes / ranking split (OUTCOME_SKIP
 * is reserved for a rank but not yet offered).
 */
#[CoversClass( Agend_Directory_Sync_Field_Map::class )]
final class FlagKeyMapSanitizeTest extends TestCase {

	#[Test]
	public function an_invalid_mode_becomes_truthy(): void {
		$flags = Agend_Directory_Sync_Field_Map::sanitize_flags(
			array( 'eligible_flag_mode' => 'nonsense' )
		);

		$this->assertSame( Agend_Directory_Sync_Field_Map::FLAG_MODE_TRUTHY, $flags['eligible_flag_mode'] );
	}

	#[Test]
	public function a_missing_mode_becomes_truthy(): void {
		$flags = Agend_Directory_Sync_Field_Map::sanitize_flags( array() );

		$this->assertSame( Agend_Directory_Sync_Field_Map::FLAG_MODE_TRUTHY, $flags['eligible_flag_mode'] );
		$this->assertSame( Agend_Directory_Sync_Field_Map::FLAG_MODE_TRUTHY, $flags['opt_in_flag_mode'] );
	}

	#[Test]
	public function map_mode_is_accepted(): void {
		$flags = Agend_Directory_Sync_Field_Map::sanitize_flags(
			array( 'opt_in_flag_mode' => 'map' )
		);

		$this->assertSame( Agend_Directory_Sync_Field_Map::FLAG_MODE_MAP, $flags['opt_in_flag_mode'] );
	}

	#[Test]
	public function skip_as_a_row_outcome_becomes_draft(): void {
		$flags = Agend_Directory_Sync_Field_Map::sanitize_flags(
			array(
				'eligible_flag_map' => array(
					array( 'value' => 'Lapsed', 'outcome' => 'skip' ),
				),
			)
		);

		$this->assertSame( 'draft', $flags['eligible_flag_map'][0]['outcome'] );
	}

	#[Test]
	public function an_unknown_outcome_becomes_draft(): void {
		$flags = Agend_Directory_Sync_Field_Map::sanitize_flags(
			array(
				'eligible_flag_map_default' => 'nonsense',
				'eligible_flag_map'         => array(
					array( 'value' => 'Lapsed', 'outcome' => 'nonsense' ),
				),
			)
		);

		$this->assertSame( 'draft', $flags['eligible_flag_map_default'] );
		$this->assertSame( 'draft', $flags['eligible_flag_map'][0]['outcome'] );
	}

	#[Test]
	public function blank_rows_are_dropped(): void {
		$flags = Agend_Directory_Sync_Field_Map::sanitize_flags(
			array(
				'eligible_flag_map' => array(
					array( 'value' => '', 'outcome' => 'published' ),
					array( 'value' => '   ', 'outcome' => 'published' ),
					array( 'value' => 'Active', 'outcome' => 'published' ),
				),
			)
		);

		$this->assertCount( 1, $flags['eligible_flag_map'] );
		$this->assertSame( 'Active', $flags['eligible_flag_map'][0]['value'] );
	}

	#[Test]
	public function a_value_repeated_case_insensitively_keeps_the_last_row(): void {
		$flags = Agend_Directory_Sync_Field_Map::sanitize_flags(
			array(
				'eligible_flag_map' => array(
					array( 'value' => 'Active', 'outcome' => 'published' ),
					array( 'value' => 'ACTIVE', 'outcome' => 'draft' ),
				),
			)
		);

		$this->assertCount( 1, $flags['eligible_flag_map'] );
		$this->assertSame( 'draft', $flags['eligible_flag_map'][0]['outcome'] );
	}

	#[Test]
	public function the_map_is_capped_at_max_flag_map_rows(): void {
		$rows = array();
		for ( $i = 0; $i < Agend_Directory_Sync_Field_Map::MAX_FLAG_MAP_ROWS + 10; $i++ ) {
			$rows[] = array( 'value' => 'value-' . $i, 'outcome' => 'published' );
		}

		$flags = Agend_Directory_Sync_Field_Map::sanitize_flags( array( 'eligible_flag_map' => $rows ) );

		$this->assertCount( Agend_Directory_Sync_Field_Map::MAX_FLAG_MAP_ROWS, $flags['eligible_flag_map'] );
	}

	#[Test]
	public function an_old_field_map_without_the_new_keys_resolves_as_truthy_mode(): void {
		// Simulate an install saved before the flag key map feature existed:
		// only the invert keys are present.
		Agend_Test_WP::$options[ Agend_Directory_Sync_Field_Map::OPTION_FIELD_MAP ] = array(
			'core'          => array( 'external_id' => 'id' ),
			'custom_fields' => array(),
			'locations'     => Agend_Directory_Sync_Field_Map::default_locations(),
			'flags'         => array(
				'eligible_flag_invert' => false,
				'opt_in_flag_invert'   => true,
			),
		);

		$resolved = Agend_Directory_Sync_Field_Map::resolve();

		$this->assertSame( Agend_Directory_Sync_Field_Map::FLAG_MODE_TRUTHY, $resolved['flags']['eligible_flag_mode'] );
		$this->assertSame( Agend_Directory_Sync_Field_Map::FLAG_MODE_TRUTHY, $resolved['flags']['opt_in_flag_mode'] );
		$this->assertSame( array(), $resolved['flags']['eligible_flag_map'] );
		$this->assertTrue( $resolved['flags']['opt_in_flag_invert'] );
	}

	#[Test]
	public function offered_outcomes_excludes_skip_but_the_ranking_still_places_it_as_most_restrictive(): void {
		$this->assertNotContains( Agend_Directory_Sync_Field_Map::OUTCOME_SKIP, Agend_Directory_Sync_Field_Map::OFFERED_OUTCOMES );
		$this->assertContains( Agend_Directory_Sync_Field_Map::OUTCOME_PUBLISHED, Agend_Directory_Sync_Field_Map::OFFERED_OUTCOMES );
		$this->assertContains( Agend_Directory_Sync_Field_Map::OUTCOME_DRAFT, Agend_Directory_Sync_Field_Map::OFFERED_OUTCOMES );

		$ranking = Agend_Directory_Sync_Field_Map::OUTCOME_RANKING;
		$this->assertSame( end( $ranking ), Agend_Directory_Sync_Field_Map::OUTCOME_SKIP );

		$published_index = array_search( Agend_Directory_Sync_Field_Map::OUTCOME_PUBLISHED, $ranking, true );
		$draft_index      = array_search( Agend_Directory_Sync_Field_Map::OUTCOME_DRAFT, $ranking, true );
		$skip_index       = array_search( Agend_Directory_Sync_Field_Map::OUTCOME_SKIP, $ranking, true );

		$this->assertLessThan( $draft_index, $published_index );
		$this->assertLessThan( $skip_index, $draft_index );
	}
}
