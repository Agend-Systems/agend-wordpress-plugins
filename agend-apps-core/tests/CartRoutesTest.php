<?php
/** @package Agend\Tests */
declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Cart_REST_Controller;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/cart.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/cart-routes.php';

#[RunTestsInSeparateProcesses]
final class CartRoutesTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		// As in CrmEntitlementTypesWrapperTest: other event wrappers are already
		// doubled by the bootstrap, so load only the real helper used here.
		$source = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/events.php' );
		if ( 1 !== preg_match( '/^function agend_apps_events_get_event\(.*?^}$/ms', $source, $match ) ) {
			throw new \RuntimeException( 'Event helper not found.' );
		}
		// phpcs:ignore Squiz.PHP.Eval.Discouraged
		eval( $match[0] );
	}

	private function checkoutRequest(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/cart/checkout' );
		$request->set_header( 'X-Cart-Session', 'guest-session' );
		$request->set_param( 'successUrl', 'https://shop.test/thanks' );
		$request->set_param( 'cancelUrl', 'https://shop.test/cart' );
		return $request;
	}

	private function checkoutBody( WP_REST_Request $request ): array {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'checkoutUrl' => 'https://pay.test/' ) ) );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_cart( $request );
		self::assertSame( 200, $response->get_status() );
		return json_decode( Agend_Test_WP::$requests[0]['body'], true );
	}

	#[Test]
	public function should_forward_sanitised_guest_buyer_when_a_guest_checks_out(): void {
		$request = $this->checkoutRequest();
		$request->set_param( 'guest_buyer', array( 'name' => ' <b>Alex Example</b> ', 'email' => 'alex @example.test' ) );
		self::assertSame( array( 'name' => 'Alex Example', 'email' => 'alex@example.test' ), $this->checkoutBody( $request )['guest_buyer'] );
	}

	#[Test]
	public function should_omit_guest_buyer_when_it_is_absent(): void {
		self::assertArrayNotHasKey( 'guest_buyer', $this->checkoutBody( $this->checkoutRequest() ) );
	}

	#[Test]
	public function should_omit_guest_buyer_when_a_member_checks_out(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		$request = $this->checkoutRequest();
		$request->set_param( 'guest_buyer', array( 'name' => 'Alex', 'email' => 'alex@example.test' ) );
		self::assertArrayNotHasKey( 'guest_buyer', $this->checkoutBody( $request ) );
		self::assertSame( 'Bearer member-token', Agend_Test_WP::$requests[0]['headers']['Authorization'] );
	}

	#[Test]
	public function should_use_cart_page_when_checkout_urls_are_empty(): void {
		update_option( 'agend_apps_shop_cart_page_url', 'https://shop.test/cart?source=event' );
		$request = $this->checkoutRequest();
		$request->set_param( 'successUrl', '' );
		$request->set_param( 'cancelUrl', '' );
		$body = $this->checkoutBody( $request );
		self::assertSame( 'https://shop.test/cart?source=event&checkout=success', $body['successUrl'] );
		self::assertSame( 'https://shop.test/cart?source=event', $body['cancelUrl'] );
	}

	#[Test]
	public function should_use_current_page_when_cart_page_and_checkout_urls_are_empty(): void {
		$request = $this->checkoutRequest();
		$request->set_header( 'Referer', 'https://shop.test/current' );
		$request->set_param( 'successUrl', '' );
		$request->set_param( 'cancelUrl', '' );
		$body = $this->checkoutBody( $request );
		self::assertSame( 'https://shop.test/current?checkout=success', $body['successUrl'] );
		self::assertSame( 'https://shop.test/current', $body['cancelUrl'] );
	}

	#[Test]
	public function should_preserve_configured_urls_when_settings_are_present(): void {
		update_option( 'agend_apps_shop_checkout_success_url', 'https://shop.test/paid' );
		update_option( 'agend_apps_shop_checkout_cancel_url', 'https://shop.test/retry' );
		$request = $this->checkoutRequest();
		$request->set_param( 'successUrl', '' );
		$request->set_param( 'cancelUrl', '' );
		$body = $this->checkoutBody( $request );
		self::assertSame( 'https://shop.test/paid', $body['successUrl'] );
		self::assertSame( 'https://shop.test/retry', $body['cancelUrl'] );
	}

	#[Test]
	public function should_preserve_requested_urls_when_they_are_not_empty(): void {
		$body = $this->checkoutBody( $this->checkoutRequest() );
		self::assertSame( 'https://shop.test/thanks', $body['successUrl'] );
		self::assertSame( 'https://shop.test/cart', $body['cancelUrl'] );
	}

	#[Test]
	public function should_expose_api_validation_details_when_checkout_fails(): void {
		$message = 'A buyer name and email are required to check out without signing in.';
		Agend_Test_WP::queue_response( 400, array( 'error' => array( 'code' => 'VALIDATION_ERROR', 'message' => 'Validation failed', 'details' => array( 'fields' => array( 'guest_buyer' => array( $message ) ) ) ) ) );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_cart( $this->checkoutRequest() );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'Validation failed', $response->get_data()['message'] );
		self::assertSame( array( $message ), $response->get_data()['data']['body']['error']['details']['fields']['guest_buyer'] );
	}

	private function cartRequest(): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/agend-apps/v1/cart' );
		$request->set_header( 'X-Cart-Session', 'guest-session' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'items' => array(
			array( 'name' => 'General Admission', 'product_type' => 'event_tickets', 'metadata' => array( 'event_slug' => 'conference' ) ),
			array( 'name' => 'VIP', 'product_type' => 'event_tickets', 'metadata' => array( 'event_slug' => 'conference' ) ),
			array( 'name' => 'Workshop', 'product_type' => 'event_tickets', 'metadata' => array( 'event_slug' => 'workshop' ) ),
			array( 'name' => 'Course', 'product_type' => 'courses' ),
			array( 'name' => 'Other Ticket', 'product_type' => 'event_tickets' ),
		) ) ) );
		return $request;
	}

	#[Test]
	public function should_enrich_ticket_names_once_per_slug_when_events_are_available(): void {
		$request = $this->cartRequest();
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'name' => 'Annual Conference' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'name' => 'Skills Workshop' ) ) );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->get_cart( $request );
		self::assertSame( array( 'Annual Conference - General Admission', 'Annual Conference - VIP', 'Skills Workshop - Workshop', 'Course', 'Other Ticket' ), array_column( $response->get_data()['data']['items'], 'name' ) );
		self::assertCount( 3, Agend_Test_WP::$requests );
		self::assertSame( 600, Agend_Test_WP::$transient_ttls['agend_apps_cart_event_name_' . md5( 'conference' )] );
	}

	#[Test]
	public function should_reuse_event_name_transients_when_the_cart_is_loaded_again(): void {
		// Member event reads bypass the event helper's cache, so only the cart
		// name transient can prevent another event lookup on the second load.
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		$request = $this->cartRequest();
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'name' => 'Annual Conference' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'name' => 'Skills Workshop' ) ) );
		$controller = new Agend_Apps_Cart_REST_Controller();
		$controller->get_cart( $request );
		$response = $controller->get_cart( $this->cartRequest() );
		self::assertSame( 'Annual Conference - General Admission', $response->get_data()['data']['items'][0]['name'] );
		self::assertCount( 4, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_keep_ticket_names_when_event_lookup_fails(): void {
		$request = $this->cartRequest();
		Agend_Test_WP::queue_response( 404, array( 'error' => array( 'message' => 'Event not found' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->get_cart( $request );
		self::assertSame( array( 'General Admission', 'VIP', 'Workshop', 'Course', 'Other Ticket' ), array_column( $response->get_data()['data']['items'], 'name' ) );
		self::assertCount( 3, Agend_Test_WP::$requests );
		self::assertSame( '', get_transient( 'agend_apps_cart_event_name_' . md5( 'conference' ) ) );
	}
}
