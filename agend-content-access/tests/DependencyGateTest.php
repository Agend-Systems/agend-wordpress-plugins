<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Dependencies;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-dependencies.php';

/**
 * The plugin must register nothing until Core is present.
 *
 * From a visitor's side, "no restriction was registered" and "a restriction was
 * registered and satisfied" look identical, so a partially loaded
 * access-control plugin is worse than one that refuses to load. The gate is
 * all-or-nothing and says so in the admin.
 */
#[CoversClass( Agend_Content_Access_Dependencies::class )]
final class DependencyGateTest extends TestCase {

	#[Test]
	public function all_present_means_no_missing_dependencies(): void {
		$this->assertSame(
			array(),
			Agend_Content_Access_Dependencies::missing_from( array( 'strlen', 'count' ) )
		);
	}

	#[Test]
	public function an_absent_function_reports_core_as_missing(): void {
		$this->assertSame(
			array( 'Agend Apps Core' ),
			Agend_Content_Access_Dependencies::missing_from(
				array( 'agend_apps_definitely_not_a_real_function' )
			)
		);
	}

	/**
	 * The case a version check would have missed: Core is installed, but a
	 * consumed function has been renamed or dropped. Probing the actual call
	 * sites is what catches it.
	 */
	#[Test]
	public function a_partially_present_core_still_fails_the_gate(): void {
		$this->assertSame(
			array( 'Agend Apps Core' ),
			Agend_Content_Access_Dependencies::missing_from(
				array( 'strlen', 'agend_apps_renamed_since_last_release' )
			)
		);
	}

	#[Test]
	public function the_real_probe_names_the_functions_actually_consumed(): void {
		// Guards against the constant drifting away from the code. Each of these
		// is called somewhere in the plugin; if one is dropped from the probe,
		// a missing Core would stop being detected.
		$reflection = new \ReflectionClass( Agend_Content_Access_Dependencies::class );
		$required   = $reflection->getConstant( 'REQUIRED_CORE_FUNCTIONS' );

		$this->assertContains( 'agend_apps_api', $required );
		$this->assertContains( 'agend_apps_get_bearer_token', $required );
		$this->assertContains( 'agend_apps_crm_get_tiers', $required );
	}

	#[Test]
	public function the_notice_says_restrictions_are_not_being_applied(): void {
		// Naming the missing plugin is not enough: an administrator needs to
		// know the security consequence, not just the cause.
		$notice = $this->renderNoticeFor( array( 'Agend Apps Core' ) );

		$this->assertStringContainsString( 'Agend Apps Core', $notice );
		$this->assertStringContainsString( 'no content restrictions are being applied', $notice );
		$this->assertStringContainsString( 'notice-error', $notice );
	}

	#[Test]
	public function nothing_is_rendered_when_dependencies_are_met(): void {
		// The harness doubles satisfy the real probe, so the production entry
		// point should emit nothing at all.
		$this->assertTrue( Agend_Content_Access_Dependencies::are_met() );

		ob_start();
		Agend_Content_Access_Dependencies::render_notice();

		$this->assertSame( '', (string) ob_get_clean() );
		$this->assertSame( '', $this->renderNoticeFor( array() ) );
	}

	private function renderNoticeFor( array $missing ): string {
		ob_start();
		Agend_Content_Access_Dependencies::render_notice_for( $missing );
		return (string) ob_get_clean();
	}
}
