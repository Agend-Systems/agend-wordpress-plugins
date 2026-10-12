<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Token_Worker;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sso.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';

/**
 * Concurrent requests for one member share a single `POST /v1/sso/tokens`: the
 * request holding the per-user lock mints, the others wait and read its token
 * from user meta. The gateway limits minting to 30 a minute per site key, and a
 * 429 renders the member logged out for the negative-cache window, so a mint
 * per concurrent request is a correctness problem as well as a slow one.
 *
 * The "other request" is simulated by writing the lock row and user meta
 * directly, from the sleeper hook where the winner finishes mid-wait.
 */
final class TokenWorkerSingleFlightTest extends TestCase {

	private const USER_ID     = 42;
	private const EXTERNAL_ID = 'external-42';

	private Agend_Apps_Token_Worker $worker;

	/** @var int[] Microseconds of every sleep the worker requested. */
	private array $sleeps = array();

	protected function setUp(): void {
		parent::setUp();

		// The mint is gated on the sso_account_link optional feature holding
		// sso.identities.read + sso.tokens.create; seeded as held, as
		// MembershipSyncTest does, so these tests do not depend on which other
		// test files loaded records/features.php earlier in the run.
		if ( class_exists( '\Agend_Apps_Key_Scopes' ) && function_exists( 'agend_apps_verify_api_key' ) ) {
			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'sso.identities.read', 'sso.tokens.create' ) ) ) );
			\Agend_Apps_Key_Scopes::refresh();
			Agend_Test_WP::$requests = array();
		}

		update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://idp.example.test/metadata' ) );
		update_user_meta( self::USER_ID, agend_apps_external_id_meta_key(), self::EXTERNAL_ID );
		$GLOBALS['agend_test_current_user_id'] = self::USER_ID;

		$this->sleeps = array();
		$this->onSleep( static function (): void {} );

		$this->worker = new Agend_Apps_Token_Worker();
	}

	#[Test]
	public function should_mint_once_and_release_the_lock_when_no_other_request_holds_it(): void {
		$held_during_mint = null;
		$this->onMint(
			static function () use ( &$held_during_mint ): void {
				$held_during_mint = Agend_Test_WP::$options[ Agend_Apps_Token_Worker::MINT_LOCK_PREFIX . self::USER_ID ] ?? null;
			}
		);
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'minted-1' ) );

		$token = $this->worker->provide_token( '' );

		$this->assertSame( 'minted-1', $token );
		$this->assertSame( 1, $this->mintRequests() );
		$this->assertMatchesRegularExpression( '/^\d+:[0-9a-f]{16}$/', (string) $held_during_mint );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
		$this->assertSame( 'minted-1', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true )['access_token'] );
		$this->assertSame( array(), $this->sleeps );
	}

	#[Test]
	public function should_not_touch_the_lock_when_a_valid_token_is_cached(): void {
		$this->storeToken( 'cached-token', self::EXTERNAL_ID, self::USER_ID );

		$token = $this->worker->provide_token( '' );

		$this->assertSame( 'cached-token', $token );
		$this->assertSame( 0, $this->mintRequests() );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
	}

	#[Test]
	public function should_use_the_winners_token_without_minting_when_another_request_holds_the_lock(): void {
		$this->holdLock( time() . ':winner' );
		Agend_Test_WP::$object_cache['user_meta'][ self::USER_ID ] = array( 'stale' );

		$this->onSleep(
			function (): void {
				$this->storeToken( 'winner-token', self::EXTERNAL_ID, self::USER_ID );
				unset( Agend_Test_WP::$options[ $this->lockName() ] );
			}
		);

		$token = $this->worker->provide_token( '' );

		$this->assertSame( 'winner-token', $token );
		$this->assertSame( 0, $this->mintRequests() );
		$this->assertCount( 1, $this->sleeps );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
		$this->assertArrayNotHasKey( self::USER_ID, Agend_Test_WP::$object_cache['user_meta'] ?? array(), 'the re-read must bypass this request\'s copy of user meta' );
	}

	#[Test]
	public function should_leave_the_winners_lock_in_place_when_it_reads_the_winners_token(): void {
		$this->holdLock( time() . ':winner' );
		$winner_lock = Agend_Test_WP::$options[ $this->lockName() ];

		$this->onSleep(
			function (): void {
				$this->storeToken( 'winner-token', self::EXTERNAL_ID, self::USER_ID );
			}
		);

		$token = $this->worker->provide_token( '' );

		$this->assertSame( 'winner-token', $token );
		$this->assertSame( 0, $this->mintRequests() );
		$this->assertSame( $winner_lock, Agend_Test_WP::$options[ $this->lockName() ] );
	}

	#[Test]
	public function should_break_a_lock_older_than_the_ttl_and_mint_without_waiting(): void {
		$this->holdLock( ( time() - Agend_Apps_Token_Worker::MINT_LOCK_TTL - 1 ) . ':crashed' );
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'minted-1' ) );

		$token = $this->worker->provide_token( '' );

		$this->assertSame( 'minted-1', $token );
		$this->assertSame( 1, $this->mintRequests() );
		$this->assertSame( array(), $this->sleeps );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
	}

	#[Test]
	public function should_mint_for_itself_after_the_bounded_wait_when_the_holder_never_finishes(): void {
		$holder_lock = ( time() - 2 ) . ':slow';
		$this->holdLock( $holder_lock );
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'minted-1' ) );

		$token = $this->worker->provide_token( '' );

		$this->assertSame( 'minted-1', $token, 'a timed-out wait must mint, never render anonymous' );
		$this->assertSame( 1, $this->mintRequests() );
		$this->assertSame( array_fill( 0, Agend_Apps_Token_Worker::MINT_WAIT_POLLS, Agend_Apps_Token_Worker::MINT_WAIT_POLL_MICROSECONDS ), $this->sleeps );
		$this->assertSame( $holder_lock, Agend_Test_WP::$options[ $this->lockName() ], 'a request that never took the lock must not delete the holder\'s' );
	}

	#[Test]
	public function should_use_a_token_minted_between_its_first_read_and_taking_the_lock(): void {
		$winner = function (): void {
			$this->storeToken( 'winner-token', self::EXTERNAL_ID, self::USER_ID );
		};

		$GLOBALS['wpdb'] = new class( $winner ) extends \wpdb {

			/** @var callable|null */
			private $before_insert;

			public function __construct( callable $before_insert ) {
				$this->before_insert = $before_insert;
			}

			public function query( string $query ) {
				if ( null !== $this->before_insert && str_starts_with( $query, 'INSERT IGNORE' ) ) {
					$before              = $this->before_insert;
					$this->before_insert = null;
					$before();
				}

				return parent::query( $query );
			}
		};

		$token = $this->worker->provide_token( '' );

		$this->assertSame( 'winner-token', $token );
		$this->assertSame( 0, $this->mintRequests() );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
	}

	#[Test]
	public function should_not_reuse_a_token_minted_for_a_different_external_id_when_waiting(): void {
		$this->holdLock( time() . ':winner' );
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'minted-1' ) );

		$this->onSleep(
			function (): void {
				$this->storeToken( 'other-identity-token', 'external-other', self::USER_ID );
			}
		);

		$token = $this->worker->provide_token( '' );

		$this->assertSame( 'minted-1', $token );
		$this->assertSame( 1, $this->mintRequests() );
	}

	#[Test]
	public function should_not_reuse_a_cached_token_minted_for_a_different_external_id(): void {
		$this->storeToken( 'other-identity-token', 'external-other', self::USER_ID );
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'minted-1' ) );

		$token = $this->worker->provide_token( '' );

		$this->assertSame( 'minted-1', $token );
		$this->assertSame( 1, $this->mintRequests() );
	}

	#[Test]
	public function should_not_read_another_users_token_when_waiting(): void {
		$this->holdLock( time() . ':winner' );
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'minted-1' ) );

		$this->onSleep(
			function (): void {
				$this->storeToken( 'other-user-token', self::EXTERNAL_ID, 43 );
			}
		);

		$token = $this->worker->provide_token( '' );

		$this->assertSame( 'minted-1', $token );
		$this->assertSame( 1, $this->mintRequests() );
	}

	#[Test]
	public function should_not_wait_on_another_users_lock(): void {
		Agend_Test_WP::$options[ Agend_Apps_Token_Worker::MINT_LOCK_PREFIX . '43' ] = time() . ':other-user';
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'minted-1' ) );

		$token = $this->worker->provide_token( '' );

		$this->assertSame( 'minted-1', $token );
		$this->assertSame( array(), $this->sleeps );
		$this->assertArrayHasKey( Agend_Apps_Token_Worker::MINT_LOCK_PREFIX . '43', Agend_Test_WP::$options );
	}

	#[Test]
	public function should_return_empty_without_minting_when_the_negative_cache_is_set_while_waiting(): void {
		$this->holdLock( time() . ':winner' );

		$this->onSleep(
			static function (): void {
				set_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . self::USER_ID, 1, 60 );
			}
		);

		$token = $this->worker->provide_token( '' );

		$this->assertSame( '', $token );
		$this->assertSame( 0, $this->mintRequests() );
		$this->assertCount( 1, $this->sleeps );
	}

	#[Test]
	public function should_return_empty_without_taking_the_lock_when_the_negative_cache_is_already_set(): void {
		set_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . self::USER_ID, 1, 60 );

		$token = $this->worker->provide_token( '' );

		$this->assertSame( '', $token );
		$this->assertSame( 0, $this->mintRequests() );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
	}

	#[Test]
	public function should_release_the_lock_and_negative_cache_when_the_mint_returns_an_error(): void {
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'boom' ) ) );

		$token = $this->worker->provide_token( '' );

		$this->assertSame( '', $token );
		$this->assertSame( 1, $this->mintRequests() );
		$this->assertNotFalse( get_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . self::USER_ID ) );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
	}

	#[Test]
	public function should_release_the_lock_when_the_mint_response_is_malformed(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'access_token' => 'no-expiry' ) ) );

		$token = $this->worker->provide_token( '' );

		$this->assertSame( '', $token );
		$this->assertSame( 1, $this->mintRequests() );
		$this->assertNotFalse( get_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . self::USER_ID ) );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
	}

	#[Test]
	public function should_not_delete_a_lock_another_request_took_over_when_its_own_mint_finishes(): void {
		$taken_over = time() . ':new-owner';
		$this->onMint(
			function () use ( $taken_over ): void {
				Agend_Test_WP::$options[ $this->lockName() ] = $taken_over;
			}
		);
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'minted-1' ) );

		$token = $this->worker->provide_token( '' );

		$this->assertSame( 'minted-1', $token );
		$this->assertSame( $taken_over, Agend_Test_WP::$options[ $this->lockName() ] );
	}

	private function lockName(): string {
		return Agend_Apps_Token_Worker::MINT_LOCK_PREFIX . self::USER_ID;
	}

	private function holdLock( string $value ): void {
		Agend_Test_WP::$options[ $this->lockName() ] = $value;
	}

	private function storeToken( string $token, string $external_id, int $user_id ): void {
		update_user_meta(
			$user_id,
			Agend_Apps_Token_Worker::META_KEY,
			array(
				'access_token' => $token,
				'expires_at'   => time() + 300,
				'external_id'  => $external_id,
				'minted_at'    => time(),
			)
		);
	}

	private function mintBody( string $token ): array {
		return array(
			'data' => array(
				'access_token' => $token,
				'expires_at'   => time() + 300,
			),
		);
	}

	private function mintRequests(): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => str_ends_with( $request['url'], '/sso/tokens' )
			)
		);
	}

	/**
	 * Replaces the worker's sleep with a recorder, so the suite never blocks.
	 *
	 * @param callable $callback Runs on each sleep, where the winner "finishes".
	 */
	private function onSleep( callable $callback ): void {
		Agend_Test_WP::$filters['agend_apps_token_worker_sleeper'] = fn() => function ( int $microseconds ) use ( $callback ): void {
			$this->sleeps[] = $microseconds;
			$callback();
		};
	}

	/**
	 * Runs a callback at the moment the mint request leaves the plugin.
	 *
	 * @param callable $callback Called with no arguments.
	 */
	private function onMint( callable $callback ): void {
		Agend_Test_WP::$filters['agend_apps_api_path'] = static function ( $path ) use ( $callback ) {
			if ( '/sso/tokens' === $path ) {
				$callback();
			}

			return $path;
		};
	}
}
