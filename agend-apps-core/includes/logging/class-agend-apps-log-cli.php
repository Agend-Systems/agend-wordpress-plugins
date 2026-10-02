<?php
/**
 * WP-CLI commands for the API log.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and maintains the Agend API log.
 *
 * Registered as `wp agend-apps logs`. Every row is already redacted, so
 * nothing printed here carries personal data.
 */
class Agend_Apps_Log_CLI {

	/**
	 * Lists recent API log entries, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--path=<prefix>]
	 * : Path-template prefix, e.g. `/v1/crm`.
	 *
	 * [--status=<class>]
	 * : Status class: 2xx, 3xx, 4xx, 5xx or none.
	 *
	 * [--outcome=<outcome>]
	 * : Exact outcome, e.g. http_error or transport_error.
	 *
	 * [--since=<when>]
	 * : Lower bound, anything strtotime() reads, e.g. "1 hour ago". UTC.
	 *
	 * [--email=<email>]
	 * : Only calls made for this user. Matched by hash; the log holds no emails.
	 *
	 * [--limit=<n>]
	 * : Rows to show. Default 20, maximum 5000.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp agend-apps logs list --status=5xx --since="1 day ago"
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$filters = array(
			'path'         => (string) ( $assoc_args['path'] ?? '' ),
			'status_class' => (string) ( $assoc_args['status'] ?? '' ),
			'outcome'      => (string) ( $assoc_args['outcome'] ?? '' ),
		);

		if ( ! empty( $assoc_args['since'] ) ) {
			$since = strtotime( (string) $assoc_args['since'] );

			if ( false === $since ) {
				WP_CLI::error( 'Could not read --since.' );
			}

			$filters['since'] = gmdate( 'Y-m-d H:i:s', $since );
		}

		if ( ! empty( $assoc_args['email'] ) ) {
			$filters['user_hash'] = Agend_Apps_Logger::user_hash( (string) $assoc_args['email'] );
		}

		$result = ( new Agend_Apps_Log_Store() )->query( $filters, (int) ( $assoc_args['limit'] ?? 20 ) );

		WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$result['rows'],
			array( 'id', 'created_at', 'direction', 'method', 'path', 'status', 'outcome', 'duration_ms', 'gateway_request_id', 'error_code' )
		);
	}

	/**
	 * Deletes entries older than the retention window now, instead of
	 * waiting for the daily cron run.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<n>]
	 * : Override the configured retention for this run.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function prune( array $args, array $assoc_args ): void {
		$days    = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : Agend_Apps_Logger::retention_days();
		$deleted = ( new Agend_Apps_Log_Store() )->prune( $days );

		WP_CLI::success( sprintf( 'Deleted %d entries older than %d days.', $deleted, $days ) );
	}

	/**
	 * Deletes every API log entry.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function purge( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'Delete every entry in the Agend API log?', $assoc_args );

		( new Agend_Apps_Log_Store() )->purge();

		WP_CLI::success( 'API log purged.' );
	}
}
