<?php
/**
 * Regression test: the plugin registers nothing until Core is present.
 *
 * An access-control plugin that half-loads is worse than one that does not load
 * at all: from the visitor's side, "no restriction registered" and "restriction
 * registered and satisfied" look identical. So the bootstrap must be all or
 * nothing, and must say so loudly in the admin.
 *
 * Runs standalone against stubbed WordPress functions, because the repository
 * has no PHPUnit harness:
 *
 *     php agend-content-access/tests/test-bootstrap-dependency-gate.php
 *
 * Exits non-zero on failure.
 *
 * @package Agend_Content_Access
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

define( 'ABSPATH', __DIR__ );

$GLOBALS['test_actions']    = array();
$GLOBALS['test_did_action'] = array();
$GLOBALS['test_notices']    = array();

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['test_actions'][ $hook ][] = $callback;
	return true;
}

function do_action( $hook, ...$args ) {
	$GLOBALS['test_did_action'][ $hook ] = ( $GLOBALS['test_did_action'][ $hook ] ?? 0 ) + 1;
	foreach ( $GLOBALS['test_actions'][ $hook ] ?? array() as $callback ) {
		call_user_func_array( $callback, $args );
	}
}

function did_action( $hook ) {
	return $GLOBALS['test_did_action'][ $hook ] ?? 0;
}

function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/agend-content-access/';
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function __( $text, $domain = null ) {
	return $text;
}

require_once __DIR__ . '/../agend-content-access.php';

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

function capture_notices(): string {
	ob_start();
	do_action( 'admin_notices' );
	return (string) ob_get_clean();
}

function reset_hooks(): void {
	$GLOBALS['test_actions']    = array();
	$GLOBALS['test_did_action'] = array();
}

echo "bootstrap dependency gate\n";

// --- Core absent: nothing registers, and the admin is told why. ------------
reset_hooks();
agend_content_access_bootstrap();

check(
	'without Core: the loaded action never fires',
	0 === did_action( 'agend_content_access_loaded' )
);
check(
	'without Core: an admin notice is registered',
	isset( $GLOBALS['test_actions']['admin_notices'] )
);

$notice = capture_notices();
check( 'without Core: the notice names the missing plugin', str_contains( $notice, 'Agend Apps Core' ) );
check(
	'without Core: the notice states that no restrictions are applied',
	str_contains( $notice, 'no content restrictions are being applied' )
);
check( 'without Core: the notice is an error', str_contains( $notice, 'notice-error' ) );

// --- Core partially present: still treated as missing. ---------------------
// A Core build that had renamed or dropped one of the consumed functions must
// fail the gate. Probing the actual call sites is what makes that possible; a
// version-string check would have passed here.
reset_hooks();
// Declared inside a conditional so PHP does not hoist it: the cases above must
// run with Core genuinely absent.
if ( ! function_exists( 'agend_apps_api' ) ) {
	function agend_apps_api() {
		return null;
	}
}
agend_content_access_bootstrap();

check(
	'partial Core: a missing consumed function still fails the gate',
	0 === did_action( 'agend_content_access_loaded' )
);
check(
	'partial Core: the admin is still notified',
	isset( $GLOBALS['test_actions']['admin_notices'] )
);

// --- Core present, Elementor absent: loads cleanly and silently. -----------
reset_hooks();
if ( ! function_exists( 'agend_apps_get_bearer_token' ) ) {
	function agend_apps_get_bearer_token() {
		return '';
	}
}
if ( ! function_exists( 'agend_apps_crm_get_tiers' ) ) {
	function agend_apps_crm_get_tiers( array $query = array() ) {
		return array();
	}
}
agend_content_access_bootstrap();

check(
	'with Core: the loaded action fires exactly once',
	1 === did_action( 'agend_content_access_loaded' )
);
check(
	'with Core but no Elementor: no admin notice is registered',
	! isset( $GLOBALS['test_actions']['admin_notices'] )
);
check(
	'with Core but no Elementor: Elementor integration stays off',
	false === agend_content_access_has_elementor()
);

// --- Elementor present: the integration guard opens. -----------------------
$GLOBALS['test_did_action']['elementor/loaded'] = 1;
check(
	'once Elementor has loaded: the integration guard opens',
	true === agend_content_access_has_elementor()
);

echo $failures > 0 ? "\n{$failures} failure(s)\n" : "\nall passed\n";
exit( $failures > 0 ? 1 : 0 );
