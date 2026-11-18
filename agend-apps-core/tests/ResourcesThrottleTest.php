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

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/resources-throttle.php';
require_once __DIR__ . '/fixtures/wp-hash-stub.php';

/**
 * The limits in front of the Resources proxies: one per visitor address, one
 * per member, and one for the whole site. The per-address policy is the one the
 * directory geocode route uses.
 */
final class ResourcesThrottleTest extends TestCase {

	private const VISITOR = '198.51.100.20';

	protected function setUp(): void {
		parent::setUp();
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'] );
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Whose hit it is
	// -----------------------------------------------------------------

	/** @return array<string, array{0: string, 1: string}> */
	public static function addresses(): array {
		return array(
			'ipv4 is its own bucket'          => array( '203.0.113.7', '203.0.113.7' ),
			'ipv6 is counted by its /64'      => array( '2001:db8:aa:1::ff', '2001:db8:aa:1::/64' ),
			'another host on the same /64'    => array( '2001:db8:aa:1:abcd:1234:5678:9abc', '2001:db8:aa:1::/64' ),
			'ipv4 mapped counts as the ipv4'  => array( '::ffff:203.0.113.7', '203.0.113.7' ),
			'something that is not an ip'     => array( 'not-an-ip', 'not-an-ip' ),
			'nothing'                         => array( '', '' ),
		);
	}

	#[Test]
	#[DataProvider( 'addresses' )]
	public function should_count_a_visitor_against_the_right_bucket( string $ip, string $bucket ): void {
		self::assertSame( $bucket, agend_apps_resources_throttle_bucket( $ip ) );
	}

	#[Test]
	public function should_not_let_a_host_escape_by_picking_a_fresh_ipv6_address_each_time(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8:aa:1::1';

		for ( $i = 0; $i < 10; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '2001:db8:aa:1::' . dechex( $i + 1 );
			self::assertSame( 0, agend_apps_resources_visitor_throttle( 'visitor_search', 10, 60, 1000 ) );
		}

		$_SERVER['REMOTE_ADDR'] = '2001:db8:aa:1::ffff';
		self::assertGreaterThan( 0, agend_apps_resources_visitor_throttle( 'visitor_search', 10, 60, 1000 ) );
	}

	// -----------------------------------------------------------------
	// The window
	// -----------------------------------------------------------------

	#[Test]
	public function should_let_a_subject_through_up_to_the_limit_then_say_how_long_to_wait(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			self::assertSame( 0, agend_apps_resources_throttle_hit( 'search', 'a', 10, 60, 1000 + $i ), 'hit ' . ( $i + 1 ) );
		}

		// The window opened at the first hit (1000) and closes 60 seconds on.
		self::assertSame( 51, agend_apps_resources_throttle_hit( 'search', 'a', 10, 60, 1009 ) );
	}

	#[Test]
	public function should_hold_to_a_fixed_window_that_starts_at_the_first_hit(): void {
		agend_apps_resources_throttle_hit( 'search', 'a', 1, 60, 1000 );

		self::assertSame( 1, agend_apps_resources_throttle_hit( 'search', 'a', 1, 60, 1059 ), 'a second before it closes' );
		self::assertSame( 0, agend_apps_resources_throttle_hit( 'search', 'a', 1, 60, 1060 ), 'a new window opens' );
	}

	#[Test]
	public function should_not_refuse_someone_who_searches_now_and_then(): void {
		for ( $minute = 0; $minute < 5; $minute++ ) {
			self::assertSame( 0, agend_apps_resources_throttle_hit( 'search', 'a', 2, 60, 1000 + $minute * 61 ) );
		}
	}

	#[Test]
	public function should_keep_subjects_and_scopes_apart(): void {
		agend_apps_resources_throttle_hit( 'search', 'a', 1, 60, 1000 );

		self::assertGreaterThan( 0, agend_apps_resources_throttle_hit( 'search', 'a', 1, 60, 1001 ) );
		self::assertSame( 0, agend_apps_resources_throttle_hit( 'search', 'b', 1, 60, 1001 ), 'another subject' );
		self::assertSame( 0, agend_apps_resources_throttle_hit( 'ask', 'a', 1, 60, 1001 ), 'another scope' );
	}

	#[Test]
	public function should_keep_the_transient_for_the_rest_of_the_window_only(): void {
		agend_apps_resources_throttle_hit( 'search', 'a', 5, 60, 1000 );
		self::assertSame( array( 60 ), array_values( Agend_Test_WP::$transient_ttls ) );

		agend_apps_resources_throttle_hit( 'search', 'a', 5, 60, 1030 );
		self::assertSame( array( 30 ), array_values( Agend_Test_WP::$transient_ttls ), 'it ends with the window, not a fresh minute later' );
	}

	// -----------------------------------------------------------------
	// Nothing identifying is stored
	// -----------------------------------------------------------------

