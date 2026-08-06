<?php
/**
 * Elementor compatibility range and suppression-chain probe.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares which Elementor versions this plugin is tested against, and checks
 * at runtime that the mechanism suppression depends on is still intact
 * (SPEC-CMS-20260727 US-4.4 criteria 9 and 10).
 *
 * Two separate concerns, deliberately not collapsed into one "is Elementor ok"
 * boolean:
 *
 *   1. VERSION RANGE. Advisory. Running outside the tested range is a support
 *      statement, not a fault, so it warns and carries on. Refusing to load
 *      would take a working site down over a version number.
 *
 *   2. SUPPRESSION CHAIN. Load-bearing. Fragment suppression works because
 *      every renderable Elementor element, including the Editor V4 atomic
 *      elements, inherits `Element_Base::print_element()`, which is where the
 *      `elementor/frontend/{type}/should_render` filter fires. If a future
 *      release gives atomic elements their own render path that does not fire
 *      that filter, restricted V4 content would render to everybody, silently
 *      and with no error anywhere.
 *
 * The second is why this is a runtime probe and not only a unit test. A test
 * asserts the chain in CI against whatever Elementor the harness has; the probe
 * asserts it on the actual site, against the actual installed version, on every
 * admin pageload. Only the probe can catch the release that ships after this
 * code was last tested.
 */
class Agend_Content_Access_Compat {

	/** Oldest Elementor this plugin is built against (Decision 2.16). */
	const MIN_ELEMENTOR = '3.0.0';

	/** Newest Elementor this plugin has been validated on (US-4.4). */
	const MAX_TESTED_ELEMENTOR = '4.2.0';

	/**
	 * Registers the admin notices.
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_version_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_suppression_notice' ) );
	}

	/**
	 * The installed Elementor version, or '' when Elementor is absent.
	 *
	 * @return string
	 */
	public static function elementor_version(): string {
		return defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '';
	}

	/**
	 * Classifies a version against the supported range.
	 *
	 * Pure, so the boundaries are unit-testable without Elementor installed.
	 *
	 * @param string $version   Installed version, '' when absent.
	 * @param string $min       Minimum supported.
	 * @param string $max_tested Newest validated.
	 * @return string One of 'absent', 'too_old', 'untested', 'ok'.
	 */
	public static function version_state_for(
		string $version,
		string $min = self::MIN_ELEMENTOR,
		string $max_tested = self::MAX_TESTED_ELEMENTOR
	): string {
		if ( '' === trim( $version ) ) {
			return 'absent';
		}

		if ( version_compare( $version, $min, '<' ) ) {
			return 'too_old';
		}

		if ( version_compare( $version, $max_tested, '>' ) ) {
			return 'untested';
		}

		return 'ok';
	}

	/**
	 * Classifies the installed Elementor.
	 *
	 * @return string
	 */
	public static function version_state(): string {
		return self::version_state_for( self::elementor_version() );
	}

	/**
	 * Whether a class chain still routes through the class that fires the
	 * `should_render` filter.
	 *
	 * Pure and injected, so the logic is testable without Elementor. `$exists`
	 * and `$is_subclass` mirror `class_exists()` and `is_subclass_of()`.
	 *
	 * A MISSING atomic class is not a failure: Editor V4 is opt-in and most
	 * sites will not have it. The failure is an atomic class that EXISTS but no
	 * longer inherits the base, because that is the state in which suppression
	 * silently stops applying.
	 *
	 * @param callable $exists       fn(string $class): bool
	 * @param callable $is_subclass  fn(string $class, string $parent): bool
	 * @return string[] Atomic class names whose inheritance is broken.
	 */
	public static function broken_atomic_chains_for(
		callable $exists,
		callable $is_subclass
	): array {
		$base   = 'Elementor\\Element_Base';
		$atomic = array(
			'Elementor\\Modules\\AtomicWidgets\\Elements\\Atomic_Element_Base',
			'Elementor\\Modules\\AtomicWidgets\\Elements\\Atomic_Widget_Base',
		);

		$broken = array();

		if ( ! $exists( $base ) ) {
			return $broken;
		}

		foreach ( $atomic as $class ) {
			if ( ! $exists( $class ) ) {
				continue;
			}

			if ( ! $is_subclass( $class, $base ) ) {
				$broken[] = $class;
			}
		}

		return $broken;
	}

	/**
	 * Runs the chain probe against the real class table.
	 *
	 * @return string[]
	 */
	public static function broken_atomic_chains(): array {
		return self::broken_atomic_chains_for(
			static function ( string $class ): bool {
				return class_exists( $class );
			},
			static function ( string $class, string $parent ): bool {
				return is_subclass_of( $class, $parent );
			}
		);
	}

	/**
	 * Warns when Elementor is outside the tested range.
	 */
	public static function render_version_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = self::version_state();

		if ( 'ok' === $state || 'absent' === $state ) {
			return;
		}

		$version = self::elementor_version();

		if ( 'too_old' === $state ) {
			$message = sprintf(
				/* translators: 1: installed version, 2: minimum supported version. */
				__(
					'Agend Content Access supports Elementor %2$s and later. You are running %1$s, so per-section restrictions may not apply. Page-level restrictions are unaffected.',
					'agend-content-access'
				),
				esc_html( $version ),
				esc_html( self::MIN_ELEMENTOR )
			);
		} else {
			$message = sprintf(
				/* translators: 1: installed version, 2: newest tested version. */
				__(
					'Agend Content Access has been tested up to Elementor %2$s. You are running %1$s. Per-section restrictions are expected to work, but this combination has not been validated. Verify that a restricted section is hidden from a logged-out visitor.',
					'agend-content-access'
				),
				esc_html( $version ),
				esc_html( self::MAX_TESTED_ELEMENTOR )
			);
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			wp_kses_post( $message )
		);
	}

	/**
	 * Escalates when the suppression chain itself is broken.
	 *
	 * Deliberately `notice-error` and not dismissible: this is the one state in
	 * which the plugin believes it is protecting content and is not.
	 */
	public static function render_suppression_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$broken = self::broken_atomic_chains();

		if ( array() === $broken ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p><p><code>%s</code></p></div>',
			esc_html__( 'Agend Content Access: per-section restrictions are NOT being applied to Elementor V4 elements.', 'agend-content-access' ),
			esc_html__(
				'This version of Elementor renders V4 elements through a path that bypasses the filter this plugin uses to hide restricted sections, so those sections may be visible to everyone. Page-level restrictions still apply. Roll Elementor back to a tested version, or remove per-section restrictions from V4 pages until this is resolved.',
				'agend-content-access'
			),
			esc_html( implode( ', ', $broken ) )
		);
	}
}
