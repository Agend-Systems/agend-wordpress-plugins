<?php
/**
 * Regression test: identity-attached reads must not touch the shared cache.
 *
 * Transients are shared across every visitor to a site. Several gateway
 * endpoints enrich their response when a member bearer is attached, and the
 * cache key does not include the member, so caching such a response serves one
 * member's data to the next visitor. `Agend_Apps_API::get_cached()` therefore
 * bypasses the transient store in BOTH directions whenever a bearer is present.
 *
 * The repository has no PHPUnit or WordPress test harness, so this runs
 * standalone against stubbed WordPress functions:
 *
 *     php agend-apps-core/tests/test-get-cached-identity-bypass.php
 *
 * Exits non-zero on failure. A proper harness is the follow-up; this exists so
 * the behaviour is pinned now rather than after one is built.
 *
 * @package Agend_Apps_Core
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

define( 'ABSPATH', __DIR__ );

/** In-memory transient store standing in for the shared WordPress one. */
$GLOBALS['test_transients'] = array();
/** Every outbound request the client attempted, in order. */
$GLOBALS['test_requests'] = array();
/** What `agend_apps_bearer_token` resolves to for the current case. */
$GLOBALS['test_bearer'] = '';

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['test_transients'] )
		? $GLOBALS['test_transients'][ $key ]
		: false;
}

function set_transient( $key, $value, $ttl ) {
	$GLOBALS['test_transients'][ $key ] = $value;
	return true;
}

function apply_filters( $hook, $value ) {
	if ( 'agend_apps_bearer_token' === $hook ) {
		return $GLOBALS['test_bearer'];
	}
	return $value;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function __( $text, $domain = null ) {
	return $text;
}

function wp_remote_request( $url, $args ) {
	$GLOBALS['test_requests'][] = array(
		'url'     => $url,
		'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
	);
	// A distinct body per call, so a cached response is distinguishable from a
	// fresh one.
	return array( 'body' => json_encode( array( 'call' => count( $GLOBALS['test_requests'] ) ) ) );
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function wp_remote_retrieve_response_code( $response ) {
	return 200;
}

function wp_remote_retrieve_header( $response, $header ) {
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

class WP_Error {
	public function __construct( $code = '', $message = '' ) {}
}

class Agend_Apps_Settings {
	public static function get_api_key() {
		return 'test-key';
	}
	public static function get_base_url() {
		return 'https://api.example.test/v1';
	}
	public static function get_vercel_bypass_token() {
		return '';
	}
}

require_once __DIR__ . '/../includes/class-agend-apps-api.php';

$failures = 0;

function check( string $name, bool $passed ): void {
	global $failures;
	if ( $passed ) {
		echo "  ok   {$name}\n";
		return;
	}
	echo "  FAIL {$name}\n";
	++$failures;
}

function reset_state( string $bearer = '' ): void {
	$GLOBALS['test_transients'] = array();
	$GLOBALS['test_requests']   = array();
	$GLOBALS['test_bearer']     = $bearer;
}

/**
 * Content cache keys only. The client keeps its own rate-limit transients,
 * which are not per-identity and are not what these assertions are about.
 */
function cached_content_keys(): array {
	return array_values(
		array_filter(
			array_keys( $GLOBALS['test_transients'] ),
			static function ( $key ) {
				return false === strpos( $key, 'rate_limit' );
			}
		)
	);
}

echo "get_cached identity bypass\n";

// --- Anonymous callers keep the existing caching behaviour. -----------------
reset_state( '' );
$api    = new Agend_Apps_API();
$first  = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );
$second = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

check( 'anonymous: first read hits the network', 1 === count( $GLOBALS['test_requests'] ) );
check( 'anonymous: second read is served from cache', 1 === count( $GLOBALS['test_requests'] ) );
check( 'anonymous: cached value is returned unchanged', $first === $second );
check( 'anonymous: response was written to the shared store', array( 'agend_apps_cms_x' ) === cached_content_keys() );

// --- A member-attached read must not read OR write the shared store. --------
reset_state( 'member-a-token' );
$api  = new Agend_Apps_API();
$one  = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );
$two  = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

check( 'member: every read hits the network', 2 === count( $GLOBALS['test_requests'] ) );
check( 'member: nothing is written to the shared store', array() === cached_content_keys() );
check( 'member: the bearer is attached to the request',
	isset( $GLOBALS['test_requests'][0]['headers']['Authorization'] )
	&& 'Bearer member-a-token' === $GLOBALS['test_requests'][0]['headers']['Authorization'] );

// --- Member A's response must never reach member B. -------------------------
reset_state( 'member-a-token' );
$api      = new Agend_Apps_API();
$response_a = $api->get_cached( '/events/gala', array(), 'events_gala', 60 );

$GLOBALS['test_bearer'] = 'member-b-token';
$response_b             = $api->get_cached( '/events/gala', array(), 'events_gala', 60 );

check( 'cross-member: B does not receive A\'s response', $response_a !== $response_b );
check( 'cross-member: B\'s request carried B\'s bearer',
	'Bearer member-b-token' === $GLOBALS['test_requests'][1]['headers']['Authorization'] );

// --- A member read must not poison the cache for a later anonymous visitor. -
reset_state( 'member-a-token' );
$api        = new Agend_Apps_API();
$member_res = $api->get_cached( '/events/gala', array(), 'events_gala', 60 );

$GLOBALS['test_bearer'] = '';
$anon_res               = $api->get_cached( '/events/gala', array(), 'events_gala', 60 );

check( 'anonymous after member: response is freshly fetched', $member_res !== $anon_res );
check( 'anonymous after member: only the anonymous response is cached',
	array( 'agend_apps_events_gala' ) === cached_content_keys() );

// --- An explicit bearer in $args counts as identity too. --------------------
reset_state( '' );
$api = new Agend_Apps_API();
$api->get_cached( '/cart', array( 'bearer_token' => 'explicit-token' ), 'cart', 60 );

check( 'explicit bearer_token arg also bypasses the shared store',
	array() === cached_content_keys() );

echo $failures > 0 ? "\n{$failures} failure(s)\n" : "\nall passed\n";
exit( $failures > 0 ? 1 : 0 );
