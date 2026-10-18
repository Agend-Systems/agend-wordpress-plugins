<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Contact_Resolver;
use Agend_Loop_Sync_User_Sync;
use Loop_Sync_Test_Activity;
use Loop_Sync_Test_Committee;
use Loop_Sync_Test_State;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/LoopSyncTestCase.php';

/**
 * What the plugin sends for each member: a usable external_id, the highest
 * role when a member holds several, and no field the gateway would reject.
 */
final class RosterCorrectnessTest extends LoopSyncTestCase {

	/**
	 * @return array<string, array{0:string,1:int|null}>
	 */
	public static function external_ids(): array {
		return array(
			'plain digits'     => array( '10234', 10234 ),
			'padded'           => array( ' 77 ', 77 ),
			'alphanumeric'     => array( 'ABC123', null ),
			'zero'             => array( '0', null ),
			'negative'         => array( '-5', null ),
			'decimal'          => array( '12.5', null ),
			'empty'            => array( '', null ),
			'too long for int' => array( '99999999999999999999', null ),
		);
	}

	#[Test]
	#[DataProvider( 'external_ids' )]
	public function to_external_id_only_accepts_positive_integers( string $input, ?int $expected ): void {
		$this->assertSame( $expected, Agend_Loop_Sync_Contact_Resolver::to_external_id( $input ) );
	}

	#[Test]
	public function user_sync_skips_a_non_numeric_membership_number(): void {
		$user = loop_sync_test_user( 1, 'ABC123' );

		$this->assertFalse( ( new Agend_Loop_Sync_User_Sync() )->sync_user( $user ) );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_user' ) );
	}

	#[Test]
	public function user_sync_skips_an_invalid_email(): void {
		$user = loop_sync_test_user( 1, '555', 'not-an-email' );

		$this->assertFalse( ( new Agend_Loop_Sync_User_Sync() )->sync_user( $user ) );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_user' ) );
	}

	#[Test]
	public function user_sync_sends_an_integer_external_id(): void {
		$user = loop_sync_test_user( 1, '555' );

		$this->assertTrue( ( new Agend_Loop_Sync_User_Sync() )->sync_user( $user ) );

		$payload = Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_user' )[0][0];
		$this->assertSame( 555, $payload['external_id'] );
	}

	#[Test]
	public function backfill_drops_unusable_members_instead_of_sending_zero(): void {
		loop_sync_test_user( 1, '100' );
		loop_sync_test_user( 2, 'ABC' );
		loop_sync_test_user( 3, '300', 'bad-email' );

		$summary = Agend_Loop_Sync_User_Sync::backfill_all();

		$this->assertSame( 2, $summary['skipped_invalid'] );
		$sent = Loop_Sync_Test_State::calls_to( 'agend_apps_loop_bulk_sync_users' )[0][0];
		$this->assertSame( array( 100 ), array_column( $sent, 'external_id' ) );
	}

	#[Test]
	public function committee_roster_skips_a_member_with_a_non_numeric_number(): void {
		loop_sync_test_user( 1, '100' );
		loop_sync_test_user( 2, 'ABC' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board', 'uid-board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Member' ) );
		Loop_Sync_Test_State::$upbeat_activities['ABC'] = array( new Loop_Sync_Test_Activity( 'Board', 'Member' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( 1, $summary['skipped_invalid_members'] );
		$roster = Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' )[0][0]['roster'];
		$this->assertSame( array( 100 ), array_column( $roster, 'external_id' ) );
	}

	#[Test]
	public function committee_roster_omits_an_invalid_email_rather_than_failing_the_committee(): void {
		loop_sync_test_user( 1, '100', 'bad-email' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board', 'uid-board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Member' ) );

		$this->committee_sync()->run();

		$roster = Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' )[0][0]['roster'];
		$this->assertArrayNotHasKey( 'email', $roster[0] );
		$this->assertSame( 100, $roster[0]['external_id'] );
	}

	/**
	 * @return array<string, array{0:string[],1:string,2:string}>
	 */
	public static function role_orders(): array {
		return array(
			'owner then member'   => array( array( 'Chair', 'Member' ), 'owner', 'Chair' ),
			'member then owner'   => array( array( 'Member', 'Chair' ), 'owner', 'Chair' ),
			'moderator vs member' => array( array( 'Member', 'Secretary' ), 'moderator', 'Secretary' ),
			'owner vs moderator'  => array( array( 'Secretary', 'Chair', 'Member' ), 'owner', 'Chair' ),
		);
	}

	/**
	 * @param string[] $roles Upbeat roles in row order.
	 */
	#[Test]
	#[DataProvider( 'role_orders' )]
	public function a_member_with_several_roles_keeps_the_highest( array $roles, string $channel_role, string $label ): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board', 'uid-board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array_map(
			static fn( $role ) => new Loop_Sync_Test_Activity( 'Board', $role ),
			$roles
		);

		$this->committee_sync()->run();

		$roster = Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' )[0][0]['roster'];
		$this->assertCount( 1, $roster );
		$this->assertSame( $channel_role, $roster[0]['channel_role'] );
		$this->assertSame( $label, $roster[0]['committee_role_label'] );
	}
}
