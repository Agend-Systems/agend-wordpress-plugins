<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Key_Scopes;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sites.php';

/**
 * A site whose account has no published site config is told so by the
 * gateway once per cache lifetime, not on every page view, and the answer is
 * the same for every visitor.
 *
 * Agend_Apps_Cache is a bootstrap-time double, so the real class is loaded
 * here under another name to prove that clearing the "Site Config" row
 * forgets the remembered answer. The wpdb double does not model the LIKE
 * delete clear() issues, so this test applies it to the stored transients.
 */
final class SitesConfigNotFoundTest extends TestCase {

	private const REAL_CACHE = 'Agend_Test_Site_Config_Real_Cache';

	private const NOT_FOUND = array(
		'success' => false,
		'error'   => array(
			'code'    => 'NOT_FOUND',
			'message' => 'No published site config for this account',
		),
	);

	private const CONFIG = array(
		'success' => true,
		'data'    => array( 'brand' => array( 'name' => 'Example' ) ),
	);

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( class_exists( self::REAL_CACHE, false ) ) {
			return;
		}

		$file        = AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-cache.php';
		$source      = (string) file_get_contents( $file );
		$declaration = 'class Agend_Apps_Cache {';

		if ( 1 !== substr_count( $source, $declaration ) ) {
			throw new \RuntimeException( sprintf( 'Expected exactly one "%s" in %s.', $declaration, $file ) );
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged
		eval( '?>' . str_replace( $declaration, 'class ' . self::REAL_CACHE . ' {', $source ) );
	}

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Endpoint_Scopes::set_map( null );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	#[Test]
	public function should_ask_the_gateway_once_when_the_account_has_no_published_site_config(): void {
		Agend_Test_WP::queue_response( 404, self::NOT_FOUND );

		$first  = agend_apps_sites_get_config();
		$second = agend_apps_sites_get_config();
		$third  = agend_apps_sites_get_config();

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertInstanceOf( WP_Error::class, $first );
		$this->assertSame( 'agend_api_error', $first->get_error_code() );
		$this->assertSame( 404, $first->get_error_data()['status_code'] );
		$this->assertEquals( $first, $second );
		$this->assertEquals( $first, $third );
	}

	#[Test]
	public function should_remember_the_missing_config_for_the_site_config_lifetime_when_the_gateway_says_not_found(): void {
		$GLOBALS['agend_test_cache_ttls']['sites_config'] = 1234;
		Agend_Test_WP::queue_response( 404, self::NOT_FOUND );

		agend_apps_sites_get_config();

		$this->assertSame( array( 1234 ), array_values( $this->siteConfigTtls() ) );
	}

	#[Test]
	public function should_keep_the_original_expiry_when_a_remembered_missing_config_is_served(): void {
		Agend_Test_WP::queue_response( 404, self::NOT_FOUND );
		agend_apps_sites_get_config();
		$stored = array_keys( $this->siteConfigTtls() );
		Agend_Test_WP::$transient_ttls = array();

		agend_apps_sites_get_config();

		$this->assertCount( 1, $stored );
		$this->assertSame( array(), $this->siteConfigTtls(), 'rewriting the entry on each serve would keep it from ever lapsing' );
	}

	#[Test]
	public function should_ask_the_gateway_every_time_when_the_site_config_lifetime_is_zero(): void {
		$GLOBALS['agend_test_cache_ttls']['sites_config'] = 0;
		Agend_Test_WP::queue_response( 404, self::NOT_FOUND );
		Agend_Test_WP::queue_response( 404, self::NOT_FOUND );

		agend_apps_sites_get_config();
		agend_apps_sites_get_config();

		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertSame( array(), $this->siteConfigTtls() );
	}

	/**
	 * @return array<string, array{0: callable(): void}>
	 */
	public static function gateway_failures(): array {
		$error = static fn( int $status, string $code ): callable => static function () use ( $status, $code ): void {
			Agend_Test_WP::queue_response( $status, array( 'error' => array( 'code' => $code, 'message' => 'refused' ) ) );
		};

		return array(
			'a transport error'            => array( static fn() => Agend_Test_WP::queue_transport_error( 'http_request_failed', 'cURL error 28: timed out' ) ),
			'a 500'                        => array( $error( 500, 'INTERNAL_ERROR' ) ),
			'a 503 page that is not JSON'  => array( static fn() => Agend_Test_WP::queue_response( 503, '<html>Unavailable</html>' ) ),
			'a 401'                        => array( $error( 401, 'UNAUTHORIZED' ) ),
			'a 403'                        => array( $error( 403, 'FORBIDDEN' ) ),
			'a 429 from the gateway'       => array( $error( 429, 'RATE_LIMITED' ) ),
			'a 404 for an unknown app'     => array( $error( 404, 'APP_NOT_FOUND' ) ),
			'a 404 page that is not JSON'  => array( static fn() => Agend_Test_WP::queue_response( 404, '<html>Not found</html>' ) ),
		);
	}

