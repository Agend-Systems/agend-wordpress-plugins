<?php
/**
 * Committee-role to channel-role mapper.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a free-text Upbeat committee role string (e.g. "Chair", "Secretary")
 * to a Loop channel role (owner, moderator, member) using the admin-configured
 * map. Unmapped roles fall back to 'member' and are logged.
 */
class Agend_Loop_Sync_Role_Mapper {

	/**
	 * Orders channel roles so the most privileged one can win a merge. Also ranks
	 * 'admin', a role Loop can hold but this plugin never maps to, so a member's
	 * current Loop role can be compared with the one the roster gives them.
	 *
	 * @param string $channel_role One of 'owner', 'admin', 'moderator', 'member'.
	 * @return int Higher means more privileged; unknown roles rank 0.
	 */
	public static function rank( string $channel_role ): int {
		$ranks = array(
			'owner'     => 4,
			'admin'     => 3,
			'moderator' => 2,
			'member'    => 1,
		);

		return $ranks[ $channel_role ] ?? 0;
	}

	/**
	 * Resolves a committee role string to a Loop channel role.
	 *
	 * Matching is exact first, then case-insensitive. An unmapped role
	 * defaults to 'member' and records a warning.
	 *
	 * @param string $committee_role The committee role string from Upbeat.
	 * @return string One of 'owner', 'moderator', 'member'.
	 */
	public static function to_channel_role( string $committee_role ): string {
		$needle = trim( $committee_role );
		$map    = Agend_Loop_Sync_Settings::get_role_map();

		if ( '' !== $needle && isset( $map[ $needle ] ) ) {
			return $map[ $needle ];
		}

		foreach ( $map as $label => $role ) {
			if ( 0 === strcasecmp( (string) $label, $needle ) ) {
				return $role;
			}
		}

		Agend_Loop_Sync_Logger::warning(
			'Unmapped committee role; defaulting to member',
			array( 'role' => $committee_role )
		);

		return 'member';
	}
}
