<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Token_Worker;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/sso.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';

/**
 * A member who keeps browsing never waits for a token mint: a request that
 * finds the token in its last two minutes uses it, and mints the next one
 * after the response has been sent.
 *
 * The clock and the finish-request function are replaced through the
 * worker's filters, so every boundary is exact and "after the response" is
 * observable.
 */
final class TokenWorkerRefreshAheadTest extends TestCase {

	private const USER_ID     = 42;
	private const EXTERNAL_ID = 'external-42';
	private const NOW         = 1800000000;

	private Agend_Apps_Token_Worker $worker;

	/** @var string[] What happened, in order: `finished` and `minted`. */
	private array $events = array();

	protected function setUp(): void {
		parent::setUp();

		if ( class_exists( '\Agend_Apps_Key_Scopes' ) && function_exists( 'agend_apps_verify_api_key' ) ) {
			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'sso.identities.read', 'sso.tokens.create' ) ) ) );
			\Agend_Apps_Key_Scopes::refresh();
			Agend_Test_WP::$requests = array();
		}

		Agend_Test_WP::set_filter( 'agend_apps_api_block_unauthorised', false );
		update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://idp.example.test/metadata' ) );
		update_user_meta( self::USER_ID, agend_apps_external_id_meta_key(), self::EXTERNAL_ID );
		$GLOBALS['agend_test_current_user_id'] = self::USER_ID;

		$this->events = array();
		Agend_Test_WP::set_filter( 'agend_apps_token_worker_clock', static fn(): int => self::NOW );
		Agend_Test_WP::set_filter( 'agend_apps_token_worker_sleeper', static function (): void {} );
		Agend_Test_WP::set_filter(
			'agend_apps_token_worker_finisher',
			function (): bool {
				$this->events[] = 'finished';
				return true;
			}
		);
		$this->onMint( static function (): void {} );

		$this->worker = new Agend_Apps_Token_Worker();
	}

	#[Test]
	public function should_return_the_token_and_schedule_nothing_when_more_than_120_seconds_remain(): void {
		$this->storeToken( 'current', 121 );

		$this->assertSame( 'current', $this->worker->provide_token( '' ) );
		$this->assertSame( array(), $this->refreshAheadPriorities() );
	}

	#[Test]
	public function should_schedule_one_refresh_ahead_mint_when_at_most_120_seconds_remain(): void {
		$this->storeToken( 'current', 120 );

		$this->assertSame( 'current', $this->worker->provide_token( '' ) );
		$this->assertSame( 'current', $this->worker->provide_token( '' ) );
		$this->assertSame( 'current', $this->worker->provide_token( '' ) );

		$this->assertSame( array( 9000 ), $this->refreshAheadPriorities() );
		$this->assertSame( 0, $this->mintRequests(), 'nothing is minted while the page renders' );
	}

	#[Test]
	public function should_schedule_a_refresh_ahead_mint_when_61_seconds_remain(): void {
		$this->storeToken( 'current', 61 );

		$this->assertSame( 'current', $this->worker->provide_token( '' ) );
		$this->assertSame( array( 9000 ), $this->refreshAheadPriorities() );
	}

	#[Test]
	public function should_send_the_response_before_minting_when_the_refresh_ahead_mint_runs(): void {
		$this->storeToken( 'current', 100 );
		$this->onMint(
			function (): void {
				$this->events[] = 'minted';
			}
		);
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'next', 300 ) );

		$this->worker->provide_token( '' );
		$this->runRefreshAhead();

		$this->assertSame( array( 'finished', 'minted' ), $this->events );
		$this->assertSame( 'next', $this->stored()['access_token'] );
	}

	#[Test]
	public function should_schedule_nothing_when_no_finish_request_function_exists(): void {
		unset( Agend_Test_WP::$filters['agend_apps_token_worker_finisher'] );
		$this->storeToken( 'current', 100 );

		$this->assertFalse( function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ), 'the CLI test runner has neither' );
		$this->assertSame( 'current', $this->worker->provide_token( '' ) );
		$this->assertSame( array(), $this->refreshAheadPriorities() );
	}

	#[Test]
	public function should_schedule_nothing_when_the_enabled_filter_returns_false(): void {
		Agend_Test_WP::set_filter( 'agend_apps_token_refresh_ahead_enabled', false );
		$this->storeToken( 'current', 100 );

		$this->assertSame( 'current', $this->worker->provide_token( '' ) );
		$this->assertSame( array(), $this->refreshAheadPriorities() );
	}

	#[Test]
	public function should_mint_nothing_when_another_request_holds_the_mint_lock(): void {
		$this->storeToken( 'current', 100 );
		$this->worker->provide_token( '' );
		Agend_Test_WP::$options[ $this->lockName() ] = self::NOW . ':other';

		$this->runRefreshAhead();

		$this->assertSame( 0, $this->mintRequests() );
		$this->assertSame( self::NOW . ':other', Agend_Test_WP::$options[ $this->lockName() ] );
		$this->assertSame( 'current', $this->stored()['access_token'] );
	}

	#[Test]
	public function should_mint_nothing_when_the_stored_token_was_refreshed_by_another_request(): void {
		$this->storeToken( 'current', 100 );
		$this->worker->provide_token( '' );
		$this->storeToken( 'refreshed-elsewhere', 300 );
		Agend_Test_WP::$object_cache['user_meta'][ self::USER_ID ] = array( 'stale' );

		$this->runRefreshAhead();

		$this->assertSame( 0, $this->mintRequests() );
		$this->assertArrayNotHasKey( self::USER_ID, Agend_Test_WP::$object_cache['user_meta'] ?? array(), 'the re-read must bypass the object cache' );
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options, 'the lock is released' );
	}

	#[Test]
	public function should_mint_nothing_when_the_stored_token_belongs_to_another_external_id(): void {
		$this->storeToken( 'current', 100 );
		$this->worker->provide_token( '' );
		$this->storeToken( 'current', 100, 'external-other' );

		$this->runRefreshAhead();

		$this->assertSame( 0, $this->mintRequests() );
	}

	#[Test]
	public function should_mint_nothing_when_the_stored_token_is_gone(): void {
		$this->storeToken( 'current', 100 );
		$this->worker->provide_token( '' );
		$this->worker->handle_logout( self::USER_ID );

		$this->runRefreshAhead();

		$this->assertSame( 0, $this->mintRequests() );
		$this->assertSame( '', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	#[Test]
	public function should_store_the_same_token_record_as_an_inline_mint_when_the_refresh_ahead_mint_succeeds(): void {
		$this->storeToken( 'current', 100 );
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'next', 300 ) );

		$this->worker->provide_token( '' );
		$this->runRefreshAhead();

		$this->assertSame(
			array(
				'access_token' => 'next',
				'expires_at'   => self::NOW + 300,
				'external_id'  => self::EXTERNAL_ID,
				'minted_at'    => self::NOW,
			),
			$this->stored()
		);
		$this->assertArrayNotHasKey( $this->lockName(), Agend_Test_WP::$options );
	}

	#[Test]
	public function should_leave_no_token_stored_when_the_member_logs_out_during_the_refresh_ahead_mint(): void {
		$this->storeToken( 'current', 100 );
		$this->onMint(
			function (): void {
				$this->worker->handle_logout( self::USER_ID );
			}
		);
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'next', 300 ) );

		$this->worker->provide_token( '' );
		$this->runRefreshAhead();

		$this->assertSame( 1, $this->mintRequests() );
		$this->assertSame( '', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	#[Test]
	public function should_keep_the_other_requests_token_when_it_replaces_the_token_during_the_mint(): void {
		$this->storeToken( 'current', 100 );
		$this->onMint(
			function (): void {
				$this->storeToken( 'inline-elsewhere', 300 );
			}
		);
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'next', 300 ) );

		$this->worker->provide_token( '' );
		$this->runRefreshAhead();

		$this->assertSame( 'inline-elsewhere', $this->stored()['access_token'] );
	}

	#[Test]
	public function should_keep_the_token_and_back_off_when_the_refresh_ahead_mint_is_refused(): void {
		$this->storeToken( 'current', 100 );
		Agend_Test_WP::queue_response( 429, array( 'error' => array( 'message' => 'Too many requests' ) ) );

		$this->worker->provide_token( '' );
		$this->runRefreshAhead();

		$this->assertSame( 'current', $this->stored()['access_token'] );
		$this->assertArrayNotHasKey( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . self::USER_ID, Agend_Test_WP::$transients );
		$this->assertSame( 1, Agend_Test_WP::$transients[ $this->backoffName() ] ?? null );
		$this->assertSame( 60, Agend_Test_WP::$transient_ttls[ $this->backoffName() ] ?? null );
	}

	#[Test]
	public function should_keep_the_token_and_back_off_when_the_refresh_ahead_mint_fails_in_transport(): void {
		$this->storeToken( 'current', 100 );
		Agend_Test_WP::queue_transport_error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$this->worker->provide_token( '' );
		$this->runRefreshAhead();

		$this->assertSame( 'current', $this->stored()['access_token'] );
		$this->assertArrayNotHasKey( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . self::USER_ID, Agend_Test_WP::$transients );
		$this->assertSame( 1, Agend_Test_WP::$transients[ $this->backoffName() ] ?? null );
	}

	#[Test]
	public function should_schedule_nothing_while_the_refresh_ahead_back_off_is_set(): void {
		set_transient( $this->backoffName(), 1, 60 );
		$this->storeToken( 'current', 100 );

		$this->assertSame( 'current', $this->worker->provide_token( '' ) );
		$this->assertSame( array(), $this->refreshAheadPriorities() );
	}

	#[Test]
	public function should_mint_inline_and_schedule_nothing_when_60_seconds_remain(): void {
		$this->storeToken( 'expiring', 60 );
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'inline', 300 ) );

		$this->assertSame( 'inline', $this->worker->provide_token( '' ) );
		$this->assertSame( 1, $this->mintRequests() );
		$this->assertSame( array(), $this->refreshAheadPriorities() );
	}

	#[Test]
	public function should_mint_inline_and_schedule_nothing_when_no_token_is_stored(): void {
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'inline', 300 ) );

		$this->assertSame( 'inline', $this->worker->provide_token( '' ) );
		$this->assertSame( array(), $this->refreshAheadPriorities() );
	}

	#[Test]
	public function should_schedule_nothing_when_no_user_is_signed_in(): void {
		$GLOBALS['agend_test_current_user_id'] = 0;
		$this->storeToken( 'current', 100 );

		$this->assertSame( '', $this->worker->provide_token( '' ) );
		$this->assertSame( array(), $this->refreshAheadPriorities() );
	}

	#[Test]
	public function should_schedule_nothing_during_wp_cron(): void {
		$GLOBALS['agend_test_doing_cron'] = true;
		$this->storeToken( 'current', 100 );

		$this->assertSame( 'current', $this->worker->provide_token( '' ) );
		$this->assertSame( array(), $this->refreshAheadPriorities() );
	}

	#[Test]
	#[RunInSeparateProcess]
	public function should_schedule_nothing_under_wp_cli(): void {
		define( 'WP_CLI', true );
		$this->storeToken( 'current', 100 );

		$this->assertSame( 'current', $this->worker->provide_token( '' ) );
		$this->assertSame( array(), $this->refreshAheadPriorities() );
	}

	#[Test]
	public function should_keep_the_new_token_out_of_every_transient_option_and_output(): void {
		$this->storeToken( 'current', 100 );
		Agend_Test_WP::queue_response( 200, $this->mintBody( 'next-secret-token', 300 ) );

		$this->worker->provide_token( '' );
		$this->expectOutputString( '' );
		$this->runRefreshAhead();

		$this->assertStringNotContainsString( 'next-secret-token', (string) json_encode( Agend_Test_WP::$transients ) );
		$this->assertStringNotContainsString( 'next-secret-token', (string) json_encode( Agend_Test_WP::$options ) );
		$this->assertSame( 'next-secret-token', $this->stored()['access_token'] );
	}

	#[Test]
	public function should_schedule_nothing_when_the_bearer_is_re_resolved_after_a_rejection(): void {
		$this->storeToken( 'current', 100 );

		$this->worker->handle_rejected( 'another-providers-token', 401 );

		$this->assertSame( 'current', $this->worker->provide_token( '' ) );
		$this->assertSame( array(), $this->refreshAheadPriorities() );
	}

	#[Test]
	public function should_mint_nothing_when_the_scheduled_token_was_cleared_as_rejected(): void {
		$this->storeToken( 'current', 100 );
		$this->worker->provide_token( '' );

		$this->worker->handle_rejected( 'current', 401 );
		$this->runRefreshAhead();

		$this->assertSame( 0, $this->mintRequests() );
		$this->assertSame( '', get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true ) );
	}

	/**
	 * Priorities of every refresh-ahead callback the worker hooked to shutdown.
	 *
	 * @return int[]
	 */
	private function refreshAheadPriorities(): array {
		$priorities = array();

		foreach ( Agend_Test_WP::$actions['shutdown'] ?? array() as $index => $callback ) {
			if ( is_array( $callback ) && $callback[0] === $this->worker ) {
				$priorities[] = Agend_Test_WP::$action_priorities['shutdown'][ $index ];
			}
		}

		return $priorities;
	}

	private function runRefreshAhead(): void {
		foreach ( Agend_Test_WP::$actions['shutdown'] ?? array() as $callback ) {
			if ( is_array( $callback ) && $callback[0] === $this->worker ) {
				call_user_func( $callback );
			}
		}
	}

	private function storeToken( string $token, int $remaining, string $external_id = self::EXTERNAL_ID ): void {
		update_user_meta(
			self::USER_ID,
			Agend_Apps_Token_Worker::META_KEY,
			array(
				'access_token' => $token,
				'expires_at'   => self::NOW + $remaining,
				'external_id'  => $external_id,
				'minted_at'    => self::NOW + $remaining - 300,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return (array) get_user_meta( self::USER_ID, Agend_Apps_Token_Worker::META_KEY, true );
	}

	private function mintBody( string $token, int $lifetime ): array {
		return array(
			'data' => array(
				'access_token' => $token,
				'expires_at'   => self::NOW + $lifetime,
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

	private function lockName(): string {
		return Agend_Apps_Token_Worker::MINT_LOCK_PREFIX . self::USER_ID;
	}

	private function backoffName(): string {
		return Agend_Apps_Token_Worker::REFRESH_AHEAD_BACKOFF_PREFIX . self::USER_ID;
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
