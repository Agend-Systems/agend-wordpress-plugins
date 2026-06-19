<?php
/**
 * Dependency guard.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks that the required sibling plugins (agend-apps-core and
 * iugo-membership-kiosk) are active before any sync hooks are registered.
 */
class Agend_Loop_Sync_Dependencies {

	/**
	 * Returns the list of unmet dependencies.
	 *
	 * @return string[] Human-readable names of missing dependencies.
	 */
	public static function missing(): array {
		$missing = array();

		if ( ! function_exists( 'agend_apps_loop_sync_user' ) ) {
			$missing[] = 'Agend Apps Core';
		}

		if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
			$missing[] = 'Agend Membership (iugo-membership-kiosk)';
		}

		return $missing;
	}

	/**
	 * Whether all dependencies are met.
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
					__( 'Agend Loop Sync is inactive. Required plugins are not active: %s.', 'agend-loop-sync' ),
					implode( ', ', $missing )
				)
			)
		);
	}
}
