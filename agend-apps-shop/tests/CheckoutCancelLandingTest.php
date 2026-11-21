<?php

declare( strict_types=1 );

namespace Agend\Tests\Shop;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once dirname( __DIR__ ) . '/includes/checkout-cancel-landing.php';

/**
 * The payment page's cancel return carries a marker, so the cart page can
 * cancel the abandoned checkout and unlock the cart at once.
 */
final class CheckoutCancelLandingTest extends TestCase {

	#[Test]
	public function should_mark_a_cancel_url_on_this_site(): void {
		$args = agend_apps_shop_mark_cancel_url( array( 'body' => array( 'cancelUrl' => 'https://example.test/cart/?continue=https%3A%2F%2Fexample.test%2Fevents%2F', 'successUrl' => 'https://example.test/thanks/' ) ) );
		self::assertStringStartsWith( 'https://example.test/cart/?continue=https%3A%2F%2Fexample.test%2Fevents%2F&', $args['body']['cancelUrl'] );
		self::assertStringEndsWith( 'agend_checkout=cancelled', $args['body']['cancelUrl'] );
		self::assertSame( 'https://example.test/thanks/', $args['body']['successUrl'] );
	}

	#[Test]
	public function should_leave_an_off_site_or_missing_cancel_url_alone(): void {
		foreach ( array( 'https://other.test/cart/', 'https://user:pass@example.test/cart/' ) as $url ) {
			$args = array( 'body' => array( 'cancelUrl' => $url ) );
			self::assertSame( $args, agend_apps_shop_mark_cancel_url( $args ) );
		}
		self::assertSame( array( 'body' => array() ), agend_apps_shop_mark_cancel_url( array( 'body' => array() ) ) );
	}

	#[Test]
	public function should_mark_cart_checkouts_once_registered(): void {
		agend_apps_shop_register_checkout_cancel_landing();
		$args = apply_filters( 'agend_apps_cart_checkout_args', array( 'body' => array( 'cancelUrl' => 'https://example.test/cart/' ) ) );
		self::assertSame( 'https://example.test/cart/?agend_checkout=cancelled', $args['body']['cancelUrl'] );
	}
}
