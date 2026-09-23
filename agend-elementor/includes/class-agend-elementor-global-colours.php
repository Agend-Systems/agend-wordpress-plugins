<?php
/**
 * Resolves Elementor global colour references in widget settings.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor's COLOR control offers the site kit's global colours. Choosing one
 * stores a reference (`__globals__[<control>] = 'globals/colors?id=<id>'`) and
 * leaves the control's own value empty, because Elementor only resolves that
 * reference while it writes the CSS for a control's `selectors`. The Agend
 * surfaces render their colours through the core renderer instead of
 * `selectors`, so a chosen global would arrive there as an empty string and
 * silently fall back to the surface default.
 *
 * resolve() closes that gap: every global colour reference in a widget's
 * settings is replaced with the kit colour it names, before the settings reach
 * the core renderer. The renderer then treats it like any manually chosen
 * colour, including its safety check.
 */
class Agend_Elementor_Global_Colours {

	/**
	 * Kit colours keyed by global id, cached for the request.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $kit_colours = null;

	/**
	 * Replaces every global colour reference in $settings with its kit colour.
	 *
	 * A reference to a colour the kit no longer holds leaves the setting as it
	 * was, so the renderer's own fallback applies, as it would for an empty
	 * value.
	 *
	 * @param array                      $settings    Widget settings from get_settings_for_display().
	 * @param array<string, string>|null $kit_colours Global id => colour. Null reads the active kit.
	 * @return array
	 */
	public static function resolve( array $settings, ?array $kit_colours = null ): array {
		$kit_colours = $kit_colours ?? self::kit_colours();

		// A repeater stores each row's global references on the row itself,
		// so rows are resolved the same way as the top level.
		foreach ( $settings as $key => $value ) {
			if ( '__globals__' === $key || ! is_array( $value ) || array_values( $value ) !== $value ) {
				continue;
			}
			foreach ( $value as $index => $row ) {
				if ( is_array( $row ) && isset( $row['__globals__'] ) ) {
					$settings[ $key ][ $index ] = self::resolve( $row, $kit_colours );
				}
			}
		}

		$globals = $settings['__globals__'] ?? null;
		if ( ! is_array( $globals ) || empty( $globals ) ) {
			return $settings;
		}

		foreach ( $globals as $key => $reference ) {
			$id = self::colour_id( (string) $reference );
			if ( '' === $id || ! isset( $kit_colours[ $id ] ) ) {
				continue;
			}
			$settings[ $key ] = $kit_colours[ $id ];
		}

		return $settings;
	}

	/**
	 * The global id a colour reference names, or '' for anything else.
	 *
	 * Only colours are resolved: a global typography reference is left to
	 * Elementor, which applies it through the control's own `selectors`.
	 *
	 * @param string $reference For example 'globals/colors?id=a1b2c3d4'.
	 * @return string
	 */
	public static function colour_id( string $reference ): string {
		if ( 1 !== preg_match( '#^globals/colors\?id=([A-Za-z0-9_-]+)$#', $reference, $m ) ) {
			return '';
		}
		return $m[1];
	}

	/**
	 * The active kit's system and custom colours, keyed by global id.
	 *
	 * @return array<string, string>
	 */
	private static function kit_colours(): array {
		if ( null !== self::$kit_colours ) {
			return self::$kit_colours;
		}

		self::$kit_colours = array();

		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			return self::$kit_colours;
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
		if ( ! $kit ) {
			return self::$kit_colours;
		}

		foreach ( array( 'system_colors', 'custom_colors' ) as $group ) {
			$colours = $kit->get_settings_for_display( $group );
			if ( ! is_array( $colours ) ) {
				continue;
			}
			foreach ( $colours as $colour ) {
				if ( ! empty( $colour['_id'] ) && ! empty( $colour['color'] ) ) {
					self::$kit_colours[ (string) $colour['_id'] ] = (string) $colour['color'];
				}
			}
		}

		return self::$kit_colours;
	}

	/**
	 * Forgets the cached kit colours. For tests, and for a kit saved mid-request.
	 */
	public static function flush(): void {
		self::$kit_colours = null;
	}
}
