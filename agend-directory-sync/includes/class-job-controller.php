<?php
/**
 * Admin-ajax endpoints that drive a sync job one step per request.
 *
 * Every endpoint is capability-checked and nonce-checked, and every one returns
 * the same job-state envelope, so the page has a single shape to render whether
 * it just started a job, stepped one, or reloaded onto one already in flight.
 *
 * These are deliberately separate from Agend_Directory_Sync_Admin_Page's
 * admin-post handlers: those redirect and render HTML, these answer JSON and
 * must stay short enough that the browser can call them in a loop.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Job_Controller' ) ) :
	final class Agend_Directory_Sync_Job_Controller {

		public const NONCE_ACTION = 'agend_directory_sync_job';

		public static function setup_hooks(): void {
			add_action( 'wp_ajax_agend_directory_sync_job_start', array( __CLASS__, 'handle_start' ) );
			add_action( 'wp_ajax_agend_directory_sync_job_step', array( __CLASS__, 'handle_step' ) );
			add_action( 'wp_ajax_agend_directory_sync_job_status', array( __CLASS__, 'handle_status' ) );
			add_action( 'wp_ajax_agend_directory_sync_job_cancel', array( __CLASS__, 'handle_cancel' ) );
			add_action( 'wp_ajax_agend_directory_sync_job_clear', array( __CLASS__, 'handle_clear' ) );
		}

		public static function handle_start(): void {
			self::assert_request();

			$max_records = isset( $_POST['max_records'] ) ? max( 0, (int) $_POST['max_records'] ) : 0;

			try {
				$job = Agend_Directory_Sync_Job::start( $max_records, get_current_user_id() );
			} catch ( Throwable $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ), 409 );
			}

			wp_send_json_success( self::envelope( $job ) );
		}

		public static function handle_step(): void {
			self::assert_request();

			// A step performs one upload batch, which is a network round trip to
			// the gateway; the browser is waiting on it, so give it room without
			// letting it approach the timeout this whole design exists to avoid.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 );
			}

			$job = Agend_Directory_Sync_Job::step();

			wp_send_json_success( self::envelope( $job ) );
		}

		public static function handle_status(): void {
			self::assert_request();

			$job = Agend_Directory_Sync_Job::current();

			wp_send_json_success( null === $job ? array( 'job' => null ) : self::envelope( $job ) );
		}

		public static function handle_cancel(): void {
			self::assert_request();

			Agend_Directory_Sync_Job::cancel();

			$job = Agend_Directory_Sync_Job::current();

			wp_send_json_success( null === $job ? array( 'job' => null ) : self::envelope( $job ) );
		}

		public static function handle_clear(): void {
			self::assert_request();

			Agend_Directory_Sync_Job::clear();

			wp_send_json_success( array( 'job' => null ) );
		}

		/**
		 * The response body every endpoint returns: the progress numbers for the
		 * panel, plus `busy` so the page can back off when another tab holds the
		 * step lock rather than treating it as a stalled job.
		 *
		 * The listings themselves are never included. They are large, and the
		 * browser has no use for them.
		 *
		 * @param array<string, mixed> $job
		 *
		 * @return array<string, mixed>
		 */
		private static function envelope( array $job ): array {
			return array(
				'job'  => Agend_Directory_Sync_Job::progress( $job ),
				'busy' => ! empty( $job['busy'] ),
			);
		}

		/**
		 * Same gate as the settings page: the capability first, then the nonce.
		 * A sync writes to a client's live directory, so neither is optional.
		 */
		private static function assert_request(): void {
			if ( ! current_user_can( Agend_Directory_Sync_Admin_Page::CAPABILITY ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission to run a sync.', 'agend-directory-sync' ) ), 403 );
			}

			if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'This page has expired. Reload it and try again.', 'agend-directory-sync' ) ), 403 );
			}
		}
	}
endif;
