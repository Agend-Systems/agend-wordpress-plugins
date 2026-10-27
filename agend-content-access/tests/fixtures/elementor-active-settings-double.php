<?php
/**
 * Elementor's idea of which of an element's settings are active, for the tests.
 *
 * Suppression reads `get_settings_for_display()`, and Elementor builds that by
 * reading every setting whose control is hidden by its `condition` or `conditions`
 * as `null`. So a hidden control is not just a UI detail here: what the rule
 * controls hide is what enforcement does not see. The controls this plugin
 * registers are only safe if hiding one can never change who sees an element, and
 * that can only be tested against Elementor's own algorithm.
 *
 * This is that algorithm, ported from Elementor 4.3.4: `Conditions::compare()` and
 * `::check()`, `Controls_Stack::is_control_visible()` for the `conditions` form, the
 * control defaults `get_init_settings()` adds, and `get_active_settings()`. It
 * supports `conditions` only and fails loudly on the older `condition` form, which
 * the plugin does not use: a test that silently ignored one would pass for the wrong
 * reason.
 *
 * Not a test file: it has no `Test.php` suffix, so PHPUnit does not collect it.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

final class ElementorActiveSettings {

	/**
	 * Port of `Elementor\Conditions::compare()`.
	 *
	 * @param mixed  $left_value  Value of the setting.
	 * @param mixed  $right_value Value the condition names.
	 * @param string $operator    Operator.
	 */
	public static function compare( $left_value, $right_value, ?string $operator ): bool {
		switch ( $operator ) {
			case '==':
				return $left_value == $right_value; // phpcs:ignore Universal.Operators.StrictComparisons
			case '!=':
				return $left_value != $right_value; // phpcs:ignore Universal.Operators.StrictComparisons
			case '!==':
				return $left_value !== $right_value;
			case 'in':
				return in_array( $left_value, $right_value, true );
			case '!in':
				return ! in_array( $left_value, $right_value, true );
			case 'contains':
				return in_array( $right_value, $left_value, true );
			case '!contains':
				return ! in_array( $right_value, $left_value, true );
			default:
				return $left_value === $right_value;
		}
	}

	/**
	 * Port of `Elementor\Conditions::check()`.
	 *
	 * @param array $conditions `{relation?, terms}`.
	 * @param array $comparison The element's settings.
	 */
	public static function check( array $conditions, array $comparison ): bool {
		$is_or             = isset( $conditions['relation'] ) && 'or' === $conditions['relation'];
		$condition_succeed = ! $is_or;

		foreach ( $conditions['terms'] as $term ) {
			if ( ! empty( $term['terms'] ) ) {
				$result = self::check( $term, $comparison );
			} else {
				// Elementor indexes the settings directly, so a term naming a setting
				// that is not there is a warning in production: refuse it here.
				if ( ! array_key_exists( $term['name'], $comparison ) ) {
					throw new \LogicException( 'A condition names a setting the element does not have: ' . $term['name'] );
				}

				$result = self::compare( $comparison[ $term['name'] ], $term['value'], $term['operator'] ?? null );
			}

			if ( $is_or ) {
				if ( $result ) {
					return true;
				}
			} elseif ( ! $result ) {
				return false;
			}
		}

		return $condition_succeed;
	}

	/**
	 * Whether a control is shown for these settings.
	 *
	 * @param array $control A control's registration args.
	 * @param array $values  The element's settings, with defaults.
	 */
	public static function visible( array $control, array $values ): bool {
		if ( ! empty( $control['condition'] ) ) {
			throw new \LogicException( 'This double reads `conditions`, not `condition`.' );
		}

		return empty( $control['conditions'] ) || self::check( $control['conditions'], $values );
	}

	/**
	 * The settings an element has: what was stored, with the default of every data
	 * control the section registers, as `get_init_settings()` adds them.
	 *
	 * @param array<string, array> $controls The section's controls, by id.
	 * @param array                $stored   What the document stores for the element.
	 * @return array
	 */
	public static function settings( array $controls, array $stored ): array {
		$settings = $stored;

		foreach ( $controls as $id => $control ) {
			if ( 'raw_html' === $control['type'] ) {
				continue;
			}

			if ( ! array_key_exists( $id, $settings ) ) {
				$settings[ $id ] = $control['default'] ?? '';
			}
		}

		return $settings;
	}

	/**
	 * Port of `Controls_Stack::get_active_settings()`: a setting whose control is hidden
	 * is `null`, and a setting with no control at all passes through untouched.
	 *
	 * @param array<string, array> $controls The section's controls, by id.
	 * @param array                $stored   What the document stores for the element.
	 * @return array What `get_settings_for_display()` returns.
	 */
	public static function for_display( array $controls, array $stored ): array {
		$settings = self::settings( $controls, $stored );
		$active   = array();

		foreach ( $settings as $key => $setting ) {
			if ( ! isset( $controls[ $key ] ) ) {
				$active[ $key ] = $setting;

				continue;
			}

			$active[ $key ] = self::visible( $controls[ $key ], $settings ) ? $setting : null;
		}

		return $active;
	}
}
