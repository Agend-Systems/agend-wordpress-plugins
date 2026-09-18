<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Settings;
use Agend_Apps_Token_Worker;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use WP_User;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sso.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-link.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-saml-link.php';

/**
 * The SAML round trip that links a WordPress member to Agend
 * (`includes/wp-idp-saml-link.php`): the two pure decisions
 * (`login_redirect` handoff, and the `template_redirect` handoff itself), SP
 * entity id resolution, and the token worker's `asserted` -> `linked`
 * promotion on a successful mint.
 *
 * `WP_SAML_IDP_Service_Provider::get_service_providers()` is required in
 * `setUp()`, not at file scope: PHPUnit includes every test FILE up front to
 * discover its class, before any test runs, so a file-scope require would
 * declare the stub classes before SsoLinkMechanismTest's own "no SAML IdP
 * plugin present" tests run and break them (that class runs first --
 * alphabetically 'S' < 'W' -- but is fully discovered, and so file-scope code
 * in every OTHER test file already executed, before its first test does).
 * `setUp()` code only runs once a test actually executes, which is safely
 * after discovery. See fixtures/saml-idp-stub.php's own docblock for why the
 * fixture itself declares `get_service_providers()`.
 */
final class WpIdpSamlLinkTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/fixtures/saml-idp-stub.php';

		update_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML );
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );

		// agend_apps_idp_entity_id() falls back to site_url(), unstubbed in
		// this harness, when this option is absent -- same seed
		// AccountLinkIdentityRecordTest/the old WpIdpLinkTest used for the
		// same reason.
		update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://example.test/saml/metadata' ) );

		// The token worker's mint is gated on the sso_account_link optional
		// feature (sso.identities.read + sso.tokens.create), mirroring
		// AccountLinkIdentityRecordTest's setUp for the same reason.
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'sso.identities.read', 'sso.tokens.create' ) ) ) );
		Agend_Apps_Key_Scopes::refresh();
		Agend_Test_WP::$requests = array();
	}

	private function registerUser( int $user_id, string $email = '' ): WP_User {
		$user             = new WP_User( $user_id );
		$user->user_email = '' !== $email ? $email : "user{$user_id}@example.test";

		$GLOBALS['agend_test_users'][] = $user;

		return $user;
	}

	/** Decodes a value this file encodes with a single rawurlencode() before add_query_arg(). */
	private function queryValue( string $url, string $key ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$parts = array();
		parse_str( $query, $parts );

		return (string) ( $parts[ $key ] ?? '' );
	}

	// -----------------------------------------------------------------
	// login_redirect decision
	// -----------------------------------------------------------------

	#[Test]
	public function should_return_a_handoff_url_when_mechanism_is_saml_and_the_member_is_not_yet_linked(): void {
		$url = \agend_apps_saml_login_redirect_decision( 'https://example.test/account/', 1 );

		$this->assertStringStartsWith( 'https://example.test/?', $url );
		$this->assertSame( '1', $this->queryValue( $url, \AGEND_APPS_SAML_LINK_QUERY_FLAG ) );
		$this->assertSame( 'https://example.test/account/', rawurldecode( $this->queryValue( $url, 'redirect_to' ) ) );
	}

	#[Test]
	public function should_leave_the_redirect_unchanged_when_the_mechanism_is_not_saml(): void {
		update_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_DISABLED );

		$url = \agend_apps_saml_login_redirect_decision( 'https://example.test/account/', 1 );

		$this->assertSame( 'https://example.test/account/', $url );
	}

	#[Test]
	public function should_leave_the_redirect_unchanged_once_the_member_is_linked(): void {
		\agend_apps_wp_idp_record_link_state( 2, \AGEND_APPS_LINK_STATE_LINKED );

		$url = \agend_apps_saml_login_redirect_decision( 'https://example.test/account/', 2 );

		$this->assertSame( 'https://example.test/account/', $url );
	}

	#[Test]
	public function should_leave_the_redirect_unchanged_while_throttled(): void {
		\agend_apps_wp_idp_record_link_state( 3, \AGEND_APPS_LINK_STATE_ASSERTED );

		$url = \agend_apps_saml_login_redirect_decision( 'https://example.test/account/', 3 );

		$this->assertSame( 'https://example.test/account/', $url );
	}

	#[Test]
	public function should_leave_the_redirect_unchanged_for_a_result_that_is_not_a_wp_user(): void {
		$this->assertSame(
			'https://example.test/account/',
			\agend_apps_saml_login_redirect( 'https://example.test/account/', '', null )
		);
	}

	// -----------------------------------------------------------------
	// template_redirect handoff decision
	// -----------------------------------------------------------------

	private function samlServiceProvider( string $entity_id ): void {
		update_option(
			'wp_saml_idp_service_providers',
			array( array( 'entityId' => $entity_id ) )
		);
	}

	#[Test]
	public function should_skip_when_the_flag_is_not_set(): void {
		$decision = \agend_apps_saml_link_handoff_decision( 10, array( 'redirect_to' => '/account/' ) );

		$this->assertSame( array( 'action' => 'skip', 'url' => '', 'state' => '' ), $decision );
	}

	#[Test]
	public function should_skip_when_signed_out(): void {
		$decision = \agend_apps_saml_link_handoff_decision( 0, array( \AGEND_APPS_SAML_LINK_QUERY_FLAG => '1' ) );

		$this->assertSame( 'skip', $decision['action'] );
	}

	#[Test]
	public function should_skip_when_the_mechanism_is_not_saml(): void {
		update_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_DISABLED );

		$decision = \agend_apps_saml_link_handoff_decision( 11, array( \AGEND_APPS_SAML_LINK_QUERY_FLAG => '1' ) );

		$this->assertSame( 'skip', $decision['action'] );
	}

	#[Test]
	public function should_skip_when_already_linked(): void {
		\agend_apps_wp_idp_record_link_state( 12, \AGEND_APPS_LINK_STATE_LINKED );

		$decision = \agend_apps_saml_link_handoff_decision( 12, array( \AGEND_APPS_SAML_LINK_QUERY_FLAG => '1' ) );

		$this->assertSame( 'skip', $decision['action'] );
	}

	#[Test]
	public function should_skip_while_throttled(): void {
		\agend_apps_wp_idp_record_link_state( 13, \AGEND_APPS_LINK_STATE_ASSERTED );

		$decision = \agend_apps_saml_link_handoff_decision( 13, array( \AGEND_APPS_SAML_LINK_QUERY_FLAG => '1' ) );

		$this->assertSame( 'skip', $decision['action'] );
	}

	#[Test]
	public function should_build_the_exact_idp_initiated_url_and_record_asserted(): void {
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );

		$decision = \agend_apps_saml_link_handoff_decision(
			20,
			array(
				\AGEND_APPS_SAML_LINK_QUERY_FLAG => '1',
				'redirect_to'                    => 'https://example.test/account/',
			)
		);

		$this->assertSame( 'redirect', $decision['action'] );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ASSERTED, $decision['state'] );

		$url = $decision['url'];
		$this->assertStringStartsWith( 'https://example.test/?', $url );
		$this->assertSame( '1', $this->queryValue( $url, 'idp_initiated' ) );

		// `sp` and `RelayState` are rawurlencode()'d once by this file before
		// add_query_arg() -- the correct behaviour against real WordPress's
		// add_query_arg(), which does not encode its values itself. The
		// PHPUnit stub in tests/wp-stubs.php diverges from real WordPress
		// here: its add_query_arg() ALSO rawurlencode()s every value, so a
		// pre-encoded value comes out double-encoded on this test double
		// specifically. parse_str() above already reverses one layer, so a
		// second manual rawurldecode() below reverses the stub's extra layer
		// and recovers the exact value real WordPress would have put on the
		// wire with a single decode.
		$this->assertSame( 'https://gw.example.test/api/auth/sso/wdaa/metadata', rawurldecode( $this->queryValue( $url, 'sp' ) ) );
		$this->assertSame( 'https://example.test/account/', rawurldecode( $this->queryValue( $url, 'RelayState' ) ) );

		$this->assertSame(
			wp_create_nonce( 'wp_saml_idp_sso_https://gw.example.test/api/auth/sso/wdaa/metadata' ),
			$this->queryValue( $url, '_wpnonce' )
		);

		$stored = \agend_apps_wp_idp_link_state( 20 );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ASSERTED, $stored['state'] );
	}

	#[Test]
	public function should_fall_back_to_home_when_the_requested_redirect_points_off_site(): void {
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );

		$decision = \agend_apps_saml_link_handoff_decision(
			21,
			array(
				\AGEND_APPS_SAML_LINK_QUERY_FLAG => '1',
				'redirect_to'                    => 'https://evil.example/phish',
			)
		);

		$this->assertSame( 'https://example.test/', rawurldecode( $this->queryValue( $decision['url'], 'RelayState' ) ) );
	}

	#[Test]
	public function should_record_error_and_redirect_to_the_safe_target_when_no_sp_is_registered(): void {
		update_option( 'wp_saml_idp_service_providers', array() );
		update_option( 'agend_apps_account_slug', '' );

		$decision = \agend_apps_saml_link_handoff_decision(
			22,
			array(
				\AGEND_APPS_SAML_LINK_QUERY_FLAG => '1',
				'redirect_to'                    => 'https://example.test/account/',
			)
		);

		$this->assertSame( 'redirect', $decision['action'] );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ERROR, $decision['state'] );
		$this->assertSame( 'https://example.test/account/', $decision['url'] );

		$stored = \agend_apps_wp_idp_link_state( 22 );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ERROR, $stored['state'] );
		$this->assertSame( 'sp_not_registered', $stored['error_code'] );
	}

	// -----------------------------------------------------------------
	// SP entity id resolution
	// -----------------------------------------------------------------

	#[Test]
	public function should_resolve_the_single_registered_candidate(): void {
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );

		$this->assertSame(
			'https://gw.example.test/api/auth/sso/wdaa/metadata',
			\agend_apps_saml_agend_sp_entity_id()
		);
	}

	#[Test]
	public function should_prefer_the_candidate_matching_the_account_slug(): void {
		update_option( 'agend_apps_account_slug', 'wdaa' );
		update_option(
			'wp_saml_idp_service_providers',
			array(
				array( 'entityId' => 'https://gw.example.test/api/auth/sso/other-org/metadata' ),
				array( 'entityId' => 'https://gw.example.test/api/auth/sso/wdaa/metadata' ),
			)
		);

		$this->assertSame(
			'https://gw.example.test/api/auth/sso/wdaa/metadata',
			\agend_apps_saml_agend_sp_entity_id()
		);
	}

	#[Test]
	public function should_prefer_the_candidate_matching_the_root_url_host_when_no_slug_matches(): void {
		update_option( 'agend_apps_account_slug', 'no-such-org' );
		update_option( 'agend_apps_root_url_for_tests', 'https://api.agend.com.au' );
		update_option(
			'wp_saml_idp_service_providers',
			array(
				array( 'entityId' => 'https://other.example.test/api/auth/sso/foo/metadata' ),
				array( 'entityId' => 'https://api.agend.com.au/api/auth/sso/bar/metadata' ),
			)
		);

		$this->assertSame(
			'https://api.agend.com.au/api/auth/sso/bar/metadata',
			\agend_apps_saml_agend_sp_entity_id()
		);
	}

	#[Test]
	public function should_construct_the_expected_shape_when_the_registry_has_no_matching_candidate(): void {
		update_option( 'wp_saml_idp_service_providers', array() );
		update_option( 'agend_apps_account_slug', 'wdaa' );
		update_option( 'agend_apps_root_url_for_tests', 'https://api.agend.com.au' );

		$this->assertSame(
			'https://api.agend.com.au/api/auth/sso/wdaa/metadata',
			\agend_apps_saml_agend_sp_entity_id()
		);
	}

	#[Test]
	public function should_return_empty_when_nothing_resolves(): void {
		update_option( 'wp_saml_idp_service_providers', array() );
		update_option( 'agend_apps_account_slug', '' );
		update_option( 'agend_apps_root_url_for_tests', '' );

		$this->assertSame( '', \agend_apps_saml_agend_sp_entity_id() );
	}

	// -----------------------------------------------------------------
	// Token worker: asserted -> linked on a successful mint
	// -----------------------------------------------------------------

	#[Test]
	public function should_promote_asserted_to_linked_on_a_successful_mint(): void {
		$GLOBALS['agend_test_current_user_id'] = 30;
		update_user_meta( 30, 'imk_membership_number', 'member-30' );
		\agend_apps_wp_idp_record_link_state( 30, \AGEND_APPS_LINK_STATE_ASSERTED );

		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'access_token' => 'token-30',
					'expires_at'   => time() + 300,
				),
			)
		);

		$worker = new Agend_Apps_Token_Worker();
		$token  = $worker->provide_token( '' );

		$this->assertSame( 'token-30', $token );
		$this->assertSame( \AGEND_APPS_LINK_STATE_LINKED, \agend_apps_wp_idp_link_state( 30 )['state'] );
	}
}
