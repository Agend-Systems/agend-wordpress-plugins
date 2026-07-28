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

function update_option( string $key, $value, $autoload = null ): bool {
	Agend_Test_WP::$options[ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
	unset( Agend_Test_WP::$options[ $key ] );
	return true;
}

/** Minimal paragraph wrapper. Enough for assertions; not WordPress's algorithm. */
function wpautop( $text, $br = true ): string {
	$text = trim( (string) $text );
	return '' === $text ? '' : '<p>' . $text . '</p>';
}

function esc_url( $url ): string {
	return htmlspecialchars( (string) $url, ENT_QUOTES );
}

function add_query_arg( ...$args ) {
	if ( is_array( $args[0] ) && count( $args ) === 1 ) {
		return '';
	}
	[ $key, $value, $url ] = array( $args[0], $args[1] ?? '', $args[2] ?? '' );
	$sep = str_contains( (string) $url, '?' ) ? '&' : '?';
	return $url . $sep . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
}

function home_url( $path = '' ): string {
	return 'https://example.test' . $path;
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

/**
 * Post meta store for tests that exercise document-level promotion.
 *
 * Deliberately a real read/write pair rather than a no-op: the promotion walk
 * only counts as tested if the rewritten document can be read back and
 * asserted on.
 */
if ( ! isset( $GLOBALS['agend_test_post_meta'] ) ) {
	$GLOBALS['agend_test_post_meta'] = array();
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$value = $GLOBALS['agend_test_post_meta'][ (int) $post_id ][ $key ] ?? '';

		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['agend_test_post_meta'][ (int) $post_id ][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		return $value;
	}
}

/**
 * Current-user stubs for the condition runtime tests.
 *
 * Driven by globals so a test can move the visitor between signed-out,
 * signed-in and a specific user without rebuilding WordPress.
 */
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return ! empty( $GLOBALS['agend_test_current_user_id'] );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['agend_test_current_user_id'] ?? 0 );
	}
}

if ( ! class_exists( 'Agend_Test_User' ) ) {
	/**
	 * Minimal WP_User stand-in for the condition providers.
	 *
	 * Roles come from a global so a test can move the visitor between roles
	 * without a user table.
	 */
	class Agend_Test_User {
		public array $roles = array();

		public function __construct( array $roles = array() ) {
			$this->roles = $roles;
		}

		public function exists(): bool {
			return ! empty( $GLOBALS['agend_test_current_user_id'] );
		}
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return new Agend_Test_User( (array) ( $GLOBALS['agend_test_current_user_roles'] ?? array() ) );
	}
}
