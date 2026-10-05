<?php
/**
 * Logger.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight logger that keeps a rolling buffer of recent sync events in a
 * WordPress option so the admin screen can show what happened without a
 * separate log store. Also forwards to the PHP error log when WP_DEBUG is on.
 */
class Agend_Loop_Sync_Logger {

	/**
	 * Option name for the rolling log buffer.
	 *
	 * @var string
	 */
	const OPTION = 'agend_loop_sync_log';

	/**
	 * Maximum number of entries retained in the buffer.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 200;

	/**
	 * Largest JSON size, in bytes, kept for one entry's context. The buffer is a
	 * single option row, so an unbounded context (a full committee roster) would
	 * bloat it and be rewritten on every log call.
	 *
	 * @var int
	 */
	const MAX_CONTEXT_BYTES = 2000;

	/**
	 * Context keys that carry personal data and are never stored.
	 *
	 * @var string[]
	 */
	const REDACTED_KEYS = array( 'email', 'display_name', 'first_name', 'last_name', 'avatar_url' );

	/**
	 * Records a log entry.
	 *
	 * @param string $level   One of 'info', 'warning', 'error'.
	 * @param string $message Human-readable message.
	 * @param array  $context Optional. Structured context.
	 * @return void
	 */
	public static function log( string $level, string $message, array $context = array() ) {
		$context = self::sanitize_context( $context );

		$entry = array(
			'time'    => time(),
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);

		$buffer = get_option( self::OPTION, array() );
		if ( ! is_array( $buffer ) ) {
			$buffer = array();
		}

		$buffer[] = $entry;
		if ( count( $buffer ) > self::MAX_ENTRIES ) {
			$buffer = array_slice( $buffer, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION, $buffer, false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				sprintf( '[Agend Loop Sync][%s] %s %s', $level, $message, wp_json_encode( $context ) )
			);
		}
	}

	/**
	 * Drops personal data from a context and caps its size.
	 *
	 * @param array $context Raw context.
	 * @return array Context safe to store and to write to the PHP error log.
	 */
	public static function sanitize_context( array $context ): array {
		$context = self::strip_redacted( $context );
		$json    = wp_json_encode( $context );

		if ( is_string( $json ) && strlen( $json ) > self::MAX_CONTEXT_BYTES ) {
			return array(
				'truncated' => true,
				'preview'   => substr( $json, 0, self::MAX_CONTEXT_BYTES ),
			);
		}

		return $context;
	}

	/**
	 * Recursively removes the redacted keys.
	 *
	 * @param array $data Data to clean.
	 * @return array
	 */
	private static function strip_redacted( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, self::REDACTED_KEYS, true ) ) {
				unset( $data[ $key ] );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::strip_redacted( $value );
			}
		}

		return $data;
	}

	/**
	 * Records an info entry.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional. Context.
	 * @return void
	 */
	public static function info( string $message, array $context = array() ) {
		self::log( 'info', $message, $context );
	}

	/**
	 * Records a warning entry.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional. Context.
	 * @return void
	 */
	public static function warning( string $message, array $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Records an error entry.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional. Context.
	 * @return void
	 */
	public static function error( string $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Returns the most recent log entries, newest first.
	 *
	 * @param int $limit Optional. Number of entries to return. Default 50.
	 * @return array List of log entries.
	 */
	public static function recent( int $limit = 50 ): array {
		$buffer = get_option( self::OPTION, array() );
		if ( ! is_array( $buffer ) ) {
			return array();
		}

		return array_slice( array_reverse( $buffer ), 0, $limit );
	}
}
