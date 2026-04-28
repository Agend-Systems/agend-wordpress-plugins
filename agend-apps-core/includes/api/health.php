<?php
/**
 * Health API functions.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks the Agend service health without authentication.
 *
 * Calls the unversioned `/api/health` endpoint directly using
 * `wp_remote_get()`. This endpoint requires no API key and is used to
 * verify that the Agend Gateway is reachable.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_service_health() {
	$url = Agend_Apps_Settings::get_root_url() . '/api/health';

	/**
	 * Filters the service health request args before the request is sent.
	 *
	 * @param array $args The wp_remote_get args.
	 */
	$args = (array) apply_filters(
		'agend_apps_service_health_args',
		array(
			'headers' => array(
				'Accept' => 'application/json',
			),
			'timeout' => 15,
		)
	);

	$response = wp_remote_get( $url, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status_code = (int) wp_remote_retrieve_response_code( $response );
	$body        = wp_remote_retrieve_body( $response );
	$decoded     = json_decode( $body, true );

	if ( null === $decoded && '' !== $body ) {
		return new WP_Error(
			'agend_apps_invalid_response',
			__( 'Invalid JSON response from Agend API.', 'agend-apps-core' ),
			array( 'body' => $body )
		);
	}

	if ( $status_code < 200 || $status_code >= 300 ) {
		$message = isset( $decoded['error']['message'] )
			? $decoded['error']['message']
			: __( 'An unknown API error occurred.', 'agend-apps-core' );

		return new WP_Error(
			'agend_api_error',
			$message,
			array(
				'status_code' => $status_code,
				'body'        => $decoded,
			)
		);
	}

	/**
	 * Filters the decoded service health response before it is returned.
	 *
	 * @param array $decoded     Decoded response body.
	 * @param int   $status_code HTTP status code.
	 */
	return apply_filters( 'agend_apps_service_health_response', $decoded, $status_code );
}

/**
 * Verifies the configured API key against the Agend Gateway.
 *
 * Calls `GET /v1/health` (versioned, authenticated) and returns the
 * decoded payload containing the key's scopes and authorised app IDs.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_verify_api_key() {
	/**
	 * Filters the verify-API-key request args before the request is sent.
	 *
	 * @param array $args Request args passed to Agend_Apps_API::request().
	 */
	$args = (array) apply_filters( 'agend_apps_verify_api_key_args', array() );

	$response = agend_apps_api()->request( 'GET', '/health', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded verify-API-key response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_verify_api_key_response', $response );
}
