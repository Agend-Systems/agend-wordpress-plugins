<?php
/**
 * Directory listing enquiry API client.
 *
 * Kept apart from directory.php so the enquiry call can be loaded and tested
 * on its own.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends an enquiry to the owner of a directory listing.
 *
 * Requires the `directory.enquiries.send` scope on the API key. Not cached.
 * The gateway sends the emails; WordPress sends none. The listing's email
 * address is never returned.
 *
 * The call is made without the member token: an enquiry is a visitor's
 * message, never a member's, so no signed-in identity rides along.
 *
 * @param string $listing_id The listing slug or UUID.
 * @param array  $enquiry {
 *     Enquiry payload (snake_case). See the gateway enquiry schema.
 *
 *     @type string $first_name     First name (1 to 100 chars). Required.
 *     @type string $last_name      Last name (1 to 100 chars). Required.
 *     @type string $email          Visitor email (max 254 chars). Required.
 *     @type string $phone          Visitor phone (max 40 chars). Optional.
 *     @type string $message        Message (10 to 5000 chars). Required.
 *     @type bool   $terms_accepted Whether the visitor accepted the listing's terms. Optional.
 * }
 * @param string $visitor_ip The visitor's IP address for per-visitor rate limiting, or ''.
 * @return array|WP_Error Decoded response on success, or WP_Error on failure.
 */
function agend_apps_directory_send_listing_enquiry( string $listing_id, array $enquiry, string $visitor_ip = '' ) {
	$args = array(
		'body'       => $enquiry,
		'unattended' => true,
		// The gateway limits enquiries per visitor and per key. Its refusal must
		// not become the site-wide rate-limit state that stops every other call.
		'isolated_rate_limit' => true,
	);

	if ( '' !== $visitor_ip ) {
		$args['headers'] = array( 'x-agend-visitor-ip' => $visitor_ip );
	}

	/**
	 * Filters the send-enquiry request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $listing_id Listing slug or UUID.
	 * @param array  $enquiry    Enquiry payload.
	 */
	$args = (array) apply_filters( 'agend_apps_directory_send_listing_enquiry_args', $args, $listing_id, $enquiry );

	$response = agend_apps_api()->request( 'POST', '/directory/listings/' . rawurlencode( $listing_id ) . '/enquiries', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded send-enquiry response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $listing_id Listing slug or UUID.
	 */
	return apply_filters( 'agend_apps_directory_send_listing_enquiry_response', $response, $listing_id );
}
