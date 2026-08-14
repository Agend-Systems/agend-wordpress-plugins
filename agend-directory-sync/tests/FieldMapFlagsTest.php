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
 * The `flags` section of the field map (SPEC-DIR-20260731 US-3.3):
 * `sanitize_flags()` and the `resolve()` round-trip, including the
 * back-compat case of an install saved before `flags` existed.
 */
#[CoversClass( Agend_Directory_Sync_Field_Map::class )]
final class FieldMapFlagsTest extends TestCase {

	#[Test]
	public function sanitize_flags_treats_checkbox_presence_as_true_and_absence_as_false(): void {
		$flags = Agend_Directory_Sync_Field_Map::sanitize_flags(
			array( 'eligible_flag_invert' => '1' )
		);

		$this->assertTrue( $flags['eligible_flag_invert'] );
		$this->assertFalse( $flags['opt_in_flag_invert'] );
	}

	#[Test]
	public function sanitize_flags_discards_unknown_keys(): void {
		$flags = Agend_Directory_Sync_Field_Map::sanitize_flags(
			array(
				'eligible_flag_invert' => '1',
				'some_unknown_flag'    => '1',
			)
		);

		$this->assertArrayNotHasKey( 'some_unknown_flag', $flags );
	}

	#[Test]
	public function save_then_resolve_round_trips_the_invert_settings(): void {
		Agend_Directory_Sync_Field_Map::save(
			array(),
			'',
			array(),
			array( 'opt_in_flag_invert' => '1' )
		);

		$resolved = Agend_Directory_Sync_Field_Map::resolve();

		$this->assertTrue( $resolved['flags']['opt_in_flag_invert'] );
		$this->assertFalse( $resolved['flags']['eligible_flag_invert'] );
	}

	#[Test]
	public function an_install_saved_before_flags_existed_resolves_to_non_inverted_defaults(): void {
		// Simulate a pre-US-3.3 saved option with no `flags` key at all.
		Agend_Test_WP::$options[ Agend_Directory_Sync_Field_Map::OPTION_FIELD_MAP ] = array(
			'core'          => array( 'external_id' => 'id' ),
			'custom_fields' => array(),
			'locations'     => Agend_Directory_Sync_Field_Map::default_locations(),
		);

		$resolved = Agend_Directory_Sync_Field_Map::resolve();

		$this->assertSame( Agend_Directory_Sync_Field_Map::default_flags(), $resolved['flags'] );
	}
}
