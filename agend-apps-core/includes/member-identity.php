<?php
/**
 * WordPress identity establishment and role sync for member credential login.
 *
 * Resolves the WordPress user a credential login is stored against
 * (SPEC-CORE-20260722-wordpress-member-login US-1.4) and keeps that user's
 * WordPress role in step with their Agend dashboard role (US-1.7): a dashboard
 * admin (owner or a role with management permission, reported as
 * `is_account_admin` by the gateway) maps to the WordPress administrator role.
 *
 * Members whose first interaction is through the WordPress/API surface are
 * onboarded without the association manually creating WordPress accounts: a
 * successful Agend login finds-or-creates a member-role WordPress user and
 * signs them in. Role management is confined to accounts this integration
 * created (the `_agend_apps_managed` flag), so a pre-existing independent
 * WordPress account that happens to share an email is never adopted, demoted,
 * or promoted by a login.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User-meta flag marking a WordPress user as created and managed by this
 * integration. Only managed users have their role synced from Agend.
 *
 * @var string
 */
const AGEND_APPS_MANAGED_META = '_agend_apps_managed';

/**
 * Establishes the WordPress user id for a credential login and syncs its role.
 *
 * @param int             $user_id Current WordPress user id (0 when the visitor is not signed in).
 * @param string          $email   Authenticated member email (already validated by the gateway).
 * @param array           $data    Decoded login data (`user`, `contact`, `session`, `is_account_admin`).
 * @param WP_REST_Request $request Current request.
 * @return int The resolved WordPress user id, or 0 when no identity could be established.
 */
function agend_apps_member_login_establish_identity( $user_id, string $email, array $data, WP_REST_Request $request ): int {
	unset( $request );

	$user_id = (int) $user_id;
	$role    = agend_apps_member_mapped_role( $data );

	// Already signed in to WordPress — attach the session. Sync the role only
	// for accounts this integration manages; an independent account is left
	// untouched.
	if ( 0 !== $user_id ) {
		if ( agend_apps_member_is_managed( $user_id ) ) {
			agend_apps_member_apply_role( $user_id, $role );
		}
		agend_apps_member_store_contact_ref( $user_id, $data );
		return $user_id;
	}

	if ( '' === $email || ! is_email( $email ) ) {
		return 0;
	}

	$existing = get_user_by( 'email', $email );

	if ( $existing instanceof WP_User ) {
		// Only manage accounts this integration created. A pre-existing
		// independent WordPress account (any role) is never adopted or
		// modified by a credential login: this protects a shared-email account
		// from hijack, promotion, or demotion. Those users sign in through
		// WordPress.
		if ( ! agend_apps_member_is_managed( $existing->ID ) ) {
			return 0;
		}

		agend_apps_member_apply_role( $existing->ID, $role );
		agend_apps_member_sign_in( $existing->ID );
		agend_apps_member_store_contact_ref( $existing->ID, $data );

		return $existing->ID;
	}

	$new_id = agend_apps_member_create_user( $email, $data, $role );

	if ( 0 === $new_id ) {
		return 0;
	}

	agend_apps_member_sign_in( $new_id );
	agend_apps_member_store_contact_ref( $new_id, $data );

	return $new_id;
}
add_filter( 'agend_apps_member_login_user_id', 'agend_apps_member_login_establish_identity', 10, 4 );

/**
 * Maps an Agend login's admin flag to a WordPress role.
 *
 * @param array $data Decoded login data (reads `is_account_admin`).
 * @return string The WordPress role slug.
 */
function agend_apps_member_mapped_role( array $data ): string {
	$is_admin = ! empty( $data['is_account_admin'] );
	$default  = $is_admin ? 'administrator' : 'subscriber';

	/**
	 * Filters the WordPress role a member login maps to.
	 *
	 * @param string $role     Default role slug (administrator for dashboard admins, subscriber otherwise).
	 * @param bool   $is_admin Whether the member is an Agend dashboard admin.
	 * @param array  $data     Decoded login data.
	 */
	return (string) apply_filters( 'agend_apps_member_login_role', $default, $is_admin, $data );
}

/**
 * Whether a WordPress user is managed by this integration.
 *
 * @param int $user_id WordPress user id.
 * @return bool True when the user was created by a member login.
 */
function agend_apps_member_is_managed( int $user_id ): bool {
	return '1' === (string) get_user_meta( $user_id, AGEND_APPS_MANAGED_META, true );
}

/**
 * Applies a role to a managed WordPress user when it differs from the current
 * one. `set_role` replaces the user's roles, so a demotion (admin back to
 * member) is honoured as well as a promotion.
 *
 * @param int    $user_id WordPress user id.
 * @param string $role    Target role slug.
 */
function agend_apps_member_apply_role( int $user_id, string $role ): void {
	if ( '' === $role ) {
		return;
	}

	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return;
	}

	if ( ! in_array( $role, (array) $user->roles, true ) ) {
		$user->set_role( $role );
	}
}

/**
 * Signs a WordPress user in for the remainder of the request and the browser.
 *
 * @param int $user_id WordPress user id.
 */
function agend_apps_member_sign_in( int $user_id ): void {
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true );
}

/**
 * Creates a member WordPress user for a credential login and marks it managed.
 *
 * The password is random (the member authenticates against Agend, never with a
 * local WordPress password).
 *
 * @param string $email Member email.
 * @param array  $data  Decoded login data.
 * @param string $role  Role slug to assign.
 * @return int The new user id, or 0 on failure.
 */
function agend_apps_member_create_user( string $email, array $data, string $role ): int {
	$base = sanitize_user( (string) current( explode( '@', $email ) ), true );

	if ( '' === $base ) {
		$base = 'member';
	}

	$username = $base;
	$suffix   = 1;

	while ( username_exists( $username ) ) {
		$username = $base . $suffix;
		++$suffix;
	}

	$contact    = ( isset( $data['contact'] ) && is_array( $data['contact'] ) ) ? $data['contact'] : array();
	$first_name = isset( $contact['first_name'] ) ? (string) $contact['first_name'] : '';
	$last_name  = isset( $contact['last_name'] ) ? (string) $contact['last_name'] : '';

	$new_id = wp_insert_user(
		array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24, true, true ),
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ),
			'role'         => '' !== $role ? $role : 'subscriber',
		)
	);

	if ( is_wp_error( $new_id ) ) {
		return 0;
	}

	update_user_meta( (int) $new_id, AGEND_APPS_MANAGED_META, '1' );

	return (int) $new_id;
}

/**
 * Stores the member's Agend contact and user ids on the WordPress user for
 * reference by other Agend surfaces and by the role-change webhook (which keys
 * the WordPress user by the Agend user id without a login). Underscore-prefixed
 * (hidden from the profile UI); never sent to the browser.
 *
 * @param int   $user_id WordPress user id.
 * @param array $data    Decoded login data.
 */
function agend_apps_member_store_contact_ref( int $user_id, array $data ): void {
	if ( isset( $data['contact']['id'] ) && '' !== (string) $data['contact']['id'] ) {
		update_user_meta( $user_id, '_agend_apps_contact_id', sanitize_text_field( (string) $data['contact']['id'] ) );
	}

	if ( isset( $data['user']['id'] ) && '' !== (string) $data['user']['id'] ) {
		update_user_meta( $user_id, '_agend_apps_supabase_user_id', sanitize_text_field( (string) $data['user']['id'] ) );
	}
}
