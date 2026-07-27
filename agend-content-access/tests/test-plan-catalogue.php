<?php
/**
 * Regression test: the plan catalogue is reduced, cached, and outage-safe.
 *
 * Three things must hold, and each has a way of going quietly wrong:
 *
 *   - Reduction is an allow list. If it were a blocklist, a new upstream field
 *     would reach the editor until somebody noticed.
 *   - A failed refresh must not clobber a known-good catalogue, or an Agend
 *     blip would empty every editor's plan picker.
 *   - With no catalogue at all, a NEW plan selection must be blocked rather
 *     than offered against an empty list an editor could read as "no plans".
 *
 * Runs standalone:
 *
 *     php agend-content-access/tests/test-plan-catalogue.php
 *
 * @package Agend_Content_Access
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

define( 'ABSPATH', __DIR__ );

$GLOBALS['test_transients'] = array();
$GLOBALS['test_tiers']      = array();

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['test_transients'] )
		? $GLOBALS['test_transients'][ $key ]
		: false;
}

function set_transient( $key, $value, $ttl ) {
	$GLOBALS['test_transients'][ $key ] = $value;
	return true;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}

class Agend_Apps_Settings {
	public static function get_cache_ttl( string $key ): int {
		return 300;
	}
}

function agend_apps_crm_get_tiers( array $query = array() ) {
	return $GLOBALS['test_tiers'];
}

require_once __DIR__ . '/../includes/class-agend-content-access-catalogue.php';

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

/** A realistic gateway payload, including everything the editor must NOT see. */
function tier_payload(): array {
	return array(
		'data' => array(
			array(
				'id'              => '11111111-1111-1111-1111-111111111111',
				'account_id'      => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
				'name'            => 'Full Member',
				'slug'            => 'full-member',
				'tier_type'       => 'individual',
				'is_active'       => true,
				'price_cents'     => 45000,
				'formatted_price' => '$450.00',
				'member_count'    => 317,
				'benefits'        => array( 'Journal', 'Events discount' ),
				'seat_brackets'   => array( array( 'min' => 1, 'max' => 5 ) ),
				'app_access'      => array( 'lms' => 'full' ),
			),
			array(
				'id'        => '22222222-2222-2222-2222-222222222222',
				'name'      => 'Retired',
				'slug'      => 'retired',
				'tier_type' => 'individual',
				'is_active' => false,
				'price_cents' => 5000,
			),
		),
	);
}

echo "plan catalogue\n";

// --- Reduction is an allow list. -------------------------------------------
$GLOBALS['test_transients'] = array();
$GLOBALS['test_tiers']      = tier_payload();

$result = Agend_Content_Access_Catalogue::get();
$plans  = $result['plans'];

check( 'two plans are returned', 2 === count( $plans ) );
check(
	'a plan carries exactly the five editor fields',
	array( 'id', 'name', 'slug', 'type', 'active' ) === array_keys( $plans[0] )
);
check( 'tier_type is mapped to type', 'individual' === $plans[0]['type'] );
check( 'is_active is mapped to active', true === $plans[0]['active'] );

$serialised = wp_json_encode_compat( $plans );
foreach ( array( '45000', 'formatted_price', 'member_count', 'benefits', 'seat_brackets', 'app_access', 'account_id', '317' ) as $forbidden ) {
	check( "reduction drops {$forbidden}", false === strpos( $serialised, $forbidden ) );
}

// --- Caching. ---------------------------------------------------------------
check(
	'the reduced catalogue is cached, not the raw payload',
	isset( $GLOBALS['test_transients'][ Agend_Content_Access_Catalogue::TRANSIENT ] )
	&& 2 === count( $GLOBALS['test_transients'][ Agend_Content_Access_Catalogue::TRANSIENT ] )
);

$GLOBALS['test_tiers'] = array( 'data' => array() );
$cached                = Agend_Content_Access_Catalogue::get();
check( 'a second call is served from the transient', 2 === count( $cached['plans'] ) );
check( 'a cache hit is not marked stale', false === $cached['stale'] );

$fresh = Agend_Content_Access_Catalogue::get( true );
check( 'force_refresh bypasses the transient', 0 === count( $fresh['plans'] ) );

// --- An outage must not clobber a known-good catalogue. ---------------------
$GLOBALS['test_transients'] = array(
	Agend_Content_Access_Catalogue::TRANSIENT => Agend_Content_Access_Catalogue::reduce( tier_payload() ),
);
$GLOBALS['test_tiers']      = new WP_Error( 'http_request_failed', 'Connection refused' );

$outage = Agend_Content_Access_Catalogue::get( true );
check( 'on failure the last known catalogue is served', 2 === count( $outage['plans'] ) );
check( 'on failure the result is marked stale', true === $outage['stale'] );
check( 'on failure the error is surfaced to the editor', 'Connection refused' === $outage['error'] );
check(
	'on failure the cached catalogue is left intact',
	2 === count( $GLOBALS['test_transients'][ Agend_Content_Access_Catalogue::TRANSIENT ] )
);
check( 'on failure plan selection is still permitted', true === Agend_Content_Access_Catalogue::can_select_plans( $outage ) );

// --- An outage with NO catalogue blocks new plan selection. -----------------
$GLOBALS['test_transients'] = array();
$empty                      = Agend_Content_Access_Catalogue::get( true );
check( 'with no catalogue at all, plans is empty', 0 === count( $empty['plans'] ) );
check(
	'with no catalogue at all, new plan selection is blocked',
	false === Agend_Content_Access_Catalogue::can_select_plans( $empty )
);

// --- Only active plans are offered for a NEW selection. ---------------------
$all = Agend_Content_Access_Catalogue::reduce( tier_payload() );
check( 'only the active plan is selectable', 1 === count( Agend_Content_Access_Catalogue::selectable( $all ) ) );
check(
	'the selectable plan is the active one',
	'11111111-1111-1111-1111-111111111111' === Agend_Content_Access_Catalogue::selectable( $all )[0]['id']
);

// --- A stored selection is annotated, never silently dropped. ---------------
$annotated = Agend_Content_Access_Catalogue::annotate_selection(
	array(
		'11111111-1111-1111-1111-111111111111', // active
		'22222222-2222-2222-2222-222222222222', // deactivated
		'99999999-9999-9999-9999-999999999999', // deleted upstream
	),
	$all
);

check( 'every stored tier id is retained', 3 === count( $annotated ) );
check( 'the active one is available', true === $annotated[0]['available'] );
check( 'a deactivated plan is retained but unavailable', false === $annotated[1]['available'] );
check( 'a deactivated plan keeps its name for the editor', 'Retired' === $annotated[1]['name'] );
check( 'a deleted plan is retained but unavailable', false === $annotated[2]['available'] );
check( 'a deleted plan keeps its id so it can be repaired', '99999999-9999-9999-9999-999999999999' === $annotated[2]['id'] );

// --- Malformed rows are skipped rather than producing empty plans. ----------
$junk = Agend_Content_Access_Catalogue::reduce(
	array( 'data' => array( array( 'name' => 'No id' ), 'not-an-array', array( 'id' => '' ) ) )
);
check( 'rows without a usable id are skipped', 0 === count( $junk ) );

/**
 * json_encode without depending on WordPress.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_compat( $value ): string {
	return (string) json_encode( $value );
}

echo $failures > 0 ? "\n{$failures} failure(s)\n" : "\nall passed\n";
exit( $failures > 0 ? 1 : 0 );
