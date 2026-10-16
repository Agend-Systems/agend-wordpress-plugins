<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_API;
use Agend_Apps_Token_Worker;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sso.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';

/**
 * A Supabase session is revoked server side whenever the member signs out
 * anywhere with global scope, so the token the worker cached can be dead long
 * before its recorded expiry. The API client announces a 401 on the bearer it
 * resolved (`agend_apps_bearer_rejected`) and the worker answers by
 * discarding that token, so the retry mints a new one instead of the member
 * silently getting the public view for up to an hour.
 */
final class TokenWorkerRejectionTest extends TestCase {

	private const USER_ID     = 42;
	private const EXTERNAL_ID = 'external-42';

	private Agend_Apps_Token_Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		// The mint is gated on the sso_account_link optional feature; seeded as
		// held, as TokenWorkerSingleFlightTest does, so these tests do not
		// depend on which other test files loaded records/features.php first.
		if ( class_exists( '\Agend_Apps_Key_Scopes' ) && function_exists( 'agend_apps_verify_api_key' ) ) {
			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'sso.identities.read', 'sso.tokens.create' ) ) ) );
			\Agend_Apps_Key_Scopes::refresh();
			Agend_Test_WP::$requests = array();
		}

		// Endpoint scope refusal is not what these tests are about.
		Agend_Test_WP::set_filter( 'agend_apps_api_block_unauthorised', false );

		update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://idp.example.test/metadata' ) );
		update_user_meta( self::USER_ID, agend_apps_external_id_meta_key(), self::EXTERNAL_ID );
		$GLOBALS['agend_test_current_user_id'] = self::USER_ID;

		$this->worker = new Agend_Apps_Token_Worker();
	}

	#[Test]
	public function should_clear_the_cached_token_when_the_gateway_rejects_it_with_a_401(): void {
		$this->storeToken( 'revoked-token', time() - 600 );

		$this->worker->handle_rejected( 'revoked-token', 401 );

		$this->assertSame( '', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	#[Test]
	public function should_clear_the_cached_token_when_the_api_client_announces_the_rejection(): void {
		$this->storeToken( 'revoked-token', time() - 600 );

		do_action( 'agend_apps_bearer_rejected', 'revoked-token', 401, '/events' );

		$this->assertSame( '', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	#[Test]
	public function should_keep_the_cached_token_when_the_rejected_token_is_another_providers(): void {
		$this->storeToken( 'worker-token', time() - 600 );

		$this->worker->handle_rejected( 'member-session-token', 401 );

		$this->assertSame( 'worker-token', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true )['access_token'] );
		$this->assertFalse( get_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . self::USER_ID ) );
	}

	#[Test]
	public function should_keep_the_cached_token_when_the_rejection_is_a_403(): void {
		$this->storeToken( 'member-token', time() - 600 );

		$this->worker->handle_rejected( 'member-token', 403 );

		$this->assertSame( 'member-token', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true )['access_token'] );
	}

	#[Test]
	public function should_do_nothing_when_no_user_is_signed_in(): void {
		$this->storeToken( 'revoked-token', time() - 600 );
		$GLOBALS['agend_test_current_user_id'] = 0;

		$this->worker->handle_rejected( 'revoked-token', 401 );

		$this->assertSame( 'revoked-token', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true )['access_token'] );
	}

	#[Test]
	public function should_do_nothing_when_no_token_is_cached(): void {
		$this->worker->handle_rejected( 'revoked-token', 401 );

		$this->assertSame( '', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true ) );
		$this->assertFalse( get_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . self::USER_ID ) );
	}

	#[Test]
	public function should_not_set_the_negative_cache_when_the_rejected_token_was_minted_long_ago(): void {
		$this->storeToken( 'revoked-token', time() - Agend_Apps_Token_Worker::REJECTED_REMINT_GRACE - 1 );

		$this->worker->handle_rejected( 'revoked-token', 401 );

		$this->assertSame( '', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true ) );
		$this->assertFalse( get_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . self::USER_ID ) );
	}

	#[Test]
	public function should_set_the_negative_cache_when_the_rejected_token_was_minted_moments_ago(): void {
		$this->storeToken( 'fresh-but-refused-token', time() - 5 );

		$this->worker->handle_rejected( 'fresh-but-refused-token', 401 );

		$this->assertSame( '', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true ) );
		$this->assertNotFalse( get_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . self::USER_ID ) );
	}

	#[Test]
	public function should_swallow_an_error_raised_while_handling_the_rejection(): void {
		$this->storeToken( 'revoked-token', time() - 600 );

		// An object with no string form makes the comparison throw an Error.
		$this->worker->handle_rejected( new \stdClass(), 401 );

		$this->assertSame( 'revoked-token', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true )['access_token'] );
	}

	#[Test]
	public function should_mint_a_new_token_and_retry_with_it_when_a_cached_token_is_rejected_on_a_get(): void {
		$this->storeToken( 'revoked-token', time() - 600 );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'Invalid token' ) ) );
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'fresh-token' ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'member-view' ) ) );

		$result = ( new Agend_Apps_API() )->request( 'GET', '/events' );

		$this->assertSame( array( 'data' => array( 'member-view' ), 'status_code' => 200 ), $result );
		$this->assertCount( 3, Agend_Test_WP::$requests );
		$this->assertSame( 'Bearer revoked-token', $this->authorisationOf( 0 ) );
		$this->assertStringEndsWith( '/sso/tokens', Agend_Test_WP::$requests[1]['url'] );
		$this->assertNull( $this->authorisationOf( 1 ), 'the mint itself goes out unattended' );
		$this->assertSame( 'Bearer fresh-token', $this->authorisationOf( 2 ) );
		$this->assertSame( 'fresh-token', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true )['access_token'] );
		$this->assertFalse( get_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . self::USER_ID ) );
	}

	#[Test]
	public function should_mint_once_a_minute_at_most_when_the_gateway_refuses_every_token_it_issues(): void {
		$this->storeToken( 'revoked-token', time() - 600 );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'Invalid token' ) ) );
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'refused-token' ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'Invalid token' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'public-view' ) ) );

		$api    = new Agend_Apps_API();
		$result = $api->request( 'GET', '/events' );

		$this->assertSame( array( 'data' => array( 'public-view' ), 'status_code' => 200 ), $result );
		$this->assertCount( 4, Agend_Test_WP::$requests );
		$this->assertSame( 'Bearer refused-token', $this->authorisationOf( 2 ) );
		$this->assertNull( $this->authorisationOf( 3 ), 'a second rejection falls back to the public view' );
		$this->assertSame( '', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true ) );

		Agend_Test_WP::$requests = array();
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'public-view' ) ) );

		$api->request( 'GET', '/events' );

		$this->assertCount( 1, Agend_Test_WP::$requests, 'the next request must not mint again inside the minute' );
		$this->assertNull( $this->authorisationOf( 0 ) );
	}

	#[Test]
	public function should_not_discard_the_cached_token_when_a_403_rejects_it(): void {
		$this->storeToken( 'member-token', time() - 600 );
		Agend_Test_WP::queue_response( 403, array( 'error' => array( 'message' => 'NOT_ACCOUNT_MEMBER' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'public-view' ) ) );

		( new Agend_Apps_API() )->request( 'GET', '/lms/courses' );

		$this->assertCount( 2, Agend_Test_WP::$requests, 'no mint: a new token cannot cure a 403' );
		$this->assertNull( $this->authorisationOf( 1 ) );
		$this->assertSame( 'member-token', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true )['access_token'] );
	}

	private function authorisationOf( int $request_index ): ?string {
		return Agend_Test_WP::$requests[ $request_index ]['headers']['Authorization'] ?? null;
	}

	private function storeToken( string $token, int $minted_at ): void {
		update_user_meta(
			self::USER_ID,
			Agend_Apps_Token_Worker::META_KEY,
			array(
				'access_token' => $token,
				'expires_at'   => time() + 3000,
				'external_id'  => self::EXTERNAL_ID,
				'minted_at'    => $minted_at,
			)
		);
	}

	private function mintBody( string $token ): array {
		return array(
			'data' => array(
				'access_token' => $token,
				'expires_at'   => time() + 3600,
			),
		);
	}
}
