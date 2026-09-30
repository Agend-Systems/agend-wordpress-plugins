<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Field_Map;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-path-resolver.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-config.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-http-api-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-dataverse-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-field-map.php';

/**
 * `child_lists` sanitisation (SPEC-DIR-20260930-directory-item-list-field
 * US-2.1 AC1, AC2; US-2.2 AC1).
 */
#[CoversClass( Agend_Directory_Sync_Field_Map::class )]
final class FieldMapChildListsTest extends TestCase {

	private const VALID_FETCH_XML = '<fetch><entity name="pca_majorspecialothertenants"><attribute name="pca_tenantname" /></entity></fetch>';

	/**
	 * The exact PCA centre-tenants configuration named in the deliverable:
	 * proof it passes the sanitiser end to end.
	 */
	private const PCA_FETCH_XML = <<<'XML'
<fetch>
  <entity name="pca_majorspecialothertenants">
    <attribute name="pca_asset" />
    <attribute name="pca_tenantname" />
    <attribute name="pca_sctenanttype" />
    <attribute name="pca_tenantarea" />
    <attribute name="pca_tenantclassification" />
    <attribute name="pca_tenantchainname" />
    <link-entity name="pca_scperiod" from="pca_scperiodid" to="pca_updateperiod" link-type="inner">
      <filter>
        <condition attribute="pca_currentperiod" operator="eq" value="1" />
      </filter>
    </link-entity>
    <filter>
      <condition attribute="statecode" operator="eq" value="0" />
    </filter>
    <order attribute="pca_asset" />
  </entity>
</fetch>
XML;

	private const VALID_ENTITY_SET = 'pca_majorspecialothertenantses';

	#[Test]
	public function it_keeps_a_well_formed_entry(): void {
		$entries = Agend_Directory_Sync_Field_Map::sanitize_child_lists(
			array(
				array(
					'target'     => 'centre_tenants',
					'entity_set' => self::VALID_ENTITY_SET,
					'fetch_xml'  => self::VALID_FETCH_XML,
					'parent_key' => '_pca_asset_value',
					'items'      => "tenant_name = pca_tenantname\ntenant_type = pca_sctenanttype",
					'order_by'   => 'tenant_type, tenant_name',
					'aggregates' => 'no_major_tenants = count',
				),
			)
		);

		$this->assertCount( 1, $entries );
		$this->assertSame( 'centre_tenants', $entries[0]['target'] );
		$this->assertSame( self::VALID_ENTITY_SET, $entries[0]['entity_set'] );
		$this->assertSame( '_pca_asset_value', $entries[0]['parent_key'] );
		$this->assertSame(
			array( 'tenant_name' => 'pca_tenantname', 'tenant_type' => 'pca_sctenanttype' ),
			$entries[0]['items']
		);
		$this->assertSame( array( 'tenant_type', 'tenant_name' ), $entries[0]['order_by'] );
	}

	#[Test]
	public function it_drops_an_entry_with_no_target(): void {
		$entries = Agend_Directory_Sync_Field_Map::sanitize_child_lists(
			array(
				array(
					'target'     => '',
					'entity_set' => self::VALID_ENTITY_SET,
					'fetch_xml'  => self::VALID_FETCH_XML,
					'parent_key' => '_pca_asset_value',
				),
			)
		);

		$this->assertSame( array(), $entries );
	}

	/**
	 * The entity set (Dataverse EntitySetName) is required and never
	 * guessed: a heuristic guess for pca_majorspecialothertenants (append
	 * "s" unless already ending in "s") previously returned the logical name
	 * unchanged, but its verified EntitySetName is
	 * pca_majorspecialothertenantses, so guessing 404s the request.
	 */
	#[Test]
	public function it_drops_an_entry_with_no_entity_set(): void {
		$entries = Agend_Directory_Sync_Field_Map::sanitize_child_lists(
			array(
				array(
					'target'     => 'centre_tenants',
					'entity_set' => '',
					'fetch_xml'  => self::VALID_FETCH_XML,
					'parent_key' => '_pca_asset_value',
				),
			)
		);

		$this->assertSame( array(), $entries );
	}

	#[Test]
	public function it_drops_an_entry_with_no_parent_key(): void {
		$entries = Agend_Directory_Sync_Field_Map::sanitize_child_lists(
			array(
				array(
					'target'     => 'centre_tenants',
					'entity_set' => self::VALID_ENTITY_SET,
					'fetch_xml'  => self::VALID_FETCH_XML,
					'parent_key' => '',
				),
			)
		);

		$this->assertSame( array(), $entries );
	}

	#[Test]
	public function it_drops_an_entry_whose_fetch_xml_does_not_parse(): void {
		$entries = Agend_Directory_Sync_Field_Map::sanitize_child_lists(
			array(
				array(
					'target'     => 'centre_tenants',
					'entity_set' => self::VALID_ENTITY_SET,
					'fetch_xml'  => '<fetch><entity name="pca_majorspecialothertenants">',
					'parent_key' => '_pca_asset_value',
				),
			)
		);

		$this->assertSame( array(), $entries );
	}

