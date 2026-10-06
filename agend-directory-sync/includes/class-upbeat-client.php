<?php
/**
 * Upbeat source: the membership directory contacts endpoint.
 *
 * Delegates auth and base URI resolution to the kiosk plugin's API class
 * (its public get_raw_json()), and owns the paging loop itself so the fetch
 * can be bounded to a page window, the same model as the Dataverse source:
 * a page size, a start page and a max page count. A run fetches only that
 * window and reports when the source still had more records, so a large
 * directory is synced a window at a time instead of in one unbounded request.
 *
 * Implements Agend_Directory_Sync_Source (key `upbeat`) so the runner
 * resolves it via the registry rather than constructing it directly
 * (SPEC-DIR-20260731 US-1.1).
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Upbeat_Client' ) ) :
	final class Agend_Directory_Sync_Upbeat_Client implements Agend_Directory_Sync_Source {

		/**
		 * Stable registry key for this source.
		 */
		public const SOURCE_KEY = 'upbeat';

		/**
		 * Default Upbeat endpoint path. Filterable so a client can override
		 * without editing this file.
		 */
		public const DEFAULT_ENDPOINT = 'membershipDirectoryContacts';

		/**
		 * Records requested per page. 1000 is the page count the kiosk plugin
		 * itself uses for every Upbeat list endpoint, so it is the default and
		 * the ceiling.
		 */
		public const DEFAULT_PAGE_SIZE = 1000;
		public const MIN_PAGE_SIZE     = 1;
		public const MAX_PAGE_SIZE     = 1000;

		public const DEFAULT_START_PAGE = 1;

		/**
		 * Hard safety cap on pages fetched in one run: guards against an
		 * endpoint that reports `MoreRecords` forever. Hitting it throws
		 * rather than returning a truncated set that looks complete.
		 */
		public const MAX_PAGES = 500;

		private int $pages_fetched = 0;
		private bool $stopped_at_page_limit = false;

		/**
		 * An API object used in place of the kiosk plugin's singleton. Only
		 * needs a `get_raw_json( string $url ): string` method. Lets the paging
		 * loop run without the kiosk plugin loaded.
		 */
		private ?object $api_override = null;

		public function use_api( object $api ): self {
			$this->api_override = $api;
			return $this;
		}

		public function get_pages_fetched(): int {
			return $this->pages_fetched;
		}

		/**
		 * True when the configured page window ended while Upbeat still
		 * reported more records. Read by the runner (duck-typed) to flag a
		 * partial fetch in the run summary.
		 */
		public function stopped_at_page_limit(): bool {
			return $this->stopped_at_page_limit;
		}

		/**
		 * The saved page window, each value clamped. `max_pages` 0 means
		 * "keep going until Upbeat reports no more records".
		 *
		 * @return array{page_size: int, start_page: int, max_pages: int}
		 */
		public static function paging_settings(): array {
			$saved = get_option( Agend_Directory_Sync::OPTION_UPBEAT_PAGING, array() );

			return self::sanitize_paging( is_array( $saved ) ? $saved : array() );
		}

		/**
		 * Clamp a posted or saved page window.
		 *
		 * @param array<string, mixed> $raw
		 *
		 * @return array{page_size: int, start_page: int, max_pages: int}
		 */
		public static function sanitize_paging( array $raw ): array {
			return array(
				'page_size'  => Agend_Directory_Sync_Config::clamp_int( $raw['page_size'] ?? self::DEFAULT_PAGE_SIZE, self::MIN_PAGE_SIZE, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE ),
				'start_page' => Agend_Directory_Sync_Config::clamp_int( $raw['start_page'] ?? self::DEFAULT_START_PAGE, 1, PHP_INT_MAX, self::DEFAULT_START_PAGE ),
				'max_pages'  => Agend_Directory_Sync_Config::clamp_int( $raw['max_pages'] ?? 0, 0, self::MAX_PAGES, 0 ),
			);
		}

		public function get_key(): string {
			return self::SOURCE_KEY;
		}

		public function get_label(): string {
			return __( 'Upbeat (membership kiosk)', 'agend-directory-sync' );
		}

		public function is_available(): bool {
			return '' === $this->get_unavailable_reason();
		}

		public function get_unavailable_reason(): string {
			if ( null === $this->api_override && ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
				return __( 'Iugo Membership Kiosk plugin is not active.', 'agend-directory-sync' );
			}
			return '';
		}

		/**
		 * Fetch the configured page window of membership directory contacts.
		 * Each result is an associative array matching the Upbeat response shape.
		 *
		 * Pages are requested with `pageCount` and `pageNumber` and read until
		 * `ResponseData.MoreRecords` is false, the window's max pages is reached
		 * (recorded in stopped_at_page_limit() when Upbeat had more), or the
		 * safety cap is hit. A page that lacks `ResponseData.Results` after a page
		 * that promised more records is an error, not an end of data: stopping
		 * quietly there would return a short set that looks complete.
		 *
		 * @return array<int, array<string, mixed>>
		 *
		 * @throws RuntimeException When the kiosk API class is unavailable, a request fails, or paging cannot complete.
		 */
		public function fetch_all(): array {
			if ( ! $this->is_available() ) {
				throw new RuntimeException( $this->get_unavailable_reason() );
			}

			$this->pages_fetched         = 0;
			$this->stopped_at_page_limit = false;

			// The endpoint path varies per client, so it is configurable in the
			// admin. Fall back to the default when unset.
			$configured = trim( (string) get_option( Agend_Directory_Sync::OPTION_UPBEAT_ENDPOINT, '' ) );
			$default    = '' !== $configured ? $configured : self::DEFAULT_ENDPOINT;

			/**
			 * Filter the Upbeat endpoint path for the membership directory. The
			 * configured option value is passed as the default so a filter can
			 * still override it in code.
			 *
			 * @param string $endpoint Configured (or default) endpoint slug.
			 */
			$endpoint = apply_filters( 'agend_directory_sync_upbeat_endpoint', $default );

			$settings = self::paging_settings();
			$api      = $this->api_override ?? Iugo_Membership_Kiosk_API::instance();
			$page     = $settings['start_page'];
			$results  = array();

			while ( true ) {
				if ( $this->pages_fetched >= self::MAX_PAGES ) {
					throw new RuntimeException(
						sprintf(
							// translators: %d is the page safety cap.
							__( 'Upbeat paging reached the %d page safety cap without completing; aborting instead of returning a truncated set.', 'agend-directory-sync' ),
							self::MAX_PAGES
						)
					);
				}

				$url = add_query_arg(
					array(
						'pageCount'  => $settings['page_size'],
						'pageNumber' => $page,
					),
					$endpoint
				);

				try {
					$decoded = json_decode( $api->get_raw_json( $url ), true, 512, JSON_THROW_ON_ERROR );
				} catch ( Throwable $e ) {
					throw new RuntimeException(
						sprintf(
							// translators: 1: page number, 2: the underlying error message.
							__( 'Upbeat directory fetch failed on page %1$d: %2$s', 'agend-directory-sync' ),
							$page,
							$e->getMessage()
						),
						0,
						$e
					);
				}

				$data = is_array( $decoded ) && is_array( $decoded['ResponseData'] ?? null ) ? $decoded['ResponseData'] : null;
				$rows = is_array( $data ) && is_array( $data['Results'] ?? null ) ? $data['Results'] : null;

				if ( null === $rows ) {
					// Only the very first page of a window may legitimately have
					// no Results (an empty directory); after that it is a break in
					// the paging.
					if ( $this->pages_fetched > 0 ) {
						throw new RuntimeException(
							sprintf(
								// translators: %d is the page number.
								__( 'Upbeat page %d had no Results although the previous page reported more records; aborting instead of returning a truncated set.', 'agend-directory-sync' ),
								$page
							)
						);
					}
					break;
				}

				$this->pages_fetched++;
				$results = array_merge( $results, $rows );

				if ( empty( $data['MoreRecords'] ) ) {
					break;
				}

				if ( $settings['max_pages'] > 0 && $this->pages_fetched >= $settings['max_pages'] ) {
					$this->stopped_at_page_limit = true;
					break;
				}

				++$page;
			}

			return array_values(
				array_filter(
					$results,
					static function ( $row ): bool {
						return is_array( $row );
					}
				)
			);
		}

		/**
		 * Upbeat's external_metadata contribution: unchanged from the
		 * pre-US-1.1 hardcoded keys, so existing rows keep the same metadata
		 * shape byte-for-byte (SPEC-DIR-20260731 Decision 2.7, US-1.1
		 * criterion 8).
		 *
		 * @param array<string, mixed>  $contact
		 * @param array<string, string> $core_map
		 *
		 * @return array<string, mixed>
		 */
		public function get_external_metadata( array $contact, array $core_map ): array {
			return array(
				'upbeat_unique_id'     => Agend_Directory_Sync_Listing_Transformer::resolve_source_field( $contact, $core_map, 'external_id' ),
				'upbeat_date_modified' => Agend_Directory_Sync_Listing_Transformer::resolve_source_field( $contact, $core_map, 'date_modified' ),
				'synced_at'            => gmdate( 'c' ),
			);
		}
	}
endif;
