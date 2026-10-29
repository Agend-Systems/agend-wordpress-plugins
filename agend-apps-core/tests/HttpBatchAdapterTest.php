<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Http_Batch;
use Agend_Test_Requests_Transport;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

/**
 * The Requests adapter sends a batch through the real Requests library with
 * an injected transport, and treats each request exactly as
 * wp_remote_request() would: the same filters and actions, with the same
 * arguments, around each one.
 *
 * Each case runs in its own process: the WordPress HTTP stand-ins it loads
 * would otherwise be visible to every later test.
 */
#[CoversClass( Agend_Apps_Http_Batch::class )]
#[RunTestsInSeparateProcesses]
final class HttpBatchAdapterTest extends TestCase {

	private const SEARCH = 'https://api.example.test/v1/directory/search?q=';
	private const FACETS = 'https://api.example.test/v1/directory/facets?fields=custom.size';

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/http-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-http-batch.php';
		if ( ! defined( 'WPINC' ) ) {
			define( 'WPINC', 'wp-includes' );
		}
		Agend_Test_Requests_Transport::reset();
		Agend_Test_WP::set_filter( 'agend_apps_api_batch_transport', new Agend_Test_Requests_Transport() );
	}

	/**
	 * @return array<string, array{url: string, args: array}>
	 */
	private function requests(): array {
		$args = array(
			'method'  => 'GET',
			'headers' => array( 'x-api-key' => 'test-api-key', 'Authorization' => 'Bearer member-token' ),
			'timeout' => 15,
		);

		return array(
			'search' => array( 'url' => self::SEARCH, 'args' => $args ),
			'facets' => array( 'url' => self::FACETS, 'args' => $args ),
		);
	}

	/**
	 * Records every call of a filter, passing the value through.
	 *
	 * @return \ArrayObject<int, array>
	 */
	private function recordFilter( string $hook ): \ArrayObject {
		$calls                            = new \ArrayObject();
		Agend_Test_WP::$filters[ $hook ] = static function ( ...$args ) use ( $calls ) {
			$calls[] = $args;
			return $args[0];
		};
		return $calls;
	}

	/**
	 * @return \ArrayObject<int, array>
	 */
	private function recordAction( string $hook ): \ArrayObject {
		$calls = new \ArrayObject();
		add_action(
			$hook,
			static function ( ...$args ) use ( $calls ): void {
				$calls[] = $args;
			}
		);
		return $calls;
	}

	#[Test]
	public function should_send_every_request_together_and_return_each_answer_as_wp_remote_request_would(): void {
		Agend_Test_Requests_Transport::$answers = array(
			self::SEARCH => array( 'status' => 200, 'body' => '{"data":[1]}' ),
			self::FACETS => array( 'status' => 200, 'body' => '{"data":{"custom.size":{}}}' ),
		);

		$sent = Agend_Apps_Http_Batch::send( $this->requests() );

		$this->assertSame( array( 'search', 'facets' ), array_keys( $sent ) );
		$this->assertCount( 2, Agend_Test_Requests_Transport::$sent, 'one batch carried both' );
		$this->assertSame( '{"data":[1]}', $sent['search']['response']['body'] );
		$this->assertSame( 200, $sent['search']['response']['response']['code'] );
		$this->assertSame( 'application/json', $sent['facets']['response']['headers']['content-type'] );
		$this->assertSame( 'Bearer member-token', Agend_Test_Requests_Transport::$sent[0]['headers']['Authorization'] );
		$this->assertSame( 15, Agend_Test_Requests_Transport::$sent[0]['options']['timeout'] );
	}

	#[Test]
	public function should_fire_the_http_api_filters_and_actions_for_each_request_with_its_own_arguments(): void {
		$request_args = $this->recordFilter( 'http_request_args' );
		$pre          = $this->recordFilter( 'pre_http_request' );
		$response     = $this->recordFilter( 'http_response' );
		$curl         = $this->recordAction( 'http_api_curl' );
		$debug        = $this->recordAction( 'http_api_debug' );

		Agend_Apps_Http_Batch::send( $this->requests() );

		$this->assertSame( array( self::SEARCH, self::FACETS ), array_column( (array) $request_args, 1 ) );
		$this->assertSame( 'test-api-key', $request_args[0][0]['headers']['x-api-key'] );
		$this->assertSame( array( false, false ), array_column( (array) $pre, 0 ) );
		$this->assertSame( array( self::SEARCH, self::FACETS ), array_column( (array) $pre, 2 ) );
		$this->assertSame( array( 'handle-search', 'handle-facets' ), array_column( (array) $curl, 0 ) );
		$this->assertSame( array( self::SEARCH, self::FACETS ), array_column( (array) $curl, 2 ) );
		$this->assertSame( 15, $curl[0][1]['timeout'], 'http_api_curl receives the parsed args' );
		$this->assertSame( array( 'response', 'response' ), array_column( (array) $debug, 1 ) );
		$this->assertSame( array( 'WpOrg\Requests\Requests', 'WpOrg\Requests\Requests' ), array_column( (array) $debug, 2 ) );
		$this->assertSame( array( self::SEARCH, self::FACETS ), array_column( (array) $debug, 4 ) );
		$this->assertSame( array( self::SEARCH, self::FACETS ), array_column( (array) $response, 2 ) );
	}

	#[Test]
	public function should_dispatch_the_requests_hooks_a_single_request_dispatches_for_each_request(): void {
		$before_request = $this->recordAction( 'requests-requests.before_request' );
		$before_parse   = $this->recordAction( 'requests-requests.before_parse' );

		Agend_Apps_Http_Batch::send( $this->requests() );

		$this->assertSame( array( self::SEARCH, self::FACETS ), array_column( (array) $before_request, 0 ) );
		$this->assertCount( 2, $before_parse );
		$this->assertStringStartsWith( 'HTTP/1.1 200', (string) $before_parse[0][0], 'dispatched with the raw response, before it is parsed' );
	}

	#[Test]
	public function should_return_a_pre_http_request_answer_without_sending_that_request(): void {
		Agend_Test_WP::$filters['pre_http_request'] = static function ( $pre, $args, $url ) {
			return self::SEARCH === $url ? array( 'body' => 'short-circuit' ) : $pre;
		};

		$sent = Agend_Apps_Http_Batch::send( $this->requests() );

		$this->assertSame( array( 'body' => 'short-circuit' ), $sent['search']['response'] );
		$this->assertSame( array( self::FACETS ), array_column( Agend_Test_Requests_Transport::$sent, 'url' ) );
	}

	#[Test]
	public function should_refuse_a_blocked_host_without_sending_it(): void {
		\WP_Http::$blocked = array( 'api.example.test' );
		$debug             = $this->recordAction( 'http_api_debug' );

		$sent = Agend_Apps_Http_Batch::send( $this->requests() );

		$this->assertSame( 'http_request_not_executed', $sent['search']['response']->get_error_code() );
		$this->assertSame( array(), Agend_Test_Requests_Transport::$sent );
		$this->assertCount( 2, $debug );
	}

	#[Test]
	public function should_answer_a_failed_request_with_the_error_wp_remote_request_returns_and_keep_the_others(): void {
		Agend_Test_Requests_Transport::$answers = array(
			self::SEARCH => array( 'error' => 'Operation timed out after 15001 milliseconds', 'code' => 28 ),
			self::FACETS => array( 'status' => 200, 'body' => '{}' ),
		);

		$sent = Agend_Apps_Http_Batch::send( $this->requests() );

		$this->assertInstanceOf( WP_Error::class, $sent['search']['response'] );
		$this->assertSame( 'http_request_failed', $sent['search']['response']->get_error_code() );
		$this->assertSame( 'cURL error 28: Operation timed out after 15001 milliseconds', $sent['search']['response']->get_error_message(), 'worded as Curl::request() words it' );
		$this->assertSame( 200, $sent['facets']['response']['response']['code'] );
	}

	#[Test]
	public function should_time_each_request_to_its_own_completion(): void {
		Agend_Test_Requests_Transport::$order   = array( self::FACETS, self::SEARCH );
		Agend_Test_Requests_Transport::$answers = array( self::SEARCH => array( 'status' => 200, 'body' => '{}', 'delay' => 60 ) );

		$sent = Agend_Apps_Http_Batch::send( $this->requests() );

		$this->assertLessThan( 50, $sent['facets']['elapsed'] );
		$this->assertGreaterThanOrEqual( 55, $sent['search']['elapsed'] );
		$this->assertSame( array( 'search', 'facets' ), array_keys( $sent ), 'answers keep the caller order' );
	}

	#[Test]
	public function should_attach_the_proxy_and_curl_listeners_through_curl_before_send_once_per_request(): void {
		$before_send = $this->recordAction( 'requests-curl.before_send' );
		$curl        = $this->recordAction( 'http_api_curl' );

		Agend_Apps_Http_Batch::send( $this->requests() );

		$this->assertSame( array( 'handle-search', 'handle-facets' ), array_column( (array) $before_send, 0 ) );
		$this->assertCount( 2, $curl );
	}

	#[Test]
	public function should_send_the_cookies_a_request_carries(): void {
		$requests                              = $this->requests();
		$requests['search']['args']['cookies'] = array( 'session' => 'abc' );

		Agend_Apps_Http_Batch::send( $requests );

		$this->assertSame( 'session=abc', Agend_Test_Requests_Transport::$sent[0]['headers']['Cookie'] ?? null );
		$this->assertArrayNotHasKey( 'Cookie', Agend_Test_Requests_Transport::$sent[1]['headers'] );
	}

	#[Test]
	public function should_keep_the_filename_wp_remote_request_returns(): void {
		$sent = Agend_Apps_Http_Batch::send( $this->requests() );

		$this->assertArrayHasKey( 'filename', $sent['search']['response'] );
		$this->assertNull( $sent['search']['response']['filename'] );
	}

	#[Test]
	public function should_return_a_null_pre_http_request_answer_as_core_does(): void {
		Agend_Test_WP::$filters['pre_http_request'] = static function ( $pre, $args, $url ) {
			return self::SEARCH === $url ? null : $pre;
		};

		$sent = Agend_Apps_Http_Batch::send( $this->requests() );

		$this->assertNull( $sent['search']['response'] );
		$this->assertSame( array( self::FACETS ), array_column( Agend_Test_Requests_Transport::$sent, 'url' ) );
	}

	#[Test]
	public function should_refuse_a_scheme_other_than_http_for_that_request_only(): void {
		$requests                  = $this->requests();
		$requests['search']['url'] = 'ssl://api.example.test/v1/directory/search';

		$sent = Agend_Apps_Http_Batch::send( $requests );

		$this->assertSame( 'Only HTTP(S) requests are handled.', $sent['search']['response']->get_error_message() );
		$this->assertSame( 200, $sent['facets']['response']['response']['code'] );
	}

	#[Test]
	public function should_answer_only_that_request_with_an_error_when_a_before_request_listener_throws(): void {
		add_action(
			'requests-requests.before_request',
			static function ( $url ): void {
				if ( self::SEARCH === $url ) {
					throw new \WpOrg\Requests\Exception( 'refused by a listener', 'listener' );
				}
			}
		);

		$sent = Agend_Apps_Http_Batch::send( $this->requests() );

		$this->assertSame( 'refused by a listener', $sent['search']['response']->get_error_message() );
		$this->assertSame( 200, $sent['facets']['response']['response']['code'] );
	}

	#[Test]
	public function should_send_one_after_another_through_wp_remote_request_when_parallel_is_switched_off(): void {
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );

		$sent = Agend_Apps_Http_Batch::send( $this->requests() );

		$this->assertSame( array(), Agend_Test_Requests_Transport::$sent );
		$this->assertSame( array( self::SEARCH, self::FACETS ), array_column( Agend_Test_WP::$requests, 'url' ) );
		$this->assertSame( array( 'search', 'facets' ), array_keys( $sent ) );
	}

	#[Test]
	public function should_send_a_single_request_through_wp_remote_request(): void {
		Agend_Apps_Http_Batch::send( array( 'search' => $this->requests()['search'] ) );

		$this->assertSame( array(), Agend_Test_Requests_Transport::$sent );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}
}
