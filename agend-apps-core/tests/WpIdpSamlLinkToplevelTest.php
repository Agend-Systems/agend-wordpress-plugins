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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sso.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/connect-site.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/detail-login.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-link.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-saml-link.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-saml-link-toplevel.php';

/**
 * The top-level link triggers (`includes/wp-idp-saml-link-toplevel.php`): the
 * sign-in and registration rewrite and its start step (B), and the redirect
 * ahead of directory content (A), together with the loop guard, the toggles and
 * the hook order that make them safe.
 *
 * The hook wrappers end in `wp_safe_redirect()` + `exit`, which would end the
 * test process, so the pure decisions they delegate to are what is covered:
 * {@see agend_apps_saml_link_signin_redirect()},
 * {@see agend_apps_saml_link_start_decision()} and
 * {@see agend_apps_saml_link_prerender_target()}. The harness clears registered
 * hooks before every test, so the wiring itself is asserted against the source.
 */
final class WpIdpSamlLinkToplevelTest extends TestCase {

	private const SP        = 'https://gw.example.test/api/auth/sso/wdaa/metadata';
	private const DIRECTORY = 60;
	private const MEMBER    = 5;

	private array $server = array();

	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/fixtures/saml-idp-stub.php';
		require_once __DIR__ . '/fixtures/woocommerce-page-stub.php';
		require_once __DIR__ . '/fixtures/woocommerce-urls-stub.php';

