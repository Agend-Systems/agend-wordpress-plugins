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
	 * Records a log entry.
	 *
	 * @param string $level   One of 'info', 'warning', 'error'.
	 * @param string $message Human-readable message.
	 * @param array  $context Optional. Structured context.
	 * @return void
	 */
	public static function log( string $level, string $message, array $context = array() ) {
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
