<?php
/**
 * Site palette resolution for the catalogue and member login surfaces
 * (SPEC-INFRA-20260907 US-1.3).
 *
 * A surface's colour settings resolve to final CSS variable values once, on
 * the server, in this order: the WordPress site palette (Global Styles) when
 * the active theme declares one, otherwise the plugin defaults, otherwise the
 * instance's own colour settings when inheritance is off. The front end never
 * derives a colour from the site palette itself.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role defaults shared by every catalogue surface (Decision 2.3/US-1.2).
 * A surface with fewer roles (memberships has no button/buttonText) passes a
 * subset, or its own default, as its `$roles` map to
 * {@see agend_apps_records_resolve_colours()}.
 *
 * @var array<string, string>
 */
const AGEND_APPS_RECORDS_COLOUR_DEFAULTS = array(
	'heading'    => '#1E2A4A',
	'body'       => '#26304D',
	'accent'     => '#FF6B55',
	'button'     => '#FF6B55',
	'buttonText' => '#FFFFFF',
);

/**
 * Maps a colour role to the surface setting name that carries its manual
 * (non-inherited) value, byte-identical to the field names US-1.2 declared.
 *
 * @var array<string, string>
 */
const AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS = array(
	'heading'    => 'heading_colour',
	'body'       => 'body_colour',
	'accent'     => 'accent_colour',
	'button'     => 'button_colour',
	'buttonText' => 'button_text_colour',
);

/**
 * Global Styles paths for each role, first non-empty value wins (Decision 2.3).
 *
 * Each path is a list of array keys walked in order against the tree
 * `wp_get_global_styles()` returns.
 *
 * @var array<string, array<int, array<int, string>>>
 */
const AGEND_APPS_RECORDS_PALETTE_PATHS = array(
	'heading'    => array(
		array( 'elements', 'heading', 'color', 'text' ),
		array( 'color', 'text' ),
	),
	'body'       => array(
		array( 'color', 'text' ),
	),
	'accent'     => array(
		array( 'elements', 'link', 'color', 'text' ),
		array( 'color', 'text' ),
	),
	'button'     => array(
		array( 'elements', 'button', 'color', 'background' ),
		array( 'elements', 'link', 'color', 'text' ),
	),
	'buttonText' => array(
		array( 'elements', 'button', 'color', 'text' ),
	),
);

/**
 * Whether a colour value is safe to write inside a `style=""` attribute
 * (Decision 2.4).
 *
 * `esc_attr()` alone does not stop a `;background:url(...)` payload becoming
 * a second declaration, so every colour reaching an inline style, whichever
 * source it came from, is checked against this allow-list first.
 *
 * @param string $value Candidate colour value.
 * @return bool
 */
function agend_apps_records_colour_is_safe( string $value ): bool {
	if ( '' === $value ) {
		return false;
	}

	$hex    = '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i';
	$number = '[\d.]+(?:deg)?%?';
	$func   = '/^(?:rgb|rgba|hsl|hsla)\(\s*' . $number . '(?:\s*[,\s]\s*' . $number . '){2}(?:\s*[,\/]\s*' . $number . ')?\s*\)$/i';
	$preset = '/^var\(--wp--preset--color--[a-z0-9-]+\)$/i';

	return 1 === preg_match( $hex, $value )
		|| 1 === preg_match( $func, $value )
		|| 1 === preg_match( $preset, $value );
}

/**
 * Walks a nested array by a list of keys, returning the string leaf or null.
 *
 * @param array           $tree The tree to walk.
 * @param array<int, string> $path A list of keys.
 * @return string|null
 */
function agend_apps_records_global_styles_path( array $tree, array $path ): ?string {
	$node = $tree;
	foreach ( $path as $key ) {
		if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
			return null;
		}
		$node = $node[ $key ];
	}

	return is_string( $node ) && '' !== $node ? $node : null;
}

/**
 * Converts a Global Styles `var:preset|color|<slug>` reference to the CSS
 * custom property WordPress defines for it on the front end (Decision 2.3).
 * Any other value is returned unchanged.
 *
 * @param string $value Raw Global Styles value.
 * @return string
 */
