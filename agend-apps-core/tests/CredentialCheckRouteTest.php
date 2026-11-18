<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Captcha;
use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Key_Scopes;
use Agend_Apps_LMS_REST_Controller;
use Agend_Apps_Logger;
use Agend_Apps_Secret_Store;
use Agend_Test_WP;
use Agend\Tests\AppsCore\MemoryLogStore;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;
use WP_REST_Response;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/memory-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/enquiry-stubs.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-captcha.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';

/**
 * What a public check of a certificate or badge code does and says: how a
 * pasted link becomes a code, the order a visitor is refused in, what counts
 * as found, what is shown, and that "could not check" is never "not found".
 *
 * The route is the POST `lms/credentials/verify` handler; the logic under it
 * is {@see agend_apps_credentials_handle()}.
 *
 * Each case runs in its own process: the real LMS wrappers are declared here,
 * and other tests declare doubles for some of them.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass( Agend_Apps_LMS_REST_Controller::class )]
final class CredentialCheckRouteTest extends TestCase {

	private const VER = 'VER-3fa91c0b7d2e48a1b6c5';

	private const TOKEN = '0123456789abcdef0123456789abcdef';

	private const NOW = 1_700_000_000;

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/public-throttle.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/credential-guard.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/lms.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/credential-check.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/lms-routes.php';

		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', true );
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$this->know( array( 'lms.certificates.verify', 'lms.badges.verify' ) );
	}

	protected function tearDown(): void {
		Agend_Apps_Secret_Store::delete( Agend_Apps_Captcha::SECRET_STORE_KEY );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tearDown();
	}

	private function know( array $scopes ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array() ) ) );
	}

	private function use_turnstile(): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, Agend_Apps_Captcha::PROVIDER_TURNSTILE );
		update_option( Agend_Apps_Captcha::OPTION_SITE_KEY, 'site-key' );
		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'captcha-secret' );
	}

	/**
	 * One check as the route runs it.
	 *
	 * @return array{http: int, body: array}
	 */
	private function handle( string $input = self::VER, string $token = '', bool $holder = false, string $ip = '203.0.113.7', int $now = self::NOW ): array {
		return agend_apps_credentials_handle( $input, $token, $holder, $ip, $now );
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

	private function certificate( array $overrides = array() ): array {
		return array(
			'data' => array(
				'valid'       => true,
				'verified_at' => '2026-11-18T05:42:10Z',
				'certificate' => $overrides + array(
					'id'                 => '3f2b8c1e-5a4d-4e6f-9b7a-1c2d3e4f5a6b',
					'certificate_number' => 'CERT-2026-000123',
					'course_title'       => 'Ethics in Practice',
					'course_category'    => 'ethics',
					'topics'             => array( 'Ethics', 'Conflicts of interest' ),
					'issued_at'          => '2026-03-04T00:00:00Z',
					'completion_date'    => '2026-03-04T00:00:00Z',
					'cpd_points'         => 3.5,
					'instructor_name'    => 'Dr Sam Lee',
					'certificate_url'    => null,
				),
			),
		);
	}

	private function badge( array $overrides = array() ): array {
		return array(
			'data' => array(
				'valid' => true,
				'badge' => $overrides + array(
					'id'              => '9d3c0b7a-1111-4222-8333-444455556666',
					'badge_name'      => 'Ethics Foundations',
					'badge_color'     => '#1E2A4A',
					'badge_image_url' => 'https://cdn.example.test/badges/ethics.png',
					'earned_at'       => '2026-04-09T10:00:00Z',
					'course'          => array(
						'title'      => 'Ethics in Practice',
						'slug'       => 'ethics-in-practice',
						'category'   => 'ethics',
						'topics'     => array( 'Ethics' ),
						'cpd_points' => 2,
					),
					'earner'          => array( 'display_name' => 'Jordan Smith' ),
				),
			),
		);
	}

	// -----------------------------------------------------------------
	// A pasted link becomes a code
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function pasted(): array {
		return array(
			'a plain code'                          => array( self::VER, self::VER ),
			'a code with spaces round it'           => array( "  " . self::VER . "\n", self::VER ),
			'a platform certificate link'           => array( 'https://lms.example.test/verify/' . self::VER, self::VER ),
			'a platform link with a trailing slash' => array( 'https://lms.example.test/verify/' . self::VER . '/', self::VER ),
			'a link with a query string'            => array( 'https://lms.example.test/verify/' . self::VER . '?utm=x', self::VER ),
			'a badge link'                          => array( 'https://lms.example.test/badges/' . self::TOKEN, self::TOKEN ),
			'a link with no scheme'                 => array( 'lms.example.test/verify/' . self::VER, self::VER ),
			'a link with a code in the query'       => array( 'https://assoc.example.test/check/?agend_code=' . self::VER, self::VER ),
			'a link with the short name'            => array( 'https://assoc.example.test/check/?code=' . self::VER, self::VER ),
			'the agend name wins over the short'    => array( 'https://assoc.example.test/check/?code=other&agend_code=' . self::VER, self::VER ),
			'an encoded code'                       => array( 'https://lms.example.test/verify/VER-3fa91c0b7d2e48a1b6c5%20', 'VER-3fa91c0b7d2e48a1b6c5' ),
			'only the segment after verify'         => array( 'https://lms.example.test/verify/' . self::VER . '/extra', self::VER ),
			'a link with no code at all'            => array( 'https://lms.example.test/', '' ),
			'an absurdly long paste'                => array( str_repeat( 'a', 5000 ), '' ),
		);
	}

	#[Test]
	#[DataProvider( 'pasted' )]
	public function should_reduce_a_pasted_link_to_its_code_without_fetching_it( string $input, string $expected ): void {
		$this->assertSame( $expected, trim( agend_apps_credentials_extract_code( $input ) ) );
		$this->assertSame( array(), Agend_Test_WP::$requests, 'a link is never fetched' );
	}

	#[Test]
	public function should_take_the_code_from_a_link_to_any_host_and_check_only_the_code(): void {
		// The host is irrelevant: only the code is looked up, on this site's key.
		Agend_Test_WP::queue_response( 200, $this->certificate() );

		$result = $this->handle( 'https://elsewhere.example.test/verify/' . self::VER );

		$this->assertSame( 'valid', $result['body']['status'] );
		$this->assertCount( 1, $this->gateway_requests() );
		$this->assertStringEndsWith( '/lms/certificates/verify/' . self::VER, $this->gateway_requests()[0]['url'] );
	}

	// -----------------------------------------------------------------
	// Reading what was entered
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function unreadable(): array {
		return array(
			'nothing'                 => array( '', 'empty' ),
			'only spaces'             => array( '   ', 'empty' ),
			'a certificate number'    => array( 'CERT-2026-000123', 'format' ),
			'the seeded number'       => array( 'WDAA-REACT-DC-001', 'format' ),
			'a few letters'           => array( 'abc', 'format' ),
			'a link with no code'     => array( 'https://lms.example.test/', 'format' ),
			'an internal badge id'    => array( '3f2b8c1e-5a4d-4e6f-9b7a-1c2d3e4f5a6b', 'uuid' ),
			'a link to an internal id' => array( 'https://lms.example.test/badges/3f2b8c1e-5a4d-4e6f-9b7a-1c2d3e4f5a6b', 'uuid' ),
		);
	}

	#[Test]
	#[DataProvider( 'unreadable' )]
	public function should_refuse_a_code_that_cannot_be_read_without_counting_it_or_calling_anything( string $input, string $reason ): void {
		$result = $this->handle( $input );

		$this->assertSame( 400, $result['http'] );
		$this->assertSame( array( 'status' => 'invalid', 'reason' => $reason ), $result['body'] );
		$this->assertSame( array(), Agend_Test_WP::$requests );
		$this->assertSame( array(), Agend_Test_WP::$transients, 'a mistyped code is never counted' );
	}

	#[Test]
	public function should_refuse_a_badge_code_when_the_key_cannot_check_badges(): void {
		$this->know( array( 'lms.certificates.verify' ) );

		$result = $this->handle( self::TOKEN );

		$this->assertSame( 400, $result['http'] );
		$this->assertSame( 'badges_unsupported', $result['body']['reason'] );
		$this->assertSame( array(), Agend_Test_WP::$requests );
		$this->assertSame( array(), Agend_Test_WP::$transients );
	}

	#[Test]
	public function should_still_check_a_certificate_when_the_key_cannot_check_badges(): void {
		$this->know( array( 'lms.certificates.verify' ) );
		Agend_Test_WP::queue_response( 200, $this->certificate() );

		$this->assertSame( 'valid', $this->handle()['body']['status'] );
	}

	#[Test]
	public function should_not_accept_a_badge_code_while_the_keys_scopes_are_unknown(): void {
		Agend_Test_WP::reset();
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', true );

		$this->assertSame( 'badges_unsupported', $this->handle( self::TOKEN )['body']['reason'] );
	}

	// -----------------------------------------------------------------
	// Fails closed without a captcha
	// -----------------------------------------------------------------

	#[Test]
	public function should_refuse_every_check_when_no_captcha_is_set_up_and_the_opt_out_is_off(): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );

		$result = $this->handle();

		$this->assertSame( 503, $result['http'] );
		$this->assertSame( array( 'status' => 'unavailable' ), $result['body'] );
		$this->assertSame( array(), Agend_Test_WP::$requests );
		$this->assertSame( array(), Agend_Test_WP::$transients );
	}

	#[Test]
	public function should_refuse_when_a_provider_is_selected_but_has_no_secret(): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, Agend_Apps_Captcha::PROVIDER_TURNSTILE );

		$this->assertSame( 503, $this->handle( self::VER, 'x' )['http'] );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_check_with_a_valid_captcha_token_and_keep_the_token_to_itself(): void {
		$this->use_turnstile();
		Agend_Test_WP::queue_response( 200, array( 'success' => true ) );
		Agend_Test_WP::queue_response( 200, $this->certificate() );

		$result = $this->handle( self::VER, 'visitor-token' );

		$this->assertSame( 'valid', $result['body']['status'] );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertStringContainsString( 'turnstile', Agend_Test_WP::$requests[0]['url'] );
		$this->assertSame( 'visitor-token', Agend_Test_WP::$requests[0]['body']['response'] );
		$this->assertSame( '203.0.113.7', Agend_Test_WP::$requests[0]['body']['remoteip'] );
		$this->assertStringContainsString( '/lms/certificates/verify/', Agend_Test_WP::$requests[1]['url'] );
		$this->assertStringNotContainsString( 'visitor-token', Agend_Test_WP::$requests[1]['url'] );
		$this->assertStringNotContainsString( 'visitor-token', (string) json_encode( Agend_Test_WP::$requests[1] ) );
	}

	#[Test]
	public function should_refuse_and_not_call_the_gateway_when_the_captcha_fails(): void {
		$this->use_turnstile();
		Agend_Test_WP::queue_response( 200, array( 'success' => false ) );

		$result = $this->handle( self::VER, 'bad-token' );

		$this->assertSame( 400, $result['http'] );
		$this->assertSame( array( 'status' => 'captcha_failed' ), $result['body'] );
		$this->assertSame( array(), $this->gateway_requests() );
	}

	#[Test]
	public function should_refuse_when_a_provider_is_selected_and_the_token_is_missing(): void {
		$this->use_turnstile();

		$result = $this->handle();

		$this->assertSame( 'captcha_failed', $result['body']['status'] );
		$this->assertSame( array(), Agend_Test_WP::$requests, 'no provider call and no gateway call' );
	}

	#[Test]
	public function should_not_spend_the_site_ceiling_on_a_check_the_captcha_refused(): void {
		$this->use_turnstile();

		for ( $i = 1; $i <= 30; $i++ ) {
			Agend_Test_WP::queue_response( 200, array( 'success' => false ) );
			$this->handle( self::VER, 'bad', false, '198.51.100.' . $i );
		}

		Agend_Test_WP::queue_response( 200, array( 'success' => true ) );
		Agend_Test_WP::queue_response( 200, $this->certificate() );

		$this->assertSame( 'valid', $this->handle( self::VER, 'good', false, '203.0.113.99' )['body']['status'] );
	}

	// -----------------------------------------------------------------
	// Throttle
	// -----------------------------------------------------------------

	#[Test]
	public function should_refuse_the_eleventh_check_a_minute_from_one_visitor_before_the_captcha_or_gateway(): void {
		$this->use_turnstile();

		for ( $i = 1; $i <= 10; $i++ ) {
			Agend_Test_WP::queue_response( 200, array( 'success' => true ) );
			Agend_Test_WP::queue_response( 200, $this->certificate() );
			$this->assertSame( 200, $this->handle( self::VER, 'tok' . $i )['http'], 'check ' . $i );
		}

		$before = count( Agend_Test_WP::$requests );
		$result = $this->handle( self::VER, 'tok11' );

		$this->assertSame( 429, $result['http'] );
		$this->assertSame( 'busy', $result['body']['status'] );
		$this->assertGreaterThanOrEqual( 1, $result['body']['retry_after'] );
		$this->assertLessThanOrEqual( 60, $result['body']['retry_after'] );
		$this->assertCount( $before, Agend_Test_WP::$requests, 'neither the provider nor the gateway was called' );
	}

	#[Test]
	public function should_count_a_check_even_when_the_captcha_then_fails_so_token_farming_is_throttled(): void {
		$this->use_turnstile();

		for ( $i = 1; $i <= 10; $i++ ) {
			Agend_Test_WP::queue_response( 200, array( 'success' => false ) );
			$this->assertSame( 'captcha_failed', $this->handle( self::VER, 'bad' )['body']['status'] );
		}

		$this->assertSame( 429, $this->handle( self::VER, 'bad' )['http'] );
	}

	#[Test]
	public function should_stop_every_visitor_at_the_site_ceiling_with_a_busy_answer(): void {
		for ( $i = 1; $i <= 20; $i++ ) {
			Agend_Test_WP::queue_response( 200, $this->certificate() );
			$this->assertSame( 200, $this->handle( self::VER, '', false, '203.0.113.' . $i )['http'], 'visitor ' . $i );
		}

		$result = $this->handle( self::VER, '', false, '203.0.113.99' );

		$this->assertSame( 429, $result['http'] );
		$this->assertSame( 'busy', $result['body']['status'] );
		$this->assertCount( 20, $this->gateway_requests() );
	}

	#[Test]
	public function should_make_the_visitor_with_no_usable_address_share_one_allowance(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			Agend_Test_WP::queue_response( 200, $this->certificate() );
			$this->handle( self::VER, '', false, '' );
		}

		$this->assertSame( 429, $this->handle( self::VER, '', false, '' )['http'] );
	}

	// -----------------------------------------------------------------
	// Found, and what is shown
	// -----------------------------------------------------------------

	#[Test]
	public function should_show_a_certificate_with_what_the_record_holds_and_nothing_else(): void {
		Agend_Test_WP::queue_response( 200, $this->certificate() );

		$result = $this->handle();
		$body   = $result['body'];

		$this->assertSame( 200, $result['http'] );
		$this->assertSame( 'valid', $body['status'] );
		$this->assertSame( 'certificate', $body['kind'] );
		$this->assertSame( 'Ethics in Practice', $body['title'] );
		$this->assertTrue( $body['known'] );
		$this->assertSame( 'CERT-2026-000123', $body['number'] );
		$this->assertSame( '4 March 2026', $body['issued'] );
		$this->assertSame( '2026-03-04', $body['issued_iso'] );
		$this->assertSame( '3.5', $body['cpd'] );
		$this->assertSame( 'Dr Sam Lee', $body['instructor'] );
		$this->assertSame( array( 'Ethics', 'Conflicts of interest' ), $body['topics'] );
		$this->assertSame( '18 November 2026, 5:42 am', $body['checked'] );
		$this->assertSame( '', $body['valid_until'] );
	}

	#[Test]
	public function should_never_return_an_internal_id_a_download_link_a_holder_or_an_issuer_for_a_certificate(): void {
		Agend_Test_WP::queue_response(
			200,
			$this->certificate(
				array(
					'holder_name'      => 'Taylor Brown',
					'owner_account_id' => 'acct-9',
					'issuer'           => 'Some Association',
					'user_email'       => 'taylor@example.test',
					'certificate_url'  => 'https://lms.example.test/private.pdf',
				)
			)
		);

		$json = (string) json_encode( $this->handle( self::VER, '', true )['body'] );

		foreach ( array( 'Taylor Brown', 'acct-9', 'Some Association', 'taylor@example.test', 'private.pdf', '3f2b8c1e-5a4d', 'holder', 'issuer', 'expires' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, $needle );
		}
	}

	#[Test]
	public function should_show_a_badge_with_its_picture_colour_and_course(): void {
		Agend_Test_WP::queue_response( 200, $this->badge() );

		$body = $this->handle( self::TOKEN )['body'];

		$this->assertSame( 'valid', $body['status'] );
		$this->assertSame( 'badge', $body['kind'] );
		$this->assertSame( 'Ethics Foundations', $body['title'] );
		$this->assertSame( '9 April 2026', $body['issued'] );
		$this->assertSame( 'Ethics in Practice', $body['course'] );
		$this->assertSame( '2', $body['cpd'] );
		$this->assertSame( 'https://cdn.example.test/badges/ethics.png', $body['image'] );
		$this->assertSame( '#1E2A4A', $body['colour'] );
		$this->assertStringContainsString( '/lms/badges/verify/' . self::TOKEN, $this->gateway_requests()[0]['url'] );
	}

	#[Test]
	public function should_leave_a_badge_earners_name_out_unless_the_page_asked_for_it(): void {
		Agend_Test_WP::queue_response( 200, $this->badge() );
		$this->assertArrayNotHasKey( 'holder', $this->handle( self::TOKEN, '', false )['body'] );
		$this->assertStringNotContainsString( 'Jordan', (string) json_encode( Agend_Test_WP::$requests ) . '' );

		Agend_Test_WP::queue_response( 200, $this->badge() );
		$this->assertSame( 'Jordan Smith', $this->handle( self::TOKEN, '', true )['body']['holder'] );
	}

	#[Test]
	public function should_leave_the_name_out_when_the_gateway_sends_none(): void {
		Agend_Test_WP::queue_response( 200, $this->badge( array( 'earner' => null ) ) );

		$this->assertArrayNotHasKey( 'holder', $this->handle( self::TOKEN, '', true )['body'] );
	}

	#[Test]
	public function should_call_an_unknown_course_what_it_is_and_not_by_the_placeholder_name(): void {
		Agend_Test_WP::queue_response( 200, $this->certificate( array( 'course_title' => 'Unknown Course' ) ) );

		$body = $this->handle()['body'];

		$this->assertSame( 'valid', $body['status'] );
		$this->assertFalse( $body['known'] );
		$this->assertSame( '', $body['title'] );
	}

	#[Test]
	public function should_show_only_points_above_zero_and_whole_numbers_without_decimals(): void {
		Agend_Test_WP::queue_response( 200, $this->certificate( array( 'cpd_points' => 0 ) ) );
		$this->assertSame( '', $this->handle()['body']['cpd'] );

		Agend_Test_WP::queue_response( 200, $this->certificate( array( 'cpd_points' => 4 ) ) );
		$this->assertSame( '4', $this->handle()['body']['cpd'] );

		Agend_Test_WP::queue_response( 200, $this->certificate( array( 'cpd_points' => null ) ) );
		$this->assertSame( '', $this->handle()['body']['cpd'] );
	}

	#[Test]
	public function should_list_at_most_five_topics_without_repeats_or_blanks(): void {
		Agend_Test_WP::queue_response(
			200,
			$this->certificate( array( 'topics' => array( 'One', 'Two', 'One', '', 'Three', 'Four', 'Five', 'Six', 'Seven' ) ) )
		);

		$this->assertSame( array( 'One', 'Two', 'Three', 'Four', 'Five' ), $this->handle()['body']['topics'] );
	}

	#[Test]
	public function should_strip_markup_from_every_string_the_record_holds_and_cap_their_length(): void {
		Agend_Test_WP::queue_response(
			200,
			$this->certificate(
				array(
					'course_title'    => '<script>alert(1)</script>Ethics <b>in</b> Practice',
					'instructor_name' => '  Dr <img src=x onerror=alert(1)> Lee  ',
					'topics'          => array( '<a href="x">Ethics</a>', str_repeat( 'long ', 40 ) ),
				)
			)
		);

		$body = $this->handle()['body'];

		$this->assertStringNotContainsString( '<', (string) json_encode( array( $body['title'], $body['instructor'], $body['topics'] ) ) );
		$this->assertSame( 'Dr Lee', $body['instructor'] );
		$this->assertLessThanOrEqual( 60, strlen( $body['topics'][1] ) );
	}

	#[Test]
	public function should_pass_on_only_a_hex_colour_and_an_https_picture(): void {
		Agend_Test_WP::queue_response( 200, $this->badge( array( 'badge_color' => 'red;background:url(x)', 'badge_image_url' => 'http://cdn.example.test/b.png' ) ) );
		$body = $this->handle( self::TOKEN )['body'];

		$this->assertSame( '', $body['colour'] );
		$this->assertSame( '', $body['image'] );

		Agend_Test_WP::queue_response( 200, $this->badge( array( 'badge_color' => '#abc', 'badge_image_url' => 'javascript:alert(1)' ) ) );
		$body = $this->handle( self::TOKEN )['body'];

		$this->assertSame( '#abc', $body['colour'] );
		$this->assertSame( '', $body['image'] );
	}

	#[Test]
	public function should_fall_back_to_the_completion_date_and_to_now_for_the_time_checked(): void {
		$answer = $this->certificate( array( 'issued_at' => null ) );
		unset( $answer['data']['verified_at'] );
		Agend_Test_WP::queue_response( 200, $answer );

		$body = $this->handle()['body'];

		$this->assertSame( '4 March 2026', $body['issued'] );
		$this->assertSame( '14 November 2023, 10:13 pm', $body['checked'], 'now, in the site timezone' );
	}

	// -----------------------------------------------------------------
	// Not found is only ever a real "no"
	// -----------------------------------------------------------------

	#[Test]
	public function should_say_not_found_for_a_successful_answer_that_says_the_code_is_not_valid(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'valid' => false, 'certificate' => null, 'verified_at' => '2026-11-18T05:42:10Z' ) ) );

		$result = $this->handle();

		$this->assertSame( 200, $result['http'] );
		$this->assertSame( array( 'status' => 'not_found' ), $result['body'] );
	}

	/**
	 * @return array<string, array{int, mixed}>
	 */
	public static function not_an_answer(): array {
		return array(
			'a server error'                 => array( 500, array( 'error' => array( 'code' => 'INTERNAL' ) ) ),
			'a bad gateway'                  => array( 502, '<html>Bad gateway</html>' ),
			'a 404'                          => array( 404, array( 'error' => array( 'code' => 'NOT_FOUND' ) ) ),
			'an unauthorised key'            => array( 403, array( 'error' => array( 'code' => 'FORBIDDEN' ) ) ),
			'an unauthenticated key'         => array( 401, array( 'error' => array( 'code' => 'UNAUTHORIZED' ) ) ),
			'a success flag that is false'   => array( 200, array( 'success' => false, 'data' => array( 'valid' => false ) ) ),
			'an answer with no data'         => array( 200, array( 'ok' => true ) ),
			'an answer with no verdict'      => array( 200, array( 'data' => array( 'certificate' => array( 'course_title' => 'X' ) ) ) ),
			'a verdict that is not a boolean' => array( 200, array( 'data' => array( 'valid' => 'false' ) ) ),
			'valid but no record'            => array( 200, array( 'data' => array( 'valid' => true ) ) ),
			'valid but a record with no name' => array( 200, array( 'data' => array( 'valid' => true, 'certificate' => array( 'certificate_number' => 'CERT-2026-000123' ) ) ) ),
			'valid but a badge record in place of a certificate' => array( 200, array( 'data' => array( 'valid' => true, 'badge' => array( 'badge_name' => 'X' ) ) ) ),
			'a body that is not JSON'        => array( 200, 'not json' ),
		);
	}

	#[Test]
	#[DataProvider( 'not_an_answer' )]
	public function should_say_it_could_not_check_and_never_not_found_when_there_was_no_real_answer( int $status, $body ): void {
		Agend_Test_WP::queue_response( $status, $body );

		$result = $this->handle();

		$this->assertSame( array( 'status' => 'unavailable' ), $result['body'] );
		$this->assertSame( 503, $result['http'] );
	}

	#[Test]
	public function should_say_it_could_not_check_when_the_gateway_cannot_be_reached(): void {
		Agend_Test_WP::queue_transport_error( 'http_request_failed', 'cURL error 28: timed out' );

		$result = $this->handle();

		$this->assertSame( array( 'status' => 'unavailable' ), $result['body'] );
		$this->assertStringNotContainsString( 'cURL', (string) json_encode( $result ), 'no transport wording reaches the visitor' );
	}

	#[Test]
	public function should_say_it_could_not_check_when_the_key_lacks_the_scope(): void {
		$this->know( array( 'lms.paths.browse' ) );

		$result = $this->handle();

		$this->assertSame( array( 'status' => 'unavailable' ), $result['body'] );
		$this->assertSame( array(), $this->gateway_requests(), 'the local guard refused it' );
	}

	#[Test]
	public function should_ask_the_visitor_to_wait_when_the_gateway_says_slow_down(): void {
		Agend_Test_WP::queue_response( 429, array( 'error' => array( 'code' => 'RATE_LIMIT_EXCEEDED' ) ) );

		$result = $this->handle();

		$this->assertSame( 429, $result['http'] );
		$this->assertSame( 'busy', $result['body']['status'] );
		$this->assertSame( 30, $result['body']['retry_after'] );
	}

	#[Test]
	public function should_never_pass_a_gateway_message_to_the_visitor(): void {
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'code' => 'X', 'message' => 'RAW gateway wording: db password in stack trace' ) ) );

		$this->assertStringNotContainsString( 'RAW gateway wording', (string) json_encode( $this->handle() ) );
	}

	#[Test]
	public function should_make_exactly_one_gateway_call_for_one_accepted_code(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'valid' => false ) ) );

		$this->handle();

		$this->assertCount( 1, Agend_Test_WP::$requests, 'a not-found is never followed by a second lookup' );
	}

	// -----------------------------------------------------------------
	// Dormant: expiry and revocation fail closed
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{array<string, mixed>, bool}> Overrides on the certificate, whether on `data` instead.
	 */
	public static function ended(): array {
		return array(
			'revoked on the record'      => array( array( 'revoked_at' => '2026-10-01T00:00:00Z' ), false ),
			'revoked on the envelope'    => array( array( 'revoked_at' => '2026-10-01T00:00:00Z' ), true ),
			'expired on the record'      => array( array( 'expires_at' => '2020-01-01T00:00:00Z' ), false ),
			'expired on the envelope'    => array( array( 'expires_at' => '2020-01-01T00:00:00Z' ), true ),
			'an expiry nobody can read'  => array( array( 'expires_at' => 'next tuesday-ish' ), false ),
			'a status that is not active' => array( array( 'status' => 'revoked' ), false ),
			'a suspended status'         => array( array( 'status' => 'suspended' ), true ),
		);
	}

	#[Test]
	#[DataProvider( 'ended' )]
	public function should_say_no_longer_valid_when_a_future_gateway_marks_a_credential_revoked_or_expired( array $overrides, bool $on_envelope ): void {
		$answer = $this->certificate( $on_envelope ? array() : $overrides );

		if ( $on_envelope ) {
			$answer['data'] += $overrides;
		}

		Agend_Test_WP::queue_response( 200, $answer );

		$body = $this->handle()['body'];

		$this->assertSame( 'ended', $body['status'] );
		$this->assertSame( 'Ethics in Practice', $body['title'] );
	}

	#[Test]
	public function should_say_valid_until_for_an_expiry_in_the_future(): void {
		Agend_Test_WP::queue_response( 200, $this->certificate( array( 'expires_at' => '2024-12-18T00:00:00Z' ) ) );

		$body = $this->handle()['body'];

		$this->assertSame( 'valid', $body['status'] );
		$this->assertSame( '18 December 2024', $body['valid_until'] );
	}

	#[Test]
	public function should_treat_an_active_status_as_valid(): void {
		foreach ( array( 'active', 'ACTIVE', 'valid', '' ) as $index => $status ) {
			Agend_Test_WP::queue_response( 200, $this->certificate( array( 'status' => $status ) ) );

			$this->assertSame( 'valid', $this->handle( self::VER, '', false, '198.51.100.' . ( $index + 1 ) )['body']['status'], $status );
		}
	}

	// -----------------------------------------------------------------
	// The route
	// -----------------------------------------------------------------

	private function controller(): Agend_Apps_LMS_REST_Controller {
		return new Agend_Apps_LMS_REST_Controller();
	}

	private function post( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/lms/credentials/verify' );

		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $this->controller()->sanitize_credential_input( $value ) );
		}

		return $this->controller()->verify_credential( $request );
	}

	#[Test]
	public function should_register_a_public_post_route(): void {
		$this->controller()->register_routes();

		$routes = array_values(
			array_filter(
				Agend_Test_WP::$rest_routes,
				static fn( array $r ): bool => '/lms/credentials/verify' === $r['route']
			)
		);

		$this->assertCount( 1, $routes );
		$this->assertSame( 'agend-apps/v1', $routes[0]['namespace'] );
		$this->assertSame( 'POST', $routes[0]['args'][0]['methods'] );
		$this->assertSame( '__return_true', $routes[0]['args'][0]['permission_callback'] );
		$this->assertTrue( $routes[0]['args'][0]['args']['code']['required'] );
	}

	#[Test]
	public function should_answer_a_check_as_json_and_never_let_a_cache_keep_it(): void {
		Agend_Test_WP::queue_response( 200, $this->certificate() );

		$response = $this->post( array( 'code' => self::VER ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'valid', $response->get_data()['status'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertArrayNotHasKey( 'Retry-After', $response->get_headers() );
	}

	#[Test]
	public function should_send_retry_after_when_the_visitor_is_over_the_limit(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			Agend_Test_WP::queue_response( 200, $this->certificate() );
			$this->post( array( 'code' => self::VER ) );
		}

		$response = $this->post( array( 'code' => self::VER ) );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( (string) $response->get_data()['retry_after'], $response->get_headers()['Retry-After'] );
	}

	#[Test]
	public function should_give_a_badge_earners_name_only_to_a_page_that_carries_the_key(): void {
		Agend_Test_WP::queue_response( 200, $this->badge() );
		$without = $this->post( array( 'code' => self::TOKEN ) )->get_data();

		Agend_Test_WP::queue_response( 200, $this->badge() );
		$wrong = $this->post( array( 'code' => self::TOKEN, 'holder_key' => 'guess' ) )->get_data();

		Agend_Test_WP::queue_response( 200, $this->badge() );
		$right = $this->post( array( 'code' => self::TOKEN, 'holder_key' => agend_apps_credentials_holder_key() ) )->get_data();

		$this->assertArrayNotHasKey( 'holder', $without );
		$this->assertArrayNotHasKey( 'holder', $wrong );
		$this->assertSame( 'Jordan Smith', $right['holder'] );
	}

	#[Test]
	public function should_use_remote_addr_for_the_visitor_and_ignore_a_forwarded_for_header(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.' . $i;
			Agend_Test_WP::queue_response( 200, $this->certificate() );
			$this->post( array( 'code' => self::VER ) );
		}

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.250';

		$this->assertSame( 429, $this->post( array( 'code' => self::VER ) )->get_status() );
	}

	#[Test]
	public function should_keep_percent_encoding_and_drop_control_characters_from_what_was_typed(): void {
		$controller = $this->controller();

		$this->assertSame( 'https://x.test/verify/a%20b', $controller->sanitize_credential_input( "  https://x.test/verify/a%20b\n" ) );
		$this->assertSame( 'ab', $controller->sanitize_credential_input( "a\x00\x07b" ) );
		$this->assertSame( 2000, strlen( $controller->sanitize_credential_input( str_repeat( 'a', 5000 ) ) ) );
		$this->assertSame( '', $controller->sanitize_credential_input( array( 'a' ) ) );
	}

	#[Test]
	public function should_reduce_a_pasted_link_to_its_code_through_the_route(): void {
		Agend_Test_WP::queue_response( 200, $this->certificate() );

		$response = $this->post( array( 'code' => 'https://lms.example.test/verify/' . self::VER ) );

		$this->assertSame( 'valid', $response->get_data()['status'] );
		$this->assertStringEndsWith( '/lms/certificates/verify/' . self::VER, $this->gateway_requests()[0]['url'] );
	}
}
