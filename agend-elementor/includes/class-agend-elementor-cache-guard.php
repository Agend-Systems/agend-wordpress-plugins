<?php
/**
 * Elementor element-cache guard for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flushes Elementor's element cache after a deploy window could have
 * poisoned it.
 *
 * Elementor's Element Cache stores each document's rendered markup in post
 * meta (`_elementor_element_cache`, default TTL 24 hours). While a widget
 * type is unregistered, `Document::do_print_elements()` silently skips its
 * element — no markup and no dynamic-render placeholder — so any cache
 * built during that window omits the widget for the cache's whole lifetime.
 *
 * Elementor only flushes that cache on `activated_plugin` /
 * `deactivated_plugin` / `upgrader_process_complete`, none of which fire on
 * a direct file-copy deploy. A deploy during which Agend Apps Core or
 * Elementor was momentarily unavailable therefore bakes every Agend widget
 * out of any header/footer/page cache rebuilt in that window, and the
 * widgets stay invisible after the deploy completes (observed on the
 * agend-header-auth widget, SPEC-CORE-20260722 US-2.8).
 *
 * Two triggers close that gap:
 *
 * - Version change: the recorded plugin version differs from
 *   AGEND_ELEMENTOR_VERSION on a healthy boot (fires once per versioned
 *   deploy).
 * - Degraded-boot recovery: a previous request bailed out of the bootstrap
 *   dependency checks (recorded in an option) and the current boot is
 *   healthy again — covering same-version windows where caches were built
 *   while the Agend widgets were unregistered.
 */
class Agend_Elementor_Cache_Guard {

	/**
	 * Option storing the plugin version that most recently booted healthy.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'agend_elementor_cache_guard_version';

	/**
	 * Option flagging that a request bailed out of the dependency checks.
	 *
	 * @var string
	 */
	const DEGRADED_OPTION = 'agend_elementor_cache_guard_degraded';

	/**
	 * Records that this request could not register the Agend widgets.
	 *
	 * Called from the bootstrap when a dependency (Agend Apps Core or
	 * Elementor) is unavailable. Any Elementor element cache built from now
	 * until the next healthy boot may omit the Agend widgets, so the next
	 * healthy boot must flush.
	 */
	public static function flag_degraded(): void {
		if ( '1' === get_option( self::DEGRADED_OPTION ) ) {
			return;
		}

		update_option( self::DEGRADED_OPTION, '1', false );
	}

	/**
	 * Arms the healthy-boot cache check.
	 *
	 * Called from the bootstrap once both dependencies are confirmed.
	 * Defers to `elementor/init` because Elementor's files manager is
	 * created in `Plugin::init_components()`, immediately before that
	 * action fires.
	 */
	public static function watch(): void {
		add_action( 'elementor/init', array( __CLASS__, 'maybe_flush' ) );
	}

	/**
	 * Flushes Elementor's caches when a poisoning window is detected.
	 *
	 * Mirrors what Elementor itself does on plugin (de)activation:
	 * `files_manager->clear_cache()` deletes every document's element cache
	 * and generated-CSS meta, all of which regenerate lazily on the next
	 * render — with the Agend widgets registered again.
	 */
	public static function maybe_flush(): void {
		$version_changed = get_option( self::VERSION_OPTION ) !== AGEND_ELEMENTOR_VERSION;
		$was_degraded    = '1' === get_option( self::DEGRADED_OPTION );

		if ( ! $version_changed && ! $was_degraded ) {
			return;
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		if ( $version_changed ) {
			update_option( self::VERSION_OPTION, AGEND_ELEMENTOR_VERSION );
		}

		if ( $was_degraded ) {
			delete_option( self::DEGRADED_OPTION );
		}
	}
}
