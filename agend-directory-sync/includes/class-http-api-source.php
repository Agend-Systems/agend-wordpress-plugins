<?php
/**
 * Custom HTTP API source: a generic, admin-configurable JSON API client.
 *
 * Reads records from any JSON API by fetching a configured absolute HTTPS
 * URL and resolving the records array via an admin-configured dot-path (the
 * "response data path") through each page's decoded body
 * (SPEC-DIR-20260731 Decision 2.2, US-2.1). Authentication (none, static
 * token, OAuth 2.0 client credentials — US-2.2) and pagination (none, page,
 * offset — US-2.3) are configuration, not code.
 *
 * All settings live under a single option (`agend_directory_sync_http_api`),
 * sanitised as a whole on save (US-2.4 criterion 2). Secrets are never part
 * of that option: the static token and the OAuth client secret are read from
 * wp-config.php constants at call time only (Decision 2.4), delegating OAuth
 * token acquisition and caching to Agend_Directory_Sync_Oauth_Token_Manager.
 *
 * Custom request headers (a `headers` name => value map, admin-configured)
 * are sent with every DATA request — never the OAuth token request — merged
 * so an auth-mode header always wins over a same-named custom header
 * (`merge_request_headers()`). Values may contain `{name}` placeholders
 * resolved from Connection variables at run time. The motivating case is
 * Dynamics/OData, which needs `Prefer: odata.include-annotations="*"` for
 * the `@OData...FormattedValue` annotation fields to appear in the response.
 *
 * Non-associative-array rows (i.e. not JSON objects) resolved from the data
 * path are skipped rather than passed through to the transformer, and the
 * count is accumulated on the instance (`get_skipped_non_associative_count()`).
 * The runner reads that accessor (duck-typed) after the transform and merges
 * the count into the run summary as the `row_not_an_object` skip reason
 * (US-2.1 criterion 4); the admin raw-fetch preview reads it directly.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Http_Api_Source' ) ) :
	final class Agend_Directory_Sync_Http_Api_Source implements Agend_Directory_Sync_Source {

		public const SOURCE_KEY = 'http_api';

		public const AUTH_NONE  = 'none';
		public const AUTH_TOKEN = 'token';
		public const AUTH_OAUTH = 'oauth_client_credentials';

		public const PAGINATION_NONE   = 'none';
		public const PAGINATION_PAGE   = 'page';
		public const PAGINATION_OFFSET = 'offset';

		public const CLIENT_AUTH_BASIC = 'basic';
		public const CLIENT_AUTH_BODY  = 'body';

		public const DEFAULT_TIMEOUT = 30;
		public const MIN_TIMEOUT     = 5;
		public const MAX_TIMEOUT     = 120;

		public const DEFAULT_PAGE_SIZE = 100;
		public const MIN_PAGE_SIZE     = 1;
		public const MAX_PAGE_SIZE     = 500;

		public const DEFAULT_FIRST_PAGE = 1;

		/**
		 * Hard safety cap on pages fetched in one run (Decision 2.5). Guards
		 * against a server that ignores pagination parameters and would
		 * otherwise page forever; hitting it throws rather than returning a
		 * truncated set (US-2.3 criterion 6).
		 */
		public const MAX_PAGES = 500;

		/**
		 * Length of the response-body excerpt included in a non-2xx failure
		 * message (US-2.1 criterion 1).
		 */
		public const RESPONSE_EXCERPT_LENGTH = 500;

		/**
		 * Bytes at which the admin raw-fetch preview truncates the rendered
		 * envelope (US-2.4 criterion 3).
		 */
		public const PREVIEW_TRUNCATE_BYTES = 102400;

		/**
		 * Number of resolved records shown in the raw-fetch preview
		 * (US-2.4 criterion 3).
		 */
		public const PREVIEW_RECORD_LIMIT = 5;

		/**
		 * Count of resolved rows skipped because they were not associative
		 * arrays (JSON objects), accumulated across the most recent
		 * fetch_all() / preview_first_page() call (US-2.1 criterion 4).
		 */
		private int $skipped_non_associative_count = 0;

		public function get_key(): string {
			return self::SOURCE_KEY;
		}

		public function get_label(): string {
			return __( 'Custom HTTP API', 'agend-directory-sync' );
		}

		public function is_available(): bool {
			return '' === $this->get_unavailable_reason();
		}

		/**
		 * Unavailable when no URL is configured, or when the constant the
		 * selected auth mode requires is undefined (US-2.2 criterion 5).
		 * Never performs a network request.
		 */
		public function get_unavailable_reason(): string {
			$settings = self::resolve_settings();

			if ( '' === $settings['url'] ) {
				return __( 'No URL is configured for the Custom HTTP API source.', 'agend-directory-sync' );
			}

			return self::auth_unavailable_reason( $settings['auth_mode'] );
		}

		/**
		 * Non-associative rows skipped on the most recent fetch (US-2.1
		 * criterion 4). Public so the admin preview can read it.
		 */
		public function get_skipped_non_associative_count(): int {
			return $this->skipped_non_associative_count;
		}

		/**
		 * Fetch every page and aggregate the resolved records, in fetch
		 * order (US-2.3 criterion 5).
		 *
		 * @return array<int, array<string, mixed>>
		 *
		 * @throws RuntimeException When the source is unavailable, the URL
		 *                          fails the HTTPS check, a request fails, a
		 *                          page's data path does not resolve to an
		 *                          array, or the pagination hard cap is hit.
		 */
		public function fetch_all(): array {
			if ( ! $this->is_available() ) {
				throw new RuntimeException( $this->get_unavailable_reason() );
			}

			$settings = $this->runtime_settings();
			$this->assert_url_allowed( $settings['url'] );

			$this->skipped_non_associative_count = 0;

			$records       = array();
			$page_number   = $settings['first_page'];
			$offset        = 0;
			$pages_fetched = 0;

			while ( true ) {
				if ( $pages_fetched >= self::MAX_PAGES ) {
					throw new RuntimeException(
						sprintf(
							/* translators: %d: the pagination hard cap (pages). */
							__( 'Custom HTTP API pagination reached the %d page safety cap without completing; aborting instead of returning a truncated set.', 'agend-directory-sync' ),
							self::MAX_PAGES
						)
					);
				}

				$query_args   = $this->build_pagination_query_args( $settings, $page_number, $offset );
				$response     = $this->request_json( $settings, $query_args );
				$page_records = $this->resolve_records( $response['decoded'], $settings['data_path'] );

				$pages_fetched++;
				$records = array_merge( $records, $page_records );

				if ( self::PAGINATION_NONE === $settings['pagination_mode'] ) {
					break;
				}

				if ( empty( $page_records ) ) {
					break;
				}

				if ( count( $page_records ) < $settings['page_size'] ) {
					break;
				}

				if ( '' !== $settings['has_more_path']
					&& false === Agend_Directory_Sync_Path_Resolver::resolve( $response['decoded'], $settings['has_more_path'] )
				) {
					break;
				}

				if ( self::PAGINATION_PAGE === $settings['pagination_mode'] ) {
					$page_number++;
				} else {
					$offset += $settings['page_size'];
				}
			}

			return $records;
		}

		/**
		 * Fetch the first page only and report both the raw envelope and
		 * what the configured path resolved, for the admin preview
		 * (US-2.4). Unlike fetch_all(), a path that fails to resolve is
		 * reported in the return value rather than thrown (US-2.4 criterion
		 * 4); HTTP-level failures (non-2xx, invalid JSON, auth) still throw.
		 *
		 * @return array{
		 *     http_status: int,
		 *     decoded: array<string, mixed>,
		 *     data_path: string,
		 *     path_resolved: bool,
		 *     failed_at?: string,
		 *     available_keys?: array<int, string>,
		 *     resolved_count?: int,
		 *     resolved_records?: array<int, array<string, mixed>>,
		 *     skipped_non_associative?: int
		 * }
		 *
		 * @throws RuntimeException When the source is unavailable, the URL
		 *                          fails the HTTPS check, or the request
		 *                          itself fails.
		 */
		public function preview_first_page(): array {
			if ( ! $this->is_available() ) {
				throw new RuntimeException( $this->get_unavailable_reason() );
			}

			$settings = $this->runtime_settings();
			$this->assert_url_allowed( $settings['url'] );

			$this->skipped_non_associative_count = 0;

			$query_args = $this->build_pagination_query_args( $settings, $settings['first_page'], 0 );
			$response   = $this->request_json( $settings, $query_args );

			$decoded = $response['decoded'];
			$diag    = Agend_Directory_Sync_Path_Resolver::resolve_with_diagnostics( $decoded, $settings['data_path'] );

			$result = array(
				'http_status' => $response['status'],
				'decoded'     => $decoded,
				'data_path'   => $settings['data_path'],
			);

			if ( ! $diag['resolved'] || ! is_array( $diag['value'] ) ) {
				$failure                  = self::describe_path_failure( $decoded, $settings['data_path'], $diag );
				$result['path_resolved']  = false;
				$result['failed_at']      = $failure['failed_at'];
				$result['available_keys'] = $failure['available_keys'];
				return $result;
			}

			$records = array();
			foreach ( $diag['value'] as $row ) {
				if ( self::is_associative_array( $row ) ) {
					$records[] = $row;
				} else {
					$this->skipped_non_associative_count++;
				}
			}

			$result['path_resolved']           = true;
			$result['resolved_count']          = count( $records );
			$result['resolved_records']        = array_slice( $records, 0, self::PREVIEW_RECORD_LIMIT );
			$result['skipped_non_associative'] = $this->skipped_non_associative_count;

			return $result;
		}

		/**
		 * This source's external_metadata contribution (Decision 2.7):
		 * `source_unique_id` always, `source_date_modified` only when a
		 * `date_modified` field-map source is configured, plus `synced_at`.
		 *
		 * @param array<string, mixed>  $contact
		 * @param array<string, string> $core_map
		 *
		 * @return array<string, mixed>
		 */
		public function get_external_metadata( array $contact, array $core_map ): array {
			$metadata = array(
				'source_unique_id' => Agend_Directory_Sync_Listing_Transformer::resolve_source_field( $contact, $core_map, 'external_id' ),
			);

			if ( '' !== (string) ( $core_map['date_modified'] ?? '' ) ) {
				$metadata['source_date_modified'] = Agend_Directory_Sync_Listing_Transformer::resolve_source_field( $contact, $core_map, 'date_modified' );
			}

			$metadata['synced_at'] = gmdate( 'c' );

			return $metadata;
		}

		/**
		 * Resolve the effective settings: the saved option merged over
		 * defaults, with every numeric setting clamped defensively (the
		 * admin-page sanitiser already clamps on save; this guards a value
		 * written by any other path, e.g. a migration).
		 *
		 * @return array<string, mixed>
		 */
		public static function resolve_settings(): array {
			$saved = get_option( Agend_Directory_Sync::OPTION_HTTP_API, array() );
			$saved = is_array( $saved ) ? $saved : array();

			return array(
				'url'             => (string) ( $saved['url'] ?? '' ),
				'timeout'         => self::clamp_int( $saved['timeout'] ?? self::DEFAULT_TIMEOUT, self::MIN_TIMEOUT, self::MAX_TIMEOUT, self::DEFAULT_TIMEOUT ),
				'data_path'       => trim( (string) ( $saved['data_path'] ?? '' ) ),
				'auth_mode'       => self::sanitize_auth_mode( (string) ( $saved['auth_mode'] ?? self::AUTH_NONE ) ),
				'token_header'    => self::non_blank( $saved['token_header'] ?? '', 'Authorization' ),
				'token_template'  => self::non_blank( $saved['token_template'] ?? '', 'Bearer %s' ),
				'oauth_token_url' => (string) ( $saved['oauth_token_url'] ?? '' ),
				'oauth_client_id' => (string) ( $saved['oauth_client_id'] ?? '' ),
				'oauth_scope'     => trim( (string) ( $saved['oauth_scope'] ?? '' ) ),
				'oauth_client_auth' => self::sanitize_client_auth( (string) ( $saved['oauth_client_auth'] ?? self::CLIENT_AUTH_BASIC ) ),
				'variables'       => self::sanitize_variables( $saved['variables'] ?? array() ),
				'headers'         => self::sanitize_headers( $saved['headers'] ?? array() ),
				'pagination_mode' => self::sanitize_pagination_mode( (string) ( $saved['pagination_mode'] ?? self::PAGINATION_NONE ) ),
				'page_param'      => self::non_blank( $saved['page_param'] ?? '', 'page' ),
				'page_size_param' => self::non_blank( $saved['page_size_param'] ?? '', 'per_page' ),
				'page_size'       => self::clamp_int( $saved['page_size'] ?? self::DEFAULT_PAGE_SIZE, self::MIN_PAGE_SIZE, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE ),
				'first_page'      => self::clamp_int( $saved['first_page'] ?? self::DEFAULT_FIRST_PAGE, 0, PHP_INT_MAX, self::DEFAULT_FIRST_PAGE ),
				'offset_param'    => self::non_blank( $saved['offset_param'] ?? '', 'offset' ),
				'limit_param'     => self::non_blank( $saved['limit_param'] ?? '', 'limit' ),
				'has_more_path'   => trim( (string) ( $saved['has_more_path'] ?? '' ) ),
			);
		}

		/**
		 * The resolved settings with connection variables substituted into
		 * the data URL, the OAuth token endpoint URL, the OAuth scope, and
		 * every custom request header value (SPEC-DIR-20260731 v1.1 US-2.5,
		 * Decision 2.9; custom headers added for OData/Dynamics support). A
		 * `{name}` placeholder that remains unresolved after substitution
		 * fails loudly naming the placeholder (a header names itself as
		 * `Header "<name>"`), rather than sending a literal `{name}` to the
		 * remote API. Substitution happens here, at run time, so the stored
		 * settings remain templates and a variables edit takes effect
		 * without re-saving the URLs or headers.
		 *
		 * @return array<string, mixed>
		 *
		 * @throws RuntimeException When a placeholder cannot be resolved from
		 *                          the configured variables.
		 */
		private function runtime_settings(): array {
			$settings = self::resolve_settings();

			$settings['url'] = self::substitute_variables( $settings['url'], $settings['variables'] );
			self::assert_no_unresolved_placeholders( $settings['url'], __( 'URL', 'agend-directory-sync' ) );

			if ( self::AUTH_OAUTH === $settings['auth_mode'] ) {
				$settings['oauth_token_url'] = self::substitute_variables( $settings['oauth_token_url'], $settings['variables'] );
				self::assert_no_unresolved_placeholders( $settings['oauth_token_url'], __( 'Token endpoint URL', 'agend-directory-sync' ) );

				$settings['oauth_scope'] = self::substitute_variables( $settings['oauth_scope'], $settings['variables'] );
				self::assert_no_unresolved_placeholders( $settings['oauth_scope'], __( 'Scope', 'agend-directory-sync' ) );
			}

			foreach ( $settings['headers'] as $header_name => $header_value ) {
				$header_value                     = self::substitute_variables( $header_value, $settings['variables'] );
				self::assert_no_unresolved_placeholders(
					$header_value,
					sprintf(
						/* translators: %s: the custom request header name. */
						__( 'Header "%s"', 'agend-directory-sync' ),
						$header_name
					)
				);
				$settings['headers'][ $header_name ] = $header_value;
			}

			return $settings;
		}

		/**
		 * Connection-variable substitution (Decision 2.9). Rules live in
		 * Agend_Directory_Sync_Config; retained here as the public entry point
		 * existing callers and tests use.
		 *
		 * @param array<string, string> $variables
		 */
		public static function substitute_variables( string $value, array $variables ): string {
			return Agend_Directory_Sync_Config::substitute_variables( $value, $variables );
		}

		/**
		 * @throws RuntimeException When an unresolved placeholder remains.
		 */
		public static function assert_no_unresolved_placeholders( string $value, string $setting_label ): void {
			Agend_Directory_Sync_Config::assert_no_unresolved_placeholders( $value, $setting_label );
		}

		/**
		 * Sanitise a posted settings array into the persisted shape, as a
		 * single unit (US-2.4 criterion 2). URLs must parse as absolute
		 * HTTPS (US-2.1 criterion 6; http allowed only in local/development
		 * environments) — a URL failing that check is dropped (blanked)
		 * rather than persisted in a state that would fail every run.
		 * Parameter names are restricted to `[A-Za-z0-9_.\[\]-]`; numeric
		 * settings are clamped (timeout 5-120s, page size 1-500).
		 *
		 * @param array<string, mixed> $raw
		 *
		 * @return array<string, mixed>
		 */
		public static function sanitize_settings( array $raw ): array {
			return array(
				'url'             => self::sanitize_url_setting( isset( $raw['url'] ) ? (string) $raw['url'] : '' ),
				'timeout'         => self::clamp_int( $raw['timeout'] ?? self::DEFAULT_TIMEOUT, self::MIN_TIMEOUT, self::MAX_TIMEOUT, self::DEFAULT_TIMEOUT ),
				'data_path'       => self::sanitize_path_setting( isset( $raw['data_path'] ) ? (string) $raw['data_path'] : '' ),
				'auth_mode'       => self::sanitize_auth_mode( isset( $raw['auth_mode'] ) ? (string) $raw['auth_mode'] : self::AUTH_NONE ),
				'token_header'    => self::sanitize_param_name( isset( $raw['token_header'] ) ? (string) $raw['token_header'] : '', 'Authorization' ),
				'token_template'  => '' !== trim( (string) ( $raw['token_template'] ?? '' ) ) ? sanitize_text_field( (string) $raw['token_template'] ) : 'Bearer %s',
				'oauth_token_url' => self::sanitize_url_setting( isset( $raw['oauth_token_url'] ) ? (string) $raw['oauth_token_url'] : '' ),
				'oauth_client_id' => sanitize_text_field( isset( $raw['oauth_client_id'] ) ? (string) $raw['oauth_client_id'] : '' ),
				'oauth_scope'     => sanitize_text_field( isset( $raw['oauth_scope'] ) ? (string) $raw['oauth_scope'] : '' ),
				'oauth_client_auth' => self::sanitize_client_auth( isset( $raw['oauth_client_auth'] ) ? (string) $raw['oauth_client_auth'] : self::CLIENT_AUTH_BASIC ),
				'variables'       => self::sanitize_variables( $raw['variables'] ?? array() ),
				'headers'         => self::sanitize_headers( $raw['headers'] ?? array() ),
				'pagination_mode' => self::sanitize_pagination_mode( isset( $raw['pagination_mode'] ) ? (string) $raw['pagination_mode'] : self::PAGINATION_NONE ),
				'page_param'      => self::sanitize_param_name( isset( $raw['page_param'] ) ? (string) $raw['page_param'] : '', 'page' ),
				'page_size_param' => self::sanitize_param_name( isset( $raw['page_size_param'] ) ? (string) $raw['page_size_param'] : '', 'per_page' ),
				'page_size'       => self::clamp_int( $raw['page_size'] ?? self::DEFAULT_PAGE_SIZE, self::MIN_PAGE_SIZE, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE ),
				'first_page'      => self::clamp_int( $raw['first_page'] ?? self::DEFAULT_FIRST_PAGE, 0, PHP_INT_MAX, self::DEFAULT_FIRST_PAGE ),
				'offset_param'    => self::sanitize_param_name( isset( $raw['offset_param'] ) ? (string) $raw['offset_param'] : '', 'offset' ),
				'limit_param'     => self::sanitize_param_name( isset( $raw['limit_param'] ) ? (string) $raw['limit_param'] : '', 'limit' ),
				'has_more_path'   => self::sanitize_path_setting( isset( $raw['has_more_path'] ) ? (string) $raw['has_more_path'] : '' ),
			);
		}

		/**
		 * US-2.1 criterion 6 — see
		 * Agend_Directory_Sync_Config::is_https_url().
		 */
		public static function is_https_url( string $url ): bool {
			return Agend_Directory_Sync_Config::is_https_url( $url );
		}

		private static function is_local_or_development_environment(): bool {
			return Agend_Directory_Sync_Config::is_local_or_development_environment();
		}

		/**
		 * Run-time guard mirroring the save-time HTTPS check (US-2.1
		 * criterion 6). Save-time sanitisation already blanks a
		 * disallowed URL, so this only matters if the option was written
		 * by a path that bypassed sanitize_settings() (e.g. a direct DB
		 * edit or migration).
		 *
		 * @throws RuntimeException When the URL is not HTTPS (and not an
		 *                          allowed local/development HTTP URL).
		 */
		private function assert_url_allowed( string $url ): void {
			if ( ! self::is_https_url( $url ) ) {
				throw new RuntimeException( __( 'Custom HTTP API URL must be HTTPS (HTTP is only allowed when WP_ENVIRONMENT_TYPE is local or development).', 'agend-directory-sync' ) );
			}
		}

		private static function auth_unavailable_reason( string $auth_mode ): string {
			if ( self::AUTH_TOKEN === $auth_mode
				&& '' === Agend_Directory_Sync_Secret_Store::source_of( Agend_Directory_Sync_Secret_Store::KEY_HTTP_TOKEN, 'AGEND_DIRECTORY_SYNC_HTTP_TOKEN' )
			) {
				return __( 'No API token is set. Enter it under Static token settings (stored encrypted), or define AGEND_DIRECTORY_SYNC_HTTP_TOKEN in wp-config.php.', 'agend-directory-sync' );
			}

			if ( self::AUTH_OAUTH === $auth_mode
				&& '' === Agend_Directory_Sync_Secret_Store::source_of( Agend_Directory_Sync_Secret_Store::KEY_OAUTH_CLIENT_SECRET, 'AGEND_DIRECTORY_SYNC_OAUTH_CLIENT_SECRET' )
			) {
				return __( 'No client secret is set. Enter it under OAuth client credentials settings (stored encrypted), or define AGEND_DIRECTORY_SYNC_OAUTH_CLIENT_SECRET in wp-config.php.', 'agend-directory-sync' );
			}

			return '';
		}

		/**
		 * Build this page's pagination query args. Empty for `none` mode
		 * and for the first offset-mode page at offset 0 with a blank
		 * offset param name (never happens in practice — defaults are
		 * always non-blank).
		 *
		 * @return array<string, int>
		 */
		private function build_pagination_query_args( array $settings, int $page_number, int $offset ): array {
			if ( self::PAGINATION_PAGE === $settings['pagination_mode'] ) {
				return array(
					$settings['page_param']      => $page_number,
					$settings['page_size_param'] => $settings['page_size'],
				);
			}

			if ( self::PAGINATION_OFFSET === $settings['pagination_mode'] ) {
				return array(
					$settings['offset_param'] => $offset,
					$settings['limit_param']  => $settings['page_size'],
				);
			}

			return array();
		}

		/**
		 * Request and decode one page. Applies auth headers, retries once
		 * on a 401 with a fresh OAuth token (US-2.2 criterion 4), then
		 * fails loudly on a non-2xx status or a non-JSON body.
		 *
		 * @return array{status: int, decoded: array<string, mixed>}
		 *
		 * @throws RuntimeException On transport failure, non-2xx status, or
		 *                          an invalid JSON body.
		 */
		private function request_json( array $settings, array $query_args ): array {
			$url = ! empty( $query_args ) ? add_query_arg( $query_args, $settings['url'] ) : $settings['url'];

			$response = $this->perform_get( $url, $settings, false );

			if ( 401 === $response['status'] && self::AUTH_OAUTH === $settings['auth_mode'] ) {
				Agend_Directory_Sync_Oauth_Token_Manager::invalidate( $settings['oauth_token_url'], $settings['oauth_client_id'] );
				$response = $this->perform_get( $url, $settings, true );
			}

			if ( $response['status'] < 200 || $response['status'] >= 300 ) {
				throw new RuntimeException( self::format_http_failure_message( $response['status'], $response['body'] ) );
			}

			$decoded = json_decode( $response['body'], true );

			if ( ! is_array( $decoded ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: the response Content-Type header, or "unknown" when absent. */
						__( 'Custom HTTP API response was not valid JSON (content type: %s).', 'agend-directory-sync' ),
						'' !== $response['content_type'] ? $response['content_type'] : __( 'unknown', 'agend-directory-sync' )
					)
				);
			}

			return array(
				'status'  => $response['status'],
				'decoded' => $decoded,
			);
		}

		/**
		 * Perform the raw GET request with auth headers, never throwing on
		 * a non-2xx status (the caller decides whether to retry or fail).
		 *
		 * @return array{status: int, body: string, content_type: string}
		 *
		 * @throws RuntimeException On transport failure or an auth error
		 *                          (e.g. an undefined constant, or an OAuth
		 *                          token request failure).
		 */
		private function perform_get( string $url, array $settings, bool $force_fresh_token ): array {
			$auth_headers = $this->build_auth_headers( $settings, $force_fresh_token );
			$headers      = self::merge_request_headers( $settings['headers'], $auth_headers );

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
						__( 'Custom HTTP API request failed: %s', 'agend-directory-sync' ),
						$response->get_error_message()
					)
				);
			}

			return array(
				'status'       => (int) wp_remote_retrieve_response_code( $response ),
				'body'         => (string) wp_remote_retrieve_body( $response ),
				'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			);
		}

		/**
		 * Build the auth headers for the configured mode. Secrets are read
		 * from constants (or acquired via the OAuth manager) here, at call
		 * time, and never persisted (Decision 2.4).
		 *
		 * @return array<string, string>
		 *
		 * @throws RuntimeException When a required constant is undefined,
		 *                          or OAuth token acquisition fails.
		 */
		private function build_auth_headers( array $settings, bool $force_fresh_token ): array {
			if ( self::AUTH_TOKEN === $settings['auth_mode'] ) {
				$token = Agend_Directory_Sync_Secret_Store::resolve(
					Agend_Directory_Sync_Secret_Store::KEY_HTTP_TOKEN,
					'AGEND_DIRECTORY_SYNC_HTTP_TOKEN'
				);

				if ( '' === $token ) {
					throw new RuntimeException( __( 'No API token is set. Enter it under Static token settings (stored encrypted), or define AGEND_DIRECTORY_SYNC_HTTP_TOKEN in wp-config.php.', 'agend-directory-sync' ) );
				}

				return array( $settings['token_header'] => sprintf( $settings['token_template'], $token ) );
			}

			if ( self::AUTH_OAUTH === $settings['auth_mode'] ) {
				$access_token = Agend_Directory_Sync_Oauth_Token_Manager::get_access_token(
					$settings['oauth_token_url'],
					$settings['oauth_client_id'],
					$settings['oauth_scope'],
					$settings['oauth_client_auth'],
					$force_fresh_token
				);

				return array( 'Authorization' => 'Bearer ' . $access_token );
			}

			return array();
		}

		/**
		 * Merge the admin-configured custom request headers with the
		 * computed auth-mode headers for the DATA request only (never the
		 * OAuth token request); auth headers always win. See
		 * Agend_Directory_Sync_Config::merge_request_headers().
		 *
		 * @param array<string, string> $custom Sanitised custom headers (name => value).
		 * @param array<string, string> $auth   Headers built by build_auth_headers().
		 *
		 * @return array<string, string>
		 */
		public static function merge_request_headers( array $custom, array $auth ): array {
			return Agend_Directory_Sync_Config::merge_request_headers( $custom, $auth );
		}

		/**
		 * Resolve one page's records array from its decoded body, counting
		 * (and skipping) non-associative-array rows (US-2.1 criterion 4).
		 *
		 * @param array<string, mixed> $decoded
		 *
		 * @return array<int, array<string, mixed>>
		 *
		 * @throws RuntimeException When the configured path does not
		 *                          resolve to an array (US-2.1 criterion 3).
		 */
		private function resolve_records( array $decoded, string $data_path ): array {
			$diag = Agend_Directory_Sync_Path_Resolver::resolve_with_diagnostics( $decoded, $data_path );

			if ( ! $diag['resolved'] || ! is_array( $diag['value'] ) ) {
				$failure = self::describe_path_failure( $decoded, $data_path, $diag );
				throw new RuntimeException( self::format_path_failure_message( $data_path, $failure ) );
			}

			$records = array();
			foreach ( $diag['value'] as $row ) {
				if ( self::is_associative_array( $row ) ) {
					$records[] = $row;
				} else {
					$this->skipped_non_associative_count++;
				}
			}

			return $records;
		}

		/**
		 * Describe why a path failed to resolve to an array, for both the
		 * thrown run-failure message and the non-throwing preview (US-2.1
		 * criterion 3, US-2.4 criterion 4). Two cases:
		 * - The path missed entirely: the resolver already reports the
		 *   deepest resolvable segment and the keys present there.
		 * - The path resolved fully but the value found is not an array
		 *   (e.g. a string): the resolver only reports available_keys on a
		 *   miss, so the parent segment is re-resolved here (via the same
		 *   shared resolver, not a second implementation) to report what
		 *   was available one level up.
		 *
		 * @param array<string, mixed> $decoded
		 * @param array{value: mixed, resolved: bool, failed_at: string, available_keys: array<int, string>} $diag
		 *
		 * @return array{failed_at: string, available_keys: array<int, string>}
		 */
		private static function describe_path_failure( array $decoded, string $data_path, array $diag ): array {
			if ( ! $diag['resolved'] ) {
				return array(
					'failed_at'      => '' !== $diag['failed_at'] ? $diag['failed_at'] : ( '' !== $data_path ? $data_path : __( '(root)', 'agend-directory-sync' ) ),
					'available_keys' => $diag['available_keys'],
				);
			}

			$segments = '' !== $data_path ? explode( '.', $data_path ) : array();
			array_pop( $segments );
			$parent_path  = implode( '.', $segments );
			$parent_diag  = Agend_Directory_Sync_Path_Resolver::resolve_with_diagnostics( $decoded, $parent_path );
			$parent_value = $parent_diag['resolved'] ? $parent_diag['value'] : null;

			return array(
				'failed_at'      => '' !== $data_path ? $data_path : __( '(root)', 'agend-directory-sync' ),
				'available_keys' => is_array( $parent_value ) ? array_map( 'strval', array_keys( $parent_value ) ) : array(),
			);
		}

		/**
		 * @param array{failed_at: string, available_keys: array<int, string>} $failure
		 */
		private static function format_path_failure_message( string $data_path, array $failure ): string {
			$available = ! empty( $failure['available_keys'] )
				? implode( ', ', $failure['available_keys'] )
				: __( '(none)', 'agend-directory-sync' );

			return sprintf(
				/* translators: 1: configured response data path (or "(root)"), 2: deepest path segment where resolution failed, 3: comma-separated keys available at that segment. */
				__( 'Response data path "%1$s" did not resolve to an array. Resolution failed at "%2$s"; keys available there: %3$s.', 'agend-directory-sync' ),
				'' !== $data_path ? $data_path : __( '(root)', 'agend-directory-sync' ),
				$failure['failed_at'],
				$available
			);
		}

		private static function format_http_failure_message( int $status, string $body ): string {
			$excerpt = Agend_Directory_Sync_Config::excerpt( $body, self::RESPONSE_EXCERPT_LENGTH );

			return sprintf(
				/* translators: 1: HTTP status code, 2: first 500 characters of the response body. */
				__( 'Custom HTTP API request failed with HTTP %1$d: %2$s', 'agend-directory-sync' ),
				$status,
				$excerpt
			);
		}

		/**
		 * @param mixed $value
		 */
		private static function is_associative_array( $value ): bool {
			return Agend_Directory_Sync_Config::is_associative_array( $value );
		}

		private static function sanitize_auth_mode( string $value ): string {
			$value = trim( $value );
			return in_array( $value, array( self::AUTH_NONE, self::AUTH_TOKEN, self::AUTH_OAUTH ), true ) ? $value : self::AUTH_NONE;
		}

		private static function sanitize_pagination_mode( string $value ): string {
			$value = trim( $value );
			return in_array( $value, array( self::PAGINATION_NONE, self::PAGINATION_PAGE, self::PAGINATION_OFFSET ), true ) ? $value : self::PAGINATION_NONE;
		}

		/**
		 * US-2.1 criterion 6, US-2.4 criterion 2 — see
		 * Agend_Directory_Sync_Config::sanitize_url_setting().
		 */
		private static function sanitize_url_setting( string $value ): string {
			return Agend_Directory_Sync_Config::sanitize_url_setting( $value );
		}

		/**
		 * Sanitise the OAuth client authentication method (Decision 2.10):
		 * `basic` (HTTP Basic header) or `body` (form-encoded client
		 * credentials). Anything else falls back to `basic`.
		 */
		private static function sanitize_client_auth( string $value ): string {
			$value = trim( $value );
			return in_array( $value, array( self::CLIENT_AUTH_BASIC, self::CLIENT_AUTH_BODY ), true ) ? $value : self::CLIENT_AUTH_BASIC;
		}

		/**
		 * Decision 2.9 — see Agend_Directory_Sync_Config::sanitize_variables().
		 *
		 * @param mixed $raw
		 *
		 * @return array<string, string>
		 */
		private static function sanitize_variables( $raw ): array {
			return Agend_Directory_Sync_Config::sanitize_variables( $raw );
		}

		/**
		 * See Agend_Directory_Sync_Config::sanitize_headers().
		 *
		 * @param mixed $raw
		 *
		 * @return array<string, string>
		 */
		private static function sanitize_headers( $raw ): array {
			return Agend_Directory_Sync_Config::sanitize_headers( $raw );
		}

		/**
		 * @param array<string, string> $headers
		 */
		public static function headers_to_textarea( array $headers ): string {
			return Agend_Directory_Sync_Config::headers_to_textarea( $headers );
		}

		/**
		 * US-2.4 criterion 2 — see
		 * Agend_Directory_Sync_Config::sanitize_param_name().
		 */
		private static function sanitize_param_name( string $value, string $default ): string {
			return Agend_Directory_Sync_Config::sanitize_param_name( $value, $default );
		}

		private static function sanitize_path_setting( string $value ): string {
			return Agend_Directory_Sync_Config::sanitize_path_setting( $value );
		}

		/**
		 * @param mixed $value
		 */
		private static function clamp_int( $value, int $min, int $max, int $default ): int {
			return Agend_Directory_Sync_Config::clamp_int( $value, $min, $max, $default );
		}

		/**
		 * @param mixed $value
		 */
		private static function non_blank( $value, string $default ): string {
			return Agend_Directory_Sync_Config::non_blank( $value, $default );
		}
	}
endif;
