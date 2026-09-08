<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core {

	use Agend\Tests\TestCase;
	use Agend_Apps_Key_Scopes;
	use Agend_Test_WP;
	use PHPUnit\Framework\Attributes\Test;

	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sso.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/account-link-state.php';

	/**
	 * The optional-feature registry ({@see agend_apps_records_optional_features()})
	 * and its availability helpers (SPEC-CORE-20260908 scope-gated features).
	 */
	final class OptionalFeaturesTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			unset( $GLOBALS['agend_test_current_user_id'] );
			update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://example.test/saml/metadata' ) );
		}

		/**
		 * Stores the given scopes as already-known (bypasses a gateway call).
		 *
		 * @param string[] $scopes Scopes to record as held.
		 */
		private function set_held_scopes( array $scopes ): void {
			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => $scopes ) ) );
			Agend_Apps_Key_Scopes::refresh();
		}

		// -----------------------------------------------------------------
		// Registry shape
		// -----------------------------------------------------------------

		#[Test]
		public function the_registry_should_declare_every_expected_feature(): void {
			$features = agend_apps_records_optional_features();

			$this->assertArrayHasKey( 'directory_achievements', $features );
			$this->assertArrayHasKey( 'directory_export_reports', $features );
			$this->assertArrayHasKey( 'directory_review_form', $features );
			$this->assertArrayHasKey( 'directory_my_listing', $features );
			$this->assertArrayHasKey( 'sso_account_link', $features );

			$this->assertSame( array( 'directory.achievements.browse' ), $features['directory_achievements']['scopes'] );
			$this->assertSame( AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION, $features['directory_achievements']['option'] );
			$this->assertSame( array( 'directory.export_reports.browse' ), $features['directory_export_reports']['scopes'] );
			$this->assertSame( array( 'directory.reviews.manage' ), $features['directory_review_form']['scopes'] );
			$this->assertSame( array( 'directory.listings.self_update' ), $features['directory_my_listing']['scopes'] );
			$this->assertSame( array( 'sso.identities.read', 'sso.tokens.create' ), $features['sso_account_link']['scopes'] );
		}

		#[Test]
		public function the_registry_should_be_filterable(): void {
			add_filter(
				'agend_apps_records_optional_features',
				static function ( array $features ): array {
					$features['custom_feature'] = array(
						'label'  => 'Custom',
						'scopes' => array( 'custom.scope' ),
					);
					return $features;
				}
			);

			$features = agend_apps_records_optional_features();

			$this->assertArrayHasKey( 'custom_feature', $features );
		}

		// -----------------------------------------------------------------
		// Availability
		// -----------------------------------------------------------------

		#[Test]
		public function an_unknown_feature_id_should_be_treated_as_available(): void {
			$this->assertTrue( agend_apps_records_feature_available( 'not_a_real_feature' ) );
			$this->assertTrue( agend_apps_records_feature_scopes_held( 'not_a_real_feature' ) );
			$this->assertSame( array(), agend_apps_records_feature_missing_scopes( 'not_a_real_feature' ) );
		}

		#[Test]
		public function a_scope_gated_feature_should_be_unavailable_before_the_scopes_are_known(): void {
			$this->assertTrue( agend_apps_records_feature_unknown_scopes() );
			$this->assertFalse( agend_apps_records_feature_available( 'directory_export_reports' ) );
		}

		#[Test]
		public function a_feature_should_be_available_once_its_scope_is_confirmed_held(): void {
			$this->set_held_scopes( array( 'directory.export_reports.browse' ) );

			$this->assertFalse( agend_apps_records_feature_unknown_scopes() );
			$this->assertTrue( agend_apps_records_feature_available( 'directory_export_reports' ) );
			$this->assertSame( array(), agend_apps_records_feature_missing_scopes( 'directory_export_reports' ) );
		}

		#[Test]
		public function a_feature_should_be_unavailable_when_the_confirmed_scope_list_lacks_it(): void {
			$this->set_held_scopes( array( 'directory.achievements.browse' ) );

			$this->assertFalse( agend_apps_records_feature_available( 'directory_export_reports' ) );
			$this->assertSame(
				array( 'directory.export_reports.browse' ),
				agend_apps_records_feature_missing_scopes( 'directory_export_reports' )
			);
		}

		#[Test]
		public function a_feature_needing_every_scope_in_a_multi_scope_requirement(): void {
			$this->set_held_scopes( array( 'sso.identities.read' ) );

			$this->assertFalse( agend_apps_records_feature_available( 'sso_account_link' ) );
			$this->assertSame( array( 'sso.tokens.create' ), agend_apps_records_feature_missing_scopes( 'sso_account_link' ) );

			$this->set_held_scopes( array( 'sso.identities.read', 'sso.tokens.create' ) );

			$this->assertTrue( agend_apps_records_feature_available( 'sso_account_link' ) );
		}

		#[Test]
		public function an_option_gated_feature_should_stay_off_when_its_option_is_off_even_with_the_scope_held(): void {
			$this->set_held_scopes( array( 'directory.achievements.browse' ) );
			update_option( AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION, '' );

			$this->assertFalse( agend_apps_records_feature_available( 'directory_achievements' ) );
		}

		#[Test]
		public function an_option_gated_feature_should_be_available_when_the_option_is_on_and_the_scope_is_held(): void {
			$this->set_held_scopes( array( 'directory.achievements.browse' ) );
			update_option( AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION, '1' );

			$this->assertTrue( agend_apps_records_feature_available( 'directory_achievements' ) );
		}

		// -----------------------------------------------------------------
		// Achievements helper (settings.php)
		// -----------------------------------------------------------------

		#[Test]
		public function the_achievements_helper_should_honour_scope_availability(): void {
			update_option( AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION, '1' );
			$this->set_held_scopes( array( 'directory.achievements.browse' ) );

			$this->assertTrue( agend_apps_records_show_achievements_enabled() );

			$this->set_held_scopes( array() );

			$this->assertFalse( agend_apps_records_show_achievements_enabled() );
		}

		#[Test]
		public function the_achievements_helper_should_stay_off_with_the_option_off_regardless_of_scopes(): void {
			update_option( AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION, '' );
			$this->set_held_scopes( array( 'directory.achievements.browse' ) );

			$this->assertFalse( agend_apps_records_show_achievements_enabled() );
		}

		// -----------------------------------------------------------------
		// Missing-scope notice
		// -----------------------------------------------------------------

		#[Test]
		public function the_notice_should_point_at_verifying_the_connection_when_scopes_are_unknown(): void {
			$notice = agend_apps_records_feature_missing_scope_notice( 'directory_export_reports' );

			$this->assertStringContainsString( 'verify the connection', $notice );
		}

		#[Test]
		public function the_notice_should_name_the_missing_scope_once_known(): void {
			$this->set_held_scopes( array() );

			$notice = agend_apps_records_feature_missing_scope_notice( 'directory_export_reports' );

			$this->assertStringContainsString( 'directory.export_reports.browse', $notice );
		}

		// -----------------------------------------------------------------
		// Account-link state: missing_scope reason
		// -----------------------------------------------------------------

		private function setLoggedInUser( int $user_id, string $external_id ): void {
			$GLOBALS['agend_test_current_user_id'] = $user_id;
			update_user_meta( $user_id, 'imk_membership_number', $external_id );
		}

		#[Test]
		public function account_link_state_should_report_missing_scope_without_a_gateway_call(): void {
			update_option( 'agend_apps_member_auth_mode', 'sso' );
			$this->setLoggedInUser( 30, 'member-30' );
			$this->set_held_scopes( array( 'sso.identities.read' ) ); // sso.tokens.create missing.

			$before = count( Agend_Test_WP::$requests );

			$state = agend_apps_account_link_state( 30 );

			$this->assertSame( 'error', $state['state'] );
			$this->assertSame( 'missing_scope', $state['reason'] );
			$this->assertCount( $before, Agend_Test_WP::$requests );
		}

		#[Test]
		public function account_link_state_should_proceed_to_the_gateway_once_both_scopes_are_held(): void {
			update_option( 'agend_apps_member_auth_mode', 'sso' );
			$this->setLoggedInUser( 31, 'member-31' );
			$this->set_held_scopes( array( 'sso.identities.read', 'sso.tokens.create' ) );

			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'linked' => true ) ) );

			$state = agend_apps_account_link_state( 31 );

			$this->assertSame( 'linked', $state['state'] );
			$this->assertSame( '', $state['reason'] );
		}
	}
}
