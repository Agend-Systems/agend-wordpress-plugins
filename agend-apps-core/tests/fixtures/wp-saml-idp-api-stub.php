<?php
/**
 * Test fixture: declares `WP_SAML_IDP_Api`, agend-saml-idp's programmatic API
 * (see that plugin's README.md, "Programmatic API (for other plugins)"),
 * simulating it being active for ConnectSiteTest. Class declarations cannot
 * be nested inside a test method (they would be nested inside the test
 * CLASS), so this lives in its own plain file, matching
 * fixtures/saml-idp-stub.php's convention.
 *
 * Every call is recorded on {@see Agend_Test_WP_SAML_IDP_Api}'s static state,
 * and every return value is configurable there, so a test can drive the happy
 * path, a save failure, or a mismatched SP-url response without a live IdP
 * plugin.
 *
 * To simulate an IdP plugin too old to carry `upsert_service_provider()` (a
 * genuine version-skew scenario `agend_apps_connect_run()` must detect via
 * `method_exists()`), define the constant `AGEND_TEST_SAML_IDP_API_OMIT_UPSERT`
 * (to any truthy value) BEFORE requiring this file. The class is declared
 * once per PHP process, so the test relying on the omission MUST run in its
 * own process (`#[RunInSeparateProcess]`) -- otherwise a later test in the
 * same process would find the class already declared without the method it
 * needs, or vice versa.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

/**
 * Records every call made and lets a test script canned answers.
 */
final class Agend_Test_WP_SAML_IDP_Api {

	/** @var array<string, mixed> */
	public static array $idp_metadata = array();

	/** @var array{success: bool, errors: string[]} */
	public static array $upsert_result = array(
		'success' => true,
		'errors'  => array(),
	);

	public static bool $mapping_result = true;

	public static bool $sso_settings_result = true;

	/** @var array<int, array{0: string, 1?: string, 2?: array}> */
	public static array $calls = array();

	public static function reset(): void {
		self::$idp_metadata        = array();
		self::$upsert_result       = array(
			'success' => true,
			'errors'  => array(),
		);
		self::$mapping_result      = true;
		self::$sso_settings_result = true;
		self::$calls               = array();
	}
}

if ( ! class_exists( 'WP_SAML_IDP_Api' ) ) {
	if ( defined( 'AGEND_TEST_SAML_IDP_API_OMIT_UPSERT' ) && AGEND_TEST_SAML_IDP_API_OMIT_UPSERT ) {
		/** Missing upsert_service_provider(): simulates an IdP plugin too old to carry it. */
		class WP_SAML_IDP_Api {
			public static function get_idp_metadata(): array {
				Agend_Test_WP_SAML_IDP_Api::$calls[] = array( 'get_idp_metadata' );

				return Agend_Test_WP_SAML_IDP_Api::$idp_metadata;
			}

			public static function save_attribute_mapping( string $entity_id, array $mapping ): bool {
				Agend_Test_WP_SAML_IDP_Api::$calls[] = array( 'save_attribute_mapping', $entity_id, $mapping );

				return Agend_Test_WP_SAML_IDP_Api::$mapping_result;
			}

			public static function save_sp_sso_settings( string $entity_id, array $settings ): bool {
				Agend_Test_WP_SAML_IDP_Api::$calls[] = array( 'save_sp_sso_settings', $entity_id, $settings );

				return Agend_Test_WP_SAML_IDP_Api::$sso_settings_result;
			}
		}
	} else {
		class WP_SAML_IDP_Api {
			public static function get_idp_metadata(): array {
				Agend_Test_WP_SAML_IDP_Api::$calls[] = array( 'get_idp_metadata' );

				return Agend_Test_WP_SAML_IDP_Api::$idp_metadata;
			}

			public static function upsert_service_provider( string $entity_id, array $data ): array {
				Agend_Test_WP_SAML_IDP_Api::$calls[] = array( 'upsert_service_provider', $entity_id, $data );

				return Agend_Test_WP_SAML_IDP_Api::$upsert_result;
			}

			public static function save_attribute_mapping( string $entity_id, array $mapping ): bool {
				Agend_Test_WP_SAML_IDP_Api::$calls[] = array( 'save_attribute_mapping', $entity_id, $mapping );

				return Agend_Test_WP_SAML_IDP_Api::$mapping_result;
			}

			public static function save_sp_sso_settings( string $entity_id, array $settings ): bool {
				Agend_Test_WP_SAML_IDP_Api::$calls[] = array( 'save_sp_sso_settings', $entity_id, $settings );

				return Agend_Test_WP_SAML_IDP_Api::$sso_settings_result;
			}
		}
	}
}