function agend_apps_records_resolve_preset_colour( string $value ): string {
	$prefix = 'var:preset|color|';
	if ( 0 !== strpos( $value, $prefix ) ) {
		return $value;
	}

	return 'var(--wp--preset--color--' . substr( $value, strlen( $prefix ) ) . ')';
}

/**
 * The colours the active WordPress theme declares through Global Styles,
 * one entry per surface role (US-1.3 AC1-3).
 *
 * Every value is either an allow-listed CSS colour string or null. A theme
 * with no `theme.json` yields every value null unless the
 * `agend_apps_records_site_palette` filter supplies a map (Decision 2.3): a
 * classic theme can register colours through it.
 *
 * @return array<string, string|null>
 */
function agend_apps_records_site_palette(): array {
	$palette = array_fill_keys( array_keys( AGEND_APPS_RECORDS_PALETTE_PATHS ), null );

	if ( wp_theme_has_theme_json() ) {
		$styles = wp_get_global_styles();
		foreach ( AGEND_APPS_RECORDS_PALETTE_PATHS as $role => $paths ) {
			foreach ( $paths as $path ) {
				$raw = agend_apps_records_global_styles_path( $styles, $path );
				if ( null === $raw ) {
					continue;
				}

				$value = agend_apps_records_resolve_preset_colour( $raw );
				if ( agend_apps_records_colour_is_safe( $value ) ) {
					$palette[ $role ] = $value;
					break;
				}
			}
		}
	}

	/**
	 * Filters the resolved site palette (Decision 2.3). A theme with no
	 * `theme.json` is otherwise treated as having set nothing; a classic
	 * theme supplies its own role map here.
	 *
	 * @param array<string, string|null> $palette Role => colour or null.
	 */
	$palette = apply_filters( 'agend_apps_records_site_palette', $palette );

	// The filter is third-party input; re-validate every value regardless of
	// where it came from so an unsafe filter result can never reach a style
	// attribute (Decision 2.4).
	foreach ( $palette as $role => $value ) {
		if ( null !== $value && ! agend_apps_records_colour_is_safe( (string) $value ) ) {
			$palette[ $role ] = null;
		}
	}

	return $palette;
}

/**
 * Resolves a surface's final colour values and records which source won
 * (US-1.3 AC4).
 *
 * `$settings['inherit_colours']` is read as-is: the caller merges in its own
 * schema default (surfaces that declare the toggle default it to `'yes'`;
 * memberships has no toggle and never merges one in, so it always resolves
 * to `custom`).
 *
 * @param array<string, mixed> $settings Surface settings (Elementor-shaped: toggles are 'yes'/'').
 * @param array<string, string> $roles   Role => default colour for the roles this surface exposes.
 * @return array{colours: array<string, string>, source: string}
 */
function agend_apps_records_resolve_colours( array $settings, array $roles ): array {
	$inherit = 'yes' === ( $settings['inherit_colours'] ?? '' );

	if ( $inherit ) {
		$palette = agend_apps_records_site_palette();
		$has_palette_value = false;
		foreach ( $roles as $role => $default ) {
			if ( null !== ( $palette[ $role ] ?? null ) ) {
				$has_palette_value = true;
				break;
			}
		}

		$colours = array();
		foreach ( $roles as $role => $default ) {
			$colours[ $role ] = $has_palette_value ? ( $palette[ $role ] ?? $default ) : $default;
		}

		return array(
			'colours' => $colours,
			'source'  => $has_palette_value ? 'wordpress' : 'agend',
		);
	}

	$colours = array();
	foreach ( $roles as $role => $default ) {
		$setting_key = AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS[ $role ] ?? null;
		$value       = $setting_key ? (string) ( $settings[ $setting_key ] ?? '' ) : '';
		$colours[ $role ] = agend_apps_records_colour_is_safe( $value ) ? $value : $default;
	}

	return array(
		'colours' => $colours,
		'source'  => 'custom',
	);
}
