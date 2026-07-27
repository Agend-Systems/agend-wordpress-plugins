<?php
/**
 * Dependency guard.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks that Agend Apps Core is active before anything else is registered.
 *
 * Only Core is required. Elementor is optional and is checked separately by
 * `agend_content_access_has_elementor()`, because its absence degrades the
 * feature rather than breaking it: native post and page policies still apply,
 * fragment policies simply have nowhere to attach.
 *
 * The specific Core functions probed here are the ones this plugin actually
 * consumes, not a version string. A version check would pass against a Core
 * build that had moved or renamed them; probing the call sites cannot.
 */
class Agend_Content_Access_Dependencies {

	/**
	 * Core interfaces this plugin consumes (SPEC-CMS-20260727 Decision 2.10).
	 *
	 * `agend_apps_api` is the transport, `agend_apps_get_bearer_token` is the
	 * member identity contract, and `agend_apps_crm_get_tiers` supplies the
	 * membership plan catalogue for the editor.
	 *
	 * @var string[]
	 */
	private const REQUIRED_CORE_FUNCTIONS = array(
		'agend_apps_api',
		'agend_apps_get_bearer_token',
		'agend_apps_crm_get_tiers',
	);

	/**
	 * Returns the list of unmet dependencies.
	 *
	 * @return string[] Human-readable names of missing dependencies.
	 */
	public static function missing(): array {
		foreach ( self::REQUIRED_CORE_FUNCTIONS as $function ) {
			if ( ! function_exists( $function ) ) {
				return array( 'Agend Apps Core' );
			}
		}

		return array();
	}

	/**
	 * Whether all required dependencies are met.
	 *
	 * @return bool
	 */
	public static function are_met(): bool {
		return array() === self::missing();
	}

	/**
	 * Renders an admin notice listing unmet dependencies.
	 *
	 * @return void
	 */
	public static function render_notice() {
		$missing = self::missing();

		if ( array() === $missing ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated plugin names. */
					__(
						'Agend Content Access is inactive and no content restrictions are being applied. Required plugins are not active: %s.',
						'agend-content-access'
					),
					implode( ', ', $missing )
				)
			)
		);
	}
}
