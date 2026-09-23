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
 * Flag key map feature: a flag's value map turns the source field's value
 * into an outcome (published/draft) instead of a truthy read, and the most
 * restrictive outcome across the two flags wins. Covers mixed
 * mapped/truthy combinations, both-mapped precedence, case-insensitive and
 * label matching, list values, and the map-mode blank-source case.
 */
#[CoversClass( Agend_Directory_Sync_Listing_Transformer::class )]
final class FlagKeyMapModeTest extends TestCase {

	/**
	 * @param array<string, mixed>  $contact
	 * @param array<string, string> $core
	 * @param array<string, mixed>  $flags
	 */
	private function transform( array $contact, array $core, array $flags ): array {
		$field_map = array(
			'core'  => $core,
			'flags' => $flags,
		);

		return Agend_Directory_Sync_Listing_Transformer::transform_all( array( $contact ), $field_map );
	}

	private function statusFor( array $contact, array $core, array $flags ): string {
		$result = $this->transform( $contact, $core, $flags );
		return (string) $result['listings'][0]['status'];
	}

	#[Test]
	public function mapped_published_with_truthy_true_is_approved(): void {
		$status = $this->statusFor(
			array( 'id' => '1', 'membership_status' => 'Active', 'opted_in' => true ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'membership_status',
				'opt_in_flag'   => 'opted_in',
			),
			array(
				'eligible_flag_mode' => 'map',
				'eligible_flag_map'  => array(
					array( 'value' => 'Active', 'outcome' => 'published' ),
				),
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $status );
	}

	#[Test]
	public function mapped_published_with_truthy_false_is_pending(): void {
		$status = $this->statusFor(
			array( 'id' => '1', 'membership_status' => 'Active', 'opted_in' => false ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'membership_status',
				'opt_in_flag'   => 'opted_in',
			),
			array(
				'eligible_flag_mode' => 'map',
				'eligible_flag_map'  => array(
					array( 'value' => 'Active', 'outcome' => 'published' ),
				),
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_PENDING, $status );
	}

	#[Test]
	public function mapped_draft_is_pending_even_when_truthy_flag_is_true(): void {
		$status = $this->statusFor(
			array( 'id' => '1', 'membership_status' => 'Lapsed', 'opted_in' => true ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'membership_status',
				'opt_in_flag'   => 'opted_in',
			),
			array(
				'eligible_flag_mode' => 'map',
				'eligible_flag_map'  => array(
					array( 'value' => 'Active', 'outcome' => 'published' ),
					array( 'value' => 'Lapsed', 'outcome' => 'draft' ),
				),
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_PENDING, $status );
	}

	#[Test]
	public function both_flags_mapped_the_most_restrictive_outcome_wins(): void {
		$status = $this->statusFor(
			array( 'id' => '1', 'membership_status' => 'Active', 'consent_status' => 'Withdrawn' ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'membership_status',
				'opt_in_flag'   => 'consent_status',
			),
			array(
				'eligible_flag_mode' => 'map',
				'eligible_flag_map'  => array(
					array( 'value' => 'Active', 'outcome' => 'published' ),
				),
				'opt_in_flag_mode'   => 'map',
				'opt_in_flag_map'    => array(
					array( 'value' => 'Granted', 'outcome' => 'published' ),
					array( 'value' => 'Withdrawn', 'outcome' => 'draft' ),
				),
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_PENDING, $status );
	}

	#[Test]
	public function match_is_case_insensitive_and_trimmed(): void {
		$status = $this->statusFor(
			array( 'id' => '1', 'membership_status' => '  aCtIvE  ' ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'membership_status',
			),
			array(
				'eligible_flag_mode' => 'map',
				'eligible_flag_map'  => array(
					array( 'value' => 'active', 'outcome' => 'published' ),
				),
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $status );
	}

	#[Test]
	public function label_matches_via_the_formatted_value_annotation_when_raw_is_an_int_code(): void {
		$status = $this->statusFor(
			array(
				'id'                  => '1',
				'membership_status'   => 1,
				'membership_status@OData.Community.Display.V1.FormattedValue' => 'Active',
			),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'membership_status',
			),
			array(
				'eligible_flag_mode' => 'map',
				'eligible_flag_map'  => array(
					array( 'value' => 'Active', 'outcome' => 'published' ),
				),
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $status );
	}

	#[Test]
	public function raw_int_matches_its_string_form_in_the_map(): void {
		$status = $this->statusFor(
			array( 'id' => '1', 'membership_status' => 1 ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'membership_status',
			),
			array(
				'eligible_flag_mode' => 'map',
				'eligible_flag_map'  => array(
					array( 'value' => '1', 'outcome' => 'published' ),
					array( 'value' => '2', 'outcome' => 'draft' ),
				),
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $status );
	}

	#[Test]
	public function a_list_value_matches_item_by_item_and_the_most_restrictive_wins(): void {
		$status = $this->statusFor(
			array( 'id' => '1', 'statuses' => array( 'Active', 'Lapsed' ) ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'statuses',
			),
			array(
				'eligible_flag_mode' => 'map',
				'eligible_flag_map'  => array(
					array( 'value' => 'Active', 'outcome' => 'published' ),
					array( 'value' => 'Lapsed', 'outcome' => 'draft' ),
				),
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_PENDING, $status );
	}

	#[Test]
	public function a_blank_source_in_map_mode_gives_approved(): void {
		$status = $this->statusFor(
			array( 'id' => '1' ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => '',
			),
			array(
				'eligible_flag_mode' => 'map',
				'eligible_flag_map'  => array(
					array( 'value' => 'Active', 'outcome' => 'published' ),
				),
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $status );
	}

	#[Test]
	public function invert_does_not_apply_in_map_mode(): void {
		// Invert is a truthy-mode-only concept; a stray invert flag left set
		// alongside map mode must not affect the map match.
		$status = $this->statusFor(
			array( 'id' => '1', 'membership_status' => 'Active' ),
			array(
				'external_id'   => 'id',
				'eligible_flag' => 'membership_status',
			),
			array(
				'eligible_flag_mode'   => 'map',
				'eligible_flag_invert' => true,
				'eligible_flag_map'    => array(
					array( 'value' => 'Active', 'outcome' => 'published' ),
				),
			)
		);

		$this->assertSame( Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE, $status );
	}
}
