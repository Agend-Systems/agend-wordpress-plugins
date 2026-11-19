<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Key_Scopes;
use Agend_Apps_Member_Session;
use Agend_Apps_Settings;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

/**
 * The member access state, mode by mode: what it says when a visitor is signed
 * out, when a member bearer resolves, and why not when none does.
 *
 * Each case runs in its own process because the fixture declares the bearer
 * resolver and the SSO status read, which other tests in this suite load for
 * real.
 */
#[RunTestsInSeparateProcesses]
final class MemberAccessStateTest extends TestCase {

	private const USER_ID = 42;

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-link.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-diagnostics.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-member-session.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/account-link-state.php';
		require_once __DIR__ . '/fixtures/member-access-stubs.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-access-state.php';

		agend_ma_test_reset();
		agend_apps_member_access_forget();
		update_option( 'agend_apps_account_slug', 'wdaa' );
		update_option( 'agend_apps_root_url_for_tests', 'https://api.example.test' );
	}

	// -----------------------------------------------------------------
	// Scene setting
	// -----------------------------------------------------------------

	private function mode( string $mode ): void {
		update_option( 'agend_apps_member_auth_mode', $mode );
	}

	private function signIn(): void {
		$GLOBALS['agend_test_current_user_id'] = self::USER_ID;
	}

	/**
	 * Makes the bearer resolver return a token, as a live session or a minted
	 * token would.
	 */
	private function withBearer( string $token = 'member-token' ): void {
		agend_ma_test_bearer( $token );
	}

	/**
	 * Gives the user the identity a mode mints a token from.
	 */
	private function withExternalId(): void {
		update_user_meta( self::USER_ID, 'imk_membership_number', 'M-1001' );
		update_user_meta( self::USER_ID, AGEND_APPS_EXTERNAL_ID_META, 'guid-1001' );
	}

	/**
	 * The key holds, or lacks, what a member token needs.
	 */
	private function keyCanMint( bool $can ): void {
		$scopes = $can ? array( 'sso.identities.read', 'sso.tokens.create', 'crm.contacts.browse' ) : array( 'crm.contacts.browse' );

		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array() ) ) );
	}

	private function recordLinkState( string $state ): void {
		if ( '' !== $state ) {
			agend_apps_wp_idp_record_link_state( self::USER_ID, $state );
		}
	}

	private function storeSession(): void {
		Agend_Apps_Member_Session::store(
			self::USER_ID,
			array(
				'access_token'  => 'access',
				'refresh_token' => 'refresh',
				'expires_at'    => time() + 3600,
			)
		);
	}

	/**
	 * The shared keys of an answer, so a failure reads as one comparison.
	 *
	 * @param array $access An access answer.
	 * @return array{state: string, reason: string, action: string}
	 */
	private function verdict( array $access ): array {
		return array(
			'state'  => $access['state'],
			'reason' => $access['reason'],
			'action' => $access['action'],
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function modes(): array {
		return array(
			'credentials' => array( 'credentials' ),
			'sso'         => array( 'sso' ),
			'wordpress'   => array( 'wordpress' ),
		);
	}

	// -----------------------------------------------------------------
	// Every mode
	// -----------------------------------------------------------------

	#[Test]
	#[DataProvider( 'modes' )]
	public function should_say_signed_out_without_probing_for_a_bearer(string $mode): void {
		$this->mode( $mode );
		$this->withBearer();

		$access = agend_apps_member_access_state( array( 'return_url' => 'https://site.test/my-membership/' ) );

		self::assertSame( array( 'state' => 'signed_out', 'reason' => '', 'action' => 'sign_in' ), $this->verdict( $access ) );
		self::assertFalse( $access['signed_in'] );
		self::assertFalse( $access['linked'] );
		self::assertSame( $mode, $access['mode'] );
		self::assertSame( 0, $GLOBALS['agend_ma_test']['bearer_calls'], 'a signed-out visitor never runs the token resolver, which can mint' );
		self::assertSame( 0, $GLOBALS['agend_ma_test']['sso_calls'] );
	}

	#[Test]
	#[DataProvider( 'modes' )]
	public function should_send_a_signed_out_visitor_to_sign_in_and_back_to_the_page(string $mode): void {
		$this->mode( $mode );

		$access = agend_apps_member_access_state( array( 'return_url' => 'https://site.test/my-membership/' ) );

		self::assertStringContainsString( 'wp-login.php', $access['sign_in_url'] );
		self::assertStringContainsString( 'my-membership', $access['sign_in_url'] );
	}

	#[Test]
	#[DataProvider( 'modes' )]
	public function should_be_ready_when_a_bearer_resolves_whatever_the_mode(string $mode): void {
		$this->mode( $mode );
		$this->signIn();
		$this->withBearer();

		$access = agend_apps_member_access_state();

		self::assertSame( array( 'state' => 'ready', 'reason' => '', 'action' => 'none' ), $this->verdict( $access ) );
		self::assertTrue( $access['signed_in'] );
		self::assertTrue( $access['linked'] );
		self::assertSame( '', $access['detail'] );
		self::assertSame( 0, $GLOBALS['agend_ma_test']['sso_calls'], 'no link lookup is needed when a bearer is already there' );
	}

	#[Test]
	#[DataProvider( 'modes' )]
	public function should_never_hand_back_the_bearer(string $mode): void {
		$this->mode( $mode );
		$this->signIn();
		$this->withBearer( 'secret-member-token' );

		self::assertStringNotContainsString( 'secret-member-token', (string) wp_json_encode( agend_apps_member_access_state() ) );
	}

	#[Test]
	#[DataProvider( 'modes' )]
	public function should_resolve_the_bearer_once_per_request(string $mode): void {
		$this->mode( $mode );
		$this->signIn();
		$this->withBearer();

		agend_apps_member_access_state();
		agend_apps_member_access_state();
		agend_apps_member_access_state();

		self::assertSame( 1, $GLOBALS['agend_ma_test']['bearer_calls'], 'the resolver can mint a token and wait for another request\'s mint' );
	}

	#[Test]
	public function should_probe_again_once_what_it_holds_is_dropped(): void {
		$this->signIn();
		$this->withBearer();

		agend_apps_member_access_state();
		agend_apps_member_access_forget();
		agend_apps_member_access_state();

		self::assertSame( 2, $GLOBALS['agend_ma_test']['bearer_calls'] );
	}

	#[Test]
	#[DataProvider( 'modes' )]
	public function should_hold_the_bearer_answer_per_user(string $mode): void {
		$this->mode( $mode );
		$this->signIn();
		$this->withBearer();
		agend_apps_member_access_state();

		$GLOBALS['agend_test_current_user_id'] = 77;
		agend_apps_member_access_state();

		self::assertSame( 2, $GLOBALS['agend_ma_test']['bearer_calls'], 'one user\'s answer is never another\'s' );
	}

	#[Test]
	#[DataProvider( 'modes' )]
	public function should_carry_every_key_in_every_answer(string $mode): void {
		$this->mode( $mode );
		$expected = array( 'state', 'reason', 'mode', 'signed_in', 'linked', 'action', 'retry_after', 'sign_in_url', 'initiate_url', 'detail' );

		$out = agend_apps_member_access_state();
		$keys = array_keys( $out );
		sort( $keys );
		sort( $expected );
		self::assertSame( $expected, $keys, 'signed out' );

		$this->signIn();
		$keys = array_keys( agend_apps_member_access_state() );
		sort( $keys );
		self::assertSame( $expected, $keys, 'signed in, no bearer' );
	}

	// -----------------------------------------------------------------
	// credentials
	// -----------------------------------------------------------------

	#[Test]
	public function should_ask_a_credentials_user_with_no_stored_session_to_sign_in_again(): void {
		$this->mode( 'credentials' );
		$this->signIn();

		$access = agend_apps_member_access_state();

		self::assertSame( array( 'state' => 'not_linked', 'reason' => 'session_missing', 'action' => 'sign_in_again' ), $this->verdict( $access ) );
		self::assertFalse( $access['linked'] );
		self::assertTrue( $access['signed_in'] );
	}

	#[Test]
	public function should_not_trust_the_account_link_helper_which_calls_every_credentials_user_linked(): void {
		$this->mode( 'credentials' );
		$this->signIn();

		// The helper's answer for a signed-in user with no session at all.
		self::assertSame( 'linked', agend_apps_account_link_state( self::USER_ID )['state'] );

		// The access state does not take it.
		self::assertSame( 'session_missing', agend_apps_member_access_state()['reason'] );
		self::assertSame( 0, $GLOBALS['agend_ma_test']['sso_calls'] );
	}

	#[Test]
	public function should_call_a_credentials_user_with_a_session_and_a_bearer_ready(): void {
		$this->mode( 'credentials' );
		$this->signIn();
		$this->storeSession();
		$this->withBearer();

		self::assertSame( 'ready', agend_apps_member_access_state()['state'] );
	}

	#[Test]
	public function should_call_a_session_that_yields_no_bearer_unavailable_not_unlinked(): void {
		$this->mode( 'credentials' );
		$this->signIn();
		$this->storeSession();

		self::assertSame(
			array( 'state' => 'unavailable', 'reason' => 'token_unavailable', 'action' => 'retry' ),
			$this->verdict( agend_apps_member_access_state() )
		);
	}

	#[Test]
	public function should_make_no_link_lookup_in_credentials_mode(): void {
		$this->mode( 'credentials' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( true );

		agend_apps_member_access_state();

		self::assertSame( 0, $GLOBALS['agend_ma_test']['sso_calls'] );
	}

	// -----------------------------------------------------------------
	// wordpress
	// -----------------------------------------------------------------

	#[Test]
	public function should_say_no_member_record_for_a_wordpress_user_with_no_external_id(): void {
		$this->mode( 'wordpress' );
		$this->signIn();
		$this->keyCanMint( true );

		self::assertSame(
			array( 'state' => 'no_member_record', 'reason' => 'no_external_id', 'action' => 'join' ),
			$this->verdict( agend_apps_member_access_state() )
		);
	}

	#[Test]
	public function should_report_a_key_that_cannot_mint_as_not_configured_rather_than_not_linked(): void {
		$this->mode( 'wordpress' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( false );
		$this->recordLinkState( 'asserted' );

		self::assertSame(
			array( 'state' => 'not_configured', 'reason' => 'missing_scope', 'action' => 'retry' ),
			$this->verdict( agend_apps_member_access_state() ),
			'the token worker returns nothing without both scopes, so every member would otherwise look merely unlinked'
		);
	}

	#[Test]
	public function should_report_not_configured_while_the_keys_scopes_are_not_yet_known(): void {
		$this->mode( 'wordpress' );
		$this->signIn();
		$this->withExternalId();

		self::assertSame( 'not_configured', agend_apps_member_access_state()['state'] );
	}

	/**
	 * @return array<string, array{string, string, string, string, string}>
	 */
	public static function wordpressLinkStates(): array {
		return array(
			'never started' => array( '', 'connecting', 'link_not_started', 'retry', '' ),
			'asserted'      => array( 'asserted', 'connecting', 'link_asserted', 'retry', '' ),
			'errored'       => array( 'error', 'connecting', 'link_error', 'retry', '' ),
			'pending email' => array( 'pending', 'not_linked', 'link_pending', 'check_email', '' ),
			'conflict'      => array( 'conflict', 'not_linked', 'link_conflict', 'contact', '' ),
			'no contact'    => array( 'no_contact', 'no_member_record', 'link_no_contact', 'join', '' ),
			'forbidden'     => array( 'forbidden', 'not_configured', 'link_forbidden', 'contact', '' ),
			'not approved'  => array( 'pending_approval', 'not_configured', 'link_pending_approval', 'contact', '' ),
			'linked'        => array( 'linked', 'unavailable', 'token_unavailable', 'retry', '' ),
		);
	}

	#[Test]
	#[DataProvider( 'wordpressLinkStates' )]
	public function should_read_the_recorded_link_state_in_wordpress_mode(string $recorded, string $state, string $reason, string $action): void {
		$this->mode( 'wordpress' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( true );
		$this->recordLinkState( $recorded );

		self::assertSame(
			array( 'state' => $state, 'reason' => $reason, 'action' => $action ),
			$this->verdict( agend_apps_member_access_state() )
		);
	}

	#[Test]
	public function should_poll_a_connecting_member_again_in_a_few_seconds(): void {
		$this->mode( 'wordpress' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( true );

		self::assertSame( 3, agend_apps_member_access_state()['retry_after'] );
	}

	#[Test]
	public function should_never_call_the_sso_status_endpoint_or_build_a_link_in_wordpress_mode(): void {
		$this->mode( 'wordpress' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( true );
		$GLOBALS['agend_ma_test']['sso_status'] = array( 'success' => true, 'data' => array( 'linked' => false ) );

		foreach ( array( '', 'asserted', 'error', 'pending', 'conflict', 'linked' ) as $recorded ) {
			agend_apps_member_access_forget();
			$this->recordLinkState( $recorded );
			$access = agend_apps_member_access_state( array( 'return_url' => 'https://site.test/' ) );

			self::assertSame( '', $access['initiate_url'], $recorded );
		}

		self::assertSame( 0, $GLOBALS['agend_ma_test']['sso_calls'], 'WordPress is the identity provider here, so the endpoint does not apply' );
	}

	#[Test]
	public function should_ask_nothing_of_the_link_state_when_a_wordpress_bearer_already_resolves(): void {
		$this->mode( 'wordpress' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( true );
		$this->recordLinkState( 'conflict' );
		$this->withBearer();

		self::assertSame( 'ready', agend_apps_member_access_state()['state'], 'the bearer is the truth; a stale recorded state does not outvote it' );
	}

	// -----------------------------------------------------------------
	// sso
	// -----------------------------------------------------------------

	#[Test]
	public function should_say_no_member_record_for_an_sso_user_with_no_external_id_without_a_lookup(): void {
		$this->mode( 'sso' );
		$this->signIn();
		$this->keyCanMint( true );

		self::assertSame(
			array( 'state' => 'no_member_record', 'reason' => 'no_external_id', 'action' => 'join' ),
			$this->verdict( agend_apps_member_access_state() )
		);
		self::assertSame( 0, $GLOBALS['agend_ma_test']['sso_calls'] );
	}

	#[Test]
	public function should_not_use_the_minted_guid_in_sso_mode(): void {
		$this->mode( 'sso' );
		$this->signIn();
		$this->keyCanMint( true );
		update_user_meta( self::USER_ID, AGEND_APPS_EXTERNAL_ID_META, 'guid-only' );

		self::assertSame( 'no_external_id', agend_apps_member_access_state()['reason'], 'the GUID fallback is a wordpress mode rule' );
	}

	#[Test]
	public function should_report_a_key_that_cannot_mint_as_not_configured_in_sso_mode_without_a_lookup(): void {
		$this->mode( 'sso' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( false );

		self::assertSame(
			array( 'state' => 'not_configured', 'reason' => 'missing_scope', 'action' => 'retry' ),
			$this->verdict( agend_apps_member_access_state() )
		);
		self::assertSame( 0, $GLOBALS['agend_ma_test']['sso_calls'] );
	}

	#[Test]
	public function should_offer_an_unlinked_sso_member_the_connect_button_and_read_the_status_once(): void {
		$this->mode( 'sso' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( true );

		$access = agend_apps_member_access_state( array( 'return_url' => 'https://site.test/my-membership/' ) );

		self::assertSame(
			array( 'state' => 'not_linked', 'reason' => 'sso_unlinked', 'action' => 'connect' ),
			$this->verdict( $access )
		);
		self::assertStringStartsWith( 'https://api.example.test/api/auth/sso/wdaa/initiate', $access['initiate_url'] );
		self::assertStringContainsString( 'my-membership', $access['initiate_url'] );
		self::assertSame( 1, $GLOBALS['agend_ma_test']['sso_calls'] );
	}

	#[Test]
	public function should_ask_an_unlinked_sso_member_to_get_in_touch_when_no_link_can_be_built(): void {
		$this->mode( 'sso' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( true );
		update_option( 'agend_apps_account_slug', '' );

		$access = agend_apps_member_access_state();

		self::assertSame( 'sso_unlinked', $access['reason'] );
		self::assertSame( 'contact', $access['action'] );
		self::assertSame( '', $access['initiate_url'] );
	}

	#[Test]
	public function should_call_an_sso_member_linked_at_agend_but_without_a_token_unavailable(): void {
		$this->mode( 'sso' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( true );
		$GLOBALS['agend_ma_test']['sso_status'] = array( 'success' => true, 'data' => array( 'linked' => true, 'user_id' => 'u-1' ) );

		self::assertSame(
			array( 'state' => 'unavailable', 'reason' => 'token_unavailable', 'action' => 'retry' ),
			$this->verdict( agend_apps_member_access_state() )
		);
	}

	#[Test]
	public function should_call_a_failed_sso_status_read_unavailable(): void {
		$this->mode( 'sso' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( true );
		$GLOBALS['agend_ma_test']['sso_status'] = new WP_Error( 'agend_api_error', 'down', array( 'status_code' => 502 ) );

		self::assertSame(
			array( 'state' => 'unavailable', 'reason' => 'link_check_failed', 'action' => 'retry' ),
			$this->verdict( agend_apps_member_access_state() )
		);
	}

	#[Test]
	public function should_not_read_the_sso_status_when_a_bearer_resolves(): void {
		$this->mode( 'sso' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( true );
		$this->withBearer();

		agend_apps_member_access_state();

		self::assertSame( 0, $GLOBALS['agend_ma_test']['sso_calls'] );
	}

	// -----------------------------------------------------------------
	// Administrator detail
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{string, string}> Mode, and a scene key.
	 */
	public static function faults(): array {
		return array(
			'credentials, no session' => array( 'credentials', 'no_session' ),
			'sso, unlinked'           => array( 'sso', 'sso_unlinked' ),
			'sso, key cannot mint'    => array( 'sso', 'cannot_mint' ),
			'wordpress, conflict'     => array( 'wordpress', 'conflict' ),
			'wordpress, no id'        => array( 'wordpress', 'no_id' ),
		);
	}

	private function scene( string $mode, string $scene ): void {
		$this->mode( $mode );
		$this->signIn();

		switch ( $scene ) {
			case 'no_session':
				break;
			case 'sso_unlinked':
				$this->withExternalId();
				$this->keyCanMint( true );
				break;
			case 'cannot_mint':
				$this->withExternalId();
				$this->keyCanMint( false );
				break;
			case 'conflict':
				$this->withExternalId();
				$this->keyCanMint( true );
				$this->recordLinkState( 'conflict' );
				break;
			case 'no_id':
				$this->keyCanMint( true );
				break;
		}
	}

	#[Test]
	#[DataProvider( 'faults' )]
	public function should_give_an_administrator_the_reason_in_words(string $mode, string $scene): void {
		$this->scene( $mode, $scene );

		$access = agend_apps_member_access_state();

		self::assertNotSame( '', $access['detail'], 'an administrator is told what is wrong' );
	}

	#[Test]
	#[DataProvider( 'faults' )]
	public function should_give_nobody_else_the_detail(string $mode, string $scene): void {
		$this->scene( $mode, $scene );
		$GLOBALS['agend_test_current_user_can']['manage_options'] = false;

		$access = agend_apps_member_access_state();

		self::assertSame( '', $access['detail'] );
		self::assertNotSame( '', $access['reason'], 'the machine reason is still there for the caller to use, and to withhold' );
	}

	#[Test]
	public function should_use_the_wording_already_written_for_administrators_for_a_recorded_link_state(): void {
		$this->mode( 'wordpress' );
		$this->signIn();
		$this->withExternalId();
		$this->keyCanMint( true );
		$this->recordLinkState( 'conflict' );

		self::assertSame(
			agend_apps_wp_idp_link_state_guidance( 'conflict' )['guidance'],
			agend_apps_member_access_state()['detail']
		);
	}

	// -----------------------------------------------------------------
	// What a member may be told
	// -----------------------------------------------------------------

	/**
	 * Every state and action the resolver and the classifier can produce.
	 *
	 * @return array<string, array{array}>
	 */
	public static function everyAnswer(): array {
		$answers = array();

		foreach ( array(
			array( 'signed_out', 'sign_in', '' ),
			array( 'connecting', 'retry', 'link_asserted' ),
			array( 'not_linked', 'sign_in_again', 'session_missing' ),
			array( 'not_linked', 'sign_in_again', 'session_expired' ),
			array( 'not_linked', 'connect', 'sso_unlinked' ),
			array( 'not_linked', 'check_email', 'link_pending' ),
			array( 'not_linked', 'contact', 'link_conflict' ),
			array( 'not_configured', 'retry', 'missing_scope' ),
			array( 'not_configured', 'retry', 'app_not_allowed' ),
			array( 'not_configured', 'contact', 'link_forbidden' ),
			array( 'not_configured', 'contact', 'link_pending_approval' ),
			array( 'no_member_record', 'join', 'no_external_id' ),
			array( 'no_member_record', 'join', 'contact_not_found' ),
			array( 'unavailable', 'retry', 'token_unavailable' ),
			array( 'unavailable', 'retry', 'busy' ),
			array( 'unavailable', 'retry', 'gateway_error' ),
		) as $row ) {
			$answers[ $row[0] . ' / ' . $row[2] ] = array( array( 'state' => $row[0], 'action' => $row[1], 'reason' => $row[2] ) );
		}

		return $answers;
	}

	#[Test]
	#[DataProvider( 'everyAnswer' )]
	public function should_tell_a_member_something_plain_and_never_the_reason(array $access): void {
		$message = agend_apps_member_access_message( $access );
		$text    = $message['title'] . ' ' . $message['message'];

		self::assertNotSame( '', trim( $message['title'] ) );
		self::assertNotSame( '', trim( $message['message'] ) );
		self::assertDoesNotMatchRegularExpression(
			'/\b(scopes?|permissions?|sso|saml|external id|bearer|token|gateway|api|key|session|conflict|forbidden|approval|wordpress|credentials)\b/i',
			$text,
			'a member is told nothing they cannot act on, and nothing about how the site is built'
		);
		self::assertStringNotContainsString( '—', $text );
	}

	#[Test]
	public function should_give_a_member_something_to_do_for_each_state_they_can_act_on(): void {
		self::assertStringContainsString( 'sign in again', strtolower( agend_apps_member_access_message( array( 'state' => 'not_linked', 'action' => 'sign_in_again' ) )['title'] ) );
		self::assertStringContainsString( 'connect', strtolower( agend_apps_member_access_message( array( 'state' => 'not_linked', 'action' => 'connect' ) )['title'] ) );
		self::assertStringContainsString( 'email', strtolower( agend_apps_member_access_message( array( 'state' => 'not_linked', 'action' => 'check_email' ) )['title'] ) );
		self::assertStringContainsString( 'contact us', strtolower( agend_apps_member_access_message( array( 'state' => 'not_linked', 'action' => 'contact' ) )['message'] ) );
		self::assertStringContainsString( 'join', strtolower( agend_apps_member_access_message( array( 'state' => 'no_member_record' ) )['message'] ) );
	}

	#[Test]
	public function should_word_a_site_fault_as_unavailable_or_contact_us_never_as_a_setup_problem(): void {
		$retry   = agend_apps_member_access_message( array( 'state' => 'not_configured', 'action' => 'retry' ) );
		$contact = agend_apps_member_access_message( array( 'state' => 'not_configured', 'action' => 'contact' ) );

		self::assertStringContainsString( 'try again', strtolower( $retry['message'] ) );
		self::assertStringContainsString( 'contact us', strtolower( $contact['message'] ) );
	}

	// -----------------------------------------------------------------
	// A read that failed
	// -----------------------------------------------------------------

	private function ready( string $mode = 'credentials' ): array {
		$this->mode( $mode );
		$this->signIn();
		$this->withBearer();

		return agend_apps_member_access_state();
	}

	private function failure( int $status, string $code = 'agend_api_error', array $extra = array() ): WP_Error {
		return new WP_Error( $code, 'The gateway said no.', array( 'status_code' => $status ) + $extra );
	}

	#[Test]
	public function should_read_a_404_as_no_member_record_in_every_mode(): void {
		foreach ( array( 'credentials', 'sso', 'wordpress' ) as $mode ) {
			$access = agend_apps_member_access_from_error( $this->failure( 404 ), $this->ready( $mode ) );

			self::assertSame( array( 'state' => 'no_member_record', 'reason' => 'contact_not_found', 'action' => 'join' ), $this->verdict( $access ), $mode );
		}
	}

	#[Test]
	public function should_read_a_401_as_sign_in_again_in_credentials_mode(): void {
		$access = agend_apps_member_access_from_error( $this->failure( 401 ), $this->ready( 'credentials' ) );

		self::assertSame( array( 'state' => 'not_linked', 'reason' => 'session_expired', 'action' => 'sign_in_again' ), $this->verdict( $access ) );
	}

	#[Test]
	public function should_read_a_401_as_retry_in_the_modes_where_the_site_mints_the_token(): void {
		foreach ( array( 'sso', 'wordpress' ) as $mode ) {
			$access = agend_apps_member_access_from_error( $this->failure( 401 ), $this->ready( $mode ) );

			self::assertSame( array( 'state' => 'unavailable', 'reason' => 'session_expired', 'action' => 'retry' ), $this->verdict( $access ), $mode );
		}
	}

	#[Test]
	public function should_read_the_clients_own_scope_refusal_before_the_status_it_carries(): void {
		$refusal = new WP_Error(
			'agend_apps_scope_missing',
			'Not sent: the API key lacks the required scope: crm.contacts.browse.',
			array( 'status_code' => 403, 'missing' => array( 'crm.contacts.browse' ), 'app' => 'crm' )
		);

		$access = agend_apps_member_access_from_error( $refusal, $this->ready() );

		self::assertSame( array( 'state' => 'not_configured', 'reason' => 'missing_scope', 'action' => 'retry' ), $this->verdict( $access ) );
		self::assertStringContainsString( 'crm.contacts.browse', $access['detail'], 'an administrator is told which scope' );
	}

	#[Test]
	public function should_not_read_a_missing_scope_as_an_expired_session(): void {
		// A scope missing on a /me route reaches the caller as a 401 after the
		// client's unattended retry; the client's own code says what it was.
		$refusal = new WP_Error( 'agend_apps_scope_missing', 'x', array( 'status_code' => 401, 'missing' => array( 'crm.memberships.browse' ) ) );

		self::assertSame( 'missing_scope', agend_apps_member_access_from_error( $refusal, $this->ready( 'credentials' ) )['reason'] );
	}

	#[Test]
	public function should_read_an_app_the_key_may_not_use_as_not_configured(): void {
		$refusal = new WP_Error( 'agend_apps_app_not_allowed', 'x', array( 'status_code' => 403, 'app' => 'crm' ) );

		self::assertSame( array( 'state' => 'not_configured', 'reason' => 'app_not_allowed', 'action' => 'retry' ), $this->verdict( agend_apps_member_access_from_error( $refusal, $this->ready() ) ) );
	}

	#[Test]
	public function should_read_a_429_and_the_clients_local_limit_as_busy_with_a_wait(): void {
		$live  = agend_apps_member_access_from_error( $this->failure( 429 ), $this->ready() );
		$local = agend_apps_member_access_from_error( $this->failure( 429, 'agend_apps_rate_limited', array( 'reset' => (string) ( time() + 20 ) ) ), $this->ready() );

		self::assertSame( array( 'state' => 'unavailable', 'reason' => 'busy', 'action' => 'retry' ), $this->verdict( $live ) );
		self::assertSame( 30, $live['retry_after'], 'a wait is always given' );
		self::assertGreaterThanOrEqual( 19, $local['retry_after'] );
		self::assertLessThanOrEqual( 21, $local['retry_after'] );
	}

	#[Test]
	public function should_cap_how_long_a_member_is_asked_to_wait(): void {
		$access = agend_apps_member_access_from_error( $this->failure( 429, 'agend_apps_rate_limited', array( 'reset' => (string) ( time() + 7200 ) ) ), $this->ready() );

		self::assertSame( 300, $access['retry_after'] );
	}

	#[Test]
	public function should_read_anything_else_as_unavailable(): void {
		foreach ( array( $this->failure( 500 ), $this->failure( 502 ), $this->failure( 403 ), new WP_Error( 'http_request_failed', 'timeout' ), 'not an error', null ) as $error ) {
			$access = agend_apps_member_access_from_error( $error, $this->ready() );

			self::assertSame( array( 'state' => 'unavailable', 'reason' => 'gateway_error', 'action' => 'retry' ), $this->verdict( $access ) );
		}
	}

	#[Test]
	public function should_keep_who_the_visitor_is_when_a_read_fails(): void {
		$ready  = $this->ready( 'sso' );
		$access = agend_apps_member_access_from_error( $this->failure( 404 ), $ready );

		self::assertTrue( $access['signed_in'] );
		self::assertSame( 'sso', $access['mode'] );
		self::assertSame( $ready['sign_in_url'], $access['sign_in_url'] );
	}

	#[Test]
	public function should_not_call_a_member_linked_once_a_read_says_there_is_no_record(): void {
		$access = agend_apps_member_access_from_error( $this->failure( 404 ), $this->ready() );

		// Ready meant a bearer existed; it did not mean Agend knew the member.
		self::assertTrue( $this->ready()['linked'] );
		self::assertSame( 'no_member_record', $access['state'] );
	}

	#[Test]
	public function should_give_the_classified_error_detail_to_administrators_only(): void {
		$ready = $this->ready();
		self::assertNotSame( '', agend_apps_member_access_from_error( $this->failure( 404 ), $ready )['detail'] );

		$GLOBALS['agend_test_current_user_can']['manage_options'] = false;
		self::assertSame( '', agend_apps_member_access_from_error( $this->failure( 404 ), $ready )['detail'] );
	}

	#[Test]
	public function should_use_the_mode_the_site_is_in(): void {
		$this->mode( 'wordpress' );
		self::assertSame( 'wordpress', agend_apps_member_access_mode() );

		$this->mode( 'sso' );
		self::assertSame( 'sso', agend_apps_member_access_mode() );

		$this->mode( 'something else' );
		self::assertSame( Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS, agend_apps_member_access_mode() );
	}
}
