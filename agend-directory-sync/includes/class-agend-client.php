<?php
/**
 * Directory bulk-upsert client.
 *
 * Chunks the listings into batches (batch_size(), configurable, capped at the
 * API's hard limit of 100) and delegates each batch to agend-apps-core's
 * `agend_apps_directory_bulk_upsert_listings()`, which is the single source of
 * truth for the gateway base URL, API key, and request envelope. This plugin
 * never talks to the gateway directly.
 *
 * On HTTP or transport failures the batch is recorded under `http_errors`; we
 * do NOT retry here. The directory bulk-upsert is idempotent on
 * `(external_source, external_id)`, so a manual rerun is safe and is the
 * simplest recovery for a one-button admin tool. The resumable job
 * (class-sync-job.php) does retry a transport timeout, a few times with
 * backoff, before it too gives up and records the failure here.
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
		 * Default, minimum and maximum for the "listings per request" setting
		 * (Agend_Directory_Sync::OPTION_BATCH_SIZE). The maximum is the hard
		 * gateway cap in MAX_BATCH_SIZE; the default of 25 trades some request
		 * count for headroom under a host's execution-time limit, which the
		 * hard cap of 100 could exceed on a slow connection.
		 */
		public const DEFAULT_BATCH_SIZE = 25;
		public const MIN_BATCH_SIZE     = 1;

		/**
		 * Default, minimum and maximum for the "upload timeout" setting
		 * (Agend_Directory_Sync::OPTION_TIMEOUT_SECONDS), in seconds.
		 */
		public const DEFAULT_TIMEOUT_SECONDS = 60;
		public const MIN_TIMEOUT_SECONDS     = 15;
		public const MAX_TIMEOUT_SECONDS     = 300;

		/**
		 * The number of listings per bulk-upsert request, resolved from
		 * Agend_Directory_Sync::OPTION_BATCH_SIZE and clamped to [1, 100]. The
		 * single place step_fetch()'s chunking, send_listings()'s chunking, and
		 * progress()'s listings-sent estimate all read, so there is exactly one
		 * definition to change if the default or the cap ever move.
		 *
		 * A job stores the value this returned at the moment it started
		 * (Agend_Directory_Sync_Job::start()) rather than calling this again per
		 * step, so a setting changed mid-run cannot desync its batch-index-to
		 * listing-position arithmetic.
		 */
		public static function batch_size(): int {
			$raw   = get_option( Agend_Directory_Sync::OPTION_BATCH_SIZE, null );
			$value = ( null === $raw || '' === $raw ) ? self::DEFAULT_BATCH_SIZE : (int) $raw;

			return max( self::MIN_BATCH_SIZE, min( self::MAX_BATCH_SIZE, $value ) );
		}

		/**
		 * The per-batch gateway request timeout in seconds, resolved from
		 * Agend_Directory_Sync::OPTION_TIMEOUT_SECONDS and clamped to [15, 300].
		 * This is the operator's setting; effective_timeout() further caps it to
		 * what the current request can actually spend.
		 */
		public static function timeout_seconds(): int {
			$raw   = get_option( Agend_Directory_Sync::OPTION_TIMEOUT_SECONDS, null );
			$value = ( null === $raw || '' === $raw ) ? self::DEFAULT_TIMEOUT_SECONDS : (int) $raw;

			return max( self::MIN_TIMEOUT_SECONDS, min( self::MAX_TIMEOUT_SECONDS, $value ) );
		}

		/**
		 * The timeout to actually send with a batch request: the operator's
		 * setting, capped to leave 5 seconds of the current request's execution
		 * budget for everything around the HTTP call (JSON decode, option
		 * writes, the JSON response). Never below 10 seconds, since a lower
		 * value would make the request more likely to fail than to succeed.
		 *
		 * A pure function of its two inputs so it is trivial to test every
		 * combination without a real PHP ini setting.
		 *
		 * @param int $setting            The resolved timeout_seconds() value.
		 * @param int $max_execution_time `ini_get( 'max_execution_time' )` for
		 *                                the current request, 0 meaning
		 *                                unlimited (WP-CLI, or a host with no
		 *                                cap), read AFTER any set_time_limit()
		 *                                call the caller already made.
		 */
		public static function effective_timeout( int $setting, int $max_execution_time ): int {
			if ( 0 === $max_execution_time ) {
				return max( 10, $setting );
			}

			$available = max( 0, $max_execution_time - 5 );

			return max( 10, min( $setting, $available ) );
		}

		/**
		 * POST the listings in batches (via agend-apps-core) and aggregate the
		 * results.
		 *
		 * @param array<int, array<string, mixed>> $listings              Transformed listings.
		 * @param string                           $external_source       The `external_source` string sent with each batch.
		 * @param bool                             $auto_publish_approved When true, Agend sets `published_at` on any
		 *                                                                listing in the batch with `status: 'approved'`
		 *                                                                that does not already have one.
		 * @param int|null                         $batch_size            Listings per request; defaults to batch_size().
		 *                                                                A caller re-sending a job's own pre-chunked
		 *                                                                batch passes the size it was chunked with, so a
		 *                                                                setting changed mid-run cannot split it further.
		 * @param int|null                         $timeout_seconds       Per-request gateway timeout in seconds; null
		 *                                                                leaves agend-apps-core's own default in place.
		 *
		 * @return array<string, mixed>
		 *
		 * @throws RuntimeException When agend-apps-core is unavailable.
		 */
		public function send_listings(
			array $listings,
			string $external_source,
			bool $auto_publish_approved = false,
			?int $batch_size = null,
			?int $timeout_seconds = null
		): array {
			if ( ! function_exists( 'agend_apps_directory_bulk_upsert_listings' ) ) {
				throw new RuntimeException( __( 'agend-apps-core is not available; cannot reach the Agend gateway.', 'agend-directory-sync' ) );
			}

			$batches = array_chunk( $listings, max( 1, $batch_size ?? self::batch_size() ) );

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
				$result = $this->send_batch( $external_source, $batch, $auto_publish_approved, $timeout_seconds );

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
		 * When a timeout is given, it is injected through the
		 * `agend_apps_directory_bulk_upsert_listings_args` filter for the
		 * duration of this one call and removed in a `finally`, the same
		 * pattern agend-apps-core's own
		 * `agend_apps_records_export_reports_authoring_listing()` uses to scope
		 * a one-off request arg through a filter it does not otherwise control.
		 * agend-apps-core is never modified for this: `request()` already
		 * honours `$args['timeout']`.
		 *
		 * @param string                           $external_source
		 * @param array<int, array<string, mixed>> $batch
		 * @param bool                             $auto_publish_approved
		 * @param int|null                         $timeout_seconds
		 *
		 * @return array{status: string, rows?: array<int, array<string, mixed>>, message?: string, status_code?: int, code?: string, issues?: array<int, array<string, mixed>>, issues_omitted?: int}
		 */
		private function send_batch(
			string $external_source,
			array $batch,
			bool $auto_publish_approved,
			?int $timeout_seconds = null
		): array {
			$inject_timeout = null;

			if ( null !== $timeout_seconds ) {
				$inject_timeout = static function ( $args ) use ( $timeout_seconds ): array {
					$args             = is_array( $args ) ? $args : array();
					$args['timeout']  = $timeout_seconds;
					return $args;
				};
				add_filter( 'agend_apps_directory_bulk_upsert_listings_args', $inject_timeout, 20 );
			}

			try {
				$response = agend_apps_directory_bulk_upsert_listings(
					$batch,
					$external_source,
					$auto_publish_approved,
					self::LOCATIONS_MODE
				);
			} finally {
				if ( null !== $inject_timeout ) {
					remove_filter( 'agend_apps_directory_bulk_upsert_listings_args', $inject_timeout, 20 );
				}
			}

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
