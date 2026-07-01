<?php
/**
 * Upbeat client wrapper for the membership directory contacts endpoint.
 *
 * Delegates auth, base URI resolution, pagination and the Upbeat response
 * envelope handling to the kiosk plugin's API class. This is intentionally
 * thin: it adds a single endpoint and exposes the parsed Results array.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Upbeat_Client' ) ) :
	final class Agend_Directory_Sync_Upbeat_Client {

		/**
		 * Default Upbeat endpoint path. Filterable so a client can override
		 * without editing this file.
		 */
		public const DEFAULT_ENDPOINT = 'membershipDirectoryContacts';

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
			if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
				throw new RuntimeException( __( 'Iugo Membership Kiosk plugin is not active.', 'agend-directory-sync' ) );
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
	}
endif;
