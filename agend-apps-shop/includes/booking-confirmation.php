<?php
/**
 * A buyer-bound booking receipt for the shop's configured return page.
 *
 * @package Agend_Apps_Shop
 */

defined( 'ABSPATH' ) || exit;

function agend_apps_shop_booking_key( string $reference, string $buyer ): string {
	return 'agend_shop_booking_' . hash_hmac( 'sha256', $reference . ':' . $buyer, wp_salt( 'auth' ) );
}

function agend_apps_shop_booking_buyer( string $cart, string $bearer ): string {
	if ( '' !== $bearer && get_current_user_id() > 0 ) {
		return 'member:' . get_current_user_id();
	}
	return '' !== $cart && strlen( $cart ) <= 512 ? 'cart:' . $cart : '';
}

function agend_apps_shop_register_booking_confirmation(): void {
	$reference = '';
	$has_events = false;
	add_filter( 'agend_apps_cart_checkout_args', static function ( array $args, $urls = array(), $cart = '', $bearer = '' ) use ( &$reference, &$has_events ): array {
		$reference  = '';
		$has_events = false;
		$configured = (string) get_option( 'agend_apps_shop_checkout_success_url', '' );
		$target = esc_url_raw( $args['body']['successUrl'] ?? '' );
		if ( '' === $configured || agend_apps_shop_booking_url_parts( $configured ) !== agend_apps_shop_booking_url_parts( $target ) || ! agend_apps_shop_is_local_booking_url( $target ) ) {
			return $args;
		}

		$reference = wp_generate_uuid4();
		$args['body']['successUrl'] = add_query_arg( 'booking_ref', $reference, $target );
		// A free checkout's receipt names no events, so whether this is an
		// event booking is read from the cart before it is checked out.
		$has_events = agend_apps_shop_booking_cart_has_events( is_string( $cart ) ? $cart : '', is_string( $bearer ) ? $bearer : '' );
		return $args;
	}, 10, 4 );

	add_filter( 'agend_apps_cart_checkout_response', static function ( array $response, string $cart, string $bearer = '' ) use ( &$reference, &$has_events ): array {
		$groups = $response['data']['groups'] ?? array();
		$buyer  = agend_apps_shop_booking_buyer( $cart, $bearer );
		if ( '' === $reference || '' === $buyer || ! is_array( $groups ) || 1 !== count( $groups ) ) {
			return $response;
		}

		$group = $groups[0] ?? array();
		$session = $group['externalId'] ?? '';
		if ( ! is_string( $session ) || '' === $session || strlen( $session ) > 255 ) {
			return $response;
		}

		$free = 0 === strpos( $session, 'free:' ) && 0 === ( $group['subtotalCents'] ?? null ) && ! empty( $group['orderId'] );
		if ( $free || ( $group['subtotalCents'] ?? 0 ) > 0 ) {
			$record = array( 'session' => $session, 'free' => $free );
			if ( $has_events ) {
				$record['events'] = true;
			}
			// The amount shown on the receipt when the platform's answer
			// carries none (the complete route returns the order number only).
			$currency = $group['currency'] ?? ( $response['data']['currency'] ?? null );
			if ( ! $free && is_int( $group['subtotalCents'] ?? null ) && is_string( $currency ) && preg_match( '/^[A-Za-z]{3}$/', $currency ) ) {
				$record['total_cents'] = $group['subtotalCents'];
				$record['currency']    = strtoupper( $currency );
			}
			set_transient( agend_apps_shop_booking_key( $reference, $buyer ), $record, DAY_IN_SECONDS );
		}

		return $response;
	}, 10, 3 );

	add_action( 'template_redirect', static function (): void {
		// A receipt can be placed on any WordPress page. Never cache a buyer-specific return.
		$confirmation_page = url_to_postid( (string) get_option( 'agend_apps_shop_checkout_success_url', '' ) );
		if ( isset( $_GET['booking_ref'] ) || ( $confirmation_page > 0 && is_page( $confirmation_page ) ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
			wp_enqueue_style( 'agend-apps-shop-booking', AGEND_APPS_SHOP_URL . 'assets/css/agend-apps-shop-booking.css', array(), AGEND_APPS_SHOP_VERSION );
		}
	} );

	add_shortcode( 'agend_booking_confirmation', 'agend_apps_shop_render_booking_confirmation' );
}

/**
 * Whether the cart about to be checked out holds event tickets. Read through
 * Core's cart helper (cached where the cart read is cached); any failure
 * answers false, which keeps the generic order wording.
 *
 * @param string $cart   Guest cart session token.
 * @param string $bearer Member bearer token, or ''.
 * @return bool
 */
function agend_apps_shop_booking_cart_has_events( string $cart, string $bearer ): bool {
	if ( ! function_exists( 'agend_apps_cart_get' ) || ( '' === $cart && '' === $bearer ) ) {
		return false;
	}
	$response = agend_apps_cart_get( $cart, $bearer );
	if ( is_wp_error( $response ) || ! is_array( $response ) ) {
		return false;
	}
	$data  = is_array( $response['data'] ?? null ) ? $response['data'] : $response;
	$cart_data = is_array( $data['cart'] ?? null ) ? $data['cart'] : $data;
	$items = is_array( $cart_data['items'] ?? null ) ? $cart_data['items'] : array();
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$type = $item['product_type'] ?? ( $item['productType'] ?? '' );
		if ( 'event_tickets' === $type ) {
			return true;
		}
	}
	return false;
}

