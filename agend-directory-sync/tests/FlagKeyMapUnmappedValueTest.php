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
 * Flag key map feature: a value matching no map entry (including a missing
 * or empty resolved value) takes the map's default outcome, and is counted
 * in `transform_all()`'s `unmapped_flag_values` report, capped at
 * MAX_UNMAPPED_FLAG_VALUES distinct values per flag.
 */
#[CoversClass( Agend_Directory_Sync_Listing_Transformer::class )]
final class FlagKeyMapUnmappedValueTest extends TestCase {

	private function fieldMap( array $flag_overrides ): array {
		return array(
			'core'  => array(
				'external_id'   => 'id',
				'eligible_flag' => 'membership_status',
			),
			'flags' => array_merge(
				array(
					'eligible_flag_mode' => 'map',
					'eligible_flag_map'  => array(
						array( 'value' => 'Active', 'outcome' => 'published' ),
					),
				),
				$flag_overrides
			),
		);
	}

	#[Test]
	public function an_unmapped_value_takes_the_default_outcome(): void {
		$result = Agend_Directory_Sync_Listing_Transformer::transform_all(
			array( array( 'id' => '1', 'membership_status' => 'Lapsed' ) ),
			$this->fieldMap( array( 'eligible_flag_map_default' => 'draft' ) )
		);

		$this->assertSame(
			Agend_Directory_Sync_Listing_Transformer::STATUS_PENDING,
			(string) $result['listings'][0]['status']
		);
	}

	#[Test]
	public function published_as_the_default_works(): void {
		$result = Agend_Directory_Sync_Listing_Transformer::transform_all(
			array( array( 'id' => '1', 'membership_status' => 'Lapsed' ) ),
			$this->fieldMap( array( 'eligible_flag_map_default' => 'published' ) )
		);

		$this->assertSame(
			Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE,
			(string) $result['listings'][0]['status']
		);
	}

	#[Test]
	public function default_is_draft_when_not_configured(): void {
		$result = Agend_Directory_Sync_Listing_Transformer::transform_all(
			array( array( 'id' => '1', 'membership_status' => 'Lapsed' ) ),
			$this->fieldMap( array() )
		);

		$this->assertSame(
			Agend_Directory_Sync_Listing_Transformer::STATUS_PENDING,
			(string) $result['listings'][0]['status']
		);
	}

	#[Test]
	public function unmapped_values_are_counted_per_flag(): void {
		$result = Agend_Directory_Sync_Listing_Transformer::transform_all(
			array(
				array( 'id' => '1', 'membership_status' => 'Lapsed' ),
				array( 'id' => '2', 'membership_status' => 'Lapsed' ),
				array( 'id' => '3', 'membership_status' => 'Unknown' ),
				array( 'id' => '4', 'membership_status' => 'Active' ),
			),
			$this->fieldMap( array() )
		);

		$this->assertSame(
			array( 'eligible_flag' => array( 'Lapsed' => 2, 'Unknown' => 1 ) ),
			$result['unmapped_flag_values']
		);
	}

	#[Test]
	public function a_missing_source_value_is_counted_as_an_empty_string(): void {
		$result = Agend_Directory_Sync_Listing_Transformer::transform_all(
			array( array( 'id' => '1' ) ),
			$this->fieldMap( array() )
		);

		$this->assertSame(
			array( 'eligible_flag' => array( '' => 1 ) ),
			$result['unmapped_flag_values']
		);
	}

	#[Test]
	public function unmapped_tracking_is_capped_at_max_distinct_values_per_flag(): void {
		$max      = Agend_Directory_Sync_Listing_Transformer::MAX_UNMAPPED_FLAG_VALUES;
		$contacts = array();
		for ( $i = 0; $i < $max + 5; $i++ ) {
			$contacts[] = array( 'id' => (string) $i, 'membership_status' => 'Unmapped-' . $i );
		}

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts, $this->fieldMap( array() ) );

		$this->assertCount( $max, $result['unmapped_flag_values']['eligible_flag'] );
	}

	#[Test]
	public function a_value_already_tracked_keeps_incrementing_past_the_cap(): void {
		$max      = Agend_Directory_Sync_Listing_Transformer::MAX_UNMAPPED_FLAG_VALUES;
		$contacts = array();
		for ( $i = 0; $i < $max; $i++ ) {
			$contacts[] = array( 'id' => 'a' . $i, 'membership_status' => 'Unmapped-' . $i );
		}
		// Push past the cap with brand-new values, and repeat one already
		// tracked value several more times.
		for ( $i = 0; $i < 5; $i++ ) {
			$contacts[] = array( 'id' => 'b' . $i, 'membership_status' => 'Brand-new-' . $i );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$contacts[] = array( 'id' => 'c' . $i, 'membership_status' => 'Unmapped-0' );
		}

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts, $this->fieldMap( array() ) );

		$this->assertCount( $max, $result['unmapped_flag_values']['eligible_flag'] );
		$this->assertSame( 1 + 3, $result['unmapped_flag_values']['eligible_flag']['Unmapped-0'] );
	}
}
