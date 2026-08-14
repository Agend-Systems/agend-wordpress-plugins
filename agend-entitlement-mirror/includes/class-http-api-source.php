<?php
/**
 * Custom HTTP API source: a generic, admin-configurable JSON API client.
 *
 * Reads member entitlements, a member's profile, the entitlement-type
 * catalogue, and the member list from any JSON API reachable at one
 * configured base URL, using four configurable relative endpoint paths.
 * Deliberately scoped down from agend-directory-sync's
 * Agend_Directory_Sync_Http_Api_Source: one auth mode (an optional static
 * bearer token, resolved from a wp-config constant, never stored in
 * `wp_options`), one pagination shape (`page`/`per_page` query params) for
 * the member list. A client needing OAuth or a different pagination shape
 * for entitlement-mirror is a follow-up on this same interface, not a
 * redesign of it.
 *
 * All non-secret settings live under one option
 * (`agend_entitlement_mirror_http_api`), sanitised as a whole. The bearer
 * token is never persisted — it is read from
 * `AGEND_ENTITLEMENT_MIRROR_HTTP_TOKEN` in wp-config.php at call time only,
 * following the precedent in agend-directory-sync's
 * `AGEND_DIRECTORY_SYNC_HTTP_TOKEN`.
 *
 * @package Agend_Entitlement_Mirror
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Entitlement_Mirror_Http_Api_Source' ) ) :
	final class Agend_Entitlement_Mirror_Http_Api_Source implements Agend_Entitlement_Mirror_Source {

		public const SOURCE_KEY = 'http_api';

		public const OPTION_SETTINGS = 'agend_entitlement_mirror_http_api';

		public const TOKEN_CONSTANT = 'AGEND_ENTITLEMENT_MIRROR_HTTP_TOKEN';

		public const DEFAULT_TIMEOUT = 30;
		public const MIN_TIMEOUT     = 5;
		public const MAX_TIMEOUT     = 120;

		public const DEFAULT_PAGE_SIZE = 100;
		public const MIN_PAGE_SIZE     = 1;
		public const MAX_PAGE_SIZE     = 500;

		/**
		 * Hard safety cap on pages fetched enumerating members in one run.
		 * Guards against a server that ignores pagination parameters.
		 */
		public const MAX_PAGES = 500;

		/**
		 * Length of the response-body excerpt included in a non-2xx failure
		 * message.
		 */
		public const RESPONSE_EXCERPT_LENGTH = 500;

		public function get_key(): string {
			return self::SOURCE_KEY;
		}

		public function get_label(): string {
			return __( 'Custom HTTP API', 'agend-entitlement-mirror' );
		}

		public function is_available(): bool {
			return '' === $this->get_unavailable_reason();
		}

		/**
		 * Unavailable when no base URL is configured. The bearer token is
		 * optional (some internal APIs need none), so its absence never
		 * makes the source unavailable.
		 */
		public function get_unavailable_reason(): string {
			$settings = self::resolve_settings();

			if ( '' === $settings['base_url'] ) {
				return __( 'No base URL is configured for the Custom HTTP API source.', 'agend-entitlement-mirror' );
			}

			return '';
		}

		/**
		 * @param string $member_id Membership number / external id.
		 *
		 * @return array<int, array{category: string, type: string, name: string, starts_at: ?DateTime, expires_at: ?DateTime, quantity_allowed: ?string, quantity_remaining: ?string}>
		 *
		 * @throws RuntimeException When the source is unavailable, the request fails, or the response is not a JSON array.
		 */
		public function fetch_member_entitlements( string $member_id ): array {
			$settings = $this->assert_available_settings();
			$body     = $this->request_json( $this->build_url( $settings['base_url'], $settings['entitlements_path'], $member_id ), $settings );

			if ( ! is_array( $body ) ) {
				throw new RuntimeException( __( 'Custom HTTP API entitlements response was not a JSON array.', 'agend-entitlement-mirror' ) );
			}

			$rows = array();

			foreach ( $body as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$rows[] = array(
					'category'           => (string) ( $row['category'] ?? '' ),
					'type'               => (string) ( $row['type'] ?? '' ),
					'name'               => (string) ( $row['name'] ?? '' ),
					'starts_at'          => self::parse_date( $row['starts_at'] ?? null ),
					'expires_at'         => self::parse_date( $row['expires_at'] ?? null ),
					'quantity_allowed'   => self::parse_nullable_string( $row['quantity_allowed'] ?? null ),
					'quantity_remaining' => self::parse_nullable_string( $row['quantity_remaining'] ?? null ),
				);
			}

			return $rows;
		}

		/**
		 * @param string $member_id Membership number / external id.
		 *
		 * @return array{email: string, first_name: string, last_name: string}
		 */
		public function fetch_member_profile( string $member_id ): array {
			$profile = array(
				'email'      => '',
				'first_name' => '',
				'last_name'  => '',
			);

			if ( ! $this->is_available() ) {
				return $profile;
			}

			$settings = self::resolve_settings();

			if ( '' === $settings['profile_path'] ) {
				return $profile;
			}

			try {
				$body = $this->request_json( $this->build_url( $settings['base_url'], $settings['profile_path'], $member_id ), $settings );
			} catch ( RuntimeException $e ) {
				// A profile is a courtesy for create-on-miss identity fields
				// only -- a transport failure here must never block the
				// entitlement sync itself, so this degrades to blanks rather
				// than propagating.
				return $profile;
			}

			if ( ! is_array( $body ) ) {
				return $profile;
			}

			$profile['email']      = (string) ( $body['email'] ?? '' );
			$profile['first_name'] = (string) ( $body['first_name'] ?? '' );
			$profile['last_name']  = (string) ( $body['last_name'] ?? '' );

			return $profile;
		}

		/**
		 * @return array<int, array{category: string, type: string}>
		 *
		 * @throws RuntimeException When the source is unavailable, the request fails, or the response is not a JSON array.
		 */
		public function fetch_entitlement_types(): array {
			$settings = $this->assert_available_settings();
			$body     = $this->request_json( $this->build_url( $settings['base_url'], $settings['types_path'], '' ), $settings );

			if ( ! is_array( $body ) ) {
				throw new RuntimeException( __( 'Custom HTTP API entitlement-types response was not a JSON array.', 'agend-entitlement-mirror' ) );
			}

			$rows = array();

			foreach ( $body as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$rows[] = array(
					'category' => (string) ( $row['category'] ?? '' ),
					'type'     => (string) ( $row['type'] ?? '' ),
				);
			}

			return $rows;
		}

		/**
		 * @param int $max Maximum members to yield (0 = unbounded).
		 *
		 * @return iterable<array{member_id: string, email: string, first_name: string, last_name: string}>
		 *
		 * @throws RuntimeException When the source is unavailable, a request fails, a response is not a JSON array, or the pagination hard cap is hit.
		 */
		public function enumerate_members( int $max ): iterable {
			$settings = $this->assert_available_settings();

			$page          = 1;
			$yielded       = 0;
			$pages_fetched = 0;

			do {
				if ( $pages_fetched >= self::MAX_PAGES ) {
					throw new RuntimeException(
						sprintf(
							/* translators: %d: the pagination hard cap (pages). */
							__( 'Custom HTTP API member pagination reached the %d page safety cap without completing; aborting instead of returning a truncated set.', 'agend-entitlement-mirror' ),
							self::MAX_PAGES
						)
					);
				}

				$url = add_query_arg(
					array(
						'page'     => $page,
						'per_page' => $settings['page_size'],
					),
					$this->build_url( $settings['base_url'], $settings['members_path'], '' )
				);

				$body = $this->request_json( $url, $settings );
				++$pages_fetched;

				if ( ! is_array( $body ) ) {
					throw new RuntimeException( __( 'Custom HTTP API members response was not a JSON array.', 'agend-entitlement-mirror' ) );
				}

				foreach ( $body as $row ) {
					if ( ! is_array( $row ) || '' === (string) ( $row['member_id'] ?? '' ) ) {
						continue;
					}

					yield array(
						'member_id'  => (string) $row['member_id'],
						'email'      => (string) ( $row['email'] ?? '' ),
						'first_name' => (string) ( $row['first_name'] ?? '' ),
						'last_name'  => (string) ( $row['last_name'] ?? '' ),
					);
					++$yielded;

					if ( $max > 0 && $yielded >= $max ) {
						return;
					}
				}

				++$page;
			} while ( count( $body ) >= $settings['page_size'] && ! empty( $body ) );
		}

		/**
		 * Resolve the effective settings: the saved option merged over
		 * defaults, with numeric settings clamped defensively.
		 *
		 * @return array<string, mixed>
		 */
		public static function resolve_settings(): array {
			$saved = get_option( self::OPTION_SETTINGS, array() );
			$saved = is_array( $saved ) ? $saved : array();

			return array(
				'base_url'          => (string) ( $saved['base_url'] ?? '' ),
				'timeout'           => self::clamp_int( $saved['timeout'] ?? self::DEFAULT_TIMEOUT, self::MIN_TIMEOUT, self::MAX_TIMEOUT, self::DEFAULT_TIMEOUT ),
				'entitlements_path' => trim( (string) ( $saved['entitlements_path'] ?? 'members/{member_id}/entitlements' ) ),
				'profile_path'      => trim( (string) ( $saved['profile_path'] ?? 'members/{member_id}/profile' ) ),
				'types_path'        => trim( (string) ( $saved['types_path'] ?? 'entitlement-types' ) ),
				'members_path'      => trim( (string) ( $saved['members_path'] ?? 'members' ) ),
				'page_size'         => self::clamp_int( $saved['page_size'] ?? self::DEFAULT_PAGE_SIZE, self::MIN_PAGE_SIZE, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE ),
			);
		}

		/**
		 * Sanitise a posted settings array into the persisted shape. The
		 * base URL must parse as absolute HTTPS (http allowed only in
		 * local/development environments) -- a URL failing that check is
		 * dropped (blanked) rather than persisted in a state that would fail
		 * every run.
		 *
		 * @param array<string, mixed> $raw
		 *
		 * @return array<string, mixed>
		 */
		public static function sanitize_settings( array $raw ): array {
			return array(
				'base_url'          => self::sanitize_url( isset( $raw['base_url'] ) ? (string) $raw['base_url'] : '' ),
				'timeout'           => self::clamp_int( $raw['timeout'] ?? self::DEFAULT_TIMEOUT, self::MIN_TIMEOUT, self::MAX_TIMEOUT, self::DEFAULT_TIMEOUT ),
				'entitlements_path' => self::sanitize_path( isset( $raw['entitlements_path'] ) ? (string) $raw['entitlements_path'] : '', 'members/{member_id}/entitlements' ),
				'profile_path'      => self::sanitize_path( isset( $raw['profile_path'] ) ? (string) $raw['profile_path'] : '', 'members/{member_id}/profile' ),
				'types_path'        => self::sanitize_path( isset( $raw['types_path'] ) ? (string) $raw['types_path'] : '', 'entitlement-types' ),
				'members_path'      => self::sanitize_path( isset( $raw['members_path'] ) ? (string) $raw['members_path'] : '', 'members' ),
				'page_size'         => self::clamp_int( $raw['page_size'] ?? self::DEFAULT_PAGE_SIZE, self::MIN_PAGE_SIZE, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE ),
			);
		}

		private function assert_available_settings(): array {
			if ( ! $this->is_available() ) {
				throw new RuntimeException( $this->get_unavailable_reason() );
			}

			return self::resolve_settings();
		}

		/**
		 * Builds the absolute request URL, substituting `{member_id}` in the
		 * relative path when present.
		 */
		private function build_url( string $base_url, string $path, string $member_id ): string {
			$path = '' !== $member_id ? str_replace( '{member_id}', rawurlencode( $member_id ), $path ) : $path;

			return rtrim( $base_url, '/' ) . '/' . ltrim( $path, '/' );
		}

		/**
		 * Requests and decodes one URL, applying the optional bearer token.
		 *
		 * @return mixed Decoded JSON body (array, or null for a JSON `null`).
		 *
		 * @throws RuntimeException On transport failure, non-2xx status, or an invalid JSON body.
		 */
		private function request_json( string $url, array $settings ) {
			$headers = array();

			if ( defined( self::TOKEN_CONSTANT ) && '' !== (string) constant( self::TOKEN_CONSTANT ) ) {
				$headers['Authorization'] = 'Bearer ' . (string) constant( self::TOKEN_CONSTANT );
			}

			$response = wp_remote_get(
				$url,
				array(
					'timeout' => $settings['timeout'],
					'headers' => $headers,
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: underlying WP HTTP transport error message. */
						__( 'Custom HTTP API request failed: %s', 'agend-entitlement-mirror' ),
						$response->get_error_message()
					)
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = (string) wp_remote_retrieve_body( $response );

			if ( $status < 200 || $status >= 300 ) {
				$excerpt = function_exists( 'mb_substr' )
					? mb_substr( $body, 0, self::RESPONSE_EXCERPT_LENGTH )
					: substr( $body, 0, self::RESPONSE_EXCERPT_LENGTH );

				throw new RuntimeException(
					sprintf(
						/* translators: 1: HTTP status code, 2: first 500 characters of the response body. */
						__( 'Custom HTTP API request failed with HTTP %1$d: %2$s', 'agend-entitlement-mirror' ),
						$status,
						$excerpt
					)
				);
			}

			$decoded    = json_decode( $body, true );
			$json_error = json_last_error();

			if ( JSON_ERROR_NONE !== $json_error ) {
				throw new RuntimeException( __( 'Custom HTTP API response was not valid JSON.', 'agend-entitlement-mirror' ) );
			}

			return $decoded;
		}

		private static function parse_date( $value ): ?DateTime {
			if ( empty( $value ) || ! is_string( $value ) ) {
				return null;
			}

			try {
				return new DateTime( $value );
			} catch ( Exception $e ) {
				return null;
			}
		}

		private static function parse_nullable_string( $value ): ?string {
			if ( null === $value || '' === $value ) {
				return null;
			}

			return (string) $value;
		}

		private static function clamp_int( $value, int $min, int $max, int $default ): int {
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

		private static function sanitize_path( string $value, string $default ): string {
			$value = trim( $value );
			return '' !== $value ? sanitize_text_field( $value ) : $default;
		}

		/**
		 * Absolute HTTPS always; absolute HTTP only in a local/development
		 * environment. An invalid or disallowed URL is blanked rather than
		 * persisted, so `is_available()` reports the plain "no URL
		 * configured" reason instead of a silently-broken state.
		 */
		private static function sanitize_url( string $value ): string {
			$value = trim( $value );
			if ( '' === $value ) {
				return '';
			}

			$value = (string) esc_url_raw( $value );
			$parts = wp_parse_url( $value );

			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return '';
			}

			$scheme = strtolower( (string) $parts['scheme'] );

			if ( 'https' === $scheme ) {
				return $value;
			}

			$is_local = function_exists( 'wp_get_environment_type' )
				&& in_array( wp_get_environment_type(), array( 'local', 'development' ), true );

			return ( 'http' === $scheme && $is_local ) ? $value : '';
		}
	}
endif;
