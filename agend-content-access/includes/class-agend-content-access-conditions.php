<?php
/**
 * Segmented display conditions: the Agend-native ESAC engine.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates `"<provider>:<value>"` display conditions
 * (SPEC-CMS-20260727 US-6.1, reframed as ESAC feature parity).
 *
 * Recreates the rule model of `wordpress-plugin-iugo-esac`, which is what the
 * `agend_cp_access_conditions` data in every existing site is written against:
 * a flat list of provider-prefixed conditions, combined with ANY or ALL, and
 * optionally negated. Keeping the data shape identical is deliberate, so
 * existing conditions can be read without a lossy conversion step.
 *
 * ONE THING IS DELIBERATELY DIFFERENT. The reference implementation checks a
 * condition with `apply_filters( 'iugo_esac_filter_check_condition_' . $p,
 * true, $v )`: the default is TRUE, so a condition whose provider is not
 * registered PASSES. Deactivate the plugin that supplies a provider, misspell a
 * prefix, or load in the wrong order, and restricted content silently becomes
 * visible to everyone.
 *
 * That is the same defect removed from the Elementor usermeta conditions in
 * US-1.1, and it is not carried over. Here an unknown provider DENIES, and the
 * evaluation reports which providers it did not recognise so the failure is
 * loud rather than invisible.
 *
 * The distinction between access control and personalisation matters here. ESAC
 * was built for personalisation, where failing open shows a generic greeting
 * instead of a personal one. The same engine used for access control fails open
 * into a disclosure. This implementation serves both, so it takes the strict
 * reading.
 */
class Agend_Content_Access_Conditions {

	/** Combine mode: any one condition passing is enough. */
	const MATCH_ANY = 'any';

	/** Combine mode: every condition must pass. */
	const MATCH_ALL = 'all';

	/**
	 * Evaluates a condition list.
	 *
	 * @param array    $conditions Provider-prefixed condition strings.
	 * @param callable $check      fn(string $provider, string $value): ?bool
	 *                             Returns null when the provider is unknown.
	 * @param string   $match      MATCH_ANY or MATCH_ALL.
	 * @param bool     $invert     Negate the combined result.
	 * @return array{passes: bool, unknown: string[], evaluated: array<string, bool>}
	 */
	public static function evaluate(
		array $conditions,
		callable $check,
		string $match = self::MATCH_ANY,
		bool $invert = false
	): array {
		$parsed = self::parse( $conditions );

		// No conditions means no restriction. This matches the reference
		// implementation and is the overwhelmingly common case: 1,808 of the
		// surveyed rows carry an empty condition list.
		if ( array() === $parsed['valid'] && array() === $parsed['malformed'] ) {
			return array(
				'passes'    => ! $invert,
				'unknown'   => array(),
				'evaluated' => array(),
			);
		}

		// A malformed entry is not ignored. Skipping it, as the reference does,
		// silently shrinks the rule: a two-condition ALL rule with one typo
		// becomes a one-condition rule that passes more often.
		if ( array() !== $parsed['malformed'] ) {
			return array(
				'passes'    => false,
				'unknown'   => $parsed['malformed'],
				'evaluated' => array(),
			);
		}

		$evaluated = array();
		$unknown   = array();

		// Every condition is evaluated, even once the outcome is decided. The
		// reference short-circuits, which is faster and means an unknown
		// provider later in the list is never reported. Knowing the rule is
		// broken matters more here than saving a few checks.
		foreach ( $parsed['valid'] as $condition => $parts ) {
			$result = $check( $parts['provider'], $parts['value'] );

			if ( null === $result ) {
				$unknown[]              = $condition;
				$evaluated[ $condition ] = false;
				continue;
			}

			$evaluated[ $condition ] = (bool) $result;
		}

		// An unrecognised provider denies outright, whatever the other
		// conditions say and whichever match mode is in play. The rule cannot
		// be evaluated as written, and a partial evaluation is a different rule.
		if ( array() !== $unknown ) {
			return array(
				'passes'    => false,
				'unknown'   => $unknown,
				'evaluated' => $evaluated,
			);
		}

		$results = array_values( $evaluated );

		$passes = self::MATCH_ALL === $match
			? ! in_array( false, $results, true )
			: in_array( true, $results, true );

		return array(
			'passes'    => $invert ? ! $passes : $passes,
			'unknown'   => array(),
			'evaluated' => $evaluated,
		);
	}

	/**
	 * Splits condition strings into provider and value.
	 *
	 * A condition is `"<provider>:<value>"`. The value may itself contain
	 * colons (a segment id, a URL fragment), so only the FIRST colon splits.
	 * The reference uses `explode(':', $c)` and discards anything that does not
	 * yield exactly two parts, which silently drops such a condition.
	 *
	 * @param array $conditions Raw condition strings.
	 * @return array{valid: array<string, array{provider: string, value: string}>, malformed: string[]}
	 */
	public static function parse( array $conditions ): array {
		$valid     = array();
		$malformed = array();

		foreach ( $conditions as $condition ) {
			if ( ! is_string( $condition ) ) {
				$malformed[] = '(non-string condition)';
				continue;
			}

			$condition = trim( $condition );

			if ( '' === $condition ) {
				continue;
			}

			$at = strpos( $condition, ':' );

			if ( false === $at || 0 === $at || $at === strlen( $condition ) - 1 ) {
				$malformed[] = $condition;
				continue;
			}

			$valid[ $condition ] = array(
				'provider' => substr( $condition, 0, $at ),
				'value'    => substr( $condition, $at + 1 ),
			);
		}

		return array( 'valid' => $valid, 'malformed' => $malformed );
	}

	/**
	 * The distinct providers a condition list references.
	 *
	 * Lets a caller check up front which providers a document needs, so a
	 * missing one can be reported at audit time rather than discovered when a
	 * visitor is refused.
	 *
	 * @param array $conditions Raw condition strings.
	 * @return string[]
	 */
	public static function providers_used( array $conditions ): array {
		$parsed    = self::parse( $conditions );
		$providers = array();

		foreach ( $parsed['valid'] as $parts ) {
			$providers[ $parts['provider'] ] = $parts['provider'];
		}

		return array_values( $providers );
	}
}
