<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Sync;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-mirror-settings.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-collector.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-sync.php';

/**
 * `Agend_Entitlement_Sync::wait_for_rate_limit_window()`,
 * `Agend_Entitlement_Sync::pace()` and `Agend_Entitlement_Sync::is_rate_limited_error()`:
 * the CLI sweep's pacing against the gateway's 60 requests/minute limit.
 *
 * Every test intercepts `agend_entitlement_mirror_sleeper` so the suite never
 * blocks on a real sleep()/usleep().
 */
#[CoversClass( Agend_Entitlement_Sync::class )]
final class RateLimitPacingTest extends TestCase {

	/** @var array<int, float> Seconds passed to every intercepted sleep call. */
	private array $slept = array();

	protected function setUp(): void {
		parent::setUp();
		$this->slept = array();

		Agend_Test_WP::$filters['agend_entitlement_mirror_sleeper'] = function () {
			return function ( float $seconds ): void {
				$this->slept[] = $seconds;
			};
		};
	}

	#[Test]
	public function returns_zero_and_never_sleeps_when_no_rate_limit_transients_are_set(): void {
		$waited = Agend_Entitlement_Sync::wait_for_rate_limit_window();

		$this->assertSame( 0, $waited );
		$this->assertSame( array(), $this->slept );
	}

	#[Test]
	public function returns_zero_when_remaining_is_comfortably_above_the_floor(): void {
		Agend_Test_WP::$transients['agend_apps_rate_limit_remaining'] = 40;
		Agend_Test_WP::$transients['agend_apps_rate_limit_reset']     = time() + 30;

		$waited = Agend_Entitlement_Sync::wait_for_rate_limit_window();

		$this->assertSame( 0, $waited );
		$this->assertSame( array(), $this->slept );
	}

	#[Test]
	public function sleeps_until_just_past_reset_when_remaining_is_at_the_floor(): void {
		Agend_Test_WP::$transients['agend_apps_rate_limit_remaining'] = 1;
		Agend_Test_WP::$transients['agend_apps_rate_limit_reset']     = time() + 10;

		$waited = Agend_Entitlement_Sync::wait_for_rate_limit_window();

		// Allow +/-1s of wall-clock slack between the transient being set and
		// time() being read again inside the method.
		$this->assertGreaterThanOrEqual( 10, $waited );
		$this->assertLessThanOrEqual( 11, $waited );
		$this->assertCount( 1, $this->slept );
		$this->assertSame( (float) $waited, $this->slept[0] );
	}

	#[Test]
	public function respects_a_custom_floor_filter(): void {
		Agend_Test_WP::$transients['agend_apps_rate_limit_remaining'] = 3;
		Agend_Test_WP::$transients['agend_apps_rate_limit_reset']     = time() + 5;

		// Wrap the existing sleeper filter registration; the floor filter is a
		// separate hook so this does not disturb it.
		Agend_Test_WP::$filters['agend_entitlement_mirror_rate_limit_floor'] = static function () {
			return 5;
		};

		$waited = Agend_Entitlement_Sync::wait_for_rate_limit_window();

		$this->assertGreaterThan( 0, $waited, 'remaining (3) at or below the filtered floor (5) must trigger a wait' );
	}

	#[Test]
	public function returns_zero_when_the_reset_time_has_already_passed(): void {
		Agend_Test_WP::$transients['agend_apps_rate_limit_remaining'] = 0;
		Agend_Test_WP::$transients['agend_apps_rate_limit_reset']     = time() - 5;

		$waited = Agend_Entitlement_Sync::wait_for_rate_limit_window();

		$this->assertSame( 0, $waited );
		$this->assertSame( array(), $this->slept );
	}

	#[Test]
	public function the_wait_is_capped_at_120_seconds(): void {
		Agend_Test_WP::$transients['agend_apps_rate_limit_remaining'] = 0;
		Agend_Test_WP::$transients['agend_apps_rate_limit_reset']     = time() + 600;

		$waited = Agend_Entitlement_Sync::wait_for_rate_limit_window();

		$this->assertSame( 120, $waited );
	}

	#[Test]
	public function pace_is_a_no_op_for_a_non_positive_duration(): void {
		Agend_Entitlement_Sync::pace( 0.0 );
		Agend_Entitlement_Sync::pace( -1.0 );

		$this->assertSame( array(), $this->slept );
	}

	#[Test]
	public function pace_routes_through_the_sleeper_filter(): void {
		Agend_Entitlement_Sync::pace( 0.25 );

		$this->assertSame( array( 0.25 ), $this->slept );
	}

	#[Test]
	public function detects_the_local_rate_limited_short_circuit_by_code(): void {
		$error = new WP_Error( 'agend_apps_rate_limited', 'Agend API rate limit exceeded.', array( 'status_code' => 429 ) );

		$this->assertTrue( Agend_Entitlement_Sync::is_rate_limited_error( $error ) );
	}

	#[Test]
	public function detects_a_live_429_by_status_code_regardless_of_error_code(): void {
		$error = new WP_Error( 'agend_apps_http_error', 'Too Many Requests', array( 'status_code' => 429 ) );

		$this->assertTrue( Agend_Entitlement_Sync::is_rate_limited_error( $error ) );
	}

	#[Test]
	public function a_non_429_error_is_not_rate_limited(): void {
		$error = new WP_Error( 'agend_apps_http_error', 'Internal Server Error', array( 'status_code' => 500 ) );

		$this->assertFalse( Agend_Entitlement_Sync::is_rate_limited_error( $error ) );
	}

	#[Test]
	public function a_non_error_result_is_never_rate_limited(): void {
		$this->assertFalse( Agend_Entitlement_Sync::is_rate_limited_error( true ) );
		$this->assertFalse( Agend_Entitlement_Sync::is_rate_limited_error( array( 'data' => array() ) ) );
	}
}
