<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Field_Map;
use Agend_Directory_Sync_Listing_Transformer;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-path-resolver.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-field-map.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-listing-transformer.php';

/**
 * Child list item and aggregate building inside `build_custom_fields()`
 * (SPEC-DIR-20260930-directory-item-list-field US-2.1 AC5 to AC11; US-2.2
 * AC2 to AC6). Attachment onto `CHILD_ROWS_KEY` is the runner's job; these
 * tests attach it by hand, exactly the shape `Agend_Directory_Sync_Runner`
 * produces.
 */
#[CoversClass( Agend_Directory_Sync_Listing_Transformer::class )]
final class ListingTransformerChildListsTest extends TestCase {

	private function field_map( array $child_lists ): array {
		return array(
			'core'          => array( 'external_id' => 'id' ),
			'custom_fields' => array(),
			'locations'     => array(),
			'flags'         => Agend_Directory_Sync_Field_Map::default_flags(),
			'child_lists'   => $child_lists,
		);
	}

	private function contact_with_rows( array $target_rows ): array {
		$key = Agend_Directory_Sync_Listing_Transformer::CHILD_ROWS_KEY;
		return array(
			'id'  => 'centre-1',
			$key  => $target_rows,
		);
	}

	#[Test]
	public function it_builds_items_from_the_attached_child_rows(): void {
		$field_map = $this->field_map(
			array(
				array(
					'target'     => 'centre_tenants',
					'items'      => array( 'tenant_name' => '_pca_tenantname_value@OData.Community.Display.V1.FormattedValue' ),
					'order_by'   => array(),
					'aggregates' => array(),
				),
			)
		);

		$contact = $this->contact_with_rows(
			array(
				'centre_tenants' => array(
					array( '_pca_tenantname_value@OData.Community.Display.V1.FormattedValue' => 'Acme Retail' ),
				),
			)
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( array( $contact ), $field_map );

		$this->assertSame(
			array( array( 'tenant_name' => 'Acme Retail' ) ),
			$result['listings'][0]['custom_fields']['centre_tenants']
		);
	}

	#[Test]
	public function it_sends_an_empty_array_when_the_asset_has_no_child_rows(): void {
		$field_map = $this->field_map(
			array(
				array(
					'target'     => 'centre_tenants',
					'items'      => array( 'tenant_name' => 'pca_tenantname' ),
					'order_by'   => array(),
					'aggregates' => array(),
				),
			)
		);

		$contact = $this->contact_with_rows( array( 'centre_tenants' => array() ) );

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( array( $contact ), $field_map );

		$this->assertSame( array(), $result['listings'][0]['custom_fields']['centre_tenants'] );
	}

	#[Test]
	public function it_keeps_a_numeric_item_field_value_as_a_number(): void {
		$field_map = $this->field_map(
			array(
				array(
					'target'     => 'centre_tenants',
					'items'      => array( 'tenant_area' => 'pca_tenantarea' ),
					'order_by'   => array(),
					'aggregates' => array(),
				),
			)
		);

		$contact = $this->contact_with_rows( array( 'centre_tenants' => array( array( 'pca_tenantarea' => '250' ) ) ) );

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( array( $contact ), $field_map );

		$this->assertSame( 250, $result['listings'][0]['custom_fields']['centre_tenants'][0]['tenant_area'] );
	}

	#[Test]
	public function it_orders_items_by_the_declared_order_by_keys(): void {
		$field_map = $this->field_map(
			array(
				array(
					'target'     => 'centre_tenants',
					'items'      => array(
						'tenant_type' => 'type',
						'tenant_name' => 'name',
					),
					'order_by'   => array( 'tenant_type', 'tenant_name' ),
					'aggregates' => array(),
				),
			)
		);

		$contact = $this->contact_with_rows(
			array(
				'centre_tenants' => array(
					array( 'type' => '2', 'name' => 'Zeta' ),
					array( 'type' => '1', 'name' => 'Beta' ),
					array( 'type' => '1', 'name' => 'Alpha' ),
				),
			)
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( array( $contact ), $field_map );
		$names  = array_column( $result['listings'][0]['custom_fields']['centre_tenants'], 'tenant_name' );

		$this->assertSame( array( 'Alpha', 'Beta', 'Zeta' ), $names );
	}

	#[Test]
	public function it_computes_a_count_aggregate_with_a_where_clause(): void {
		$field_map = $this->field_map(
			array(
				array(
					'target'     => 'centre_tenants',
					'items'      => array(),
					'order_by'   => array(),
					'aggregates' => array(
						array(
							'target'       => 'no_major_tenants',
							'op'           => Agend_Directory_Sync_Field_Map::AGGREGATE_OP_COUNT,
							'source'       => '',
							'where_source' => 'pca_sctenanttype',
							'where_value'  => '1',
						),
					),
				),
			)
		);

		$contact = $this->contact_with_rows(
			array(
				'centre_tenants' => array(
					array( 'pca_sctenanttype' => '1' ),
					array( 'pca_sctenanttype' => '2' ),
					array( 'pca_sctenanttype' => '1' ),
				),
			)
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( array( $contact ), $field_map );

		$this->assertSame( 2, $result['listings'][0]['custom_fields']['no_major_tenants'] );
	}

	#[Test]
	public function it_computes_a_sum_aggregate_with_a_where_clause(): void {
		$field_map = $this->field_map(
			array(
				array(
					'target'     => 'centre_tenants',
					'items'      => array(),
					'order_by'   => array(),
					'aggregates' => array(
						array(
							'target'       => 'specialty_glar',
							'op'           => Agend_Directory_Sync_Field_Map::AGGREGATE_OP_SUM,
							'source'       => 'pca_tenantarea',
							'where_source' => 'pca_sctenanttype',
							'where_value'  => '2',
						),
					),
				),
			)
		);

		$contact = $this->contact_with_rows(
			array(
				'centre_tenants' => array(
					array( 'pca_sctenanttype' => '2', 'pca_tenantarea' => '100' ),
					array( 'pca_sctenanttype' => '1', 'pca_tenantarea' => '999' ),
					array( 'pca_sctenanttype' => '2', 'pca_tenantarea' => '50' ),
				),
			)
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( array( $contact ), $field_map );

		$this->assertSame( 150, $result['listings'][0]['custom_fields']['specialty_glar'] );
	}

	#[Test]
	public function it_sends_zero_for_an_aggregate_with_no_matching_rows(): void {
		$field_map = $this->field_map(
			array(
				array(
					'target'     => 'centre_tenants',
					'items'      => array(),
					'order_by'   => array(),
					'aggregates' => array(
						array(
							'target'       => 'no_major_tenants',
							'op'           => Agend_Directory_Sync_Field_Map::AGGREGATE_OP_COUNT,
							'source'       => '',
							'where_source' => 'pca_sctenanttype',
							'where_value'  => '1',
						),
					),
				),
			)
		);

		$contact = $this->contact_with_rows( array( 'centre_tenants' => array() ) );

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( array( $contact ), $field_map );

		$this->assertSame( 0, $result['listings'][0]['custom_fields']['no_major_tenants'] );
	}

	#[Test]
	public function aggregates_compare_the_raw_value_not_the_formatted_value(): void {
		$field_map = $this->field_map(
			array(
				array(
					'target'     => 'centre_tenants',
					'items'      => array(),
					'order_by'   => array(),
					'aggregates' => array(
						array(
							'target'       => 'no_major_tenants',
							'op'           => Agend_Directory_Sync_Field_Map::AGGREGATE_OP_COUNT,
							'source'       => '',
							'where_source' => 'pca_sctenanttype',
							'where_value'  => '1',
						),
					),
				),
			)
		);

		// The FormattedValue label is "Major Tenant"; the raw value is "1".
		// Matching against the label would find nothing.
		$contact = $this->contact_with_rows(
			array(
				'centre_tenants' => array(
					array(
						'pca_sctenanttype'                                                 => '1',
						'pca_sctenanttype@OData.Community.Display.V1.FormattedValue'       => 'Major Tenant',
					),
				),
			)
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( array( $contact ), $field_map );

		$this->assertSame( 1, $result['listings'][0]['custom_fields']['no_major_tenants'] );
	}
}
