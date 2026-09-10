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
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-diagnostics.php';

/**
 * The WordPress-IdP diagnostics panel's data-building function
 * (docs/PLAN-wordpress-idp-option-b.md section 6, "Diagnostic panel").
 *
 * `includes/records/features.php` is required here for the same reason
 * {@see \Agend\Tests\Core\WpIdpLinkTest} requires it: once loaded anywhere in
 * the PHPUnit process, `agend_apps_records_optional_features()` is available
 * for the rest of the run, so this file's own behaviour must not depend on
 * being the one that first loaded it.
 */
final class WpIdpDiagnosticsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
		update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://example.test/saml/metadata' ) );
	}

	/** Registers a WordPress user `get_user_by()` can resolve. */
	private function registerUser( int $user_id, string $email = '' ): WP_User {
		$user             = new WP_User( $user_id );
		$user->user_email = $email;

		$GLOBALS['agend_test_users'][] = $user;

		return $user;
	}

	/** Seeds the key-scope cache directly (no request), for held/missing/unknown scenarios. */
	private function seedScopes( array $scopes ): void {
		update_option(
			Agend_Apps_Key_Scopes::OPTION,
			array(
				'scopes'     => $scopes,
				'fetched_at' => time(),
				'key_hash'   => sha1( Agend_Apps_Settings::get_api_key() . '|production' ),
			)
		);
	}

	// -----------------------------------------------------------------
	// Never a gateway call
	// -----------------------------------------------------------------

	#[Test]
	public function should_make_no_http_request_when_building_diagnostics(): void {
		$this->registerUser( 1, 'nobody@example.test' );

		// Deliberately leave the scope cache unseeded (unknown) and the link
		// state unrecorded, the two conditions most likely to tempt a
		// refresh/mint if the builder were not purely reading recorded state.
		\agend_apps_wp_idp_diagnostics( 1 );

		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// Link state classification and guidance
	// -----------------------------------------------------------------

	/** @return array<string, array{0: string, 1: string, 2: string}> state => [label, severity, needle in guidance] */
	public static function linkStateProvider(): array {
		return array(
			'linked'            => array( \AGEND_APPS_LINK_STATE_LINKED, 'success', 'No action needed' ),
			'pending'           => array( \AGEND_APPS_LINK_STATE_PENDING, 'info', 'confirmation email' ),
			'conflict'          => array( \AGEND_APPS_LINK_STATE_CONFLICT, 'error', 'different external id' ),
			'no_contact'        => array( \AGEND_APPS_LINK_STATE_NO_CONTACT, 'error', 'JIT contact provisioning' ),
			'forbidden'         => array( \AGEND_APPS_LINK_STATE_FORBIDDEN, 'error', 'sso.connections.create' ),
			'error'             => array( \AGEND_APPS_LINK_STATE_ERROR, 'warning', 'retries on its own' ),
			'never attempted'   => array( '', 'info', 'Expected until' ),
			'unrecognised'      => array( 'some_future_state', 'info', 'Expected until' ),
		);
	}

	#[Test]
	public function should_classify_every_link_state_with_the_right_severity_and_guidance(): void {
		foreach ( self::linkStateProvider() as $case ) {
			[ $state, $severity, $needle ] = $case;

			$guidance = \agend_apps_wp_idp_link_state_guidance( $state );

			$this->assertSame( $severity, $guidance['severity'], "state: {$state}" );
			$this->assertStringContainsString( $needle, $guidance['guidance'], "state: {$state}" );
		}
	}

	#[Test]
	public function should_carry_the_recorded_state_error_code_and_guidance_into_the_full_diagnostics(): void {
		$this->registerUser( 20, 'conflict@example.test' );
		\agend_apps_wp_idp_record_link_state( 20, \AGEND_APPS_LINK_STATE_CONFLICT, 'IDENTITY_ALREADY_LINKED' );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 20 );

		$this->assertSame( \AGEND_APPS_LINK_STATE_CONFLICT, $diagnostics['link']['state'] );
		$this->assertSame( 'IDENTITY_ALREADY_LINKED', $diagnostics['link']['error_code'] );
		$this->assertSame( 'error', $diagnostics['link_guidance']['severity'] );
	}

	// -----------------------------------------------------------------
	// External id source detection
	// -----------------------------------------------------------------

	#[Test]
	public function should_report_the_configured_meta_key_as_the_source_when_it_resolves(): void {
		$this->registerUser( 30 );
		update_user_meta( 30, 'imk_membership_number', 'M-30' );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 30 );

		$this->assertSame(
			array(
				'value'  => 'M-30',
				'source' => 'meta_key',
			),
			$diagnostics['external_id']
		);
	}

	#[Test]
	public function should_report_the_minted_guid_as_the_source_in_wordpress_mode_when_no_meta_key_value_exists(): void {
		$this->registerUser( 31 );
		update_user_meta( 31, \AGEND_APPS_EXTERNAL_ID_META, 'guid-31' );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 31 );

		$this->assertSame(
			array(
				'value'  => 'guid-31',
				'source' => 'minted',
			),
			$diagnostics['external_id']
		);
	}

	#[Test]
	public function should_not_fall_back_to_the_minted_guid_outside_wordpress_mode(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_SSO );
		$this->registerUser( 32 );
		update_user_meta( 32, \AGEND_APPS_EXTERNAL_ID_META, 'guid-32' );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 32 );

		$this->assertSame( '', $diagnostics['external_id']['value'] );
		$this->assertSame( '', $diagnostics['external_id']['source'] );
	}

	#[Test]
	public function should_report_the_filter_as_the_source_when_it_supplies_the_value(): void {
		$this->registerUser( 33 );

		add_filter(
			'agend_apps_current_user_external_id',
			static function ( $value, $user_id ) {
				return 33 === $user_id ? 'filter-supplied' : $value;
			},
			10,
			2
		);

		$diagnostics = \agend_apps_wp_idp_diagnostics( 33 );

		$this->assertSame(
			array(
				'value'  => 'filter-supplied',
				'source' => 'filter',
			),
			$diagnostics['external_id']
		);
	}

	#[Test]
	public function should_report_no_source_when_nothing_resolves(): void {
		$this->registerUser( 34 );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 34 );

		$this->assertSame(
			array(
				'value'  => '',
				'source' => '',
			),
			$diagnostics['external_id']
		);
	}

	// -----------------------------------------------------------------
	// Token cache state
	// -----------------------------------------------------------------

	#[Test]
	public function should_report_no_token_cached_when_none_exists(): void {
		$this->registerUser( 40 );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 40 );

		$this->assertFalse( $diagnostics['token']['cached'] );
		$this->assertFalse( $diagnostics['token']['expired'] );
	}

	#[Test]
	public function should_report_a_cached_token_as_current_when_within_expiry(): void {
		$this->registerUser( 41 );
		update_user_meta(
			41,
			Agend_Apps_Token_Worker::META_KEY,
			array(
				'access_token' => 'super-secret-token',
				'expires_at'   => time() + HOUR_IN_SECONDS,
				'external_id'  => 'ext-41',
				'minted_at'    => time(),
			)
		);

		$diagnostics = \agend_apps_wp_idp_diagnostics( 41 );

		$this->assertTrue( $diagnostics['token']['cached'] );
		$this->assertFalse( $diagnostics['token']['expired'] );
		$this->assertStringNotContainsString( 'super-secret-token', wp_json_encode( $diagnostics ) );
	}

	#[Test]
	public function should_report_a_cached_token_as_expired_once_past_its_expiry(): void {
		$this->registerUser( 42 );
		update_user_meta(
			42,
			Agend_Apps_Token_Worker::META_KEY,
			array(
				'access_token' => 'stale-token',
				'expires_at'   => time() - 10,
				'external_id'  => 'ext-42',
				'minted_at'    => time() - 3600,
			)
		);

		$diagnostics = \agend_apps_wp_idp_diagnostics( 42 );

		$this->assertTrue( $diagnostics['token']['cached'] );
		$this->assertTrue( $diagnostics['token']['expired'] );
	}

	// -----------------------------------------------------------------
	// Negative cache
	// -----------------------------------------------------------------

	#[Test]
	public function should_report_the_negative_cache_as_set_when_the_transient_exists(): void {
		$this->registerUser( 50 );
		set_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . 50, 1, 300 );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 50 );

		$this->assertTrue( $diagnostics['token']['negative_cached'] );
	}

	#[Test]
	public function should_report_the_negative_cache_as_not_set_when_the_transient_is_absent(): void {
		$this->registerUser( 51 );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 51 );

		$this->assertFalse( $diagnostics['token']['negative_cached'] );
	}

	// -----------------------------------------------------------------
	// Key scopes
	// -----------------------------------------------------------------

	#[Test]
	public function should_report_scopes_as_unknown_when_the_cache_was_never_populated(): void {
		$this->registerUser( 60 );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 60 );

		$this->assertFalse( $diagnostics['scopes']['identity_link']['known'] );
		$this->assertFalse( $diagnostics['scopes']['identity_link']['held'] );
		$this->assertFalse( $diagnostics['scopes']['account_link']['known'] );
	}

	#[Test]
	public function should_report_scopes_as_held_when_the_connected_key_holds_all_of_them(): void {
		$this->registerUser( 61 );
		$this->seedScopes( array( 'sso.identities.create', 'sso.identities.read', 'sso.tokens.create' ) );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 61 );

		$this->assertTrue( $diagnostics['scopes']['identity_link']['known'] );
		$this->assertTrue( $diagnostics['scopes']['identity_link']['held'] );
		$this->assertTrue( $diagnostics['scopes']['account_link']['held'] );
	}

	#[Test]
	public function should_report_scopes_as_missing_when_the_key_lacks_one_of_them(): void {
		$this->registerUser( 62 );
		$this->seedScopes( array( 'sso.identities.read' ) );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 62 );

		$this->assertTrue( $diagnostics['scopes']['identity_link']['known'] );
		$this->assertFalse( $diagnostics['scopes']['identity_link']['held'] );
		$this->assertFalse( $diagnostics['scopes']['account_link']['held'] );
	}

	// -----------------------------------------------------------------
	// Mode degradation, entity id, recorded Agend ids
	// -----------------------------------------------------------------

	#[Test]
	public function should_report_wordpress_mode_as_false_and_still_report_everything_else_outside_it(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );
		$this->registerUser( 70, 'seventy@example.test' );
		update_user_meta( 70, 'imk_membership_number', 'M-70' );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 70 );

		$this->assertFalse( $diagnostics['wordpress_mode'] );
		$this->assertSame( 'M-70', $diagnostics['external_id']['value'] );
		$this->assertSame( '', $diagnostics['link']['state'] );
		$this->assertNotSame( '', $diagnostics['idp_entity_id'] );
	}

	#[Test]
	public function should_report_recorded_agend_ids(): void {
		$this->registerUser( 80 );
		\agend_apps_record_linked_identity(
			80,
			array(
				'user_id'    => 'supabase-80',
				'contact_id' => 'contact-80',
			)
		);

		$diagnostics = \agend_apps_wp_idp_diagnostics( 80 );

		$this->assertSame(
			array(
				'supabase_user_id' => 'supabase-80',
				'contact_id'       => 'contact-80',
			),
			$diagnostics['identity_ids']
		);
	}

	#[Test]
	public function should_report_the_connection_entity_id_in_effect(): void {
		$this->registerUser( 90 );

		$diagnostics = \agend_apps_wp_idp_diagnostics( 90 );

		$this->assertSame( 'https://example.test/saml/metadata', $diagnostics['idp_entity_id'] );
	}
}
