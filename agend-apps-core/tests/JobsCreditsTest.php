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
 * In-memory credit provider: a member's credits are a ref => used map.
 */
final class FakeJobsCreditProvider implements Agend_Apps_Jobs_Credit_Provider {

	/** @var array<int, array<string, bool>> */
	public array $credits = array();

	/** @var array<int, string> */
	public array $calls = array();

	public bool $available = true;

	public ?WP_Error $fail = null;

	public function get_key(): string {
		return 'fake';
	}

	public function is_available(): bool {
		return $this->available;
	}

	public function get_available_credits( int $user_id ) {
		if ( $this->fail ) {
			return $this->fail;
		}

		$out = array();

		foreach ( $this->credits[ $user_id ] ?? array() as $ref => $used ) {
			if ( ! $used ) {
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

		if ( $this->fail ) {
			return $this->fail;
		}

		$this->credits[ $user_id ][ $ref ] = true;

		return true;
	}

	public function restore( int $user_id, string $ref ) {
		$this->calls[] = "restore:$user_id:$ref";

		if ( $this->fail ) {
			return $this->fail;
		}

		$this->credits[ $user_id ][ $ref ] = false;

		return true;
	}
}

/**
 * The job credit coordinator spends, links and gives back credits around the
 * active provider, and only ever gives back a credit this site spent on the
 * job a webhook names.
 */
#[CoversClass( Agend_Apps_Jobs_Credits::class )]
final class JobsCreditsTest extends TestCase {

	private FakeJobsCreditProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['agend_test_user_meta'] = array();
		$this->provider                  = new FakeJobsCreditProvider();
		$provider                        = $this->provider;
		add_filter(
			'agend_apps_jobs_credit_provider',
			static function () use ( $provider ) {
				return $provider;
			}
		);
	}

	#[Test]
	public function it_is_disabled_without_an_available_provider(): void {
		$this->provider->available = false;

		$this->assertFalse( Agend_Apps_Jobs_Credits::enabled() );
		$this->assertSame( 'agend_jobs_credits_unavailable', Agend_Apps_Jobs_Credits::reserve( 7 )->get_error_code() );
	}

	#[Test]
	public function reserve_spends_the_first_available_credit_and_records_it(): void {
		$this->provider->credits[7] = array(
			'cr-1' => true,
			'cr-2' => false,
			'cr-3' => false,
		);

		$ref = Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertSame( 'cr-2', $ref );
		$this->assertSame( array( 'spend:7:cr-2' ), $this->provider->calls );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RESERVED, Agend_Apps_Jobs_Credits::entry( 7, 'cr-2' )['state'] );
		$this->assertFalse( get_option( 'agend_apps_jobs_credit_lock_7', false ), 'the lock is released' );
	}

	#[Test]
	public function reserve_reports_no_credits_as_402(): void {
		$this->provider->credits[7] = array( 'cr-1' => true );

		$result = Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertSame( 'agend_jobs_credits_none', $result->get_error_code() );
		$this->assertSame( 402, $result->get_error_data()['status'] );
	}

	#[Test]
	public function a_lookup_failure_is_not_reported_as_running_out(): void {
		$this->provider->fail = new WP_Error( 'crm_down', 'CRM unreachable', array( 'status' => 503 ) );

		$this->assertSame( 'crm_down', Agend_Apps_Jobs_Credits::reserve( 7 )->get_error_code() );
	}

	#[Test]
	public function a_credit_already_spent_here_is_skipped_even_when_the_crm_still_shows_it_unused(): void {
		$this->provider->credits[7] = array( 'cr-1' => false );
		$this->assertSame( 'cr-1', Agend_Apps_Jobs_Credits::reserve( 7 ) );

		// A stale CRM read reports the spent credit as unused again.
		$this->provider->credits[7]['cr-1'] = false;
		$this->provider->credits[7]['cr-2'] = false;

		$this->assertSame( 'cr-2', Agend_Apps_Jobs_Credits::reserve( 7 ) );
	}

	#[Test]
	public function a_second_reserve_while_one_is_running_is_refused(): void {
		$this->provider->credits[7] = array( 'cr-1' => false );
		add_option( 'agend_apps_jobs_credit_lock_7', time() );

		$this->assertSame( 'agend_jobs_credits_busy', Agend_Apps_Jobs_Credits::reserve( 7 )->get_error_code() );
		$this->assertSame( array(), $this->provider->calls );
	}

	#[Test]
	public function an_abandoned_lock_is_taken_over(): void {
		$this->provider->credits[7] = array( 'cr-1' => false );
		add_option( 'agend_apps_jobs_credit_lock_7', time() - Agend_Apps_Jobs_Credits::LOCK_TTL - 1 );

		$this->assertSame( 'cr-1', Agend_Apps_Jobs_Credits::reserve( 7 ) );
	}

