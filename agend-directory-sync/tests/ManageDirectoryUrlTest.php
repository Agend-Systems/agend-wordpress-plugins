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
 * `manage_directory_url()` builds the pretty-SSO-route hand-off link the
 * "Manage directory" admin link uses. Pure: takes root URL and account slug
 * as arguments rather than resolving them from Agend_Apps_Settings, so these
 * cases are exercised without any WordPress option state.
 */
#[CoversClass( Agend_Directory_Sync_Admin_Page::class )]
final class ManageDirectoryUrlTest extends TestCase {

	#[Test]
	public function it_builds_the_directory_home_url_with_the_login_purpose_pair(): void {
		$url = Agend_Directory_Sync_Admin_Page::manage_directory_url(
			'https://gateway.example.test',
			'acme'
		);

		$this->assertSame(
			'https://gateway.example.test/sso/acme/directory-home?purpose=login',
			$url
		);
	}

	#[Test]
	public function it_returns_empty_string_when_the_root_url_is_empty(): void {
		$url = Agend_Directory_Sync_Admin_Page::manage_directory_url( '', 'acme' );

		$this->assertSame( '', $url );
	}

	#[Test]
	public function it_returns_empty_string_when_the_account_slug_is_empty(): void {
		$url = Agend_Directory_Sync_Admin_Page::manage_directory_url(
			'https://gateway.example.test',
			''
		);

		$this->assertSame( '', $url );
	}

	#[Test]
	public function it_strips_a_trailing_slash_from_the_root_url(): void {
		$url = Agend_Directory_Sync_Admin_Page::manage_directory_url(
			'https://gateway.example.test/',
			'acme'
		);

		$this->assertSame(
			'https://gateway.example.test/sso/acme/directory-home?purpose=login',
			$url
		);
	}

	#[Test]
	public function it_percent_encodes_an_account_slug_that_needs_it(): void {
		$url = Agend_Directory_Sync_Admin_Page::manage_directory_url(
			'https://gateway.example.test',
			'acme co/nz'
		);

		$this->assertSame(
			'https://gateway.example.test/sso/acme%20co%2Fnz/directory-home?purpose=login',
			$url
		);
	}

	#[Test]
	public function link_markup_is_empty_when_no_account_slug_is_configured(): void {
		// TestCase::setUp() resets Agend_Test_WP's options for every test, so
		// with no `agend_apps_account_slug` option set,
		// Agend_Apps_Settings::get_account_slug() (when that class is loaded
		// elsewhere in this suite) resolves to '' -- the same "cannot build a
		// link" case manage_directory_url() itself covers, exercised here
		// through the markup builder to prove it renders nothing at all
		// rather than a dead control.
		$markup = Agend_Directory_Sync_Admin_Page::manage_directory_link_markup();

		$this->assertSame( '', $markup );
	}
}
