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
 * The SAML round trip that links a WordPress member to Agend
 * (`includes/wp-idp-saml-link.php`): SP entity id resolution and readiness,
 * the shared eligibility gate, the URL-issuing/attempt-counting function, the
 * `template_redirect` trigger decision (including the visible-redirect
 * fallback), the same-origin done-URL decision, the promotion the done URL
 * runs against `GET /v1/sso/identities/status`, and the inert placeholder
 * markup.
 *
 * Also covers the token worker NOT promoting `asserted` to `linked` on a
 * successful mint any more: a mint needs `sso.tokens.create` while the link
 * only needs `sso.identities.read`, so that promotion made the recorded state
 * depend on a strictly stronger scope than the thing it described.
 *
 * `WP_SAML_IDP_Service_Provider::get_service_providers()`/`get_sp_by_entity_id()`
 * are required in `setUp()`, not at file scope: PHPUnit includes every test
 * FILE up front to discover its class, before any test runs, so a file-scope
 * require would declare the stub classes before SsoLinkMechanismTest's own
 * "no SAML IdP plugin present" tests run and break them (that class runs
 * first -- alphabetically 'S' < 'W' -- but is fully discovered, and so
 * file-scope code in every OTHER test file already executed, before its
 * first test does). `setUp()` code only runs once a test actually executes,
 * which is safely after discovery. See fixtures/saml-idp-stub.php's own
 * docblock for why the fixture itself declares `get_service_providers()`.
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

	/** Decodes a value this file encodes with a single rawurlencode() before add_query_arg(). */
	private function queryValue( string $url, string $key ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$parts = array();
		parse_str( $query, $parts );

		return (string) ( $parts[ $key ] ?? '' );
	}

	private function samlServiceProvider( string $entity_id, ?bool $enabled = null ): void {
		$sp = array( 'entityId' => $entity_id );

		if ( null !== $enabled ) {
			$sp['enabled'] = $enabled;
		}

		update_option( 'wp_saml_idp_service_providers', array( $sp ) );
	}

	/**
	 * Mints an external id directly for a user, so eligibility checks that
	 * mean to exercise a LATER gate (SP readiness, the attempt cap, the
	 * happy path) are not stopped early by `no_external_id` instead. The
	 * dedicated `no_external_id` test leaves this unmet on purpose.
	 */
	private function mintExternalId( int $user_id ): void {
		update_user_meta( $user_id, \AGEND_APPS_EXTERNAL_ID_META, 'ext-' . $user_id );
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
	public function should_return_empty_when_the_registry_has_no_matching_candidate(): void {
		// The fabricating fallback (`{root}/api/auth/sso/{slug}/metadata` when
		// nothing in the registry matches) was removed deliberately: it turned
		// a plain configuration gap into a raw `wp_die()` on the receiving IdP
		// endpoint. '' is now the correct, honest answer here even though a
		// slug and root URL are both configured.
		update_option( 'wp_saml_idp_service_providers', array() );
		update_option( 'agend_apps_account_slug', 'wdaa' );
		update_option( 'agend_apps_root_url_for_tests', 'https://api.agend.com.au' );

		$this->assertSame( '', \agend_apps_saml_agend_sp_entity_id() );
	}

	#[Test]
	public function should_return_empty_when_nothing_resolves(): void {
		update_option( 'wp_saml_idp_service_providers', array() );
		update_option( 'agend_apps_account_slug', '' );
		update_option( 'agend_apps_root_url_for_tests', '' );

		$this->assertSame( '', \agend_apps_saml_agend_sp_entity_id() );
	}

	// -----------------------------------------------------------------
	// SP readiness
	// -----------------------------------------------------------------

	#[Test]
	public function should_report_ready_for_a_registered_and_enabled_sp(): void {
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata', true );

		$this->assertSame(
			array( 'registered' => true, 'enabled' => true ),
			\agend_apps_saml_sp_ready( 'https://gw.example.test/api/auth/sso/wdaa/metadata' )
		);
	}

	#[Test]
	public function should_default_to_enabled_when_the_sp_carries_no_enabled_key(): void {
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );

		$this->assertSame(
			array( 'registered' => true, 'enabled' => true ),
			\agend_apps_saml_sp_ready( 'https://gw.example.test/api/auth/sso/wdaa/metadata' )
		);
	}

	#[Test]
	public function should_report_registered_but_disabled(): void {
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata', false );

		$this->assertSame(
			array( 'registered' => true, 'enabled' => false ),
			\agend_apps_saml_sp_ready( 'https://gw.example.test/api/auth/sso/wdaa/metadata' )
		);
	}

	#[Test]
	public function should_report_not_registered_for_an_unknown_entity_id(): void {
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );

		$this->assertSame(
			array( 'registered' => false, 'enabled' => false ),
			\agend_apps_saml_sp_ready( 'https://gw.example.test/api/auth/sso/someone-else/metadata' )
		);
	}

	#[Test]
	public function should_report_not_registered_for_an_empty_entity_id(): void {
		$this->assertSame(
			array( 'registered' => false, 'enabled' => false ),
			\agend_apps_saml_sp_ready( '' )
		);
	}

	// -----------------------------------------------------------------
	// agend_apps_saml_link_request_eligible()
	// -----------------------------------------------------------------

	#[Test]
	public function should_be_eligible_for_an_ordinary_front_end_get_request(): void {
		$this->assertTrue( \agend_apps_saml_link_request_eligible( 'GET', '/some/page/', array() ) );
	}

	#[Test]
	public function should_not_be_eligible_for_a_post_request(): void {
		$this->assertFalse( \agend_apps_saml_link_request_eligible( 'POST', '/some/page/', array() ) );
	}

	#[Test]
	public function should_not_be_eligible_for_wp_login_php(): void {
		$this->assertFalse( \agend_apps_saml_link_request_eligible( 'GET', '/wp-login.php', array() ) );
	}

	#[Test]
	public function should_not_be_eligible_for_a_saml_path_prefix(): void {
		$this->assertFalse( \agend_apps_saml_link_request_eligible( 'GET', '/saml/acs', array() ) );
	}

	/** @return array<string, array{0: string}> query key => [marker key] */
	public static function samlQueryMarkerProvider(): array {
		return array(
			'saml'          => array( 'saml' ),
			'idp_initiated' => array( 'idp_initiated' ),
			'SAMLRequest'   => array( 'SAMLRequest' ),
			'saml_action'   => array( 'saml_action' ),
			'option'        => array( 'option' ),
			'done flag'     => array( \AGEND_APPS_SAML_LINK_DONE_FLAG ),
		);
	}

	#[Test]
	public function should_not_be_eligible_when_a_marker_query_key_is_present(): void {
		foreach ( self::samlQueryMarkerProvider() as $case ) {
			[ $marker ] = $case;

			$this->assertFalse(
				\agend_apps_saml_link_request_eligible( 'GET', '/some/page/', array( $marker => '1' ) ),
				"marker: {$marker}"
			);
		}
	}

	// -----------------------------------------------------------------
	// agend_apps_saml_link_surface_blocked(): the surfaces that must never
	// carry the placeholder even on a plain front-end GET. The WooCommerce
	// arms need fixtures/woocommerce-page-stub.php to exist at all -- see
	// that file for why the functions cannot be declared in a test method.
	// -----------------------------------------------------------------

	#[Test]
	public function should_not_block_an_ordinary_front_end_surface(): void {
		require_once __DIR__ . '/fixtures/woocommerce-page-stub.php';

		$this->assertFalse( \agend_apps_saml_link_surface_blocked() );
	}

	#[Test]
	public function should_block_a_feed_request(): void {
		$GLOBALS['agend_test_is_feed'] = true;

		$this->assertTrue( \agend_apps_saml_link_surface_blocked() );
	}

	#[Test]
	public function should_block_the_woocommerce_cart_checkout_and_account_pages(): void {
		require_once __DIR__ . '/fixtures/woocommerce-page-stub.php';

		foreach ( array( 'agend_test_is_cart', 'agend_test_is_checkout', 'agend_test_is_account_page' ) as $flag ) {
			$GLOBALS['agend_test_is_cart']         = false;
			$GLOBALS['agend_test_is_checkout']     = false;
			$GLOBALS['agend_test_is_account_page'] = false;
			$GLOBALS[ $flag ]                      = true;

			$this->assertTrue( \agend_apps_saml_link_surface_blocked(), "flag: {$flag}" );
		}
	}

	// -----------------------------------------------------------------
	// agend_apps_saml_link_eligibility(): each reason, and that it writes
	// nothing regardless of outcome.
	// -----------------------------------------------------------------

	#[Test]
	public function should_report_signed_out_for_user_id_zero(): void {
		$this->assertSame(
			array( 'eligible' => false, 'reason' => 'signed_out', 'entity_id' => '' ),
			\agend_apps_saml_link_eligibility( 0 )
		);
	}

	#[Test]
	public function should_report_site_moved_before_mode_or_mechanism(): void {
		// mode_not_wordpress would otherwise fire first for this user; site_moved
		// must win regardless, since it is checked before mode/mechanism.
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_SSO );
		update_option(
			'agend_apps_sso_connection',
			array( 'site_url' => 'https://a-different-site.test' )
		);

		$this->assertSame( 'site_moved', \agend_apps_saml_link_eligibility( 60 )['reason'] );
	}

	#[Test]
	public function should_not_report_site_moved_when_the_stamp_is_absent(): void {
		delete_option( 'agend_apps_sso_connection' );

		$this->assertNotSame( 'site_moved', \agend_apps_saml_link_eligibility( 61 )['reason'] );
	}

	#[Test]
	public function should_write_no_state_for_site_moved(): void {
		update_option(
			'agend_apps_sso_connection',
			array( 'site_url' => 'https://a-different-site.test' )
		);

		\agend_apps_saml_link_eligibility( 62 );

		$this->assertSame( '', \agend_apps_wp_idp_link_state( 62 )['state'] );
	}

	#[Test]
	public function should_report_mode_not_wordpress(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_SSO );

		$this->assertSame( 'mode_not_wordpress', \agend_apps_saml_link_eligibility( 40 )['reason'] );
	}

	#[Test]
	public function should_report_mechanism_not_saml(): void {
		update_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_DISABLED );

		$this->assertSame( 'mechanism_not_saml', \agend_apps_saml_link_eligibility( 41 )['reason'] );
	}

	#[Test]
	public function should_report_linked(): void {
		\agend_apps_wp_idp_record_link_state( 42, \AGEND_APPS_LINK_STATE_LINKED );

		$this->assertSame( 'linked', \agend_apps_saml_link_eligibility( 42 )['reason'] );
	}

	#[Test]
	public function should_report_no_external_id(): void {
		// A fresh user id with no minted external id and no imk_membership_number.
		$this->assertSame( '', \agend_apps_user_external_id( 43 ) );

		$this->assertSame( 'no_external_id', \agend_apps_saml_link_eligibility( 43 )['reason'] );
	}

	#[Test]
	public function should_report_sp_not_registered_when_nothing_resolves(): void {
		$this->mintExternalId( 44 );
		update_option( 'wp_saml_idp_service_providers', array() );

		$this->assertSame( 'sp_not_registered', \agend_apps_saml_link_eligibility( 44 )['reason'] );
	}

	#[Test]
	public function should_report_sp_not_registered_when_the_resolved_entity_is_not_in_the_registry(): void {
		$this->mintExternalId( 45 );
		// Force a resolved-but-unregistered mismatch via the filter: the
		// registry scan itself only ever returns candidates it found, so this
		// exercises the get_sp_by_entity_id() miss branch directly.
		add_filter(
			'agend_apps_saml_agend_sp_entity_id',
			static function () {
				return 'https://gw.example.test/api/auth/sso/ghost/metadata';
			}
		);

		$this->assertSame( 'sp_not_registered', \agend_apps_saml_link_eligibility( 45 )['reason'] );
	}

	#[Test]
	public function should_report_sp_disabled(): void {
		$this->mintExternalId( 46 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata', false );

		$this->assertSame( 'sp_disabled', \agend_apps_saml_link_eligibility( 46 )['reason'] );
	}

	#[Test]
	public function should_report_connection_pending_approval_before_the_attempt_cap_check(): void {
		$this->mintExternalId( 47 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );
		\agend_apps_connect_store(
			array( 'approval_state' => 'pending' ),
			array()
		);

		$this->assertSame(
			array( 'eligible' => false, 'reason' => 'connection_pending_approval', 'entity_id' => '' ),
			\agend_apps_saml_link_eligibility( 47 )
		);
	}

	#[Test]
	public function should_report_attempt_cap(): void {
		$this->mintExternalId( 47 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );
		\agend_apps_wp_idp_merge_link_state( 47, array( 'attempts' => \AGEND_APPS_LINK_MAX_ATTEMPTS ) );

		$this->assertSame( 'attempt_cap', \agend_apps_saml_link_eligibility( 47 )['reason'] );
	}

	#[Test]
	public function should_report_eligible_with_the_resolved_entity_id(): void {
		$this->mintExternalId( 48 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );

		$this->assertSame(
			array( 'eligible' => true, 'reason' => '', 'entity_id' => 'https://gw.example.test/api/auth/sso/wdaa/metadata' ),
			\agend_apps_saml_link_eligibility( 48 )
		);
	}

	#[Test]
	public function should_write_no_state_regardless_of_outcome(): void {
		$this->mintExternalId( 49 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );

		\agend_apps_saml_link_eligibility( 49 );
		$this->assertSame( '', \agend_apps_wp_idp_link_state( 49 )['state'] );

		update_option( 'wp_saml_idp_service_providers', array() );
		\agend_apps_saml_link_eligibility( 49 );
		$this->assertSame( '', \agend_apps_wp_idp_link_state( 49 )['state'] );
	}

	// -----------------------------------------------------------------
	// agend_apps_saml_link_issue_url()
	// -----------------------------------------------------------------

	#[Test]
	public function should_build_the_exact_idp_initiated_url_and_record_asserted(): void {
		$this->mintExternalId( 50 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );

		$issued = \agend_apps_saml_link_issue_url( 50, 'https://example.test/account/', false );

		$this->assertSame( '', $issued['reason'] );
		$url = $issued['url'];

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

		$relay_state = rawurldecode( $this->queryValue( $url, 'RelayState' ) );
		$this->assertStringStartsWith( 'https://example.test/?', $relay_state );
		$this->assertSame( '1', $this->queryValue( $relay_state, \AGEND_APPS_SAML_LINK_DONE_FLAG ) );
		$this->assertSame(
			'https://example.test/account/',
			rawurldecode( $this->queryValue( $relay_state, 'redirect_to' ) )
		);

		// The purpose rides on the RelayState, which is where the gateway's
		// ACS reads it from. This is the non-framed fallback and it carries it
		// too: it is the same provisioning round trip, just performed at the
		// top level, and it consumes an ACS session no more than the iframe
		// does.
		$this->assertSame(
			\AGEND_APPS_SAML_LINK_PURPOSE_PROVISION,
			$this->queryValue( $relay_state, \AGEND_APPS_SAML_LINK_PURPOSE_PARAM )
		);

		$this->assertSame(
			wp_create_nonce( 'wp_saml_idp_sso_https://gw.example.test/api/auth/sso/wdaa/metadata' ),
			$this->queryValue( $url, '_wpnonce' )
		);

		$stored = \agend_apps_wp_idp_link_state( 50 );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ASSERTED, $stored['state'] );
		$this->assertSame( 1, $stored['attempts'] );
	}

	#[Test]
	public function should_take_attempts_from_zero_to_one_then_to_two(): void {
		$this->mintExternalId( 51 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );

		$this->assertSame( 0, \agend_apps_wp_idp_link_state( 51 )['attempts'] );

		\agend_apps_saml_link_issue_url( 51, '', true );
		$this->assertSame( 1, \agend_apps_wp_idp_link_state( 51 )['attempts'] );

		// Move past the `asserted` backoff so the second issue call is not
		// blocked by anything other than what this test is exercising.
		\agend_apps_wp_idp_merge_link_state( 51, array( 'timestamp' => time() - ( 24 * HOUR_IN_SECONDS ) ) );

		\agend_apps_saml_link_issue_url( 51, '', true );
		$this->assertSame( 2, \agend_apps_wp_idp_link_state( 51 )['attempts'] );
	}

	#[Test]
	public function should_not_issue_a_url_while_throttled(): void {
		$this->mintExternalId( 54 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );

		// One attempt lands and records `asserted`, which starts that state's
		// backoff window.
		$first = \agend_apps_saml_link_issue_url( 54, '', true );
		$this->assertNotSame( '', $first['url'] );

		// A second call inside the window is refused. This is the control that
		// stops anything holding the exposed `wp_rest` nonce from calling the
		// endpoint repeatedly and burning the whole lifetime cap at once.
		$second = \agend_apps_saml_link_issue_url( 54, '', true );

		$this->assertSame( '', $second['url'] );
		$this->assertSame( 'throttled', $second['reason'] );
		$this->assertSame( 1, \agend_apps_wp_idp_link_state( 54 )['attempts'] );
	}

	#[Test]
	public function should_record_error_and_return_empty_url_for_sp_not_registered(): void {
		$this->mintExternalId( 52 );
		update_option( 'wp_saml_idp_service_providers', array() );

		$issued = \agend_apps_saml_link_issue_url( 52, '', true );

		$this->assertSame( '', $issued['url'] );
		$this->assertSame( 'sp_not_registered', $issued['reason'] );

		$stored = \agend_apps_wp_idp_link_state( 52 );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ERROR, $stored['state'] );
		$this->assertSame( 'sp_not_registered', $stored['error_code'] );
		$this->assertSame( 0, $stored['attempts'] );
	}

	#[Test]
	public function should_return_empty_url_and_write_no_attempt_for_attempt_cap(): void {
		$this->mintExternalId( 53 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );
		\agend_apps_wp_idp_merge_link_state( 53, array( 'attempts' => \AGEND_APPS_LINK_MAX_ATTEMPTS ) );

		$issued = \agend_apps_saml_link_issue_url( 53, '', true );

		$this->assertSame( '', $issued['url'] );
		$this->assertSame( 'attempt_cap', $issued['reason'] );
		$this->assertSame( \AGEND_APPS_LINK_MAX_ATTEMPTS, \agend_apps_wp_idp_link_state( 53 )['attempts'] );
		// attempt_cap is not one of the two registry-error reasons, so no
		// state is recorded for it here either.
		$this->assertSame( '', \agend_apps_wp_idp_link_state( 53 )['state'] );
	}

	// -----------------------------------------------------------------
	// agend_apps_saml_link_decision()
	// -----------------------------------------------------------------

	#[Test]
	public function should_render_on_the_happy_path_and_bump_views(): void {
		$this->mintExternalId( 60 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );

		$decision = \agend_apps_saml_link_decision( 60, 'https://example.test/page/' );

		$this->assertSame( 'render', $decision['action'] );
		$this->assertSame( 1, \agend_apps_wp_idp_link_state( 60 )['views'] );
	}

	#[Test]
	public function should_skip_when_already_linked(): void {
		\agend_apps_wp_idp_record_link_state( 61, \AGEND_APPS_LINK_STATE_LINKED );

		$decision = \agend_apps_saml_link_decision( 61, 'https://example.test/page/' );

		$this->assertSame( array( 'action' => 'skip', 'url' => '', 'reason' => 'linked' ), $decision );
	}

	#[Test]
	public function should_skip_when_no_external_id(): void {
		$this->assertSame( '', \agend_apps_user_external_id( 62 ) );

		$decision = \agend_apps_saml_link_decision( 62, 'https://example.test/page/' );

		$this->assertSame( 'skip', $decision['action'] );
		$this->assertSame( 'no_external_id', $decision['reason'] );
	}

	#[Test]
	public function should_skip_when_the_mechanism_is_not_saml(): void {
		update_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_DISABLED );

		$decision = \agend_apps_saml_link_decision( 63, 'https://example.test/page/' );

		$this->assertSame( 'skip', $decision['action'] );
		$this->assertSame( 'mechanism_not_saml', $decision['reason'] );
	}

	#[Test]
	public function should_skip_while_throttled(): void {
		$this->mintExternalId( 64 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );
		\agend_apps_wp_idp_record_link_state( 64, \AGEND_APPS_LINK_STATE_ASSERTED );

		$decision = \agend_apps_saml_link_decision( 64, 'https://example.test/page/' );

		$this->assertSame( array( 'action' => 'skip', 'url' => '', 'reason' => 'throttled' ), $decision );
	}

	#[Test]
	public function should_skip_and_record_error_for_sp_not_registered(): void {
		$this->mintExternalId( 65 );
		update_option( 'wp_saml_idp_service_providers', array() );

		$decision = \agend_apps_saml_link_decision( 65, 'https://example.test/page/' );

		$this->assertSame( 'skip', $decision['action'] );
		$this->assertSame( 'sp_not_registered', $decision['reason'] );

		$stored = \agend_apps_wp_idp_link_state( 65 );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ERROR, $stored['state'] );
		$this->assertSame( 'sp_not_registered', $stored['error_code'] );
	}

	#[Test]
	public function should_fall_back_to_a_redirect_when_views_reach_the_threshold_with_zero_attempts(): void {
		$this->mintExternalId( 66 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );
		\agend_apps_wp_idp_merge_link_state( 66, array( 'views' => \AGEND_APPS_SAML_LINK_FALLBACK_VIEWS ) );

		$decision = \agend_apps_saml_link_decision( 66, 'https://example.test/page/' );

		$this->assertSame( 'redirect', $decision['action'] );
		$this->assertNotSame( '', $decision['url'] );
		$this->assertTrue( \agend_apps_wp_idp_link_state( 66 )['fallback_done'] );
	}

	#[Test]
	public function should_fall_back_to_a_redirect_once_the_attempt_cap_throttle_window_has_passed(): void {
		$this->mintExternalId( 67 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );
		\agend_apps_wp_idp_record_link_state( 67, \AGEND_APPS_LINK_STATE_ASSERTED );
		\agend_apps_wp_idp_merge_link_state(
			67,
			array(
				'attempts'  => \AGEND_APPS_LINK_MAX_ATTEMPTS,
				'timestamp' => time() - ( 24 * HOUR_IN_SECONDS ),
			)
		);

		$decision = \agend_apps_saml_link_decision( 67, 'https://example.test/page/' );

		$this->assertSame( 'redirect', $decision['action'] );
		$this->assertNotSame( '', $decision['url'] );
	}

	#[Test]
	public function should_skip_attempt_cap_when_fallback_already_spent(): void {
		$this->mintExternalId( 68 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );
		\agend_apps_wp_idp_record_link_state( 68, \AGEND_APPS_LINK_STATE_ASSERTED );
		\agend_apps_wp_idp_merge_link_state(
			68,
			array(
				'attempts'      => \AGEND_APPS_LINK_MAX_ATTEMPTS,
				'fallback_done' => true,
				'timestamp'     => time() - ( 24 * HOUR_IN_SECONDS ),
			)
		);

		$decision = \agend_apps_saml_link_decision( 68, 'https://example.test/page/' );

		$this->assertSame( array( 'action' => 'skip', 'url' => '', 'reason' => 'attempt_cap' ), $decision );
	}

	#[Test]
	public function should_suppress_the_fallback_once_completed_is_true(): void {
		$this->mintExternalId( 69 );
		$this->samlServiceProvider( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );
		\agend_apps_wp_idp_merge_link_state(
			69,
			array(
				'views'     => \AGEND_APPS_SAML_LINK_FALLBACK_VIEWS,
				'completed' => true,
			)
		);

		$decision = \agend_apps_saml_link_decision( 69, 'https://example.test/page/' );

		$this->assertSame( 'render', $decision['action'] );
	}

	// -----------------------------------------------------------------
	// agend_apps_saml_link_promote()
	// -----------------------------------------------------------------

	#[Test]
	public function should_record_nothing_and_report_signed_out_for_user_id_zero(): void {
		$promoted = \agend_apps_saml_link_promote( 0 );

		$this->assertSame( array( 'state' => '', 'code' => 'signed_out' ), $promoted );
	}

	#[Test]
	public function should_record_linked_and_the_identity_ids_on_a_linked_result(): void {
		$this->mintExternalId( 100 );

		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'linked'     => true,
					'user_id'    => 'supabase-100',
					'contact_id' => 'contact-100',
				),
			)
		);

		$promoted = \agend_apps_saml_link_promote( 100 );

		$this->assertSame( array( 'state' => \AGEND_APPS_LINK_STATE_LINKED, 'code' => 'linked' ), $promoted );
		$this->assertSame( \AGEND_APPS_LINK_STATE_LINKED, \agend_apps_wp_idp_link_state( 100 )['state'] );
		$this->assertSame(
			array( 'supabase_user_id' => 'supabase-100', 'contact_id' => 'contact-100' ),
			\agend_apps_linked_identity_ids( 100 )
		);
	}

	#[Test]
	public function should_record_pending_approval_on_an_unknown_issuer_error(): void {
		$this->mintExternalId( 101 );

		Agend_Test_WP::queue_response( 403, array( 'error' => array( 'code' => 'UNKNOWN_ISSUER' ) ) );

		$promoted = \agend_apps_saml_link_promote( 101 );

		$this->assertSame( array( 'state' => \AGEND_APPS_LINK_STATE_PENDING_APPROVAL, 'code' => 'unknown_issuer' ), $promoted );

		$stored = \agend_apps_wp_idp_link_state( 101 );
		$this->assertSame( \AGEND_APPS_LINK_STATE_PENDING_APPROVAL, $stored['state'] );
		$this->assertSame( 'unknown_issuer', $stored['error_code'] );
	}

	#[Test]
	public function should_record_error_with_the_lower_cased_gateway_code_for_another_gateway_error(): void {
		$this->mintExternalId( 102 );

		Agend_Test_WP::queue_response( 409, array( 'error' => array( 'code' => 'IDENTITY_ALREADY_LINKED' ) ) );

		$promoted = \agend_apps_saml_link_promote( 102 );

		$this->assertSame( array( 'state' => \AGEND_APPS_LINK_STATE_ERROR, 'code' => 'identity_already_linked' ), $promoted );

		$stored = \agend_apps_wp_idp_link_state( 102 );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ERROR, $stored['state'] );
		$this->assertSame( 'identity_already_linked', $stored['error_code'] );
	}

	#[Test]
	public function should_record_status_failed_for_a_transport_style_error_with_no_gateway_code(): void {
		$this->mintExternalId( 103 );

		Agend_Test_WP::queue_response( 500, '' );

		$promoted = \agend_apps_saml_link_promote( 103 );

		$this->assertSame( array( 'state' => \AGEND_APPS_LINK_STATE_ERROR, 'code' => 'status_failed' ), $promoted );

		$stored = \agend_apps_wp_idp_link_state( 103 );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ERROR, $stored['state'] );
		$this->assertSame( 'status_failed', $stored['error_code'] );
	}

	#[Test]
	public function should_record_nothing_and_report_not_linked_when_the_gateway_reports_unlinked(): void {
		$this->mintExternalId( 104 );

		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'linked' => false ) ) );

		$promoted = \agend_apps_saml_link_promote( 104 );

		$this->assertSame( array( 'state' => '', 'code' => 'not_linked' ), $promoted );
		$this->assertSame( '', \agend_apps_wp_idp_link_state( 104 )['state'] );
	}

	#[Test]
	public function should_record_nothing_and_report_no_external_id_when_none_resolves(): void {
		$this->assertSame( '', \agend_apps_user_external_id( 105 ) );

		$promoted = \agend_apps_saml_link_promote( 105 );

		$this->assertSame( array( 'state' => '', 'code' => 'no_external_id' ), $promoted );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// agend_apps_saml_link_done_url(): the RelayState the gateway's ACS reads
	// its purpose off.
	// -----------------------------------------------------------------

	#[Test]
	public function should_request_the_provision_purpose_on_the_framed_done_url(): void {
		$url = \agend_apps_saml_link_done_url( '', true );

		$this->assertSame( '1', $this->queryValue( $url, 'frame' ) );
		$this->assertSame(
			\AGEND_APPS_SAML_LINK_PURPOSE_PROVISION,
			$this->queryValue( $url, \AGEND_APPS_SAML_LINK_PURPOSE_PARAM )
		);
	}

	#[Test]
	public function should_request_the_provision_purpose_on_the_non_framed_done_url(): void {
		$url = \agend_apps_saml_link_done_url( 'https://example.test/account/', false );

		$this->assertSame(
			\AGEND_APPS_SAML_LINK_PURPOSE_PROVISION,
			$this->queryValue( $url, \AGEND_APPS_SAML_LINK_PURPOSE_PARAM )
		);
	}

	#[Test]
	public function should_keep_the_done_url_same_origin(): void {
		foreach ( array( true, false ) as $frame ) {
			$url = \agend_apps_saml_link_done_url( 'https://example.test/account/', $frame );

			$this->assertStringStartsWith( home_url( '/' ), $url, 'frame: ' . var_export( $frame, true ) );
		}
	}

	// -----------------------------------------------------------------
	// agend_apps_saml_link_done_decision()
	// -----------------------------------------------------------------

	#[Test]
	public function should_still_decide_when_the_gateway_has_stripped_the_purpose_param(): void {
		// The gateway strips agend_purpose before redirecting the browser back,
		// so the done handler never sees it. It must not have grown any
		// dependency on the parameter it sent out.
		$decision = \agend_apps_saml_link_done_decision(
			78,
			array(
				\AGEND_APPS_SAML_LINK_DONE_FLAG => '1',
				'_wpnonce'                      => wp_create_nonce( \AGEND_APPS_SAML_LINK_DONE_NONCE ),
				'frame'                         => '1',
			)
		);

		$this->assertSame( 'frame', $decision['action'] );
	}

	#[Test]
	public function should_skip_the_done_decision_on_a_missing_flag(): void {
		$decision = \agend_apps_saml_link_done_decision( 70, array() );

		$this->assertSame( array( 'action' => 'skip', 'url' => '', 'state' => '', 'code' => '' ), $decision );
	}

	#[Test]
	public function should_skip_and_record_nothing_on_a_bad_nonce(): void {
		$decision = \agend_apps_saml_link_done_decision(
			71,
			array(
				\AGEND_APPS_SAML_LINK_DONE_FLAG => '1',
				'_wpnonce'                       => 'not-a-real-nonce',
			)
		);

		$this->assertSame( 'skip', $decision['action'] );
		$this->assertFalse( \agend_apps_wp_idp_link_state( 71 )['completed'] );
	}

	#[Test]
	public function should_return_frame_and_record_completed_for_frame_equal_one(): void {
		$this->mintExternalId( 72 );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'linked' => true, 'user_id' => 'supabase-72' ) ) );

		$decision = \agend_apps_saml_link_done_decision(
			72,
			array(
				\AGEND_APPS_SAML_LINK_DONE_FLAG => '1',
				'_wpnonce'                       => wp_create_nonce( \AGEND_APPS_SAML_LINK_DONE_NONCE ),
				'frame'                          => '1',
			)
		);

		$this->assertSame( 'frame', $decision['action'] );
		$this->assertTrue( \agend_apps_wp_idp_link_state( 72 )['completed'] );
		// The frame case also carries the promotion outcome computed on this
		// same return leg.
		$this->assertSame( \AGEND_APPS_LINK_STATE_LINKED, $decision['state'] );
		$this->assertSame( 'linked', $decision['code'] );
		$this->assertSame( \AGEND_APPS_LINK_STATE_LINKED, \agend_apps_wp_idp_link_state( 72 )['state'] );
	}

	#[Test]
	public function should_redirect_to_the_validated_same_site_redirect_to(): void {
		$this->mintExternalId( 73 );
		Agend_Test_WP::queue_response( 403, array( 'error' => array( 'code' => 'UNKNOWN_ISSUER' ) ) );

		$decision = \agend_apps_saml_link_done_decision(
			73,
			array(
				\AGEND_APPS_SAML_LINK_DONE_FLAG => '1',
				'_wpnonce'                       => wp_create_nonce( \AGEND_APPS_SAML_LINK_DONE_NONCE ),
				'redirect_to'                    => 'https://example.test/account/',
			)
		);

		$this->assertSame( 'redirect', $decision['action'] );
		$this->assertSame( 'https://example.test/account/', $decision['url'] );
		$this->assertTrue( \agend_apps_wp_idp_link_state( 73 )['completed'] );
		// The redirect case also carries the promotion outcome computed on this
		// same return leg.
		$this->assertSame( \AGEND_APPS_LINK_STATE_PENDING_APPROVAL, $decision['state'] );
		$this->assertSame( 'unknown_issuer', $decision['code'] );
	}

	#[Test]
	public function should_fall_back_to_home_for_an_off_site_redirect_to(): void {
		$decision = \agend_apps_saml_link_done_decision(
			74,
			array(
				\AGEND_APPS_SAML_LINK_DONE_FLAG => '1',
				'_wpnonce'                       => wp_create_nonce( \AGEND_APPS_SAML_LINK_DONE_NONCE ),
				'redirect_to'                    => 'https://evil.example/phish',
			)
		);

		$this->assertSame( 'redirect', $decision['action'] );
		$this->assertSame( 'https://example.test/', $decision['url'] );
	}

	// -----------------------------------------------------------------
	// Placeholder / done markup
	// -----------------------------------------------------------------

	#[Test]
	public function should_build_placeholder_markup_containing_the_endpoint_and_display_none(): void {
		$markup = \agend_apps_saml_link_placeholder_markup( 'https://example.test/wp-json/agend-apps/v1/identity-link/sso-url' );

		$this->assertStringContainsString( 'https://example.test/wp-json/agend-apps/v1/identity-link/sso-url', $markup );
		$this->assertStringContainsString( 'display:none', $markup );
	}

	#[Test]
	public function should_return_empty_placeholder_markup_for_an_empty_endpoint(): void {
		$this->assertSame( '', \agend_apps_saml_link_placeholder_markup( '' ) );
	}

	#[Test]
	public function should_carry_no_wp_rest_nonce_in_the_placeholder_markup(): void {
		$markup = \agend_apps_saml_link_placeholder_markup( 'https://example.test/wp-json/agend-apps/v1/identity-link/sso-url' );

		// The cache-safety contract: the markup must never carry a nonce, so
		// serving it from a page cache to any visitor is always safe.
		$this->assertStringNotContainsString( wp_create_nonce( 'wp_rest' ), $markup );
	}

	#[Test]
	public function should_build_done_markup_containing_noindex(): void {
		$this->assertStringContainsString( 'noindex', \agend_apps_saml_link_done_markup() );
	}

	// -----------------------------------------------------------------
	// agend_apps_record_membership_role(): noticing a membership-role change
	// reported by the gateway's identity-status route and dropping the stale
	// cached token. The gateway does not send this field at all today, so the
	// "absent" case below is the current, normal, silent-no-op case against
	// every gateway shipping right now.
	// -----------------------------------------------------------------

	#[Test]
	public function should_do_nothing_when_the_role_field_is_absent_the_current_gateway_case(): void {
		update_user_meta( 200, \AGEND_APPS_MEMBERSHIP_ROLE_META, 'contact' );
		update_user_meta( 200, Agend_Apps_Token_Worker::META_KEY, array( 'access_token' => 't' ) );

		$result = \agend_apps_record_membership_role( 200, array( 'linked' => true ) );

		$this->assertFalse( $result );
		$this->assertSame( 'contact', get_user_meta( 200, \AGEND_APPS_MEMBERSHIP_ROLE_META, true ) );
		$this->assertNotSame( '', get_user_meta( 200, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	#[Test]
	public function should_do_nothing_for_an_empty_role_value(): void {
		update_user_meta( 201, \AGEND_APPS_MEMBERSHIP_ROLE_META, 'contact' );
		update_user_meta( 201, Agend_Apps_Token_Worker::META_KEY, array( 'access_token' => 't' ) );

		$result = \agend_apps_record_membership_role( 201, array( \AGEND_APPS_SSO_STATUS_ROLE_FIELD => '' ) );

		$this->assertFalse( $result );
		$this->assertSame( 'contact', get_user_meta( 201, \AGEND_APPS_MEMBERSHIP_ROLE_META, true ) );
		$this->assertNotSame( '', get_user_meta( 201, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	#[Test]
	public function should_record_but_not_clear_the_token_on_first_observation(): void {
		update_user_meta( 202, Agend_Apps_Token_Worker::META_KEY, array( 'access_token' => 't' ) );

		$result = \agend_apps_record_membership_role( 202, array( \AGEND_APPS_SSO_STATUS_ROLE_FIELD => 'contact' ) );

		$this->assertFalse( $result );
		$this->assertSame( 'contact', get_user_meta( 202, \AGEND_APPS_MEMBERSHIP_ROLE_META, true ) );
		$this->assertNotSame( '', get_user_meta( 202, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	#[Test]
	public function should_record_the_new_role_and_clear_the_cached_token_on_a_changed_value(): void {
		update_user_meta( 203, \AGEND_APPS_MEMBERSHIP_ROLE_META, 'contact' );
		update_user_meta( 203, Agend_Apps_Token_Worker::META_KEY, array( 'access_token' => 't' ) );

		$result = \agend_apps_record_membership_role( 203, array( \AGEND_APPS_SSO_STATUS_ROLE_FIELD => 'owner' ) );

		$this->assertTrue( $result );
		$this->assertSame( 'owner', get_user_meta( 203, \AGEND_APPS_MEMBERSHIP_ROLE_META, true ) );
		$this->assertSame( '', get_user_meta( 203, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	#[Test]
	public function should_return_false_and_leave_the_token_untouched_when_the_role_is_unchanged(): void {
		update_user_meta( 204, \AGEND_APPS_MEMBERSHIP_ROLE_META, 'owner' );
		update_user_meta( 204, Agend_Apps_Token_Worker::META_KEY, array( 'access_token' => 't' ) );

		$result = \agend_apps_record_membership_role( 204, array( \AGEND_APPS_SSO_STATUS_ROLE_FIELD => 'owner' ) );

		$this->assertFalse( $result );
		$this->assertSame( 'owner', get_user_meta( 204, \AGEND_APPS_MEMBERSHIP_ROLE_META, true ) );
		$this->assertNotSame( '', get_user_meta( 204, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	#[Test]
	public function should_also_clear_on_a_downgrade_since_direction_is_not_inspected(): void {
		update_user_meta( 205, \AGEND_APPS_MEMBERSHIP_ROLE_META, 'owner' );
		update_user_meta( 205, Agend_Apps_Token_Worker::META_KEY, array( 'access_token' => 't' ) );

		$result = \agend_apps_record_membership_role( 205, array( \AGEND_APPS_SSO_STATUS_ROLE_FIELD => 'contact' ) );

		$this->assertTrue( $result );
		$this->assertSame( 'contact', get_user_meta( 205, \AGEND_APPS_MEMBERSHIP_ROLE_META, true ) );
		$this->assertSame( '', get_user_meta( 205, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	// -----------------------------------------------------------------
	// agend_apps_saml_link_promote(): the round trip's own status check also
	// clears a stale cached token on a reported role change, and leaves it
	// alone when the response carries no role field (the current gateway).
	// -----------------------------------------------------------------

	#[Test]
	public function should_clear_the_cached_token_through_promote_on_a_changed_role(): void {
		$this->mintExternalId( 210 );
		update_user_meta( 210, \AGEND_APPS_MEMBERSHIP_ROLE_META, 'contact' );
		update_user_meta( 210, Agend_Apps_Token_Worker::META_KEY, array( 'access_token' => 't' ) );

		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'linked'          => true,
					\AGEND_APPS_SSO_STATUS_ROLE_FIELD => 'owner',
				),
			)
		);

		\agend_apps_saml_link_promote( 210 );

		$this->assertSame( 'owner', get_user_meta( 210, \AGEND_APPS_MEMBERSHIP_ROLE_META, true ) );
		$this->assertSame( '', get_user_meta( 210, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	#[Test]
	public function should_leave_the_cached_token_alone_through_promote_when_no_role_field_is_sent(): void {
		$this->mintExternalId( 211 );
		update_user_meta( 211, \AGEND_APPS_MEMBERSHIP_ROLE_META, 'contact' );
		update_user_meta( 211, Agend_Apps_Token_Worker::META_KEY, array( 'access_token' => 't' ) );

		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'linked' => true ) ) );

		\agend_apps_saml_link_promote( 211 );

		$this->assertSame( 'contact', get_user_meta( 211, \AGEND_APPS_MEMBERSHIP_ROLE_META, true ) );
		$this->assertNotSame( '', get_user_meta( 211, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	// -----------------------------------------------------------------
	// Token worker: a successful mint no longer promotes the link state --
	// promotion moved to the SAML round trip's own return leg
	// (agend_apps_saml_link_promote(), see the tests above). A mint needs
	// sso.tokens.create, a strictly stronger scope than the sso.identities.read
	// the link itself needs, so leaving promotion here would make the
	// recorded state depend on a scope stronger than the thing it describes.
	// -----------------------------------------------------------------

	#[Test]
	public function should_leave_the_recorded_state_alone_on_a_successful_mint(): void {
		$GLOBALS['agend_test_current_user_id'] = 30;
		update_user_meta( 30, 'imk_membership_number', 'member-30' );
		\agend_apps_wp_idp_record_link_state( 30, \AGEND_APPS_LINK_STATE_ASSERTED );

		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'access_token' => 'token-30',
					'expires_at'   => time() + 300,
					'user_id'      => 'supabase-30',
				),
			)
		);

		$worker = new Agend_Apps_Token_Worker();
		$token  = $worker->provide_token( '' );

		$this->assertSame( 'token-30', $token );
		// The mint still records the identity ids it returns (unrelated to link
		// state), but must not touch the recorded state.
		$this->assertSame( \AGEND_APPS_LINK_STATE_ASSERTED, \agend_apps_wp_idp_link_state( 30 )['state'] );
		$this->assertSame( 'supabase-30', \agend_apps_linked_identity_ids( 30 )['supabase_user_id'] );
	}
}