	#[Test]
	public function a_refused_submission_gives_its_credit_back(): void {
		$this->provider->credits[7] = array( 'cr-1' => false );
		Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertTrue( Agend_Apps_Jobs_Credits::release( 'cr-1' ) );
		$this->assertFalse( $this->provider->credits[7]['cr-1'] );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RELEASED, Agend_Apps_Jobs_Credits::entry( 7, 'cr-1' )['state'] );
	}

	#[Test]
	public function a_released_credit_can_be_spent_again(): void {
		$this->provider->credits[7] = array( 'cr-1' => false );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::release( 'cr-1' );

		$this->assertSame( 'cr-1', Agend_Apps_Jobs_Credits::reserve( 7 ) );
	}

	#[Test]
	public function a_rejection_webhook_gives_back_the_credit_for_that_job(): void {
		$this->provider->credits[7] = array( 'cr-1' => false );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		$this->assertTrue( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' ) );

		Agend_Apps_Jobs_Credits::handle_webhook(
			'jobs.submission.rejected',
			array(
				'data' => array(
					'job_id'     => 'job-1',
					'credit_ref' => 'cr-1',
				),
			)
		);

		$this->assertFalse( $this->provider->credits[7]['cr-1'] );
	}

	#[Test]
	public function a_withdrawal_webhook_gives_the_credit_back_too(): void {
		$this->provider->credits[7] = array( 'cr-1' => false );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' );

		Agend_Apps_Jobs_Credits::handle_webhook(
			'jobs.submission.withdrawn',
			array(
				'data' => array(
					'job_id'     => 'job-1',
					'credit_ref' => 'cr-1',
				),
			)
		);

		$this->assertFalse( $this->provider->credits[7]['cr-1'] );
	}

	#[Test]
	public function a_webhook_for_a_different_job_leaves_the_credit_spent(): void {
		$this->provider->credits[7] = array( 'cr-1' => false );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-2' );

		Agend_Apps_Jobs_Credits::handle_webhook(
			'jobs.submission.rejected',
			array(
				'data' => array(
					'job_id'     => 'job-1',
					'credit_ref' => 'cr-1',
				),
			)
		);

		$this->assertTrue( $this->provider->credits[7]['cr-1'] );
		$this->assertNotContains( 'restore:7:cr-1', $this->provider->calls );
	}

	#[Test]
	public function a_duplicate_webhook_after_the_credit_paid_for_a_new_job_is_ignored(): void {
		$this->provider->credits[7] = array( 'cr-1' => false );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' );

		$rejected = array(
			'data' => array(
				'job_id'     => 'job-1',
				'credit_ref' => 'cr-1',
			),
		);

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $rejected );

		// The member spends the returned credit on a new job.
		$this->assertSame( 'cr-1', Agend_Apps_Jobs_Credits::reserve( 7 ) );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-2' );

		// The first rejection is delivered again.
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $rejected );

		$this->assertTrue( $this->provider->credits[7]['cr-1'] );
	}

	#[Test]
	public function a_webhook_for_a_credit_this_site_never_spent_is_ignored(): void {
		Agend_Apps_Jobs_Credits::handle_webhook(
			'jobs.submission.rejected',
			array(
				'data' => array(
					'job_id'     => 'job-1',
					'credit_ref' => 'cr-unknown',
				),
			)
		);

		$this->assertSame( array(), $this->provider->calls );
	}

	#[Test]
	public function other_event_types_are_ignored(): void {
		$this->provider->credits[7] = array( 'cr-1' => false );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' );

		Agend_Apps_Jobs_Credits::handle_webhook(
			'jobs.submission.approved',
			array(
				'data' => array(
					'job_id'     => 'job-1',
					'credit_ref' => 'cr-1',
				),
			)
		);

		$this->assertTrue( $this->provider->credits[7]['cr-1'] );
	}

	#[Test]
	public function a_refused_release_without_a_job_does_not_touch_a_submitted_credit(): void {
		$this->provider->credits[7] = array( 'cr-1' => false );
		Agend_Apps_Jobs_Credits::reserve( 7 );
		Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' );

		$this->assertSame( 'agend_jobs_credits_stale', Agend_Apps_Jobs_Credits::release( 'cr-1' )->get_error_code() );
		$this->assertTrue( $this->provider->credits[7]['cr-1'] );
	}

	#[Test]
	public function attach_needs_a_reserved_credit_and_a_job_id(): void {
		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' ) );

		$this->provider->credits[7] = array( 'cr-1' => false );
		Agend_Apps_Jobs_Credits::reserve( 7 );

		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', '' ) );
		$this->assertTrue( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-1' ) );
		$this->assertFalse( Agend_Apps_Jobs_Credits::attach( 7, 'cr-1', 'job-2' ), 'a submitted credit cannot be moved to another job' );
	}

	#[Test]
	public function the_webhook_listener_is_registered(): void {
		Agend_Apps_Jobs_Credits::register();

		$this->assertContains(
			array( Agend_Apps_Jobs_Credits::class, 'handle_webhook' ),
			\Agend_Test_WP::$actions['agend_apps_webhook_received'] ?? array()
		);
	}
}
