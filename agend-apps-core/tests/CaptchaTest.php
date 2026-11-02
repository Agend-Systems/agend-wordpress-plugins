<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Admin;
use Agend_Apps_Captcha;
use Agend_Apps_Secret_Store;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/enquiry-stubs.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-captcha.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-member-session.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/admin/class-agend-apps-admin.php';

/**
 * Server-side captcha verification and the settings behind it. Every provider
 * call is stubbed through the shared wp_remote_request() recorder.
 */
#[CoversClass( Agend_Apps_Captcha::class )]
final class CaptchaTest extends TestCase {

	private function configure( string $provider, string $secret = 'the-secret', string $site_key = 'the-site-key' ): void {
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, $provider );
		update_option( Agend_Apps_Captcha::OPTION_SITE_KEY, $site_key );

		if ( '' !== $secret ) {
			Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, $secret );
		}
	}

	protected function tearDown(): void {
		Agend_Apps_Secret_Store::delete( Agend_Apps_Captcha::SECRET_STORE_KEY );
		parent::tearDown();
	}

	private function reason( $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_apps_captcha_failed', $result->get_error_code() );

		return (string) $result->get_error_data()['reason'];
	}

	#[Test]
	public function should_pass_without_calling_a_provider_when_no_provider_is_selected(): void {
		$this->assertTrue( Agend_Apps_Captcha::verify( '' ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_default_to_no_provider_when_the_stored_value_is_unknown(): void {
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, 'hcaptcha' );

		$this->assertSame( Agend_Apps_Captcha::PROVIDER_NONE, Agend_Apps_Captcha::provider() );
		$this->assertFalse( Agend_Apps_Captcha::is_enabled() );
	}

	#[Test]
	public function should_post_the_token_secret_and_visitor_ip_to_turnstile_siteverify(): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_TURNSTILE );
		Agend_Test_WP::queue_response( 200, array( 'success' => true ) );

		$this->assertTrue( Agend_Apps_Captcha::verify( 'visitor-token', '203.0.113.9' ) );

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertSame( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', Agend_Test_WP::$requests[0]['url'] );
		$this->assertSame(
			array(
				'secret'   => 'the-secret',
				'response' => 'visitor-token',
				'remoteip' => '203.0.113.9',
			),
			Agend_Test_WP::$requests[0]['body']
		);
	}

	#[Test]
	public function should_refuse_when_turnstile_rejects_the_token(): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_TURNSTILE );
		Agend_Test_WP::queue_response( 200, array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) ) );

		$this->assertSame( 'rejected', $this->reason( Agend_Apps_Captcha::verify( 'bad-token' ) ) );
	}

	#[Test]
	public function should_pass_a_recaptcha_answer_that_meets_the_threshold_and_action(): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_RECAPTCHA );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'score' => 0.5, 'action' => 'agend_enquiry' ) );

		$this->assertTrue( Agend_Apps_Captcha::verify( 'visitor-token' ) );
		$this->assertSame( 'https://www.google.com/recaptcha/api/siteverify', Agend_Test_WP::$requests[0]['url'] );
		$this->assertArrayNotHasKey( 'remoteip', Agend_Test_WP::$requests[0]['body'] );
	}

	#[Test]
	public function should_refuse_a_recaptcha_score_below_the_threshold(): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_RECAPTCHA );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'score' => 0.3, 'action' => 'agend_enquiry' ) );

		$this->assertSame( 'low_score', $this->reason( Agend_Apps_Captcha::verify( 'visitor-token' ) ) );
	}

	#[Test]
	public function should_apply_the_configured_recaptcha_threshold(): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_RECAPTCHA );
		update_option( Agend_Apps_Captcha::OPTION_THRESHOLD, '0.9' );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'score' => 0.8, 'action' => 'agend_enquiry' ) );

		$this->assertSame( 'low_score', $this->reason( Agend_Apps_Captcha::verify( 'visitor-token' ) ) );
	}

	#[Test]
	public function should_refuse_a_recaptcha_answer_for_a_different_action(): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_RECAPTCHA );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'score' => 0.9, 'action' => 'login' ) );

		$this->assertSame( 'action_mismatch', $this->reason( Agend_Apps_Captcha::verify( 'visitor-token' ) ) );
	}

	#[Test]
	public function should_expect_the_configured_recaptcha_action(): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_RECAPTCHA );
		update_option( Agend_Apps_Captcha::OPTION_ACTION, 'smsf/contact' );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'score' => 0.9, 'action' => 'smsf/contact' ) );

		$this->assertTrue( Agend_Apps_Captcha::verify( 'visitor-token' ) );
	}

	#[Test]
	public function should_refuse_a_recaptcha_answer_with_no_score(): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_RECAPTCHA );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'action' => 'agend_enquiry' ) );

		$this->assertSame( 'bad_response', $this->reason( Agend_Apps_Captcha::verify( 'visitor-token' ) ) );
	}

	#[Test]
	public function should_refuse_without_calling_the_provider_when_the_token_is_missing(): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_TURNSTILE );

		$this->assertSame( 'missing_token', $this->reason( Agend_Apps_Captcha::verify( '  ' ) ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_refuse_without_calling_the_provider_when_no_secret_is_set(): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_TURNSTILE, '' );

		$this->assertSame( 'not_configured', $this->reason( Agend_Apps_Captcha::verify( 'visitor-token' ) ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_refuse_when_the_provider_cannot_be_reached(): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_TURNSTILE );
		Agend_Test_WP::queue_transport_error( 'http_request_failed', 'cURL error 28' );

		$this->assertSame( 'unreachable', $this->reason( Agend_Apps_Captcha::verify( 'visitor-token' ) ) );
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function unusable_answers(): array {
		return array(
			'server error' => array( 500, '{"success":true}' ),
			'not json'     => array( 200, '<html>oops</html>' ),
			'json scalar'  => array( 200, 'true' ),
		);
	}

	#[Test]
	#[DataProvider( 'unusable_answers' )]
	public function should_refuse_when_the_provider_answer_is_unusable( int $status, string $body ): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_RECAPTCHA );
		Agend_Test_WP::queue_response( $status, $body );

		$this->assertSame( 'bad_response', $this->reason( Agend_Apps_Captcha::verify( 'visitor-token' ) ) );
	}

	#[Test]
	public function should_clamp_a_score_threshold_into_zero_to_one(): void {
		$this->assertSame( 1.0, Agend_Apps_Captcha::normalize_threshold( '7' ) );
		$this->assertSame( 0.0, Agend_Apps_Captcha::normalize_threshold( '-1' ) );
		$this->assertSame( 0.5, Agend_Apps_Captcha::normalize_threshold( 'abc' ) );
		$this->assertSame( 0.7, Agend_Apps_Captcha::normalize_threshold( '0.7' ) );
	}

	#[Test]
	public function should_give_a_page_only_the_provider_site_key_and_action(): void {
		$this->configure( Agend_Apps_Captcha::PROVIDER_RECAPTCHA );

		$this->assertSame(
			array(
				'provider' => 'recaptcha',
				'siteKey'  => 'the-site-key',
				'action'   => 'agend_enquiry',
			),
			Agend_Apps_Captcha::client_config()
		);
		$this->assertStringNotContainsString( 'the-secret', (string) json_encode( Agend_Apps_Captcha::client_config() ) );
	}

	#[Test]
	public function should_give_a_page_nothing_when_no_provider_or_no_site_key_is_set(): void {
		$this->assertNull( Agend_Apps_Captcha::client_config() );

		$this->configure( Agend_Apps_Captcha::PROVIDER_TURNSTILE, 'the-secret', '' );

		$this->assertNull( Agend_Apps_Captcha::client_config() );
	}

	#[Test]
	public function should_store_a_posted_secret_encrypted_and_keep_the_plain_option_empty(): void {
		$admin = new Agend_Apps_Admin();

		$this->assertSame( '', $admin->sanitize_captcha_secret_setting( '  posted-secret  ' ) );
		$this->assertSame( 'posted-secret', Agend_Apps_Secret_Store::get( Agend_Apps_Captcha::SECRET_STORE_KEY ) );
		$this->assertFalse( get_option( 'agend_apps_captcha_secret' ) );
	}

	#[Test]
	public function should_keep_the_stored_secret_when_the_field_is_left_blank(): void {
		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'kept' );

		( new Agend_Apps_Admin() )->sanitize_captcha_secret_setting( '' );

		$this->assertSame( 'kept', Agend_Apps_Secret_Store::get( Agend_Apps_Captcha::SECRET_STORE_KEY ) );
	}

	#[Test]
	public function should_clear_the_stored_secret_when_the_clear_box_is_ticked(): void {
		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'doomed' );
		$_POST['agend_apps_captcha_secret_clear'] = '1';

		try {
			( new Agend_Apps_Admin() )->sanitize_captcha_secret_setting( '' );
		} finally {
			unset( $_POST['agend_apps_captcha_secret_clear'] );
		}

		$this->assertSame( '', Agend_Apps_Secret_Store::get( Agend_Apps_Captcha::SECRET_STORE_KEY ) );
	}

	#[Test]
	public function should_never_print_the_stored_secret_in_the_settings_field(): void {
		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'super-secret-value' );

		ob_start();
		( new Agend_Apps_Admin() )->render_captcha_secret_field();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'super-secret-value', $html );
		$this->assertStringContainsString( 'set (stored encrypted)', $html );
		$this->assertStringContainsString( 'type="password"', $html );
	}

	#[Test]
	public function should_reduce_a_posted_action_name_to_the_characters_google_allows(): void {
		$admin = new Agend_Apps_Admin();

		$this->assertSame( 'smsf/contact_us', $admin->sanitize_captcha_action( 'smsf/contact_us!' ) );
		$this->assertSame( Agend_Apps_Captcha::DEFAULT_ACTION, $admin->sanitize_captcha_action( '!!!' ) );
	}
}
