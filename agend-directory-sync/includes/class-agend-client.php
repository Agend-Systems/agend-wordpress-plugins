<?php
/**
 * Agend public API client for the bulk-upsert directory endpoint.
 *
 * Chunks the listings into batches of 100 (the API's hard cap),
 * POSTs each batch sequentially and aggregates the per-row results.
 *
 * On HTTP or transport failures the batch is recorded under
 * `http_errors`; we do NOT retry here. The directory bulk-upsert is
 * idempotent on `external_id`, so a manual rerun is safe and is the
 * simplest recovery for a one-button admin tool.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Agend_Client' ) ) :
	final class Agend_Directory_Sync_Agend_Client {

		public const BULK_UPSERT_PATH = '/v1/directory/listings/bulk-upsert';

		/**
		 * Hard cap per the Agend bulk-upsert brief.
		 */
		public const MAX_BATCH_SIZE = 100;

		/**
		 * Number of per-row error examples to surface in the admin UI.
		 */
		public const MAX_ERROR_EXAMPLES = 10;

		/**
		 * POST the listings in batches and aggregate the results.
		 *
		 * @param array<int, array<string, mixed>> $listings              Transformed listings.
		 * @param string                           $external_source       The `external_source` string sent with each batch.
		 * @param bool                             $auto_publish_approved When true, Agend sets `published_at` on any
		 *                                                                listing in the batch with `status: 'approved'`
		 *                                                                that does not already have one. Required for
		 *                                                                rows to appear on the public directory.
		 *
		 * @return array<string, mixed>
		 *
		 * @throws RuntimeException When the gateway URL or API key are unconfigured.
		 */
		public function send_listings(
			array $listings,
			string $external_source,
			bool $auto_publish_approved = false
		): array {
			$gateway_url = trim( (string) get_option( Agend_Directory_Sync::OPTION_AGEND_GATEWAY_URL, '' ) );
			$api_key     = trim( (string) get_option( Agend_Directory_Sync::OPTION_AGEND_API_KEY, '' ) );

			if ( '' === $gateway_url ) {
				throw new RuntimeException( __( 'Agend gateway URL is not configured.', 'agend-directory-sync' ) );
			}
			if ( '' === $api_key ) {
				throw new RuntimeException( __( 'Agend API key is not configured.', 'agend-directory-sync' ) );
			}

			$url     = rtrim( $gateway_url, '/' ) . self::BULK_UPSERT_PATH;
			$batches = array_chunk( $listings, self::MAX_BATCH_SIZE );

			$summary = array(
				'url'                   => $url,
				'external_source'       => $external_source,
				'auto_publish_approved' => $auto_publish_approved,
				'total_listings'        => count( $listings ),
				'batches'               => count( $batches ),
				'created'               => 0,
				'updated'               => 0,
				'errored'               => 0,
				'error_examples'        => array(),
				'http_errors'           => array(),
			);

			foreach ( $batches as $batch_index => $batch ) {
				$result = $this->send_batch( $url, $api_key, $external_source, $batch, $auto_publish_approved );

				if ( 'ok' !== $result['status'] ) {
					$summary['http_errors'][] = array_merge(
						array( 'batch_index' => $batch_index ),
						$result
					);
					continue;
				}

				foreach ( $result['rows'] as $row ) {
					$row_status = (string) ( $row['status'] ?? '' );
					if ( 'created' === $row_status ) {
						$summary['created']++;
					} elseif ( 'updated' === $row_status ) {
						$summary['updated']++;
					} elseif ( 'error' === $row_status ) {
						$summary['errored']++;
						if ( count( $summary['error_examples'] ) < self::MAX_ERROR_EXAMPLES ) {
							$summary['error_examples'][] = array(
								'batch_index' => $batch_index,
								'external_id' => (string) ( $row['external_id'] ?? '' ),
								'code'        => (string) ( $row['error']['code'] ?? '' ),
								'message'     => (string) ( $row['error']['message'] ?? '' ),
							);
						}
					}
				}
			}

			return $summary;
		}

		/**
		 * POST a single batch and parse the response envelope.
		 *
		 * @param string                           $url
		 * @param string                           $api_key
		 * @param string                           $external_source
		 * @param array<int, array<string, mixed>> $batch
		 * @param bool                             $auto_publish_approved
		 *
		 * @return array{status: string, rows?: array<int, array<string, mixed>>, http_code?: int, body_snippet?: string, message?: string}
		 */
		private function send_batch(
			string $url,
			string $api_key,
			string $external_source,
			array $batch,
			bool $auto_publish_approved
		): array {
			$body = wp_json_encode(
				array(
					'external_source'       => $external_source,
					'listings'              => $batch,
					'auto_publish_approved' => $auto_publish_approved,
				)
			);

			if ( false === $body ) {
				return array(
					'status'  => 'error',
					'message' => 'Failed to JSON-encode the batch payload',
				);
			}

			$response = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'timeout' => 60,
					'headers' => array(
						'X-API-Key'    => $api_key,
						'Content-Type' => 'application/json',
					),
					'body'    => $body,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'status'  => 'error',
					'message' => $response->get_error_message(),
				);
			}

			$code        = (int) wp_remote_retrieve_response_code( $response );
			$raw_body    = (string) wp_remote_retrieve_body( $response );
			$decoded     = json_decode( $raw_body, true );
			$has_envelope = is_array( $decoded ) && array_key_exists( 'success', $decoded );

			if ( 200 !== $code || ! $has_envelope || true !== (bool) $decoded['success'] ) {
				return array(
					'status'       => 'error',
					'http_code'    => $code,
					'body_snippet' => substr( $raw_body, 0, 500 ),
					'message'      => $has_envelope ? (string) ( $decoded['error']['message'] ?? 'Non-success envelope' ) : 'Non-JSON or unexpected response',
				);
			}

			$rows = array();
			if ( isset( $decoded['data']['results'] ) && is_array( $decoded['data']['results'] ) ) {
				$rows = $decoded['data']['results'];
			}

			return array(
				'status' => 'ok',
				'rows'   => $rows,
			);
		}
	}
endif;
