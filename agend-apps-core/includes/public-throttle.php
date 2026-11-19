<?php
/**
 * Throttle for routes that anyone on the internet can call.
 *
 * A route with `permission_callback => __return_true` that reaches the Agend
 * gateway spends the site's API key on behalf of a stranger, and every
 * visitor and every route shares that key's rate limit. This file holds the
 * counters such a route needs, so each route does not grow its own. It is the
 * one throttle every public route uses (the credential check, the Resources
 * search and Ask):
 *
 * - a per-visitor counter, keyed on a salted hash of the visitor's address,
 *   with one limit per window (for example 10 a minute and 60 an hour);
 * - a per-member counter, keyed on a salted hash of the WordPress user, for a
 *   route a signed-in member calls;
 * - a site-wide counter, which caps what one route may spend in total however
 *   many addresses the traffic comes from.
 *
 * Each feature keeps its own limits and its own filter names; this file only
 * counts.
 *
 * Counters are fixed windows kept in transients. A transient has no atomic
 * increment, so two requests in flight can read the same count and a burst may
 * overshoot a limit by the requests in flight. That is acceptable for a limit
 * that protects a shared budget, and it holds no personal data: the key is a
 * hash and the value is a count and a reset time.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The visitor's IP address, as a public route should count it.
 *
 * `REMOTE_ADDR` only. A forwarded-for header is attacker-controlled unless a
 * trusted proxy sets it, so a site behind one opts in through the
 * `agend_apps_visitor_ip` filter, which only site code can add.
 *
 * @param WP_REST_Request|null $request Current request, passed on to the filter.
 * @return string A valid IP address, or '' when none can be established.
 */
function agend_apps_visitor_ip( $request = null ): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	/**
	 * Filters the visitor IP used for rate limiting.
	 *
	 * Return the real client address on a site behind a trusted proxy.
	 *
	 * @param string               $ip      `REMOTE_ADDR`, or ''.
	 * @param WP_REST_Request|null $request Current request, when there is one.
	 */
	$ip = (string) apply_filters( 'agend_apps_visitor_ip', $ip, $request );

	return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * The network a visitor's requests are counted against.
 *
 * An IPv4 address is its own bucket. An IPv6 address is counted against its
 * /64: one connection is usually given a whole /64, so counting each address
 * apart would let a single host take a fresh address for every request and
 * never reach the limit. An IPv4-mapped address (`::ffff:203.0.113.7`, as a
 * dual-stack socket reports an IPv4 client) is counted against the IPv4
 * address it carries, because as IPv6 every such visitor would share one
 * bucket.
 *
 * Matches the bucket the geocode route uses in open PR #164, so the two can
 * share this function once both are merged.
 *
 * @param string $ip A valid IP address, or ''.
 * @return string The bucket, or 'unknown' when there is no address. Every
 *                visitor with no usable address shares the 'unknown' bucket
 *                and its limits, so a missing address fails closed.
 */
function agend_apps_visitor_bucket( string $ip ): string {
	if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return 'unknown';
	}

	if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		return $ip;
	}

	$packed = inet_pton( $ip );

	if ( false === $packed ) {
		return 'unknown';
	}

	if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
		$ipv4 = inet_ntop( substr( $packed, 12 ) );

		if ( false !== $ipv4 ) {
			return $ipv4;
		}
	}

	$network = inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) );

	return false !== $network ? $network . '/64' : 'unknown';
}

/**
 * The transient key for one counter.
 *
 * The part (a visitor bucket, or '' for a site counter) is hashed with the
 * site's own salt together with the scope and window, so the stored key cannot
 * be turned back into an address by trying every IPv4 address, and two scopes
 * never share a counter.
 *
 * @param string $scope  Route family, for example 'credential-check'.
 * @param string $part   Visitor bucket, or '' for a site-wide counter.
 * @param int    $window Window length in seconds.
 * @return string
 */
function agend_apps_public_throttle_key( string $scope, string $part, int $window ): string {
	return 'agend_apps_pt_' . substr( hash_hmac( 'sha256', $scope . '|' . $window . '|' . $part, wp_salt() ), 0, 32 );
}

/**
 * Counts one hit against every counter, but only when none is full.
 *
 * Every counter is read first, so a request refused by the hour limit does not
 * also spend a place in the minute window. A window starts at the first hit
 * and is not extended by later ones, so a visitor who checks now and then is
 * never refused and one who used up the window waits for it to close.
 *
 * @param array<int, array{key: string, limit: int, window: int}> $counters Counters to read and advance together.
 * @param int                                                     $now      Unix time.
 * @return int 0 when the hit was counted and may go ahead, otherwise the
 *             seconds until the fullest counter's window closes.
 */
