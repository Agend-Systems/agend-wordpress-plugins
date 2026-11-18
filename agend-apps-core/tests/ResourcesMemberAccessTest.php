<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Key_Scopes;
use Agend_Apps_Resources_REST_Controller;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/fixtures/resources-fixtures.php';

/**
 * Resources member states in `wordpress` sign-in mode, where
 * `window.agendApps.loggedIn` is false for every member: the routes decide who
 * is a member from the member access state, the same answer the Member Summary
 * uses, and a member whose link is still being made is told so rather than to
 * sign in again.
 *
 * Each case runs in its own process because the fixtures declare the bearer
 * resolver and the SSO status read, which other tests load for real.
 */
#[RunTestsInSeparateProcesses]
final class ResourcesMemberAccessTest extends TestCase {

	private const USER_ID = 42;

	protected function setUp(): void {
		parent::setUp();
		$root = AGEND_TESTS_ROOT . '/agend-apps-core/includes/';

		agend_res_test_reset();
		require_once $root . 'class-agend-apps-secret-store.php';
		require_once $root . 'records/settings.php';
		require_once $root . 'records/pages.php';
		require_once $root . 'identity.php';
		require_once $root . 'wp-idp-link.php';
		require_once $root . 'wp-idp-diagnostics.php';
		require_once $root . 'member-provisioning.php';
		require_once $root . 'api/auth.php';
		require_once $root . 'class-agend-apps-member-session.php';
		require_once __DIR__ . '/fixtures/member-access-stubs.php';

		agend_ma_test_reset();
		agend_apps_member_access_forget();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$GLOBALS['agend_test_current_user_id'] = self::USER_ID;
	}

	private function mode( string $mode ): void {
		update_option( 'agend_apps_member_auth_mode', $mode );
	}

	private function keyCanMint(): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'sso.identities.read', 'sso.tokens.create', 'crm.contacts.browse' ), 'app_ids' => array() ) ) );
	}

	private function withExternalId(): void {
		update_user_meta( self::USER_ID, 'imk_membership_number', 'M-1001' );
		update_user_meta( self::USER_ID, AGEND_APPS_EXTERNAL_ID_META, 'guid-1001' );
	}

	private function controller(): Agend_Apps_Resources_REST_Controller {
		return new Agend_Apps_Resources_REST_Controller();
	}

	private function mine(): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/agend-apps/v1/resources/collections/mine' );

		foreach ( array(
			'topics'      => true,
			'description' => true,
			'excerpt'     => 140,
			'pages'       => 4,
			'level'       => 4,
		) as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->controller()->my_collections( $request );
	}

	private function ask(): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/resources/ask' );
		$request->set_param( 'question', 'How do I make a complaint?' );

		return $this->controller()->ask( $request );
	}

	/** @return array<string, mixed> */
	private function body( WP_REST_Response $response ): array {
		return (array) $response->get_data();
	}

	#[Test]
	public function should_serve_a_wordpress_mode_member_who_has_no_stored_credential_session(): void {
		$this->mode( 'wordpress' );
		agend_ma_test_bearer( 'member-token' );
		agend_res_test_queue_data( array( agend_res_test_member_collection( '09600000-0000-0000-0004-000000000001', 'Starter', array( 'Code of conduct' ) ) ) );
		agend_res_test_queue_collections( array() );

		$response = $this->mine();

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 1, $this->body( $response )['data']['count'] );
		self::assertStringContainsString( 'Starter', $this->body( $response )['data']['html'] );
	}

	#[Test]
	public function should_tell_a_member_whose_link_is_still_being_made_to_wait_not_to_sign_in_again(): void {
		$this->mode( 'wordpress' );
		$this->withExternalId();
		$this->keyCanMint();
		agend_ma_test_bearer( '' );

		$response = $this->ask();
		$body     = $this->body( $response );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'agend_apps_resources_no_member', $body['code'] );
		self::assertSame( 'connecting', $body['state'] );
		self::assertSame( 3, $body['retry_after'] );
		self::assertSame( 'Connecting your membership', $body['title'] );
		self::assertStringContainsString( 'first time you sign in', $body['message'] );
		self::assertStringNotContainsString( 'Sign in again', $body['message'] );
		self::assertSame( array(), Agend_Test_WP::$requests, 'nothing was asked of the gateway' );
	}

	#[Test]
	public function should_tell_a_member_waiting_on_an_email_check_to_check_their_email(): void {
		$this->mode( 'wordpress' );
		$this->withExternalId();
		$this->keyCanMint();
		agend_apps_wp_idp_record_link_state( self::USER_ID, 'pending' );
		agend_ma_test_bearer( '' );

		$body = $this->body( $this->mine() );

		self::assertSame( 'not_linked', $body['state'] );
		self::assertSame( 'Check your email', $body['title'] );
	}

	#[Test]
	public function should_never_send_the_reason_or_anything_written_for_an_administrator(): void {
		$this->mode( 'wordpress' );
		$this->withExternalId();
		$this->keyCanMint();
		agend_apps_wp_idp_record_link_state( self::USER_ID, 'conflict' );
		agend_ma_test_bearer( '' );
		// The test user can manage options by default, so the administrator's own
		// wording would be in the answer if the route passed it on.
		self::assertTrue( current_user_can( 'manage_options' ) );

		$json = (string) wp_json_encode( $this->body( $this->mine() ) );

		self::assertStringNotContainsString( 'link_conflict', $json );
		self::assertStringNotContainsString( 'reason', $json );
		self::assertStringNotContainsString( 'detail', $json );
	}

	#[Test]
	public function should_resolve_the_bearer_once_for_a_user_who_is_not_a_member(): void {
		$this->mode( 'wordpress' );
		$this->withExternalId();
		$this->keyCanMint();
		agend_ma_test_bearer( '' );

		$this->mine();

		self::assertSame( 1, $GLOBALS['agend_ma_test']['bearer_calls'], 'resolving can mint a token and can wait on another request, so it is asked once' );
	}

	#[Test]
	public function should_hold_a_user_who_keeps_asking_before_working_out_their_state(): void {
		// In `sso` mode, working out why a user is not linked asks Agend, once. A
		// user who asks over and over must be stopped before that, not after.
		$this->mode( 'sso' );
		$this->withExternalId();
		$this->keyCanMint();
		agend_ma_test_bearer( '' );

		for ( $i = 0; $i < AGEND_APPS_RESOURCES_MEMBER_SEARCH_LIMIT + 10; $i++ ) {
			$response = $this->mine();
		}

		self::assertSame( 429, $response->get_status() );
		self::assertSame( 'agend_apps_resources_busy', $this->body( $response )['code'] );
		self::assertSame( AGEND_APPS_RESOURCES_MEMBER_SEARCH_LIMIT, $GLOBALS['agend_ma_test']['sso_calls'] );
	}
}
