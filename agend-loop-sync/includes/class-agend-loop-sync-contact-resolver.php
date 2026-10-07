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
	 * Converts a membership number to the gateway's `external_id`.
	 *
	 * The gateway requires a positive integer. A casual `(int)` cast turns a
	 * non-numeric number ("ABC123") into 0, which the gateway rejects and which
	 * fails a whole committee roster, so anything that is not plain digits is
	 * refused here and the caller skips that member.
	 *
	 * @param string $membership_number Upbeat membership number.
	 * @return int|null A positive integer, or null when the number is unusable.
	 */
	public static function to_external_id( string $membership_number ): ?int {
		$membership_number = trim( $membership_number );

		// 18 digits is the longest run that always fits a 64-bit PHP int.
		if ( '' === $membership_number || strlen( $membership_number ) > 18 || ! ctype_digit( $membership_number ) ) {
			return null;
		}

		$id = (int) $membership_number;

		return $id > 0 ? $id : null;
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
