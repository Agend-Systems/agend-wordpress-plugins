<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Identity_Admin;
use Agend_Apps_Settings;
use PHPUnit\Framework\Attributes\Test;

// Agend_Apps_Settings is a bootstrap-time test double (tests/doubles.php),
// mirroring the real includes/class-agend-apps-settings.php -- requiring the
// real file here would redeclare the class and fatal. The double's
// sso_link_mechanism()/detection helpers were added alongside the real
// class's, so this exercises the same decision the real class makes.
require_once AGEND_TESTS_ROOT . '/agend-apps-core/admin/class-agend-apps-identity-admin.php';

/**
 * The SSO link mechanism setting: the closed three-value vocabulary (`auto`,
 * `saml`, `disabled` -- the retired `server` value normalises to `auto`, see
 * {@see Agend_Apps_Settings::normalize_sso_link_mechanism()}), its sanitiser,
 * and the `auto` resolver that consults IdP detection. Also covers the
 * `agend_apps_sso_link_on_user_create` boolean.
 *
 * An Agend identity may only be created from a signed SAML assertion, so
 * `auto` has exactly two outcomes: `saml` when a SAML IdP plugin is detected,
 * `disabled` otherwise. `includes/wp-idp-saml-link.php` is what actually
 * links a member when the resolved mechanism is `saml`.
 *
 * IdP presence is detected via real `class_exists()`/`defined()` checks
 * against classes/constants a plugin would declare -- there is no way to
 * "undeclare" a PHP class or constant once defined, so the tests below that
 * need the SAML IdP plugin ABSENT are ordered before the ones that define its
 * stub classes. `define_saml_idp_stub_classes()` defines each class only if
 * missing, so it is safe to call more than once and safe regardless of which
 * of the two classes a still-partial declaration already introduced.
 */
final class SsoLinkMechanismTest extends TestCase {

	// -----------------------------------------------------------------
	// Agend_Apps_Identity_Admin::sanitize_sso_link_mechanism()
	// -----------------------------------------------------------------

	#[Test]
	public function should_sanitise_each_valid_mechanism_to_itself(): void {
		$admin = new Agend_Apps_Identity_Admin();

		$this->assertSame( 'auto', $admin->sanitize_sso_link_mechanism( 'auto' ) );
		$this->assertSame( 'saml', $admin->sanitize_sso_link_mechanism( 'saml' ) );
		$this->assertSame( 'disabled', $admin->sanitize_sso_link_mechanism( 'disabled' ) );
	}

	#[Test]
	public function should_sanitise_an_unrecognised_value_to_auto(): void {
		$admin = new Agend_Apps_Identity_Admin();

		$this->assertSame( 'auto', $admin->sanitize_sso_link_mechanism( 'garbage' ) );
		$this->assertSame( 'auto', $admin->sanitize_sso_link_mechanism( '' ) );
		$this->assertSame( 'auto', $admin->sanitize_sso_link_mechanism( 'SAML' ) );
		$this->assertSame( 'auto', $admin->sanitize_sso_link_mechanism( null ) );
	}

	#[Test]
	public function should_sanitise_the_retired_server_value_to_auto(): void {
		// `server` (Agend_Apps_Settings::SSO_LINK_MECHANISM_SERVER) is retired:
		// an Agend identity may only be created from a signed SAML assertion,
		// so there is no longer a mechanism it names. A site upgrading past
		// its retirement re-evaluates via `auto` rather than fataling or
		// silently keeping a mechanism that no longer runs.
		$admin = new Agend_Apps_Identity_Admin();

		$this->assertSame( 'auto', $admin->sanitize_sso_link_mechanism( 'server' ) );
	}

	#[Test]
	public function should_normalise_an_unrecognised_stored_value_to_auto(): void {
		// Exercises the same fallback the resolver relies on for a value that
		// reached the option some way other than the sanitiser above (a
		// filter, a direct DB edit, a rolled-back version that wrote a value
		// no longer in the vocabulary).
		$this->assertSame( 'auto', Agend_Apps_Settings::normalize_sso_link_mechanism( 'not-a-real-mechanism' ) );
	}

