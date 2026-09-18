<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Identity_Link_REST_Controller;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Settings;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sso.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-link.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-saml-link.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/identity-link-routes.php';

/**
 * The identity-link REST controller (`includes/rest/identity-link-routes.php`):
 * the ONLY place a per-user nonce'd SAML IdP URL is minted for the footer
 * placeholder's background fetch. Both the eligible and ineligible paths
 * return a 200 -- the browser must never be able to tell the two apart from
 * the HTTP status alone -- and the response always carries a `no-store`
 * Cache-Control header, since the entire cache-safety argument for the
 * footer placeholder rests on this response never being reused across
 * members.
 */
final class IdentityLinkRoutesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		update_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML );
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );

		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'sso.identities.read', 'sso.tokens.create' ) ) ) );
		Agend_Apps_Key_Scopes::refresh();
		Agend_Test_WP::$requests = array();
	}

	private function controller(): Agend_Apps_Identity_Link_REST_Controller {
		return new Agend_Apps_Identity_Link_REST_Controller();
	}

	/**
	 * Runs in its own process: it is the only test in this file that needs
	 * agend-saml-idp's `WP_SAML_IDP_Service_Provider` class actually declared
	 * (via the shared fixture) so the SP-readiness gate can resolve
	 * `registered`/`enabled`. Declaring it in the shared process would leak
	 * into SsoLinkMechanismTest's own "no SAML IdP plugin present" tests --
	 * see fixtures/saml-idp-stub.php's docblock -- and unlike
	 * WpIdpSamlLinkTest (alphabetically after SsoLinkMechanismTest), this
	 * file's name sorts before it, so there is no execution-order escape
	 * hatch available here.
	 */
	#[Test]
	#[RunInSeparateProcess]
	public function should_return_a_non_empty_url_and_200_for_an_eligible_member(): void {
		require_once __DIR__ . '/fixtures/saml-idp-stub.php';

		update_option(
			'wp_saml_idp_service_providers',
			array( array( 'entityId' => 'https://gw.example.test/api/auth/sso/wdaa/metadata' ) )
		);

		$GLOBALS['agend_test_current_user_id'] = 80;
		update_user_meta( 80, \AGEND_APPS_EXTERNAL_ID_META, 'ext-80' );

		$response = $this->controller()->get_sso_url( new WP_REST_Request( 'GET', '/agend-apps/v1/identity-link/sso-url' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotSame( '', $response->get_data()['url'] );
		$this->assertSame( '', $response->get_data()['reason'] );
	}

	#[Test]
	public function should_return_an_empty_url_a_reason_and_200_for_an_ineligible_member(): void {
		$GLOBALS['agend_test_current_user_id'] = 81;
		\agend_apps_wp_idp_record_link_state( 81, \AGEND_APPS_LINK_STATE_LINKED );

		$response = $this->controller()->get_sso_url( new WP_REST_Request( 'GET', '/agend-apps/v1/identity-link/sso-url' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $response->get_data()['url'] );
		$this->assertSame( 'linked', $response->get_data()['reason'] );
	}

	#[Test]
	public function should_carry_the_no_store_cache_control_header(): void {
		$GLOBALS['agend_test_current_user_id'] = 82;

		$response = $this->controller()->get_sso_url( new WP_REST_Request( 'GET', '/agend-apps/v1/identity-link/sso-url' ) );

		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
	}
}
