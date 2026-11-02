<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Captcha;
use Agend_Apps_Directory_REST_Controller;
use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Secret_Store;
use Agend_Test_Mail;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;
use WP_REST_Response;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/enquiry-stubs.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-captcha.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-enquiries.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/directory-routes.php';

/**
 * `POST /agend-apps/v1/directory/listings/{slugOrId}/enquiries`: the nonce
 * check, sanitising and validation, the captcha, the call to the gateway with
 * the site key and visitor IP, the visitor-safe error mapping, and that
 * WordPress sends no email.
 */
#[CoversClass( Agend_Apps_Directory_REST_Controller::class )]
final class ListingEnquiryRouteTest extends TestCase {

	private const GATEWAY_PATH = '/directory/listings/jane-smith/enquiries';

	protected function setUp(): void {
		parent::setUp();
		Agend_Test_Mail::$sent = array();
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	protected function tearDown(): void {
		Agend_Apps_Secret_Store::delete( Agend_Apps_Captcha::SECRET_STORE_KEY );
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tearDown();
	}

	private function controller(): Agend_Apps_Directory_REST_Controller {
		return new Agend_Apps_Directory_REST_Controller();
	}

	/**
	 * A request as WordPress hands it to the callback: every parameter passed
	 * through the sanitize callback the route declares for it.
	 *
	 * @param array<string, mixed> $overrides Parameters to replace or add.
	 */
	private function request( array $overrides = array(), string $listing = 'jane-smith' ): WP_REST_Request {
		$params = array_merge(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'email'      => 'ada@example.test',
				'phone'      => '0400 000 000',
				'message'    => 'I would like to discuss my super fund.',
			),
			$overrides,
			array( 'listing_id' => $listing )
		);

		$declared = ( new \ReflectionMethod( Agend_Apps_Directory_REST_Controller::class, 'enquiry_args' ) )->invoke( $this->controller() );
		$request  = new WP_REST_Request( 'POST', '/agend-apps/v1/directory/listings/' . $listing . '/enquiries' );

		foreach ( $params as $name => $value ) {
			$callback = $declared[ $name ]['sanitize_callback'] ?? null;
			$request->set_param( $name, null === $callback ? $value : call_user_func( $callback, $value ) );
		}

		return $request;
	}

	private function submit( array $overrides = array() ): WP_REST_Response {
		return $this->controller()->submit_enquiry( $this->request( $overrides ) );
	}

