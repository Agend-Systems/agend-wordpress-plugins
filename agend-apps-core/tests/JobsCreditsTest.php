<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Jobs_Credit_Provider;
use Agend_Apps_Jobs_Credits;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/jobs-credits.php';

/**
 * In-memory credit provider. A credit is a ref => used flag, visible to the
 * users listed for it, so a firm's credit can be shared by several members.
 */
final class FakeJobsCreditProvider implements Agend_Apps_Jobs_Credit_Provider {

	/** @var array<string, bool> ref => used */
	public array $used = array();

	/** @var array<string, int[]> ref => user ids who can see it */
	public array $holders = array();

	/** @var array<int, string> */
	public array $calls = array();

	public bool $available = true;

	public ?WP_Error $read_error = null;

	/** A list read before another request's spend, returned instead of the live one. */
	public ?array $stale_list = null;

	public ?WP_Error $spend_error = null;

	/** Runs at the start of spend(), to simulate other requests meanwhile. */
	public $on_spend = null;

	/** Apply the spend before returning spend_error (a timeout after the CRM committed). */
	public bool $spend_commits_before_error = false;

	public ?WP_Error $restore_error = null;

	public function give( string $ref, int ...$user_ids ): void {
		$this->used[ $ref ]    = false;
		$this->holders[ $ref ] = $user_ids;
	}

	public function get_key(): string {
		return 'fake';
	}

	public function is_available(): bool {
		return $this->available;
	}

	public function get_available_credits( int $user_id ) {
		if ( $this->read_error ) {
			return $this->read_error;
		}

		if ( null !== $this->stale_list ) {
			return $this->stale_list;
		}

		$out = array();

		foreach ( $this->used as $ref => $used ) {
			if ( ! $used && in_array( $user_id, $this->holders[ $ref ], true ) ) {
				$out[] = array(
					'ref'        => $ref,
					'expires_at' => null,
				);
			}
		}

		return $out;
	}

	public function spend( int $user_id, string $ref ) {
		$this->calls[] = "spend:$user_id:$ref";

		if ( is_callable( $this->on_spend ) ) {
			( $this->on_spend )();
		}

		if ( $this->spend_error ) {
			if ( $this->spend_commits_before_error ) {
				$this->used[ $ref ] = true;
			}

			return $this->spend_error;
		}

		// Like the real provider: re-check under the lock.
		if ( $this->used[ $ref ] ?? true ) {
			return new WP_Error(
				'not_available',
				'Used',
				array(
					'applied' => false,
					'taken'   => true,
				)
			);
		}

		$this->used[ $ref ] = true;

		return true;
	}

	public function restore( int $user_id, string $ref ) {
		$this->calls[] = "restore:$user_id:$ref";

		if ( $this->restore_error ) {
			return $this->restore_error;
		}

		$this->used[ $ref ] = false;

		return true;
	}
}

/**
 * The job credit coordinator spends, links and gives back credits around the
 * active provider: never spending one credit twice, always giving back to the
 * member who spent it, acting only on the reservation an event names, and
 * never losing a credit when the CRM fails or a request dies.
 */
#[CoversClass( Agend_Apps_Jobs_Credits::class )]
final class JobsCreditsTest extends TestCase {

	private FakeJobsCreditProvider $provider;

	/** What the gateway lookup returns: a submission item, null, or a WP_Error. */
	private $found = null;

	/** @var array<int, string> user_id:credit_ref for each gateway lookup */
	private array $lookups = array();

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Jobs_Credits::$lock_poll = 0;
		$this->provider                     = new FakeJobsCreditProvider();
		$provider                           = $this->provider;
		add_filter(
			'agend_apps_jobs_credit_provider',
			static function () use ( $provider ) {
				return $provider;
			}
		);

