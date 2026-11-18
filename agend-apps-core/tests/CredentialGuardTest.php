<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/public-throttle.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/credential-guard.php';

/**
 * The guard on every public certificate and badge lookup: the code allow-list,
 * the limits, and the two counters (per visitor and per site) that enforce them.
 */
final class CredentialGuardTest extends TestCase {

	private const NOW = 1_700_000_000;

	private const VER = 'VER-3fa91c0b7d2e48a1b6c5';

	private const TOKEN = '0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Allow-list
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{string, string, string}> Input, kind, canonical code.
	 */
	public static function accepted_codes(): array {
		return array(
			'verification code'                    => array( self::VER, 'certificate', self::VER ),
			'verification code, first letter only' => array( 'Ver-3fa91c0b7d2e48a1b6c5', 'certificate', self::VER ),
			'verification code, all upper case'    => array( 'VER-3FA91C0B7D2E48A1B6C5', 'certificate', self::VER ),
			'verification code with whitespace'    => array( "  VER-3fa91c0b7d2e48a1b6c5 \n", 'certificate', self::VER ),
			'badge token'                          => array( self::TOKEN, 'badge', self::TOKEN ),
			'badge token in capitals'              => array( strtoupper( self::TOKEN ), 'badge', self::TOKEN ),
			'legacy hex code in lower case'        => array( 'ab12cd34', 'certificate', 'AB12CD34' ),
			'legacy hex code'                      => array( 'AB12CD34', 'certificate', 'AB12CD34' ),
			'legacy code, longer, sent as typed'   => array( 'Abc12xyz90Zq', 'certificate', 'Abc12xyz90Zq' ),
			'legacy code, 9 characters as typed'   => array( 'Abcdefghi', 'certificate', 'Abcdefghi' ),
		);
	}