	private function use_turnstile(): void {
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, Agend_Apps_Captcha::PROVIDER_TURNSTILE );
		update_option( Agend_Apps_Captcha::OPTION_SITE_KEY, 'site-key' );
		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'captcha-secret' );
	}

	/**
	 * @return array<int, array<string, mixed>> The requests that went to the gateway.
	 */
	private function gateway_requests(): array {
		return array_values(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $r ): bool => false !== strpos( $r['url'], '/directory/listings/' )
			)
		);
	}

	// -----------------------------------------------------------------
	// Registration and nonce
	// -----------------------------------------------------------------

	#[Test]
	public function should_register_a_nonce_protected_post_route_for_a_listing(): void {
		$this->controller()->register_routes();

		$routes = array_values(
			array_filter(
				Agend_Test_WP::$rest_routes,
				static fn( array $r ): bool => '/directory/listings/(?P<listing_id>[a-zA-Z0-9_-]+)/enquiries' === $r['route']
			)
		);

		$this->assertCount( 1, $routes );
		$this->assertSame( 'agend-apps/v1', $routes[0]['namespace'] );
		$this->assertSame( 'POST', $routes[0]['args'][0]['methods'] );
		$this->assertEquals( array( $this->controller(), 'nonce_check' ), $routes[0]['args'][0]['permission_callback'] );
	}

	#[Test]
	public function should_refuse_a_request_without_a_valid_nonce_and_accept_one_with_it(): void {
		$request = new WP_REST_Request( 'POST', '/x' );
		$this->assertFalse( $this->controller()->nonce_check( $request ) );

		$request->set_header( 'X-WP-Nonce', 'forged' );
		$this->assertFalse( $this->controller()->nonce_check( $request ) );

		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertTrue( $this->controller()->nonce_check( $request ) );
	}

	#[Test]
	public function should_declare_the_enquiry_scope_in_the_endpoint_scope_map(): void {
		$entry = Agend_Apps_Endpoint_Scopes::lookup( 'POST', '/directory/listings/jane-smith/enquiries' );

		$this->assertSame( 'directory', $entry['app'] );
		$this->assertSame( array( array( 'directory.enquiries.send' ) ), $entry['scopes'] );
	}

	// -----------------------------------------------------------------
	// Sanitising
	// -----------------------------------------------------------------

	#[Test]
	public function should_strip_markup_from_every_text_field_before_forwarding(): void {
		Agend_Test_WP::queue_response( 201, array( 'data' => array( 'sent' => true ) ) );

		$this->submit(
			array(
				'first_name' => '<b>Ada</b>',
				'last_name'  => 'Love<script>alert(1)</script>lace',
				'phone'      => '<i>0400</i> 000 000',
				'message'    => 'Hello <img src=x onerror=alert(1)> there, I have a question.',
			)
		);

		$sent = json_decode( (string) $this->gateway_requests()[0]['body'], true );

		$this->assertSame( 'Ada', $sent['first_name'] );
		$this->assertSame( 'Lovealert(1)lace', $sent['last_name'] );
		$this->assertSame( '0400 000 000', $sent['phone'] );
		$this->assertStringNotContainsString( '<', $sent['message'] );
	}

	#[Test]
	public function should_treat_an_invalid_email_as_a_validation_error_after_sanitising(): void {
		$response = $this->submit( array( 'email' => 'not an email' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'email', $response->get_data()['fields'] );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// Validation
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function invalid_fields(): array {
		return array(
			'blank first name'  => array( array( 'first_name' => '  ' ), 'first_name' ),
			'long first name'   => array( array( 'first_name' => str_repeat( 'a', 101 ) ), 'first_name' ),
			'blank last name'   => array( array( 'last_name' => '' ), 'last_name' ),
			'long last name'    => array( array( 'last_name' => str_repeat( 'a', 101 ) ), 'last_name' ),
			'missing email'     => array( array( 'email' => '' ), 'email' ),
			'long email'        => array( array( 'email' => str_repeat( 'a', 250 ) . '@example.test' ), 'email' ),
			'long phone'        => array( array( 'phone' => str_repeat( '1', 41 ) ), 'phone' ),
			'short message'     => array( array( 'message' => 'too short' ), 'message' ),
			'long message'      => array( array( 'message' => str_repeat( 'a', 5001 ) ), 'message' ),
		);
	}

	/**
	 * @param array<string, mixed> $overrides Parameters to replace.
	 */
	#[Test]
	#[DataProvider( 'invalid_fields' )]
	public function should_answer_400_with_a_message_for_the_field_and_send_nothing_when_a_field_is_invalid( array $overrides, string $field ): void {
		$this->use_turnstile();

		$response = $this->submit( $overrides );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'validation_failed', $data['code'] );
		$this->assertArrayHasKey( $field, $data['fields'] );
		$this->assertNotSame( '', $data['fields'][ $field ] );
		$this->assertSame( array(), Agend_Test_WP::$requests, 'neither the captcha nor the gateway is called' );
	}

	#[Test]
	public function should_count_characters_not_bytes_against_the_limits(): void {
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );

		$response = $this->submit( array( 'first_name' => str_repeat( 'é', 100 ) ) );

		$this->assertSame( 200, $response->get_status() );
	}

	#[Test]
	public function should_accept_a_blank_phone_and_leave_it_out_of_the_payload(): void {
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );

		$this->submit( array( 'phone' => '' ) );

		$this->assertArrayNotHasKey( 'phone', json_decode( (string) $this->gateway_requests()[0]['body'], true ) );
	}

	// -----------------------------------------------------------------
	// Forwarding
	// -----------------------------------------------------------------

	#[Test]
	public function should_forward_the_enquiry_to_the_listing_endpoint_and_report_success(): void {
		Agend_Test_WP::queue_response( 201, array( 'data' => array( 'sent' => true ) ) );

		$response = $this->submit();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'success' => true ), $response->get_data() );

		$requests = $this->gateway_requests();
		$this->assertCount( 1, $requests );
		$this->assertStringEndsWith( self::GATEWAY_PATH, $requests[0]['url'] );
		$this->assertSame(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'email'      => 'ada@example.test',
				'message'    => 'I would like to discuss my super fund.',
				'phone'      => '0400 000 000',
			),
			json_decode( (string) $requests[0]['body'], true )
		);
	}

	#[Test]
	public function should_url_encode_the_listing_identifier_in_the_gateway_path(): void {
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );

		$this->controller()->submit_enquiry( $this->request( array(), 'a b/c' ) );

		$this->assertStringEndsWith( '/directory/listings/a%20b%2Fc/enquiries', $this->gateway_requests()[0]['url'] );
	}

	#[Test]
	public function should_send_terms_accepted_only_when_the_visitor_ticked_the_box(): void {
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );

		$this->submit();
		$this->submit( array( 'terms_accepted' => 'false' ) );
		$this->submit( array( 'terms_accepted' => true ) );

		$bodies = array_map( static fn( array $r ): array => json_decode( (string) $r['body'], true ), $this->gateway_requests() );

		$this->assertArrayNotHasKey( 'terms_accepted', $bodies[0] );
		$this->assertArrayNotHasKey( 'terms_accepted', $bodies[1] );
		$this->assertTrue( $bodies[2]['terms_accepted'] );
	}

	#[Test]
	public function should_send_the_site_api_key_and_never_the_signed_in_members_token(): void {
		add_filter( 'agend_apps_bearer_token', static fn(): string => 'member-jwt' );
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );

		$this->submit();

		$headers = $this->gateway_requests()[0]['headers'];
		$this->assertArrayHasKey( 'x-api-key', $headers );
		$this->assertArrayNotHasKey( 'Authorization', $headers );
	}

	// -----------------------------------------------------------------
	// Visitor IP
	// -----------------------------------------------------------------

	#[Test]
	public function should_send_remote_addr_as_the_visitor_ip_header(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );

		$this->submit();

		$this->assertSame( '203.0.113.7', $this->gateway_requests()[0]['headers']['x-agend-visitor-ip'] );
	}

	#[Test]
	public function should_not_trust_x_forwarded_for_by_default(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );

		$this->submit();

		$this->assertSame( '203.0.113.7', $this->gateway_requests()[0]['headers']['x-agend-visitor-ip'] );
	}

	#[Test]
	public function should_let_a_filter_supply_the_ip_for_a_site_behind_a_trusted_proxy(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
		Agend_Test_WP::set_filter( 'agend_apps_visitor_ip', '198.51.100.44' );
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );

		$this->submit();

		$this->assertSame( '198.51.100.44', $this->gateway_requests()[0]['headers']['x-agend-visitor-ip'] );
	}

	#[Test]
	public function should_omit_the_header_when_no_valid_ip_can_be_established(): void {
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );

		$this->submit();

		$this->assertArrayNotHasKey( 'x-agend-visitor-ip', $this->gateway_requests()[0]['headers'] );
	}

	// -----------------------------------------------------------------
	// Captcha
	// -----------------------------------------------------------------

	#[Test]
	public function should_call_the_gateway_without_a_captcha_request_when_no_provider_is_selected(): void {
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );

		$this->assertSame( 200, $this->submit()->get_status() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_verify_the_captcha_before_calling_the_gateway(): void {
		$this->use_turnstile();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		Agend_Test_WP::queue_response( 200, array( 'success' => true ) );
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );

		$response = $this->submit( array( 'captcha_token' => 'visitor-token' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertStringContainsString( 'turnstile', Agend_Test_WP::$requests[0]['url'] );
		$this->assertSame( 'visitor-token', Agend_Test_WP::$requests[0]['body']['response'] );
		$this->assertSame( '203.0.113.7', Agend_Test_WP::$requests[0]['body']['remoteip'] );
		$this->assertStringEndsWith( self::GATEWAY_PATH, Agend_Test_WP::$requests[1]['url'] );
		$this->assertArrayNotHasKey( 'captcha_token', json_decode( (string) Agend_Test_WP::$requests[1]['body'], true ), 'the token stays on this server' );
	}

	#[Test]
	public function should_refuse_and_not_call_the_gateway_when_the_captcha_fails(): void {
		$this->use_turnstile();
		Agend_Test_WP::queue_response( 200, array( 'success' => false ) );

		$response = $this->submit( array( 'captcha_token' => 'bad-token' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'captcha_failed', $response->get_data()['code'] );
		$this->assertSame( array(), $this->gateway_requests() );
	}

	#[Test]
	public function should_refuse_when_a_provider_is_selected_and_the_token_is_missing(): void {
		$this->use_turnstile();

		$response = $this->submit();

		$this->assertSame( 'captcha_failed', $response->get_data()['code'] );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// Error mapping
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{int, string, string, int}>
	 */
	public static function gateway_failures(): array {
		return array(
			'validation error'    => array( 400, 'VALIDATION_ERROR', 'validation_failed', 400 ),
			'unprocessable'       => array( 422, '', 'validation_failed', 400 ),
			'not found'           => array( 404, 'NOT_FOUND', 'not_found', 404 ),
			'not accepting'       => array( 409, 'LISTING_NOT_ACCEPTING_ENQUIRIES', 'not_accepting', 409 ),
			'not accepting (422)' => array( 422, 'LISTING_NOT_ACCEPTING_ENQUIRIES', 'not_accepting', 409 ),
			'terms required'      => array( 400, 'TERMS_REQUIRED', 'terms_required', 422 ),
			'rate limited'        => array( 429, 'RATE_LIMITED', 'rate_limited', 429 ),
			'rate limited, code unknown' => array( 429, 'SOMETHING_ELSE', 'rate_limited', 429 ),
			'internal error'      => array( 500, 'INTERNAL_ERROR', 'send_failed', 502 ),
			'unauthorised key'    => array( 403, 'FORBIDDEN', 'send_failed', 502 ),
			'unknown code'        => array( 418, 'TEAPOT', 'send_failed', 502 ),
		);
	}

	#[Test]
	#[DataProvider( 'gateway_failures' )]
	public function should_map_a_gateway_failure_to_a_friendly_visitor_message( int $status, string $code, string $expected_code, int $expected_status ): void {
		Agend_Test_WP::queue_response(
			$status,
			array(
				'error' => array(
					'code'    => $code,
					'message' => 'RAW gateway wording: listing owner@example.test, stack trace',
				),
			)
		);

		$response = $this->submit();
		$data     = $response->get_data();

		$this->assertSame( $expected_status, $response->get_status() );
		$this->assertFalse( $data['success'] );
		$this->assertSame( $expected_code, $data['code'] );
		$this->assertNotSame( '', $data['message'] );
		$this->assertStringNotContainsString( 'RAW gateway wording', (string) json_encode( $data ) );
		$this->assertStringNotContainsString( 'owner@example.test', (string) json_encode( $data ) );
	}

	#[Test]
	public function should_say_it_could_not_send_when_the_gateway_cannot_be_reached(): void {
		Agend_Test_WP::queue_transport_error( 'http_request_failed', 'cURL error 28: timed out' );

		$response = $this->submit();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'send_failed', $response->get_data()['code'] );
		$this->assertStringNotContainsString( 'cURL', (string) json_encode( $response->get_data() ) );
	}

	#[Test]
	public function should_map_a_validation_error_with_a_different_conventional_code_by_its_status(): void {
		Agend_Test_WP::queue_response( 400, array( 'error' => array( 'message' => 'Bad request' ) ) );

		$this->assertSame( 'validation_failed', $this->submit()->get_data()['code'] );
	}

	// -----------------------------------------------------------------
	// WordPress sends no email
	// -----------------------------------------------------------------

	#[Test]
	public function should_send_no_email_from_wordpress_on_success_or_failure(): void {
		Agend_Test_WP::queue_response( 201, array( 'data' => array() ) );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'code' => 'INTERNAL_ERROR' ) ) );

		$this->submit();
		$this->submit();
		$this->submit( array( 'email' => 'nope' ) );

		$this->assertSame( array(), Agend_Test_Mail::$sent );
	}

	#[Test]
	public function should_not_reference_wp_mail_anywhere_in_the_enquiry_path(): void {
		foreach ( array(
			'/agend-apps-core/includes/rest/directory-routes.php',
			'/agend-apps-core/includes/api/directory-enquiries.php',
			'/agend-apps-core/includes/class-agend-apps-captcha.php',
			'/agend-apps-core/includes/records/render/listing-enquiry.php',
		) as $file ) {
			$this->assertStringNotContainsString( 'wp_mail', (string) file_get_contents( AGEND_TESTS_ROOT . $file ), $file );
		}
	}
}
