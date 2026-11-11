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

	public ?WP_Error $spend_error = null;

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

		if ( $this->spend_error ) {
			if ( $this->spend_commits_before_error ) {
				$this->used[ $ref ] = true;
			}

			return $this->spend_error;
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
 * member who spent it, and never losing a credit when the CRM fails.
 */
#[CoversClass( Agend_Apps_Jobs_Credits::class )]
final class JobsCreditsTest extends TestCase {

	private FakeJobsCreditProvider $provider;

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
	}

	private function rejected( string $job_id, string $ref ): array {
		return array(
			'data' => array(
				'job_id'     => $job_id,
				'credit_ref' => $ref,
			),
		);
	}

	private function hold_lock( string $name ): void {
		\Agend_Test_WP::$options[ $name ] = time() . ':other';
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
	public function reserve_spends_the_first_available_credit_and_records_it(): void {
		$this->provider->give( 'cr-1', 7 );
		$this->provider->give( 'cr-2', 7 );

		$this->assertSame( 'cr-1', Agend_Apps_Jobs_Credits::reserve( 7 ) );
		$this->assertSame( array( 'spend:7:cr-1' ), $this->provider->calls );

		$entry = Agend_Apps_Jobs_Credits::entry( 'cr-1' );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RESERVED, $entry['state'] );
		$this->assertSame( 7, $entry['user_id'] );
	}

	#[Test]
	public function locks_are_released_after_reserve(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );

		foreach ( array_keys( \Agend_Test_WP::$options ) as $name ) {
			$this->assertStringNotContainsString( '_lock_', $name );
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

		$this->assertSame( 'firm-2', Agend_Apps_Jobs_Credits::reserve( 8 ) );
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
		$this->assertSame( 'firm-1', Agend_Apps_Jobs_Credits::reserve( 7 ) );

		// A stale CRM read still reports the credit as unused.
		$this->provider->used['firm-1'] = false;

		$this->assertSame( 'agend_jobs_credits_none', Agend_Apps_Jobs_Credits::reserve( 8 )->get_error_code() );
	}

	#[Test]
	public function an_abandoned_lock_is_taken_over(): void {
		$this->provider->give( 'cr-1', 7 );
		\Agend_Test_WP::$options[ Agend_Apps_Jobs_Credits::MEMBER_LOCK_PREFIX . '7' ] = ( time() - Agend_Apps_Jobs_Credits::LOCK_TTL - 1 ) . ':dead';

		$this->assertSame( 'cr-1', Agend_Apps_Jobs_Credits::reserve( 7 ) );
	}

	#[Test]
	public function a_failed_spend_is_given_back_in_case_the_crm_applied_it(): void {
		$this->provider->give( 'cr-1', 7 );
		$this->provider->spend_error                = new WP_Error( 'timeout', 'Timed out' );
		$this->provider->spend_commits_before_error = true;

		$this->assertSame( 'timeout', Agend_Apps_Jobs_Credits::reserve( 7 )->get_error_code() );
		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, Agend_Apps_Jobs_Credits::entry( 'cr-1' )['state'] );
	}

	#[Test]
	public function a_failed_spend_that_cannot_be_given_back_is_queued_for_retry(): void {
		$this->provider->give( 'cr-1', 7 );
		$this->provider->spend_error                = new WP_Error( 'timeout', 'Timed out' );
		$this->provider->spend_commits_before_error = true;
		$this->provider->restore_error              = new WP_Error( 'crm_down', 'Down' );

		Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASE_PENDING, Agend_Apps_Jobs_Credits::entry( 'cr-1' )['state'] );
		$this->assertArrayHasKey( md5( 'cr-1' ), Agend_Apps_Jobs_Credits::pending() );
	}

	#[Test]
	public function a_refused_submission_gives_its_credit_back(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertTrue( Agend_Apps_Jobs_Credits::release( 'cr-1' ) );
		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, Agend_Apps_Jobs_Credits::entry( 'cr-1' )['state'] );
		$this->assertSame( 'cr-1', Agend_Apps_Jobs_Credits::reserve( 7 ), 'a released credit can be spent again' );
	}

	#[Test]
	public function a_rejection_webhook_gives_back_the_credit_for_that_job(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->assertTrue( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' ) );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->rejected( 'job-1', 'cr-1' ) );

		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertContains( 'job-1', Agend_Apps_Jobs_Credits::entry( 'cr-1' )['history'] );
	}

	#[Test]
	public function a_withdrawal_webhook_gives_the_credit_back_too(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.withdrawn', $this->rejected( 'job-1', 'cr-1' ) );

		$this->assertFalse( $this->provider->used['cr-1'] );
	}

	#[Test]
	public function a_rejection_that_arrives_before_attach_still_gives_the_credit_back(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->rejected( 'job-1', 'cr-1' ) );

		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' ), 'the late attach sees the credit already given back' );
	}

	#[Test]
	public function a_webhook_for_a_different_job_leaves_the_credit_spent(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-2' );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->rejected( 'job-1', 'cr-1' ) );

		$this->assertTrue( $this->provider->used['cr-1'] );
		$this->assertNotContains( 'restore:7:cr-1', $this->provider->calls );
	}

	#[Test]
	public function a_duplicate_webhook_after_the_credit_paid_for_a_new_job_is_ignored(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' );
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->rejected( 'job-1', 'cr-1' ) );

		// The member spends the returned credit on a new job, not yet attached.
		$this->assertSame( 'cr-1', Agend_Apps_Jobs_Credits::reserve( 7 ) );

		// The first rejection is delivered again.
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->rejected( 'job-1', 'cr-1' ) );

		$this->assertTrue( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RESERVED, Agend_Apps_Jobs_Credits::entry( 'cr-1' )['state'] );
	}

	#[Test]
	public function a_shared_credit_is_given_back_to_the_colleague_who_spent_it_last(): void {
		$this->provider->give( 'firm-1', 7, 8 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'firm-1', 'job-1' );
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->rejected( 'job-1', 'firm-1' ) );

		$this->assertSame( 'firm-1', Agend_Apps_Jobs_Credits::reserve( 8 ) );
		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, 'firm-1', 'job-2' ), 'only the spender attaches' );
		$this->assertTrue( Agend_Apps_Jobs_Credits::attach( 8, 'firm-1', 'job-2' ) );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->rejected( 'job-2', 'firm-1' ) );

		$this->assertFalse( $this->provider->used['firm-1'] );
		$this->assertContains( 'restore:8:firm-1', $this->provider->calls );
	}

	#[Test]
	public function a_failed_release_is_queued_and_retried_until_it_succeeds(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' );
		$this->provider->restore_error = new WP_Error( 'crm_down', 'Down' );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->rejected( 'job-1', 'cr-1' ) );

		$this->assertTrue( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASE_PENDING, Agend_Apps_Jobs_Credits::entry( 'cr-1' )['state'] );
		$this->assertSame( array( md5( 'cr-1' ) => 1 ), Agend_Apps_Jobs_Credits::pending() );
		$this->assertNotFalse( wp_next_scheduled( Agend_Apps_Jobs_Credits::RETRY_HOOK ) );

		// The CRM is still down at the first retry.
		Agend_Apps_Jobs_Credits::retry_pending();
		$this->assertSame( array( md5( 'cr-1' ) => 2 ), Agend_Apps_Jobs_Credits::pending() );

		// It comes back.
		$this->provider->restore_error = null;
		Agend_Apps_Jobs_Credits::retry_pending();

		$this->assertFalse( $this->provider->used['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, Agend_Apps_Jobs_Credits::entry( 'cr-1' )['state'] );
		$this->assertSame( array(), Agend_Apps_Jobs_Credits::pending() );
	}

	#[Test]
	public function a_pending_release_is_not_spent_again(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->provider->restore_error = new WP_Error( 'crm_down', 'Down' );
		Agend_Apps_Jobs_Credits::release( 'cr-1' );

		// The CRM half-applied the restore and reports the credit unused.
		$this->provider->used['cr-1'] = false;

		$this->assertSame( array(), Agend_Apps_Jobs_Credits::available( 7 ) );
	}

	#[Test]
	public function a_repeated_webhook_while_the_release_is_pending_is_accepted_without_a_second_restore(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' );
		$this->provider->restore_error = new WP_Error( 'crm_down', 'Down' );
		Agend_Apps_Jobs_Credits::release( 'cr-1', 'job-1' );
		$calls = count( $this->provider->calls );

		$this->assertTrue( Agend_Apps_Jobs_Credits::release( 'cr-1', 'job-1' ) );
		$this->assertCount( $calls, $this->provider->calls );
	}

	#[Test]
	public function a_credit_staff_gave_back_by_hand_can_be_spent_again(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' );

		// Staff reset the credit to unused in the CRM.
		$this->provider->used['cr-1'] = false;

		$this->assertSame( 'cr-1', Agend_Apps_Jobs_Credits::reserve( 7 ) );
	}

	#[Test]
	public function a_webhook_for_a_credit_this_site_never_spent_is_ignored(): void {
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $this->rejected( 'job-1', 'cr-unknown' ) );

		$this->assertSame( array(), $this->provider->calls );
	}

	#[Test]
	public function other_event_types_are_ignored(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.approved', $this->rejected( 'job-1', 'cr-1' ) );

		$this->assertTrue( $this->provider->used['cr-1'] );
	}

	#[Test]
	public function a_release_without_a_job_does_not_touch_a_submitted_credit(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' );

		$this->assertSame( 'agend_jobs_credits_stale', Agend_Apps_Jobs_Credits::release( 'cr-1' )->get_error_code() );
		$this->assertTrue( $this->provider->used['cr-1'] );
	}

	#[Test]
	public function attach_needs_a_reserved_credit_and_a_job_id(): void {
		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' ) );

		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', '' ) );
		$this->assertTrue( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' ) );
		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-2' ), 'a submitted credit cannot be moved to another job' );
	}

	#[Test]
	public function a_release_waiting_on_a_held_lock_reports_busy(): void {
		$this->provider->give( 'cr-1', 7 );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->hold_lock( Agend_Apps_Jobs_Credits::CREDIT_LOCK_PREFIX . md5( 'cr-1' ) );

		$this->assertSame( 'agend_jobs_credits_busy', Agend_Apps_Jobs_Credits::release( 'cr-1' )->get_error_code() );
		$this->assertTrue( $this->provider->used['cr-1'] );
	}

	#[Test]
	public function the_webhook_and_retry_listeners_are_registered(): void {
		Agend_Apps_Jobs_Credits::register();

		$this->assertContains( array( Agend_Apps_Jobs_Credits::class, 'handle_webhook' ), \Agend_Test_WP::$actions['agend_apps_webhook_received'] ?? array() );
		$this->assertContains( array( Agend_Apps_Jobs_Credits::class, 'retry_pending' ), \Agend_Test_WP::$actions[ Agend_Apps_Jobs_Credits::RETRY_HOOK ] ?? array() );
	}
}
