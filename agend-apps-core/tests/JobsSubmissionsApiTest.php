<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Endpoint_Scopes;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/jobs.php';
require_once __DIR__ . '/fixtures/real-cache.php';

/**
 * A member's job submissions go to `/v1/jobs/me`: submitted with the credit
 * that pays for them as the idempotency key, and found again by that credit.
 */
final class JobsSubmissionsApiTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		agend_test_load_real_cache();
	}

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Endpoint_Scopes::set_map( null );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	private function page( array $items, int $page, int $total_pages ): array {
		return array(
			'success'    => true,
			'data'       => $items,
			'pagination' => array(
				'page'        => $page,
				'limit'       => 100,
				'total'       => count( $items ),
				'total_pages' => $total_pages,
			),
		);
	}

	#[Test]
	public function a_submission_sends_its_credit_ref_as_the_idempotency_key(): void {
		Agend_Test_WP::queue_response( 201, array( 'id' => 'job-1', 'status' => 'pending' ) );

		agend_apps_jobs_submit( array( 'title' => 'Accountant' ), 'cr-1#0123456789abcdef' );

		$request = Agend_Test_WP::$requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertStringEndsWith( '/jobs/me', $request['url'] );
		$this->assertSame( 'cr-1#0123456789abcdef', $request['headers']['Idempotency-Key'] );
		$this->assertSame(
			array(
				'title'      => 'Accountant',
				'credit_ref' => 'cr-1#0123456789abcdef',
			),
			json_decode( (string) $request['body'], true )
		);
	}

	#[Test]
	public function the_submissions_list_is_never_served_from_cache(): void {
		Agend_Test_WP::queue_response( 200, $this->page( array(), 1, 1 ) );
		Agend_Test_WP::queue_response( 200, $this->page( array(), 1, 1 ) );

		agend_apps_jobs_get_my_submissions();
		agend_apps_jobs_get_my_submissions();

		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_submission_is_found_by_its_credit_ref_across_pages(): void {
		Agend_Test_WP::queue_response( 200, $this->page( array( array( 'id' => 'job-1', 'credit_ref' => 'other#0123456789abcdef', 'status' => 'pending' ) ), 1, 2 ) );
		Agend_Test_WP::queue_response( 200, $this->page( array( array( 'id' => 'job-2', 'credit_ref' => 'cr-1#0123456789abcdef', 'status' => 'published' ) ), 2, 2 ) );

		$found = agend_apps_jobs_find_my_submission( 'cr-1#0123456789abcdef' );

		$this->assertSame( 'job-2', $found['id'] );
		$this->assertStringContainsString( 'page=2', Agend_Test_WP::$requests[1]['url'] );
	}

	#[Test]
	public function a_live_submission_is_preferred_over_a_refused_one_with_the_same_credit_ref(): void {
		Agend_Test_WP::queue_response(
			200,
			$this->page(
				array(
					array( 'id' => 'job-old', 'credit_ref' => 'cr-1#0123456789abcdef', 'status' => 'rejected' ),
					array( 'id' => 'job-new', 'credit_ref' => 'cr-1#0123456789abcdef', 'status' => 'pending' ),
				),
				1,
				1
			)
		);

		$this->assertSame( 'job-new', agend_apps_jobs_find_my_submission( 'cr-1#0123456789abcdef' )['id'] );
	}

	#[Test]
	public function a_refused_submission_is_returned_when_it_is_the_only_one(): void {
		Agend_Test_WP::queue_response( 200, $this->page( array( array( 'id' => 'job-1', 'credit_ref' => 'cr-1#0123456789abcdef', 'status' => 'withdrawn' ) ), 1, 1 ) );

		$this->assertSame( 'withdrawn', agend_apps_jobs_find_my_submission( 'cr-1#0123456789abcdef' )['status'] );
	}

	#[Test]
	public function no_submission_is_null_and_a_failed_lookup_is_an_error(): void {
		Agend_Test_WP::queue_response( 200, $this->page( array(), 1, 0 ) );
		$this->assertNull( agend_apps_jobs_find_my_submission( 'cr-1#0123456789abcdef' ) );

		Agend_Test_WP::queue_response( 503, array( 'success' => false, 'error' => array( 'code' => 'UNAVAILABLE' ) ) );
		$this->assertInstanceOf( \WP_Error::class, agend_apps_jobs_find_my_submission( 'cr-1#0123456789abcdef' ) );
	}

	#[Test]
	public function a_list_without_items_is_an_error_not_an_empty_result(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true ) );

		$this->assertSame( 'agend_jobs_submissions_invalid', agend_apps_jobs_find_my_submission( 'cr-1#0123456789abcdef' )->get_error_code() );
	}
}
