<?php
/**
 * Database storage for the API log.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only class that touches the `{prefix}agend_apps_api_log` table.
 *
 * Rows arrive here already redacted; this class never sees a raw body.
 */
class Agend_Apps_Log_Store {

	/** Bumped whenever the table definition changes, so dbDelta re-runs. */
	public const DB_VERSION = '1';

	public const DB_VERSION_OPTION = 'agend_apps_api_log_db_version';

	/** Rows deleted per statement, so a prune never holds a long lock. */
	public const PRUNE_BATCH = 5000;

	/** Upper bound on batches per prune run (one million rows). */
	public const PRUNE_MAX_BATCHES = 200;

	/** Every column a row may carry, in insert order. */
	public const COLUMNS = array(
		'created_at',
		'request_id',
		'direction',
		'source',
		'method',
		'host',
		'path',
		'path_template',
		'query',
		'status',
		'outcome',
		'duration_ms',
		'attempt',
		'request_bytes',
		'response_bytes',
		'gateway_request_id',
		'event_type',
		'auth_mode',
		'user_id',
		'user_hash',
		'context',
		'page_path',
		'ip_prefix',
		'error_code',
		'error_message',
		'request_body',
		'response_body',
		'response_headers',
		'truncated',
	);

	/** Integer columns, bound with %d. `status` is handled separately: it is nullable. */
	private const INT_COLUMNS = array( 'duration_ms', 'attempt', 'request_bytes', 'response_bytes', 'user_id', 'truncated' );

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'agend_apps_api_log';
	}

	/**
	 * Creates or migrates the table. Safe to call repeatedly.
	 */
	public static function install(): void {
		global $wpdb;

		$table   = self::table();
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one
		// column or key per line.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  request_id char(36) NOT NULL DEFAULT '',
  direction varchar(8) NOT NULL DEFAULT 'outbound',
  source varchar(64) NOT NULL DEFAULT '',
  method varchar(10) NOT NULL DEFAULT '',
  host varchar(191) NOT NULL DEFAULT '',
  path varchar(500) NOT NULL DEFAULT '',
  path_template varchar(191) NOT NULL DEFAULT '',
  query text NULL,
  status smallint(5) unsigned NULL,
  outcome varchar(24) NOT NULL DEFAULT '',
  duration_ms int(10) unsigned NULL,
  attempt tinyint(3) unsigned NOT NULL DEFAULT 1,
  request_bytes int(10) unsigned NOT NULL DEFAULT 0,
  response_bytes int(10) unsigned NOT NULL DEFAULT 0,
  gateway_request_id varchar(64) NOT NULL DEFAULT '',
  event_type varchar(64) NOT NULL DEFAULT '',
  auth_mode varchar(12) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_hash char(16) NOT NULL DEFAULT '',
  context varchar(8) NOT NULL DEFAULT '',
  page_path varchar(500) NOT NULL DEFAULT '',
  ip_prefix varchar(45) NOT NULL DEFAULT '',
  error_code varchar(64) NOT NULL DEFAULT '',
  error_message text NULL,
  request_body mediumtext NULL,
  response_body mediumtext NULL,
  response_headers text NULL,
  truncated tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY path_template_created (path_template,created_at),
  KEY status_created (status,created_at),
  KEY user_hash_created (user_hash,created_at),
  KEY user_id_created (user_id,created_at),
  KEY gateway_request_id (gateway_request_id),
  KEY request_id (request_id)
) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Installs or migrates when the stored schema version is behind, so a
	 * plugin update (which does not fire the activation hook) still migrates.
	 */
	public static function maybe_install(): void {
		if ( self::DB_VERSION !== (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			self::install();
		}
	}

	public static function exists(): bool {
		global $wpdb;

		$table = self::table();

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	public static function uninstall(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Writes rows in one multi-row INSERT.
	 *
	 * @param array<int, array<string, mixed>> $rows Redacted rows keyed by column.
	 * @return bool True when every row was written.
	 */
	public function insert_many( array $rows ): bool {
		global $wpdb;

		if ( empty( $rows ) ) {
			return true;
		}

		$placeholders = array();
		$values       = array();

		foreach ( $rows as $row ) {
			$tuple = array();

			foreach ( self::COLUMNS as $column ) {
				$value = $row[ $column ] ?? null;

				if ( 'status' === $column && null === $value ) {
					$tuple[] = 'NULL';
					continue;
				}

				if ( 'status' === $column || in_array( $column, self::INT_COLUMNS, true ) ) {
					$tuple[]  = '%d';
					$values[] = (int) $value;
					continue;
				}

				$tuple[]  = '%s';
				$values[] = null === $value ? '' : (string) $value;
			}

			$placeholders[] = '(' . implode( ', ', $tuple ) . ')';
		}

		$sql = 'INSERT INTO ' . self::table() . ' (' . implode( ', ', self::COLUMNS ) . ') VALUES ' . implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above.
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		return false !== $result;
	}

	/**
	 * Deletes rows older than the retention window, oldest first, in bounded
	 * batches. Uses the `created_at` index; never a whole-table scan.
	 *
	 * @return int Rows deleted.
	 */
	public function prune( int $days ): int {
		global $wpdb;

		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$deleted = 0;

		for ( $batch = 0; $batch < self::PRUNE_MAX_BATCHES; $batch++ ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . self::table() . ' WHERE created_at < %s ORDER BY created_at ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff,
					self::PRUNE_BATCH
				)
			);

			if ( false === $result ) {
				break;
			}

			$deleted += (int) $result;

			if ( (int) $result < self::PRUNE_BATCH ) {
				break;
			}
		}

		return $deleted;
	}

	public function purge(): void {
		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Builds the WHERE clause for the viewer and the CLI.
	 *
	 * @param array<string, mixed> $filters {
	 *     @type string $since              UTC `Y-m-d H:i:s` lower bound.
	 *     @type string $until              UTC `Y-m-d H:i:s` upper bound.
	 *     @type string $status_class       `2xx`, `3xx`, `4xx`, `5xx` or `none`.
	 *     @type string $outcome            Exact outcome.
	 *     @type string $method             HTTP method.
	 *     @type string $direction          `outbound` or `inbound`.
	 *     @type string $source             Source plugin slug.
	 *     @type string $path               Path-template prefix.
	 *     @type string $request_id         Exact PHP request id.
	 *     @type string $gateway_request_id Exact gateway request id, or webhook event id.
	 *     @type string $event_type         Exact inbound webhook event type.
	 *     @type int    $user_id            WordPress user id.
	 *     @type string $user_hash          Pseudonymous user hash.
	 * }
	 * @return array{0: string, 1: array<int, mixed>} SQL fragment and its values.
	 */
	private function where( array $filters ): array {
		global $wpdb;

		$clauses = array();
		$values  = array();

		$exact = array(
			'outcome'            => 'outcome',
			'method'             => 'method',
			'direction'          => 'direction',
			'source'             => 'source',
			'request_id'         => 'request_id',
			'gateway_request_id' => 'gateway_request_id',
			'event_type'         => 'event_type',
			'user_hash'          => 'user_hash',
		);

		foreach ( $exact as $filter => $column ) {
			if ( isset( $filters[ $filter ] ) && '' !== (string) $filters[ $filter ] ) {
				$clauses[] = "{$column} = %s";
				$values[]  = (string) $filters[ $filter ];
			}
		}

		if ( ! empty( $filters['user_id'] ) ) {
			$clauses[] = 'user_id = %d';
			$values[]  = (int) $filters['user_id'];
		}

		if ( ! empty( $filters['since'] ) ) {
			$clauses[] = 'created_at >= %s';
			$values[]  = (string) $filters['since'];
		}

		if ( ! empty( $filters['until'] ) ) {
			$clauses[] = 'created_at <= %s';
			$values[]  = (string) $filters['until'];
		}

		if ( ! empty( $filters['path'] ) ) {
			$clauses[] = 'path_template LIKE %s';
			$values[]  = $wpdb->esc_like( (string) $filters['path'] ) . '%';
		}

		$status_class = (string) ( $filters['status_class'] ?? '' );

		if ( 'none' === $status_class ) {
			$clauses[] = 'status IS NULL';
		} elseif ( 1 === preg_match( '/^([1-5])xx$/', $status_class, $m ) ) {
			$clauses[] = 'status BETWEEN %d AND %d';
			$values[]  = (int) $m[1] * 100;
			$values[]  = (int) $m[1] * 100 + 99;
		}

		return array( $clauses ? ' WHERE ' . implode( ' AND ', $clauses ) : '', $values );
	}

	/**
	 * Fetches one page of rows, newest first.
	 *
	 * Fetches one row more than asked for instead of running a COUNT(*): the
	 * viewer only needs to know whether a next page exists, and a COUNT over
	 * a large unfiltered log is the slow query the Pro viewer runs on every
	 * load.
	 *
	 * @param array<string, mixed> $filters  See where().
	 * @param int                  $per_page Rows per page.
	 * @param int                  $page     1-based page number.
	 * @param bool                 $summary  When true, omit the body columns.
	 * @return array{rows: array<int, array<string, mixed>>, has_more: bool}
	 */
	public function query( array $filters, int $per_page = 50, int $page = 1, bool $summary = true ): array {
		global $wpdb;

		$per_page = max( 1, min( 5000, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		list( $where, $values ) = $this->where( $filters );

		$columns = $summary
			? 'id, created_at, request_id, direction, source, method, path, path_template, status, outcome, duration_ms, attempt, gateway_request_id, event_type, user_id, user_hash, context, error_code'
			: '*';

		$sql      = "SELECT {$columns} FROM " . self::table() . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$values[] = $per_page + 1;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed fragments and placeholders.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		$has_more = count( $rows ) > $per_page;

		return array(
			'rows'     => array_slice( $rows, 0, $per_page ),
			'has_more' => $has_more,
		);
	}

	/**
	 * @return array<string, mixed>|null The full row, or null when absent.
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Distinct path templates seen recently, for the viewer's filter list.
	 *
	 * @return string[]
	 */
	public function recent_path_templates( int $limit = 200 ): array {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		return array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT path_template FROM ' . self::table() . ' WHERE created_at >= %s ORDER BY path_template ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$since,
					$limit
				)
			)
		);
	}
}
