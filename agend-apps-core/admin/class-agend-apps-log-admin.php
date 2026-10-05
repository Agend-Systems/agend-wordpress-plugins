<?php
/**
 * Admin viewer for the API log.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools > Agend API Log: browse, filter, inspect, export and purge.
 *
 * Every row is redacted before it is written, so this screen shows what the
 * table holds without further masking. Searching by email hashes the input
 * server-side and matches the stored pseudonym.
 */
class Agend_Apps_Log_Admin {

	public const PAGE_SLUG = 'agend-apps-api-log';

	private const CAPABILITY = 'manage_options';

	private const PER_PAGE = 50;

	private const EXPORT_LIMIT = 5000;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_agend_apps_api_log_filter', array( $this, 'handle_filter' ) );
		add_action( 'admin_post_agend_apps_api_log_settings', array( $this, 'handle_settings' ) );
		add_action( 'admin_post_agend_apps_api_log_purge', array( $this, 'handle_purge' ) );
		add_action( 'admin_post_agend_apps_api_log_export', array( $this, 'handle_export' ) );
	}

	public function add_menu_page(): void {
		add_management_page(
			__( 'Agend API Log', 'agend-apps-core' ),
			__( 'Agend API Log', 'agend-apps-core' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Reads the filter fields from the query string.
	 *
	 * @return array<string, mixed>
	 */
	private function filters_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$get = static fn( string $key ): string => isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';

		$filters = array(
			'status_class'       => $get( 'status_class' ),
			'outcome'            => $get( 'outcome' ),
			'method'             => strtoupper( $get( 'method' ) ),
			'direction'          => $get( 'direction' ),
			'source'             => $get( 'source' ),
			'path'               => $get( 'path' ),
			'request_id'         => $get( 'request_id' ),
			'gateway_request_id' => $get( 'gateway_request_id' ),
			'event_type'         => $get( 'event_type' ),
		);

		$since = $get( 'since' );
		$until = $get( 'until' );

		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
			$filters['since'] = $since . ' 00:00:00';
		}

		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
			$filters['until'] = $until . ' 23:59:59';
		}

		$user = $get( 'user' );

		if ( '' !== $user && ctype_digit( $user ) ) {
			$filters['user_id'] = (int) $user;
		}

		$user_hash = $get( 'user_hash' );

		if ( 1 === preg_match( '/^[0-9a-f]{16}$/', $user_hash ) ) {
			$filters['user_hash'] = $user_hash;
		}
		// phpcs:enable

		return $filters;
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view the API log.', 'agend-apps-core' ) );
		}

		$store  = new Agend_Apps_Log_Store();
		$log_id = isset( $_GET['log_id'] ) ? absint( $_GET['log_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $log_id > 0 ) {
			$row = $store->find( $log_id );
			require AGEND_APPS_CORE_DIR . 'admin/views/log-detail.php';
			return;
		}

		$filters   = $this->filters_from_request();
		$page      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result    = $store->query( $filters, self::PER_PAGE, $page );
		$templates = $store->recent_path_templates();
		$enabled   = Agend_Apps_Logger::enabled();
		$retention = Agend_Apps_Logger::retention_days();
		$locked    = defined( 'AGEND_APPS_API_LOG' );
		$page_url  = admin_url( 'tools.php?page=' . self::PAGE_SLUG );

		require AGEND_APPS_CORE_DIR . 'admin/views/log-list.php';
	}

	/**
	 * Turns the posted filter form into a GET URL. Posted rather than
	 * submitted as GET so an email typed into the user filter is hashed here
	 * and never appears in a URL, where access logs, browser history and
	 * Referer headers would keep it.
	 */
	public function handle_filter(): void {
		$this->guard( 'agend_apps_api_log_filter' );

		wp_safe_redirect( self::filter_redirect_url( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		exit;
	}

	/**
	 * The GET URL for a posted filter form, with any email replaced by its
	 * pseudonym.
	 *
	 * @param array<string, mixed> $posted Unslashed form fields.
	 */
	public static function filter_redirect_url( array $posted ): string {
		$args = array( 'page' => self::PAGE_SLUG );

		foreach ( array( 'since', 'until', 'status_class', 'outcome', 'method', 'direction', 'source', 'path', 'request_id', 'gateway_request_id', 'event_type' ) as $field ) {
			$value = isset( $posted[ $field ] ) && is_scalar( $posted[ $field ] ) ? sanitize_text_field( (string) $posted[ $field ] ) : '';

			if ( '' !== $value ) {
				$args[ $field ] = $value;
			}
		}

		$user = isset( $posted['user'] ) && is_scalar( $posted['user'] ) ? trim( sanitize_text_field( (string) $posted['user'] ) ) : '';

		if ( '' !== $user ) {
			if ( ctype_digit( $user ) ) {
				$args['user'] = $user;
			} else {
				$args['user_hash'] = Agend_Apps_Logger::user_hash( $user );
			}
		}

		return add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'tools.php' ) );
	}

	public function handle_settings(): void {
		$this->guard( 'agend_apps_api_log_settings' );

		// While the constant decides, the checkbox is disabled and never
		// posted; writing its absence would switch logging off for good
		// once the constant is removed.
		if ( ! defined( 'AGEND_APPS_API_LOG' ) ) {
			update_option( Agend_Apps_Logger::OPTION_ENABLED, empty( $_POST['enabled'] ) ? '0' : '1' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		}

		$days = isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : Agend_Apps_Logger::DEFAULT_RETENTION_DAYS; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_option( Agend_Apps_Logger::OPTION_RETENTION, max( 1, min( 365, $days ) ) );

		wp_safe_redirect( add_query_arg( 'updated', 'settings', admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	public function handle_purge(): void {
		$this->guard( 'agend_apps_api_log_purge' );

		( new Agend_Apps_Log_Store() )->purge();

		wp_safe_redirect( add_query_arg( 'updated', 'purged', admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * Streams the filtered rows as CSV. The rows are already redacted.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export the API log.', 'agend-apps-core' ), 403 );
		}

		check_admin_referer( 'agend_apps_api_log_export' );

		$result = ( new Agend_Apps_Log_Store() )->query( $this->filters_from_request(), self::EXPORT_LIMIT, 1, false );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="agend-api-log-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );

		if ( false === $out ) {
			exit;
		}

		fputcsv( $out, array_merge( array( 'id' ), Agend_Apps_Log_Store::COLUMNS ) );

		foreach ( $result['rows'] as $row ) {
			$line = array( $row['id'] ?? '' );

			foreach ( Agend_Apps_Log_Store::COLUMNS as $column ) {
				// A leading =, +, -, @, tab or carriage return makes a
				// spreadsheet read the cell as a formula.
				$line[] = (string) preg_replace( '/^([=+\-@\t\r])/', "'$1", (string) ( $row[ $column ] ?? '' ) );
			}

			fputcsv( $out, $line );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change the API log.', 'agend-apps-core' ), 403 );
		}

		check_admin_referer( $action );
	}
}
