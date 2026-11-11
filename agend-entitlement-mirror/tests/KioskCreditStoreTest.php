<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Mirror_Kiosk_Credit_Store;
use Agend\Tests\TestCase;
use Iugo_Membership_Kiosk_API_Entitlement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-kiosk-credit-store.php';
require_once __DIR__ . '/fixtures/kiosk-inherit-flags.php';

/**
 * A kiosk account: only its membership number is read.
 */
final class FakeKioskAccount {
	public function __construct( private string $number ) {}

	public function get_membership_number(): string {
		return $this->number;
	}
}

/**
 * A kiosk member.
 */
final class FakeKioskMember {
	public function __construct( private ?FakeKioskAccount $account = null, private string $parent = '', private bool $exists = true ) {}

	public function get_account() {
		return $this->account;
	}

	public function get_ultimate_parent_account_id(): string {
		return $this->parent;
	}

	public function exists(): bool {
		return $this->exists;
	}
}

/**
 * The kiosk API surface the store uses.
 */
final class FakeKioskApi {

	/** @var mixed */
	public $member;

	/** @var array<string, array|false> id => records, or false for a failed request */
	public array $records = array();

	/** @var array<int, string> */
	public array $calls = array();

	/** @var mixed */
	public $update_result = null;

	public ?Iugo_Membership_Kiosk_API_Entitlement $sent = null;

	public static function get_entitlements_for_id_api_url( string $id ): string {
		return 'member/entitlements/id=' . $id;
	}

	public function get_member_details_by_id( $id ) {
		return $this->member;
	}

	public function get_objects( $method, $endpoint, $body, $arguments, $class ) {
		$this->calls[] = 'get:' . $endpoint;
		$id            = substr( $endpoint, strlen( 'member/entitlements/id=' ) );

		return $this->records[ $id ] ?? array();
	}

	public function update_entitlement( $entitlement, $member ) {
		$this->sent = $entitlement;

		return $this->update_result ?? $entitlement;
	}

	public function erase_entitlements_cache_for_member_id( string $id ): void {
		$this->calls[] = 'erase-member:' . $id;
	}

	public function erase_entitlements_cache_for_account_id( string $id ): void {
		$this->calls[] = 'erase-account:' . $id;
	}
}

/**
 * The kiosk credit store reads entitlement records fresh, reports any failed
 * read as an error rather than "no records", and writes downloadCount.
 */
#[CoversClass( Agend_Entitlement_Mirror_Kiosk_Credit_Store::class )]
final class KioskCreditStoreTest extends TestCase {

	private FakeKioskApi $api;

	private Agend_Entitlement_Mirror_Kiosk_Credit_Store $store;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['agend_test_inherit_account'] = false;
		$GLOBALS['agend_test_inherit_parent']  = false;
		$this->api                             = new FakeKioskApi();
		$this->api->member                     = new FakeKioskMember( new FakeKioskAccount( 'A1' ), 'P1' );
		$this->store                           = new Agend_Entitlement_Mirror_Kiosk_Credit_Store( $this->api );
	}

	private function record( string $ref, string $type, ?string $download_count, bool $current = true ): Iugo_Membership_Kiosk_API_Entitlement {
		return ( new Iugo_Membership_Kiosk_API_Entitlement( 'Products', $type ) )
			->set_unique_id( $ref )
			->set_download_count( $download_count )
			->set_current( $current );
	}

	#[Test]
	public function it_maps_current_records_and_reads_download_count_as_used(): void {
		$this->api->records['M1'] = array(
			$this->record( 'a', 'Job Credit', '0' ),
			$this->record( 'b', 'Job Credit', '1' ),
			$this->record( 'c', 'Job Credit', null ),
			$this->record( 'd', 'Job Credit', '0', false ),
		);

		$rows = $this->store->member_entitlements( 'M1' );

		$this->assertSame( array( 'a', 'b', 'c' ), array_column( $rows, 'ref' ) );
		$this->assertSame( array( false, true, false ), array_column( $rows, 'used' ) );
		$this->assertSame( 'Job Credit', $rows[0]['type'] );
	}

	#[Test]
	public function a_failed_entitlement_request_is_an_error_not_an_empty_list(): void {
		$this->api->records['M1'] = false;

		$this->assertSame( 'agend_jobs_credit_lookup_failed', $this->store->member_entitlements( 'M1' )->get_error_code() );
	}

	#[Test]
	public function a_failed_member_lookup_is_an_error(): void {
		$this->api->member = false;

		$this->assertSame( 'agend_jobs_credit_lookup_failed', $this->store->member_entitlements( 'M1' )->get_error_code() );

		$this->api->member = new FakeKioskMember( null, '', false );

		$this->assertSame( 'agend_jobs_credit_lookup_failed', $this->store->member_entitlements( 'M1' )->get_error_code() );
	}

	#[Test]
	public function it_reads_the_account_and_ultimate_parent_records_when_the_kiosk_inherits_them(): void {
		$GLOBALS['agend_test_inherit_account'] = true;
		$GLOBALS['agend_test_inherit_parent']  = true;
		$this->api->records['A1']              = array( $this->record( 'firm', 'Job Credit', '0' ) );

		$rows = $this->store->member_entitlements( 'M1' );

		$this->assertSame( array( 'firm' ), array_column( $rows, 'ref' ) );
		$this->assertSame(
			array( 'get:member/entitlements/id=M1', 'get:member/entitlements/id=A1', 'get:member/entitlements/id=P1' ),
			$this->api->calls
		);
	}

	#[Test]
	public function a_failed_account_request_fails_the_whole_read(): void {
		$GLOBALS['agend_test_inherit_account'] = true;
		$this->api->records['M1']              = array( $this->record( 'a', 'Job Credit', '0' ) );
		$this->api->records['A1']              = false;

		$this->assertSame( 'agend_jobs_credit_lookup_failed', $this->store->member_entitlements( 'M1' )->get_error_code() );
	}

	#[Test]
	public function account_records_are_not_read_when_the_kiosk_does_not_inherit_them(): void {
		$this->store->member_entitlements( 'M1' );

		$this->assertSame( array( 'get:member/entitlements/id=M1' ), $this->api->calls );
	}

	#[Test]
	public function set_used_sends_only_the_unique_id_and_download_count_then_clears_caches(): void {
		$GLOBALS['agend_test_inherit_account'] = true;

		$this->assertTrue( $this->store->set_used( 'M1', 'a', true ) );
		$this->assertSame( 'a', $this->api->sent->get_unique_id() );
		$this->assertSame( '1', $this->api->sent->get_download_count() );
		$this->assertSame( '', $this->api->sent->get_entitlement_type() );
		$this->assertSame( array( 'erase-member:M1', 'erase-account:A1' ), $this->api->calls );

		$this->assertTrue( $this->store->set_used( 'M1', 'a', false ) );
		$this->assertSame( '0', $this->api->sent->get_download_count() );
	}

	#[Test]
	public function a_failed_write_is_an_error(): void {
		$this->api->update_result = false;

		$this->assertSame( 'agend_jobs_credit_write_failed', $this->store->set_used( 'M1', 'a', true )->get_error_code() );
	}
}
