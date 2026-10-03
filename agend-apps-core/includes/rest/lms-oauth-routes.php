<?php
/** Trusted LMS OAuth start and login-session-bound callback. @package Agend_Apps_Core */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agend_Apps_LMS_OAuth_Controller {
	public const TRANSACTION_PREFIX = '_agend_lms_oauth_tx_';
	public const TOKEN_META = '_agend_lms_oauth_tokens';
	public const EXPIRY_HOOK = 'agend_apps_lms_oauth_expire';
	private $config;

	public function __construct( array $config = array() ) {
		$this->config = $config ?: agend_apps_lms_oauth_config();
	}

	public function register_routes(): void {
		if ( ! agend_apps_lms_oauth_valid_config( $this->config ) ) {
			return;
		}
		register_rest_route( 'agend-apps/v1', '/lms/oauth/start', array(
			'methods' => 'POST',
			'callback' => array( $this, 'start' ),
			'permission_callback' => array( $this, 'start_permission' ),
		) );
	}

	public function start_permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_user_logged_in() || ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) || '' === wp_get_session_token() ) {
			return new WP_Error( 'lms_oauth_forbidden', 'Sign in to WordPress and retry.', array( 'status' => 403 ) );
		}
		return true;
	}

	private function result( string $status, int $code = 400, string $url = '' ): WP_REST_Response {
		$data = array( 'status' => $status );
		if ( '' !== $url ) {
			$data['authorization_url'] = $url;
		}
		$response = new WP_REST_Response( $data, $code );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Referrer-Policy', 'no-referrer' );
		return $response;
	}

	public function start( WP_REST_Request $request ): WP_REST_Response {
		if ( true !== $this->start_permission( $request ) ) {
			return $this->result( 'retry', 403 );
		}
		if ( ! agend_apps_lms_oauth_valid_config( $this->config ) ) {
			return $this->result( 'unavailable', 503 );
		}
		foreach ( array( 'wp_user_id', 'wp_user_email', 'wp_user_name', 'client_id', 'client_secret', 'redirect_uri', 'state', 'scope', 'code_verifier', 'code_challenge', 'request_uri', 'origin' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				return $this->result( 'retry' );
			}
		}
		$user = wp_get_current_user();
		if ( $user->ID <= 0 || ! is_string( $user->user_email ) || ! is_email( $user->user_email ) || ! is_string( $user->display_name ) ) {
			return $this->result( 'retry', 403 );
		}
		$state = self::random_token();
		$verifier = self::random_token();
		$key = self::TRANSACTION_PREFIX . hash( 'sha256', $state );
		$transaction = array(
			'user_id' => (int) $user->ID,
			'session_hash' => hash( 'sha256', wp_get_session_token() ),
			'state' => $state,
			'verifier' => $verifier,
			'expires_at' => time() + 600,
			'origin' => $this->config['origin'],
			'client_id' => $this->config['client_id'],
			'redirect_uri' => $this->config['redirect_uri'],
		);
		$encoded = wp_json_encode( $transaction );
		if ( ! is_string( $encoded ) || ! add_option( $key, $encoded, '', false ) ) {
			return $this->result( 'unavailable', 503 );
		}
		if ( ! wp_schedule_single_event( $transaction['expires_at'], self::EXPIRY_HOOK, array( $key ) ) ) {
			delete_option( $key );
			return $this->result( 'unavailable', 503 );
		}
		$data = agend_apps_lms_oauth_post( $this->config, '/api/v1/oauth/requests', array(
			'client_id' => $this->config['client_id'],
			'client_secret' => $this->config['client_secret'],
			'redirect_uri' => $this->config['redirect_uri'],
			'response_type' => 'code',
			'scope' => 'openid profile email',
			'state' => $state,
			'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
			'code_challenge_method' => 'S256',
			'wp_user_id' => (string) $user->ID,
			'wp_user_email' => $user->user_email,
			'wp_user_name' => $user->display_name,
		), true );
		if ( is_wp_error( $data ) || ! isset( $data['request_uri'], $data['expires_in'] ) || ! is_string( $data['request_uri'] ) || ! preg_match( '/^urn:agend:oauth:request:[A-Za-z0-9_-]{32,128}$/D', $data['request_uri'] ) || ! is_int( $data['expires_in'] ) || $data['expires_in'] <= 0 || $data['expires_in'] > 600 ) {
			delete_option( $key );
			return $this->result( 'retry', 502 );
		}
		$transaction['expires_at'] = min( $transaction['expires_at'], time() + $data['expires_in'] );
		if ( ! update_option( $key, wp_json_encode( $transaction ), false ) && get_option( $key ) !== wp_json_encode( $transaction ) ) {
			delete_option( $key );
			return $this->result( 'unavailable', 503 );
		}
		$url = rtrim( $this->config['origin'], '/' ) . '/api/v1/oauth/authorize?' . http_build_query( array( 'client_id' => $this->config['client_id'], 'request_uri' => $data['request_uri'] ), '', '&', PHP_QUERY_RFC3986 );
		return $this->result( 'continue', 200, $url );
	}

	public function callback( WP_REST_Request $request ): WP_REST_Response {
		$state = $request->get_param( 'state' );
		if ( ! agend_apps_lms_oauth_valid_config( $this->config ) || ! is_user_logged_in() || '' === wp_get_session_token() || ! is_string( $state ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/D', $state ) ) {
			return $this->result( 'retry' );
		}
		$key = self::TRANSACTION_PREFIX . hash( 'sha256', $state );
		$encoded = get_option( $key, '' );
		$transaction = is_string( $encoded ) ? json_decode( $encoded, true ) : null;
		if ( ! is_array( $transaction ) || ( $transaction['user_id'] ?? 0 ) !== (int) wp_get_current_user()->ID || ! hash_equals( (string) ( $transaction['session_hash'] ?? '' ), hash( 'sha256', wp_get_session_token() ) ) || ! hash_equals( (string) ( $transaction['state'] ?? '' ), $state ) ) {
			return $this->result( 'retry' );
		}
		foreach ( array( 'origin', 'client_id', 'redirect_uri' ) as $field ) {
			if ( ( $transaction[ $field ] ?? '' ) !== $this->config[ $field ] ) {
				return $this->result( 'retry' );
			}
		}
		// Compare-and-delete is the cross-worker single-use gate, before any token exchange.
		global $wpdb;
		$consumed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $encoded ) );
		wp_cache_delete( $key, 'options' );
		if ( 1 !== $consumed ) {
			return $this->result( 'retry' );
		}
		$code = $request->get_param( 'code' );
		if ( ( $transaction['expires_at'] ?? 0 ) <= time() || null !== $request->get_param( 'error' ) || ! is_string( $code ) || '' === $code || strlen( $code ) > 4096 || ! is_string( $transaction['verifier'] ?? null ) ) {
			return $this->result( 'retry' );
		}
		$data = agend_apps_lms_oauth_post( $this->config, '/api/v1/oauth/token', array(
			'grant_type' => 'authorization_code',
			'client_id' => $this->config['client_id'],
			'client_secret' => $this->config['client_secret'],
			'code' => $code,
			'redirect_uri' => $this->config['redirect_uri'],
			'code_verifier' => $transaction['verifier'],
		), false );
		if ( is_wp_error( $data ) || ! is_string( $data['access_token'] ?? null ) || '' === $data['access_token'] || ! is_string( $data['refresh_token'] ?? null ) || '' === $data['refresh_token'] || ! is_int( $data['expires_in'] ?? null ) || $data['expires_in'] <= 0 || $data['expires_in'] > 86400 || ! is_string( $data['token_type'] ?? null ) || 0 !== strcasecmp( $data['token_type'], 'bearer' ) ) {
			return $this->result( 'retry', 502 );
		}
		$stored = update_user_meta( $transaction['user_id'], self::TOKEN_META, array(
			'client_id' => $this->config['client_id'],
			'origin' => $this->config['origin'],
			'access_token' => $data['access_token'],
			'refresh_token' => $data['refresh_token'],
			'expires_at' => time() + $data['expires_in'],
		) );
		return false === $stored ? $this->result( 'retry', 503 ) : $this->result( 'connected', 200 );
	}

	private static function random_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	public static function expire( string $key ): void {
		if ( ! preg_match( '/^_agend_lms_oauth_tx_[a-f0-9]{64}$/D', $key ) ) {
			return;
		}
		$data = json_decode( (string) get_option( $key, '' ), true );
		if ( is_array( $data ) && ( $data['expires_at'] ?? PHP_INT_MAX ) <= time() ) {
			delete_option( $key );
		}
	}
}

function agend_apps_lms_oauth_callback(): void {
	$request = new WP_REST_Request( 'GET' );
	foreach ( array( 'state', 'code', 'error' ) as $field ) {
		if ( isset( $_GET[ $field ] ) ) {
			$request->set_param( $field, wp_unslash( $_GET[ $field ] ) );
		}
	}
	$result = ( new Agend_Apps_LMS_OAuth_Controller() )->callback( $request );
	header( 'Cache-Control: no-store' );
	header( 'Referrer-Policy: no-referrer' );
	$connected = 200 === $result->get_status();
	wp_safe_redirect( home_url( '/?agend_lms_oauth=' . ( $connected ? 'connected' : 'retry' ) ), 303 );
	exit;
}

add_action( 'rest_api_init', static function () { ( new Agend_Apps_LMS_OAuth_Controller() )->register_routes(); } );
add_action( 'admin_post_agend_lms_oauth_callback', 'agend_apps_lms_oauth_callback' );
add_action( 'admin_post_nopriv_agend_lms_oauth_callback', 'agend_apps_lms_oauth_callback' );
add_action( Agend_Apps_LMS_OAuth_Controller::EXPIRY_HOOK, array( 'Agend_Apps_LMS_OAuth_Controller', 'expire' ) );
