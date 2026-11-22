<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend\Tests\TestCase;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/cart.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/membership-return.php';

/**
 * A paid membership join completes on the member's return from the payment
 * page, for that member's own latest join only.
 */
final class MembershipReturnTest extends TestCase {

	private const SUCCESS = 'https://example.test/membership/join/?agend_membership=success';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['agend_test_current_user_id'] = 42;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		agend_apps_register_membership_return();
	}

	/** Starts a join through the hooks and returns the success URL's reference. */
	private function join( string $session = 'cs_test_join' ): string {
		$args = apply_filters( 'agend_apps_crm_purchase_my_membership_args', array( 'body' => array( 'tier_id' => 't1', 'success_url' => self::SUCCESS, 'cancel_url' => 'https://example.test/membership/join/?agend_membership=cancel' ) ), array() );
		apply_filters( 'agend_apps_crm_purchase_my_membership_response', array( 'success' => true, 'data' => array( 'checkout_url' => 'https://checkout.stripe.com/c/pay/' . $session, 'external_id' => $session, 'cart_id' => 'c1' ) ), array() );
		self::assertSame( 'https://example.test/membership/join/?agend_membership=cancel', $args['body']['cancel_url'] );
		parse_str( (string) wp_parse_url( $args['body']['success_url'], PHP_URL_QUERY ), $query );
		self::assertSame( 'success', $query['agend_membership'] );
		return (string) ( $query['agend_membership_ref'] ?? '' );
	}

	private function confirm( string $token ) {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/crm/me/memberships/return-confirm' );
		$request->set_param( 'token', $token );
		return agend_apps_membership_return_confirm( $request )->get_data();
	}

	private function calls( string $path ): array {
		return array_values( array_filter( Agend_Test_WP::$requests, static fn ( $r ) => str_contains( (string) $r['url'], $path ) ) );
	}

	#[Test]
	public function should_add_a_reference_only_to_a_signed_in_members_same_site_success_url(): void {
		self::assertSame( 32, strlen( $this->join() ) );

		$off = apply_filters( 'agend_apps_crm_purchase_my_membership_args', array( 'body' => array( 'success_url' => 'https://other.test/thanks/' ) ), array() );
		self::assertSame( 'https://other.test/thanks/', $off['body']['success_url'] );

		$GLOBALS['agend_test_current_user_id'] = 0;
		$guest = apply_filters( 'agend_apps_crm_purchase_my_membership_args', array( 'body' => array( 'success_url' => self::SUCCESS ) ), array() );
		self::assertSame( self::SUCCESS, $guest['body']['success_url'] );
	}

	#[Test]
	public function should_complete_the_join_when_the_platform_has_no_status_route_and_remember_it(): void {
		$token = $this->join();
		Agend_Test_WP::queue_response( 404, array( 'success' => false, 'error' => array( 'code' => 'NOT_FOUND', 'message' => 'The requested endpoint does not exist' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'order_id' => 'o1', 'order_number' => 'CRM-2026-00099' ) ) );

		$result = $this->confirm( $token );

		self::assertSame( 'confirmed', $result['status'] );
		self::assertArrayHasKey( 'portalUrl', $result );
		$complete = $this->calls( '/cart/checkout/complete' );
		self::assertCount( 1, $complete );
		self::assertSame( array( 'externalId' => 'cs_test_join' ), json_decode( (string) $complete[0]['body'], true ) );
		self::assertSame( 'Bearer member-token', $complete[0]['headers']['Authorization'] ?? null );

		// A reload asks nothing more.
		self::assertSame( 'confirmed', $this->confirm( $token )['status'] );
		self::assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_say_pending_while_the_payment_is_not_confirmed_and_never_failed(): void {
		$token = $this->join();
		set_transient( 'agend_apps_checkout_status_missing', 1, 60 );
		foreach ( array( 402, 409 ) as $status ) {
			Agend_Test_WP::queue_response( $status, array( 'success' => false, 'error' => array( 'code' => 'PAYMENT_NOT_CONFIRMED', 'message' => 'x' ) ) );
			self::assertSame( array( 'status' => 'pending' ), $this->confirm( $token ) );
		}
		Agend_Test_WP::queue_response( 500, array( 'success' => false, 'error' => array( 'code' => 'INTERNAL', 'message' => 'x' ) ) );
		self::assertSame( array( 'status' => 'unknown' ), $this->confirm( $token ) );
	}

	#[Test]
	public function should_confirm_nothing_for_another_member_a_guessed_or_an_old_reference(): void {
		$old   = $this->join( 'cs_test_old' );
		$token = $this->join( 'cs_test_new' );

		self::assertSame( array( 'status' => 'none' ), $this->confirm( $old ) );
		self::assertSame( array( 'status' => 'none' ), $this->confirm( 'guessed-reference-guessed-ref' ) );
		self::assertSame( array( 'status' => 'none' ), $this->confirm( '' ) );

		$GLOBALS['agend_test_current_user_id'] = 43;
		self::assertSame( array( 'status' => 'none' ), $this->confirm( $token ) );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_use_the_status_route_when_the_platform_has_it(): void {
		$token = $this->join();
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'status' => 'confirmed', 'receipt' => array( 'order_number' => 'CRM-1' ) ) ) );
		self::assertSame( 'confirmed', $this->confirm( $token )['status'] );
		self::assertCount( 0, $this->calls( '/cart/checkout/complete' ) );
	}

	#[Test]
	public function should_never_store_the_reference_itself(): void {
		$token = $this->join();
		foreach ( Agend_Test_WP::$transients as $value ) {
			self::assertStringNotContainsString( $token, (string) json_encode( $value ) );
		}
	}

	#[Test]
	public function should_keep_every_reference_for_a_checkout_the_platform_hands_back_again(): void {
		$first  = $this->join( 'cs_test_same' );
		$second = $this->join( 'cs_test_same' );
		set_transient( 'agend_apps_checkout_status_missing', 1, 60 );

		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'order_id' => 'o1', 'order_number' => 'CRM-7' ) ) );
		self::assertSame( 'confirmed', $this->confirm( $first )['status'], 'the hosted page still returns with the first reference' );
		self::assertSame( 'confirmed', $this->confirm( $second )['status'] );
		self::assertCount( 1, $this->calls( '/cart/checkout/complete' ) );

		// A join that opens a different checkout replaces them.
		$this->join( 'cs_test_other' );
		self::assertSame( array( 'status' => 'none' ), $this->confirm( $first ) );
	}
}