/** Normalise the URL path without changing query values or their encoding. */
function agend_apps_shop_booking_url_parts( string $url ): array {
	$parts = wp_parse_url( esc_url_raw( $url ) );
	if ( ! is_array( $parts ) ) {
		return array();
	}
	$parts['path'] = untrailingslashit( $parts['path'] ?? '' );
	foreach ( array( 'scheme', 'host' ) as $key ) {
		if ( isset( $parts[ $key ] ) ) {
			$parts[ $key ] = strtolower( $parts[ $key ] );
		}
	}
	ksort( $parts );
	return $parts;
}

function agend_apps_shop_is_local_booking_url( string $url ): bool {
	$target = wp_parse_url( $url );
	$home   = wp_parse_url( home_url() );
	if ( ! is_array( $target ) || ! is_array( $home ) ) {
		return false;
	}
	return isset( $target['scheme'], $target['host'], $home['scheme'], $home['host'] )
		&& strtolower( $target['scheme'] ) === strtolower( $home['scheme'] )
		&& strtolower( $target['host'] ) === strtolower( $home['host'] )
		&& ( $target['port'] ?? null ) === ( $home['port'] ?? null )
		&& ! isset( $target['user'] )
		&& ! isset( $target['pass'] );
}

/** @param array<string,string>|string $attributes Shortcode attributes. */
function agend_apps_shop_render_booking_confirmation( $attributes = array() ): string {
	$attributes = shortcode_atts(
		array( 'support_url' => '', 'events_url' => '' ),
		$attributes,
		'agend_booking_confirmation'
	);
	$ref = is_string( $_GET['booking_ref'] ?? null ) ? wp_unslash( $_GET['booking_ref'] ) : '';
	$cart = is_string( $_COOKIE['agend_cart_session'] ?? null ) ? wp_unslash( $_COOKIE['agend_cart_session'] ) : '';
	$bearer = agend_apps_get_bearer_token();
	$buyer = agend_apps_shop_booking_buyer( $cart, $bearer );
	$record = '' !== $buyer && wp_is_uuid( $ref, 4 ) ? get_transient( agend_apps_shop_booking_key( $ref, $buyer ) ) : false;
	$is_free = is_array( $record ) && true === ( $record['free'] ?? false );
	$result  = array();
	if ( is_array( $record ) && is_string( $record['session'] ?? null ) && ! $is_free ) {
		$result = agend_apps_shop_booking_lookup( $ref, $buyer, $record, $cart, $bearer );
	}

	$status  = $result['status'] ?? '';
	$support = '' !== esc_url( $attributes['support_url'] )
		? '<p><a href="' . esc_url( $attributes['support_url'] ) . '">' . esc_html__( 'Contact the event organiser', 'agend-apps-shop' ) . '</a></p>'
		: '';
	$event_cart = is_array( $record ) && true === ( $record['events'] ?? false );

	if ( 'confirmed' === $status || ( $is_free && '' === $status ) ) {
		$receipt = is_array( $result['receipt'] ?? null ) ? $result['receipt'] : array();
		return agend_apps_shop_render_confirmed_booking( $receipt, $is_free, $attributes['events_url'], $support, $event_cart );
	}

	if ( 'pending' === $status ) {
		$retry = esc_url( add_query_arg( 'booking_ref', $ref, (string) get_option( 'agend_apps_shop_checkout_success_url', '' ) ) );
		return '<section class="agend-booking-receipt agend-booking-receipt--pending">'
			. '<h1>' . esc_html__( 'We’re confirming your booking', 'agend-apps-shop' ) . '</h1>'
			. '<p>' . esc_html__( 'We’re waiting for the payment result. You can check again in a moment.', 'agend-apps-shop' ) . '</p>'
			. '<p><a href="' . $retry . '">' . esc_html__( 'Check again', 'agend-apps-shop' ) . '</a></p></section>';
	}

	// A paid checkout this buyer started and returned from, that the platform
	// could not answer for just now. Nothing is claimed either way: no
	// "couldn't verify" after what may be a real payment, and no "payment
	// received" without the platform saying so.
	if ( 'unknown' === $status ) {
		$retry = esc_url( add_query_arg( 'booking_ref', $ref, (string) get_option( 'agend_apps_shop_checkout_success_url', '' ) ) );
		return '<section class="agend-booking-receipt agend-booking-receipt--received">'
			. '<p class="agend-booking-receipt__status">' . esc_html__( 'Booking received', 'agend-apps-shop' ) . '</p>'
			. '<h1>' . esc_html__( 'Thank you', 'agend-apps-shop' ) . '</h1>'
			. '<p>' . esc_html__( 'We’re finishing your booking. If your payment went through, your confirmation email will arrive shortly.', 'agend-apps-shop' ) . '</p>'
			. '<p><a href="' . $retry . '">' . esc_html__( 'Check again', 'agend-apps-shop' ) . '</a></p>'
			. $support . '</section>';
	}

	return '<section class="agend-booking-receipt agend-booking-receipt--unverified">'
		. '<h1>' . esc_html__( 'Check your booking', 'agend-apps-shop' ) . '</h1>'
		. '<p>' . esc_html__( 'We couldn’t verify a booking on this page. Check your email for a confirmation.', 'agend-apps-shop' ) . '</p>'
		. $support . '</section>';
}

