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
 * Concatenation templates (SPEC-DIR-20260731 US-3.2): a source value may also
 * be a template string containing one or more `{path}` placeholders, e.g.
 * `{name_first} {name_last}` or `{addresses.0.unit}/{addresses.0.street}`.
 * Each placeholder resolves via `resolve()` and the results are concatenated
 * with the literal text between them. This reuses the `{name}` visual
 * convention already used for connection-variable substitution
 * (Agend_Directory_Sync_Http_Api_Source::substitute_variables), but the
 * per-row resolution semantics differ: a missing value is normal (a row
 * simply has no data for that field) rather than a configuration error, so a
 * template never throws — it degrades to blank text for the placeholders it
 * cannot resolve.
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

		/**
		 * Whether a configured source value is a concatenation template
		 * rather than a plain source field / dot-path. Any `{` is enough to
		 * decide: a plain source field name is validated elsewhere
		 * (Agend_Directory_Sync_Field_Map::sanitize_source_path) to never
		 * contain one.
		 */
		public static function is_template( string $value ): bool {
			return false !== strpos( $value, '{' );
		}

		/**
		 * Resolve a concatenation template against a data row, replacing each
		 * `{path}` placeholder with its resolved value and joining with the
		 * template's literal text.
		 *
		 * Per-row semantics deliberately differ from the connection-variable
		 * `{name}` substitution: a placeholder that fails to resolve, or
		 * resolves to null/array, is normal (the source row simply has no
		 * value there) and becomes '', never a thrown error. When every
		 * placeholder in the template resolves to '', the whole template
		 * resolves to '' — so a template that is entirely literal residue
		 * around missing data (e.g. a lone `,` or a stray prefix) never
		 * emits a value for a row with no data. Otherwise runs of whitespace
		 * are collapsed to one space and the result trimmed.
		 *
		 * The placeholder charset includes `@`, matching the plain-path
		 * charset in Agend_Directory_Sync_Field_Map::sanitize_source_path,
		 * so an OData-style annotated key such as
		 * `{pca_state@OData.Community.Display.V1.FormattedValue}` resolves
		 * inside a template the same way it resolves as a plain source.
		 *
		 * @param array<string, mixed> $data
		 */
		public static function resolve_template( array $data, string $template ): string {
			$placeholder_count = 0;
			$all_blank         = true;

			$result = preg_replace_callback(
				'/\{([A-Za-z0-9_.\-@]+)\}/',
				static function ( array $matches ) use ( $data, &$placeholder_count, &$all_blank ): string {
					$placeholder_count++;

					$value  = self::resolve( $data, $matches[1] );
					$string = is_scalar( $value ) ? trim( (string) $value ) : '';

					if ( '' !== $string ) {
						$all_blank = false;
					}

					return $string;
				},
				$template
			);

			if ( null === $result ) {
				return '';
			}

			if ( $placeholder_count > 0 && $all_blank ) {
				return '';
			}

			return trim( (string) preg_replace( '/\s+/', ' ', $result ) );
		}
	}
endif;
