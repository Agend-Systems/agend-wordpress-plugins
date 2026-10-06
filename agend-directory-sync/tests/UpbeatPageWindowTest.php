<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync;
use Agend_Directory_Sync_Upbeat_Client;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/agend-directory-sync.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-config.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-upbeat-client.php';

/**
 * The Upbeat page window (page size, start page, max pages): which pages are
 * requested, when a run stops and says so, and that a broken page fails the
 * run instead of returning a short set.
 */
#[CoversClass( Agend_Directory_Sync_Upbeat_Client::class )]
final class UpbeatPageWindowTest extends TestCase {

	/**
	 * Pages of `$per_page` rows; every page but the last reports MoreRecords.
	 *
	 * @return object Scripted API double recording the URLs it was asked for.
	 */
	private function api( int $total_pages, int $per_page = 2, ?int $drop_results_on = null ): object {
		return new class( $total_pages, $per_page, $drop_results_on ) {
			/** @var array<int, string> */
			public array $urls = array();

			private int $total;
			private int $per_page;
			private ?int $drop_on;

			public function __construct( int $total, int $per_page, ?int $drop_on ) {
				$this->total    = $total;
				$this->per_page = $per_page;
				$this->drop_on  = $drop_on;
			}

			public function get_raw_json( string $url ): string {
				$this->urls[] = $url;
				parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
				$page = (int) $query['pageNumber'];

				if ( $this->drop_on === $page ) {
					return (string) json_encode( array( 'ResponseData' => array( 'MoreRecords' => false ) ) );
				}

				if ( $page > $this->total ) {
					return (string) json_encode( array( 'ResponseData' => array( 'Results' => array(), 'MoreRecords' => false ) ) );
				}

				$rows = array();
				for ( $i = 1; $i <= $this->per_page; $i++ ) {
					$rows[] = array( 'UniqueId' => $page . '-' . $i );
				}

				return (string) json_encode(
					array( 'ResponseData' => array( 'Results' => $rows, 'MoreRecords' => $page < $this->total ) )
				);
			}
		};
	}

	private function client( object $api ): Agend_Directory_Sync_Upbeat_Client {
		return ( new Agend_Directory_Sync_Upbeat_Client() )->use_api( $api );
	}

	private function pageNumbers( object $api ): array {
		return array_map(
			static function ( string $url ): int {
				parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
				return (int) $query['pageNumber'];
			},
			$api->urls
		);
	}

	private function setPaging( array $paging ): void {
		update_option( Agend_Directory_Sync::OPTION_UPBEAT_PAGING, $paging );
	}

	#[Test]
	public function by_default_it_fetches_every_page_at_a_page_size_of_one_thousand(): void {
		$api  = $this->api( 3 );
		$rows = $this->client( $api )->fetch_all();

		$this->assertCount( 6, $rows );
		$this->assertSame( array( 1, 2, 3 ), $this->pageNumbers( $api ) );
		$this->assertStringContainsString( 'pageCount=1000', $api->urls[0] );
		$this->assertStringContainsString( 'membershipDirectoryContacts', $api->urls[0] );
	}

	#[Test]
	public function a_complete_fetch_is_not_flagged_as_truncated(): void {
		$client = $this->client( $this->api( 3 ) );
		$client->fetch_all();

		$this->assertFalse( $client->stopped_at_page_limit() );
		$this->assertSame( 3, $client->get_pages_fetched() );
	}

	#[Test]
	public function max_pages_limits_the_run_and_flags_that_more_records_remained(): void {
		$this->setPaging( array( 'max_pages' => 2 ) );
		$api    = $this->api( 5 );
		$client = $this->client( $api );

		$rows = $client->fetch_all();

		$this->assertCount( 4, $rows );
		$this->assertSame( array( 1, 2 ), $this->pageNumbers( $api ) );
		$this->assertTrue( $client->stopped_at_page_limit() );
		$this->assertSame( 2, $client->get_pages_fetched() );
	}

	#[Test]
	public function a_window_that_ends_exactly_where_the_data_ends_is_not_flagged(): void {
		$this->setPaging( array( 'max_pages' => 3 ) );
		$client = $this->client( $this->api( 3 ) );

		$client->fetch_all();

		$this->assertFalse( $client->stopped_at_page_limit() );
	}

