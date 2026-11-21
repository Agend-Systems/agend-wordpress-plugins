<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Jobs_Credits;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Settings;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/jobs.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/jobs-credits.php';

/**
 * Agend counts a member's job credits; the site only caches the count and
 * offers it to ESAC rules.
 */
#[CoversClass( Agend_Apps_Jobs_Credits::class )]
final class JobsCreditsTest extends TestCase {

	private const USER = 42;

	protected function setUp(): void {
		parent::setUp();
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );
		update_user_meta( self::USER, 'imk_membership_number', 'M1001' );
		$GLOBALS['agend_test_current_user_id'] = self::USER;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_current_user_id'] );
		parent::tearDown();
	}

	private function count_response( int $available ): void {
		Agend_Test_WP::queue_response(
			200,
			array(
				'success' => true,
				'data'    => array(
					'available'  => $available,
					'checked_at' => '2026-11-17T00:00:00Z',
					'stale'      => false,
				),
			)
		);
	}

	private function error_response( int $status, string $code ): void {
		Agend_Test_WP::queue_response(
			$status,
			array(
				'success' => false,
				'error'   => array(
					'code'    => $code,
					'message' => 'x',
				),
			)
		);
	}

	private function know_scopes( array $scopes ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array() ) ) );
	}

	#[Test]
	public function a_member_is_named_by_the_upbeat_source_and_their_membership_number(): void {
		$this->assertSame(
			array(
				'external_source' => 'upbeat-contacts',
				'external_id'     => 'M1001',
			),
			Agend_Apps_Jobs_Credits::contact( self::USER )
		);
	}

	#[Test]
	public function the_source_can_be_set_by_option_or_filter(): void {
		update_option( Agend_Apps_Jobs_Credits::SOURCE_OPTION, 'other-crm' );
		$this->assertSame( 'other-crm', Agend_Apps_Jobs_Credits::source() );

		Agend_Test_WP::set_filter( 'agend_apps_jobs_contact_source', 'filtered' );
		$this->assertSame( 'filtered', Agend_Apps_Jobs_Credits::source() );
	}

	#[Test]
	public function a_member_without_an_external_id_has_no_contact_and_no_count(): void {
		delete_user_meta( self::USER, 'imk_membership_number' );

		$this->assertNull( Agend_Apps_Jobs_Credits::contact( self::USER ) );
		$this->assertSame( 'agend_jobs_credits_no_member', Agend_Apps_Jobs_Credits::count( self::USER )->get_error_code() );
		$this->assertNull( Agend_Apps_Jobs_Credits::contact( 0 ) );
		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function the_count_is_read_once_and_then_cached(): void {
		$this->count_response( 3 );

		$this->assertSame( 3, Agend_Apps_Jobs_Credits::count( self::USER ) );
		$this->assertSame( 3, Agend_Apps_Jobs_Credits::count( self::USER ) );

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertStringContainsString( 'external_id=M1001', Agend_Test_WP::$requests[0]['url'] );
		$this->assertContains( Agend_Apps_Jobs_Credits::CACHE_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function forgetting_the_count_reads_it_again(): void {
		$this->count_response( 1 );
		$this->count_response( 0 );

		Agend_Apps_Jobs_Credits::count( self::USER );
		Agend_Apps_Jobs_Credits::forget( Agend_Apps_Jobs_Credits::contact( self::USER ) );

		$this->assertSame( 0, Agend_Apps_Jobs_Credits::count( self::USER ) );
		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_count_that_cannot_be_read_is_an_error_remembered_briefly(): void {
		$this->error_response( 503, 'CREDIT_COUNT_UNAVAILABLE' );

		$first  = Agend_Apps_Jobs_Credits::count( self::USER );
		$second = Agend_Apps_Jobs_Credits::count( self::USER );

		$this->assertInstanceOf( WP_Error::class, $first );
		$this->assertSame( 'agend_jobs_credits_unavailable', $first->get_error_code() );
		$this->assertSame( 'agend_jobs_credits_unavailable', $second->get_error_code() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertContains( Agend_Apps_Jobs_Credits::FAILURE_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function a_site_that_cannot_count_credits_is_told_so(): void {
		foreach ( array( 'SOURCE_NOT_ALLOWED', 'JOB_CREDITS_NOT_CONFIGURED', 'JOB_SUBMISSIONS_DISABLED', 'INSUFFICIENT_SCOPES' ) as $code ) {
			Agend_Test_WP::$transients = array();
			$this->error_response( 403, $code );

			$this->assertSame( 'agend_jobs_credits_not_available', Agend_Apps_Jobs_Credits::count( self::USER )->get_error_code(), $code );
		}

		$this->assertContains( Agend_Apps_Jobs_Credits::REFUSED_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function an_answer_without_a_count_is_not_read_as_zero(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'available' => '2' ) ) );

		$this->assertSame( 'agend_jobs_credits_unavailable', Agend_Apps_Jobs_Credits::count( self::USER )->get_error_code() );
	}

	#[Test]
	public function a_key_known_to_lack_the_scope_sends_nothing(): void {
		$this->know_scopes( array( 'jobs.listings.browse' ) );

		$this->assertSame( 'agend_jobs_credits_not_available', Agend_Apps_Jobs_Credits::count( self::USER )->get_error_code() );
		$this->assertCount( 0, Agend_Test_WP::$requests );
		$this->assertSame( array(), Agend_Apps_Jobs_Credits::esac_condition_sets( array() ) );
	}

	#[Test]
	public function a_rejected_or_withdrawn_job_forgets_its_members_count(): void {
		$this->count_response( 0 );
		$this->count_response( 1 );
		$this->count_response( 2 );
		Agend_Apps_Jobs_Credits::count( self::USER );

		$data = array(
			'data' => array(
				'contact_external_source' => 'upbeat-contacts',
				'contact_external_id'     => 'M1001',
			),
		);

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', $data );
		$this->assertSame( 1, Agend_Apps_Jobs_Credits::count( self::USER ) );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.withdrawn', $data );
		$this->assertSame( 2, Agend_Apps_Jobs_Credits::count( self::USER ) );
	}

	#[Test]
	public function other_webhooks_and_other_members_leave_the_count_cached(): void {
		$this->count_response( 1 );
		Agend_Apps_Jobs_Credits::count( self::USER );

		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.approved', array( 'data' => array( 'contact_external_source' => 'upbeat-contacts', 'contact_external_id' => 'M1001' ) ) );
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', array( 'data' => array( 'contact_external_source' => 'upbeat-contacts', 'contact_external_id' => 'M2002' ) ) );
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', array( 'data' => array( 'contact_external_id' => 'M1001' ) ) );
		Agend_Apps_Jobs_Credits::handle_webhook( 'jobs.submission.rejected', 'not an envelope' );

		$this->assertSame( 1, Agend_Apps_Jobs_Credits::count( self::USER ) );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function the_esac_condition_is_offered_and_passes_only_with_a_credit(): void {
		$sets = Agend_Apps_Jobs_Credits::esac_condition_sets( array( 'other' => array() ) );
		$this->assertArrayHasKey( 'other', $sets );
		$this->assertArrayHasKey( 'has_job_credit', $sets['agend_jobs']['conditions'] );

		$this->count_response( 1 );
		$this->assertTrue( Agend_Apps_Jobs_Credits::esac_check( true, 'has_job_credit' ) );
		$this->assertFalse( Agend_Apps_Jobs_Credits::esac_check( false, 'has_job_credit' ) );

		Agend_Apps_Jobs_Credits::forget( Agend_Apps_Jobs_Credits::contact( self::USER ) );
		$this->count_response( 0 );
		$this->assertFalse( Agend_Apps_Jobs_Credits::esac_check( true, 'has_job_credit' ) );
	}

	#[Test]
	public function the_esac_condition_fails_for_a_visitor_or_an_unreadable_count(): void {
		$this->error_response( 503, 'CREDIT_COUNT_UNAVAILABLE' );
		$this->assertFalse( Agend_Apps_Jobs_Credits::esac_check( true, 'has_job_credit' ) );

		$GLOBALS['agend_test_current_user_id'] = 0;
		$this->assertFalse( Agend_Apps_Jobs_Credits::esac_check( true, 'has_job_credit' ) );
	}

	#[Test]
	public function other_esac_conditions_pass_through(): void {
		$this->assertTrue( Agend_Apps_Jobs_Credits::esac_check( true, 'something_else' ) );
		$this->assertFalse( Agend_Apps_Jobs_Credits::esac_check( false, 'something_else' ) );
		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function free_listings_are_cached_with_zero_count_and_allow_posting_and_esac(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'available' => 0, 'free_listings' => true ) ) );
		$this->assertSame( 0, Agend_Apps_Jobs_Credits::count( self::USER ) );
		$this->assertTrue( Agend_Apps_Jobs_Credits::free_listings( self::USER ) );
		$this->assertTrue( Agend_Apps_Jobs_Credits::can_post( self::USER ) );
		$this->assertTrue( Agend_Apps_Jobs_Credits::esac_check( true, 'has_job_credit' ) );
		$this->assertFalse( Agend_Apps_Jobs_Credits::esac_check( false, 'has_job_credit' ) );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		Agend_Apps_Jobs_Credits::forget( Agend_Apps_Jobs_Credits::contact( self::USER ) );
		$this->count_response( 0 );
		$this->assertFalse( Agend_Apps_Jobs_Credits::free_listings( self::USER ) );
		$this->assertFalse( Agend_Apps_Jobs_Credits::can_post( self::USER ) );
		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function absent_false_or_non_boolean_free_flags_keep_the_existing_credit_gate(): void {
		foreach ( array( null, false, 'true', 1 ) as $flag ) {
			Agend_Apps_Jobs_Credits::forget( Agend_Apps_Jobs_Credits::contact( self::USER ) );
			$data = array( 'available' => 0 );
			if ( null !== $flag ) { $data['free_listings'] = $flag; }
			Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => $data ) );
			$this->assertFalse( Agend_Apps_Jobs_Credits::can_post( self::USER ) );
			$this->assertFalse( Agend_Apps_Jobs_Credits::free_listings( self::USER ) );
		}
	}

}
