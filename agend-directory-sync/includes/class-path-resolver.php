<?php
/**
 * Shared dot-path resolver for Agend Directory Sync.
 *
 * One resolver class backs every place a dot-path is accepted: the field map
 * (US-3.1), the generic HTTP source's response data path, and its pagination
 * probe paths (US-2.x, not yet built). Semantics (SPEC-DIR-20260731 Decision
 * 2.3):
 * - Segments split on `.`; each segment is an associative key lookup.
 * - A purely numeric segment is also valid as a list (array) index, so
 *   `addresses.0.suburb` resolves the first address's suburb.
 * - An exact top-level key match wins before dot-path traversal, so an
 *   existing source field whose literal name contains a dot (e.g. a saved
 *   field-map entry `a.b`) keeps resolving exactly as it did before nested
 *   paths existed.
 *
 * Pure; no I/O.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Path_Resolver' ) ) :
	final class Agend_Directory_Sync_Path_Resolver {

		/**
		 * Resolve a dot-path against an associative array.
		 *
		 * @param array<string, mixed> $data Data to resolve against.
		 * @param string               $path Dot-path, or blank to return $data itself.
		 *
		 * @return mixed The resolved value, or null when the path does not resolve.
		 */
		public static function resolve( array $data, string $path ) {
			return self::resolve_with_diagnostics( $data, $path )['value'];
		}

		/**
		 * Resolve a dot-path, additionally reporting where resolution failed so
		 * callers can surface a fail-loud diagnostic (US-2.1 criterion 3,
		 * US-2.4 criterion 4) instead of a silent miss.
		 *
		 * @param array<string, mixed> $data Data to resolve against.
		 * @param string               $path Dot-path, or blank to return $data itself.
		 *
		 * @return array{value: mixed, resolved: bool, failed_at: string, available_keys: array<int, string>}
		 */
		public static function resolve_with_diagnostics( array $data, string $path ): array {
			$path = trim( $path );

			if ( '' === $path ) {
				return array(
					'value'          => $data,
					'resolved'       => true,
					'failed_at'      => '',
					'available_keys' => array(),
				);
			}

			// Exact top-level key match wins before dot-path traversal
			// (Decision 2.3), so a saved source field whose literal name
			// contains a dot keeps working.
			if ( array_key_exists( $path, $data ) ) {
				return array(
					'value'          => $data[ $path ],
					'resolved'       => true,
					'failed_at'      => '',
					'available_keys' => array(),
				);
			}

			$segments = explode( '.', $path );
			$current  = $data;
			$walked   = array();

			foreach ( $segments as $segment ) {
				$walked[] = $segment;

				if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
					$current = $current[ $segment ];
					continue;
				}

				if ( is_array( $current ) && ctype_digit( $segment ) && array_key_exists( (int) $segment, $current ) ) {
					$current = $current[ (int) $segment ];
					continue;
				}

				// Miss: report the deepest resolvable segment and the keys
				// actually present there, so the failure message can name them
				// (Decision 2.2, US-2.1 criterion 3).
				return array(
					'value'          => null,
					'resolved'       => false,
					'failed_at'      => implode( '.', $walked ),
					'available_keys' => is_array( $current ) ? array_map( 'strval', array_keys( $current ) ) : array(),
				);
			}

			return array(
				'value'          => $current,
				'resolved'       => true,
				'failed_at'      => '',
				'available_keys' => array(),
			);
		}
	}
endif;
