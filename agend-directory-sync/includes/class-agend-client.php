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
		 * (Agend_Directory_Sync::OPTION_TIMEOUT_SECONDS), in seconds. The default
		 * of 45 is chosen against STEP_REQUEST_BUDGET_SECONDS below, not against
		 * the maximum: a browser step's own budget caps it there regardless, so
		 * 45 is the largest default that still leaves margin inside a typical
		 * 60-second host/proxy request limit (Kinsta and others). WP-CLI applies
		 * the setting in full, so the 300-second maximum is still meaningful
		 * there.
		 */
		public const DEFAULT_TIMEOUT_SECONDS = 45;
		public const MIN_TIMEOUT_SECONDS     = 15;
		public const MAX_TIMEOUT_SECONDS     = 300;

		/**
		 * Fixed budget for one AJAX step request (handle_step()), independent
		 * of `max_execution_time`: on Linux, time blocked on a network read
		 * does not count against max_execution_time, so a host or proxy can
		 * still cut the request off well before PHP's own limit would. Kinsta
		 * and many others cut at 60 seconds; 50 leaves margin for everything
		 * around the gateway call itself (fetching the option, JSON encode/
		 * decode, the AJAX response).
		 */
		public const STEP_REQUEST_BUDGET_SECONDS = 50;

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
		 * The timeout to actually send with a batch request.
		 *
		 * `$context = 'cli'` (WP-CLI, or a future scheduled run with no request
		 * to protect): the operator's setting applies in full, floored at 10.
		 *
		 * `$context = 'web'` (the AJAX stepper, the default): capped against
		 * TWO independent ceilings, both with a 5-second margin for everything
		 * around the HTTP call itself (JSON decode, option writes, the AJAX
		 * response) --
		 *   - STEP_REQUEST_BUDGET_SECONDS, always, because `max_execution_time`
		 *     alone is not trustworthy: on Linux it does not count time spent
		 *     blocked on a network read, so PHP's own limit can be far more
		 *     generous than what the host or an intermediate proxy actually
		 *     allows the request to run for.
		 *   - `$max_execution_time`, when it is not 0 (unlimited).
		 * Never below 10 seconds either way, since a lower value would make the
		 * request more likely to fail outright than to succeed.
		 *
		 * A pure function of its inputs so it is trivial to test every
		 * combination without a real PHP ini setting.
		 *
		 * @param int    $setting            The resolved timeout_seconds() value.
		 * @param int    $max_execution_time `ini_get( 'max_execution_time' )` for
		 *                                   the current request, 0 meaning
		 *                                   unlimited, read AFTER any
		 *                                   set_time_limit() call the caller
		 *                                   already made. Ignored for 'cli'.
		 * @param string $context            'web' or 'cli'.
		 */
		public static function effective_timeout( int $setting, int $max_execution_time, string $context = 'web' ): int {
			if ( 'cli' === $context ) {
				return max( 10, $setting );
			}

			$limit = self::STEP_REQUEST_BUDGET_SECONDS - 5;

			if ( $max_execution_time > 0 ) {
				$limit = min( $limit, max( 0, $max_execution_time - 5 ) );
			}

			return max( 10, min( $setting, $limit ) );
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
								'message'     => self::sanitize_text( (string) ( $row['error']['message'] ?? '' ) ),
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
		 * Two request args are injected through the
		 * `agend_apps_directory_bulk_upsert_listings_args` filter for the
		 * duration of this one call and removed in a `finally`, the same
		 * pattern agend-apps-core's own
		 * `agend_apps_records_export_reports_authoring_listing()` uses to scope
		 * a one-off request arg through a filter it does not otherwise control
		 * (agend-apps-core is never modified for either), both from ONE
		 * callback so only one add_filter()/remove_filter() pair is needed:
		 *
		 * - `timeout`, when given; `request()` already honours it.
		 * - `unattended => true`, always: this is a server-to-server upload
		 *   driven by the admin (or WP-CLI), never a member's own action, so it
		 *   must never carry a member bearer -- API-key auth only.
		 *
		 * A second, temporary hook (`http_api_debug`, a real WP core action
		 * fired right after the HTTP call completes) captures the response's
		 * actual HTTP status for the one gateway error that carries none of
		 * its own: `agend_apps_invalid_response`, which is what an edge or
		 * proxy's non-JSON error page (an HTML 504, most commonly) becomes.
		 * Without it, is_retryable_failure() would have no status to look at
		 * for that case at all.
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
			$inject_args = static function ( $args ) use ( $timeout_seconds ): array {
				$args               = is_array( $args ) ? $args : array();
				$args['unattended'] = true;
				if ( null !== $timeout_seconds ) {
					$args['timeout'] = $timeout_seconds;
				}
				return $args;
			};
			add_filter( 'agend_apps_directory_bulk_upsert_listings_args', $inject_args, 20 );

			$captured_status = null;
			$needle          = '/directory/listings/bulk-upsert';
			$capture_status  = static function ( $response, $context, $class, $parsed_args, $url ) use ( &$captured_status, $needle ): void {
				if ( is_wp_error( $response ) || ! is_string( $url ) ) {
					return;
				}
				if ( $needle !== substr( $url, -strlen( $needle ) ) ) {
					return;
				}
				$captured_status = (int) wp_remote_retrieve_response_code( $response );
			};
			add_action( 'http_api_debug', $capture_status, 10, 5 );

			try {
				$response = agend_apps_directory_bulk_upsert_listings(
					$batch,
					$external_source,
					$auto_publish_approved,
					self::LOCATIONS_MODE
				);
			} finally {
				remove_filter( 'agend_apps_directory_bulk_upsert_listings_args', $inject_args, 20 );
				remove_action( 'http_api_debug', $capture_status, 10 );
			}

			if ( is_wp_error( $response ) ) {
				$result = array(
					'status'    => 'error',
					'message'   => self::sanitize_text( $response->get_error_message() ),
					'retryable' => self::is_retryable_failure( $response, $captured_status ),
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
							'reason' => self::sanitize_text( (string) $message ),
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
				'reason' => self::sanitize_text( $message ),
			);
		}

		/**
		 * One privacy scrub applied to every message this class stores or
		 * returns, whatever the error type: a batch-level failure message and
		 * a per-issue reason alike, so neither ever carries a member's data
		 * into a log, the admin screen, or the raw JSON block (sanitised here,
		 * before storage, not only where either is rendered).
		 *
		 * - Strips a zod-style "received ..." tail (e.g. "Invalid enum value.
		 *   Expected 'a' | 'b', received 'Jane Smith'").
		 * - Blanks out every quoted literal, single or double, one character
		 *   or more -- a value the gateway may have echoed back is exactly as
		 *   likely inside a plain message as inside a "received" tail, and
		 *   there is no reliable way to tell a submitted value apart from a
		 *   literal that is simply part of the message (e.g. an allowed enum
		 *   option), so every quoted literal is treated as unsafe.
		 * - Redacts an email address anywhere in the message.
		 * - Caps the result at 300 characters.
		 *
		 * Field names, error codes and record/position numbers, which
		 * describe shape rather than content, are never passed through this;
		 * they are kept as-is wherever they are used.
		 */
		private static function sanitize_text( string $message ): string {
			$text = (string) preg_replace( '/,?\s*received\b.*$/is', '', $message );
			$text = (string) preg_replace( '/"[^"]+"|\'[^\']+\'/', "'\u{2026}'", $text );
			$text = (string) preg_replace(
				'/[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+/',
				'[email]',
				$text
			);
			$text = trim( $text );

			return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 300 ) : substr( $text, 0, 300 );
		}

		/**
		 * Fold a failed batch's issue count and field names into the message
		 * the operator sees in the job log and the CLI, instead of the generic
		 * "Invalid request parameters" the gateway sends: e.g. "Invalid request
		 * parameters (3 issues: listings, external_source)".
		 *
		 * Deliberately carries no record index: at this point (inside one
		 * gateway call) a record is only known relative to this one batch, and
		 * the run-wide listing_position a record-level detail should use is not
		 * computed until the job or the runner stamps it afterwards (see
		 * stamp_issue_position()). Putting a "record N (0-based in this batch)"
		 * figure in the batch-level message duplicated that detail with a
		 * different, more confusing number; the per-issue lines are the only
		 * place a record is now named.
		 *
		 * @param array<int, array{record: int|null, field: string, reason: string}> $issues
		 */
		private static function summarize_issues_message( string $base_message, array $issues, int $issues_omitted ): string {
			$total = count( $issues ) + $issues_omitted;

			if ( 0 === $total ) {
				return $base_message;
			}

			$fields = array();
			foreach ( $issues as $issue ) {
				$field = (string) ( $issue['field'] ?? '' );
				if ( '' !== $field && ! in_array( $field, $fields, true ) ) {
					$fields[] = $field;
				}
				if ( count( $fields ) >= 3 ) {
					break;
				}
			}

			return 1 === $total
				? sprintf(
					/* translators: 1: base gateway message, 2: the one issue's field name. */
					__( '%1$s (1 issue: %2$s)', 'agend-directory-sync' ),
					$base_message,
					implode( ', ', $fields )
				)
				: sprintf(
					/* translators: 1: base gateway message, 2: number of issues, 3: comma-separated field names (first few, deduplicated). */
					__( '%1$s (%2$d issues: %3$s)', 'agend-directory-sync' ),
					$base_message,
					$total,
					implode( ', ', $fields )
				);
		}

		/**
		 * Whether a batch's failure is safe to retry given the bulk-upsert's
		 * idempotency on (external_source, external_id):
		 *
		 * - `http_request_failed`: WP core's own error code for a transport
		 *   failure (cURL 7, 28, 52, 56, and the like) that never reached the
		 *   gateway at all, whatever status a later look might report.
		 * - A real HTTP status of 502, 503 or 504: the gateway (or something in
		 *   front of it) answered, but with an upstream/availability failure a
		 *   retry can plausibly succeed against.
		 *
		 * The status comes from the error's own `status_code` when
		 * agend-apps-core set one (the normal `agend_api_error` case); for
		 * `agend_apps_invalid_response`, which carries none because the body
		 * could not be decoded as JSON (what an edge/proxy's non-JSON error
		 * page -- an HTML 504, most commonly -- becomes), $captured_status is
		 * used instead: the real status send_batch() captured via WP core's
		 * `http_api_debug` action, since agend-apps-core itself never exposes
		 * one for that case. No status at all (neither the error's own nor a
		 * captured one) is NOT retried: an unknown failure is not assumed to
		 * be a retryable one.
		 *
		 * Every other status, 4xx and 500 included, is a real answer retrying
		 * cannot change, so this returns false for those.
		 *
		 * @param WP_Error $error
		 * @param int|null $captured_status See send_batch()'s http_api_debug capture.
		 */
		public static function is_retryable_failure( WP_Error $error, ?int $captured_status = null ): bool {
			if ( 'http_request_failed' === $error->get_error_code() ) {
				return true;
			}

			$data   = $error->get_error_data();
			$status = ( is_array( $data ) && isset( $data['status_code'] ) && null !== $data['status_code'] )
				? (int) $data['status_code']
				: $captured_status;

			if ( null === $status ) {
				return false;
			}

			return in_array( $status, array( 502, 503, 504 ), true );
		}

		/**
		 * Convert one issue's in-batch record index into a run-wide 1-based
		 * listing position, and attach the external_id of the listing it
		 * refers to when the batch it came from is available.
		 *
		 * Shared by the resumable job (Agend_Directory_Sync_Job, which sends
		 * one batch per gateway call and restamps its always-0 batch index to
		 * the job's real one) and the synchronous runner/CLI path
		 * (Agend_Directory_Sync_Runner, whose own batch index from
		 * send_listings() is already run-wide since it makes one call for the
		 * whole listings set), so a run-wide position and an external_id mean
		 * the same thing and are computed the same way whichever path produced
		 * them.
		 *
		 * @param array<string, mixed>             $issue
		 * @param array<int, array<string, mixed>> $batch      The listings sent in the batch this issue's
		 *                                                     record index is relative to.
		 *
		 * @return array<string, mixed>
		 */
		public static function stamp_issue_position( array $issue, int $batch_index, array $batch, int $batch_size ): array {
			$record = $issue['record'] ?? null;

			if ( null === $record ) {
				$issue['listing_position'] = null;
				return $issue;
			}

			$record = (int) $record;

			$issue['listing_position'] = $batch_index * $batch_size + $record + 1;

			if ( isset( $batch[ $record ]['external_id'] ) && '' !== $batch[ $record ]['external_id'] ) {
				$issue['external_id'] = (string) $batch[ $record ]['external_id'];
			}

			return $issue;
		}

		/**
		 * One issue as a plain-text, operator-facing line: "Listing #M
		 * (external id X), field F: reason", "Listing #M, field F: reason" when
		 * no external_id is known, or "Field F: reason" when the issue carries
		 * no run-wide listing_position at all (a batch-level failure with no
		 * per-row detail, e.g. a rejected external_source).
		 *
		 * Shared by the CLI's own log lines, and directly unit-testable without
		 * WP_CLI (that class is only defined under an actual WP-CLI process).
		 * The admin result panel's Agend_Directory_Sync_Admin_Page keeps its
		 * own translated version of the same shape rather than calling this,
		 * since a CLI log line is not translated but a page rendered for an
		 * operator's browser should be.
		 *
		 * @param array<string, mixed> $issue
		 */
		public static function format_issue_line( array $issue ): string {
			$field       = (string) ( $issue['field'] ?? '' );
			$reason      = (string) ( $issue['reason'] ?? '' );
			$position    = $issue['listing_position'] ?? null;
			$external_id = (string) ( $issue['external_id'] ?? '' );

			if ( null === $position ) {
				return sprintf( 'Field %s: %s', $field, $reason );
			}

			if ( '' !== $external_id ) {
				return sprintf( 'Listing #%d (external id %s), field %s: %s', (int) $position, $external_id, $field, $reason );
			}

			return sprintf( 'Listing #%d, field %s: %s', (int) $position, $field, $reason );
		}
	}
endif;