/**
 * Asks the platform what became of a paid checkout this buyer started.
 *
 * `GET /cart/checkout/status` (which returns the receipt with its events) is
 * asked first. Released platforms do not have it: only when the gateway says
 * the route does not exist is that remembered for a day and
 * `POST /cart/checkout/complete` asked instead, through Core's completion
 * helper (its hooks run and the buyer's cached cart is cleared). That route
 * is the one a payment page's success return is meant to call: it confirms
 * the payment with the provider before fulfilling the cart once, and a repeat
 * call for a settled checkout returns the same order. A confirmed order is
 * kept with the buyer's record so a reload does not ask again.
 *
 * @param string               $ref    Booking reference.
 * @param string               $buyer  Buyer key.
 * @param array<string,mixed>  $record The stored checkout record.
 * @param string               $cart   Guest cart session token.
 * @param string               $bearer Member bearer token, or ''.
 * @return array{status?: string, receipt?: array<string,mixed>} `confirmed`
 *         (with a receipt), `pending` (the payment is not confirmed yet, or
 *         another request is finishing it), or `unknown` (the platform could
 *         not say; nothing is claimed either way).
 */
function agend_apps_shop_booking_lookup( string $ref, string $buyer, array $record, string $cart, string $bearer ): array {
	$session = (string) $record['session'];

	if ( is_array( $record['confirmed'] ?? null ) ) {
		return array( 'status' => 'confirmed', 'receipt' => $record['confirmed'] );
	}

	if ( false === get_transient( 'agend_shop_booking_status_missing' ) ) {
		$response = agend_apps_api()->request(
			'GET',
			'/cart/checkout/status',
			array(
				'query'        => array( 'externalId' => $session ),
				'cart_session' => $cart,
				'bearer_token' => $bearer,
				'timeout'      => 10,
			)
		);
		if ( ! is_wp_error( $response ) ) {
			$data = is_array( $response['data'] ?? null ) ? $response['data'] : array();
			return in_array( $data['status'] ?? '', array( 'confirmed', 'pending' ), true ) ? $data : array( 'status' => 'unknown' );
		}
		if ( ! agend_apps_shop_booking_is_missing_route( $response ) ) {
			return array( 'status' => 'unknown' );
		}
		set_transient( 'agend_shop_booking_status_missing', 1, DAY_IN_SECONDS );
	}

	$response = agend_apps_shop_booking_complete( $session, $cart, $bearer );
	if ( ! is_wp_error( $response ) ) {
		$order = is_array( $response['data'] ?? null ) ? $response['data'] : array();
		if ( empty( $order['order_id'] ) && empty( $order['order_number'] ) ) {
			return array( 'status' => 'unknown' );
		}
		$receipt = array();
		if ( is_string( $order['order_number'] ?? null ) && '' !== $order['order_number'] ) {
			$receipt['order_number'] = $order['order_number'];
		}
		if ( isset( $receipt['order_number'], $record['total_cents'], $record['currency'] ) ) {
			$receipt['total_cents'] = (int) $record['total_cents'];
			$receipt['currency']    = (string) $record['currency'];
		}
		$record['confirmed'] = $receipt;
		set_transient( agend_apps_shop_booking_key( $ref, $buyer ), $record, DAY_IN_SECONDS );
		return array( 'status' => 'confirmed', 'receipt' => $receipt );
	}

	// 402: the provider has not confirmed the payment yet. 409: another
	// request (the payment webhook) holds the checkout while it finishes it,
	// or the payment is under review. Both clear on their own.
	$data   = $response->get_error_data();
	$status = is_array( $data ) ? ( $data['status_code'] ?? null ) : null;
	if ( 402 === $status || 409 === $status ) {
		return array( 'status' => 'pending' );
	}
	return array( 'status' => 'unknown' );
}

