<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Updater;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-updater.php';

/**
 * `Agend_Apps_Updater`: serves plugin updates from the GitHub Pages manifest
 * for every Agend plugin, through the WP 5.8+ `update_plugins_{hostname}`
 * filter, and fills the "View details" modal through `plugins_api`.
 */
final class UpdaterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Updater::reset_overrides();
	}

	protected function tearDown(): void {
		Agend_Apps_Updater::reset_overrides();
		parent::tearDown();
	}

	/**
	 * Queues a manifest as the next (and only) `wp_remote_get()` response.
	 *
	 * @param array<string, mixed> $manifest Decoded manifest shape.
	 */
	private function queue_manifest( array $manifest ): void {
		Agend_Apps_Updater::set_http_fetcher(
			static function ( string $url, array $args ) use ( $manifest ) {
				unset( $url, $args );
				return array(
					'body'        => (string) json_encode( $manifest ),
					'status_code' => 200,
				);
			}
		);
	}

	/**
	 * Queues a raw (possibly malformed) body as the next `wp_remote_get()` response.
	 */
	private function queue_raw_response( string $body, int $status = 200 ): void {
		Agend_Apps_Updater::set_http_fetcher(
			static function ( string $url, array $args ) use ( $body, $status ) {
				unset( $url, $args );
				return array(
					'body'        => $body,
					'status_code' => $status,
				);
			}
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sample_manifest(): array {
		return array(
			'generated_at' => '2026-09-08T00:00:00Z',
			'plugins'      => array(
				'agend-apps-core' => array(
					'slug'         => 'agend-apps-core',
					'name'         => 'Agend Apps Core',
					'version'      => '1.12.0',
					'requires'     => '6.0',
					'requires_php' => '7.4',
					'tested'       => '6.8',
					'package'      => 'https://github.com/Agend-Systems/agend-wordpress-plugins/releases/download/agend-apps-core-v1.12.0/agend-apps-core.zip',
					'url'          => 'https://github.com/Agend-Systems/agend-wordpress-plugins/releases/tag/agend-apps-core-v1.12.0',
					'last_updated' => '2026-09-08T00:00:00Z',
					'author'       => 'Agend',
					'author_url'   => 'https://agend.com.au',
					'sections'     => array(
						'description' => '<p>Foundational plugin.</p>',
						'changelog'   => '<p>Changes.</p>',
					),
				),
			),
		);
	}

	#[Test]
	public function should_report_an_update_when_the_manifest_version_is_newer(): void {
		$this->queue_manifest( $this->sample_manifest() );

		$update = Agend_Apps_Updater::filter_plugin_update(
			false,
			array( 'Version' => '1.11.0' ),
			'agend-apps-core/agend-apps-core.php',
			array()
		);

		$this->assertIsArray( $update );
		$this->assertSame( 'agend-apps-core', $update['slug'] );
		$this->assertSame( '1.12.0', $update['version'] );
		$this->assertSame(
			'https://github.com/Agend-Systems/agend-wordpress-plugins/releases/download/agend-apps-core-v1.12.0/agend-apps-core.zip',
			$update['package']
		);
		$this->assertSame( 'agend-apps-core/agend-apps-core.php', $update['plugin'] );
		$this->assertSame( '6.0', $update['requires'] );
		$this->assertSame( '7.4', $update['requires_php'] );
		$this->assertSame( '6.8', $update['tested'] );
	}

	#[Test]
	public function should_not_report_an_update_when_the_installed_version_is_equal(): void {
		$this->queue_manifest( $this->sample_manifest() );

		$update = Agend_Apps_Updater::filter_plugin_update(
			false,
			array( 'Version' => '1.12.0' ),
			'agend-apps-core/agend-apps-core.php',
			array()
		);

		$this->assertFalse( $update );
	}

	#[Test]
	public function should_not_report_an_update_when_the_installed_version_is_newer(): void {
		$this->queue_manifest( $this->sample_manifest() );

		$update = Agend_Apps_Updater::filter_plugin_update(
			false,
			array( 'Version' => '2.0.0' ),
			'agend-apps-core/agend-apps-core.php',
			array()
		);

		$this->assertFalse( $update );
	}

	#[Test]
	public function should_pass_through_the_incoming_update_when_the_slug_is_unknown(): void {
		$this->queue_manifest( $this->sample_manifest() );

		$incoming = array( 'version' => '9.9.9' );

		$update = Agend_Apps_Updater::filter_plugin_update(
			$incoming,
			array( 'Version' => '1.0.0' ),
			'some-other-plugin/some-other-plugin.php',
			array()
		);

		$this->assertSame( $incoming, $update );
	}

	#[Test]
	public function should_match_by_update_uri_when_the_installed_folder_name_differs(): void {
		$this->queue_manifest( $this->sample_manifest() );

		$update = Agend_Apps_Updater::filter_plugin_update(
			false,
			array(
				'Version'   => '1.11.0',
				'UpdateURI' => 'https://agend-systems.github.io/agend-wordpress-plugins/agend-apps-core',
			),
			'agend-apps-core-renamed-folder/agend-apps-core.php',
			array()
		);

		$this->assertIsArray( $update );
		$this->assertSame( 'agend-apps-core', $update['slug'] );
	}

	#[Test]
	public function should_treat_a_malformed_manifest_as_a_failed_fetch_and_cache_the_sentinel(): void {
		$this->queue_raw_response( 'not json at all' );

		$update = Agend_Apps_Updater::filter_plugin_update(
			false,
			array( 'Version' => '1.0.0' ),
			'agend-apps-core/agend-apps-core.php',
			array()
		);

		$this->assertFalse( $update );
		$this->assertSame(
			Agend_Apps_Updater::FETCH_FAILED_SENTINEL,
			Agend_Test_WP::$site_transients[ Agend_Apps_Updater::CACHE_TRANSIENT ]
		);
	}

	#[Test]
	public function should_treat_a_manifest_missing_the_plugins_key_as_a_failed_fetch(): void {
		$this->queue_raw_response( (string) json_encode( array( 'generated_at' => '2026-09-08T00:00:00Z' ) ) );

		$update = Agend_Apps_Updater::filter_plugin_update(
			false,
			array( 'Version' => '1.0.0' ),
			'agend-apps-core/agend-apps-core.php',
			array()
		);

		$this->assertFalse( $update );
		$this->assertSame(
			Agend_Apps_Updater::FETCH_FAILED_SENTINEL,
			Agend_Test_WP::$site_transients[ Agend_Apps_Updater::CACHE_TRANSIENT ]
		);
	}

	#[Test]
	public function should_not_refetch_while_the_manifest_cache_is_fresh(): void {
		$calls = 0;

		Agend_Apps_Updater::set_http_fetcher(
			function ( string $url, array $args ) use ( &$calls ) {
				unset( $url, $args );
				++$calls;
				return array(
					'body'        => (string) json_encode( $this->sample_manifest() ),
					'status_code' => 200,
				);
			}
		);

		Agend_Apps_Updater::filter_plugin_update( false, array( 'Version' => '1.0.0' ), 'agend-apps-core/agend-apps-core.php', array() );
		Agend_Apps_Updater::filter_plugin_update( false, array( 'Version' => '1.0.0' ), 'agend-apps-core/agend-apps-core.php', array() );

		$this->assertSame( 1, $calls );
	}

	#[Test]
	public function should_return_plugin_information_for_a_known_slug(): void {
		$this->queue_manifest( $this->sample_manifest() );

		$args           = new \stdClass();
		$args->slug     = 'agend-apps-core';
		$result         = Agend_Apps_Updater::filter_plugins_api( false, 'plugin_information', $args );

		$this->assertIsObject( $result );
		$this->assertSame( 'agend-apps-core', $result->slug );
		$this->assertSame( 'Agend Apps Core', $result->name );
		$this->assertSame( '1.12.0', $result->version );
		$this->assertSame( '<a href="https://agend.com.au">Agend</a>', $result->author );
		$this->assertSame(
			'https://github.com/Agend-Systems/agend-wordpress-plugins/releases/download/agend-apps-core-v1.12.0/agend-apps-core.zip',
			$result->download_link
		);
		$this->assertSame(
			array(
				'description' => '<p>Foundational plugin.</p>',
				'changelog'   => '<p>Changes.</p>',
			),
			$result->sections
		);
	}

	#[Test]
	public function should_pass_through_plugins_api_for_an_unknown_slug(): void {
		$this->queue_manifest( $this->sample_manifest() );

		$args       = new \stdClass();
		$args->slug = 'some-other-plugin';

		$result = Agend_Apps_Updater::filter_plugins_api( false, 'plugin_information', $args );

		$this->assertFalse( $result );
	}

	#[Test]
	public function should_pass_through_plugins_api_for_a_different_action(): void {
		$this->queue_manifest( $this->sample_manifest() );

		$args       = new \stdClass();
		$args->slug = 'agend-apps-core';

		$result = Agend_Apps_Updater::filter_plugins_api( false, 'query_plugins', $args );

		$this->assertFalse( $result );
	}

	#[Test]
	public function should_flush_the_cached_manifest_when_the_update_plugins_transient_is_deleted(): void {
		Agend_Test_WP::$site_transients[ Agend_Apps_Updater::CACHE_TRANSIENT ] = $this->sample_manifest();

		Agend_Apps_Updater::boot();
		\do_action( 'delete_site_transient_update_plugins' );

		$this->assertArrayNotHasKey(
			Agend_Apps_Updater::CACHE_TRANSIENT,
			Agend_Test_WP::$site_transients
		);
	}

	#[Test]
	public function flush_cache_removes_the_cached_manifest(): void {
		Agend_Test_WP::$site_transients[ Agend_Apps_Updater::CACHE_TRANSIENT ] = $this->sample_manifest();

		Agend_Apps_Updater::flush_cache();

		$this->assertArrayNotHasKey(
			Agend_Apps_Updater::CACHE_TRANSIENT,
			Agend_Test_WP::$site_transients
		);
	}

	#[Test]
	public function row_meta_gets_a_check_for_updates_link_for_our_update_uri(): void {
		$links = Agend_Apps_Updater::filter_plugin_row_meta(
			array( '<a href="https://agend.com.au">Visit plugin site</a>' ),
			'agend-apps-core/agend-apps-core.php',
			array( 'UpdateURI' => 'https://agend-systems.github.io/agend-wordpress-plugins/agend-apps-core' ),
			'all'
		);

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'Check for updates', $links[1] );
		$this->assertStringContainsString( 'action=agend_apps_check_updates', $links[1] );
		$this->assertStringContainsString( 'plugin=agend-apps-core', str_replace( '%2F', '/', $links[1] ) );
	}

	#[Test]
	public function row_meta_is_unchanged_for_a_foreign_update_uri(): void {
		$links = array( '<a href="https://example.com">Visit plugin site</a>' );

		$result = Agend_Apps_Updater::filter_plugin_row_meta(
			$links,
			'some-other-plugin/some-other-plugin.php',
			array( 'UpdateURI' => 'https://example.com/some-other-plugin' ),
			'all'
		);

		$this->assertSame( $links, $result );
	}

	#[Test]
	public function row_meta_is_unchanged_without_the_update_plugins_capability(): void {
		$GLOBALS['agend_test_current_user_can']['update_plugins'] = false;

		$links = array();

		$result = Agend_Apps_Updater::filter_plugin_row_meta(
			$links,
			'agend-apps-core/agend-apps-core.php',
			array( 'UpdateURI' => 'https://agend-systems.github.io/agend-wordpress-plugins/agend-apps-core' ),
			'all'
		);

		$this->assertSame( $links, $result );
	}

	#[Test]
	public function handle_check_updates_flushes_caches_and_triggers_a_forced_check(): void {
		Agend_Test_WP::$site_transients[ Agend_Apps_Updater::CACHE_TRANSIENT ] = $this->sample_manifest();
		Agend_Test_WP::$site_transients['update_plugins']                     = array( 'stale' => true );

		$redirect_url = Agend_Apps_Updater::handle_check_updates();

		$this->assertArrayNotHasKey( Agend_Apps_Updater::CACHE_TRANSIENT, Agend_Test_WP::$site_transients );
		$this->assertArrayNotHasKey( 'update_plugins', Agend_Test_WP::$site_transients );
		$this->assertSame( 1, Agend_Test_WP::$wp_update_plugins_calls );
		$this->assertStringContainsString( 'agend_apps_checked=1', $redirect_url );
	}

	#[Test]
	public function handle_check_updates_also_refreshes_agend_themes(): void {
		Agend_Test_WP::$site_transients[ Agend_Apps_Updater::THEME_CACHE_TRANSIENT ] = array( 'themes' => array() );
		Agend_Test_WP::$site_transients['update_themes']                            = array( 'stale' => true );

		Agend_Apps_Updater::handle_check_updates();

		$this->assertArrayNotHasKey( Agend_Apps_Updater::THEME_CACHE_TRANSIENT, Agend_Test_WP::$site_transients );
		$this->assertArrayNotHasKey( 'update_themes', Agend_Test_WP::$site_transients );
		$this->assertSame( 1, Agend_Test_WP::$wp_update_themes_calls );
	}

	#[Test]
	public function handle_check_updates_dies_without_the_update_plugins_capability(): void {
		$GLOBALS['agend_test_current_user_can']['update_plugins'] = false;

		$this->expectException( \Agend_Test_WP_Die_Exception::class );

		Agend_Apps_Updater::handle_check_updates();
	}

	/**
	 * WordPress has fired `update_plugins_{$hostname}` (underscore) since 5.8.
	 * A hyphenated name silently never fires, which is exactly the bug this
	 * guards against: the filter method can be correct while nothing calls it.
	 */
	#[Test]
	public function boot_registers_the_underscore_update_plugins_hostname_filter(): void {
		Agend_Apps_Updater::boot();

		$this->assertArrayHasKey( 'update_plugins_agend-systems.github.io', Agend_Test_WP::$filters );
		$this->assertArrayNotHasKey( 'update-plugins_agend-systems.github.io', Agend_Test_WP::$filters );
		$this->assertSame(
			array( Agend_Apps_Updater::class, 'filter_plugin_update' ),
			Agend_Test_WP::$filters['update_plugins_agend-systems.github.io']
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sample_theme_manifest(): array {
		return array(
			'generated_at' => '2026-10-23T00:00:00Z',
			'themes'       => array(
				'agend-base' => array(
					'slug'         => 'agend-base',
					'name'         => 'Agend Base',
					'version'      => '0.3.0',
					'requires'     => '6.7',
					'requires_php' => '8.0',
					'package'      => 'https://agend-systems.github.io/agend-base-theme/packages/agend-base.zip',
					'url'          => 'https://github.com/Agend-Systems/agend-base-theme/releases/tag/agend-base-v0.3.0',
				),
			),
		);
	}

	#[Test]
	public function should_report_a_theme_update_when_the_manifest_version_is_newer(): void {
		$this->queue_manifest( $this->sample_theme_manifest() );

		$update = Agend_Apps_Updater::filter_theme_update(
			false,
			array( 'Version' => '0.2.0' ),
			'agend-base',
			array()
		);

		$this->assertIsArray( $update );
		$this->assertSame( 'agend-base', $update['theme'] );
		$this->assertSame( '0.3.0', $update['new_version'] );
		$this->assertSame( 'https://agend-systems.github.io/agend-base-theme/packages/agend-base.zip', $update['package'] );
		$this->assertSame( '6.7', $update['requires'] );
		$this->assertSame( '8.0', $update['requires_php'] );
	}

	/**
	 * WordPress files a current theme under `no_update` itself, and the Themes
	 * screen only shows "Auto-updates enabled" for a theme it found there.
	 */
	#[Test]
	public function should_return_the_theme_entry_even_when_the_installed_version_is_current(): void {
		$this->queue_manifest( $this->sample_theme_manifest() );

		$update = Agend_Apps_Updater::filter_theme_update( false, array( 'Version' => '0.3.0' ), 'agend-base', array() );

		$this->assertIsArray( $update );
		$this->assertSame( '0.3.0', $update['new_version'] );
		$this->assertSame( 'agend-base', $update['theme'] );
	}

	#[Test]
	public function should_pass_through_the_incoming_theme_update_when_the_slug_is_unknown(): void {
		$this->queue_manifest( $this->sample_theme_manifest() );

		$update = Agend_Apps_Updater::filter_theme_update( false, array( 'Version' => '1.0.0' ), 'some-other-theme', array() );

		$this->assertFalse( $update );
	}

	/**
	 * A renamed theme folder is not matched through its Update URI, because
	 * WordPress would unpack the update into the zip's own folder name.
	 */
	#[Test]
	public function should_not_offer_a_theme_update_to_a_renamed_folder(): void {
		$this->queue_manifest( $this->sample_theme_manifest() );

		$update = Agend_Apps_Updater::filter_theme_update(
			false,
			array(
				'Version'   => '0.2.0',
				'UpdateURI' => 'https://agend-systems.github.io/agend-base-theme/agend-base',
			),
			'agend-base-renamed',
			array()
		);

		$this->assertFalse( $update );
	}

	#[Test]
	public function should_fetch_the_theme_manifest_from_its_own_url_and_cache_it_separately(): void {
		$urls = array();
		Agend_Apps_Updater::set_http_fetcher(
			function ( string $url, array $args ) use ( &$urls ) {
				unset( $args );
				$urls[] = $url;
				return array(
					'body'        => (string) json_encode( $this->sample_theme_manifest() ),
					'status_code' => 200,
				);
			}
		);

		Agend_Apps_Updater::filter_theme_update( false, array( 'Version' => '0.2.0' ), 'agend-base', array() );
		Agend_Apps_Updater::filter_theme_update( false, array( 'Version' => '0.2.0' ), 'agend-base', array() );

		$this->assertSame( array( Agend_Apps_Updater::DEFAULT_THEME_MANIFEST_URL ), $urls );
		$this->assertIsArray( Agend_Test_WP::$site_transients[ Agend_Apps_Updater::THEME_CACHE_TRANSIENT ] );
		$this->assertArrayNotHasKey( Agend_Apps_Updater::CACHE_TRANSIENT, Agend_Test_WP::$site_transients );
	}

	#[Test]
	public function should_treat_a_theme_manifest_missing_the_themes_key_as_a_failed_fetch(): void {
		$this->queue_manifest( $this->sample_manifest() );

		$update = Agend_Apps_Updater::filter_theme_update( false, array( 'Version' => '0.2.0' ), 'agend-base', array() );

		$this->assertFalse( $update );
		$this->assertSame(
			Agend_Apps_Updater::FETCH_FAILED_SENTINEL,
			Agend_Test_WP::$site_transients[ Agend_Apps_Updater::THEME_CACHE_TRANSIENT ]
		);
	}

	#[Test]
	public function should_flush_the_cached_theme_manifest_when_the_update_themes_transient_is_deleted(): void {
		Agend_Test_WP::$site_transients[ Agend_Apps_Updater::THEME_CACHE_TRANSIENT ] = $this->sample_theme_manifest();

		Agend_Apps_Updater::boot();
		\do_action( 'delete_site_transient_update_themes' );

		$this->assertArrayNotHasKey( Agend_Apps_Updater::THEME_CACHE_TRANSIENT, Agend_Test_WP::$site_transients );
	}

	#[Test]
	public function should_auto_update_a_theme_served_from_our_update_host(): void {
		$item = (object) array(
			'theme' => 'agend-base',
			'id'    => 'https://agend-systems.github.io/agend-base-theme/agend-base',
		);

		$this->assertTrue( Agend_Apps_Updater::filter_auto_update_theme( null, $item ) );
		$this->assertTrue( Agend_Apps_Updater::filter_auto_update_theme( false, $item ) );
	}

	#[Test]
	public function should_let_a_site_opt_a_theme_out_of_auto_updates(): void {
		\add_filter(
			'agend_apps_theme_auto_update',
			static function ( bool $enabled, string $theme ): bool {
				return 'agend-base' === $theme ? false : $enabled;
			}
		);

		$item = (object) array(
			'theme' => 'agend-base',
			'id'    => 'https://agend-systems.github.io/agend-base-theme/agend-base',
		);

		$this->assertFalse( Agend_Apps_Updater::filter_auto_update_theme( null, $item ) );
	}

	#[Test]
	public function should_leave_auto_updates_alone_for_a_theme_from_elsewhere(): void {
		$item = (object) array(
			'theme' => 'twentytwentyfive',
			'id'    => 'w.org/themes/twentytwentyfive',
		);

		$this->assertNull( Agend_Apps_Updater::filter_auto_update_theme( null, $item ) );
		$this->assertFalse( Agend_Apps_Updater::filter_auto_update_theme( false, $item ) );
	}

	#[Test]
	public function boot_registers_the_theme_update_and_auto_update_filters(): void {
		Agend_Apps_Updater::boot();

		$this->assertSame(
			array( Agend_Apps_Updater::class, 'filter_theme_update' ),
			Agend_Test_WP::$filters['update_themes_agend-systems.github.io']
		);
		$this->assertSame(
			array( Agend_Apps_Updater::class, 'filter_auto_update_theme' ),
			Agend_Test_WP::$filters['auto_update_theme']
		);
	}
}
