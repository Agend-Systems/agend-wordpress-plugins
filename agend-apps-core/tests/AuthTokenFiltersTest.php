<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';

/**
 * The public auth filters never receive a member's session tokens, and the
 * refresh call never carries anyone's bearer. A filter is extension surface: a
 * plugin hooking one to log a response would otherwise store a refresh token
 * redeemable at Supabase Auth without the gateway.
 */
final class AuthTokenFiltersTest extends TestCase {

	#[Test]
	public function should_give_the_login_response_filter_no_tokens_and_still_return_the_session(): void {
		$seen = $this->captureFilter( 'agend_apps_auth_login_response' );
		Agend_Test_WP::queue_response( 200, $this->sessionBody() );

		$response = agend_apps_auth_login( 'member@example.test', 'password' );

		$this->assertTokenFree( $seen->value );
		$this->assertSame( 'member@example.test', $seen->value['data']['user']['email'] );
		$this->assertSame( 'access-1', $response['data']['session']['access_token'] );
		$this->assertSame( 'refresh-1', $response['data']['session']['refresh_token'] );
	}

	#[Test]
	public function should_give_the_register_response_filter_no_tokens_and_still_return_the_session(): void {
		$seen = $this->captureFilter( 'agend_apps_auth_register_response' );
		Agend_Test_WP::queue_response( 200, $this->sessionBody() );

		$response = agend_apps_auth_register( array( 'email' => 'member@example.test' ) );

		$this->assertTokenFree( $seen->value );
		$this->assertSame( 'refresh-1', $response['data']['session']['refresh_token'] );
	}

	#[Test]
	public function should_give_the_refresh_filters_no_tokens_and_still_return_the_session(): void {
		$args_seen     = $this->captureFilter( 'agend_apps_auth_refresh_args' );
		$response_seen = $this->captureFilter( 'agend_apps_auth_refresh_response' );
		Agend_Test_WP::queue_response( 200, $this->sessionBody() );

		$response = agend_apps_auth_refresh( 'refresh-0' );

		$this->assertStringNotContainsString( 'refresh-0', (string) wp_json_encode( $args_seen->value ) );
		$this->assertSame( '', $args_seen->extra[0] );
		$this->assertTokenFree( $response_seen->value );
		$this->assertSame( '', $response_seen->extra[0] );
		$this->assertSame( 'access-1', $response['data']['session']['access_token'] );
	}

	#[Test]
	public function should_let_a_response_filter_change_other_fields_but_not_the_session(): void {
		Agend_Test_WP::$filters['agend_apps_auth_login_response'] = static function ( $response ) {
			$response['data']['user']['email']                = 'changed@example.test';
			$response['data']['session']['refresh_token']     = 'forged';
			return $response;
		};
		Agend_Test_WP::queue_response( 200, $this->sessionBody() );

		$response = agend_apps_auth_login( 'member@example.test', 'password' );

		$this->assertSame( 'changed@example.test', $response['data']['user']['email'] );
		$this->assertSame( 'refresh-1', $response['data']['session']['refresh_token'] );
	}

	#[Test]
	public function should_let_a_response_filter_refuse_the_response_with_an_error(): void {
		Agend_Test_WP::$filters['agend_apps_auth_login_response'] = static fn() => new WP_Error( 'refused', 'Refused by site policy.' );
		Agend_Test_WP::queue_response( 200, $this->sessionBody() );

		$this->assertInstanceOf( WP_Error::class, agend_apps_auth_login( 'member@example.test', 'password' ) );
	}

	#[Test]
	public function should_send_the_refresh_without_a_bearer_even_when_the_current_user_has_one(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'admin-bearer' );
		Agend_Test_WP::queue_response( 200, $this->sessionBody() );

		agend_apps_auth_refresh( 'refresh-0' );

		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'] );
	}

	#[Test]
	public function should_ignore_a_refresh_args_filter_that_tries_to_attach_a_bearer_or_rewrite_the_token(): void {
		Agend_Test_WP::$filters['agend_apps_auth_refresh_args'] = static fn() => array(
			'bearer_token' => 'injected-bearer',
			'unattended'   => false,
			'body'         => array( 'refresh_token' => 'injected-token' ),
		);
		Agend_Test_WP::queue_response( 200, $this->sessionBody() );

		agend_apps_auth_refresh( 'refresh-0' );

		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'] );
		$this->assertSame( array( 'refresh_token' => 'refresh-0' ), json_decode( (string) Agend_Test_WP::$requests[0]['body'], true ) );
	}

	#[Test]
	public function should_send_an_explicit_logout_bearer_without_passing_it_to_the_args_filter(): void {
		$seen = $this->captureFilter( 'agend_apps_auth_logout_args' );
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'current-user-bearer' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'status' => 'ok' ) ) );

		agend_apps_auth_logout( 'member-access' );

		$this->assertStringNotContainsString( 'member-access', (string) wp_json_encode( $seen->value ) );
		$this->assertSame( 'Bearer member-access', Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? null );
	}

	/**
	 * Records what a filter receives and passes the value through unchanged.
	 *
	 * @return object{value: mixed, extra: array}
	 */
	private function captureFilter( string $hook ): object {
		$seen = (object) array(
			'value' => null,
			'extra' => array(),
		);

		Agend_Test_WP::$filters[ $hook ] = static function ( $value, ...$extra ) use ( $seen ) {
			$seen->value = $value;
			$seen->extra = $extra;
			return $value;
		};

		return $seen;
	}

	private function assertTokenFree( $value ): void {
		$encoded = (string) wp_json_encode( $value );

		$this->assertStringNotContainsString( 'access-1', $encoded );
		$this->assertStringNotContainsString( 'refresh-1', $encoded );
		$this->assertStringNotContainsString( 'access_token', $encoded );
		$this->assertStringNotContainsString( 'refresh_token', $encoded );
	}

	private function sessionBody(): array {
		return array(
			'data' => array(
				'user'    => array(
					'id'    => 'u-1',
					'email' => 'member@example.test',
				),
				'session' => array(
					'access_token'  => 'access-1',
					'refresh_token' => 'refresh-1',
					'expires_at'    => time() + 3600,
					'expires_in'    => 3600,
					'token_type'    => 'bearer',
				),
			),
		);
	}
}