		Agend_Apps_Jobs_Credits::$submission_finder = function ( int $user_id, string $credit_ref ) {
			$this->lookups[] = "$user_id:$credit_ref";

			return $this->found;
		};
	}

	protected function tearDown(): void {
		Agend_Apps_Jobs_Credits::$submission_finder = null;
		parent::tearDown();
	}

	/** Ages a reservation past the point the sweep looks it up. */
	private function age_for_lookup( string $ref ): void {
		$this->change_elsewhere( $ref, array( 'updated_at' => time() - Agend_Apps_Jobs_Credits::LOOKUP_AFTER - 1 ) );
	}

	private function event( string $job_id, string $credit_ref ): array {
		return array(
			'data' => array(
				'job_id'     => $job_id,
				'credit_ref' => $credit_ref,
			),
		);
	}

	private function hold_lock( string $name ): void {
		\Agend_Test_WP::$options[ $name ] = time() . ':other';
	}

	private function ledger( string $ref ): string {
		return Agend_Apps_Jobs_Credits::LEDGER_PREFIX . md5( $ref );
	}

	private function state( string $ref ): string {
		return (string) Agend_Apps_Jobs_Credits::entry( $ref )['state'];
	}

	/**
	 * Changes a ledger record as another request would: in the database,
	 * leaving this request's cached copy stale.
	 */
	private function change_elsewhere( string $ref, array $changes ): void {
		$entry = get_option( $this->ledger( $ref ) );
		\Agend_Test_WP::write_option_bypassing_cache( $this->ledger( $ref ), array_merge( $entry, $changes ) );
	}

	#[Test]
	public function it_is_disabled_without_an_available_provider(): void {
		$this->provider->available = false;

		$this->assertFalse( Agend_Apps_Jobs_Credits::enabled() );
		$this->assertSame( 'agend_jobs_credits_unavailable', Agend_Apps_Jobs_Credits::reserve( 7 )->get_error_code() );
	}

	#[Test]
	public function a_signed_out_visitor_cannot_reserve(): void {
		$this->assertSame( 'agend_jobs_credits_no_member', Agend_Apps_Jobs_Credits::reserve( 0 )->get_error_code() );
	}

	#[Test]
	public function reserve_spends_the_first_credit_and_returns_a_reservation_specific_ref(): void {
		$this->provider->give( 'cr-1', 7 );
		$this->provider->give( 'cr-2', 7 );

		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		$parts      = Agend_Apps_Jobs_Credits::parse( $credit_ref );

		$this->assertSame( 'cr-1', $parts['ref'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $parts['reservation'] );
		$this->assertSame( array( 'spend:7:cr-1' ), $this->provider->calls );

		$entry = Agend_Apps_Jobs_Credits::entry( 'cr-1' );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RESERVED, $entry['state'] );
		$this->assertSame( 7, $entry['user_id'] );
		$this->assertSame( $parts['reservation'], $entry['reservation'] );
	}

	#[Test]
	public function reserve_schedules_the_recurring_sweep(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertNotFalse( wp_next_scheduled( Agend_Apps_Jobs_Credits::SWEEP_HOOK ) );
	}

	#[Test]
	public function locks_are_released_after_reserve(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );

		foreach ( array_keys( \Agend_Test_WP::$options ) as $name ) {
			$this->assertStringNotContainsString( '_lock_', (string) $name );
		}
	}

	#[Test]
	public function reserve_reports_no_credits_as_402(): void {
		$result = Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertSame( 'agend_jobs_credits_none', $result->get_error_code() );
		$this->assertSame( 402, $result->get_error_data()['status'] );
	}

	#[Test]
	public function a_lookup_failure_is_not_reported_as_running_out(): void {
		$this->provider->read_error = new WP_Error( 'crm_down', 'CRM unreachable', array( 'status' => 503 ) );

		$this->assertSame( 'crm_down', Agend_Apps_Jobs_Credits::reserve( 7 )->get_error_code() );
	}

	#[Test]
	public function a_second_reserve_by_the_same_member_while_one_runs_is_refused(): void {
		$this->provider->give( 'cr-1', 7 );
		$this->hold_lock( Agend_Apps_Jobs_Credits::MEMBER_LOCK_PREFIX . '7' );

		$this->assertSame( 'agend_jobs_credits_busy', Agend_Apps_Jobs_Credits::reserve( 7 )->get_error_code() );
		$this->assertSame( array(), $this->provider->calls );
	}

	#[Test]
	public function a_firm_credit_being_spent_by_a_colleague_is_skipped_for_the_next_one(): void {
		$this->provider->give( 'firm-1', 7, 8 );
		$this->provider->give( 'firm-2', 7, 8 );
		$this->hold_lock( Agend_Apps_Jobs_Credits::CREDIT_LOCK_PREFIX . md5( 'firm-1' ) );

		$this->assertSame( 'firm-2', Agend_Apps_Jobs_Credits::parse( Agend_Apps_Jobs_Credits::reserve( 8 ) )['ref'] );
		$this->assertSame( array( 'spend:8:firm-2' ), $this->provider->calls );
	}

	#[Test]
	public function the_only_credit_being_spent_by_a_colleague_reports_busy_not_none(): void {
		$this->provider->give( 'firm-1', 7, 8 );
		$this->hold_lock( Agend_Apps_Jobs_Credits::CREDIT_LOCK_PREFIX . md5( 'firm-1' ) );

		$this->assertSame( 'agend_jobs_credits_busy', Agend_Apps_Jobs_Credits::reserve( 8 )->get_error_code() );
	}

	#[Test]
	public function a_credit_reserved_by_a_colleague_is_not_spent_again_on_a_stale_read(): void {
		$this->provider->give( 'firm-1', 7, 8 );
		Agend_Apps_Jobs_Credits::reserve( 7 );

		// A stale CRM read still reports the credit as unused.
		$this->provider->used['firm-1'] = false;

		$this->assertSame( 'agend_jobs_credits_none', Agend_Apps_Jobs_Credits::reserve( 8 )->get_error_code() );
	}

	#[Test]
	public function a_colleague_whose_list_is_stale_cannot_take_over_a_submitted_credit(): void {
		$this->provider->give( 'firm-1', 7, 8 );
		$this->provider->give( 'firm-2', 7, 8 );
		$a = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, $a, 'job-a' );

		// B's list was read before A spent firm-1, so it still offers firm-1.
		$this->provider->stale_list = array(
			array(
				'ref'        => 'firm-1',
				'expires_at' => null,
			),
			array(
				'ref'        => 'firm-2',
				'expires_at' => null,
			),
		);

		$b = Agend_Apps_Jobs_Credits::reserve( 8 );

		$this->assertContains( 'spend:8:firm-1', $this->provider->calls, 'B tried firm-1 and the CRM refused' );
		$this->assertSame( 'firm-2', Agend_Apps_Jobs_Credits::parse( $b )['ref'], 'B moved on to the next credit' );
		$this->assertTrue( $this->provider->used['firm-1'], 'A keeps their spend' );
		$this->assertNotContains( 'restore:8:firm-1', $this->provider->calls );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_SUBMITTED, $this->state( 'firm-1' ) );
		$this->assertSame( 7, Agend_Apps_Jobs_Credits::entry( 'firm-1' )['user_id'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::parse( $a )['reservation'], Agend_Apps_Jobs_Credits::entry( 'firm-1' )['reservation'] );
	}

	#[Test]
	public function a_spend_the_crm_refused_leaves_the_ledger_as_it_was(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-1' );
		$before = Agend_Apps_Jobs_Credits::entry( 'cr-1' );

		// Staff appear to refund it, but the provider's own re-check refuses.
		$this->provider->spend_error = new WP_Error(
			'not_available',
			'Used',
			array(
				'applied' => false,
				'taken'   => true,
			)
		);
		$this->provider->used['cr-1'] = false;

		$this->assertSame( 'agend_jobs_credits_none', Agend_Apps_Jobs_Credits::reserve( 7 )->get_error_code() );
		$this->assertSame( $before['reservation'], Agend_Apps_Jobs_Credits::entry( 'cr-1' )['reservation'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_SUBMITTED, $this->state( 'cr-1' ) );
		$this->assertNotContains( 'restore:7:cr-1', $this->provider->calls );
	}

	#[Test]
	public function a_failed_recheck_during_the_spend_is_reported_not_as_running_out(): void {
		$this->provider->give( 'cr-1', 7 );
		$this->provider->give( 'cr-2', 7 );
		$this->provider->spend_error = new WP_Error( 'crm_down', 'Down', array( 'status' => 503, 'applied' => false ) );

		$result = Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertSame( 'crm_down', $result->get_error_code() );
		$this->assertSame( array( 'spend:7:cr-1' ), $this->provider->calls, 'the next credit is not tried during an outage' );
		$this->assertNull( Agend_Apps_Jobs_Credits::entry( 'cr-1' ), 'the ledger is left as it was' );
	}

	#[Test]
	public function a_spend_that_outlived_its_lock_writes_nothing_over_the_sweep(): void {
		$this->provider->give( 'cr-1', 7 );
		$provider = $this->provider;
		$test     = $this;

		// While the CRM is answering, the sweep takes the spend over and gives
		// the credit back, and a colleague spends it again.
		$this->provider->on_spend = static function () use ( $test ) {
			$entry = get_option( Agend_Apps_Jobs_Credits::LEDGER_PREFIX . md5( 'cr-1' ) );
			\Agend_Test_WP::write_option_bypassing_cache(
				Agend_Apps_Jobs_Credits::LEDGER_PREFIX . md5( 'cr-1' ),
				array_merge(
					$entry,
					array(
						'state'       => Agend_Apps_Jobs_Credits::STATE_RESERVED,
						'user_id'     => 8,
						'reservation' => 'fedcba9876543210',
					)
				)
			);
			// The sweep took this spend's lock over, and has since let it go.
			unset( \Agend_Test_WP::$options[ Agend_Apps_Jobs_Credits::CREDIT_LOCK_PREFIX . md5( 'cr-1' ) ] );
		};

		$result = Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertSame( 'agend_jobs_credits_timeout', $result->get_error_code() );
		$entry = Agend_Apps_Jobs_Credits::entry( 'cr-1' );
		$this->assertSame( 'fedcba9876543210', $entry['reservation'], "the colleague's reservation is kept" );
		$this->assertSame( 8, $entry['user_id'] );
		$this->assertNotContains( 'restore:0:cr-1', $provider->calls );
		$this->assertNotContains( 'restore:8:cr-1', $provider->calls, "the colleague's spend is not refunded" );
		unset( $test );
	}

	#[Test]
	public function a_spend_that_outlived_its_lock_after_the_sweep_gave_it_back_is_given_back_again(): void {
		$this->provider->give( 'cr-1', 7 );

		$this->provider->on_spend = static function () {
			$entry = get_option( Agend_Apps_Jobs_Credits::LEDGER_PREFIX . md5( 'cr-1' ) );
			\Agend_Test_WP::write_option_bypassing_cache(
				Agend_Apps_Jobs_Credits::LEDGER_PREFIX . md5( 'cr-1' ),
				array_merge( $entry, array( 'state' => Agend_Apps_Jobs_Credits::STATE_RELEASED ) )
			);
			unset( \Agend_Test_WP::$options[ Agend_Apps_Jobs_Credits::CREDIT_LOCK_PREFIX . md5( 'cr-1' ) ] );
		};

		$this->assertSame( 'agend_jobs_credits_timeout', Agend_Apps_Jobs_Credits::reserve( 7 )->get_error_code() );
		$this->assertFalse( $this->provider->used['cr-1'], 'the late spend is undone' );
	}

	#[Test]
	public function an_abandoned_lock_is_taken_over(): void {
		$this->provider->give( 'cr-1', 7 );
		\Agend_Test_WP::$options[ Agend_Apps_Jobs_Credits::MEMBER_LOCK_PREFIX . '7' ] = ( time() - Agend_Apps_Jobs_Credits::LOCK_TTL - 1 ) . ':dead';

		$this->assertIsString( Agend_Apps_Jobs_Credits::reserve( 7 ) );
	}

	#[Test]
	public function a_spend_with_an_unknown_outcome_is_given_back(): void {
		$this->provider->give( 'cr-1', 7 );
		$this->provider->spend_error                = new WP_Error( 'timeout', 'Timed out' );
		$this->provider->spend_commits_before_error = true;

		$this->assertSame( 'timeout', Agend_Apps_Jobs_Credits::reserve( 7 )->get_error_code() );
		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, $this->state( 'cr-1' ) );
	}

	#[Test]
	public function a_refused_submission_gives_its_credit_back(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertTrue( Agend_Apps_Jobs_Credits::release( $credit_ref ) );
		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, $this->state( 'cr-1' ) );
		$this->assertIsString( Agend_Apps_Jobs_Credits::reserve( 7 ), 'a released credit can be spent again' );
	}

	#[Test]
	public function a_rejection_webhook_gives_back_the_credit_for_that_job(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->assertTrue( Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-1' ) );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->event( 'job-1', $credit_ref ) );

		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertSame( array(), $this->requests(), 'the handled request is deleted' );
	}

	#[Test]
	public function a_withdrawal_webhook_gives_the_credit_back_too(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-1' );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.withdrawn', $this->event( 'job-1', $credit_ref ) );

		$this->assertFalse( $this->provider->used['cr-1'] );
	}

	#[Test]
	public function a_rejection_that_arrives_before_attach_gives_the_credit_back_and_the_late_attach_fails(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->event( 'job-1', $credit_ref ) );

		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-1' ) );
	}

	#[Test]
	public function a_late_attach_reading_a_stale_cache_does_not_undo_a_release_made_by_another_request(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		get_option( $this->ledger( 'cr-1' ) ); // This request now caches `reserved`.

		// Another request releases it.
		$this->change_elsewhere( 'cr-1', array( 'state' => Agend_Apps_Jobs_Credits::STATE_RELEASED ) );

		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-1' ) );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, $this->state( 'cr-1' ) );
	}

	#[Test]
	public function an_attach_for_an_earlier_reservation_of_the_same_credit_fails(): void {
		$this->provider->give( 'cr-1', 7 );
		$first = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->event( 'job-1', $first ) );
		$second = Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertNotSame( $first, $second );
		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, $first, 'job-1' ) );
		$this->assertTrue( Agend_Apps_Jobs_Credits::attach( 7, $second, 'job-2' ) );
	}

	#[Test]
	public function an_event_about_an_earlier_reservation_never_releases_the_current_one(): void {
		$this->provider->give( 'cr-1', 7 );
		$first = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, $first, 'job-1' );

		// Staff refund it by hand; it is spent again on a new submission.
		$this->provider->used['cr-1'] = false;
		$second = Agend_Apps_Jobs_Credits::reserve( 7 );

		// A late withdrawal of the first job arrives while the second is reserved.
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.withdrawn', $this->event( 'job-1', $first ) );

		$this->assertTrue( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RESERVED, $this->state( 'cr-1' ) );
		$this->assertTrue( Agend_Apps_Jobs_Credits::attach( 7, $second, 'job-2' ) );
	}

	#[Test]
	public function a_repeated_event_for_a_released_reservation_is_acknowledged_without_a_second_restore(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-1' );
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->event( 'job-1', $credit_ref ) );
		$calls = count( $this->provider->calls );

		$this->assertTrue( Agend_Apps_Jobs_Credits::release( $credit_ref, 'job-1' ) );
		$this->assertCount( $calls, $this->provider->calls );
	}

	#[Test]
	public function a_webhook_for_a_different_job_leaves_the_credit_spent(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-2' );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->event( 'job-1', $credit_ref ) );

		$this->assertTrue( $this->provider->used['cr-1'] );
	}

	#[Test]
	public function a_shared_credit_is_given_back_to_the_colleague_who_spent_it_last(): void {
		$this->provider->give( 'firm-1', 7, 8 );
		$a = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, $a, 'job-1' );
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->event( 'job-1', $a ) );

		$b = Agend_Apps_Jobs_Credits::reserve( 8 );
		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, $b, 'job-2' ), 'only the spender attaches' );
		$this->assertTrue( Agend_Apps_Jobs_Credits::attach( 8, $b, 'job-2' ) );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->event( 'job-2', $b ) );

		$this->assertFalse( $this->provider->used['firm-1'] );
		$this->assertContains( 'restore:8:firm-1', $this->provider->calls );
	}

	#[Test]
	public function a_webhook_that_cannot_get_the_lock_is_kept_and_finished_by_the_sweep(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-1' );
		$this->hold_lock( Agend_Apps_Jobs_Credits::CREDIT_LOCK_PREFIX . md5( 'cr-1' ) );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->event( 'job-1', $credit_ref ) );

		$this->assertTrue( $this->provider->used['cr-1'] );
		$this->assertCount( 1, $this->requests() );

		unset( \Agend_Test_WP::$options[ Agend_Apps_Jobs_Credits::CREDIT_LOCK_PREFIX . md5( 'cr-1' ) ] );
		Agend_Apps_Jobs_Credits::sweep();

		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertSame( array(), $this->requests() );
	}

	#[Test]
	public function a_failed_release_stays_pending_and_the_sweep_retries_it(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-1' );
		$this->provider->restore_error = new WP_Error( 'crm_down', 'Down' );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->event( 'job-1', $credit_ref ) );

		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASE_PENDING, $this->state( 'cr-1' ) );
		$this->assertSame( array(), $this->requests(), 'the ledger now carries it' );

		Agend_Apps_Jobs_Credits::sweep();
		$this->assertSame( 2, Agend_Apps_Jobs_Credits::entry( 'cr-1' )['attempts'] );

		$this->provider->restore_error = null;
		Agend_Apps_Jobs_Credits::sweep();

		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, $this->state( 'cr-1' ) );
	}

	#[Test]
	public function the_sweep_gives_back_a_spend_left_half_done_by_a_request_that_died(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->change_elsewhere(
			'cr-1',
			array(
				'state'      => Agend_Apps_Jobs_Credits::STATE_SPENDING,
				'updated_at' => time() - Agend_Apps_Jobs_Credits::LOCK_TTL - 1,
			)
		);

		Agend_Apps_Jobs_Credits::sweep();

		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, $this->state( 'cr-1' ) );
	}

	#[Test]
	public function the_sweep_leaves_a_spend_that_may_still_be_running(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->change_elsewhere( 'cr-1', array( 'state' => Agend_Apps_Jobs_Credits::STATE_SPENDING ) );

		Agend_Apps_Jobs_Credits::sweep();

		$this->assertTrue( $this->provider->used['cr-1'] );
	}

	#[Test]
	public function the_sweep_gives_back_a_release_left_pending_by_a_request_that_died(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->change_elsewhere( 'cr-1', array( 'state' => Agend_Apps_Jobs_Credits::STATE_RELEASE_PENDING ) );

		Agend_Apps_Jobs_Credits::sweep();

		$this->assertFalse( $this->provider->used['cr-1'] );
	}

	#[Test]
	public function a_release_that_keeps_failing_is_flagged_for_staff_and_still_retried(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->provider->restore_error = new WP_Error( 'crm_down', 'Down' );
		Agend_Apps_Jobs_Credits::release( $credit_ref );
		$this->change_elsewhere( 'cr-1', array( 'attempts' => Agend_Apps_Jobs_Credits::MAX_ATTEMPTS - 1 ) );

		Agend_Apps_Jobs_Credits::sweep();

		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASE_FAILED, $this->state( 'cr-1' ) );
		$this->assertCount( 1, get_option( Agend_Apps_Jobs_Credits::ATTENTION_OPTION ) );

		// Staff put it right; the next sweep notices.
		$this->provider->restore_error = null;
		Agend_Apps_Jobs_Credits::sweep();

		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, $this->state( 'cr-1' ) );
		$this->assertSame( array(), get_option( Agend_Apps_Jobs_Credits::ATTENTION_OPTION ) );
	}

	#[Test]
	public function an_expired_credit_is_not_retried(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref                    = Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->provider->restore_error = new WP_Error( 'expired', 'Expired', array( 'gone' => true ) );

		$this->assertTrue( Agend_Apps_Jobs_Credits::release( $credit_ref ) );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, $this->state( 'cr-1' ) );
	}

	#[Test]
	public function a_reservation_that_never_reached_a_job_is_flagged_after_a_day(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->change_elsewhere( 'cr-1', array( 'updated_at' => time() - Agend_Apps_Jobs_Credits::STALE_RESERVATION - 1 ) );

		Agend_Apps_Jobs_Credits::sweep();

		$this->assertSame( 'reserved', get_option( Agend_Apps_Jobs_Credits::ATTENTION_OPTION )[0]['state'] );
	}

	#[Test]
	public function staff_can_give_back_a_stuck_reservation(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->change_elsewhere( 'cr-1', array( 'updated_at' => time() - Agend_Apps_Jobs_Credits::STALE_RESERVATION - 1 ) );
		Agend_Apps_Jobs_Credits::sweep();
		$this->assertCount( 1, get_option( Agend_Apps_Jobs_Credits::ATTENTION_OPTION ) );

		$this->assertTrue( Agend_Apps_Jobs_Credits::resolve( 'cr-1', 'release' ) );

		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, $this->state( 'cr-1' ) );
		$this->assertSame( array(), get_option( Agend_Apps_Jobs_Credits::ATTENTION_OPTION ) );
	}

	#[Test]
	public function staff_can_keep_a_stuck_reservation_as_used(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertTrue( Agend_Apps_Jobs_Credits::resolve( 'cr-1', 'keep' ) );

		$this->assertTrue( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_SUBMITTED, $this->state( 'cr-1' ) );
	}

	#[Test]
	public function only_a_stuck_credit_can_be_resolved(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-1' );

		$this->assertSame( 'agend_jobs_credits_stale', Agend_Apps_Jobs_Credits::resolve( 'cr-1', 'release' )->get_error_code() );
		$this->assertSame( 'agend_jobs_credits_resolution', Agend_Apps_Jobs_Credits::resolve( 'cr-1', 'delete' )->get_error_code() );
		$this->assertTrue( $this->provider->used['cr-1'] );
	}

	#[Test]
	public function the_notice_lists_each_stuck_credit_with_its_two_actions(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->change_elsewhere( 'cr-1', array( 'updated_at' => time() - Agend_Apps_Jobs_Credits::STALE_RESERVATION - 1 ) );
		Agend_Apps_Jobs_Credits::sweep();

		ob_start();
		Agend_Apps_Jobs_Credits::render_attention_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<code>cr-1</code>', $html );
		$this->assertStringContainsString( 'value="release"', $html );
		$this->assertStringContainsString( 'value="keep"', $html );
		$this->assertStringContainsString( Agend_Apps_Jobs_Credits::RESOLVE_ACTION, $html );
	}

	#[Test]
	public function a_webhook_for_a_credit_this_site_never_spent_is_ignored(): void {
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->event( 'job-1', 'cr-unknown#0123456789abcdef' ) );

		$this->assertSame( array(), $this->provider->calls );
		$this->assertSame( array(), $this->requests() );
	}

	#[Test]
	public function a_malformed_credit_ref_is_ignored(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->event( 'job-1', 'cr-1' ) );

		$this->assertTrue( $this->provider->used['cr-1'] );
		$this->assertSame( array(), $this->requests() );
	}

	#[Test]
	public function other_event_types_are_ignored(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.listing.updated', $this->event( 'job-1', $credit_ref ) );

		$this->assertTrue( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RESERVED, $this->state( 'cr-1' ) );
		$this->assertSame( array(), $this->requests() );
	}

	#[Test]
	public function an_approval_records_the_job_when_attach_was_lost(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.approved', $this->event( 'job-1', $credit_ref ) );

		$entry = Agend_Apps_Jobs_Credits::entry( 'cr-1' );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_SUBMITTED, $entry['state'] );
		$this->assertSame( 'job-1', $entry['job_id'] );
		$this->assertTrue( $this->provider->used['cr-1'] );
		$this->assertSame( array(), $this->requests() );
	}

	#[Test]
	public function an_approval_for_an_attached_job_is_acknowledged(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-1' );

		$this->assertTrue( Agend_Apps_Jobs_Credits::confirm( $credit_ref, 'job-1' ) );
		$this->assertSame( 'agend_jobs_credits_stale', Agend_Apps_Jobs_Credits::confirm( $credit_ref, 'job-2' )->get_error_code(), 'never moved to another job' );
		$this->assertSame( 'job-1', Agend_Apps_Jobs_Credits::entry( 'cr-1' )['job_id'] );
	}

	#[Test]
	public function an_approval_fills_in_the_job_of_a_credit_staff_kept_as_used(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::resolve( 'cr-1', 'keep' );

		$this->assertTrue( Agend_Apps_Jobs_Credits::confirm( $credit_ref, 'job-1' ) );
		$this->assertSame( 'job-1', Agend_Apps_Jobs_Credits::entry( 'cr-1' )['job_id'] );
	}

	#[Test]
	public function an_approval_after_the_credit_was_given_back_is_logged_and_spends_nothing(): void {
		$logged = array();
		add_action(
			'agend_apps_jobs_credits_log',
			static function ( $message ) use ( &$logged ) {
				$logged[] = $message;
			}
		);
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::release( $credit_ref );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.approved', $this->event( 'job-1', $credit_ref ) );

		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, $this->state( 'cr-1' ) );
		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertCount( 1, preg_grep( '/went ahead after its credit was given back/', $logged ) );
		$this->assertSame( array(), $this->requests() );
	}

	#[Test]
	public function an_approval_for_an_earlier_reservation_leaves_the_current_one_alone(): void {
		$this->provider->give( 'cr-1', 7 );
		$first = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::release( $first );
		$second = Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertSame( 'agend_jobs_credits_conflict', Agend_Apps_Jobs_Credits::confirm( $first, 'job-1' )->get_error_code() );

		$entry = Agend_Apps_Jobs_Credits::entry( 'cr-1' );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RESERVED, $entry['state'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::parse( $second )['reservation'], $entry['reservation'] );
	}

	#[Test]
	public function an_approval_that_cannot_get_the_lock_is_kept_and_finished_by_the_sweep(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->hold_lock( Agend_Apps_Jobs_Credits::CREDIT_LOCK_PREFIX . md5( 'cr-1' ) );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.approved', $this->event( 'job-1', $credit_ref ) );

		$this->assertCount( 1, $this->requests() );

		unset( \Agend_Test_WP::$options[ Agend_Apps_Jobs_Credits::CREDIT_LOCK_PREFIX . md5( 'cr-1' ) ] );
		Agend_Apps_Jobs_Credits::sweep();

		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_SUBMITTED, $this->state( 'cr-1' ) );
		$this->assertSame( array(), $this->requests() );
	}

	#[Test]
	public function the_sweep_records_the_job_of_an_unattached_reservation_the_gateway_has(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->age_for_lookup( 'cr-1' );
		$this->found = array(
			'id'         => 'job-9',
			'status'     => 'pending',
			'credit_ref' => $credit_ref,
		);

		Agend_Apps_Jobs_Credits::sweep();

		$this->assertSame( array( "7:$credit_ref" ), $this->lookups, 'looked up as the member who spent it' );
		$entry = Agend_Apps_Jobs_Credits::entry( 'cr-1' );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_SUBMITTED, $entry['state'] );
		$this->assertSame( 'job-9', $entry['job_id'] );
		$this->assertTrue( $this->provider->used['cr-1'] );
	}

	#[Test]
	public function the_sweep_gives_back_an_unattached_reservation_whose_submission_was_refused(): void {
		foreach ( array( 7 => 'rejected', 8 => 'withdrawn' ) as $user_id => $status ) {
			$ref = "cr-$status";
			$this->provider->give( $ref, $user_id );
			$credit_ref = Agend_Apps_Jobs_Credits::reserve( $user_id );
			$this->age_for_lookup( $ref );
			$this->found = array(
				'id'         => "job-$status",
				'status'     => $status,
				'credit_ref' => $credit_ref,
			);

			Agend_Apps_Jobs_Credits::sweep();

			$this->assertFalse( $this->provider->used[ $ref ], $status );
			$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, $this->state( $ref ), $status );
		}
	}

	#[Test]
	public function an_unattached_reservation_the_gateway_has_no_submission_for_is_left_for_staff(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->change_elsewhere( 'cr-1', array( 'updated_at' => time() - Agend_Apps_Jobs_Credits::STALE_RESERVATION - 1 ) );

		Agend_Apps_Jobs_Credits::sweep();

		$this->assertCount( 1, $this->lookups );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RESERVED, $this->state( 'cr-1' ) );
		$this->assertTrue( $this->provider->used['cr-1'] );
		$this->assertSame( 'reserved', get_option( Agend_Apps_Jobs_Credits::ATTENTION_OPTION )[0]['state'] );
	}

	#[Test]
	public function a_failed_lookup_or_an_unknown_status_changes_nothing(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->age_for_lookup( 'cr-1' );

		foreach ( array(
			new WP_Error( 'down', 'Down' ),
			array( 'id' => 'job-1', 'status' => 'archived', 'credit_ref' => $credit_ref ),
			array( 'status' => 'pending', 'credit_ref' => $credit_ref ),
		) as $found ) {
			$this->found = $found;
			Agend_Apps_Jobs_Credits::sweep();

			$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RESERVED, $this->state( 'cr-1' ) );
			$this->assertTrue( $this->provider->used['cr-1'] );
		}
	}

	#[Test]
	public function a_recent_reservation_or_an_attached_one_is_not_looked_up(): void {
		$this->provider->give( 'cr-1', 7 );
		$this->provider->give( 'cr-2', 8 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 8 );
		Agend_Apps_Jobs_Credits::attach( 8, $credit_ref, 'job-1' );
		$this->age_for_lookup( 'cr-2' );

		Agend_Apps_Jobs_Credits::sweep();

		$this->assertSame( array(), $this->lookups );
	}

	#[Test]
	public function a_release_without_a_job_does_not_touch_a_submitted_credit(): void {
		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-1' );

		$this->assertSame( 'agend_jobs_credits_stale', Agend_Apps_Jobs_Credits::release( $credit_ref )->get_error_code() );
		$this->assertTrue( $this->provider->used['cr-1'] );
	}

	#[Test]
	public function attach_needs_the_current_reservation_and_a_job_id(): void {
		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1#0123456789abcdef', 'job-1' ) );

		$this->provider->give( 'cr-1', 7 );
		$credit_ref = Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, '' ) );
		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' ), 'a bare provider ref is refused' );
		$this->assertTrue( Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-1' ) );
		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, $credit_ref, 'job-2' ), 'a submitted credit cannot be moved to another job' );
	}

	#[Test]
	public function the_sweep_does_nothing_without_a_provider(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->change_elsewhere( 'cr-1', array( 'state' => Agend_Apps_Jobs_Credits::STATE_RELEASE_PENDING ) );
		$this->provider->available = false;

		Agend_Apps_Jobs_Credits::sweep();

		$this->assertTrue( $this->provider->used['cr-1'] );
		$this->assertNotFalse( wp_next_scheduled( Agend_Apps_Jobs_Credits::SWEEP_HOOK ), 'the recurring sweep stays scheduled' );
	}

	#[Test]
	public function the_listeners_are_registered(): void {
		Agend_Apps_Jobs_Credits::register();

		$this->assertContains( array( Agend_Apps_Jobs_Credits::class, 'handle_webhook' ), \Agend_Test_WP::$actions['agend_apps_webhook_received'] ?? array() );
		$this->assertContains( array( Agend_Apps_Jobs_Credits::class, 'sweep' ), \Agend_Test_WP::$actions[ Agend_Apps_Jobs_Credits::SWEEP_HOOK ] ?? array() );
		$this->assertArrayHasKey( Agend_Apps_Jobs_Credits::SWEEP_SCHEDULE, Agend_Apps_Jobs_Credits::cron_schedules( array() ) );
	}

	/**
	 * Saved release requests.
	 *
	 * @return string[]
	 */
	private function requests(): array {
		return array_values(
			array_filter(
				array_map( 'strval', array_keys( \Agend_Test_WP::$options ) ),
				static fn( string $name ): bool => str_starts_with( $name, Agend_Apps_Jobs_Credits::REQUEST_PREFIX )
			)
		);
	}
}
