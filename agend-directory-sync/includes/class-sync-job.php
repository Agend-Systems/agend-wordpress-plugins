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
		 * The in-flight job, or null when there is none.
		 *
		 * @return array<string, mixed>|null
		 */
		public static function current(): ?array {
			$job = get_option( self::OPTION_JOB, null );

			return is_array( $job ) && isset( $job['id'] ) ? $job : null;
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
				'batch_count'     => 0,
				'batch_cursor'    => 0,
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
		 * @return array<string, mixed> The job state, with `busy` set when
		 *                              another request holds the lock.
		 */
		public static function step(): array {
			$job = self::current();

			if ( null === $job ) {
				return array( 'stage' => self::STAGE_DONE, 'missing' => true );
			}

			if ( ! self::is_active( $job ) ) {
				return $job;
			}

			if ( ! self::acquire_lock() ) {
				$job['busy'] = true;
				return $job;
			}

			try {
				$job = self::STAGE_PENDING === $job['stage']
					? self::step_fetch( $job )
					: self::step_send( $job );
			} catch ( Throwable $e ) {
				$job['stage']   = self::STAGE_FAILED;
				$job['message'] = $e->getMessage();
				self::save( $job );
				self::write_result( $job );
				self::discard_batches( $job );
			} finally {
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

			$sent = min( $done * Agend_Directory_Sync_Agend_Client::MAX_BATCH_SIZE, (int) ( $job['listing_count'] ?? 0 ) );

			return array(
				'stage'          => $stage,
				'active'         => self::is_active( $job ),
				'percent'        => $percent,
				'batches_total'  => $batches,
				'batches_done'   => $done,
				'listing_count'  => (int) ( $job['listing_count'] ?? 0 ),
				'listings_sent'  => $sent,
				'created'        => (int) ( $job['send']['created'] ?? 0 ),
				'updated'        => (int) ( $job['send']['updated'] ?? 0 ),
				'errored'        => (int) ( $job['send']['errored'] ?? 0 ),
				'http_errors'    => count( (array) ( $job['send']['http_errors'] ?? array() ) ),
				'message'        => (string) ( $job['message'] ?? '' ),
				'source'         => (string) ( $job['source'] ?? '' ),
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
			$result = Agend_Directory_Sync_Runner::run( (int) $job['max_records'], true );

			$listings = is_array( $result['listings'] ?? null ) ? $result['listings'] : array();
			unset( $result['listings'] );

			$result['kind'] = 'send';
			$job['transform'] = $result;
			$job['listing_count'] = count( $listings );

			$batches = array_chunk( $listings, Agend_Directory_Sync_Agend_Client::MAX_BATCH_SIZE );

			foreach ( $batches as $index => $batch ) {
				update_option( self::batch_option( (string) $job['id'], (int) $index ), $batch, false );
			}

			$job['batch_count']       = count( $batches );
			$job['batch_cursor']      = 0;
			$job['send']['batches']   = count( $batches );
			$job['stage']             = empty( $batches ) ? self::STAGE_DONE : self::STAGE_SENDING;

			self::save( $job );

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
		 * @param array<string, mixed> $job
		 *
		 * @return array<string, mixed>
		 */
		private static function step_send( array $job ): array {
			$index  = (int) $job['batch_cursor'];
			$option = self::batch_option( (string) $job['id'], $index );
			$batch  = get_option( $option, null );

			if ( ! is_array( $batch ) || empty( $batch ) ) {
				// Nothing stored for this index: treat it as sent rather than
				// stalling the job on a payload that will never appear.
				$job['batch_cursor'] = $index + 1;
			} else {
				$client  = new Agend_Directory_Sync_Agend_Client();
				$summary = $client->send_listings( $batch, (string) $job['external_source'], (bool) $job['auto_publish'] );

				$job['send']         = self::merge_send_summary( $job['send'], $summary, $index );
				$job['batch_cursor'] = $index + 1;
			}

			delete_option( $option );

			if ( $job['batch_cursor'] >= (int) $job['batch_count'] ) {
				$job['stage'] = self::STAGE_DONE;
			}

			self::save( $job );

			if ( self::STAGE_DONE === $job['stage'] ) {
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
		 * @param array<string, mixed> $running
		 * @param array<string, mixed> $batch_summary
		 *
		 * @return array<string, mixed>
		 */
		private static function merge_send_summary( array $running, array $batch_summary, int $batch_index ): array {
			$running['created'] += (int) ( $batch_summary['created'] ?? 0 );
			$running['updated'] += (int) ( $batch_summary['updated'] ?? 0 );
			$running['errored'] += (int) ( $batch_summary['errored'] ?? 0 );

			foreach ( (array) ( $batch_summary['error_examples'] ?? array() ) as $example ) {
				if ( count( $running['error_examples'] ) >= Agend_Directory_Sync_Agend_Client::MAX_ERROR_EXAMPLES ) {
					break;
				}
				$example['batch_index']      = $batch_index;
				$running['error_examples'][] = $example;
			}

			foreach ( (array) ( $batch_summary['http_errors'] ?? array() ) as $http_error ) {
				$http_error['batch_index'] = $batch_index;
				$running['http_errors'][]  = $http_error;
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
				'batches'        => 0,
				'created'        => 0,
				'updated'        => 0,
				'errored'        => 0,
				'error_examples' => array(),
				'http_errors'    => array(),
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
		 * Take the step lock. add_option() fails when the row already exists, and
		 * that failure is the database's, so two simultaneous callers cannot both
		 * believe they hold it.
		 */
		private static function acquire_lock(): bool {
			$held = get_option( self::OPTION_LOCK, false );

			if ( false !== $held ) {
				if ( (int) $held > time() - self::LOCK_TTL ) {
					return false;
				}
				delete_option( self::OPTION_LOCK );
			}

			return add_option( self::OPTION_LOCK, (string) time(), '', false );
		}

		private static function release_lock(): void {
			delete_option( self::OPTION_LOCK );
		}
	}
endif;
