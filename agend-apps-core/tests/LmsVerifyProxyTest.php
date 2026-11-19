<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_LMS_REST_Controller;
use Agend_Apps_Logger;
use Agend_Test_WP;
use Agend\Tests\AppsCore\MemoryLogStore;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/memory-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';

/**
 * The public certificate and badge verify proxies
 * (`GET /agend-apps/v1/lms/{badges,certificates}/verify/{code}`) and the two
 * PHP wrappers behind them: the code allow-list, the per-visitor and site-wide
 * throttle, and that the call goes out as a visitor's lookup, with the site key
 * alone, and isolated from the site-wide rate-limit state.
 *
 * Each case runs in its own process: the real LMS wrappers are declared here,
 * and other tests declare doubles for some of them.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass( Agend_Apps_LMS_REST_Controller::class )]
final class LmsVerifyProxyTest extends TestCase {

	private const VER = 'VER-3fa91c0b7d2e48a1b6c5';

	private const TOKEN = '0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/public-throttle.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/credential-guard.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/lms.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/lms-routes.php';

		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tearDown();
	}

	private function controller(): Agend_Apps_LMS_REST_Controller {
		return new Agend_Apps_LMS_REST_Controller();
	}

	/**
	 * A request as WordPress hands it to the callback: the parameter passed
	 * through the sanitize callback the route declares for it.
	 */
	private function request( string $kind, string $code ): WP_REST_Request {
		$param   = 'badge' === $kind ? 'token' : 'code';
		$request = new WP_REST_Request( 'GET', '/agend-apps/v1/lms/' . ( 'badge' === $kind ? 'badges' : 'certificates' ) . '/verify/' . $code );
		$request->set_param( $param, sanitize_text_field( $code ) );

		return $request;
	}

	private function verify_certificate( string $code = self::VER ): WP_REST_Response {
		return $this->controller()->verify_certificate( $this->request( 'certificate', $code ) );
	}

	private function verify_badge( string $code = self::TOKEN ): WP_REST_Response {
		return $this->controller()->verify_badge( $this->request( 'badge', $code ) );
	}

	/**
	 * @return array<int, array<string, mixed>> The requests that went to the gateway.
	 */
	private function gateway_requests(): array {
		return array_values(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $r ): bool => false !== strpos( $r['url'], '/lms/' )
			)
		);
	}

	private function queue_valid_certificate(): void {
		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'valid'       => true,
					'certificate' => array( 'course_title' => 'Ethics in Practice' ),
				),
			)
		);
	}

	// -----------------------------------------------------------------
	// Routes
	// -----------------------------------------------------------------

	#[Test]
	public function should_keep_both_routes_public_and_readable(): void {
		$this->controller()->register_routes();

		foreach ( array( '/lms/badges/verify/(?P<token>[a-zA-Z0-9_-]+)', '/lms/certificates/verify/(?P<code>[a-zA-Z0-9_-]+)' ) as $route ) {
			$matches = array_values(
				array_filter(
					Agend_Test_WP::$rest_routes,
					static fn( array $r ): bool => $route === $r['route']
				)
			);

			$this->assertCount( 1, $matches, $route );
			$this->assertSame( 'GET', $matches[0]['args'][0]['methods'] );
			$this->assertSame( '__return_true', $matches[0]['args'][0]['permission_callback'] );
		}
	}

	// -----------------------------------------------------------------
	// A good lookup
	// -----------------------------------------------------------------

	#[Test]
	public function should_look_a_certificate_up_and_return_what_the_gateway_said(): void {
		$this->queue_valid_certificate();

		$response = $this->verify_certificate();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['data']['valid'] );
		$this->assertCount( 1, $this->gateway_requests() );
		$this->assertStringEndsWith( '/lms/certificates/verify/' . self::VER, $this->gateway_requests()[0]['url'] );
	}

	#[Test]
	public function should_look_a_badge_up_by_its_token(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'valid' => true ) ) );

		$response = $this->verify_badge();

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '/lms/badges/verify/' . self::TOKEN, $this->gateway_requests()[0]['url'] );
	}

	#[Test]
	public function should_send_the_code_in_the_case_the_gateway_matches(): void {
		$this->queue_valid_certificate();

		$this->verify_certificate( 'Ver-3FA91C0B7D2E48A1B6C5' );

		$this->assertStringEndsWith( '/lms/certificates/verify/' . self::VER, $this->gateway_requests()[0]['url'] );
	}

	#[Test]
	public function should_never_let_a_shared_cache_keep_an_answer(): void {
		$this->queue_valid_certificate();
		$this->assertSame( 'no-store', $this->verify_certificate()->get_headers()['Cache-Control'] );

		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'code' => 'BOOM' ) ) );
		$failed = $this->verify_certificate();
		$this->assertGreaterThanOrEqual( 400, $failed->get_status() );
		$this->assertSame( 'no-store', $failed->get_headers()['Cache-Control'] );
	}

	#[Test]
	public function should_pass_a_gateway_failure_through_as_an_error_not_as_not_found(): void {
		Agend_Test_WP::queue_response( 503, array( 'error' => array( 'code' => 'UNAVAILABLE' ) ) );

		$response = $this->verify_certificate();

		$this->assertSame( 503, $response->get_status() );
	}

	// -----------------------------------------------------------------
	// Input allow-list
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{string, string}> Route kind, input.
	 */
	public static function refused_inputs(): array {
		return array(
			'certificate number on the certificate route'    => array( 'certificate', 'CERT-2026-000123' ),
			'lower case certificate number'                  => array( 'certificate', 'cert-2026-000123' ),
			'seeded certificate number'                      => array( 'certificate', 'WDAA-REACT-DC-001' ),
			'a hyphenated number of any kind'                => array( 'certificate', 'ABC-123-456' ),
			'too short'                                      => array( 'certificate', 'abc123' ),
			'a UUID on the certificate route'                => array( 'certificate', '3f2b8c1e-5a4d-4e6f-9b7a-1c2d3e4f5a6b' ),
			'a badge token on the certificate route'         => array( 'certificate', self::TOKEN ),
			'certificate number on the badge route'          => array( 'badge', 'CERT-2026-000123' ),
			'a verification code on the badge route'         => array( 'badge', self::VER ),
			'a UUID on the badge route'                      => array( 'badge', '3f2b8c1e-5a4d-4e6f-9b7a-1c2d3e4f5a6b' ),
			'a 31 character token on the badge route'        => array( 'badge', '0123456789abcdef0123456789abcde' ),
		);
	}

	#[Test]
	#[DataProvider( 'refused_inputs' )]
	public function should_refuse_a_code_that_is_not_an_accepted_shape_without_calling_the_gateway_or_counting_it( string $kind, string $input ): void {
		$response = 'badge' === $kind ? $this->verify_badge( $input ) : $this->verify_certificate( $input );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'agend_apps_invalid_credential_code', $response->get_data()['code'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( array(), Agend_Test_WP::$requests, 'nothing went to the gateway' );
		$this->assertSame( array(), Agend_Test_WP::$transients, 'nothing was counted against the visitor or the site' );
	}

	#[Test]
	public function should_not_spend_a_visitors_limit_on_codes_it_refuses(): void {
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame( 400, $this->verify_certificate( 'CERT-2026-' . str_pad( (string) $i, 6, '0', STR_PAD_LEFT ) )->get_status() );
		}

		$this->queue_valid_certificate();

		$this->assertSame( 200, $this->verify_certificate()->get_status(), 'a real code is still allowed after 30 refused numbers' );
	}

	#[Test]
	public function should_refuse_a_certificate_number_in_the_wrapper_too_so_a_php_caller_cannot_walk_the_sequence(): void {
		$result = agend_apps_lms_verify_certificate( 'CERT-2026-000123' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_apps_invalid_credential_code', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status_code'] );
		$this->assertSame( array(), Agend_Test_WP::$requests );

		$badge = agend_apps_lms_verify_badge( self::VER );

		$this->assertInstanceOf( WP_Error::class, $badge );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// The call itself
	// -----------------------------------------------------------------

	#[Test]
	public function should_send_the_site_key_and_never_the_signed_in_members_token(): void {
		add_filter( 'agend_apps_bearer_token', static fn(): string => 'member-jwt' );
		$this->queue_valid_certificate();
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'valid' => true ) ) );

		$this->verify_certificate();
		$this->verify_badge();

		foreach ( $this->gateway_requests() as $request ) {
			$this->assertArrayHasKey( 'x-api-key', $request['headers'] );
			$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
		}
	}

	#[Test]
	public function should_not_stop_for_the_site_wide_rate_limit_state(): void {
		// Another part of the site has used the key's whole minute. A visitor's
		// lookup is governed by its own limits and still goes out.
		set_transient( 'agend_apps_rate_limit_remaining', 0, 60 );
		set_transient( 'agend_apps_rate_limit_reset', (string) ( time() + 60 ), 60 );
		$this->queue_valid_certificate();

		$this->assertSame( 200, $this->verify_certificate()->get_status() );
		$this->assertCount( 1, $this->gateway_requests() );
	}

	#[Test]
	public function should_not_record_a_gateway_rate_limit_as_the_site_wide_state(): void {
		Agend_Test_WP::queue_response(
			429,
			array( 'error' => array( 'code' => 'RATE_LIMIT_EXCEEDED' ) ),
			array(
				'X-RateLimit-Remaining' => '0',
				'X-RateLimit-Reset'     => (string) ( time() + 60 ),
			)
		);

		$response = $this->verify_certificate();

		$this->assertSame( 429, $response->get_status() );
		$this->assertFalse( get_transient( 'agend_apps_rate_limit_remaining' ), 'the stop flag every other Agend call obeys is not set' );
		$this->assertFalse( get_transient( 'agend_apps_rate_limit_reset' ) );
	}

	#[Test]
	public function should_let_a_site_filter_the_lookup_args_after_the_defaults_are_set(): void {
		$seen = array();
		add_filter(
			'agend_apps_lms_verify_certificate_args',
			static function ( array $args ) use ( &$seen ): array {
				$seen = $args;

				return $args;
			}
		);
		$this->queue_valid_certificate();

		$this->verify_certificate();

		$this->assertTrue( $seen['unattended'] );
		$this->assertTrue( $seen['isolated_rate_limit'] );
	}

	// -----------------------------------------------------------------
	// Per-visitor throttle
	// -----------------------------------------------------------------

	#[Test]
	public function should_allow_ten_lookups_a_minute_to_a_visitor_and_refuse_the_eleventh_before_the_gateway(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->queue_valid_certificate();
			$this->assertSame( 200, $this->verify_certificate()->get_status(), 'lookup ' . $i );
		}

		$response = $this->verify_certificate();

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'agend_apps_credential_rate_limited', $response->get_data()['code'] );
		$this->assertCount( 10, $this->gateway_requests(), 'the refused lookup never reached the gateway' );
	}

	#[Test]
	public function should_tell_a_refused_visitor_how_long_to_wait_and_never_cache_it(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->queue_valid_certificate();
			$this->verify_certificate();
		}

		$response = $this->verify_certificate();
		$headers  = $response->get_headers();

		$this->assertSame( 'no-store', $headers['Cache-Control'] );
		$this->assertGreaterThanOrEqual( 1, (int) $headers['Retry-After'] );
		$this->assertLessThanOrEqual( 60, (int) $headers['Retry-After'] );
		$this->assertSame( (int) $headers['Retry-After'], $response->get_data()['data']['retry_after'] );
		$this->assertSame( 429, $response->get_data()['data']['status_code'] );
	}

	#[Test]
	public function should_share_one_allowance_between_the_two_routes(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->queue_valid_certificate();
			$this->verify_certificate();
			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'valid' => true ) ) );
			$this->verify_badge();
		}

		$this->assertSame( 429, $this->verify_badge()->get_status() );
		$this->assertSame( 429, $this->verify_certificate()->get_status() );
	}

	#[Test]
	public function should_count_a_lookup_that_found_nothing_the_same_as_one_that_did(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'valid' => false ) ) );
			$this->verify_certificate();
		}

		$this->assertSame( 429, $this->verify_certificate()->get_status() );
	}

	#[Test]
	public function should_leave_other_visitors_alone_when_one_is_refused(): void {
		for ( $i = 1; $i <= 11; $i++ ) {
			$this->queue_valid_certificate();
			$this->verify_certificate();
		}

		$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
		$this->queue_valid_certificate();

		$this->assertSame( 200, $this->verify_certificate()->get_status() );
	}

	#[Test]
	public function should_not_let_a_forwarded_for_header_change_which_visitor_is_counted(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.' . $i;
			$this->queue_valid_certificate();
			$this->verify_certificate();
		}

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.200';

		$this->assertSame( 429, $this->verify_certificate()->get_status(), 'varying the header does not make a fresh visitor' );
	}

	#[Test]
	public function should_make_visitors_with_no_usable_address_share_one_allowance(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		for ( $i = 1; $i <= 10; $i++ ) {
			$this->queue_valid_certificate();
			$this->verify_certificate();
		}

		$this->assertSame( 429, $this->verify_certificate()->get_status() );
	}

	#[Test]
	public function should_use_the_limits_a_site_sets(): void {
		add_filter(
			'agend_apps_credential_check_limits',
			static fn( array $limits ): array => array_merge( $limits, array( 'visitor_per_minute' => 2 ) )
		);

		$this->queue_valid_certificate();
		$this->queue_valid_certificate();
		$this->verify_certificate();
		$this->verify_certificate();

		$this->assertSame( 429, $this->verify_certificate()->get_status() );
		$this->assertCount( 2, $this->gateway_requests() );
	}

	// -----------------------------------------------------------------
	// Site-wide ceiling
	// -----------------------------------------------------------------

	#[Test]
	public function should_stop_at_twenty_lookups_a_minute_across_every_visitor(): void {
		for ( $i = 1; $i <= 20; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.' . $i;
			$this->queue_valid_certificate();
			$this->assertSame( 200, $this->verify_certificate()->get_status(), 'visitor ' . $i );
		}

		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
		$response               = $this->verify_certificate();

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'agend_apps_credential_busy', $response->get_data()['code'], 'the site is busy, not this visitor over their limit' );
		$this->assertGreaterThanOrEqual( 1, (int) $response->get_headers()['Retry-After'] );
		$this->assertCount( 20, $this->gateway_requests() );
	}

	#[Test]
	public function should_not_let_a_refused_code_use_the_site_ceiling(): void {
		for ( $i = 1; $i <= 40; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . $i;
			$this->verify_certificate( 'CERT-2026-000123' );
		}

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->queue_valid_certificate();

		$this->assertSame( 200, $this->verify_certificate()->get_status() );
	}
}
