<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend\Tests\TestCase;
use Agend_Apps_Admin;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/admin/class-agend-apps-admin.php';

/**
 * Agend Content Access sizes its per-member standing cache with
 * `Agend_Apps_Settings::get_cache_ttl( 'crm_me_entitlements' )`. Until the key
 * was registered in the `Agend_Apps_Cache` map that call fell back to 60
 * seconds, the configured value never applied, and the TTL could not be set on
 * the Cache tab at all.
 *
 * Agend_Apps_Cache and Agend_Apps_Settings are bootstrap-time doubles
 * (tests/doubles.php), so neither can say what the real map holds. The real
 * files are loaded here under other class names, as SitesConfigCacheTtlTest
 * does, so the real map and the real lookup are what is asserted.
 */
final class MemberStandingCacheKeyTest extends TestCase {

	private const KEY           = 'crm_me_entitlements';
	private const REAL_CACHE    = 'Agend_Test_Standing_Cache';
	private const REAL_SETTINGS = 'Agend_Test_Standing_Settings';

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
	public function should_register_member_standing_in_the_cache_map_with_a_label_and_a_300_second_default(): void {
		$keys = \Agend_Test_Standing_Cache::get_all_keys();

		$this->assertArrayHasKey( self::KEY, $keys );
		$this->assertSame( 'Member Standing', $keys[ self::KEY ]['label'] );
		$this->assertSame( 300, $keys[ self::KEY ]['default_ttl'] );
	}

	#[Test]
	public function should_describe_the_key_as_the_content_access_standing_cache_cleared_by_webhooks(): void {
		$description = \Agend_Test_Standing_Cache::get_all_keys()[ self::KEY ]['description'];

		$this->assertStringContainsString( 'Content Access', $description );
		$this->assertStringContainsString( 'Agend webhooks clear it', $description );
		$this->assertStringContainsString( 'lapse', $description );
		$this->assertStringContainsString( 'within this time', $description );
	}

	#[Test]
	public function should_resolve_to_300_seconds_when_no_ttl_is_stored(): void {
		$this->assertSame( 300, \Agend_Test_Standing_Settings::get_cache_ttl( self::KEY ) );
	}

	#[Test]
	public function should_resolve_to_the_stored_ttl_when_an_admin_has_set_one(): void {
		update_option( 'agend_apps_cache_' . self::KEY, 45 );

		$this->assertSame( 45, \Agend_Test_Standing_Settings::get_cache_ttl( self::KEY ) );
	}

	#[Test]
	public function should_give_every_registered_key_an_integer_default_and_only_a_string_description(): void {
		foreach ( \Agend_Test_Standing_Cache::get_all_keys() as $key => $config ) {
			$this->assertIsString( $config['label'], $key );
			$this->assertIsInt( $config['default_ttl'], $key );
			$this->assertIsString( $config['description'] ?? '', $key );
		}
	}

	#[Test]
	public function should_show_a_keys_description_under_its_ttl_field(): void {
		update_option( 'agend_apps_cache_' . self::KEY, 45 );

		ob_start();
		( new Agend_Apps_Admin() )->render_ttl_field(
			array(
				'option_name' => 'agend_apps_cache_' . self::KEY,
				'default'     => 300,
				'description' => 'Shown <b>escaped</b>.',
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="agend_apps_cache_crm_me_entitlements" value="45"', $html );
		$this->assertStringContainsString( '<p class="description">Shown &lt;b&gt;escaped&lt;/b&gt;.</p>', $html );
	}

	#[Test]
	public function should_render_a_key_without_a_description_exactly_as_before(): void {
		ob_start();
		( new Agend_Apps_Admin() )->render_ttl_field(
			array(
				'option_name' => 'agend_apps_cache_crm_tiers',
				'default'     => 3600,
				'description' => '',
			)
		);
		$html = (string) ob_get_clean();

		$this->assertSame( '<input type="number" id="agend_apps_cache_crm_tiers" name="agend_apps_cache_crm_tiers" value="3600" min="0" step="1" class="small-text" />', $html );
	}
}
