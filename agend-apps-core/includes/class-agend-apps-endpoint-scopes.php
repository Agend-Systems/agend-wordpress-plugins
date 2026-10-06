<?php
/**
 * Endpoint scope lookup.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers "which app and scopes does this gateway call need?" from the
 * generated map in `includes/api/endpoint-scopes.php`.
 *
 * The map is keyed by `METHOD /path/{param}` relative to the /v1 base URL. A
 * request path carries real values (`/events/spring-gala`), so lookup compiles
 * each template to a pattern and tries the most specific first: a literal
 * segment beats a parameter at the same position, so `/crm/contacts/search`
 * wins over `/crm/contacts/{id}`.
 */
class Agend_Apps_Endpoint_Scopes {

	/**
	 * The raw map, loaded once.
	 *
	 * @var array<string, array{app: string, scopes: array<int, string[]>}>|null
	 */
	private static $map = null;

	/**
	 * Compiled patterns per HTTP method, most specific first. Each entry is
	 * `array{ pattern: string, key: string }`.
	 *
	 * @var array<string, array<int, array{pattern: string, key: string}>>|null
	 */
	private static $compiled = null;

	/**
	 * Finds the app and scope alternatives a request needs.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path relative to the /v1 base URL. A query string
	 *                       is ignored.
	 * @return array{app: string, scopes: array<int, string[]>}|null Null when
	 *         the endpoint is not in the map.
	 */
	public static function lookup( string $method, string $path ): ?array {
		self::compile();

		$method = strtoupper( $method );
		$path   = '/' . trim( (string) strtok( $path, '?' ), '/' );

		foreach ( self::$compiled[ $method ] ?? array() as $entry ) {
			if ( 1 === preg_match( $entry['pattern'], $path ) ) {
				return self::$map[ $entry['key'] ];
			}
		}

		return null;
	}

	/**
	 * Every key in the map, as `METHOD /path/{param}`.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		self::compile();

		return array_keys( self::$map );
	}

	/**
	 * Replaces the map, for tests. Pass null to reload the generated file.
	 *
	 * @param array|null $map Replacement map, or null to restore.
	 */
	public static function set_map( ?array $map ): void {
		self::$map      = $map;
		self::$compiled = null;
	}

	/**
	 * Loads the map and compiles it into ordered patterns.
	 */
	private static function compile(): void {
		if ( null !== self::$compiled ) {
			return;
		}

		if ( null === self::$map ) {
			$loaded    = require __DIR__ . '/api/endpoint-scopes.php';
			self::$map = is_array( $loaded ) ? $loaded : array();
		}

		$by_method = array();

		foreach ( array_keys( self::$map ) as $key ) {
			list( $method, $template ) = explode( ' ', $key, 2 );

			$segments = explode( '/', trim( $template, '/' ) );
			$parts    = array();

			foreach ( $segments as $segment ) {
				$parts[] = ( 1 === preg_match( '/^\{[^}]+\}$/', $segment ) ) ? '[^/]+' : preg_quote( $segment, '#' );
			}

			$by_method[ $method ][] = array(
				'pattern'  => '#^/' . implode( '/', $parts ) . '$#',
				'key'      => $key,
				'segments' => $segments,
			);
		}

		foreach ( $by_method as $method => $entries ) {
			// Grouped by segment count (templates of different lengths never
			// both match one path), then literal before parameter, left to
			// right.
			usort(
				$entries,
				static function ( array $a, array $b ): int {
					$count = count( $a['segments'] );

					if ( count( $b['segments'] ) !== $count ) {
						return $count <=> count( $b['segments'] );
					}

					for ( $i = 0; $i < $count; $i++ ) {
						$a_param = '{' === substr( $a['segments'][ $i ], 0, 1 );
						$b_param = '{' === substr( $b['segments'][ $i ], 0, 1 );

						if ( $a_param !== $b_param ) {
							return $a_param ? 1 : -1;
						}
					}

					return 0;
				}
			);

			foreach ( $entries as $entry ) {
				self::$compiled[ $method ][] = array(
					'pattern' => $entry['pattern'],
					'key'     => $entry['key'],
				);
			}
		}

		if ( null === self::$compiled ) {
			self::$compiled = array();
		}
	}
}
