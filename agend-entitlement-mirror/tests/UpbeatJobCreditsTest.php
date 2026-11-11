<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Mirror_Credit_Store;
use Agend_Entitlement_Mirror_Upbeat_Job_Credits;
use Agend\Tests\TestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/jobs-credits.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-mirror-settings.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-kiosk-credit-store.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-upbeat-job-credits.php';

/**
 * In-memory Upbeat entitlement records for one or more members.
 */
final class FakeCreditStore implements Agend_Entitlement_Mirror_Credit_Store {

	/** @var array<string, array<int, array>> */
	public array $rows = array();

	/** @var array<int, string> */
	public array $writes = array();

	public ?WP_Error $fail = null;

	public function is_available(): bool {
		return true;
	}

	public function member_entitlements( string $member_id ) {
		return $this->fail ?? ( $this->rows[ $member_id ] ?? array() );
	}

	public function set_used( string $member_id, string $ref, bool $used ) {
		$this->writes[] = "$member_id:$ref:" . ( $used ? '1' : '0' );

		foreach ( $this->rows[ $member_id ] as &$row ) {
			if ( $row['ref'] === $ref ) {
				$row['used'] = $used;
			}
		}

		return true;
	}

	public function add( string $member_id, string $ref, array $overrides = array() ): void {
		$this->rows[ $member_id ][] = array_merge(
			array(
				'ref'        => $ref,
				'category'   => 'Products',
				'type'       => 'Job Credit',
				'used'       => false,
				'starts_at'  => new DateTimeImmutable( '2026-11-01' ),
				'expires_at' => new DateTimeImmutable( '2027-11-01' ),
			),
			$overrides
		);
	}
}

/**
 * Upbeat entitlement records work as job credits: one record per credit,
 * downloadCount marking it used, soonest to expire spent first.
 */
#[CoversClass( Agend_Entitlement_Mirror_Upbeat_Job_Credits::class )]
final class UpbeatJobCreditsTest extends TestCase {

	private FakeCreditStore $store;

	private Agend_Entitlement_Mirror_Upbeat_Job_Credits $provider;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['agend_test_user_meta'] = array( 7 => array( 'imk_membership_number' => 'M100' ) );
		$this->store                     = new FakeCreditStore();
		$this->provider                  = new Agend_Entitlement_Mirror_Upbeat_Job_Credits( $this->store, 'Job Credit' );
	}

	#[Test]
	public function it_is_unavailable_until_a_credit_type_is_set(): void {
		$this->assertFalse( ( new Agend_Entitlement_Mirror_Upbeat_Job_Credits( $this->store, ' ' ) )->is_available() );
		$this->assertTrue( $this->provider->is_available() );
	}

	#[Test]
	public function only_unused_records_of_the_credit_type_count(): void {
		$this->store->add( 'M100', 'a' );
		$this->store->add( 'M100', 'b', array( 'used' => true ) );
		$this->store->add( 'M100', 'c', array( 'type' => 'Support Ticket' ) );
		$this->store->add( 'M100', 'd', array( 'type' => 'job credit' ) );

		$refs = array_column( $this->provider->get_available_credits( 7 ), 'ref' );

		$this->assertSame( array( 'a', 'd' ), $refs );
	}

	#[Test]
	public function the_category_narrows_the_type_when_set(): void {
		$provider = new Agend_Entitlement_Mirror_Upbeat_Job_Credits( $this->store, 'Job Credit', 'Careers' );
		$this->store->add( 'M100', 'a', array( 'category' => 'Careers' ) );
		$this->store->add( 'M100', 'b', array( 'category' => 'Products' ) );

		$this->assertSame( array( 'a' ), array_column( $provider->get_available_credits( 7 ), 'ref' ) );
	}

	#[Test]
	public function credits_are_ordered_soonest_to_expire_first(): void {
		$this->store->add( 'M100', 'never', array( 'expires_at' => null ) );
		$this->store->add( 'M100', 'later', array( 'expires_at' => new DateTimeImmutable( '2027-06-01' ) ) );
		$this->store->add( 'M100', 'soon', array( 'expires_at' => new DateTimeImmutable( '2026-12-01' ) ) );

		$credits = $this->provider->get_available_credits( 7 );

		$this->assertSame( array( 'soon', 'later', 'never' ), array_column( $credits, 'ref' ) );
		$this->assertSame( '2026-12-01T00:00:00+00:00', $credits[0]['expires_at'] );
		$this->assertNull( $credits[2]['expires_at'] );
	}

	#[Test]
	public function a_member_without_a_membership_number_has_no_credits(): void {
		$this->store->add( 'M100', 'a' );

		$this->assertSame( array(), $this->provider->get_available_credits( 8 ) );
	}

	#[Test]
	public function a_lookup_failure_is_passed_on(): void {
		$this->store->fail = new WP_Error( 'agend_jobs_credit_lookup_failed', 'down' );

		$this->assertSame( 'agend_jobs_credit_lookup_failed', $this->provider->get_available_credits( 7 )->get_error_code() );
	}

	#[Test]
	public function spend_marks_the_record_used(): void {
		$this->store->add( 'M100', 'a' );

		$this->assertTrue( $this->provider->spend( 7, 'a' ) );
		$this->assertSame( array( 'M100:a:1' ), $this->store->writes );
	}

	#[Test]
	public function spend_refuses_a_used_or_unknown_record(): void {
		$this->store->add( 'M100', 'a', array( 'used' => true ) );
		$this->store->add( 'M100', 'other', array( 'type' => 'Support Ticket' ) );

		$this->assertSame( 'agend_jobs_credit_not_available', $this->provider->spend( 7, 'a' )->get_error_code() );
		$this->assertSame( 'agend_jobs_credit_not_available', $this->provider->spend( 7, 'other' )->get_error_code() );
		$this->assertSame( 'agend_jobs_credit_not_available', $this->provider->spend( 7, 'missing' )->get_error_code() );
		$this->assertSame( array(), $this->store->writes );
	}

	#[Test]
	public function restore_marks_the_record_unused(): void {
		$this->store->add( 'M100', 'a', array( 'used' => true ) );

		$this->assertTrue( $this->provider->restore( 7, 'a' ) );
		$this->assertSame( array( 'M100:a:0' ), $this->store->writes );
	}

	#[Test]
	public function restoring_an_unused_record_writes_nothing(): void {
		$this->store->add( 'M100', 'a' );

		$this->assertTrue( $this->provider->restore( 7, 'a' ) );
		$this->assertSame( array(), $this->store->writes );
	}

	#[Test]
	public function restoring_an_expired_credit_reports_it(): void {
		$this->assertSame( 'agend_jobs_credit_expired', $this->provider->restore( 7, 'gone' )->get_error_code() );
	}

	#[Test]
	public function the_provider_is_only_supplied_when_job_credits_are_switched_on(): void {
		require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/agend-entitlement-mirror.php';

		$this->assertNull( agend_entitlement_mirror_job_credit_provider( null ) );

		update_option( 'agend_entitlement_mirror_job_credits_enabled', '1' );
		update_option( 'agend_entitlement_mirror_job_credits_type', 'Job Credit' );

		$this->assertInstanceOf( Agend_Entitlement_Mirror_Upbeat_Job_Credits::class, agend_entitlement_mirror_job_credit_provider( null ) );
		$this->assertSame( $this->provider, agend_entitlement_mirror_job_credit_provider( $this->provider ), 'an earlier provider wins' );
	}
}
