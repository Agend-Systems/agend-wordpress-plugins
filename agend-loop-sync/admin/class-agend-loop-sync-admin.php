<?php
/**
 * Admin screen and manual sync handler.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the settings page under Settings, wires the Settings API, and
 * handles the manual "Sync committees now" action.
 */
class Agend_Loop_Sync_Admin {

	/**
	 * Committee sync handler used by the manual run action.
	 *
	 * @var Agend_Loop_Sync_Committee_Sync
	 */
	private $committee_sync;

	/**
	 * Constructor.
	 *
	 * @param Agend_Loop_Sync_Committee_Sync $committee_sync Committee sync handler.
	 */
	public function __construct( Agend_Loop_Sync_Committee_Sync $committee_sync ) {
		$this->committee_sync = $committee_sync;
	}

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( 'Agend_Loop_Sync_Settings', 'register' ) );
		add_action( 'admin_post_agend_loop_sync_run_committees', array( $this, 'handle_run_committees' ) );
		add_action( 'admin_post_agend_loop_sync_inspect_committees', array( $this, 'handle_inspect_committees' ) );
		add_action( 'admin_post_agend_loop_sync_scan_members', array( $this, 'handle_scan_members' ) );
		add_action( 'admin_post_agend_loop_sync_backfill_users', array( $this, 'handle_backfill_users' ) );
	}

	/**
	 * Adds the settings page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Agend Loop Sync', 'agend-loop-sync' ),
			__( 'Loop Sync', 'agend-loop-sync' ),
			'manage_options',
			'agend-loop-sync',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require AGEND_LOOP_SYNC_DIR . 'templates/admin-settings.php';
	}

	/**
	 * Handles the manual committee sync action.
	 *
	 * @return void
	 */
	public function handle_run_committees() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agend-loop-sync' ) );
		}

		check_admin_referer( 'agend_loop_sync_run_committees' );

		$summary = $this->committee_sync->run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'agend-loop-sync',
					'committee_sync'  => 'done',
					'synced'          => (int) $summary['synced'],
					'members_scanned' => (int) $summary['members_scanned'],
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the "Scan members for committees" action: reads the read-only
	 * member scan and stores it in a short-lived transient for the settings
	 * page to render. Makes no gateway calls.
	 *
	 * @return void
	 */
	public function handle_scan_members() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agend-loop-sync' ) );
		}

		check_admin_referer( 'agend_loop_sync_scan_members' );

		$report = $this->committee_sync->scan_members();
		set_transient( 'agend_loop_sync_member_scan', $report, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'agend-loop-sync',
					'scanned' => 'done',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the "Inspect committees" action: reads the read-only committee
	 * preview and stores it in a short-lived transient for the settings page
	 * to render. Makes no gateway calls.
	 *
	 * @return void
	 */
	public function handle_inspect_committees() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agend-loop-sync' ) );
		}

		check_admin_referer( 'agend_loop_sync_inspect_committees' );

		$preview = $this->committee_sync->inspect();
		set_transient( 'agend_loop_sync_inspection', $preview, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'agend-loop-sync',
					'inspected' => 'done',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the "Backfill all users" action: pushes every local member into
	 * Loop via the bulk-sync endpoint. Long-running on large memberships, so the
	 * time limit is lifted. Stores a short-lived transient for the settings page.
	 *
	 * @return void
	 */
	public function handle_backfill_users() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agend-loop-sync' ) );
		}

		check_admin_referer( 'agend_loop_sync_backfill_users' );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		ignore_user_abort( true );

		$summary = Agend_Loop_Sync_User_Sync::backfill_all();
		set_transient( 'agend_loop_sync_user_backfill', $summary, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'agend-loop-sync',
					'backfilled' => 'done',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
