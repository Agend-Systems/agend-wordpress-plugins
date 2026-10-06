<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync;
use Agend_Directory_Sync_Agend_Client;
use Agend_Test_Directory_Bulk_Upsert;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/agend-directory-sync.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-agend-client.php';

/**
 * The Locations mode setting: how the gateway applies each listing's
 * locations[], defaulting to replace.
 */
#[CoversClass( Agend_Directory_Sync_Agend_Client::class )]
final class LocationsModeSettingTest extends TestCase {

	#[Test]
	public function it_defaults_to_replace_when_unset(): void {
		$this->assertSame( 'replace', Agend_Directory_Sync_Agend_Client::locations_mode() );
	}

	#[Test]
	public function it_reads_a_saved_merge(): void {
		Agend_Test_WP::$options[ Agend_Directory_Sync::OPTION_LOCATIONS_MODE ] = 'merge';

		$this->assertSame( 'merge', Agend_Directory_Sync_Agend_Client::locations_mode() );
	}

	#[Test]
	public function an_unknown_stored_value_falls_back_to_replace(): void {
		Agend_Test_WP::$options[ Agend_Directory_Sync::OPTION_LOCATIONS_MODE ] = 'wipe-everything';

		$this->assertSame( 'replace', Agend_Directory_Sync_Agend_Client::locations_mode() );
	}

	#[Test]
	public function sanitising_is_case_and_whitespace_insensitive_and_rejects_non_strings(): void {
		$this->assertSame( 'merge', Agend_Directory_Sync_Agend_Client::sanitize_locations_mode( ' Merge ' ) );
		$this->assertSame( 'replace', Agend_Directory_Sync_Agend_Client::sanitize_locations_mode( array( 'merge' ) ) );
		$this->assertSame( 'replace', Agend_Directory_Sync_Agend_Client::sanitize_locations_mode( null ) );
	}

	#[Test]
	public function the_configured_mode_reaches_the_bulk_upsert_request(): void {
		Agend_Test_WP::$options[ Agend_Directory_Sync::OPTION_LOCATIONS_MODE ] = 'merge';

		( new Agend_Directory_Sync_Agend_Client() )->send_listings( array( array( 'external_id' => 'ext-1' ) ), 'test-source' );

		$this->assertSame( 'merge', Agend_Test_Directory_Bulk_Upsert::$calls[0]['args']['body']['locations_mode'] );
	}

	#[Test]
	public function the_default_mode_reaches_the_bulk_upsert_request(): void {
		( new Agend_Directory_Sync_Agend_Client() )->send_listings( array( array( 'external_id' => 'ext-1' ) ), 'test-source' );

		$this->assertSame( 'replace', Agend_Test_Directory_Bulk_Upsert::$calls[0]['args']['body']['locations_mode'] );
	}
}
