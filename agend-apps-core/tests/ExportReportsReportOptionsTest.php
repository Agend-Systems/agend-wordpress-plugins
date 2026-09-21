<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Key_Scopes;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-export-reports.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/export-reports.php';

/**
 * The editor's report dropdown asks the gateway in authoring mode
 * (`scope=all`, every published report with its audience) when the key
 * holds directory.listings.manage, and otherwise, or when the gateway
 * refuses, falls back to the audience-filtered default listing the download
 * path uses.
 */
final class ExportReportsReportOptionsTest extends TestCase {

	private const AUTHORING = array(
		'success' => true,
		'data'    => array(
			array( 'id' => 'rep-anyone', 'name' => 'Member directory', 'audience' => 'anonymous' ),
			array( 'id' => 'rep-members', 'name' => 'Committee contacts', 'audience' => 'members_only' ),
			array( 'id' => 'rep-restricted', 'name' => 'Regional roll', 'audience' => 'restricted' ),
		),
	);

	private const DEFAULT_LISTING = array(
		'success' => true,
		'data'    => array(
			array( 'id' => 'rep-anyone', 'name' => 'Member directory', 'audience' => 'anonymous' ),
		),
	);

	/**
	 * @param string[] $scopes
	 */
	private function set_held_scopes( array $scopes ): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => $scopes ) ) );
		Agend_Apps_Key_Scopes::refresh();
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
	public function should_request_every_published_report_when_the_key_holds_the_manage_scope(): void {
		$this->set_held_scopes( array( 'directory.export_reports.browse', 'directory.listings.manage' ) );
		Agend_Test_WP::queue_response( 200, self::AUTHORING );

		$options = agend_apps_records_export_reports_report_options();

		$urls = $this->listing_requests();
		$this->assertCount( 1, $urls );
		$this->assertStringContainsString( 'scope=all', $urls[0] );
		$this->assertSame(
			array(
				''               => 'Select a report',
				'rep-anyone'     => 'Member directory',
				'rep-members'    => 'Committee contacts (members only)',
				'rep-restricted' => 'Regional roll (restricted)',
			),
			$options
		);
	}

	#[Test]
	public function should_use_the_default_listing_when_the_key_lacks_the_manage_scope(): void {
		$this->set_held_scopes( array( 'directory.export_reports.browse' ) );
		Agend_Test_WP::queue_response( 200, self::DEFAULT_LISTING );

		$options = agend_apps_records_export_reports_report_options();

		$urls = $this->listing_requests();
		$this->assertCount( 1, $urls );
		$this->assertStringNotContainsString( 'scope=', $urls[0] );
		$this->assertSame( array( '' => 'Select a report', 'rep-anyone' => 'Member directory' ), $options );
	}

	#[Test]
	public function should_fall_back_to_the_default_listing_when_the_gateway_refuses_the_scope(): void {
		$this->set_held_scopes( array( 'directory.export_reports.browse', 'directory.listings.manage' ) );
		Agend_Test_WP::queue_response( 403, array( 'error' => 'forbidden' ) );
		Agend_Test_WP::queue_response( 200, self::DEFAULT_LISTING );

		$options = agend_apps_records_export_reports_report_options();

		$urls = $this->listing_requests();
		$this->assertCount( 2, $urls );
		$this->assertStringContainsString( 'scope=all', $urls[0] );
		$this->assertStringNotContainsString( 'scope=', $urls[1] );
		$this->assertSame( array( '' => 'Select a report', 'rep-anyone' => 'Member directory' ), $options );
	}

	#[Test]
	public function should_fall_back_to_the_default_listing_on_a_gateway_that_does_not_know_scope(): void {
		$this->set_held_scopes( array( 'directory.export_reports.browse', 'directory.listings.manage' ) );
		Agend_Test_WP::queue_response( 422, array( 'error' => 'unprocessable' ) );
		Agend_Test_WP::queue_response( 200, self::DEFAULT_LISTING );

		$options = agend_apps_records_export_reports_report_options();

		$this->assertCount( 2, $this->listing_requests() );
		$this->assertSame( array( '' => 'Select a report', 'rep-anyone' => 'Member directory' ), $options );
	}

	#[Test]
	public function should_not_retry_on_an_unrelated_gateway_error(): void {
		$this->set_held_scopes( array( 'directory.export_reports.browse', 'directory.listings.manage' ) );
		Agend_Test_WP::queue_response( 500, array( 'error' => 'boom' ) );

		$options = agend_apps_records_export_reports_report_options();

		$this->assertCount( 1, $this->listing_requests() );
		$this->assertSame( array( '' => 'Select a report' ), $options );
	}

	#[Test]
	public function should_cache_the_authoring_and_default_listings_separately(): void {
		$this->set_held_scopes( array( 'directory.export_reports.browse', 'directory.listings.manage' ) );
		Agend_Test_WP::queue_response( 200, self::AUTHORING );
		Agend_Test_WP::queue_response( 200, self::DEFAULT_LISTING );

		$authoring = agend_apps_records_export_reports_report_options();
		$visitor   = agend_apps_directory_get_export_reports();

		$this->assertCount( 2, $this->listing_requests(), 'the visitor listing must not be served from the authoring transient' );
		$this->assertArrayHasKey( 'rep-members', $authoring );
		$this->assertCount( 1, $visitor['data'] );
		$this->assertSame( 'rep-anyone', $visitor['data'][0]['id'] );
	}

	#[Test]
	public function should_leave_the_request_args_filter_clear_after_the_authoring_call(): void {
		$this->set_held_scopes( array( 'directory.export_reports.browse', 'directory.listings.manage' ) );
		Agend_Test_WP::queue_response( 200, self::AUTHORING );

		agend_apps_records_export_reports_report_options();

		$this->assertArrayNotHasKey( 'agend_apps_directory_get_export_reports_args', Agend_Test_WP::$filters );
	}

	#[Test]
	public function should_label_only_reports_not_open_to_everyone(): void {
		$this->assertSame( 'Roll', agend_apps_records_export_reports_option_label( 'Roll', 'anonymous' ) );
		$this->assertSame( 'Roll', agend_apps_records_export_reports_option_label( 'Roll', '' ) );
		$this->assertSame( 'Roll (members only)', agend_apps_records_export_reports_option_label( 'Roll', 'members_only' ) );
		$this->assertSame( 'Roll (restricted)', agend_apps_records_export_reports_option_label( 'Roll', 'restricted' ) );
	}
}
