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
 * handles the manual "Sync committees now" action and the hand-over actions.
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
		add_action( 'admin_post_agend_loop_sync_adopt_load_committees', array( $this, 'handle_adopt_load_committees' ) );
		add_action( 'admin_post_agend_loop_sync_adopt_load_members', array( $this, 'handle_adopt_load_members' ) );
		add_action( 'admin_post_agend_loop_sync_adopt_queue', array( $this, 'handle_adopt_queue' ) );
		add_action( 'admin_post_agend_loop_sync_adopt_clear', array( $this, 'handle_adopt_clear' ) );
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

		// One Upbeat call per member: lift the time limit as the backfill does.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		ignore_user_abort( true );

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

	/**
	 * Handles the hand-over "Load committees" action: reads the committees the
	 * sync covers and stores them in a short-lived transient for the settings
	 * page to offer in its committee select. Makes no gateway calls.
	 *
	 * @return void
	 */
	public function handle_adopt_load_committees() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agend-loop-sync' ) );
		}

		check_admin_referer( 'agend_loop_sync_adopt_load_committees' );

		// The committee list is read a few committees at a time.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		set_transient( 'agend_loop_sync_adopt_committees', $this->committee_sync->adoption_committees(), 15 * MINUTE_IN_SECONDS );

		$this->redirect_to_adopt( array( 'adopt' => 'committees' ) );
	}

	/**
	 * Handles the hand-over "Load members" action: builds the member picker for
	 * the chosen committee and stores it in a short-lived transient for the
	 * settings page to render. Reads Upbeat and Loop; writes nothing.
	 *
	 * @return void
	 */
	public function handle_adopt_load_members() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agend-loop-sync' ) );
		}

		check_admin_referer( 'agend_loop_sync_adopt_load_members' );

		$unique_id = $this->posted_unique_id();

		// Only a committee the loaded list offered, so a made-up id reaches neither
		// Upbeat nor the gateway.
		if ( ! $this->is_listed_committee( $unique_id ) ) {
			$this->redirect_to_adopt( array( 'adopt' => 'unknown_committee' ) );
		}

		// One Upbeat call per member: lift the time limit as the manual sync does.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		set_transient( 'agend_loop_sync_adopt_picker', $this->committee_sync->adoption_picker( $unique_id ), 5 * MINUTE_IN_SECONDS );

		$this->redirect_to_adopt( array( 'adopt' => 'members' ) );
	}

	/**
	 * Handles the hand-over "Queue hand-over" action: merges the ticked members
	 * into the committee's queue. Only a member the loaded picker offered as
	 * selectable is queued, whatever was posted.
	 *
	 * @return void
	 */
	public function handle_adopt_queue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agend-loop-sync' ) );
		}

		check_admin_referer( 'agend_loop_sync_adopt_queue' );

		$unique_id = $this->posted_unique_id();
		$picker    = get_transient( 'agend_loop_sync_adopt_picker' );

		// The picker lives five minutes; without it there is nothing to check the selection against.
		if ( ! is_array( $picker ) ) {
			$this->redirect_to_adopt( array( 'adopt' => 'expired' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by check_admin_referer() above.
		$posted = isset( $_POST['external_ids'] ) && is_array( $_POST['external_ids'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['external_ids'] ) ) : array();
		$ids    = Agend_Loop_Sync_Committee_Sync::selectable_external_ids( $picker, $unique_id, $posted );

		if ( array() === $ids ) {
			$this->redirect_to_adopt( array( 'adopt' => 'none_selected' ) );
		}

		$queued = Agend_Loop_Sync_Settings::queue_adoptions( $unique_id, $ids );

		Agend_Loop_Sync_Logger::info(
			'Hand-over queued',
			array(
				'unique_id' => $unique_id,
				'selected'  => count( $ids ),
				'queued'    => $queued,
			)
		);

		$this->redirect_to_adopt(
			array(
				'adopt'    => 'queued',
				'selected' => count( $ids ),
				'queued'   => $queued,
			)
		);
	}

	/**
	 * Handles the hand-over "Clear" action: empties one committee's queue.
	 *
	 * @return void
	 */
	public function handle_adopt_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agend-loop-sync' ) );
		}

		check_admin_referer( 'agend_loop_sync_adopt_clear' );

		$unique_id = $this->posted_unique_id();
		$cleared   = count( Agend_Loop_Sync_Settings::get_adopt_queue_for( $unique_id ) );

		Agend_Loop_Sync_Settings::clear_adopt_queue( $unique_id );

		Agend_Loop_Sync_Logger::info(
			'Hand-over queue cleared',
			array(
				'unique_id' => $unique_id,
				'cleared'   => $cleared,
			)
		);

		$this->redirect_to_adopt( array( 'adopt' => 'cleared' ) );
	}

	/**
	 * The committee unique id posted by a hand-over form.
	 *
	 * @return string The unique id, or an empty string when none was posted.
	 */
	private function posted_unique_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers check the nonce first.
		return isset( $_POST['unique_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['unique_id'] ) ) ) : '';
	}

	/**
	 * Whether the committee list last loaded for the hand-over select offers a
	 * committee.
	 *
	 * @param string $unique_id Upbeat committee unique id.
	 * @return bool
	 */
	private function is_listed_committee( string $unique_id ): bool {
		$loaded = get_transient( 'agend_loop_sync_adopt_committees' );
		if ( '' === $unique_id || ! is_array( $loaded ) ) {
			return false;
		}

		foreach ( (array) ( $loaded['committees'] ?? array() ) as $committee ) {
			if ( is_array( $committee ) && ( $committee['unique_id'] ?? null ) === $unique_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sends the administrator back to the hand-over section of the settings page.
	 *
	 * @param array<string, int|string> $args Query args that say what happened.
	 * @return void
	 */
	private function redirect_to_adopt( array $args ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge( array( 'page' => 'agend-loop-sync' ), $args ),
				admin_url( 'options-general.php' )
			) . '#als-adopt'
		);
		exit;
	}
}
