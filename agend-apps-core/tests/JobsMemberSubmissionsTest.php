<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Jobs_Credit_Provider;
use Agend_Apps_Jobs_Credits;
use Agend_Apps_Jobs_Me_REST_Controller;
use Agend_Apps_Jobs_Submissions;
use Agend_Apps_Key_Scopes;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/jobs.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/jobs-credits.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/jobs-submissions.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/jobs-me-routes.php';
require_once __DIR__ . '/fixtures/real-cache.php';

/**
 * One credit per ref, for one member, that records every spend and restore.
 */
final class MemberJobsCreditProvider implements Agend_Apps_Jobs_Credit_Provider {

	/** @var array<string, bool> ref => used */
	public array $used = array();

	/** @var array<int, string> */
	public array $calls = array();

	public function get_key(): string {
		return 'fake';
	}

	public function is_available(): bool {
		return true;
	}

	public function get_available_credits( int $user_id ) {
		$out = array();
		foreach ( $this->used as $ref => $used ) {
			if ( ! $used ) {
				$out[] = array(
					'ref'        => $ref,
					'expires_at' => 'cr-late' === $ref ? '2027-11-01T00:00:00+00:00' : '2027-06-01T00:00:00+00:00',
				);
			}
		}
		return $out;
	}

	public function spend( int $user_id, string $ref ) {
		$this->calls[]      = "spend:$ref";
		$this->used[ $ref ] = true;
		return true;
	}

	public function restore( int $user_id, string $ref ) {
		$this->calls[]      = "restore:$ref";
		$this->used[ $ref ] = false;
		return true;
	}
}

/**
 * A member's job goes to the gateway paid for by one credit: the credit is
 * spent only once nothing else can stop the submission, given back whenever
 * the gateway refused it, and left alone whenever the job may exist.
 */
#[CoversClass( Agend_Apps_Jobs_Submissions::class )]
#[CoversClass( Agend_Apps_Jobs_Me_REST_Controller::class )]
final class JobsMemberSubmissionsTest extends TestCase {

	private const JOB_ID = '11111111-2222-3333-4444-555555555555';

