<?php
/**
 * User sync.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pushes a WordPress user's identity and roles to Loop when they log in or
 * when their roles or profile change. Failures are logged and never block the
 * originating WordPress flow (e.g. login).
 */
class Agend_Loop_Sync_User_Sync {

	/**
	 * Per-request guard so a user is synced at most once per request, even when
	 * several triggering hooks (e.g. profile_update + set_user_role) fire in the
	 * same request.
	 *
	 * @var array<int, bool>
	 */
	private static $synced_this_request = array();

	/**
	 * Registers the WordPress hooks that trigger a user sync.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_login', array( $this, 'on_login' ), 20, 2 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 20, 1 );
		add_action( 'set_user_role', array( $this, 'on_set_role' ), 20, 1 );

		// Event-driven: re-sync a member when iugo-membership-kiosk reports their
		// data changed upstream (Upbeat), so identity / role changes made outside
		// WordPress propagate to Loop without waiting for a scheduled run.
		add_action( 'iugo_membership_kiosk_invalidate_member_caches_complete', array( $this, 'on_member_changed' ), 10, 2 );
	}

	/**
	 * Re-syncs a member when iugo-membership-kiosk invalidates their caches
	 * (an upstream create / update / delete). Resolves the membership number to
	 * a local WordPress user and pushes the current identity + roles.
	 *
	 * @param string|int $membership_number The Upbeat membership number.
	 * @param string     $event_name        The invalidation event name (unused).
	 * @return void
	 */
	public function on_member_changed( $membership_number, $event_name = '' ) {
		unset( $event_name );

		if ( ! Agend_Loop_Sync_Settings::is_enabled() || ! Agend_Loop_Sync_Settings::is_user_sync_enabled() ) {
			return;
		}

		$wp_id = Agend_Loop_Sync_Contact_Resolver::resolve_wp_id( (string) $membership_number );
		if ( null === $wp_id ) {
			return;
		}

		$user = get_userdata( $wp_id );
		if ( $user instanceof WP_User ) {
			$this->sync_user( $user );
		}
	}

	/**
	 * Syncs the user on login.
	 *
	 * @param string  $user_login The user login (unused).
	 * @param WP_User $user       The authenticated user.
	 * @return void
	 */
	public function on_login( $user_login, $user ) {
		unset( $user_login );
		if ( $user instanceof WP_User ) {
			$this->sync_user( $user );
		}
	}

	/**
	 * Syncs the user after a profile update.
	 *
	 * @param int $user_id The user id.
	 * @return void
	 */
	public function on_profile_update( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( $user instanceof WP_User ) {
			$this->sync_user( $user );
		}
	}

	/**
	 * Syncs the user after a role change.
	 *
	 * @param int $user_id The user id.
	 * @return void
	 */
	public function on_set_role( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( $user instanceof WP_User ) {
			$this->sync_user( $user );
		}
	}

	/**
	 * Builds the payload and syncs a single user to Loop.
	 *
	 * @param WP_User $user The user to sync.
	 * @return bool True on success or dry-run; false on failure or when disabled.
	 */
	public function sync_user( WP_User $user ): bool {
		if ( ! Agend_Loop_Sync_Settings::is_enabled() || ! Agend_Loop_Sync_Settings::is_user_sync_enabled() ) {
			return false;
		}

		if ( ! function_exists( 'agend_apps_loop_sync_user' ) ) {
			Agend_Loop_Sync_Logger::error( 'agend-apps-core is not available; cannot sync user', array( 'wp_id' => $user->ID ) );
			return false;
		}

		// external_id is the subject the SSO assertion's NameID carries: for this
		// site the Upbeat membership number, not the WordPress user id. A user
		// with no membership number cannot be matched to a Loop user, so skip
		// rather than send an invalid id.
		$membership_number = trim(
			(string) get_user_meta( $user->ID, 'imk_membership_number', true )
		);
		if ( '' === $membership_number ) {
			Agend_Loop_Sync_Logger::warning(
				'User has no membership number; skipping Loop user sync',
				array( 'wp_id' => (int) $user->ID )
			);
			return false;
		}

		// De-duplicate within a single request: profile_update and set_user_role
		// can both fire for the same user in one request, and the event-driven
		// hook may overlap with a WordPress hook. One gateway call per user is
		// enough.
		if ( isset( self::$synced_this_request[ (int) $user->ID ] ) ) {
			return true;
		}
		self::$synced_this_request[ (int) $user->ID ] = true;

		$payload = array(
			'idp_entity_id' => Agend_Loop_Sync_Settings::idp_entity_id(),
			'external_id'   => (int) $membership_number,
			'email'         => (string) $user->user_email,
			'display_name'  => (string) $user->display_name,
			'roles'         => array_values( (array) $user->roles ),
		);

		if ( Agend_Loop_Sync_Settings::is_dry_run() ) {
			Agend_Loop_Sync_Logger::info( 'Dry run: would sync user', $payload );
			return true;
		}

		$response = agend_apps_loop_sync_user( $payload );

		if ( is_wp_error( $response ) ) {
			Agend_Loop_Sync_Logger::error(
				'User sync failed',
				array(
					'wp_id' => (int) $user->ID,
					'error' => $response->get_error_message(),
				)
			);
			return false;
		}

		Agend_Loop_Sync_Logger::info( 'User synced', array( 'wp_id' => (int) $user->ID ) );

		return true;
	}

