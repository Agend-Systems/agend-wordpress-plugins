<?php
/**
 * Shared account-link state resolution.
 *
 * Resolves a WordPress user's Agend SSO link state to one small array
 * consumed by any surface that needs to show "linked" / "not yet linked" /
 * "can't connect" — currently the WooCommerce My Account "Directory"
 * endpoint (see my-account-directory.php). The REST account-link status
 * route (rest/account-link-routes.php) resolves the same status
 * independently; it predates this helper and is left as-is to avoid
 * behaviour risk on an already-shipped endpoint, but shares the URL-building
 * helper below.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds an SSO initiate URL that returns the visitor to a given URL.
 *
 * Same shape as {@see agend_apps_account_link_initiate_url()} (account
 * root + `/api/auth/sso/{slug}/initiate` + a `relayState` back to the
 * caller), but takes the return URL directly instead of resolving it from
 * the current request's referer, so a caller with no `WP_REST_Request` (the
 * My Account endpoint renders on a normal front-end page load) can use it.
 *
 * Guarded with `method_exists()` rather than calling
 * `Agend_Apps_Settings::get_account_slug()`/`get_root_url()` directly: a
 * lightweight substitute for that class (unit tests use one) may not define
 * every accessor the full settings class does, and an unconfigured account
 * slug/root URL is exactly the "cannot build a link" case this returns ''
 * for anyway.
 *
 * @param string $return_url Absolute URL to return the visitor to after SSO.
 * @return string The initiate URL, or '' when no account slug/root URL is configured.
 */
function agend_apps_account_link_initiate_url_to( string $return_url ): string {
	if ( '' === $return_url ) {
		return '';
	}

	$slug = method_exists( 'Agend_Apps_Settings', 'get_account_slug' )
		? Agend_Apps_Settings::get_account_slug()
		: '';

	if ( '' === $slug ) {
		return '';
	}

	$root = method_exists( 'Agend_Apps_Settings', 'get_root_url' )
		? Agend_Apps_Settings::get_root_url()
		: '';

	if ( '' === $root ) {
		return '';
	}

	$initiate = rtrim( $root, '/' ) . '/api/auth/sso/' . rawurlencode( $slug ) . '/initiate';

	return add_query_arg( 'relayState', rawurlencode( $return_url ), $initiate );
}

/**
 * Resolves the "directory page" a linked member is sent to.
 *
 * Resolution order:
 * 1. The explicit `agend_apps_directory_page_id` setting (Agend Apps
 *    settings screen), when it names a published page.
 * 2. Otherwise the dedicated Directory Catalogue page
 *    ({@see Agend_Apps_Records_Pages::page_id( 'listing' )}), when configured.
 * 3. Otherwise ''.
 *
 * @return string The directory page URL, or '' when neither is configured.
 */
