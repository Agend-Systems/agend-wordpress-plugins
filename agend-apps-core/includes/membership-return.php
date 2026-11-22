<?php
/**
 * Completes a paid membership join when the member returns from the payment
 * page, the way the shop's booking confirmation completes a paid booking.
 *
 * A membership checkout settles when the platform hears from the payment
 * provider. Where that notice never arrives (a platform with no webhook
 * delivery, or one delayed), the member came back to a static "Thank you",
 * no membership was created and the order's cart stayed locked.
 *
 * So the join's success URL now carries a return reference
 * (`agend_membership_ref=<token>`). Only a hash of the token is kept, with the
 * checkout session the platform opened, bound to the signed-in WordPress user
 * and replaced by their next join with a different session (a reused session
 * keeps every reference it was handed out with). On the return, the memberships catalogue
 * posts the token to `POST /agend-apps/v1/crm/me/memberships/return-confirm`,
 * which, for that user's own latest join only, asks the platform what became
 * of the payment: `GET /cart/checkout/status` where the platform has it, else
 * `POST /cart/checkout/complete`, which confirms the payment with the provider
 * and fulfils the order once (a repeat call returns the same order). A
 * confirmed result is kept, so reloads ask nothing more.
 *
 * The answer is `confirmed`, `pending` (not confirmed yet: try again), or
 * `unknown` (the platform could not say). Never "failed": a member returning
 * from the payment page may well have paid.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Query argument carrying the membership return reference. */
const AGEND_APPS_MEMBERSHIP_RETURN_ARG = 'agend_membership_ref';

/** How long a return reference is honoured. */
const AGEND_APPS_MEMBERSHIP_RETURN_TTL = DAY_IN_SECONDS;

/**
 * The transient holding a user's latest membership join.
 *
 * @param int $user_id WordPress user id.
 * @return string
 */
function agend_apps_membership_return_key( int $user_id ): string {
	return 'agend_mem_return_' . hash_hmac( 'sha256', 'user:' . $user_id, wp_salt( 'auth' ) );
}

/**
 * The stored form of a return token: never the token itself.
 *
 * @param string $token Return token.
 * @return string
 */
function agend_apps_membership_return_hash( string $token ): string {
	return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
}

/**
 * The reference hashes a stored join accepts.
 *
 * @param array $record Stored join.
 * @return string[]
 */
function agend_apps_membership_return_hashes( array $record ): array {
	$hashes = is_array( $record['hashes'] ?? null ) ? $record['hashes'] : array();
	return array_values( array_filter( $hashes, 'is_string' ) );
}

/**
 * Whether a token is one of a stored join's references.
 *
 * @param array  $record Stored join.
 * @param string $token  Return token.
 * @return bool
 */