/**
 * Completes one checkout session through Core's helper, which runs its hooks
 * and clears the buyer's cached cart. A Core too old to pass the session id
 * gets the same request made directly.
 *
 * @param string $session Checkout session (external) id.
 * @param string $cart    Guest cart session token.
 * @param string $bearer  Member bearer token, or ''.
 * @return array|WP_Error
 */
function agend_apps_shop_booking_complete( string $session, string $cart, string $bearer ) {
	$body = array( 'externalId' => $session );
	if ( function_exists( 'agend_apps_cart_checkout_complete' ) ) {
		$helper = new ReflectionFunction( 'agend_apps_cart_checkout_complete' );
		if ( $helper->getNumberOfParameters() >= 3 ) {
			return agend_apps_cart_checkout_complete( $cart, $bearer, $body );
		}
	}
	return agend_apps_api()->request(
		'POST',
		'/cart/checkout/complete',
		array(
			'body'         => $body,
			'cart_session' => $cart,
			'bearer_token' => $bearer,
			'timeout'      => 10,
		)
	);
}

/**
 * Whether an error is the gateway saying it has no such endpoint (as opposed
 * to no such checkout).
 *
 * @param WP_Error $error What the request returned.
 * @return bool
 */
function agend_apps_shop_booking_is_missing_route( WP_Error $error ): bool {
	$data    = $error->get_error_data();
	$message = is_array( $data ) && is_array( $data['body']['error'] ?? null ) ? (string) ( $data['body']['error']['message'] ?? '' ) : $error->get_error_message();
	return is_array( $data ) && 404 === ( $data['status_code'] ?? null ) && false !== stripos( $message, 'endpoint' );
}

/**
 * @param array<string,mixed> $receipt    Verified Agend checkout receipt.
 * @param bool                $is_free    Whether no payment was taken.
 * @param string              $events_url "See more events" link, or ''.
 * @param string              $support    Support link HTML, or ''.
 * @param bool                $event_cart Whether the cart checked out held
 *                                        event tickets, for a receipt that names
 *                                        no events (a free registration's).
 */
