<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend\Tests\TestCase;
use Agend_Apps_Cart_Discount_REST_Controller;
use Agend_Apps_Cart_REST_Controller;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/cart.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/cart-discount-routes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/cart-routes.php';

/**
 * The cart's discount code routes proxy the gateway's /cart/discount.
 */
final class CartDiscountRoutesTest extends TestCase {

	private function request( string $method, array $params = array(), string $cart = 'guest-cart' ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/agend-apps/v1/cart/discount' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$request->set_header( 'X-Cart-Session', $cart );
		return $request;
	}

	#[Test]
	public function should_apply_a_code_and_return_the_quote(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		set_transient( 'agend_apps_' . agend_apps_cart_cache_key( 'member-token', 'guest-cart' ), array( 'stale' => true ), 60 );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'code' => 'ATEMDEMO10', 'applies' => true, 'total_cents' => 10890 ) ) );

		$response = ( new Agend_Apps_Cart_Discount_REST_Controller() )->apply( $this->request( 'POST', array( 'discount_code' => ' atemdemo10 ', 'guest_buyer' => array( 'name' => 'Ignored', 'email' => 'x@example.test' ) ) ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 10890, $response->get_data()['data']['total_cents'] );
		$sent = Agend_Test_WP::$requests[0];
		self::assertSame( 'POST', $sent['method'] );
		self::assertStringEndsWith( '/cart/discount', (string) $sent['url'] );
		self::assertSame( array( 'discount_code' => 'atemdemo10' ), json_decode( (string) $sent['body'], true ), 'a member sends no guest buyer' );
		self::assertSame( 'Bearer member-token', $sent['headers']['Authorization'] ?? null );
		self::assertFalse( get_transient( 'agend_apps_' . agend_apps_cart_cache_key( 'member-token', 'guest-cart' ) ), 'the cached cart is dropped' );
	}

	#[Test]
	public function should_send_a_guests_buyer_details(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'applies' => true ) ) );
		( new Agend_Apps_Cart_Discount_REST_Controller() )->apply( $this->request( 'POST', array( 'discount_code' => 'SAVE', 'guest_buyer' => array( 'name' => 'Sam <b>Guest</b>', 'email' => 'sam@example.test' ) ) ) );
		self::assertSame(
			array( 'discount_code' => 'SAVE', 'guest_buyer' => array( 'name' => 'Sam Guest', 'email' => 'sam@example.test' ) ),
			json_decode( (string) Agend_Test_WP::$requests[0]['body'], true )
		);
		self::assertSame( 'guest-cart', Agend_Test_WP::$requests[0]['headers']['X-Cart-Session'] ?? null );
	}

	#[Test]
	public function should_pass_on_a_refused_code_with_the_platforms_message(): void {
		Agend_Test_WP::queue_response( 400, array( 'success' => false, 'error' => array( 'code' => 'INVALID_DISCOUNT_CODE', 'message' => 'This code has expired.' ) ) );
		$response = ( new Agend_Apps_Cart_Discount_REST_Controller() )->apply( $this->request( 'POST', array( 'discount_code' => 'OLD' ) ) );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'INVALID_DISCOUNT_CODE', $response->get_data()['data']['body']['error']['code'] );
	}

	#[Test]
	public function should_refuse_an_empty_code_without_asking_the_platform(): void {
		$response = ( new Agend_Apps_Cart_Discount_REST_Controller() )->apply( $this->request( 'POST', array( 'discount_code' => '   ' ) ) );
		self::assertSame( 400, $response->get_status() );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_remove_the_code(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'code' => null, 'applies' => false ) ) );
		$response = ( new Agend_Apps_Cart_Discount_REST_Controller() )->remove( $this->request( 'DELETE' ) );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'DELETE', Agend_Test_WP::$requests[0]['method'] );
	}

	private function checkoutBody( array $params ): array {
		update_option( 'agend_apps_shop_guest_checkout', 'allow' );
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/cart/checkout' );
		$request->set_header( 'X-Cart-Session', 'guest-session' );
		$request->set_param( 'successUrl', 'https://shop.test/thanks' );
		$request->set_param( 'cancelUrl', 'https://shop.test/cart' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'checkoutUrl' => 'https://pay.test/' ) ) );
		( new Agend_Apps_Cart_REST_Controller() )->checkout_cart( $request );
		return json_decode( (string) Agend_Test_WP::$requests[0]['body'], true );
	}

	#[Test]
	public function should_forward_the_applied_code_to_card_checkout_with_or_without_a_quote_version(): void {
		$body = $this->checkoutBody( array( 'discount_code' => 'ATEMDEMO10' ) );
		self::assertSame( 'ATEMDEMO10', $body['discount_code'] );
		self::assertArrayNotHasKey( 'accepted_quote_version', $body );
	}

	#[Test]
	public function should_forward_a_quote_version_only_alongside_a_code(): void {
		Agend_Test_WP::$requests = array();
		$body = $this->checkoutBody( array( 'discount_code' => 'ATEMDEMO10', 'accepted_quote_version' => 'v1' ) );
		self::assertSame( 'v1', $body['accepted_quote_version'] );
		Agend_Test_WP::$requests = array();
		$none = $this->checkoutBody( array( 'accepted_quote_version' => 'v1' ) );
		self::assertArrayNotHasKey( 'accepted_quote_version', $none );
		self::assertArrayNotHasKey( 'discount_code', $none );
	}
}