function agend_apps_membership_return_matches( array $record, string $token ): bool {
	$hash = agend_apps_membership_return_hash( $token );
	foreach ( agend_apps_membership_return_hashes( $record ) as $candidate ) {
		if ( hash_equals( $candidate, $hash ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a URL is on this site.
 *
 * @param string $url Address.
 * @return bool
 */
function agend_apps_membership_return_is_local( string $url ): bool {
	$target = wp_parse_url( $url );
	$home   = wp_parse_url( home_url() );
	return is_array( $target ) && is_array( $home )
		&& isset( $target['host'], $home['host'] )
		&& strtolower( $target['host'] ) === strtolower( $home['host'] )
		&& ! isset( $target['user'] ) && ! isset( $target['pass'] );
}

/**
 * Asks the platform what became of one checkout session.
 *
 * `GET /cart/checkout/status` is asked first while the platform is not known
 * to lack it; a 404 naming the endpoint is remembered for a day. Then
 * `POST /cart/checkout/complete` through {@see agend_apps_cart_checkout_complete()},
 * which confirms the payment with the provider before fulfilling, and returns
 * the same order to a repeat call.
 *
 * @param string $external_id Checkout session id the platform opened.
 * @param string $cart        Guest cart session token, or ''.
 * @param string $bearer      Member bearer token, or ''.
 * @return array{status: string, order_number?: string} `confirmed`, `pending` or `unknown`.
 */
function agend_apps_cart_confirm_session( string $external_id, string $cart, string $bearer ): array {
	if ( '' === $external_id ) {
		return array( 'status' => 'unknown' );
	}

	if ( false === get_transient( 'agend_apps_checkout_status_missing' ) ) {
		$response = agend_apps_api()->request(
			'GET',
			'/cart/checkout/status',
			array(
				'query'        => array( 'externalId' => $external_id ),
				'cart_session' => $cart,
				'bearer_token' => $bearer,
				'timeout'      => 10,
			)
		);
		if ( ! is_wp_error( $response ) ) {
			$data   = is_array( $response['data'] ?? null ) ? $response['data'] : array();
			$status = (string) ( $data['status'] ?? '' );
			if ( 'confirmed' === $status ) {
				$receipt = is_array( $data['receipt'] ?? null ) ? $data['receipt'] : array();
				return array_filter(
					array(
						'status'       => 'confirmed',
						'order_number' => is_string( $receipt['order_number'] ?? null ) ? $receipt['order_number'] : '',
					)
				);
			}
			return array( 'status' => 'pending' === $status ? 'pending' : 'unknown' );
		}
		$data    = $response->get_error_data();
		$message = is_array( $data ) && is_array( $data['body']['error'] ?? null ) ? (string) ( $data['body']['error']['message'] ?? '' ) : '';
		if ( ! ( is_array( $data ) && 404 === ( $data['status_code'] ?? null ) && false !== stripos( $message, 'endpoint' ) ) ) {
			return array( 'status' => 'unknown' );
		}
		set_transient( 'agend_apps_checkout_status_missing', 1, DAY_IN_SECONDS );
	}

	if ( ! function_exists( 'agend_apps_cart_checkout_complete' ) ) {
		return array( 'status' => 'unknown' );
	}
	$response = agend_apps_cart_checkout_complete( $cart, $bearer, array( 'externalId' => $external_id ) );
	if ( ! is_wp_error( $response ) ) {
		$order = is_array( $response['data'] ?? null ) ? $response['data'] : array();
		if ( empty( $order['order_id'] ) && empty( $order['order_number'] ) ) {
			return array( 'status' => 'unknown' );
		}
		return array_filter(
			array(
				'status'       => 'confirmed',
				'order_number' => is_string( $order['order_number'] ?? null ) ? $order['order_number'] : '',
			)
		);
	}

	// 402: the provider has not confirmed the payment yet. 409: another
	// request (the payment webhook) is finishing the same checkout, or the
	// payment is under review. Both clear on their own.
	$data   = $response->get_error_data();
	$status = is_array( $data ) ? ( $data['status_code'] ?? null ) : null;
	return array( 'status' => ( 402 === $status || 409 === $status ) ? 'pending' : 'unknown' );
}

/**
 * Registers the return reference on membership joins, and its route.
 */
function agend_apps_register_membership_return(): void {
	$token = '';

	// A same-site success URL gets a fresh reference. A join by someone not
	// signed in to WordPress, or returning off-site, gets none.
	add_filter(
		'agend_apps_crm_purchase_my_membership_args',
		static function ( array $args ) use ( &$token ): array {
			$token   = '';
			$user_id = get_current_user_id();
			$success = $args['body']['success_url'] ?? '';
			if ( $user_id <= 0 || ! is_string( $success ) || '' === $success || ! agend_apps_membership_return_is_local( $success ) ) {
				return $args;
			}
			$token                       = wp_generate_password( 32, false, false );
			$args['body']['success_url'] = add_query_arg( AGEND_APPS_MEMBERSHIP_RETURN_ARG, $token, $success );
			return $args;
		}
	);

	// The reference is kept with the checkout session once the platform has
	// opened it.
	add_filter(
		'agend_apps_crm_purchase_my_membership_response',
		static function ( $response ) use ( &$token ) {
			$user_id = get_current_user_id();
			$data    = is_array( $response ) && is_array( $response['data'] ?? null ) ? $response['data'] : ( is_array( $response ) ? $response : array() );
			$session = $data['external_id'] ?? ( $data['externalId'] ?? '' );
			if ( '' !== $token && $user_id > 0 && is_string( $session ) && '' !== $session && strlen( $session ) <= 255 ) {
				// The platform may hand back a checkout it opened earlier, whose
				// hosted page still returns with that earlier reference: for the
				// same session every reference stays valid. A different session
				// replaces them all.
				$key      = agend_apps_membership_return_key( $user_id );
				$previous = get_transient( $key );
				$hashes   = array();
				if ( is_array( $previous ) && ( $previous['session'] ?? '' ) === $session ) {
					$hashes = agend_apps_membership_return_hashes( $previous );
				}
				$hashes[] = agend_apps_membership_return_hash( $token );
				set_transient(
					$key,
					array(
						'hashes'    => array_slice( array_values( array_unique( $hashes ) ), -5 ),
						'session'   => $session,
						'confirmed' => is_array( $previous ) && ( $previous['session'] ?? '' ) === $session && ! empty( $previous['confirmed'] ),
					),
					AGEND_APPS_MEMBERSHIP_RETURN_TTL
				);
			} elseif ( $user_id > 0 && '' !== $token ) {
				// The join opened no checkout this site can follow: forget the
				// earlier one, as a new join supersedes it.
				delete_transient( agend_apps_membership_return_key( $user_id ) );
			}
			$token = '';
			return $response;
		}
	);

	add_action(
		'rest_api_init',
		static function (): void {
			register_rest_route(
				'agend-apps/v1',
				'/crm/me/memberships/return-confirm',
				array(
					'methods'             => 'POST',
					'callback'            => 'agend_apps_membership_return_confirm',
					'permission_callback' => static function ( $request ): bool {
						$nonce = is_object( $request ) && method_exists( $request, 'get_header' ) ? $request->get_header( 'X-WP-Nonce' ) : '';
						return get_current_user_id() > 0 && false !== wp_verify_nonce( (string) $nonce, 'wp_rest' );
					},
					'args'                => array(
						'token' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				)
			);
		}
	);
}

/**
 * Confirms the signed-in member's latest membership join on their return from
 * the payment page. Safe to call again: a confirmed join is remembered.
 *
 * @param WP_REST_Request $request Current request.
 * @return WP_REST_Response 200 with `status` (`confirmed`, `pending`,
 *                          `unknown`, or `none` for a reference that is not
 *                          this member's latest join) and, when confirmed,
 *                          `portalUrl`.
 */
function agend_apps_membership_return_confirm( WP_REST_Request $request ): WP_REST_Response {
	$token   = (string) $request->get_param( 'token' );
	$user_id = get_current_user_id();
	$key     = agend_apps_membership_return_key( $user_id );
	$record  = $user_id > 0 ? get_transient( $key ) : false;

	if ( '' === $token || strlen( $token ) > 64 || ! is_array( $record ) || ! agend_apps_membership_return_matches( $record, $token ) ) {
		return new WP_REST_Response( array( 'status' => 'none' ), 200 );
	}

	$portal = '';
	if ( class_exists( 'Agend_Apps_Settings' ) ) {
		$portal = method_exists( 'Agend_Apps_Settings', 'get_portal_home_url' )
			? (string) Agend_Apps_Settings::get_portal_home_url()
			: (string) Agend_Apps_Settings::get_portal_url();
	}

	if ( ! empty( $record['confirmed'] ) ) {
		return new WP_REST_Response( array( 'status' => 'confirmed', 'portalUrl' => esc_url_raw( $portal ) ), 200 );
	}

	$bearer = function_exists( 'agend_apps_get_bearer_token' ) ? agend_apps_get_bearer_token() : '';
	if ( '' === $bearer ) {
		return new WP_REST_Response( array( 'status' => 'unknown' ), 200 );
	}

	$result = agend_apps_cart_confirm_session( (string) ( $record['session'] ?? '' ), '', $bearer );
	if ( 'confirmed' === $result['status'] ) {
		// Remembered only if this is still the member's latest join.
		$current = get_transient( $key );
		if ( is_array( $current ) && ( $current['session'] ?? '' ) === ( $record['session'] ?? '' ) ) {
			$current['confirmed'] = true;
			set_transient( $key, $current, AGEND_APPS_MEMBERSHIP_RETURN_TTL );
		}
		return new WP_REST_Response( array( 'status' => 'confirmed', 'portalUrl' => esc_url_raw( $portal ) ), 200 );
	}

	return new WP_REST_Response( array( 'status' => $result['status'] ), 200 );
}
