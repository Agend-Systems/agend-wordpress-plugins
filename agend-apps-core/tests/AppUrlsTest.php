<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/app-urls.php';

/**
 * The one way every Agend app (Loop, Atlas) is found: an option the site sets,
 * else the API root with its host swapped, then a filter.
 */
final class AppUrlsTest extends TestCase {

	private function root( string $url ): void {
		Agend_Test_WP::$options['agend_apps_root_url_for_tests'] = $url;
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> */
	public static function derived(): array {
		return array(
			'loop, production'           => array( 'loop', 'https://api.agend.com.au', 'https://loop.agend.com.au' ),
			'atlas, production'          => array( 'atlas', 'https://api.agend.com.au', 'https://atlas.agend.com.au' ),
			'loop, staging'              => array( 'loop', 'https://api.agend.info', 'https://loop.agend.info' ),
			'atlas, staging'             => array( 'atlas', 'https://api.agend.info', 'https://atlas.agend.info' ),
			'loop, local gateway'        => array( 'loop', 'http://localhost:3072', 'http://localhost:3063' ),
			'atlas, local gateway'       => array( 'atlas', 'http://localhost:3072', 'http://localhost:3066' ),
			'a local gateway by address' => array( 'atlas', 'http://127.0.0.1:3072', 'http://127.0.0.1:3066' ),
			'a trailing slash'           => array( 'loop', 'https://api.agend.com.au/', 'https://loop.agend.com.au' ),
			'a custom api host'          => array( 'atlas', 'https://api.example.org', 'https://atlas.example.org' ),
			'a host with nothing to swap' => array( 'loop', 'https://gateway.example.org', '' ),
			'no root at all'             => array( 'atlas', '', '' ),
			'an app nobody has heard of' => array( 'unknown', 'https://api.agend.com.au', '' ),
		);
	}

	#[Test]
	#[DataProvider( 'derived' )]
	public function should_work_out_an_app_address_from_the_api_root( string $app, string $root, string $expected ): void {
		self::assertSame( $expected, agend_apps_app_derive_url( $app, $root ) );

		$this->root( $root );
		self::assertSame( $expected, agend_apps_app_url( $app ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function options(): array {
		return array(
			'loop'  => array( 'loop', 'agend_apps_loop_url' ),
			'atlas' => array( 'atlas', 'agend_apps_atlas_url' ),
		);
	}

	#[Test]
	#[DataProvider( 'options' )]
	public function should_let_an_explicit_option_win_over_the_derived_address( string $app, string $option ): void {
		$this->root( 'https://api.agend.com.au' );
		Agend_Test_WP::$options[ $option ] = ' https://own.example.org/ ';

		self::assertSame( 'https://own.example.org', agend_apps_app_url( $app ) );
	}

	#[Test]
	#[DataProvider( 'options' )]
	public function should_ignore_an_option_that_is_not_a_web_address( string $app, string $option ): void {
		$this->root( 'https://api.agend.com.au' );
		Agend_Test_WP::$options[ $option ] = 'javascript:alert(1)';

		self::assertSame( 'https://' . $app . '.agend.com.au', agend_apps_app_url( $app ) );
	}

	#[Test]
	public function should_keep_each_apps_option_to_that_app(): void {
		$this->root( 'https://api.agend.com.au' );
		Agend_Test_WP::$options['agend_apps_atlas_url'] = 'https://library.example.org';

		self::assertSame( 'https://library.example.org', agend_apps_app_url( 'atlas' ) );
		self::assertSame( 'https://loop.agend.com.au', agend_apps_app_url( 'loop' ) );
	}

	#[Test]
	public function should_let_the_filter_have_the_last_word_and_say_which_app(): void {
		$this->root( 'https://api.agend.com.au' );
		$seen = array();

		add_filter(
			'agend_apps_app_url',
			static function ( string $url, string $app ) use ( &$seen ): string {
				$seen[] = array( $app, $url );

				return 'loop' === $app ? 'https://filtered.example.org/' : $url;
			},
			10,
			2
		);

		self::assertSame( 'https://filtered.example.org', agend_apps_app_url( 'loop' ) );
		self::assertSame( 'https://atlas.agend.com.au', agend_apps_app_url( 'atlas' ) );
		self::assertSame( array( array( 'loop', 'https://loop.agend.com.au' ), array( 'atlas', 'https://atlas.agend.com.au' ) ), $seen );
	}

	#[Test]
	public function should_let_both_named_helpers_agree_with_it(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/community.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/atlas.php';

		$this->root( 'https://api.agend.info' );
		add_filter(
			'agend_apps_app_url',
			static fn( string $url, string $app ): string => $url . ( 'atlas' === $app ? '/x' : '' ),
			10,
			2
		);

		self::assertSame( agend_apps_app_url( 'loop' ), agend_apps_community_loop_url() );
		self::assertSame( agend_apps_app_url( 'atlas' ), agend_apps_atlas_app_url() );
		self::assertSame( 'https://atlas.agend.info/x', agend_apps_atlas_app_url() );
	}
}