	#[Test]
	#[DataProvider( 'accepted_codes' )]
	public function should_accept_a_real_code_shape_and_return_it_in_the_case_the_gateway_matches( string $input, string $kind, string $canonical ): void {
		$this->assertSame(
			array(
				'kind' => $kind,
				'code' => $canonical,
			),
			agend_apps_credential_parse_code( $input )
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function refused_codes(): array {
		return array(
			'certificate number'                 => array( 'CERT-2026-000123' ),
			'certificate number, lower case'     => array( 'cert-2026-000123' ),
			'seeded certificate number'          => array( 'WDAA-REACT-DC-001' ),
			'any hyphenated number'              => array( 'ABC-123-456' ),
			'empty'                              => array( '' ),
			'whitespace only'                    => array( "  \t\n" ),
			'seven characters'                   => array( 'ab12cd3' ),
			'thirteen characters'                => array( 'ab12cd34ef567' ),
			'verification code, 19 hex'          => array( 'VER-3fa91c0b7d2e48a1b6c' ),
			'verification code, 21 hex'          => array( 'VER-3fa91c0b7d2e48a1b6c5a' ),
			'verification code, not hex'         => array( 'VER-3fa91c0b7d2e48a1b6cz' ),
			'verification code, no hyphen'       => array( 'VER3fa91c0b7d2e48a1b6c5' ),
			'verification code, trailing text'   => array( 'VER-3fa91c0b7d2e48a1b6c5 and more' ),
			'badge token, 31 characters'         => array( '0123456789abcdef0123456789abcde' ),
			'badge token, 33 characters'         => array( '0123456789abcdef0123456789abcdef0' ),
			'badge token, not hex'               => array( '0123456789abcdef0123456789abcdez' ),
			'internal badge id (a UUID)'         => array( '3f2b8c1e-5a4d-4e6f-9b7a-1c2d3e4f5a6b' ),
			'embedded newline'                   => array( "ab12cd34\nab12cd34" ),
			'embedded null byte'                 => array( "ab12\0cd34" ),
			'a path'                             => array( '../../crm/contacts' ),
			'a query string'                     => array( 'ab12cd34?x=1' ),
			'much too long'                      => array( str_repeat( 'a', 5000 ) ),
		);
	}

	#[Test]
	#[DataProvider( 'refused_codes' )]
	public function should_refuse_every_other_shape_including_every_certificate_number( string $input ): void {
		$this->assertNull( agend_apps_credential_parse_code( $input ) );
		$this->assertNull( agend_apps_credential_parse_code( $input, 'certificate' ) );
		$this->assertNull( agend_apps_credential_parse_code( $input, 'badge' ) );
	}

	#[Test]
	public function should_accept_only_the_kind_a_route_is_for(): void {
		$this->assertNotNull( agend_apps_credential_parse_code( self::VER, 'certificate' ) );
		$this->assertNull( agend_apps_credential_parse_code( self::VER, 'badge' ), 'a certificate code on the badge route' );
		$this->assertNotNull( agend_apps_credential_parse_code( self::TOKEN, 'badge' ) );
		$this->assertNull( agend_apps_credential_parse_code( self::TOKEN, 'certificate' ), 'a badge token on the certificate route' );
		$this->assertNull( agend_apps_credential_parse_code( 'ab12cd34', 'badge' ), 'a legacy code is never a badge' );
	}

	#[Test]
	public function should_let_a_site_add_a_code_shape_with_a_filter(): void {
		add_filter(
			'agend_apps_credential_code_shapes',
			static function ( array $shapes ): array {
				$shapes['club'] = array(
					'kind'      => 'certificate',
					'pattern'   => '/^CLUB-[0-9]{6}$/iD',
					'normalise' => 'strtoupper',
				);

				return $shapes;
			}
		);

		$this->assertSame(
			array(
				'kind' => 'certificate',
				'code' => 'CLUB-123456',
			),
			agend_apps_credential_parse_code( 'club-123456' )
		);
		$this->assertNull( agend_apps_credential_parse_code( 'CERT-2026-000123' ), 'the standard refusals still hold' );
	}

	#[Test]
	public function should_ignore_a_filtered_shape_that_is_incomplete_or_cannot_be_called(): void {
		add_filter(
			'agend_apps_credential_code_shapes',
			static fn(): array => array(
				'no pattern'      => array(
					'kind'      => 'certificate',
					'normalise' => 'strtoupper',
				),
				'not callable'    => array(
					'kind'      => 'certificate',
					'pattern'   => '/^CLUB-[0-9]{6}$/D',
					'normalise' => 'no_such_function_anywhere',
				),
				'not an array'    => 'CLUB-.*',
			)
		);

		$this->assertNull( agend_apps_credential_parse_code( 'CLUB-123456' ) );
		$this->assertNull( agend_apps_credential_parse_code( self::VER ), 'the filter replaced the list, so nothing else matches' );
	}

	// -----------------------------------------------------------------
	// Limits
	// -----------------------------------------------------------------

	#[Test]
	public function should_default_to_ten_a_minute_and_sixty_an_hour_per_visitor_and_twenty_a_minute_per_site(): void {
		$this->assertSame(
			array(
				'visitor_per_minute' => 10,
				'visitor_per_hour'   => 60,
				'site_per_minute'    => 20,
			),
			agend_apps_credential_check_limits()
		);
	}

	#[Test]
	public function should_let_a_filter_change_a_limit(): void {
		add_filter(
			'agend_apps_credential_check_limits',
			static fn( array $limits ): array => array_merge( $limits, array( 'visitor_per_minute' => 3 ) )
		);

		$this->assertSame( 3, agend_apps_credential_check_limits()['visitor_per_minute'] );
		$this->assertSame( 60, agend_apps_credential_check_limits()['visitor_per_hour'] );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function nonsense_limits(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -5 ),
			'text'     => array( 'lots' ),
			'null'     => array( null ),
			'false'    => array( false ),
			'array'    => array( array( 5 ) ),
		);
	}

	#[Test]
	#[DataProvider( 'nonsense_limits' )]
	public function should_fall_back_to_the_default_rather_than_switch_the_throttle_off( $value ): void {
		add_filter(
			'agend_apps_credential_check_limits',
			static fn(): array => array(
				'visitor_per_minute' => $value,
				'visitor_per_hour'   => $value,
				'site_per_minute'    => $value,
			)
		);

		$this->assertSame( 10, agend_apps_credential_check_limits()['visitor_per_minute'] );
		$this->assertSame( 60, agend_apps_credential_check_limits()['visitor_per_hour'] );
		$this->assertSame( 20, agend_apps_credential_check_limits()['site_per_minute'] );
	}

	// -----------------------------------------------------------------
	// Visitor address
	// -----------------------------------------------------------------

	#[Test]
	public function should_read_remote_addr_and_ignore_a_forwarded_for_header(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';

		$this->assertSame( '203.0.113.7', agend_apps_visitor_ip() );
	}

	#[Test]
	public function should_let_a_site_behind_a_trusted_proxy_supply_the_address(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
		Agend_Test_WP::set_filter( 'agend_apps_visitor_ip', '198.51.100.44' );

		$this->assertSame( '198.51.100.44', agend_apps_visitor_ip() );
	}

	#[Test]
	public function should_return_nothing_when_no_valid_address_can_be_established(): void {
		$this->assertSame( '', agend_apps_visitor_ip(), 'no REMOTE_ADDR' );

		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
		$this->assertSame( '', agend_apps_visitor_ip() );
	}

	// -----------------------------------------------------------------
	// Visitor buckets
	// -----------------------------------------------------------------

	#[Test]
	public function should_count_an_ipv4_address_as_itself(): void {
		$this->assertSame( '203.0.113.7', agend_apps_visitor_bucket( '203.0.113.7' ) );
	}

	#[Test]
	public function should_count_an_ipv6_address_against_its_slash_64(): void {
		$this->assertSame(
			agend_apps_visitor_bucket( '2001:db8:1:2:aaaa:bbbb:cccc:dddd' ),
			agend_apps_visitor_bucket( '2001:db8:1:2:1111:2222:3333:4444' )
		);
		$this->assertNotSame(
			agend_apps_visitor_bucket( '2001:db8:1:2::1' ),
			agend_apps_visitor_bucket( '2001:db8:1:3::1' )
		);
		$this->assertSame( '2001:db8:1:2::/64', agend_apps_visitor_bucket( '2001:db8:1:2:aaaa::1' ) );
	}

	#[Test]
	public function should_count_an_ipv4_mapped_address_as_the_ipv4_address_it_carries(): void {
		$this->assertSame( '203.0.113.7', agend_apps_visitor_bucket( '::ffff:203.0.113.7' ) );
		$this->assertNotSame( agend_apps_visitor_bucket( '::ffff:203.0.113.7' ), agend_apps_visitor_bucket( '::ffff:203.0.113.8' ) );
	}

	#[Test]
	public function should_put_every_visitor_with_no_usable_address_in_one_bucket(): void {
		$this->assertSame( 'unknown', agend_apps_visitor_bucket( '' ) );
		$this->assertSame( 'unknown', agend_apps_visitor_bucket( 'garbage' ) );
	}

	// -----------------------------------------------------------------
	// Visitor throttle
	// -----------------------------------------------------------------

	/**
	 * @return int How many hits from the address were allowed before the first refusal.
	 */
	private function hits_allowed_in_one_moment( string $ip, int $now, int $try = 100 ): int {
		for ( $i = 0; $i < $try; $i++ ) {
			if ( agend_apps_credential_throttle_visitor( $ip, $now ) > 0 ) {
				return $i;
			}
		}

		return $try;
	}

	#[Test]
	public function should_allow_ten_lookups_a_minute_to_one_visitor_and_refuse_the_eleventh(): void {
		$this->assertSame( 10, $this->hits_allowed_in_one_moment( '203.0.113.7', self::NOW ) );
	}

	#[Test]
	public function should_say_how_long_to_wait_and_never_less_than_one_second(): void {
		$this->hits_allowed_in_one_moment( '203.0.113.7', self::NOW );

		$this->assertSame( 60, agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW ) );
		$this->assertSame( 21, agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW + 39 ) );
		$this->assertSame( 1, agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW + 59 ) );
	}

	#[Test]
	public function should_let_the_visitor_back_in_when_the_minute_closes(): void {
		$this->hits_allowed_in_one_moment( '203.0.113.7', self::NOW );

		$this->assertSame( 0, agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW + 60 ) );
	}

	#[Test]
	public function should_not_extend_a_window_by_hits_made_inside_it(): void {
		agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW );

		for ( $i = 0; $i < 8; $i++ ) {
			agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW + 50 );
		}

		// Ten hits so far in a window that opened at NOW: the eleventh waits for
		// that window to close, not for a quiet minute after the last hit.
		$this->assertSame( 0, agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW + 55 ) );
		$this->assertSame( 5, agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW + 55 ) );
	}

	#[Test]
	public function should_allow_sixty_lookups_an_hour_to_one_visitor_however_they_are_spread(): void {
		// Two lookups every minute never fill a minute window, so only the hour
		// limit can stop this: 60 get through and the rest wait for the hour.
		$allowed = 0;

		for ( $i = 0; $i < 70; $i++ ) {
			if ( 0 === agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW + ( $i * 30 ) + 1 ) ) {
				++$allowed;
			}
		}

		$this->assertSame( 60, $allowed, '60 allowed once the minute limit has been dealt with' );
	}

	#[Test]
	public function should_refuse_on_the_hour_limit_with_the_time_until_the_hour_closes(): void {
		// Six bursts of ten, a minute apart, use the hour up without ever being
		// refused by the minute limit.
		for ( $burst = 0; $burst < 6; $burst++ ) {
			$this->assertSame( 10, $this->hits_allowed_in_one_moment( '203.0.113.7', self::NOW + ( $burst * 60 ), 10 ) );
		}

		$wait = agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW + 360 );

		$this->assertSame( 3600 - 360, $wait );
		$this->assertSame( 0, agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW + 3600 ) );
	}

	#[Test]
	public function should_not_spend_the_hour_on_a_lookup_the_minute_refused(): void {
		$this->assertSame( 10, $this->hits_allowed_in_one_moment( '203.0.113.7', self::NOW ) );

		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertGreaterThan( 0, agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW + 1 ) );
		}

		// Ten counted so far, so 50 more fit in the hour: five bursts of ten.
		for ( $burst = 1; $burst <= 5; $burst++ ) {
			$this->assertSame( 10, $this->hits_allowed_in_one_moment( '203.0.113.7', self::NOW + ( $burst * 60 ), 10 ) );
		}

		$this->assertGreaterThan( 0, agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW + 360 ) );
	}

	#[Test]
	public function should_count_each_visitor_on_their_own(): void {
		$this->hits_allowed_in_one_moment( '203.0.113.7', self::NOW );

		$this->assertSame( 0, agend_apps_credential_throttle_visitor( '203.0.113.8', self::NOW ) );
	}

	#[Test]
	public function should_count_every_address_in_a_slash_64_as_one_visitor(): void {
		$this->assertSame( 10, $this->hits_allowed_in_one_moment( '2001:db8:1:2:aaaa::1', self::NOW ) );
		$this->assertGreaterThan( 0, agend_apps_credential_throttle_visitor( '2001:db8:1:2:bbbb::9', self::NOW ) );
		$this->assertSame( 0, agend_apps_credential_throttle_visitor( '2001:db8:1:3::1', self::NOW ) );
	}

	#[Test]
	public function should_make_every_visitor_with_no_usable_address_share_one_set_of_limits(): void {
		$this->assertSame( 10, $this->hits_allowed_in_one_moment( '', self::NOW ) );
		$this->assertGreaterThan( 0, agend_apps_credential_throttle_visitor( '', self::NOW ), 'a second such visitor is refused too' );
		$this->assertGreaterThan( 0, agend_apps_credential_throttle_visitor( 'garbage', self::NOW ) );
		$this->assertSame( 0, agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW ), 'a visitor with an address is unaffected' );
	}

	#[Test]
	public function should_honour_a_filtered_visitor_limit(): void {
		add_filter(
			'agend_apps_credential_check_limits',
			static fn( array $limits ): array => array_merge( $limits, array( 'visitor_per_minute' => 2 ) )
		);

		$this->assertSame( 2, $this->hits_allowed_in_one_moment( '203.0.113.7', self::NOW ) );
	}

	#[Test]
	public function should_store_nothing_that_names_the_visitor(): void {
		$ip = '203.0.113.7';
		$this->hits_allowed_in_one_moment( $ip, self::NOW, 3 );

		$this->assertNotSame( array(), Agend_Test_WP::$transients );

		foreach ( Agend_Test_WP::$transients as $key => $value ) {
			$this->assertStringNotContainsString( $ip, $key );
			$this->assertStringNotContainsString( '203', $key );
			$this->assertStringNotContainsString( $ip, (string) json_encode( $value ) );
			$this->assertSame( array( 'count', 'reset' ), array_keys( $value ), 'a count and a reset time, nothing else' );
		}
	}

	#[Test]
	public function should_expire_a_counter_when_its_window_closes(): void {
		agend_apps_credential_throttle_visitor( '203.0.113.7', self::NOW );

		$ttls = array_values( Agend_Test_WP::$transient_ttls );
		sort( $ttls );

		$this->assertSame( array( 60, 3600 ), $ttls );
	}

	// -----------------------------------------------------------------
	// Site ceiling
	// -----------------------------------------------------------------

	#[Test]
	public function should_allow_twenty_lookups_a_minute_across_the_whole_site(): void {
		$allowed = 0;

		for ( $i = 0; $i < 30; $i++ ) {
			if ( 0 === agend_apps_credential_throttle_site( self::NOW ) ) {
				++$allowed;
			}
		}

		$this->assertSame( 20, $allowed );
		$this->assertSame( 60, agend_apps_credential_throttle_site( self::NOW ) );
		$this->assertSame( 0, agend_apps_credential_throttle_site( self::NOW + 60 ) );
	}

	#[Test]
	public function should_keep_the_site_ceiling_apart_from_any_visitor_counter(): void {
		$this->hits_allowed_in_one_moment( '203.0.113.7', self::NOW );

		$this->assertSame( 0, agend_apps_credential_throttle_site( self::NOW ), 'a visitor over their limit has not touched the ceiling' );
	}

	#[Test]
	public function should_honour_a_filtered_site_ceiling(): void {
		add_filter(
			'agend_apps_credential_check_limits',
			static fn( array $limits ): array => array_merge( $limits, array( 'site_per_minute' => 3 ) )
		);

		$this->assertSame( 0, agend_apps_credential_throttle_site( self::NOW ) );
		$this->assertSame( 0, agend_apps_credential_throttle_site( self::NOW ) );
		$this->assertSame( 0, agend_apps_credential_throttle_site( self::NOW ) );
		$this->assertGreaterThan( 0, agend_apps_credential_throttle_site( self::NOW ) );
	}

	// -----------------------------------------------------------------
	// Both, in order
	// -----------------------------------------------------------------

	#[Test]
	public function should_say_which_limit_refused_a_lookup(): void {
		$this->assertSame(
			array(
				'reason'      => '',
				'retry_after' => 0,
			),
			agend_apps_credential_throttle( '203.0.113.7', self::NOW )
		);

		for ( $i = 0; $i < 9; $i++ ) {
			agend_apps_credential_throttle( '203.0.113.7', self::NOW );
		}

		$visitor = agend_apps_credential_throttle( '203.0.113.7', self::NOW );
		$this->assertSame( 'visitor', $visitor['reason'] );
		$this->assertSame( 60, $visitor['retry_after'] );
	}

	#[Test]
	public function should_say_busy_when_the_site_ceiling_is_reached_by_other_visitors(): void {
		for ( $i = 1; $i <= 20; $i++ ) {
			$this->assertSame( '', agend_apps_credential_throttle( '203.0.113.' . $i, self::NOW )['reason'] );
		}

		$busy = agend_apps_credential_throttle( '203.0.113.99', self::NOW );

		$this->assertSame( 'site', $busy['reason'] );
		$this->assertSame( 60, $busy['retry_after'] );
	}

	#[Test]
	public function should_not_spend_the_site_ceiling_on_a_visitor_who_is_already_over_their_limit(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			agend_apps_credential_throttle( '203.0.113.7', self::NOW );
		}

		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertSame( 'visitor', agend_apps_credential_throttle( '203.0.113.7', self::NOW )['reason'] );
		}

		// Ten lookups went through, so ten places of the ceiling are used, not sixty.
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertSame( '', agend_apps_credential_throttle( '198.51.100.' . $i, self::NOW )['reason'] );
		}

		$this->assertSame( 'site', agend_apps_credential_throttle( '198.51.100.99', self::NOW )['reason'] );
	}
}
