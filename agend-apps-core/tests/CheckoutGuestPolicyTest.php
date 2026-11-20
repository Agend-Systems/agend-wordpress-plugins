<?php
/** @package Agend\Tests */
declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Cart_REST_Controller;
use Agend_Apps_Events_REST_Controller;
use Agend_Apps_CRM_REST_Controller;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/cart.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/cart-routes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/events-routes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/crm-routes.php';

#[RunTestsInSeparateProcesses]
final class CheckoutGuestPolicyTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		// Neighbouring CartRoutesTest loads individual real wrappers because
		// other functions in these API files are doubled by the bootstrap.
		foreach ( array(
			'events' => array( 'agend_apps_events_register', 'agend_apps_events_pay_registration' ),
			'crm'    => array( 'agend_apps_crm_purchase_membership' ),
		) as $file => $helpers ) {
			$source = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/' . $file . '.php' );
			foreach ( $helpers as $helper ) {
				if ( 1 !== preg_match( '/^function ' . $helper . '\\(.*?^}$/ms', $source, $match ) ) {
					throw new \RuntimeException( 'Checkout helper not found: ' . $helper );
				}
				// phpcs:ignore Squiz.PHP.Eval.Discouraged
				eval( $match[0] );
			}
		}
	}

	public static function routes(): array {
		return array(
			'paid event registration' => array( Agend_Apps_Events_REST_Controller::class, 'register', '/events/conference/register', array( 'ticket_type_id' => 'paid-ticket', 'quantity' => 1 ) ),
			'free event registration' => array( Agend_Apps_Events_REST_Controller::class, 'register', '/events/conference/register', array( 'ticket_type_id' => 'free-ticket', 'quantity' => 1 ) ),
			'pending event payment'   => array( Agend_Apps_Events_REST_Controller::class, 'pay_registrations', '/events/registrations/pay', array( 'registration_ids' => array( 'registration-1' ) ) ),
			'cart completion'         => array( Agend_Apps_Cart_REST_Controller::class, 'checkout_complete', '/cart/checkout/complete', array() ),
		);
	}

	private function request( string $route, array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1' . $route );
		$request->set_header( 'X-Cart-Session', 'guest-session' );
		$request->set_param( 'slug', 'conference' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return $request;
	}

	#[Test]
	#[DataProvider( 'routes' )]
	public function should_refuse_guests_before_any_gateway_request( string $class, string $method, string $route, array $body ): void {
		foreach ( array( null, 'require', 'unknown' ) as $setting ) {
			if ( null === $setting ) {
				delete_option( 'agend_apps_shop_guest_checkout' );
			} else {
				update_option( 'agend_apps_shop_guest_checkout', $setting );
			}
			$response = ( new $class() )->$method( $this->request( $route, $body ) );
			self::assertSame( 401, $response->get_status() );
			self::assertSame( array(
				'code' => 'agend_apps_sign_in_required',
				'message' => 'Sign in to check out.',
				'data' => array( 'status_code' => 401 ),
			), $response->get_data() );
			self::assertCount( 0, Agend_Test_WP::$requests );
		}
	}

	#[Test]
	#[DataProvider( 'routes' )]
	public function should_forward_allowed_guests_and_members_without_changing_the_payload( string $class, string $method, string $route, array $body ): void {
		foreach ( array( false, true ) as $member ) {
			update_option( 'agend_apps_shop_guest_checkout', $member ? 'require' : 'allow' );
			Agend_Test_WP::set_filter( 'agend_apps_bearer_token', $member ? 'member-token' : '' );
			$reply = array( 'data' => array( 'id' => 'result-1' ) );
			Agend_Test_WP::queue_response( 200, $reply );
			$response = ( new $class() )->$method( $this->request( $route, $body ) );
			self::assertSame( 200, $response->get_status() );
			self::assertSame( $reply['data'], $response->get_data()['data'] );
			$sent = Agend_Test_WP::$requests[ $member ? 1 : 0 ];
			self::assertStringEndsWith( $route, $sent['url'] );
			if ( array() !== $body ) {
				self::assertSame( $body, json_decode( $sent['body'], true ) );
			}
			if ( $member ) {
				self::assertSame( 'Bearer member-token', $sent['headers']['Authorization'] );
			}
		}
	}

	#[Test]
	public function should_keep_joining_open_to_signed_out_visitors_when_checkout_requires_sign_in(): void {
		// Joining is how a visitor becomes a member, so the guest checkout
		// setting must never block the public membership catalogue.
		update_option( 'agend_apps_shop_guest_checkout', 'require' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'membership-1' ) ) );
		$response = ( new Agend_Apps_CRM_REST_Controller() )->purchase_membership( $this->request( '/crm/memberships/purchase', array( 'contact_id' => 'contact-1', 'tier_id' => 'tier-1' ) ) );
		self::assertSame( 200, $response->get_status() );
		self::assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_apply_the_shared_guest_policy_filter_to_event_registration(): void {
		Agend_Test_WP::set_filter( 'agend_apps_cart_guest_checkout_allowed', true );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'registration-1' ) ) );
		$response = ( new Agend_Apps_Events_REST_Controller() )->register( $this->request( '/events/conference/register', array() ) );
		self::assertSame( 200, $response->get_status() );
		self::assertCount( 1, Agend_Test_WP::$requests );
	}
}
