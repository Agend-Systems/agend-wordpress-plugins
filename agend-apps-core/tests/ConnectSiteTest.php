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
use Agend_Test_WP_SAML_IDP_Api;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sso.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/connect-site.php';

/**
 * `includes/connect-site.php`: the "Connect this site" action's pure builders
 * (SP url/slug derivation, the sp-data/attribute-mapping/connection-payload
 * shapes, the member-roles allow-list, scope checking, the stored-connection
 * round trip, and the approval-notice copy) and the `agend_apps_connect_run()`
 * orchestration end to end against a `WP_SAML_IDP_Api` test double
 * (`fixtures/wp-saml-idp-api-stub.php`).
 */
final class ConnectSiteTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
		update_option( 'agend_apps_account_slug', 'wdaa' );

		// The test double for Agend_Apps_Settings::get_root_url() (tests/doubles.php)
		// reads this option directly rather than the real environment-switch
		// logic -- see that method's own docblock.
		update_option( 'agend_apps_root_url_for_tests', 'https://api.example.test' );

		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'sso.connections.browse', 'sso.connections.create' ) ) ) );
		Agend_Apps_Key_Scopes::refresh();
		Agend_Test_WP::$requests = array();
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_sp_urls()
	// -----------------------------------------------------------------

	#[Test]
	public function should_derive_the_sp_urls_stripping_a_trailing_slash(): void {
		$urls = \agend_apps_connect_sp_urls( 'https://api.example.test/', 'wdaa' );

		$this->assertSame(
			array(
				'sp_entity_id'    => 'https://api.example.test/api/auth/sso/wdaa/metadata',
				'sp_acs_url'      => 'https://api.example.test/api/auth/sso/wdaa/acs',
				'sp_metadata_url' => 'https://api.example.test/api/auth/sso/wdaa/metadata',
			),
			$urls
		);
	}

	#[Test]
	public function should_interpolate_the_slug_raw_without_encoding(): void {
		$urls = \agend_apps_connect_sp_urls( 'https://api.example.test', 'my slug/x' );

		$this->assertSame( 'https://api.example.test/api/auth/sso/my slug/x/metadata', $urls['sp_entity_id'] );
	}

	#[Test]
	public function should_return_all_empty_when_the_root_url_is_empty(): void {
		$this->assertSame(
			array( 'sp_entity_id' => '', 'sp_acs_url' => '', 'sp_metadata_url' => '' ),
			\agend_apps_connect_sp_urls( '', 'wdaa' )
		);
	}

	#[Test]
	public function should_return_all_empty_when_the_account_slug_is_empty(): void {
		$this->assertSame(
			array( 'sp_entity_id' => '', 'sp_acs_url' => '', 'sp_metadata_url' => '' ),
			\agend_apps_connect_sp_urls( 'https://api.example.test', '' )
		);
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_connection_slug()
	// -----------------------------------------------------------------

	#[Test]
	public function should_derive_a_deterministic_slug_with_the_wp_saml_prefix(): void {
		$slug_a = \agend_apps_connect_connection_slug( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );
		$slug_b = \agend_apps_connect_connection_slug( 'https://gw.example.test/api/auth/sso/wdaa/metadata' );

		$this->assertSame( $slug_a, $slug_b );
		$this->assertStringStartsWith( 'wp-saml-', $slug_a );
	}

	#[Test]
	public function should_derive_different_slugs_for_different_entity_ids(): void {
		$this->assertNotSame(
			\agend_apps_connect_connection_slug( 'https://gw.example.test/a' ),
			\agend_apps_connect_connection_slug( 'https://gw.example.test/b' )
		);
	}

	#[Test]
	public function should_return_empty_slug_for_an_empty_entity_id(): void {
		$this->assertSame( '', \agend_apps_connect_connection_slug( '' ) );
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_missing_scopes()
	// -----------------------------------------------------------------

	#[Test]
	public function should_report_no_missing_scopes_when_both_are_held(): void {
		$this->assertSame(
			array( 'missing' => array(), 'unknown' => false ),
			\agend_apps_connect_missing_scopes()
		);
	}

	#[Test]
	public function should_report_the_missing_scope_when_only_one_is_held(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'sso.connections.browse' ) ) ) );
		Agend_Apps_Key_Scopes::refresh();

		$this->assertSame(
			array( 'missing' => array( 'sso.connections.create' ), 'unknown' => false ),
			\agend_apps_connect_missing_scopes()
		);
	}

	#[Test]
	public function should_report_both_scopes_as_unknown_when_never_fetched(): void {
		delete_option( 'agend_apps_key_scopes' );

		$result = \agend_apps_connect_missing_scopes();

		$this->assertTrue( $result['unknown'] );
		$this->assertSame( \agend_apps_connect_required_scopes(), $result['missing'] );
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_find_existing()
	// -----------------------------------------------------------------

	#[Test]
	public function should_find_the_connection_matching_the_idp_entity_id(): void {
		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'connections' => array(
						array( 'id' => '1', 'idp_entity_id' => 'https://gw.example.test/other' ),
						array( 'id' => '2', 'idp_entity_id' => 'https://gw.example.test/mine' ),
					),
				),
			)
		);

		$found = \agend_apps_connect_find_existing( 'https://gw.example.test/mine' );

		$this->assertIsArray( $found );
		$this->assertSame( '2', $found['id'] );
	}

	#[Test]
	public function should_return_null_when_no_page_has_a_match(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'connections' => array() ) ) );

		$this->assertNull( \agend_apps_connect_find_existing( 'https://gw.example.test/mine' ) );
	}

	#[Test]
	public function should_surface_a_wp_error_from_the_list_call(): void {
		Agend_Test_WP::queue_response( 500, '' );

		$found = \agend_apps_connect_find_existing( 'https://gw.example.test/mine' );

		$this->assertInstanceOf( WP_Error::class, $found );
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_member_roles()
	// -----------------------------------------------------------------

	#[Test]
	public function should_exclude_administrator_from_the_allow_list(): void {
		$roles = \agend_apps_connect_member_roles();

		$this->assertNotContains( 'administrator', $roles );
		$this->assertContains( 'subscriber', $roles );
		$this->assertContains( 'editor', $roles );
	}

	#[Test]
	public function should_never_return_an_empty_allow_list(): void {
		Agend_Test_WP::$roles = array( 'administrator' => 'Administrator' );

		$this->assertSame( array( 'subscriber' ), \agend_apps_connect_member_roles() );
	}

	#[Test]
	public function should_never_return_an_empty_allow_list_even_when_the_filter_empties_it(): void {
		add_filter( 'agend_apps_connect_member_roles', static function () {
			return array();
		} );

		$this->assertSame( array( 'subscriber' ), \agend_apps_connect_member_roles() );
	}

	#[Test]
	public function should_honour_the_member_roles_filter(): void {
		add_filter( 'agend_apps_connect_member_roles', static function () {
			return array( 'custom_role' );
		} );

		$this->assertSame( array( 'custom_role' ), \agend_apps_connect_member_roles() );
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_sp_data()
	// -----------------------------------------------------------------

	#[Test]
	public function should_build_the_sp_data_with_the_expected_security_settings(): void {
		$sp_data = \agend_apps_connect_sp_data(
			array( 'sp_acs_url' => 'https://api.example.test/api/auth/sso/wdaa/acs' )
		);

		$this->assertSame( 'Agend', $sp_data['name'] );
		$this->assertSame( 'https://api.example.test/api/auth/sso/wdaa/acs', $sp_data['acs_url'] );
		$this->assertTrue( $sp_data['enabled'] );
		$this->assertTrue( $sp_data['assertion_signed'] );
		$this->assertTrue( $sp_data['response_signed'] );
		$this->assertSame( 'sha256', $sp_data['signature_algorithm'] );
		$this->assertSame( 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified', $sp_data['nameid_format'] );
		$this->assertFalse( $sp_data['allow_unsolicited_sso'] );
		$this->assertArrayNotHasKey( 'allow_insecure', $sp_data );
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_attribute_mapping()
	// -----------------------------------------------------------------

	#[Test]
	public function should_build_the_exact_attribute_mapping_shape(): void {
		$this->assertSame(
			array(
				'nameid_attribute' => 'imk_membership_number',
				'user_attributes'  => array(
					'user_email'   => 'email',
					'first_name'   => 'first_name',
					'last_name'    => 'last_name',
					'display_name' => 'display_name',
				),
				'group_mapping'    => array(
					'enabled'        => true,
					'attribute_name' => 'groups',
				),
			),
			\agend_apps_connect_attribute_mapping( 'imk_membership_number' )
		);
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_nameid_meta_key()
	// -----------------------------------------------------------------

	#[Test]
	public function should_use_the_configured_meta_key_when_explicitly_set(): void {
		update_option( 'agend_apps_external_id_meta_key', 'custom_meta_key' );

		$this->assertSame( 'custom_meta_key', \agend_apps_connect_nameid_meta_key() );
	}

	#[Test]
	public function should_use_the_minted_guid_meta_when_unconfigured_in_wordpress_mode(): void {
		delete_option( 'agend_apps_external_id_meta_key' );

		$this->assertSame( \AGEND_APPS_EXTERNAL_ID_META, \agend_apps_connect_nameid_meta_key() );
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_connection_payload()
	// -----------------------------------------------------------------

	#[Test]
	public function should_build_the_connection_payload_with_no_role_mapping_and_the_lowest_role(): void {
		$payload = \agend_apps_connect_connection_payload(
			array(
				'entity_id'   => 'https://gw.example.test/api/auth/sso/wdaa/metadata',
				'idp_sso_url' => 'https://gw.example.test/?idp_initiated=1',
				'certificate' => '-----BEGIN CERTIFICATE-----abc-----END CERTIFICATE-----',
			)
		);

		$this->assertFalse( $payload['jit_contact_provisioning'] );
		$this->assertSame( 'contact', $payload['default_role'] );
		$this->assertArrayNotHasKey( 'role_attribute', $payload );
		$this->assertArrayNotHasKey( 'role_mapping', $payload );
		$this->assertArrayNotHasKey( 'role_attribute', $payload['attribute_mappings'] );
		$this->assertArrayNotHasKey( 'role_mapping', $payload['attribute_mappings'] );
		$this->assertSame( 'https://gw.example.test/api/auth/sso/wdaa/metadata', $payload['idp_entity_id'] );
		$this->assertSame( 'https://gw.example.test/?idp_initiated=1', $payload['idp_sso_url'] );
		$this->assertTrue( $payload['jit_provisioning'] );
		$this->assertTrue( $payload['allow_idp_initiated'] );
		$this->assertTrue( $payload['want_assertions_signed'] );
		$this->assertSame(
			\agend_apps_connect_connection_slug( 'https://gw.example.test/api/auth/sso/wdaa/metadata' ),
			$payload['slug']
		);
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_store() / agend_apps_connect_stored()
	// -----------------------------------------------------------------

	#[Test]
	public function should_round_trip_the_stored_connection_including_site_url(): void {
		\agend_apps_connect_store(
			array(
				'id'             => 'conn-1',
				'slug'           => 'wp-saml-abc123',
				'approval_state' => 'pending',
				'idp_entity_id'  => 'https://example.test/saml/metadata',
			),
			array(
				'sp_entity_id'    => 'https://api.example.test/api/auth/sso/wdaa/metadata',
				'sp_acs_url'      => 'https://api.example.test/api/auth/sso/wdaa/acs',
				'sp_metadata_url' => 'https://api.example.test/api/auth/sso/wdaa/metadata',
			)
		);

		$stored = \agend_apps_connect_stored();

		$this->assertSame( 'conn-1', $stored['id'] );
		$this->assertSame( 'wp-saml-abc123', $stored['slug'] );
		$this->assertSame( 'pending', $stored['approval_state'] );
		$this->assertSame( 'https://example.test/saml/metadata', $stored['idp_entity_id'] );
		$this->assertSame( 'https://api.example.test/api/auth/sso/wdaa/acs', $stored['sp_acs_url'] );
		$this->assertNotSame( '', $stored['site_url'] );
		$this->assertGreaterThan( 0, $stored['connected_at'] );
	}

	#[Test]
	public function should_return_a_fully_defaulted_shape_when_nothing_is_stored(): void {
		delete_option( 'agend_apps_sso_connection' );

		$this->assertSame(
			array(
				'id'              => '',
				'slug'            => '',
				'approval_state'  => '',
				'idp_entity_id'   => '',
				'sp_entity_id'    => '',
				'sp_acs_url'      => '',
				'sp_metadata_url' => '',
				'site_url'        => '',
				'connected_at'    => 0,
			),
			\agend_apps_connect_stored()
		);
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_approval_notice()
	// -----------------------------------------------------------------

	#[Test]
	public function should_return_the_verbatim_pending_wording(): void {
		$this->assertSame(
			'pending Agend approval, SSO inactive until approved',
			\agend_apps_connect_approval_notice( 'pending' )
		);
	}

	#[Test]
	public function should_return_a_non_empty_confirmation_for_approved(): void {
		$this->assertNotSame( '', \agend_apps_connect_approval_notice( 'approved' ) );
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_run()
	// -----------------------------------------------------------------

	private function seedIdpMetadata(): void {
		require_once __DIR__ . '/fixtures/wp-saml-idp-api-stub.php';
		Agend_Test_WP_SAML_IDP_Api::reset();
		Agend_Test_WP_SAML_IDP_Api::$idp_metadata = array(
			'entity_id'   => 'https://example.test/saml/metadata',
			'sso_url'     => 'https://example.test/saml/sso',
			'idp_sso_url' => 'https://example.test/?idp_initiated=1',
			'certificate' => '-----BEGIN CERTIFICATE-----abc-----END CERTIFICATE-----',
		);
	}

	#[Test]
	public function should_run_the_happy_path_end_to_end(): void {
		$this->seedIdpMetadata();

		// find_existing: no match on the first (only) page.
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'connections' => array() ) ) );
		// create_connection: succeeds.
		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'id'             => 'conn-1',
					'slug'           => 'wp-saml-abc123',
					'approval_state' => 'pending',
					'idp_entity_id'  => 'https://example.test/saml/metadata',
					'sp_entity_id'   => 'https://api.example.test/api/auth/sso/wdaa/metadata',
					'sp_acs_url'     => 'https://api.example.test/api/auth/sso/wdaa/acs',
					'sp_metadata_url' => 'https://api.example.test/api/auth/sso/wdaa/metadata',
				),
			)
		);

		$result = \agend_apps_connect_run();

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['sp_mismatch'] );
		$this->assertSame( 'conn-1', $result['connection']['id'] );

		$stored = \agend_apps_connect_stored();
		$this->assertSame( 'conn-1', $stored['id'] );
		$this->assertSame( 'pending', $stored['approval_state'] );

		$upsert_call = null;
		foreach ( Agend_Test_WP_SAML_IDP_Api::$calls as $call ) {
			if ( 'upsert_service_provider' === $call[0] ) {
				$upsert_call = $call;
			}
		}
		$this->assertNotNull( $upsert_call );
		$this->assertSame( 'https://api.example.test/api/auth/sso/wdaa/metadata', $upsert_call[1] );
	}

	#[Test]
	#[RunInSeparateProcess]
	public function should_report_idp_plugin_too_old_when_a_method_is_missing(): void {
		define( 'AGEND_TEST_SAML_IDP_API_OMIT_UPSERT', true );
		$this->seedIdpMetadata();

		$result = \agend_apps_connect_run();

		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'too old', $result['errors'][0] );
	}

	#[Test]
	public function should_skip_creation_when_already_connected(): void {
		$this->seedIdpMetadata();

		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'connections' => array(
						array(
							'id'              => 'existing-1',
							'idp_entity_id'   => 'https://example.test/saml/metadata',
							'approval_state'  => 'approved',
							'sp_entity_id'    => 'https://api.example.test/api/auth/sso/wdaa/metadata',
							'sp_acs_url'      => 'https://api.example.test/api/auth/sso/wdaa/acs',
							'sp_metadata_url' => 'https://api.example.test/api/auth/sso/wdaa/metadata',
						),
					),
				),
			)
		);

		$result = \agend_apps_connect_run();

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'existing-1', $result['connection']['id'] );

		$skipped = false;
		foreach ( $result['steps'] as $step ) {
			if ( 'connection' === $step['step'] && 'skipped' === $step['status'] ) {
				$skipped = true;
			}
		}
		$this->assertTrue( $skipped );
	}

	#[Test]
	public function should_refetch_on_a_409_conflict_from_create(): void {
		$this->seedIdpMetadata();

		// find_existing: no match initially.
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'connections' => array() ) ) );
		// create_connection: conflict.
		Agend_Test_WP::queue_response( 409, array( 'error' => array( 'code' => 'CONFLICT' ) ) );
		// find_existing (refetch): now finds it.
		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'connections' => array(
						array(
							'id'              => 'conn-refetched',
							'idp_entity_id'   => 'https://example.test/saml/metadata',
							'approval_state'  => 'approved',
							'sp_entity_id'    => 'https://api.example.test/api/auth/sso/wdaa/metadata',
							'sp_acs_url'      => 'https://api.example.test/api/auth/sso/wdaa/acs',
							'sp_metadata_url' => 'https://api.example.test/api/auth/sso/wdaa/metadata',
						),
					),
				),
			)
		);

		$result = \agend_apps_connect_run();

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'conn-refetched', $result['connection']['id'] );
	}

	#[Test]
	public function should_populate_sp_mismatch_when_the_response_urls_disagree_with_the_local_derivation(): void {
		$this->seedIdpMetadata();

		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'connections' => array() ) ) );
		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'id'              => 'conn-mismatch',
					'idp_entity_id'   => 'https://example.test/saml/metadata',
					'approval_state'  => 'pending',
					'sp_entity_id'    => 'https://gateway.other.test/api/auth/sso/wdaa/metadata',
					'sp_acs_url'      => 'https://gateway.other.test/api/auth/sso/wdaa/acs',
					'sp_metadata_url' => 'https://gateway.other.test/api/auth/sso/wdaa/metadata',
				),
			)
		);

		$result = \agend_apps_connect_run();

		$this->assertNotEmpty( $result['sp_mismatch'] );
		$this->assertSame( 'https://gateway.other.test/api/auth/sso/wdaa/metadata', $result['sp_mismatch']['authoritative']['sp_entity_id'] );
		$this->assertSame( 'https://api.example.test/api/auth/sso/wdaa/metadata', $result['sp_mismatch']['local']['sp_entity_id'] );
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_preflight()
	// -----------------------------------------------------------------

	#[Test]
	public function should_block_when_the_nameid_empty_count_is_positive(): void {
		$preflight = \agend_apps_connect_preflight( 1, 0 );

		$this->assertTrue( $preflight['blocks'] );
		$this->assertSame( 1, $preflight['nameid_empty'] );
	}

	#[Test]
	public function should_not_block_when_the_nameid_empty_count_is_zero(): void {
		$preflight = \agend_apps_connect_preflight( 0, 0 );

		$this->assertFalse( $preflight['blocks'] );
	}

	#[Test]
	public function should_never_block_on_the_credentials_member_count_alone(): void {
		$preflight = \agend_apps_connect_preflight( 0, 50 );

		$this->assertFalse( $preflight['blocks'] );
		$this->assertSame( 50, $preflight['credentials_members'] );
	}

	#[Test]
	public function should_report_the_resolved_nameid_meta_key(): void {
		update_option( 'agend_apps_external_id_meta_key', 'custom_meta_key' );

		$preflight = \agend_apps_connect_preflight( 0, 0 );

		$this->assertSame( 'custom_meta_key', $preflight['nameid_meta_key'] );
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_preflight_acknowledged()
	// -----------------------------------------------------------------

	#[Test]
	public function should_report_acknowledged_true_when_the_checkbox_field_is_checked(): void {
		$this->assertTrue( \agend_apps_connect_preflight_acknowledged( array( \AGEND_APPS_CONNECT_ACK_FIELD => '1' ) ) );
	}

	#[Test]
	public function should_report_acknowledged_false_when_the_field_is_absent(): void {
		$this->assertFalse( \agend_apps_connect_preflight_acknowledged( array() ) );
	}

	#[Test]
	public function should_report_acknowledged_false_when_the_field_is_empty(): void {
		$this->assertFalse( \agend_apps_connect_preflight_acknowledged( array( \AGEND_APPS_CONNECT_ACK_FIELD => '' ) ) );
	}

	// -----------------------------------------------------------------
	// agend_apps_connect_run() -- the NameID pre-flight step
	// -----------------------------------------------------------------

	/**
	 * Forces a non-zero NameID-empty count through the
	 * `agend_apps_connect_nameid_empty_count` filter, which
	 * {@see agend_apps_connect_nameid_empty_count()} applies on every return
	 * path including the one taken when `WP_User_Query` is unavailable. This
	 * suite has no database (see this file's header and `phpunit.xml.dist`),
	 * so that filter is what lets these tests drive
	 * `agend_apps_connect_run()` into its blocking branch. It is a real
	 * extension point, not a test-only seam: a large site can substitute its
	 * own cheaper or cached count the same way.
	 */
	private function seedNameidEmptyCount( int $count ): void {
		add_filter(
			'agend_apps_connect_nameid_empty_count',
			static function () use ( $count ) {
				return $count;
			}
		);
	}

	#[Test]
	public function should_stop_at_the_nameid_preflight_when_it_blocks_and_is_not_acknowledged(): void {
		$this->seedIdpMetadata();
		$this->seedNameidEmptyCount( 3 );

		$result = \agend_apps_connect_run( false );

		$this->assertNotEmpty( $result['errors'] );

		$preflight_step = null;
		foreach ( $result['steps'] as $step ) {
			if ( 'nameid_preflight' === $step['step'] ) {
				$preflight_step = $step;
			}
		}
		$this->assertNotNull( $preflight_step );
		$this->assertSame( 'error', $preflight_step['status'] );

		// Never reached the IdP-plugin-presence step.
		foreach ( $result['steps'] as $step ) {
			$this->assertNotSame( 'idp_api', $step['step'] );
		}

		$this->assertTrue( $result['preflight']['blocks'] );
		$this->assertSame( 3, $result['preflight']['nameid_empty'] );
	}

	#[Test]
	public function should_proceed_past_the_nameid_preflight_when_it_blocks_but_is_acknowledged(): void {
		$this->seedIdpMetadata();
		$this->seedNameidEmptyCount( 3 );

		// find_existing: no match on the first (only) page.
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'connections' => array() ) ) );
		// create_connection: succeeds.
		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'id'              => 'conn-ack',
					'slug'            => 'wp-saml-abc123',
					'approval_state'  => 'pending',
					'idp_entity_id'   => 'https://example.test/saml/metadata',
					'sp_entity_id'    => 'https://api.example.test/api/auth/sso/wdaa/metadata',
					'sp_acs_url'      => 'https://api.example.test/api/auth/sso/wdaa/acs',
					'sp_metadata_url' => 'https://api.example.test/api/auth/sso/wdaa/metadata',
				),
			)
		);

		$result = \agend_apps_connect_run( true );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'conn-ack', $result['connection']['id'] );

		$preflight_step = null;
		foreach ( $result['steps'] as $step ) {
			if ( 'nameid_preflight' === $step['step'] ) {
				$preflight_step = $step;
			}
		}
		$this->assertNotNull( $preflight_step );
		$this->assertSame( 'ok', $preflight_step['status'] );
		$this->assertStringContainsString( '3', $preflight_step['message'] );

		$this->assertTrue( $result['preflight']['blocks'] );
	}

	#[Test]
	public function should_carry_the_preflight_result_when_proceeding(): void {
		$this->seedIdpMetadata();

		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'connections' => array() ) ) );
		Agend_Test_WP::queue_response(
			200,
			array(
				'data' => array(
					'id'              => 'conn-preflight-ok',
					'slug'            => 'wp-saml-abc123',
					'approval_state'  => 'pending',
					'idp_entity_id'   => 'https://example.test/saml/metadata',
					'sp_entity_id'    => 'https://api.example.test/api/auth/sso/wdaa/metadata',
					'sp_acs_url'      => 'https://api.example.test/api/auth/sso/wdaa/acs',
					'sp_metadata_url' => 'https://api.example.test/api/auth/sso/wdaa/metadata',
				),
			)
		);

		$result = \agend_apps_connect_run();

		$this->assertArrayHasKey( 'preflight', $result );
		$this->assertFalse( $result['preflight']['blocks'] );
	}
}
