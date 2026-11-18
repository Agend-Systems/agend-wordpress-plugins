<?php
/**
 * Per-visitor and site-wide limits for the Resources proxies.
 *
 * The Resources search and Ask routes call the gateway on behalf of whoever
 * asks. The gateway gives the whole site one request bucket (60 a minute per
 * API key, shared by every widget), so an unchecked route lets one visitor
 * take the bucket from the jobs board and the events list. The limits here sit
 * in front of the gateway call and refuse before it is made.
 *
 * The per-address policy is the one the directory geocode route uses
 * (`agend_apps_directory_geocode_throttle()`): `REMOTE_ADDR` only, an IPv6
 * address counted by its /64, an IPv4-mapped address counted as IPv4, the
 * transient key a salted hash of that bucket and never the address, and a fixed
 * window that starts at the first request. This file is its own copy under its
 * own names so it can be reconciled with that helper, and with any other
 * limiter built for the same purpose, in one place.
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
 * The address a visitor is counted against.
 *
 * An IPv4 address is its own bucket. An IPv6 address is counted against its
 * /64 network: one connection is usually given a whole /64, so counting each
 * address apart would let one host pick a fresh address for every request. An
 * IPv4-mapped address (`::ffff:203.0.113.7`, as a dual-stack socket reports an
 * IPv4 client) is counted as the IPv4 address it carries; as IPv6 its first 64
 * bits are all zero and every IPv4 visitor would share one bucket.
 *
 * @param string $ip The visitor's `REMOTE_ADDR`.
 * @return string
 */
function agend_apps_resources_throttle_bucket( string $ip ): string {
	if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		$packed = inet_pton( $ip );

		if ( false !== $packed && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			$ipv4 = inet_ntop( substr( $packed, 12 ) );

			if ( false !== $ipv4 ) {
				return $ipv4;
			}
		}

		if ( false !== $packed ) {
			$network = inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) );

			if ( false !== $network ) {
				return $network . '/64';
			}
		}
	}

	return $ip;
}

/**
 * Counts one hit against a subject in a scope, and says whether it may go
 * ahead.
 *
 * The window is fixed from the subject's first hit, so one that asks now and
 * then is never refused, and one that used its hits up waits for the window to
 * close rather than for a quiet spell. The transient key holds a salted hash of
 * the subject, so the stored key cannot be turned back into an address or a
 * user id by trying them all.
 *
 * @param string $scope   What is being limited, e.g. `search`. A letters, digits and underscores name.
 * @param string $subject Who is being counted: an address bucket, or a user id.
 * @param int    $max     Hits allowed in the window.
 * @param int    $window  The window, in seconds.
 * @param int    $now     Unix time.
 * @return int 0 when the hit may go ahead, otherwise the seconds until it may.
 */
function agend_apps_resources_throttle_hit( string $scope, string $subject, int $max, int $window, int $now ): int {
	$key   = 'agend_apps_rthrottle_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( $scope ) ) . '_' . wp_hash( $subject );
	$entry = get_transient( $key );

	if ( ! is_array( $entry ) || ! isset( $entry['count'], $entry['reset'] ) || (int) $entry['reset'] <= $now ) {
		$entry = array(
			'count' => 0,
			'reset' => $now + $window,
		);
	}

	$remaining = max( 1, (int) $entry['reset'] - $now );

	if ( (int) $entry['count'] >= $max ) {
		return $remaining;
	}

	$entry['count'] = (int) $entry['count'] + 1;
	set_transient( $key, $entry, $remaining );

	return 0;
}

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
 * Keyed on `REMOTE_ADDR` alone, deliberately and not through any forwarded
 * header: a limit keyed on a header the visitor can set is escaped by varying
 * it, wherever no trusted proxy sets it.
 *
 * @param string   $scope  The scope, e.g. `visitor_search`.
 * @param int      $max    Default hits per window.
 * @param int      $window Default window, in seconds.
 * @param int|null $now    Unix time. Defaults to now.
 * @return int 0 when the request may go ahead, otherwise the seconds until it may.
 */
function agend_apps_resources_visitor_throttle( string $scope, int $max, int $window, ?int $now = null ): int {
	list( $max, $window ) = agend_apps_resources_throttle_limit( $scope, $max, $window );

	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return agend_apps_resources_throttle_hit( $scope, agend_apps_resources_throttle_bucket( $ip ), $max, $window, $now ?? time() );
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

	return agend_apps_resources_throttle_hit( $scope, 'user:' . $user_id, $max, $window, $now ?? time() );
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

	return agend_apps_resources_throttle_hit( $scope, 'site', $max, $window, $now ?? time() );
}
