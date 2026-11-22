<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend\Tests\TestCase;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/locale.php';

/**
 * A new member returns to this site after confirming their email: register
 * sends the site's sign-in page as `redirect_to`, and a platform whose API key
 * allowlist lacks it never breaks sign-up.
 */
final class RegisterReturnTest extends TestCase {

	private string $previousErrorLog = '';

	protected function setUp(): void {
		parent::setUp();
		// The fallback warns the site operator through error_log(); keep it
		// out of the test output.
		$this->previousErrorLog = (string) ini_get( 'error_log' );
		ini_set( 'error_log', '/dev/null' );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->previousErrorLog );
		parent::tearDown();
	}

	private function registerBodies(): array {
		return array_values(
			array_map(
				static fn ( $r ) => json_decode( (string) $r['body'], true ),
				array_filter( Agend_Test_WP::$requests, static fn ( $r ) => str_contains( (string) $r['url'], '/auth/register' ) )
			)
		);
	}

	#[Test]
	public function should_send_the_member_sign_in_page_as_the_return_address(): void {
		update_option( 'agend_apps_checkout_signin_url', 'https://example.test/login/' );
		$payload = agend_apps_provision_register_payload( 'new@example.test', 'password1', 'New', 'Member' );
		self::assertSame( 'https://example.test/login/', $payload['redirect_to'] );
	}

	#[Test]
	public function should_fall_back_to_the_wordpress_login_page(): void {
		$payload = agend_apps_provision_register_payload( 'new@example.test', 'password1' );
		self::assertSame( wp_login_url(), $payload['redirect_to'] );
	}

	#[Test]
	public function should_let_a_site_change_or_drop_the_return_address_but_never_to_a_non_web_address(): void {
		Agend_Test_WP::set_filter( 'agend_apps_register_redirect_to', 'https://example.test/welcome/' );
		self::assertSame( 'https://example.test/welcome/', agend_apps_provision_register_payload( 'a@example.test', 'p' )['redirect_to'] );

		Agend_Test_WP::set_filter( 'agend_apps_register_redirect_to', '' );
		self::assertArrayNotHasKey( 'redirect_to', agend_apps_provision_register_payload( 'a@example.test', 'p' ) );

		foreach ( array( 'javascript:alert(1)', '/login/', 'mailto:a@example.test', 42 ) as $bad ) {
			Agend_Test_WP::set_filter( 'agend_apps_register_redirect_to', $bad );
			self::assertArrayNotHasKey( 'redirect_to', agend_apps_provision_register_payload( 'a@example.test', 'p' ), var_export( $bad, true ) );
		}
	}

	#[Test]
	public function should_register_again_without_the_return_address_when_the_released_platform_refuses_it(): void {
		Agend_Test_WP::queue_response( 400, array( 'success' => false, 'error' => array( 'code' => 'BAD_REQUEST', 'message' => 'redirect_to is not in the API key allowlist' ) ) );
		Agend_Test_WP::queue_response( 201, array( 'success' => true, 'data' => array( 'user' => array( 'id' => 'u1' ), 'session' => null, 'pending_email_confirmation' => true ) ) );

		$response = agend_apps_auth_register( array( 'email' => 'new@example.test', 'password' => 'password1', 'redirect_to' => 'https://example.test/login/' ) );

		self::assertIsArray( $response );
		$bodies = $this->registerBodies();
		self::assertCount( 2, $bodies );
		self::assertSame( 'https://example.test/login/', $bodies[0]['redirect_to'] );
		self::assertArrayNotHasKey( 'redirect_to', $bodies[1] );
		self::assertSame( 'new@example.test', $bodies[1]['email'] );
	}

	#[Test]
	public function should_register_again_when_a_later_platform_names_the_refusal(): void {
		Agend_Test_WP::queue_response( 400, array( 'success' => false, 'error' => array( 'code' => 'REDIRECT_NOT_ALLOWED', 'message' => 'Not allowed' ) ) );
		Agend_Test_WP::queue_response( 201, array( 'success' => true, 'data' => array( 'user' => array( 'id' => 'u1' ) ) ) );
		self::assertIsArray( agend_apps_auth_register( array( 'email' => 'n@example.test', 'password' => 'p', 'redirect_to' => 'https://example.test/login/' ) ) );
		self::assertCount( 2, $this->registerBodies() );
	}

	#[Test]
	public function should_not_retry_any_other_refusal_or_a_payload_without_a_return_address(): void {
		Agend_Test_WP::queue_response( 400, array( 'success' => false, 'error' => array( 'code' => 'VALIDATION_ERROR', 'message' => 'Password must be at least 8 characters' ) ) );
		self::assertInstanceOf( \WP_Error::class, agend_apps_auth_register( array( 'email' => 'n@example.test', 'password' => 'p', 'redirect_to' => 'https://example.test/login/' ) ) );

		Agend_Test_WP::queue_response( 400, array( 'success' => false, 'error' => array( 'code' => 'BAD_REQUEST', 'message' => 'redirect_to is not in the API key allowlist' ) ) );
		self::assertInstanceOf( \WP_Error::class, agend_apps_auth_register( array( 'email' => 'n@example.test', 'password' => 'p' ) ) );

		self::assertCount( 2, $this->registerBodies(), 'one request each, no retry' );
	}

	#[Test]
	public function should_give_the_browser_the_sites_locale_as_a_language_tag(): void {
		self::assertMatchesRegularExpression( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]+)*$/', agend_apps_js_locale() );
		self::assertStringNotContainsString( '_', agend_apps_js_locale() );
	}
}
