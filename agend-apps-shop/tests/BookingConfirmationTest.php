<?php

declare( strict_types=1 );

namespace Agend\Tests\Shop;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once dirname( __DIR__ ) . '/includes/booking-confirmation.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/cart.php';

final class BookingConfirmationTest extends TestCase {
	#[Test]
	public function should_render_the_gateway_receipt_when_an_event_checkout_is_confirmed(): void {
		$html = agend_apps_shop_render_confirmed_booking(
			array(
				'order_number' => 'CRM-2026-00026',
				'total_cents' => 46200,
				'currency' => 'AUD',
				'events' => array(
					array(
						'name' => 'Project Management',
						'slug' => 'project-management',
						'venue_name' => 'Meeting Room',
						'attendees' => array( 'Taylor Example' ),
					),
				),
			),
			false,
			'',
			''
		);
		self::assertStringContainsString( 'Booking confirmed', $html );
		self::assertStringContainsString( 'CRM-2026-00026', $html );
		self::assertStringContainsString( 'AUD 462.00', $html );
		self::assertStringContainsString( 'Meeting Room', $html );
		self::assertStringContainsString( 'Taylor Example', $html );
		self::assertStringContainsString( '/events/project-management/ical', $html );
	}

	#[Test]
	public function should_render_an_order_confirmation_when_a_paid_receipt_has_no_events(): void {
		$html = agend_apps_shop_render_confirmed_booking( array(), false, '', '' );
		self::assertStringContainsString( 'Order confirmed', $html );
		self::assertStringNotContainsString( 'your place is booked', $html );
		self::assertStringNotContainsString( 'Add to Calendar', $html );
	}
	#[Test]
	public function should_render_a_free_event_booking_when_verified_events_exist(): void {
		$html = agend_apps_shop_render_confirmed_booking( array( 'events' => array( array( 'name' => 'Free workshop' ) ) ), true, '', '' );
		self::assertStringContainsString( 'Booking confirmed', $html );
		self::assertStringContainsString( 'Free workshop', $html );
		self::assertStringContainsString( 'Your place is booked. No payment was required.', $html );
	}

	#[Test]
	public function should_render_a_generic_free_order_when_no_verified_events_exist(): void {
		$html = agend_apps_shop_render_confirmed_booking( array(), true, '', '' );
		self::assertStringContainsString( 'Order confirmed', $html );
		self::assertStringContainsString( 'Thank you for your order', $html );
		self::assertStringContainsString( 'No payment was required.', $html );
		self::assertStringNotContainsString( 'Booking confirmed', $html );
		self::assertStringNotContainsString( 'Your place is booked', $html );
		self::assertStringNotContainsString( 'Your payment was received', $html );
	}