function agend_apps_account_link_directory_url(): string {
	$page_id = absint( get_option( 'agend_apps_directory_page_id', 0 ) );

	if ( $page_id > 0 ) {
		$post = get_post( $page_id );

		if ( $post instanceof WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status ) {
			$url = get_permalink( $page_id );

			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
	}

	if ( class_exists( 'Agend_Apps_Records_Pages' ) && Agend_Apps_Records_Pages::page_id( 'listing' ) > 0 ) {
		return Agend_Apps_Records_Pages::page_url( 'listing' );
	}

	return '';
}

/**
 * Resolves a WordPress user's Agend account-link state.
 *
 * In `credentials` member sign-in mode a WordPress session already IS an
 * Agend session (SPEC-CORE-20260907 US-4.1): there is no separate SSO link
 * to establish, so a logged-in user always resolves to `linked` there,
 * without a gateway round trip. Only in `sso` mode is the SSO identity
 * lookup performed.
 *
 * @param int    $user_id    WordPress user id (0 = not logged in).
 * @param string $return_url Absolute URL to return the visitor to after SSO,
 *                            used to build `initiate_url` for the `unlinked`
 *                            state. May be '' when the caller has none (the
 *                            resulting `initiate_url` is then '').
 * @return array{state: string, initiate_url: string, directory_url: string, supabase_user_id: string, contact_id: string}
 *         `state` is one of `linked`, `unlinked`, `no_external_id`, `error`,
 *         `logged_out`. `supabase_user_id`/`contact_id` are the recorded
 *         Agend identity ids (see {@see agend_apps_record_linked_identity()});
 *         both '' when nothing has been recorded, or the visitor is logged
 *         out.
 */
function agend_apps_account_link_state( int $user_id, string $return_url = '' ): array {
	$directory_url = agend_apps_account_link_directory_url();

	if ( 0 === $user_id ) {
		return array(
			'state'            => 'logged_out',
			'initiate_url'     => '',
			'directory_url'    => $directory_url,
			'supabase_user_id' => '',
			'contact_id'       => '',
		);
	}

	if ( class_exists( 'Agend_Apps_Settings' ) && method_exists( 'Agend_Apps_Settings', 'credential_login_enabled' ) && Agend_Apps_Settings::credential_login_enabled() ) {
		$ids = agend_apps_linked_identity_ids( $user_id );

		return array(
			'state'            => 'linked',
			'initiate_url'     => '',
			'directory_url'    => $directory_url,
			'supabase_user_id' => $ids['supabase_user_id'],
			'contact_id'       => $ids['contact_id'],
		);
	}

	$external_id = agend_apps_current_user_external_id();

	if ( '' === $external_id ) {
		$ids = agend_apps_linked_identity_ids( $user_id );

		return array(
			'state'            => 'no_external_id',
			'initiate_url'     => '',
			'directory_url'    => $directory_url,
			'supabase_user_id' => $ids['supabase_user_id'],
			'contact_id'       => $ids['contact_id'],
		);
	}

	$result = agend_apps_sso_get_link_status( agend_apps_idp_entity_id(), $external_id );

	if ( is_wp_error( $result ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Agend Apps: account-link status lookup failed: ' . $result->get_error_message() );
		}

		$ids = agend_apps_linked_identity_ids( $user_id );

		return array(
			'state'            => 'error',
			'initiate_url'     => '',
			'directory_url'    => $directory_url,
			'supabase_user_id' => $ids['supabase_user_id'],
			'contact_id'       => $ids['contact_id'],
		);
	}

	// The gateway returns the canonical { success, data:{ linked, user_id?,
	// contact_id? } } envelope. user_id/contact_id are optional -- an older
	// gateway omits them.
	$identity_data = ( isset( $result['data'] ) && is_array( $result['data'] ) ) ? $result['data'] : $result;

	$linked = false;
	if ( isset( $identity_data['linked'] ) ) {
		$linked = (bool) $identity_data['linked'];
	}

	if ( $linked ) {
		// A freshly linked member should gain their bearer token on the next
		// gateway call rather than waiting out the worker's negative cache.
		if ( class_exists( 'Agend_Apps_Token_Worker' ) ) {
			Agend_Apps_Token_Worker::clear_negative_cache( $user_id );
		}

		agend_apps_record_linked_identity( $user_id, $identity_data );
		$ids = agend_apps_linked_identity_ids( $user_id );

		return array(
			'state'            => 'linked',
			'initiate_url'     => '',
			'directory_url'    => $directory_url,
			'supabase_user_id' => $ids['supabase_user_id'],
			'contact_id'       => $ids['contact_id'],
		);
	}

	$ids = agend_apps_linked_identity_ids( $user_id );

	return array(
		'state'            => 'unlinked',
		'initiate_url'     => agend_apps_account_link_initiate_url_to( $return_url ),
		'directory_url'    => $directory_url,
		'supabase_user_id' => $ids['supabase_user_id'],
		'contact_id'       => $ids['contact_id'],
	);
}
