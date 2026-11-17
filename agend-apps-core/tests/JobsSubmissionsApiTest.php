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

/**
 * A member's job submissions go to `/v1/jobs/submissions` with the site key
 * alone, the member named by their contact pair.
 */
final class JobsSubmissionsApiTest extends TestCase {

	private const CONTACT = array(
		'external_source' => 'upbeat-contacts',
		'external_id'     => 'M1001',
	);

	private const ID = '0b9c2f4e-1d2a-4c3b-8e5f-6a7b8c9d0e1f';

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Endpoint_Scopes::set_map( null );
		// A signed-in member with a bearer, which these routes must never send.
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	private function request( int $index = 0 ): array {
		return Agend_Test_WP::$requests[ $index ];
	}

	private function body( int $index = 0 ): array {
		return json_decode( (string) $this->request( $index )['body'], true );
	}

	#[Test]
	public function a_post_sends_the_contact_and_job_with_the_idempotency_key_and_no_bearer(): void {
		Agend_Test_WP::queue_response( 202, array( 'success' => true, 'data' => array( 'id' => self::ID, 'status' => 'checking_credit' ) ) );

		$contact = self::CONTACT + array(
			'first_name' => 'Sam',
			'last_name'  => 'Lee',
			'email'      => 'sam@example.com',
		);

		$response = agend_apps_jobs_create_submission( $contact, array( 'title' => 'Accountant' ), 'key-1' );

		$request = $this->request();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertStringEndsWith( '/jobs/submissions', $request['url'] );
		$this->assertSame( 'key-1', $request['headers']['Idempotency-Key'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
		$this->assertSame(
			array(
				'contact' => $contact,
				'job'     => array( 'title' => 'Accountant' ),
			),
			$this->body()
		);
		$this->assertSame( 'checking_credit', $response['data']['status'] );
	}

	#[Test]
	public function the_list_names_the_member_by_their_pair_and_is_never_served_from_cache(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array() ) );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array() ) );

		agend_apps_jobs_get_submissions( self::CONTACT, array( 'page' => 2 ) );
		agend_apps_jobs_get_submissions( self::CONTACT, array( 'page' => 2 ) );

		$this->assertCount( 2, Agend_Test_WP::$requests );
		$url = $this->request()['url'];
		$this->assertStringContainsString( '/jobs/submissions?', $url );
		$this->assertStringContainsString( 'external_source=upbeat-contacts', $url );
		$this->assertStringContainsString( 'external_id=M1001', $url );
		$this->assertStringContainsString( 'page=2', $url );
		$this->assertArrayNotHasKey( 'Authorization', $this->request()['headers'] );
	}

	#[Test]
	public function one_submission_is_read_by_id_with_the_pair(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'id' => self::ID, 'status' => 'pending' ) ) );

		$response = agend_apps_jobs_get_submission( self::ID, self::CONTACT );

		$this->assertSame( 'GET', $this->request()['method'] );
		$this->assertStringContainsString( '/jobs/submissions/' . self::ID . '?', $this->request()['url'] );
		$this->assertStringContainsString( 'external_id=M1001', $this->request()['url'] );
		$this->assertSame( 'pending', $response['data']['status'] );
	}

	#[Test]
	public function the_credit_count_names_the_member_by_their_pair(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'available' => 2, 'checked_at' => '2026-11-17T00:00:00Z', 'stale' => false ) ) );

		$response = agend_apps_jobs_get_credits( self::CONTACT );

		$this->assertStringContainsString( '/jobs/credits?', $this->request()['url'] );
		$this->assertStringContainsString( 'external_source=upbeat-contacts', $this->request()['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->request()['headers'] );
		$this->assertSame( 2, $response['data']['available'] );
	}

	#[Test]
	public function an_edit_sends_only_the_pair_and_the_job(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'id' => self::ID ) ) );

		agend_apps_jobs_update_submission( self::ID, self::CONTACT + array( 'email' => 'sam@example.com' ), array( 'title' => 'Senior Accountant' ) );

		$this->assertSame( 'PATCH', $this->request()['method'] );
		$this->assertStringEndsWith( '/jobs/submissions/' . self::ID, $this->request()['url'] );
		$this->assertSame(
			array(
				'contact' => self::CONTACT,
				'job'     => array( 'title' => 'Senior Accountant' ),
			),
			$this->body()
		);
	}

	#[Test]
	public function withdraw_and_close_send_the_pair(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'id' => self::ID ) ) );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'id' => self::ID ) ) );

		agend_apps_jobs_withdraw_submission( self::ID, self::CONTACT );
		agend_apps_jobs_close_submission( self::ID, self::CONTACT, true );

		$this->assertStringEndsWith( '/jobs/submissions/' . self::ID . '/withdraw', $this->request( 0 )['url'] );
		$this->assertSame( array( 'contact' => self::CONTACT ), $this->body( 0 ) );
		$this->assertStringEndsWith( '/jobs/submissions/' . self::ID . '/close', $this->request( 1 )['url'] );
		$this->assertSame(
			array(
				'contact' => self::CONTACT,
				'filled'  => true,
			),
			$this->body( 1 )
		);
	}

	#[Test]
	public function an_id_is_encoded_into_the_path(): void {
		Agend_Test_WP::queue_response( 404, array( 'success' => false, 'error' => array( 'code' => 'JOB_NOT_FOUND' ) ) );

		agend_apps_jobs_get_submission( '../x', self::CONTACT );

		$this->assertStringContainsString( '/jobs/submissions/..%2Fx?', $this->request()['url'] );
	}
}
