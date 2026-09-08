<?php
/**
 * WooCommerce My Account "Directory" endpoint.
 *
 * Adds a "Directory" item to the WooCommerce My Account navigation, gated on
 * the member's Agend account-link state ({@see account-link-state.php}):
 * linked members get a button to the directory page, an unlinked-but-linkable
 * member gets a "Connect my account" SSO button that returns them to this
 * endpoint, and a member with no external id (nothing to link) gets a
 * "contact support" message.
 *
 * WooCommerce-gated ({@see agend_apps_my_account_directory_enabled()}): every
 * hook below is registered unconditionally at `plugins_loaded` (this file is
 * loaded from the main plugin bootstrap), but every callback checks
 * `class_exists( 'WooCommerce' )` itself before doing anything, so nothing
 * here runs on a site without WooCommerce active.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrite endpoint slug for the My Account "Directory" page.
 *
 * @var string
 */
const AGEND_APPS_MY_ACCOUNT_DIRECTORY_ENDPOINT = 'agend-directory';

/**
 * Rewrite ruleset version. Bump whenever the endpoint above changes so the
 * versioned auto-flush (below) regenerates the rules on the next request
 * after an update deploy, rather than flushing on every load.
 *
 * @var string
 */
const AGEND_APPS_MY_ACCOUNT_DIRECTORY_REWRITE_VERSION = '20260908-1';

/**
 * Whether the My Account "Directory" endpoint should be registered.
 *
 * Requires WooCommerce active and a resolvable directory page (an explicit
 * `agend_apps_directory_page_id` setting, or the dedicated Directory
 * Catalogue page as a fallback); with neither configured, nothing is
 * registered at all — there would be nowhere for the linked-state button to
 * point.
 *
 * Deliberately NOT gated on the member sign-in mode: both `sso` and
 * `credentials` mode register the menu item, they only differ in what state
 * {@see agend_apps_account_link_state()} resolves to.
 *
 * @return bool True when the endpoint should be registered.
 */
function agend_apps_my_account_directory_enabled(): bool {
	return class_exists( 'WooCommerce' ) && '' !== agend_apps_account_link_directory_url();
}

/**
 * Registers the `agend-directory` rewrite endpoint.
 *
 * `EP_ROOT | EP_PAGES` matches how WooCommerce registers its own My Account
 * endpoints, so `/my-account/agend-directory/` resolves the same way
 * `/my-account/orders/` does.
 */
function agend_apps_my_account_directory_add_rewrite_endpoint(): void {
	if ( ! agend_apps_my_account_directory_enabled() ) {
		return;
	}

	add_rewrite_endpoint( AGEND_APPS_MY_ACCOUNT_DIRECTORY_ENDPOINT, EP_ROOT | EP_PAGES );
}
add_action( 'init', 'agend_apps_my_account_directory_add_rewrite_endpoint' );

/**
 * Flushes rewrite rules once whenever the rewrite ruleset version changes.
 *
 * Mirrors the pattern in includes/records/routing.php: a plugin update
 * performed with `wp plugin install --force` does not run the activation
 * hook, so an activation-only flush would leave the endpoint 404ing after an
 * update. Runs after the endpoint registration above (priority 10) so the
 * flush picks it up.
 */
function agend_apps_my_account_directory_maybe_flush_rewrite_rules(): void {
	if ( get_option( 'agend_apps_my_account_directory_rewrite_version' ) === AGEND_APPS_MY_ACCOUNT_DIRECTORY_REWRITE_VERSION ) {
		return;
	}

	flush_rewrite_rules();
	update_option( 'agend_apps_my_account_directory_rewrite_version', AGEND_APPS_MY_ACCOUNT_DIRECTORY_REWRITE_VERSION );
}
add_action( 'init', 'agend_apps_my_account_directory_maybe_flush_rewrite_rules', 11 );

/**
 * Registers the `agend-directory` query var with WooCommerce.
 *
 * @param string[] $vars WooCommerce's registered My Account query vars.
 * @return string[] The query vars, with `agend-directory` added when enabled.
 */
function agend_apps_my_account_directory_query_vars( array $vars ): array {
	if ( agend_apps_my_account_directory_enabled() ) {
		$vars[ AGEND_APPS_MY_ACCOUNT_DIRECTORY_ENDPOINT ] = AGEND_APPS_MY_ACCOUNT_DIRECTORY_ENDPOINT;
	}

	return $vars;
}
add_filter( 'woocommerce_get_query_vars', 'agend_apps_my_account_directory_query_vars' );

/**
 * Inserts "Directory" into the My Account navigation, immediately before
 * "Logout".
 *
 * Falls back to appending at the end when no `customer-logout` item is found
 * (a theme/plugin that has already restructured the nav), rather than
 * silently dropping the item.
 *
 * @param array<string, string> $items WooCommerce's account menu items, keyed by endpoint.
 * @return array<string, string> The menu items, with `agend-directory` inserted.
 */
