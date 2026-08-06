<?php
/**
 * Upbeat source: the membership directory contacts endpoint.
 *
 * Delegates auth, base URI resolution, pagination and the Upbeat response
 * envelope handling to the kiosk plugin's API class. This is intentionally
 * thin: it adds a single endpoint and exposes the parsed Results array.
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
			if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
				return __( 'Iugo Membership Kiosk plugin is not active.', 'agend-directory-sync' );
			}
			return '';
		}

		/**
		 * Fetch every membership directory contact, paginating via the kiosk
		 * API helper. Each result is an associative array matching the Upbeat
		 * response shape.
		 *
		 * @return array<int, array<string, mixed>>
		 *
		 * @throws RuntimeException When the kiosk API class is unavailable or the request fails.
		 */
		public function fetch_all(): array {
			if ( ! $this->is_available() ) {
				throw new RuntimeException( $this->get_unavailable_reason() );
			}

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

			$api = Iugo_Membership_Kiosk_API::instance();

			try {
				$results = $api->get_all_decoded_json_results( $endpoint );
			} catch ( Exception $e ) {
				throw new RuntimeException(
					sprintf(
						// translators: %s is the underlying error message.
						__( 'Upbeat directory fetch failed: %s', 'agend-directory-sync' ),
						$e->getMessage()
					),
					0,
					$e
				);
			}

			// The kiosk helper returns objects (stdClass). Normalise to arrays
			// so the admin page can render them with json_encode predictably
			// and downstream transform code can rely on array access.
			return array_map(
				static function ( $row ) {
					return json_decode( wp_json_encode( $row ), true );
				},
				$results
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
