<?php
/**
 * WordPress function stubs for the unit suite.
 *
 * Loaded once by tests/bootstrap.php. PHP cannot redeclare a function, so every
 * stub here is declared exactly once and all mutable state lives in
 * {@see Agend_Test_WP}, which each test resets. That is what makes the stubs
 * safe to share across the whole suite.
 *
 * Only functions the code under test actually calls are stubbed. Adding one
 * "just in case" hides a missing dependency that a real WordPress install would
 * surface, so add on demand.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

/**
 * Mutable state behind the stubs.
 *
 * Reset between tests by Agend\Tests\TestCase::setUp().
 */
final class Agend_Test_WP {

	/** @var array<string, mixed> */
	public static array $transients = array();

	/** @var array<string, array<int, callable>> */
	public static array $actions = array();

	/** @var array<string, int> */
	public static array $did_action = array();

	/** @var array<string, callable> Filter callbacks keyed by hook name. */
	public static array $filters = array();

	/** @var array<string, mixed> */
	public static array $options = array();

	/** @var array<int, array{url: string, headers: array<string, string>}> */
	public static array $requests = array();

	/** @var mixed Value the next agend_apps_crm_get_tiers() call returns. */
	public static $tiers_response = array();

	/** Resets every stub back to a clean state. */
	public static function reset(): void {
		self::$transients     = array();
		self::$actions        = array();
		self::$did_action     = array();
		self::$filters        = array();
		self::$options        = array();
		self::$requests       = array();
		self::$tiers_response = array();
	}

	/**
	 * Sets the value a filter hook resolves to.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value to return.
	 */
	public static function set_filter( string $hook, $value ): void {
		self::$filters[ $hook ] = static function () use ( $value ) {
			return $value;
		};
	}
}

// ---------------------------------------------------------------------------
// Transients
// ---------------------------------------------------------------------------

function get_transient( string $key ) {
	return array_key_exists( $key, Agend_Test_WP::$transients )
		? Agend_Test_WP::$transients[ $key ]
		: false;
}

function set_transient( string $key, $value, int $ttl = 0 ): bool {
	Agend_Test_WP::$transients[ $key ] = $value;
	return true;
}

function delete_transient( string $key ): bool {
	unset( Agend_Test_WP::$transients[ $key ] );
	return true;
}

// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
	Agend_Test_WP::$actions[ $hook ][] = $callback;
	return true;
}

function do_action( string $hook, ...$args ): void {
	Agend_Test_WP::$did_action[ $hook ] = ( Agend_Test_WP::$did_action[ $hook ] ?? 0 ) + 1;

	foreach ( Agend_Test_WP::$actions[ $hook ] ?? array() as $callback ) {
		call_user_func_array( $callback, $args );
	}
}

function did_action( string $hook ): int {
	return Agend_Test_WP::$did_action[ $hook ] ?? 0;
}

function apply_filters( string $hook, $value, ...$args ) {
	if ( isset( Agend_Test_WP::$filters[ $hook ] ) ) {
		return ( Agend_Test_WP::$filters[ $hook ] )( $value, ...$args );
	}

	return $value;
}

function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
	Agend_Test_WP::$filters[ $hook ] = $callback;
	return true;
}

// ---------------------------------------------------------------------------
// Options, escaping, i18n
// ---------------------------------------------------------------------------

function get_option( string $key, $default = false ) {
	return Agend_Test_WP::$options[ $key ] ?? $default;
}

function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html__( $text, $domain = null ): string {
	return (string) $text;
}

function __( $text, $domain = null ): string {
	return (string) $text;
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

/**
 * Records the request and returns a body that differs per call, so a cached
 * response is always distinguishable from a freshly fetched one.
 */
function wp_remote_request( string $url, array $args = array() ) {
	Agend_Test_WP::$requests[] = array(
		'url'     => $url,
		'headers' => $args['headers'] ?? array(),
	);

	return array(
		'body' => (string) json_encode( array( 'call' => count( Agend_Test_WP::$requests ) ) ),
	);
}

function wp_remote_retrieve_body( $response ): string {
	return $response['body'] ?? '';
}

function wp_remote_retrieve_response_code( $response ): int {
	return 200;
}

/**
 * Per-header stub.
 *
 * Returning one value for every header made the API client read the content
 * type as its remaining rate-limit budget and throttle itself, which cost a
 * debugging cycle. Header names are matched explicitly for that reason.
 */
function wp_remote_retrieve_header( $response, string $header ) {
	switch ( strtolower( $header ) ) {
		case 'x-ratelimit-remaining':
			return '999';
		case 'x-ratelimit-reset':
			return (string) ( time() + 60 );
		case 'content-type':
			return 'application/json';
		default:
			return '';
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal WP_Error stand-in. */
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}
