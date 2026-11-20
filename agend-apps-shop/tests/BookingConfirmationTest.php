<?php

declare( strict_types=1 );

namespace Agend\Tests\Shop;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once dirname( __DIR__ ) . '/includes/booking-confirmation.php';

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

}
