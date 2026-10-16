<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Member_Session;
use Agend_Apps_Secret_Store;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-member-session.php';

/**
 * The credential-login session store: encrypted at rest, refreshed once per
 * member however many requests need it, and deleted only when the gateway says
 * the refresh token is dead.
 *
 * The "other request" is simulated from the sleeper hook and from the moment
 * the refresh request leaves the plugin, as TokenWorkerSingleFlightTest does.
 */
final class MemberSessionTest extends TestCase {

	private const USER_ID = 42;

	/** @var int[] Microseconds of every sleep the store requested. */
	private array $sleeps = array();

	private Agend_Apps_Member_Session $provider;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_id'] = self::USER_ID;

		$this->sleeps = array();
		$this->onSleep( static function (): void {} );

		$this->provider = new Agend_Apps_Member_Session();
	}

	#[Test]
	public function should_store_the_session_encrypted_and_serve_its_access_token_when_it_is_fresh(): void {
		$this->storeSession( 'access-1', 'refresh-1', 3600 );

		$raw = get_user_meta( self::USER_ID, Agend_Apps_Member_Session::META_KEY, true );

		$this->assertIsString( $raw );
		$this->assertStringNotContainsString( 'access-1', $raw );
		$this->assertStringNotContainsString( 'refresh-1', base64_decode( $raw ) );
		$this->assertSame( 'access-1', $this->provider->provide_token( '' ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_encrypt_a_legacy_plain_text_session_in_place_when_it_is_first_read(): void {
		update_user_meta(
			self::USER_ID,
			Agend_Apps_Member_Session::META_KEY,
			array(
				'access_token'  => 'legacy-access',
				'refresh_token' => 'legacy-refresh',
				'expires_at'    => time() + 3600,
				'stored_at'     => time() - 60,
			)
		);

		$this->assertSame( 'legacy-access', $this->provider->provide_token( '' ) );

		$this->assertIsString( get_user_meta( self::USER_ID, Agend_Apps_Member_Session::META_KEY, true ) );
		$this->assertSame( 'legacy-refresh', $this->decryptedSession()['refresh_token'] );
	}

	#[Test]
	public function should_delete_the_session_and_pass_through_when_it_cannot_be_decrypted(): void {
		update_user_meta( self::USER_ID, Agend_Apps_Member_Session::META_KEY, base64_encode( random_bytes( 64 ) ) );

		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
		$this->assertSame( '', $this->provider->provide_token( '' ) );
		$this->assertSame( '', get_user_meta( self::USER_ID, Agend_Apps_Member_Session::META_KEY, true ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_refuse_a_session_value_copied_onto_another_users_row(): void {
		Agend_Apps_Member_Session::store(
			7,
			array(
				'access_token'  => 'someone-elses-access',
				'refresh_token' => 'someone-elses-refresh',
				'expires_at'    => time() + 3600,
			)
		);
		update_user_meta( self::USER_ID, Agend_Apps_Member_Session::META_KEY, get_user_meta( 7, Agend_Apps_Member_Session::META_KEY, true ) );

		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
		$this->assertSame( '', $this->provider->provide_token( '' ) );
		$this->assertTrue( Agend_Apps_Member_Session::has_session( 7 ) );
	}

	#[Test]
	public function should_report_a_stale_session_as_live_without_calling_the_gateway_or_writing(): void {
		$this->storeSession( 'stale-access', 'refresh-1', -600 );
		$raw = get_user_meta( self::USER_ID, Agend_Apps_Member_Session::META_KEY, true );

		$this->assertTrue( Agend_Apps_Member_Session::is_live( self::USER_ID ) );
		$this->assertTrue( Agend_Apps_Member_Session::has_session( self::USER_ID ) );

		$this->assertSame( array(), Agend_Test_WP::$requests );
		$this->assertSame( $raw, get_user_meta( self::USER_ID, Agend_Apps_Member_Session::META_KEY, true ) );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
	}

	#[Test]
	public function should_refresh_once_unattended_and_store_the_rotated_session_when_the_access_token_is_stale(): void {
		$this->storeSession( 'stale-access', 'refresh-1', -600 );
		$held_during_refresh = null;
		$this->onRefresh(
			function () use ( &$held_during_refresh ): void {
				$held_during_refresh = Agend_Test_WP::$options[ $this->lockName() ] ?? null;
			}
		);
		Agend_Test_WP::queue_response( 200, $this->sessionBody( 'access-2', 'refresh-2' ) );

		$token = $this->provider->provide_token( '' );

		$this->assertSame( 'access-2', $token );
		$this->assertCount( 1, $this->requestsTo( '/auth/refresh' ) );
		$this->assertArrayNotHasKey( 'Authorization', $this->requestsTo( '/auth/refresh' )[0]['headers'] );
		$this->assertSame( array( 'refresh_token' => 'refresh-1' ), json_decode( (string) $this->requestsTo( '/auth/refresh' )[0]['body'], true ) );
		$this->assertMatchesRegularExpression( '/^\d+:[0-9a-f]{16}$/', (string) $held_during_refresh );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
		$this->assertSame( 'refresh-2', $this->decryptedSession()['refresh_token'] );
	}

	#[Test]
	public function should_keep_the_session_when_the_refresh_fails_for_a_reason_other_than_the_token(): void {
		foreach ( array( 500, 502, 429 ) as $status ) {
			$this->storeSession( 'stale-access', 'refresh-1', -600 );
			Agend_Test_WP::queue_response( $status, array( 'error' => array( 'code' => 'INTERNAL_ERROR', 'message' => 'unavailable' ) ) );

			$this->assertSame( '', $this->provider->provide_token( '' ), "status {$status}" );
			$this->assertSame( 'refresh-1', $this->decryptedSession()['refresh_token'], "status {$status}" );
		}

		Agend_Test_WP::queue_transport_error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$this->assertSame( '', $this->provider->provide_token( '' ) );
		$this->assertSame( 'refresh-1', $this->decryptedSession()['refresh_token'] );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
	}

	#[Test]
	public function should_keep_the_session_when_a_401_is_about_the_api_key_not_the_token(): void {
		$this->storeSession( 'stale-access', 'refresh-1', -600 );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'INVALID_KEY', 'message' => 'Invalid API key' ) ) );

		$this->assertSame( '', $this->provider->provide_token( '' ) );
		$this->assertTrue( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
	}

	#[Test]
	public function should_delete_the_session_when_the_gateway_says_the_refresh_token_is_dead(): void {
		$this->storeSession( 'stale-access', 'refresh-1', -600 );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'UNAUTHORIZED', 'message' => 'refresh_token is invalid or has expired' ) ) );

		$this->assertSame( '', $this->provider->provide_token( '' ) );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
	}

	#[Test]
	public function should_keep_a_session_another_request_stored_when_this_refresh_fails_definitively(): void {
		$this->storeSession( 'stale-access', 'refresh-1', -600 );
		$this->onRefresh(
			function (): void {
				$this->storeSession( 'access-from-other-request', 'refresh-from-other-request', 3600 );
			}
		);
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'UNAUTHORIZED', 'message' => 'refresh_token is invalid or has expired' ) ) );

		$this->provider->provide_token( '' );

		$this->assertSame( 'refresh-from-other-request', $this->decryptedSession()['refresh_token'] );
	}

	#[Test]
	public function should_not_bring_a_session_back_when_a_sign_out_lands_during_the_refresh(): void {
		$this->storeSession( 'stale-access', 'refresh-1', -600 );
		$this->onRefresh(
			static function (): void {
				Agend_Apps_Member_Session::clear( self::USER_ID );
			}
		);
		Agend_Test_WP::queue_response( 200, $this->sessionBody( 'access-2', 'refresh-2' ) );

		$this->assertSame( '', $this->provider->provide_token( '' ) );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
	}

	#[Test]
	public function should_use_the_winners_session_without_refreshing_when_another_request_holds_the_lock(): void {
		$this->storeSession( 'stale-access', 'refresh-1', -600 );
		Agend_Test_WP::$options[ $this->lockName() ] = time() . ':winner';

		$this->onSleep(
			function (): void {
				$this->storeSession( 'winner-access', 'winner-refresh', 3600 );
				unset( Agend_Test_WP::$options[ $this->lockName() ] );
			}
		);

		$this->assertSame( 'winner-access', $this->provider->provide_token( '' ) );
		$this->assertCount( 0, $this->requestsTo( '/auth/refresh' ) );
		$this->assertCount( 1, $this->sleeps );
	}

	#[Test]
	public function should_pass_through_without_refreshing_when_the_winner_found_the_session_dead(): void {
		$this->storeSession( 'stale-access', 'refresh-1', -600 );
		Agend_Test_WP::$options[ $this->lockName() ] = time() . ':winner';

		$this->onSleep(
			static function (): void {
				Agend_Apps_Member_Session::clear( self::USER_ID );
			}
		);

		$this->assertSame( '', $this->provider->provide_token( '' ) );
		$this->assertCount( 0, $this->requestsTo( '/auth/refresh' ) );
	}

	#[Test]
	public function should_reset_the_guard_and_release_the_lock_when_the_refresh_throws(): void {
		$this->storeSession( 'stale-access', 'refresh-1', -600 );
		$this->onRefresh(
			static function (): void {
				throw new \RuntimeException( 'transport failure' );
			}
		);

		try {
			$this->provider->provide_token( '' );
			$this->fail( 'The refresh should have thrown.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'transport failure', $e->getMessage() );
		}

		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );

		unset( Agend_Test_WP::$filters['agend_apps_api_path'] );
		Agend_Test_WP::queue_response( 200, $this->sessionBody( 'access-after-throw', 'refresh-2' ) );

		$this->assertSame( 'access-after-throw', $this->provider->provide_token( '' ) );
	}

	private function lockName(): string {
		return Agend_Apps_Member_Session::REFRESH_LOCK_PREFIX . self::USER_ID;
	}

	private function storeSession( string $access, string $refresh, int $expires_in ): void {
		Agend_Apps_Member_Session::store(
			self::USER_ID,
			array(
				'access_token'  => $access,
				'refresh_token' => $refresh,
				'expires_at'    => time() + $expires_in,
			)
		);
	}

	/** @return array<string, mixed> */
	private function decryptedSession(): array {
		$raw = get_user_meta( self::USER_ID, Agend_Apps_Member_Session::META_KEY, true );

		return (array) json_decode( Agend_Apps_Secret_Store::decrypt( (string) $raw ), true );
	}

	private function sessionBody( string $access, string $refresh ): array {
		return array(
			'data' => array(
				'user'    => array( 'id' => 'u-1', 'email' => 'member@example.test' ),
				'session' => array(
					'access_token'  => $access,
					'refresh_token' => $refresh,
					'expires_at'    => time() + 3600,
					'expires_in'    => 3600,
					'token_type'    => 'bearer',
				),
			),
		);
	}

	/** @return array<int, array{url: string, headers: array<string, string>, body: mixed}> */
	private function requestsTo( string $suffix ): array {
		return array_values(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => str_ends_with( $request['url'], $suffix )
			)
		);
	}

	private function onSleep( callable $callback ): void {
		Agend_Test_WP::$filters['agend_apps_member_session_sleeper'] = fn() => function ( int $microseconds ) use ( $callback ): void {
			$this->sleeps[] = $microseconds;
			$callback();
		};
	}

	/**
	 * Runs a callback at the moment the refresh request leaves the plugin.
	 *
	 * @param callable $callback Called with no arguments.
	 */
	private function onRefresh( callable $callback ): void {
		Agend_Test_WP::$filters['agend_apps_api_path'] = static function ( $path ) use ( $callback ) {
			if ( '/auth/refresh' === $path ) {
				$callback();
			}

			return $path;
		};
	}
}
