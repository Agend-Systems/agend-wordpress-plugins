<?php
/**
 * Stand-ins for the parts of WordPress's HTTP API that
 * Agend_Apps_Http_Batch calls around the Requests library, plus a Requests
 * transport that answers without a network.
 *
 * Each stand-in keeps only the behaviour the adapter relies on. The library
 * itself is the real one (rmccue/requests, the version WordPress bundles).
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( (array) $defaults, (array) $args );
	}
}

if ( ! function_exists( 'do_action_ref_array' ) ) {
	function do_action_ref_array( string $hook, array $args ): void {
		Agend_Test_WP::$did_action[ $hook ] = ( Agend_Test_WP::$did_action[ $hook ] ?? 0 ) + 1;

		foreach ( Agend_Test_WP::$actions[ $hook ] ?? array() as $callback ) {
			call_user_func_array( $callback, $args );
		}
	}
}

if ( ! function_exists( 'mbstring_binary_safe_encoding' ) ) {
	function mbstring_binary_safe_encoding( bool $reset = false ): void {}
}

if ( ! function_exists( 'reset_mbstring_encoding' ) ) {
	function reset_mbstring_encoding(): void {}
}

if ( ! function_exists( 'get_status_header_desc' ) ) {
	function get_status_header_desc( $code ): string {
		return array( 200 => 'OK', 401 => 'Unauthorized', 500 => 'Internal Server Error' )[ (int) $code ] ?? '';
	}
}

if ( ! function_exists( 'get_temp_dir' ) ) {
	function get_temp_dir(): string {
		return sys_get_temp_dir() . '/';
	}
}

if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( string $path ): bool {
		return is_writable( $path );
	}
}

if ( ! class_exists( 'WP_Http' ) ) {
	/** Hosts in $blocked are refused, as WP_HTTP_BLOCK_EXTERNAL refuses them. */
	class WP_Http {
		/** @var string[] */
		public static array $blocked = array();

		public function block_request( $uri ): bool {
			return in_array( (string) wp_parse_url( $uri, PHP_URL_HOST ), self::$blocked, true );
		}

		public static function processHeaders( $headers ): array {
			return array( 'headers' => array() );
		}

		public static function normalize_cookies( $cookies ) {
			return $cookies;
		}

		public static function browser_redirect_compatibility( $location, $headers, $data, &$options, $original ): void {}

		public static function validate_redirects( $location ): void {}
	}
}

if ( ! function_exists( '_wp_http_get_object' ) ) {
	function _wp_http_get_object(): WP_Http {
		return new WP_Http();
	}
}

if ( ! class_exists( 'WP_HTTP_Proxy' ) ) {
	class WP_HTTP_Proxy {
		public function is_enabled(): bool {
			return false;
		}
	}
}

if ( ! class_exists( 'WP_HTTP_Requests_Hooks' ) ) {
	/** Bridges Requests hooks to WordPress actions, as core's class does. */
	class WP_HTTP_Requests_Hooks extends \WpOrg\Requests\Hooks {
		protected $url;

		protected $request = array();

		public function __construct( $url, $request ) {
			$this->url     = $url;
			$this->request = $request;
		}

		public function dispatch( $hook, $parameters = array() ) {
			$result = parent::dispatch( $hook, $parameters );

			if ( 'curl.before_send' === $hook ) {
				do_action_ref_array( 'http_api_curl', array( &$parameters[0], $this->request, $this->url ) );
			}

			do_action_ref_array( "requests-{$hook}", $parameters );

			return $result;
		}
	}
}

if ( ! class_exists( 'WP_HTTP_Requests_Response' ) ) {
	/** The array shape wp_remote_request() returns, from a Requests response. */
	class WP_HTTP_Requests_Response {
		private \WpOrg\Requests\Response $response;

		private string $filename;

		public function __construct( \WpOrg\Requests\Response $response, $filename = '' ) {
			$this->response = $response;
			$this->filename = (string) $filename;
		}

		public function to_array(): array {
			$headers = array();
			foreach ( $this->response->headers->getAll() as $name => $values ) {
				$headers[ strtolower( (string) $name ) ] = implode( ', ', (array) $values );
			}

			return array(
				'headers'  => $headers,
				'body'     => $this->response->body,
				'response' => array(
					'code'    => $this->response->status_code,
					'message' => get_status_header_desc( $this->response->status_code ),
				),
				'cookies'  => array(),
				'filename' => $this->filename,
			);
		}
	}
}

/**
 * Answers each request from a table, following the contract of the cURL
 * transport's request_multiple() (Requests/src/Transport/Curl.php): it
 * dispatches `curl.before_multi_add` per request, hands each raw response to
 * `transport.internal.parse_response` (or an exception to
 * `transport.internal.parse_error`), then dispatches
 * `multiple.request.complete`, in the order the answers are listed.
 */
final class Agend_Test_Requests_Transport implements \WpOrg\Requests\Transport {

	/**
	 * Raw answers by URL: an HTTP status and body, or an exception message
	 * under `error`.
	 *
	 * @var array<string, array{status?: int, body?: string, error?: string}>
	 */
	public static array $answers = array();

	/** @var string[] Answer order by URL; the table's order when empty. */
	public static array $order = array();

	/** @var array<int, array{url: string, headers: array, options: array}> */
	public static array $sent = array();

	public static function reset(): void {
		self::$answers = array();
		self::$order   = array();
		self::$sent    = array();
	}

	public function request( $url, $headers = array(), $data = array(), $options = array() ) {
		return self::raw( (string) $url );
	}

	public function request_multiple( $requests, $options ) {
		$handles = array();
		foreach ( $requests as $id => $request ) {
			self::$sent[]   = array( 'url' => $request['url'], 'headers' => $request['headers'], 'options' => $request['options'] );
			$handles[ $id ] = 'handle-' . $id;
			$request['options']['hooks']->dispatch( 'curl.before_multi_add', array( &$handles[ $id ] ) );
		}

		$ids = array_keys( $requests );
		if ( ! empty( self::$order ) ) {
			usort(
				$ids,
				static function ( $a, $b ) use ( $requests ): int {
					return array_search( $requests[ $a ]['url'], self::$order, true ) <=> array_search( $requests[ $b ]['url'], self::$order, true );
				}
			);
		}

		$responses = array();
		foreach ( $ids as $id ) {
			$request = $requests[ $id ];
			$answer  = self::$answers[ $request['url'] ] ?? array( 'status' => 200, 'body' => '{}' );

			if ( isset( $answer['error'] ) ) {
				$responses[ $id ] = new \WpOrg\Requests\Exception\Transport\Curl( $answer['error'], \WpOrg\Requests\Exception\Transport\Curl::EASY );
				$request['options']['hooks']->dispatch( 'transport.internal.parse_error', array( &$responses[ $id ], $request ) );
			} else {
				$responses[ $id ] = self::raw( $request['url'] );
				$request['options']['hooks']->dispatch( 'transport.internal.parse_response', array( &$responses[ $id ], $request ) );
			}

			if ( ! is_string( $responses[ $id ] ) ) {
				$request['options']['hooks']->dispatch( 'multiple.request.complete', array( &$responses[ $id ], $id ) );
			}
		}

		return $responses;
	}

	public static function test( $capabilities = array() ) {
		return true;
	}

	private static function raw( string $url ): string {
		$answer = self::$answers[ $url ] ?? array( 'status' => 200, 'body' => '{}' );

		return sprintf( "HTTP/1.1 %d X\r\nContent-Type: application/json\r\n\r\n%s", (int) ( $answer['status'] ?? 200 ), (string) ( $answer['body'] ?? '' ) );
	}
}
