<?php
/**
 * Resumable send job: the same pipeline as Agend_Directory_Sync_Runner, split
 * into steps small enough that no single HTTP request outlives the web server's
 * patience.
 *
 * The synchronous runner does fetch, transform and every upload batch inside one
 * request. That is correct under WP-CLI, which has no request timeout, and it is
 * what the CLI still calls. In a browser it fails at scale in a way that looks
 * worse than it is: the web server returns a 504 while PHP carries on to
 * completion behind it (the admin handler sets `ignore_user_abort`), so the sync
 * finishes but the operator sees an error and no report. The natural response is
 * to press the button again, which starts a second concurrent run.
 *
 * A job splits that work: one step fetches and transforms, then one step per
 * upload batch. Each step is its own short request, so the page never waits long
 * enough to be cut off, and progress is reportable between steps. State lives in
 * options rather than the session, so closing the tab pauses the run instead of
 * losing it.
 *
 * The transformed listings are stored one option per batch, not one option for
 * the whole payload. A single option holding thousands of listings would be read
 * and rewritten in full on every step, and any option this large must never be
 * autoloaded — every batch option is written with autoload off for that reason.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Job' ) ) :
	final class Agend_Directory_Sync_Job {

		/**
		 * The single in-flight job. One at a time is deliberate: two concurrent
		 * runs of the same directory would race each other's upserts to no
		 * benefit.
		 */
		public const OPTION_JOB = 'agend_directory_sync_job';

		/**
		 * Prefix for the per-batch listing payloads, completed by the job id and
		 * the batch index.
		 */
		public const OPTION_BATCH_PREFIX = 'agend_directory_sync_job_batch_';

		/**
		 * Held for the duration of a step so two browser tabs cannot advance the
		 * same job at once. Written with add_option(), whose uniqueness is
		 * enforced by the database, because get-then-set is not atomic and the
		 * failure it allows is a batch sent twice or skipped.
		 */
		public const OPTION_LOCK = 'agend_directory_sync_job_lock';

		/**
		 * How long a held lock is honoured before it is treated as abandoned. A
		 * step that dies mid-flight (fatal, killed worker) must not strand the
		 * job forever, and no step legitimately runs this long.
		 */
		public const LOCK_TTL = 300;

		public const STAGE_PENDING   = 'pending';
		public const STAGE_SENDING   = 'sending';
		public const STAGE_DONE      = 'done';
		public const STAGE_FAILED    = 'failed';
		public const STAGE_CANCELLED = 'cancelled';

		/**
		 * Total attempts a batch gets before its timeout is finally recorded as a
		 * failure: the first send plus two retries.
		 */
		public const MAX_SEND_ATTEMPTS = 3;

		/**
		 * Backoff before retry N+1, keyed by the number of attempts already
		 * failed (1 or 2, since MAX_SEND_ATTEMPTS is 3).
		 */
		private const RETRY_BACKOFF_SECONDS = array(
			1 => 5,
			2 => 15,
		);

		/**
		 * Default step budget when no caller-supplied max_execution_time is
		 * given (only the test suite calls step() this way; the AJAX
		 * controller always passes ini_get('max_execution_time')).
		 */
		private const DEFAULT_MAX_EXECUTION_TIME = 30;

		/**
		 * The in-flight job, or null when there is none.
		 *
		 * @return array<string, mixed>|null
		 */
		public static function current(): ?array {
			$job = get_option( self::OPTION_JOB, null );

			return is_array( $job ) && isset( $job['id'] ) ? $job : null;
		}

		/**
		 * Re-reads the job bypassing any copy already cached in this
		 * request's object cache. A value fetched once (e.g. step()'s own
		 * pre-lock read of current()) stays pinned there for the rest of the
		 * request on a real persistent object cache (the default WP_Object_Cache
		 * behaviour), regardless of what a concurrent request has since
		 * written to the shared/database copy -- only an explicit
		 * wp_cache_delete() forces the next read to go back to the source of
		 * truth. Used wherever a step is about to act on, or write over, the
		 * job: right after the step lock is granted, and again immediately
		 * before every write a step makes, since cancel() deliberately does
		 * not take that lock (an operator's Cancel click is not made to wait
		 * behind a slow gateway request) and so can race a step in flight.
		 *
		 * @return array<string, mixed>|null
		 */
		private static function fresh_job(): ?array {
			wp_cache_delete( self::OPTION_JOB, 'options' );
			wp_cache_delete( 'notoptions', 'options' );

			return self::current();
		}

		/**
		 * Create a job. Does no network work: the first step does the fetching,
		 * so the request that presses the button returns immediately.
		 *
		 * @return array<string, mixed>
		 *
		 * @throws RuntimeException When a job is already in flight, or the active
		 *                          source cannot run.
		 */
		public static function start( int $max_records, int $user_id ): array {
			$existing = self::current();

			if ( null !== $existing && self::is_active( $existing ) ) {
				throw new RuntimeException( __( 'A sync is already running. Wait for it to finish, or cancel it first.', 'agend-directory-sync' ) );
			}

			$source = Agend_Directory_Sync_Source_Registry::active();

			if ( ! $source->is_available() ) {
				throw new RuntimeException( $source->get_unavailable_reason() );
			}

			self::discard_batches( $existing );

			$job = array(
				'id'              => uniqid( 'dsj_', true ),
				'user_id'         => $user_id,
				'stage'           => self::STAGE_PENDING,
				'created_at'      => time(),
				'updated_at'      => time(),
				'max_records'     => $max_records,
				'source'          => $source->get_key(),
				'external_source' => Agend_Directory_Sync_Runner::resolve_external_source(),
				'auto_publish'    => Agend_Directory_Sync_Runner::resolve_auto_publish_approved(),
				// Fixed for the life of the job (SPEC: a setting changed
				// mid-run must not desync batch-index-to-listing-position
				// arithmetic already computed against earlier batches).
				'batch_size'      => Agend_Directory_Sync_Agend_Client::batch_size(),
				'batch_count'     => 0,
				'batch_cursor'    => 0,
				'batch_attempts'  => 0,
				'retry_after'     => 0,
				'listing_count'   => 0,
				'transform'       => array(),
				'send'            => self::empty_send_summary(),
				'message'         => '',
			);

			self::save( $job );

			return $job;
		}

		/**
		 * Advance the job by one step and return the new state.
		 *
		 * A step that throws marks the job failed rather than letting the
		 * exception reach the browser: a half-finished sync the operator can see
		 * and retry beats a stack trace and no record of how far it got.
		 *
		 * @param int|null $max_execution_time `ini_get( 'max_execution_time' )`
		 *                                     for the current request, read by
		 *                                     the caller AFTER its own
		 *                                     set_time_limit() call, 0 meaning
		 *                                     unlimited. Null (the WP-CLI /
		 *                                     test-suite case) falls back to a
		 *                                     conservative default; step_fetch()
		 *                                     ignores it, only the upload step
		 *                                     uses it to size the gateway
		 *                                     request's own timeout.
		 *
		 * @return array<string, mixed> The job state, with `busy` set when
		 *                              another request holds the lock.
		 */
		public static function step( ?int $max_execution_time = null ): array {
			$job = self::current();

			if ( null === $job ) {
				return array( 'stage' => self::STAGE_DONE, 'missing' => true );
			}

			if ( ! self::is_active( $job ) ) {
				return $job;
			}

			// A retry that is not due yet is a cheap no-op: no lock, no gateway
			// call, just the unchanged job (progress() derives waiting_seconds
			// from it so the page can back off on its own). This is only an
			// early exit, not the source of truth for the decision -- see the
			// re-read below, which checks the same thing again against
			// whatever is actually stored once the lock is held.
			if ( self::STAGE_SENDING === $job['stage'] && (int) ( $job['retry_after'] ?? 0 ) > time() ) {
				return $job;
			}

			/**
			 * Fires with the job as read at the top of step(), immediately
			 * before the lock is requested. Test seam only: it lets a test
			 * simulate another request's write landing in the gap between this
			 * read and the lock being granted (id est: acquiring the lock does
			 * not itself refresh $job), so the re-read immediately after
			 * acquire_lock() below can be asserted against. No production code
			 * hooks this.
			 *
			 * @param array<string, mixed> $job
			 */
			do_action( 'agend_directory_sync_job_before_lock', $job );

			if ( ! self::acquire_lock() ) {
				$job['busy'] = true;
				return $job;
			}

			try {
				// Re-read now that the lock is held, bypassing the cache
				// (fresh_job()): another request may have advanced, cancelled,
				// or restarted this job in the gap between the read above and
				// the lock being granted (acquire_lock() only serialises steps
				// against each other, it does not itself see or wait on a
				// concurrent write, and a cached copy of the pre-lock read
				// would keep showing that same stale state even after this
				// re-read otherwise). Acting on the pre-lock copy here would
				// redo a step already taken, or step a job that no longer
				// exists.
				$fresh = self::fresh_job();

				if (
					null === $fresh
					|| $fresh['id'] !== $job['id']
					|| ! self::is_active( $fresh )
					|| ( self::STAGE_SENDING === $fresh['stage'] && (int) ( $fresh['retry_after'] ?? 0 ) > time() )
				) {
					return $fresh ?? $job;
				}

				// From here on $job IS the re-read copy: if step_fetch() or
				// step_send() throws before returning (a fatal mid-call, or an
				// uncaught exception such as
				// Agend_Directory_Sync_Agend_Client::send_listings()'s
				// "agend-apps-core is not available"), the catch block below
				// marks and saves THIS state, not the stale pre-lock one --
				// reassigning $job only via step_fetch()/step_send()'s return
				// value would leave it unchanged (still the pre-lock copy) for
				// exactly the call that throws, since PHP never completes the
				// assignment when the right-hand side throws.
				$job = $fresh;

				$job = self::STAGE_PENDING === $job['stage']
					? self::step_fetch( $job )
					: self::step_send( $job, $max_execution_time ?? self::DEFAULT_MAX_EXECUTION_TIME );
			} catch ( Throwable $e ) {
				$job['stage']   = self::STAGE_FAILED;
				$job['message'] = $e->getMessage();
				self::save( $job );
				self::write_result( $job );
				self::discard_batches( $job );
			} finally {
				// Covers step_fetch()/step_send() throwing for any reason,
				// including Agend_Directory_Sync_Agend_Client::send_listings()
				// (e.g. "agend-apps-core is not available"): the lock is
				// always released here, so a later step is never left waiting
				// out LOCK_TTL for a request that already ended.
				self::release_lock();
			}

			return $job;
		}

		/**
		 * Abandon the job and drop its stored payload. Batches already uploaded
		 * stay uploaded: the bulk-upsert is idempotent, so a cancelled run is a
		 * partial sync that a later run completes, not something to undo.
		 */
		public static function cancel(): void {
			$job = self::current();

			if ( null === $job ) {
				return;
			}

			self::discard_batches( $job );

			$job['stage']      = self::STAGE_CANCELLED;
			$job['updated_at'] = time();

			self::save( $job );
		}

		/**
		 * Forget a finished job so the page stops offering to resume it.
		 */
		public static function clear(): void {
			$job = self::current();

			if ( null !== $job ) {
				self::discard_batches( $job );
			}

			delete_option( self::OPTION_JOB );
			delete_option( self::OPTION_LOCK );
		}

		public static function is_active( array $job ): bool {
			return in_array(
				(string) ( $job['stage'] ?? '' ),
				array( self::STAGE_PENDING, self::STAGE_SENDING ),
				true
			);
		}

		/**
		 * The batch size a job was actually split with.
		 *
		 * start() has stored `batch_size` on every job since it was
		 * introduced, but a job created by an older version of this plugin (or
		 * one resumed across an upgrade) has no such key, and it was chunked
		 * with the hardcoded 100 every pre-0.8.1 release used -- not today's
		 * batch_size() setting, whose default (25) is a different number. Any
		 * other missing or invalid value is treated the same way: falling back
		 * to the CURRENT batch_size() setting here would silently change the
		 * arithmetic partway through resuming an old job (a mismatch between
		 * how many listings are actually in each stored batch option and what
		 * this returns), which is exactly the bug this method exists to avoid.
		 *
		 * @param array<string, mixed> $job
		 */
		public static function job_batch_size( array $job ): int {
			$value = $job['batch_size'] ?? null;

			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}

			return Agend_Directory_Sync_Agend_Client::MAX_BATCH_SIZE;
		}

		/**
		 * The numbers the progress panel renders, derived rather than stored so
		 * they cannot drift from the job they describe.
		 *
		 * @param array<string, mixed> $job
		 *
		 * @return array<string, mixed>
		 */
		public static function progress( array $job ): array {
			$batches = (int) ( $job['batch_count'] ?? 0 );
			$done    = (int) ( $job['batch_cursor'] ?? 0 );
			$stage   = (string) ( $job['stage'] ?? '' );

			// Fetching has no batch count yet, so it reports as indeterminate
			// rather than as 0%, which reads as "stuck".
			$percent = null;
			if ( $batches > 0 ) {
				$percent = (int) floor( ( $done / $batches ) * 100 );
			} elseif ( self::STAGE_DONE === $stage ) {
				$percent = 100;
			}

			// The job's own batch_size, fixed at start() -- not the current
			// setting, which may have changed since (see start()'s comment).
			$batch_size = self::job_batch_size( $job );
			$sent       = min( $done * $batch_size, (int) ( $job['listing_count'] ?? 0 ) );

			$retry_after     = (int) ( $job['retry_after'] ?? 0 );
			$waiting_seconds = $retry_after > time() ? $retry_after - time() : null;

			return array(
				'stage'              => $stage,
				'active'             => self::is_active( $job ),
				'percent'            => $percent,
				'batches_total'      => $batches,
				'batches_done'       => $done,
				'listing_count'      => (int) ( $job['listing_count'] ?? 0 ),
				'listings_sent'      => $sent,
				'created'            => (int) ( $job['send']['created'] ?? 0 ),
				'updated'            => (int) ( $job['send']['updated'] ?? 0 ),
				'errored'            => (int) ( $job['send']['errored'] ?? 0 ),
				// Cheap counters for the live panel; the full per-path/per-code
				// breakdown is only in the finished result (render_result()'s
				// options_created / warning_examples).
				'options_created'    => self::sum_option_created_counts( (array) ( $job['send']['options_created'] ?? array() ) ),
				'warnings'           => (int) ( $job['send']['warnings'] ?? 0 ),
				'http_errors'        => count( (array) ( $job['send']['http_errors'] ?? array() ) ),
				'http_error_details' => self::progress_http_error_details( (array) ( $job['send']['http_errors'] ?? array() ) ),
				// Set only while the current batch is between a failed attempt
				// and its next retry; the JS stepper uses these two to show
				// "Batch N timed out, retrying in Xs (attempt Y of Z)" and to
				// delay its next step call instead of hammering the lock.
				'waiting_seconds'    => $waiting_seconds,
				'batch_attempts'     => (int) ( $job['batch_attempts'] ?? 0 ),
				'max_send_attempts'  => self::MAX_SEND_ATTEMPTS,
				'message'            => (string) ( $job['message'] ?? '' ),
				'source'             => (string) ( $job['source'] ?? '' ),
			);
		}

		/**
		 * Total rows across every `options_created` path, for the live
		 * progress panel's cheap running count; the per-path breakdown and
		 * example values are only in the finished result.
		 *
		 * @param array<string, mixed> $options_created
		 */
		private static function sum_option_created_counts( array $options_created ): int {
			$total = 0;
			foreach ( $options_created as $data ) {
				$total += is_array( $data ) ? (int) ( $data['count'] ?? 0 ) : 0;
			}
			return $total;
		}

		/**
		 * Reduce the running job's `http_errors` to the handful the live
		 * progress panel shows while a run is in flight; the full list is still
		 * on the finished result page. Kept to the most recent few batches so a
		 * long run with many failures doesn't grow the step response without
		 * bound.
		 *
		 * @param array<int, array<string, mixed>> $http_errors
		 *
		 * @return array<int, array{batch: int, message: string, issues: array<int, array<string, mixed>>}>
		 */
		private static function progress_http_error_details( array $http_errors ): array {
			$recent = array_slice( $http_errors, -5 );

			return array_map(
				static function ( array $http_error ): array {
					$issues = is_array( $http_error['issues'] ?? null ) ? $http_error['issues'] : array();

					return array(
						'batch'   => (int) ( $http_error['batch_index'] ?? 0 ) + 1,
						'message' => (string) ( $http_error['message'] ?? '' ),
						'issues'  => array_map(
							static function ( $issue ): array {
								$issue = is_array( $issue ) ? $issue : array();

								return array(
									'field'            => (string) ( $issue['field'] ?? '' ),
									'reason'           => (string) ( $issue['reason'] ?? '' ),
									'listing_position' => $issue['listing_position'] ?? null,
									'external_id'      => (string) ( $issue['external_id'] ?? '' ),
								);
							},
							$issues
						),
					);
				},
				$recent
			);
		}

		/**
		 * Fetch and transform, then split the listings into per-batch options.
		 *
		 * Delegates to the runner in dry-run mode, so the fetch, the transform,
		 * the skip accounting and the source-specific paging summary are the same
		 * code the CLI and the preview button use. Only the upload is re-staged
		 * here.
		 *
		 * @param array<string, mixed> $job
		 *
		 * @return array<string, mixed>
		 */
		private static function step_fetch( array $job ): array {
			$result = Agend_Directory_Sync_Runner::run( (int) $job['max_records'], true, 'web' );

			$listings = is_array( $result['listings'] ?? null ) ? $result['listings'] : array();
			unset( $result['listings'] );

			$result['kind'] = 'send';
			$job['transform'] = $result;
			$job['listing_count'] = count( $listings );

			$batches = array_chunk( $listings, self::job_batch_size( $job ) );

			foreach ( $batches as $index => $batch ) {
				update_option( self::batch_option( (string) $job['id'], (int) $index ), $batch, false );
			}

			$job['batch_count']       = count( $batches );
			$job['batch_cursor']      = 0;
			$job['send']['batches']   = count( $batches );
			$job['stage']             = empty( $batches ) ? self::STAGE_DONE : self::STAGE_SENDING;

			$job_id = (string) $job['id'];

			[ $saved, $job ] = self::save_unless_superseded( $job );

			if ( ! $saved ) {
				// Cancelled, cleared, or replaced by a new job while this
				// fetch was in flight: the batch options just written above
				// belong to a job that is no longer the current one, so they
				// are cleaned up here rather than left to leak.
				foreach ( array_keys( $batches ) as $index ) {
					delete_option( self::batch_option( $job_id, (int) $index ) );
				}
				return $job;
			}

			if ( self::STAGE_DONE === $job['stage'] ) {
				self::write_result( $job );
			}

			return $job;
		}

		/**
		 * Upload the batch at the cursor.
		 *
		 * The batch goes through Agend_Directory_Sync_Agend_Client unchanged: it
		 * already chunks to the API's cap, so handing it exactly one chunk gives
		 * one request and a summary for it, and the client keeps its single
		 * responsibility for talking to the gateway.
		 *
		 * A transport timeout is retried here, not inside one request (which
		 * would only make it longer): the cursor and the batch option are left
		 * alone, an attempt counter and a retry_after timestamp go on the job,
		 * and the caller's own step() no-ops on the next call until retry_after
		 * has passed. The bulk-upsert is idempotent on
		 * (external_source, external_id), so re-sending the same batch after a
		 * timeout -- which may or may not have reached the gateway -- is safe.
		 * A 4xx/5xx the gateway did answer with is never retried: the gateway
		 * has spoken, and trying again cannot change its answer.
		 *
		 * @param array<string, mixed> $job
		 * @param int                  $max_execution_time See step()'s docblock.
		 *
		 * @return array<string, mixed>
		 */
		private static function step_send( array $job, int $max_execution_time ): array {
			$index  = (int) $job['batch_cursor'];
			$option = self::batch_option( (string) $job['id'], $index );
			$batch  = get_option( $option, null );

			if ( ! is_array( $batch ) || empty( $batch ) ) {
				// Nothing stored for this index. This is a real failure, not
				// silently "treat as sent": the job was cancelled (which
				// discards every stored batch) and then restarted, or its
				// data was otherwise cleared, so advancing past it would
				// under-report what the run actually uploaded.
				$message = sprintf(
					/* translators: %d: 1-based batch number. */
					__( 'Batch %d payload is missing (the job was cancelled or its data was cleared); start a new sync.', 'agend-directory-sync' ),
					$index + 1
				);

				$job['send']['http_errors'][] = array(
					'batch_index' => $index,
					'status'      => 'error',
					'message'     => $message,
				);
				$job['stage']          = self::STAGE_FAILED;
				$job['message']        = $message;
				$job['batch_attempts'] = 0;
				$job['retry_after']    = 0;

				[ $saved, $job ] = self::save_unless_superseded( $job );

				if ( $saved ) {
					self::write_result( $job );
					self::discard_batches( $job );
				}

				return $job;
			}

			$attempts_before = (int) ( $job['batch_attempts'] ?? 0 );

			if ( $attempts_before >= self::MAX_SEND_ATTEMPTS ) {
				// batch_attempts is bumped and saved BEFORE send_listings() is
				// called (below), precisely so a step that dies mid-flight --
				// the PHP process killed, a host recycling a worker blocked on
				// the gateway call -- still leaves that fact recorded. Getting
				// back here with attempts already at the cap means the
				// previous attempt never returned an answer at all: recorded
				// as its own failure rather than retried forever.
				$message = sprintf(
					/* translators: 1: 1-based batch number, 2: attempts made. */
					__( 'Batch %1$d: no response after %2$d attempts; the request may have been terminated by the host.', 'agend-directory-sync' ),
					$index + 1,
					$attempts_before
				);

				$job['send']['http_errors'][] = array(
					'batch_index' => $index,
					'status'      => 'error',
					'message'     => $message,
				);
				$job['batch_cursor']   = $index + 1;
				$job['batch_attempts'] = 0;
				$job['retry_after']    = 0;

				delete_option( $option );

				if ( $job['batch_cursor'] >= (int) $job['batch_count'] ) {
					$job['stage'] = self::STAGE_DONE;
				}

				[ $saved, $job ] = self::save_unless_superseded( $job );

				if ( $saved && self::STAGE_DONE === $job['stage'] ) {
					self::write_result( $job );
				}

				return $job;
			}

			$batch_size = self::job_batch_size( $job );
			$timeout    = Agend_Directory_Sync_Agend_Client::effective_timeout(
				Agend_Directory_Sync_Agend_Client::timeout_seconds(),
				$max_execution_time,
				'web'
			);

			// Record this attempt BEFORE calling send_listings(), not after: a
			// step that dies mid-flight must not leave batch_attempts and
			// retry_after looking like nothing was ever tried, or a batch that
			// always dies the same way would retry forever instead of
			// eventually failing (see the attempts-at-cap branch above).
			// retry_after is set to the backoff this attempt would need IF it
			// fails; the success and failure paths below both overwrite it
			// with the real outcome once one is known.
			$attempts = $attempts_before + 1;

			$job['batch_attempts'] = $attempts;
			$job['retry_after']    = time() + ( self::RETRY_BACKOFF_SECONDS[ $attempts ] ?? 15 );

			[ $saved, $job ] = self::save_unless_superseded( $job );

			if ( ! $saved ) {
				// Cancelled, cleared, or replaced while about to send: do not
				// send at all.
				return $job;
			}

			$client  = new Agend_Directory_Sync_Agend_Client();
			$summary = $client->send_listings(
				$batch,
				(string) $job['external_source'],
				(bool) $job['auto_publish'],
				$batch_size,
				$timeout
			);

			$http_error = $summary['http_errors'][0] ?? null;
			$retryable  = null !== $http_error && ! empty( $http_error['retryable'] );

			if ( $retryable && $attempts < self::MAX_SEND_ATTEMPTS ) {
				// batch_attempts and retry_after were already saved above,
				// before the call: nothing changed since, so there is nothing
				// new to persist. Leave batch_cursor and the stored batch
				// option alone; the next step (once retry_after has passed)
				// tries this same batch again.
				return $job;
			}

			if ( $retryable ) {
				// Final attempt also failed: note the attempt count in the
				// message an operator sees, then let it fall through to
				// http_errors like any other batch failure.
				$http_error['message']              = sprintf(
					/* translators: 1: number of attempts made, 2: the last attempt's failure message. */
					__( '(failed after %1$d attempts: %2$s)', 'agend-directory-sync' ),
					$attempts,
					(string) ( $http_error['message'] ?? '' )
				);
				$summary['http_errors'][0] = $http_error;
			}

			if ( null === $http_error && $attempts > 1 ) {
				// Succeeded on a retry: the gateway may already hold rows an
				// earlier, timed-out attempt actually delivered, so this
				// attempt's own created/updated split is not fully trusted --
				// flagged for the result panel rather than silently folded
				// into the totals as if nothing had happened. A legacy job's
				// `send` may predate this key.
				if ( ! is_array( $job['send']['retried_batches'] ?? null ) ) {
					$job['send']['retried_batches'] = array();
				}
				$job['send']['retried_batches'][] = array(
					'batch'    => $index + 1,
					'attempts' => $attempts,
				);
			}

			$job['send']           = self::merge_send_summary( $job['send'], $summary, $index, $batch, $batch_size );
			$job['batch_cursor']   = $index + 1;
			$job['batch_attempts'] = 0;
			$job['retry_after']    = 0;

			delete_option( $option );

			if ( $job['batch_cursor'] >= (int) $job['batch_count'] ) {
				$job['stage'] = self::STAGE_DONE;
			}

			[ $saved, $job ] = self::save_unless_superseded( $job );

			if ( $saved && self::STAGE_DONE === $job['stage'] ) {
				self::write_result( $job );
			}

			return $job;
		}

		/**
		 * Fold one batch's summary into the running totals.
		 *
		 * The client reports a batch index relative to the call it was given,
		 * which is always 0 here, so both the error examples and the HTTP errors
		 * are restamped with the job's real batch index — otherwise every failure
		 * in a 25-batch run claims to be batch 0.
		 *
		 * A batch-level validation failure's issues carry a record index that is
		 * also relative to the call (`record`, 0-based within the batch); this
		 * also converts it into a job-wide listing position (`listing_position`,
		 * 1-based, what an operator counts by), and attaches the record's
		 * external_id from the batch that was actually sent, when the batch is
		 * available.
		 *
		 * @param array<string, mixed>              $running
		 * @param array<string, mixed>              $batch_summary
		 * @param array<int, array<string, mixed>>  $batch         The listings sent in this batch, for
		 *                                                          resolving an issue's external_id.
		 * @param int                                $batch_size    The job's own batch_size (see start()'s
		 *                                                          comment on why this is not
		 *                                                          Agend_Directory_Sync_Agend_Client::batch_size()).
		 *
		 * @return array<string, mixed>
		 */
		private static function merge_send_summary( array $running, array $batch_summary, int $batch_index, array $batch, int $batch_size ): array {
			$running['created'] += (int) ( $batch_summary['created'] ?? 0 );
			$running['updated'] += (int) ( $batch_summary['updated'] ?? 0 );
			$running['errored'] += (int) ( $batch_summary['errored'] ?? 0 );

			foreach ( (array) ( $batch_summary['error_examples'] ?? array() ) as $example ) {
				if ( count( $running['error_examples'] ) >= Agend_Directory_Sync_Agend_Client::MAX_ERROR_EXAMPLES ) {
					break;
				}
				$example['batch_index'] = $batch_index;

				if ( is_array( $example['fields'] ?? null ) ) {
					$example['fields'] = array_map(
						static function ( array $issue ) use ( $batch_index, $batch, $batch_size ): array {
							return Agend_Directory_Sync_Agend_Client::stamp_issue_position( $issue, $batch_index, $batch, $batch_size );
						},
						$example['fields']
					);
				}

				$running['error_examples'][] = $example;
			}

			foreach ( (array) ( $batch_summary['http_errors'] ?? array() ) as $http_error ) {
				$http_error['batch_index'] = $batch_index;

				if ( is_array( $http_error['issues'] ?? null ) ) {
					$http_error['issues'] = array_map(
						static function ( array $issue ) use ( $batch_index, $batch, $batch_size ): array {
							return Agend_Directory_Sync_Agend_Client::stamp_issue_position( $issue, $batch_index, $batch, $batch_size );
						},
						$http_error['issues']
					);
				}

				$running['http_errors'][] = $http_error;
			}

			foreach ( (array) ( $batch_summary['options_created'] ?? array() ) as $path => $data ) {
				if ( ! is_array( $data ) ) {
					continue;
				}

				if ( ! isset( $running['options_created'][ $path ] ) ) {
					$running['options_created'][ $path ] = array(
						'count'  => 0,
						'values' => array(),
					);
				}

				$running['options_created'][ $path ]['count'] += (int) ( $data['count'] ?? 0 );

				foreach ( (array) ( $data['values'] ?? array() ) as $value ) {
					if ( count( $running['options_created'][ $path ]['values'] ) >= Agend_Directory_Sync_Agend_Client::MAX_OPTION_VALUES_PER_PATH ) {
						break;
					}
					if ( ! in_array( $value, $running['options_created'][ $path ]['values'], true ) ) {
						$running['options_created'][ $path ]['values'][] = $value;
					}
				}
			}

			foreach ( (array) ( $batch_summary['other_notices'] ?? array() ) as $code => $count ) {
				$running['other_notices'][ $code ] = ( $running['other_notices'][ $code ] ?? 0 ) + (int) $count;
			}

			$running['warnings'] = ( $running['warnings'] ?? 0 ) + (int) ( $batch_summary['warnings'] ?? 0 );

			foreach ( (array) ( $batch_summary['warning_examples'] ?? array() ) as $example ) {
				if ( count( $running['warning_examples'] ) >= Agend_Directory_Sync_Agend_Client::MAX_WARNING_EXAMPLES ) {
					break;
				}
				$running['warning_examples'][] = $example;
			}

			return $running;
		}

		/**
		 * Write the finished job into the same per-user result transient the
		 * synchronous actions use, so the existing result rendering shows a
		 * chunked run exactly as it shows a one-request one.
		 *
		 * @param array<string, mixed> $job
		 */
		private static function write_result( array $job ): void {
			$result = is_array( $job['transform'] ?? null ) ? $job['transform'] : array();

			$result['kind']   = 'send';
			$result['status'] = self::STAGE_FAILED === $job['stage'] ? 'error' : 'ok';
			$result['send']   = $job['send'];

			if ( self::STAGE_FAILED === $job['stage'] ) {
				$result['message'] = (string) $job['message'];
			}

			$result['ran_at'] = current_time( 'mysql' );

			set_transient( 'agend_directory_sync_last_' . (int) $job['user_id'], $result, MINUTE_IN_SECONDS * 30 );
		}

		/**
		 * @return array<string, mixed>
		 */
		private static function empty_send_summary(): array {
			return array(
				'batches'          => 0,
				'created'          => 0,
				'updated'          => 0,
				'errored'          => 0,
				'error_examples'   => array(),
				'http_errors'      => array(),
				'retried_batches'  => array(),
				'options_created'  => array(),
				'other_notices'    => array(),
				'warnings'         => 0,
				'warning_examples' => array(),
			);
		}

		/**
		 * @param array<string, mixed> $job
		 */
		private static function save( array $job ): void {
			$job['updated_at'] = time();

			update_option( self::OPTION_JOB, $job, false );
		}

		/**
		 * Saves $job UNLESS a fresher, no-longer-active state has landed since
		 * this step started acting on it -- cancel() and clear() deliberately
		 * do not take the step lock (an operator's Cancel click is not made to
		 * wait behind a slow gateway request), so either can race a step in
		 * flight. Checked with fresh_job(), bypassing the cache, immediately
		 * before every write a step makes: writing $job over that fresher
		 * state would resurrect a job the operator already cancelled, or
		 * clobber one they already started again.
		 *
		 * @param array<string, mixed> $job
		 *
		 * @return array{0: bool, 1: array<string, mixed>} [ whether $job was
		 *         actually saved, the job to use from here on -- $job itself
		 *         when saved, or the fresher state that superseded it
		 *         (unsaved) otherwise ].
		 */
		private static function save_unless_superseded( array $job ): array {
			$fresh = self::fresh_job();

			if ( null === $fresh || $fresh['id'] !== $job['id'] || ! self::is_active( $fresh ) ) {
				return array( false, $fresh ?? $job );
			}

			self::save( $job );

			return array( true, $job );
		}

		/**
		 * @param array<string, mixed>|null $job
		 */
		private static function discard_batches( ?array $job ): void {
			if ( null === $job || empty( $job['id'] ) ) {
				return;
			}

			$count = (int) ( $job['batch_count'] ?? 0 );

			for ( $index = 0; $index < $count; $index++ ) {
				delete_option( self::batch_option( (string) $job['id'], $index ) );
			}
		}

		private static function batch_option( string $job_id, int $index ): string {
			// md5 keeps the option name inside wp_options.option_name's 191
			// characters whatever uniqid() produced.
			return self::OPTION_BATCH_PREFIX . md5( $job_id ) . '_' . $index;
		}

		/**
		 * The exact "timestamp:token" value this process wrote for the lock it
		 * currently holds, so release_lock() can delete only that row and only
		 * if it still holds that exact value -- never a lock a different
		 * request has since taken over after this one's TTL expired. Static
		 * because acquire_lock() and release_lock() are always called within
		 * the same step() call (the try/finally in step() guarantees a
		 * release for every acquire), never across two.
		 */
		private static ?string $lock_value = null;

		/**
		 * @return array{0: int, 1: string} [timestamp, token]; token is '' for
		 *         a value that is not the "timestamp:token" shape -- a legacy
		 *         lock from before it carried one, or nothing at all.
		 */
		private static function decode_lock( ?string $value ): array {
			if ( null === $value || '' === $value ) {
				return array( 0, '' );
			}

			if ( 1 === preg_match( '/^(\d+):(.*)$/s', $value, $m ) ) {
				return array( (int) $m[1], $m[2] );
			}

			return array( (int) $value, '' );
		}

		/**
		 * Take the step lock, atomically, directly against the database.
		 *
		 * get_option()/add_option() are not used for this: WP core's own
		 * add_option() is an `INSERT ... ON DUPLICATE KEY UPDATE` under the
		 * hood, so two callers racing a plain add_option() can both be told
		 * they "added" a row when only one of them actually created it --
		 * exactly the double-acquire this lock exists to prevent. The lock is
		 * instead taken with a real `INSERT IGNORE`, whose affected-row count
		 * is the database's own word on whether THIS process's row won, and
		 * an expired lock is taken over with a compare-and-swap UPDATE, so a
		 * second request racing for the same takeover cannot also win it.
		 *
		 * Falls back to the previous add_option()-based behaviour when $wpdb
		 * is unavailable (should not happen under WordPress proper, but keeps
		 * this from fataling in an unusual host or test context).
		 */
		private static function acquire_lock(): bool {
			global $wpdb;

			if ( ! ( $wpdb instanceof wpdb ) ) {
				return self::acquire_lock_without_wpdb();
			}

			$token = bin2hex( random_bytes( 8 ) );
			$value = time() . ':' . $token;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the step lock must be atomic; see the docblock above.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					self::OPTION_LOCK,
					$value
				)
			);
			wp_cache_delete( self::OPTION_LOCK, 'options' );

			if ( 1 === (int) $wpdb->rows_affected ) {
				self::$lock_value = $value;
				return true;
			}

			// Someone already holds (or held) the lock. Read it directly,
			// bypassing the cache: a stale cached copy here is exactly the
			// failure mode this lock exists to prevent.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$existing = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_LOCK )
			);

			if ( null === $existing ) {
				// Gone by the time we looked (released, or never really
				// there): nothing to take over. Let the caller's next step
				// try again rather than looping here.
				return false;
			}

			[ $existing_time ] = self::decode_lock( (string) $existing );

			if ( $existing_time > time() - self::LOCK_TTL ) {
				return false;
			}

			// Expired: take it over atomically. The WHERE clause only matches
			// if the value is still exactly what was just read, so a third
			// request racing for the same takeover cannot also win it.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$value,
					self::OPTION_LOCK,
					(string) $existing
				)
			);
			wp_cache_delete( self::OPTION_LOCK, 'options' );

			if ( 1 === (int) $wpdb->rows_affected ) {
				self::$lock_value = $value;
				return true;
			}

			return false;
		}

		/**
		 * Pre-$wpdb-lock fallback: add_option() fails when the row already
		 * exists, and that failure being the database's (rather than a
		 * get-then-set race in PHP) is what made this safe enough before the
		 * atomic version above.
		 */
		private static function acquire_lock_without_wpdb(): bool {
			$held = get_option( self::OPTION_LOCK, false );

			if ( false !== $held ) {
				[ $existing_time ] = self::decode_lock( (string) $held );
				if ( $existing_time > time() - self::LOCK_TTL ) {
					return false;
				}
				delete_option( self::OPTION_LOCK );
			}

			return add_option( self::OPTION_LOCK, (string) time(), '', false );
		}

		private static function release_lock(): void {
			global $wpdb;

			if ( ( $wpdb instanceof wpdb ) && null !== self::$lock_value ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
						self::OPTION_LOCK,
						self::$lock_value
					)
				);
				wp_cache_delete( self::OPTION_LOCK, 'options' );
			} else {
				delete_option( self::OPTION_LOCK );
			}

			self::$lock_value = null;
		}
	}
endif;
