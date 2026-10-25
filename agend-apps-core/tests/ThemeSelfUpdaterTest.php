<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Theme_Self_Updater;
use Agend_Apps_Updater;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-updater.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-theme-self-updater.php';

/**
 * `Agend_Apps_Theme_Self_Updater`: the hourly job that installs a newer
 * Agend theme release itself, checks the home page and rolls back on a
 * server error.
 */
final class ThemeSelfUpdaterTest extends TestCase {

	/** @var array<int, array{0: string, 1: string}> Installer calls: stylesheet, version. */
	private array $installs = array();

	private bool $restored = false;

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Updater::reset_overrides();
		Agend_Apps_Theme_Self_Updater::reset_overrides();
		$this->installs = array();
		$this->restored = false;

		Agend_Apps_Updater::set_http_fetcher(
			static function ( string $url, array $args ) {
				unset( $url, $args );
				return array(
					'body'        => (string) json_encode(
						array(
							'themes' => array(
								'agend-base' => array(
									'version'      => '0.3.2',
									'requires'     => '6.7',
									'requires_php' => '8.0',
									'package'      => 'https://agend-systems.github.io/agend-base-theme/packages/agend-base.zip',
									'url'          => 'https://github.com/Agend-Systems/agend-base-theme/releases/tag/agend-base-v0.3.2',
								),
							),
						)
					),
					'status_code' => 200,
				);
			}
		);

