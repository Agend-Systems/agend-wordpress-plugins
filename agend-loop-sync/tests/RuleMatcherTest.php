<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Rule_Matcher;
use Agend_Loop_Sync_Settings;
use Agend_Test_WP;
use Loop_Sync_Test_Member;
use Loop_Sync_Test_Registration;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/RuleTestCase.php';

/**
 * Whether one member belongs in one rule's channel.
 */
final class RuleMatcherTest extends RuleTestCase {

	/**
	 * @param array<string, mixed> $fields Raw rule fields.
	 * @return array<string, mixed>
	 */
	private function rule( array $fields = array() ): array {
		return Agend_Loop_Sync_Settings::sanitize_rule( array_merge( array( 'slug' => 'r', 'name' => 'R', 'enabled' => true ), $fields ) );
	}

	private function member_matches( array $rule, Loop_Sync_Test_Member $member, array $registrations = array() ): bool {
		return Agend_Loop_Sync_Rule_Matcher::matches( $rule, $member, $registrations, 1 );
	}

	#[Test]
	public function a_rule_with_no_criteria_matches_every_financial_member(): void {
		$this->assertTrue( $this->member_matches( $this->rule(), new Loop_Sync_Test_Member( 'Financial' ) ) );
	}

	#[Test]
	public function the_membership_type_is_matched_ignoring_case(): void {
		$rule = $this->rule( array( 'membership_types' => "fellow\nAffiliate" ) );

		$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', 'Fellow' ) ) );
		$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', 'AFFILIATE' ) ) );
		$this->assertFalse( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', 'Specialist' ) ) );
		$this->assertFalse( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', '' ) ), 'a member with no type cannot match a type' );
	}

	#[Test]
	public function the_membership_level_is_matched_ignoring_case(): void {
		$rule = $this->rule( array( 'membership_levels' => array( 'Gold' ) ) );

		$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', 'Fellow', 'gold' ) ) );
		$this->assertFalse( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', 'Fellow', 'Silver' ) ) );
	}

	#[Test]
	public function a_registration_for_a_listed_event_matches(): void {
		$rule = $this->rule( array( 'event_ids' => "EV100\nEV200" ) );

		$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member(), array( new Loop_Sync_Test_Registration( 'ev200', 'Paid', 'Registered' ) ) ) );
		$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member(), array( new Loop_Sync_Test_Registration( 'EV100' ) ) ), 'no status recorded still counts' );
		$this->assertFalse( $this->member_matches( $rule, new Loop_Sync_Test_Member(), array( new Loop_Sync_Test_Registration( 'EV999' ) ) ) );
		$this->assertFalse( $this->member_matches( $rule, new Loop_Sync_Test_Member(), array() ) );
	}

	#[Test]
	public function a_cancelled_voided_or_refunded_registration_does_not_count(): void {
		$rule = $this->rule( array( 'event_ids' => 'EV100' ) );

		foreach ( array( array( 'Cancelled', null ), array( 'Paid', 'Cancelled by member' ), array( 'Voided', null ), array( 'Refunded', null ) ) as $statuses ) {
			$registration = new Loop_Sync_Test_Registration( 'EV100', $statuses[0], $statuses[1] );
			$this->assertFalse( $this->member_matches( $rule, new Loop_Sync_Test_Member(), array( $registration ) ), implode( '/', array_filter( $statuses ) ) );
		}

		$counting = new Loop_Sync_Test_Registration( 'EV100', 'Paid' );
		$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member(), array( new Loop_Sync_Test_Registration( 'EV100', 'Cancelled' ), $counting ) ), 'one live registration is enough' );
	}

	#[Test]
	public function the_registration_filter_can_change_what_counts(): void {
		$rule = $this->rule( array( 'event_ids' => 'EV100' ) );
		Agend_Test_WP::set_filter( 'agend_loop_sync_registration_counts', true );

		$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member(), array( new Loop_Sync_Test_Registration( 'EV100', 'Cancelled' ) ) ) );
	}

	#[Test]
	public function all_criteria_must_pass_by_default(): void {
		$rule = $this->rule( array( 'membership_types' => 'Fellow', 'event_ids' => 'EV100' ) );
		$reg  = array( new Loop_Sync_Test_Registration( 'EV100' ) );

		$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', 'Fellow' ), $reg ) );
		$this->assertFalse( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', 'Fellow' ), array() ) );
		$this->assertFalse( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', 'Affiliate' ), $reg ) );
	}

	#[Test]
	public function any_mode_needs_one_criterion_to_pass(): void {
		$rule = $this->rule( array( 'match' => 'any', 'membership_types' => 'Fellow', 'event_ids' => 'EV100' ) );
		$reg  = array( new Loop_Sync_Test_Registration( 'EV100' ) );

		$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', 'Affiliate' ), $reg ) );
		$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', 'Fellow' ), array() ) );
		$this->assertFalse( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', 'Affiliate' ), array() ) );
	}

	#[Test]
	public function the_lapse_rule_keeps_financial_renewal_due_and_overdue_by_default(): void {
		$rule = $this->rule();

		foreach ( array( 'Financial', 'Renewal Due', 'Overdue', 'overdue' ) as $status ) {
			$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member( $status ) ), $status );
		}

		foreach ( array( 'Reinstatable', 'Lapsed', 'Cancelled', 'Pending', '' ) as $status ) {
			$this->assertFalse( $this->member_matches( $rule, new Loop_Sync_Test_Member( $status ) ), '"' . $status . '" must lose access' );
		}
	}

	#[Test]
	public function the_lapse_rule_can_cut_access_earlier(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_ACCESS_STATUSES, array( 'Financial', 'Renewal Due' ) );

		$this->assertTrue( $this->member_matches( $this->rule(), new Loop_Sync_Test_Member( 'Renewal Due' ) ) );
		$this->assertFalse( $this->member_matches( $this->rule(), new Loop_Sync_Test_Member( 'Overdue' ) ) );
	}

	#[Test]
	public function a_financial_member_keeps_access_whatever_is_saved(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_ACCESS_STATUSES, array( 'Overdue' ) );

		$this->assertTrue( $this->member_matches( $this->rule(), new Loop_Sync_Test_Member( 'Financial' ) ) );
	}

	#[Test]
	public function a_rule_that_does_not_apply_the_lapse_rule_ignores_status(): void {
		$rule = $this->rule( array( 'apply_access_statuses' => '0' ) );

		$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Lapsed' ) ) );
		$this->assertTrue( $this->member_matches( $rule, new Loop_Sync_Test_Member( '' ) ) );
	}

	#[Test]
	public function the_lapse_rule_applies_on_top_of_the_criteria(): void {
		$rule = $this->rule( array( 'membership_types' => 'Fellow' ) );

		$this->assertFalse( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Lapsed', 'Fellow' ) ) );
		$this->assertFalse( $this->member_matches( $rule, new Loop_Sync_Test_Member( 'Financial', 'Affiliate' ) ) );
	}

	#[Test]
	public function the_filter_can_add_a_criterion(): void {
		$rule = $this->rule( array( 'slug' => 'specialists' ) );
		add_filter(
			'agend_loop_sync_rule_matches',
			static function ( bool $matches, array $rule, $detail, int $user_id ): bool {
				return $matches && 'specialists' === $rule['slug'] && 7 === $user_id;
			},
			10,
			4
		);

		$this->assertTrue( Agend_Loop_Sync_Rule_Matcher::matches( $rule, new Loop_Sync_Test_Member( 'Financial' ), array(), 7 ) );
		$this->assertFalse( Agend_Loop_Sync_Rule_Matcher::matches( $rule, new Loop_Sync_Test_Member( 'Financial' ), array(), 8 ) );
	}

	#[Test]
	public function the_filter_can_override_the_result(): void {
		Agend_Test_WP::set_filter( 'agend_loop_sync_rule_matches', true );

		$this->assertTrue( $this->member_matches( $this->rule(), new Loop_Sync_Test_Member( 'Lapsed' ) ), 'an override can let a lapsed member in' );
	}
}
