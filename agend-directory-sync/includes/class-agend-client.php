<?php
/**
 * Directory bulk-upsert client.
 *
 * Chunks the listings into batches of 100 (the API's hard cap) and delegates
 * each batch to agend-apps-core's `agend_apps_directory_bulk_upsert_listings()`,
 * which is the single source of truth for the gateway base URL, API key, and
 * request envelope. This plugin never talks to the gateway directly.
 *
 * On HTTP or transport failures the batch is recorded under `http_errors`; we
 * do NOT retry here. The directory bulk-upsert is idempotent on
 * `(external_source, external_id)`, so a manual rerun is safe and is the
 * simplest recovery for a one-button admin tool.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Agend_Client' ) ) :
	final class Agend_Directory_Sync_Agend_Client {

		/**
		 * Hard cap per the Agend bulk-upsert brief.
		 */
		public const MAX_BATCH_SIZE = 100;

		/**
		 * Number of per-row error examples to surface in the admin UI.
		 */
		public const MAX_ERROR_EXAMPLES = 10;

		/**
		 * POST the listings in batches (via agend-apps-core) and aggregate the
		 * results.
		 *
		 * @param array<int, array<string, mixed>> $listings              Transformed listings.
		 * @param string                           $external_source       The `external_source` string sent with each batch.
		 * @param bool                             $auto_publish_approved When true, Agend sets `published_at` on any
		 *                                                                listing in the batch with `status: 'approved'`
		 *                                                                that does not already have one.
		 *
		 * @return array<string, mixed>
		 *
		 * @throws RuntimeException When agend-apps-core is unavailable.
		 */
		public function send_listings(
			array $listings,
			string $external_source,
			bool $auto_publish_approved = false
		): array {
			if ( ! function_exists( 'agend_apps_directory_bulk_upsert_listings' ) ) {
				throw new RuntimeException( __( 'agend-apps-core is not available; cannot reach the Agend gateway.', 'agend-directory-sync' ) );
			}

			$batches = array_chunk( $listings, self::MAX_BATCH_SIZE );

			$summary = array(
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
				$result = $this->send_batch( $external_source, $batch, $auto_publish_approved );

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
		 * Send a single batch via agend-apps-core and parse the result rows.
		 *
		 * @param string                           $external_source
		 * @param array<int, array<string, mixed>> $batch
		 * @param bool                             $auto_publish_approved
		 *
		 * @return array{status: string, rows?: array<int, array<string, mixed>>, message?: string}
		 */
		private function send_batch(
			string $external_source,
			array $batch,
			bool $auto_publish_approved
		): array {
			$response = agend_apps_directory_bulk_upsert_listings(
				$batch,
				$external_source,
				$auto_publish_approved
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'status'  => 'error',
					'message' => $response->get_error_message(),
				);
			}

			// agend-apps-core returns the decoded gateway response. Accept both
			// the full envelope ({ success, data: { results } }) and an
			// already-unwrapped data payload ({ results }).
			$data = is_array( $response ) ? ( $response['data'] ?? $response ) : array();
			$rows = isset( $data['results'] ) && is_array( $data['results'] )
				? $data['results']
				: array();

			return array(
				'status' => 'ok',
				'rows'   => $rows,
			);
		}
	}
endif;
