<?php
/**
 * Connection-configuration primitives shared by every configurable source.
 *
 * Pure functions only: sanitising a posted setting, substituting `{name}`
 * connection variables, and classifying a decoded JSON row. No I/O, no
 * options access, so every one of them is unit-testable without a request.
 *
 * These started life as private statics on
 * Agend_Directory_Sync_Http_Api_Source. They live here because the Dataverse
 * source needs the identical rules — a URL that must be HTTPS, a header map
 * that must not carry a smuggled newline, a variables map that must not carry
 * a secret — and two copies of a sanitiser is two places for the rules to
 * drift apart. The HTTP API source keeps its own public statics as delegates
 * so its callers and tests are unaffected.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Config' ) ) :
	final class Agend_Directory_Sync_Config {

		/**
		 * Whether a URL is allowed: absolute HTTPS always; absolute HTTP only
		 * when `wp_get_environment_type()` is `local` or `development`.
		 */
		public static function is_https_url( string $url ): bool {
			if ( '' === $url ) {
				return false;
			}

			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return false;
			}

			$scheme = strtolower( (string) $parts['scheme'] );

			if ( 'https' === $scheme ) {
				return true;
			}

			return 'http' === $scheme && self::is_local_or_development_environment();
		}

		public static function is_local_or_development_environment(): bool {
			if ( ! function_exists( 'wp_get_environment_type' ) ) {
				return false;
			}
			return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		}

		/**
		 * Sanitise a URL setting: trim, run through esc_url_raw(), and drop it
		 * entirely (return '') if it is not an absolute HTTPS URL (or an
		 * allowed local/development HTTP URL). Blanking rather than persisting
		 * an invalid value means a source reports the plain "no URL configured"
		 * reason instead of a silently-broken state.
		 */
		public static function sanitize_url_setting( string $value ): string {
			$value = trim( $value );
			if ( '' === $value ) {
				return '';
			}

			// A URL containing a `{name}` placeholder is stored as a template:
			// esc_url_raw() strips braces, which would silently corrupt it. The
			// scheme must still be literal at save time; full HTTPS validation
			// runs against the substituted result at run time.
			if ( false !== strpos( $value, '{' ) ) {
				$value = sanitize_text_field( $value );

				$allowed = 0 === strpos( $value, 'https://' )
					|| ( 0 === strpos( $value, 'http://' ) && self::is_local_or_development_environment() );

				return $allowed ? $value : '';
			}

			$value = (string) esc_url_raw( $value );

			return self::is_https_url( $value ) ? $value : '';
		}

		/**
		 * Sanitise a dot-path setting (response data path / has-more path) to
		 * the same character set the field map's source paths allow, so nested
		 * paths remain expressible.
		 */
		public static function sanitize_path_setting( string $value ): string {
			$value = trim( $value );
			if ( '' === $value ) {
				return '';
			}
			return (string) preg_replace( '/[^A-Za-z0-9_.\-]/', '', $value );
		}

		/**
		 * Sanitise a query-parameter name setting to `[A-Za-z0-9_.\[\]-]`,
		 * falling back to the given default when the sanitised result is blank.
		 */
		public static function sanitize_param_name( string $value, string $default ): string {
			$value = trim( $value );
			if ( '' === $value ) {
				return $default;
			}
			$value = (string) preg_replace( '/[^A-Za-z0-9_.\[\]\-]/', '', $value );
			return '' !== $value ? $value : $default;
		}

		/**
		 * Sanitise the connection variables map. Accepts either the saved map
		 * (array) or the posted textarea (one `name = value` per line). Names
		 * are restricted to `[A-Za-z0-9_]+`; values are plain sanitised text.
		 * Variables are stored in `wp_options`, so they must never hold
		 * secrets — the help text says so, and secrets stay in the encrypted
		 * store or a wp-config.php constant.
		 *
		 * @param mixed $raw
		 *
		 * @return array<string, string>
		 */
		public static function sanitize_variables( $raw ): array {
			$pairs = array();

			if ( is_array( $raw ) ) {
				foreach ( $raw as $name => $value ) {
					$pairs[] = array( (string) $name, (string) $value );
				}
			} elseif ( is_string( $raw ) ) {
				foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
					$line = trim( (string) $line );
					if ( '' === $line || false === strpos( $line, '=' ) ) {
						continue;
					}
					list( $name, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );
					$pairs[]              = array( $name, $value );
				}
			}

			$variables = array();
			foreach ( $pairs as $pair ) {
				list( $name, $value ) = $pair;
				if ( '' === $name || ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
					continue;
				}
				$value = sanitize_text_field( $value );
				if ( '' === $value ) {
					continue;
				}
				$variables[ $name ] = $value;
			}

			return $variables;
		}

		/**
		 * Render the variables map as a `name = value` textarea body.
		 *
		 * @param array<string, string> $variables
		 */
		public static function variables_to_textarea( array $variables ): string {
			$lines = array();
			foreach ( $variables as $name => $value ) {
				$lines[] = $name . ' = ' . $value;
			}
			return implode( "\n", $lines );
		}

		/**
		 * Sanitise a custom request headers map. Accepts either the saved map
		 * (array) or the posted textarea (one `Header-Name: value` per line — a
		 * colon, NOT `=`, because a header value such as OData's
		 * `Prefer: odata.include-annotations="*"` legitimately contains `=` and
		 * quotes).
		 *
		 * The header NAME is restricted to HTTP token characters
		 * (`[A-Za-z0-9_\-]`); a line with an empty/invalid name, or with no
		 * colon, is dropped. The header VALUE is trimmed and has every CR/LF
		 * and control character stripped (a header-injection guard — a literal
		 * newline in a header value can smuggle a second header into the
		 * request) but is otherwise preserved verbatim: quotes, `=`, `*`, and
		 * `{name}` placeholders all survive, unlike `sanitize_text_field()`,
		 * which would corrupt them.
		 *
		 * Header values are stored in `wp_options` like the connection
		 * variables, so they must never hold a secret.
		 *
		 * @param mixed $raw
		 *
		 * @return array<string, string>
		 */
		public static function sanitize_headers( $raw ): array {
			$pairs = array();

			if ( is_array( $raw ) ) {
				foreach ( $raw as $name => $value ) {
					$pairs[] = array( (string) $name, (string) $value );
				}
			} elseif ( is_string( $raw ) ) {
				foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
					$line = trim( (string) $line );
					if ( '' === $line || false === strpos( $line, ':' ) ) {
						continue;
					}
					list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
					$pairs[]              = array( $name, $value );
				}
			}

			$headers = array();
			foreach ( $pairs as $pair ) {
				list( $name, $value ) = $pair;
				if ( '' === $name || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $name ) ) {
					continue;
				}
				$value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) );
				if ( '' === $value ) {
					continue;
				}
				$headers[ $name ] = $value;
			}

			return $headers;
		}

		/**
		 * Render a headers map as a `Header-Name: value` textarea body.
		 *
		 * @param array<string, string> $headers
		 */
		public static function headers_to_textarea( array $headers ): string {
			$lines = array();
			foreach ( $headers as $name => $value ) {
				$lines[] = $name . ': ' . $value;
			}
			return implode( "\n", $lines );
		}

		/**
		 * Merge admin-configured custom request headers with the computed
		 * auth-mode headers for a DATA request (never a token request). Auth
		 * headers always win: a custom header with the same name (e.g. an
		 * operator accidentally naming one `Authorization`) must never
		 * silently override the header the configured auth mode sets.
		 *
		 * @param array<string, string> $custom
		 * @param array<string, string> $auth
		 *
		 * @return array<string, string>
		 */
		public static function merge_request_headers( array $custom, array $auth ): array {
			return array_merge( $custom, $auth );
		}

		/**
		 * Replace `{name}` placeholders in a setting value with the configured
		 * connection variables. Unknown placeholders are left in place for
		 * `assert_no_unresolved_placeholders()` to report.
		 *
		 * @param array<string, string> $variables
		 */
		public static function substitute_variables( string $value, array $variables ): string {
			if ( '' === $value || false === strpos( $value, '{' ) ) {
				return $value;
			}

			foreach ( $variables as $name => $variable_value ) {
				$value = str_replace( '{' . $name . '}', $variable_value, $value );
			}

			return $value;
		}

		/**
		 * Fail loudly when a `{name}` placeholder survives substitution,
		 * naming the placeholder and the setting it sits in, so a missing
		 * variable is a configuration message rather than a literal `{name}`
		 * sent to the remote API.
		 *
		 * @throws RuntimeException When an unresolved placeholder remains.
		 */
		public static function assert_no_unresolved_placeholders( string $value, string $setting_label ): void {
			if ( ! preg_match_all( '/\{[A-Za-z0-9_]+\}/', $value, $matches ) ) {
				return;
			}

			throw new RuntimeException(
				sprintf(
					/* translators: 1: setting label, 2: comma-separated unresolved placeholders. */
					__( 'The %1$s setting contains unresolved placeholders: %2$s. Define them under Connection variables.', 'agend-directory-sync' ),
					$setting_label,
					implode( ', ', array_unique( $matches[0] ) )
				)
			);
		}

		/**
		 * Whether a decoded JSON value is an associative array (a JSON
		 * object), as opposed to a list or a scalar. An empty array is treated
		 * as non-associative (skipped): `json_decode('{}', true)` and
		 * `json_decode('[]', true)` are both PHP `array()`, so an empty object
		 * is indistinguishable from an empty list here — but either way it
		 * carries no fields to transform.
		 *
		 * @param mixed $value
		 */
		public static function is_associative_array( $value ): bool {
			if ( ! is_array( $value ) || empty( $value ) ) {
				return false;
			}
			return array_keys( $value ) !== range( 0, count( $value ) - 1 );
		}

		/**
		 * @param mixed $value
		 */
		public static function clamp_int( $value, int $min, int $max, int $default ): int {
			if ( ! is_numeric( $value ) ) {
				return $default;
			}
			$int = (int) $value;
			if ( $int < $min ) {
				return $min;
			}
			if ( $int > $max ) {
				return $max;
			}
			return $int;
		}

		/**
		 * Trim a setting and fall back to $default when blank.
		 *
		 * @param mixed $value
		 */
		public static function non_blank( $value, string $default ): string {
			$value = trim( (string) $value );
			return '' !== $value ? $value : $default;
		}

		/**
		 * Truncate a response body for inclusion in a failure message.
		 */
		public static function excerpt( string $body, int $length ): string {
			return function_exists( 'mb_substr' )
				? mb_substr( $body, 0, $length )
				: substr( $body, 0, $length );
		}
	}
endif;
