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
	add_filter( 'agend_apps_cart_checkout_args', static function ( array $args ) use ( &$reference ): array {
		$reference = '';
		$configured = (string) get_option( 'agend_apps_shop_checkout_success_url', '' );
		$target = $args['body']['successUrl'] ?? '';
		if ( '' === $configured || $configured !== $target || ! agend_apps_shop_is_local_booking_url( $target ) ) {
			return $args;
		}

		$reference = wp_generate_uuid4();
		$args['body']['successUrl'] = add_query_arg( 'booking_ref', $reference, $target );
		return $args;
	} );

	add_filter( 'agend_apps_cart_checkout_response', static function ( array $response, string $cart, string $bearer = '' ) use ( &$reference ): array {
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
			set_transient( agend_apps_shop_booking_key( $reference, $buyer ), array( 'session' => $session, 'free' => $free ), DAY_IN_SECONDS );
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
	$result = array();
	if ( is_array( $record ) && is_string( $record['session'] ?? null ) ) {
		$response = agend_apps_api()->request(
			'GET',
			'/cart/checkout/status',
			array(
				'query'        => array( 'externalId' => $record['session'] ),
				'cart_session' => $cart,
				'bearer_token' => $bearer,
				'timeout'      => 10,
			)
		);
		if ( ! is_wp_error( $response ) && is_array( $response['data'] ?? null ) ) {
			$result = $response['data'];
		}
	}

	$is_free = is_array( $record ) && true === ( $record['free'] ?? false );
	$status  = $result['status'] ?? '';
	$support = '' !== esc_url( $attributes['support_url'] )
		? '<p><a href="' . esc_url( $attributes['support_url'] ) . '">' . esc_html__( 'Contact the event organiser', 'agend-apps-shop' ) . '</a></p>'
		: '';

	if ( 'confirmed' === $status || ( $is_free && '' === $status ) ) {
		$receipt = is_array( $result['receipt'] ?? null ) ? $result['receipt'] : array();
		return agend_apps_shop_render_confirmed_booking( $receipt, $is_free, $attributes['events_url'], $support );
	}

	if ( 'pending' === $status ) {
		$retry = esc_url( add_query_arg( 'booking_ref', $ref, (string) get_option( 'agend_apps_shop_checkout_success_url', '' ) ) );
		return '<section class="agend-booking-receipt agend-booking-receipt--pending">'
			. '<h1>' . esc_html__( 'We’re confirming your booking', 'agend-apps-shop' ) . '</h1>'
			. '<p>' . esc_html__( 'We’re waiting for the payment result. You can check again in a moment.', 'agend-apps-shop' ) . '</p>'
			. '<p><a href="' . $retry . '">' . esc_html__( 'Check again', 'agend-apps-shop' ) . '</a></p></section>';
	}

	return '<section class="agend-booking-receipt agend-booking-receipt--unverified">'
		. '<h1>' . esc_html__( 'Check your booking', 'agend-apps-shop' ) . '</h1>'
		. '<p>' . esc_html__( 'We couldn’t verify a booking on this page. Check your email for a confirmation.', 'agend-apps-shop' ) . '</p>'
		. $support . '</section>';
}

/** @param array<string,mixed> $receipt Verified Agend checkout receipt. */
function agend_apps_shop_render_confirmed_booking( array $receipt, bool $is_free, string $events_url, string $support ): string {
	$events = array_values( array_filter( (array) ( $receipt['events'] ?? array() ), 'is_array' ) );
	$is_booking = $is_free || count( $events ) > 0;
	$order_status = $is_booking ? __( 'Booking confirmed', 'agend-apps-shop' ) : __( 'Order confirmed', 'agend-apps-shop' );
	$title = $is_booking ? __( 'Thank you for registering', 'agend-apps-shop' ) : __( 'Thank you for your order', 'agend-apps-shop' );
	if ( 1 === count( $events ) && ! empty( $events[0]['name'] ) ) {
		$title = $events[0]['name'];
	}
	$detail = __( 'Your payment was received.', 'agend-apps-shop' );
	if ( $is_free ) {
		$detail = __( 'Your place is booked. No payment was required.', 'agend-apps-shop' );
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
	if ( $events ) {
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
