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
		return self::missing_from( self::REQUIRED_CORE_FUNCTIONS );
	}

	/**
	 * The probe itself, over an explicit function list.
	 *
	 * Split out so the gate's behaviour is testable. `function_exists()` cannot
	 * be un-declared once true, so a test cannot simulate Core being absent
	 * against the real constant; it can against a list it supplies.
	 *
	 * This is a seam for tests, not an extension point. Production always calls
	 * `missing()`, which passes the constant. There is deliberately no filter
	 * here: a hook able to report dependencies as satisfied would be a hook able
	 * to switch the access-control plugin on without its transport.
	 *
	 * @param string[] $functions Function names that must all exist.
	 * @return string[] Human-readable names of missing dependencies.
	 */
	public static function missing_from( array $functions ): array {
		foreach ( $functions as $function ) {
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
		self::render_notice_for( self::missing() );
	}

	/**
	 * Renders the notice for an explicit missing list.
	 *
	 * Split out for the same reason as `missing_from()`: the copy is worth
	 * asserting on, and a test cannot make `function_exists()` return false for
	 * the real probe. A seam for tests, not an extension point.
	 *
	 * @param string[] $missing Human-readable names of missing dependencies.
	 * @return void
	 */
	public static function render_notice_for( array $missing ) {
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