function agend_apps_my_account_directory_menu_item( array $items ): array {
	if ( ! agend_apps_my_account_directory_enabled() ) {
		return $items;
	}

	$inserted  = false;
	$new_items = array();

	foreach ( $items as $key => $label ) {
		if ( 'customer-logout' === $key ) {
			$new_items[ AGEND_APPS_MY_ACCOUNT_DIRECTORY_ENDPOINT ] = __( 'Directory', 'agend-apps-core' );
			$inserted = true;
		}

		$new_items[ $key ] = $label;
	}

	if ( ! $inserted ) {
		$new_items[ AGEND_APPS_MY_ACCOUNT_DIRECTORY_ENDPOINT ] = __( 'Directory', 'agend-apps-core' );
	}

	return $new_items;
}
add_filter( 'woocommerce_account_menu_items', 'agend_apps_my_account_directory_menu_item' );

/**
 * Endpoint title, shown in the page `<title>` and heading.
 *
 * @param string $title Default endpoint title.
 * @return string 'Directory'.
 */
function agend_apps_my_account_directory_title( string $title ): string {
	if ( ! agend_apps_my_account_directory_enabled() ) {
		return $title;
	}

	return __( 'Directory', 'agend-apps-core' );
}
add_filter( 'woocommerce_endpoint_' . AGEND_APPS_MY_ACCOUNT_DIRECTORY_ENDPOINT . '_title', 'agend_apps_my_account_directory_title' );

/**
 * Renders the endpoint content markup for a resolved account-link state.
 *
 * Pure/testable: takes the state array {@see agend_apps_account_link_state()}
 * returns rather than resolving it itself, and returns markup rather than
 * echoing it.
 *
 * @param array{state: string, initiate_url: string, directory_url: string, supabase_user_id?: string, contact_id?: string} $state Resolved account-link state.
 * @return string The endpoint content markup.
 */
function agend_apps_my_account_directory_render( array $state ): string {
	$markup_state = $state['state'];

	$out = '<div class="agend-my-account-directory agend-my-account-directory--' . esc_attr( $markup_state ) . '">';

	switch ( $markup_state ) {
		case 'linked':
			$out .= '<p>' . esc_html__( 'Browse the member directory to search for and connect with other members.', 'agend-apps-core' ) . '</p>';

			// A linked member with no recorded contact id (SSO mode: the
			// gateway has not associated a CRM contact yet, or an older
			// gateway did not report one) is still linked, but has no
			// directory profile to show yet.
			if ( '' === ( $state['contact_id'] ?? '' ) ) {
				$out .= '<p>' . esc_html__( 'Your directory profile is still being set up.', 'agend-apps-core' ) . '</p>';
			}

			if ( '' !== $state['directory_url'] ) {
				$out .= '<p><a class="button" href="' . esc_url( $state['directory_url'] ) . '">' . esc_html__( 'Go to directory', 'agend-apps-core' ) . '</a></p>';
			}
			break;

		case 'unlinked':
			$out .= '<p>' . esc_html__( 'Connect your account once to unlock the member directory.', 'agend-apps-core' ) . '</p>';

			if ( '' !== $state['initiate_url'] ) {
				$out .= '<p><a class="button" href="' . esc_url( $state['initiate_url'] ) . '">' . esc_html__( 'Connect my account', 'agend-apps-core' ) . '</a></p>';
			}
			break;

		case 'no_external_id':
			$out .= '<p>' . esc_html__( 'Your account is not yet ready to connect. Please contact support.', 'agend-apps-core' ) . '</p>';
			break;

		case 'logged_out':
			$out .= '<p>' . esc_html__( 'Please sign in to view the member directory.', 'agend-apps-core' ) . '</p>';
			break;

		case 'error':
		default:
			$out .= '<p>' . esc_html__( 'The directory is temporarily unavailable. Please try again shortly.', 'agend-apps-core' ) . '</p>';
			break;
	}

	$out .= '</div>';

	return $out;
}

/**
 * Outputs the endpoint content for the current visitor.
 *
 * A failed link-status lookup (WP_Error) resolves to the neutral `error`
 * state rather than propagating, so a gateway outage shows an "unavailable"
 * message instead of fataling the whole My Account page.
 */
function agend_apps_my_account_directory_content(): void {
	if ( ! agend_apps_my_account_directory_enabled() ) {
		return;
	}

	$return_url = function_exists( 'wc_get_account_endpoint_url' )
		? wc_get_account_endpoint_url( AGEND_APPS_MY_ACCOUNT_DIRECTORY_ENDPOINT )
		: '';

	$state = agend_apps_account_link_state( get_current_user_id(), $return_url );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- agend_apps_my_account_directory_render() escapes every dynamic value itself.
	echo agend_apps_my_account_directory_render( $state );
}
add_action( 'woocommerce_account_' . AGEND_APPS_MY_ACCOUNT_DIRECTORY_ENDPOINT . '_endpoint', 'agend_apps_my_account_directory_content' );
