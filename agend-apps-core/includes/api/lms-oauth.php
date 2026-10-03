<?php
/** Opt-in trusted LMS authorization-request client. @package Agend_Apps_Core */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function agend_apps_lms_oauth_config(): array {
	$config = array();
	foreach ( array( 'ENABLED', 'ORIGIN', 'CLIENT_ID', 'CLIENT_SECRET', 'REDIRECT_URI' ) as $name ) {
		$constant = 'AGEND_LMS_OAUTH_' . $name;
		$config[ strtolower( $name ) ] = defined( $constant ) ? constant( $constant ) : '';
	}
	return $config;
}

function agend_apps_lms_oauth_valid_config( array $config ): bool {
	if ( true !== ( $config['enabled'] ?? false ) ) {
		return false;
	}
	foreach ( array( 'origin', 'client_id', 'client_secret', 'redirect_uri' ) as $field ) {
		if ( ! isset( $config[ $field ] ) || ! is_string( $config[ $field ] ) || '' === $config[ $field ] ) {
			return false;
		}
	}
	$origin = wp_parse_url( $config['origin'] );
	if ( ! is_array( $origin ) || 'https' !== ( $origin['scheme'] ?? '' ) || empty( $origin['host'] ) ) {
		return false;
	}
	foreach ( array( 'user', 'pass', 'query', 'fragment' ) as $field ) {
		if ( isset( $origin[ $field ] ) ) {
			return false;
		}
	}
	if ( ! empty( $origin['path'] ) && '/' !== $origin['path'] ) {
		return false;
	}
	$callback = admin_url( 'admin-post.php?action=agend_lms_oauth_callback', 'https' );
	return 'https' === wp_parse_url( $config['redirect_uri'], PHP_URL_SCHEME ) && $callback === $config['redirect_uri'];
}

/** Does not use the gateway logger: OAuth request bodies and URLs contain credentials. */
function agend_apps_lms_oauth_post( array $config, string $path, array $body, bool $json ) {
	$response = wp_remote_request(
		rtrim( $config['origin'], '/' ) . $path,
		array(
			'method'             => 'POST',
			'timeout'            => 10,
			'redirection'        => 0,
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
			'limit_response_size' => 16384,
			'headers'            => array(
				'Content-Type' => $json ? 'application/json' : 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
			'body'               => $json ? wp_json_encode( $body ) : http_build_query( $body, '', '&', PHP_QUERY_RFC3986 ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'lms_oauth_unavailable', 'Unable to connect. Restart from WordPress.' );
	}
	$status = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ( $json ? 201 : 200 ) !== $status || ! is_array( $data ) ) {
		return new WP_Error( 'lms_oauth_rejected', 'Unable to connect. Restart from WordPress.' );
	}
	return $data;
}