function agend_apps_public_throttle_take( array $counters, int $now ): int {
	$entries = array();
	$wait    = 0;

	foreach ( $counters as $index => $counter ) {
		$entry = get_transient( $counter['key'] );

		if ( ! is_array( $entry ) || ! isset( $entry['count'], $entry['reset'] ) || (int) $entry['reset'] <= $now ) {
			$entry = array(
				'count' => 0,
				'reset' => $now + $counter['window'],
			);
		}

		$entries[ $index ] = array(
			'count' => (int) $entry['count'],
			'reset' => (int) $entry['reset'],
		);

		if ( $entries[ $index ]['count'] >= $counter['limit'] ) {
			$wait = max( $wait, max( 1, $entries[ $index ]['reset'] - $now ) );
		}
	}

	if ( $wait > 0 ) {
		return $wait;
	}

	foreach ( $counters as $index => $counter ) {
		$entry = $entries[ $index ];
		++$entry['count'];
		set_transient( $counter['key'], $entry, max( 1, $entry['reset'] - $now ) );
	}

	return 0;
}

/**
 * Turns `window seconds => limit` pairs into counters, dropping nonsense.
 *
 * A limit below 1 or a window below 1 second is ignored rather than read as
 * "no limit": a filter that returns garbage must not switch a throttle off.
 *
 * @param string          $scope  Route family.
 * @param string          $part   Visitor bucket, or '' for a site counter.
 * @param array<int, int> $limits Window length in seconds => hits allowed.
 * @return array<int, array{key: string, limit: int, window: int}>
 */
function agend_apps_public_throttle_counters( string $scope, string $part, array $limits ): array {
	$counters = array();

	foreach ( $limits as $window => $limit ) {
		$window = (int) $window;
		$limit  = (int) $limit;

		if ( $window < 1 || $limit < 1 ) {
			continue;
		}

		$counters[] = array(
			'key'    => agend_apps_public_throttle_key( $scope, $part, $window ),
			'limit'  => $limit,
			'window' => $window,
		);
	}

	return $counters;
}

/**
 * Counts one hit against a visitor, and says whether it may go ahead.
 *
 * @param string          $scope  Route family, for example 'credential-check'.
 * @param string          $ip     Visitor address from {@see agend_apps_visitor_ip()}, or ''.
 * @param array<int, int> $limits Window length in seconds => hits allowed per visitor.
 * @param int             $now    Unix time.
 * @return int 0 when the visitor may go ahead, otherwise the seconds to wait.
 */
function agend_apps_public_throttle_visitor( string $scope, string $ip, array $limits, int $now ): int {
	return agend_apps_public_throttle_take(
		agend_apps_public_throttle_counters( $scope, agend_apps_visitor_bucket( $ip ), $limits ),
		$now
	);
}

/**
 * Counts one hit against a signed-in WordPress user, and says whether it may
 * go ahead.
 *
 * For a route a member calls with their own session, where counting by address
 * would hold a whole office behind one NAT to one person's limit. The counter
 * is keyed on the user id, hashed like every other part, so the stored key does
 * not name the user. A visitor bucket is always an address or 'unknown' and so
 * can never be the same counter as a user.
 *
 * @param string          $scope   Route family.
 * @param int             $user_id WordPress user id.
 * @param array<int, int> $limits  Window length in seconds => hits allowed per member.
 * @param int             $now     Unix time.
 * @return int 0 when the member may go ahead, otherwise the seconds to wait.
 */
function agend_apps_public_throttle_member( string $scope, int $user_id, array $limits, int $now ): int {
	return agend_apps_public_throttle_take(
		agend_apps_public_throttle_counters( $scope, 'user:' . $user_id, $limits ),
		$now
	);
}

/**
 * Counts one hit against the whole site, and says whether it may go ahead.
 *
 * The ceiling that keeps a distributed script, which no per-visitor limit
 * sees, from spending the site key's budget: over it every visitor waits.
 *
 * @param string          $scope  Route family.
 * @param array<int, int> $limits Window length in seconds => hits allowed in total.
 * @param int             $now    Unix time.
 * @return int 0 when the call may go ahead, otherwise the seconds to wait.
 */
function agend_apps_public_throttle_site( string $scope, array $limits, int $now ): int {
	return agend_apps_public_throttle_take(
		agend_apps_public_throttle_counters( $scope, '', $limits ),
		$now
	);
}