	#[Test]
	public function should_normalise_the_retired_server_value_to_auto(): void {
		$this->assertSame( 'auto', Agend_Apps_Settings::normalize_sso_link_mechanism( 'server' ) );
	}

	// -----------------------------------------------------------------
	// Agend_Apps_Identity_Admin::sanitize_idp_plugin()
	// -----------------------------------------------------------------

	#[Test]
	public function should_sanitise_each_valid_idp_plugin_value_to_itself(): void {
		$admin = new Agend_Apps_Identity_Admin();

		$this->assertSame( 'auto', $admin->sanitize_idp_plugin( 'auto' ) );
		$this->assertSame( 'saml', $admin->sanitize_idp_plugin( 'saml' ) );
		$this->assertSame( 'miniorange', $admin->sanitize_idp_plugin( 'miniorange' ) );
		$this->assertSame( 'none', $admin->sanitize_idp_plugin( 'none' ) );
	}

	#[Test]
	public function should_sanitise_an_unrecognised_idp_plugin_value_to_auto(): void {
		$admin = new Agend_Apps_Identity_Admin();

		$this->assertSame( 'auto', $admin->sanitize_idp_plugin( 'garbage' ) );
		$this->assertSame( 'auto', $admin->sanitize_idp_plugin( '' ) );
		$this->assertSame( 'auto', $admin->sanitize_idp_plugin( null ) );
	}

	// -----------------------------------------------------------------
	// Detection and the auto resolver -- ABSENCE first (see class docblock).
	// -----------------------------------------------------------------

	#[Test]
	public function should_not_detect_a_saml_idp_plugin_when_neither_class_exists(): void {
		$this->assertFalse( Agend_Apps_Settings::saml_idp_plugin_present() );
		$this->assertSame( '', Agend_Apps_Settings::detected_idp_plugin() );
	}

	#[Test]
	public function should_not_detect_a_saml_idp_plugin_from_only_one_of_the_two_required_classes(): void {
		// agend-embed's own detection (class-agend-embed-sso-drivers.php:126-134)
		// requires BOTH classes; only one existing (a partial load, a
		// differently-versioned build) must not count as the plugin present.
		require_once __DIR__ . '/fixtures/saml-idp-partial-stub.php';

		$this->assertFalse( Agend_Apps_Settings::saml_idp_plugin_present() );
	}

	#[Test]
	public function should_resolve_auto_to_disabled_when_no_saml_idp_plugin_is_present(): void {
		update_option( 'agend_apps_sso_link_mechanism', 'auto' );

		$this->assertFalse( Agend_Apps_Settings::saml_idp_plugin_present() );
		$this->assertSame( 'disabled', Agend_Apps_Settings::sso_link_mechanism() );
	}

	#[Test]
	public function should_default_to_auto_resolved_when_the_option_is_missing(): void {
		$this->assertSame( 'disabled', Agend_Apps_Settings::sso_link_mechanism() );
	}

	#[Test]
	public function should_return_saml_as_the_detected_plugin_when_explicitly_selected_with_no_plugin_present(): void {
		update_option( 'agend_apps_idp_plugin', 'saml' );

		$this->assertFalse( Agend_Apps_Settings::saml_idp_plugin_present() );
		$this->assertSame( 'saml', Agend_Apps_Settings::detected_idp_plugin() );
	}

	#[Test]
	public function should_return_miniorange_as_the_detected_plugin_when_explicitly_selected_with_no_plugin_present(): void {
		update_option( 'agend_apps_idp_plugin', 'miniorange' );
		update_option( 'agend_apps_sso_link_mechanism', 'auto' );

		$this->assertFalse( Agend_Apps_Settings::miniorange_idp_plugin_present() );
		$this->assertSame( 'miniorange', Agend_Apps_Settings::detected_idp_plugin() );
		// miniOrange asserts its own NameID, so it still counts as "an IdP is
		// detected" for `auto` even though this plugin only builds against
		// agend-saml-idp's own registry (includes/wp-idp-saml-link.php);
		// nothing in `sso_link_mechanism()` distinguishes which SAML IdP.
		$this->assertSame( 'saml', Agend_Apps_Settings::sso_link_mechanism() );
	}

	// -----------------------------------------------------------------
	// Detection and the auto resolver -- PRESENCE from here on.
	// -----------------------------------------------------------------

