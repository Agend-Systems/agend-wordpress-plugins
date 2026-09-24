<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Listing_Transformer;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-path-resolver.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-field-map.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-listing-transformer.php';

/**
 * Flag key map feature, back-compat requirement: when NEITHER flag has a map
 * configured, `resolve_status()` must reproduce today's output byte-for-byte:
 * `approved` / `suspended`, never `pending`, including invert and
 * blank-source semantics. This is an explicit matrix over both-true,
 * one-false, blank-source and invert-on-each, kept separate from
 * FlagInvertTest / FlagTruthyCoercionTest so a regression here is unambiguous.
 */
#[CoversClass( Agend_Directory_Sync_Listing_Transformer::class )]
final class FlagKeyMapBackCompatTest extends TestCase {

	/**
	 * @param array<string, mixed> $contact
	 * @param array<string, string> $core
	 * @param array<string, bool>   $flags
	 */
	private function statusFor( array $contact, array $core, array $flags = array() ): string {
		$field_map = array(
			'core'  => $core,
			'flags' => $flags,
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( array( $contact ), $field_map );

		return (string) $result['listings'][0]['status'];
	}

	#[Test]
	public function both_flags_true_is_approved(): void {
		$status = $this->statusFor(
			array( 'id' => '1', 'eligible' => true, 'opted_in' => true ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'eligible',
				'opt_in_flag'   => 'opted_in',
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $status );
	}

	/**
	 * @return array<string, array{0: bool, 1: bool}>
	 */
	public static function oneFalseMatrix(): array {
		return array(
			'eligible false, opt-in true' => array( false, true ),
			'eligible true, opt-in false' => array( true, false ),
			'both false'                  => array( false, false ),
		);
	}

	#[Test]
	#[DataProvider( 'oneFalseMatrix' )]
	public function either_flag_false_is_suspended_never_pending( bool $eligible, bool $opted_in ): void {
		$status = $this->statusFor(
			array( 'id' => '1', 'eligible' => $eligible, 'opted_in' => $opted_in ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'eligible',
				'opt_in_flag'   => 'opted_in',
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN, $status );
		$this->assertNotSame( 'pending', $status );
	}

	#[Test]
	public function a_blank_source_on_either_flag_stays_approved(): void {
		$status = $this->statusFor(
			array( 'id' => '1', 'opted_in' => true ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => '',
				'opt_in_flag'   => 'opted_in',
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $status );
	}

	#[Test]
	public function invert_on_eligible_flag_with_a_truthy_source_is_suspended(): void {
		$status = $this->statusFor(
			array( 'id' => '1', 'excluded' => true ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'excluded',
			),
			array( 'eligible_flag_invert' => true )
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN, $status );
	}

	#[Test]
	public function invert_on_opt_in_flag_with_a_falsey_source_is_approved(): void {
		$status = $this->statusFor(
			array( 'id' => '1', 'opted_out' => false ),
			array(
				'external_id' => 'id',
				'opt_in_flag' => 'opted_out',
			),
			array( 'opt_in_flag_invert' => true )
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $status );
	}

	#[Test]
	public function no_flags_key_at_all_still_resolves_to_the_back_compat_statuses(): void {
		// A caller that never touched `flags` (e.g. a very old direct caller)
		// passes no such key at all, not even an empty array.
		$field_map = array(
			'core' => array(
				'external_id'   => 'id',
				'eligible_flag' => 'eligible',
			),
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all(
			array( array( 'id' => '1', 'eligible' => false ) ),
			$field_map
		);

		$this->assertSame(
			Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN,
			(string) $result['listings'][0]['status']
		);
	}
}