	#[Test]
	public function it_drops_an_order_by_key_not_declared_as_an_item_field(): void {
		$entries = Agend_Directory_Sync_Field_Map::sanitize_child_lists(
			array(
				array(
					'target'     => 'centre_tenants',
					'entity_set' => self::VALID_ENTITY_SET,
					'fetch_xml'  => self::VALID_FETCH_XML,
					'parent_key' => '_pca_asset_value',
					'items'      => 'tenant_name = pca_tenantname',
					'order_by'   => 'tenant_name, not_a_declared_field',
				),
			)
		);

		$this->assertSame( array( 'tenant_name' ), $entries[0]['order_by'] );
	}

	#[Test]
	public function it_parses_a_count_aggregate_with_a_where_clause(): void {
		$aggregates = Agend_Directory_Sync_Field_Map::sanitize_aggregates(
			'no_major_tenants = count where pca_sctenanttype = 1'
		);

		$this->assertSame(
			array(
				array(
					'target'       => 'no_major_tenants',
					'op'           => Agend_Directory_Sync_Field_Map::AGGREGATE_OP_COUNT,
					'source'       => '',
					'where_source' => 'pca_sctenanttype',
					'where_value'  => '1',
				),
			),
			$aggregates
		);
	}

	#[Test]
	public function it_parses_a_sum_aggregate_with_a_where_clause(): void {
		$aggregates = Agend_Directory_Sync_Field_Map::sanitize_aggregates(
			'specialty_glar = sum(pca_tenantarea) where pca_sctenanttype = 2'
		);

		$this->assertSame(
			array(
				array(
					'target'       => 'specialty_glar',
					'op'           => Agend_Directory_Sync_Field_Map::AGGREGATE_OP_SUM,
					'source'       => 'pca_tenantarea',
					'where_source' => 'pca_sctenanttype',
					'where_value'  => '2',
				),
			),
			$aggregates
		);
	}

	#[Test]
	public function it_parses_a_bare_count_aggregate_with_no_where_clause(): void {
		$aggregates = Agend_Directory_Sync_Field_Map::sanitize_aggregates( 'total_tenants = count' );

		$this->assertSame(
			array(
				array(
					'target'       => 'total_tenants',
					'op'           => Agend_Directory_Sync_Field_Map::AGGREGATE_OP_COUNT,
					'source'       => '',
					'where_source' => '',
					'where_value'  => '',
				),
			),
			$aggregates
		);
	}

	#[Test]
	public function it_drops_a_sum_aggregate_with_no_source(): void {
		$this->assertSame( array(), Agend_Directory_Sync_Field_Map::sanitize_aggregates( 'broken = sum()' ) );
	}

	#[Test]
	public function it_drops_an_unparseable_aggregate_line(): void {
		$this->assertSame( array(), Agend_Directory_Sync_Field_Map::sanitize_aggregates( 'this is not a line' ) );
	}

	/**
	 * The exact PCA centre-tenants child list configuration from the
	 * deliverable, end to end through the sanitiser.
	 */
	#[Test]
	public function it_accepts_the_pca_centre_tenants_configuration(): void {
		$entries = Agend_Directory_Sync_Field_Map::sanitize_child_lists(
			array(
				array(
					'target'     => 'centre_tenants',
					'entity_set' => 'pca_majorspecialothertenantses',
					'fetch_xml'  => self::PCA_FETCH_XML,
					'parent_key' => '_pca_asset_value',
					'items'      => implode(
						"\n",
						array(
							'tenant_name = _pca_tenantname_value@OData.Community.Display.V1.FormattedValue',
							'tenant_type = pca_sctenanttype@OData.Community.Display.V1.FormattedValue',
							'tenant_area = pca_tenantarea',
							'tenant_classification = _pca_tenantclassification_value@OData.Community.Display.V1.FormattedValue',
							'chain_name = pca_tenantchainname',
						)
					),
					'order_by'   => 'tenant_type, tenant_name',
					'aggregates' => implode(
						"\n",
						array(
							'no_major_tenants = count where pca_sctenanttype = 1',
							'no_specialty_stores = count where pca_sctenanttype = 2',
							'specialty_glar = sum(pca_tenantarea) where pca_sctenanttype = 2',
						)
					),
				),
			)
		);

		$this->assertCount( 1, $entries );
		$entry = $entries[0];

		$this->assertSame( 'centre_tenants', $entry['target'] );
		$this->assertSame( 'pca_majorspecialothertenantses', $entry['entity_set'] );
		$this->assertSame( '_pca_asset_value', $entry['parent_key'] );
		$this->assertSame( array( 'tenant_type', 'tenant_name' ), $entry['order_by'] );
		$this->assertCount( 5, $entry['items'] );
		$this->assertSame(
			'_pca_tenantname_value@OData.Community.Display.V1.FormattedValue',
			$entry['items']['tenant_name']
		);
		$this->assertCount( 3, $entry['aggregates'] );
		$this->assertSame( 'no_major_tenants', $entry['aggregates'][0]['target'] );
		$this->assertSame( 'specialty_glar', $entry['aggregates'][2]['target'] );
		$this->assertSame( Agend_Directory_Sync_Field_Map::AGGREGATE_OP_SUM, $entry['aggregates'][2]['op'] );
		$this->assertSame( 'pca_tenantarea', $entry['aggregates'][2]['source'] );
	}

	#[Test]
	public function resolve_falls_back_to_an_empty_child_lists_array_when_unsaved(): void {
		$resolved = Agend_Directory_Sync_Field_Map::resolve();

		$this->assertSame( array(), $resolved['child_lists'] );
	}
}
