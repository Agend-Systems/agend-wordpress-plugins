<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Log_Store;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';

#[CoversClass( Agend_Apps_Log_Store::class )]
final class ApiLogStoreTest extends TestCase {

	#[Test]
	public function should_write_every_row_in_one_insert_when_flushing_a_batch(): void {
		$GLOBALS['wpdb']->query_results = array( 2 );

		$ok = ( new Agend_Apps_Log_Store() )->insert_many(
			array(
				array( 'method' => 'GET', 'path' => '/v1/events', 'status' => 200 ),
				array( 'method' => 'GET', 'path' => '/v1/crm/me', 'status' => null ),
			)
		);

		$this->assertTrue( $ok );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$sql = $GLOBALS['wpdb']->queries[0];
		$this->assertStringStartsWith( 'INSERT INTO wp_agend_apps_api_log (created_at, request_id,', $sql );
		$this->assertSame( 2, substr_count( $sql, "'GET'" ) );
		$this->assertStringContainsString( 'NULL', $sql, 'a missing status is stored as NULL, not 0' );
	}

	#[Test]
	public function should_prune_in_bounded_batches_by_created_at_until_a_short_batch(): void {
		$GLOBALS['wpdb']->query_results = array( Agend_Apps_Log_Store::PRUNE_BATCH, Agend_Apps_Log_Store::PRUNE_BATCH, 12 );

		$deleted = ( new Agend_Apps_Log_Store() )->prune( 30 );

		$this->assertSame( 2 * Agend_Apps_Log_Store::PRUNE_BATCH + 12, $deleted );
		$this->assertCount( 3, $GLOBALS['wpdb']->queries );
		$this->assertMatchesRegularExpression(
			"/^DELETE FROM wp_agend_apps_api_log WHERE created_at < '\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}' ORDER BY created_at ASC LIMIT 5000$/",
			$GLOBALS['wpdb']->queries[0]
		);
	}

	#[Test]
	public function should_stop_pruning_when_a_delete_fails(): void {
		$GLOBALS['wpdb']->query_results = array( false );

		$this->assertSame( 0, ( new Agend_Apps_Log_Store() )->prune( 30 ) );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
	}

	#[Test]
	public function should_fetch_one_extra_row_instead_of_counting_when_paging(): void {
		$GLOBALS['wpdb']->results = array_fill( 0, 3, array( 'id' => 1 ) );

		$result = ( new Agend_Apps_Log_Store() )->query( array( 'status_class' => '5xx', 'path' => '/v1/crm' ), 2, 1 );

		$this->assertCount( 2, $result['rows'] );
		$this->assertTrue( $result['has_more'] );
		$sql = $GLOBALS['wpdb']->queries[0];
		$this->assertStringNotContainsString( 'COUNT(', $sql );
		$this->assertStringContainsString( 'status BETWEEN 500 AND 599', $sql );
		$this->assertStringContainsString( "path_template LIKE '/v1/crm%'", $sql );
		$this->assertStringEndsWith( 'ORDER BY id DESC LIMIT 3 OFFSET 0', $sql );
	}
}
