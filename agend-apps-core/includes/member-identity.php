<?php
/**
 * WordPress identity establishment for member credential login.
 *
 * Resolves the WordPress user a credential login is stored against
 * (SPEC-CORE-20260722-wordpress-member-login US-1.4). Members whose first
 * interaction is through the WordPress/API surface are onboarded without the
 * association manually creating WordPress accounts: a successful Agend login
 * finds or creates a member-role WordPress user and signs them in.
 *
 * Hooks the `agend_apps_member_login_user_id` filter the auth login route
 * applies. A site that manages member accounts differently can unhook this and
 * provide its own resolver.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Establishes the WordPress user id for a credential login.
 *
 * @param int             $user_id Current WordPress user id (0 when the visitor is not signed in).
 * @param string          $email   Authenticated member email (already validated by the gateway).
 * @param array           $data    Decoded login data (`user`, `contact`, `session`).
 * @param WP_REST_Request $request Current request.
 * @return int The resolved WordPress user id, or 0 when no identity could be established.
 */
function agend_apps_member_login_establish_identity( $user_id, string $email, array $data, WP_REST_Request $request ): int {
	unset( $request );

	$user_id = (int) $user_id;

	// Already signed in to WordPress — attach the Agend session to them.
	if ( 0 !== $user_id ) {
		agend_apps_member_store_contact_ref( $user_id, $data );
		return $user_id;
	}

	if ( '' === $email || ! is_email( $email ) ) {
		return 0;
	}

	$existing = get_user_by( 'email', $email );

	if ( $existing instanceof WP_User ) {
		// Never adopt a privileged WordPress account by an email match: a
		// member credential login must not sign in as an editor or admin who
		// happens to share the email. Those users sign in through WordPress.
		if ( user_can( $existing, 'edit_posts' ) ) {
			return 0;
		}

		agend_apps_member_sign_in( $existing->ID );
		agend_apps_member_store_contact_ref( $existing->ID, $data );

		return $existing->ID;
	}

	$new_id = agend_apps_member_create_user( $email, $data );

	if ( 0 === $new_id ) {
		return 0;
	}

	agend_apps_member_sign_in( $new_id );
	agend_apps_member_store_contact_ref( $new_id, $data );

	return $new_id;
}
add_filter( 'agend_apps_member_login_user_id', 'agend_apps_member_login_establish_identity', 10, 4 );

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
 * Creates a minimal member-role WordPress user for a credential login.
 *
 * The password is random (the member authenticates against Agend, never with a
 * local WordPress password). The role defaults to `subscriber` and is
 * filterable via `agend_apps_member_login_role`.
 *
 * @param string $email Member email.
 * @param array  $data  Decoded login data.
 * @return int The new user id, or 0 on failure.
 */
function agend_apps_member_create_user( string $email, array $data ): int {
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

	/**
	 * Filters the WordPress role assigned to an auto-provisioned member.
	 *
	 * @param string $role  Default role slug.
	 * @param string $email Member email.
	 */
	$role = (string) apply_filters( 'agend_apps_member_login_role', 'subscriber', $email );

	$new_id = wp_insert_user(
		array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24, true, true ),
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ),
			'role'         => $role,
		)
	);

	if ( is_wp_error( $new_id ) ) {
		return 0;
	}

	return (int) $new_id;
}

/**
 * Stores the member's Agend contact and user ids on the WordPress user for
 * reference by other Agend surfaces. Underscore-prefixed (hidden from the
 * profile UI); never sent to the browser.
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
