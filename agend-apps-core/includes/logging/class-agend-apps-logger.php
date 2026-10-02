<?php
/**
 * API log recorder and the public logging function.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalises, redacts and buffers API log entries, then writes them in one
 * statement at the end of the request.
 *
 * Nothing here can fail a request: every path that could throw is caught,
 * and a failed write is reported without its payload.
 */
class Agend_Apps_Logger {

	public const OPTION_ENABLED   = 'agend_apps_api_log_enabled';
	public const OPTION_RETENTION = 'agend_apps_api_log_retention_days';
	public const OPTION_SALT      = 'agend_apps_api_log_salt';
	public const CRON_HOOK        = 'agend_apps_api_log_prune';

	public const DEFAULT_RETENTION_DAYS = 30;

	/** Successful-call bodies are capped at this many characters, after redaction. */
	public const SUCCESS_BODY_LIMIT = 5000;

	/**
	 * A successful body larger than this is summarised, not redacted: only
	 * 5,000 characters would be kept, and redacting a multi-megabyte list
	 * response would cost the request seconds.
	 */
	public const SUCCESS_REDACT_LIMIT = 262144;

	/** Failed-call bodies are kept whole up to this size, then summarised. */
	public const FAILED_BODY_LIMIT = 1048576;

	/** Characters kept of `error_message` and `query`. */
	private const TEXT_COLUMN_LIMIT = 2000;

	/** Entries held before a long-running CLI or cron process flushes early. */
	public const FLUSH_THRESHOLD = 50;

	private static ?Agend_Apps_Logger $instance = null;

	private Agend_Apps_Log_Store $store;

	private ?Agend_Apps_Log_Redactor $redactor = null;

	/** @var array<int, array<string, mixed>> */
	private array $buffer = array();

	/** Identifies every entry written by this PHP process. */
	private string $request_id;

	private bool $shutdown_hooked = false;

	public function __construct( ?Agend_Apps_Log_Store $store = null ) {
		$this->store      = $store ?? new Agend_Apps_Log_Store();
		$this->request_id = wp_generate_uuid4();
	}

	public static function instance(): Agend_Apps_Logger {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Replaces the shared instance. For tests, and for nothing else.
	 */
	public static function set_instance( ?Agend_Apps_Logger $logger ): void {
		self::$instance = $logger;
	}

	public function request_id(): string {
		return $this->request_id;
	}

	/**
	 * Whether logging is on. The constant wins over the option, so a host
	 * can switch logging off in wp-config.php without the admin turning it
	 * back on.
	 */
	public static function enabled(): bool {
		if ( defined( 'AGEND_APPS_API_LOG' ) ) {
			return (bool) constant( 'AGEND_APPS_API_LOG' );
		}

		return '0' !== (string) get_option( self::OPTION_ENABLED, '1' );
	}

	public static function retention_days(): int {
		$days = (int) get_option( self::OPTION_RETENTION, self::DEFAULT_RETENTION_DAYS );

		return max( 1, min( 365, $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS ) );
	}

	/**
	 * The site's hashing key, created on first use. A dedicated option rather
	 * than a WordPress salt, so rotating the auth salts does not break
	 * searching the existing log by email.
	 */
	public static function salt(): string {
		$salt = (string) get_option( self::OPTION_SALT, '' );

		if ( '' === $salt ) {
			$salt = bin2hex( random_bytes( 32 ) );
			update_option( self::OPTION_SALT, $salt, false );
		}

		return $salt;
	}

	public function redactor(): Agend_Apps_Log_Redactor {
		if ( null === $this->redactor ) {
			/**
			 * Filters extra keys redacted from the API log, in addition to the
			 * built-in personal-data keys.
			 *
			 * @param string[] $keys Key names, matched case- and separator-insensitively.
			 */
			$sensitive = (array) apply_filters( 'agend_apps_log_sensitive_keys', array() );

			/**
			 * Filters keys kept verbatim in the API log. Cannot un-redact a
			 * built-in personal-data key.
			 *
			 * @param string[] $keys Key names.
			 */
			$safe = (array) apply_filters( 'agend_apps_log_safe_keys', array() );

			$this->redactor = new Agend_Apps_Log_Redactor( self::salt(), $sensitive, $safe );
		}

		return $this->redactor;
	}

	/**
	 * Pseudonymous identifier for an email: the same email always maps to the
	 * same hash on one site, so the viewer can search by email without the
	 * log ever holding one.
	 */
	public static function user_hash( string $email ): string {
		$email = strtolower( trim( $email ) );

		return '' === $email ? '' : substr( hash_hmac( 'sha256', $email, self::salt() ), 0, 16 );
	}

	/**
	 * Truncates an IP address to its network: /24 for IPv4, /48 for IPv6.
	 */
	public static function ip_prefix( string $ip ): string {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );

			return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );

