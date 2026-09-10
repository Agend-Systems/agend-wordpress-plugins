<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Settings;
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

/**
 * The WordPress-as-IdP identity link step
 * (docs/PLAN-wordpress-idp-option-b.md section 4.2; shipped-contract build
 * brief supersedes that document's draft endpoint shape).
 *
 * `agend_apps_wp_idp_link_user()` is exercised directly rather than through
 * `do_action( 'wp_login', ... )`: the hooks this file registers at load time
 * (bottom of `includes/wp-idp-link.php`) are wiped by
 * {@see \Agend\Tests\TestCase::setUp()}'s `Agend_Test_WP::reset()` before the
 * first test runs, the same reason `LoginBridgeDecisionTest` calls its
 * decision functions directly instead of firing `authenticate`.
 *
 * `includes/records/features.php` (and the scope cache behind it) IS
 * required here, deliberately, even though nothing below tests the
 * `sso_identity_link` gate directly: `agend_apps_records_feature_available()`
 * is only a no-op via `function_exists()` when that file has never loaded in
 * the WHOLE PHPUnit PROCESS, and another test file
 * (`OptionalFeaturesTest`/`MembershipSyncTest`) always requires it at file
 * scope regardless of which test method actually runs. Skipping the require
 * here would make this file pass in isolation and fail inside `composer
 * test`, which is worse than not testing the gate at all. `setUp()` seeds
 * the cached scopes with `sso.identities.create` (the scope
 * `sso_identity_link` needs) so every test below exercises the link logic
 * itself rather than the gate standing it down.
 */
