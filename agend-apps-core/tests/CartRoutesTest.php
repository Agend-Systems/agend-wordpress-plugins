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
		// The guest checkout cases below predate the Guest checkout setting
		// and run with guests allowed; the refusal cases clear it.
		update_option( 'agend_apps_shop_guest_checkout', 'allow' );
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
		self::assertSame( 60, Agend_Test_WP::$transient_ttls['agend_apps_cart_event_name_' . md5( 'conference' )] );
		self::assertSame( 60, Agend_Test_WP::$transient_ttls['agend_apps_cart_event_name_' . md5( 'workshop' )] );
	}

	#[Test]
	public function should_refuse_guest_checkout_without_calling_the_gateway_when_sign_in_is_required(): void {
		delete_option( 'agend_apps_shop_guest_checkout' );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_cart( $this->checkoutRequest() );
		self::assertSame( 401, $response->get_status() );
		self::assertSame( 'agend_apps_sign_in_required', $response->get_data()['code'] );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_refuse_a_guest_buyer_when_the_setting_requires_sign_in(): void {
		update_option( 'agend_apps_shop_guest_checkout', 'require' );
		$request = $this->checkoutRequest();
		$request->set_param( 'guest_buyer', array( 'name' => 'Alex', 'email' => 'alex@example.test' ) );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_cart( $request );
		self::assertSame( 401, $response->get_status() );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_refuse_guests_when_the_setting_holds_an_unknown_value(): void {
		update_option( 'agend_apps_shop_guest_checkout', 'yes' );
		self::assertFalse( Agend_Apps_Cart_REST_Controller::guest_checkout_allowed() );
	}

	#[Test]
	public function should_let_a_member_check_out_when_sign_in_is_required(): void {
		delete_option( 'agend_apps_shop_guest_checkout' );
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		$body = $this->checkoutBody( $this->checkoutRequest() );
		self::assertSame( 'https://shop.test/thanks', $body['successUrl'] );
		self::assertSame( 'Bearer member-token', Agend_Test_WP::$requests[0]['headers']['Authorization'] );
	}

	private function invoiceOption( array $overrides = array() ): array {
		return array_merge(
			array(
				'available'             => true,
				'reason'                => null,
				'reasonMessage'         => null,
				'purchaseOrderRequired' => true,
				'termsDays'             => 30,
				'paymentInstructions'   => 'Pay by EFT to BSB 000-000.',
				'releasePolicies'       => array( 'event_tickets' => 'payment_confirmed' ),
				'unsupportedProductTypes' => array(),
				'approvedCompanyIds'    => array(),
				'dueAt'                 => '2026-12-20T13:00:00+00:00',
				'dueLocalDate'          => '2026-12-21',
				'timezone'              => 'Australia/Sydney',
			),
			$overrides
		);
	}

	private function optionsRequest(): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/agend-apps/v1/cart/checkout-options' );
		$request->set_header( 'X-Cart-Session', 'guest-session' );
		return $request;
	}

	#[Test]
	public function should_refuse_checkout_options_to_a_guest_without_calling_the_gateway_when_sign_in_is_required(): void {
		delete_option( 'agend_apps_shop_guest_checkout' );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_options( $this->optionsRequest() );
		self::assertSame( 401, $response->get_status() );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_refuse_checkout_options_to_a_guest_without_a_cart(): void {
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_options( new WP_REST_Request( 'GET', '/agend-apps/v1/cart/checkout-options' ) );
		self::assertSame( 401, $response->get_status() );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_tell_an_allowed_guest_only_whether_the_organisation_takes_guest_event_registrations(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'invoice' => $this->invoiceOption(), 'membershipInstalments' => null, 'guestEventCheckout' => false ) ) );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_options( $this->optionsRequest() );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'data' => array( 'guestEventCheckout' => false ) ), $response->get_data() );
		self::assertSame( 'guest-session', Agend_Test_WP::$requests[0]['headers']['X-Cart-Session'] );
		self::assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'] );
	}

	#[Test]
	public function should_show_a_guest_only_the_status_when_the_gateway_refuses_or_answers_badly(): void {
		$leak = array(
			'error' => array(
				'code'    => 'FORBIDDEN',
				'message' => 'Buyer member@example.test may not invoice.',
				'details' => array( 'paymentInstructions' => 'Pay by EFT to BSB 000-000.' ),
			),
		);
		Agend_Test_WP::queue_response( 403, $leak );
		$refused = ( new Agend_Apps_Cart_REST_Controller() )->checkout_options( $this->optionsRequest() );
		self::assertSame( 403, $refused->get_status() );
		self::assertSame(
			array(
				'code'    => 'agend_apps_checkout_options_unavailable',
				'message' => 'Checkout options are not available right now.',
				'data'    => array( 'status_code' => 403 ),
			),
			$refused->get_data()
		);

		Agend_Test_WP::queue_response( 200, '<html>member@example.test</html>' );
		$malformed = ( new Agend_Apps_Cart_REST_Controller() )->checkout_options( $this->optionsRequest() );
		self::assertGreaterThanOrEqual( 400, $malformed->get_status() );
		self::assertStringNotContainsString( 'member@example.test', (string) wp_json_encode( $malformed->get_data() ) );
		self::assertSame( 'agend_apps_checkout_options_unavailable', $malformed->get_data()['code'] );
	}

	#[Test]
	public function should_leave_the_guest_event_answer_out_when_the_gateway_does_not_give_one(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'invoice' => $this->invoiceOption(), 'membershipInstalments' => null ) ) );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_options( $this->optionsRequest() );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'data' => array() ), $response->get_data() );
	}

	#[Test]
	public function should_pass_the_invoice_option_through_for_a_member(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'invoice' => $this->invoiceOption(), 'membershipInstalments' => null ) ) );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_options( $this->optionsRequest() );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( $this->invoiceOption(), $response->get_data()['data']['invoice'] );
		self::assertSame( 'GET', Agend_Test_WP::$requests[0]['method'] );
		self::assertStringEndsWith( '/cart/checkout-options', Agend_Test_WP::$requests[0]['url'] );
		self::assertSame( 'Bearer member-token', Agend_Test_WP::$requests[0]['headers']['Authorization'] );
	}

	#[Test]
	public function should_mark_invoice_unavailable_when_pay_on_invoice_is_switched_off(): void {
		update_option( 'agend_apps_shop_pay_on_invoice', '0' );
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'invoice' => $this->invoiceOption(), 'membershipInstalments' => null ) ) );
		$invoice = ( new Agend_Apps_Cart_REST_Controller() )->checkout_options( $this->optionsRequest() )->get_data()['data']['invoice'];
		self::assertFalse( $invoice['available'] );
		self::assertSame( 'invoice_disabled', $invoice['reason'] );
		self::assertNull( $invoice['paymentInstructions'] );
	}

	#[Test]
	public function should_name_each_company_when_the_buyer_may_invoice_several(): void {
		$ids = array( '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222' );
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'invoice' => $this->invoiceOption( array( 'approvedCompanyIds' => $ids ) ) ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => $ids[0], 'name' => 'Acme School' ) ) );
		Agend_Test_WP::queue_response( 404, array( 'error' => array( 'message' => 'Not found' ) ) );
		$invoice = ( new Agend_Apps_Cart_REST_Controller() )->checkout_options( $this->optionsRequest() )->get_data()['data']['invoice'];
		self::assertSame(
			array(
				array( 'id' => $ids[0], 'name' => 'Acme School' ),
				array( 'id' => $ids[1], 'name' => '' ),
			),
			$invoice['approvedCompanies']
		);
		self::assertStringEndsWith( '/crm/companies/' . $ids[0], Agend_Test_WP::$requests[1]['url'] );
		self::assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[1]['headers'] );
	}

	#[Test]
	public function should_leave_a_single_company_unnamed(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'invoice' => $this->invoiceOption( array( 'approvedCompanyIds' => array( '11111111-1111-4111-8111-111111111111' ) ) ) ) ) );
		$invoice = ( new Agend_Apps_Cart_REST_Controller() )->checkout_options( $this->optionsRequest() )->get_data()['data']['invoice'];
		self::assertArrayNotHasKey( 'approvedCompanies', $invoice );
		self::assertCount( 1, Agend_Test_WP::$requests );
	}

	private function invoiceRequest( array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/cart/checkout/invoice' );
		$request->set_header( 'X-Cart-Session', 'guest-session' );
		foreach ( array_merge( array( 'idempotencyKey' => 'wp-cart-key-1' ), $params ) as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	private function acceptance(): array {
		return array(
			'orderId'             => '33333333-3333-4333-8333-333333333333',
			'orderNumber'         => 'ORD-1001',
			'invoiceOrderId'      => '44444444-4444-4444-8444-444444444444',
			'totalCents'          => 12000,
			'currency'            => 'AUD',
			'dueAt'               => '2026-12-20T13:00:00+00:00',
			'dueLocalDate'        => '2026-12-21',
			'timezone'            => 'Australia/Sydney',
			'purchaseOrderNumber' => 'PO-77',
			'paymentInstructions' => 'Pay by EFT.',
			'lines'               => array(),
		);
	}

	#[Test]
	public function should_refuse_invoice_checkout_to_a_guest_without_calling_the_gateway(): void {
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_invoice( $this->invoiceRequest() );
		self::assertSame( 401, $response->get_status() );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_refuse_invoice_checkout_when_pay_on_invoice_is_switched_off(): void {
		update_option( 'agend_apps_shop_pay_on_invoice', '0' );
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_invoice( $this->invoiceRequest() );
		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'agend_apps_invoice_disabled', $response->get_data()['code'] );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_send_the_invoice_order_with_its_purchase_order_and_company(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::queue_response( 200, array( 'data' => $this->acceptance() ) );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_invoice(
			$this->invoiceRequest(
				array(
					'purchaseOrderNumber' => '  PO-77 ',
					'companyId'           => '11111111-1111-4111-8111-11111111111A',
					'paymentMethod'       => 'card',
				)
			)
		);
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'ORD-1001', $response->get_data()['data']['orderNumber'] );
		self::assertSame( 'POST', Agend_Test_WP::$requests[0]['method'] );
		self::assertStringEndsWith( '/cart/checkout/invoice', Agend_Test_WP::$requests[0]['url'] );
		self::assertSame( 'Bearer member-token', Agend_Test_WP::$requests[0]['headers']['Authorization'] );
		self::assertSame(
			array(
				'paymentMethod'       => 'invoice',
				'idempotencyKey'      => 'wp-cart-key-1',
				'purchaseOrderNumber' => 'PO-77',
				'companyId'           => '11111111-1111-4111-8111-11111111111a',
			),
			json_decode( Agend_Test_WP::$requests[0]['body'], true )
		);
	}

	#[Test]
	public function should_omit_an_empty_purchase_order_and_an_invalid_company(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::queue_response( 200, array( 'data' => $this->acceptance() ) );
		( new Agend_Apps_Cart_REST_Controller() )->checkout_invoice( $this->invoiceRequest( array( 'purchaseOrderNumber' => '   ', 'companyId' => 'not-a-company' ) ) );
		self::assertSame(
			array( 'paymentMethod' => 'invoice', 'idempotencyKey' => 'wp-cart-key-1' ),
			json_decode( Agend_Test_WP::$requests[0]['body'], true )
		);
	}

	#[Test]
	public function should_refuse_a_purchase_order_number_over_the_platform_limit(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_invoice( $this->invoiceRequest( array( 'purchaseOrderNumber' => str_repeat( 'P', 101 ) ) ) );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'agend_apps_invalid_purchase_order', $response->get_data()['code'] );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_pass_the_platform_refusal_through_when_a_purchase_order_is_required(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::queue_response( 400, array( 'error' => array( 'code' => 'INVALID_PURCHASE_ORDER', 'message' => 'Enter a purchase order number to order on invoice' ) ) );
		$response = ( new Agend_Apps_Cart_REST_Controller() )->checkout_invoice( $this->invoiceRequest() );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'Enter a purchase order number to order on invoice', $response->get_data()['message'] );
	}
}