			if ( false !== $packed ) {
				$network = inet_ntop( substr( $packed, 0, 6 ) . str_repeat( "\0", 10 ) );

				return ( false !== $network ? $network : '' ) . '/48';
			}
		}

		return '';
	}

	/**
	 * Records one exchange.
	 *
	 * @param array<string, mixed> $entry {
	 *     @type string     $source           Plugin slug. Default `agend-apps-core`.
	 *     @type string     $direction        `outbound` (default) or `inbound`.
	 *     @type string     $method           HTTP method.
	 *     @type string     $url              Full URL. Split, and its query redacted, here.
	 *     @type int|null   $status           HTTP status, or null when there was no response.
	 *     @type string     $outcome          Outcome; derived from status and error when omitted.
	 *     @type int|null   $duration_ms      Round-trip time.
	 *     @type int        $attempt          1 for the first attempt, 2 for a retry.
	 *     @type string     $auth_mode        `api_key`, `bearer`, `unattended` or `signature`.
	 *     @type string     $request_body     Raw request body.
	 *     @type string     $response_body    Raw response body.
	 *     @type string     $content_type     Response content type, for non-JSON summaries.
	 *     @type array      $response_headers Response headers; filtered to an allowlist.
	 *     @type string     $error_code       Machine-readable error code.
	 *     @type string     $error_message    Human-readable error; scanned for personal data.
	 *     @type string     $gateway_request_id Gateway correlation id; read from `X-Request-Id`, then
	 *                                        `X-Vercel-Id` (searchable in the Vercel logs), when omitted.
	 *     @type string     $event_type       Inbound webhook event type.
	 * }
	 */
	public function record( array $entry ): void {
		if ( ! self::enabled() ) {
			return;
		}

		try {
			$row = $this->build_row( $entry );

			/**
			 * Filters a redacted API log row before it is buffered. Return
			 * false to drop it. Only redacted data is passed: the raw entry
			 * never leaves the logger.
			 *
			 * @param array|false $row Redacted row keyed by column.
			 */
			$row = apply_filters( 'agend_apps_log_entry', $row );

			if ( ! is_array( $row ) ) {
				return;
			}

			$this->buffer[] = $row;
			$this->hook_shutdown();

			if ( count( $this->buffer ) >= self::FLUSH_THRESHOLD ) {
				$this->flush();
			}
		} catch ( \Throwable $e ) {
			self::debug( 'API log entry dropped: ' . get_class( $e ) );
		}
	}

	/**
	 * Redacts and shapes an entry into a row. Public for the test suite.
	 *
	 * @param array<string, mixed> $entry See record().
	 * @return array<string, mixed>
	 */
	public function build_row( array $entry ): array {
		$redactor = $this->redactor();

		$url   = (string) ( $entry['url'] ?? '' );
		$parts = wp_parse_url( $url );
		$parts = is_array( $parts ) ? $parts : array();

		// An unverified inbound delivery is attacker-controlled: its body is
		// never decoded, redacted or stored, so a flood of large forged POSTs
		// costs nothing beyond one metadata row each.
		$store_bodies = ! isset( $entry['store_bodies'] ) || false !== $entry['store_bodies'];

		$status  = isset( $entry['status'] ) && null !== $entry['status'] && (int) $entry['status'] > 0 ? (int) $entry['status'] : null;
		$failed  = null === $status || $status >= 400 || ! empty( $entry['error_code'] );
		$outcome = (string) ( $entry['outcome'] ?? '' );

		if ( '' === $outcome ) {
			$outcome = null === $status ? 'transport_error' : ( $status >= 400 ? 'http_error' : 'success' );
		}

		if ( 'success' !== $outcome ) {
			$failed = true;
		}

		$request_raw  = (string) ( $entry['request_body'] ?? '' );
		$response_raw = (string) ( $entry['response_body'] ?? '' );

		$user_id = (int) ( $entry['user_id'] ?? get_current_user_id() );
		$user    = $this->user( $user_id );

		// The known-value pass: everything personal this exchange carries,
		// and the requester's own identity, is scrubbed from every free-text
		// column of this row (error messages included), not only from the
		// key it arrived under.
		$redactor->forget();

		$request_kept  = $store_bodies && $this->body_within_limits( $request_raw, $failed );
		$response_kept = $store_bodies && $this->body_within_limits( $response_raw, $failed );

		if ( $request_kept ) {
			$redactor->remember( json_decode( $request_raw, true ) );
		}

		if ( $response_kept ) {
			$redactor->remember( json_decode( $response_raw, true ) );
		}

		if ( null !== $user ) {
			foreach ( array( 'first_name' => 'name', 'last_name' => 'name', 'display_name' => 'name', 'user_email' => 'email', 'user_login' => 'name' ) as $field => $kind ) {
				if ( isset( $user->$field ) && is_string( $user->$field ) ) {
					$redactor->remember_value( $user->$field, $kind );
				}
			}
		}

		// Path and query are redacted before the bodies because both
		// remember what they redact (a listing slug, a search term) for the
		// known-value pass over the bodies and the error message.
		$path  = $redactor->redact_path( (string) ( $parts['path'] ?? '' ) );
		$query = $redactor->redact_query( (string) ( $parts['query'] ?? '' ) );

		/**
		 * Filters paths whose request and response bodies are never stored.
		 *
		 * @param string[] $paths Paths without the version prefix, e.g. `/auth/login`.
		 */
		$excluded_paths = (array) apply_filters( 'agend_apps_log_body_excluded_paths', array() );
		$body_excluded  = Agend_Apps_Log_Redactor::is_body_excluded( $path, $excluded_paths );

		$request_body  = '';
		$response_body = '';
		$truncated     = false;

		if ( ! $store_bodies ) {
			$request_body  = '' !== $request_raw ? '[not stored: unverified delivery]' : '';
			$response_body = '' !== $response_raw ? '[not stored: unverified delivery]' : '';
		} elseif ( $body_excluded ) {
			$request_body  = '' !== $request_raw ? '[not stored for this endpoint]' : '';
			$response_body = '' !== $response_raw ? '[not stored for this endpoint]' : '';
		} else {
			$request_body  = $request_kept ? $redactor->redact_body( $request_raw, 'application/json' ) : self::oversized( $request_raw );
			$response_body = $response_kept ? $redactor->redact_body( $response_raw, (string) ( $entry['content_type'] ?? '' ) ) : self::oversized( $response_raw );

			if ( ! $failed && strlen( $request_body ) > self::SUCCESS_BODY_LIMIT ) {
				$request_body = self::cut( $request_body, self::SUCCESS_BODY_LIMIT );
				$truncated    = true;
			}

			if ( ! $failed && strlen( $response_body ) > self::SUCCESS_BODY_LIMIT ) {
				$response_body = self::cut( $response_body, self::SUCCESS_BODY_LIMIT );
				$truncated     = true;
			}
		}

		$headers = $redactor->filter_response_headers( (array) ( $entry['response_headers'] ?? array() ) );

		$user_hash = null !== $user && isset( $user->user_email ) ? self::user_hash( (string) $user->user_email ) : '';

		$row = array(
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'request_id'         => $this->request_id,
			'direction'          => 'inbound' === ( $entry['direction'] ?? '' ) ? 'inbound' : 'outbound',
			'source'             => self::clip( (string) preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) ( $entry['source'] ?? 'agend-apps-core' ) ) ), 64 ),
			'method'             => self::clip( strtoupper( (string) ( $entry['method'] ?? 'GET' ) ), 10 ),
			'host'               => self::clip( strtolower( (string) ( $parts['host'] ?? '' ) ), 191 ),
			'path'               => self::clip( $path, 500 ),
			'path_template'      => self::clip( Agend_Apps_Log_Redactor::path_template( $path ), 191 ),
			'query'              => self::clip( $query, self::TEXT_COLUMN_LIMIT ),
			'status'             => $status,
			'outcome'            => self::clip( $outcome, 24 ),
			'duration_ms'        => isset( $entry['duration_ms'] ) ? max( 0, (int) $entry['duration_ms'] ) : 0,
			'attempt'            => max( 1, (int) ( $entry['attempt'] ?? 1 ) ),
			'request_bytes'      => strlen( $request_raw ),
			'response_bytes'     => strlen( $response_raw ),
			'gateway_request_id' => self::clip( (string) preg_replace( '/[^A-Za-z0-9._:\-]/', '', (string) ( $entry['gateway_request_id'] ?? $headers['x-request-id'] ?? $headers['x-vercel-id'] ?? '' ) ), 64 ),
			'event_type'         => self::clip( (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) ( $entry['event_type'] ?? '' ) ), 64 ),
			'auth_mode'          => self::clip( (string) ( $entry['auth_mode'] ?? '' ), 12 ),
			'user_id'            => max( 0, $user_id ),
			'user_hash'          => $user_hash,
			'context'            => self::context(),
			'page_path'          => self::clip( $this->page_path(), 500 ),
			'ip_prefix'          => self::ip_prefix( self::remote_ip() ),
			'error_code'         => self::clip( (string) ( $entry['error_code'] ?? '' ), 64 ),
			'error_message'      => self::clip( $redactor->redact_text( (string) ( $entry['error_message'] ?? '' ) ), self::TEXT_COLUMN_LIMIT ),
			'request_body'       => $request_body,
			'response_body'      => $response_body,
			'response_headers'   => $headers ? (string) wp_json_encode( $headers ) : '',
			'truncated'          => $truncated ? 1 : 0,
		);

		$redactor->forget();

		// One row with invalid UTF-8 would make wpdb refuse the whole batch.
		foreach ( $row as $column => $value ) {
			if ( is_string( $value ) ) {
				$row[ $column ] = self::valid_utf8( $value );
			}
		}

		return $row;
	}

	/**
	 * Whether a body is small enough to redact and keep.
	 */
	private function body_within_limits( string $raw, bool $failed ): bool {
		return strlen( $raw ) <= ( $failed ? self::FAILED_BODY_LIMIT : self::SUCCESS_REDACT_LIMIT );
	}

	private static function oversized( string $raw ): string {
		return sprintf( '[body not stored: %d bytes, over the size limit]', strlen( $raw ) );
	}

	private static function valid_utf8( string $value ): string {
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return function_exists( 'mb_scrub' ) ? mb_scrub( $value, 'UTF-8' ) : (string) iconv( 'UTF-8', 'UTF-8//IGNORE', $value );
		}

		return $value;
	}

	/**
	 * The WordPress user a call was made for, or null.
	 */
	private function user( int $user_id ): ?object {
		if ( $user_id <= 0 ) {
			return null;
		}

		$user = get_user_by( 'id', $user_id );

		return is_object( $user ) ? $user : null;
	}

	/**
	 * Entries waiting to be written. For the test suite and diagnostics.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function pending(): array {
		return $this->buffer;
	}

	/**
	 * Writes the buffer. Called on shutdown, and early by long-running
	 * processes once the threshold is reached.
	 */
	public function flush(): void {
		if ( empty( $this->buffer ) ) {
			return;
		}

		$rows         = $this->buffer;
		$this->buffer = array();

		try {
			if ( $this->store->insert_many( $rows ) ) {
				return;
			}

			// A site updated without reactivation may not have the table yet.
			if ( ! Agend_Apps_Log_Store::exists() ) {
				Agend_Apps_Log_Store::install();
			}

			// One bad row must not take the rest of the batch with it.
			$dropped = 0;

			foreach ( $rows as $row ) {
				if ( ! $this->store->insert_many( array( $row ) ) ) {
					++$dropped;
				}
			}

			if ( $dropped > 0 ) {
				self::debug( sprintf( 'API log write failed (%d of %d rows dropped).', $dropped, count( $rows ) ) );
			}
		} catch ( \Throwable $e ) {
			self::debug( 'API log write failed: ' . get_class( $e ) );
		}
	}

	private function hook_shutdown(): void {
		if ( $this->shutdown_hooked ) {
			return;
		}

		$this->shutdown_hooked = true;

		// Late, so entries recorded by other shutdown callbacks still land.
		add_action( 'shutdown', array( $this, 'flush' ), 9999 );
	}

	/**
	 * Daily cron callback.
	 */
	public static function prune(): int {
		$deleted = ( new Agend_Apps_Log_Store() )->prune( self::retention_days() );

		// A run that hit its cap leaves a backlog; continue shortly rather
		// than letting a busy site's table outgrow the daily run.
		if ( $deleted >= Agend_Apps_Log_Store::PRUNE_BATCH * Agend_Apps_Log_Store::PRUNE_MAX_BATCHES ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_HOOK );
		}

		return $deleted;
	}

	/**
	 * Schedules the daily prune when it is missing. Cheap enough for `init`:
	 * wp_next_scheduled reads the already-loaded cron option.
	 */
	public static function ensure_cron(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function clear_cron(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	private static function context(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		if ( wp_doing_ajax() ) {
			return 'ajax';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		return is_admin() ? 'admin' : 'web';
	}

	/**
	 * The page that triggered the call: path only. The query string is
	 * dropped rather than redacted, because front-end query strings are
	 * unstructured and the referrer is not stored at all.
	 */
	private function page_path(): string {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$uri      = wp_unslash( (string) $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$segments = explode( '/', (string) strtok( $uri, '?' ) );

		// Only the first segment is kept readable (`/events/`, `/my-account/`):
		// any deeper segment may be a member profile slug under a base this
		// plugin cannot know (`/find-a-physio/jane-smith/`, `/author/jane/`).
		foreach ( $segments as $index => $segment ) {
			if ( $index > 1 && '' !== $segment && 1 !== preg_match( '/^\d+$/', $segment ) ) {
				$segments[ $index ] = ':slug';
			}
		}

		return $this->redactor()->redact_path( implode( '/', $segments ) );
	}

	private static function remote_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	private static function clip( string $value, int $length ): string {
		return strlen( $value ) > $length ? self::cut( $value, $length ) : $value;
	}

	/**
	 * Cuts on a character boundary so a multibyte character is never split
	 * into invalid UTF-8.
	 */
	private static function cut( string $value, int $length ): string {
		return function_exists( 'mb_strcut' ) ? mb_strcut( $value, 0, $length, 'UTF-8' ) : substr( $value, 0, $length );
	}

	private static function debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}

/**
 * The shared API logger.
 */
function agend_apps_logger(): Agend_Apps_Logger {
	return Agend_Apps_Logger::instance();
}

/**
 * Records an HTTP exchange in the Agend API log, with the same redaction the
 * Core client gets.
 *
 * For sibling plugins that call third-party systems directly (Dataverse,
 * OAuth token endpoints, custom upstreams). Guard the call with
 * `function_exists( 'agend_apps_log_http' )`. Never pass request headers:
 * there is deliberately no field for them.
 *
 * @param array<string, mixed> $entry {
 *     @type string   $source        Your plugin slug, e.g. `agend-directory-sync`. Required.
 *     @type string   $direction     `outbound` (default) or `inbound`.
 *     @type string   $method        HTTP method.
 *     @type string   $url           Full URL.
 *     @type int|null $status        HTTP status, or null for a transport failure.
 *     @type int      $duration_ms   Round-trip time.
 *     @type string   $request_body  Raw request body.
 *     @type string   $response_body Raw response body.
 *     @type array    $response_headers Response headers.
 *     @type string   $error         Error message, when the call failed.
 * }
 */
function agend_apps_log_http( array $entry ): void {
	if ( isset( $entry['error'] ) && ! isset( $entry['error_message'] ) ) {
		$entry['error_message'] = (string) $entry['error'];
		$entry['error_code']    = $entry['error_code'] ?? 'error';
	}

	unset( $entry['error'], $entry['request_headers'], $entry['headers'] );

	agend_apps_logger()->record( $entry );
}
