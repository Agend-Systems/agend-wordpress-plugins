<?php
/**
 * Rewrite endpoints for Agend Elementor catalogue detail URLs.
 *
 * Registers page-agnostic EP_PAGES endpoints so the catalogue widgets can render
 * a single item's detail view from a path segment
 * (/{page}/event/{slug}/, /{page}/course/{slug}/, /{page}/listing/{slug}/)
 * instead of a query parameter. See SPEC-INFRA-20260717 US-1.1 and US-3.2.
 *
 * Loaded unconditionally by the main plugin file: endpoint registration is not
 * gated behind Elementor or Agend Apps Core, so the rewrite rules exist whenever
 * this plugin is active.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the catalogue detail rewrite endpoints.
 *
 * `EP_PAGES` attaches each endpoint to every page, so a detail path works on
 * whatever page hosts a catalogue widget without per-page configuration. Each
 * endpoint also registers a query var of the same name (`event`, `course`),
 * which the widgets read server-side to resolve the initial detail slug.
 */
function agend_elementor_add_rewrite_endpoints(): void {
	add_rewrite_endpoint( 'event', EP_PAGES );
	add_rewrite_endpoint( 'course', EP_PAGES );

	// Directory detail deliberately does NOT use add_rewrite_endpoint( 'listing' ).
	// `listing` is a very common query var that directory themes/plugins register
	// (typically via a `listing` custom post type, which claims the query var
	// WITHOUT adding a rewrite rule, so the conflict never shows in
	// `wp rewrite list`). When another registration owns `listing`, WordPress
	// drops our value during request parsing, the SSR dispatch sees an empty
	// slug, and redirect_canonical strips the /{page}/listing/{slug}/ tail — a
	// 301 back to the catalogue page. `event`/`course` are unique words so
	// nothing competes for them.
	//
	// Instead we register our own rule, at the top so it can never be shadowed,
	// pointing the public /{page}/listing/{slug}/ path at a PRIVATE, namespaced
	// query var (`agend_dir_listing`) that no other plugin can claim. The public
	// URL is unchanged; only the internal query var differs.
	add_rewrite_rule(
		'(.?.+?)/listing(/(.*))?/?$',
		'index.php?pagename=$matches[1]&agend_dir_listing=$matches[3]',
		'top'
	);
}
add_action( 'init', 'agend_elementor_add_rewrite_endpoints' );

/**
 * Registers the directory-detail private query var.
 *
 * Paired with the top-priority rewrite rule in
 * agend_elementor_add_rewrite_endpoints(). Namespaced so no directory
 * theme/plugin that claims the generic `listing` query var can strip the
 * directory detail slug from the parsed request.
 *
 * @param string[] $vars Registered public query vars.
 * @return string[] The query vars with `agend_dir_listing` added.
 */
function agend_elementor_register_query_vars( array $vars ): array {
	$vars[] = 'agend_dir_listing';

	// Legacy (non-pretty) deep-link query params for the dedicated-page
	// redirect (class-agend-elementor-pages.php) to see on the request.
	$vars[] = 'agend_event';
	$vars[] = 'agend_course';

	return $vars;
}
add_filter( 'query_vars', 'agend_elementor_register_query_vars' );

/**
 * Flushes rewrite rules once whenever the rewrite ruleset version changes.
 *
 * A plugin update performed with `wp plugin install --force` does not run the
 * activation hook, so an activation-only flush would leave detail paths
 * returning 404 after an update deploy. This compares a stored option against
 * the AGEND_ELEMENTOR_REWRITE_VERSION constant and flushes once when they
 * differ, so the rules regenerate on the first request after an update. The
 * endpoints are already registered by the `init` hook above (priority 10) when
 * this runs (priority 11), so the flush picks them up.
 */
function agend_elementor_maybe_flush_rewrite_rules(): void {
	if ( get_option( 'agend_elementor_rewrite_version' ) === AGEND_ELEMENTOR_REWRITE_VERSION ) {
		return;
	}

	flush_rewrite_rules();
	update_option( 'agend_elementor_rewrite_version', AGEND_ELEMENTOR_REWRITE_VERSION );
}
add_action( 'init', 'agend_elementor_maybe_flush_rewrite_rules', 11 );

/**
 * Activation: register the endpoints then flush so detail paths resolve
 * immediately, and stamp the rewrite version so the versioned auto-flush does
 * not fire redundantly on the next request.
 */
function agend_elementor_activate_rewrites(): void {
	agend_elementor_add_rewrite_endpoints();
	flush_rewrite_rules();
	update_option( 'agend_elementor_rewrite_version', AGEND_ELEMENTOR_REWRITE_VERSION );
}

/**
 * Deactivation: flush so the plugin's endpoints are removed from the rewrite
 * rules, and clear the version stamp so a later reactivation re-flushes.
 */
function agend_elementor_deactivate_rewrites(): void {
	flush_rewrite_rules();
	delete_option( 'agend_elementor_rewrite_version' );
}