	#[Test]
	public function should_add_a_booking_reference_to_an_equivalent_configured_query_url(): void {
		update_option( 'agend_apps_shop_checkout_success_url', ' https://example.test/thanks/?page_id=8&lang=en ' );
		agend_apps_shop_register_booking_confirmation();
		$target = 'https://example.test/thanks?page_id=8&lang=en';
		$args = apply_filters( 'agend_apps_cart_checkout_args', array( 'body' => array( 'successUrl' => $target ) ) );
		self::assertStringStartsWith( $target . '&booking_ref=', $args['body']['successUrl'] );
		parse_str( wp_parse_url( $args['body']['successUrl'], PHP_URL_QUERY ), $query );
		self::assertSame( '8', $query['page_id'] );
		self::assertSame( 'en', $query['lang'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $query['booking_ref'] );
	}

	#[Test]
	public function should_not_add_a_booking_reference_to_a_different_or_external_target(): void {
		agend_apps_shop_register_booking_confirmation();
		foreach ( array( 'https://example.test/?page_id=9&lang=en', 'https://other.test/?page_id=8&lang=en' ) as $target ) {
			update_option( 'agend_apps_shop_checkout_success_url', 'https://example.test/?page_id=8&lang=en' );
			$args = array( 'body' => array( 'successUrl' => $target ) );
			self::assertSame( $args, apply_filters( 'agend_apps_cart_checkout_args', $args ) );
		}
	}

	#[Test]
	public function should_keep_paid_receipts_bound_to_the_guest_or_member_who_checked_out(): void {
		update_option( 'agend_apps_shop_checkout_success_url', 'https://example.test/?page_id=8&lang=en' );
		agend_apps_shop_register_booking_confirmation();
		foreach ( array( false, true ) as $member ) {
			$GLOBALS['agend_test_current_user_id'] = $member ? 42 : 0;
			$bearer = $member ? 'member-token' : '';
			$args = apply_filters( 'agend_apps_cart_checkout_args', array( 'body' => array( 'successUrl' => 'https://example.test/?page_id=8&lang=en' ) ) );
			parse_str( wp_parse_url( $args['body']['successUrl'], PHP_URL_QUERY ), $query );
			$response = array( 'data' => array( 'groups' => array( array( 'externalId' => 'cs_paid', 'subtotalCents' => 46200 ) ) ) );
			self::assertSame( $response, apply_filters( 'agend_apps_cart_checkout_response', $response, 'buyer-cart', $bearer ) );
			$buyer = $member ? 'member:42' : 'cart:buyer-cart';
			self::assertSame( array( 'session' => 'cs_paid', 'free' => false ), get_transient( agend_apps_shop_booking_key( $query['booking_ref'], $buyer ) ) );
			foreach ( array( 'cart:another-cart', 'member:43', $member ? 'cart:buyer-cart' : 'member:42' ) as $other_buyer ) {
				self::assertFalse( get_transient( agend_apps_shop_booking_key( $query['booking_ref'], $other_buyer ) ) );
			}
		}
	}

	#[Test]
	public function should_call_a_free_registration_a_booking_when_the_cart_held_event_tickets(): void {
		$html = agend_apps_shop_render_confirmed_booking( array(), true, '', '', true );
		self::assertStringContainsString( 'Booking confirmed', $html );
		self::assertStringContainsString( 'Thank you for registering', $html );
		self::assertStringContainsString( 'Your place is booked. No payment was required.', $html );
		self::assertStringNotContainsString( 'Order confirmed', $html );
		self::assertStringNotContainsString( 'Thank you for your order', $html );
	}

	#[Test]
	public function should_tell_whether_the_cart_holds_event_tickets(): void {
		\Agend_Test_WP::queue_response( 200, array( 'data' => array( 'cart' => array( 'items' => array( array( 'product_type' => 'event_tickets' ) ) ) ) ) );
		self::assertTrue( agend_apps_shop_booking_cart_has_events( 'events-cart', '' ) );
		\Agend_Test_WP::queue_response( 200, array( 'data' => array( 'items' => array( array( 'product_type' => 'membership' ) ) ) ) );
		self::assertFalse( agend_apps_shop_booking_cart_has_events( 'membership-cart', '' ) );
		\Agend_Test_WP::queue_response( 500, array( 'error' => array( 'code' => 'INTERNAL' ) ) );
		self::assertFalse( agend_apps_shop_booking_cart_has_events( 'broken-cart', '' ) );
		self::assertFalse( agend_apps_shop_booking_cart_has_events( '', '' ) );
	}

	#[Test]
	public function should_confirm_a_paid_checkout_through_the_complete_route_when_the_platform_has_no_status_route(): void {
		$record = array( 'session' => 'cs_paid', 'free' => false, 'events' => true, 'total_cents' => 46200, 'currency' => 'AUD' );
		$ref    = '6f1c1d6e-0a51-4b4a-9c0b-1f7d2a3b4c5d';
		\Agend_Test_WP::queue_response( 404, array( 'success' => false, 'error' => array( 'code' => 'NOT_FOUND', 'message' => 'The requested endpoint does not exist' ) ) );
		\Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'order_id' => 'o1', 'order_number' => 'CRM-2026-00031', 'paid_at' => '2026-11-22T00:00:00Z' ) ) );

		$result = agend_apps_shop_booking_lookup( $ref, 'cart:buyer', $record, 'buyer', '' );

		self::assertSame( 'confirmed', $result['status'] );
		self::assertSame( array( 'order_number' => 'CRM-2026-00031', 'total_cents' => 46200, 'currency' => 'AUD' ), $result['receipt'] );
		$urls = array_column( \Agend_Test_WP::$requests, 'url' );
		self::assertStringContainsString( '/cart/checkout/status', $urls[0] );
		self::assertStringContainsString( '/cart/checkout/complete', $urls[1] );
		self::assertStringContainsString( 'cs_paid', (string) \Agend_Test_WP::$requests[1]['body'] );
		self::assertSame( 'POST', \Agend_Test_WP::$requests[1]['method'] );

		// Remembered: a reload neither asks the missing route again nor
		// completes the checkout twice.
		$stored = get_transient( agend_apps_shop_booking_key( $ref, 'cart:buyer' ) );
		self::assertSame( $result['receipt'], $stored['confirmed'] );
		self::assertSame( 'confirmed', agend_apps_shop_booking_lookup( $ref, 'cart:buyer', $stored, 'buyer', '' )['status'] );
		self::assertCount( 2, \Agend_Test_WP::$requests );

		$html = agend_apps_shop_render_confirmed_booking( $result['receipt'], false, '', '', true );
		self::assertStringContainsString( 'Booking confirmed', $html );
		self::assertStringContainsString( 'CRM-2026-00031', $html );
		self::assertStringContainsString( 'AUD 462.00', $html );
	}

	#[Test]
	public function should_wait_while_the_payment_is_not_yet_confirmed_and_never_say_couldnt_verify_after_a_payment(): void {
		set_transient( 'agend_shop_booking_status_missing', 1, 60 );
		$record = array( 'session' => 'cs_paid', 'free' => false );

		\Agend_Test_WP::queue_response( 402, array( 'success' => false, 'error' => array( 'code' => 'PAYMENT_NOT_CONFIRMED', 'message' => 'Payment is not confirmed' ) ) );
		self::assertSame( array( 'status' => 'pending' ), agend_apps_shop_booking_lookup( '6f1c1d6e-0a51-4b4a-9c0b-1f7d2a3b4c5d', 'cart:buyer', $record, 'buyer', '' ) );

		// The webhook settled and removed the cart first: the platform no
		// longer knows the checkout, but the buyer came back from paying.
		\Agend_Test_WP::queue_response( 404, array( 'success' => false, 'error' => array( 'code' => 'NOT_FOUND', 'message' => 'Checkout session not found' ) ) );
		self::assertSame( array( 'status' => 'unknown' ), agend_apps_shop_booking_lookup( '6f1c1d6e-0a51-4b4a-9c0b-1f7d2a3b4c5d', 'cart:buyer', $record, 'buyer', '' ) );

		// The webhook is fulfilling the same checkout at this moment.
		\Agend_Test_WP::queue_response( 409, array( 'success' => false, 'error' => array( 'code' => 'CART_CONFLICT', 'message' => 'claimed' ) ) );
		self::assertSame( array( 'status' => 'pending' ), agend_apps_shop_booking_lookup( '6f1c1d6e-0a51-4b4a-9c0b-1f7d2a3b4c5d', 'cart:buyer', $record, 'buyer', '' ) );
		self::assertCount( 3, \Agend_Test_WP::$requests, 'the missing status route is not asked again' );
	}

	#[Test]
	public function should_not_complete_anything_when_the_status_route_exists_but_fails(): void {
		\Agend_Test_WP::queue_response( 503, array( 'success' => false, 'error' => array( 'code' => 'UNAVAILABLE', 'message' => 'down' ) ) );
		$result = agend_apps_shop_booking_lookup( '6f1c1d6e-0a51-4b4a-9c0b-1f7d2a3b4c5d', 'cart:buyer', array( 'session' => 'cs_paid', 'free' => false ), 'buyer', '' );
		self::assertSame( array( 'status' => 'unknown' ), $result );
		self::assertCount( 1, \Agend_Test_WP::$requests );
		self::assertFalse( get_transient( 'agend_shop_booking_status_missing' ) );
	}

	#[Test]
	public function should_neither_claim_nor_deny_a_payment_the_platform_cannot_answer_for(): void {
		update_option( 'agend_apps_shop_checkout_success_url', 'https://example.test/thanks/' );
		$ref = '6f1c1d6e-0a51-4b4a-9c0b-1f7d2a3b4c5d';
		$_GET['booking_ref']                = $ref;
		$_COOKIE['agend_cart_session']      = 'buyer';
		set_transient( agend_apps_shop_booking_key( $ref, 'cart:buyer' ), array( 'session' => 'cs_paid', 'free' => false, 'events' => true ), 60 );
		\Agend_Test_WP::queue_response( 404, array( 'success' => false, 'error' => array( 'code' => 'NOT_FOUND', 'message' => 'The requested endpoint does not exist' ) ) );
		\Agend_Test_WP::queue_response( 500, array( 'success' => false, 'error' => array( 'code' => 'INTERNAL', 'message' => 'boom' ) ) );

		$html = agend_apps_shop_render_booking_confirmation();
		unset( $_GET['booking_ref'], $_COOKIE['agend_cart_session'] );

		self::assertStringContainsString( 'Booking received', $html );
		self::assertStringContainsString( 'If your payment went through, your confirmation email will arrive shortly.', $html );
		self::assertStringContainsString( 'Check again', $html );
		self::assertStringNotContainsString( 'couldn’t verify', $html );
		self::assertStringNotContainsString( 'Payment received', $html );
	}

	#[Test]
	public function should_read_the_cart_currency_with_its_event_tickets(): void {
		\Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'currency' => 'aud', 'items' => array( array( 'product_type' => 'event_tickets' ) ) ) ) );
		self::assertSame( array( 'events' => true, 'currency' => 'AUD' ), agend_apps_shop_booking_cart_summary( 'aud-cart', '' ) );
		\Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'currency' => 'dollars', 'items' => array() ) ) );
		self::assertSame( array( 'events' => false, 'currency' => null ), agend_apps_shop_booking_cart_summary( 'odd-cart', '' ) );
		\Agend_Test_WP::queue_response( 500, array( 'error' => array( 'code' => 'INTERNAL' ) ) );
		self::assertSame( array( 'events' => false, 'currency' => null ), agend_apps_shop_booking_cart_summary( 'broken-cart', '' ) );
	}

	#[Test]
	public function should_keep_the_cart_currency_for_the_receipt_when_the_checkout_groups_carry_none(): void {
		// A released platform's checkout groups have a subtotal but no currency.
		update_option( 'agend_apps_shop_checkout_success_url', 'https://example.test/?page_id=8&lang=en' );
		agend_apps_shop_register_booking_confirmation();
		$GLOBALS['agend_test_current_user_id'] = 42;
		\Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'currency' => 'aud', 'items' => array( array( 'product_type' => 'event_tickets' ) ) ) ) );
		$args = apply_filters( 'agend_apps_cart_checkout_args', array( 'body' => array( 'successUrl' => 'https://example.test/?page_id=8&lang=en' ) ), array(), '', 'member-token' );
		parse_str( wp_parse_url( $args['body']['successUrl'], PHP_URL_QUERY ), $query );
		$response = array( 'data' => array( 'groups' => array( array( 'externalId' => 'cs_paid', 'subtotalCents' => 34650 ) ) ) );
		apply_filters( 'agend_apps_cart_checkout_response', $response, '', 'member-token' );

		self::assertSame(
			array( 'session' => 'cs_paid', 'free' => false, 'events' => true, 'total_cents' => 34650, 'currency' => 'AUD' ),
			get_transient( agend_apps_shop_booking_key( $query['booking_ref'], 'member:42' ) )
		);
	}

	#[Test]
	public function should_show_the_order_reference_even_when_the_amount_is_unknown(): void {
		$html = agend_apps_shop_render_confirmed_booking( array( 'order_number' => 'CRM-2026-00032' ), false, '', '', true );
		self::assertStringContainsString( 'Order reference', $html );
		self::assertStringContainsString( 'CRM-2026-00032', $html );
		self::assertStringNotContainsString( 'Amount paid', $html );
	}
}