	/**
	 * Backfills every local member into Loop in one pass, using the bulk-sync
	 * endpoint in batches of 100. Unlike the per-login trigger this is intended
	 * for the initial rollout (so committee roster resolution has Loop users to
	 * match against). Honours dry-run.
	 *
	 * @return array<string, mixed> A summary of the backfill.
	 */
	public static function backfill_all(): array {
		$summary = array(
			'total'             => 0,
			'sent'              => 0,
			'succeeded'         => 0,
			'failed'            => 0,
			'skipped_no_number' => 0,
			'batches'           => 0,
			'dry_run'           => Agend_Loop_Sync_Settings::is_dry_run(),
			'errors'            => array(),
		);

		if ( ! Agend_Loop_Sync_Settings::is_enabled() || ! Agend_Loop_Sync_Settings::is_user_sync_enabled() ) {
			$summary['errors'][] = 'User sync is disabled in settings';
			return $summary;
		}

		if ( ! function_exists( 'agend_apps_loop_bulk_sync_users' ) ) {
			$summary['errors'][] = 'agend-apps-core is not available';
			return $summary;
		}

		$idp   = Agend_Loop_Sync_Settings::idp_entity_id();
		$users = get_users(
			array(
				'meta_key'     => 'imk_membership_number', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'orderby'      => 'ID',
				'order'        => 'ASC',
			)
		);
		$summary['total'] = count( $users );

		$items = array();
		foreach ( $users as $user ) {
			$number = trim( (string) get_user_meta( $user->ID, 'imk_membership_number', true ) );
			if ( '' === $number ) {
				++$summary['skipped_no_number'];
				continue;
			}
			$items[] = array(
				'external_id'  => (int) $number,
				'email'        => (string) $user->user_email,
				'display_name' => (string) $user->display_name,
				'roles'        => array_values( (array) $user->roles ),
			);
		}

		foreach ( array_chunk( $items, 100 ) as $chunk ) {
			++$summary['batches'];

			if ( Agend_Loop_Sync_Settings::is_dry_run() ) {
				Agend_Loop_Sync_Logger::info( 'Dry run: would bulk-sync users', array( 'count' => count( $chunk ) ) );
				$summary['sent'] += count( $chunk );
				continue;
			}

			$response = agend_apps_loop_bulk_sync_users( $chunk, $idp );

			if ( is_wp_error( $response ) ) {
				$summary['errors'][] = $response->get_error_message();
				Agend_Loop_Sync_Logger::error( 'User backfill batch failed', array( 'error' => $response->get_error_message() ) );
				continue;
			}

			$data                  = is_array( $response ) ? ( $response['data'] ?? $response ) : array();
			$summary['sent']      += count( $chunk );
			$summary['succeeded'] += (int) ( $data['succeeded'] ?? 0 );
			$summary['failed']    += (int) ( $data['failed'] ?? 0 );
		}

		Agend_Loop_Sync_Logger::info( 'User backfill complete', $summary );

		return $summary;
	}
}
