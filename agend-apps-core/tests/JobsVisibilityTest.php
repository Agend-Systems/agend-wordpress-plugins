<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Jobs_REST_Controller;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/jobs.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/jobs-routes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/jobs-visibility.php';
require_once __DIR__ . '/fixtures/real-cache.php';

/**
 * The jobs board visibility setting is enforced on the data, not just the
 * page: the REST proxy refuses or trims what a visitor may not see, so a
 * members-only board never sends a job to a signed-out browser.
 */
final class JobsVisibilityTest extends TestCase {

	private const JOB = array(
		'id'                       => '11111111-1111-1111-1111-111111111111',
		'title'                    => 'SMSF Auditor',
		'slug'                     => 'smsf-auditor',
		'company_name'             => 'Example Audit',
		'location_city'            => 'Melbourne',
		'location_state'           => 'VIC',
		'employment_type'          => 'full-time',
		'experience_level'         => 'mid',
		'short_description'        => 'Sign off audits.',
		'description'              => '<p>The full ad.</p>',
		'salary_min'               => 120000,
		'salary_max'               => 140000,
		'application_url'          => 'https://example.com/apply',
		'application_email'        => 'careers@example.com',
		'application_instructions' => 'Send a CV.',
		'company_description'      => 'About us.',
		'categories'               => array(),
		'tags'                     => array(),
	);

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		agend_test_load_real_cache();
	}

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Endpoint_Scopes::set_map( null );
		unset( $GLOBALS['agend_test_current_user_id'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_current_user_id'] );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	private function visibility( string $value ): void {
		update_option( AGEND_APPS_RECORDS_JOBS_VISIBILITY_OPTION, $value );
	}

	private function sign_in(): void {
		$GLOBALS['agend_test_current_user_id'] = 7;
	}

	/**
	 * @return array{0: int, 1: array}
	 */
	private function list_jobs(): array {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( self::JOB ) ) );
		$response = ( new Agend_Apps_Jobs_REST_Controller() )->get_jobs( new WP_REST_Request( 'GET', '/agend-apps/v1/jobs' ) );

		return array( $response->get_status(), (array) $response->get_data() );
	}

	/**
	 * @return array{0: int, 1: array}
	 */
	private function get_job(): array {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => self::JOB ) );
		$request = new WP_REST_Request( 'GET', '/agend-apps/v1/jobs/smsf-auditor' );
		$request->set_param( 'slug_or_id', 'smsf-auditor' );
		$response = ( new Agend_Apps_Jobs_REST_Controller() )->get_job( $request );

		return array( $response->get_status(), (array) $response->get_data() );
	}

	#[Test]
	public function should_show_everything_to_everyone_by_default(): void {
		[ $list_status, $list ] = $this->list_jobs();
		[ $job_status, $job ]   = $this->get_job();

		self::assertSame( 200, $list_status );
		self::assertSame( 'Sign off audits.', $list['data'][0]['short_description'] );
		self::assertSame( 200, $job_status );
		self::assertSame( '<p>The full ad.</p>', $job['data']['description'] );
	}

	#[Test]
	public function should_refuse_the_list_and_the_detail_to_a_signed_out_visitor_when_members_only(): void {
		$this->visibility( 'members' );

		[ $list_status, $list ] = $this->list_jobs();
		[ $job_status, $job ]   = $this->get_job();

		self::assertSame( 401, $list_status );
		self::assertSame( 'agend_apps_sign_in_required', $list['code'] );
		self::assertArrayNotHasKey( 'data', $list );
		self::assertSame( 401, $job_status );
		self::assertArrayNotHasKey( 'data', $job );
		// Refused before the gateway is asked.
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_give_a_signed_out_visitor_only_card_fields_when_titles_are_public(): void {
		$this->visibility( 'teaser' );

		[ $status, $body ] = $this->list_jobs();
		$job               = $body['data'][0];

		self::assertSame( 200, $status );
		self::assertSame( 'SMSF Auditor', $job['title'] );
		self::assertSame( 'Example Audit', $job['company_name'] );
		self::assertSame( 'mid', $job['experience_level'] );
		foreach ( array( 'short_description', 'description', 'salary_min', 'salary_max', 'application_url', 'application_email', 'application_instructions', 'company_description' ) as $hidden ) {
			self::assertArrayNotHasKey( $hidden, $job, $hidden );
		}
	}

	#[Test]
	public function should_refuse_a_job_detail_to_a_signed_out_visitor_when_titles_are_public(): void {
		$this->visibility( 'teaser' );

		[ $status ] = $this->get_job();

		self::assertSame( 401, $status );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function restricted_visibilities(): array {
		return array(
			'titles public' => array( 'teaser' ),
			'members only'  => array( 'members' ),
		);
	}

	#[Test]
	#[DataProvider( 'restricted_visibilities' )]
	public function should_show_a_signed_in_visitor_everything( string $visibility ): void {
		$this->visibility( $visibility );
		$this->sign_in();

		[ $list_status, $list ] = $this->list_jobs();
		[ $job_status, $job ]   = $this->get_job();

		self::assertSame( 200, $list_status );
		self::assertSame( 'Sign off audits.', $list['data'][0]['short_description'] );
		self::assertSame( 200, $job_status );
		self::assertSame( 'careers@example.com', $job['data']['application_email'] );
	}

	#[Test]
	public function should_let_a_site_narrow_who_counts_as_a_member(): void {
		$this->visibility( 'members' );
		$this->sign_in();
		Agend_Test_WP::set_filter( 'agend_apps_records_jobs_visitor_can_view', false );

		[ $status ] = $this->list_jobs();

		self::assertSame( 401, $status );
	}

	#[Test]
	public function should_treat_an_unknown_stored_value_as_public(): void {
		$this->visibility( 'everyone-but-bob' );

		self::assertSame( 'public', agend_apps_records_jobs_visibility() );
		self::assertTrue( agend_apps_records_jobs_visitor_can_view( 'detail' ) );
	}
}
