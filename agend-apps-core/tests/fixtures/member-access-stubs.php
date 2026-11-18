<?php
/**
 * Test fixture for the member access state: the one gateway read `sso` mode may
 * make, and a counter for the bearer resolver.
 *
 * - `agend_apps_sso_get_link_status()` is answered from
 *   `$GLOBALS['agend_ma_test']['sso_status']`, and counted, so a test can say it
 *   was called once, or not at all.
 * - `agend_ma_test_bearer()` makes the API client's own resolver
 *   (`agend_apps_get_bearer_token()`, a filter) return a token, counting how
 *   many times it is asked.
 *
 * Declared in a plain file because a function cannot be declared inside a test
 * method. The tests that require it run in separate processes.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

/**
 * Clears the canned answer and the counters.
 */
function agend_ma_test_reset(): void {
	$GLOBALS['agend_ma_test'] = array(
		'bearer_calls' => 0,
		'sso_calls'    => 0,
		'sso_status'   => array( 'success' => true, 'data' => array( 'linked' => false ) ),
	);
}

agend_ma_test_reset();

if ( ! function_exists( 'agend_apps_sso_get_link_status' ) ) {
	/**
	 * @param string $idp_entity_id Issuer.
	 * @param string $external_id   The member's external id.
	 * @return array|WP_Error
	 */
	function agend_apps_sso_get_link_status( string $idp_entity_id, string $external_id ) {
		++$GLOBALS['agend_ma_test']['sso_calls'];

		return $GLOBALS['agend_ma_test']['sso_status'];
	}
}

/**
 * Makes the bearer resolver answer with a token (or '' for none), counting each
 * time the resolver runs.
 *
 * @param string $token The token to resolve; '' for no bearer.
 */
function agend_ma_test_bearer( string $token ): void {
	Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function () use ( $token ): string {
		++$GLOBALS['agend_ma_test']['bearer_calls'];

		return $token;
	};
}