	#[Test]
	public function should_resolve_auto_to_saml_when_a_saml_idp_plugin_is_present(): void {
		$this->define_saml_idp_stub_classes();
		update_option( 'agend_apps_sso_link_mechanism', 'auto' );

		$this->assertTrue( Agend_Apps_Settings::saml_idp_plugin_present() );
		$this->assertSame( 'saml', Agend_Apps_Settings::sso_link_mechanism() );
		$this->assertSame( 'saml', Agend_Apps_Settings::detected_idp_plugin() );
	}

	#[Test]
	public function should_resolve_explicit_saml_to_saml_regardless_of_detection(): void {
		update_option( 'agend_apps_sso_link_mechanism', 'saml' );

		$this->assertSame( 'saml', Agend_Apps_Settings::sso_link_mechanism() );
	}

	#[Test]
	public function should_resolve_a_stored_server_value_as_auto_even_when_a_saml_idp_plugin_is_present(): void {
		// The retired `server` value normalises to `auto` before the resolver
		// ever sees it (Agend_Apps_Settings::normalize_sso_link_mechanism()),
		// so a site left with this stored value re-evaluates against
		// detection rather than keeping a mechanism that no longer runs.
		$this->define_saml_idp_stub_classes();
		update_option( 'agend_apps_sso_link_mechanism', 'server' );

		$this->assertTrue( Agend_Apps_Settings::saml_idp_plugin_present() );
		$this->assertSame( 'saml', Agend_Apps_Settings::sso_link_mechanism() );
	}

	#[Test]
	public function should_resolve_explicit_disabled_to_disabled(): void {
		update_option( 'agend_apps_sso_link_mechanism', 'disabled' );

		$this->assertSame( 'disabled', Agend_Apps_Settings::sso_link_mechanism() );
	}

	#[Test]
	public function should_return_no_detected_plugin_when_none_is_selected_even_with_a_saml_idp_plugin_present(): void {
		$this->define_saml_idp_stub_classes();
		update_option( 'agend_apps_idp_plugin', 'none' );
		update_option( 'agend_apps_sso_link_mechanism', 'auto' );

		$this->assertTrue( Agend_Apps_Settings::saml_idp_plugin_present() );
		$this->assertSame( '', Agend_Apps_Settings::detected_idp_plugin() );
		$this->assertSame( 'disabled', Agend_Apps_Settings::sso_link_mechanism() );
	}

	// -----------------------------------------------------------------
	// miniOrange detection
	// -----------------------------------------------------------------

	#[Test]
	public function should_detect_the_miniorange_idp_plugin_from_its_version_constant(): void {
		if ( ! defined( 'MSI_VERSION' ) ) {
			define( 'MSI_VERSION', '9.9.9' );
		}

		$this->assertTrue( Agend_Apps_Settings::miniorange_idp_plugin_present() );
	}

	// -----------------------------------------------------------------
	// agend_apps_sso_link_on_user_create
	// -----------------------------------------------------------------

	#[Test]
	public function should_sanitise_the_link_on_create_checkbox_as_a_boolean(): void {
		$admin = new Agend_Apps_Identity_Admin();

		$this->assertTrue( $admin->sanitize_checkbox( '1' ) );
		$this->assertFalse( $admin->sanitize_checkbox( null ) );
		$this->assertFalse( $admin->sanitize_checkbox( '' ) );
	}

	#[Test]
	public function should_report_link_on_user_create_disabled_by_default(): void {
		$this->assertFalse( Agend_Apps_Settings::link_on_user_create() );
	}

	#[Test]
	public function should_report_link_on_user_create_enabled_when_the_option_is_set(): void {
		update_option( 'agend_apps_sso_link_on_user_create', true );

		$this->assertTrue( Agend_Apps_Settings::link_on_user_create() );
	}

	// -----------------------------------------------------------------
	// The detection filter, and the mechanisms it steers
	// -----------------------------------------------------------------

