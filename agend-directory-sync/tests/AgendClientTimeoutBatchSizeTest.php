<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync;
use Agend_Directory_Sync_Agend_Client;
use Agend_Test_Directory_Bulk_Upsert;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/agend-directory-sync.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-agend-client.php';

/**
 * The batch-size and timeout settings a hosted "Send to Agend" run reads, and
 * effective_timeout()'s pure arithmetic for fitting a batch's own gateway
 * request inside whatever execution-time budget the current request has.
 */
#[CoversClass( Agend_Directory_Sync_Agend_Client::class )]
final class AgendClientTimeoutBatchSizeTest extends TestCase {

	#[Test]
	public function effective_timeout_uses_the_full_setting_when_execution_time_is_unlimited(): void {
		$this->assertSame( 60, Agend_Directory_Sync_Agend_Client::effective_timeout( 60, 0 ) );
	}

	#[Test]
	public function effective_timeout_leaves_five_seconds_of_the_execution_budget_free(): void {
		// max_execution_time 30, minus the 5-second margin, is less than the
		// 60-second setting, so the smaller figure wins.
		$this->assertSame( 25, Agend_Directory_Sync_Agend_Client::effective_timeout( 60, 30 ) );
	}

	#[Test]
	public function effective_timeout_uses_the_setting_when_the_execution_budget_easily_covers_it(): void {
		$this->assertSame( 60, Agend_Directory_Sync_Agend_Client::effective_timeout( 60, 120 ) );
	}

	#[Test]
	public function effective_timeout_never_drops_below_ten_seconds(): void {
		// max_execution_time 10, minus 5, is 5 -- below the 10-second floor.
		$this->assertSame( 10, Agend_Directory_Sync_Agend_Client::effective_timeout( 60, 10 ) );
	}

	#[Test]
	public function batch_size_defaults_when_unset(): void {
		delete_option( Agend_Directory_Sync::OPTION_BATCH_SIZE );

		$this->assertSame( Agend_Directory_Sync_Agend_Client::DEFAULT_BATCH_SIZE, Agend_Directory_Sync_Agend_Client::batch_size() );
	}

	#[Test]
	public function batch_size_is_clamped_to_the_gateway_cap(): void {
		update_option( Agend_Directory_Sync::OPTION_BATCH_SIZE, 500 );

		$this->assertSame( Agend_Directory_Sync_Agend_Client::MAX_BATCH_SIZE, Agend_Directory_Sync_Agend_Client::batch_size() );
	}

	#[Test]
	public function batch_size_is_clamped_to_at_least_one(): void {
		update_option( Agend_Directory_Sync::OPTION_BATCH_SIZE, 0 );

		$this->assertSame( Agend_Directory_Sync_Agend_Client::MIN_BATCH_SIZE, Agend_Directory_Sync_Agend_Client::batch_size() );
	}

	#[Test]
	public function timeout_seconds_is_clamped_between_fifteen_and_three_hundred(): void {
		update_option( Agend_Directory_Sync::OPTION_TIMEOUT_SECONDS, 5 );
		$this->assertSame( Agend_Directory_Sync_Agend_Client::MIN_TIMEOUT_SECONDS, Agend_Directory_Sync_Agend_Client::timeout_seconds() );

		update_option( Agend_Directory_Sync::OPTION_TIMEOUT_SECONDS, 999 );
		$this->assertSame( Agend_Directory_Sync_Agend_Client::MAX_TIMEOUT_SECONDS, Agend_Directory_Sync_Agend_Client::timeout_seconds() );
	}

	/**
	 * send_batch()'s timeout injection reaches the request args exactly the
	 * way agend-apps-core's export-reports authoring listing scopes its own
	 * one-off arg: add_filter() before the call, remove_filter() in a
	 * finally. A second, untimed call must not see a leftover timeout.
	 */
	#[Test]
	public function it_injects_the_timeout_for_one_call_and_removes_it_afterwards(): void {
		$client = new Agend_Directory_Sync_Agend_Client();

		$client->send_listings(
			array( array( 'external_id' => 'ext-1' ) ),
			'test-source',
			false,
			10,
			45
		);

		$this->assertSame( 45, Agend_Test_Directory_Bulk_Upsert::$calls[0]['args']['timeout'] );

		$client->send_listings(
			array( array( 'external_id' => 'ext-2' ) ),
			'test-source',
			false,
			10,
			null
		);

		$this->assertArrayNotHasKey( 'timeout', Agend_Test_Directory_Bulk_Upsert::$calls[1]['args'] );
	}

	#[Test]
	public function send_listings_chunks_by_the_given_batch_size_rather_than_the_gateway_cap(): void {
		$client   = new Agend_Directory_Sync_Agend_Client();
		$listings = array_fill( 0, 5, array( 'external_id' => 'x' ) );

		$client->send_listings( $listings, 'test-source', false, 2 );

		$this->assertCount( 3, Agend_Test_Directory_Bulk_Upsert::$calls );
		$this->assertCount( 2, Agend_Test_Directory_Bulk_Upsert::$calls[0]['listings'] );
		$this->assertCount( 2, Agend_Test_Directory_Bulk_Upsert::$calls[1]['listings'] );
		$this->assertCount( 1, Agend_Test_Directory_Bulk_Upsert::$calls[2]['listings'] );
	}
}
