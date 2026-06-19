<?php
/**
 * Contact resolver.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves an Upbeat membership number (the `contact` field on a committee
 * activity) to a WordPress user id via the `imk_membership_number` user meta
 * written by iugo-membership-kiosk. Results are memoised per request.
 */
class Agend_Loop_Sync_Contact_Resolver {

	/**
	 * Per-request resolution cache, keyed by membership number.
	 *
	 * @var array<string, int|null>
	 */
	private static $cache = array();

	/**
	 * Resolves a membership number to a WordPress user id.
	 *
	 * @param string $membership_number Upbeat membership number.
	 * @return int|null The WordPress user id, or null when no user matches.
	 */
	public static function resolve_wp_id( string $membership_number ): ?int {
		$membership_number = trim( $membership_number );

		if ( '' === $membership_number ) {
			return null;
		}

		if ( array_key_exists( $membership_number, self::$cache ) ) {
			return self::$cache[ $membership_number ];
		}

		$users = get_users(
			array(
				'meta_key'   => 'imk_membership_number', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $membership_number,       // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		$wp_id = ! empty( $users ) ? (int) $users[0] : null;

		self::$cache[ $membership_number ] = $wp_id;

		return $wp_id;
	}

	/**
	 * Clears the per-request cache. Primarily for tests and long-running CLI.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = array();
	}
}
