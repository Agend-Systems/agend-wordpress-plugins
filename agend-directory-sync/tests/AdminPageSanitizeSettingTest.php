<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Admin_Page;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-admin-page.php';

/**
 * handle_save_settings()'s numeric-setting sanitize path, extracted into
 * Agend_Directory_Sync_Admin_Page::sanitize_clamped_setting() so it is
 * directly testable: the admin-post handler itself ends in `exit`, which
 * would terminate the test process if called in-process.
 */
#[CoversClass( Agend_Directory_Sync_Admin_Page::class )]
final class AdminPageSanitizeSettingTest extends TestCase {

	#[Test]
	public function a_missing_value_is_the_default(): void {
		$this->assertSame(
			25,
			Agend_Directory_Sync_Admin_Page::sanitize_clamped_setting( null, 1, 100, 25 )
		);
	}

	/**
	 * A cleared number input posts an empty string, not an absent field --
	 * this must not become (int) '' === 0 clamped up to the minimum, i.e.
	 * "as small as this setting can possibly be".
	 */
	#[Test]
	public function a_blank_value_is_the_default_not_the_minimum(): void {
		$this->assertSame(
			25,
			Agend_Directory_Sync_Admin_Page::sanitize_clamped_setting( '', 1, 100, 25 )
		);
	}

	#[Test]
	public function whitespace_only_is_treated_as_blank(): void {
		$this->assertSame(
			45,
			Agend_Directory_Sync_Admin_Page::sanitize_clamped_setting( '   ', 15, 300, 45 )
		);
	}

	#[Test]
	public function a_value_within_range_is_kept(): void {
		$this->assertSame(
			40,
			Agend_Directory_Sync_Admin_Page::sanitize_clamped_setting( '40', 1, 100, 25 )
		);
	}

	#[Test]
	public function a_value_above_the_maximum_is_clamped_down(): void {
		$this->assertSame(
			100,
			Agend_Directory_Sync_Admin_Page::sanitize_clamped_setting( '500', 1, 100, 25 )
		);
	}

	#[Test]
	public function a_value_below_the_minimum_is_clamped_up(): void {
		$this->assertSame(
			15,
			Agend_Directory_Sync_Admin_Page::sanitize_clamped_setting( '5', 15, 300, 45 )
		);
	}

	#[Test]
	public function a_negative_value_is_clamped_up_to_the_minimum(): void {
		$this->assertSame(
			1,
			Agend_Directory_Sync_Admin_Page::sanitize_clamped_setting( '-20', 1, 100, 25 )
		);
	}
}
