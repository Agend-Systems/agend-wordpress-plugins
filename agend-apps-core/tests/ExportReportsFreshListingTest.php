<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-export-reports.php';

/**
 * `agend_apps_directory_get_export_reports( true )` is what the export
 * report widget calls at the moment of a click, so its parameters are
 * matched against the report's current declaration rather than a listing an
 * author edited after the transient was warmed (the stale-parameters
 * defect this fixes).
 *
 * The cached path (`$fresh` omitted or false) is unchanged and covered here
 * only as a contrast: a fresh call must still make the live gateway request
 * even when a cached entry exists, and must leave that entry holding the
 * new answer for the next cached read.
 */
final class ExportReportsFreshListingTest extends TestCase {

	private const CACHED_LISTING = array(
		'success' => true,
		'data'    => array(
			array( 'id' => 'rep-a', 'name' => 'Stale name', 'parameters' => array() ),
		),
	);

	private const LIVE_LISTING = array(
		'success' => true,
		'data'    => array(
			array(
				'id'         => 'rep-a',
				'name'       => 'Current name',
				'parameters' => array(
					array( 'name' => 'cond-uuid-2', 'field' => 'region' ),
				),
			),
		),
	);

	protected function setUp(): void {
		parent::setUp();

		// The feature is scope-gated (agend_apps_records_optional_features()):
		// without the browse scope on record, agend_apps_directory_get_export_reports()
		// short-circuits to an empty list before ever touching get_cached().
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'directory.export_reports.browse' ) ) ) );
		\Agend_Apps_Key_Scopes::refresh();
		Agend_Test_WP::$requests = array();
	}

	/**
	 * @return string[] URLs of the export-report list requests made so far.
	 */
	private function listing_requests(): array {
		$urls = array();
		foreach ( Agend_Test_WP::$requests as $request ) {
			if ( false !== strpos( $request['url'], '/directory/export-reports' ) ) {
				$urls[] = $request['url'];
			}
		}
		return $urls;
	}

	#[Test]
	public function fresh_false_serves_the_cached_transient_without_a_request(): void {
		Agend_Test_WP::$transients['agend_apps_directory_export_reports'] = self::CACHED_LISTING;

		$result = agend_apps_directory_get_export_reports();

		$this->assertSame( array(), $this->listing_requests(), 'a cached hit must not touch the network' );
		$this->assertSame( self::CACHED_LISTING, $result );
	}

	#[Test]
	public function fresh_true_calls_the_gateway_even_when_a_transient_exists(): void {
		Agend_Test_WP::$transients['agend_apps_directory_export_reports'] = self::CACHED_LISTING;
		Agend_Test_WP::queue_response( 200, self::LIVE_LISTING + array( 'status_code' => 200 ) );

		$result = agend_apps_directory_get_export_reports( true );

		$this->assertCount( 1, $this->listing_requests(), 'fresh=true must bypass the cached transient and call the gateway' );
		$this->assertSame( self::LIVE_LISTING + array( 'status_code' => 200 ), $result );
	}

	#[Test]
	public function fresh_true_overwrites_the_transient_with_the_new_body(): void {
		Agend_Test_WP::$transients['agend_apps_directory_export_reports'] = self::CACHED_LISTING;
		Agend_Test_WP::queue_response( 200, self::LIVE_LISTING + array( 'status_code' => 200 ) );

		agend_apps_directory_get_export_reports( true );

		$this->assertSame(
			self::LIVE_LISTING + array( 'status_code' => 200 ),
			Agend_Test_WP::$transients['agend_apps_directory_export_reports'],
			'a later cached read (e.g. the dropdown label fill-in) must see the current definition too'
		);
	}
}