final class WpIdpLinkTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Explicit rather than relying on `auto` resolution: `auto` resolves
		// against `class_exists( 'WP_SAML_IDP_Service_Provider' )`, and
		// another test class in this same PHPUnit process
		// (SsoLinkMechanismTest) defines that class globally at some point
		// during the run. PHP classes cannot be "undefined" once declared,
		// so this test would become order-dependent if it relied on
		// detection instead of stating the mechanism outright.
		update_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_SERVER );
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );

		// agend_apps_idp_entity_id() falls back to site_url(), unstubbed in
		// this harness, when this option is absent -- same seed
		// AccountLinkIdentityRecordTest uses for the same reason.
		update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://example.test/saml/metadata' ) );

		// Seed the sso_identity_link gate as held, mirroring
		// AccountLinkIdentityRecordTest's setUp for sso_account_link: queue
		// the /health response Agend_Apps_Key_Scopes::refresh() consumes,
		// then discard the request it recorded so it does not show up in a
		// test's own request-count assertions.
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'sso.identities.create' ) ) ) );
		Agend_Apps_Key_Scopes::refresh();
		Agend_Test_WP::$requests = array();
	}

	/**
	 * Registers a linkable WordPress user: an email `get_user_by()` can
	 * resolve, and (via `agend_apps_ensure_external_id()`, called by the
	 * function under test) a minted external id.
	 */
	private function registerUser( int $user_id, string $email, string $first_name = '', string $last_name = '' ): WP_User {
		$user             = new WP_User( $user_id );
		$user->user_email = $email;
		$user->first_name = $first_name;
		$user->last_name  = $last_name;

		$GLOBALS['agend_test_users'][] = $user;

		return $user;
	}

	/** Every request path recorded, in call order (full URL, base stripped). */
	private function requestPaths(): array {
		return array_map(
			static fn( array $request ): string => str_replace( 'https://api.example.test/v1', '', $request['url'] ),
			Agend_Test_WP::$requests
		);
	}

	// -----------------------------------------------------------------
	// Mechanism gate
	// -----------------------------------------------------------------

	#[Test]
	public function should_be_a_no_op_and_make_no_request_when_the_mechanism_is_not_server(): void {
		update_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_DISABLED );
		$this->registerUser( 1, 'a@example.test' );

		$result = \agend_apps_wp_idp_link_user( 1 );

		$this->assertSame( '', $result );
		$this->assertSame( array(), Agend_Test_WP::$requests );
		$this->assertSame( '', \agend_apps_wp_idp_link_state( 1 )['state'] );
	}

	// -----------------------------------------------------------------
	// The initial POST
	// -----------------------------------------------------------------

	#[Test]
	public function should_record_linked_state_and_both_ids_on_201(): void {
		$this->registerUser( 10, 'ada@example.test', 'Ada', 'Lovelace' );
		Agend_Test_WP::queue_response(
			201,
			array(
				'data' => array(
					'linked'     => true,
					'created'    => true,
					'user_id'    => 'supabase-10',
					'contact_id' => 'contact-10',
				),
			)
		);

		$result = \agend_apps_wp_idp_link_user( 10 );

		$this->assertSame( \AGEND_APPS_LINK_STATE_LINKED, $result );
		$this->assertSame( \AGEND_APPS_LINK_STATE_LINKED, \agend_apps_wp_idp_link_state( 10 )['state'] );
		$this->assertSame(
			array(
				'supabase_user_id' => 'supabase-10',
				'contact_id'       => 'contact-10',
			),
			\agend_apps_linked_identity_ids( 10 )
		);
	}

	#[Test]
	public function should_record_linked_state_on_the_idempotent_200(): void {
		$this->registerUser( 11, 'bea@example.test' );
		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'linked'     => true,
					'created'    => false,
					'user_id'    => 'supabase-11',
					'contact_id' => 'contact-11',
				),
			)
		);

		$result = \agend_apps_wp_idp_link_user( 11 );

		$this->assertSame( \AGEND_APPS_LINK_STATE_LINKED, $result );
		$this->assertSame(
			array(
				'supabase_user_id' => 'supabase-11',
				'contact_id'       => 'contact-11',
			),
			\agend_apps_linked_identity_ids( 11 )
		);
	}

	#[Test]
	public function should_record_pending_and_no_ids_on_202(): void {
		$this->registerUser( 12, 'cai@example.test' );
		Agend_Test_WP::queue_response(
			202,
			array(
				'data' => array(
					'status'  => 'verification_required',
					'message' => 'Check your email to confirm.',
				),
			)
		);

		$result = \agend_apps_wp_idp_link_user( 12 );

		$this->assertSame( \AGEND_APPS_LINK_STATE_PENDING, $result );
		$this->assertSame( \AGEND_APPS_LINK_STATE_PENDING, \agend_apps_wp_idp_link_state( 12 )['state'] );
		$this->assertSame(
			array(
				'supabase_user_id' => '',
				'contact_id'       => '',
			),
			\agend_apps_linked_identity_ids( 12 )
		);
	}

	// -----------------------------------------------------------------
	// Pending: poll, never re-post
	// -----------------------------------------------------------------

	/** Seeds a `pending` state old enough to be outside every backoff window. */
	private function seedStalePending( int $user_id ): void {
		update_user_meta(
			$user_id,
			'_agend_apps_link_state',
			array(
				'state'      => \AGEND_APPS_LINK_STATE_PENDING,
				'error_code' => '',
				'timestamp'  => time() - ( 2 * HOUR_IN_SECONDS ),
			)
		);
	}

	#[Test]
	public function should_poll_status_instead_of_reposting_when_already_pending(): void {
		$this->registerUser( 20, 'dee@example.test' );
		$this->seedStalePending( 20 );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'linked' => false ) ) );

		$result = \agend_apps_wp_idp_link_user( 20 );

		$this->assertSame( \AGEND_APPS_LINK_STATE_PENDING, $result );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertStringContainsString( '/sso/identities/status', $this->requestPaths()[0] );
		$this->assertStringNotContainsString( '/sso/tokens', $this->requestPaths()[0] );
		// The path assertion above is the one that matters: it proves the
		// request actually made was the status GET, not a fresh POST to
		// `/sso/identities` (which shares the `/sso/identities` prefix with
		// the status route, so a strict-equality check on the collection
		// alone would not by itself rule out a second, wrongly-added POST).
		$this->assertSame( array( '/sso/identities/status?idpEntityId=' . rawurlencode( \agend_apps_idp_entity_id() ) . '&externalId=' . rawurlencode( \agend_apps_ensure_external_id( 20 ) ) ), $this->requestPaths() );
	}

	#[Test]
	public function should_promote_pending_to_linked_when_status_reports_linked(): void {
		$this->registerUser( 21, 'eli@example.test' );
		$this->seedStalePending( 21 );
		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'linked'     => true,
					'user_id'    => 'supabase-21',
					'contact_id' => 'contact-21',
				),
			)
		);

		$result = \agend_apps_wp_idp_link_user( 21 );

		$this->assertSame( \AGEND_APPS_LINK_STATE_LINKED, $result );
		$this->assertSame( \AGEND_APPS_LINK_STATE_LINKED, \agend_apps_wp_idp_link_state( 21 )['state'] );
		$this->assertSame(
			array(
				'supabase_user_id' => 'supabase-21',
				'contact_id'       => 'contact-21',
			),
			\agend_apps_linked_identity_ids( 21 )
		);
	}

	// -----------------------------------------------------------------
	// Gateway error mapping
	// -----------------------------------------------------------------

	#[Test]
	public function should_record_conflict_on_409(): void {
		$this->registerUser( 30, 'finn@example.test' );
		Agend_Test_WP::queue_response( 409, array( 'error' => array( 'code' => 'IDENTITY_ALREADY_LINKED' ) ) );

		$result = \agend_apps_wp_idp_link_user( 30 );

		$this->assertSame( \AGEND_APPS_LINK_STATE_CONFLICT, $result );
		$state = \agend_apps_wp_idp_link_state( 30 );
		$this->assertSame( \AGEND_APPS_LINK_STATE_CONFLICT, $state['state'] );
		$this->assertSame( 'IDENTITY_ALREADY_LINKED', $state['error_code'] );
	}

	#[Test]
	public function should_record_no_contact_on_404(): void {
		$this->registerUser( 31, 'gia@example.test' );
		Agend_Test_WP::queue_response( 404, array( 'error' => array( 'code' => 'CONTACT_NOT_FOUND' ) ) );

		$result = \agend_apps_wp_idp_link_user( 31 );

		$this->assertSame( \AGEND_APPS_LINK_STATE_NO_CONTACT, $result );
		$this->assertSame( 'CONTACT_NOT_FOUND', \agend_apps_wp_idp_link_state( 31 )['error_code'] );
	}

	#[Test]
	public function should_record_forbidden_on_403_connection_create_forbidden(): void {
		$this->registerUser( 32, 'hux@example.test' );
		Agend_Test_WP::queue_response( 403, array( 'error' => array( 'code' => 'CONNECTION_CREATE_FORBIDDEN' ) ) );

		$result = \agend_apps_wp_idp_link_user( 32 );

		$this->assertSame( \AGEND_APPS_LINK_STATE_FORBIDDEN, $result );
		$this->assertSame( 'CONNECTION_CREATE_FORBIDDEN', \agend_apps_wp_idp_link_state( 32 )['error_code'] );
	}

	#[Test]
	public function should_leave_a_retryable_error_state_on_a_5xx(): void {
		$this->registerUser( 33, 'ivy@example.test' );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'code' => 'INTERNAL_ERROR' ) ) );

		$result = \agend_apps_wp_idp_link_user( 33 );

		$this->assertSame( \AGEND_APPS_LINK_STATE_ERROR, $result );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ERROR, \agend_apps_wp_idp_link_state( 33 )['state'] );
	}

	// -----------------------------------------------------------------
	// Throttle
	// -----------------------------------------------------------------

	#[Test]
	public function should_suppress_an_immediate_second_attempt(): void {
		$this->registerUser( 40, 'jax@example.test' );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'code' => 'INTERNAL_ERROR' ) ) );

		$first  = \agend_apps_wp_idp_link_user( 40 );
		$second = \agend_apps_wp_idp_link_user( 40 );

		$this->assertSame( \AGEND_APPS_LINK_STATE_ERROR, $first );
		$this->assertSame( \AGEND_APPS_LINK_STATE_ERROR, $second );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// Non-blocking guarantee
	// -----------------------------------------------------------------

	#[Test]
	public function should_never_let_a_thrown_exception_propagate_out_of_the_wp_login_handler(): void {
		$user = $this->registerUser( 50, 'kim@example.test' );

		add_filter(
			'agend_apps_idp_entity_id',
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);

		// If the exception escapes either agend_apps_wp_idp_link_user()'s own
		// try/catch or the handler's outer one, PHPUnit reports this test as
		// an ERROR (an uncaught exception), not a failed assertion -- so
		// simply reaching the assertion below is the proof.
		\agend_apps_wp_idp_handle_login( 'kim', $user );

		$this->assertSame( \AGEND_APPS_LINK_STATE_ERROR, \agend_apps_wp_idp_link_state( 50 )['state'] );
	}

	#[Test]
	public function should_never_let_a_transport_failure_propagate_and_should_leave_an_error_state(): void {
		$user = $this->registerUser( 51, 'lou@example.test' );
		// No canned response queued: the stub's default wp_remote_request()
		// behaviour returns a 200 with an incrementing counter body, which is
		// not what is being exercised here. Instead, drive an actual WP_Error
		// return from the client the same way ApiResponseContractTest does
		// for a malformed/absent body: queue a non-2xx status whose body
		// cannot be decoded as the expected envelope.
		Agend_Test_WP::queue_response( 500, '' );

		\agend_apps_wp_idp_handle_login( 'lou', $user );

		$this->assertSame( \AGEND_APPS_LINK_STATE_ERROR, \agend_apps_wp_idp_link_state( 51 )['state'] );
	}

	/**
	 * Stamps a link state whose backoff window has already elapsed, so a
	 * test exercises the branch it names rather than the throttle. Writing
	 * the meta directly is deliberate: `agend_apps_wp_idp_record_link_state()`
	 * always stamps `time()`, which is the behaviour under test elsewhere.
	 */
	private function recordExpiredState( int $user_id, string $state ): void {
		update_user_meta(
			$user_id,
			\AGEND_APPS_LINK_STATE_META,
			array(
				'state'      => $state,
				'error_code' => '',
				'timestamp'  => time() - ( 2 * DAY_IN_SECONDS ),
			)
		);
	}

	// -----------------------------------------------------------------
	// The lazy pending poll (`init`)
	// -----------------------------------------------------------------

	/**
	 * The reason this hook exists: a withheld member clicks the emailed
	 * confirm link and returns to the site with their WordPress session
	 * still live, so `wp_login` never fires again. Without the `init` poll
	 * they would have to log out and back in before the site noticed the
	 * link they were just told to complete.
	 */
	#[Test]
	public function should_promote_a_pending_member_to_linked_on_init_without_a_fresh_login(): void {
		$this->registerUser( 60, 'nell@example.test' );
		$this->recordExpiredState( 60, \AGEND_APPS_LINK_STATE_PENDING );
		$GLOBALS['agend_test_current_user_id'] = 60;

		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'linked'     => true,
					'user_id'    => 'supabase-60',
					'contact_id' => 'contact-60',
				),
			)
		);

		\agend_apps_wp_idp_maybe_poll_pending();

		$this->assertSame( \AGEND_APPS_LINK_STATE_LINKED, \agend_apps_wp_idp_link_state( 60 )['state'] );

		// Exactly one call, and it is the read-only status GET, never the
		// identities POST: re-posting would rotate the member's pending
		// confirm token and could email them again.
		$paths = $this->requestPaths();
		$this->assertCount( 1, $paths );
		$this->assertStringStartsWith( '/sso/identities/status?', $paths[0] );
	}

	#[Test]
	public function should_make_no_request_on_init_for_a_signed_out_visitor(): void {
		$GLOBALS['agend_test_current_user_id'] = 0;

		\agend_apps_wp_idp_maybe_poll_pending();

		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_make_no_request_on_init_for_a_member_who_is_not_pending(): void {
		$this->registerUser( 61, 'otto@example.test' );
		\agend_apps_wp_idp_record_link_state( 61, \AGEND_APPS_LINK_STATE_LINKED );
		$GLOBALS['agend_test_current_user_id'] = 61;

		\agend_apps_wp_idp_maybe_poll_pending();

		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_make_no_request_on_init_during_cron(): void {
		$this->registerUser( 62, 'pia@example.test' );
		$this->recordExpiredState( 62, \AGEND_APPS_LINK_STATE_PENDING );
		$GLOBALS['agend_test_current_user_id'] = 62;
		$GLOBALS['agend_test_doing_cron']      = true;

		\agend_apps_wp_idp_maybe_poll_pending();

		unset( $GLOBALS['agend_test_doing_cron'] );

		$this->assertSame( array(), Agend_Test_WP::$requests );
	}
}
