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

	/**
	 * Site (network-wide) transients: a separate store from {@see $transients}
	 * because plugin updates are network-wide (`get_site_transient()` /
	 * `set_site_transient()`) even on a single-site install.
	 *
	 * @var array<string, mixed>
	 */
	public static array $site_transients = array();

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

	/** @var array<int, array{timestamp: int, hook: string, args: array<int, mixed>}> WP-Cron events scheduled via wp_schedule_single_event(). */
	public static array $scheduled_events = array();

	public static int $queried_object_id = 0;

	public static array $query_vars = array();

	/** @var bool Whether the active theme declares theme.json (wp_theme_has_theme_json()). */
	public static bool $theme_has_theme_json = false;

	/** @var array The merged Global Styles tree returned by wp_get_global_styles(). */
	public static array $global_styles = array();

	/**
	 * Canned `wp_remote_request()` responses, consumed one per call (FIFO).
	 * Empty means the default (200, `{"call": N}`) behaviour.
	 *
	 * @var array<int, array{status:int, body:string}>
	 */
	public static array $canned_responses = array();

	/** @var array<int, array{location: string, status: int}> Recorded `wp_safe_redirect()` calls. */
	public static array $redirects = array();

	/** Number of `wp_update_plugins()` calls recorded. */
	public static int $wp_update_plugins_calls = 0;

	/** @var string[] Style handles recorded by `wp_enqueue_style()`, in call order. */
	public static array $enqueued_styles = array();

	/**
	 * Value `wp_style_engine_get_stylesheet_from_context()` returns. A test
	 * sets this directly to simulate block-supports layout CSS having been
	 * generated during a render; '' (the default) simulates an empty store.
	 */
	public static string $style_engine_stylesheet = '';

	/** Resets every stub back to a clean state. */
	public static function reset(): void {
		self::$queried_object_id    = 0;
		self::$query_vars           = array();
		self::$theme_has_theme_json = false;
		self::$global_styles        = array();
		self::$transients        = array();
		self::$site_transients   = array();
		self::$actions           = array();
		self::$did_action        = array();
		self::$filters           = array();
		self::$options           = array();
		self::$requests          = array();
		self::$tiers_response    = array();
		self::$scheduled_events  = array();
		self::$canned_responses  = array();
		self::$redirects         = array();
		self::$wp_update_plugins_calls = 0;
		self::$enqueued_styles   = array();
		self::$style_engine_stylesheet = '';
	}

	/**
	 * Queues the next `wp_remote_request()` call to return a specific status
	 * and JSON body, so a test can drive a gateway response
	 * (non-2xx included) without a live HTTP call.
	 *
	 * @param int          $status HTTP status code.
	 * @param array|string $body   Response body; arrays are JSON-encoded.
	 */
	public static function queue_response( int $status, $body ): void {
		self::$canned_responses[] = array(
			'status' => $status,
			'body'   => is_string( $body ) ? $body : (string) json_encode( $body ),
		);
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

if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( string $key ) {
		return array_key_exists( $key, Agend_Test_WP::$site_transients )
			? Agend_Test_WP::$site_transients[ $key ]
			: false;
	}
}