	#[Test]
	public function should_store_a_hash_of_the_bucket_and_never_the_address(): void {
		$_SERVER['REMOTE_ADDR'] = self::VISITOR;

		agend_apps_resources_visitor_throttle( 'visitor_search', 10, 60, 1000 );

		$keys = array_keys( Agend_Test_WP::$transients );

		self::assertCount( 1, $keys );
		self::assertSame( 'agend_apps_rthrottle_visitor_search_' . wp_hash( self::VISITOR ), $keys[0] );
		self::assertStringNotContainsString( self::VISITOR, $keys[0] );
	}

	#[Test]
	public function should_key_an_ipv6_visitor_by_the_hash_of_the_network(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8:aa:1::ff';

		agend_apps_resources_visitor_throttle( 'visitor_search', 10, 60, 1000 );

		self::assertSame( array( 'agend_apps_rthrottle_visitor_search_' . wp_hash( '2001:db8:aa:1::/64' ) ), array_keys( Agend_Test_WP::$transients ) );
	}

	// -----------------------------------------------------------------
	// The visitor, the member, the site
	// -----------------------------------------------------------------

	#[Test]
	public function should_count_a_visitor_on_their_connection_address_and_not_on_a_header_they_can_set(): void {
		$_SERVER['REMOTE_ADDR']          = self::VISITOR;
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '10.0.0.2';

		for ( $i = 0; $i < 10; $i++ ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.' . $i;
			self::assertSame( 0, agend_apps_resources_visitor_throttle( 'visitor_search', 10, 60, 1000 ) );
		}

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.9.9.9';
		self::assertGreaterThan( 0, agend_apps_resources_visitor_throttle( 'visitor_search', 10, 60, 1000 ), 'varying the header does not help' );
	}

	#[Test]
	public function should_count_a_member_by_their_own_user_and_not_by_address(): void {
		$_SERVER['REMOTE_ADDR'] = self::VISITOR;

		self::assertSame( 0, agend_apps_resources_member_throttle( 'ask_member', 7, 1, 600, 1000 ) );
		self::assertGreaterThan( 0, agend_apps_resources_member_throttle( 'ask_member', 7, 1, 600, 1001 ) );
		self::assertSame( 0, agend_apps_resources_member_throttle( 'ask_member', 8, 1, 600, 1001 ), 'another member on the same address' );
	}

	#[Test]
	public function should_count_the_site_as_one_subject(): void {
		self::assertSame( 0, agend_apps_resources_site_throttle( 'site_calls', 2, 60, 1000 ) );
		self::assertSame( 0, agend_apps_resources_site_throttle( 'site_calls', 2, 60, 1001 ) );
		self::assertSame( 59, agend_apps_resources_site_throttle( 'site_calls', 2, 60, 1001 ) );
	}

	#[Test]
	public function should_let_a_site_change_a_limit_with_the_filter(): void {
		add_filter(
			'agend_apps_resources_throttle_limit',
			static function ( array $limit, string $scope ): array {
				return 'visitor_search' === $scope ? array( 'max' => 1, 'window' => 30 ) : $limit;
			},
			10,
			2
		);
		$_SERVER['REMOTE_ADDR'] = self::VISITOR;

		self::assertSame( 0, agend_apps_resources_visitor_throttle( 'visitor_search', 10, 60, 1000 ) );
		self::assertSame( 30, agend_apps_resources_visitor_throttle( 'visitor_search', 10, 60, 1000 ), 'one hit, in a 30 second window' );
	}

	#[Test]
	public function should_never_let_a_filter_make_a_limit_zero(): void {
		add_filter(
			'agend_apps_resources_throttle_limit',
			static function (): array {
				return array( 'max' => 0, 'window' => 0 );
			}
		);

		self::assertSame( array( 1, 1 ), agend_apps_resources_throttle_limit( 'visitor_search', 10, 60 ) );
	}

	#[Test]
	public function should_ship_the_decided_defaults(): void {
		self::assertSame( 10, AGEND_APPS_RESOURCES_VISITOR_SEARCH_LIMIT, 'the same number the geocode route uses' );
		self::assertSame( 60, AGEND_APPS_RESOURCES_VISITOR_SEARCH_WINDOW );
		self::assertSame( 30, AGEND_APPS_RESOURCES_MEMBER_SEARCH_LIMIT );
		self::assertSame( 3, AGEND_APPS_RESOURCES_ASK_MEMBER_LIMIT );
		self::assertSame( 600, AGEND_APPS_RESOURCES_ASK_MEMBER_WINDOW, '3 a member every 10 minutes' );
		self::assertSame( 30, AGEND_APPS_RESOURCES_ASK_SITE_LIMIT );
		self::assertSame( 86400, AGEND_APPS_RESOURCES_ASK_SITE_WINDOW, '30 a site a day' );
		self::assertSame( 20, AGEND_APPS_RESOURCES_SITE_CALL_LIMIT, 'leaves 40 of the key\'s 60 a minute for the pages' );
	}
}
