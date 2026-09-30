<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Settings;
use Agend_Test_User;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sso.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/connect-site.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-link.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-saml-link.php';

/**
 * Signing in always leads to a link attempt (`includes/wp-idp-saml-link.php`):
 * the `wp_login` marker, the wp-admin trigger on `admin_footer`, the My
 * Account dashboard landing, and the skip reasons the diagnostics panel
 * shows.
 *
 * The hook wrappers are called directly: the harness clears registered
 * actions before every test, so the wiring itself is asserted against the
 * source instead.
 */
final class WpIdpSamlLinkLoginTriggerTest extends TestCase {

	private const SP = 'https://gw.example.test/api/auth/sso/wdaa/metadata';

	private array $server = array();

	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/fixtures/saml-idp-stub.php';
		require_once __DIR__ . '/fixtures/woocommerce-page-stub.php';

		update_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML );
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
		update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://example.test/saml/metadata' ) );

		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'sso.identities.read', 'sso.tokens.create' ) ) ) );
		Agend_Apps_Key_Scopes::refresh();
		Agend_Test_WP::$requests = array();

		// The front-end hand-off is a function-level static, so it survives
		// between tests unless cleared.
		\agend_apps_saml_link_pending( '' );

		$this->server = $_SERVER;
		$_GET         = array();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		$_GET    = array();

		parent::tearDown();
	}

	private function eligible( int $user_id ): void {
		update_user_meta( $user_id, \AGEND_APPS_EXTERNAL_ID_META, 'ext-' . $user_id );
		update_option( 'wp_saml_idp_service_providers', array( array( 'entityId' => self::SP ) ) );
	}

	private function signIn( int $user_id ): void {
		$GLOBALS['agend_test_current_user_id'] = $user_id;
		\agend_apps_saml_link_schedule_on_login( 'member-' . $user_id, new Agend_Test_User( array(), $user_id ) );
	}

	private function request( string $uri ): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = $uri;
	}

	private function adminFooter(): string {
		ob_start();
		\agend_apps_saml_link_render_admin_placeholder();

		return (string) ob_get_clean();
	}

	private function frontEndPage(): string {
		\agend_apps_saml_link_maybe_trigger();

		ob_start();
		\agend_apps_saml_link_render_placeholder();

		return (string) ob_get_clean();
	}

	private function skipReason( int $user_id ): string {
		$stored = get_user_meta( $user_id, \AGEND_APPS_SAML_LINK_SKIP_META, true );

		return is_array( $stored ) ? (string) $stored['reason'] : '';
	}

	// -----------------------------------------------------------------
	// Hook wiring
	// -----------------------------------------------------------------

	#[Test]
	public function should_register_the_sign_in_and_admin_footer_hooks(): void {
		$source = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-saml-link.php' );

		$this->assertStringContainsString( "add_action( 'wp_login', 'agend_apps_saml_link_schedule_on_login', 10, 2 );", $source );
		$this->assertStringContainsString( "add_action( 'admin_footer', 'agend_apps_saml_link_render_admin_placeholder' );", $source );
		$this->assertStringContainsString( "add_action( 'wp_footer', 'agend_apps_saml_link_render_placeholder' );", $source );
	}

	#[Test]
	public function should_print_the_rest_nonce_on_admin_pages_as_well_as_the_front_end(): void {
		$source = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/agend-apps-core.php' );

		// The placeholder script reads window.agendApps.nonce; without it on
		// admin pages the wp-admin attempt would fail silently.
		$this->assertStringContainsString( "add_action( 'wp_head', 'agend_apps_output_config_js', 1 );", $source );
		$this->assertStringContainsString( "add_action( 'admin_head', 'agend_apps_output_config_js', 1 );", $source );
	}

	// -----------------------------------------------------------------
	// Sign-in scheduling
	// -----------------------------------------------------------------

	#[Test]
	public function should_set_the_sign_in_marker_for_an_eligible_member(): void {
		$this->eligible( 70 );

		$this->signIn( 70 );

		$this->assertGreaterThan( 0, \agend_apps_saml_link_login_pending( 70 ) );
	}

	#[Test]
	public function should_not_set_the_sign_in_marker_for_an_ineligible_member(): void {
		// No external id, so eligibility fails with no_external_id.
		update_option( 'wp_saml_idp_service_providers', array( array( 'entityId' => self::SP ) ) );

		$this->signIn( 71 );

		$this->assertSame( 0, \agend_apps_saml_link_login_pending( 71 ) );
	}

	#[Test]
	public function should_not_set_the_sign_in_marker_for_a_linked_member(): void {
		$this->eligible( 72 );
		\agend_apps_wp_idp_record_link_state( 72, \AGEND_APPS_LINK_STATE_LINKED );

		$this->signIn( 72 );

		$this->assertSame( 0, \agend_apps_saml_link_login_pending( 72 ) );
	}

	#[Test]
	public function should_ignore_a_sign_in_with_no_user_object(): void {
		$this->eligible( 73 );

		\agend_apps_saml_link_schedule_on_login( 'member-73', null );

		$this->assertSame( 0, \agend_apps_saml_link_login_pending( 73 ) );
	}

	#[Test]
	public function should_clear_the_last_skip_reason_on_sign_in(): void {
		$this->eligible( 74 );
		\agend_apps_saml_link_record_skip( 74, 'admin_page' );

		$this->signIn( 74 );

		$this->assertSame( '', $this->skipReason( 74 ) );
	}

	#[Test]
	public function should_treat_an_expired_sign_in_marker_as_absent(): void {
		update_user_meta( 75, \AGEND_APPS_SAML_LINK_LOGIN_PENDING_META, time() - \AGEND_APPS_SAML_LINK_LOGIN_PENDING_TTL - 1 );

		$this->assertSame( 0, \agend_apps_saml_link_login_pending( 75 ) );
	}

	#[Test]
	public function should_leave_the_attempt_cap_and_throttle_to_the_issuing_path(): void {
		$this->eligible( 76 );
		\agend_apps_wp_idp_merge_link_state( 76, array( 'attempts' => \AGEND_APPS_LINK_MAX_ATTEMPTS ) );

		$this->signIn( 76 );

		// A capped member is not eligible, so signing in schedules nothing and
		// no attempt beyond the cap can come from this path.
		$this->assertSame( 0, \agend_apps_saml_link_login_pending( 76 ) );
	}

	// -----------------------------------------------------------------
	// wp-admin landing
	// -----------------------------------------------------------------

	#[Test]
	public function should_render_the_placeholder_on_the_first_wp_admin_page_after_sign_in(): void {
		$this->eligible( 80 );
		$this->signIn( 80 );
		$GLOBALS['agend_test_is_admin'] = true;
		$this->request( '/wp-admin/' );
		do_action( 'admin_head' );

		$markup = $this->adminFooter();

		$this->assertStringContainsString( 'agend-apps-saml-link-frame', $markup );
		$this->assertStringContainsString( 'identity-link/sso-url', $markup );
		$this->assertSame( 0, \agend_apps_saml_link_login_pending( 80 ), 'the marker is consumed' );
		// views and renders are front-end diagnostics; wp-admin leaves them alone.
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( 80 )['views'] );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( 80 )['renders'] );
	}

	#[Test]
	public function should_not_let_wp_admin_sign_ins_trip_the_front_end_fallback_redirect(): void {
		$this->eligible( 87 );
		$GLOBALS['agend_test_is_admin'] = true;
		$this->request( '/wp-admin/' );
		do_action( 'admin_head' );

		// Three sign-ins that each land in wp-admin, where the script never
		// reaches the endpoint (attempts stays 0).
		for ( $i = 0; $i < \AGEND_APPS_SAML_LINK_FALLBACK_VIEWS; $i++ ) {
			$this->signIn( 87 );
			$this->adminFooter();
		}

		$decision = \agend_apps_saml_link_decision( 87, 'https://example.test/page/' );

		$this->assertSame( 'render', $decision['action'], 'the first front-end page renders, it does not redirect' );
	}

	#[Test]
	public function should_skip_an_iframe_admin_screen_and_keep_the_marker(): void {
		$this->eligible( 88 );
		$this->signIn( 88 );
		$GLOBALS['agend_test_is_admin'] = true;
		$this->request( '/wp-admin/plugin-install.php' );
		$_GET = array(
			'tab'    => 'plugin-information',
			'iframe' => 'true',
		);
		do_action( 'admin_head' );

		$this->assertSame( '', $this->adminFooter() );
		$this->assertGreaterThan( 0, \agend_apps_saml_link_login_pending( 88 ) );
	}

	#[Test]
	public function should_render_once_per_sign_in_in_wp_admin(): void {
		$this->eligible( 81 );
		$this->signIn( 81 );
		$GLOBALS['agend_test_is_admin'] = true;
		$this->request( '/wp-admin/' );
		do_action( 'admin_head' );

		$this->adminFooter();
		$second = $this->adminFooter();

		$this->assertSame( '', $second );
		$this->assertSame( 'admin_page', $this->skipReason( 81 ) );
	}

	#[Test]
	public function should_skip_a_wp_admin_page_with_no_pending_sign_in(): void {
		$this->eligible( 82 );
		$GLOBALS['agend_test_current_user_id'] = 82;
		$GLOBALS['agend_test_is_admin']        = true;
		$this->request( '/wp-admin/edit.php' );
		do_action( 'admin_head' );

		$this->assertSame( '', $this->adminFooter() );
		$this->assertSame( 'admin_page', $this->skipReason( 82 ) );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( 82 )['views'] );
	}

	#[Test]
	public function should_record_no_nonce_and_keep_the_marker_when_admin_head_never_ran(): void {
		$this->eligible( 83 );
		$this->signIn( 83 );
		$GLOBALS['agend_test_is_admin'] = true;
		$this->request( '/wp-admin/' );

		$this->assertSame( '', $this->adminFooter() );
		$this->assertSame( 'no_nonce', $this->skipReason( 83 ) );
		$this->assertGreaterThan( 0, \agend_apps_saml_link_login_pending( 83 ), 'kept for a later page' );
	}

	#[Test]
	public function should_skip_and_record_throttled_inside_a_backoff_window_in_wp_admin(): void {
		$this->eligible( 84 );
		$this->signIn( 84 );
		\agend_apps_wp_idp_record_link_state( 84, \AGEND_APPS_LINK_STATE_ASSERTED );
		$GLOBALS['agend_test_is_admin'] = true;
		$this->request( '/wp-admin/' );
		do_action( 'admin_head' );

		$this->assertSame( '', $this->adminFooter() );
		$this->assertSame( 'throttled', $this->skipReason( 84 ) );
		$this->assertGreaterThan( 0, \agend_apps_saml_link_login_pending( 84 ), 'kept until the window passes' );
	}

	#[Test]
	public function should_never_redirect_or_issue_a_url_from_wp_admin_when_capped(): void {
		$this->eligible( 85 );
		update_user_meta( 85, \AGEND_APPS_SAML_LINK_LOGIN_PENDING_META, time() );
		\agend_apps_wp_idp_merge_link_state( 85, array( 'attempts' => \AGEND_APPS_LINK_MAX_ATTEMPTS ) );

		$decision = \agend_apps_saml_link_admin_decision( 85 );

		$this->assertSame( array( 'action' => 'skip', 'reason' => 'attempt_cap' ), $decision );
		$this->assertFalse( \agend_apps_wp_idp_link_state( 85 )['fallback_done'] );
		$this->assertSame( \AGEND_APPS_LINK_MAX_ATTEMPTS, \agend_apps_wp_idp_link_state( 85 )['attempts'] );
	}

	#[Test]
	public function should_keep_the_ajax_cron_and_request_shape_guards_in_wp_admin(): void {
		$this->eligible( 86 );
		$this->signIn( 86 );
		$GLOBALS['agend_test_is_admin'] = true;
		do_action( 'admin_head' );

		$this->request( '/wp-admin/admin-ajax.php' );
		$GLOBALS['agend_test_doing_ajax'] = true;
		$this->assertSame( '', $this->adminFooter(), 'AJAX' );
		$GLOBALS['agend_test_doing_ajax'] = false;

		$GLOBALS['agend_test_doing_cron'] = true;
		$this->assertSame( '', $this->adminFooter(), 'cron' );
		$GLOBALS['agend_test_doing_cron'] = false;

		$this->request( '/wp-admin/admin.php' );
		$_GET = array( 'saml_action' => 'x' );
		$this->assertSame( '', $this->adminFooter(), 'a SAML dispatcher request' );
		$_GET = array();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertSame( '', $this->adminFooter(), 'a POST' );

		$this->assertGreaterThan( 0, \agend_apps_saml_link_login_pending( 86 ), 'none of them consumed the marker' );
	}

	#[Test]
	public function should_do_nothing_in_wp_admin_for_a_signed_out_request(): void {
		$GLOBALS['agend_test_is_admin'] = true;
		$this->request( '/wp-admin/' );
		do_action( 'admin_head' );

		$this->assertSame( '', $this->adminFooter() );
	}

	// -----------------------------------------------------------------
	// Front-end landing: the My Account dashboard versus its forms
	// -----------------------------------------------------------------

	#[Test]
	public function should_render_on_the_my_account_dashboard_and_consume_the_marker(): void {
		$this->eligible( 90 );
		$this->signIn( 90 );
		$GLOBALS['agend_test_is_account_page'] = true;
		$this->request( '/my-account/' );
		do_action( 'wp_head' );

		$markup = $this->frontEndPage();

		$this->assertStringContainsString( 'agend-apps-saml-link-frame', $markup );
		$this->assertSame( 0, \agend_apps_saml_link_login_pending( 90 ) );
	}

	#[Test]
	public function should_skip_edit_account_and_record_blocked_surface(): void {
		$this->eligible( 91 );
		$this->signIn( 91 );
		$GLOBALS['agend_test_is_account_page']    = true;
		$GLOBALS['agend_test_is_wc_endpoint_url'] = true;
		$this->request( '/my-account/edit-account/' );
		do_action( 'wp_head' );

		$this->assertSame( '', $this->frontEndPage() );
		$this->assertSame( 'blocked_surface', $this->skipReason( 91 ) );
		$this->assertGreaterThan( 0, \agend_apps_saml_link_login_pending( 91 ), 'kept for the next ordinary page' );
	}

	#[Test]
	public function should_not_record_a_blocked_surface_for_an_ineligible_member(): void {
		\agend_apps_wp_idp_record_link_state( 92, \AGEND_APPS_LINK_STATE_LINKED );
		$GLOBALS['agend_test_current_user_id'] = 92;
		$GLOBALS['agend_test_is_checkout']     = true;
		$this->request( '/checkout/' );

		$this->frontEndPage();

		$this->assertSame( '', $this->skipReason( 92 ) );
	}

	#[Test]
	public function should_record_no_nonce_on_the_front_end_when_wp_head_never_ran(): void {
		$this->eligible( 93 );
		$this->signIn( 93 );
		$this->request( '/page/' );

		$this->assertSame( '', $this->frontEndPage() );
		$this->assertSame( 'no_nonce', $this->skipReason( 93 ) );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( 93 )['renders'] );
	}

	#[Test]
	public function should_leave_the_front_end_trigger_to_wp_admin_on_admin_requests(): void {
		$this->eligible( 94 );
		$this->signIn( 94 );
		$GLOBALS['agend_test_is_admin'] = true;
		$this->request( '/wp-admin/' );

		\agend_apps_saml_link_maybe_trigger();

		$this->assertSame( '', \agend_apps_saml_link_pending() );
	}

	// -----------------------------------------------------------------
	// Trigger status for the diagnostics panel
	// -----------------------------------------------------------------

	#[Test]
	public function should_derive_cached_page_when_a_sign_in_was_never_followed_by_the_trigger(): void {
		$this->eligible( 100 );
		update_user_meta( 100, \AGEND_APPS_SAML_LINK_LOGIN_PENDING_META, time() - 10 * MINUTE_IN_SECONDS );

		$status = \agend_apps_saml_link_trigger_status( 100 );

		$this->assertSame( 'cached_page', $status['reason'] );
		$this->assertGreaterThan( 0, $status['login_pending_at'] );
	}

	#[Test]
	public function should_not_claim_a_cached_page_within_a_minute_of_signing_in(): void {
		$this->eligible( 101 );
		update_user_meta( 101, \AGEND_APPS_SAML_LINK_LOGIN_PENDING_META, time() );

		$this->assertSame( '', \agend_apps_saml_link_trigger_status( 101 )['reason'] );
	}

	#[Test]
	public function should_prefer_a_skip_recorded_after_the_sign_in_over_cached_page(): void {
		$this->eligible( 102 );
		update_user_meta( 102, \AGEND_APPS_SAML_LINK_LOGIN_PENDING_META, time() - 10 * MINUTE_IN_SECONDS );
		\agend_apps_saml_link_record_skip( 102, 'blocked_surface' );

		$this->assertSame( 'blocked_surface', \agend_apps_saml_link_trigger_status( 102 )['reason'] );
	}

	#[Test]
	public function should_not_claim_a_cached_page_for_an_ineligible_member(): void {
		update_user_meta( 103, \AGEND_APPS_SAML_LINK_LOGIN_PENDING_META, time() - 10 * MINUTE_IN_SECONDS );

		$this->assertSame( '', \agend_apps_saml_link_trigger_status( 103 )['reason'] );
	}

	#[Test]
	public function should_write_a_skip_reason_only_when_it_changes(): void {
		\agend_apps_saml_link_record_skip( 104, 'admin_page' );
		$first = get_user_meta( 104, \AGEND_APPS_SAML_LINK_SKIP_META, true );

		update_user_meta( 104, \AGEND_APPS_SAML_LINK_SKIP_META, array( 'reason' => 'admin_page', 'timestamp' => 1 ) );
		\agend_apps_saml_link_record_skip( 104, 'admin_page' );

		$this->assertSame( 'admin_page', $first['reason'] );
		$this->assertSame( 1, get_user_meta( 104, \AGEND_APPS_SAML_LINK_SKIP_META, true )['timestamp'], 'unchanged reason, no write' );
	}
}
