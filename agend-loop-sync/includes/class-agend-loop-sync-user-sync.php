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
	 * Registers the WordPress hooks that trigger a user sync.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_login', array( $this, 'on_login' ), 20, 2 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 20, 1 );
		add_action( 'set_user_role', array( $this, 'on_set_role' ), 20, 1 );
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
}
