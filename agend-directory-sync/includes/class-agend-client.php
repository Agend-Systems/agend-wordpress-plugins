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
		 * Cap on the number of validation issues surfaced per failed batch. A
		 * malformed template can fail every field on every row, and nobody reads
		 * a list of a thousand of them; the count of what was left out is kept
		 * instead.
		 */
		public const MAX_ISSUES_PER_BATCH = 20;

		/**
		 * How the gateway applies each listing's locations[]. The sync builds the
		 * full, authoritative set of addresses for every member on each run, so
		 * `replace` is correct: an address removed upstream is removed in Agend.
		 */
		public const LOCATIONS_MODE = 'replace';

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
		 * @return array{status: string, rows?: array<int, array<string, mixed>>, message?: string, status_code?: int, code?: string, issues?: array<int, array<string, mixed>>, issues_omitted?: int}
		 */
		private function send_batch(
			string $external_source,
			array $batch,
			bool $auto_publish_approved
		): array {
			$response = agend_apps_directory_bulk_upsert_listings(
				$batch,
				$external_source,
				$auto_publish_approved,
				self::LOCATIONS_MODE
			);

			if ( is_wp_error( $response ) ) {
				$result = array(
					'status'  => 'error',
					'message' => $response->get_error_message(),
				);

				$described = self::describe_validation_error( $response, count( $batch ) );

				if ( null !== $described['status_code'] ) {
					$result['status_code'] = $described['status_code'];
				}

				if ( null !== $described['code'] ) {
					$result['code'] = $described['code'];
				}

				if ( ! empty( $described['issues'] ) ) {
					$result['issues']  = $described['issues'];
					$result['message'] = self::summarize_issues_message(
						$result['message'],
						$described['issues'],
						$described['issues_omitted']
					);
				}

				if ( $described['issues_omitted'] > 0 ) {
					$result['issues_omitted'] = $described['issues_omitted'];
				}

				return $result;
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

		/**
		 * Read the gateway's batch-size limit from one place, so the batch-index
		 * to run-position arithmetic done elsewhere (e.g. the job's
		 * merge_send_summary()) has a single source when this becomes
		 * configurable.
		 */
		public static function batch_size(): int {
			return self::MAX_BATCH_SIZE;
		}

		/**
		 * Extract a readable description of a batch-upsert failure from the
		 * WP_Error agend-apps-core returns, without echoing any submitted value
		 * back to the operator.
		 *
		 * Supports two gateway `details` shapes for the same 400 response:
		 *
		 * - `fields`: zod's `flatten().fieldErrors`, a flat map of top-level key
		 *   to a list of messages. No record index is available, so every issue
		 *   from this shape has `record: null`.
		 * - `issues`: a per-issue list with a full path (e.g.
		 *   `["listings", 12, "custom_fields", "state"]`), which does carry a
		 *   record index when the path starts with `listings.<int>`.
		 *
		 * Anything else (a non-validation WP_Error, a body with neither shape, a
		 * transport failure with no body at all) degrades to an empty issue list;
		 * the caller falls back to the plain `get_error_message()` in that case.
		 *
		 * @param WP_Error $error      The error agend-apps-core returned.
		 * @param int      $batch_size Number of listings in the batch that was
		 *                             sent, used to keep an out-of-range record
		 *                             index (which should never happen, but this
		 *                             is untrusted response data) from being
		 *                             reported as if it were valid.
		 *
		 * @return array{status_code: int|null, code: string|null, issues: array<int, array{record: int|null, field: string, reason: string}>, issues_omitted: int}
		 */
		public static function describe_validation_error( WP_Error $error, int $batch_size ): array {
			$empty = array(
				'status_code'    => null,
				'code'           => null,
				'issues'         => array(),
				'issues_omitted' => 0,
			);

			$data = $error->get_error_data();

			if ( ! is_array( $data ) ) {
				return $empty;
			}

			$empty['status_code'] = isset( $data['status_code'] ) ? (int) $data['status_code'] : null;

			$body        = is_array( $data['body'] ?? null ) ? $data['body'] : array();
			$error_block = is_array( $body['error'] ?? null ) ? $body['error'] : array();

			$empty['code'] = isset( $error_block['code'] ) ? (string) $error_block['code'] : null;

			$details = is_array( $error_block['details'] ?? null ) ? $error_block['details'] : array();

			$raw_issues = array();

			if ( is_array( $details['issues'] ?? null ) ) {
				foreach ( $details['issues'] as $issue ) {
					if ( ! is_array( $issue ) ) {
						continue;
					}
					$raw_issues[] = self::issue_from_path(
						is_array( $issue['path'] ?? null ) ? $issue['path'] : array(),
						(string) ( $issue['message'] ?? '' ),
						$batch_size
					);
				}
			} elseif ( is_array( $details['fields'] ?? null ) ) {
				foreach ( $details['fields'] as $field => $messages ) {
					foreach ( (array) $messages as $message ) {
						$raw_issues[] = array(
							'record' => null,
							'field'  => (string) $field,
							'reason' => self::sanitize_reason( (string) $message ),
						);
					}
				}
			}

			$empty['issues']         = array_slice( $raw_issues, 0, self::MAX_ISSUES_PER_BATCH );
			$empty['issues_omitted'] = max( 0, count( $raw_issues ) - count( $empty['issues'] ) );

			return $empty;
		}

		/**
		 * Build one issue from a zod-style path plus its message.
		 *
		 * `path[0] === 'listings'` with an in-range integer `path[1]` is a
		 * per-row issue: the record index is that integer and the field is
		 * whatever remains of the path. Anything else (a top-level field such as
		 * `external_source`, or an out-of-range index the gateway should never
		 * send but which is still untrusted input) is reported with no record
		 * and the whole path as the field.
		 *
		 * @param array<int, int|string> $path
		 */
		private static function issue_from_path( array $path, string $message, int $batch_size ): array {
			$record = null;

			if (
				isset( $path[0], $path[1] )
				&& 'listings' === $path[0]
				&& is_int( $path[1] )
				&& $path[1] >= 0
				&& $path[1] < $batch_size
			) {
				$record = $path[1];
				$field  = implode( '.', array_map( 'strval', array_slice( $path, 2 ) ) );
			} else {
				$field = implode( '.', array_map( 'strval', $path ) );
			}

			return array(
				'record' => $record,
				'field'  => $field,
				'reason' => self::sanitize_reason( $message ),
			);
		}

		/**
		 * Strip anything a zod message may echo back from the submitted value
		 * (e.g. "Invalid enum value. Expected 'a' | 'b', received 'Jane Smith'"),
		 * so a validation reason never carries a member's data into a log or an
		 * admin screen. Field names and record indexes, which describe shape
		 * rather than content, are left alone.
		 */
		private static function sanitize_reason( string $message ): string {
			$reason = preg_replace( '/,?\s*received\b.*$/is', '', $message );
			$reason = trim( (string) $reason );

			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $reason, 0, 200 );
			}

			return substr( $reason, 0, 200 );
		}

		/**
		 * Fold the first few issues of a failed batch into the message the
		 * operator sees in the job log and the CLI, instead of the generic
		 * "Invalid request parameters" the gateway sends.
		 *
		 * @param array<int, array{record: int|null, field: string, reason: string}> $issues
		 */
		private static function summarize_issues_message( string $base_message, array $issues, int $issues_omitted ): string {
			$total = count( $issues ) + $issues_omitted;
			$shown = array_slice( $issues, 0, 3 );

			$parts = array_map(
				static function ( array $issue ): string {
					return null !== $issue['record']
						? sprintf( 'record %d (0-based in this batch) %s: %s', $issue['record'], $issue['field'], $issue['reason'] )
						: sprintf( '%s: %s', $issue['field'], $issue['reason'] );
				},
				$shown
			);

			$summary = implode( '; ', $parts );

			if ( $total > count( $shown ) ) {
				$summary .= sprintf( ' and %d more', $total - count( $shown ) );
			}

			return '' !== $summary ? sprintf( '%s: %s', $base_message, $summary ) : $base_message;
		}
	}
endif;
