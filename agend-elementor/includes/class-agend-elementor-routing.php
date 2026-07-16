<?php
/**
 * Rewrite endpoints for Agend Elementor catalogue detail URLs.
 *
 * Registers page-agnostic EP_PAGES endpoints so the catalogue widgets can render
 * a single item's detail view from a path segment
 * (/{page}/event/{slug}/, /{page}/course/{slug}/) instead of a query parameter.
 * See SPEC-INFRA-20260717 US-1.1.
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
}
add_action( 'init', 'agend_elementor_add_rewrite_endpoints' );

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
