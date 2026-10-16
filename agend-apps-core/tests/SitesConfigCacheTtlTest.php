<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The site config response has its own cache lifetime rather than the 60
 * second fallback `Agend_Apps_Settings::get_cache_ttl()` gives a key that is
 * missing from the `Agend_Apps_Cache` map.
 *
 * Agend_Apps_Cache and Agend_Apps_Settings are bootstrap-time doubles
 * (tests/doubles.php), and the Settings double returns a fixed TTL for every
 * key, so neither can say what the real map holds. The real files are loaded
 * here under other class names so the real map and the real fallback are what
 * is asserted.
 */
final class SitesConfigCacheTtlTest extends TestCase {

	private const REAL_CACHE    = 'Agend_Test_Real_Cache';
	private const REAL_SETTINGS = 'Agend_Test_Real_Settings';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( class_exists( self::REAL_CACHE, false ) ) {
			return;
		}

		$includes = AGEND_TESTS_ROOT . '/agend-apps-core/includes/';

		$cache    = self::renamed( $includes . 'class-agend-apps-cache.php', 'class Agend_Apps_Cache {', 'class ' . self::REAL_CACHE . ' {' );
		$settings = self::renamed( $includes . 'class-agend-apps-settings.php', 'class Agend_Apps_Settings {', 'class ' . self::REAL_SETTINGS . ' {' );
		$settings = str_replace( 'Agend_Apps_Cache::', self::REAL_CACHE . '::', $settings );

		// phpcs:ignore Squiz.PHP.Eval.Discouraged
		eval( '?>' . $cache );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged
		eval( '?>' . $settings );
	}

	private static function renamed( string $file, string $declaration, string $replacement ): string {
		$source = (string) file_get_contents( $file );

		if ( 1 !== substr_count( $source, $declaration ) ) {
			throw new \RuntimeException( sprintf( 'Expected exactly one "%s" in %s.', $declaration, $file ) );
		}

		return str_replace( $declaration, $replacement, $source );
	}

	#[Test]
	public function should_resolve_site_config_to_one_hour_when_no_ttl_is_stored(): void {
		$this->assertSame( 3600, \Agend_Test_Real_Settings::get_cache_ttl( 'sites_config' ) );
	}

	#[Test]
	public function should_resolve_site_config_to_the_stored_ttl_when_an_admin_has_set_one(): void {
		update_option( 'agend_apps_cache_sites_config', 120 );

		$this->assertSame( 120, \Agend_Test_Real_Settings::get_cache_ttl( 'sites_config' ) );
	}

	#[Test]
	public function should_list_site_config_in_the_cache_map_with_a_label_and_an_integer_default(): void {
		$keys = \Agend_Test_Real_Cache::get_all_keys();

		$this->assertArrayHasKey( 'sites_config', $keys );
		$this->assertSame( 'Site Config', $keys['sites_config']['label'] );
		$this->assertSame( 3600, $keys['sites_config']['default_ttl'] );
	}

	#[Test]
	public function should_resolve_an_unregistered_key_to_sixty_seconds(): void {
		$this->assertSame( 60, \Agend_Test_Real_Settings::get_cache_ttl( 'a_key_missing_from_the_map' ) );
	}
}