	#[Test]
	public function start_page_moves_the_window(): void {
		$this->setPaging( array( 'start_page' => 3, 'max_pages' => 1 ) );
		$api    = $this->api( 5 );
		$client = $this->client( $api );

		$rows = $client->fetch_all();

		$this->assertSame( array( 3 ), $this->pageNumbers( $api ) );
		$this->assertSame( '3-1', $rows[0]['UniqueId'] );
		$this->assertTrue( $client->stopped_at_page_limit() );
	}

	#[Test]
	public function the_configured_page_size_is_sent(): void {
		$this->setPaging( array( 'page_size' => 250 ) );
		$api = $this->api( 1 );

		$this->client( $api )->fetch_all();

		$this->assertStringContainsString( 'pageCount=250', $api->urls[0] );
	}

	#[Test]
	public function a_start_page_past_the_end_returns_nothing_without_error(): void {
		$this->setPaging( array( 'start_page' => 9 ) );
		$client = $this->client( $this->api( 3 ) );

		$this->assertSame( array(), $client->fetch_all() );
		$this->assertFalse( $client->stopped_at_page_limit() );
	}

	#[Test]
	public function a_first_page_with_no_results_is_an_empty_directory_not_an_error(): void {
		$client = $this->client( $this->api( 3, 2, 1 ) );

		$this->assertSame( array(), $client->fetch_all() );
	}

	#[Test]
	public function a_later_page_with_no_results_after_a_page_promising_more_fails_the_run(): void {
		$client = $this->client( $this->api( 4, 2, 3 ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'page 3 had no Results' );
		$client->fetch_all();
	}

	#[Test]
	public function a_failed_page_request_names_the_page(): void {
		$api = new class() {
			public function get_raw_json( string $url ): string {
				throw new \Exception( 'boom' );
			}
		};

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'page 1: boom' );
		$this->client( $api )->fetch_all();
	}

	#[Test]
	public function invalid_json_fails_the_run(): void {
		$api = new class() {
			public function get_raw_json( string $url ): string {
				return '<html>gateway error</html>';
			}
		};

		$this->expectException( RuntimeException::class );
		$this->client( $api )->fetch_all();
	}

	#[Test]
	public function the_hard_page_cap_aborts_instead_of_returning_a_truncated_set(): void {
		$api = new class() {
			public function get_raw_json( string $url ): string {
				return (string) json_encode( array( 'ResponseData' => array( 'Results' => array( array( 'UniqueId' => 'x' ) ), 'MoreRecords' => true ) ) );
			}
		};

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'safety cap' );
		$this->client( $api )->fetch_all();
	}

	#[Test]
	public function settings_are_clamped(): void {
		$this->setPaging( array( 'page_size' => 99999, 'start_page' => -4, 'max_pages' => 99999 ) );

		$this->assertSame(
			array( 'page_size' => 1000, 'start_page' => 1, 'max_pages' => 500 ),
			Agend_Directory_Sync_Upbeat_Client::paging_settings()
		);
	}

	#[Test]
	public function unset_or_garbage_settings_use_the_defaults(): void {
		$this->assertSame(
			array( 'page_size' => 1000, 'start_page' => 1, 'max_pages' => 0 ),
			Agend_Directory_Sync_Upbeat_Client::paging_settings()
		);

		update_option( Agend_Directory_Sync::OPTION_UPBEAT_PAGING, 'junk' );
		$this->assertSame( 1000, Agend_Directory_Sync_Upbeat_Client::paging_settings()['page_size'] );
	}

	#[Test]
	public function the_runner_visible_accessors_reset_between_fetches(): void {
		$this->setPaging( array( 'max_pages' => 1 ) );
		$client = $this->client( $this->api( 3 ) );
		$client->fetch_all();
		$this->assertTrue( $client->stopped_at_page_limit() );

		$this->setPaging( array( 'max_pages' => 0 ) );
		$client->fetch_all();
		$this->assertFalse( $client->stopped_at_page_limit() );
		$this->assertSame( 3, $client->get_pages_fetched() );
	}
}
