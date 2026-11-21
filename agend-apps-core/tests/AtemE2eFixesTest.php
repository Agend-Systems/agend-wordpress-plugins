<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend\Tests\TestCase;
use Agend_Apps_Sites_REST_Controller;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sites.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/sites-routes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pricing-labels.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/member-login.php';

/**
 * Faults found in a client's end-to-end tests: the site config 404 in every
 * browser console, prices with no thousands separator, the membership join
 * link for a signed-in visitor who is not a member, and the Member Login
 * card's wording.
 */
final class AtemE2eFixesTest extends TestCase {

	// -- Site config ------------------------------------------------------

	#[Test]
	public function should_answer_an_account_with_no_published_site_config_with_an_empty_config_not_a_404(): void {
		Agend_Test_WP::queue_response( 404, array( 'success' => false, 'error' => array( 'code' => 'NOT_FOUND', 'message' => 'Site config not found' ) ) );

		$response = ( new Agend_Apps_Sites_REST_Controller() )->get_config( new WP_REST_Request( 'GET', '/agend-apps/v1/sites/config' ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'data' => null ), $response->get_data() );
	}

	#[Test]
	public function should_still_report_a_missing_endpoint_or_an_outage_as_an_error(): void {
		Agend_Test_WP::queue_response( 404, array( 'success' => false, 'error' => array( 'code' => 'NOT_FOUND', 'message' => 'The requested endpoint does not exist' ) ) );
		$missing_route = ( new Agend_Apps_Sites_REST_Controller() )->get_config( new WP_REST_Request( 'GET', '/agend-apps/v1/sites/config' ) );
		self::assertSame( 404, $missing_route->get_status() );

		Agend_Test_WP::queue_response( 500, array( 'success' => false, 'error' => array( 'code' => 'INTERNAL', 'message' => 'boom' ) ) );
		$outage = ( new Agend_Apps_Sites_REST_Controller() )->get_config( new WP_REST_Request( 'GET', '/agend-apps/v1/sites/config' ) );
		self::assertSame( 500, $outage->get_status() );
	}

	// -- Price formatting ---------------------------------------------------

	#[Test]
	public function should_format_prices_with_a_thousands_separator(): void {
		self::assertSame( '$1,325.00', agend_apps_records_ssr_ev_format_price( 1325 ) );
		self::assertSame( '$1,234,567.50', agend_apps_records_ssr_ev_format_price( '1234567.5' ) );
		self::assertSame( '$99.00', agend_apps_records_ssr_ev_format_price( 99 ) );
		self::assertSame( 'FREE', agend_apps_records_ssr_ev_format_price( 0 ) );
		self::assertNull( agend_apps_records_ssr_ev_format_price( 'n/a' ) );
		self::assertSame( '$6,600.00', agend_apps_records_ssr_lms_price( array( 'base_price' => '6600' ) ) );
		self::assertSame( '-$12.50', agend_apps_records_format_money( -12.5 ) );
	}

	// -- Membership join page -------------------------------------------------

	#[Test]
	public function should_keep_only_an_http_or_https_join_page_address(): void {
		self::assertSame( 'https://example.test/membership/join/', agend_apps_records_sanitize_member_join_url( ' https://example.test/membership/join/ ' ) );
		self::assertSame( '', agend_apps_records_sanitize_member_join_url( '' ) );
		self::assertSame( '', agend_apps_records_sanitize_member_join_url( 'javascript:alert(1)' ) );
		self::assertSame( '', agend_apps_records_sanitize_member_join_url( 'mailto:join@example.test' ) );
		self::assertSame( '', agend_apps_records_sanitize_member_join_url( array( 'https://example.test/' ) ) );
	}

	#[Test]
	public function should_have_no_join_page_unless_the_site_sets_one(): void {
		self::assertSame( '', agend_apps_records_member_join_url() );

		update_option( AGEND_APPS_RECORDS_MEMBER_JOIN_URL_OPTION, 'https://example.test/join/' );
		self::assertSame( 'https://example.test/join/', agend_apps_records_member_join_url() );

		Agend_Test_WP::set_filter( 'agend_apps_member_join_url', 'javascript:void(0)' );
		self::assertSame( '', agend_apps_records_member_join_url(), 'a filter cannot supply an unsafe address' );
	}

	// -- Member Login wording ---------------------------------------------------

	#[Test]
	public function should_head_the_signed_in_card_you_are_signed_in_in_place_of_the_stock_sign_in(): void {
		self::assertSame( 'You’re signed in', agend_apps_records_member_login_signed_in_heading( 'Sign in' ) );
		self::assertSame( 'Member area', agend_apps_records_member_login_signed_in_heading( 'Member area' ) );
		self::assertSame( '', agend_apps_records_member_login_signed_in_heading( '' ) );
	}

	#[Test]
	public function should_replace_the_old_verification_default_that_named_a_resend_control(): void {
		$old = 'Check your email to finish signing in. Click the link in the email to confirm it is you, then come back here and sign in again. If it hasn\'t arrived in a few minutes, use Resend below. You only need to do this once.';

		$config = agend_apps_records_member_login_build_config( array( 'verification_sent_message' => $old ), array( 'source' => 'site' ) );
		self::assertStringContainsString( 'choose Send another link below', $config['messages']['verificationSent'] );
		self::assertSame( 'Send another link', $config['messages']['resend'] );
		self::assertSame( 'Use at least 8 characters.', $config['messages']['passwordHint'] );

		$custom = agend_apps_records_member_login_build_config( array( 'verification_sent_message' => 'Our own words.' ), array( 'source' => 'site' ) );
		self::assertSame( 'Our own words.', $custom['messages']['verificationSent'] );
	}

	#[Test]
	public function should_head_the_wordpress_mode_signed_in_card_you_are_signed_in(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$GLOBALS['agend_test_current_user_id'] = 5;

		$markup = agend_apps_records_render_member_login( array( 'heading_text' => 'Sign in', 'portal_button_label' => 'Go to my account' ) );

		self::assertStringContainsString( 'You’re signed in', $markup );
		self::assertStringNotContainsString( '>Sign in</h3>', $markup );
	}
}