		update_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML );
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
		update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://example.test/saml/metadata' ) );
		update_option( 'agend_apps_sso_background_link', true );

		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'sso.identities.read', 'sso.tokens.create' ) ) ) );
		Agend_Apps_Key_Scopes::refresh();
		Agend_Test_WP::$requests = array();

		\agend_apps_saml_link_pending( '' );

		$this->server = $_SERVER;
		$_GET         = array();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/directory/';
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		$_GET    = array();

		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	private function eligible( int $user_id ): void {
		update_user_meta( $user_id, \AGEND_APPS_EXTERNAL_ID_META, 'ext-' . $user_id );
		update_option( 'wp_saml_idp_service_providers', array( array( 'entityId' => self::SP ) ) );
	}

	private function signedIn( int $user_id ): void {
		$GLOBALS['agend_test_current_user_id'] = $user_id;
	}

	private function signedInMember(): int {
		$this->eligible( self::MEMBER );
		$this->signedIn( self::MEMBER );

		return self::MEMBER;
	}

	private function user( int $id ): Agend_Test_User {
		return new Agend_Test_User( array(), $id );
	}

	/** Seeds the dedicated directory page and makes it the queried page. */
	private function directoryPage(): void {
		$GLOBALS['agend_test_posts'][ self::DIRECTORY ] = array(
			'ID'          => self::DIRECTORY,
			'post_type'   => 'page',
			'post_status' => 'publish',
		);

		Agend_Test_WP::$options[ \AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION ] = self::DIRECTORY;
		Agend_Test_WP::$queried_object_id                                    = self::DIRECTORY;
	}

	/** The `$_GET` a start-step URL arrives as: PHP's single decode of the query string. */
	private function startQuery( string $url ): array {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		// The stub's add_query_arg() encodes again on top of this plugin's single
		// rawurlencode() (real WordPress does not); one rawurldecode() reverses
		// that, leaving what a real $_GET would hold.
		if ( isset( $query['redirect_to'] ) ) {
			$query['redirect_to'] = rawurldecode( (string) $query['redirect_to'] );
		}

		return $query;
	}

	private function queryValue( string $url, string $key ): string {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return (string) ( $query[ $key ] ?? '' );
	}

	/** Where the done handler will send the browser, read out of an IdP-initiated URL. */
	private function returnTo( string $idp_url ): string {
		$relay_state = rawurldecode( $this->queryValue( $idp_url, 'RelayState' ) );

		return rawurldecode( $this->queryValue( $relay_state, 'redirect_to' ) );
	}

	private function doneQuery( string $idp_url ): array {
		$relay_state = rawurldecode( $this->queryValue( $idp_url, 'RelayState' ) );
		$query       = array();
		parse_str( (string) wp_parse_url( $relay_state, PHP_URL_QUERY ), $query );
		$query['redirect_to'] = rawurldecode( (string) ( $query['redirect_to'] ?? '' ) );

		return $query;
	}

	private function skipReason( int $user_id ): string {
		$stored = get_user_meta( $user_id, \AGEND_APPS_SAML_LINK_TOPLEVEL_SKIP_META, true );

		return is_array( $stored ) ? (string) $stored['reason'] : '';
	}

	private function wooPages(): void {
		$GLOBALS['agend_test_wc_pages'] = array(
			'cart'      => array( 'url' => 'https://example.test/cart/', 'id' => 10 ),
			'checkout'  => array( 'url' => 'https://example.test/checkout/', 'id' => 11 ),
			'myaccount' => array( 'url' => 'https://example.test/my-account/', 'id' => 12 ),
		);
	}

	// -----------------------------------------------------------------
	// Hook wiring and order
	// -----------------------------------------------------------------

	#[Test]
	public function should_hook_every_sign_in_entry_point_and_the_start_step(): void {
		$source = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-saml-link-toplevel.php' );

		$this->assertStringContainsString( "add_filter( 'login_redirect', 'agend_apps_saml_link_filter_login_redirect', 99, 3 );", $source );
		$this->assertStringContainsString( "add_filter( 'woocommerce_login_redirect', 'agend_apps_saml_link_filter_woocommerce_login_redirect', 99, 2 );", $source );
		$this->assertStringContainsString( "add_filter( 'woocommerce_registration_redirect', 'agend_apps_saml_link_filter_woocommerce_registration_redirect', 99, 1 );", $source );
		$this->assertStringContainsString( "add_action( 'template_redirect', 'agend_apps_saml_link_start', 0 );", $source );
		$this->assertStringContainsString( "add_action( 'wp', 'agend_apps_saml_link_prerender_maybe_redirect', 3 );", $source );
	}

	#[Test]
	public function should_load_the_top_level_triggers_only_in_wordpress_mode(): void {
		$bootstrap = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/agend-apps-core.php' );

		$this->assertMatchesRegularExpression(
			"/if \( Agend_Apps_Settings::wordpress_idp_enabled\(\) \) \{\s+require_once AGEND_APPS_CORE_DIR \. 'includes\/wp-idp-link\.php';\s+require_once AGEND_APPS_CORE_DIR \. 'includes\/wp-idp-saml-link\.php';[^}]*require_once AGEND_APPS_CORE_DIR \. 'includes\/wp-idp-saml-link-toplevel\.php';/s",
			$bootstrap
		);
	}

	#[Test]
	public function should_run_the_pre_render_redirect_before_the_sign_in_gate_and_the_server_rendered_detail_swap(): void {
		$read = static fn ( string $file ): string => (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/' . $file );

		$this->assertSame( 1, preg_match( "/add_action\( 'wp', 'agend_apps_saml_link_prerender_maybe_redirect', (\d+) \);/", $read( 'wp-idp-saml-link-toplevel.php' ), $prerender ) );
		$this->assertSame( 1, preg_match( "/add_action\( 'wp', 'agend_apps_records_maybe_redirect_directory_detail_to_login', (\d+) \);/", $read( 'records/detail-login.php' ), $gate ) );
		$this->assertSame( 1, preg_match( "/add_action\( 'wp', 'agend_apps_records_ssr_maybe_render_detail', (\d+) \);/", $read( 'records/ssr-detail.php' ), $swap ) );

		// The swap and its gateway call run at 5, the signed-out gate at 4: the
		// pre-render redirect has to come strictly before both.
		$this->assertSame( 3, (int) $prerender[1] );
		$this->assertLessThan( (int) $gate[1], (int) $prerender[1] );
		$this->assertLessThan( (int) $swap[1], (int) $prerender[1] );
		$this->assertSame( 4, (int) $gate[1] );
		$this->assertSame( 5, (int) $swap[1] );
	}

	#[Test]
	public function should_run_the_start_step_ahead_of_the_content_access_gate_and_the_done_handler(): void {
		$saml    = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-saml-link.php' );
		$toplevel = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-saml-link-toplevel.php' );
		$access  = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php' );

		$this->assertSame( 1, preg_match( "/add_action\( 'template_redirect', 'agend_apps_saml_link_start', (\d+) \);/", $toplevel, $start ) );
		$this->assertSame( 1, preg_match( "/add_action\( 'template_redirect', 'agend_apps_saml_link_done', (\d+) \);/", $saml, $done ) );
		$this->assertSame( 1, preg_match( "/add_action\( 'template_redirect', array\( \\\$this, 'gate_request' \), (\d+) \);/", $access, $gate ) );

		$this->assertLessThan( (int) $gate[1], (int) $start[1] );
		$this->assertLessThan( (int) $done[1], (int) $start[1] );
	}

	// -----------------------------------------------------------------
	// B. Destination validation
	// -----------------------------------------------------------------

	#[Test]
	public function should_accept_an_on_site_destination_and_make_a_root_relative_one_absolute(): void {
		$this->assertSame( 'https://example.test/directory/?a=1', \agend_apps_saml_link_valid_destination( 'https://example.test/directory/?a=1' ) );
		$this->assertSame( 'https://example.test/directory/', \agend_apps_saml_link_valid_destination( '/directory/' ) );
	}

	/** @return array<string, array{0: string}> */
	public static function unusableDestinations(): array {
		return array(
			'off-site host'              => array( 'https://evil.example/phish' ),
			'protocol-relative off-site' => array( '//evil.example/phish' ),
			'off-site userinfo trick'    => array( 'https://example.test@evil.example/' ),
			'javascript scheme'          => array( 'javascript:alert(1)' ),
			'empty'                      => array( '' ),
			'a start-step url'           => array( 'https://example.test/?agend_apps_saml_link_start=1&redirect_to=x' ),
			'a done url'                 => array( 'https://example.test/?agend_apps_saml_link_done=1&_wpnonce=x' ),
		);
	}

	#[Test]
	#[DataProvider( 'unusableDestinations' )]
	public function should_reject_a_destination_that_is_not_on_this_site( string $destination ): void {
		$this->assertSame( '', \agend_apps_saml_link_valid_destination( $destination ) );
		$this->assertSame( 'https://example.test/', \agend_apps_saml_link_valid_destination( $destination, 'https://example.test/' ) );
	}

	#[Test]
	#[DataProvider( 'unusableDestinations' )]
	public function should_never_rewrite_a_sign_in_to_an_unusable_destination( string $destination ): void {
		$member = $this->signedInMember();

		$this->assertSame( $destination, \agend_apps_saml_link_signin_redirect( $destination, $member ) );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'] );
	}

	#[Test]
	public function should_send_the_start_step_to_home_never_off_site_for_a_forged_destination(): void {
		$member = $this->signedInMember();

		foreach ( array( 'https://evil.example/phish', '//evil.example/phish', 'javascript:alert(1)', '' ) as $forged ) {
			$decision = \agend_apps_saml_link_start_decision(
				$member,
				array(
					\AGEND_APPS_SAML_LINK_START_FLAG => '1',
					'redirect_to'                    => $forged,
				)
			);

			$this->assertSame( 'redirect', $decision['action'], $forged );
			$this->assertStringNotContainsString( 'evil.example', $decision['url'], $forged );
			// The IdP-initiated trip still returns to the home page, not to the forged host.
			$this->assertSame( 'https://example.test/', $this->returnTo( $decision['url'] ) );

			\agend_apps_wp_idp_merge_link_state( $member, array( 'attempts' => 0, 'toplevel_at' => 0, 'timestamp' => time() - DAY_IN_SECONDS ) );
		}
	}

	// -----------------------------------------------------------------
	// B. Redirect rewriting, entry point by entry point
	// -----------------------------------------------------------------

	#[Test]
	public function should_rewrite_the_wp_login_redirect_to_the_start_step(): void {
		$member = $this->signedInMember();

		$rewritten = \agend_apps_saml_link_filter_login_redirect( 'https://example.test/directory/', 'https://example.test/directory/', $this->user( $member ) );

		$this->assertStringStartsWith( 'https://example.test/?', $rewritten );

		$query = $this->startQuery( $rewritten );

		$this->assertSame( '1', $query[ \AGEND_APPS_SAML_LINK_START_FLAG ] );
		$this->assertSame( 'https://example.test/directory/', $query['redirect_to'] );
	}

	#[Test]
	public function should_rewrite_the_woocommerce_login_redirect_to_the_start_step(): void {
		$member = $this->signedInMember();
		$this->wooPages();

		// The account dashboard is the usual destination of a WooCommerce login.
		$rewritten = \agend_apps_saml_link_filter_woocommerce_login_redirect( 'https://example.test/my-account/', $this->user( $member ) );

		$this->assertSame( '1', $this->startQuery( $rewritten )[ \AGEND_APPS_SAML_LINK_START_FLAG ] );
		$this->assertSame( 'https://example.test/my-account/', $this->startQuery( $rewritten )['redirect_to'] );
	}

	#[Test]
	public function should_rewrite_the_woocommerce_registration_redirect_for_the_member_it_just_signed_in(): void {
		$member = $this->signedInMember();
		$this->wooPages();

		$rewritten = \agend_apps_saml_link_filter_woocommerce_registration_redirect( 'https://example.test/my-account/' );

		$this->assertSame( '1', $this->startQuery( $rewritten )[ \AGEND_APPS_SAML_LINK_START_FLAG ] );
		$this->assertSame( 'https://example.test/my-account/', $this->startQuery( $rewritten )['redirect_to'] );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'], 'the redirect filter issues nothing' );
	}

	#[Test]
	public function should_leave_a_registration_redirect_alone_when_woocommerce_did_not_sign_the_member_in(): void {
		$this->eligible( self::MEMBER );
		$GLOBALS['agend_test_current_user_id'] = 0;

		$this->assertSame(
			'https://example.test/my-account/',
			\agend_apps_saml_link_filter_woocommerce_registration_redirect( 'https://example.test/my-account/' )
		);
	}

	#[Test]
	public function should_leave_a_redirect_alone_when_the_filter_has_no_usable_user_or_destination(): void {
		$this->signedInMember();

		$this->assertSame( 'https://example.test/directory/', \agend_apps_saml_link_filter_login_redirect( 'https://example.test/directory/', '', new \WP_Error( 'x', 'failed' ) ) );
		$this->assertSame( 'https://example.test/directory/', \agend_apps_saml_link_filter_login_redirect( 'https://example.test/directory/', '', null ) );
		$this->assertSame( 'https://example.test/directory/', \agend_apps_saml_link_filter_woocommerce_login_redirect( 'https://example.test/directory/', null ) );
		$this->assertNull( \agend_apps_saml_link_filter_login_redirect( null, '', $this->user( self::MEMBER ) ) );
	}

	#[Test]
	public function should_mint_no_nonce_and_write_no_state_while_rewriting(): void {
		$member = $this->signedInMember();

		$rewritten = \agend_apps_saml_link_signin_redirect( 'https://example.test/directory/', $member );

		$this->assertStringNotContainsString( '_wpnonce', $rewritten );
		$this->assertStringNotContainsString( 'idp_initiated', $rewritten );
		$this->assertStringNotContainsString( 'RelayState', $rewritten );
		$this->assertSame( \agend_apps_wp_idp_link_state( 0 ), \agend_apps_wp_idp_link_state( $member ), 'no attempt, view or marker is recorded by the rewrite' );
	}

	// -----------------------------------------------------------------
	// B. Where a member is never detoured
	// -----------------------------------------------------------------

	#[Test]
	public function should_not_detour_a_member_signing_in_to_wp_admin(): void {
		$member = $this->signedInMember();

		foreach ( array( 'https://example.test/wp-admin/', 'https://example.test/wp-admin/profile.php', 'https://example.test/wp-login.php?action=x' ) as $admin ) {
			$this->assertSame( $admin, \agend_apps_saml_link_signin_redirect( $admin, $member ), $admin );
		}

		$this->assertSame( 'signin_admin_destination', $this->skipReason( $member ) );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'] );
	}

	/** @return array<string, array{0: string}> */
	public static function purchaseAndAccountFormDestinations(): array {
		return array(
			'cart'                       => array( 'https://example.test/cart/' ),
			'cart with a query'          => array( 'https://example.test/cart/?added=1' ),
			'checkout'                   => array( 'https://example.test/checkout/' ),
			'checkout order pay'         => array( 'https://example.test/checkout/order-pay/55/?key=wc_order_x' ),
			'checkout order received'    => array( 'https://example.test/checkout/order-received/55/' ),
			'account edit account'       => array( 'https://example.test/my-account/edit-account/' ),
			'account edit address'       => array( 'https://example.test/my-account/edit-address/billing/' ),
			'account payment methods'    => array( 'https://example.test/my-account/payment-methods/' ),
			'account orders'             => array( 'https://example.test/my-account/orders/' ),
			'plain permalink cart'       => array( 'https://example.test/?page_id=10' ),
			'plain permalink checkout'   => array( 'https://example.test/?page_id=11&order-pay=55' ),
			'plain permalink endpoint'   => array( 'https://example.test/?page_id=12&edit-account=' ),
			'root-relative cart'         => array( '/cart/' ),
		);
	}

	#[Test]
	#[DataProvider( 'purchaseAndAccountFormDestinations' )]
	public function should_not_detour_a_member_signing_in_to_the_cart_checkout_or_an_account_form( string $destination ): void {
		$member = $this->signedInMember();
		$this->wooPages();

		$this->assertSame( $destination, \agend_apps_saml_link_signin_redirect( $destination, $member ) );
		$this->assertSame( 'signin_blocked_destination', $this->skipReason( $member ) );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'] );
	}

	#[Test]
	#[DataProvider( 'purchaseAndAccountFormDestinations' )]
	public function should_not_issue_a_trip_from_the_start_step_for_a_blocked_destination( string $destination ): void {
		$member = $this->signedInMember();
		$this->wooPages();

		$decision = \agend_apps_saml_link_start_decision(
			$member,
			array(
				\AGEND_APPS_SAML_LINK_START_FLAG => '1',
				'redirect_to'                    => $destination,
			)
		);

		// Still sent on to the page they were heading for, just not round the IdP.
		$this->assertSame( 'redirect', $decision['action'] );
		$this->assertStringStartsWith( 'https://example.test/', $decision['url'] );
		$this->assertStringNotContainsString( 'idp_initiated', $decision['url'] );
		$this->assertSame( 'signin_blocked_destination', $decision['reason'] );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'] );
	}

	#[Test]
	public function should_still_link_a_member_signing_in_to_the_account_dashboard_and_ordinary_pages(): void {
		$member = $this->signedInMember();
		$this->wooPages();

		foreach (
			array(
				'https://example.test/my-account/',
				'https://example.test/my-account/?utm_source=email',
				'https://example.test/?page_id=12',
				'https://example.test/directory/',
				'https://example.test/cart-care-guide/',
				'https://example.test/',
			) as $destination
		) {
			$rewritten = \agend_apps_saml_link_signin_redirect( $destination, $member );

			$this->assertNotSame( $destination, $rewritten, $destination );
			$this->assertSame( '1', $this->startQuery( $rewritten )[ \AGEND_APPS_SAML_LINK_START_FLAG ], $destination );
		}
	}

	#[Test]
	public function should_block_nothing_when_woocommerce_has_no_pages_configured(): void {
		$member = $this->signedInMember();

		// The fixture reports an unset page, as WooCommerce does when its pages are missing.
		$this->assertNotSame( 'https://example.test/cart/', \agend_apps_saml_link_signin_redirect( 'https://example.test/cart/', $member ) );
	}

	#[Test]
	public function should_let_a_site_block_further_destinations_through_the_filter(): void {
		$member = $this->signedInMember();
		add_filter(
			'agend_apps_saml_link_signin_destination_blocked',
			static fn ( bool $blocked, string $url ): bool => $blocked || false !== strpos( $url, '/event-booking/' ),
			10,
			2
		);

		$this->assertSame( 'https://example.test/event-booking/', \agend_apps_saml_link_signin_redirect( 'https://example.test/event-booking/', $member ) );
		$this->assertSame( 'signin_blocked_destination', $this->skipReason( $member ) );
		$this->assertNotSame( 'https://example.test/directory/', \agend_apps_saml_link_signin_redirect( 'https://example.test/directory/', $member ) );
	}

	// -----------------------------------------------------------------
	// B. Eligibility, the toggle, the throttle and the loop guard
	// -----------------------------------------------------------------

	#[Test]
	public function should_be_inert_when_the_sign_in_toggle_is_off(): void {
		$member = $this->signedInMember();
		update_option( 'agend_apps_sso_signin_link', false );

		$this->assertSame( 'https://example.test/directory/', \agend_apps_saml_link_signin_redirect( 'https://example.test/directory/', $member ) );

		$decision = \agend_apps_saml_link_start_decision( $member, array( \AGEND_APPS_SAML_LINK_START_FLAG => '1', 'redirect_to' => 'https://example.test/directory/' ) );

		$this->assertSame( 'https://example.test/directory/', $decision['url'] );
		$this->assertSame( 'signin_link_disabled', $decision['reason'] );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'] );
		$this->assertSame( '', $this->skipReason( $member ), 'a switched-off feature is not a skip to explain' );
	}

	/** @return array<string, array{0: string, 1: string}> option change => reason */
	public static function inertConfigurations(): array {
		return array(
			'background link off'     => array( 'background', 'background_link_disabled' ),
			'mechanism not saml'      => array( 'mechanism', 'mechanism_not_saml' ),
			'mode not wordpress'      => array( 'mode', 'mode_not_wordpress' ),
		);
	}

	#[Test]
	#[DataProvider( 'inertConfigurations' )]
	public function should_be_inert_unless_the_background_link_saml_and_wordpress_mode_are_all_on( string $which, string $reason ): void {
		$member = $this->signedInMember();

		if ( 'background' === $which ) {
			update_option( 'agend_apps_sso_background_link', false );
		} elseif ( 'mechanism' === $which ) {
			update_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_DISABLED );
		} else {
			update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_SSO );
		}

		$destination = 'https://example.test/directory/';

		// Both toggles are on (the default); the eligibility gate they share still stops both triggers.
		$this->assertTrue( Agend_Apps_Settings::signin_link_enabled() );
		$this->assertTrue( Agend_Apps_Settings::prerender_link_enabled() );
		$this->assertSame( $destination, \agend_apps_saml_link_signin_redirect( $destination, $member ) );
		$this->assertSame( $reason, \agend_apps_saml_link_start_decision( $member, array( \AGEND_APPS_SAML_LINK_START_FLAG => '1', 'redirect_to' => $destination ) )['reason'] );

		$this->directoryPage();
		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'] );
	}

	#[Test]
	public function should_not_rewrite_for_a_linked_ineligible_or_capped_member_and_record_nothing(): void {
		// Linked.
		$this->eligible( 21 );
		\agend_apps_wp_idp_record_link_state( 21, \AGEND_APPS_LINK_STATE_LINKED );
		$this->assertSame( 'https://example.test/directory/', \agend_apps_saml_link_signin_redirect( 'https://example.test/directory/', 21 ) );

		// No external id.
		$this->assertSame( 'https://example.test/directory/', \agend_apps_saml_link_signin_redirect( 'https://example.test/directory/', 22 ) );

		// Attempt cap: the one capped fallback the late redirect is owed is NOT available here.
		$this->eligible( 23 );
		\agend_apps_wp_idp_merge_link_state( 23, array( 'attempts' => \AGEND_APPS_LINK_MAX_ATTEMPTS ) );
		$this->assertSame( 'https://example.test/directory/', \agend_apps_saml_link_signin_redirect( 'https://example.test/directory/', 23 ) );

		$decision = \agend_apps_saml_link_start_decision( 23, array( \AGEND_APPS_SAML_LINK_START_FLAG => '1', 'redirect_to' => 'https://example.test/directory/' ) );
		$this->assertSame( 'https://example.test/directory/', $decision['url'] );
		$this->assertSame( 'attempt_cap', $decision['reason'] );
		$this->assertSame( \AGEND_APPS_LINK_MAX_ATTEMPTS, \agend_apps_wp_idp_link_state( 23 )['attempts'] );

		foreach ( array( 21, 22, 23 ) as $id ) {
			$this->assertSame( '', $this->skipReason( $id ), (string) $id );
		}
	}

	#[Test]
	public function should_respect_the_throttle_when_rewriting_and_in_the_start_step(): void {
		$member = $this->signedInMember();
		\agend_apps_wp_idp_record_link_state( $member, \AGEND_APPS_LINK_STATE_ASSERTED );

		$this->assertSame( 'https://example.test/directory/', \agend_apps_saml_link_signin_redirect( 'https://example.test/directory/', $member ) );
		$this->assertSame( 'signin_throttled', $this->skipReason( $member ) );

		$decision = \agend_apps_saml_link_start_decision( $member, array( \AGEND_APPS_SAML_LINK_START_FLAG => '1', 'redirect_to' => 'https://example.test/directory/' ) );

		$this->assertSame( 'https://example.test/directory/', $decision['url'] );
		$this->assertSame( 'signin_throttled', $decision['reason'] );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'] );
	}

	#[Test]
	public function should_not_rewrite_once_a_round_trip_has_come_back(): void {
		$member = $this->signedInMember();
		\agend_apps_wp_idp_merge_link_state( $member, array( 'completed' => true ) );

		$this->assertSame( 'https://example.test/directory/', \agend_apps_saml_link_signin_redirect( 'https://example.test/directory/', $member ) );
		$this->assertSame( 'signin_round_trip_done', $this->skipReason( $member ) );
	}

	// -----------------------------------------------------------------
	// B. The two-request nonce flow
	// -----------------------------------------------------------------

	#[Test]
	public function should_mint_the_idp_nonce_on_the_authenticated_request_not_during_the_sign_in(): void {
		$member = $this->signedInMember();

		// Request 1: the sign-in itself. WordPress has set the new auth cookie
		// but it is not in $_COOKIE yet, so a nonce minted now would be bound
		// to the pre-login session.
		$GLOBALS['agend_test_session_token'] = 'pre-login-session';
		$rewritten                           = \agend_apps_saml_link_signin_redirect( 'https://example.test/directory/', $member );
		$nonce_during_sign_in                = wp_create_nonce( 'wp_saml_idp_sso_' . self::SP );

		$this->assertStringNotContainsString( $nonce_during_sign_in, $rewritten );
		$this->assertStringNotContainsString( '_wpnonce', $rewritten );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'] );

		// Request 2: the browser follows the redirect with the new cookie.
		$GLOBALS['agend_test_session_token'] = 'post-login-session';
		$decision                            = \agend_apps_saml_link_start_decision( $member, $this->startQuery( $rewritten ) );

		$nonce_after_sign_in = wp_create_nonce( 'wp_saml_idp_sso_' . self::SP );

		$this->assertNotSame( $nonce_during_sign_in, $nonce_after_sign_in, 'the two sessions would have produced different nonces' );
		$this->assertSame( 'redirect', $decision['action'] );
		$this->assertSame( $nonce_after_sign_in, $this->queryValue( $decision['url'], '_wpnonce' ), 'the IdP nonce belongs to the authenticated session' );
		$this->assertNotSame( $nonce_during_sign_in, $this->queryValue( $decision['url'], '_wpnonce' ) );

		// The done URL's own nonce is minted on the same request.
		$done = $this->doneQuery( $decision['url'] );
		$this->assertSame( wp_create_nonce( \AGEND_APPS_SAML_LINK_DONE_NONCE ), $done['_wpnonce'] );
		$this->assertNotSame( 'test-nonce-' . md5( \AGEND_APPS_SAML_LINK_DONE_NONCE ), $done['_wpnonce'], 'bound to the post-login session, not the empty one' );
	}

	#[Test]
	public function should_issue_a_top_level_trip_that_returns_to_the_original_destination(): void {
		$member = $this->signedInMember();

		$decision = \agend_apps_saml_link_start_decision(
			$member,
			array(
				\AGEND_APPS_SAML_LINK_START_FLAG => '1',
				'redirect_to'                    => 'https://example.test/directory/listing/acme/?tab=2',
			)
		);

		$this->assertSame( array( 'action' => 'redirect', 'reason' => '' ), array( 'action' => $decision['action'], 'reason' => $decision['reason'] ) );
		$this->assertSame( '1', $this->queryValue( $decision['url'], 'idp_initiated' ) );
		$this->assertSame( self::SP, rawurldecode( $this->queryValue( $decision['url'], 'sp' ) ) );

		$done = $this->doneQuery( $decision['url'] );

		$this->assertSame( '1', $done[ \AGEND_APPS_SAML_LINK_DONE_FLAG ] );
		$this->assertArrayNotHasKey( 'frame', $done, 'a top-level trip, not the hidden-iframe one' );
		$this->assertSame( 'https://example.test/directory/listing/acme/?tab=2', $done['redirect_to'] );

		$stored = \agend_apps_wp_idp_link_state( $member );
		$this->assertSame( 1, $stored['attempts'] );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ASSERTED, $stored['state'] );
		$this->assertSame( 'signin', $stored['toplevel_via'] );
		$this->assertGreaterThan( 0, $stored['toplevel_at'] );
	}

	#[Test]
	public function should_do_nothing_for_a_request_without_the_start_flag(): void {
		$member = $this->signedInMember();

		$this->assertSame(
			array( 'action' => 'skip', 'url' => '', 'reason' => '' ),
			\agend_apps_saml_link_start_decision( $member, array( 'redirect_to' => 'https://example.test/directory/' ) )
		);
		$this->assertSame(
			array( 'action' => 'skip', 'url' => '', 'reason' => '' ),
			\agend_apps_saml_link_start_decision( $member, array( \AGEND_APPS_SAML_LINK_START_FLAG => '0' ) )
		);
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'] );
	}

	#[Test]
	public function should_send_a_signed_out_start_request_on_to_its_destination_without_a_trip(): void {
		$this->eligible( self::MEMBER );

		$decision = \agend_apps_saml_link_start_decision( 0, array( \AGEND_APPS_SAML_LINK_START_FLAG => '1', 'redirect_to' => 'https://example.test/directory/' ) );

		$this->assertSame( array( 'action' => 'redirect', 'url' => 'https://example.test/directory/', 'reason' => 'signed_out' ), $decision );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( self::MEMBER )['attempts'] );
	}

	#[Test]
	public function should_not_let_repeated_sign_ins_loop(): void {
		$member = $this->signedInMember();
		$query  = array( \AGEND_APPS_SAML_LINK_START_FLAG => '1', 'redirect_to' => 'https://example.test/directory/' );

		$first = \agend_apps_saml_link_start_decision( $member, $query );
		$this->assertStringContainsString( 'idp_initiated', $first['url'] );

		// A refresh of the start URL, or signing straight in again, within the window.
		$second = \agend_apps_saml_link_start_decision( $member, $query );

		$this->assertSame( 'https://example.test/directory/', $second['url'] );
		$this->assertSame( 'signin_recent_round_trip', $second['reason'] );
		$this->assertSame( 'https://example.test/directory/', \agend_apps_saml_link_signin_redirect( 'https://example.test/directory/', $member ) );
		$this->assertSame( 1, \agend_apps_wp_idp_link_state( $member )['attempts'], 'one attempt, however many sign-ins' );
	}

	#[Test]
	public function should_never_arm_the_background_placeholder_on_the_start_request(): void {
		$this->signedInMember();
		$_GET = array( \AGEND_APPS_SAML_LINK_START_FLAG => '1', 'redirect_to' => 'https://example.test/directory/' );
		$_SERVER['REQUEST_URI'] = '/?agend_apps_saml_link_start=1';

		\agend_apps_saml_link_maybe_trigger();

		$this->assertSame( '', \agend_apps_saml_link_pending() );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( self::MEMBER )['views'] );
	}

	// -----------------------------------------------------------------
	// A. What counts as a member content page
	// -----------------------------------------------------------------

	#[Test]
	public function should_redirect_a_signed_in_unlinked_member_through_the_round_trip_before_the_directory_page(): void {
		$member = $this->signedInMember();
		$this->directoryPage();
		$_SERVER['REQUEST_URI'] = '/directory/?agend_page=2';

		$target = \agend_apps_saml_link_prerender_target();

		$this->assertNotSame( '', $target );
		$this->assertSame( '1', $this->queryValue( $target, 'idp_initiated' ) );
		$this->assertSame( 'https://example.test/directory/?agend_page=2', $this->returnTo( $target ) );
		$this->assertArrayNotHasKey( 'frame', $this->doneQuery( $target ) );

		$stored = \agend_apps_wp_idp_link_state( $member );
		$this->assertSame( 1, $stored['attempts'] );
		$this->assertSame( 'prerender', $stored['toplevel_via'] );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ASSERTED, $stored['state'] );
	}

	/** @return array<string, array{0: string}> */
	public static function listingQueryVars(): array {
		return array(
			'pretty query var' => array( 'agend_dir_listing' ),
			'legacy query var' => array( 'agend_listing' ),
		);
	}

	#[Test]
	#[DataProvider( 'listingQueryVars' )]
	public function should_redirect_before_a_listing_detail_on_the_directory_page( string $var ): void {
		$this->signedInMember();
		$this->directoryPage();
		Agend_Test_WP::$query_vars[ $var ] = 'acme-pty-ltd';
		$_SERVER['REQUEST_URI']            = '/directory/listing/acme-pty-ltd/';

		$target = \agend_apps_saml_link_prerender_target();

		$this->assertNotSame( '', $target );
		$this->assertSame( 'https://example.test/directory/listing/acme-pty-ltd/', $this->returnTo( $target ) );
	}

	#[Test]
	public function should_redirect_before_a_listing_detail_when_no_dedicated_directory_page_is_configured(): void {
		$this->signedInMember();
		Agend_Test_WP::$queried_object_id                     = 77;
		Agend_Test_WP::$query_vars['agend_dir_listing']       = 'acme';
		$_SERVER['REQUEST_URI']                               = '/members/listing/acme/';

		$this->assertNotSame( '', \agend_apps_saml_link_prerender_target() );
	}

	#[Test]
	public function should_leave_a_listing_detail_on_another_page_to_the_dedicated_page_redirect(): void {
		$this->signedInMember();
		$this->directoryPage();
		Agend_Test_WP::$queried_object_id               = 77;
		Agend_Test_WP::$query_vars['agend_dir_listing'] = 'acme';

		// The wp:4 redirect sends this to the dedicated page first; the round trip then happens there.
		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
	}

	#[Test]
	public function should_ignore_any_page_that_is_not_the_directory(): void {
		$this->signedInMember();
		$this->directoryPage();
		Agend_Test_WP::$queried_object_id = 99;
		$_SERVER['REQUEST_URI']           = '/about/';

		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( self::MEMBER )['attempts'] );
	}

	#[Test]
	public function should_let_a_site_opt_another_page_in_and_the_directory_out(): void {
		$this->signedInMember();
		$this->directoryPage();
		$seen = array();

		add_filter(
			'agend_apps_saml_link_prerender_required',
			static function ( bool $required, array $context ) use ( &$seen ): bool {
				$seen[] = array( $required, $context['kind'], $context['page_id'] );

				return 99 === $context['page_id'];
			},
			10,
			2
		);

		// The directory page is opted out.
		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
		$this->assertSame( array( true, 'directory_page', self::DIRECTORY ), $seen[0] );

		// A page the default would never touch is opted in, and every other guard still applies to it.
		Agend_Test_WP::$queried_object_id = 99;
		$_SERVER['REQUEST_URI']           = '/member-area/';

		$target = \agend_apps_saml_link_prerender_target();

		$this->assertNotSame( '', $target );
		$this->assertSame( array( false, '', 99 ), $seen[1] );
		$this->assertSame( 'https://example.test/member-area/', $this->returnTo( $target ) );
	}

	#[Test]
	public function should_build_the_current_url_from_the_origin_and_the_request_uri(): void {
		$_SERVER['REQUEST_URI'] = '/directory/?q=a%20b&page=2';

		$this->assertSame( 'https://example.test/directory/?q=a%20b&page=2', \agend_apps_saml_link_current_url() );
	}

	// -----------------------------------------------------------------
	// A. Every guard
	// -----------------------------------------------------------------

	private function directoryRequestForMember(): int {
		$member = $this->signedInMember();
		$this->directoryPage();

		return $member;
	}

	#[Test]
	public function should_not_redirect_a_signed_out_visitor_and_leave_them_to_the_sign_in_gate(): void {
		$this->eligible( self::MEMBER );
		$this->directoryPage();
		$GLOBALS['agend_test_current_user_id']                                   = 0;
		$GLOBALS['agend_test_post_meta'][ self::DIRECTORY ]['_agend_apps_directory_require_login'] = array( 'blocks' );
		Agend_Test_WP::$options['permalink_structure']                           = '/%postname%/';
		Agend_Test_WP::$query_vars['agend_dir_listing']                          = 'acme';

		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
		// The priority 4 gate still has its signed-out visitor.
		$this->assertNotSame( '', \agend_apps_records_directory_login_redirect_target() );
	}

	#[Test]
	public function should_not_interfere_with_the_sign_in_gate_for_a_signed_in_member(): void {
		$this->directoryRequestForMember();
		$GLOBALS['agend_test_post_meta'][ self::DIRECTORY ]['_agend_apps_directory_require_login'] = array( 'blocks' );
		Agend_Test_WP::$options['permalink_structure']                                           = '/%postname%/';
		Agend_Test_WP::$query_vars['agend_dir_listing']                                          = 'acme';

		// The gate only concerns signed-out visitors and stays out of a member's way.
		$this->assertSame( '', \agend_apps_records_directory_login_redirect_target() );
		$this->assertNotSame( '', \agend_apps_saml_link_prerender_target() );
	}

	/** @return array<string, array{0: string}> */
	public static function guardedRequests(): array {
		return array(
			'a POST'                  => array( 'post' ),
			'a HEAD request'          => array( 'head' ),
			'wp-admin'                => array( 'admin' ),
			'AJAX'                    => array( 'ajax' ),
			'cron'                    => array( 'cron' ),
			'a feed'                  => array( 'feed' ),
			'an embed'                => array( 'embed' ),
			'a secondary query'       => array( 'not_main_query' ),
			'the cart'                => array( 'cart' ),
			'the checkout'            => array( 'checkout' ),
			'an account form'         => array( 'account_form' ),
			'the done url'            => array( 'done_flag' ),
			'the start url'           => array( 'start_flag' ),
			'a SAML dispatcher'       => array( 'saml_action' ),
			'an IdP-initiated url'    => array( 'idp_initiated' ),
			'a prefetch'              => array( 'prefetch' ),
			'a prerender'             => array( 'prerender' ),
			'an iframe'               => array( 'iframe' ),
			'a script fetch'          => array( 'fetch_mode' ),
			'an elementor preview'    => array( 'elementor_preview' ),
			'a post preview'          => array( 'preview' ),
		);
	}

	#[Test]
	#[DataProvider( 'guardedRequests' )]
	public function should_not_redirect_for_a_guarded_request( string $kind ): void {
		$member = $this->directoryRequestForMember();

		switch ( $kind ) {
			case 'post':
				$_SERVER['REQUEST_METHOD'] = 'POST';
				break;
			case 'head':
				$_SERVER['REQUEST_METHOD'] = 'HEAD';
				break;
			case 'admin':
				$GLOBALS['agend_test_is_admin'] = true;
				break;
			case 'ajax':
				$GLOBALS['agend_test_doing_ajax'] = true;
				break;
			case 'cron':
				$GLOBALS['agend_test_doing_cron'] = true;
				break;
			case 'feed':
				$GLOBALS['agend_test_is_feed'] = true;
				break;
			case 'embed':
				$GLOBALS['agend_test_is_embed'] = true;
				break;
			case 'not_main_query':
				$GLOBALS['agend_test_not_main_query'] = true;
				break;
			case 'cart':
				$GLOBALS['agend_test_is_cart'] = true;
				break;
			case 'checkout':
				$GLOBALS['agend_test_is_checkout'] = true;
				break;
			case 'account_form':
				$GLOBALS['agend_test_is_account_page']    = true;
				$GLOBALS['agend_test_is_wc_endpoint_url'] = true;
				break;
			case 'done_flag':
				$_GET = array( \AGEND_APPS_SAML_LINK_DONE_FLAG => '1' );
				break;
			case 'start_flag':
				$_GET = array( \AGEND_APPS_SAML_LINK_START_FLAG => '1' );
				break;
			case 'saml_action':
				$_GET = array( 'saml_action' => 'x' );
				break;
			case 'idp_initiated':
				$_GET = array( 'idp_initiated' => '1' );
				break;
			case 'prefetch':
				$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch;anonymous-client-ip';
				break;
			case 'prerender':
				$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch;prerender';
				break;
			case 'iframe':
				$_SERVER['HTTP_SEC_FETCH_DEST'] = 'iframe';
				break;
			case 'fetch_mode':
				$_SERVER['HTTP_SEC_FETCH_MODE'] = 'cors';
				break;
			case 'elementor_preview':
				$_GET = array( 'elementor-preview' => '60' );
				break;
			case 'preview':
				$_GET = array( 'preview' => 'true' );
				break;
		}

		$this->assertSame( '', \agend_apps_saml_link_prerender_target(), $kind );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'], $kind );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['toplevel_at'], $kind );
	}

	#[Test]
	public function should_redirect_a_genuine_browser_navigation(): void {
		$this->directoryRequestForMember();
		$_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
		$_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';

		$this->assertNotSame( '', \agend_apps_saml_link_prerender_target() );
	}

	#[Test]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function should_not_redirect_a_rest_request(): void {
		define( 'REST_REQUEST', true );
		$this->eligible( self::MEMBER );
		$this->signedIn( self::MEMBER );
		$this->directoryPage();

		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
	}

	#[Test]
	public function should_be_inert_when_the_pre_render_toggle_is_off_and_leave_the_background_path_alone(): void {
		$member = $this->directoryRequestForMember();
		update_option( 'agend_apps_sso_prerender_link', false );

		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'] );
		$this->assertSame( '', $this->skipReason( $member ) );

		// The hidden-iframe path still arms for this very request.
		do_action( 'wp_head' );
		\agend_apps_saml_link_maybe_trigger();

		$this->assertNotSame( '', \agend_apps_saml_link_pending() );
	}

	#[Test]
	public function should_stay_out_of_the_way_of_a_member_who_is_not_eligible(): void {
		$this->directoryPage();

		// Linked.
		$this->eligible( 31 );
		$this->signedIn( 31 );
		\agend_apps_wp_idp_record_link_state( 31, \AGEND_APPS_LINK_STATE_LINKED );
		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );

		// No external id.
		$this->signedIn( 32 );
		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );

		// Attempt cap: unlike the late fallback, the pre-render trip is never owed a capped attempt.
		$this->eligible( 33 );
		$this->signedIn( 33 );
		\agend_apps_wp_idp_merge_link_state( 33, array( 'attempts' => \AGEND_APPS_LINK_MAX_ATTEMPTS ) );
		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
		$this->assertSame( \AGEND_APPS_LINK_MAX_ATTEMPTS, \agend_apps_wp_idp_link_state( 33 )['attempts'] );
		$this->assertFalse( \agend_apps_wp_idp_link_state( 33 )['fallback_done'] );
	}

	// -----------------------------------------------------------------
	// A. Throttle, and the loop guard after a round trip came back
	// -----------------------------------------------------------------

	#[Test]
	public function should_respect_the_throttle_and_record_why_it_stood_down(): void {
		$member = $this->directoryRequestForMember();
		\agend_apps_wp_idp_record_link_state( $member, \AGEND_APPS_LINK_STATE_ASSERTED );

		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
		$this->assertSame( 'prerender_throttled', $this->skipReason( $member ) );
		$this->assertSame( 0, \agend_apps_wp_idp_link_state( $member )['attempts'] );
	}

	#[Test]
	public function should_redirect_again_once_the_throttle_and_the_window_have_both_passed(): void {
		$member = $this->directoryRequestForMember();
		\agend_apps_wp_idp_record_link_state( $member, \AGEND_APPS_LINK_STATE_ASSERTED );
		\agend_apps_wp_idp_merge_link_state(
			$member,
			array(
				'timestamp'    => time() - \AGEND_APPS_LINK_BACKOFF_ASSERTED - 1,
				'toplevel_at'  => time() - \AGEND_APPS_SAML_LINK_TOPLEVEL_WINDOW - 1,
				'toplevel_via' => 'prerender',
			)
		);

		$this->assertNotSame( '', \agend_apps_saml_link_prerender_target() );
	}

	#[Test]
	public function should_not_bounce_a_member_back_from_a_round_trip_that_failed_before_returning(): void {
		$member = $this->directoryRequestForMember();

		$first = \agend_apps_saml_link_prerender_target();
		$this->assertNotSame( '', $first );

		// The identity provider errored; the member navigates back to the same page.
		// Even once the asserted backoff has passed, the window still holds.
		\agend_apps_wp_idp_merge_link_state( $member, array( 'timestamp' => time() - \AGEND_APPS_LINK_BACKOFF_ASSERTED - 1 ) );

		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
		$this->assertSame( 'prerender_recent_round_trip', $this->skipReason( $member ) );
		$this->assertSame( 1, \agend_apps_wp_idp_link_state( $member )['attempts'] );
	}

	#[Test]
	public function should_not_bounce_a_member_back_from_a_round_trip_that_returned_unlinked(): void {
		$member = $this->directoryRequestForMember();

		$target = \agend_apps_saml_link_prerender_target();
		$this->assertNotSame( '', $target );

		// The round trip came back through the done handler, but the gateway does not (yet) report them linked.
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'linked' => false ) ) );
		$done = \agend_apps_saml_link_done_decision( $member, $this->doneQuery( $target ) );

		$this->assertSame( 'redirect', $done['action'] );
		$this->assertSame( 'https://example.test/directory/', $done['url'] );
		$this->assertSame( 'not_linked', $done['code'] );

		// Back on the same page, past every time-based guard: the returned trip alone stops a second one.
		\agend_apps_wp_idp_merge_link_state(
			$member,
			array(
				'timestamp'   => time() - DAY_IN_SECONDS,
				'toplevel_at' => time() - DAY_IN_SECONDS,
			)
		);

		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
		$this->assertSame( 'prerender_round_trip_done', $this->skipReason( $member ) );
		$this->assertSame( 1, \agend_apps_wp_idp_link_state( $member )['attempts'] );

		// The background iframe path still runs on that page.
		do_action( 'wp_head' );
		\agend_apps_saml_link_maybe_trigger();
		$this->assertNotSame( '', \agend_apps_saml_link_pending() );
	}

	#[Test]
	public function should_not_bounce_a_member_back_from_a_round_trip_that_linked_them(): void {
		$member = $this->directoryRequestForMember();

		$target = \agend_apps_saml_link_prerender_target();
		$this->assertNotSame( '', $target );

		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'linked' => true, 'user_id' => 'supabase-5' ) ) );
		$done = \agend_apps_saml_link_done_decision( $member, $this->doneQuery( $target ) );

		$this->assertSame( 'redirect', $done['action'] );
		$this->assertSame( \AGEND_APPS_LINK_STATE_LINKED, $done['state'] );

		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
		$this->assertSame( \AGEND_APPS_LINK_STATE_LINKED, \agend_apps_wp_idp_link_state( $member )['state'] );
		$this->assertSame( 1, \agend_apps_wp_idp_link_state( $member )['attempts'] );
	}

	#[Test]
	public function should_hold_a_sign_in_trip_and_a_pre_render_trip_to_the_same_guard(): void {
		$member = $this->directoryRequestForMember();

		// A member just sent round by the sign-in step is not sent round again by the page they land on.
		$decision = \agend_apps_saml_link_start_decision( $member, array( \AGEND_APPS_SAML_LINK_START_FLAG => '1', 'redirect_to' => 'https://example.test/directory/' ) );
		$this->assertStringContainsString( 'idp_initiated', $decision['url'] );

		$this->assertSame( '', \agend_apps_saml_link_prerender_target() );
		$this->assertSame( 'prerender_recent_round_trip', $this->skipReason( $member ) );
		$this->assertSame( 1, \agend_apps_wp_idp_link_state( $member )['attempts'] );
	}

	#[Test]
	public function should_record_the_loop_guard_marker_in_the_link_state_not_a_parallel_store(): void {
		$member = $this->directoryRequestForMember();

		\agend_apps_saml_link_prerender_target();

		$meta = $GLOBALS['agend_test_user_meta'][ $member ] ?? array();

		// The member's external id aside, the only key written is the existing link state.
		$this->assertSame( array( \AGEND_APPS_EXTERNAL_ID_META, \AGEND_APPS_LINK_STATE_META ), array_keys( $meta ) );
		$this->assertSame( 'prerender', $meta[ \AGEND_APPS_LINK_STATE_META ]['toplevel_via'] );
	}

	#[Test]
	public function should_write_a_skip_reason_only_when_it_changes(): void {
		\agend_apps_saml_link_record_toplevel_skip( 41, 'prerender_throttled' );
		update_user_meta( 41, \AGEND_APPS_SAML_LINK_TOPLEVEL_SKIP_META, array( 'reason' => 'prerender_throttled', 'timestamp' => 1 ) );

		\agend_apps_saml_link_record_toplevel_skip( 41, 'prerender_throttled' );
		$this->assertSame( 1, get_user_meta( 41, \AGEND_APPS_SAML_LINK_TOPLEVEL_SKIP_META, true )['timestamp'], 'unchanged reason, no write' );

		\agend_apps_saml_link_record_toplevel_skip( 41, 'prerender_recent_round_trip' );
		$this->assertSame( 'prerender_recent_round_trip', get_user_meta( 41, \AGEND_APPS_SAML_LINK_TOPLEVEL_SKIP_META, true )['reason'] );

		\agend_apps_saml_link_record_toplevel_skip( 0, 'prerender_throttled' );
		\agend_apps_saml_link_record_toplevel_skip( 42, '' );
		$this->assertSame( '', get_user_meta( 42, \AGEND_APPS_SAML_LINK_TOPLEVEL_SKIP_META, true ) );
	}

	#[Test]
	public function should_clear_the_recorded_skip_once_a_trip_is_issued(): void {
		$member = $this->directoryRequestForMember();
		\agend_apps_saml_link_record_toplevel_skip( $member, 'signin_admin_destination' );

		$this->assertNotSame( '', \agend_apps_saml_link_prerender_target() );
		$this->assertSame( '', $this->skipReason( $member ) );
	}
}
