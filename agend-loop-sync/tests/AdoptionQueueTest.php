<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Settings;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/LoopSyncTestCase.php';

/**
 * The hand-over queue holds the membership numbers an administrator chose to
 * hand to the committee sync, per Upbeat committee unique id. Whatever is stored,
 * what the sync reads back must be distinct positive integers the gateway will
 * accept.
 */
final class AdoptionQueueTest extends LoopSyncTestCase {

	#[Test]
	public function an_unset_queue_is_empty(): void {
		$this->assertSame( array(), Agend_Loop_Sync_Settings::get_adopt_queue() );
		$this->assertSame( array(), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
	}

	#[Test]
	public function queuing_stores_the_numbers_per_committee(): void {
		$added = Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100, 200 ) );
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-audit', array( 300 ) );

		$this->assertSame( 2, $added );
		$this->assertSame( array( 100, 200 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
		$this->assertSame( array( 300 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-audit' ) );
		$this->assertSame( array(), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-other' ) );
	}

	#[Test]
	public function queuing_merges_into_what_is_already_queued_without_repeats(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100, 200 ) );

		$added = Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 200, 300, 300 ) );

		$this->assertSame( 1, $added );
		$this->assertSame( array( 100, 200, 300 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
	}

	#[Test]
	public function queuing_accepts_digit_strings_and_drops_anything_the_gateway_would_refuse(): void {
		$added = Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( '100', 0, -5, 'ABC123', '12.5', '', null, 1.5, array( 7 ), '0', 200 ) );

		$this->assertSame( 2, $added );
		$this->assertSame( array( 100, 200 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
	}

	#[Test]
	public function queuing_under_an_empty_unique_id_is_ignored(): void {
		$this->assertSame( 0, Agend_Loop_Sync_Settings::queue_adoptions( '  ', array( 100 ) ) );
		$this->assertSame( array(), Agend_Loop_Sync_Settings::get_adopt_queue() );
	}

	#[Test]
	public function a_queue_is_capped_at_what_the_gateway_accepts(): void {
		$added = Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', range( 1, 2100 ) );

		$this->assertSame( 2000, $added );
		$this->assertCount( 2000, Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );

		$this->assertSame( 0, Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 5000 ) ) );
		$this->assertNotContains( 5000, Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
	}

	#[Test]
	public function dequeuing_removes_only_the_listed_numbers_from_that_committee(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100, 200, 300 ) );
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-audit', array( 100 ) );

		Agend_Loop_Sync_Settings::dequeue_adoptions( 'uid-board', array( 100, 300, 999 ) );

		$this->assertSame( array( 200 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
		$this->assertSame( array( 100 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-audit' ) );
	}

	#[Test]
	public function a_committee_whose_queue_empties_leaves_the_option(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );

		Agend_Loop_Sync_Settings::dequeue_adoptions( 'uid-board', array( 100 ) );

		$this->assertSame( array(), get_option( Agend_Loop_Sync_Settings::OPT_ADOPT_QUEUE ) );
	}

	#[Test]
	public function clearing_empties_one_committee_and_leaves_the_others(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100, 200 ) );
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-audit', array( 300 ) );

		Agend_Loop_Sync_Settings::clear_adopt_queue( 'uid-board' );

		$this->assertSame( array( 'uid-audit' => array( 300 ) ), Agend_Loop_Sync_Settings::get_adopt_queue() );
	}

	#[Test]
	public function a_numeric_unique_id_survives_php_turning_it_into_an_int_key(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( '4821', array( 100 ) );

		$this->assertSame( array( 100 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( '4821' ) );
		$this->assertSame( array( '4821' ), array_map( 'strval', array_keys( Agend_Loop_Sync_Settings::get_adopt_queue() ) ) );
	}

	#[Test]
	public function a_damaged_option_reads_as_a_clean_queue(): void {
		update_option(
			Agend_Loop_Sync_Settings::OPT_ADOPT_QUEUE,
			array(
				'uid-board' => array( '100', 100, 'x', -1 ),
				'uid-empty' => array(),
				'uid-bad'   => 'not a list',
				''          => array( 5 ),
			)
		);

		$this->assertSame( array( 'uid-board' => array( 100 ) ), Agend_Loop_Sync_Settings::get_adopt_queue() );

		update_option( Agend_Loop_Sync_Settings::OPT_ADOPT_QUEUE, 'garbage' );

		$this->assertSame( array(), Agend_Loop_Sync_Settings::get_adopt_queue() );
	}
}