		Agend_Apps_Theme_Self_Updater::set_guard( static fn() => '' );
		Agend_Apps_Theme_Self_Updater::set_health_checker( static fn() => true );
		Agend_Apps_Theme_Self_Updater::set_installer(
			function ( string $stylesheet, string $version ): array {
				$this->installs[] = array( $stylesheet, $version );
				return array(
					'result'  => true,
					'restore' => function (): void {
						$this->restored = true;
					},
				);
			}
		);
	}

	protected function tearDown(): void {
		Agend_Apps_Updater::reset_overrides();
		Agend_Apps_Theme_Self_Updater::reset_overrides();
		parent::tearDown();
	}

	/**
	 * @param array<string, array<string, string>> $themes
	 */
	private function themes( array $themes ): void {
		Agend_Apps_Theme_Self_Updater::set_theme_lister( static fn() => $themes );
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function log(): array {
		return (array) \get_option( Agend_Apps_Theme_Self_Updater::LOG_OPTION, array() );
	}

	private function agend_base( string $version ): array {
		return array(
			'agend-base' => array(
				'Version'   => $version,
				'UpdateURI' => 'https://agend-systems.github.io/agend-base-theme/agend-base',
			),
		);
	}

	#[Test]
	public function installs_a_newer_release_and_logs_it(): void {
		$this->themes( $this->agend_base( '0.3.1' ) );

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertSame( array( array( 'agend-base', '0.3.2' ) ), $this->installs );
		$this->assertSame( 'updated', $this->log()[0]['status'] );
		$this->assertSame( '0.3.1 to 0.3.2', $this->log()[0]['detail'] );
		$this->assertFalse( $this->restored );
	}

	#[Test]
	public function does_nothing_when_the_installed_version_is_current(): void {
		$this->themes( $this->agend_base( '0.3.2' ) );

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertSame( array(), $this->installs );
		$this->assertSame( array(), $this->log() );
	}

	/**
	 * The common case: a site running Sam's plugins on another theme (OTA,
	 * AVA, APNA). Nothing may run: no guard, no manifest request, no log.
	 */
	#[Test]
	public function does_nothing_at_all_on_a_site_without_an_agend_theme(): void {
		$this->themes(
			array(
				'wordpress-theme-agend' => array( 'Version' => '1.58.0', 'UpdateURI' => '' ),
				'hello-elementor'       => array( 'Version' => '3.5.1', 'UpdateURI' => '' ),
			)
		);
		$fetches = 0;
		Agend_Apps_Updater::set_http_fetcher(
			static function () use ( &$fetches ): array {
				++$fetches;
				return array( 'body' => '{}', 'status_code' => 200 );
			}
		);
		$guarded = false;
		Agend_Apps_Theme_Self_Updater::set_guard(
			static function () use ( &$guarded ): string {
				$guarded = true;
				return '';
			}
		);
		Agend_Test_WP::$site_transients[ Agend_Apps_Updater::THEME_CACHE_TRANSIENT ] = array( 'themes' => array() );

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertSame( 0, $fetches );
		$this->assertFalse( $guarded );
		$this->assertSame( array(), $this->installs );
		$this->assertFalse( \get_option( Agend_Apps_Theme_Self_Updater::LOG_OPTION, false ) );
		$this->assertArrayHasKey( Agend_Apps_Updater::THEME_CACHE_TRANSIENT, Agend_Test_WP::$site_transients );
	}

	#[Test]
	public function does_nothing_when_the_manifest_cannot_be_fetched(): void {
		$this->themes( $this->agend_base( '0.3.1' ) );
		Agend_Apps_Updater::set_http_fetcher( static fn(): array => array( 'body' => '', 'status_code' => 503 ) );

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertSame( array(), $this->installs );
	}

	#[Test]
	public function updates_only_the_agend_parent_when_a_child_theme_is_active(): void {
		$this->themes(
			$this->agend_base( '0.3.1' ) + array(
				'agend-template-1' => array( 'Version' => '0.1.0', 'UpdateURI' => '' ),
			)
		);

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertSame( array( array( 'agend-base', '0.3.2' ) ), $this->installs );
	}

	#[Test]
	public function ignores_themes_that_do_not_use_the_agend_update_host(): void {
		$this->themes(
			array(
				'agend-template-1' => array( 'Version' => '0.1.0', 'UpdateURI' => '' ),
				'twentytwentyfive' => array( 'Version' => '1.0', 'UpdateURI' => 'https://example.com/twentytwentyfive' ),
			)
		);

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertSame( array(), $this->installs );
	}

	#[Test]
	public function rolls_back_when_the_home_page_returns_a_server_error(): void {
		$this->themes( $this->agend_base( '0.3.1' ) );
		Agend_Apps_Theme_Self_Updater::set_health_checker( static fn() => false );

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertTrue( $this->restored );
		$this->assertSame( 'rolled_back', $this->log()[0]['status'] );
	}

	#[Test]
	public function does_not_retry_a_rolled_back_release_until_a_newer_one_appears(): void {
		$this->themes( $this->agend_base( '0.3.1' ) );
		Agend_Apps_Theme_Self_Updater::set_health_checker( static fn() => false );

		Agend_Apps_Theme_Self_Updater::run();
		Agend_Apps_Theme_Self_Updater::run();

		$this->assertCount( 1, $this->installs );

		Agend_Apps_Updater::set_http_fetcher(
			static fn(): array => array(
				'body'        => (string) json_encode(
					array(
						'themes' => array(
							'agend-base' => array(
								'version' => '0.3.3',
								'package' => 'https://agend-systems.github.io/agend-base-theme/packages/agend-base.zip',
							),
						),
					)
				),
				'status_code' => 200,
			)
		);
		Agend_Apps_Theme_Self_Updater::set_health_checker( static fn() => true );

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertSame( array( 'agend-base', '0.3.3' ), $this->installs[1] );
	}

	#[Test]
	public function logs_a_failed_install_without_running_the_health_check(): void {
		$this->themes( $this->agend_base( '0.3.1' ) );
		$checked = false;
		Agend_Apps_Theme_Self_Updater::set_health_checker(
			static function () use ( &$checked ): bool {
				$checked = true;
				return true;
			}
		);
		Agend_Apps_Theme_Self_Updater::set_installer(
			static fn(): array => array(
				'result'  => new \WP_Error( 'download_failed', 'Download failed.' ),
				'restore' => null,
			)
		);

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertFalse( $checked );
		$this->assertSame( 'failed', $this->log()[0]['status'] );
		$this->assertStringContainsString( 'Download failed.', $this->log()[0]['detail'] );
	}

	#[Test]
	public function logs_a_run_refused_by_the_lock_as_skipped(): void {
		$this->themes( $this->agend_base( '0.3.1' ) );
		Agend_Apps_Theme_Self_Updater::set_installer(
			static fn(): array => array(
				'result'  => new \WP_Error( 'agend_theme_locked', 'another update run is in progress' ),
				'restore' => null,
			)
		);

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertSame( 'skipped', $this->log()[0]['status'] );
		$this->assertSame( 'another update run is in progress', $this->log()[0]['detail'] );
	}

	#[Test]
	public function skips_the_whole_run_when_the_guard_objects(): void {
		$this->themes( $this->agend_base( '0.3.1' ) );
		Agend_Apps_Theme_Self_Updater::set_guard( static fn() => 'the theme directory is under version control' );

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertSame( array(), $this->installs );
		$this->assertSame( 'skipped', $this->log()[0]['status'] );
		$this->assertSame( 'the theme directory is under version control', $this->log()[0]['detail'] );
	}

	#[Test]
	public function respects_the_auto_update_opt_out_filter(): void {
		$this->themes( $this->agend_base( '0.3.1' ) );
		\add_filter( 'agend_apps_theme_auto_update', static fn( bool $enabled, string $theme ): bool => 'agend-base' !== $theme );

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertSame( array(), $this->installs );
		$this->assertSame( 'skipped', $this->log()[0]['status'] );
	}

	#[Test]
	public function skips_a_release_that_needs_a_newer_php(): void {
		$this->themes( $this->agend_base( '0.3.1' ) );
		Agend_Apps_Updater::set_http_fetcher(
			static fn(): array => array(
				'body'        => (string) json_encode(
					array(
						'themes' => array(
							'agend-base' => array(
								'version'      => '0.4.0',
								'requires_php' => '99.0',
								'package'      => 'https://agend-systems.github.io/agend-base-theme/packages/agend-base.zip',
							),
						),
					)
				),
				'status_code' => 200,
			)
		);

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertSame( array(), $this->installs );
		$this->assertSame( 'skipped', $this->log()[0]['status'] );
	}

	#[Test]
	public function refetches_the_manifest_instead_of_waiting_for_the_twelve_hour_cache(): void {
		$this->themes( $this->agend_base( '0.3.1' ) );
		Agend_Test_WP::$site_transients[ Agend_Apps_Updater::THEME_CACHE_TRANSIENT ] = array(
			'themes' => array(
				'agend-base' => array(
					'version' => '0.3.1',
					'package' => 'https://agend-systems.github.io/agend-base-theme/packages/agend-base.zip',
				),
			),
		);

		Agend_Apps_Theme_Self_Updater::run();

		$this->assertSame( array( array( 'agend-base', '0.3.2' ) ), $this->installs );
	}

	#[Test]
	public function keeps_only_the_most_recent_log_entries(): void {
		$this->themes( $this->agend_base( '0.3.2' ) );
		Agend_Apps_Theme_Self_Updater::set_guard( static fn() => 'file changes are disallowed on this site' );

		for ( $i = 0; $i < Agend_Apps_Theme_Self_Updater::LOG_LIMIT + 5; $i++ ) {
			Agend_Apps_Theme_Self_Updater::run();
		}

		$this->assertCount( Agend_Apps_Theme_Self_Updater::LOG_LIMIT, $this->log() );
	}

	#[Test]
	public function boot_schedules_an_hourly_check_once(): void {
		Agend_Apps_Theme_Self_Updater::boot();
		\do_action( 'init' );
		\do_action( 'init' );

		$events = array_values(
			array_filter(
				Agend_Test_WP::$scheduled_events,
				static fn( array $e ): bool => Agend_Apps_Theme_Self_Updater::CRON_HOOK === $e['hook']
			)
		);

		$this->assertCount( 1, $events );
		$this->assertSame( 'hourly', $events[0]['recurrence'] );
	}
}