	/**
	 * miniOrange asserts a NameID of its own, exactly as agend-saml-idp does,
	 * so a miniOrange site left on server-to-server linking would link the
	 * same person twice. `auto` therefore has to resolve against any detected
	 * IdP, not only agend-saml-idp.
	 *
	 * Driven through the filter rather than by defining `MSI_VERSION`: a
	 * constant cannot be undefined once set, so it would leak into every test
	 * that runs after it. The filter registry is cleared per test by
	 * `TestCase::setUp()`.
	 */
	#[Test]
	public function should_resolve_auto_to_saml_when_miniorange_is_the_detected_plugin(): void {
		add_filter( 'agend_apps_detected_idp_plugin', static fn (): string => 'miniorange' );
		update_option( 'agend_apps_sso_link_mechanism', 'auto' );

		$this->assertSame( 'saml', Agend_Apps_Settings::sso_link_mechanism() );
	}

	#[Test]
	public function should_let_the_detection_filter_declare_a_third_party_idp(): void {
		add_filter( 'agend_apps_detected_idp_plugin', static fn (): string => 'keycloak' );
		update_option( 'agend_apps_sso_link_mechanism', 'auto' );

		$this->assertSame( 'keycloak', Agend_Apps_Settings::detected_idp_plugin() );
		$this->assertSame( 'saml', Agend_Apps_Settings::sso_link_mechanism() );
	}

	#[Test]
	public function should_let_the_detection_filter_override_an_explicit_none_selection(): void {
		update_option( 'agend_apps_idp_plugin', 'none' );
		add_filter( 'agend_apps_detected_idp_plugin', static fn (): string => 'keycloak' );

		$this->assertSame( 'keycloak', Agend_Apps_Settings::detected_idp_plugin() );
	}

	#[Test]
	public function should_let_the_detection_filter_override_a_present_plugin_to_none(): void {
		$this->define_saml_idp_stub_classes();
		add_filter( 'agend_apps_detected_idp_plugin', static fn (): string => '' );
		update_option( 'agend_apps_sso_link_mechanism', 'auto' );

		$this->assertTrue( Agend_Apps_Settings::saml_idp_plugin_present() );
		$this->assertSame( '', Agend_Apps_Settings::detected_idp_plugin() );
		$this->assertSame( 'disabled', Agend_Apps_Settings::sso_link_mechanism() );
	}

	/**
	 * Requires the fixture that defines the two classes `agend-saml-idp`
	 * registers, guarded per-class so it is safe to call from more than one
	 * test and safe even after the "only one class" test above has already
	 * introduced one of them.
	 */
	private function define_saml_idp_stub_classes(): void {
		require_once __DIR__ . '/fixtures/saml-idp-stub.php';
	}

	// -----------------------------------------------------------------
	// saml_nameid_attribute_for_agend_sp()
	//
	// The server-to-server mechanism this method's own consumer
	// (`link_mechanisms_are_identity_equivalent()`) supported is retired: an
	// Agend identity may only be created from a signed SAML assertion, so
	// there is no longer a case where the SAML NameID and a server-to-server
	// external id could name the same subject via two different mechanisms.
	// The method itself is retained (it identifies the Agend SP entry in
	// agend-saml-idp's mapping option by the same `/api/auth/sso/` + slug
	// shape `includes/wp-idp-saml-link.php` uses to identify the SAME entry
	// in a different agend-saml-idp option), so its own resolution behaviour
	// is still covered here.
	// -----------------------------------------------------------------

	#[Test]
	public function should_return_empty_nameid_attribute_when_no_mappings_are_configured(): void {
		$this->assertSame( '', Agend_Apps_Settings::saml_nameid_attribute_for_agend_sp() );
	}

	#[Test]
	public function should_pick_the_agend_sp_entry_by_the_sso_path_and_account_slug(): void {
		update_option( 'agend_apps_account_slug', 'wdaa' );
		update_option(
			'wp_saml_idp_attribute_mappings',
			array(
				'https://idp.example.test/some-other-sp'                  => array( 'nameid_attribute' => 'user_email' ),
				'https://api.agend.com.au/api/auth/sso/wdaa/metadata'      => array( 'nameid_attribute' => 'imk_membership_number' ),
				'https://api.agend.com.au/api/auth/sso/other-org/metadata' => array( 'nameid_attribute' => 'user_login' ),
			)
		);

		$this->assertSame( 'imk_membership_number', Agend_Apps_Settings::saml_nameid_attribute_for_agend_sp() );
	}
}
