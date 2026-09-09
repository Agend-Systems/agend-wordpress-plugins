<?php
/**
 * Membership snapshot sync: a PRESENTATION signal, never an authority.
 *
 * THIS SNAPSHOT MUST NEVER BE READ TO DECIDE ACCESS TO PROTECTED CONTENT
 * (SPEC-CMS-20260727 US-1.1).
 *
 * It mirrors the signed-in member's Agend membership standing into WordPress
 * usermeta so the interface can SAY something about a member without a gateway
 * call per render: greet them by plan, show a renewal prompt, badge a menu.
 * That is its entire remit.
 *
 * It is unfit to authorise, by construction and not by oversight:
 *
 *   - It is a cache. It is refreshed on credential login and on inbound
 *     `crm.membership.*` / `crm.seat.*` webhooks, so between those moments it
 *     is simply stale. A lapsed membership still reads `active` until
 *     something refreshes it.
 *   - It FAILS OPEN. A failed gateway read deliberately does not clobber the
 *     last known values, which is right for a display signal and catastrophic
 *     for an access decision: an outage would leave the last good standing in
 *     place indefinitely.
 *   - It keys on mutable tier SLUGS, which an administrator can rename at any
 *     time, silently breaking any rule written against them.
 *   - It is ordinary usermeta, writable by anything on the site with the
 *     capability to edit a user.
 *
 * The authority is the Agend entitlement engine, reached per request. Content
 * gating goes through Agend Content Access, whose typed policies resolve tier
 * UUIDs server-side and fail CLOSED. The usermeta display conditions that used
 * to read these keys were removed for exactly this reason; the `_display`
 * suffix on every key below is there so a future reader cannot mistake the
 * snapshot for an entitlement.
 *
 * The snapshot is refreshed on every credential login and by the incoming
 * webhook receiver when a `crm.membership.*` event arrives for a member
 * with an active session.
 *
 * Meta written (all underscore-prefixed, hidden from the profile UI):
 * - `_agend_apps_membership_status_display`     Aggregate standing: `active`,
 *                                        `pending`, the most recent
 *                                        membership's status, or `none`.
 * - `_agend_apps_membership_tier_slugs_display` Comma-separated tier slugs held with
 *                                        active/pending standing.
 * - `_agend_apps_membership_tier_names_display` Comma-separated tier display names
 *                                        for the same memberships.
 * - `_agend_apps_membership_expiry_display`     Latest expiry date (Y-m-d) among
 *                                        active/pending memberships, or ''.
 * - `_agend_apps_membership_synced_at_display`  ISO 8601 UTC timestamp of the sync.
 *
 * A failed gateway read never clobbers the last known snapshot: the meta is
 * only rewritten after a successful fetch.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronises the membership snapshot usermeta for a member.
 *
 * Reads the member's own memberships through the bearer-scoped gateway proxy
 * (`GET /v1/crm/me/memberships`) plus the tier catalogue, and writes the
 * aggregate snapshot to usermeta. The gateway client resolves the bearer from
 * the CURRENT WordPress user, so the call is made impersonating `$user_id`
 * and the previous user is restored afterwards — safe in the login and
 * webhook contexts this runs in (no rendering depends on the current user).
 *
 * @param int $user_id WordPress user id holding an Agend member session.
 * @return bool True when the snapshot was refreshed, false when skipped or failed.
 */
function agend_apps_member_sync_membership_meta( int $user_id ): bool {
	if ( $user_id <= 0 || ! class_exists( 'Agend_Apps_Member_Session' ) ) {
		return false;
	}

	if ( ! Agend_Apps_Member_Session::has_session( $user_id ) ) {
		return false;
	}

	$previous_user_id = get_current_user_id();

	if ( $previous_user_id !== $user_id ) {
		wp_set_current_user( $user_id );
	}

	$memberships = agend_apps_crm_get_my_memberships();
	$tiers       = is_wp_error( $memberships ) ? null : agend_apps_crm_get_tiers();

	if ( $previous_user_id !== $user_id ) {
		wp_set_current_user( $previous_user_id );
	}

	// Never clobber the last known snapshot on a failed read; the member
	// keeps their previous standing until the next successful sync.
	if ( is_wp_error( $memberships ) ) {
		return false;
	}

	$rows     = agend_apps_member_membership_rows( $memberships );
	$tier_map = agend_apps_member_tier_map( $tiers );
	$snapshot = agend_apps_member_build_membership_snapshot( $rows, $tier_map );

	update_user_meta( $user_id, '_agend_apps_membership_status_display', $snapshot['status'] );
	update_user_meta( $user_id, '_agend_apps_membership_tier_slugs_display', $snapshot['tier_slugs'] );
	update_user_meta( $user_id, '_agend_apps_membership_tier_names_display', $snapshot['tier_names'] );
	update_user_meta( $user_id, '_agend_apps_membership_expiry_display', $snapshot['expiry'] );
	update_user_meta( $user_id, '_agend_apps_membership_synced_at_display', gmdate( 'c' ) );

	/**
	 * Fires after a member's membership snapshot usermeta has been refreshed.
	 *
	 * @param int   $user_id  WordPress user id.
	 * @param array $snapshot Snapshot values written (status, tier_slugs, tier_names, expiry).
	 * @param array $rows     Raw membership rows from the gateway.
	 */
	do_action( 'agend_apps_membership_meta_synced', $user_id, $snapshot, $rows );

	return true;
}