	#[Test]
	#[DataProvider( 'gateway_failures' )]
	public function should_ask_the_gateway_again_when_the_last_answer_was_not_a_missing_config( callable $fail ): void {
		$fail();

		$failed = agend_apps_sites_get_config();
		$next   = agend_apps_sites_get_config();

		$this->assertInstanceOf( WP_Error::class, $failed );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertIsArray( $next );
	}

	#[Test]
	public function should_ask_the_gateway_when_a_scope_refusal_has_cleared(): void {
		$this->knowScopes( array( 'crm.contacts.browse' ) );

		$refused = agend_apps_sites_get_config();

		$this->knowScopes( array( 'sites.config.browse' ) );
		$next = agend_apps_sites_get_config();

		$this->assertSame( 'agend_apps_scope_missing', $refused->get_error_code() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertIsArray( $next );
	}

	#[Test]
	public function should_ask_the_gateway_when_an_app_refusal_has_cleared(): void {
		$this->knowScopes( array( 'sites.config.browse' ), array( 'crm' ) );

		$refused = agend_apps_sites_get_config();

		$this->knowScopes( array( 'sites.config.browse' ), array( 'sites' ) );
		$next = agend_apps_sites_get_config();

		$this->assertSame( 'agend_apps_app_not_allowed', $refused->get_error_code() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertIsArray( $next );
	}

	#[Test]
	public function should_ask_the_gateway_when_the_local_rate_limit_has_lapsed(): void {
		set_transient( 'agend_apps_rate_limit_remaining', 0, 60 );

		$refused = agend_apps_sites_get_config();

		delete_transient( 'agend_apps_rate_limit_remaining' );
		$next = agend_apps_sites_get_config();

		$this->assertSame( 'agend_apps_rate_limited', $refused->get_error_code() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertIsArray( $next );
	}

	#[Test]
	public function should_serve_a_published_config_from_the_shared_entry_when_it_was_fetched_before(): void {
		$GLOBALS['agend_test_cache_ttls']['sites_config'] = 1234;
		Agend_Test_WP::queue_response( 200, self::CONFIG );

		$first  = agend_apps_sites_get_config();
		$second = agend_apps_sites_get_config();

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertSame( self::CONFIG['data'], $first['data'] );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, Agend_Test_WP::$transients['agend_apps_sites_config'] ?? null );
		$this->assertSame( array( 'agend_apps_sites_config' => 1234 ), $this->siteConfigTtls() );
	}

	#[Test]
	public function should_ask_the_gateway_when_the_site_config_row_was_cleared_after_a_missing_config(): void {
		Agend_Test_WP::queue_response( 404, self::NOT_FOUND );
		Agend_Test_WP::queue_response( 200, self::CONFIG );
		agend_apps_sites_get_config();
		agend_apps_sites_get_config();
		$this->assertCount( 1, Agend_Test_WP::$requests, 'the missing config is remembered before the clear' );

		\Agend_Test_Site_Config_Real_Cache::clear( 'sites_config' );
		$this->applyOptionDeletes();
		$after = agend_apps_sites_get_config();

		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertSame( self::CONFIG['data'], $after['data'] );
	}

	#[Test]
	public function should_forget_a_missing_config_under_the_production_cache_key_when_the_row_is_cleared(): void {
		$cache_key = \Agend_Test_Site_Config_Real_Cache::build_key( 'sites_config', array() );
		Agend_Test_WP::queue_response( 404, self::NOT_FOUND );
		agend_apps_sites_remember_missing_config( $cache_key, 60, agend_apps_api()->request( 'GET', '/sites/config' ) );
		$this->assertNotNull( agend_apps_sites_remembered_missing_config( $cache_key, 60 ) );

		\Agend_Test_Site_Config_Real_Cache::clear( 'sites_config' );
		$this->applyOptionDeletes();

		$this->assertNull( agend_apps_sites_remembered_missing_config( $cache_key, 60 ) );
	}

	#[Test]
	public function should_ask_the_gateway_when_all_caches_were_cleared_after_a_missing_config(): void {
		Agend_Test_WP::queue_response( 404, self::NOT_FOUND );
		agend_apps_sites_get_config();
		agend_apps_sites_get_config();
		$this->assertCount( 1, Agend_Test_WP::$requests, 'the missing config is remembered before the clear' );

		\Agend_Test_Site_Config_Real_Cache::clear_all();
		$this->applyOptionDeletes();
		agend_apps_sites_get_config();

		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_send_no_member_token_when_a_signed_in_visitor_reads_the_site_config(): void {
		$resolved = 0;
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function () use ( &$resolved ): string {
			++$resolved;
			return 'member-token-unresolved';
		};

		agend_apps_sites_get_config();

		$this->assertSame( 0, $resolved, 'resolving the token can mint one' );
		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'] );
	}

	#[Test]
	public function should_serve_an_anonymous_visitor_the_config_a_signed_in_visitor_fetched(): void {
		Agend_Test_WP::queue_response( 200, self::CONFIG );

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token-shared-config' );
		$member = agend_apps_sites_get_config();

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );
		$anonymous = agend_apps_sites_get_config();

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertSame( $member, $anonymous );
	}

	#[Test]
	public function should_serve_a_signed_in_visitor_the_missing_config_an_anonymous_visitor_was_told(): void {
		Agend_Test_WP::queue_response( 404, self::NOT_FOUND );

		$anonymous = agend_apps_sites_get_config();

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token-shared-missing' );
		$member = agend_apps_sites_get_config();

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertEquals( $anonymous, $member );
	}

	#[Test]
	public function should_still_send_the_member_token_on_other_reads_when_the_site_config_was_read(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token-other-reads' );

		agend_apps_sites_get_config();
		agend_apps_api()->get_cached( '/events', array(), 'events_list_x', 60 );

		$this->assertSame( 'Bearer member-token-other-reads', Agend_Test_WP::$requests[1]['headers']['Authorization'] ?? null );
	}

	#[Test]
	public function should_send_the_member_token_when_the_args_filter_drops_the_anonymous_read(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token-filter-opt-out' );
		Agend_Test_WP::set_filter( 'agend_apps_sites_get_config_args', array() );

		agend_apps_sites_get_config();

		$this->assertSame( 'Bearer member-token-filter-opt-out', Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? null );
	}

	/**
	 * Stores what a `GET /v1/health` response would have reported for the key.
	 *
	 * @param string[] $scopes  Scopes held.
	 * @param string[] $app_ids Apps the key is restricted to; empty for none.
	 */
	private function knowScopes( array $scopes, array $app_ids = array() ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => $app_ids ) ) );
	}

	/**
	 * @return array<string, int>
	 */
	private function siteConfigTtls(): array {
		return array_filter(
			Agend_Test_WP::$transient_ttls,
			static fn( $key ): bool => 0 === strpos( (string) $key, 'agend_apps_sites_config' ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Applies every `option_name LIKE` delete sent to wpdb to the stored
	 * transients, whose rows are `_transient_<name>`.
	 */
	private function applyOptionDeletes(): void {
		$this->assertNotEmpty( $GLOBALS['wpdb']->queries, 'clear() should have sent a delete' );

		foreach ( $GLOBALS['wpdb']->queries as $sql ) {
			if ( 0 !== strpos( $sql, 'DELETE FROM' ) || ! preg_match_all( "/option_name LIKE '([^']*)'/", $sql, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $like ) {
				$regex = $this->likeToRegex( stripslashes( $like ) );

				foreach ( array_keys( Agend_Test_WP::$transients ) as $name ) {
					if ( 1 === preg_match( $regex, '_transient_' . $name ) ) {
						unset( Agend_Test_WP::$transients[ $name ], Agend_Test_WP::$transient_ttls[ $name ] );
					}
				}
			}
		}
	}

	private function likeToRegex( string $like ): string {
		$regex = '';

		for ( $i = 0, $length = strlen( $like ); $i < $length; $i++ ) {
			$char = $like[ $i ];

			if ( '\\' === $char && $i + 1 < $length ) {
				$regex .= preg_quote( $like[ ++$i ], '/' );
			} elseif ( '%' === $char ) {
				$regex .= '.*';
			} elseif ( '_' === $char ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $char, '/' );
			}
		}

		return '/^' . $regex . '$/s';
	}
}
