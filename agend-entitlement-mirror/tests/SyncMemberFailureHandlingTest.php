<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Sync;
use Agend_Test_Mirror_Gateway;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-mirror-settings.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-collector.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-sync.php';

/**
 * `Agend_Entitlement_Sync`'s gateway write-failure handling: a transient
 * failure (5xx / network) gets exactly one WP-Cron retry, while a 401/403 is
 * treated as a configuration error and is never retried against the same
 * broken credential.
 *
 * SPEC-CRM-20260805-member-entitlement-grants v1.2 US-5.1 AC7.
 */
#[CoversClass( Agend_Entitlement_Sync::class )]
final class SyncMemberFailureHandlingTest extends TestCase {

	private const MEMBER_ID = 'MEM-400';

	protected function setUp(): void {
		parent::setUp();
		Agend_Test_WP::$options['agend_entitlement_mirror_categories'] = 'Membership';
		Agend_Test_WP::set_filter( 'agend_entitlement_mirror_raw_entitlements', array() );
		Agend_Test_Mirror_Gateway::$get_contacts_response = array(
			'data' => array( array( 'id' => 'contact-existing' ) ),
		);
	}

	#[Test]
	public function a_500_response_schedules_exactly_one_retry(): void {
		Agend_Test_Mirror_Gateway::$reconcile_response = new WP_Error(
			'agend_apps_http_error',
			'Internal Server Error',
			array( 'status_code' => 500 )
		);

		$result = Agend_Entitlement_Sync::sync_member( self::MEMBER_ID );

		$this->assertFalse( $result );
		$this->assertCount( 1, Agend_Test_WP::$scheduled_events );
		$this->assertSame( Agend_Entitlement_Sync::RETRY_HOOK, Agend_Test_WP::$scheduled_events[0]['hook'] );
		$this->assertSame( array( self::MEMBER_ID ), Agend_Test_WP::$scheduled_events[0]['args'] );
	}

	#[Test]
	public function a_500_response_records_a_transient_last_error(): void {
		Agend_Test_Mirror_Gateway::$reconcile_response = new WP_Error(
			'agend_apps_http_error',
			'Internal Server Error',
			array( 'status_code' => 500 )
		);

		Agend_Entitlement_Sync::sync_member( self::MEMBER_ID );

		$last_error = Agend_Test_WP::$options[ Agend_Entitlement_Sync::LAST_ERROR_OPTION ];

		$this->assertSame( 'transient', $last_error['kind'] );
		$this->assertSame( 500, $last_error['status_code'] );
	}

	#[Test]
	public function a_403_response_schedules_no_retry(): void {
		Agend_Test_Mirror_Gateway::$reconcile_response = new WP_Error(
			'agend_apps_http_error',
			'Forbidden',
			array( 'status_code' => 403 )
		);

		$result = Agend_Entitlement_Sync::sync_member( self::MEMBER_ID );

		$this->assertFalse( $result );
		$this->assertCount( 0, Agend_Test_WP::$scheduled_events, 'a configuration error must never be retried against the same credential' );
	}

	#[Test]
	public function a_403_response_records_a_configuration_last_error(): void {
		Agend_Test_Mirror_Gateway::$reconcile_response = new WP_Error(
			'agend_apps_http_error',
			'Forbidden',
			array( 'status_code' => 403 )
		);

		Agend_Entitlement_Sync::sync_member( self::MEMBER_ID );

		$last_error = Agend_Test_WP::$options[ Agend_Entitlement_Sync::LAST_ERROR_OPTION ];

		$this->assertSame( 'configuration', $last_error['kind'] );
		$this->assertSame( 403, $last_error['status_code'] );
	}

	#[Test]
	public function a_401_response_is_also_treated_as_configuration_and_not_retried(): void {
		Agend_Test_Mirror_Gateway::$reconcile_response = new WP_Error(
			'agend_apps_http_error',
			'Unauthorized',
			array( 'status_code' => 401 )
		);

		Agend_Entitlement_Sync::sync_member( self::MEMBER_ID );

		$last_error = Agend_Test_WP::$options[ Agend_Entitlement_Sync::LAST_ERROR_OPTION ];

		$this->assertSame( 'configuration', $last_error['kind'] );
		$this->assertCount( 0, Agend_Test_WP::$scheduled_events );
	}

	#[Test]
	public function a_second_failure_within_the_retry_window_does_not_schedule_a_duplicate_retry(): void {
		Agend_Test_Mirror_Gateway::$reconcile_response = new WP_Error(
			'agend_apps_http_error',
			'Internal Server Error',
			array( 'status_code' => 500 )
		);

		// The coalesce lock would normally skip a second sync_member() call for
		// the same member within its window; call handle_retry() directly (the
		// WP-Cron callback) to exercise wp_next_scheduled()'s de-duplication in
		// isolation from that lock.
		Agend_Entitlement_Sync::handle_retry( self::MEMBER_ID );
		Agend_Test_WP::$transients = array(); // Clear the coalesce lock the first call set.
		Agend_Entitlement_Sync::handle_retry( self::MEMBER_ID );

		$this->assertCount( 1, Agend_Test_WP::$scheduled_events, 'a retry already scheduled for this member must not be duplicated' );
	}
}
