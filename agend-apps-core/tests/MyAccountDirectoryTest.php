<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace {
	// class_exists( 'WooCommerce' ) is how the plugin gates every My Account
	// hook (agend_apps_my_account_directory_enabled()); a bare marker class in
	// the global namespace is enough to exercise "WooCommerce active" without
	// pulling in real WooCommerce. Must live outside any namespace block: the
	// production code checks the unqualified, global `WooCommerce`.
	if ( ! class_exists( 'WooCommerce' ) ) {
		class WooCommerce {}
	}
}

namespace Agend\Tests\Core {

	use Agend\Tests\TestCase;
	use Agend_Test_WP;
	use PHPUnit\Framework\Attributes\Test;

	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sso.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/account-link-state.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/my-account-directory.php';

	/**
	 * The WooCommerce My Account "Directory" endpoint: account-link state
	 * resolution ({@see agend_apps_account_link_state()}), the menu-item
	 * insertion point, and the WooCommerce gate.
	 */
	final class MyAccountDirectoryTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			unset( $GLOBALS['agend_test_current_user_id'] );

			// agend_apps_idp_entity_id() falls back to site_url(), which is not
			// stubbed; configuring the option avoids that fallback in every test
			// that reaches the SSO link-status lookup.
			update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://example.test/saml/metadata' ) );
		}

		private function setLoggedInUser( int $user_id, string $external_id = '' ): void {
			$GLOBALS['agend_test_current_user_id'] = $user_id;

			if ( '' !== $external_id ) {
				update_user_meta( $user_id, 'imk_membership_number', $external_id );
			}
		}

		private function setDirectoryPage( int $page_id = 42 ): void {
			$GLOBALS['agend_test_posts'][ $page_id ] = new \WP_Post(
				array(
					'ID'          => $page_id,
					'post_type'   => 'page',
					'post_status' => 'publish',
				)
			);
			update_option( 'agend_apps_directory_page_id', $page_id );
		}

		// -----------------------------------------------------------------
		// State resolution
		// -----------------------------------------------------------------

		#[Test]
		public function should_resolve_logged_out_when_no_user_is_signed_in(): void {
			$state = agend_apps_account_link_state( 0 );

			$this->assertSame( 'logged_out', $state['state'] );
		}

		#[Test]
		public function should_resolve_no_external_id_when_the_member_has_no_external_id(): void {
			update_option( 'agend_apps_member_auth_mode', 'sso' );
			$this->setLoggedInUser( 7 );

			$state = agend_apps_account_link_state( 7 );

			$this->assertSame( 'no_external_id', $state['state'] );
			$this->assertSame( '', $state['initiate_url'] );
		}

		#[Test]
		public function should_resolve_linked_when_the_gateway_reports_linked(): void {
			update_option( 'agend_apps_member_auth_mode', 'sso' );
			$this->setLoggedInUser( 8, 'member-8' );

			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'linked' => true ) ) );

			$state = agend_apps_account_link_state( 8, 'https://example.test/my-account/agend-directory/' );

			$this->assertSame( 'linked', $state['state'] );
			$this->assertSame( '', $state['initiate_url'] );
		}

		#[Test]
		public function should_record_and_expose_ids_from_a_linked_status_response(): void {
			update_option( 'agend_apps_member_auth_mode', 'sso' );
			$this->setLoggedInUser( 20, 'member-20' );

			Agend_Test_WP::queue_response(
				200,
				array(
					'data' => array(
						'linked'     => true,
						'user_id'    => 'supabase-20',
						'contact_id' => 'contact-20',
					),
				)
			);

			$state = agend_apps_account_link_state( 20 );

			$this->assertSame( 'linked', $state['state'] );
			$this->assertSame( 'supabase-20', $state['supabase_user_id'] );
			$this->assertSame( 'contact-20', $state['contact_id'] );
			$this->assertSame( 'supabase-20', get_user_meta( 20, '_agend_apps_supabase_user_id', true ) );
			$this->assertSame( 'contact-20', get_user_meta( 20, '_agend_apps_contact_id', true ) );
		}

		#[Test]
		public function should_resolve_linked_when_an_older_gateway_response_omits_the_identity_fields(): void {
			update_option( 'agend_apps_member_auth_mode', 'sso' );
			$this->setLoggedInUser( 21, 'member-21' );

			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'linked' => true ) ) );

			$state = agend_apps_account_link_state( 21 );

			$this->assertSame( 'linked', $state['state'] );
			$this->assertSame( '', $state['supabase_user_id'] );
			$this->assertSame( '', $state['contact_id'] );
		}

		#[Test]
		public function should_not_overwrite_a_recorded_contact_id_with_an_empty_one(): void {
			update_option( 'agend_apps_member_auth_mode', 'sso' );
			$this->setLoggedInUser( 22, 'member-22' );
			update_user_meta( 22, '_agend_apps_contact_id', 'contact-22' );

			// A later status check that happens not to carry contact_id (e.g. a
			// contactless-member response with an explicit null) must not erase
			// the previously recorded id.
			Agend_Test_WP::queue_response(
				200,
				array(
					'data' => array(
						'linked'     => true,
						'contact_id' => null,
					),
				)
			);

			$state = agend_apps_account_link_state( 22 );

			$this->assertSame( 'contact-22', $state['contact_id'] );
			$this->assertSame( 'contact-22', get_user_meta( 22, '_agend_apps_contact_id', true ) );
		}

		#[Test]
		public function should_resolve_unlinked_when_the_gateway_reports_not_linked(): void {
			update_option( 'agend_apps_member_auth_mode', 'sso' );
			$this->setLoggedInUser( 9, 'member-9' );

			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'linked' => false ) ) );

			$state = agend_apps_account_link_state( 9, 'https://example.test/my-account/agend-directory/' );

			$this->assertSame( 'unlinked', $state['state'] );
			// The Settings double lacks get_account_slug()/get_root_url(), so no
			// initiate URL can be built in this test environment -- the
			// no-slug/no-root-URL outcome ('') is itself the behaviour under
			// test for an unconfigured account slug/root URL in production too.
			$this->assertSame( '', $state['initiate_url'] );
		}

		#[Test]
		public function should_resolve_error_when_the_link_status_lookup_fails(): void {
			update_option( 'agend_apps_member_auth_mode', 'sso' );
			$this->setLoggedInUser( 10, 'member-10' );

			Agend_Test_WP::queue_response(
				500,
				array(
					'error' => array(
						'code'    => 'SERVER_ERROR',
						'message' => 'boom',
					),
				)
			);

			$state = agend_apps_account_link_state( 10 );

			$this->assertSame( 'error', $state['state'] );
			$this->assertSame( '', $state['initiate_url'] );
		}

		#[Test]
		public function should_resolve_linked_in_credentials_mode_without_a_gateway_call(): void {
			update_option( 'agend_apps_member_auth_mode', 'credentials' );
			$this->setLoggedInUser( 11 );

			$before = count( Agend_Test_WP::$requests );

			$state = agend_apps_account_link_state( 11 );

			$this->assertSame( 'linked', $state['state'] );
			$this->assertCount( $before, Agend_Test_WP::$requests );
		}

		#[Test]
		public function should_include_the_directory_url_when_a_directory_page_is_configured(): void {
			$this->setDirectoryPage( 42 );
			update_option( 'agend_apps_member_auth_mode', 'credentials' );
			$this->setLoggedInUser( 12 );

			$state = agend_apps_account_link_state( 12 );

			$this->assertSame( 'linked', $state['state'] );
			$this->assertNotSame( '', $state['directory_url'] );
		}

		#[Test]
		public function should_resolve_an_empty_directory_url_when_no_directory_page_is_configured(): void {
			update_option( 'agend_apps_member_auth_mode', 'credentials' );
			$this->setLoggedInUser( 13 );

			$state = agend_apps_account_link_state( 13 );

			$this->assertSame( '', $state['directory_url'] );
		}

		// -----------------------------------------------------------------
		// Menu item
		// -----------------------------------------------------------------

		#[Test]
		public function should_insert_directory_immediately_before_logout(): void {
			$this->setDirectoryPage();

			$items = agend_apps_my_account_directory_menu_item(
				array(
					'dashboard'       => 'Dashboard',
					'orders'          => 'Orders',
					'customer-logout' => 'Log out',
				)
			);

			$keys = array_keys( $items );

			$this->assertSame(
				array( 'dashboard', 'orders', \AGEND_APPS_MY_ACCOUNT_DIRECTORY_ENDPOINT, 'customer-logout' ),
				$keys
			);
			$this->assertSame( 'Directory', $items[ \AGEND_APPS_MY_ACCOUNT_DIRECTORY_ENDPOINT ] );
		}

		#[Test]
		public function should_append_directory_when_no_logout_item_is_present(): void {
			$this->setDirectoryPage();

			$items = agend_apps_my_account_directory_menu_item(
				array(
					'dashboard' => 'Dashboard',
					'orders'    => 'Orders',
				)
			);

			$this->assertSame(
				array( 'dashboard', 'orders', \AGEND_APPS_MY_ACCOUNT_DIRECTORY_ENDPOINT ),
				array_keys( $items )
			);
		}

		#[Test]
		public function should_omit_the_menu_item_when_no_directory_page_is_configured(): void {
			$original = array(
				'dashboard'       => 'Dashboard',
				'customer-logout' => 'Log out',
			);

			$items = agend_apps_my_account_directory_menu_item( $original );

			$this->assertSame( $original, $items );
		}

		// -----------------------------------------------------------------
		// Content rendering
		// -----------------------------------------------------------------

		#[Test]
		public function should_render_a_button_to_the_directory_when_linked(): void {
			$markup = agend_apps_my_account_directory_render(
				array(
					'state'         => 'linked',
					'initiate_url'  => '',
					'directory_url' => 'https://example.test/directory/',
					'contact_id'    => 'contact-1',
				)
			);

			$this->assertStringContainsString( 'agend-my-account-directory--linked', $markup );
			$this->assertStringContainsString( 'https://example.test/directory/', $markup );
			$this->assertStringContainsString( 'button', $markup );
		}

		#[Test]
		public function should_not_render_the_contactless_note_when_linked_with_a_contact_id(): void {
			$markup = agend_apps_my_account_directory_render(
				array(
					'state'         => 'linked',
					'initiate_url'  => '',
					'directory_url' => 'https://example.test/directory/',
					'contact_id'    => 'contact-1',
				)
			);

			$this->assertStringNotContainsString( 'still being set up', $markup );
		}

		#[Test]
		public function should_render_the_contactless_note_when_linked_with_an_empty_contact_id(): void {
			$markup = agend_apps_my_account_directory_render(
				array(
					'state'         => 'linked',
					'initiate_url'  => '',
					'directory_url' => 'https://example.test/directory/',
					'contact_id'    => '',
				)
			);

			$this->assertStringContainsString( 'still being set up', $markup );
		}

		#[Test]
		public function should_render_the_contactless_note_when_linked_with_no_contact_id_key(): void {
			$markup = agend_apps_my_account_directory_render(
				array(
					'state'         => 'linked',
					'initiate_url'  => '',
					'directory_url' => 'https://example.test/directory/',
				)
			);

			$this->assertStringContainsString( 'still being set up', $markup );
		}

		#[Test]
		public function should_render_a_connect_button_when_unlinked(): void {
			$markup = agend_apps_my_account_directory_render(
				array(
					'state'         => 'unlinked',
					'initiate_url'  => 'https://api.example.test/api/auth/sso/acme/initiate?relayState=x',
					'directory_url' => '',
				)
			);

			$this->assertStringContainsString( 'agend-my-account-directory--unlinked', $markup );
			$this->assertStringContainsString( 'sso', $markup );
		}

		#[Test]
		public function should_render_a_contact_support_message_when_no_external_id(): void {
			$markup = agend_apps_my_account_directory_render(
				array(
					'state'         => 'no_external_id',
					'initiate_url'  => '',
					'directory_url' => '',
				)
			);

			$this->assertStringContainsString( 'agend-my-account-directory--no_external_id', $markup );
			$this->assertStringContainsString( 'support', $markup );
		}

		#[Test]
		public function should_render_a_neutral_message_when_the_link_status_lookup_errored(): void {
			$markup = agend_apps_my_account_directory_render(
				array(
					'state'         => 'error',
					'initiate_url'  => '',
					'directory_url' => '',
				)
			);

			$this->assertStringContainsString( 'agend-my-account-directory--error', $markup );
			$this->assertStringContainsString( 'unavailable', $markup );
		}
	}
}
