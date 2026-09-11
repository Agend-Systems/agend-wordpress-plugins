<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Member_Session;
use Agend_Apps_Token_Worker;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sso.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-member-session.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-membership-sync.php';

/**
 * `agend_apps_member_sync_membership_meta()` used to hard-gate on
 * `Agend_Apps_Member_Session::has_session()`, which only ever holds a value
 * in credentials sign-in mode. On an SSO-mode site the bearer comes from
 * `Agend_Apps_Token_Worker` instead, so the guard silently skipped every
 * sync. It now discovers whether a user has a resolvable identity by
 * impersonating them and calling `agend_apps_get_bearer_token()` — the same
 * filter chain every gateway call goes through — so it works under whichever
 * provider is active without needing to know which one that is.
 */
final class MembershipSyncTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// The token worker's mint is gated on the sso_account_link optional
		// feature (SPEC-CORE-20260908 scope-gated features) holding
		// sso.identities.read + sso.tokens.create. Seeded as held here,
		// following AccountLinkIdentityRecordTest's convention, so this file's
		// own behaviour is unaffected by that gate regardless of which other
		// test files (KeyScopesTest/OptionalFeaturesTest exercise the gate
		// itself) happened to load records/features.php earlier in the run;
		// class_exists() guards a run where it never loaded at all.
		if ( class_exists( '\Agend_Apps_Key_Scopes' ) && function_exists( 'agend_apps_verify_api_key' ) ) {
			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'sso.identities.read', 'sso.tokens.create' ) ) ) );
			\Agend_Apps_Key_Scopes::refresh();
			Agend_Test_WP::$requests = array();
		}
	}

	private function membershipsResponse(): array {
		return array(
			'data' => array(
				array(
					'tier_id'     => 't1',
					'status'      => 'active',
					'expiry_date' => '2027-01-01',
					'created_at'  => '2026-01-01T00:00:00Z',
				),
			),
		);
	}

	#[Test]
	public function should_return_false_and_make_no_gateway_call_when_no_bearer_resolves(): void {
		$GLOBALS['agend_test_current_user_id'] = 0;

		$result = agend_apps_member_sync_membership_meta( 42 );

		$this->assertFalse( $result );
		$this->assertSame( array(), Agend_Test_WP::$requests );
		$this->assertSame( 0, get_current_user_id() );
	}

	#[Test]
	public function should_sync_using_a_bearer_from_a_stored_member_session(): void {
		new Agend_Apps_Member_Session();
		Agend_Apps_Member_Session::store(
			42,
			array(
				'access_token'  => 'session-token',
				'refresh_token' => 'refresh-token',
				'expires_at'    => time() + 3600,
			)
		);

		Agend_Test_WP::$tiers_response = array( 'data' => array() );
		Agend_Test_WP::queue_response( 200, $this->membershipsResponse() );

		$GLOBALS['agend_test_current_user_id'] = 0;

		$result = agend_apps_member_sync_membership_meta( 42 );

		$this->assertTrue( $result );
		$this->assertSame(
			'Bearer session-token',
			Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? null
		);
		$this->assertSame( 'active', $GLOBALS['agend_test_user_meta'][42]['_agend_apps_membership_status_display'] );
		$this->assertSame( 0, get_current_user_id(), 'the impersonated user must not leak past the sync' );
	}

	#[Test]
	public function should_sync_using_a_bearer_from_the_token_worker_path(): void {
		new Agend_Apps_Token_Worker();
		update_user_meta( 42, agend_apps_external_id_meta_key(), 'external-42' );
		update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://idp.example.test/metadata' ) );

		Agend_Test_WP::$tiers_response = array( 'data' => array() );
		// First outbound call is the token mint (POST /sso/tokens), the
		// second is the memberships read the sync makes once a bearer exists.
		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'access_token' => 'minted-token',
					'expires_at'   => time() + 3600,
				),
			)
		);
		Agend_Test_WP::queue_response( 200, $this->membershipsResponse() );

		$GLOBALS['agend_test_current_user_id'] = 0;

		$result = agend_apps_member_sync_membership_meta( 42 );

		$this->assertTrue( $result );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertSame(
			'Bearer minted-token',
			Agend_Test_WP::$requests[1]['headers']['Authorization'] ?? null
		);
		$this->assertSame( 'active', $GLOBALS['agend_test_user_meta'][42]['_agend_apps_membership_status_display'] );
		$this->assertSame( 0, get_current_user_id(), 'the impersonated user must not leak past the sync' );
	}

	#[Test]
	public function should_restore_the_previous_user_when_impersonating_a_different_one(): void {
		new Agend_Apps_Member_Session();
		Agend_Apps_Member_Session::store(
			42,
			array(
				'access_token'  => 'session-token',
				'refresh_token' => 'refresh-token',
				'expires_at'    => time() + 3600,
			)
		);

		Agend_Test_WP::$tiers_response = array( 'data' => array() );
		Agend_Test_WP::queue_response( 200, $this->membershipsResponse() );

		$GLOBALS['agend_test_current_user_id'] = 7;

		agend_apps_member_sync_membership_meta( 42 );

		$this->assertSame( 7, get_current_user_id() );
	}

	#[Test]
	public function should_leave_the_previous_snapshot_untouched_on_a_gateway_error(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'some-bearer' );
		$GLOBALS['agend_test_user_meta'][42]['_agend_apps_membership_status_display'] = 'active';

		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'code' => 'INTERNAL' ) ) );

		$GLOBALS['agend_test_current_user_id'] = 0;

		$result = agend_apps_member_sync_membership_meta( 42 );

		$this->assertFalse( $result );
		$this->assertSame( 'active', $GLOBALS['agend_test_user_meta'][42]['_agend_apps_membership_status_display'] );
		$this->assertSame( 0, get_current_user_id() );
	}
}
