<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Jobs_Credits;
use Agend_Apps_Jobs_Me_REST_Controller;
use Agend_Apps_Jobs_Submissions;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Settings;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;
use WP_REST_Request;
use WP_User;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/jobs.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/jobs-credits.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/jobs-submissions.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/jobs-me-routes.php';
require_once __DIR__ . '/fixtures/real-cache.php';

/**
 * A member's job goes to Agend named by their contact pair, and Agend decides
 * whether a credit pays for it. The site never spends or returns a credit; it
 * passes on what Agend says in its own words and forgets its cached count
 * whenever a credit may have changed hands.
 */
#[CoversClass( Agend_Apps_Jobs_Submissions::class )]
#[CoversClass( Agend_Apps_Jobs_Me_REST_Controller::class )]
final class JobsMemberSubmissionsTest extends TestCase {

	private const USER = 7;

	private const JOB_ID = '11111111-2222-3333-4444-555555555555';

	private const REQUEST_ID = '6f1c2d3e-4b5a-4978-8a1b-2c3d4e5f6a7b';

	private const CONTACT = array(
		'external_source' => 'upbeat-contacts',
		'external_id'     => 'M1001',
	);

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		agend_test_load_real_cache();
	}

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Endpoint_Scopes::set_map( null );
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );

		$user                        = new WP_User( self::USER );
		$user->user_email            = 'sam@example.com';
		$GLOBALS['agend_test_users'] = array( $user );
		update_user_meta( self::USER, 'imk_membership_number', 'M1001' );
		update_user_meta( self::USER, 'first_name', 'Sam' );
		update_user_meta( self::USER, 'last_name', 'Lee' );

		// A member bearer that these calls must never send.
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-jwt' );
		wp_set_current_user( self::USER );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		$GLOBALS['agend_test_users'] = array();
		parent::tearDown();
	}

	private function job_fields(): array {
		return array(
			'title'           => 'SMSF Accountant',
			'employment_type' => 'full-time',
			'location_type'   => 'hybrid',
		);
	}

	private function gateway_error( string $code, array $details = array() ): array {
		return array(
			'success' => false,
			'error'   => array(
				'code'    => $code,
				'message' => 'Gateway wording the member never sees.',
				'details' => $details,
			),
		);
	}

	private function accepted( string $status = 'checking_credit' ): void {
		Agend_Test_WP::queue_response(
			202,
			array(
				'success' => true,
				'data'    => array(
					'id'              => self::JOB_ID,
					'status'          => $status,
					'contact'         => array(
						'id'          => '99999999-8888-7777-6666-555555555555',
						'external_id' => 'M1001',
					),
					'contact_created' => true,
				),
			)
		);
	}

	private function item( array $extra = array() ): array {
		return array(
			'success' => true,
			'data'    => $extra + array(
				'id'         => self::JOB_ID,
				'title'      => 'SMSF Accountant',
				'status'     => 'pending',
				'credit_ref' => 'ENT098467',
				'account_id' => 'acct',
				'contact'    => self::CONTACT + array( 'id' => 'contact-uuid' ),
			),
		);
	}

	private function body( int $index = 0 ): array {
		return json_decode( (string) Agend_Test_WP::$requests[ $index ]['body'], true );
	}

	/** Puts a cached count in place, so a test can see it forgotten. */
	private function cache_count( int $available ): string {
		$key = Agend_Apps_Jobs_Credits::CACHE_PREFIX . md5( 'upbeat-contacts|M1001' );
		set_transient( $key, array( 'available' => $available ), 300 );

		return $key;
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
				'request_id'        => self::REQUEST_ID,
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
		$this->assertArrayNotHasKey( 'request_id', $clean['fields'], 'the request id is not a job field' );
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

	#[Test]
	public function a_description_longer_than_the_typed_limit_is_refused(): void {
		$clean = Agend_Apps_Jobs_Submissions::sanitize( $this->job_fields() + array( 'description' => str_repeat( 'a', Agend_Apps_Jobs_Submissions::DESCRIPTION_TEXT_MAX + 1 ) ), true );

		$this->assertArrayHasKey( 'description', $clean['errors'] );
	}

	// --------------------------------------------------------------- submit --

	#[Test]
	public function a_post_names_the_member_by_their_pair_with_their_name_and_email_and_no_bearer(): void {
		$this->accepted();

		$result = Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID );

		$this->assertSame(
			array(
				'id'     => self::JOB_ID,
				'status' => 'checking_credit',
			),
			$result
		);

		$request = Agend_Test_WP::$requests[0];
		$this->assertStringEndsWith( '/jobs/submissions', $request['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
		$this->assertSame( 'wp-7-' . self::REQUEST_ID, $request['headers']['Idempotency-Key'] );
		$this->assertSame(
			array(
				'external_source' => 'upbeat-contacts',
				'external_id'     => 'M1001',
				'first_name'      => 'Sam',
				'last_name'       => 'Lee',
				'email'           => 'sam@example.com',
			),
			$this->body()['contact']
		);
		$this->assertSame( $this->job_fields(), $this->body()['job'] );
	}

	#[Test]
	public function the_same_request_id_gives_the_same_idempotency_key_and_a_bad_one_a_fresh_key(): void {
		$this->assertSame( Agend_Apps_Jobs_Submissions::idempotency_key( 7, self::REQUEST_ID ), Agend_Apps_Jobs_Submissions::idempotency_key( 7, self::REQUEST_ID ) );
		$this->assertNotSame( Agend_Apps_Jobs_Submissions::idempotency_key( 7, self::REQUEST_ID ), Agend_Apps_Jobs_Submissions::idempotency_key( 8, self::REQUEST_ID ), 'scoped to the member' );
		$this->assertMatchesRegularExpression( '/^wp-7-[0-9a-f-]{36}$/', Agend_Apps_Jobs_Submissions::idempotency_key( 7, 'x y' ) );
	}

	#[Test]
	public function a_post_forgets_the_cached_credit_count(): void {
		$key = $this->cache_count( 1 );
		$this->accepted();

		Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID );

		$this->assertArrayNotHasKey( $key, Agend_Test_WP::$transients );
	}

	#[Test]
	public function a_post_that_may_have_landed_is_unconfirmed(): void {
		foreach ( array( 500, 502, 503 ) as $status ) {
			Agend_Test_WP::$requests = array();
			Agend_Test_WP::queue_response( $status, $this->gateway_error( 'INTERNAL_ERROR' ) );
			$this->assertSame( 'unconfirmed', Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID )->get_error_code(), (string) $status );
		}

		Agend_Test_WP::queue_transport_error( 'http_request_failed', 'timed out' );
		$this->assertSame( 'unconfirmed', Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID )->get_error_code() );

		Agend_Test_WP::queue_response( 409, $this->gateway_error( 'IDEMPOTENCY_KEY_REUSED' ) );
		$this->assertSame( 'unconfirmed', Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID )->get_error_code() );

		Agend_Test_WP::queue_response( 202, array( 'success' => true, 'data' => array( 'status' => 'checking_credit' ) ) );
		$this->assertSame( 'unconfirmed', Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID )->get_error_code(), 'no job id' );
	}

	#[Test]
	public function a_member_without_a_name_or_email_is_asked_to_add_one_and_nothing_is_sent(): void {
		delete_user_meta( self::USER, 'last_name' );
		$this->assertSame( 'profile_incomplete', Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID )->get_error_code() );

		update_user_meta( self::USER, 'last_name', 'Lee' );
		$GLOBALS['agend_test_users'][0]->user_email = 'not-an-email';
		$this->assertSame( 'profile_incomplete', Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID )->get_error_code() );

		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_member_without_a_membership_number_cannot_post(): void {
		delete_user_meta( self::USER, 'imk_membership_number' );

		$error = Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID );

		$this->assertSame( 'not_connected', $error->get_error_code() );
		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function nothing_is_sent_when_the_key_lacks_the_scope_or_the_site_is_rate_limited(): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'jobs.submissions.browse' ), 'app_ids' => array() ) ) );
		$this->assertSame( 'unavailable', Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID )->get_error_code() );
		$this->assertFalse( Agend_Apps_Jobs_Submissions::available() );

		delete_option( Agend_Apps_Key_Scopes::OPTION );
		set_transient( 'agend_apps_rate_limit_remaining', 0, 60 );
		$this->assertSame( 'rate_limited', Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID )->get_error_code() );

		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function the_gateways_field_errors_come_back_in_the_sites_words(): void {
		Agend_Test_WP::queue_response( 400, $this->gateway_error( 'VALIDATION_ERROR', array( 'fields' => array( 'job.application_url' => array( 'Invalid url' ) ) ) ) );

		$error = Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID );

		$this->assertSame( 'validation_failed', $error->get_error_code() );
		$this->assertSame( array( 'application_url' => Agend_Apps_Jobs_Submissions::message( 'check' ) ), $error->get_error_data()['fields'] );
	}

	#[Test]
	public function gateway_codes_become_stable_codes(): void {
		$cases = array(
			array( 403, 'JOB_SUBMISSIONS_DISABLED', 'disabled' ),
			array( 403, 'SOURCE_NOT_ALLOWED', 'unavailable' ),
			array( 403, 'JOB_CREDITS_NOT_CONFIGURED', 'unavailable' ),
			array( 403, 'INSUFFICIENT_SCOPES', 'unavailable' ),
			array( 409, 'CONTACT_CONFLICT', 'not_contact' ),
			array( 429, 'RATE_LIMITED', 'rate_limited' ),
			array( 401, 'UNAUTHORIZED', 'failed' ),
		);

		foreach ( $cases as $case ) {
			Agend_Test_WP::queue_response( $case[0], $this->gateway_error( $case[1] ) );
			$error = Agend_Apps_Jobs_Submissions::submit( self::USER, $this->job_fields(), self::REQUEST_ID );

			$this->assertSame( $case[2], $error->get_error_code(), $case[1] );
			$this->assertStringNotContainsString( 'Gateway wording', $error->get_error_message() );
		}
	}

	// ---------------------------------------------------- the other routes --

	#[Test]
	public function one_job_is_read_with_the_pair_and_without_private_keys(): void {
		Agend_Test_WP::queue_response( 200, $this->item() );

		$item = Agend_Apps_Jobs_Submissions::get( self::USER, self::JOB_ID );

		$this->assertStringContainsString( '/jobs/submissions/' . self::JOB_ID . '?', Agend_Test_WP::$requests[0]['url'] );
		$this->assertStringContainsString( 'external_id=M1001', Agend_Test_WP::$requests[0]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'] );
		$this->assertSame( 'pending', $item['status'] );
		$this->assertArrayNotHasKey( 'credit_ref', $item );
		$this->assertArrayNotHasKey( 'account_id', $item );
		$this->assertArrayNotHasKey( 'contact', $item );
	}

	#[Test]
	public function a_settled_check_forgets_the_count_and_a_running_one_does_not(): void {
		$key = $this->cache_count( 1 );
		Agend_Test_WP::queue_response( 200, $this->item( array( 'status' => 'checking_credit' ) ) );
		Agend_Apps_Jobs_Submissions::get( self::USER, self::JOB_ID );
		$this->assertArrayHasKey( $key, Agend_Test_WP::$transients );

		Agend_Test_WP::queue_response( 200, $this->item( array( 'status' => 'no_credit' ) ) );
		$this->assertSame( 'no_credit', Agend_Apps_Jobs_Submissions::get( self::USER, self::JOB_ID )['status'] );
		$this->assertArrayNotHasKey( $key, Agend_Test_WP::$transients );
	}

	#[Test]
	public function a_job_that_is_not_the_members_is_not_found(): void {
		Agend_Test_WP::queue_response( 404, $this->gateway_error( 'JOB_NOT_FOUND' ) );

		$this->assertSame( 'not_found', Agend_Apps_Jobs_Submissions::get( self::USER, self::JOB_ID )->get_error_code() );
	}

	#[Test]
	public function agends_statuses_are_shown_as_the_member_sees_them(): void {
		$this->assertSame( 'published', Agend_Apps_Jobs_Submissions::display_status( array( 'status' => 'approved', 'expired' => false ) ) );
		$this->assertSame( 'expired', Agend_Apps_Jobs_Submissions::display_status( array( 'status' => 'approved', 'expired' => true ) ) );
		$this->assertSame( 'filled', Agend_Apps_Jobs_Submissions::display_status( array( 'status' => 'closed', 'filled' => true ) ) );
		$this->assertSame( 'closed', Agend_Apps_Jobs_Submissions::display_status( array( 'status' => 'closed', 'filled' => false ) ) );

		foreach ( array( 'checking_credit', 'pending', 'no_credit', 'rejected', 'withdrawn' ) as $status ) {
			$this->assertSame( $status, Agend_Apps_Jobs_Submissions::display_status( array( 'status' => $status ) ) );
		}
	}

	#[Test]
	public function an_edit_sends_the_pair_and_the_changes(): void {
		Agend_Test_WP::queue_response( 200, $this->item( array( 'title' => 'Senior Accountant' ) ) );

		$item = Agend_Apps_Jobs_Submissions::update( self::USER, self::JOB_ID, array( 'title' => 'Senior Accountant' ) );

		$this->assertSame( 'PATCH', Agend_Test_WP::$requests[0]['method'] );
		$this->assertSame(
			array(
				'contact' => self::CONTACT,
				'job'     => array( 'title' => 'Senior Accountant' ),
			),
			$this->body()
		);
		$this->assertSame( 'Senior Accountant', $item['title'] );
	}

	#[Test]
	public function an_edit_of_a_job_no_longer_pending_is_refused(): void {
		Agend_Test_WP::queue_response( 409, $this->gateway_error( 'JOB_NOT_EDITABLE' ) );

		$this->assertSame( 'not_editable', Agend_Apps_Jobs_Submissions::update( self::USER, self::JOB_ID, array( 'title' => 'x' ) )->get_error_code() );
	}

	#[Test]
	public function withdrawing_forgets_the_count_because_agend_frees_the_credit(): void {
		$key = $this->cache_count( 0 );
		Agend_Test_WP::queue_response( 200, $this->item( array( 'status' => 'withdrawn' ) ) );

		$item = Agend_Apps_Jobs_Submissions::withdraw( self::USER, self::JOB_ID );

		$this->assertStringEndsWith( '/jobs/submissions/' . self::JOB_ID . '/withdraw', Agend_Test_WP::$requests[0]['url'] );
		$this->assertSame( array( 'contact' => self::CONTACT ), $this->body() );
		$this->assertSame( 'withdrawn', $item['status'] );
		$this->assertArrayNotHasKey( $key, Agend_Test_WP::$transients );
	}

	#[Test]
	public function a_refused_withdrawal_keeps_the_count(): void {
		$key = $this->cache_count( 0 );
		Agend_Test_WP::queue_response( 409, $this->gateway_error( 'INVALID_STATUS_TRANSITION' ) );

		$this->assertSame( 'not_editable', Agend_Apps_Jobs_Submissions::withdraw( self::USER, self::JOB_ID )->get_error_code() );
		$this->assertArrayHasKey( $key, Agend_Test_WP::$transients );
	}

	#[Test]
	public function closing_sends_filled_and_keeps_the_count(): void {
		$key = $this->cache_count( 0 );
		Agend_Test_WP::queue_response( 200, $this->item( array( 'status' => 'closed', 'filled' => true ) ) );

		$item = Agend_Apps_Jobs_Submissions::close( self::USER, self::JOB_ID, true );

		$this->assertSame(
			array(
				'contact' => self::CONTACT,
				'filled'  => true,
			),
			$this->body()
		);
		$this->assertSame( 'filled', $item['status'] );
		$this->assertArrayHasKey( $key, Agend_Test_WP::$transients );
	}

	#[Test]
	public function the_list_reads_items_and_pagination_and_hides_private_keys(): void {
		Agend_Test_WP::queue_response(
			200,
			array(
				'success' => true,
				'data'    => array( $this->item( array( 'status' => 'approved', 'expired' => false ) )['data'] ),
				'meta'    => array( 'pagination' => array( 'page' => 1, 'has_next' => false ) ),
			)
		);

		$mine = Agend_Apps_Jobs_Submissions::mine( self::USER );

		$this->assertStringContainsString( 'external_source=upbeat-contacts', Agend_Test_WP::$requests[0]['url'] );
		$this->assertSame( 'published', $mine['items'][0]['status'] );
		$this->assertArrayNotHasKey( 'credit_ref', $mine['items'][0] );
		$this->assertFalse( $mine['pagination']['has_next'] );
	}

	#[Test]
	public function the_credit_summary_is_agends_count(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'available' => 2, 'checked_at' => '2026-11-17T00:00:00Z', 'stale' => false ) ) );
		$this->assertSame( array( 'available' => 2 ), Agend_Apps_Jobs_Submissions::credits( self::USER ) );

		delete_transient( Agend_Apps_Jobs_Credits::CACHE_PREFIX . md5( 'upbeat-contacts|M1001' ) );
		Agend_Test_WP::queue_response( 503, $this->gateway_error( 'CREDIT_COUNT_UNAVAILABLE' ) );
		$this->assertSame( 'credits_unavailable', Agend_Apps_Jobs_Submissions::credits( self::USER )->get_error_code() );

		delete_transient( Agend_Apps_Jobs_Credits::CACHE_PREFIX . md5( 'upbeat-contacts|M1001' ) );
		Agend_Test_WP::queue_response( 403, $this->gateway_error( 'JOB_CREDITS_NOT_CONFIGURED' ) );
		$this->assertSame( 'unavailable', Agend_Apps_Jobs_Submissions::credits( self::USER )->get_error_code() );
	}

	// --------------------------------------------------------------- routes --

	private function post_request( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/jobs/me' );
		$request->set_body( (string) wp_json_encode( $body ) );
		$request->set_header( 'content-type', 'application/json' );

		return $request;
	}

	#[Test]
	public function the_route_refuses_bad_fields_before_anything_is_sent(): void {
		$response = ( new Agend_Apps_Jobs_Me_REST_Controller() )->submit( $this->post_request( array( 'title' => 'Accountant' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'validation_failed', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'employment_type', $response->get_data()['fields'] );
		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function the_route_answers_an_accepted_job_with_202_and_uses_the_request_id(): void {
		$this->accepted();

		$response = ( new Agend_Apps_Jobs_Me_REST_Controller() )->submit( $this->post_request( $this->job_fields() + array( 'request_id' => self::REQUEST_ID ) ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'checking_credit', $response->get_data()['data']['status'] );
		$this->assertSame( 'wp-7-' . self::REQUEST_ID, Agend_Test_WP::$requests[0]['headers']['Idempotency-Key'] );
	}

	#[Test]
	public function the_route_reads_one_job(): void {
		Agend_Test_WP::queue_response( 200, $this->item() );
		$request = new WP_REST_Request( 'GET', '/agend-apps/v1/jobs/me/' . self::JOB_ID );
		$request->set_param( 'id', strtoupper( self::JOB_ID ) );

		$response = ( new Agend_Apps_Jobs_Me_REST_Controller() )->get_one( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::JOB_ID, $response->get_data()['data']['id'] );
		$this->assertStringContainsString( '/jobs/submissions/' . self::JOB_ID . '?', Agend_Test_WP::$requests[0]['url'] );
	}

	#[Test]
	public function only_a_signed_in_user_reaches_the_routes(): void {
		wp_set_current_user( 0 );

		$this->assertInstanceOf( WP_Error::class, ( new Agend_Apps_Jobs_Me_REST_Controller() )->signed_in() );
		$this->assertSame( 'sign_in', Agend_Apps_Jobs_Submissions::submit( 0, $this->job_fields(), self::REQUEST_ID )->get_error_code() );
	}
}