if ( ! function_exists( 'set_site_transient' ) ) {
	function set_site_transient( string $key, $value, int $ttl = 0 ): bool {
		Agend_Test_WP::$site_transients[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_site_transient' ) ) {
	function delete_site_transient( string $key ): bool {
		unset( Agend_Test_WP::$site_transients[ $key ] );
		return true;
	}
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

/**
 * Deprecated-hook variant of {@see apply_filters()}: runs the same filter
 * machinery against `$args[0]` under the OLD hook name, so a test (or a
 * site's real `add_filter()`) registered on the pre-rename name still
 * changes the result.
 *
 * @param string $hook        The deprecated hook name.
 * @param array  $args        `array( $value, ...$extra )`.
 * @param string $version     Unused; kept for signature parity with WordPress.
 * @param string $replacement Unused; kept for signature parity with WordPress.
 */
function apply_filters_deprecated( string $hook, array $args, string $version = '', string $replacement = '' ) {
	$value = array_shift( $args );

	return apply_filters( $hook, $value, ...$args );
}

/** No-op: the stub harness does not assert on deprecation notices. */
function _deprecated_function( string $function, string $version, string $replacement = '' ): void {}

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

/**
 * Minimal `sanitize_title()` stand-in: transliterates common Latin-1
 * accented characters to their ASCII base letter, then applies WordPress's
 * lowercase-hyphenate-collapse-trim algorithm.
 *
 * A byte-stripping fallback (drop anything non-ASCII) would make an
 * accented entitlement name slugify to nothing, which is exactly the
 * "transliterates rather than drops" behaviour the gate-key conversion
 * needs to be exercised against. Not WordPress's full transliteration
 * table -- just enough Latin-1 coverage for the entitlement names this
 * plugin actually sees.
 */
function sanitize_title( $title, $fallback_title = '', $context = 'save' ): string {
	$title = (string) $title;

	$accents = array(
		'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
		'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
		'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
		'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
		'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
		'ý' => 'y', 'ÿ' => 'y',
		'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
	);

	$title = strtr( strtolower( $title ), $accents );
	$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
	$title = trim( (string) $title, '-' );

	return $title;
}

/**
 * Minimal stand-in for WordPress's sanitize_text_field(): strips tags,
 * collapses line breaks and extra whitespace to a single space, trims. Not a
 * full re-implementation (WP's version also strips %-encoded octets) --
 * enough for the plain single-line settings values this test suite
 * exercises.
 */
function sanitize_text_field( $value ): string {
	$value = strip_tags( (string) $value );
	$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );
	return trim( (string) $value );
}

function esc_url( $url ): string {
	return htmlspecialchars( (string) $url, ENT_QUOTES );
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/** Minimal stand-in: trims only, no kses/scheme allow-listing. */
	function esc_url_raw( $url ): string {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Thin wrapper over PHP's parse_url(), matching WP's own fallback shape,
	 * including the optional `$component` (e.g. `PHP_URL_HOST`) argument.
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	/** Defaults to 'production' -- the strictest/safest value, matching WP's own default. */
	function wp_get_environment_type(): string {
		return (string) ( $GLOBALS['agend_test_environment_type'] ?? 'production' );
	}
}

function add_query_arg( ...$args ) {
	if ( is_array( $args[0] ) ) {
		// `add_query_arg( array $args, string $url = '' )`.
		$pairs = $args[0];
		$url   = (string) ( $args[1] ?? '' );
	} else {
		// `add_query_arg( string $key, string $value, string $url = '' )`.
		$pairs = array( $args[0] => $args[1] ?? '' );
		$url   = (string) ( $args[2] ?? '' );
	}

	if ( array() === $pairs ) {
		return $url;
	}

	$query = array();

	foreach ( $pairs as $key => $value ) {
		$query[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}

	$sep = str_contains( $url, '?' ) ? '&' : '?';

	return $url . $sep . implode( '&', $query );
}

function home_url( $path = '' ): string {
	return 'https://example.test' . $path;
}

if ( ! function_exists( 'wp_login_url' ) ) {
	/**
	 * Minimal stand-in for WordPress's wp_login_url(): a fixed login page under
	 * the stub site root, with an optional redirect query arg appended.
	 *
	 * @param string $redirect     Redirect URL after login.
	 * @param bool   $force_reauth Ignored; the stub has no session state to force.
	 * @return string
	 */
	function wp_login_url( string $redirect = '', bool $force_reauth = false ): string {
		$url = home_url( '/wp-login.php' );

		if ( '' !== $redirect ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
		}

		return $url;
	}
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

function _n( $single, $plural, $number, $domain = null ): string {
	return (string) ( 1 === (int) $number ? $single : $plural );
}

/**
 * The site timezone, fixed to UTC for deterministic test fixtures.
 */
function wp_timezone(): DateTimeZone {
	return new DateTimeZone( 'UTC' );
}

/**
 * Formats a timestamp in a given (or the site) timezone, mirroring wp_date()
 * closely enough for the date-formatting unit tests: no WP_Locale month/day
 * translation, since the format strings under test do not need it.
 */
function wp_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ): string|false {
	$timestamp = $timestamp ?? time();
	$timezone  = $timezone ?? wp_timezone();
	$datetime  = new DateTime( '@' . $timestamp );
	$datetime->setTimezone( $timezone );
	return $datetime->format( $format );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

/**
 * Minimal `wp_list_pluck()` stand-in: extracts one column from a list of
 * arrays or objects, keyed by the source array's own numeric position.
 *
 * @param array<int, mixed> $list        List of arrays/objects.
 * @param int|string        $field       Field to pluck.
 * @param int|string|null   $index_key   Ignored; the entitlement mirror never re-indexes.
 * @return array<int, mixed>
 */
function wp_list_pluck( array $list, $field, $index_key = null ): array {
	unset( $index_key );

	return array_map(
		static function ( $item ) use ( $field ) {
			return is_array( $item ) ? ( $item[ $field ] ?? null ) : ( $item->$field ?? null );
		},
		$list
	);
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

	if ( ! empty( Agend_Test_WP::$canned_responses ) ) {
		$next = array_shift( Agend_Test_WP::$canned_responses );

		return array(
			'body'        => $next['body'],
			'status_code' => $next['status'],
		);
	}

	return array(
		'body' => (string) json_encode( array( 'call' => count( Agend_Test_WP::$requests ) ) ),
	);
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/** Thin `wp_remote_request()` wrapper, matching WordPress's own. */
	function wp_remote_get( string $url, array $args = array() ) {
		$args['method'] = 'GET';
		return wp_remote_request( $url, $args );
	}
}

function wp_remote_retrieve_body( $response ): string {
	return $response['body'] ?? '';
}

function wp_remote_retrieve_response_code( $response ): int {
	return isset( $response['status_code'] ) ? (int) $response['status_code'] : 200;
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
		private array $data;

		public function __construct( string $code = '', string $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = is_array( $data ) ? $data : array( $data );
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

// ---------------------------------------------------------------------------
// WP-Cron
// ---------------------------------------------------------------------------

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * 60 );
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/** Fixed 'version' value; the updater's tests only assert the User-Agent is well-formed. */
	function get_bloginfo( string $show = '' ): string {
		return 'version' === $show ? '6.8' : '';
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Reports whether an event with the given hook + args is already scheduled.
	 *
	 * @param string             $hook Cron hook name.
	 * @param array<int, mixed>  $args Cron event args.
	 * @return int|false Timestamp of the scheduled event, or false when none is scheduled.
	 */
	function wp_next_scheduled( string $hook, array $args = array() ) {
		foreach ( Agend_Test_WP::$scheduled_events as $event ) {
			if ( $event['hook'] === $hook && $event['args'] === $args ) {
				return $event['timestamp'];
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Records a single scheduled cron event.
	 *
	 * @param int                $timestamp Unix timestamp for the event.
	 * @param string             $hook      Cron hook name.
	 * @param array<int, mixed>  $args      Cron event args.
	 * @return bool
	 */
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		Agend_Test_WP::$scheduled_events[] = array(
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
		);

		return true;
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
		public int $ID;

		public function __construct( array $roles = array(), int $id = 0 ) {
			$this->roles = $roles;
			$this->ID    = $id;
		}

		public function exists(): bool {
			return ! empty( $GLOBALS['agend_test_current_user_id'] );
		}
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return new Agend_Test_User(
			(array) ( $GLOBALS['agend_test_current_user_roles'] ?? array() ),
			(int) ( $GLOBALS['agend_test_current_user_id'] ?? 0 )
		);
	}
}

if ( ! function_exists( 'wp_set_current_user' ) ) {
	/**
	 * Swaps the effective current user, as impersonation code (e.g. the
	 * membership snapshot sync) relies on.
	 */
	function wp_set_current_user( int $user_id ) {
		$GLOBALS['agend_test_current_user_id'] = $user_id;

		return wp_get_current_user();
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Minimal WP_User stand-in.
	 *
	 * A real class rather than a stdClass cast, because production code
	 * type-hints `WP_User` (e.g. the `wp_login` and
	 * `wp_saml_idp_user_attributes_lightsaml` handlers), and a cast object
	 * would fail that type check on a real site while slipping past a test.
	 *
	 * Carries the password/email/name fields the login-bridge decision layer
	 * reads; a test registers an instance in `$GLOBALS['agend_test_users']`
	 * (or via {@see agend_apps_test_register_user()}) so `get_user_by()` can
	 * resolve it.
	 */
	class WP_User {
		public int $ID;
		public string $user_email = '';
		public string $user_pass  = '';
		public string $first_name = '';
		public string $last_name  = '';

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}
	}
}

if ( ! isset( $GLOBALS['agend_test_users'] ) ) {
	$GLOBALS['agend_test_users'] = array();
}

if ( ! function_exists( 'get_user_by' ) ) {
	/**
	 * User lookup stub, backed by the `$GLOBALS['agend_test_users']` registry
	 * a test populates directly (`$GLOBALS['agend_test_users'][] = $user;`).
	 *
	 * @param string     $field 'email' or 'id'.
	 * @param string|int $value Value to match.
	 * @return WP_User|false
	 */
	function get_user_by( string $field, $value ) {
		foreach ( (array) $GLOBALS['agend_test_users'] as $user ) {
			if ( ! ( $user instanceof WP_User ) ) {
				continue;
			}

			if ( 'email' === $field && strtolower( $user->user_email ) === strtolower( (string) $value ) ) {
				return $user;
			}

			if ( 'id' === $field && $user->ID === (int) $value ) {
				return $user;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_check_password' ) ) {
	/**
	 * Password-check stub. Real WordPress hashes the password; the tests
	 * that drive this stub set a user's `user_pass` to the plaintext they
	 * expect, so a direct comparison is the correct fake here.
	 *
	 * @param string     $password Plaintext password submitted.
	 * @param string     $hash     Stored password ('hash' in name only here).
	 * @param string|int $user_id  Ignored; matches the real signature.
	 * @return bool
	 */
	function wp_check_password( string $password, string $hash, $user_id = '' ): bool {
		unset( $user_id );

		return '' !== $hash && $password === $hash;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * @param mixed $email Value to validate.
	 * @return string|false The email when valid, false otherwise.
	 */
	function is_email( $email ) {
		return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? (string) $email : false;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Capability stub, true by default; a test denies a capability via
	 * `$GLOBALS['agend_test_current_user_can'][$capability] = false;`.
	 *
	 * @param string $capability Capability to check.
	 * @return bool
	 */
	function current_user_can( string $capability ): bool {
		return (bool) ( $GLOBALS['agend_test_current_user_can'][ $capability ] ?? true );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ): string {
		return 'test-nonce-' . md5( (string) $action );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'self_admin_url' ) ) {
	/** Single-site stand-in: the test suite has no multisite/network context. */
	function self_admin_url( string $path = '' ): string {
		return admin_url( $path );
	}
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( string $actionurl, $action = -1 ): string {
		$sep = str_contains( $actionurl, '?' ) ? '&' : '?';
		return $actionurl . $sep . '_wpnonce=' . wp_create_nonce( $action );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	/**
	 * No-op stand-in: the unit suite does not exercise nonce forgery, so this
	 * only records that the check happened (via `did_action()`-style counting
	 * is unnecessary; the calling code's own capability/behaviour is what
	 * tests assert on).
	 */
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		return true;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/** Records the redirect location rather than sending real headers. */
	function wp_safe_redirect( string $location, int $status = 302 ): bool {
		Agend_Test_WP::$redirects[] = array(
			'location' => $location,
			'status'   => $status,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_update_plugins' ) ) {
	/** Records that a forced update check was requested. */
	function wp_update_plugins(): void {
		++Agend_Test_WP::$wp_update_plugins_calls;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Throws rather than exiting the process, so a test can assert the
	 * permission-denied path without killing the test runner.
	 */
	function wp_die( $message = '', $title = '', $args = array() ) {
		throw new Agend_Test_WP_Die_Exception( (string) $message );
	}
}

if ( ! class_exists( 'Agend_Test_WP_Die_Exception' ) ) {
	/** Thrown by the `wp_die()` stub so a test can assert on it. */
	class Agend_Test_WP_Die_Exception extends \RuntimeException {}
}

// ---------------------------------------------------------------------------
// REST API — minimal stand-ins so a controller class (which extends
// WP_REST_Controller) can be instantiated and its route methods called
// directly in a test, bypassing `register_routes()`/dispatch entirely.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE   = 'GET';
		const CREATABLE  = 'POST';
		const EDITABLE   = 'POST, PUT, PATCH';
		const DELETABLE  = 'DELETE';
		const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stand-in: a test builds one and sets params
	 * directly, bypassing WordPress's own routing/sanitisation.
	 */
	class WP_REST_Request {
		private string $method;
		private string $route;

		/** @var array<string, mixed> */
		private array $params = array();

		/** @var array<string, string> */
		private array $headers = array();

		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		public function get_header( string $key ) {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response stand-in: a test reads back `get_data()` and
	 * `get_status()`, matching the real class's public surface.
	 */
	class WP_REST_Response {
		private $data;
		private int $status;

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	abstract class WP_REST_Controller {}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * No-op recording stub: route registration itself is WordPress dispatch
	 * machinery, out of scope for these unit tests, which call a
	 * controller's route method directly.
	 */
	function register_rest_route( string $namespace, string $route, array $args = array(), bool $override = false ) {
		unset( $namespace, $route, $args, $override );

		return true;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return $nonce === wp_create_nonce( $action ) ? 1 : false;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	/**
	 * User meta stub, backed by a global registry keyed by user id then meta
	 * key. Mirrors {@see get_post_meta()}'s `$single` contract.
	 */
	function get_user_meta( int $user_id, string $key = '', bool $single = false ) {
		$value = $GLOBALS['agend_test_user_meta'][ $user_id ][ $key ] ?? '';

		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( int $user_id, string $key, $value ) {
		$GLOBALS['agend_test_user_meta'][ $user_id ][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( int $user_id, string $key ): bool {
		unset( $GLOBALS['agend_test_user_meta'][ $user_id ][ $key ] );

		return true;
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal WP_Post stand-in.
	 *
	 * A real class rather than a stdClass cast, because production code checks
	 * `instanceof WP_Post` before trusting a post, and a cast object would slip
	 * past a test while failing on a real site.
	 */
	class WP_Post {
		public int $ID = 0;
		public string $post_status = 'publish';
		public string $post_content = '';
		public string $post_title = '';
		public string $post_type = 'page';

		public function __construct( array $fields = array() ) {
			foreach ( $fields as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
		}
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Post lookup stub, backed by a global registry of field arrays.
	 *
	 * Returns null for an unknown id, matching WordPress, so a test can cover
	 * the missing-post path without a database.
	 */
	function get_post( $id = null ) {
		$fields = $GLOBALS['agend_test_posts'][ (int) $id ] ?? null;

		if ( null === $fields ) {
			return null;
		}

		return $fields instanceof WP_Post ? $fields : new WP_Post( (array) $fields );
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Permalink stub: one distinguishable URL per known post id, built from
	 * the id alone (the stub `WP_Post` carries no `post_name`, so this does
	 * not attempt to mirror WordPress's slug-based permalink structure).
	 *
	 * @param int|WP_Post $post Post id, or a post object.
	 * @return string|false The permalink, or false when the post is unknown.
	 */
	function get_permalink( $post = 0 ) {
		$id       = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
		$post_obj = get_post( $id );

		if ( ! ( $post_obj instanceof WP_Post ) ) {
			return false;
		}

		return 'https://example.test/page-' . $post_obj->ID . '/';
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Ensures a single trailing slash, matching WordPress's helper.
	 *
	 * @param string $value The string to slash.
	 * @return string
	 */
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Absolute integer cast, matching WordPress's helper.
	 *
	 * @param mixed $value The value to cast.
	 * @return int
	 */
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Post-content sanitiser stub: strips everything but a basic allow-list.
	 * Tests assert the allow-list boundary (script gone, strong kept), not
	 * WordPress's full kses ruleset.
	 *
	 * @param string $content The HTML.
	 * @return string
	 */
	function wp_kses_post( $content ): string {
		return strip_tags( (string) $content, '<p><a><strong><em><b><i><ul><ol><li><br><h1><h2><h3><h4><h5><h6><blockquote><span><div><img>' );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Locale-agnostic number formatter, matching the en_AU result.
	 *
	 * @param float $number   The number.
	 * @param int   $decimals Decimal places.
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ): string {
		return number_format( (float) $number, (int) $decimals, '.', ',' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = null ): string {
		return esc_attr( (string) $text );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = null ): void {
		echo esc_attr( (string) $text );
	}
}

if ( ! function_exists( 'sanitize_hex_color' ) ) {
	/**
	 * Minimal `sanitize_hex_color()` stand-in, matching WordPress's own
	 * contract: a 3- or 6-digit `#` hex colour passes through unchanged, an
	 * empty string passes through as an empty string, and anything else
	 * (including a value entirely absent from a settings array) returns
	 * `null` -- callers use the `?:` (Elvis) operator to fall back to a
	 * default, which treats `null` and `''` alike.
	 *
	 * @param string $color The value to validate.
	 * @return string|null
	 */
	function sanitize_hex_color( $color ) {
		$color = (string) $color;

		if ( '' === $color ) {
			return '';
		}

		return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ? $color : null;
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	/** Minimal stand-in: WordPress keeps letters, digits, _ and -, dropping everything else. */
	function sanitize_html_class( $class, $fallback = '' ): string {
		$sanitized = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );
		return '' !== $sanitized ? $sanitized : (string) $fallback;
	}
}

// ---------------------------------------------------------------------------
// Front-end render context (queried page, permalinks, REST, escaping)
// ---------------------------------------------------------------------------

function esc_html_e( string $text, string $domain = 'default' ): void {
	echo esc_html( $text );
}

function rest_url( string $path = '' ): string {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}

function get_queried_object_id(): int {
	return Agend_Test_WP::$queried_object_id;
}

function get_query_var( string $var, $default = '' ) {
	return Agend_Test_WP::$query_vars[ $var ] ?? $default;
}

function wp_timezone_string(): string {
	return 'Australia/Sydney';
}

/**
 * Whether the active theme declares theme.json (block theme, or a classic
 * theme opting into Global Styles). {@see Agend_Test_WP::$theme_has_theme_json}
 * is the test double a case sets directly; there is no real WordPress here.
 */
function wp_theme_has_theme_json(): bool {
	return Agend_Test_WP::$theme_has_theme_json;
}

/**
 * The merged Global Styles tree. {@see Agend_Test_WP::$global_styles} is the
 * test double a case sets directly, shaped exactly like the real merged
 * styles array (Decision 2.3 paths).
 */
function wp_get_global_styles(): array {
	return Agend_Test_WP::$global_styles;
}

// ---------------------------------------------------------------------------
// Block editor (wp_block templates, block rendering, style engine), added
// for the block-editor template renderer/source, which parses and renders a
// `wp_block` post's own content directly rather than going through a real
// WordPress+Gutenberg install.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_post_type' ) ) {
	/**
	 * @param int|WP_Post|null $post Post id or object.
	 * @return string|false
	 */
	function get_post_type( $post = null ) {
		$post_obj = is_object( $post ) ? $post : get_post( $post );

		return ( $post_obj instanceof WP_Post ) ? $post_obj->post_type : false;
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	/**
	 * @param int|WP_Post|null $post Post id or object.
	 * @return string|false
	 */
	function get_post_status( $post = null ) {
		$post_obj = is_object( $post ) ? $post : get_post( $post );

		return ( $post_obj instanceof WP_Post ) ? $post_obj->post_status : false;
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	/**
	 * @param int|WP_Post|null $post Post id or object.
	 * @return string
	 */
	function get_the_title( $post = null ): string {
		$post_obj = is_object( $post ) ? $post : get_post( $post );

		return ( $post_obj instanceof WP_Post ) ? $post_obj->post_title : '';
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key ): bool {
		unset( $GLOBALS['agend_test_post_meta'][ (int) $post_id ][ $key ] );

		return true;
	}
}

if ( ! class_exists( 'WP_Block_Type' ) ) {
	/**
	 * Minimal `WP_Block_Type` stand-in: only the public properties
	 * `Agend_Apps_Block_Template_Renderer::ensure_styles()` (style handles)
	 * and the `render_block()` stub below (the dynamic render callback) read.
	 */
	class WP_Block_Type {
		public string $name;

		/** @var string[] */
		public array $style_handles = array();

		/** @var callable|null */
		public $render_callback = null;

		public function __construct( string $name, array $args = array() ) {
			$this->name = $name;

			if ( isset( $args['style_handles'] ) ) {
				$this->style_handles = (array) $args['style_handles'];
			} elseif ( isset( $args['style'] ) ) {
				$this->style_handles = (array) $args['style'];
			}

			if ( isset( $args['render_callback'] ) ) {
				$this->render_callback = $args['render_callback'];
			}
		}
	}
}

if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	/**
	 * Minimal `WP_Block_Type_Registry` stand-in: a name => `WP_Block_Type` map,
	 * enough for `ensure_styles()` to resolve a block's style handles and for
	 * the `render_block()` stub to find a dynamic block's render callback.
	 */
	final class WP_Block_Type_Registry {
		private static ?self $instance = null;

		/** @var array<string, WP_Block_Type> */
		private array $registered = array();

		public static function get_instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		public function register( string $name, array $args = array() ): WP_Block_Type {
			$block_type = new WP_Block_Type( $name, $args );

			$this->registered[ $name ] = $block_type;

			return $block_type;
		}

		public function get_registered( string $name ): ?WP_Block_Type {
			return $this->registered[ $name ] ?? null;
		}

		public function is_registered( string $name ): bool {
			return isset( $this->registered[ $name ] );
		}

		public function unregister( string $name ): void {
			unset( $this->registered[ $name ] );
		}

		/** Clears every registered block type. Called by Agend\Tests\TestCase::setUp(). */
		public function reset(): void {
			$this->registered = array();
		}
	}
}

if ( ! function_exists( 'register_block_type' ) ) {
	/**
	 * Minimal `register_block_type()` stand-in: only the (name, args) form
	 * these tests use, not the metadata-file-path form real WordPress also
	 * accepts.
	 *
	 * @param string $name Block name, e.g. 'agend-apps/record-field'.
	 * @param array  $args Block args (style_handles, render_callback, ...).
	 * @return WP_Block_Type
	 */
	function register_block_type( string $name, array $args = array() ): WP_Block_Type {
		return WP_Block_Type_Registry::get_instance()->register( $name, $args );
	}
}

if ( ! function_exists( 'agend_test_freeform_block' ) ) {
	/**
	 * A parsed-block array for a run of plain (non-block) content, matching
	 * the shape `parse_blocks()` gives a freeform segment (`blockName` null).
	 *
	 * @param string $html Raw HTML/text between two blocks.
	 * @return array<string, mixed>
	 */
	function agend_test_freeform_block( string $html ): array {
		return array(
			'blockName'    => null,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}
}

if ( ! function_exists( 'agend_test_parse_block_list' ) ) {
	/**
	 * Parses one nesting level of serialized block content, recursing into
	 * each block's own inner content.
	 *
	 * @param string $content Serialized block content.
	 * @return array<int, array<string, mixed>>
	 */
	function agend_test_parse_block_list( string $content ): array {
		$blocks = array();
		$pos    = 0;
		$len    = strlen( $content );

		while ( $pos < $len ) {
			$open = strpos( $content, '<!-- wp:', $pos );

			if ( false === $open ) {
				$text = substr( $content, $pos );
				if ( '' !== trim( $text ) ) {
					$blocks[] = agend_test_freeform_block( $text );
				}
				break;
			}

			if ( $open > $pos ) {
				$text = substr( $content, $pos, $open - $pos );
				if ( '' !== trim( $text ) ) {
					$blocks[] = agend_test_freeform_block( $text );
				}
			}

			$open_tag_end = strpos( $content, '-->', $open );
			if ( false === $open_tag_end ) {
				break;
			}
			$open_tag_end += 3;
			$tag           = substr( $content, $open, $open_tag_end - $open );

			if ( ! preg_match( '#^<!--\s*wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)\s*(\{.*\})?\s*(/)?-->#s', $tag, $m ) ) {
				// Malformed comment; skip past it rather than looping forever.
				$pos = $open_tag_end;
				continue;
			}

			$name  = false === strpos( $m[1], '/' ) ? 'core/' . $m[1] : $m[1];
			$attrs = array();

			if ( ! empty( $m[2] ) ) {
				$decoded = json_decode( $m[2], true );
				$attrs   = is_array( $decoded ) ? $decoded : array();
			}

			if ( '' !== ( $m[3] ?? '' ) ) {
				// Self-closing: no inner content, nothing further to find.
				$blocks[] = array(
					'blockName'    => $name,
					'attrs'        => $attrs,
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				);
				$pos = $open_tag_end;
				continue;
			}

			// Walk forward tracking nesting depth (further openers vs
			// closers, ignoring self-closing openers) to find THIS block's
			// own matching closing comment.
			$depth       = 1;
			$scan        = $open_tag_end;
			$close_start = null;
			$close_end   = null;

			while ( $scan < $len ) {
				$next_open  = strpos( $content, '<!-- wp:', $scan );
				$next_close = strpos( $content, '<!-- /wp:', $scan );

				if ( false === $next_close ) {
					break;
				}

				if ( false !== $next_open && $next_open < $next_close ) {
					$inner_open_end = strpos( $content, '-->', $next_open );
					if ( false === $inner_open_end ) {
						break;
					}
					$inner_open_end += 3;
					if ( '/-->' !== substr( $content, $inner_open_end - 4, 4 ) ) {
						++$depth;
					}
					$scan = $inner_open_end;
					continue;
				}

				$inner_close_end = strpos( $content, '-->', $next_close );
				if ( false === $inner_close_end ) {
					break;
				}
				$inner_close_end += 3;
				--$depth;

				if ( 0 === $depth ) {
					$close_start = $next_close;
					$close_end   = $inner_close_end;
					break;
				}

				$scan = $inner_close_end;
			}

			if ( null === $close_start ) {
				// Unclosed block; treat the remainder as its inner content.
				$close_start = $len;
				$close_end   = $len;
			}

			$blocks[] = array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => agend_test_parse_block_list( substr( $content, $open_tag_end, $close_start - $open_tag_end ) ),
				'innerHTML'    => '',
				'innerContent' => array(),
			);

			$pos = $close_end;
		}

		return $blocks;
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	/**
	 * Minimal block-comment parser for the unit suite.
	 *
	 * Real WordPress ships a proper streaming tokenizer (`WP_Block_Parser`);
	 * this stand-in only needs to correctly parse the plain
	 * `<!-- wp:namespace/name {"attr":"value"} -->...<!-- /wp:namespace/name -->`
	 * and self-closing `<!-- wp:namespace/name {...} /-->` comment forms this
	 * plugin's own fixtures use, with arbitrary nesting. A block comment with
	 * no namespace (e.g. `<!-- wp:paragraph -->`) is normalised to
	 * `core/paragraph`, matching WordPress's own default.
	 *
	 * @param string $content Serialized block content.
	 * @return array<int, array{blockName: string|null, attrs: array, innerBlocks: array, innerHTML: string, innerContent: array}>
	 */
	function parse_blocks( string $content ): array {
		return agend_test_parse_block_list( $content );
	}
}

if ( ! function_exists( 'agend_test_blocks_contain' ) ) {
	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                            $name   Block name to look for.
	 * @return bool
	 */
	function agend_test_blocks_contain( array $blocks, string $name ): bool {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? null ) === $name ) {
				return true;
			}

			if ( ! empty( $block['innerBlocks'] ) && agend_test_blocks_contain( $block['innerBlocks'], $name ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'has_block' ) ) {
	/**
	 * `has_block()` stand-in, matching WordPress's own algorithm rather than a
	 * more thorough one.
	 *
	 * Real `has_block()` (`wp-includes/blocks.php`) does NOT parse: it
	 * substring-searches the serialised content for `'<!-- wp:' . $name . ' '`,
	 * trailing space included, and prepends `core/` to a name given without a
	 * namespace. An earlier version of this stub recursively searched parsed
	 * blocks for an exact name match instead. That is stricter than production
	 * in one direction (a name that is a prefix of another block's name matches
	 * in WordPress but not in a parsed exact-match search) and more thorough in
	 * another, and a stub that answers differently from the function it stands
	 * in for turns a green suite into false confidence. Both serialised forms
	 * carry the space this searches for: `<!-- wp:name {"a":1} -->` and the
	 * attribute-less `<!-- wp:name /-->`.
	 *
	 * Nested blocks are still found, because WordPress serialises them as
	 * literal nested comments inside the same string. A block referenced only
	 * through a synced pattern is NOT found, by either implementation, which is
	 * the documented limitation in
	 * Agend_Apps_Block_Template_Source::page_contains_surface().
	 *
	 * @param string                  $block_name Block name to look for.
	 * @param int|WP_Post|string|null $post       Post id, post object, raw content, or null.
	 * @return bool
	 */
	function has_block( string $block_name, $post = null ): bool {
		if ( is_string( $post ) ) {
			$content = $post;
		} else {
			$post_obj = is_object( $post ) ? $post : get_post( $post );
			$content  = ( $post_obj instanceof WP_Post ) ? (string) $post_obj->post_content : '';
		}

		if ( false === strpos( $content, '<!-- wp:' ) ) {
			return false;
		}

		if ( false === strpos( $block_name, '/' ) ) {
			$block_name = 'core/' . $block_name;
		}

		if ( false !== strpos( $content, '<!-- wp:' . $block_name . ' ' ) ) {
			return true;
		}

		// WordPress also accepts a core block serialised without its namespace.
		$unprefixed = 0 === strpos( $block_name, 'core/' ) ? substr( $block_name, 5 ) : $block_name;

		return $unprefixed !== $block_name
			&& false !== strpos( $content, '<!-- wp:' . $unprefixed . ' ' );
	}
}

if ( ! function_exists( 'render_block' ) ) {
	/**
	 * Minimal `render_block()` stand-in: renders a single parsed block array
	 * (as returned by `parse_blocks()`), recursing into `innerBlocks` first
	 * and handing a dynamic block's registered render callback the
	 * concatenated inner markup. Matches WordPress's own
	 * `(attributes, content, block)` render callback signature closely
	 * enough for these tests: the `$block` instance argument is not
	 * reproduced, since nothing under test reads it.
	 *
	 * @param array $block Parsed block array.
	 * @return string
	 */
	function render_block( array $block ): string {
		$inner = '';

		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner_block ) {
			$inner .= render_block( $inner_block );
		}

		$name = $block['blockName'] ?? null;

		if ( ! is_string( $name ) || '' === $name ) {
			return (string) ( $block['innerHTML'] ?? '' ) . $inner;
		}

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $name );

		if ( $block_type instanceof WP_Block_Type && is_callable( $block_type->render_callback ) ) {
			return (string) call_user_func( $block_type->render_callback, (array) ( $block['attrs'] ?? array() ), $inner );
		}

		return (string) ( $block['innerHTML'] ?? '' ) . $inner;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/** Records the handle so a test can assert on exactly what was enqueued. */
	function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false, string $media = 'all' ): void {
		Agend_Test_WP::$enqueued_styles[] = $handle;
	}
}

if ( ! function_exists( 'wp_style_engine_get_stylesheet_from_context' ) ) {
	/**
	 * Test double for the style engine's block-supports capture.
	 * {@see Agend_Test_WP::$style_engine_stylesheet} is the string a test sets
	 * directly to simulate layout CSS having been generated during a render.
	 *
	 * @param string $context Style engine context, e.g. 'block-supports'.
	 * @param array  $options Ignored.
	 * @return string
	 */
	function wp_style_engine_get_stylesheet_from_context( string $context, array $options = array() ): string {
		return Agend_Test_WP::$style_engine_stylesheet;
	}
}