function agend_apps_shop_render_confirmed_booking( array $receipt, bool $is_free, string $events_url, string $support, bool $event_cart = false ): string {
	$events = array_values( array_filter( (array) ( $receipt['events'] ?? array() ), 'is_array' ) );
	$is_booking = count( $events ) > 0 || $event_cart;
	$order_status = $is_booking ? __( 'Booking confirmed', 'agend-apps-shop' ) : __( 'Order confirmed', 'agend-apps-shop' );
	$title = $is_booking ? __( 'Thank you for registering', 'agend-apps-shop' ) : __( 'Thank you for your order', 'agend-apps-shop' );
	if ( 1 === count( $events ) && ! empty( $events[0]['name'] ) ) {
		$title = $events[0]['name'];
	}
	$detail = __( 'Your payment was received.', 'agend-apps-shop' );
	if ( $is_free ) {
		$detail = $is_booking
			? __( 'Your place is booked. No payment was required.', 'agend-apps-shop' )
			: __( 'No payment was required.', 'agend-apps-shop' );
	} elseif ( $is_booking ) {
		$detail = __( 'Your payment was received and your place is booked.', 'agend-apps-shop' );
	}
	$html = '<section class="agend-booking-receipt agend-booking-receipt--confirmed">'
		. '<p class="agend-booking-receipt__status">' . esc_html( $order_status ) . '</p>'
		. '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $detail ) . '</p>';

	if ( isset( $receipt['order_number'], $receipt['total_cents'], $receipt['currency'] ) ) {
		$amount = sprintf( '%s %s', $receipt['currency'], number_format_i18n( (int) $receipt['total_cents'] / 100, 2 ) );
		$html .= '<dl class="agend-booking-receipt__summary"><dt>' . esc_html__( 'Order reference', 'agend-apps-shop' )
			. '</dt><dd>' . esc_html( $receipt['order_number'] ) . '</dd><dt>' . esc_html__( 'Amount paid', 'agend-apps-shop' )
			. '</dt><dd>' . esc_html( $amount ) . '</dd></dl>';
	}

	foreach ( $events as $event ) {
		$html .= agend_apps_shop_render_booking_event( $event, count( $events ) > 1 );
	}
	if ( $is_booking ) {
		$html .= '<p>' . esc_html__( 'Check your confirmation email for joining instructions.', 'agend-apps-shop' ) . '</p>';
	}
	$html .= $support;
	if ( '' !== esc_url( $events_url ) ) {
		$html .= '<p><a href="' . esc_url( $events_url ) . '">' . esc_html__( 'See more events', 'agend-apps-shop' ) . '</a></p>';
	}
	return $html . '</section>';
}

/** @param array<string,mixed> $event Verified public event details and booked attendees. */
function agend_apps_shop_render_booking_event( array $event, bool $show_name ): string {
	$name = (string) ( $event['name'] ?? '' );
	$html = '<section class="agend-booking-receipt__event">';
	if ( $show_name ) {
		$html .= '<h2>' . esc_html( $name ) . '</h2>';
	}
	try {
		if ( is_string( $event['start_date'] ?? null ) && '' !== $event['start_date'] ) {
			$tz = new DateTimeZone( (string) ( $event['timezone'] ?? 'UTC' ) );
			$start = new DateTimeImmutable( $event['start_date'] );
			$format = get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) . ' T';
			$date = wp_date( $format, $start->getTimestamp(), $tz );
			if ( is_string( $event['end_date'] ?? null ) && '' !== $event['end_date'] ) {
				$end = new DateTimeImmutable( $event['end_date'] );
				if ( $start->setTimezone( $tz )->format( 'Y-m-d' ) === $end->setTimezone( $tz )->format( 'Y-m-d' ) ) {
					$date = wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $start->getTimestamp(), $tz )
						. ' – ' . wp_date( get_option( 'time_format' ) . ' T', $end->getTimestamp(), $tz );
				} else {
					$date .= ' – ' . wp_date( $format, $end->getTimestamp(), $tz );
				}
			}
			$html .= '<p>' . esc_html( $date ) . '</p>';
		}
	} catch ( Exception $error ) {
		// A missing or malformed date must not hide the verified receipt.
	}
	if ( ! empty( $event['venue_name'] ) ) {
		$html .= '<p>' . esc_html( $event['venue_name'] ) . '</p>';
	}
	$attendees = array_filter( (array) ( $event['attendees'] ?? array() ), 'is_string' );
	if ( $attendees ) {
		$html .= '<p><strong>' . esc_html__( 'Attending', 'agend-apps-shop' ) . '</strong>: ' . esc_html( implode( ', ', $attendees ) ) . '</p>';
	}
	$slug = $event['slug'] ?? '';
	if ( is_string( $slug ) && preg_match( '/^[a-z0-9_-]+$/iD', $slug ) ) {
		$url = rest_url( 'agend-apps/v1/events/' . rawurlencode( $slug ) . '/ical' );
		$html .= '<p class="agend-booking-receipt__calendar"><a class="wp-element-button" href="' . esc_url( $url ) . '">' . esc_html__( 'Add to Calendar', 'agend-apps-shop' ) . '</a></p>';
	}
	return $html . '</section>';
}
