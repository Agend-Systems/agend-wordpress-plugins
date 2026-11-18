<?php
/**
 * Per-visitor, per-member and site-wide limits for the Resources proxies.
 *
 * The Resources search and Ask routes call the gateway on behalf of whoever
 * asks. The gateway gives the whole site one request bucket (60 a minute per
 * API key, shared by every widget), so an unchecked route lets one visitor
 * take the bucket from the jobs board and the events list. The limits here sit
 * in front of the gateway call and refuse before it is made.
 *
 * The counting is not done here. It is the shared public throttle in
 * `public-throttle.php`, the same one the credential check uses: `REMOTE_ADDR`
 * only (a site behind a trusted proxy opts in through the `agend_apps_visitor_ip`
 * filter), an IPv6 address counted by its /64, an IPv4-mapped address counted as
 * IPv4, a transient key that is a salted hash and never an address or a user
 * id, and a fixed window that starts at the first request. This file holds what
 * is Resources's own: the default numbers, the `agend_apps_resources_throttle_limit`
 * filter, and the three subjects it counts (a visitor, a member, the site).
 *
 * Two concurrent requests can read the same count, so a burst can overshoot a
 * limit by the requests in flight: transients have no atomic increment. The
 * site-wide cap bounds the damage.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/public-throttle.php';

/** Searches one visitor address may make through the Resources route per window. */
const AGEND_APPS_RESOURCES_VISITOR_SEARCH_LIMIT = 10;

/** That window, in seconds. */
const AGEND_APPS_RESOURCES_VISITOR_SEARCH_WINDOW = 60;

/** Searches one signed-in member may make per window. */
const AGEND_APPS_RESOURCES_MEMBER_SEARCH_LIMIT = 30;

/** That window, in seconds. */
const AGEND_APPS_RESOURCES_MEMBER_SEARCH_WINDOW = 60;

/** Questions one member may ask per window. */
const AGEND_APPS_RESOURCES_ASK_MEMBER_LIMIT = 3;

/** That window, in seconds. */
const AGEND_APPS_RESOURCES_ASK_MEMBER_WINDOW = 600;

/** Questions the whole site may ask per day. */
const AGEND_APPS_RESOURCES_ASK_SITE_LIMIT = 30;

/** That window, in seconds. */
const AGEND_APPS_RESOURCES_ASK_SITE_WINDOW = 86400;

/**
 * Gateway calls the Resources proxies may make between them per window, over
 * every visitor. It keeps most of the key's 60 a minute for the pages.
 */
const AGEND_APPS_RESOURCES_SITE_CALL_LIMIT = 20;

/** That window, in seconds. */
const AGEND_APPS_RESOURCES_SITE_CALL_WINDOW = 60;

/**
 * The limit for a scope, which a site can change.
 *
 * @param string $scope   The scope, e.g. `visitor_search`.
 * @param int    $max     Default hits per window.
 * @param int    $window  Default window, in seconds.
 * @return array{0: int, 1: int} Hits allowed, and the window.
 */
function agend_apps_resources_throttle_limit( string $scope, int $max, int $window ): array {
	/**
	 * Filters a Resources limit.
	 *
	 * @param array  $limit `max` hits per `window` seconds.
	 * @param string $scope `visitor_search`, `member_search`, `ask_member`, `ask_site` or `site_calls`.
	 */
	$limit = (array) apply_filters(
		'agend_apps_resources_throttle_limit',
		array(
			'max'    => $max,
			'window' => $window,
		),
		$scope
	);

	return array(
		max( 1, (int) ( $limit['max'] ?? $max ) ),
		max( 1, (int) ( $limit['window'] ?? $window ) ),
	);
}

/**
 * Counts a visitor's request against their address.
 *
 * Keyed on the address {@see agend_apps_visitor_ip()} gives, which is
 * `REMOTE_ADDR` and never a forwarded header unless site code opts in: a limit
 * keyed on a header the visitor can set is escaped by varying it.
 *
 * @param string   $scope  The scope, e.g. `visitor_search`.
 * @param int      $max    Default hits per window.
 * @param int      $window Default window, in seconds.
 * @param int|null $now    Unix time. Defaults to now.
 * @return int 0 when the request may go ahead, otherwise the seconds until it may.
 */
function agend_apps_resources_visitor_throttle( string $scope, int $max, int $window, ?int $now = null ): int {
	list( $max, $window ) = agend_apps_resources_throttle_limit( $scope, $max, $window );

	return agend_apps_public_throttle_visitor(
		agend_apps_resources_throttle_scope( $scope ),
		agend_apps_visitor_ip(),
		array( $window => $max ),
		$now ?? time()
	);
}

/**
 * Counts a signed-in member's request against their WordPress user.
 *
 * @param string   $scope   The scope, e.g. `ask_member`.
 * @param int      $user_id The WordPress user id.
 * @param int      $max     Default hits per window.
 * @param int      $window  Default window, in seconds.
 * @param int|null $now     Unix time. Defaults to now.
 * @return int 0 when the request may go ahead, otherwise the seconds until it may.
 */
function agend_apps_resources_member_throttle( string $scope, int $user_id, int $max, int $window, ?int $now = null ): int {
	list( $max, $window ) = agend_apps_resources_throttle_limit( $scope, $max, $window );

	return agend_apps_public_throttle_member(
		agend_apps_resources_throttle_scope( $scope ),
		$user_id,
		array( $window => $max ),
		$now ?? time()
	);
}

/**
 * Counts a request against the site as a whole, whoever made it.
 *
 * @param string   $scope   The scope, e.g. `site_calls` or `ask_site`.
 * @param int      $max     Default hits per window.
 * @param int      $window  Default window, in seconds.
 * @param int|null $now     Unix time. Defaults to now.
 * @return int 0 when the request may go ahead, otherwise the seconds until it may.
 */
function agend_apps_resources_site_throttle( string $scope, int $max, int $window, ?int $now = null ): int {
	list( $max, $window ) = agend_apps_resources_throttle_limit( $scope, $max, $window );

	return agend_apps_public_throttle_site(
		agend_apps_resources_throttle_scope( $scope ),
		array( $window => $max ),
		$now ?? time()
	);
}

/**
 * The shared throttle's scope for one of Resources's scopes. Prefixed so a
 * Resources counter can never be the same counter as another feature's, whatever
 * either calls its scopes.
 *
 * @param string $scope The Resources scope, e.g. `visitor_search`.
 * @return string
 */
function agend_apps_resources_throttle_scope( string $scope ): string {
	return 'resources:' . $scope;
}
