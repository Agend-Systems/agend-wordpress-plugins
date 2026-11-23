<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_API;
use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Key_Scopes;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';

/**
 * A surface that inherits the site theme has the browser read
 * `/sites/config` on every page load. When the stored key is known not to be
 * allowed that read, the surface's config says so, and the browser skips it.
 */
#[CoversMethod( Agend_Apps_API::class, 'key_refuses' )]
#[CoversFunction( 'agend_apps_records_site_theme' )]
#[CoversFunction( 'agend_apps_records_directory_catalogue_build_config' )]
final class SiteThemeConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Endpoint_Scopes::set_map( null );
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-catalogue.php';
	}

	protected function tearDown(): void {
		delete_option( Agend_Apps_Key_Scopes::OPTION );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	/**
	 * Stores what a `GET /v1/health` response would have reported.
	 *
	 * @param string[] $scopes  Scopes held.
	 * @param string[] $app_ids Apps the key is restricted to; empty for none.
	 */
	private function know( array $scopes, array $app_ids = array() ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => $app_ids ) ) );
	}

	#[Test]
	public function should_mark_the_theme_when_the_key_is_not_allowed_the_sites_app(): void {
		$this->know( array( 'directory.listings.search', 'sites.config.browse' ), array( 'directory' ) );

		self::assertTrue( Agend_Apps_API::key_refuses( 'GET', '/sites/config' ) );
		self::assertSame(
			array( 'inheritFonts' => true, 'colourSource' => 'agend', 'siteConfig' => false ),
			agend_apps_records_site_theme( array( 'inheritFonts' => true, 'colourSource' => 'agend' ) )
		);
		self::assertCount( 0, Agend_Test_WP::$requests, 'deciding sends nothing' );
	}

	#[Test]
	public function should_mark_the_theme_when_the_key_lacks_the_site_config_scope(): void {
		$this->know( array( 'directory.listings.search' ), array( 'directory', 'sites' ) );

		self::assertSame( false, agend_apps_records_site_theme( array( 'inheritFonts' => true ) )['siteConfig'] );
	}

	#[Test]
	public function should_leave_the_theme_unchanged_when_the_key_may_read_the_site_config(): void {
		$this->know( array( 'sites.config.browse' ), array( 'sites' ) );

		self::assertFalse( Agend_Apps_API::key_refuses( 'GET', '/sites/config' ) );
		self::assertSame( array( 'inheritFonts' => true ), agend_apps_records_site_theme( array( 'inheritFonts' => true ) ) );
	}

	#[Test]
	public function should_leave_the_theme_unchanged_when_the_key_scopes_are_not_known(): void {
		delete_option( Agend_Apps_Key_Scopes::OPTION );

		self::assertFalse( Agend_Apps_API::key_refuses( 'GET', '/sites/config' ) );
		self::assertArrayNotHasKey( 'siteConfig', agend_apps_records_site_theme( array( 'inheritFonts' => true ) ) );
	}

	#[Test]
	public function should_leave_the_theme_unchanged_when_a_site_lets_refused_calls_through(): void {
		$this->know( array(), array( 'directory' ) );
		add_filter( 'agend_apps_api_block_unauthorised', static fn(): bool => false );

		self::assertFalse( Agend_Apps_API::key_refuses( 'GET', '/sites/config' ) );
		self::assertArrayNotHasKey( 'siteConfig', agend_apps_records_site_theme( array() ) );
	}

	#[Test]
	public function should_carry_the_mark_in_the_directory_catalogue_config_only_when_refused(): void {
		$this->know( array( 'directory.listings.search' ), array( 'directory' ) );
		self::assertSame( false, agend_apps_records_directory_catalogue_build_config( array() )['theme']['siteConfig'] );

		$this->know( array( 'directory.listings.search', 'sites.config.browse' ), array( 'directory', 'sites' ) );
		self::assertArrayNotHasKey( 'siteConfig', agend_apps_records_directory_catalogue_build_config( array() )['theme'] );
	}
}