	private MemberJobsCreditProvider $provider;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		agend_test_load_real_cache();
	}

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Endpoint_Scopes::set_map( null );
		Agend_Apps_Jobs_Credits::$lock_poll = 0;

		$this->provider = new MemberJobsCreditProvider();
		$provider       = $this->provider;
		add_filter(
			'agend_apps_jobs_credit_provider',
			static function () use ( $provider ) {
				return $provider;
			}
		);
		add_filter(
			'agend_apps_bearer_token',
			static function () {
				return 'member-jwt';
			}
		);
		wp_set_current_user( 7 );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	private function job_fields(): array {
		return array(
			'title'           => 'SMSF Accountant',
			'employment_type' => 'full-time',
			'location_type'   => 'hybrid',
		);
	}

	private function gateway_error( int $status, string $code, array $details = array() ): array {
		return array(
			'success' => false,
			'error'   => array(
				'code'    => $code,
				'message' => 'Gateway wording the member never sees.',
				'details' => $details,
			),
		);
	}

	private function state( string $ref ): string {
		return (string) ( Agend_Apps_Jobs_Credits::entry( $ref )['state'] ?? '' );
	}

	// ------------------------------------------------------------- sanitize --

	#[Test]
	public function a_new_job_needs_a_title_an_employment_type_and_a_work_arrangement(): void {
		$clean = Agend_Apps_Jobs_Submissions::sanitize( array( 'title' => '  ' ), true );

		$this->assertSame( array( 'title', 'employment_type', 'location_type' ), array_keys( $clean['errors'] ) );
	}

	#[Test]
	public function fields_are_cleaned_to_the_gateway_rules(): void {
		$clean = Agend_Apps_Jobs_Submissions::sanitize(
			$this->job_fields() + array(
				'description'       => "First paragraph.\n\nSecond <b>one</b> & more.",
				'company_website'   => 'https://example.org/about',
				'application_email' => 'jobs@example.org',
				'salary_min'        => '80000',
				'salary_max'        => '120000',
				'show_salary'       => false,
				'category_ids'      => array( 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE' ),
				'company_logo_url'  => 'https://example.org/logo.png',
				'credit_ref'        => 'chosen-by-the-browser',
				'reference_number'  => '',
			),
			true
		);

		$this->assertSame( array(), $clean['errors'] );
		$this->assertStringContainsString( 'First paragraph.', $clean['fields']['description'] );
		$this->assertStringNotContainsString( '<b>', $clean['fields']['description'], 'typed markup never reaches the board' );
		$this->assertSame( 80000, $clean['fields']['salary_min'] );
		$this->assertFalse( $clean['fields']['show_salary'] );
		$this->assertSame( array( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' ), $clean['fields']['category_ids'] );
		$this->assertArrayNotHasKey( 'credit_ref', $clean['fields'], 'the browser never names a credit' );
		$this->assertArrayNotHasKey( 'company_logo_url', $clean['fields'] );
		$this->assertArrayNotHasKey( 'reference_number', $clean['fields'], 'an empty optional field is left out of a new job' );
	}

	#[Test]
	public function bad_values_are_named_per_field(): void {
		$clean = Agend_Apps_Jobs_Submissions::sanitize(
			array_merge(
				$this->job_fields(),
				array(
				'employment_type'   => 'gig',
				'application_url'   => 'javascript:alert(1)',
				'application_email' => 'not-an-email',
				'salary_min'        => '9000',
				'salary_max'        => '100',
				'tag_ids'           => array( 'not-a-uuid' ),
				'title'             => str_repeat( 'x', 201 ),
				)
			),
			true
		);

		$this->assertSame(
			array( 'title', 'employment_type', 'application_url', 'application_email', 'tag_ids', 'salary_max' ),
			array_keys( $clean['errors'] )
		);
	}

	#[Test]
	public function an_edit_clears_emptied_optional_fields_and_keeps_required_ones(): void {
		$clean = Agend_Apps_Jobs_Submissions::sanitize(
			array(
				'company_name'  => '',
				'salary_period' => '',
				'title'         => '',
			),
			false
		);

		$this->assertNull( $clean['fields']['company_name'] );
		$this->assertArrayNotHasKey( 'salary_period', $clean['fields'], 'a field the gateway cannot clear is left as it is' );
		$this->assertSame( array( 'title' ), array_keys( $clean['errors'] ) );
	}

	// --------------------------------------------------------------- submit --

	#[Test]
	public function a_created_job_spends_the_credit_and_is_attached_to_it(): void {
		$this->provider->used['cr-1'] = false;
		Agend_Test_WP::queue_response( 201, array( 'success' => true, 'data' => array( 'id' => self::JOB_ID, 'status' => 'pending' ) ) );

		$item = Agend_Apps_Jobs_Submissions::submit( 7, $this->job_fields() );

		$this->assertSame( self::JOB_ID, $item['id'] );
		$this->assertSame( array( 'spend:cr-1' ), $this->provider->calls );
		$this->assertSame( Agend_Apps_Jobs_Credits::STATE_SUBMITTED, $this->state( 'cr-1' ) );

		$request = Agend_Test_WP::$requests[0];
		$sent    = json_decode( (string) $request['body'], true );
		$this->assertSame( $sent['credit_ref'], $request['headers']['Idempotency-Key'] );
		$this->assertStringStartsWith( 'cr-1#', $sent['credit_ref'] );
	}

	#[Test]
	public function a_refused_job_gives_the_credit_back(): void {
		foreach ( array( 400 => 'VALIDATION_ERROR', 401 => 'MISSING_USER_TOKEN', 403 => 'JOB_SUBMISSIONS_DISABLED', 429 => 'RATE_LIMITED' ) as $status => $code ) {
			$this->provider->used  = array( 'cr-1' => false );
			$this->provider->calls = array();
			Agend_Test_WP::queue_response( $status, $this->gateway_error( $status, $code ) );

			$result = Agend_Apps_Jobs_Submissions::submit( 7, $this->job_fields() );

			$this->assertInstanceOf( WP_Error::class, $result, "$status" );
			$this->assertSame( array( 'spend:cr-1', 'restore:cr-1' ), $this->provider->calls, "$status gives the credit back" );
			$this->assertFalse( $this->provider->used['cr-1'], "$status" );
		}
	}

	#[Test]
	public function a_job_that_may_exist_keeps_its_credit(): void {
		$answers = array(
			'409' => fn() => Agend_Test_WP::queue_response( 409, $this->gateway_error( 409, 'IDEMPOTENCY_IN_PROGRESS' ) ),
			'500' => fn() => Agend_Test_WP::queue_response( 500, $this->gateway_error( 500, 'INTERNAL_ERROR' ) ),
			'timeout' => fn() => Agend_Test_WP::queue_transport_error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
			'201 without an id' => fn() => Agend_Test_WP::queue_response( 201, array( 'success' => true, 'data' => array() ) ),
		);

		$n = 0;
		foreach ( $answers as $label => $queue ) {
			$label = (string) $label;
			$ref                         = 'cr-' . ( ++$n );
			$this->provider->used        = array( $ref => false );
			$this->provider->calls       = array();
			$queue();

			$result = Agend_Apps_Jobs_Submissions::submit( 7, $this->job_fields() );

			$this->assertSame( 'unconfirmed', $result->get_error_code(), $label );
			$this->assertSame( 202, $result->get_error_data()['status'], $label );
			$this->assertSame( array( "spend:$ref" ), $this->provider->calls, "$label never gives the credit back" );
			$this->assertSame( Agend_Apps_Jobs_Credits::STATE_RESERVED, $this->state( $ref ), "$label is left for the sweep" );
		}
	}

	#[Test]
	public function the_gateways_field_errors_come_back_in_the_sites_words(): void {
		$this->provider->used['cr-1'] = false;
		Agend_Test_WP::queue_response( 400, $this->gateway_error( 400, 'VALIDATION_ERROR', array( 'fields' => array( 'application_url' => array( 'Invalid url' ), 'secret' => array( 'x' ) ) ) ) );

		$result = Agend_Apps_Jobs_Submissions::submit( 7, $this->job_fields() );

		$this->assertSame( 'validation_failed', $result->get_error_code() );
		$this->assertSame( array( 'application_url' ), array_keys( $result->get_error_data()['fields'] ) );
		$this->assertStringNotContainsString( 'Gateway wording', $result->get_error_message() );
	}

	#[Test]
	public function nothing_is_spent_when_the_member_has_no_gateway_session(): void {
		$this->provider->used['cr-1'] = false;
		add_filter(
			'agend_apps_bearer_token',
			static function () {
				return '';
			},
			99
		);

		$result = Agend_Apps_Jobs_Submissions::submit( 7, $this->job_fields() );

		$this->assertSame( 'not_connected', $result->get_error_code() );
		$this->assertSame( array(), $this->provider->calls );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function nothing_is_spent_when_the_key_lacks_the_scope(): void {
		$this->provider->used['cr-1'] = false;
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'jobs.listings.browse' ) ) ) );

		$result = Agend_Apps_Jobs_Submissions::submit( 7, $this->job_fields() );

		$this->assertSame( 'unavailable', $result->get_error_code() );
		$this->assertSame( array(), $this->provider->calls );
	}

	#[Test]
	public function a_member_without_credits_is_told_so_and_nothing_is_sent(): void {
		$result = Agend_Apps_Jobs_Submissions::submit( 7, $this->job_fields() );

		$this->assertSame( 'no_credits', $result->get_error_code() );
		$this->assertSame( 402, $result->get_error_data()['status'] );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	// -------------------------------------------------------- manage, list --

	#[Test]
	public function withdrawing_gives_the_credit_back_at_once(): void {
		$this->provider->used['cr-1'] = false;
		Agend_Test_WP::queue_response( 201, array( 'success' => true, 'data' => array( 'id' => self::JOB_ID, 'status' => 'pending' ) ) );
		Agend_Apps_Jobs_Submissions::submit( 7, $this->job_fields() );
		$credit_ref = json_decode( (string) Agend_Test_WP::$requests[0]['body'], true )['credit_ref'];

		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'id' => self::JOB_ID, 'status' => 'withdrawn', 'credit_ref' => $credit_ref ) ) );
		$item = Agend_Apps_Jobs_Submissions::withdraw( 7, self::JOB_ID );

		$this->assertSame( 'withdrawn', $item['status'] );
		$this->assertStringEndsWith( '/jobs/me/' . self::JOB_ID . '/withdraw', Agend_Test_WP::$requests[1]['url'] );
		$this->assertSame( array( 'spend:cr-1', 'restore:cr-1' ), $this->provider->calls );
	}

	#[Test]
	public function a_withdraw_the_gateway_refuses_returns_nothing(): void {
		Agend_Test_WP::queue_response( 409, $this->gateway_error( 409, 'INVALID_STATUS_TRANSITION' ) );

		$result = Agend_Apps_Jobs_Submissions::withdraw( 7, self::JOB_ID );

		$this->assertSame( 'not_editable', $result->get_error_code() );
		$this->assertSame( array(), $this->provider->calls );
	}

	#[Test]
	public function gateway_codes_become_stable_codes(): void {
		$cases = array(
			'NOT_ACCOUNT_CONTACT'      => 'not_contact',
			'JOB_SUBMISSIONS_DISABLED' => 'disabled',
			'INVALID_USER_TOKEN'       => 'not_connected',
			'JOB_NOT_FOUND'            => 'not_found',
			'SOMETHING_NEW'            => 'failed',
		);

		foreach ( $cases as $gateway => $ours ) {
			$error = new WP_Error(
				'agend_api_error',
				'x',
				array(
					'status_code' => 403,
					'body'        => $this->gateway_error( 403, $gateway ),
				)
			);

			$this->assertSame( $ours, Agend_Apps_Jobs_Submissions::gateway_error( $error )->get_error_code(), $gateway );
		}
	}

	#[Test]
	public function the_list_reads_items_and_pagination_from_the_released_shape(): void {
		Agend_Test_WP::queue_response(
			200,
			array(
				'success' => true,
				'data'    => array( array( 'id' => self::JOB_ID, 'status' => 'pending' ) ),
				'meta'    => array( 'pagination' => array( 'page' => 1, 'has_next' => true ) ),
			)
		);

		$mine = Agend_Apps_Jobs_Submissions::mine( 7 );

		$this->assertSame( self::JOB_ID, $mine['items'][0]['id'] );
		$this->assertTrue( $mine['pagination']['has_next'] );
	}

	#[Test]
	public function the_credit_summary_counts_and_names_the_first_expiry(): void {
		$this->provider->used = array(
			'cr-late'  => false,
			'cr-early' => false,
		);

		$this->assertSame(
			array(
				'available'   => 2,
				'next_expiry' => '2027-06-01T00:00:00+00:00',
			),
			Agend_Apps_Jobs_Submissions::credits( 7 )
		);
	}

	// --------------------------------------------------------------- routes --

	#[Test]
	public function the_route_refuses_bad_fields_before_any_credit_is_spent(): void {
		$this->provider->used['cr-1'] = false;
		$request                      = new WP_REST_Request( 'POST', '/agend-apps/v1/jobs/me' );
		$request->set_body( (string) wp_json_encode( array( 'title' => 'Accountant' ) ) );
		$request->set_header( 'content-type', 'application/json' );

		$response = ( new Agend_Apps_Jobs_Me_REST_Controller() )->submit( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'validation_failed', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'employment_type', $response->get_data()['fields'] );
		$this->assertSame( array(), $this->provider->calls );
	}

	#[Test]
	public function the_route_answers_a_created_job_with_201(): void {
		$this->provider->used['cr-1'] = false;
		Agend_Test_WP::queue_response( 201, array( 'success' => true, 'data' => array( 'id' => self::JOB_ID, 'status' => 'pending' ) ) );
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/jobs/me' );
		$request->set_body( (string) wp_json_encode( $this->job_fields() ) );
		$request->set_header( 'content-type', 'application/json' );

		$response = ( new Agend_Apps_Jobs_Me_REST_Controller() )->submit( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	#[Test]
	public function only_a_signed_in_user_reaches_the_routes(): void {
		wp_set_current_user( 0 );

		$this->assertInstanceOf( WP_Error::class, ( new Agend_Apps_Jobs_Me_REST_Controller() )->signed_in() );
	}
}
