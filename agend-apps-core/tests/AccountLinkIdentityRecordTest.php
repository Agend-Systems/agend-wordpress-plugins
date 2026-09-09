<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace {
	// class_exists( 'WooCommerce' ) is how the plugin gates every My Account
	// hook; not exercised here, but account-link-state.php's docblock links it
	// to my-account-directory.php and some shared test doubles assume it is
	// available. Declared defensively so this file can run standalone.
	if ( ! class_exists( 'WooCommerce' ) ) {
		class WooCommerce {}
	}
}

namespace Agend\Tests\Core {

	use Agend\Tests\TestCase;
	use Agend_Apps_Account_Link_REST_Controller;
	use Agend_Apps_Token_Worker;
	use Agend_Test_WP;
	use PHPUnit\Framework\Attributes\Test;
	use WP_REST_Request;

	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sso.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/account-link-state.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/account-link-routes.php';

	/**
	 * Recording a WordPress user's linked Agend identity ids
	 * ({@see \agend_apps_record_linked_identity()}) from the two surfaces that
	 * observe a linked SSO identity -- the token worker's mint, and the REST
	 * account-link status route -- plus the shared helper's own rules (empty
	 * values never overwrite, older-gateway responses without the fields still
	 * resolve).
	 */
	final class AccountLinkIdentityRecordTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			unset( $GLOBALS['agend_test_current_user_id'] );

			update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://example.test/saml/metadata' ) );

			// This file exercises the token worker's mint and the account-link
			// status route directly, both gated (SPEC-CORE-20260908 scope-gated
			// features) on the sso_account_link optional feature holding
			// sso.identities.read + sso.tokens.create. Seeded as held here so
			// this file's own behaviour under test is unaffected by that gate
			// (KeyScopesTest/OptionalFeaturesTest exercise the gate itself);
			// class_exists() guards a run where those classes never loaded.
			if ( class_exists( '\Agend_Apps_Key_Scopes' ) && function_exists( 'agend_apps_verify_api_key' ) ) {
				Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'sso.identities.read', 'sso.tokens.create' ) ) ) );
				\Agend_Apps_Key_Scopes::refresh();
				Agend_Test_WP::$requests = array();
			}
		}

		private function setLoggedInUser( int $user_id, string $external_id ): void {
			$GLOBALS['agend_test_current_user_id'] = $user_id;
			update_user_meta( $user_id, 'imk_membership_number', $external_id );
		}

		// -----------------------------------------------------------------
		// Shared helper
		// -----------------------------------------------------------------

		#[Test]
		public function should_record_both_ids_when_present(): void {
			\agend_apps_record_linked_identity(
				30,
				array(
					'user_id'    => 'supabase-30',
					'contact_id' => 'contact-30',
				)
			);

			$this->assertSame( 'supabase-30', get_user_meta( 30, '_agend_apps_supabase_user_id', true ) );
			$this->assertSame( 'contact-30', get_user_meta( 30, '_agend_apps_contact_id', true ) );
		}

		#[Test]
		public function should_record_nothing_for_a_response_missing_both_ids(): void {
			\agend_apps_record_linked_identity( 31, array( 'linked' => true ) );

			$this->assertSame( '', get_user_meta( 31, '_agend_apps_supabase_user_id', true ) );
			$this->assertSame( '', get_user_meta( 31, '_agend_apps_contact_id', true ) );
		}

		#[Test]
		public function should_never_overwrite_a_recorded_id_with_an_empty_or_null_one(): void {
			update_user_meta( 32, '_agend_apps_supabase_user_id', 'supabase-32' );
			update_user_meta( 32, '_agend_apps_contact_id', 'contact-32' );

			\agend_apps_record_linked_identity(
				32,
				array(
					'user_id'    => '',
					'contact_id' => null,
				)
			);

			$this->assertSame( 'supabase-32', get_user_meta( 32, '_agend_apps_supabase_user_id', true ) );
			$this->assertSame( 'contact-32', get_user_meta( 32, '_agend_apps_contact_id', true ) );
		}

		// -----------------------------------------------------------------
		// Token worker mint
		// -----------------------------------------------------------------

		#[Test]
		public function should_record_ids_from_a_successful_mint_response(): void {
			$this->setLoggedInUser( 40, 'member-40' );

			Agend_Test_WP::queue_response(
				200,
				array(
					'data' => array(
						'access_token' => 'token-40',
						'expires_at'   => time() + 300,
						'user_id'      => 'supabase-40',
						'contact_id'   => 'contact-40',
					),
				)
			);

			$worker = new Agend_Apps_Token_Worker();
			$token  = $worker->provide_token( '' );

			$this->assertSame( 'token-40', $token );
			$this->assertSame( 'supabase-40', get_user_meta( 40, '_agend_apps_supabase_user_id', true ) );
			$this->assertSame( 'contact-40', get_user_meta( 40, '_agend_apps_contact_id', true ) );
		}

		#[Test]
		public function should_still_mint_a_token_when_an_older_gateway_mint_response_omits_the_identity_fields(): void {
			$this->setLoggedInUser( 41, 'member-41' );

			Agend_Test_WP::queue_response(
				200,
				array(
					'data' => array(
						'access_token' => 'token-41',
						'expires_at'   => time() + 300,
					),
				)
			);

			$worker = new Agend_Apps_Token_Worker();
			$token  = $worker->provide_token( '' );

			$this->assertSame( 'token-41', $token );
			$this->assertSame( '', get_user_meta( 41, '_agend_apps_supabase_user_id', true ) );
			$this->assertSame( '', get_user_meta( 41, '_agend_apps_contact_id', true ) );
		}

		// -----------------------------------------------------------------
		// REST account-link/status
		// -----------------------------------------------------------------

		#[Test]
		public function should_include_the_recorded_ids_in_the_status_response_for_a_linked_user(): void {
			$this->setLoggedInUser( 50, 'member-50' );

			Agend_Test_WP::queue_response(
				200,
				array(
					'data' => array(
						'linked'     => true,
						'user_id'    => 'supabase-50',
						'contact_id' => 'contact-50',
					),
				)
			);

			$controller = new Agend_Apps_Account_Link_REST_Controller();
			$response   = $controller->get_status( new WP_REST_Request( 'GET', '/agend-apps/v1/account-link/status' ) );
			$data       = $response->get_data();

			$this->assertTrue( $data['linked'] );
			$this->assertSame( 'supabase-50', $data['supabase_user_id'] );
			$this->assertSame( 'contact-50', $data['contact_id'] );
			$this->assertSame( 'supabase-50', get_user_meta( 50, '_agend_apps_supabase_user_id', true ) );
			$this->assertSame( 'contact-50', get_user_meta( 50, '_agend_apps_contact_id', true ) );
		}

		#[Test]
		public function should_resolve_linked_via_the_status_route_when_an_older_gateway_response_omits_the_identity_fields(): void {
			$this->setLoggedInUser( 51, 'member-51' );

			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'linked' => true ) ) );

			$controller = new Agend_Apps_Account_Link_REST_Controller();
			$response   = $controller->get_status( new WP_REST_Request( 'GET', '/agend-apps/v1/account-link/status' ) );
			$data       = $response->get_data();

			$this->assertTrue( $data['linked'] );
			$this->assertSame( '', $data['supabase_user_id'] );
			$this->assertSame( '', $data['contact_id'] );
		}
	}
}
