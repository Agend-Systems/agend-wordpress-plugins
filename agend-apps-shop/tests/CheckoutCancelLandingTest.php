<?php

declare( strict_types=1 );

namespace Agend\Tests\Shop;

use Agend\Tests\TestCase;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once dirname( __DIR__ ) . '/includes/checkout-cancel-landing.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/cart.php';

/**
 * Returning from the payment page's cancel link cancels the abandoned
 * checkout, but only with the single-use token of the buyer's latest checkout.
 */
final class CheckoutCancelLandingTest extends TestCase {

	/** Starts a checkout through the hooks and returns the cancel URL's token. */
	private function startCheckout( string $cart = 'guest-cart', string $cancel = 'https://example.test/cart/' ): string {
		$args = apply_filters( 'agend_apps_cart_checkout_args', array( 'body' => array( 'cancelUrl' => $cancel, 'successUrl' => 'https://example.test/thanks/' ) ), array(), $cart, '' );
		apply_filters( 'agend_apps_cart_checkout_response', array( 'data' => array( 'groups' => array() ) ), $cart, '' );
		parse_str( (string) wp_parse_url( $args['body']['cancelUrl'], PHP_URL_QUERY ), $query );
		self::assertSame( 'https://example.test/thanks/', $args['body']['successUrl'] );
		return (string) ( $query['agend_cancel'] ?? '' );
	}

	private function returnWith( string $token, string $cart = 'guest-cart' ) {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/cart/checkout/return-cancel' );
		$request->set_param( 'token', $token );
		$request->set_header( 'X-Cart-Session', $cart );
		return agend_apps_shop_cancel_on_return( $request );
	}

	private function cancelCalls(): int {
		return count( array_filter( Agend_Test_WP::$requests, static fn ( $r ) => str_contains( (string) $r['url'], '/cart/checkout/cancel' ) ) );
	}

	protected function setUp(): void {
		parent::setUp();
		agend_apps_shop_register_checkout_cancel_landing();
	}

	#[Test]
	public function should_mark_a_same_site_cancel_url_with_a_fresh_token_and_keep_its_query(): void {
		$args = apply_filters( 'agend_apps_cart_checkout_args', array( 'body' => array( 'cancelUrl' => 'https://example.test/cart/?continue=https%3A%2F%2Fexample.test%2Fevents%2F' ) ) );
		self::assertStringStartsWith( 'https://example.test/cart/?continue=https%3A%2F%2Fexample.test%2Fevents%2F&agend_checkout=cancelled&agend_cancel=', $args['body']['cancelUrl'] );
	}

	#[Test]
	public function should_leave_an_off_site_or_missing_cancel_url_alone(): void {
		foreach ( array( 'https://other.test/cart/', 'https://user:pass@example.test/cart/' ) as $url ) {
			$args = array( 'body' => array( 'cancelUrl' => $url ) );
			self::assertSame( $args, apply_filters( 'agend_apps_cart_checkout_args', $args ) );
		}
		self::assertSame( array( 'body' => array() ), apply_filters( 'agend_apps_cart_checkout_args', array( 'body' => array() ) ) );
	}

	#[Test]
	public function should_cancel_once_with_the_buyers_latest_token(): void {
		$token = $this->startCheckout();
		self::assertSame( 32, strlen( $token ) );

		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'status' => 'cancelled' ) ) );
		self::assertSame( array( 'cancelled' => true ), $this->returnWith( $token )->get_data() );
		self::assertSame( 1, $this->cancelCalls() );

		// Single use: the same link again asks nothing.
		self::assertSame( array( 'cancelled' => false ), $this->returnWith( $token )->get_data() );
		self::assertSame( 1, $this->cancelCalls() );
	}

	#[Test]
	public function should_cancel_nothing_for_a_crafted_or_stale_link_or_another_buyer(): void {
		$old   = $this->startCheckout();
		$token = $this->startCheckout();

		self::assertSame( array( 'cancelled' => false ), $this->returnWith( $old )->get_data(), 'an older checkout\'s link cannot cancel the newer one' );
		self::assertSame( array( 'cancelled' => false ), $this->returnWith( 'guessed-token' )->get_data() );
		self::assertSame( array( 'cancelled' => false ), $this->returnWith( '' )->get_data() );
		self::assertSame( array( 'cancelled' => false ), $this->returnWith( $token, 'someone-elses-cart' )->get_data() );
		self::assertSame( 0, $this->cancelCalls() );
	}

	#[Test]
	public function should_answer_quietly_when_the_platform_refuses(): void {
		$token = $this->startCheckout();
		Agend_Test_WP::queue_response( 409, array( 'success' => false, 'error' => array( 'code' => 'CHECKOUT_NOT_INITIATED', 'message' => 'Nothing to cancel' ) ) );
		$response = $this->returnWith( $token );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'cancelled' => false ), $response->get_data() );
	}

	#[Test]
	public function should_never_store_the_token_itself(): void {
		$token = $this->startCheckout();
		foreach ( Agend_Test_WP::$transients as $value ) {
			self::assertStringNotContainsString( $token, (string) json_encode( $value ) );
		}
	}

	#[Test]
	public function should_revoke_the_token_when_a_newer_checkout_has_an_off_site_cancel_url(): void {
		$old = $this->startCheckout();
		$this->startCheckout( 'guest-cart', 'https://pay.other.test/cancelled' );
		self::assertSame( array( 'cancelled' => false ), $this->returnWith( $old )->get_data() );
		self::assertSame( 0, $this->cancelCalls() );
	}

	#[Test]
	public function should_turn_away_a_request_that_overlaps_one_already_cancelling(): void {
		$token = $this->startCheckout();
		// Another request holds the claim for this token right now.
		add_option( 'agend_shop_cancel_claim_' . substr( agend_apps_shop_cancel_hash( $token ), 0, 40 ), '1' );
		self::assertSame( array( 'cancelled' => false ), $this->returnWith( $token )->get_data() );
		self::assertSame( 0, $this->cancelCalls() );
	}

	#[Test]
	public function should_turn_away_a_request_that_claims_after_the_first_has_spent_the_token(): void {
		$token = $this->startCheckout();
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'status' => 'cancelled' ) ) );
		$this->returnWith( $token );
		// The first request has finished and released its claim; the replay
		// can claim, but finds the token spent.
		self::assertSame( array( 'cancelled' => false ), $this->returnWith( $token )->get_data() );
		self::assertSame( 1, $this->cancelCalls() );
		self::assertArrayNotHasKey( 'agend_shop_cancel_claim_' . substr( agend_apps_shop_cancel_hash( $token ), 0, 40 ), Agend_Test_WP::$options );
	}

	#[Test]
	public function should_revoke_the_earlier_token_as_soon_as_a_new_checkout_starts(): void {
		$old = $this->startCheckout();
		// The next checkout has been asked for, but the platform has not answered.
		apply_filters( 'agend_apps_cart_checkout_args', array( 'body' => array( 'cancelUrl' => 'https://example.test/cart/' ) ), array(), 'guest-cart', '' );
		self::assertSame( array( 'cancelled' => false ), $this->returnWith( $old )->get_data() );
		self::assertSame( 0, $this->cancelCalls() );
	}
}