/**
 * Extracts the membership rows from a decoded gateway response.
 *
 * Untyped for the same reason as `agend_apps_auth_response_session()`: the
 * argument comes straight from a gateway response, which is not guaranteed to
 * be an array, and a type declaration would raise a TypeError inside the
 * post-login snapshot refresh that every sign-in surface calls.
 *
 * @param mixed $response Decoded `GET /v1/crm/me/memberships` response.
 * @return array List of membership rows (possibly empty).
 */
function agend_apps_member_membership_rows( $response ): array {
	$response = is_array( $response ) ? $response : array();
	$data     = isset( $response['data'] ) && is_array( $response['data'] )
		? $response['data']
		: $response;

	// A list payload is a plain array of rows; anything else is unexpected.
	return array_values( array_filter( $data, 'is_array' ) );
}

/**
 * Builds a tier_id map to slug and name from the tiers response.
 *
 * @param array|WP_Error|null $tiers Decoded `GET /v1/crm/tiers` response, or an error.
 * @return array Map of tier id to array{slug: string, name: string}.
 */
function agend_apps_member_tier_map( $tiers ): array {
	if ( null === $tiers || is_wp_error( $tiers ) || ! is_array( $tiers ) ) {
		return array();
	}

	$data = isset( $tiers['data'] ) && is_array( $tiers['data'] ) ? $tiers['data'] : $tiers;
	$map  = array();

	foreach ( $data as $tier ) {
		if ( ! is_array( $tier ) || empty( $tier['id'] ) ) {
			continue;
		}

		$map[ (string) $tier['id'] ] = array(
			'slug' => isset( $tier['slug'] ) ? (string) $tier['slug'] : '',
			'name' => isset( $tier['name'] ) ? (string) $tier['name'] : '',
		);
	}

	return $map;
}

/**
 * Computes the snapshot values from membership rows and the tier map.
 *
 * Aggregate status precedence: any `active` membership wins, then `pending`,
 * then the most recently created membership's status, then `none`. Tier slugs
 * and names include only active/pending memberships (current standing), and
 * the expiry is the latest expiry date among them.
 *
 * @param array $rows     Membership rows (tier_id, status, expiry_date, created_at).
 * @param array $tier_map Tier id map from agend_apps_member_tier_map().
 * @return array{status: string, tier_slugs: string, tier_names: string, expiry: string}
 */
function agend_apps_member_build_membership_snapshot( array $rows, array $tier_map ): array {
	$statuses = array();
	$slugs    = array();
	$names    = array();
	$expiry   = '';
	$latest   = null;

	foreach ( $rows as $row ) {
		$status = isset( $row['status'] ) ? strtolower( (string) $row['status'] ) : '';

		if ( '' === $status ) {
			continue;
		}

		$statuses[] = $status;

		$created = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
		if ( null === $latest || $created > $latest['created_at'] ) {
			$latest = array(
				'created_at' => $created,
				'status'     => $status,
			);
		}

		if ( ! in_array( $status, array( 'active', 'pending' ), true ) ) {
			continue;
		}

		$tier_id = isset( $row['tier_id'] ) ? (string) $row['tier_id'] : '';

		if ( '' !== $tier_id ) {
			$tier    = isset( $tier_map[ $tier_id ] ) ? $tier_map[ $tier_id ] : array();
			$slugs[] = ! empty( $tier['slug'] ) ? (string) $tier['slug'] : $tier_id;

			if ( ! empty( $tier['name'] ) ) {
				$names[] = (string) $tier['name'];
			}
		}

		$row_expiry = isset( $row['expiry_date'] ) ? substr( (string) $row['expiry_date'], 0, 10 ) : '';

		if ( '' !== $row_expiry && $row_expiry > $expiry ) {
			$expiry = $row_expiry;
		}
	}

	if ( in_array( 'active', $statuses, true ) ) {
		$status = 'active';
	} elseif ( in_array( 'pending', $statuses, true ) ) {
		$status = 'pending';
	} elseif ( null !== $latest ) {
		$status = $latest['status'];
	} else {
		$status = 'none';
	}

	return array(
		'status'     => $status,
		'tier_slugs' => implode( ',', array_values( array_unique( $slugs ) ) ),
		'tier_names' => implode( ',', array_values( array_unique( $names ) ) ),
		'expiry'     => $expiry,
	);
}

/**
 * Removes the pre-`_display` snapshot meta, once.
 *
 * The rename in SPEC-CMS-20260727 US-1.1 orphans whatever was stored under the
 * old keys. Leaving those rows behind is the dangerous option rather than the
 * tidy one: nothing refreshes them any more, so any leftover rule or bespoke
 * theme code still reading `_agend_apps_membership_status` would read a value
 * frozen at the moment of upgrade, forever. On a lapsed member that value says
 * `active`. Deleting them turns a silent wrong answer into an obvious absent
 * one, which is the failure mode we can live with.
 */
function agend_apps_purge_legacy_membership_snapshot_meta(): void {
	if ( get_option( 'agend_apps_membership_snapshot_legacy_purged' ) ) {
		return;
	}

	global $wpdb;

	$legacy_keys = array(
		'_agend_apps_membership_status',
		'_agend_apps_membership_tier_slugs',
		'_agend_apps_membership_tier_names',
		'_agend_apps_membership_expiry',
		'_agend_apps_membership_synced_at',
	);

	foreach ( $legacy_keys as $key ) {
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $key ), array( '%s' ) );
	}

	update_option( 'agend_apps_membership_snapshot_legacy_purged', true, false );
}
add_action( 'admin_init', 'agend_apps_purge_legacy_membership_snapshot_meta' );
