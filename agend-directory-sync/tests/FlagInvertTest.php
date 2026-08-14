<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Listing_Transformer;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-path-resolver.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-field-map.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-listing-transformer.php';

/**
 * Per-flag invert setting (SPEC-DIR-20260731 US-3.3): a "hide when true"
 * data source (e.g. an "opted out" flag) inverts the coerced value so the
 * status decision still reads as visible/hidden correctly.
 */
#[CoversClass( Agend_Directory_Sync_Listing_Transformer::class )]
final class FlagInvertTest extends TestCase {

	private function statusFor( array $contact, array $core, array $flags ): string {
		$field_map = array(
			'core'  => $core,
			'flags' => $flags,
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( array( $contact ), $field_map );

		return (string) $result['listings'][0]['status'];
	}

	#[Test]
	public function without_invert_a_truthy_source_is_visible(): void {
		$status = $this->statusFor(
			array(
				'id'       => '1',
				'excluded' => true,
			),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'excluded',
			),
			array( 'eligible_flag_invert' => false )
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $status );
	}

	#[Test]
	public function with_invert_a_truthy_source_is_hidden(): void {
		$status = $this->statusFor(
			array(
				'id'       => '1',
				'excluded' => true,
			),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'excluded',
			),
			array( 'eligible_flag_invert' => true )
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN, $status );
	}

	#[Test]
	public function with_invert_a_falsey_source_is_visible(): void {
		$status = $this->statusFor(
			array(
				'id'       => '1',
				'excluded' => false,
			),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'excluded',
			),
			array( 'eligible_flag_invert' => true )
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $status );
	}

	#[Test]
	public function opt_in_flag_invert_is_independent_of_eligible_flag_invert(): void {
		$status = $this->statusFor(
			array(
				'id'        => '1',
				'opted_out' => true,
			),
			array(
				'external_id' => 'id',
				'opt_in_flag' => 'opted_out',
			),
			array( 'opt_in_flag_invert' => true )
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN, $status );
	}

	#[Test]
	public function a_blank_source_stays_visible_regardless_of_invert(): void {
		$core = array(
			'external_id'   => 'id',
			'eligible_flag' => '',
		);

		$visible_no_invert = $this->statusFor(
			array( 'id' => '1' ),
			$core,
			array( 'eligible_flag_invert' => false )
		);
		$visible_with_invert = $this->statusFor(
			array( 'id' => '1' ),
			$core,
			array( 'eligible_flag_invert' => true )
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $visible_no_invert );
		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $visible_with_invert );
	}

	#[Test]
	public function with_invert_a_missing_unresolved_flag_coerces_false_and_inverts_to_visible(): void {
		// The source field is configured but the contact row carries no such
		// key: resolve() returns null, truthy(null) is false, and inverting
		// false yields true (visible) — a missing flag never hides a row
		// when the gate is hide-when-true.
		$status = $this->statusFor(
			array( 'id' => '1' ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'excluded',
			),
			array( 'eligible_flag_invert' => true )
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $status );
	}
}
